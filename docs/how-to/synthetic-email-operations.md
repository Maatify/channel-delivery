# Synthetic recording operations

The source change does not deploy anything, execute production SQL or alter mail
policy. Enable the optional endpoint only after the following operator steps:

1. Install the committed dependency lock (email-delivery1.2.0) on PHP8.4+.
2. Keep the existing real/service schemas and consumer bindings. Apply the optional
   **installed package** file
   `vendor/maatify/email-delivery/schema/mysql/004_email_synthetic_requests.sql`.
   This is different from service `migrations/004_create_email_consumer_bindings.sql`.
   Do not copy/rename package SQL, alter historical service001 or automatically
   migrate at startup. A missing synthetic table does not disable real v1/v2 email.
3. Explicitly configure `SYNTHETIC_EMAIL_RETENTION_SECONDS` as integer seconds
   1..2147483647. No business default is selected. Empty/invalid configuration
   makes only synthetic recording/maintenance unavailable. Production value is
   an operator/owner decision; tests use3600 seconds solely as a fixture.
4. Clear stale compiled DI definitions during deployment; in Athar use the existing
   `php scripts/deploy_clear_cache.php` procedure, preserving its templates/cache.
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

With that isolated fixture configured, the optional timing smoke test is:

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
