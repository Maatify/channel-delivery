<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use Maatify\ChannelDelivery\ApiKey\DTO\ApiKeyDTO;
use Maatify\ChannelDelivery\Http\Middleware\ApiKeyMiddleware;
use Maatify\ChannelDelivery\Http\Middleware\RateLimitMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Redis;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Tests for RateLimitMiddleware — covering:
 *   1. Redis key prefix (cd:rl:) — no collision on shared Redis
 *   2. Per-key rate limiting (identity = key:{id})
 *   3. IP fallback identity (identity = ip:{addr})
 *   4. 429 response with correct headers when limit exceeded
 *   5. Rate limit headers on allowed requests
 *   6. Fail-open when Redis is unavailable (factory returns null)
 *   7. Fail-open when Redis throws exception
 */
final class RateLimitMiddlewareTest extends TestCase
{
    private ResponseFactory $responseFactory;

    protected function setUp(): void
    {
        $this->responseFactory = new ResponseFactory();
    }

    // ── Helpers ───────────────────────────────────────────────

    /**
     * @param \Closure(): (\Redis|null) $factory
     */
    private function makeMiddleware(
        \Closure $factory,
        int $max    = 100,
        int $window = 60,
    ): RateLimitMiddleware {
        return new RateLimitMiddleware(
            redisFactory:    $factory,
            responseFactory: $this->responseFactory,
            logger:          new NullLogger(),
            maxRequests:     $max,
            windowSeconds:   $window,
        );
    }

    /**
     * @param \Closure(string): int $incrCallback
     */
    private function makeRedisMock(?\Closure $incrCallback = null, int $incrReturn = 1): Redis
    {
        /** @var Redis&MockObject $redis */
        $redis = $this->createMock(Redis::class);

        if ($incrCallback !== null) {
            $redis->method('incr')->willReturnCallback($incrCallback);
        } else {
            $redis->method('incr')->willReturn($incrReturn);
        }

        $redis->method('expireAt')->willReturn(true);

        return $redis;
    }

