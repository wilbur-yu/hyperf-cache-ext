<?php

/**
 * This file is part of project wilbur-yu/hyperf-cache-ext.
 *
 * @author   wenbo@wenber.club
 * @link     https://github.com/wilbur-yu
 */

namespace WilburYu\HyperfCacheExt\Aspect;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\Utils\Str;
use Psr\Http\Message\ResponseInterface;
use WilburYu\HyperfCacheExt\Annotation\ConcurrencyRateLimiter;
use WilburYu\HyperfCacheExt\Annotation\CounterRateLimiter;
use WilburYu\HyperfCacheExt\Annotation\CounterRateLimiterWithRedis;
use WilburYu\HyperfCacheExt\Annotation\DurationRateLimiter;

trait Common
{
    protected function parseConfig(ConfigInterface $config)
    {
        if ($config->has('cache.limiter')) {
            $limiterConfig = $config->get('cache.limiter');
        } else {
            $limiterConfig = [
                'max_attempts' => 60,
                'decay_minutes' => 1,
            ];
        }
        foreach ($limiterConfig as $k => $v) {
            if (str_contains($k, '_')) {
                $limiterConfig[Str::of($k)->lower()->camel()->__toString()] = $v;
                unset($limiterConfig[$k]);
            }
        }

        return $limiterConfig;
    }

    protected function getRateLimiterKey($annotation): ?string
    {
        $rateLimiterKey = $annotation->key ?? null;
        if (is_callable($rateLimiterKey)) {
            $rateLimiterKey = $rateLimiterKey($this->request);
        }
        if (!$rateLimiterKey) {
            $rateLimiterKey = sha1($this->request->fullUrl().':'.$this->request->server('remote_addr'));
        }

        return $rateLimiterKey;
    }

    protected function getAnnotationObject(
        ProceedingJoinPoint $proceedingJoinPoint
    ): ConcurrencyRateLimiter|CounterRateLimiter|CounterRateLimiterWithRedis|DurationRateLimiter {
        return $this->getWeightingAnnotation($this->getAnnotations($proceedingJoinPoint));
    }

    /**
     * Add the limit header information to the given response.
     *
     * @param  \Psr\Http\Message\ResponseInterface  $response
     * @param  int                                  $maxAttempts
     * @param  int                                  $remainingAttempts
     * @param  int|null                             $retryAfter
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function addHeaders(
        ResponseInterface $response,
        int $maxAttempts,
        int $remainingAttempts,
        int $retryAfter = null
    ): ResponseInterface {
        $headers = $this->getHeaders($maxAttempts, $remainingAttempts, $retryAfter, $response);
        if (method_exists($response, 'withAddedHeaders')) {
            $response->withAddedHeaders($headers);
        } else {
            foreach ($headers as $k => $v) {
                $response->withAddedHeader($k, $v);
            }
        }

        return $response;
    }

    /**
     * Get the limit headers information.
     *
     * @param  int                     $maxAttempts
     * @param  int                     $remainingAttempts
     * @param  int|null                $retryAfter
     * @param  ResponseInterface|null  $response
     *
     * @return array
     */
    protected function getHeaders(
        int $maxAttempts,
        int $remainingAttempts,
        int $retryAfter = null,
        ?ResponseInterface $response = null
    ): array {
        if ($response && !is_null($response->getHeaderLine('X-RateLimit-Remaining'))
            && (int)$response->getHeaderLine(
                'X-RateLimit-Remaining'
            ) <= $remainingAttempts) {
            return [];
        }

        $headers = [
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => $remainingAttempts,
        ];

        if (!is_null($retryAfter)) {
            $headers['Retry-After'] = $retryAfter;
            $headers['X-RateLimit-Reset'] = $this->availableAt($retryAfter);
        }

        return $headers;
    }
}
