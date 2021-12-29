<?php

use Hyperf\Utils\ApplicationContext;
use WilburYu\HyperfCacheExt\CacheInterface;
use WilburYu\HyperfCacheExt\CounterLimiter;

if (!function_exists('rate_limiter')) {
    function counter_limiter()
    {
        return ApplicationContext::getContainer()->get(CounterLimiter::class);
    }
}
if (!function_exists('cache')) {
    function cache(): CacheInterface
    {
        return ApplicationContext::getContainer()->get(CacheInterface::class);
    }
}