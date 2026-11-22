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
 * Drives the email worker and owns its database connection.
 *
 * Before every batch the runner checks that its connection still answers. A lost
 * connection is replaced, together with the worker built on it, from the factory.
 * Replacement only happens between batches, never inside a transaction, and only
 * on the worker's own connection: the HTTP path never uses this logic.
 *
 * An iteration that cannot connect, or whose batch fails and leaves the connection
 * unusable (or still inside a transaction), is a connection failure. After the
 * configured number of consecutive connection failures the run returns a non-zero
 * exit code so the process manager restarts the worker. A successful batch, or a
 * batch error the server itself answered, resets that count. Other batch errors are
 * logged and the loop continues, as before.
 */
final class EmailWorkerRunner
{
    public const EXIT_OK = 0;
    public const EXIT_CONNECTION_UNAVAILABLE = 1;
    public const DEFAULT_MAX_CONNECTION_FAILURES = 3;

    /** @var Closure(): PDO */
    private readonly Closure $connect;

    /** @var Closure(PDO): EmailQueueWorker */
    private readonly Closure $buildWorker;

    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /**
     * @param Closure(): PDO                  $connect     Opens a fresh connection with the service's session settings.
     * @param Closure(PDO): EmailQueueWorker  $buildWorker Builds a worker bound to the given connection.
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
     * @return int EXIT_OK, or EXIT_CONNECTION_UNAVAILABLE when the run ended on connection failures.
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

        $this->logger->info('Worker started', [
            'batch'                   => $batchSize,
            'loop'                    => $loop,
            'sleep'                   => $sleepSeconds,
            'max_connection_failures' => $limit,
        ]);

        $pdo = null;
        $worker = null;
        $connectedBefore = false;
        $connectionFailures = 0;
        $batchFailures = 0;
        $lastSuccess = null;

        do {
            // ── A live connection before every batch ──────────────
            if ($pdo !== null && !$this->answers($pdo)) {
                $this->logger->warning('Worker database connection lost; reconnecting');
                $pdo = null;
                $worker = null;
            }

            if ($pdo === null || $worker === null) {
                try {
                    $pdo = ($this->connect)();
                    $worker = ($this->buildWorker)($pdo);
                } catch (\Throwable $failure) {
                    $pdo = null;
                    $worker = null;
                    ++$connectionFailures;
                    ++$batchFailures;
                    $state?->write($this->state($batchFailures, $connectionFailures, $lastSuccess));
                    if ($connectionFailures >= $limit) {
                        return $this->exitForRestart($connectionFailures, $limit, $failure);
                    }
                    $this->logger->warning('Worker database connection failed', [
                        'consecutive_connection_failures' => $connectionFailures,
                        'max_connection_failures'         => $limit,
                        'message'                         => $failure->getMessage(),
                    ]);
                    $this->pause($loop, $sleepSeconds);
                    continue;
                }
                if ($connectedBefore || $connectionFailures > 0) {
                    $this->logger->info('Worker database connection re-established', [
                        'consecutive_connection_failures' => $connectionFailures,
                    ]);
                }
                $connectedBefore = true;
            }

            // ── The batch ─────────────────────────────────────────
            try {
                $worker->processBatch($batchSize);
                $connectionFailures = 0;
                $batchFailures = 0;
                $lastSuccess = time();
                $this->logger->info('Batch processed', ['limit' => $batchSize]);
            } catch (\Throwable $e) {
                ++$batchFailures;
                $this->logger->error('Worker batch failed', [
                    'message' => $e->getMessage(),
                    'trace'   => $e->getTraceAsString(),
                ]);

                if ($pdo->inTransaction() || !$this->answers($pdo)) {
                    // Never reuse it: the next iteration opens a fresh connection, so
                    // no open transaction is ever carried into the next batch.
                    $pdo = null;
                    $worker = null;
                    ++$connectionFailures;
                    if ($connectionFailures >= $limit) {
                        $state?->write($this->state($batchFailures, $connectionFailures, $lastSuccess));

                        return $this->exitForRestart($connectionFailures, $limit, $e);
                    }
                } else {
                    // The server answered: not a connection failure.
                    $connectionFailures = 0;
                }
            }

            $state?->write($this->state($batchFailures, $connectionFailures, $lastSuccess));
            $this->pause($loop, $sleepSeconds);
        } while ($loop);

        $this->logger->info('Worker finished');

        return self::EXIT_OK;
    }

    /** Liveness probe. Driver notices from a dead socket are expected here and not reported. */
    private function answers(PDO $pdo): bool
    {
        set_error_handler(static fn (): bool => true);
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

    private function state(int $batchFailures, int $connectionFailures, ?int $lastSuccess): EmailWorkerState
    {
        $pid = getmypid();

        return new EmailWorkerState(
            $pid === false ? 0 : $pid,
            time(),
            $batchFailures,
            $connectionFailures,
            $lastSuccess,
        );
    }
}
