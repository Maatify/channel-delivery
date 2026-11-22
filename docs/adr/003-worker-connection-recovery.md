# Service ADR003: worker database connection recovery and queue-stall signals

Status: accepted by owner on 2026-11-22 under channel-delivery#12, implemented in #13.

## Context

The long-running worker caught every batch error and looped forever on one PDO
created once by the container. After any lost connection (server restart, idle
timeout, network) every batch failed at `beginTransaction()` with 2006 "MySQL
server has gone away", once per sleep interval, indefinitely. The process never
exited, so the process manager never restarted it. The API kept accepting messages
and nothing signalled that the queue had stopped. The connection also inherited
the server's global `wait_timeout`.

## Decision

- One `PdoConnectionFactory` opens every shared and worker connection. It applies
  the service's session settings on each new connection through the driver's init
  command; today `wait_timeout` from `DB_SESSION_WAIT_TIMEOUT` (default 28800, MySQL's
  compiled default). It never reconnects by itself.
- The worker runner owns its connection. Before each batch it checks that the
  connection answers; a lost connection is replaced, together with the worker built
  on it, from the factory. Replacement happens only between batches, never inside a
  transaction; a connection left inside a transaction after a failed batch is dropped.
- An iteration that cannot connect, or whose batch fails and leaves the connection
  unusable, is a connection failure. After N consecutive ones (CLI option, default 3)
  the worker exits with code 1 for the process manager to restart it. A successful
  batch, or a batch error the server answered, resets the count. Other batch errors
  keep the previous log-and-continue behavior. The log distinguishes reconnect from
  exit for restart.
- The HTTP path keeps one shared connection per request and never reconnects:
  swapping a connection inside an open transaction would silently split it.
- Stall signals come from a read-only CLI, not `/health`: the age of the oldest due
  pending message (by the worker's own due clocks), and each worker's consecutive
  batch failures and heartbeat age from an optional per-process state file. A
  heartbeat older than one full iteration (longest batch plus sleep) alerts as stale. `/health` stays an API
  liveness check with its existing contract; worker counters live in another process.
- The library's package API is unchanged. The in-batch half (idle connection during
  a long send, retrying the status write, a processing lease) belongs to
  maatify/email-delivery and will be wired here after it is released.

## Consequences

The worker recovers from a dropped connection on its next batch, and repeated
failures surface as process restarts. Operators gain one environment key, two worker
options, documented exit codes and a monitoring command with suggested thresholds.
Rows claimed by a batch whose connection drops mid-send still wait for the existing
stuck-job recovery script until the library's lease is available.
