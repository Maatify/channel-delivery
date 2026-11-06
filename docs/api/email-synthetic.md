# Synthetic email recording HTTP API

`POST /api/v2/email/synthetic` is a **trusted service-to-service**, explicit no-send
endpoint using email-delivery1.2.0. It receives and records encrypted decoy message
data in package-owned `cd_email_synthetic_requests`; the email worker never reads
that table. It has no real queue writer or maintenance port injected.

## Request

Use `X-Api-Key` and `Content-Type: application/json`, with the same active-key,
IP whitelist, server-owned consumer binding and existing shared per-key rate limit
as real v2 email. A rotated key keeps the operator-approved namespace. The caller
cannot select a namespace in its request. Missing binding returns403.

The JSON field names, byte bounds, strict integer rules and raw JSON shape checks
are **shared** with [atomic email](email-atomic.md). Maximum raw body is1MiB before
parsing. Nested empty objects or integer-shaped object property names are rejected
as documented there. No scheduling, mode, suppression or retention field is accepted.
A `synthetic` flag sent to real v2 enqueue is rejected as unknown, not interpreted.

Do **not** send an `Idempotency-Key`: it is rejected422 because this append-only
contract provides no deduplication or real-key reservation. Every successful call
creates a distinct inert record. Retrying a lost response can add another inert
record, but cannot create an email job. There is no lookup endpoint for these rows.

```bash
curl --request POST https://delivery.example/api/v2/email/synthetic \
  --header 'X-Api-Key: <secret>' \
  --header 'Content-Type: application/json' \
  --data '{"entity_type":"security_probe","recipient":"decoy@example.invalid","template_key":"welcome","language":"en","sender_type":1,"context":{"user_name":"Decoy","activation_link":"https://example.invalid/unused"}}'
```

Use nonsensitive decoy data that satisfies the same validation as real data.
Metadata (namespace/entity/template/language/dates) is plaintext; recipient and
payload are encrypted using package-owned synthetic crypto domains separate from
real email. Do not store real OTPs, credentials or personal data merely to create work.

## Response

After a confirmed commit:202, `Cache-Control: no-store`:

```json
{"status":"recorded","record":{"record_id":"<opaque>","recorded_at":"<UTC ISO timestamp>","expires_at":"<UTC ISO timestamp>"}}
```

This confirms **inert recording only**; it is deliberately distinct from real
email acceptance. No job, queue state or delivery receipt is returned. It is not
an instruction to enqueue later. Nothing copies these records into the real queue.

Invalid JSON400; auth401/IP or consumer403; unsupported media415; invalid fields,
shapes or Idempotency-Key422; body too large413; current shared rate quota429.
Missing/invalid server retention, storage/schema/crypto failures or unknown commit
return503 with `{"error":"recording_unavailable","recording":"unknown"}`.
Framework/bootstrap errors can return generic5xx. Never turn an unavailable
synthetic call into real email enqueue or claim a confirmed record after failure.

The existing Redis rate-limit fail-open policy is retained, just as for real email;
this endpoint does not add a stronger quota guarantee. Configure capacity, monitor
storage/backlogs and operate cleanup; retention alone does not bound stored rows
when cleanup is not running. There is no new anonymous endpoint or automatic cron.

## Timing and consumer responsibility

The shared path includes HTTP, authentication, rate limiting, binding, bounded
validation, encryption and committed storage. The synthetic store performs an
inert append, while real keyed acceptance reserves a receipt, fingerprints and
reconciles a job. Their SQL, responses and network sizes differ. This implementation
has **no artificial delay or constant-time promise**.

The consumer must normalize its public response and preceding/following work.
Never expose which endpoint was called or forward the distinct service response
as an account-existence signal. The service does not inspect account eligibility
or control account-enumeration behavior in Athar Platform. That adoption is separate.

An optional local HTTP smoke measurement compares sequential v1/v2 real success, replay,
16KiB bodies, malformed JSON, auth failures and missing-table503/recovery, with a known delay sensitivity
control located **only in the test router**.20 observations/case are descriptive,
not a production security guarantee or proof of matching timing distributions.
Independent-process tests also exercise concurrent recording and worker isolation.

See [operations](../how-to/synthetic-email-operations.md) for configuration,
canonical schema, cleanup and the exact benchmark invocation.
