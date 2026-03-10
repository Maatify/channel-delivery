<?php

/**
 * @copyright   ©2026 Maatify.dev
 * @Library     maatify/channel-delivery
 * @Project     maatify:channel-delivery
 * @author      Mohamed Abdulalim (megyptm) <mohamed@maatify.dev>
 * @since       2026-03-10 00:10
 * @see         https://www.maatify.dev Maatify.dev
 * @link        https://github.com/Maatify/channel-delivery view Project on GitHub
 * @note        Distributed in the hope that it will be useful - WITHOUT WARRANTY.
 */

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Worker;

use Maatify\EmailDelivery\Worker\EmailQueueWorker;
use Psr\Log\LoggerInterface;

final readonly class EmailWorkerRunner
{
    public function __construct(
        private EmailQueueWorker $worker,
        private LoggerInterface  $logger,
    ) {
    }

    public function run(int $batchSize, bool $loop, int $sleepSeconds): void
    {
        $this->logger->info('Worker started', [
            'batch'  => $batchSize,
            'loop'   => $loop,
            'sleep'  => $sleepSeconds,
        ]);

        do {
            try {
                $this->worker->processBatch($batchSize);
                $this->logger->info('Batch processed', ['limit' => $batchSize]);
            } catch (\Throwable $e) {
                $this->logger->error('Worker batch failed', [
                    'message' => $e->getMessage(),
                    'trace'   => $e->getTraceAsString(),
                ]);
            }

            if ($loop) {
                sleep($sleepSeconds);
            }
        } while ($loop);

        $this->logger->info('Worker finished');
    }
}
