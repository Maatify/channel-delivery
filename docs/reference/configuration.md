# Configuration and operator CLI

This reference follows `config/container.php`, dependency definitions and package
entry points. Empty placeholders in `.env.example` are not usable credentials.
HTTP/index, worker and key-creation scripts call Dotenv `load()` and require `.env`.
The trusted synthetic purge script calls `safeLoad()` and can use a correctly
populated process environment. Keep secrets out of source and the web document root.

## Application / database / crypto

| Variable | Behavior |
| --- | --- |
| APP_ENV |`production` enables compiled DI cache at var/cache/di; use development locally |
| APP_DEBUG |PHP boolean validation; false/0/off/no hide response exception details; server logging stays enabled |
| APP_NAME / SUPPORT_EMAIL |Template globals, default App / empty; configure your own branding |
| APP_BRAND_NAME_AR / APP_BRAND_NAME_EN | Optional Twig globals for the Arabic and English brand names. Each uses APP_NAME when unset. Applies to templates that reference the corresponding global. |
| DB_HOST / DB_PORT / DB_DATABASE |MySQL DSN, defaults127.0.0.1 /3306 /channel_delivery |
| DB_USERNAME / DB_PASSWORD |Required database credentials |
| DB_SESSION_WAIT_TIMEOUT |Session `wait_timeout` seconds applied on every new shared/worker connection (driver init command); empty uses 28800, MySQL's compiled default; integer 1..31536000, anything else fails the connection setup |
| EMAIL_PROCESSING_LEASE_SECONDS | Worker lease duration, integer 1..31536000. Empty uses 300. Size for the longest expected batch plus a margin. [Lease sizing](../how-to/run-worker.md#processing-lease) Invalid values fail worker initialization. |
| CRYPTO_ACTIVE_KEY_ID / CRYPTO_KEYS | Required active key ID and JSON registry. Each key string must contain exactly 32 raw bytes. See [encryption keys](#encryption-keys). |

### Database sessions

| Connection | Autocommit | Session time zone | Session wait timeout |
| --- | --- | --- | --- |
| Shared HTTP/CLI, including legacy v1 | Inherits the database default | Inherits the database default | Sets `DB_SESSION_WAIT_TIMEOUT` |
| Dedicated worker, including replacement connections | Sets `autocommit = 1` | Inherits the database default | Sets `DB_SESSION_WAIT_TIMEOUT` |
| Real v2 enqueue/lookup and synthetic recording/cleanup | Inherits the database default | Sets `time_zone = '+00:00'` | Inherits the database default |

Configure new connections for the service database account to inherit
`@@SESSION.autocommit = 1`. Each real v2 and synthetic operation opens a fresh
exception-mode PDO. The installed library checks that the connection is idle,
in exception mode, and has autocommit enabled and an explicit UTC session
(`+00:00` or `UTC`) before starting its own transaction. Verify the settings on
connections opened by the service and smoke-test the optional paths before
enabling callers.

```sql
SELECT @@SESSION.autocommit AS autocommit, @@SESSION.time_zone AS time_zone;
```

If a connection fails these checks, the library rejects the operation before
starting a transaction. The real v2 handlers surface an unavailable response
(HTTP 503 `enqueue_unavailable`), and the synthetic handler returns HTTP 503
`recording_unavailable`. The synthetic purge CLI exits with code 1 on
unavailability. Follow the relevant recovery contract for any 503, which can
also arise from other failures.

The email engine reconnects before a claim or around safe autocommit statements.
Set the worker timeout above the longest expected idle gap to limit reconnects.
Workers use UTC due, retry and sent clocks for receipt-bound jobs and retain
legacy timing for unbound rows.

### Encryption keys

For a fresh installation, generate the registry line in a new private file
outside the repository and web root. The command uses 24 random bytes to produce
32 Base64 characters safe for JSON and Dotenv. The service uses those 32 ASCII
bytes as key material directly. It performs no Base64 decoding.

```bash
(
  umask 077
  php -r '
    $keys = json_encode([["id" => "v1", "key" => base64_encode(random_bytes(24))]], JSON_THROW_ON_ERROR);
    printf("CRYPTO_KEYS=%s%s%s\n", chr(39), $keys, chr(39));
  ' > /path/to/new-private-file
)
```

Replace the empty `CRYPTO_KEYS` line in the private `.env` using your secret
management tooling and set `CRYPTO_ACTIVE_KEY_ID` to the matching ID (`v1` above).
For rotation, add a new unique ID and make it active. Keep previous registry
entries for as long as their keys are needed by retained ciphertext, synthetic
records and receipt fingerprint verification. A receipt can remain replayable
after its queue job has been deleted. Keep the keys needed for that entire
replay period and for synthetic records until their retention and cleanup
obligations have been met. Keep cryptographic contexts and binding identity
stable across retries. Keep key material out of source, logs and public examples.

## SMTP, worker and logging

| Variable | Behavior |
| --- | --- |
| MAIL_HOST / MAIL_PORT / MAIL_USERNAME / MAIL_PASSWORD |Required when constructing real SMTP transport |
| MAIL_FROM_ADDRESS / MAIL_FROM_NAME |Required real transport sender identity |
| MAIL_ENCRYPTION | `tls` for STARTTLS, `ssl` for implicit TLS, or empty/unset for an intentionally unencrypted relay. Other values reject SMTP initialization. |
| MAIL_TIMEOUT | SMTP connection and stream timeout in seconds. Default 10. Use observed batch times for the lease and heartbeat. |
| MAIL_DEBUG_LEVEL |SMTP diagnostic level; default0. `MAIL_DEBUG` is not read by the DI definition |
| LOG_PATH |Logger output base path; configure an absolute, writable, nonpublic directory |
| LOG_RETENTION_DAYS |Read by the logger library's LogCleaner; this service does not schedule that cleanup |

`LOG_TIMEZONE` is not read by this service/logger integration. Configure the PHP/logger
runtime deliberately rather than relying on that unused example name. Set the working
directory for CLI/process managers, particularly when paths are relative. Keep SMTP
and application debug disabled for deployment. Worker errors may include upstream
exception details in logs; access/redaction/retention are operator responsibilities.

## Redis counter and proxy handling

REDIS_HOST / REDIS_PORT default127.0.0.1 /6379; REDIS_TIMEOUT defaults2 seconds,
REDIS_PASSWORD defaults empty, REDIS_DB defaults0. Connections are lazy.

RATE_LIMIT_MAX_REQUESTS defaults100 and RATE_LIMIT_WINDOW_SECONDS defaults60.
The implementation is a **fixed-window counter** (`floor(now/window)` plus Redis
INCR/EXPIREAT), identified by authenticated API-key row id. It is shared across
that key's real/synthetic operations, not across every rotated key of a namespace.
If Redis is unavailable the existing policy is fail-open. It is not a general
DoS-protection or strict storage-quota guarantee.

TRUSTED_PROXIES is a comma-separated list of **exact proxy IP strings**. CIDR is
not implemented. X-Forwarded-For is used only if the direct REMOTE_ADDR exactly
matches this list; otherwise it is ignored. API-key IP whitelists also use exact
addresses; key-creation CLI accepts IPs, not CIDR. Leave trusted proxies empty for
direct connections. Match your actual proxy chain and never whitelist arbitrary proxies.

## Optional synthetic retention

SYNTHETIC_EMAIL_RETENTION_SECONDS must be an explicit integer1..2147483647 before
recording/maintenance can operate; no business retention default is chosen.
Missing/invalid config disables that optional path, not real email. Expired rows
remain until trusted cleanup commits; monitor backlog and capacity. Configure
both service 004 consumer bindings and the installed package's synthetic table.
See the [synthetic-only setup](../how-to/synthetic-email-operations.md).

## Commands

| CLI | Contract |
| --- | --- |
| `php scripts/create_api_key.php --name=example-client --ips=127.0.0.1` |Creates a hashed key and prints raw key once; `.env` and API-key table required |
| `php scripts/email_worker.php --batch=50` |One batch; `--loop --sleep=5` enables repeated polling; `--max-connection-failures=3` (1..9999) consecutive connection failures before a loop exits for restart; optional `--state-file=PATH` heartbeat. Exit 0 if the run finishes, 1 for an unavailable database connection, 2 for bad arguments, 3 for an exhausted-pending upgrade block. See the [worker guide](../how-to/run-worker.md#cli-command) for batch errors |
| `php scripts/email_queue_status.php` |Read-only queue and worker report in JSON. Checks due age, exhausted pending jobs, heartbeat and recent outcome failures. `--max-pending-age=600`, `--max-batch-failures=3`, `--max-heartbeat-age=600` (above one full worker iteration: longest batch plus `--sleep`), `--outcome-alert-window=600`, repeatable `--state-file=PATH`. Supply all worker files for outcome tracking. 0 within thresholds, 1 alert or status unavailable, 2 bad arguments |
| `php scripts/deploy_clear_cache.php` | Clears the two compiled caches for this release. Exit 0 on success or absent caches, 1 on failure. |
| `php scripts/purge_synthetic_email.php --limit=100` |Trusted global cleanup of at most1..1000 expired inert rows;0 success,1 unavailable/unknown,2 bad arguments |
| `php scripts/purge_synthetic_email.php --help` |Help without loading environment or DB |

Worker and purge are separate schedules; no scheduler is installed by this project.
Use the [worker guide](../how-to/run-worker.md), [atomic rollout](../how-to/atomic-email-rollout.md)
and [synthetic operations](../how-to/synthetic-email-operations.md). The diagnostic
`scripts/test_enqueue.php` sends a real v1 request when explicitly supplied a test
service and key; it does not provide atomic retry or a no-send mode.

## Compiled caches

Twig writes its compiled templates under `var/cache/twig` in the release directory.
In production, PHP-DI writes its compiled container under `var/cache/di`.
With the affected processes stopped, run `php scripts/deploy_clear_cache.php`
from the release that will start after template or container changes. For rollback
to a release without this command, start from a clean release directory with empty
caches. The cache paths and their parents within the release must be
real directories. The command removes links inside a cache as entries and leaves
their targets in place. Treat a non-zero exit as a failed cache clear.
