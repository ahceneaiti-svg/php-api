<?php

namespace App\Redis;

/**
 * Builds a connected phpredis client from discrete connection settings.
 */
class RedisFactory
{
    public function __construct(
        private readonly string $host,
        private readonly int $port = 6379,
        private readonly string $password = '',
        private readonly int $database = 0,
    ) {
    }

    public function create(): \Redis
    {
        $redis = new \Redis();
        $redis->connect($this->host, $this->port, 2.0);

        if ('' !== $this->password) {
            $redis->auth($this->password);
        }

        $redis->select($this->database);
        $redis->setOption(\Redis::OPT_PREFIX, 'user-api:');

        return $redis;
    }
}
