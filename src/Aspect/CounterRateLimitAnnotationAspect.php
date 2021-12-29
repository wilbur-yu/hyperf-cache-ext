<?php

declare(strict_types=1);

/**
 * This file is part of project wilbur-yu/hyperf-cache-ext.
 *
 * @author   wenbo@wenber.club
 * @link     https://github.com/wilbur-yu
 */

namespace WilburYu\HyperfCacheExt\Aspect;

use WilburYu\HyperfCacheExt\Annotation\CounterRateLimit;
use WilburYu\HyperfCacheExt\Exception\ThrottleRequestException;
use WilburYu\HyperfCacheExt\CounterLimiter;
use WilburYu\HyperfCacheExt\CounterLimiting\Unlimited;
use WilburYu\HyperfCacheExt\Redis\Limiters\DurationLimiter;
use Closure;
use Hyperf\Contract\ContainerInterface;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\Di\Annotation\Aspect;
use Psr\Http\Message\RequestInterface;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\Redis;
use Psr\Http\Message\ResponseInterface;
use Hyperf\Utils\Arr;
use Hyperf\Utils\Context;
use Hyperf\Utils\InteractsWithTime;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Utils\Str;

#[Aspect]
class CounterRateLimitAnnotationAspect extends AbstractAspect
{
    use InteractsWithTime;

    public $annotations = [
        CounterRateLimit::class,
    ];
    protected array $config;

    protected bool $driverIsRedis = true;

    private array $annotationProperty;

    /**
     * The timestamp of the end of the current duration by key.
     */
    public array $decaysAt = [];

    /**
     * The number of remaining slots by key.
     */
    public array $remaining = [];

    /**
     * @param  ContainerInterface  $container
     * @param  RequestInterface    $request
     * @param  ResponseInterface   $response
     * @param  CounterLimiter      $limiter
     * @param  ConfigInterface     $config
     */
    public function __construct(
        protected ContainerInterface $container,
        protected RequestInterface $request,
        protected ResponseInterface $response,
        protected CounterLimiter $limiter,
        ConfigInterface $config,
    ) {
        $this->config = $this->parseConfig($config);
        $this->annotationProperty = get_object_vars(new CounterRateLimit());
        $this->driverHandler($config);
    }

    protected function driverHandler(ConfigInterface $config): void
    {
        $this->driverIsRedis = $this->config['driver'] === 'redis';
        if ($this->driverIsRedis) {
            $this->config['prefix'] = $config->get('cache.default.prefix').$this->config['prefix'];
        }
    }

    /**
     * @param  \Hyperf\Di\Aop\ProceedingJoinPoint  $proceedingJoinPoint
     *
     * @throws \Hyperf\Di\Exception\Exception
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @return ResponseInterface
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint): ResponseInterface
    {
        $annotation = $this->getWeightingAnnotation($this->getAnnotations($proceedingJoinPoint));

        $counterKey = $annotation->key ?? null;
        if (is_callable($counterKey)) {
            $counterKey = $counterKey($this->request);
        }
        if (!$counterKey) {
            $counterKey = $this->request->fullUrl().':'.$this->request->server('remote_addr');
        }
        $namedLimiter = $annotation->named ?? null;
        if (is_string($namedLimiter) && !is_null($limiter = CounterLimiter::limiter($namedLimiter))) {
            return $this->handleRequestUsingNamedLimiter($namedLimiter, $limiter, $proceedingJoinPoint);
        }

        return $this->handleRequest(
            [
                (object)[
                    'key' => $annotation->prefix.$counterKey,
                    'maxAttempts' => $annotation->maxAttempts,
                    'decayMinutes' => $annotation->decayMinutes,
                ],
            ],
            $proceedingJoinPoint
        );
    }

    /**
     * @param  string                              $limiterName
     * @param  \Closure                            $limiter
     * @param  \Hyperf\Di\Aop\ProceedingJoinPoint  $proceedingJoinPoint
     *
     * @throws \Hyperf\Di\Exception\Exception
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @return ResponseInterface
     */
    protected function handleRequestUsingNamedLimiter(
        string $limiterName,
        Closure $limiter,
        ProceedingJoinPoint $proceedingJoinPoint
    ): ResponseInterface {
        $result = $limiter($this->request);
        if ($result instanceof Unlimited) {
            return $proceedingJoinPoint->process();
        }

        return $this->handleRequest(
            collect(Arr::wrap($result))->map(function ($limit) use ($limiterName) {
                return (object)[
                    'key' => $this->config['prefix'].md5($limiterName.$limit->key),
                    'maxAttempts' => $limit->maxAttempts,
                    'decayMinutes' => $limit->decayMinutes,
                ];
            })->all(),
            $proceedingJoinPoint
        );
    }

