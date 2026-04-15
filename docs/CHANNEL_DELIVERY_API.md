# Channel Delivery API Documentation

## Overview

This document defines the current public HTTP contract for the Channel Delivery email enqueue service.

The service currently exposes:

* `GET /health`
* `POST /api/v1/email/enqueue`

This document should be updated whenever:

* a new endpoint is added
* a request/response contract changes
* a new email template is added
* a template context changes
* authentication or rate-limit behavior changes

---

## Base Behavior

### Authentication

Protected API endpoints require:

* Header: `X-Api-Key: <RAW_API_KEY>`

The API key must be:

* valid
* active
* allowed for the caller IP address

### Content Type

Requests must use:

* `Content-Type: application/json`

### Rate Limiting

Protected endpoints are rate limited.

Response headers may include:

* `X-RateLimit-Limit`
* `X-RateLimit-Remaining`
* `X-RateLimit-Reset`
* `Retry-After` (on 429)

### Enqueue Semantics

`POST /api/v1/email/enqueue` does **not** send the email immediately.

It queues the email for processing by the worker and returns `202 Accepted` when the request is accepted successfully.

---

## Endpoint: Health Check

### Request

```http
GET /health
```

### Purpose

Checks whether the service is alive and whether database connectivity is healthy.

### Success Response

```json
{
  "status": "ok",
  "db": "ok"
}
```

### Degraded Response

```json
{
  "status": "degraded",
  "db": "error"
}
```

### Status Codes

* `200 OK` → service healthy
* `503 Service Unavailable` → service alive but database degraded

---

## Endpoint: Enqueue Email

### Request

```http
POST /api/v1/email/enqueue
X-Api-Key: <RAW_API_KEY>
Content-Type: application/json
```

### Request Body

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

### Request Fields

| Field          | Type          	| Required | Description                                                                    |
| -------------- | -----------------| -------: | ------------------------------------------------------------------------------ |
| `entity_type`  | string        	|      Yes | Logical owner or subject type, for example `user`, `customer`, or `admin`.     |
| `entity_id`    | `string | null` 	|       No | Optional identifier of the related entity.                                     |
| `recipient`    | string        	|      Yes | Recipient email address. Must be a valid email.                                |
| `template_key` | string        	|      Yes | Email template identifier. Must match an available template.                   |
| `language`     | string        	|      Yes | Template language code. Currently supported by existing templates: `ar`, `en`. |
| `sender_type`  | number        	|      Yes | Numeric sender type value required by the service contract.                    |
| `priority`     | number        	|       No | Queue priority from `1` to `10`. Default is `5`.                               |
| `context`      | object        	|       No | Template variables injected into the selected email template. Default is `{}`. |

### Success Response

**Status:** `202 Accepted`

```json
{
  "status": "queued"
}
```

---

## Validation Rules

### Required Fields

The following fields are required:

* `entity_type`
* `recipient`
* `template_key`
* `language`
* `sender_type`

### Type Rules

* `entity_type` must be a string
* `recipient` must be a string and valid email address
* `template_key` must be a string
* `language` must be a string
* `sender_type` must be numeric
* `priority` must be numeric if provided
* `context` must be an object if provided
* `entity_id` is optional and treated as string when provided

### Priority Range

* Minimum: `1`
* Maximum: `10`

---

## Error Responses

### Invalid JSON / Invalid Parsed Body

**Status:** `400 Bad Request`

```json
{
  "error": "invalid_body",
  "message": "Request body must be JSON."
}
```

### Missing Required Fields

**Status:** `422 Unprocessable Entity`

```json
{
  "error": "validation_error",
  "message": "Missing required fields: entity_type, recipient, template_key, language, sender_type"
}
```

### Invalid Field Types

**Status:** `422 Unprocessable Entity`

```json
{
  "error": "validation_error",
  "message": "Invalid field types."
}
```

### Invalid Recipient Email

**Status:** `422 Unprocessable Entity`

