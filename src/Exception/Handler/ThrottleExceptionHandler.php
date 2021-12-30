<?php

namespace WilburYu\HyperfCacheExt\Exception\Handler;

use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\Utils\Context;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use WilburYu\HyperfCacheExt\Exception\ThrottleRequestException;

class ThrottleExceptionHandler extends ExceptionHandler
{

    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        if ($this->isValid($throwable) && method_exists($throwable, 'getHeaders')) {
            $this->stopPropagation();
            $headers = $throwable->getHeaders();
            foreach ($headers as $k => $v) {
                $response = $response->withAddedHeader($k, $v);
            }
            Context::set(ResponseInterface::class, $response);
        }

        return $response;
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof ThrottleRequestException::class;
    }
}