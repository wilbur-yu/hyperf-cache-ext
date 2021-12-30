<?php

namespace WilburYu\HyperfCacheExt\Exception\Handler;

use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\Utils\Context;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use WilburYu\HyperfCacheExt\Exception\CounterRateLimitException;

class CounterRateLimitExceptionHandler extends ExceptionHandler
{

    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        if ($throwable instanceof CounterRateLimitException::class && method_exists($throwable, 'getHeaders')) {
            $this->stopPropagation();
            $headers = $throwable->getHeaders();
            foreach ($headers as $k => $v) {
                $response = $response->withAddedHeader($k, $v);
            }
            $response = $response->withStatus($throwable->getCode());
            Context::set(ResponseInterface::class, $response);
        }

        return $response;
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof CounterRateLimitException::class;
    }
}