    private function makeRequest(?int $apiKeyId = null, string $ip = '1.2.3.4'): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => $ip]);

        if ($apiKeyId !== null) {
            $dto = new ApiKeyDTO(
                id:          $apiKeyId,
                name:        'test',
                keyHash:     'hash',
                ipWhitelist: [$ip],
                isActive:    true,
            );
            $request->method('getAttribute')
                ->with(ApiKeyMiddleware::ATTRIBUTE)
                ->willReturn($dto);
        } else {
            $request->method('getAttribute')->willReturn(null);
        }

        return $request;
    }

    private function makePassthroughHandler(): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(
            $this->responseFactory->createResponse(200)
        );
        return $handler;
    }

    // ── Redis key prefix ──────────────────────────────────────

    #[Test]
    public function testRedisKeyHasCdRlPrefix(): void
    {
        $capturedKey = null;
        $redis       = $this->makeRedisMock(
            incrCallback: static function (string $key) use (&$capturedKey): int {
                $capturedKey = $key;
                return 1;
            }
        );

        $middleware = $this->makeMiddleware(static fn () => $redis);
        $middleware->process($this->makeRequest(apiKeyId: 5), $this->makePassthroughHandler());

        $this->assertNotNull($capturedKey);
        $this->assertStringStartsWith('cd:rl:key:5:', (string) $capturedKey);
    }

    #[Test]
    public function testRedisKeyUsesIpWhenNoApiKeyAttribute(): void
    {
        $capturedKey = null;
        $redis       = $this->makeRedisMock(
            incrCallback: static function (string $key) use (&$capturedKey): int {
                $capturedKey = $key;
                return 1;
            }
        );

        $middleware = $this->makeMiddleware(static fn () => $redis);
        $middleware->process($this->makeRequest(apiKeyId: null, ip: '9.8.7.6'), $this->makePassthroughHandler());

        $this->assertNotNull($capturedKey);
        $this->assertStringStartsWith('cd:rl:ip:9.8.7.6:', (string) $capturedKey);
    }

    // ── Rate limit headers ────────────────────────────────────

    #[Test]
    public function testAllowedRequestHasRateLimitHeaders(): void
    {
        $redis      = $this->makeRedisMock(incrReturn: 10);
        $middleware = $this->makeMiddleware(static fn () => $redis, max: 100);
        $response   = $middleware->process($this->makeRequest(1), $this->makePassthroughHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('100', $response->getHeaderLine('X-RateLimit-Limit'));
        $this->assertSame('90', $response->getHeaderLine('X-RateLimit-Remaining'));
        $this->assertNotEmpty($response->getHeaderLine('X-RateLimit-Reset'));
    }

    #[Test]
    public function testRemainingIsZeroWhenAtLimit(): void
    {
        $redis      = $this->makeRedisMock(incrReturn: 100);
        $middleware = $this->makeMiddleware(static fn () => $redis, max: 100);
        $response   = $middleware->process($this->makeRequest(1), $this->makePassthroughHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    // ── 429 exceeded ─────────────────────────────────────────

    #[Test]
    public function testExceededLimitReturns429(): void
    {
        $redis      = $this->makeRedisMock(incrReturn: 101);
        $middleware = $this->makeMiddleware(static fn () => $redis, max: 100);
        $response   = $middleware->process($this->makeRequest(1), $this->makePassthroughHandler());

        $this->assertSame(429, $response->getStatusCode());
    }

    #[Test]
    public function test429ResponseHasCorrectHeaders(): void
    {
        $redis      = $this->makeRedisMock(incrReturn: 150);
        $middleware = $this->makeMiddleware(static fn () => $redis, max: 100);
        $response   = $middleware->process($this->makeRequest(1), $this->makePassthroughHandler());

        $this->assertSame('100', $response->getHeaderLine('X-RateLimit-Limit'));
        $this->assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));
        $this->assertNotEmpty($response->getHeaderLine('Retry-After'));
        $this->assertNotEmpty($response->getHeaderLine('X-RateLimit-Reset'));
    }

    #[Test]
    public function test429ResponseBodyContainsErrorCode(): void
    {
        $redis      = $this->makeRedisMock(incrReturn: 101);
        $middleware = $this->makeMiddleware(static fn () => $redis, max: 100);
        $response   = $middleware->process($this->makeRequest(1), $this->makePassthroughHandler());

        /** @var array{error: string} $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('rate_limit_exceeded', $body['error']);
    }

    // ── Fail-open ─────────────────────────────────────────────

    #[Test]
    public function testFailsOpenWhenRedisFactoryReturnsNull(): void
    {
        $middleware = $this->makeMiddleware(static fn () => null);
        $response   = $middleware->process($this->makeRequest(1), $this->makePassthroughHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function testFailsOpenWhenRedisThrowsException(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('incr')->willThrowException(new \RedisException('Connection refused'));

        $middleware = $this->makeMiddleware(static fn () => $redis);
        $response   = $middleware->process($this->makeRequest(1), $this->makePassthroughHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function testFailsOpenWhenRedisFactoryThrowsException(): void
    {
        $factory = static function (): ?\Redis {
            throw new \RuntimeException('Redis connection failed');
        };

        $middleware = $this->makeMiddleware($factory);
        $response   = $middleware->process($this->makeRequest(1), $this->makePassthroughHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    // ── TTL set on new key ────────────────────────────────────

    #[Test]
    public function testExpireAtCalledOnFirstRequest(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('incr')->willReturn(1);
        $redis->expects($this->once())->method('expireAt');

        $middleware = $this->makeMiddleware(static fn () => $redis);
        $middleware->process($this->makeRequest(1), $this->makePassthroughHandler());
    }

    #[Test]
    public function testExpireAtNotCalledOnSubsequentRequests(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('incr')->willReturn(5);
        $redis->expects($this->never())->method('expireAt');

        $middleware = $this->makeMiddleware(static fn () => $redis);
        $middleware->process($this->makeRequest(1), $this->makePassthroughHandler());
    }
}
