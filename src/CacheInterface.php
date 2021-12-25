<?php

declare(strict_types=1);

/**
 * This file is part of project wilbur-yu/hyperf-cache-ext.
 *
 * @author   wenbo@wenber.club
 * @link     https://github.com/wilbur-yu
 */

namespace WilburYu\HyperfCacheExt;

interface CacheInterface extends \Psr\SimpleCache\CacheInterface
{
    public function increment(string $key, int $value = 1): int|bool;

    public function decrement(string $key, int $value = 1): int|bool;

    public function add(string $key, mixed $value, int $seconds): bool;

    public function put(string $key, mixed $value, int $seconds): bool;
}
