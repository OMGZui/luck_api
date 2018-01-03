<?php
/**
 * Created by PhpStorm.
 * User: shengj
 * Date: 2018/1/2
 * Time: 16:12
 */

require __DIR__ . '/../index.php';

use App\Conn;

$db = Conn::getInstance();

// 获取参数，nick、tel、urls
$nick = trim(htmlspecialchars($_POST['nick']));
$tel = trim(htmlspecialchars($_POST['tel']));
$code = trim(htmlspecialchars($_POST['code']));

// 首先进行验证是否有效员工


// code
$key_code_tel = 'code:tel:';
// user
$key_user_id = 'user:id:';
$key_user_tel = 'user:tel:';
$key_user_inc = 'key_user_id';

// 验证码校验
$check = checkCode($db, $key_code_tel, $tel, $code);
if (!$check) {
    die('验证码无效');
}

// 存入redis

if (!$db->exists($key_user_inc)) {
    $db->set($key_user_inc, 1);
}

$user_id = $db->get($key_user_inc);

/**
 * user
 */
// 冗余字段，通过tel查id，判断是否重复注册
$exist_tel = $db->keys($key_user_tel . $tel . '*');
if (empty($exist_tel)) {
    $db->set($key_user_tel . $tel . ':id', $user_id);
} else {
    die('已存在该手机号');
}

$db->set($key_user_id . $user_id . ':tel', $tel);
$db->set($key_user_id . $user_id . ':nick', $nick);

// 自增user_id
$db->incr($key_user_inc);

// WebSocket传输数据
run();

// 前端存入dataSource
header("Location: http://luck.com/public/ws.html");

function checkCode($db, $key_code_tel, $tel, $code)
{
    $origin_code = $db->get($key_code_tel . $tel . ':code');
    if ($origin_code == $code) {
        return true;
    } else {
        return false;
    }
}

function run()
{
    $command = '/usr/bin/php /var/www/youxiake/luck/server/app/WS.php';
    exec($command);
}
