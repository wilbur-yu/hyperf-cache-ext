<?php

declare(strict_types=1);

/**
 * This file is part of project wilbur-yu/hyperf-cache-ext.
 *
 * @author   wenbo@wenber.club
 * @link     https://github.com/wilbur-yu
 */

namespace WilburYu\HyperfCacheExt\Aspect;

use Hyperf\Context\Context;
use Hyperf\Di\Exception\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\SimpleCache\InvalidArgumentException;
use WilburYu\HyperfCacheExt\Annotation\CounterRateLimiterWithRedis;
use WilburYu\HyperfCacheExt\Redis\Limiters\DurationLimiter;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\Di\Annotation\Aspect;
use Hyperf\Redis\Redis;
use Psr\Http\Message\ResponseInterface;

use function Hyperf\Tappable\tap;

#[Aspect]
class CounterRateLimiterWithRedisAnnotationAspect extends CounterRateLimiterAnnotationAspect
{
    public array $annotations = [
        CounterRateLimiterWithRedis::class,
    ];

    /**
     * The timestamp of the end of the current duration by key.
     */
    public array $decaysAt = [];

    /**
     * The number of remaining slots by key.
     */
    public array $remaining = [];

    /**
     * @param  array                               $limits
     * @param ProceedingJoinPoint $proceedingJoinPoint
     *
     * @throws Exception
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws InvalidArgumentException
     * @return ResponseInterface
     */
    protected function handleRequest(array $limits, ProceedingJoinPoint $proceedingJoinPoint): ResponseInterface
    {
        foreach ($limits as $limit) {
            if ($this->tooManyAttempts($limit->key, $limit->maxAttempts, $limit->decayMinutes)) {
                throw $this->buildException($limit->key, $limit->maxAttempts);
            }
        }

        $response = $proceedingJoinPoint->process();
        Context::set(ResponseInterface::class, $response);

        foreach ($limits as $limit) {
            $response = $this->addHeaders(
                $response,
                $limit->maxAttempts,
                $this->calculateRemainingAttempts($limit->key, $limit->maxAttempts)
            );
        }

        return $response;
    }

    /**
     * Calculate the number of remaining attempts.
     *
     * @param  string    $key
     * @param  int       $maxAttempts
     * @param  int|null  $retryAfter
     *
     * @return int
     */
    protected function calculateRemainingAttempts(string $key, int $maxAttempts, int $retryAfter = null): int
    {
        return is_null($retryAfter) ? $this->remaining[$key] : 0;
    }

    /**
     * Get the number of seconds until the lock is released.
     *
     * @param  string  $key
     *
     * @return int
     */
    protected function getTimeUntilNextRetry(string $key): int
    {
        return $this->decaysAt[$key] - $this->currentTime();
    }

    /**
     * Determine if the given key has been "accessed" too many times.
     *
     * @param  string  $key
     * @param  int     $maxAttempts
     * @param  int     $decayMinutes
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @return mixed
     */
    protected function tooManyAttempts(string $key, int $maxAttempts, int $decayMinutes): mixed
    {
        $limiter = new DurationLimiter(
            $this->container->get(Redis::class),
            $key,
            $maxAttempts,
            $decayMinutes * 60
        );

        return tap(!$limiter->acquire(), function () use ($key, $limiter) {
            [$this->decaysAt[$key], $this->remaining[$key]] = [
                $limiter->decaysAt,
                $limiter->remaining,
            ];
        });
    }
}
