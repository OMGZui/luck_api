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

$key = 'name';

$db->set($key,'shengj');
$name = $db->get($key);
dump($name);
