<?php
/**
 * Created by PhpStorm.
 * User: shengj
 * Date: 2018/1/4
 * Time: 15:25
 */

namespace App;

require __DIR__ . '/../index.php';

$db = Conn::getInstance();

$key = 'tel';

// 接收手机号参数
$tel = trim(htmlspecialchars($_POST['tel']));

// 存入redis的list

$db->rpush($key,[$tel]);

echo json_encode(['msg' => '签到成功']);
