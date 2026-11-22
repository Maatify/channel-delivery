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
// Exit codes: 0 finished, 1 database connection unavailable, 2 bad arguments.
//
// Cron entry:
//   * * * * * php /var/www/scripts/email_worker.php >> /var/log/email_worker.log 2>&1
// ============================================================

use Maatify\ChannelDelivery\Worker\EmailWorkerRunner;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['batch:', 'loop', 'sleep:', 'max-connection-failures:', 'state-file:']);
if ($opts === false) {
    fwrite(STDERR, "Invalid worker options.\n");
    exit(2);
}

$batchSize = isset($opts['batch']) && is_string($opts['batch']) ? (int) $opts['batch'] : 50;
$loopMode  = array_key_exists('loop', $opts);
$sleepSecs = isset($opts['sleep']) && is_string($opts['sleep']) ? (int) $opts['sleep'] : 5;

$maxConnectionFailures = EmailWorkerRunner::DEFAULT_MAX_CONNECTION_FAILURES;
if (isset($opts['max-connection-failures'])) {
    $value = $opts['max-connection-failures'];
    if (!is_string($value) || preg_match('/\A[1-9][0-9]{0,3}\z/', $value) !== 1) {
        fwrite(STDERR, "Expected --max-connection-failures=1..9999.\n");
        exit(2);
    }
    $maxConnectionFailures = (int) $value;
}

$stateFile = null;
if (isset($opts['state-file'])) {
    if (!is_string($opts['state-file']) || $opts['state-file'] === '') {
        fwrite(STDERR, "Expected one non-empty --state-file=PATH.\n");
        exit(2);
    }
    $stateFile = $opts['state-file'];
}

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

/** @var \DI\Container $container */
$container = require dirname(__DIR__) . '/config/container.php';

$runner = $container->get(EmailWorkerRunner::class);
if (!$runner instanceof EmailWorkerRunner) {
    throw new RuntimeException('Invalid email worker binding.');
}

exit($runner->run($batchSize, $loopMode, $sleepSecs, $maxConnectionFailures, $stateFile));
