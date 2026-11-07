# API Reference: Email Enqueue

Optional [synthetic recording](../api/email-synthetic.md) is a separate trusted no-send endpoint; it never enqueues email.


For keyed atomic acceptance and receipt recovery, see the additive [v2 contract](../api/email-atomic.md). The v1 examples below retain legacy per-call inserts.


`POST /api/v1/email/enqueue`

Enqueues a new email message for background delivery.

## Headers

- `Content-Type: application/json`
- `X-Api-Key: string` (Required)

## Request Body

| Field | Accepted input / normalization | Required | Description |
| --- | --- | ---: | --- |
| `entity_type` | string | Yes | Logical owner or subject type, for example `user`, `customer`, or `admin`. |
| `entity_id` | string or null; other types become null | No | Optional identifier; non-string values are not converted to strings. |
| `recipient` | string | Yes | Must be a valid email address. |
| `reply_to` | nonempty string; other types or empty string are ignored | No | A nonempty string must be a valid email address; otherwise the request is rejected. |
| `template_key` | string | Yes | Email template identifier. Choose an available template for successful worker rendering. |
| `language` | string | Yes | Template language code; existing templates provide `ar` and `en`. |
| `sender_type` | number or numeric string, cast to integer | Yes | Numeric sender type. |
| `priority` | number or numeric string, cast to integer; otherwise 5 | No | The resulting integer must be from `1` to `10`. |
| `context` | JSON object or list; otherwise empty array | No | Parsed PHP arrays are preserved, including lists. Missing, null or non-array values become `[]`. |

## Validation Notes

Required fields are `entity_type`, `recipient`, `template_key`, `language` and
`sender_type`; missing, null or empty-string values for these fields are rejected.

These rules describe **legacy HTTP v1 only**; the additive [v2 contract](email-atomic.md)
has its own validation rules. They do not tighten the existing v1 behavior.

* `entity_type`, `template_key` and `language` must be strings.
* `recipient` must be a string and a valid email address.
* `sender_type` must be numeric (including numeric strings) and is cast to an integer.
* `entity_id` is kept only when it is a string. Missing, null or other types become `null`; there is no string conversion.
* `reply_to` is kept only when it is a nonempty string, which must then be a valid email address. Missing, null, non-string values and the empty string are ignored.
* `priority` is cast to an integer when numeric (including numeric strings); missing, null or nonnumeric values use `5`. The resulting integer is then checked against `1`..`10`.
* `context` preserves parsed PHP arrays, including JSON lists. Missing, null or non-array values become an empty array (`[]`), without a type-validation error.

Template availability, language availability and required template variables must
be chosen correctly for worker rendering; v1 enqueue does not validate them at acceptance.

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
* `admin_notification`

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

#### `admin_notification`

Required context fields:

* `customer_name`
* `customer_email`
* `customer_message`

Optional context fields:

* `customer_phone`
* `form_subject`

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
