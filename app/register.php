<?php
/**
 * Created by PhpStorm.
 * User: shengj
 * Date: 2018/1/2
 * Time: 16:12
 */

require __DIR__.'/../index.php';

use App\Conn;

$db = Conn::getInstance();

// 获取参数，nick、tel、urls
$nick = trim(htmlspecialchars($_POST['nick']));
$tel = trim(htmlspecialchars($_POST['tel']));
$code = trim(htmlspecialchars($_POST['code']));
// 首先进行验证是否有效员工

// 验证码校验

// 存入redis

// WebSocket传输数据

// 前端存入dataSource
