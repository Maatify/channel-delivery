<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Application;

use Maatify\ChannelDelivery\Http\Handler\EnqueueEmailHandler;
use Maatify\ChannelDelivery\Http\Handler\AtomicEmailHandler;
use Maatify\ChannelDelivery\Http\Middleware\AtomicEmailBodyLimitMiddleware;
use Psr\Http\Message\ResponseFactoryInterface;
use Maatify\ChannelDelivery\Http\Handler\HealthCheckHandler;
use Maatify\ChannelDelivery\Http\Middleware\ApiKeyMiddleware;
use Maatify\ChannelDelivery\Http\Middleware\RateLimitMiddleware;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Factory\AppFactory;

final class Application
{
    /** @return App<\Psr\Container\ContainerInterface> */
    public static function create(ContainerInterface $container): App
    {
        AppFactory::setContainer($container);

        /** @var App<\Psr\Container\ContainerInterface> $app */
        $app = AppFactory::create();

        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $responses = $container->get(ResponseFactoryInterface::class);
        if (!$responses instanceof ResponseFactoryInterface) {
            throw new \RuntimeException('Response factory binding is invalid.');
        }
        $app->addErrorMiddleware(
            displayErrorDetails: filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
            logErrors:           true,
            logErrorDetails:     true,
        );
        $app->add(new AtomicEmailBodyLimitMiddleware($responses));

        // ── Public ────────────────────────────────────────────
        $app->get('/health', HealthCheckHandler::class);

        // ── Protected (API Key + IP whitelist) ────────────────
        $app->group('/api/v1', function ($group): void {
            $group->post('/email/enqueue', EnqueueEmailHandler::class);
            // Middleware chain (outermost = last executed):
            // Request → RateLimitMiddleware → ApiKeyMiddleware → Handler
            // ApiKeyMiddleware runs first (innermost) so RateLimitMiddleware
            // can identify the key via request attribute for per-key limiting.
        })
            ->add(RateLimitMiddleware::class)
            ->add(ApiKeyMiddleware::class);

        $app->group('/api/v2', function ($group): void {
            $group->post('/email/enqueue', AtomicEmailHandler::class);
            $group->get('/email/receipt', [AtomicEmailHandler::class, 'receipt']);
        })
            ->add(RateLimitMiddleware::class)
            ->add(ApiKeyMiddleware::class);

        return $app;
    }
}
