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

use Closure;
use Maatify\EmailDelivery\Worker\EmailQueueWorker;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Runs email batches and lets the process manager restart a persistently disconnected worker.
 *
 * The email engine owns reconnects. The runner tracks its last session so it can
 * distinguish an escaped connection failure from a batch error on a live session.
 */
final class EmailWorkerRunner
{
    public const EXIT_OK = 0;
    public const EXIT_CONNECTION_UNAVAILABLE = 1;
    public const EXIT_QUEUE_UPGRADE_BLOCKED = 3;
    public const DEFAULT_MAX_CONNECTION_FAILURES = 3;

    /** mysqlnd notices raised while talking to a connection the server already closed. */
    private const CONNECTION_LOSS_NOTICE = '/MySQL server has gone away|Lost connection to MySQL server|Packets out of order|Error while (?:sending|reading)|Send of \d+ bytes failed/';

    /** @var Closure(): PDO */
    private readonly Closure $connect;

    /** @var Closure(Closure(): PDO, LoggerInterface): EmailQueueWorker */
    private readonly Closure $buildWorker;

    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /**
     * @param Closure(): PDO                  $connect     Opens a fresh connection with the service's session settings.
     * @param Closure(Closure(): PDO, LoggerInterface): EmailQueueWorker  $buildWorker Builds a worker that opens its own connections through the callback.
     * @param (Closure(int): void)|null       $sleep       Pause between loop iterations; defaults to sleep().
     */
    public function __construct(
        Closure                          $connect,
        Closure                          $buildWorker,
        private readonly LoggerInterface $logger,
        ?Closure                         $sleep = null,
    ) {
        $this->connect = $connect;
        $this->buildWorker = $buildWorker;
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * @param int         $maxConnectionFailures Consecutive connection failures before a loop exits for restart.
     *                                           A one-shot run (no loop) ends at its first connection failure.
     * @param string|null $stateFile             Optional per-process heartbeat for the queue status monitor.
     *
     * @return int A worker exit code for completion, connection failure, or blocked upgrade.
     */
    public function run(
        int     $batchSize,
        bool    $loop,
        int     $sleepSeconds,
        int     $maxConnectionFailures = self::DEFAULT_MAX_CONNECTION_FAILURES,
        ?string $stateFile = null,
    ): int {
        if ($maxConnectionFailures < 1) {
            throw new \InvalidArgumentException('At least one connection failure must be allowed.');
        }
        $limit = $loop ? $maxConnectionFailures : 1;
        $state = $stateFile === null ? null : new EmailWorkerStateFile($stateFile, $this->logger);
        $outcomes = new EmailWorkerOutcomeLogger(
            $this->logger,
            $stateFile === null ? null : EmailWorkerStateFile::read($stateFile),
        );

        $this->logger->info('Worker started', [
            'batch'                   => $batchSize,
            'loop'                    => $loop,
            'sleep'                   => $sleepSeconds,
            'max_connection_failures' => $limit,
        ]);

        $pdo = null;
        $worker = null;
        $attemptedConnection = false;
        $connectionFailures = 0;
        $batchFailures = 0;
        $lastSuccess = null;

        $connect = function () use (&$pdo, &$attemptedConnection, &$connectionFailures): PDO {
            // A failed replacement must not leave the previous session visible to the runner.
            $pdo = null;
            $openingReplacement = $attemptedConnection;
            $attemptedConnection = true;
            $pdo = ($this->connect)();
            if ($openingReplacement) {
                $this->logger->info('Worker database connection re-established', [
                    'consecutive_connection_failures' => $connectionFailures,
                ]);
            }

            return $pdo;
        };

        do {
            try {
                $worker ??= ($this->buildWorker)($connect, $outcomes);
                $previousOutcomeFailures = $outcomes->outcomeWriteFailures();
                $worker->processBatch($batchSize);
                $connectionFailures = 0;
                $batchFailures = 0;
                $outcomeFailures = $outcomes->outcomeWriteFailures() - $previousOutcomeFailures;
                if ($outcomeFailures > 0) {
                    $this->logger->error('Worker batch completed with unrecorded outcomes', [
                        'outcome_write_failures' => $outcomeFailures,
                    ]);
                } else {
                    $lastSuccess = time();
                    $this->logger->info('Batch processed', ['limit' => $batchSize]);
                }
            } catch (ExhaustedPendingJobs $failure) {
                $state?->write($this->state($batchFailures, 0, $lastSuccess, $outcomes));
                $this->logger->critical('Worker startup or reconnect blocked by exhausted pending email jobs', [
                    'message' => $failure->getMessage(),
                ]);

                return self::EXIT_QUEUE_UPGRADE_BLOCKED;
            } catch (\Throwable $failure) {
                ++$batchFailures;
                $failedToConnect = $pdo === null;
                if (!$failedToConnect) {
                    $this->logger->error('Worker batch failed', [
                        'message' => $failure->getMessage(),
                        'trace'   => $failure->getTraceAsString(),
                    ]);
                }

                if ($pdo === null || $pdo->inTransaction() || !$this->answers($pdo)) {
                    // After an escaped failure, discard the engine and its unusable session.
                    $pdo = null;
                    $worker = null;
                    ++$connectionFailures;
                    if ($connectionFailures >= $limit) {
                        $state?->write($this->state($batchFailures, $connectionFailures, $lastSuccess, $outcomes));

                        return $this->exitForRestart($connectionFailures, $limit, $failure);
                    }
                    if ($failedToConnect) {
                        $this->logger->warning('Worker database connection failed', [
                            'consecutive_connection_failures' => $connectionFailures,
                            'max_connection_failures'         => $limit,
                            'message'                         => $failure->getMessage(),
                        ]);
                    }
                } else {
                    $connectionFailures = 0;
                }
            }

            $state?->write($this->state($batchFailures, $connectionFailures, $lastSuccess, $outcomes));
            $this->pause($loop, $sleepSeconds);
        } while ($loop);

        $this->logger->info('Worker finished');

        return self::EXIT_OK;
    }

    /**
     * Failure-path liveness probe. A dead connection is the expected failure here, so the driver's
     * connection-loss notices (for example "Packets out of order" when MySQL 8.0.24+
     * reports an expired wait_timeout) are not reported. Every other warning goes to
     * the previous handler, or to PHP's standard handling when there is none.
     */
    private function answers(PDO $pdo): bool
    {
        $previous = null;
        $previous = set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0) use (&$previous): bool {
            if (preg_match(self::CONNECTION_LOSS_NOTICE, $message) === 1) {
                return true;
            }

            return $previous !== null && $previous($severity, $message, $file, $line) !== false;
        });
        try {
            $statement = $pdo->query('SELECT 1');

            return $statement !== false && $statement->fetchColumn() !== false;
        } catch (\PDOException) {
            return false;
        } finally {
            restore_error_handler();
        }
    }

    private function exitForRestart(int $failures, int $limit, \Throwable $last): int
    {
        $this->logger->critical('Worker exiting for restart: database connection unavailable', [
            'consecutive_connection_failures' => $failures,
            'max_connection_failures'         => $limit,
            'exit_code'                       => self::EXIT_CONNECTION_UNAVAILABLE,
            'message'                         => $last->getMessage(),
        ]);

        return self::EXIT_CONNECTION_UNAVAILABLE;
    }

    private function pause(bool $loop, int $sleepSeconds): void
    {
        if ($loop) {
            ($this->sleep)($sleepSeconds);
        }
    }

    private function state(int $batchFailures, int $connectionFailures, ?int $lastSuccess, EmailWorkerOutcomeLogger $outcomes): EmailWorkerState
    {
        $pid = getmypid();

        return new EmailWorkerState(
            $pid === false ? 0 : $pid,
            time(),
            $batchFailures,
            $connectionFailures,
            $lastSuccess,
            $outcomes->lastOutcomeWriteFailedAt(),
            $outcomes->lastLeaseExhaustedAt(),
        );
    }
}
