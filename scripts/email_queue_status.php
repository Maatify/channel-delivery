#!/usr/bin/env php
<?php

declare(strict_types=1);

// Read-only queue-stall check for an external monitor (cron, monitoring agent).
// Prints one JSON report. Exit 0 within thresholds, 1 alert or status unavailable,
// 2 bad arguments. It never changes the queue.

use Maatify\ChannelDelivery\Worker\EmailQueueStallMonitor;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

if (!isset($argv)) {
    fwrite(STDERR, "CLI argument registration is required.\n");
    exit(2);
}
$args = array_slice($argv, 1);
if ($args === ['--help']) {
    echo "Usage: php scripts/email_queue_status.php [--max-pending-age=600] [--max-batch-failures=3] [--max-heartbeat-age=600] [--state-file=PATH]...\n"
        . "Reports the age of the oldest due pending email and each worker's consecutive batch failures and heartbeat age.\n"
        . "Exit 0 within thresholds, 1 alert or status unavailable, 2 bad arguments.\n";
    exit(0);
}

$maxPendingAge = EmailQueueStallMonitor::DEFAULT_MAX_PENDING_AGE_SECONDS;
$maxBatchFailures = EmailQueueStallMonitor::DEFAULT_MAX_BATCH_FAILURES;
$maxHeartbeatAge = EmailQueueStallMonitor::DEFAULT_MAX_HEARTBEAT_AGE_SECONDS;
$stateFiles = [];
foreach ($args as $arg) {
    if (preg_match('/\A--max-pending-age=([1-9][0-9]{0,8})\z/', $arg, $match) === 1) {
        $maxPendingAge = (int) $match[1];
    } elseif (preg_match('/\A--max-batch-failures=([1-9][0-9]{0,3})\z/', $arg, $match) === 1) {
        $maxBatchFailures = (int) $match[1];
    } elseif (preg_match('/\A--max-heartbeat-age=([1-9][0-9]{0,8})\z/', $arg, $match) === 1) {
        $maxHeartbeatAge = (int) $match[1];
    } elseif (preg_match('/\A--state-file=(.+)\z/s', $arg, $match) === 1) {
        $stateFiles[] = $match[1];
    } else {
        fwrite(STDERR, "Expected --max-pending-age=SECONDS, --max-batch-failures=N, --max-heartbeat-age=SECONDS or --state-file=PATH.\n");
        exit(2);
    }
}

try {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
    $container = require dirname(__DIR__) . '/config/container.php';
    if (!$container instanceof DI\Container) {
        throw new RuntimeException('Invalid container.');
    }
    $pdo = $container->get(PDO::class);
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Invalid database binding.');
    }
    $report = (new EmailQueueStallMonitor($pdo))->report($maxPendingAge, $maxBatchFailures, $maxHeartbeatAge, $stateFiles, time());
} catch (Throwable $failure) {
    // No credentials or SQL details on output.
    echo json_encode(['status' => 'unavailable'], JSON_THROW_ON_ERROR) . "\n";
    fwrite(STDERR, 'Email queue status unavailable: ' . $failure::class . "\n");
    exit(1);
}

echo json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
exit($report['status'] === 'ok' ? 0 : 1);
