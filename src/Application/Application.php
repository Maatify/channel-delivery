<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Application;

use Maatify\ChannelDelivery\Http\Handler\EnqueueEmailHandler;
use Maatify\ChannelDelivery\Http\Handler\HealthCheckHandler;
use Maatify\ChannelDelivery\Http\Middleware\ApiKeyMiddleware;
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
        $app->addErrorMiddleware(
            displayErrorDetails: (bool) ($_ENV['APP_DEBUG'] ?? false),
            logErrors:           true,
            logErrorDetails:     true,
        );

        // ── Public ────────────────────────────────────────────
        $app->get('/health', HealthCheckHandler::class);

        // ── Protected (API Key + IP whitelist) ────────────────
        $app->group('/api/v1', function ($group): void {
            $group->post('/email/enqueue', EnqueueEmailHandler::class);
        })->add(ApiKeyMiddleware::class);

        return $app;
    }
}