```json
{
  "error": "validation_error",
  "message": "Invalid recipient email address."
}
```

### Invalid Priority

**Status:** `422 Unprocessable Entity`

```json
{
  "error": "validation_error",
  "message": "Priority must be between 1 and 10."
}
```

### Missing API Key

**Status:** `401 Unauthorized`

```json
{
  "error": "unauthorized",
  "message": "Missing X-Api-Key header"
}
```

### Invalid API Key

**Status:** `401 Unauthorized`

```json
{
  "error": "unauthorized",
  "message": "Invalid API key"
}
```

### IP Not Allowed

**Status:** `403 Forbidden`

```json
{
  "error": "forbidden",
  "message": "IP not allowed: <client_ip>"
}
```

### Rate Limit Exceeded

**Status:** `429 Too Many Requests`

```json
{
  "error": "rate_limit_exceeded",
  "message": "Too many requests. Limit: <limit> per <window>s. Retry after <seconds>s."
}
```

---

## Available Email Templates

The following templates are currently available:

* `otp`
* `verification`
* `welcome`

Supported languages for current templates:

* `ar`
* `en`

### Shared Template Globals

The service injects the following global template variables automatically:

* `app_name`
* `support_email`

Callers do **not** need to pass these values in `context`.

---

## Template Contract: `otp`

### Purpose

General one-time code email.

### Required Context Fields

| Field                | Type            	| Description                       |
| -------------------- | ------------------ | --------------------------------- |
| `display_name`       | string          	| Recipient display name            |
| `purpose`            | string          	| Human-readable purpose of the OTP |
| `otp_code`           | string          	| OTP code shown in the message     |
| `expires_in_minutes` | `string | number` 	| Expiration time in minutes        |

### Example Request

```json
{
  "entity_type": "user",
  "entity_id": "123",
  "recipient": "user@example.com",
  "template_key": "otp",
  "language": "en",
  "sender_type": 1,
  "priority": 5,
  "context": {
    "display_name": "Ahmed",
    "purpose": "login verification",
    "otp_code": "482931",
    "expires_in_minutes": 10
  }
}
```

---

## Template Contract: `verification`

### Purpose

Email address verification using a code.

### Required Context Fields

| Field                | Type            | Description                |
| -------------------- | --------------- | -------------------------- |
| `display_name`       | string          | Recipient display name     |
| `verification_code`  | string          | Verification code          |
| `expires_in_minutes` | string | number | Expiration time in minutes |

### Example Request

```json
{
  "entity_type": "user",
  "entity_id": "123",
  "recipient": "user@example.com",
  "template_key": "verification",
  "language": "en",
  "sender_type": 1,
  "priority": 5,
  "context": {
    "display_name": "Ahmed",
    "verification_code": "482931",
    "expires_in_minutes": 10
  }
}
```

---

## Template Contract: `welcome`

### Purpose

Welcome email with account activation link.

### Required Context Fields

| Field             | Type   | Description            |
| ----------------- | ------ | ---------------------- |
| `user_name`       | string | Recipient name         |
| `activation_link` | string | Account activation URL |

### Uses Automatic Globals

* `app_name`
* `support_email`

### Example Request

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

---

## Integration Notes for Other Projects

When integrating a second project with this service:

1. Store the raw API key securely.
2. Ensure the calling server IP is whitelisted.
3. Use only supported `template_key` values.
4. Pass the exact required `context` fields for the selected template.
5. Treat `202 Accepted` as successful queue acceptance, not immediate delivery.
6. Monitor worker execution separately if delivery confirmation is required.

---

## Maintenance Policy

Update this document whenever any of the following changes:

* new template directory added under `templates/emails/`
* new language file added to a template
* template variables added or removed
* request validation rules updated
* authentication behavior updated
* response format updated
* new endpoint added

Recommended update workflow:

1. Add or modify the template
2. Update this documentation in the same change
3. Add an example payload for the new template
4. Verify the integration contract with the calling project
