<?php
/**
 * Created by PhpStorm.
 * User: shengj
 * Date: 2018/1/4
 * Time: 15:39
 */

namespace App;

require __DIR__.'/../index.php';


$db = Conn::getInstance();

$key = 'tel';

// 读取list数据

$list = $db->lrange($key,0,-1);

// 输出给前端进行处理

echo json_encode(['data' => $list]);

