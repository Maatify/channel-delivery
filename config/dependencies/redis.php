<?php

declare(strict_types=1);

// ============================================================
// config/dependencies/redis.php
// ============================================================

use Maatify\ChannelDelivery\Http\Middleware\RateLimitMiddleware;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;

use function DI\factory;

return [

    // ── Rate Limit Middleware ─────────────────────────────────
    // Redis is connected lazily inside RateLimitMiddleware::process()
    // via the factory closure. This ensures Redis downtime does NOT
    // crash the DI container at boot time.
    RateLimitMiddleware::class => factory(function (
        ResponseFactoryInterface $responseFactory,
        LoggerInterface          $logger,
    ): RateLimitMiddleware {
        // Build a lazy Redis factory — connection happens on first use,
        // not at container build time.
        $redisFactory = static function (): ?\Redis {
            try {
                $redis = new \Redis();
                $connected = $redis->pconnect(
                    host:    (string) ($_ENV['REDIS_HOST']    ?? '127.0.0.1'),
                    port:    (int)    ($_ENV['REDIS_PORT']    ?? 6379),
                    timeout: (float)  ($_ENV['REDIS_TIMEOUT'] ?? 2.0),
                );

                if (!$connected) {
                    return null;
                }

                $password = (string) ($_ENV['REDIS_PASSWORD'] ?? '');
                if ($password !== '') {
                    $redis->auth($password);
                }

                $db = (int) ($_ENV['REDIS_DB'] ?? 0);
                if ($db !== 0) {
                    $redis->select($db);
                }

                return $redis;
            } catch (\Throwable) {
                return null;
            }
        };

        return new RateLimitMiddleware(
            redisFactory:  $redisFactory,
            responseFactory: $responseFactory,
            logger:        $logger,
            maxRequests:   (int) ($_ENV['RATE_LIMIT_MAX_REQUESTS']   ?? 100),
            windowSeconds: (int) ($_ENV['RATE_LIMIT_WINDOW_SECONDS'] ?? 60),
        );
    }),

];