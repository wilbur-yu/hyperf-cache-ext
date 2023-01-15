<?php

/**
 * This file is part of project wilbur-yu/hyperf-cache-ext.
 *
 * @author   wenbo@wenber.club
 * @link     https://github.com/wilbur-yu
 */

namespace WilburYu\HyperfCacheExt\Aspect;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\Aspect;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use WilburYu\HyperfCacheExt\Annotation\DurationRateLimiter;
use WilburYu\HyperfCacheExt\Redis\Limiters\DurationLimiterBuilder;

#[Aspect]
class DurationRateLimiterAnnotationAspect extends AbstractAspect
{
    use Common {
        parseConfig as baseParseConfig;
    }

    public array $annotations = [
        DurationRateLimiter::class,
    ];

    protected array $config;

    private array $annotationProperty;

    protected RequestInterface $request;

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
        $this->config = $this->parseConfig($container->get(ConfigInterface::class));
        $this->annotationProperty = get_object_vars(new DurationRateLimiter());
    }

    /**
     * @param  \Hyperf\Di\Aop\ProceedingJoinPoint  $proceedingJoinPoint
     *
     * @throws \Hyperf\Di\Exception\Exception
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint): ResponseInterface
    {
        $annotation = $this->getAnnotationObject($proceedingJoinPoint);
        $limiterKey = $annotation->prefix.$this->getRateLimiterKey($annotation);

        $concurrentRateLimiter = make(DurationLimiterBuilder::class);

        return $concurrentRateLimiter->name($limiterKey)
            ->allow($annotation->maxAttempts)
            ->block($annotation->timeout ?? null)
            ->wait($annotation->wait ?? null)
            ->every(
                $annotation->decayMinutes * 60
            )
            ->then(
                fn () => $proceedingJoinPoint->process()
            );
    }

    public function getAnnotations(ProceedingJoinPoint $proceedingJoinPoint): array
    {
        $metadata = $proceedingJoinPoint->getAnnotationMetadata();

        return [
            $metadata->class[DurationRateLimiter::class] ?? null,

            $metadata->method[DurationRateLimiter::class] ?? null,
        ];
    }

    public function getWeightingAnnotation(array $annotations): DurationRateLimiter
    {
        $whereNotNull = static function ($value) {
            return !is_null($value);
        };
        $property = array_merge($this->annotationProperty, $this->config);
        /** @var null|DurationRateLimiter $annotation */
        foreach ($annotations as $annotation) {
            if (!$annotation) {
                continue;
            }
            $property = array_merge($property, array_filter(get_object_vars($annotation), $whereNotNull));
        }

        return new DurationRateLimiter($property);
    }

    protected function parseConfig(ConfigInterface $config): array
    {
        $limiterConfig = $this->baseParseConfig($config);
        $limiterConfig['prefix'] .= 'duration:';

        return $limiterConfig;
    }
}
