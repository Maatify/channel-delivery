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
| `--batch=N` | 50 | Jobs claimed per batch |
| `--loop` | off | Keep polling; without it one batch runs |
| `--sleep=N` | 5 | Seconds between loop iterations |
| `--max-connection-failures=N` | 3 | Consecutive database connection failures (1..9999) before a loop exits for restart |
| `--state-file=PATH` | none | Per-process JSON heartbeat for the [stall monitor](#monitor-queue-stalls) |

Exit codes: `0` finished, `1` database connection unavailable, `2` bad arguments.

## Database connection and recovery

The worker owns its database connection. It opens it from the same factory as the
rest of the service, which applies `DB_SESSION_WAIT_TIMEOUT` on every new connection,
so the worker does not depend on the server's global `wait_timeout`.

- **Before every batch** the worker checks that its connection still answers. If
  the server closed it (restart, idle timeout, network), the worker opens a new
  connection, rebuilds itself on it and runs the batch.
- **A connection failure** is an iteration that cannot connect, or whose batch fails
  and afterwards leaves the connection unusable or still inside a transaction. Such a
  connection is never reused; the next iteration opens a fresh one.
- **After `--max-connection-failures` consecutive connection failures** the loop exits
  with code `1`, so the process manager restarts it. A successful batch, or a batch
  error the server itself answered, resets the count. A single run (no `--loop`)
  exits `1` on its first connection failure.
- **Other batch errors** (the server answered with an SQL error) keep the loop going
  on the same connection, as before.

| Event | Log record | Level | Connection failure count |
| --- | --- | --- | --- |
| The pre-batch check finds the connection closed | `Worker database connection lost; reconnecting` | warning | unchanged; a reconnect follows in the same iteration |
| A connect attempt fails, below the limit | `Worker database connection failed` (with the count, the limit and the driver message) | warning | +1 |
| A connect succeeds after a lost connection or failed attempts | `Worker database connection re-established` | info | unchanged |
| A batch fails, for any reason | `Worker batch failed` | error | +1 if the connection no longer answers or is still inside a transaction; otherwise reset to 0 |
| The count reaches the limit, from a failed connect or a failed batch | `Worker exiting for restart: database connection unavailable` | critical | the run exits `1` |
| A batch succeeds | `Batch processed` | info | reset to 0 |

The last failed connect attempt logs only the critical record, not the warning. A
failed batch that reaches the limit logs `Worker batch failed` first, then the
critical record.

The connection is replaced only between batches, never inside a transaction, and
only the worker's own connection. HTTP requests keep one shared connection per
request and never reconnect: replacing it inside an open transaction would silently
split that transaction across two sessions.

A connection that drops in the middle of a batch (for example during a long SMTP
send) still fails that batch, and the rows it claimed stay `processing` until
`scripts/recover_stuck_jobs.php` resets them. Run that script on a schedule.
Known limitation: it compares receipt-bound rows' UTC `updated_at` with the session
clock and fences its reset only on status; see [channel-delivery#14](https://github.com/Maatify/channel-delivery/issues/14).

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

Give each process its own `--state-file`; the directory must exist and be writable
by the worker user.

Keep `startsecs=0`. With Supervisor's default `startsecs=1`, a worker that exits within
its first second counts as a failed start. That happens, for example, with
`--max-connection-failures=1` or a very short `--sleep` while the database is down
at start. Supervisor then moves it to `BACKOFF`, gives up after `startretries`
(default 3) and leaves it `FATAL`. `autorestart=true` does not apply in `FATAL`, so
the worker stays stopped even after the database is back. With `startsecs=0`, every
exit is a normal restart, and Supervisor keeps restarting the worker until the
database answers. Supervisor restarts it right away (about once a second in a test
run), so keep the default `--max-connection-failures=3` and `--sleep=5` to restart
less often: each process then lives about 10 seconds while the database is down. If a
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

Set `--max-heartbeat-age` above one full worker iteration: the longest batch plus the
`--sleep` interval. The longest batch is roughly `--batch` times the slowest single
send, so with `--batch=50`, `MAIL_TIMEOUT=10` and `--sleep=5` one iteration can take
about 505 seconds, which the 600-second default covers. Raise it if you use larger
batches or slower transports, or the alert fires while a healthy worker is mid-batch. A healthy worker with the default
`--sleep=5` drains due messages within seconds, unless the backlog is larger than
its throughput. Raise the age threshold if your normal backlog takes longer to drain.
For example, run it every minute from cron and alert on a non-zero exit.

The worker consumes only the real queue. Synthetic records need the separate
[trusted cleanup CLI](synthetic-email-operations.md), never a worker schedule.
For receipt-bound v2 jobs the installed library uses UTC clocks; unbound legacy
rows keep their existing timing. Keep required decrypt keys, absolute log paths
and deployment cache handling. Configure SMTP debug with `MAIL_DEBUG_LEVEL`, not
`MAIL_DEBUG`. A sent status records transport handling, not mailbox confirmation.
