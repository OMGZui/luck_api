<?php
/**
 * Created by PhpStorm.
 * User: shengj
 * Date: 2018/1/3
 * Time: 15:52
 */

require __DIR__ . '/../index.php';

use App\Conn;

$db = Conn::getInstance();

$key_code_tel = 'code:tel:';

$random_code = rand(1000, 9999);
$tel = trim(htmlspecialchars($_POST['tel']));

// 存入验证码
$db->set($key_code_tel . $tel . ':code', $random_code);

// 发短信


echo '发送成功，验证码为'.$random_code;
