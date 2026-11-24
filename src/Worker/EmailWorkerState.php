<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Worker;

/** In-process worker counters, persisted so a separate read-only monitor can see them. */
final readonly class EmailWorkerState
{
    public function __construct(
        public int  $pid,
        public int  $updatedAt,
        public int  $consecutiveBatchFailures,
        public int  $consecutiveConnectionFailures,
        public ?int $lastBatchSucceededAt,
        public ?int $lastOutcomeWriteFailedAt = null,
        public ?int $lastLeaseExhaustedAt = null,
        public bool $outcomeTracking = true,
    ) {
    }

    /** @return array{pid: int, updated_at: int, consecutive_batch_failures: int, consecutive_connection_failures: int, last_batch_succeeded_at: int|null, last_outcome_write_failed_at: int|null, last_lease_exhausted_at: int|null} */
    public function toArray(): array
    {
        return [
            'pid'                             => $this->pid,
            'updated_at'                      => $this->updatedAt,
            'consecutive_batch_failures'      => $this->consecutiveBatchFailures,
            'consecutive_connection_failures' => $this->consecutiveConnectionFailures,
            'last_batch_succeeded_at'         => $this->lastBatchSucceededAt,
            'last_outcome_write_failed_at'    => $this->lastOutcomeWriteFailedAt,
            'last_lease_exhausted_at'          => $this->lastLeaseExhaustedAt,
        ];
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): ?self
    {
        foreach (['pid', 'updated_at', 'consecutive_batch_failures', 'consecutive_connection_failures'] as $field) {
            if (!is_int($data[$field] ?? null) || $data[$field] < 0) {
                return null;
            }
        }
        $lastSuccess = $data['last_batch_succeeded_at'] ?? null;
        $lastWriteFailure = $data['last_outcome_write_failed_at'] ?? null;
        $lastExhaustion = $data['last_lease_exhausted_at'] ?? null;
        if (($lastSuccess !== null && !is_int($lastSuccess))
            || ($lastWriteFailure !== null && (!is_int($lastWriteFailure) || $lastWriteFailure < 0))
            || ($lastExhaustion !== null && (!is_int($lastExhaustion) || $lastExhaustion < 0))) {
            return null;
        }

        /** @var array{pid: int, updated_at: int, consecutive_batch_failures: int, consecutive_connection_failures: int, last_outcome_write_failed_at?: mixed, last_lease_exhausted_at?: mixed} $data */
        return new self(
            $data['pid'],
            $data['updated_at'],
            $data['consecutive_batch_failures'],
            $data['consecutive_connection_failures'],
            $lastSuccess,
            $lastWriteFailure,
            $lastExhaustion,
            array_key_exists('last_outcome_write_failed_at', $data) && array_key_exists('last_lease_exhausted_at', $data),
        );
    }
}
