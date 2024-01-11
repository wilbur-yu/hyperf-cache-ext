<?php

declare(strict_types=1);

/**
 * This file is part of project wilbur-yu/hyperf-cache-ext.
 *
 * @author   wenbo@wenber.club
 * @link     https://github.com/wilbur-yu
 */

namespace WilburYu\HyperfCacheExt\Redis\Limiters;

use WilburYu\HyperfCacheExt\Exception\LimiterTimeoutException;
use Hyperf\Redis\Redis;

class DurationLimiter
{
    private Redis $redis;

    /**
     * The unique name of the lock.
     *
     * @var string
     */
    private string $name;

    /**
     * The allowed number of concurrent tasks.
     *
     * @var int
     */
    private int $maxLocks;

    /**
     * The number of seconds a slot should be maintained.
     *
     * @var int
     */
    private int $decay;

    /**
     * The timestamp of the end of the current duration.
     *
     * @var int
     */
    public int $decaysAt;

    /**
     * The number of remaining slots.
     *
     * @var int
     */
    public int $remaining;

    /**
     * Create a new duration limiter instance.
     *
     * @param  Redis   $redis
     * @param  string  $name
     * @param  int     $maxLocks
     * @param  int     $decay
     *
     * @return void
     */
    public function __construct(Redis $redis, string $name, int $maxLocks, int $decay)
    {
        $this->name = $name;
        $this->decay = $decay;
        $this->redis = $redis;
        $this->maxLocks = $maxLocks;
    }

    /**
     * Attempt to acquire the lock for the given number of seconds.
     *
     * @param  int            $timeout
     * @param  int            $wait
     * @param  callable|null  $callback
     *
     * @throws LimiterTimeoutException
     * @return mixed
     */
    public function block(int $timeout, int $wait, ?callable $callback = null): mixed
    {
        $starting = time();

        while (!$this->acquire()) {
            // 超时抛出异常
            if (time() - $timeout >= $starting) {
                throw new LimiterTimeoutException(code: 429);
            }

            // 获取失败，则阻塞 750ms 后重试
            usleep($wait * 1000);
        }

        // 获取锁成功（还未触发上限），则执行回调函数
        if (is_callable($callback)) {
            return $callback();
        }

        return true;
    }

    /**
     * Attempt to acquire the lock.
     *
     * @return bool
     */
    public function acquire(): bool
    {
        // 通过 Redis Lua 脚本设置锁，然后从返回值读取信息
        $results = $this->redis->eval(
            $this->luaScript(),
            [
                $this->name,
                microtime(true),
                time(),
                $this->decay,
                $this->maxLocks,
            ],
            1
        );

        // 时间窗口过期时间点（当前时间 + 传入的时间窗口大小）
        $this->decaysAt = (int)$results[1];

        // 剩余支持的请求槽位（传入的请求上限 - 已处理请求数）
        $this->remaining = max(0, $results[2]);

        // 是否获取锁成功（基于是否还有剩余请求槽位判断）
        return (bool)$results[0];
    }

    /**
     * Determine if the key has been "accessed" too many times.
     *
     * @return bool
     */
    public function tooManyAttempts(): bool
    {
        [$this->decaysAt, $this->remaining] = $this->redis->eval(
            $this->tooManyAttemptsLuaScript(),
            [
                $this->name,
                microtime(true),
                time(),
                $this->decay,
                $this->maxLocks,
            ],
            1
        );

        return $this->remaining <= 0;
    }

    /**
     * Clear the limiter.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->redis->del($this->name);
    }

    /**
     * Get the Lua script for acquiring a lock.
     *
     * KEYS[1] - The limiter name
     * ARGV[1] - Current time in microseconds
     * ARGV[2] - Current time in seconds
     * ARGV[3] - Duration of the bucket
     * ARGV[4] - Allowed number of tasks
     *
     * @return string
     */
    protected function luaScript(): string
    {
        return <<<'LUA'
local function reset()
    redis.call('HMSET', KEYS[1], 'start', ARGV[2], 'end', ARGV[2] + ARGV[3], 'count', 1)
    return redis.call('EXPIRE', KEYS[1], ARGV[3] * 2)
end
-- 第一次请求会初始化一个 Hash 结构作为限流器，键名是外部传入的名称，键值是包含起始时间、结束时间和请求统计数的对象
-- 返回值的第一个对应的是是否获取锁成功，即是否可以继续请求，第二个是有效期结束时间点，第三个是剩余的请求槽位数
if redis.call('EXISTS', KEYS[1]) == 0 then
    return {reset(), ARGV[2] + ARGV[3], ARGV[4] - 1}
end
-- 如果限流器已存在，并且还处于有效期对应的时间窗口内，则对请求统计数做自增操作
-- 这里，我们会限定其值不能超过请求上限，否则获取锁失败，有效期结束时间点不变，剩余槽位数=请求上限-当前请求统计数
if ARGV[1] >= redis.call('HGET', KEYS[1], 'start') and ARGV[1] <= redis.call('HGET', KEYS[1], 'end') then
    return {
        tonumber(redis.call('HINCRBY', KEYS[1], 'count', 1)) <= tonumber(ARGV[4]),
        redis.call('HGET', KEYS[1], 'end'),
        ARGV[4] - redis.call('HGET', KEYS[1], 'count')
    }
end
-- 如果限流器已过期，则和第一个请求一样，重置这个限流器，重新开始统计
return {reset(), ARGV[2] + ARGV[3], ARGV[4] - 1}
LUA;
    }

    /**
     * Get the Lua script to determine if the key has been "accessed" too many times.
     *
     * KEYS[1] - The limiter name
     * ARGV[1] - Current time in microseconds
     * ARGV[2] - Current time in seconds
     * ARGV[3] - Duration of the bucket
     * ARGV[4] - Allowed number of tasks
     *
     * @return string
     */
    protected function tooManyAttemptsLuaScript(): string
    {
        return <<<'LUA'

if redis.call('EXISTS', KEYS[1]) == 0 then
    return {0, ARGV[2] + ARGV[3]}
end

if ARGV[1] >= redis.call('HGET', KEYS[1], 'start') and ARGV[1] <= redis.call('HGET', KEYS[1], 'end') then
    return {
        redis.call('HGET', KEYS[1], 'end'),
        ARGV[4] - redis.call('HGET', KEYS[1], 'count')
    }
end

return {0, ARGV[2] + ARGV[3]}
LUA;
    }
}
