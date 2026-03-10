#!/usr/bin/env php
<?php

declare(strict_types=1);

// ============================================================
// scripts/email_worker.php
//
// CLI worker — bootstraps the Slim/DI app and runs the worker.
//
// Single run (cron every minute):
//   php scripts/email_worker.php --batch=50
//
// Daemon mode (supervisor):
//   php scripts/email_worker.php --loop --sleep=5
//
// Cron entry:
//   * * * * * php /var/www/scripts/email_worker.php >> /var/log/email_worker.log 2>&1
// ============================================================

// Bootstrap loads .env, builds the DI container, and returns the Slim app.
// We only need the container — the HTTP app itself is never run.
require_once dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

$container = require dirname(__DIR__) . '/config/container.php';

$opts      = getopt('', ['batch:', 'loop', 'sleep:']);
$batchSize = isset($opts['batch']) ? (int) $opts['batch'] : 50;
$loopMode  = array_key_exists('loop', $opts);
$sleepSecs = isset($opts['sleep']) ? (int) $opts['sleep'] : 5;

/** @var \Maatify\ChannelDelivery\Worker\EmailWorkerRunner $runner */
$runner = $container->get(\Maatify\ChannelDelivery\Worker\EmailWorkerRunner::class);
$runner->run($batchSize, $loopMode, $sleepSecs);
