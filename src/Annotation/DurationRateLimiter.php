<?php

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
class DurationRateLimiter extends AbstractAnnotation
{
    public function __construct(
        public $key = '',
        public string|int $maxAttempts = 10,
        public int $decayMinutes = 1,
        public string $prefix = 'hyperf-cache-ext',
        public int $timeout = 1,
        public int $wait = 1
    ) {
        if (is_array($this->key)) {
            $tmp = $this->key;
            $this->key = $tmp['key'];
            $this->maxAttempts = $tmp['maxAttempts'];
            $this->decayMinutes = $tmp['decayMinutes'];
            $this->prefix = $tmp['prefix'];
            $this->timeout = $tmp['timeout'];
            $this->wait = $tmp['wait'];
            unset($tmp);
        }
    }
}
