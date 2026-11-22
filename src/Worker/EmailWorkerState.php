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
    ) {
    }

    /** @return array{pid: int, updated_at: int, consecutive_batch_failures: int, consecutive_connection_failures: int, last_batch_succeeded_at: int|null} */
    public function toArray(): array
    {
        return [
            'pid'                             => $this->pid,
            'updated_at'                      => $this->updatedAt,
            'consecutive_batch_failures'      => $this->consecutiveBatchFailures,
            'consecutive_connection_failures' => $this->consecutiveConnectionFailures,
            'last_batch_succeeded_at'         => $this->lastBatchSucceededAt,
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
        if ($lastSuccess !== null && !is_int($lastSuccess)) {
            return null;
        }

        /** @var array{pid: int, updated_at: int, consecutive_batch_failures: int, consecutive_connection_failures: int} $data */
        return new self(
            $data['pid'],
            $data['updated_at'],
            $data['consecutive_batch_failures'],
            $data['consecutive_connection_failures'],
            $lastSuccess,
        );
    }
}
