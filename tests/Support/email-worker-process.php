<?php

declare(strict_types=1);

// Runs the real DI-wired email worker loop in its own process, with a recording
// transport and a JSON-lines logger, for worker process tests.

use DI\Container;
use Maatify\ChannelDelivery\Worker\EmailWorkerRunner;
use Maatify\EmailDelivery\Transport\EmailTransportInterface;
use Psr\Log\LoggerInterface;
use Tests\Support\JsonLinesLogger;
use Tests\Support\RecordingEmailTransport;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var array{env: array<string, mixed>, log: string, batch: int, sleep: int, delay_first_send: int, hold_second_send: bool, state_file: string|null} $spec */
$spec = json_decode((string) file_get_contents($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);
$_ENV = $spec['env'];
$container = require dirname(__DIR__, 2) . '/config/container.php';
if (!$container instanceof Container) {
    throw new RuntimeException('Expected fixture DI container.');
}
$logger = new JsonLinesLogger($spec['log']);
$container->set(LoggerInterface::class, $logger);
$container->set(EmailTransportInterface::class, new RecordingEmailTransport(static function (int $calls) use ($spec, $logger): void {
    $logger->info('Recording transport invoked');
    if ($calls === 1) {
        sleep($spec['delay_first_send']);
    }
    if ($calls === 2 && $spec['hold_second_send']) {
        $logger->info('Recording transport holding second send');
        sleep(60);
    }
}));

$runner = $container->get(EmailWorkerRunner::class);
if (!$runner instanceof EmailWorkerRunner) {
    throw new RuntimeException('Expected the email worker runner.');
}
exit($runner->run($spec['batch'], true, $spec['sleep'], stateFile: $spec['state_file']));
