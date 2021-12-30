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
use WilburYu\HyperfCacheExt\CounterLimiter;
use WilburYu\HyperfCacheExt\CounterLimiting\Unlimited;
use Closure;
use Hyperf\Contract\ContainerInterface;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\Di\Annotation\Aspect;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Contract\ConfigInterface;
use Psr\Http\Message\ResponseInterface;
use Hyperf\Utils\Arr;
use Hyperf\Utils\Context;
use Hyperf\Utils\InteractsWithTime;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Utils\Str;
use Psr\SimpleCache\InvalidArgumentException;
use WilburYu\HyperfCacheExt\Exception\ThrottleRequestException;

#[Aspect]
class CounterRateLimitAnnotationAspect extends AbstractAspect
{
    use InteractsWithTime;

    public $annotations = [
        CounterRateLimit::class,
    ];
    protected array $config;

    private array $annotationProperty;

    protected RequestInterface $request;
    protected CounterLimiter $limiter;

    /**
     * @param  ContainerInterface  $container
     *
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function __construct(
        protected ContainerInterface $container
    ) {
        $this->request = $container->get(RequestInterface::class);
        $this->limiter = $container->get(CounterLimiter::class);
        $this->config = $this->parseConfig($container->get(ConfigInterface::class));
        $this->annotationProperty = get_object_vars(new CounterRateLimit());
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
            return $this->handleRequestUsingNamedLimiter($namedLimiter, $limiter, $annotation, $proceedingJoinPoint);
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
     * @param                                      $annotation
     * @param  \Hyperf\Di\Aop\ProceedingJoinPoint  $proceedingJoinPoint
     *
     * @throws \Hyperf\Di\Exception\Exception
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @return ResponseInterface
     */
    protected function handleRequestUsingNamedLimiter(
        string $limiterName,
        Closure $limiter,
        $annotation,
        ProceedingJoinPoint $proceedingJoinPoint
    ): ResponseInterface {
        $result = $limiter($this->request);
        if ($result instanceof Unlimited) {
            return $proceedingJoinPoint->process();
        }

        return $this->handleRequest(
            collect(Arr::wrap($result))->map(function ($limit) use ($limiterName, $annotation) {
                return (object)[
                    'key' => $annotation->prefix.md5($limiterName.$limit->key),
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
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function handleRequest(array $limits, ProceedingJoinPoint $proceedingJoinPoint): ResponseInterface
    {
        foreach ($limits as $limit) {
            if ($this->limiter->tooManyAttempts($limit->key, $limit->maxAttempts)) {
                throw $this->buildException($limit->key, $limit->maxAttempts);
            }

            $this->limiter->hit($limit->key, $limit->decayMinutes * 60);
        }

        $response = $proceedingJoinPoint->process();
        Context::set(ResponseInterface::class, $response);

        foreach ($limits as $limit) {
            $response = $this->addHeaders(
                $response,
                $limit->maxAttempts,
                $this->calculateRemainingAttempts($limit->key, $limit->maxAttempts)
            );
        }

        return $response;
    }

    public function getWeightingAnnotation(array $annotations): CounterRateLimit
    {
        $whereNotNull = static function ($value) {
            return !is_null($value);
        };
        $property = array_merge($this->annotationProperty, $this->config);
        /** @var null|CounterRateLimit $annotation */
        foreach ($annotations as $annotation) {
            if (!$annotation) {
                continue;
            }
            $property = array_merge($property, array_filter(get_object_vars($annotation), $whereNotNull));
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
            if (str_contains($k, '_')) {
                $limiterConfig[Str::of($k)->lower()->camel()->__toString()] = $v;
                unset($limiterConfig[$k]);
            }
        }

        return $limiterConfig;
    }

    /**
     * Create a 'too many attempts' exception.
     *
     * @param  string  $key
     * @param  int     $maxAttempts
     *
     * @throws InvalidArgumentException
     * @return ThrottleRequestException
     */
    protected function buildException(
        string $key,
        int $maxAttempts
    ): ThrottleRequestException {
        $retryAfter = $this->getTimeUntilNextRetry($key);

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
}
