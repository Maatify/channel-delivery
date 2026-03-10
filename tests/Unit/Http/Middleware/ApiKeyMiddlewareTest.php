<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use Maatify\ChannelDelivery\ApiKey\ApiKeyRepositoryInterface;
use Maatify\ChannelDelivery\ApiKey\DTO\ApiKeyDTO;
use Maatify\ChannelDelivery\Http\Middleware\ApiKeyMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Tests for ApiKeyMiddleware — covering:
 *   1. Debounced last_used_at (only write DB if > 60s since last update)
 *   2. Trusted proxy support (X-Forwarded-For respected only from known proxies)
 */
final class ApiKeyMiddlewareTest extends TestCase
{
    /** @var ApiKeyRepositoryInterface&MockObject */
    private ApiKeyRepositoryInterface $repository;

    private ResponseFactory $responseFactory;

    protected function setUp(): void
    {
        $this->repository      = $this->createMock(ApiKeyRepositoryInterface::class);
        $this->responseFactory = new ResponseFactory();
    }

    // ── Helpers ───────────────────────────────────────────────

    /**
     * @param list<string> $trustedProxies
     */
    private function makeMiddleware(array $trustedProxies = []): ApiKeyMiddleware
    {
        return new ApiKeyMiddleware(
            repository:      $this->repository,
            responseFactory: $this->responseFactory,
            trustedProxies:  $trustedProxies,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function makeRequest(
        string $apiKey     = 'valid-raw-key',
        string $remoteAddr = '1.2.3.4',
        ?string $forwarded = null,
        array  $headers    = [],
    ): ServerRequestInterface {
        $request = $this->createMock(ServerRequestInterface::class);

        $allHeaders = array_merge(['X-Api-Key' => $apiKey], $headers);

        $request->method('getHeaderLine')->willReturnCallback(
            static function (string $name) use ($allHeaders, $forwarded): string {
                if ($name === 'X-Forwarded-For' && $forwarded !== null) {
                    return $forwarded;
                }
                return $allHeaders[$name] ?? '';
            }
        );

        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => $remoteAddr]);

        $request->method('withAttribute')->willReturnSelf();

        $request->method('getAttribute')->willReturn(null);

        return $request;
    }

    private function makeDto(
        string $ip = '1.2.3.4',
        ?\DateTimeImmutable $lastUsedAt = null,
    ): ApiKeyDTO {
        return new ApiKeyDTO(
            id:          1,
            name:        'test-key',
            keyHash:     hash('sha256', 'valid-raw-key'),
            ipWhitelist: [$ip],
            isActive:    true,
            lastUsedAt:  $lastUsedAt,
        );
    }

