<?php

declare(strict_types=1);

/**
 * This file is part of project wilbur-yu/hyperf-cache-ext.
 *
 * @author   wenbo@wenber.club
 * @link     https://github.com/wilbur-yu
 */

namespace WilburYu\HyperfCacheExt\Annotation;

use Attribute;
use Hyperf\Di\Annotation\AbstractAnnotation;

/**
 * @Annotation
 * @Target({"CLASS", "METHOD"})
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class CounterRateLimiter extends AbstractAnnotation
{
    public function __construct(
        public $key = '',
        public string|int $maxAttempts = 10,
        public int $decayMinutes = 1,
        public string $prefix = 'hyperf-cache-ext',
        public int $for = 1
    ) {
    }
}
