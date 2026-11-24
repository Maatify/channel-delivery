# Atomic email rollout and recovery

This is an operator procedure, not an automatic startup migration. Never run
integration fixtures on an application database: they drop fixture tables and
require an explicitly named `_test` database. No production SQL was run as part
of implementation validation.

## Rollout

1. Back up the database; install the committed Composer lock on PHP8.4 or8.5.
   Verify installed maatify/email-delivery is 1.3.0 or a separately reviewed
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
3. [Provision a consumer binding](#provision-a-consumer-binding) for each key used for real v2.
4. Refresh the release's compiled caches before restarting affected processes.
   Follow the [cache procedure](../reference/configuration.md#compiled-caches).
5. Verify the [database session preconditions](../reference/configuration.md#database-sessions),
   including inherited `autocommit=1` on fresh v2 connections. Test v1 plus v2
   accept/replay/lookup with a dedicated test intent. Deploy
   v2-ready callers only after schemas and bindings are ready. The worker consumes
   both legacy and receipt-bound queue jobs without a mail-policy change.

### Provision a consumer binding

Apply service `migrations/004_create_email_consumer_bindings.sql` once, after
the service API-key migration. Both real v2 and synthetic use this table. For
each approved existing API-key ID, choose a stable non-secret ASCII namespace
matching `[A-Za-z0-9][A-Za-z0-9._:-]{0,127}`. Use an operator PDO tool with the
approved inputs bound as parameters.

```php
$insert = $pdo->prepare('INSERT INTO cd_email_consumer_bindings (api_key_id, consumer_namespace) VALUES (:id, :namespace)');
$insert->execute(['id' => $approvedApiKeyId, 'namespace' => $stableConsumerNamespace]);
```

Assign the namespace independently of the raw key, row ID and key name. When
real v2 is enabled, keys sharing a namespace have access to the same receipts,
so approve that boundary explicitly. For a different consumer, provision a
new key and binding. See [rotation and retention](#rotation-and-retention).

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

Each v2 enqueue or lookup opens a dedicated connection and sets its time zone to
`+00:00`. An inherited autocommit value of 0 causes HTTP 503 before the library
starts a transaction. The worker uses UTC due, retry and sent clocks for keyed
jobs and retains legacy timing for unbound rows.

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
Concurrency uses separate PHP processes and the engine's InnoDB lock-wait metadata,
so the isolated fixture DB user needs permission to inspect those test locks.
