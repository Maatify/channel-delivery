#!/usr/bin/env php
<?php

declare(strict_types=1);

// ── Config ────────────────────────────────────────────────────
$baseUrl = getenv('CD_BASE_URL') ?: 'http://localhost:8080';
$apiKey  = getenv('CD_API_KEY')  ?: '';

if ($apiKey === '') {
    fwrite(STDERR, "Error: CD_API_KEY env variable is required.\n");
    fwrite(STDERR, "  export CD_API_KEY=your_raw_key\n\n");
    exit(1);
}

// ── Payload ───────────────────────────────────────────────────
$payload = [
    'entity_type'  => 'user',
    'entity_id'    => '123',
    'recipient'    => 'user@example.com',
    'template_key' => 'welcome',
    'language'     => 'ar',
    'sender_type'  => 1,
    'context'      => [
        'user_name'       => 'Ahmed',
        'activation_link' => 'https://example.com/activate?token=abc123',
    ],
];

// ── Request ───────────────────────────────────────────────────
$url  = rtrim($baseUrl, '/') . '/api/v1/email/enqueue';
$body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

echo "───────────────────────────────────────\n";
echo "POST {$url}\n";
echo "───────────────────────────────────────\n";
echo $body . "\n\n";

$ch = curl_init($url);

if ($ch === false) {
    fwrite(STDERR, "Error: failed to initialize cURL.\n");
    exit(1);
}

curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-Api-Key: ' . $apiKey,
    ],
    CURLOPT_TIMEOUT        => 10,
]);

$response   = curl_exec($ch);
$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError  = curl_error($ch);
curl_close($ch);

// ── Output ────────────────────────────────────────────────────
if ($curlError !== '') {
    fwrite(STDERR, "cURL error: {$curlError}\n");
    exit(1);
}

echo "Status : {$httpStatus}\n";
echo "───────────────────────────────────────\n";

if (is_string($response) && $response !== '') {
    $decoded = json_decode($response, true);
    echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "(empty response)\n";
}

echo "───────────────────────────────────────\n";

exit($httpStatus === 202 ? 0 : 1);