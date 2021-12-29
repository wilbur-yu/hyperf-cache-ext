<?php

declare(strict_types=1);

/**
 * This file is part of project wilbur-yu/hyperf-cache-ext.
 *
 * @author   wenbo@wenber.club
 * @link     https://github.com/wilbur-yu
 */

namespace WilburYu\HyperfCacheExt\CounterLimiting;

class Limit
{
    /**
     * The rate limit signature key.
     *
     * @var mixed|string
     */
    public mixed $key;

    /**
     * The maximum number of attempts allowed within the given number of minutes.
     *
     * @var int
     */
    public int $maxAttempts;

    /**
     * The number of minutes until the rate limit is reset.
     *
     * @var int
     */
    public int $decayMinutes;

    /**
     * The response generator callback.
     *
     * @var callable
     */
    public $responseCallback;

    /**
     * Create a new limit instance.
     *
     * @param  mixed|string  $key
     * @param  int           $maxAttempts
     * @param  int           $decayMinutes
     *
     * @return void
     */
    public function __construct(string $key = '', int $maxAttempts = 60, int $decayMinutes = 1)
    {
        $this->key = $key;
        $this->maxAttempts = $maxAttempts;
        $this->decayMinutes = $decayMinutes;
    }

    /**
     * Create a new rate limit.
     *
     * @param  int  $maxAttempts
     *
     * @return static
     */
    public static function perMinute(int $maxAttempts): static
    {
        return new static('', $maxAttempts);
    }

    /**
     * Create a new rate limit using minutes as decay time.
     *
     * @param  int  $decayMinutes
     * @param  int  $maxAttempts
     *
     * @return static
     */
    public static function perMinutes(int $decayMinutes, int $maxAttempts): static
    {
        return new static('', $maxAttempts, $decayMinutes);
    }

    /**
     * Create a new rate limit using hours as decay time.
     *
     * @param  int  $maxAttempts
     * @param  int  $decayHours
     *
     * @return static
     */
    public static function perHour(int $maxAttempts, int $decayHours = 1): static
    {
        return new static('', $maxAttempts, 60 * $decayHours);
    }

    /**
     * Create a new rate limit using days as decay time.
     *
     * @param  int  $maxAttempts
     * @param  int  $decayDays
     *
     * @return static
     */
    public static function perDay(int $maxAttempts, int $decayDays = 1): static
    {
        return new static('', $maxAttempts, 60 * 24 * $decayDays);
    }

    /**
     * Create a new unlimited rate limit.
     *
     * @return Unlimited
     */
    public static function none(): Unlimited
    {
        return new Unlimited();
    }

    /**
     * Set the key of the rate limit.
     *
     * @param  string  $key
     *
     * @return $this
     */
    public function by(string $key): self
    {
        $this->key = $key;

        return $this;
    }

    /**
     * Set the callback that should generate the response when the limit is exceeded.
     *
     * @param  callable  $callback
     *
     * @return $this
     */
    public function response(callable $callback): self
    {
        $this->responseCallback = $callback;

        return $this;
    }
}
