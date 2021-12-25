<?php

declare(strict_types=1);

namespace WilburYu\HyperfCacheExt\Exception;

use RuntimeException;

class ThrottleRequestException extends RuntimeException
{
    public function __construct(int $code, ?string $message = null, protected array $headers = [])
    {
        parent::__construct($message, $code);
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
}
