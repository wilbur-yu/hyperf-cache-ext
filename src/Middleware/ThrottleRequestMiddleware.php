<?php

declare(strict_types=1);

namespace WilburYu\HyperfCacheExt\Middleware;

use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;
use WilburYu\HyperfCacheExt\Exception\ThrottleRequestException;
use Closure;
use WilburYu\HyperfCacheExt\RateLimiter;
use WilburYu\HyperfCacheExt\RateLimiting\Unlimited;
use WilburYu\HyperfCacheExt\Exception\HttpResponseException;
use Hyperf\Utils\Arr;
use Hyperf\Utils\Context;
use Hyperf\Utils\InteractsWithTime;
use Hyperf\Utils\Str;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ThrottleRequestMiddleware
{
    use InteractsWithTime;

    protected RateLimiter $limiter;
    protected array $config;

    /**
     * @param  \Psr\Container\ContainerInterface  $container
     *
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $this->limiter = $container->get(RateLimiter::class);
        $this->config = $container->get(ConfigInterface::class)->get('cache.limiter');
    }

    /**
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     * @param  \Psr\Http\Server\RequestHandlerInterface  $handler
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (is_string($this->config['max_attempts'])
            && func_num_args() === 3
            && !is_null($limiter = $this->limiter->limiter($this->config['max_attempts']))) {
            return $this->handleRequestUsingNamedLimiter($request, $handler, $this->config['max_attempts'], $limiter);
        }

        return $this->handleRequest(
            $request,
            $handler,
            [
                (object)[
                    'key' => $this->config['prefix'].$this->resolveRequestSignature($request),
                    'maxAttempts' => $this->resolveMaxAttempts($this->config['max_attempts']),
                    'decayMinutes' => $this->config['decay_minutes'],
                    'responseCallback' => null,
                ],
            ]
        );
    }

    /**
     * laravel RateLimiter 门面在 RouteServiceProvider 类的 configureRateLimiting 方法中定义了各类自定义的闭包配置后
     * 然后使用中间件传参, 如: throttle:uploads
     * 这时, 会调用该方法
     *
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     * @param  \Psr\Http\Server\RequestHandlerInterface  $handler
     * @param  string                                    $limiterName
     * @param  \Closure                                  $limiter
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function handleRequestUsingNamedLimiter(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
        string $limiterName,
        Closure $limiter
    ): ResponseInterface {
        $limiterResponse = $limiter($request);

        if ($limiterResponse instanceof ResponseInterface) {
            return $limiterResponse;
        }

        if ($limiterResponse instanceof Unlimited) {
            return $handler->handle($request);
        }

        return $this->handleRequest(
            $request,
            $handler,
            collect(Arr::wrap($limiterResponse))->map(function ($limit) use ($limiterName) {
                return (object)[
                    'key' => md5($limiterName.$limit->key),
                    'maxAttempts' => $limit->maxAttempts,
                    'decayMinutes' => $limit->decayMinutes,
                    'responseCallback' => $limit->responseCallback,
                ];
            })->all()
        );
    }

    /**
     * @param  \Psr\Http\Message\ServerRequestInterface  $request
     * @param  \Psr\Http\Server\RequestHandlerInterface  $handler
     * @param  array                                     $limits
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function handleRequest(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
        array $limits
    ): ResponseInterface {
        foreach ($limits as $limit) {
            if ($this->limiter->tooManyAttempts($limit->key, $limit->maxAttempts)) {
                throw $this->buildException($request, $limit->key, $limit->maxAttempts, $limit->responseCallback);
            }

            $this->limiter->hit($limit->key, $limit->decayMinutes * 60);
        }

        $response = $handler->handle($request);

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

    /**
     * Resolve the number of attempts if the user is authenticated or not.
     *
     * @param  int|string  $maxAttempts
     *
     * @return int
     */
    protected function resolveMaxAttempts(int|string $maxAttempts): int
    {
        $isLogin = self::isLogin();
        if (Str::contains((string)$maxAttempts, '|')) {
            $maxAttempts = explode('|', $maxAttempts, 2)[$isLogin ? 1 : 0];
        }

        if (!is_numeric($maxAttempts) && $isLogin) {
            $maxAttempts = (int)self::user()->{$maxAttempts};
        }

        return (int)$maxAttempts;
    }

    /**
     * Resolve request signature.
     *
     * @param  ServerRequestInterface  $request
     *
     * @throws \RuntimeException
     * @return string
     *
     */
    protected function resolveRequestSignature(ServerRequestInterface $request): string
    {
        if (self::isLogin() && $user = self::user()) {
            return sha1((string)$user->getAuthIdentifier());
        }

        return sha1($request->fullUrl().'|'.get_client_ip());
        // throw new RuntimeException('Unable to generate the request signature. Route unavailable.');
    }

    /**
     * Create a 'too many attempts' exception.
     *
     * @param  ServerRequestInterface  $request
     * @param  string                  $key
     * @param  int                     $maxAttempts
     * @param  callable|null           $responseCallback
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @return HttpResponseException|ThrottleRequestException
     */
    protected function buildException(
        ServerRequestInterface $request,
        string $key,
        int $maxAttempts,
        ?callable $responseCallback = null
    ): HttpResponseException|ThrottleRequestException {
        $retryAfter = $this->getTimeUntilNextRetry($key);

        $headers = $this->getHeaders(
            $maxAttempts,
            $this->calculateRemainingAttempts($key, $maxAttempts, $retryAfter),
            $retryAfter
        );

        return is_callable($responseCallback)
            ? new HttpResponseException($responseCallback($request, $headers))
            : new ThrottleRequestException(HttpCode::TOO_MANY_REQUESTS, headers: $headers);
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
        if (method_exists($response, 'withCustomHeaders')) {
            $response->withCustomHeaders($headers);
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
