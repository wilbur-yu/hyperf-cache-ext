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
use DateInterval;
use DateTimeInterface;
use Hyperf\Redis\Redis;
use Hyperf\Utils\InteractsWithTime;

class DurationLimiterBuilder
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
     * The maximum number of locks that can be obtained per time window.
     *
     * @var int
     */
    public int $maxLocks;

    /**
     * The amount of time the lock window is maintained.
     *
     * @var int
     */
    public int $decay;

    /**
     * The amount of time to block until a lock is available.
     *
     * @var int
     */
    public int $timeout = 3;

    /**
     * Create a new builder instance.
     *
     * @param  Redis   $connection
     * @param  string  $name
     *
     * @return void
     */
    public function __construct(Redis $connection, string $name)
    {
        $this->name = $name;
        $this->connection = $connection;
    }

    /**
     * Set the maximum number of locks that can be obtained per time window.
     *
     * @param  int  $maxLocks
     *
     * @return $this
     */
    public function allow(int $maxLocks): self
    {
        $this->maxLocks = $maxLocks;

        return $this;
    }

    /**
     * Set the amount of time the lock window is maintained.
     *
     * @param  DateInterval|DateTimeInterface|int  $decay
     *
     * @return $this
     */
    public function every(DateInterval|DateTimeInterface|int $decay): self
    {
        $this->decay = $this->secondsUntil($decay);

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
     * @throws LimiterTimeoutException
     * @return mixed
     *
     */
    public function then(callable $callback, callable $failure = null): mixed
    {
        try {
            return (new DurationLimiter(
                $this->connection,
                $this->name,
                $this->maxLocks,
                $this->decay
            ))->block($this->timeout, $callback);
        } catch (LimiterTimeoutException $e) {
            if ($failure) {
                return $failure($e);
            }

            throw $e;
        }
    }
}
