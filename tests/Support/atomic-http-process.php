<?php

declare(strict_types=1);

use DI\Container;
use Maatify\ChannelDelivery\Application\Application;
use Maatify\ChannelDelivery\Email\AtomicEmailConnectionFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\Support\AtomicTestPdo;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var array{dir: string, name: string, hold: bool, env: array<string, mixed>, payload: array<string, mixed>, path?: string, barrier?: bool} $spec */
$spec = json_decode((string) file_get_contents($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);
$_ENV = $spec['env'];
$base = $spec['dir'] . '/' . $spec['name'];
$container = require dirname(__DIR__, 2) . '/config/container.php';
if (!$container instanceof Container) {
    throw new RuntimeException('Expected fixture DI container.');
}
$container->set(LoggerInterface::class, new NullLogger());
$container->set(AtomicEmailConnectionFactory::class, new AtomicEmailConnectionFactory(static function () use ($spec, $base): PDO {
    $pdo = new AtomicTestPdo();
    $statement = $pdo->query('SELECT CONNECTION_ID()');
    if ($statement === false) {
        throw new RuntimeException('Fixture connection id missing.');
    }
    file_put_contents($base . '.connection', (string) $statement->fetchColumn());
    $pdo->beforeExecute = static function (string $sql) use ($base): void {
        if (str_starts_with($sql, 'INSERT INTO cd_email_enqueue_receipts')) {
            file_put_contents($base . '.before', '1');
        }
    };
    $pdo->afterExecute = static function (string $sql) use ($spec, $base): void {
        if ($spec['hold'] && str_starts_with($sql, 'INSERT INTO cd_email_enqueue_receipts')) {
            file_put_contents($base . '.inserted', '1');
            $deadline = microtime(true) + 15;
            while (!is_file($spec['dir'] . '/release')) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Owned HTTP winner barrier was not released.');
                }
                usleep(10000);
            }
        }
    };
    return $pdo;
}));
$app = Application::create($container);
$path = $spec['path'] ?? '/api/v2/email/enqueue';
$request = (new ServerRequestFactory())->createServerRequest('POST', $path, ['REMOTE_ADDR' => '127.0.0.1'])
    ->withHeader('X-Api-Key', 'fixture-key-a')->withHeader('Content-Type', 'application/json')->withHeader('Accept', 'application/json')
    ->withBody((new StreamFactory())->createStream(json_encode($spec['payload'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)));
if ($path !== '/api/v2/email/synthetic') {
    $request = $request->withHeader('Idempotency-Key', 'concurrent-key');
}
if ($spec['barrier'] ?? false) {
    file_put_contents($base . '.ready', '1');
    $deadline = microtime(true) + 10;
    while (!is_file($spec['dir'] . '/release')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Owned synthetic process barrier timed out.');
        }
        usleep(10000);
    }
}
$response = $app->handle($request);
file_put_contents($base . '.result', json_encode(['pid' => getmypid(), 'status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR));
