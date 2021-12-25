# 说明
暂未完成, 请勿使用.

> 移植了 [Laravel Cache](https://github.com/laravel/framework) 组件的 rate-limiter.

并对 `\Psr\SimpleCache\CacheInterface` 进行了补充. 增加了以下方法:

- increment
- decrement
- add
- put

# 使用

```bash
composer require wilbur-yu/hyperf-cache-ext
```

修改cache配置文件:

```php
'default' => [
    'driver' => WilburYu\HyperfCacheExt\Driver\RedisDriver::class,
    'packer' => WilburYu\HyperfCacheExt\Utils\Packer\PhpSerializerPacker::class,
    'prefix' => env('APP_NAME', 'skeleton').':cache:',
],
'limiter' => [
    'max_attempts' => 5,  // 5次
    'decay_minutes' => 1, // 1分钟
    'prefix' => env('APP_NAME', 'skeleton').':cache:throttle:',
],
```

在exceptions配置文件中增加:

```php
\WilburYu\HyperfCacheExt\Exception\Handler\ThrottleExceptionHandler::class
```

在控制器中使用限速中间件

```php
#[Middleware(\WilburYu\HyperfCacheExt\Middleware\ThrottleRequestMiddleware::class)]
or
#[Middleware(\WilburYu\HyperfCacheExt\Middleware\ThrottleRequestWithRedisMiddleware::class)]
```

如果没有补充自己的缓存驱动, 则直接使用 `ThrottleRequestWithRedisMiddleware` 中间件即可. 在其他地方使用限速时, 可以使用辅助函数 `rate_limiter()`, 使用方法同 `laravel`
中的 `RateLimiter Facade`
, 可参考 [Laravel 限流文档](https://learnku.com/docs/laravel/8.5/current-limiting/11453)

```php
$executed = rate_limiter()->attempt('send-sms:'.$user->id,2,function(){
    // send sms logic
});
if (!$executed) {
    return 'Too many messages sent!';
}
```