<?php

declare(strict_types=1);

use Maatify\ChannelDelivery\ApiKey\ApiKeyRepository;
use Maatify\ChannelDelivery\ApiKey\ApiKeyRepositoryInterface;
use Maatify\ChannelDelivery\Http\Middleware\ApiKeyMiddleware;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Psr7\Factory\ResponseFactory;

use function DI\autowire;
use function DI\factory;
use function DI\get;

return [

    ResponseFactoryInterface::class => autowire(ResponseFactory::class),

    ApiKeyRepository::class          => autowire(ApiKeyRepository::class),
    ApiKeyRepositoryInterface::class => get(ApiKeyRepository::class),

    // Inject TRUSTED_PROXIES from ENV (comma-separated IPs).
    // Empty string or missing = no proxy = use REMOTE_ADDR directly.
    //
    // Examples:
    //   TRUSTED_PROXIES=10.0.0.1              <- single Nginx proxy
    //   TRUSTED_PROXIES=10.0.0.1,10.0.0.2    <- multiple proxies
    //   TRUSTED_PROXIES=                       <- direct (no proxy)
    ApiKeyMiddleware::class => factory(function (
        ApiKeyRepositoryInterface $repository,
        ResponseFactoryInterface $responseFactory,
    ): ApiKeyMiddleware {
        $raw            = $_ENV['TRUSTED_PROXIES'] ?? '';
        $trustedProxies = $raw !== ''
            ? array_map('trim', explode(',', $raw))
            : [];

        return new ApiKeyMiddleware(
            repository:      $repository,
            responseFactory: $responseFactory,
            trustedProxies:  $trustedProxies,
        );
    }),

];