<?php

declare(strict_types=1);

/**
 * This file is part of project wilbur-yu/hyperf-cache-ext.
 *
 * @author   wenbo@wenber.club
 * @link     https://github.com/wilbur-yu
 */

namespace WilburYu\HyperfCacheExt\Redis\Limiters;

use Hyperf\Stringable\Str;
use WilburYu\HyperfCacheExt\Exception\LimiterTimeoutException;
use Throwable;
use Hyperf\Redis\Redis;

use function Hyperf\Tappable\tap;

class ConcurrencyLimiter
{
    protected Redis $redis;

    /**
     * The name of the limiter.
     *
     * @var string
     */
    protected string $name;

    /**
     * The allowed number of concurrent tasks.
     *
     * @var int
     */
    protected int $maxLocks;

    /**
     * The number of seconds a slot should be maintained.
     *
     * @var int
     */
    protected int $releaseAfter;

    /**
     * Create a new concurrency limiter instance.
     *
     * @param  Redis   $redis
     * @param  string  $name
     * @param  int     $maxLocks
     * @param  int     $releaseAfter
     *
     * @return void
     */
    public function __construct(Redis $redis, string $name, int $maxLocks, int $releaseAfter)
    {
        $this->name = $name;
        $this->redis = $redis;
        $this->maxLocks = $maxLocks;
        $this->releaseAfter = $releaseAfter;
    }

    /**
     * Attempt to acquire the lock for the given number of seconds.
     *
     * @param  int            $timeout
     * @param  int            $wait
     * @param  callable|null  $callback
     *
     * @throws Throwable
     * @throws LimiterTimeoutException
     * @return mixed
     */
    public function block(int $timeout, int $wait, ?callable $callback = null): mixed
    {
        $starting = time();

        $id = Str::random(20);

        while (!$slot = $this->acquire($id)) {
            if (time() - $timeout >= $starting) {
                throw new LimiterTimeoutException(code: 429);
            }

            usleep($wait * 1000);
        }

        if (is_callable($callback)) {
            try {
                return tap($callback(), function () use ($slot, $id) {
                    $this->release($slot, $id);
                });
            } catch (Throwable $exception) {
                $this->release($slot, $id);

                throw $exception;
            }
        }

        return true;
    }

    /**
     * Attempt to acquire the lock.
     *
     * @param  string  $id  A unique identifier for this lock
     *
     * @return mixed
     */
    protected function acquire(string $id): mixed
    {
        $slots = array_map(function ($i) {
            return $this->name.$i;
        }, range(1, $this->maxLocks));

        return $this->redis->eval(
            $this->lockScript(),
            array_merge($slots, [$this->name, $this->releaseAfter, $id]),
            count($slots)
        );
    }

    /**
     * Get the Lua script for acquiring a lock.
     *
     * KEYS    - The keys that represent available slots
     * ARGV[1] - The limiter name
     * ARGV[2] - The number of seconds the slot should be reserved
     * ARGV[3] - The unique identifier for this lock
     *
     * @return string
     */
    protected function lockScript(): string
    {
        return <<<'LUA'
for index, value in pairs(redis.call('mget', unpack(KEYS))) do
    if not value then
        redis.call('set', KEYS[index], ARGV[3], "EX", ARGV[2])
        return ARGV[1]..index
    end
end
LUA;
    }

    /**
     * Release the lock.
     *
     * @param  string  $key
     * @param  string  $id
     *
     * @return void
     */
    protected function release(string $key, string $id): void
    {
        $this->redis->eval($this->releaseScript(), [$key, $id], 1);
    }

    /**
     * Get the Lua script to atomically release a lock.
     *
     * KEYS[1] - The name of the lock
     * ARGV[1] - The unique identifier for this lock
     *
     * @return string
     */
    protected function releaseScript(): string
    {
        return <<<'LUA'
if redis.call('get', KEYS[1]) == ARGV[1]
then
    return redis.call('del', KEYS[1])
else
    return 0
end
LUA;
    }
}
