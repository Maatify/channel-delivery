<?php

declare(strict_types=1);

// Legacy real enqueue: explicit test URL/key, no retry or no-send semantics.
$baseUrl = getenv('CD_BASE_URL');
$apiKey = getenv('CD_API_KEY');
if (!is_string($baseUrl) || $baseUrl === '' || !is_string($apiKey) || $apiKey === '') {
    fwrite(STDERR, "Set CD_BASE_URL and CD_API_KEY for an explicit test service.\n");
    exit(2);
}
$payload = file_get_contents(dirname(__DIR__, 2) . '/examples/payloads/welcome.json');
if ($payload === false) {
    throw new RuntimeException('Example payload is unavailable.');
}
$curl = curl_init(rtrim($baseUrl, '/') . '/api/v1/email/enqueue');
if ($curl === false) {
    throw new RuntimeException('Cannot initialize cURL.');
}
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Api-Key: ' . $apiKey],
    CURLOPT_TIMEOUT => 10,
]);
$response = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$error = curl_error($curl);
unset($curl);
if ($response === false) {
    fwrite(STDERR, 'HTTP outcome unresolved: ' . $error . "\n");
    exit(1);
}
echo 'HTTP ' . $status . "\n" . $response . "\n";
exit($status === 202 ? 0 : 1);