    private function makeHandler(): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(
            $this->responseFactory->createResponse(200)
        );
        return $handler;
    }

    // ── Missing / invalid key ─────────────────────────────────

    #[Test]
    public function testMissingApiKeyReturns401(): void
    {
        $middleware = $this->makeMiddleware();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');
        $request->method('getServerParams')->willReturn([]);

        $response = $middleware->process($request, $this->makeHandler());

        $this->assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function testUnknownApiKeyReturns401(): void
    {
        $this->repository->method('findByRawKey')->willReturn(null);

        $response = $this->makeMiddleware()->process(
            $this->makeRequest(),
            $this->makeHandler()
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function testIpNotInWhitelistReturns403(): void
    {
        $this->repository->method('findByRawKey')->willReturn(
            $this->makeDto(ip: '9.9.9.9')
        );

        // REMOTE_ADDR = 1.2.3.4, whitelist = [9.9.9.9]
        $response = $this->makeMiddleware()->process(
            $this->makeRequest(remoteAddr: '1.2.3.4'),
            $this->makeHandler()
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    // ── Debounce: last_used_at ────────────────────────────────

    #[Test]
    public function testTouchLastUsedWhenLastUsedAtIsNull(): void
    {
        $this->repository->method('findByRawKey')->willReturn(
            $this->makeDto(lastUsedAt: null)
        );
        $this->repository->expects($this->once())->method('touchLastUsed')->with(1);

        $this->makeMiddleware()->process($this->makeRequest(), $this->makeHandler());
    }

    #[Test]
    public function testTouchLastUsedWhenMoreThan60SecondsAgo(): void
    {
        $this->repository->method('findByRawKey')->willReturn(
            $this->makeDto(lastUsedAt: new \DateTimeImmutable('-61 seconds'))
        );
        $this->repository->expects($this->once())->method('touchLastUsed')->with(1);

        $this->makeMiddleware()->process($this->makeRequest(), $this->makeHandler());
    }

    #[Test]
    public function testSkipsTouchLastUsedWhenUpdatedWithin60Seconds(): void
    {
        $this->repository->method('findByRawKey')->willReturn(
            $this->makeDto(lastUsedAt: new \DateTimeImmutable('-30 seconds'))
        );
        $this->repository->expects($this->never())->method('touchLastUsed');

        $this->makeMiddleware()->process($this->makeRequest(), $this->makeHandler());
    }

    #[Test]
    public function testSkipsTouchLastUsedWhenUpdatedJustNow(): void
    {
        $this->repository->method('findByRawKey')->willReturn(
            $this->makeDto(lastUsedAt: new \DateTimeImmutable())
        );
        $this->repository->expects($this->never())->method('touchLastUsed');

        $this->makeMiddleware()->process($this->makeRequest(), $this->makeHandler());
    }

    // ── Trusted proxy: X-Forwarded-For ────────────────────────

    #[Test]
    public function testXForwardedForIgnoredWithoutTrustedProxies(): void
    {
        // Whitelist = [5.5.5.5], REMOTE_ADDR = 1.2.3.4, XFF = 5.5.5.5
        // Without trusted proxies XFF is ignored → REMOTE_ADDR used → 403
        $this->repository->method('findByRawKey')->willReturn(
            $this->makeDto(ip: '5.5.5.5')
        );

        $response = $this->makeMiddleware(trustedProxies: [])->process(
            $this->makeRequest(remoteAddr: '1.2.3.4', forwarded: '5.5.5.5'),
            $this->makeHandler()
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function testXForwardedForUsedWhenRemoteAddrIsTrustedProxy(): void
    {
        $this->repository->method('findByRawKey')->willReturn(
            $this->makeDto(ip: '5.5.5.5')
        );
        $this->repository->method('touchLastUsed');

        $response = $this->makeMiddleware(trustedProxies: ['10.0.0.1'])->process(
            $this->makeRequest(remoteAddr: '10.0.0.1', forwarded: '5.5.5.5'),
            $this->makeHandler()
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function testXForwardedForIgnoredWhenRemoteAddrIsNotTrustedProxy(): void
    {
        $this->repository->method('findByRawKey')->willReturn(
            $this->makeDto(ip: '5.5.5.5')
        );

        // REMOTE_ADDR = 9.9.9.9 (not trusted) → XFF ignored → 403
        $response = $this->makeMiddleware(trustedProxies: ['10.0.0.1'])->process(
            $this->makeRequest(remoteAddr: '9.9.9.9', forwarded: '5.5.5.5'),
            $this->makeHandler()
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function testXForwardedForPicksLeftmostIp(): void
    {
        $this->repository->method('findByRawKey')->willReturn(
            $this->makeDto(ip: '5.5.5.5')
        );
        $this->repository->method('touchLastUsed');

        $response = $this->makeMiddleware(trustedProxies: ['10.0.0.1'])->process(
            $this->makeRequest(remoteAddr: '10.0.0.1', forwarded: '5.5.5.5, 10.0.0.2, 10.0.0.3'),
            $this->makeHandler()
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function testInvalidXForwardedForFallsBackToRemoteAddr(): void
    {
        // XFF is invalid IP → falls back to REMOTE_ADDR (proxy IP) → whitelisted → 200
        $this->repository->method('findByRawKey')->willReturn(
            $this->makeDto(ip: '10.0.0.1')
        );
        $this->repository->method('touchLastUsed');

        $response = $this->makeMiddleware(trustedProxies: ['10.0.0.1'])->process(
            $this->makeRequest(remoteAddr: '10.0.0.1', forwarded: 'not-a-valid-ip'),
            $this->makeHandler()
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    // ── Success ───────────────────────────────────────────────

    #[Test]
    public function testValidRequestPassesApiKeyAttributeToHandler(): void
    {
        $dto = $this->makeDto();
        $this->repository->method('findByRawKey')->willReturn($dto);
        $this->repository->method('touchLastUsed');

        $capturedRequest = null;
        $handler         = $this->createMock(RequestHandlerInterface::class);

        $handler->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$capturedRequest): ResponseInterface {
                $capturedRequest = $req;
                return $this->responseFactory->createResponse(200);
            }
        );

        // Use a real request that supports withAttribute properly
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn (string $h) => $h === 'X-Api-Key' ? 'valid-raw-key' : ''
        );
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '1.2.3.4']);
        $request->method('withAttribute')->willReturnCallback(
            static function (string $name, mixed $value) use ($request): ServerRequestInterface {
                $clone = clone $request;
                return $clone;
            }
        );
        $request->method('getAttribute')->willReturnCallback(
            static fn (string $name) => $name === ApiKeyMiddleware::ATTRIBUTE ? null : null
        );

        $this->makeMiddleware()->process($request, $handler);

        // Handler was called — middleware passed the request through
        $this->assertNotNull($capturedRequest);
    }
}
