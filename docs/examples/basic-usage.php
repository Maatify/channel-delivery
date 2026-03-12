<?php

/**
 * Basic Usage Example
 *
 * Demonstrates how to enqueue an email notification by calling the
 * maatify/channel-delivery microservice from a PHP application using cURL.
 */

$baseUrl = 'http://localhost:8080';
$apiKey = 'YOUR_API_KEY_HERE';

$payload = [
    'entity_type'  => 'user',
    'entity_id'    => '123',
    'recipient'    => 'user@example.com',
    'template_key' => 'welcome',
    'language'     => 'en',
    'sender_type'  => 1,
    'context'      => [
        'user_name' => 'John Doe'
    ]
];

$ch = curl_init($baseUrl . '/api/v1/email/enqueue');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-Api-Key: ' . $apiKey
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($httpCode === 202) {
    echo "Successfully queued email notification.\n";
} else {
    echo "Failed to queue email. HTTP Status: $httpCode\n";
    echo "Response: $response\n";
}

curl_close($ch);