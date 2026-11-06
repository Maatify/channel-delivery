#!/usr/bin/env php
<?php

declare(strict_types=1);

use Maatify\EmailDelivery\Synthetic\SyntheticEmailMaintenanceInterface;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

if (!isset($argv)) {
    fwrite(STDERR, "CLI argument registration is required.\n");
    exit(2);
}
$args = array_slice($argv, 1);
if ($args === ['--help']) {
    echo "Usage: php scripts/purge_synthetic_email.php [--limit=100]\nDeletes at most 1..1000 expired synthetic rows across all consumers.\n";
    exit(0);
}
if (count($args) > 1 || (isset($args[0]) && preg_match('/\A--limit=([1-9][0-9]{0,3})\z/', $args[0], $match) !== 1)) {
    fwrite(STDERR, "Expected one optional --limit=1..1000.\n");
    exit(2);
}
$limit = isset($match[1]) ? (int) $match[1] : 100;
if ($limit > 1000) {
    fwrite(STDERR, "Limit must be 1..1000.\n");
    exit(2);
}

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
    $container = require dirname(__DIR__) . '/config/container.php';
    $maintenance = $container->get(SyntheticEmailMaintenanceInterface::class);
    if (!$maintenance instanceof SyntheticEmailMaintenanceInterface) {
        throw new RuntimeException('Invalid maintenance binding.');
    }
    echo json_encode(['purged' => $maintenance->purgeExpired($limit)], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $failure) {
    // No credentials, payloads, SQL details or false confirmed count after unknown commit.
    fwrite(STDERR, 'Synthetic cleanup unavailable: ' . $failure::class . "\n");
    exit(1);
}
