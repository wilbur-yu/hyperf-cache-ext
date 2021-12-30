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
class CounterRateLimit extends AbstractAnnotation
{
    /**
     * @var string|callable
     */
    public $key;
    /**
     * @var int|string
     */
    public $maxAttempts;
    /**
     * @var int
     */
    public $decayMinutes;
    /**
     * @var string
     */
    public $for;
    /**
     * @var string
     */
    public $prefix;
}
