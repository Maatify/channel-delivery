#!/usr/bin/env php
<?php

declare(strict_types=1);

// ============================================================
// scripts/email_worker.php
//
// CLI worker — bootstraps the DI container and runs the worker.
//
// Single run (cron every minute):
//   php scripts/email_worker.php --batch=50
//
// Daemon mode (supervisor / systemd):
//   php scripts/email_worker.php --loop --sleep=5
//
// Options:
//   --batch=N                    Jobs claimed per batch (default 50).
//   --loop                       Keep polling; without it one batch runs.
//   --sleep=N                    Seconds between loop iterations (default 5).
//   --max-connection-failures=N  Consecutive database connection failures
//                                before the loop exits with code 1 so the
//                                process manager restarts it (default 3).
//                                A single run exits 1 on its first one.
//   --state-file=PATH            Optional per-process JSON heartbeat read by
//                                scripts/email_queue_status.php.
//
// Exit codes: 0 finished, 1 database connection unavailable, 2 bad arguments,
//             3 blocked on exhausted pending jobs awaiting operator settlement.
//
// Cron entry:
//   * * * * * php /var/www/scripts/email_worker.php >> /var/log/email_worker.log 2>&1
// ============================================================

use Maatify\ChannelDelivery\Worker\EmailWorkerRunner;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

if (!isset($argv)) {
    fwrite(STDERR, "CLI argument registration is required.\n");
    exit(2);
}

try {
    $options = workerOptions(array_slice($argv, 1));
    $batchSize = workerInteger($options['--batch'] ?? '50', '--batch', 1, PHP_INT_MAX);
    $sleepSecs = workerInteger($options['--sleep'] ?? '5', '--sleep', 0, PHP_INT_MAX);
    $maxConnectionFailures = isset($options['--max-connection-failures'])
        ? workerInteger($options['--max-connection-failures'], '--max-connection-failures', 1, 9999)
        : null;
} catch (InvalidArgumentException $failure) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit(2);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

/** @var \DI\Container $container */
$container = require dirname(__DIR__) . '/config/container.php';

$runner = $container->get(EmailWorkerRunner::class);
if (!$runner instanceof EmailWorkerRunner) {
    throw new RuntimeException('Invalid email worker binding.');
}

exit($runner->run(
    $batchSize,
    isset($options['--loop']),
    $sleepSecs,
    $maxConnectionFailures ?? EmailWorkerRunner::DEFAULT_MAX_CONNECTION_FAILURES,
    $options['--state-file'] ?? null,
));

/**
 * @param list<string> $args
 * @return array<string, string>
 */
function workerOptions(array $args): array
{
    $options = [];
    for ($i = 0, $count = count($args); $i < $count; ++$i) {
        [$name, $value] = array_pad(explode('=', $args[$i], 2), 2, null);
        if ($name === '--' && $value === null && $i + 1 === $count) {
            break;
        }
        if (isset($options[$name]) || !in_array($name, ['--batch', '--loop', '--sleep', '--max-connection-failures', '--state-file'], true)) {
            throw new InvalidArgumentException('Unknown or repeated worker option.');
        }
        if ($name === '--loop') {
            if ($value !== null) {
                throw new InvalidArgumentException('--loop does not take a value.');
            }
            $options[$name] = '';
            continue;
        }
        if ($value === null) {
            $value = $args[++$i] ?? '';
            if (str_starts_with($value, '--')) {
                throw new InvalidArgumentException('Expected a value for ' . $name . '.');
            }
        }
        if ($value === '') {
            throw new InvalidArgumentException('Expected a value for ' . $name . '.');
        }
        $options[$name] = $value;
    }

    return $options;
}

function workerInteger(string $value, string $option, int $min, int $max): int
{
    if (preg_match('/\A[0-9]+\z/', $value) === 1) {
        $number = filter_var(ltrim($value, '0') ?: '0', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $min, 'max_range' => $max],
        ]);
        if ($number !== false) {
            return $number;
        }
    }

    throw new InvalidArgumentException(sprintf('Expected %s to be an integer from %d to %d.', $option, $min, $max));
}
