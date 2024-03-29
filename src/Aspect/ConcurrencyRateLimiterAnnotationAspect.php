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
use Hyperf\Support\Traits\InteractsWithTime;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use WilburYu\HyperfCacheExt\Annotation\ConcurrencyRateLimiter;
use WilburYu\HyperfCacheExt\Redis\Limiters\ConcurrencyLimiterBuilder;

use function Hyperf\Support\make;

#[Aspect]
class ConcurrencyRateLimiterAnnotationAspect extends AbstractAspect
{
    use InteractsWithTime;
    use Common {
        parseConfig as baseParseConfig;
    }

    public array $annotations = [
        ConcurrencyRateLimiter::class,
    ];

    protected array $config;

    private array $annotationProperty;

    protected RequestInterface $request;

    /**
     * @param ContainerInterface $container
     *
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function __construct(
        protected ContainerInterface $container
    ) {
        $this->request = $container->get(RequestInterface::class);
        $this->config = $this->parseConfig($container->get(ConfigInterface::class));
        $this->annotationProperty = get_object_vars(new ConcurrencyRateLimiter());
    }

    /**
     * @param \Hyperf\Di\Aop\ProceedingJoinPoint $proceedingJoinPoint
     *
     * @return \Psr\Http\Message\ResponseInterface
     * @throws \Hyperf\Di\Exception\Exception
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint): ResponseInterface
    {
        $annotation = $this->getAnnotationObject($proceedingJoinPoint);
        $limiterKey = $annotation->prefix . $this->getRateLimiterKey($annotation);

        $concurrentRateLimiter = make(ConcurrencyLimiterBuilder::class);

        return $concurrentRateLimiter->name($limiterKey)
            ->limit($annotation->maxAttempts)
            ->block($annotation->timeout ?? null)
            ->wait($annotation->wait ?? null)
            ->releaseAfter(
                $annotation->decayMinutes * 60
            )
            ->then(
                fn() => $proceedingJoinPoint->process()
            );
    }

    public function getAnnotations(ProceedingJoinPoint $proceedingJoinPoint): array
    {
        $metadata = $proceedingJoinPoint->getAnnotationMetadata();

        return [
            $metadata->class[ConcurrencyRateLimiter::class] ?? null,

            $metadata->method[ConcurrencyRateLimiter::class] ?? null,
        ];
    }

    public function getWeightingAnnotation(array $annotations): ConcurrencyRateLimiter
    {
        $whereNotNull = static function ($value) {
            return !is_null($value);
        };
        $property = array_merge($this->annotationProperty, $this->config);
        /** @var null|ConcurrencyRateLimiter $annotation */
        foreach ($annotations as $annotation) {
            if (!$annotation) {
                continue;
            }
            $property = array_merge($property, array_filter(get_object_vars($annotation), $whereNotNull));
        }

        return new ConcurrencyRateLimiter($property);
    }

    protected function parseConfig(ConfigInterface $config): array
    {
        $limiterConfig = $this->baseParseConfig($config);
        $limiterConfig['prefix'] .= 'concurrent:';

        return $limiterConfig;
    }
}
