<?php
/**
 * Created by PhpStorm.
 * User: shengj
 * Date: 2018/1/2
 * Time: 15:58
 */

namespace App;

use Predis\Client;

class Conn
{
    protected static $instance;

    //单例
    final private function __construct(){}
    final private function __clone(){}

    public static function getInstance()
    {
        if(self::$instance === null){
            self::$instance = new Client([
                'host' => env('REDIS_HOST'),
                'password' => env('REDIS_PASSWORD'),
                'port' => env('REDIS_PORT'),
            ]);
        }
        return self::$instance;
    }
}
