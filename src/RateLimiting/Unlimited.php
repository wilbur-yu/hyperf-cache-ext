<?php

declare(strict_types=1);

/**
 * This file is part of project wilbur-yu/hyperf-cache-ext.
 *
 * @author   wenbo@wenber.club
 * @link     https://github.com/wilbur-yu
 */

namespace WilburYu\HyperfCacheExt\RateLimiting;

class Unlimited extends GlobalLimit
{
    /**
     * Create a new limit instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(PHP_INT_MAX);
    }
}
