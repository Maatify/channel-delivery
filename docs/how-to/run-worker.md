# How to Run the Worker

The email delivery worker pulls pending jobs from the `cd_email_queue` database, renders them via Twig, and dispatches them via SMTP.

## CLI Command

To run a single batch of emails:

```bash
php scripts/email_worker.php --batch=50
```

To run continuously (daemon mode):

```bash
php scripts/email_worker.php --batch=50 --loop --sleep=5
```

| Option | Default | Meaning |
| --- | --- | --- |
| `--batch=N` | 50 | Positive integer number of jobs claimed per batch |
| `--loop` | off | Keep polling; without it one batch runs |
| `--sleep=N` | 5 | Nonnegative integer seconds between loop iterations |
| `--max-connection-failures=N` | 3 | Consecutive database connection failures (1..9999) before a loop exits for restart |
| `--state-file=PATH` | none | Per-process JSON heartbeat for the [stall monitor](#monitor-queue-stalls) |

The worker exits `0` when a one-shot run ends without an unavailable connection,
`1` on database connection failure or after reaching the reconnect limit in loop
mode, and `2` on invalid arguments. A one-shot run with a batch error on a usable
session logs the failure even though its exit code is `0`. Use the worker log and
queue status report to verify processing. Valued options accept `--name=value`
or `--name value`.

## Database connection and recovery

The worker opens a dedicated session through the service factory. Each replacement
applies `DB_SESSION_WAIT_TIMEOUT` and enables autocommit so status writes commit independently. The email engine
checks connectivity before claiming and reconnects once around safe status
statements after a connection loss. A retry after SMTP repeats the database
write. A claim transaction is attempted once per batch.

| Event | Log record | Level |
| --- | --- | --- |
| Connection closed before the claim | `Email queue connection was closed by the server; reconnecting before the claim` | warning |
| Connection closed during a safe status statement | `Email queue connection was closed by the server; reconnecting once` | warning |
| Replacement connection opens | `Worker database connection re-established` | info |
| Connection opening fails below the restart limit | `Worker database connection failed` | warning |
| Batch raises an error after opening its connection | `Worker batch failed` | error |
| Consecutive connection failures reach the limit | `Worker exiting for restart: database connection unavailable` | critical |
| Batch returns successfully | `Batch processed` | info |

The loop returns `1` after `--max-connection-failures` consecutive failed
iterations caused by an unavailable connection or a session still in a transaction.
A one-shot run returns `1` on the first such failure. A successful batch or a
batch error on a usable session resets the connection-failure count. The latter
still increments the batch-failure counter for the stall monitor. The HTTP path
keeps its request-scoped connections.

## Processing lease

Each claim initially stamps `updated_at` for the entire batch. The worker then
renews each row immediately before rendering and sending it. Size
`EMAIL_PROCESSING_LEASE_SECONDS` to cover both the longest wait for a claimed row
to reach that renewal and the longest render, send and status write after renewal.
Use the longest expected full batch duration plus a margin as a conservative
starting point. Reduce `--batch` or raise the lease if messages near the end of
the batch could expire while waiting. A competing worker can reclaim an expired
row and use another attempt, including when the first worker has yet to send it.
An empty setting selects the 300-second default.

The service's TIMESTAMP lease compares instants. For MySQL older than 8.0.19,
use a fixed-offset or UTC worker session zone to avoid ambiguous lease stamps
during daylight saving transitions.

If the send succeeds but the status cannot be saved, the engine logs
`Email sent but status write failed`. A later batch can reclaim the row after its
lease expires, with possible redelivery. After four attempts, an expired row
becomes `failed` with `processing_lease_expired` and no additional send.
Delivery is at-least-once. During an upgrade, disable any old scheduled reset
before starting workers that use leases. [Upgrade procedure](../upgrade/2.1.0.md)

## Supervisor Configuration

For production, you should run the worker under a process manager like **Supervisor**.

Create a configuration file at `/etc/supervisor/conf.d/channel-delivery-email.conf`:

```ini
[program:cd_email_worker]
process_name=%(program_name)s_%(process_num)02d
directory=/path/to/channel-delivery
command=php /path/to/channel-delivery/scripts/email_worker.php --batch=50 --loop --sleep=5 --state-file=/path/to/channel-delivery/var/worker/email_worker_%(process_num)02d.json
autostart=true
autorestart=true
startsecs=0
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/channel-delivery/var/log/email_worker.log
```

Give each process its own `--state-file` in an existing local directory writable by
the worker and readable by the monitor. Keep that directory outside the public
document root and restrict writes to the worker account and deployment operators.
Create the directory for `stdout_logfile` before loading the Supervisor configuration.

Keep `startsecs=0`. With Supervisor's default `startsecs=1`, a worker that exits within
its first second counts as a failed start. That happens, for example, with
`--max-connection-failures=1` or a very short `--sleep` while the database is down
at start. Supervisor then moves it to `BACKOFF`, gives up after `startretries`
(default 3) and leaves it `FATAL`. `autorestart=true` does not apply in `FATAL`, so
the worker stays stopped even after the database is back. With `startsecs=0`, every
exit is a normal restart, and Supervisor keeps restarting the worker until the
database answers. Supervisor restarts it right away (about once a second in a test
run), so keep the default `--max-connection-failures=3` and `--sleep=5` to restart
less often: each process then lives at least about 10 seconds while the database is down. If a
worker does stop for good, its heartbeat goes stale and the
[stall monitor](#monitor-queue-stalls) reports `worker_heartbeat_stale`.

Then update Supervisor:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start cd_email_worker:*
```

## systemd

```ini
# /etc/systemd/system/channel-delivery-email-worker.service
[Unit]
Description=channel-delivery email worker
After=network-online.target
Wants=network-online.target
# Keep restarting during a long database outage instead of giving up.
StartLimitIntervalSec=0

[Service]
User=www-data
WorkingDirectory=/path/to/channel-delivery
ExecStart=/usr/bin/php scripts/email_worker.php --batch=50 --loop --sleep=5 --state-file=/path/to/channel-delivery/var/worker/email_worker.json
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

`Restart=always` restarts the worker after it exits for restart (code `1`).

## Monitor queue stalls

The API keeps accepting messages (`2xx`) while the worker is stalled, so acceptance
is not a health signal, and `GET /health` only checks the API's own database
connection. Check the queue itself with the read-only status script:

```bash
php scripts/email_queue_status.php --state-file=/path/to/channel-delivery/var/worker/email_worker_00.json --state-file=/path/to/channel-delivery/var/worker/email_worker_01.json
```

It prints one JSON report and exits `0` within thresholds, `1` on an alert or when
the status is unavailable, `2` on bad arguments:

```json
{"status":"ok","alerts":[],"queue":{"due_pending":0,"oldest_due_pending_age_seconds":null},"workers":[{"state_file":"/path/to/channel-delivery/var/worker/email_worker_00.json","available":true,"pid":1234,"updated_seconds_ago":3,"consecutive_batch_failures":0,"consecutive_connection_failures":0,"last_batch_succeeded_at":1700000000}],"thresholds":{"max_pending_age_seconds":600,"max_batch_failures":3,"max_heartbeat_age_seconds":600}}
```

- `oldest_due_pending_age_seconds` is the time since the oldest `pending` message
  became due for the worker (its `scheduled_at` reached and any `retry_after` passed),
  measured with the worker's own clocks. Messages scheduled for later or waiting out
  a retry backoff are pending on purpose and do not count.
- `consecutive_batch_failures` comes from each worker's state file: iterations in a
  row without a successful batch, connection failures included.
- `updated_seconds_ago` is the heartbeat age: seconds since that worker last rewrote
  its state file. The worker rewrites it once per loop iteration, so a worker that
  stopped, hangs, or exited without being restarted leaves an old heartbeat behind,
  even when its last counters looked healthy.

**Suggested alerts** (the script's defaults): the oldest due pending message is older
than **600 seconds** (`--max-pending-age`), a worker has **3 or more** consecutive
batch failures (`--max-batch-failures`), a worker's heartbeat is older than
**600 seconds** (`--max-heartbeat-age`, alert `worker_heartbeat_stale`), a listed state
file is missing or invalid, or the script itself reports `unavailable`.

A healthy worker with the default `--sleep=5` drains due messages within seconds,
unless the backlog is larger than its throughput. Raise `--max-pending-age` if your
normal backlog takes longer to drain.

Size `--max-heartbeat-age` from the iteration time you observe. The worker writes its
heartbeat once per loop iteration, so the threshold must exceed the longest iteration:
the slowest batch plus `--sleep`. There is no hard upper bound on one batch. A batch
sends up to `--batch` messages one after another. `MAIL_TIMEOUT` configures the
SMTP client's connection and stream timeout. Response waiting also has its own
timers, so use measured iteration times under real load and failures when sizing
the heartbeat threshold. Check the spacing of `Batch processed` records and set
the threshold to the worst case you see plus a margin. 600 seconds is a starting
point, not a guarantee. Too low a threshold alerts on a healthy worker in the middle
of a long batch.

For example, run the script every minute from cron and alert on a non-zero exit.

The worker consumes only the real queue. Synthetic records need the separate
[trusted cleanup CLI](synthetic-email-operations.md), never a worker schedule.
For receipt-bound v2 jobs the installed library uses UTC clocks; unbound legacy
rows keep their existing timing. Keep required decrypt keys, absolute log paths
and deployment cache handling. Configure SMTP debug with `MAIL_DEBUG_LEVEL`, not
`MAIL_DEBUG`. A sent status records transport handling, not mailbox confirmation.
