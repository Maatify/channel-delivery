<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Worker;

use PDO;

/**
 * Read-only queue-stall signals for an external monitor.
 *
 * The API keeps accepting messages while the worker is stalled, so acceptance is
 * not a health signal. These values are:
 * - the age of the oldest due pending message: seconds since the oldest `pending`
 *   row became eligible for the worker (scheduled_at reached and any retry_after
 *   passed), measured with the same clocks the worker uses. Rows scheduled for
 *   the future or waiting out a retry backoff are pending on purpose and not counted.
 * - each worker's consecutive batch failures and heartbeat age, read from its state
 *   file. The worker refreshes its heartbeat after each loop iteration, so a heartbeat older
 *   than one full iteration (the longest batch plus the sleep interval) means the
 *   worker stopped or hangs, even when its last counters looked healthy.
 *
 * @phpstan-type QueueSnapshot array{due_pending: int, oldest_due_pending_age_seconds: int|null}
 * @phpstan-type WorkerReport array{state_file: string, available: bool, pid?: int, updated_seconds_ago?: int, consecutive_batch_failures?: int, consecutive_connection_failures?: int, last_batch_succeeded_at?: int|null, outcome_tracking?: bool, last_outcome_write_failed_at?: int|null, last_lease_exhausted_at?: int|null}
 * @phpstan-type Report array{status: 'ok'|'alert', alerts: list<string>, queue: QueueSnapshot, exhausted_pending: bool, outcome_tracking: 'state_files'|'not_configured', workers: list<WorkerReport>, thresholds: array{max_pending_age_seconds: int, max_batch_failures: int, max_heartbeat_age_seconds: int, outcome_alert_window_seconds: int}}
 */
final readonly class EmailQueueStallMonitor
{
    public const DEFAULT_MAX_PENDING_AGE_SECONDS = 600;
    public const DEFAULT_MAX_BATCH_FAILURES = 3;
    public const DEFAULT_MAX_HEARTBEAT_AGE_SECONDS = 600;
    public const DEFAULT_OUTCOME_ALERT_WINDOW_SECONDS = 600;

    private const TABLE = 'cd_email_queue';

    public function __construct(private PDO $pdo)
    {
    }

    /** @return QueueSnapshot */
    public function queue(): array
    {
        // Same due clock as the installed worker: receipt-bound rows use UTC, unbound rows the session clock.
        $clock = $this->hasReceiptBinding()
            ? 'CASE WHEN enqueue_receipt_id IS NULL THEN NOW() ELSE UTC_TIMESTAMP() END'
            : 'NOW()';
        $table = self::TABLE;

        $statement = $this->pdo->query("
            SELECT COUNT(*) AS due_pending,
                   MAX(TIMESTAMPDIFF(SECOND, GREATEST(scheduled_at, COALESCE(retry_after, scheduled_at)), $clock)) AS oldest_age
            FROM `$table`
            WHERE status = 'pending'
              AND scheduled_at <= $clock
              AND (retry_after IS NULL OR retry_after <= $clock)
        ");
        if ($statement === false) {
            throw new \RuntimeException('Email queue status is unavailable.');
        }
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        if (!is_array($row) || !is_numeric($row['due_pending'] ?? null)) {
            throw new \RuntimeException('Email queue status is unavailable.');
        }
        $oldest = $row['oldest_age'] ?? null;

        return [
            'due_pending'                    => (int) $row['due_pending'],
            'oldest_due_pending_age_seconds' => is_numeric($oldest) ? max(0, (int) $oldest) : null,
        ];
    }

    /**
     * @param list<string> $stateFiles
     *
     * @return Report
     */
    public function report(
        int $maxPendingAgeSeconds,
        int $maxBatchFailures,
        int $maxHeartbeatAgeSeconds,
        array $stateFiles,
        int $now,
        int $outcomeAlertWindowSeconds = self::DEFAULT_OUTCOME_ALERT_WINDOW_SECONDS,
    ): array
    {
        $queue = $this->queue();
        $alerts = [];
        $exhaustedPending = ExhaustedPendingJobs::exist($this->pdo);
        if ($exhaustedPending) {
            $alerts[] = 'exhausted_pending';
        }
        if ($queue['oldest_due_pending_age_seconds'] !== null && $queue['oldest_due_pending_age_seconds'] > $maxPendingAgeSeconds) {
            $alerts[] = 'oldest_due_pending_age';
        }

        $workers = [];
        foreach ($stateFiles as $path) {
            $state = EmailWorkerStateFile::read($path);
            if ($state === null) {
                $workers[] = ['state_file' => $path, 'available' => false];
                $alerts[] = 'worker_state_unavailable';
                continue;
            }
            $updatedSecondsAgo = max(0, $now - $state->updatedAt);
            $workers[] = [
                'state_file'                      => $path,
                'available'                       => true,
                'pid'                             => $state->pid,
                'updated_seconds_ago'             => $updatedSecondsAgo,
                'consecutive_batch_failures'      => $state->consecutiveBatchFailures,
                'consecutive_connection_failures' => $state->consecutiveConnectionFailures,
                'last_batch_succeeded_at'         => $state->lastBatchSucceededAt,
                'outcome_tracking'                => $state->outcomeTracking,
                'last_outcome_write_failed_at'    => $state->lastOutcomeWriteFailedAt,
                'last_lease_exhausted_at'          => $state->lastLeaseExhaustedAt,
            ];
            if ($updatedSecondsAgo > $maxHeartbeatAgeSeconds) {
                $alerts[] = 'worker_heartbeat_stale';
            }
            if ($state->consecutiveBatchFailures >= $maxBatchFailures) {
                $alerts[] = 'consecutive_batch_failures';
            }
            if (!$state->outcomeTracking) {
                $alerts[] = 'outcome_tracking_unavailable';
            }
            if ($state->lastOutcomeWriteFailedAt !== null && $now - $state->lastOutcomeWriteFailedAt <= $outcomeAlertWindowSeconds) {
                $alerts[] = 'outcome_write_failed';
            }
            if ($state->lastLeaseExhaustedAt !== null && $now - $state->lastLeaseExhaustedAt <= $outcomeAlertWindowSeconds) {
                $alerts[] = 'processing_lease_exhausted';
            }
        }
        $alerts = array_values(array_unique($alerts));

        return [
            'status'     => $alerts === [] ? 'ok' : 'alert',
            'alerts'     => $alerts,
            'queue'      => $queue,
            'exhausted_pending' => $exhaustedPending,
            'outcome_tracking' => $stateFiles === [] ? 'not_configured' : 'state_files',
            'workers'    => $workers,
            'thresholds' => [
                'max_pending_age_seconds'   => $maxPendingAgeSeconds,
                'max_batch_failures'        => $maxBatchFailures,
                'max_heartbeat_age_seconds' => $maxHeartbeatAgeSeconds,
                'outcome_alert_window_seconds' => $outcomeAlertWindowSeconds,
            ],
        ];
    }

    private function hasReceiptBinding(): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $statement->execute(['table' => self::TABLE, 'column' => 'enqueue_receipt_id']);
        $count = $statement->fetchColumn();
        $statement->closeCursor();

        return is_numeric($count) && (int) $count > 0;
    }
}
