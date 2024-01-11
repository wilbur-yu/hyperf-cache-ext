<?php

declare(strict_types=1);

namespace WilburYu\HyperfCacheExt;

use Closure;
use Hyperf\Support\Traits\InteractsWithTime;
use Psr\SimpleCache\CacheInterface;

use Psr\SimpleCache\InvalidArgumentException;

use function Hyperf\Tappable\tap;

class CounterLimiter
{
    use InteractsWithTime;

    private static array $limiters = [];

    public function __construct(protected CacheInterface $cache)
    {
    }

    /**
     * Register a named limiter configuration.
     *
     * @param  string    $name
     * @param Closure $callback
     *
     * @return void
     */
    public static function for(string $name, Closure $callback): void
    {
        self::$limiters[$name] = $callback;
    }

    /**
     * Get the given named rate limiter.
     *
     * @param  string  $name
     *
     * @return callable|null
     */
    public static function limiter(string $name): ?callable
    {
        return self::$limiters[$name] ?? null;
    }

    /**
     * Attempts to execute a callback if it's not limited.
     *
     * @param  string    $key
     * @param  int       $maxAttempts
     * @param Closure $callback
     * @param  int       $decaySeconds
     *
     * @return mixed
     *@throws InvalidArgumentException
     */
    public function attempt(string $key, int $maxAttempts, Closure $callback, int $decaySeconds = 60): mixed
    {
        if ($this->tooManyAttempts($key, $maxAttempts)) {
            return false;
        }

        return tap($callback() ?: true, function () use ($key, $decaySeconds) {
            $this->hit($key, $decaySeconds);
        });
    }

    /**
     * Determine if the given key has been "accessed" too many times.
     *
     * @param  string  $key
     * @param  int     $maxAttempts
     *
     * @throws InvalidArgumentException
     * @return bool
     */
    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        $key = $this->cleanRateLimiterKey($key);

        if ($this->attempts($key) >= $maxAttempts) {
            if ($this->cache->has($key.':timer')) {
                return true;
            }

            $this->resetAttempts($key);
        }

        return false;
    }

    /**
     * Increment the counter for a given key for a given decay time.
     *
     * @param  string  $key
     * @param  int     $decaySeconds
     *
     * @return int
     */
    public function hit(string $key, int $decaySeconds = 60): int
    {
        $key = $this->cleanRateLimiterKey($key);

        $this->cache->add(
            $key.':timer',
            $this->availableAt($decaySeconds),
            $decaySeconds
        );

        $added = $this->cache->add($key, 0, $decaySeconds);

        $hits = (int)$this->cache->increment($key);

        if (!$added && $hits === 1) {
            $this->cache->put($key, 1, $decaySeconds);
        }

        return $hits;
    }

    /**
     * Get the number of attempts for the given key.
     *
     * @param  string  $key
     *
     * @throws InvalidArgumentException
     * @return mixed
     */
    public function attempts(string $key): mixed
    {
        $key = $this->cleanRateLimiterKey($key);

        return $this->cache->get($key, 0);
    }

    /**
     * Reset the number of attempts for the given key.
     *
     * @param  string  $key
     *
     * @throws InvalidArgumentException
     * @return bool
     */
    public function resetAttempts(string $key): bool
    {
        $key = $this->cleanRateLimiterKey($key);

        return $this->cache->delete($key);
    }

    /**
     * Get the number of retries left for the given key.
     *
     * @param  string  $key
     * @param  int     $maxAttempts
     *
     * @throws InvalidArgumentException
     * @return int
     */
    public function remaining(string $key, int $maxAttempts): int
    {
        $key = $this->cleanRateLimiterKey($key);

        $attempts = $this->attempts($key);

        return $maxAttempts - $attempts;
    }

    /**
     * Get the number of retries left for the given key.
     *
     * @param  string  $key
     * @param  int     $maxAttempts
     *
     * @throws InvalidArgumentException
     * @return int
     */
    public function retriesLeft(string $key, int $maxAttempts): int
    {
        return $this->remaining($key, $maxAttempts);
    }

    /**
     * Clear the hits and lockout timer for the given key.
     *
     * @param  string  $key
     *
     * @throws InvalidArgumentException
     * @return void
     */
    public function clear(string $key): void
    {
        $key = $this->cleanRateLimiterKey($key);

        $this->resetAttempts($key);

        $this->cache->delete($key.':timer');
    }

    /**
     * Get the number of seconds until the "key" is accessible again.
     *
     * @param  string  $key
     *
     * @throws InvalidArgumentException
     * @return int
     */
    public function availableIn(string $key): int
    {
        $key = $this->cleanRateLimiterKey($key);

        return max(0, $this->cache->get($key.':timer') - $this->currentTime());
    }

    /**
     * Clean the rate limiter key from unicode characters.
     *
     * @param  string  $key
     *
     * @return string
     */
    public function cleanRateLimiterKey(string $key): string
    {
        return preg_replace('/&([a-z])[a-z]+;/i', '$1', htmlentities($key));
    }
}
