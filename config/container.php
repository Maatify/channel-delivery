<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Maatify\ChannelDelivery\Database\PdoConnectionFactory;
use Maatify\PsrLogger\LoggerFactory;
use Psr\Log\LoggerInterface;

use function DI\factory;

$builder = new ContainerBuilder();

if (($_ENV['APP_ENV'] ?? 'production') === 'production') {
    $builder->enableCompilation(dirname(__DIR__) . '/var/cache/di');
}

// ── Core ──────────────────────────────────────────────────────
// The shared and worker connections come from one factory that applies the
// service's session settings (DB_SESSION_WAIT_TIMEOUT) on each new connection.
// PDO::class is the single shared connection of an HTTP request or CLI command.
// It is never swapped for a new one: replacing it inside an open transaction
// would silently split that transaction. Only the email worker replaces its own
// connection, outside transactions (see EmailWorkerRunner).
$builder->addDefinitions([
    PdoConnectionFactory::class => factory(function (): PdoConnectionFactory {
        return PdoConnectionFactory::fromEnvironment($_ENV);
    }),
    PDO::class => factory(function (PdoConnectionFactory $connections): PDO {
        return $connections->create();
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
$builder->addDefinitions(require __DIR__ . '/dependencies/redis.php');

// Future channels:
// $builder->addDefinitions(require __DIR__ . '/dependencies/telegram_delivery.php');
// $builder->addDefinitions(require __DIR__ . '/dependencies/sms_delivery.php');

return $builder->build();