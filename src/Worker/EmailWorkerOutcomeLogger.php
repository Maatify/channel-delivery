<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Worker;

use Closure;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;

/** Observes the locked engine's outcome events while preserving all of its logs. */
final class EmailWorkerOutcomeLogger extends AbstractLogger
{
    private int $outcomeWriteFailures = 0;
    private ?int $lastOutcomeWriteFailedAt;
    private ?int $lastLeaseExhaustedAt;

    /** @param (Closure(self): void)|null $persistEvent */
    public function __construct(
        private readonly LoggerInterface $logger,
        ?EmailWorkerState $previous,
        private readonly ?Closure $persistEvent = null,
    ) {
        $this->lastOutcomeWriteFailedAt = $previous?->lastOutcomeWriteFailedAt;
        $this->lastLeaseExhaustedAt = $previous?->lastLeaseExhaustedAt;
    }

    /** @param array<mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        // A lost connection propagates to the runner and has its own counters.
        if ($level === LogLevel::ERROR && ($context['connection_lost'] ?? null) === false
            && in_array($message, ['Email sent but status write failed', 'Email job failure was not recorded'], true)) {
            ++$this->outcomeWriteFailures;
            $this->lastOutcomeWriteFailedAt = time();
            $this->persistEvent?->__invoke($this);
        } elseif ($level === LogLevel::WARNING && $message === 'Email job permanently failed'
            && ($context['reason'] ?? null) === 'processing_lease_expired') {
            $this->lastLeaseExhaustedAt = time();
            $this->persistEvent?->__invoke($this);
        }

        $this->logger->log($level, $message, $context);
    }

    public function outcomeWriteFailures(): int
    {
        return $this->outcomeWriteFailures;
    }

    public function lastOutcomeWriteFailedAt(): ?int
    {
        return $this->lastOutcomeWriteFailedAt;
    }

    public function lastLeaseExhaustedAt(): ?int
    {
        return $this->lastLeaseExhaustedAt;
    }
}
