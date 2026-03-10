<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Http\Middleware;

use Closure;
use Maatify\ChannelDelivery\ApiKey\DTO\ApiKeyDTO;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Redis-based sliding window rate limiter.
 *
 * Strategy: Sliding Window Counter using Redis INCR + EXPIREAT.
 *   - Key:    "rl:{identity}:{window_start}"
 *   - Window: configurable (default: 60s)
 *   - Limit:  configurable (default: 100 req/window)
 *
 * Fail-open design:
 *   Redis is connected lazily via $redisFactory on first request.
 *   If Redis is unreachable (factory returns null or throws),
 *   the request is allowed through and a warning is logged.
 *   Redis downtime does NOT take down the enqueue API.
 *
 * @phpstan-type RedisFactory Closure(): (\Redis|null)
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    /**
     * Prefix for all Redis keys owned by channel-delivery.
     * Prevents key collisions when Redis is shared with other applications.
     * Format: cd:rl:{identity}:{window_start}
     */
    private const KEY_PREFIX = 'cd:rl:';

    private ?\Redis $redis = null;
    private bool    $redisResolved = false;

    /**
     * @param Closure(): (\Redis|null) $redisFactory
     */
    public function __construct(
        private readonly Closure                  $redisFactory,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly LoggerInterface          $logger,
        private readonly int                      $maxRequests   = 100,
        private readonly int                      $windowSeconds = 60,
    ) {
    }

    public function process(
        ServerRequestInterface  $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        // ── Resolve identity ──────────────────────────────────
        $apiKey   = $request->getAttribute(ApiKeyMiddleware::ATTRIBUTE);
        $identity = $apiKey instanceof ApiKeyDTO
            ? 'key:' . $apiKey->id
            : 'ip:' . ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');

        // ── Sliding window ────────────────────────────────────
        $windowStart = (int) floor(time() / $this->windowSeconds) * $this->windowSeconds;
        $windowReset = $windowStart + $this->windowSeconds;
        $redisKey    = self::KEY_PREFIX . $identity . ':' . $windowStart;

        try {
            $redis = $this->getRedis();

            if ($redis === null) {
                // Redis unavailable — fail open
                return $handler->handle($request);
            }

            $raw   = $redis->incr($redisKey);
            $count = is_int($raw) ? $raw : 1;

            if ($count === 1) {
                $redis->expireAt($redisKey, $windowReset);
            }

        } catch (Throwable $e) {
            $this->logger->warning('RateLimitMiddleware: Redis error, failing open', [
                'identity' => $identity,
                'error'    => $e->getMessage(),
            ]);

            return $handler->handle($request);
        }

        $remaining = max(0, $this->maxRequests - $count);

        // ── Rate limit exceeded ───────────────────────────────
        if ($count > $this->maxRequests) {
            $retryAfter = $windowReset - time();

            $this->logger->warning('Rate limit exceeded', [
                'identity'    => $identity,
                'count'       => $count,
                'limit'       => $this->maxRequests,
                'retry_after' => $retryAfter,
            ]);

            $response = $this->responseFactory->createResponse(429);
            $response->getBody()->write(json_encode([
                'error'   => 'rate_limit_exceeded',
                'message' => sprintf(
                    'Too many requests. Limit: %d per %ds. Retry after %ds.',
                    $this->maxRequests,
                    $this->windowSeconds,
                    $retryAfter
                ),
            ], JSON_THROW_ON_ERROR));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-RateLimit-Limit', (string) $this->maxRequests)
                ->withHeader('X-RateLimit-Remaining', '0')
                ->withHeader('X-RateLimit-Reset', (string) $windowReset)
                ->withHeader('Retry-After', (string) $retryAfter);
        }

        // ── Pass through — attach rate limit headers ──────────
        $response = $handler->handle($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $this->maxRequests)
            ->withHeader('X-RateLimit-Remaining', (string) $remaining)
            ->withHeader('X-RateLimit-Reset', (string) $windowReset);
    }

    private function getRedis(): ?\Redis
    {
        if (!$this->redisResolved) {
            try {
                $this->redis = ($this->redisFactory)();
            } catch (Throwable $e) {
                $this->logger->warning('RateLimitMiddleware: Redis factory failed, failing open', [
                    'error' => $e->getMessage(),
                ]);
                $this->redis = null;
            }
            $this->redisResolved = true;
        }

        return $this->redis;
    }
}
