# API Reference: Email Enqueue

`POST /api/v1/email/enqueue`

Enqueues a new email message for background delivery.

## Headers

- `Content-Type: application/json`
- `X-Api-Key: string` (Required)

## Request Body

| Field          | Type   | Required | Description                                                    |
| :------------- | :----- | :------- | :------------------------------------------------------------- |
| `entity_type`  | string | Yes      | The type of entity triggering the email (e.g., `user`).         |
| `entity_id`    | string | No       | Optional ID associated with the entity (e.g., `123`).          |
| `recipient`    | string | Yes      | The email address of the recipient.                            |
| `template_key` | string | Yes      | The key corresponding to a Twig template.                      |
| `language`     | string | Yes      | The language code for the template (e.g., `en`, `ar`).         |
| `sender_type`  | int    | Yes      | Internal sender identifier (e.g., `1` for default).            |
| `priority`     | int    | No       | Queue priority (1-10). Default is `5`.                          |
| `context`      | object | No       | Dynamic variables to be passed to the Twig template.            |

## Example Payload

```json
{
  "entity_type": "user",
  "recipient": "user@example.com",
  "template_key": "welcome",
  "language": "en",
  "sender_type": 1,
  "context": {
    "user_name": "Ahmed",
    "activation_link": "https://example.com/activate?token=abc123"
  }
}
```

## Responses

- `202 Accepted` - Successfully queued.
- `400 Bad Request` - Invalid JSON body.
- `401 Unauthorized` - Missing or invalid API key.
- `403 Forbidden` - API key valid, but IP not whitelisted.
- `422 Unprocessable Entity` - Missing required fields or invalid types.
- `429 Too Many Requests` - Rate limit exceeded.