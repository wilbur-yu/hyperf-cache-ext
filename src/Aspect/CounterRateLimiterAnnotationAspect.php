<?php

declare(strict_types=1);

/**
 * This file is part of project wilbur-yu/hyperf-cache-ext.
 *
 * @author   wenbo@wenber.club
 * @link     https://github.com/wilbur-yu
 */

namespace WilburYu\HyperfCacheExt\Aspect;

use WilburYu\HyperfCacheExt\Annotation\CounterRateLimiter;
use WilburYu\HyperfCacheExt\Annotation\CounterRateLimiterWithRedis;
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
use Psr\SimpleCache\InvalidArgumentException;
use WilburYu\HyperfCacheExt\Exception\CounterRateLimiterException;

#[Aspect]
class CounterRateLimiterAnnotationAspect extends AbstractAspect
{
    use InteractsWithTime;
    use Common {
        parseConfig as baseParseConfig;
    }

    public $annotations = [
        CounterRateLimiter::class,
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
        $this->annotationProperty = get_object_vars(new CounterRateLimiter());
    }

    /**
     * @param  \Hyperf\Di\Aop\ProceedingJoinPoint  $proceedingJoinPoint
     *
     * @throws \Hyperf\Di\Exception\Exception
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @return ResponseInterface
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint): ResponseInterface
    {
        $annotation = $this->getAnnotationObject($proceedingJoinPoint);
        $limiterKey = $this->getRateLimiterKey($annotation);
        $limiterName = $annotation->for ?? null;
        if (is_string($limiterName) && !is_null($limiter = CounterLimiter::limiter($limiterName))) {
            return $this->handleRequestUsingNamedLimiter($limiterName, $limiter, $annotation, $proceedingJoinPoint);
        }

        return $this->handleRequest(
            [
                (object)[
                    'key' => $annotation->prefix.$limiterKey,
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
        /** @var object<string, int, int> $limit */
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

    public function getWeightingAnnotation(array $annotations): CounterRateLimiter
    {
        $whereNotNull = static function ($value) {
            return !is_null($value);
        };
        $property = array_merge($this->annotationProperty, $this->config);
        /** @var null|CounterRateLimiter $annotation */
        foreach ($annotations as $annotation) {
            if (!$annotation) {
                continue;
            }
            $property = array_merge($property, array_filter(get_object_vars($annotation), $whereNotNull));
        }

        return new CounterRateLimiter($property);
    }

    public function getAnnotations(ProceedingJoinPoint $proceedingJoinPoint): array
    {
        $metadata = $proceedingJoinPoint->getAnnotationMetadata();

        return [
            $metadata->class[CounterRateLimiter::class] ?? null,
            $metadata->class[CounterRateLimiterWithRedis::class] ?? null,

            $metadata->method[CounterRateLimiter::class] ?? null,
            $metadata->method[CounterRateLimiterWithRedis::class] ?? null,
        ];
    }

    /**
     * Create a 'too many attempts' exception.
     *
     * @param  string  $key
     * @param  int     $maxAttempts
     *
     * @throws InvalidArgumentException
     * @return CounterRateLimiterException
     */
    protected function buildException(
        string $key,
        int $maxAttempts
    ): CounterRateLimiterException {
        $retryAfter = $this->getTimeUntilNextRetry($key);

        $headers = $this->getHeaders(
            $maxAttempts,
            $this->calculateRemainingAttempts($key, $maxAttempts, $retryAfter),
            $retryAfter
        );

        return new CounterRateLimiterException(429, headers: $headers);
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

    protected function parseConfig(ConfigInterface $config)
    {
        $limiterConfig = $this->baseParseConfig($config);
        $limiterConfig['prefix'] .= 'counter:';

        return $limiterConfig;
    }
}
