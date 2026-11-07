# Atomic email rollout and recovery

This is an operator procedure, not an automatic startup migration. Never run
integration fixtures on an application database: they drop fixture tables and
require an explicitly named `_test` database. No production SQL was run as part
of implementation validation.

## Rollout

1. Back up the database; install the committed Composer lock on PHP8.4 or8.5.
   Verify installed maatify/email-delivery is1.2.0 or a separately reviewed
   compatible release; SQL comes from that installed version.
2. Existing installations keep service migrations001–003. Fresh installations
   apply them first in numeric order. Then apply, in this order:
   - `migrations/004_create_email_consumer_bindings.sql`
   - `vendor/maatify/email-delivery/schema/mysql/002_email_enqueue_receipts.sql`
   - `vendor/maatify/email-delivery/schema/mysql/003_email_queue_receipt_binding.sql`
   Use service migration001 for the existing service queue, **not** vendor001.
   Even a fresh service001 needs vendor002/003: the warning about fresh001 in
   vendor003 refers to the library's newer standalone001, not service001.
   Review vendor docs/reference/idempotency.md and migration preconditions. Apply each once;
   queue ALTER003 is not an idempotent migration runner. No SQL is applied on boot.
3. Provision a stable namespace for each authenticated consumer. Use approved
   non-secret ASCII `[A-Za-z0-9][A-Za-z0-9._:-]{0,127}` and explicit existing API-key
   ids. Execute parameterized SQL through an operator PDO tool, for example:

   ```php
   $insert = $pdo->prepare('INSERT INTO cd_email_consumer_bindings (api_key_id, consumer_namespace) VALUES (:id, :namespace)');
   $insert->execute(['id' => $approvedApiKeyId, 'namespace' => $stableConsumerNamespace]);
   ```

   Do not derive namespace from a secret, row id or key name. Do not automatically
   backfill. Keys intentionally sharing a namespace have access to the same
   receipts, so approve that security boundary explicitly. Do not rebind an
   existing key to another consumer; use a new key instead.
4. Test v1 plus v2 accept/replay/lookup with a dedicated test intent. Deploy
   v2-ready callers only after schemas and bindings are ready. The worker consumes
   both legacy and receipt-bound queue jobs without a mail-policy change.

## Rotation and retention

Create a replacement API key with its IP whitelist and bind it to the **same**
namespace before disabling the old key. Deleting a key cascades its binding,
not its receipts. Reusing a key id does not automatically inherit the binding.

Keep receipts even when queue jobs are deleted; replay reports `queue_missing`
and does not reinsert. There is no receipt-cleanup cron in this change. Removing
receipts or reusing a namespace/key destroys duplicate protection; define and
approve a caller recovery horizon before adding any pruning policy.

Keep the fingerprint context `channel-delivery:email:enqueue:fingerprint:v1`
unchanged, including in Athar. Preserve historical crypto key material required
by library fingerprints across rotation. Follow the installed library's key
rotation and receipt retention contract; do not silently regenerate keys.

Each v2 write/lookup creates a new nonpersistent exception-mode PDO and sets its
session timezone to `+00:00`. Legacy/shared PDO timezone is not changed. The library worker detects the
receipt binding and uses UTC due/retry/sent clocks for keyed jobs while retaining
legacy timing for unbound rows; the integration test exercises a non-UTC shared
session.

## Recovery / rollback

On unknown result, query/retry **same namespace/key and immutable body**, through
a fresh HTTP operation. A404 can race an inflight acceptance. Never fall back to
v1 or issue a fresh identity. Same-key409 requires reconciling the original intent,
not replacing it. No manual queue deletion is necessary to recover a receipt.

If v2 must be withdrawn, stop new v2 callers and retain schemas, bindings,
receipts, key material and recovery access for already persisted intents. Keep
accepted jobs eligible for existing worker processing. Do not drop queue binding
or receipts to roll back HTTP code. Caller durable intent adoption remains a
separate host change under Athar Platform#684.

## Local/CI qualification

`composer validate --strict`, `composer check-platform-reqs`, `composer audit`,
`composer analyse`, PHP lint and `composer test` from a full source clone (see [contributing](../../CONTRIBUTING.md)); deployment archives omit test infrastructure. Integration tests skip without
an explicit fixture, but CI sets `CHANNEL_IT_REQUIRED=1`, explicit MySQL DSN to
`channel_delivery_test`, fixture credentials and `CHANNEL_IT_REDIS_HOST`.
The fixture resets only service test tables, loads canonical installed SQL and
uses actual Slim/auth/DI/PDO/Redis; SMTP alone is replaced with a recording port.
Concurrency uses separate PHP processes and MySQL performance_schema lock waits,
so the isolated fixture DB user needs permission to inspect those test locks.
