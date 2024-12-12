<?php

namespace SwooleBase\Foundation\Abstracts;

use SwooleBase\Foundation\Interfaces\SessionInterface;

abstract class Session implements SessionInterface
{
    /** @var array|Session[] */
    private static array $cabinet = [];

    public static function createOrReceive(callable $factory, $fd, $arrayHeaders = [], $stringToken = ''): ?Session
    {
        if (!$arrayHeaders || !is_array($arrayHeaders)) {
            $arrayHeaders = [];
        }

        $arr = array_filter($arrayHeaders, fn ($key) => in_array(strtolower($key), ['user-agent', 'accept', 'accept-encoding']), ARRAY_FILTER_USE_KEY);
        $arr['fd'] = $fd;
        $arr['token'] = (string)$stringToken;
        ksort($arr);
        $drawer = md5(json_encode($arr));

        if (!isset(self::$cabinet[$drawer]) || !(self::$cabinet[$drawer] instanceof Session)) {
            self::$cabinet[$drawer] = $factory($arr);
        }

        return self::$cabinet[$drawer] ?? null;
    }
}
