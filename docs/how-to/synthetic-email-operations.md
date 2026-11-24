# Synthetic recording operations

The source change does not deploy anything, execute production SQL or alter mail
policy. Enable the optional endpoint only after the following operator steps:

1. Install the committed dependency lock (email-delivery 1.3.0) on PHP 8.4+.
   On a fresh service, apply [service 001–003](../guides/USAGE_GUIDE.md#choose-the-schema)
   first. An existing v1 service keeps those migrations and existing data.
2. Apply `migrations/004_create_email_consumer_bindings.sql`, then apply
   `vendor/maatify/email-delivery/schema/mysql/004_email_synthetic_requests.sql`
   directly from the installed package. Apply each once. Keep any bindings and
   migrations already in use. The synthetic-only path needs these two additions.
   Package 002 and 003 are for real v2 receipts and queue binding.
   [Provision a consumer binding](atomic-email-rollout.md#provision-a-consumer-binding)
   for every API key that will call synthetic.
3. Explicitly configure `SYNTHETIC_EMAIL_RETENTION_SECONDS` as integer seconds
   1..2147483647. No business default is selected. Empty/invalid configuration
   makes only synthetic recording/maintenance unavailable. Production value is
   an operator/owner decision; tests use3600 seconds solely as a fixture.
4. Refresh the release's compiled caches before restarting affected processes.
   Follow the [cache procedure](../reference/configuration.md#compiled-caches).
   Check `synthetic.sh` against a test service with a bound key and confirm HTTP
   202 `recorded`. An active key with no binding receives 403. Check that v1 still
   accepts jobs and that synthetic only adds a row to its own table.
   [Test examples](../../examples/README.md)
5. Schedule trusted cleanup externally and monitor expiry backlog/storage growth.
   No cron is installed by this source change. Example bounded invocation:

   ```bash
   php scripts/purge_synthetic_email.php --limit=100
   ```

   Each call deletes at most1..1000 expired rows, globally across consumer
   namespaces, using a fresh owned UTC connection. Default batch100 is an
   execution bound, **not** a retention policy. It never deletes real queue jobs,
   receipts, or unexpired synthetic rows. `--help` needs no configuration; invalid
   arguments fail before loading the environment or touching the database.
   Success prints `{"purged":N}`. Unavailable/unknown commit prints no success
   count and exits1; bad CLI arguments exit2. Grant access only to trusted operators.

Both recording and maintenance delegate to the released package. Every operation
opens a new exception-mode, autocommit, UTC PDO; uncertain sessions are discarded.
A lost recording commit may leave an inert row; retry may append another, with no
real enqueue fallback. Expiry is computed from database UTC, not PHP timezone.
Expired rows remain until cleanup commits. Retain decrypt keys through the chosen
retention/recovery horizon. Never apply synthetic cleanup to permanent real receipts.

## Rollback

Stop synthetic callers before withdrawing the endpoint. Keep maintenance available
for existing rows until the approved retention lifecycle completes. Do not transfer
records to the real queue. Removing this optional path must not alter v1/v2 real
acceptance or the host's delivery recovery policy. No automatic schema removal.

## Qualification and limited timing

Normal CI applies canonical package004 and runs integration tests with the explicit
isolated `_test` database/Redis fixture. They exercise actual app/auth/DI/PDO,
uncertain commits and fresh-session retries, four independent concurrent processes,
worker restart plus a real-send positive control (fake transport only), production
DI compilation, and trusted CLI cleanup with invalid-argument controls.

From a full source clone (not a deployment archive), with that isolated fixture configured, the optional timing smoke test is:

```bash
vendor/bin/phpunit --no-configuration --bootstrap tests/bootstrap.php \
  tests/Performance/SyntheticHttpTimingTest.php
```

It owns a loopback PHP HTTP server and records20 samples/case after3 warmups, in
alternating request order. Its test router requires APP_ENV=testing and an explicit
`_test` DSN; never deploy this router as a public entry point. A test-only50ms delay
checks that the measurement detects a known difference; no delay is added to the
application. The normal CI suite excludes this descriptive timing smoke test;
PHPStan still checks its source. Results do not establish production equivalence,
shared-host behavior, consumer-level security, or runtime deployment acceptance.
