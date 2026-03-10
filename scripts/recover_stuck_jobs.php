#!/usr/bin/env php
<?php

declare(strict_types=1);

// ============================================================
// scripts/recover_stuck_jobs.php
//
// Recovers zombie jobs stuck in 'processing' status.
//
// A job is considered zombie if it has been in 'processing'
// for longer than the threshold (default: 10 minutes).
//
// This happens when a worker crashes, is OOM-killed,
// or loses DB connection during processRow().
//
// Usage:
//   php scripts/recover_stuck_jobs.php
//   php scripts/recover_stuck_jobs.php --threshold=15   (minutes)
//   php scripts/recover_stuck_jobs.php --dry-run
//
// Recommended: run every 5 minutes via cron.
//   */5 * * * * php /var/www/scripts/recover_stuck_jobs.php >> /var/log/recover_stuck_jobs.log 2>&1
// ============================================================

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Maatify\PsrLogger\LoggerFactory;

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

// ── Args ──────────────────────────────────────────────────────
$opts      = getopt('', ['threshold:', 'dry-run']);
$threshold = isset($opts['threshold']) ? (int) $opts['threshold'] : 10;
$dryRun    = array_key_exists('dry-run', $opts);

$logger = LoggerFactory::create('recover_stuck_jobs');

// ── DB ────────────────────────────────────────────────────────
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $_ENV['DB_HOST']     ?? '127.0.0.1',
    $_ENV['DB_PORT']     ?? '3306',
    $_ENV['DB_DATABASE'] ?? 'channel_delivery'
);

$pdo = new PDO($dsn, $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);

// ── Find zombie jobs ──────────────────────────────────────────
$table = 'cd_email_queue';

$stmt = $pdo->prepare("
    SELECT id, attempts, updated_at
    FROM `{$table}`
    WHERE status = 'processing'
      AND updated_at <= NOW() - INTERVAL :threshold MINUTE
");

$stmt->execute(['threshold' => $threshold]);

/** @var list<array{id: string, attempts: string, updated_at: string}> $zombies */
$zombies = $stmt->fetchAll();

if ($zombies === []) {
    $logger->info('No zombie jobs found', ['threshold_minutes' => $threshold]);
    exit(0);
}

$logger->warning('Zombie jobs detected', [
    'count'             => count($zombies),
    'threshold_minutes' => $threshold,
    'dry_run'           => $dryRun,
]);

if ($dryRun) {
    foreach ($zombies as $job) {
        $logger->info('[dry-run] Would recover job', [
            'job_id'     => $job['id'],
            'attempts'   => $job['attempts'],
            'updated_at' => $job['updated_at'],
        ]);
    }
    exit(0);
}

// ── Recover ───────────────────────────────────────────────────
$ids          = array_map(static fn (array $row): int => (int) $row['id'], $zombies);
$placeholders = implode(',', array_fill(0, count($ids), '?'));

$recovered = $pdo->prepare("
    UPDATE `{$table}`
    SET status     = 'pending',
        last_error = 'recovered_from_zombie_processing'
    WHERE id IN ({$placeholders})
      AND status = 'processing'
")->execute($ids);

$logger->info('Zombie jobs recovered', [
    'recovered' => count($ids),
    'job_ids'   => $ids,
]);

echo sprintf(
    "[%s] Recovered %d zombie job(s) stuck > %d minutes.\n",
    date('Y-m-d H:i:s'),
    count($ids),
    $threshold
);
