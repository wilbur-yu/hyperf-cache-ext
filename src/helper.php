<?php

use Hyperf\Utils\ApplicationContext;
use WilburYu\HyperfCacheExt\RateLimiter;

if (!function_exists('rate_limiter')) {
    function rate_limiter()
    {
        return ApplicationContext::getContainer()->get(RateLimiter::class);
    }
}