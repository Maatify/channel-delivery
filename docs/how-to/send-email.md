# How to Send an Email

This guide explains how to send an email using the Channel Delivery API.

## Pre-requisites

1. The service must be running and accessible over HTTP.
2. You must have generated an API key and whitelisted your client IP.
3. Your `.env` must be configured with SMTP settings.

## 1. Verify Templates

Ensure the template you intend to use exists in the `templates/` directory. By default, the system looks for a file structured as `templates/{language}/{template_key}.html.twig`.

For example:
- Template key: `welcome`
- Language: `en`
- Resulting path: `templates/en/welcome.html.twig`

## 2. Prepare the Request

Your main application will issue a standard HTTP request to the microservice. You can use any HTTP client (e.g., Guzzle in PHP, Axios in Node.js, `requests` in Python).

### Example: PHP (Guzzle)

```php
use GuzzleHttp\Client;

$client = new Client([
    'base_uri' => 'http://channel-delivery-service.local',
    'timeout'  => 5.0,
]);

$response = $client->post('/api/v1/email/enqueue', [
    'headers' => [
        'X-Api-Key' => 'your_generated_api_key_here'
    ],
    'json' => [
        'entity_type'  => 'user',
        'entity_id'    => '12345',
        'recipient'    => 'customer@example.com',
        'template_key' => 'welcome',
        'language'     => 'en',
        'sender_type'  => 1,
        'context'      => [
            'name' => 'Alice'
        ]
    ]
]);

if ($response->getStatusCode() === 202) {
    echo "Email successfully enqueued!";
}
```

## 3. Worker Execution

The email is now stored in the database. The background worker (`scripts/email_worker.php`) will pick it up and process the actual SMTP delivery asynchronously.