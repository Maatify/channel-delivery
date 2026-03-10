#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Maatify\ChannelDelivery\ApiKey\ApiKeyRepository;

// ── Load ENV ──────────────────────────────────────────────────
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

// ── Parse args ────────────────────────────────────────────────
$options = getopt('', ['name:', 'ips:']);

$name = isset($options['name']) ? trim((string) $options['name']) : '';
$ips  = isset($options['ips'])  ? trim((string) $options['ips'])  : '';

if ($name === '' || $ips === '') {
    fwrite(STDERR, implode(PHP_EOL, [
        'Usage:',
        '  php scripts/create_api_key.php --name=<name> --ips=<ip1,ip2,...>',
        '',
        'Example:',
        '  php scripts/create_api_key.php --name=iam-core --ips=1.2.3.4,5.6.7.8',
        '',
    ]));
    exit(1);
}

/** @var list<string> $ipList */
$ipList = array_values(array_filter(array_map('trim', explode(',', $ips))));

if ($ipList === []) {
    fwrite(STDERR, "Error: at least one valid IP address is required.\n");
    exit(1);
}

foreach ($ipList as $ip) {
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        fwrite(STDERR, "Error: '{$ip}' is not a valid IP address.\n");
        exit(1);
    }
}

// ── Generate key ──────────────────────────────────────────────
$rawKey = bin2hex(random_bytes(32)); // 64-char hex string

// ── Persist ───────────────────────────────────────────────────
$container  = require dirname(__DIR__) . '/config/container.php';
$repository = $container->get(ApiKeyRepository::class);
$repository->create($name, $rawKey, $ipList);

// ── Output ────────────────────────────────────────────────────
echo implode(PHP_EOL, [
    '',
    '✅ API key created successfully',
    '',
    '  Name : ' . $name,
    '  IPs  : ' . implode(', ', $ipList),
    '  Key  : ' . $rawKey,
    '',
    '⚠️  Save this key now — it will NOT be shown again.',
    '',
]);
