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
| APP_BRAND_NAME_AR / APP_BRAND_NAME_EN | Optional Twig globals for the Arabic and English brand names. Both default to APP_NAME. |
| DB_HOST / DB_PORT / DB_DATABASE |MySQL DSN, defaults127.0.0.1 /3306 /channel_delivery |
| DB_USERNAME / DB_PASSWORD |Required database credentials |
| DB_SESSION_WAIT_TIMEOUT |Session `wait_timeout` seconds applied on every new shared/worker connection (driver init command); empty uses 28800, MySQL's compiled default; integer 1..31536000, anything else fails the connection setup |
| EMAIL_PROCESSING_LEASE_SECONDS | Worker lease duration, integer 1..31536000. Empty uses 300. Size for the longest expected batch plus a margin. [Lease sizing](../how-to/run-worker.md#processing-lease) Invalid values fail worker initialization. |
| CRYPTO_ACTIVE_KEY_ID / CRYPTO_KEYS |Required JSON registry; each key material string must contain exactly32 raw bytes; no automatic hex/base64 decoding |

Shared HTTP/CLI sessions and dedicated worker sessions use the service connection
factory. Every replacement worker session enables autocommit. The email engine
reconnects before a claim or around safe autocommit statements. Set the timeout
above the longest expected idle gap to limit reconnects.

Keep historical read keys for retained receipts and queued jobs. Cryptographic
contexts and binding identity must not change across retries. v2/synthetic operations
own fresh exception-mode, autocommit UTC PDO sessions; the legacy/shared PDO clock
is not changed. Workers use UTC due/retry/sent clocks for receipt-bound jobs and
retain legacy timing for unbound rows.

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
remain until trusted cleanup commits; monitor backlog and capacity. The library's
synthetic004 schema is separate from service004 consumer bindings.

## Commands

| CLI | Contract |
| --- | --- |
| `php scripts/create_api_key.php --name=example-client --ips=127.0.0.1` |Creates a hashed key and prints raw key once; `.env` and API-key table required |
| `php scripts/email_worker.php --batch=50` |One batch; `--loop --sleep=5` enables repeated polling; `--max-connection-failures=3` (1..9999) consecutive connection failures before a loop exits for restart; optional `--state-file=PATH` heartbeat. Exit 0 if the run finishes, 1 for an unavailable database connection, 2 for bad arguments. See the [worker guide](../how-to/run-worker.md#cli-command) for batch errors |
| `php scripts/email_queue_status.php` |Read-only stall check: oldest due pending age and each worker's consecutive batch failures and heartbeat age as JSON. `--max-pending-age=600`, `--max-batch-failures=3`, `--max-heartbeat-age=600` (above one full worker iteration: longest batch plus `--sleep`), repeatable `--state-file=PATH`. 0 within thresholds, 1 alert or status unavailable, 2 bad arguments |
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
