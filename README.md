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
    'max_attempts' => 5,  // 最大允许次数
    'decay_minutes' => 1, // 限流单位时间
    'prefix' => 'counter-rate-limit:', // key 前缀
    'for' => [
        'common' => static function (\Hyperf\HttpServer\Contract\RequestInterface $request) {
            return Limit::perMinute(3);
        },
    ],
    'key' => ThrottleRequest::key(),
],
```
- `for` 即对应 `Laravel Facade` `RateLimiter::for(callable)`, 
> 在服务启动时, 监听器会收集该命名限制器数组, 供在注解中使用 `for` 参数引用. 在注解切面执行时, 会将当前请求 `\Hyperf\HttpServer\Contract\RequestInterface` 实例注入到该命名闭包.
- `key` 默认为当前请求 `fullUrl` + `ip`. 支持字符串与闭包.

2. 在exceptions配置文件中增加:

```php
\WilburYu\HyperfCacheExt\Exception\Handler\CounterRateLimitException::class
```
> 可选, 也可自行捕获, 该异常自带一个 `getHeaders` 方法, 值为: array('X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Retry-After', 'X-RateLimit-Reset')

# 使用

在控制器中使用计数器限速注解

```php
#[CounterRateLimitWithRedis(maxAttempts: 5, decayMinutes: 1)]
or
#[CounterRateLimit(for: "common")]
```

> 注解参数同配置文件, 优先级为注解>配置>默认.
> 使用 `for` 时, `max_attempts` 和 `decay_minutes` 不起作用.

如果你的缓存驱动不是 `redis`, 可以使用 `CounterRateLimit` 注解,反之则直接使用 `CounterRateLimitWithRedis` 注解即可.

在其他地方使用限速时, 可以使用辅助函数 `counter_limiter()`, 使用方法同 `laravel`中的 `RateLimiter Facade`, 可参考 [Laravel 限流文档](https://learnku.com/docs/laravel/8.5/current-limiting/11453)

```php
$executed = counter_limiter()->attempt('send-sms:'.$user->id,2,function(){
    // send sms logic
});
if (!$executed) {
    return 'Too many messages sent!';
}
```
# 感谢
[Laravel Cache](https://github.com/illuminate/cache)
[Hyperf Rate-Limit](https://github.com/hyperf/rate-limit)