    /**
     * @param  array                               $limits
     * @param  \Hyperf\Di\Aop\ProceedingJoinPoint  $proceedingJoinPoint
     *
     * @throws \Hyperf\Di\Exception\Exception
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function handleRequest(array $limits, ProceedingJoinPoint $proceedingJoinPoint): ResponseInterface
    {
        foreach ($limits as $limit) {
            $tooManyAttempts =
                $this->driverIsRedis ?
                    $this->tooManyAttemptsWithRedis($limit->key, $limit->maxAttempts, $limit->decayMinutes) :
                    $this->limiter->tooManyAttempts($limit->key, $limit->maxAttempts);
            if ($tooManyAttempts) {
                throw $this->buildException($limit->key, $limit->maxAttempts);
            }

            !$this->driverIsRedis && $this->limiter->hit($limit->key, $limit->decayMinutes * 60);
        }

        $response = $proceedingJoinPoint->process();
        Context::set(ResponseInterface::class, $response);

        foreach ($limits as $limit) {
            if ($this->driverIsRedis) {
                $remainingAttempts = $this->calculateRemainingAttempts($limit->key, $limit->maxAttempts);
            } else {
                $remainingAttempts = $this->calculateRemainingAttemptsWithRedis($limit->key, $limit->maxAttempts);
            }
            $response = $this->addHeaders(
                $response,
                $limit->maxAttempts,
                $remainingAttempts
            );
        }

        return $response;
    }

    public function getWeightingAnnotation(array $annotations): CounterRateLimit
    {
        $property = array_merge($this->annotationProperty, $this->config);
        /** @var null|CounterRateLimit $annotation */
        foreach ($annotations as $annotation) {
            if (!$annotation) {
                continue;
            }
            $property = array_merge($property, array_filter(get_object_vars($annotation)));
        }

