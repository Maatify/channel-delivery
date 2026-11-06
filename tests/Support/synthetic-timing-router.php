<?php

declare(strict_types=1);

// Test-only HTTP server router. Never register or deploy as a public entry point.
use Maatify\ChannelDelivery\Application\Application;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slim\Factory\ServerRequestCreatorFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
if (($_ENV['APP_ENV'] ?? '') !== 'testing' || preg_match('/;dbname=[A-Za-z0-9_]+_test(?:;|\z)/', (string) getenv('CHANNEL_IT_DSN')) !== 1) {
    http_response_code(503);
    exit;
}
$container = require dirname(__DIR__, 2) . '/config/container.php';
$container->set(LoggerInterface::class, new NullLogger());
$request = ServerRequestCreatorFactory::create()->createServerRequestFromGlobals();
if ($request->getUri()->getPath() === '/__fixture/synthetic-delayed') {
    // Sensitivity control ONLY in this fixture, absent from production source/routes.
    usleep(50000);
    $request = $request->withUri($request->getUri()->withPath('/api/v2/email/synthetic'));
}
$response = Application::create($container)->handle($request);
http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header($name . ': ' . $value, false);
    }
}
echo (string) $response->getBody();
