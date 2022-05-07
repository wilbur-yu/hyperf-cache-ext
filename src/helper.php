<?php

/**
 * This file is part of project wilbur-yu/hyperf-cache-ext.
 *
 * @author   wenbo@wenber.club
 * @link     https://github.com/wilbur-yu
 */

use Hyperf\Utils\ApplicationContext;
use WilburYu\HyperfCacheExt\CacheInterface;
use WilburYu\HyperfCacheExt\CounterLimiter;
use WilburYu\HyperfCacheExt\Redis\Limiters\ConcurrencyLimiterBuilder;
use WilburYu\HyperfCacheExt\Redis\Limiters\DurationLimiterBuilder;

if (!function_exists('counter_limiter')) {
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
if (!function_exists('concurrency_limiter')) {
    function concurrency_limiter(string $name): ConcurrencyLimiterBuilder
    {
        return ApplicationContext::getContainer()->get(
            ConcurrencyLimiterBuilder::class
        )->name($name);
    }
}
if (!function_exists('duration_limiter')) {
    function duration_limiter(string $name): DurationLimiterBuilder
    {
        return ApplicationContext::getContainer()->get(
            DurationLimiterBuilder::class
        )->name($name);
    }
}