        return new CounterRateLimit($property);
    }

    public function getAnnotations(ProceedingJoinPoint $proceedingJoinPoint): array
    {
        $metadata = $proceedingJoinPoint->getAnnotationMetadata();

        return [
            $metadata->class[CounterRateLimit::class] ?? null,
            $metadata->method[CounterRateLimit::class] ?? null,
        ];
    }

    protected function parseConfig(ConfigInterface $config)
    {
        if ($config->has('cache.limiter')) {
            $limiterConfig = $config->get('cache.limiter');
        } else {
            $limiterConfig = [
                'max_attempts' => 60,
                'decay_minutes' => 1,
            ];
        }
        foreach ($limiterConfig as $k => $v) {
            $limiterConfig[Str::of($k)->lower()->camel()->__toString()] = $v;
        }

        return $limiterConfig;
    }

    /**
     * Create a 'too many attempts' exception.
     *
     * @param  string  $key
     * @param  int     $maxAttempts
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @return ThrottleRequestException
     */
    protected function buildException(
        string $key,
        int $maxAttempts
    ): ThrottleRequestException {
        if ($this->driverIsRedis) {
            $retryAfter = $this->getTimeUntilNextRetry($key);
        } else {
            $retryAfter = $this->getTimeUntilNextRetryWithRedis($key);
        }

        $headers = $this->getHeaders(
            $maxAttempts,
            $this->calculateRemainingAttempts($key, $maxAttempts, $retryAfter),
            $retryAfter
        );

        return new ThrottleRequestException(429, headers: $headers);
    }

    /**
     * Get the number of seconds until the next retry.
     *
     * @param  string  $key
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @return int
     */
    protected function getTimeUntilNextRetry(string $key): int
    {
        return $this->limiter->availableIn($key);
    }

    /**
     * Add the limit header information to the given response.
     *
     * @param  \Psr\Http\Message\ResponseInterface  $response
     * @param  int                                  $maxAttempts
     * @param  int                                  $remainingAttempts
     * @param  int|null                             $retryAfter
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function addHeaders(
        ResponseInterface $response,
        int $maxAttempts,
        int $remainingAttempts,
        int $retryAfter = null
    ): ResponseInterface {
        $headers = $this->getHeaders($maxAttempts, $remainingAttempts, $retryAfter, $response);
        if (method_exists($response, 'withAddedHeaders')) {
            $response->withAddedHeaders($headers);
        } else {
            foreach ($headers as $k => $v) {
                $response->withAddedHeader($k, $v);
            }
        }

        return $response;
    }

    /**
     * Get the limit headers information.
     *
     * @param  int                     $maxAttempts
     * @param  int                     $remainingAttempts
     * @param  int|null                $retryAfter
     * @param  ResponseInterface|null  $response
     *
     * @return array
     */
    protected function getHeaders(
        int $maxAttempts,
        int $remainingAttempts,
        int $retryAfter = null,
        ?ResponseInterface $response = null
    ): array {
        if ($response && !is_null($response->getHeaderLine('X-RateLimit-Remaining'))
            && (int)$response->getHeaderLine(
                'X-RateLimit-Remaining'
            ) <= $remainingAttempts) {
            return [];
        }

        $headers = [
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => $remainingAttempts,
        ];

        if (!is_null($retryAfter)) {
            $headers['Retry-After'] = $retryAfter;
            $headers['X-RateLimit-Reset'] = $this->availableAt($retryAfter);
        }

        return $headers;
    }

    /**
     * Calculate the number of remaining attempts.
     *
     * @param  string    $key
     * @param  int       $maxAttempts
     * @param  int|null  $retryAfter
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @return int
     */
    protected function calculateRemainingAttempts(string $key, int $maxAttempts, ?int $retryAfter = null): int
    {
        return is_null($retryAfter) ? $this->limiter->retriesLeft($key, $maxAttempts) : 0;
    }

    /**
     * Calculate the number of remaining attempts.
     *
     * @param  string    $key
     * @param  int       $maxAttempts
     * @param  int|null  $retryAfter
     *
     * @return int
     */
    protected function calculateRemainingAttemptsWithRedis(string $key, int $maxAttempts, int $retryAfter = null): int
    {
        return is_null($retryAfter) ? $this->remaining[$key] : 0;
    }

    /**
     * Get the number of seconds until the lock is released.
     *
     * @param  string  $key
     *
     * @return int
     */
    protected function getTimeUntilNextRetryWithRedis(string $key): int
    {
        return $this->decaysAt[$key] - $this->currentTime();
    }

    /**
     * Determine if the given key has been "accessed" too many times.
     *
     * @param  string  $key
     * @param  int     $maxAttempts
     * @param  int     $decayMinutes
     *
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @return mixed
     */
    protected function tooManyAttemptsWithRedis(string $key, int $maxAttempts, int $decayMinutes): mixed
    {
        $limiter = new DurationLimiter(
            $this->container->get(Redis::class),
            $key,
            $maxAttempts,
            $decayMinutes * 60
        );

        return tap(!$limiter->acquire(), function () use ($key, $limiter) {
            [$this->decaysAt[$key], $this->remaining[$key]] = [
                $limiter->decaysAt,
                $limiter->remaining,
            ];
        });
    }
}
