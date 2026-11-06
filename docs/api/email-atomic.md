# Atomic email acceptance (v2)

This additive API uses `maatify/email-delivery` 1.2.0. Existing
`POST /api/v1/email/enqueue` keeps its per-call insert and `{"status":"queued"}`
response; an Idempotency-Key on v1 does not deduplicate anything.

## Identity and authentication

Both endpoints require the existing valid, active `X-Api-Key`, IP whitelist and
rate-limit policy. Operators must provision the key's stable consumer binding
before v2 use; an unbound key gets 403. Identity is the pair **server-owned
consumer namespace + Idempotency-Key**. API key rotation preserves that namespace.
A different consumer cannot look up or conflict with this consumer's receipts.
Never put personal information or credentials in either identity string.

Supply exactly one `Idempotency-Key` header matching
`[A-Za-z0-9][A-Za-z0-9._:-]{0,190}` (1–191 ASCII bytes). It is case-sensitive.
Caller-supplied namespaces are rejected as unknown JSON fields and cannot change
scope through query parameters or headers.

## POST /api/v2/email/enqueue

Use `Content-Type: application/json`. The body has the same field names and
email template context as [v1](email-enqueue.md), with strict v2 validation:

| Field | Accepted value |
| --- | --- |
| entity_type | Required nonempty string, at most 32 bytes |
| entity_id | Optional string at most 64 bytes or null |
| recipient | Required valid email, at most 320 bytes |
| template_key | Required nonempty string, at most 100 bytes |
| language | Required nonempty string, at most 5 bytes |
| sender_type | Required JSON integer 0–255 (no numeric strings or floats) |
| priority | Optional JSON integer 1–10, default 5 |
| context | Optional JSON object, default empty; library deep validation applies |
| reply_to | Optional valid email at most 320 bytes; empty string normalizes to null |

Body must be a JSON object, at most 1 MiB before parsing, with no unknown fields.
Before conversion to the package's PHP arrays, v2 rejects JSON objects in context
with canonical integer-shaped property names (`0`, `1`, `-1`, etc.), including
sparse/mixed objects and objects inside lists. Nested empty objects (`{}`) are
also rejected: both they and list-shaped numeric objects would lose their object
type during conversion. These shapes return422 before any receipt/job is written.
Use actual lists for ordered values. Named nonempty objects and lists (including
empty lists) remain supported, as does the empty context root `{}`. A property
such as `"01"` remains a string and is supported. Never silently rewrite a used
intent to bypass validation: reconcile its original key/content first. A receipt
accepted before this guard remains queryable through GET even if its original
context shape is now rejected on POST.

No scheduling field is exposed. Template existence/rendering is checked by the
worker, so acceptance does not prove a template will render or SMTP will succeed.
Context types/order are significant to the library fingerprint: do not change
1.0 to 1, reorder fields, regenerate tokens or otherwise mutate a replay body.

```bash
curl --request POST https://delivery.example/api/v2/email/enqueue \
  --header 'X-Api-Key: <secret>' \
  --header 'Idempotency-Key: welcome-unique-intent-123' \
  --header 'Content-Type: application/json' \
  --data '{"entity_type":"user","entity_id":"123","recipient":"user@example.com","template_key":"welcome","language":"en","sender_type":1,"context":{"user_name":"Example","activation_link":"https://example.com/activate"}}'
```

202 means **confirmed atomic persistence of one queue job and its receipt**:

```json
{"status":"accepted","receipt":{"receipt_id":"<opaque>","job_id":"<string>","accepted_at":"<UTC ISO-8601 timestamp>","queue_state":"pending"}}
```

The receipt/job/time identity stays stable on replay. `queue_state` is a current
observation and can change (`pending`, `processing`, `sent`, `failed`, or `queue_missing`). Neither `sent` nor acceptance proves mailbox
delivery or exactly-once SMTP. A retained receipt prevents replay from recreating
a deleted queue job.

## GET /api/v2/email/receipt

Send the same X-Api-Key (or its correctly bound replacement) and Idempotency-Key;
no request body is needed. 200 returns the same envelope. 404
`{"error":"receipt_not_found"}` means no receipt was observed at that instant;
it does not authorize a new key. Retry the original immutable intent/key.
All v2 responses have `Cache-Control: no-store`.

## Errors and recovery

| HTTP | Meaning |
| --- | --- |
| 400 | Invalid JSON / unreadable body |
| 401 / 403 | Existing auth/IP denial; 403 also includes consumer_not_configured |
| 409 | idempotency_conflict: same identity, different immutable content |
| 413 / 415 / 422 | Body too large / unsupported media type / invalid input or key |
| 429 | Existing rate limiter denied; honor Retry-After |
| 503 | Unavailable or unresolved acceptance; never treat as definitive rejection |

503 from the atomic handler is
`{"error":"enqueue_unavailable","acceptance":"unknown","retry_same_key":true}`.
A lost response/timeout can also conceal committed acceptance. Persist the intent
and key in the caller **before** HTTP, query its receipt, and retry only that same
key/content. Handle transport and generic HTTP 5xx as uncertain too. There is no
fallback from uncertain v2 to v1, because that would create a second job.
Auth infrastructure failures can be caught by the generic framework error handler;
do not depend on every HTTP 5xx having the atomic handler's JSON envelope.

## Operators

See [v2 rollout and recovery](../how-to/atomic-email-rollout.md) for exact schema
sources, binding lifecycle, UTC sessions and retention. Optional [synthetic recording](email-synthetic.md) is a separate no-send contract
under issue #5. It does not accept a real idempotency key or return a delivery receipt.
