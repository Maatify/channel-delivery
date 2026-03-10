<?php

declare(strict_types=1);

use DI\ContainerBuilder;

use Maatify\PsrLogger\LoggerFactory;
use Psr\Log\LoggerInterface;

use function DI\factory;

$builder = new ContainerBuilder();

if (($_ENV['APP_ENV'] ?? 'production') === 'production') {
    $builder->enableCompilation(dirname(__DIR__) . '/var/cache/di');
}

// ── Core ──────────────────────────────────────────────────────
$builder->addDefinitions([
    PDO::class => factory(function (): PDO {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $_ENV['DB_HOST']     ?? '127.0.0.1',
            $_ENV['DB_PORT']     ?? '3306',
            $_ENV['DB_DATABASE'] ?? 'channel_delivery'
        );

        return new PDO($dsn, $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }),
]);

// ── Modules ───────────────────────────────────────────────────
$builder->addDefinitions([
    LoggerInterface::class =>
        fn () => LoggerFactory::create('slim/app'),
]);

$builder->addDefinitions(require __DIR__ . '/dependencies/crypto.php');
$builder->addDefinitions(require __DIR__ . '/dependencies/email_delivery.php');
$builder->addDefinitions(require __DIR__ . '/dependencies/api_key.php');



// Future channels:
// $builder->addDefinitions(require __DIR__ . '/dependencies/telegram_delivery.php');
// $builder->addDefinitions(require __DIR__ . '/dependencies/sms_delivery.php');

return $builder->build();