<?php

/**
 * This file is part of project wilbur-yu/hyperf-cache-ext.
 *
 * @author   wenbo@wenber.club
 * @link     https://github.com/wilbur-yu
 */

namespace WilburYu\HyperfCacheExt\Exception\Handler;

use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use WilburYu\HyperfCacheExt\Exception\CounterRateLimiterException;

class CounterRateLimiterExceptionHandler extends ExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        if ($this->isValid($throwable) && method_exists($throwable, 'getHeaders')) {
            $this->stopPropagation();
            $headers = $throwable->getHeaders();
            foreach ($headers as $k => $v) {
                $response = $response->withAddedHeader($k, $v);
            }

            return $response->withStatus($throwable->getCode())->withBody(new SwooleStream($throwable->getMessage()));
        }

        return $response;
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof CounterRateLimiterException;
    }
}
