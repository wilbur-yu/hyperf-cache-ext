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
use Hyperf\Utils\InteractsWithTime;
use Throwable;

class ConcurrencyLimiterBuilder
{
    use InteractsWithTime;

    public Redis $connection;

    /**
     * The name of the lock.
     *
     * @var string
     */
    public string $name;

    /**
     * The maximum number of entities that can hold the lock at the same time.
     *
     * @var int
     */
    public int $maxLocks;

    /**
     * The number of seconds to maintain the lock until it is automatically released.
     *
     * @var int
     */
    public int $releaseAfter = 60;

    /**
     * The amount of time to block until a lock is available.
     *
     * @var int
     */
    public int $timeout = 3;

    /**
     * Create a new builder instance.
     *
     * @param  Redis  $connection
     *
     * @return void
     */
    public function __construct(Redis $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param  string  $name
     *
     * @return $this
     */
    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Set the maximum number of locks that can be obtained per time window.
     *
     * @param  int  $maxLocks
     *
     * @return $this
     */
    public function limit(int $maxLocks): self
    {
        $this->maxLocks = $maxLocks;

        return $this;
    }

    /**
     * Set the number of seconds until the lock will be released.
     *
     * @param  int  $releaseAfter
     *
     * @return $this
     */
    public function releaseAfter(int $releaseAfter): self
    {
        $this->releaseAfter = $this->secondsUntil($releaseAfter);

        return $this;
    }

    /**
     * Set the amount of time to block until a lock is available.
     *
     * @param  int  $timeout
     *
     * @return $this
     */
    public function block(int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Execute the given callback if a lock is obtained, otherwise call the failure callback.
     *
     * @param  callable       $callback
     * @param  callable|null  $failure
     *
     * @throws LimiterTimeoutException|Throwable
     * @return mixed
     *
     */
    public function then(callable $callback, callable $failure = null): mixed
    {
        try {
            return (new ConcurrencyLimiter(
                $this->connection,
                $this->name,
                $this->maxLocks,
                $this->releaseAfter
            ))->block($this->timeout, $callback);
        } catch (LimiterTimeoutException $e) {
            if ($failure) {
                return $failure($e);
            }

            throw $e;
        }
    }
}
