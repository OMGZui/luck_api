<?php
/**
 * Created by PhpStorm.
 * User: shengj
 * Date: 2018/1/2
 * Time: 15:37
 */

if (!function_exists('env')) {
    function env($key, $default = null)
    {
        $value = getenv($key);

        if ($value === false) {
            return value($default);
        }

        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'empty':
            case '(empty)':
                return '';
            case 'null':
            case '(null)':
                return;
        }

        if (strlen($value) > 1 && startsWith($value, '"') && endsWith($value, '"')) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}

if (!function_exists('value')) {
    function value($value)
    {
        return $value instanceof Closure ? $value() : $value;
    }
}
if (!function_exists('startsWith')) {
    function startsWith($haystack, $needles)
    {
        foreach ((array)$needles as $needle) {
            if ($needle !== '' && mb_substr($haystack, 0, mb_strlen($needle)) === (string)$needle) {
                return true;
            }
        }

        return false;
    }
}
if (!function_exists('endsWith')) {
    function endsWith($haystack, $needles)
    {
        foreach ((array)$needles as $needle) {
            if (mb_substr($haystack, -mb_strlen($needle)) === (string)$needle) {
                return true;
            }
        }

        return false;
    }
}

