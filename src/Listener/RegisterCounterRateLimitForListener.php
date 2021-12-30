<?php

declare(strict_types=1);

namespace WilburYu\HyperfCacheExt\Listener;

use WilburYu\HyperfCacheExt\CounterLimiter;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Framework\Event\BootApplication;
use Hyperf\Event\Contract\ListenerInterface;

#[Listener]
class RegisterCounterRateLimitForListener implements ListenerInterface
{
    public function __construct(protected ConfigInterface $config)
    {
    }

    public function listen(): array
    {
        return [
            BootApplication::class,
        ];
    }

    public function process(object $event): void
    {
        $namedLimiters = $this->config->get('cache.limiter.for');
        foreach ($namedLimiters as $named => $limiter) {
            if (is_callable($limiter)) {
                CounterLimiter::for($named, $limiter);
            }
        }
    }
}
