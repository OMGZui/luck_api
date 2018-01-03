<?php
/**
 * Created by PhpStorm.
 * User: shengj
 * Date: 2018/1/3
 * Time: 16:29
 */

error_reporting(E_ALL);
set_time_limit(0);
date_default_timezone_set('Asia/shanghai');

class WebSocket
{
    const LISTEN_SOCKET_NUM = 9;

    private $sockets = [];
    //业务socket
    private $danmuSockets = [];
    private $master;
    private $log_path;
    private $redis;

    public function __construct($host, $port, $log_path = '/var/www/m/storage/logs/')
    {
        try {
            //连接redis
            $this->redis = new Redis();
            $this->redis->connect('172.20.0.1', 6379);

            $this->log_path = $log_path;
            $this->master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            // 设置IP和端口重用,在重启服务器后能重新使用此端口;
            socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, 1);
            //监听socket不要阻塞
            socket_set_nonblock($this->master);
            // 将IP和端口绑定在服务器socket上;
            socket_bind($this->master, $host, $port);
            // listen函数使用主动连接套接口变为被连接套接口，使得一个进程可以接受其它进程的请求，从而成为一个服务器进程。
            //在TCP服务器编程中listen函数把进程变为一个服务器，并指定相应的套接字变为被动连接,其中的能存储的请求不明的socket数目。
            socket_listen($this->master, self::LISTEN_SOCKET_NUM);
        } catch (\Exception $e) {
            $err_code = socket_last_error();
            $err_msg = socket_strerror($err_code);
            $this->error([
                'error_init_server',
                $err_code,
                $err_msg
            ]);
        }
        $this->sockets[0] = ['resource' => $this->master];
        $pid = posix_getpid();
        $this->debug(["server: {$this->master} started,pid: {$pid}"]);

