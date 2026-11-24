<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Worker;

use Closure;
use InvalidArgumentException;
use Maatify\Crypto\Contract\CryptoContextProviderInterface;
use Maatify\Crypto\DX\CryptoProvider;
use Maatify\EmailDelivery\Renderer\EmailRendererInterface;
use Maatify\EmailDelivery\Transport\EmailTransportInterface;
use Maatify\EmailDelivery\Worker\EmailQueueWorker;
use PDO;
use Psr\Log\LoggerInterface;

final readonly class EmailQueueWorkerFactory
{
    public function __construct(
        private CryptoProvider $crypto,
        private EmailRendererInterface $renderer,
        private EmailTransportInterface $transport,
        private CryptoContextProviderInterface $contexts,
        private LoggerInterface $logger,
        private int $processingLeaseSeconds,
    ) {
    }

    /** @param Closure(): PDO $connect */
    public function create(Closure $connect, ?LoggerInterface $logger = null): EmailQueueWorker
    {
        $pending = null;
        $checkedConnect = static function () use ($connect, &$pending): PDO {
            // Keep a usable session if the read fails before the engine takes ownership.
            $pending ??= $connect();
            if (ExhaustedPendingJobs::exist($pending)) {
                throw new ExhaustedPendingJobs('Review and settle exhausted pending jobs before starting the worker.');
            }
            $pdo = $pending;
            $pending = null;

            return $pdo;
        };

        return new EmailQueueWorker(
            $checkedConnect,
            $this->crypto,
            $this->renderer,
            $this->transport,
            $this->contexts,
            $logger ?? $this->logger,
            $this->processingLeaseSeconds,
        );
    }

    public static function leaseSeconds(mixed $configured): int
    {
        if ($configured === null || $configured === '') {
            return EmailQueueWorker::DEFAULT_PROCESSING_LEASE_SECONDS;
        }
        if (!is_string($configured) || preg_match('/\A[1-9][0-9]{0,7}\z/', $configured) !== 1
            || (int) $configured > 31536000) {
            throw new InvalidArgumentException('EMAIL_PROCESSING_LEASE_SECONDS must be an integer number of seconds, 1..31536000.');
        }

        return (int) $configured;
    }
}
