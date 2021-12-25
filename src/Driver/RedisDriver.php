<?php

declare(strict_types=1);

/**
 * This file is part of project wilbur-yu/hyperf-cache-ext.
 *
 * @author   wenbo@wenber.club
 * @link     https://github.com/wilbur-yu
 */

namespace WilburYu\HyperfCacheExt\Driver;

use WilburYu\HyperfCacheExt\Redis\Limiters\ConcurrencyLimiterBuilder;
use WilburYu\HyperfCacheExt\Redis\Limiters\DurationLimiterBuilder;
use Hyperf\Cache\Driver\RedisDriver as BaseRedisDriver;

class RedisDriver extends BaseRedisDriver
{
    public function increment(string $key, int $value = 1): int
    {
        return $this->redis->incrBy($this->getCacheKey($key), $value);
    }

    public function decrement(string $key, int $value = 1): int
    {
        return $this->redis->decrBy($this->getCacheKey($key), $value);
    }

    /**
     * Store an item in the cache if the key doesn't exist.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @param  int     $seconds
     *
     * @return bool
     */
    public function add(string $key, mixed $value, int $seconds): bool
    {
        $lua = "return redis.call('exists',KEYS[1])<1 and redis.call('setex',KEYS[1],ARGV[2],ARGV[1])";

        return (bool)$this->redis->eval(
            $lua,
            [$this->prefix.$key, $this->packer->pack($value), (int)max(1, $seconds)],
            1
        );
    }

    /**
     * Store an item in the cache for a given number of seconds.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @param  int     $seconds
     *
     * @return bool
     */
    public function put(string $key, mixed $value, int $seconds): bool
    {
        return $this->redis->setex(
            $this->prefix.$key,
            (int)max(1, $seconds),
            $this->packer->pack($value)
        );
    }

    /**
     * Funnel a callback for a maximum number of simultaneous executions.
     */
    public function funnel(string $name): ConcurrencyLimiterBuilder
    {
        return new ConcurrencyLimiterBuilder($this->redis, $name);
    }

    /**
     * Throttle a callback for a maximum number of executions over a given duration.
     */
    public function throttle(string $name): DurationLimiterBuilder
    {
        return new DurationLimiterBuilder($this->redis, $name);
    }
}
