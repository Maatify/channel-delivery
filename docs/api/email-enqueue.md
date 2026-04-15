# API Reference: Email Enqueue

`POST /api/v1/email/enqueue`

Enqueues a new email message for background delivery.

## Headers

- `Content-Type: application/json`
- `X-Api-Key: string` (Required)

## Request Body

| Field | Type | Required | Description |
|---|---|---:|---|
| `entity_type` | string | Yes | The type of entity triggering the email, for example `user`, `customer`, or `admin`. |
| `entity_id` | string | No | Optional ID associated with the entity. |
| `recipient` | string | Yes | Recipient email address. Must be a valid email. |
| `template_key` | string | Yes | Email template key. Must match an available template. |
| `language` | string | Yes | Template language code. Currently supported by existing templates: `ar`, `en`. |
| `sender_type` | int \\| float | Yes | Numeric sender type value required by the service contract. |
| `priority` | int | No | Queue priority from `1` to `10`. Default is `5`. |
| `context` | object | No | Template variables passed to the selected Twig template. Default is `{}`. |

## Validation Notes

- Required fields:
  - `entity_type`
  - `recipient`
  - `template_key`
  - `language`
  - `sender_type`
- `recipient` must be a valid email address.
- `priority` must be between `1` and `10`.
- `context` is optional, but when a template requires specific variables, those variables must be present.

## Example Payload

```json
{
  "entity_type": "user",
  "entity_id": "123",
  "recipient": "user@example.com",
  "template_key": "welcome",
  "language": "en",
  "sender_type": 1,
  "priority": 5,
  "context": {
    "user_name": "Ahmed",
    "activation_link": "https://example.com/activate?token=abc123"
  }
}
```

## Available Templates

Currently available templates:

* `otp`
* `verification`
* `welcome`

Currently supported languages for these templates:

* `ar`
* `en`

### Template-Specific Context Requirements

#### `otp`

Required context fields:

* `display_name`
* `purpose`
* `otp_code`
* `expires_in_minutes`

#### `verification`

Required context fields:

* `display_name`
* `verification_code`
* `expires_in_minutes`

#### `welcome`

Required context fields:

* `user_name`
* `activation_link`

Automatically injected template globals:

* `app_name`
* `support_email`

These globals do not need to be sent by the caller.

## Success Response

* `202 Accepted`

Example:

```json
{
  "status": "queued"
}
```

## Error Responses

* `400 Bad Request` - Invalid JSON body
* `401 Unauthorized` - Missing or invalid API key
* `403 Forbidden` - API key valid, but caller IP is not whitelisted
* `422 Unprocessable Entity` - Missing required fields, invalid field types, invalid email, or invalid priority
* `429 Too Many Requests` - Rate limit exceeded

## Related Documentation

For the full service contract, detailed validation rules, error examples, and integration guidance, see:

* `docs/CHANNEL_DELIVERY_API.md`
* `docs/how-to/send-email.md`
