# Channel Delivery usage guide

Use this service when trusted applications need encrypted asynchronous email jobs,
recoverable queue acceptance, or an isolated no-send recording call. It does not own
account eligibility, OTP generation, end-user response normalization or mailbox delivery.
For an embedded PHP email engine, use [maatify/email-delivery](https://github.com/Maatify/email-delivery).

## Requirements and release identity

This source artifact targets **2.0.0**, requiring PHP8.4+, PDO MySQL, the extensions
in composer.json, MySQL/InnoDB, Redis for the request counter and SMTP for real delivery.
CI exercises PHP8.4/8.5 and MySQL9.2. Remote HTTP clients are not required to use the service's PHP runtime. The [release guide](../releases/2.0.0.md) records
preparation/publication boundaries; a generic Composer create-project follows the
published channel, not an unpublished source target.

## First service setup

1. Obtain the reviewed source/tag and run `composer install` with the committed lock;
   add `--no-dev` for deployment. Do not run `composer update` on a deployment to select schemas.
2. Copy `.env.example` to `.env`. Set database access, valid crypto keys, branding,
   SMTP settings and direct/proxy IP handling using the [configuration reference](../reference/configuration.md).
   HTTP, worker and key-creation entry points require the `.env` file; do not assume
   process-only configuration replaces that file. Keep `APP_DEBUG=false` in deployed environments.
3. On a fresh, backed-up database apply service migrations001,002,003 in order.
   Real v2 additionally requires service004 then installed-library002/003 exactly
   as described in the [atomic rollout](../how-to/atomic-email-rollout.md).
   Optional synthetic recording uses installed-library004, a **different file**,
   plus explicit retention and [maintenance](../how-to/synthetic-email-operations.md).
   No entry point runs migrations for you. Never use the destructive integration fixture against this database.
4. Create an API key, allowing the actual caller IP:

   ```bash
   php scripts/create_api_key.php --name=example-client --ips=127.0.0.1
   ```

   Save the output in a secret store; it is shown once and the DB stores its hash.
   v2 keys also need an operator-provisioned stable consumer binding. Key rotation
   reuses the approved namespace; [provisioning/rotation](../how-to/atomic-email-rollout.md)
   is explicit, not inferred from the key name/id/raw value.
5. Serve only `public/` through the web server and PHP. For a **local-only** server:

   ```bash
   php -S 127.0.0.1:8080 -t public public/index.php
   ```

   PHP's built-in server is not a production process manager. Keep repository root,
   `.env`, templates, logs, vendor and tests outside the public document root.
6. Start the [email worker](../how-to/run-worker.md) when real jobs should be sent.
   Ingestion202 confirms persistence, not successful rendering or SMTP.
   Set an absolute logging path and the worker's working directory explicitly.

## Choose the actual operation

| Need | Endpoint | Result and recovery |
| --- | --- | --- |
| Existing legacy consumer | POST /api/v1/email/enqueue |202 queued; each call inserts, even with the same header |
| Recoverable acceptance | POST /api/v2/email/enqueue |202 accepted plus receipt; retain immutable intent/key before HTTP |
| Resolve uncertain acceptance | GET /api/v2/email/receipt |200 receipt or point-in-time404; reuse original key/content |
| Perform inert recording | POST /api/v2/email/synthetic |202 recorded; no job/key reservation/delivery receipt; repeated calls append |

Use the [root examples](../../examples/README.md) with nonsensitive data and an explicit
test service. The v1/v2 welcome examples include both `user_name` and `activation_link`
required by the actual template. For admin mail, `reply_to` is encrypted as part of
the payload; the full field catalogue is in the [HTTP reference](../CHANNEL_DELIVERY_API.md).

## Atomic acceptance and failure

Persist the intent and its stable Idempotency-Key **before** the network request.
On timeout, connection loss or uncertain5xx, query/retry that same key and body.
404 observes no receipt at an instant; it can race another acceptance.409 means
content differs for the existing identity and needs reconciliation, not a new key.
Never fall back to v1. Receipt identity survives queue purge; `queue_missing` does
not mean delivered and replay does not recreate the queue job. Preserve read keys
and fingerprint context across rotations. See the [atomic contract](../api/email-atomic.md).

## Synthetic operation and consumer boundary

Use the dedicated endpoint without Idempotency-Key. Its encrypted record is isolated
from the queue, with operator-selected retention and trusted bounded cleanup.
Unknown commit can leave an inert row; retry can append another. It never falls
back to real email. The response deliberately says `recorded`, not `accepted`.

Real v1, real v2 and synthetic operations have different SQL/response work. A local
smoke measurement is not proof of equal timing. The application must normalize
its public response and preceding/following work, and not expose which service
operation it selected. No consumer/account flow is changed by installing this service.

## Development, upgrades and archive layout

Use a source clone for [development/tests](../../CONTRIBUTING.md); production archives
omit test/CI tooling excluded by .gitattributes. [Upgrade2.0.0](../upgrade/2.0.0.md)
covers PHP, migration order, retained receipts/keys and cache handling. Historical
short guides remain compatibility navigation, not competing sources of contract truth.
