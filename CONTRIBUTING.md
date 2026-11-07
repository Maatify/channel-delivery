# Contributing to maatify/channel-delivery

This repository is a deployable PHP Composer project/service. Its stable consumer
surface is HTTP and the documented operator CLI; the reusable email engine lives
in [maatify/email-delivery](https://github.com/Maatify/email-delivery).
Follow the [code of conduct](CODE_OF_CONDUCT.md). Use private [security reporting](SECURITY.md)
for vulnerabilities, not public payloads or credentials.

## Source checkout and verification

Clone the repository for development. Production archives omit tests/CI development
assets; creating a deployment from an archive is not the development workflow.
Use PHP8.4 or8.5, the manifest extensions including PDO MySQL, Composer, MySQL9.2
and Redis7. Install the committed project lock; an intentional dependency update
must include the reviewed lock diff. This project is not subject to a library
no-lock policy.

```bash
composer install --no-interaction --prefer-dist
composer validate --strict
composer check-platform-reqs
composer audit --format=plain
composer test
composer analyse
```

Unit tests can run without integration DSN; integration tests then explicitly skip.
That is **not** full qualification. For the required real HTTP/auth/DI/PDO/Redis
suite, provision a disposable MySQL database whose actual name ends `_test` and set:

```text
CHANNEL_IT_REQUIRED=1
CHANNEL_IT_DSN=mysql:host=127.0.0.1;port=3306;dbname=channel_delivery_test;charset=utf8mb4
CHANNEL_IT_USER=<isolated-fixture-user>
CHANNEL_IT_PASS=<isolated-fixture-password>
CHANNEL_IT_REDIS_HOST=127.0.0.1
```

The fixture resets its named service tables and applies service plus canonical
installed-library SQL. It uses only fake SMTP transport, while actual app/auth/DI,
transactions and worker processing run. The fixture DB user needs test-table DDL
and MySQL performance_schema lock visibility for deterministic contention checks.
No two destructive suites may share that fixture concurrently. Never point it at
an application/production database, even if a name happens to end `_test`.

CI runs the required fixture matrix on8.4/8.5 plus validation/audit/PHPStan/lint.
Run PHP lint on src/tests/config/scripts/docs/examples, and `for example in examples/*.sh; do bash -n "$example"; done`.
The optional [timing smoke command](docs/how-to/synthetic-email-operations.md#qualification-and-limited-timing)
is descriptive and kept out of normal CI; no latency-equality assertion is a product guarantee.

## Documentation and release artifacts

Keep request examples, template fields, environment names and failure semantics in
sync with [canonical contracts](docs/index.md). Do not expose internal classes as
an additional supported PHP library API. Examples use decoy data and explicit
service/key configuration; preserve same-key intent on uncertain v2 acceptance.

For archive verification use Python3.9+ and the
[exact-SHA release checklist](docs/releases/RELEASE_CHECKLIST.md). The artifact checker
checks the actual ZIP, required consumer files and source-byte identity. It does
not authorize a release or establish externally published availability.

## Pull requests

Explain the concrete behavior change, compatibility impact, tests and limitations.
Include real integration evidence for transaction/worker/auth changes and honest
infrastructure failures. Discuss architectural/API/schema changes before implementation.
Link an existing issue when one governs the work; do not invent a mandatory issue
for every typo. Preserve existing migrations, key domains, receipt identity and
unknown-result handling. Do not deploy, migrate production, merge or publish a
version as an incidental part of a contribution.