        while (true) {
            try {
                //延迟十分之一秒，减少cpu负担
                usleep(2000000);
                $this->doServer();
            } catch (\Exception $e) {
                $this->error([
                    'error_do_server',
                    $e->getCode(),
                    $e->getMessage()
                ]);
            }
        }
    }

    private function doServer()
    {
        $write = $except = NULL;
        //todo 一个很重要的问题，不可写（或读）的socket就不能遍历了,所以当socket不处于激活状态时，不能在下面遍历
        $sockets = array_column($this->sockets, 'resource');
        $this->debug(array_keys($sockets));
        $read_num = socket_select($sockets, $write, $except, 0, 0);
        // select作为监视函数,参数分别是(监视可读,可写,异常,超时时间),返回可操作数目,出错时返回false;
        if (false === $read_num) {
            $this->error([
                'error_select',
                $err_code = socket_last_error(),
                socket_strerror($err_code)
            ]);
            return;
        }
        //因为这次客户端基本不会向服务端发送请求，&$read &write sokcets数组基本不会发生变化，所以推送逻辑得分开
        foreach ($this->danmuSockets as $socket) {
            $this->debug(['scan danmu']);
            $msg = [
                'type' => 'danmu',
                'content' => $this->busterBeam($socket),
            ];
            if (!empty($msg['content'])) {
                $msg = $this->build(json_encode($msg));
                socket_write($socket, $msg, strlen($msg));
            }
        }

        //这边只处理建立连接，以及对应页面（区域）和socket的挂钩
        foreach ($sockets as $socket) {
            $this->debug(['scan socket']);
            // 如果可读的是服务器socket,则处理连接逻辑
            if ($socket == $this->master) {
                $client = socket_accept($this->master);
                //不阻塞的监听socket在没有accept到新连接时会返回false
                if (false === $client) {
                    continue;
                } else {
                    self::connect($client);
                    continue;
                }
            } else {
                // 如果可读的是其他已连接socket,则读取其数据,并处理应答逻辑
                $bytes = @socket_recv($socket, $buffer, 2048, 0);
                $this->debug(['bytes', $bytes]);
                //false为发生错误
                if ($bytes === false) {
                    $err_code = socket_last_error();
                    $err_msg = socket_strerror($err_code);
                    $this->error([
                        'error_socket_recv:',
                        $err_code,
                        $err_msg
                    ]);
                    $recv_msg = $this->disconnect($socket);
                    // 断开了，趁早关掉
                } elseif ($bytes == 0) {
                    $this->disconnect($socket);
                } else {
                    //如果还没有建立websocket连接
                    if (!$this->sockets[(int)$socket]['handshake']) {
                        self::handShake($socket, $buffer);
                        continue;
                    } else {
                        $recv_msg = $this->parse($buffer);
                    }
                    $msg = $this->dealMsg($socket, $recv_msg);
                    if ($msg !== false) {
                        $this->broadcast($socket, $msg);
                    }
                }
            }
        }
    }

    /**
     * 将socket添加到已连接列表,但websocket握手状态留空;
     *
     * @param $socket
     */
    public function connect($socket)
    {
        socket_getpeername($socket, $ip, $port);
        $socket_info = [
            'resource' => $socket,
            'area' => '',
            'handshake' => false,
            'ip' => $ip,
            'port' => $port,
        ];
        $this->sockets[(int)$socket] = $socket_info;

    }

    /**
     * 客户端关闭连接
     *
     * @param $socket
     *
     * @return array
     */
    private function disconnect($socket)
    {
        $recv_msg = [
            'type' => 'logout',
        ];
        unset($this->sockets[(int)$socket]);
        unset($this->danmuSockets[(int)$socket]);
        $this->debug([(int)$socket, 'disconnect']);
        return $recv_msg;
    }

    /**
     * 用公共握手算法握手
     *
     * @param $socket
     * @param $buffer
     *
     * @return bool
     */
    public function handShake($socket, $buffer)
    {
        // 获取到客户端的升级密匙
        $line_with_key = substr($buffer, strpos($buffer, 'Sec-WebSocket-Key:') + 18);
        $key = trim(substr($line_with_key, 0, strpos($line_with_key, "\r\n")));

        // 生成升级密匙,并拼接websocket升级头
        $upgrade_key = base64_encode(sha1($key . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));// 升级key的算法
        $upgrade_message = "HTTP/1.1 101 Switching Protocols\r\n";
        $upgrade_message .= "Upgrade: websocket\r\n";
        $upgrade_message .= "Sec-WebSocket-Version: 13\r\n";
        $upgrade_message .= "Connection: Upgrade\r\n";
        $upgrade_message .= "Sec-WebSocket-Accept:" . $upgrade_key . "\r\n\r\n";

        socket_write($socket, $upgrade_message, strlen($upgrade_message));// 向socket里写入升级信息
        $this->sockets[(int)$socket]['handshake'] = true;

        socket_getpeername($socket, $ip, $port);
        $this->debug([
            'hand_shake',
            $socket,
            $ip,
            $port
        ]);

        // 向客户端发送握手成功消息,以触发客户端发送用户名动作;
        $msg = [
            'type' => 'handshake',
            'content' => 'done',
        ];
        //加入待扫描的弹幕sockets
        $this->danmuSockets[(int)$socket]['resource'] = $socket;
        $msg = $this->build(json_encode($msg));
        socket_write($socket, $msg, strlen($msg));
        return true;
    }

    /**
     * 解析数据
     *
     * @param $buffer
     *
     * @return bool|string
     */
    private function parse($buffer)
    {
        $decoded = '';
        $len = ord($buffer[1]) & 127;
        if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        } else if ($len === 127) {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        } else {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }
        for ($index = 0; $index < strlen($data); $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }

        return json_decode($decoded, true);
    }

    /**
     * 将普通信息组装成websocket数据帧
     *
     * @param $msg
     * @return string
     */
    private function build($msg)
    {
        $frame = [];
        $frame[0] = '81';
        $len = strlen($msg);
        if ($len < 126) {
            $frame[1] = $len < 16 ? '0' . dechex($len) : dechex($len);
        } else if ($len < 65025) {
            $s = dechex($len);
            $frame[1] = '7e' . str_repeat('0', 4 - strlen($s)) . $s;
        } else {
            $s = dechex($len);
            $frame[1] = '7f' . str_repeat('0', 16 - strlen($s)) . $s;
        }

        $data = '';
        $l = strlen($msg);
        for ($i = 0; $i < $l; $i++) {
            $data .= dechex(ord($msg{$i}));
        }
        $frame[2] = $data;

        $data = implode('', $frame);

        return pack("H*", $data);
    }

    /**
     * 拼装信息
     * @param $socket
     * @param $recv_msg //客户端传来的信息
     * @return string
     */
    private function dealMsg($socket, $recv_msg)
    {
        $response = [];

        $msg_type = empty($recv_msg['type']) ? '' : $recv_msg['type'];
        $msg_content = empty($recv_msg['content']) ? '' : $recv_msg['content'];
        switch ($msg_type) {
            case 'whoami':
//                $this->sockets[(int)$socket]['area'] = $msg_content;
                $this->danmuSockets[(int)$socket]['area'] = $msg_content;
                $response['type'] = 'init';
                $response['content'] = 'success num:' . count($this->sockets);
                break;
            default:
                break;
        }
        if (!empty($response['content'])) {
            return $this->build(json_encode($response));
        } else {
            return false;
        }
    }


    /**
     * 从弹幕队列抽出最新一条准备发送
     * @param $socket
     * @return null|string
     */
    private function busterBeam($socket)
    {
        if (empty($this->danmuSockets[(int)$socket]['area'])) {
            return null;
        } else {
            $area = $this->danmuSockets[(int)$socket]['area'];
        }

        $danmu = $this->redis->rpop(md5($area . 'danmu'));
        return $danmu;
    }

    /**
     * 定点响应消息
     *
     * @param $data
     */
    private function broadcast($socket, $data)
    {
        //广播消息
//        foreach ($this->sockets as $socket) {
//            if ($socket['resource'] == $this->master) {
//                continue;
//            }
//            socket_write($socket['resource'], $data, strlen($data));
//        }
        //单点响应消息
        $this->debug(['broadcast', $socket]);
        if ($socket == $this->master) {
            return;
        } else {
            socket_write($socket, $data, strlen($data));
        }
    }

    /**
     * 记录debug信息
     *
     * @param array $info
     */
    private function debug(array $info)
    {
        $time = date('Y-m-d H:i:s');
        array_unshift($info, $time);

        $info = array_map('json_encode', $info);
        file_put_contents($this->log_path . 'websocket_debug.log', implode(' | ', $info) . "\r\n", FILE_APPEND);
    }

    /**
     * 记录错误信息
     *
     * @param array $info
     */
    private function error(array $info)
    {
        $time = date('Y-m-d H:i:s');
        array_unshift($info, $time);

        $info = array_map('json_encode', $info);
        file_put_contents($this->log_path . 'websocket_error.log', implode(' | ', $info) . "\r\n", FILE_APPEND);
    }
}

$ws = new WebSocket("0.0.0.0", "9777");
