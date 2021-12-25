<?php

declare(strict_types=1);

/**
 * This file is part of project wilbur-yu/hyperf-cache-ext.
 *
 * @author   wenbo@wenber.club
 * @link     https://github.com/wilbur-yu
 */

namespace WilburYu\HyperfCacheExt\Middleware;

use Psr\Container\ContainerInterface;
use Hyperf\Redis\Redis;
use WilburYu\HyperfCacheExt\Redis\Limiters\DurationLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ThrottleRequestWithRedisMiddleware extends ThrottleRequestMiddleware
{
    /**
     * The timestamp of the end of the current duration by key.
     */
    public array $decaysAt = [];

    /**
     * The number of remaining slots by key.
     */
    public array $remaining = [];

    protected Redis $redis;

    /**
     * @param  \Psr\Container\ContainerInterface  $container
     *
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->redis = $container->get(Redis::class);
    }

    /**
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     * @param  \Psr\Http\Server\RequestHandlerInterface  $handler
     * @param  array                                     $limits
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function handleRequest(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
        array $limits
    ): ResponseInterface {
        foreach ($limits as $limit) {
            if ($this->tooManyAttempts($limit->key, $limit->maxAttempts, $limit->decayMinutes)) {
                throw $this->buildException($request, $limit->key, $limit->maxAttempts, $limit->responseCallback);
            }
        }

        $response = $handler->handle($request);

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
     * Determine if the given key has been "accessed" too many times.
     *
     * @param  string  $key
     * @param  int     $maxAttempts
     * @param  int     $decayMinutes
     *
     * @return mixed
     */
    protected function tooManyAttempts(string $key, int $maxAttempts, int $decayMinutes): mixed
    {
        $limiter = new DurationLimiter(
            $this->redis,
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
}
