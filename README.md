# 说明

BETA

> 移植了 [Laravel Cache](https://github.com/laravel/framework) 组件的 rate-limiter.

并对 `\Psr\SimpleCache\CacheInterface` 进行了补充. 增加了以下方法:

- increment
- decrement
- add
- put

# 安装

```bash
composer require wilbur-yu/hyperf-cache-ext
```

# 配置

1. 修改cache配置文件:

```php
'default' => [
    'driver' => WilburYu\HyperfCacheExt\Driver\RedisDriver::class,
    'packer' => WilburYu\HyperfCacheExt\Utils\Packer\PhpSerializerPacker::class,
    'prefix' => env('APP_NAME', 'skeleton').':cache:',
],
'limiter' => [
    'prefix' => 'rate-limit:',    // key前缀
    'max_attempts' => 20,         // 最大允许数
    'decay_minutes' => 60,        // 限流单位时间
    'wait' => 250,                // 并发时, 获取锁最大等待毫秒数
    'timeout' => 1,               // 并发时, 获取锁超时秒数
    'for' => [
        'common' => static function (\Hyperf\HttpServer\Contract\RequestInterface $request) {
            return Limit::perMinute(3);
        },
    ],
    'key' => ThrottleRequest::key(),
],
```

- `for` 即对应 `Laravel Facade` `RateLimiter::for(callable)`,

> 在服务启动时, 监听器会收集该命名限制器数组, 供在注解中使用 `for` 参数引用. 在注解切面执行时, 会将当前请求 `\Hyperf\HttpServer\Contract\RequestInterface`
> 实例注入到该命名闭包.

- `key` 默认为当前请求 `fullUrl` + `ip`的`sha1`. 支持字符串与闭包.

2. 可捕获异常

- 适用于计数器限流
> WilburYu\HyperfCacheExt\Exception\CounterRateLimiterException::class
> 
> 该异常自带一个 `getHeaders` 方法, 值为: array('X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Retry-After', '
> X-RateLimit-Reset')
- 适用于漏斗与时间窗口限流
> WilburYu\HyperfCacheExt\Exception\LimiterTimeoutException::class

# 使用

在控制器中使用

- 计数器限流注解

```php
#[CounterRateLimiterWithRedis(maxAttempts: 5, decayMinutes: 1)]
or
#[CounterRateLimiter(for: "common")]
```

- 漏斗限流注解

```php
use WilburYu\HyperfCacheExt\Annotation\ConcurrencyRateLimiter;
// 1秒内最高支持并发请求5次
#[ConcurrencyRateLimiter(key: 'get.posts', maxAttempts: 5, decayMinutes: 1, timeout: 1, wait: 250)]
```

- 时间窗口限流注解

```php
use WilburYu\HyperfCacheExt\Annotation\DurationRateLimiter;
// 每 10 秒最多支持 100 个请求
#[DurationRateLimiter(key: 'get.posts', maxAttempts: 100, decayMinutes: 10, timeout: 1, wait: 750)]
- ```

> 注解参数同配置文件, 优先级为注解>配置>默认.
>
> 1. 在计数器注解中使用 `for` 时, `max_attempts` 和 `decay_minutes` 不起作用.
> 2. `timeout` 和 `wait` 参数, 适用于`漏斗`和`时间窗口`

如果你的缓存驱动不是 `redis`, 只可以使用 `CounterRateLimit` 注解.

反之则可以使用 `CounterRateLimitWithRedis/ConcurrencyRateLimiter/DurationRateLimiter` 注解.

在其他地方使用限速时, 可以使用辅助函数

1. counter_limiter()

使用方法同 `Laravel`中的 `RateLimiter Facade`,
可参考 [Laravel 限流文档](https://learnku.com/docs/laravel/8.5/current-limiting/11453)

```php
$executed = counter_limiter()->attempt('send-sms:'.$user->id,2,function(){
    // send sms logic
});
if (!$executed) {
    return 'Too many messages sent!';
}
```

2. concurrency_limiter()

使用方法同 `Laravel` 中的 `Redis::funnel` 门面代理方法
可参考[高级限流器: 限定并发请求访问上限](https://laravelacademy.org/post/22188#toc-2)

```php
$executed = concurrency_limiter('key')->limit(100)->then(function(){
    // send sms logic
}, function(){
    // 异常上时调用
    abort(429);
});
```

3. duration_limiter()

使用方法同 `Laravel` 中的 `Redis::throttle` 门面代理方法
可参考[高级限流器: 限定单位时间访问上限](https://laravelacademy.org/post/22188#toc-3)

```php
$executed = concurrency_limiter('key')->allow(100)->every(10)->then(function(){
    // send sms logic
}, function(){
    // 异常上时调用
    abort(429);
});
```

# 感谢

[Laravel Cache](https://github.com/illuminate/cache)

[Hyperf Rate-Limit](https://github.com/hyperf/rate-limit)