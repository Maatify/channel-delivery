# How to Send an Email

This guide explains how to enqueue an email using the Channel Delivery API.

## Pre-requisites

1. The service must be running and accessible over HTTP.
2. You must have a valid API key.
3. The calling server IP must be whitelisted for that API key.
4. The service `.env` must be configured with valid SMTP settings.
5. The selected email template must exist and the required template context must be provided.

## 1. Verify the Template

Ensure the template you want to use exists under the email templates directory.

Current template structure:

```text
templates/emails/<template_key>/<language>.twig
```

Examples:

* `templates/emails/otp/en.twig`
* `templates/emails/verification/en.twig`
* `templates/emails/welcome/en.twig`

Currently available template keys:

* `otp`
* `verification`
* `welcome`

Currently available languages for these templates:

* `ar`
* `en`

## 2. Prepare the Request

Send an HTTP request to the enqueue endpoint:

```http
POST /api/v1/email/enqueue
Content-Type: application/json
X-Api-Key: <RAW_API_KEY>
```

The request body must include:

* `entity_type`
* `recipient`
* `template_key`
* `language`
* `sender_type`

Optional fields:

* `entity_id`
* `priority`
* `context`

A successful enqueue returns:

* `202 Accepted`
* `{"status":"queued"}`

## 3. Example: Welcome Email

The `welcome` template requires the following context fields:

* `user_name`
* `activation_link`

The template also uses global values injected by the service:

* `app_name`
* `support_email`

These globals do not need to be sent by the caller.

### Example: PHP (Guzzle)

```php
use GuzzleHttp\Client;

$client = new Client([
    'base_uri' => 'http://channel-delivery-service.local',
    'timeout'  => 5.0,
]);

$response = $client->post('/api/v1/email/enqueue', [
    'headers' => [
        'Content-Type' => 'application/json',
        'X-Api-Key'    => 'your_generated_api_key_here',
    ],
    'json' => [
        'entity_type'  => 'user',
        'entity_id'    => '12345',
        'recipient'    => 'customer@example.com',
        'template_key' => 'welcome',
        'language'     => 'en',
        'sender_type'  => 1,
        'priority'     => 5,
        'context'      => [
            'user_name'       => 'Alice',
            'activation_link' => 'https://example.com/activate?token=abc123',
        ],
    ],
]);

if ($response->getStatusCode() === 202) {
    echo "Email successfully enqueued!";
}
```

## 4. Other Available Templates

### `otp`

Required context:

* `display_name`
* `purpose`
* `otp_code`
* `expires_in_minutes`

Example:

```json
{
  "entity_type": "user",
  "recipient": "user@example.com",
  "template_key": "otp",
  "language": "en",
  "sender_type": 1,
  "context": {
    "display_name": "Ahmed",
    "purpose": "login verification",
    "otp_code": "482931",
    "expires_in_minutes": 10
  }
}
```

### `verification`

Required context:

* `display_name`
* `verification_code`
* `expires_in_minutes`

Example:

```json
{
  "entity_type": "user",
  "recipient": "user@example.com",
  "template_key": "verification",
  "language": "en",
  "sender_type": 1,
  "context": {
    "display_name": "Ahmed",
    "verification_code": "482931",
    "expires_in_minutes": 10
  }
}
```

## 5. Common Failure Cases

Possible responses include:

* `400 Bad Request` → invalid or non-JSON body
* `401 Unauthorized` → missing or invalid API key
* `403 Forbidden` → caller IP not whitelisted
* `422 Unprocessable Entity` → missing required fields, invalid email, invalid types, or invalid priority
* `429 Too Many Requests` → rate limit exceeded

## 6. Worker Execution

After the request is accepted, the email is stored in the queue for asynchronous delivery.

The background worker:

```bash
php scripts/email_worker.php --batch=50
```

or in loop mode:

```bash
php scripts/email_worker.php --batch=50 --loop --sleep=5
```

The worker reads queued jobs, renders the selected Twig template, and delivers the email through SMTP.

## 7. Source of Truth

For the complete API contract, validation rules, error responses, and template requirements, refer to:

* `docs/CHANNEL_DELIVERY_API.md`
* `docs/api/email-enqueue.md`
