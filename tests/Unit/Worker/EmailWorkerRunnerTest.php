<?php

declare(strict_types=1);

namespace Tests\Unit\Worker;

use Closure;
use Maatify\ChannelDelivery\Worker\EmailWorkerRunner;
use Maatify\ChannelDelivery\Worker\EmailWorkerStateFile;
use Maatify\Crypto\Contract\CryptoContextProviderInterface;
use Maatify\Crypto\DX\CryptoProvider;
use Maatify\EmailDelivery\Renderer\EmailRendererInterface;
use Maatify\EmailDelivery\Transport\EmailTransportInterface;
use Maatify\EmailDelivery\Worker\EmailQueueWorker;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\RecordingLogger;
use Tests\Support\ScriptedConnection;
use Tests\Support\StopLoop;

final class EmailWorkerRunnerTest extends TestCase
{
    private RecordingLogger $logger;
    /** @var list<ScriptedConnection> connections handed out, in order */
    private array $opened = [];
    private int $connectAttempts = 0;
    private int $sleeps = 0;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testOneShotRunProcessesOneBatchAndExitsZero(): void
    {
        $code = $this->runner([new ScriptedConnection()])->run(10, false, 0);

        self::assertSame(EmailWorkerRunner::EXIT_OK, $code);
        self::assertSame(1, $this->opened[0]->batches);
        self::assertSame(0, $this->sleeps);
        self::assertSame(['Worker started', 'Batch processed', 'Worker finished'], $this->logger->messages());
    }

    public function testLostConnectionIsReplacedBeforeTheNextBatch(): void
    {
        $first = new ScriptedConnection();
        $second = new ScriptedConnection();
        $runner = $this->runner([$first, $second], function (int $sleeps) use ($first): void {
            if ($sleeps === 1) {
                $first->alive = false; // the server closes the idle connection between batches
            }
            if ($sleeps === 2) {
                throw new StopLoop();
            }
        });

        $this->runUntilStopped($runner);

        self::assertSame(1, $first->batches, 'The dead connection must not run another batch.');
        self::assertSame(1, $second->batches, 'The next batch runs on the replacement connection.');
        self::assertSame(2, $this->connectAttempts);
        self::assertSame(['Worker database connection lost; reconnecting'], $this->logger->messages('warning'));
        self::assertSame(['Worker started', 'Batch processed', 'Worker database connection re-established', 'Batch processed'], $this->logger->messages('info'));
        self::assertSame([], $this->logger->messages('error'));
        self::assertSame([], $this->logger->messages('critical'));
    }

    public function testConsecutiveConnectionFailuresExitForRestart(): void
    {
        $runner = $this->runner([null, null, null, null, null]);

        $code = $runner->run(10, true, 5, 3);

        self::assertSame(EmailWorkerRunner::EXIT_CONNECTION_UNAVAILABLE, $code);
        self::assertSame(3, $this->connectAttempts);
        self::assertSame(2, $this->sleeps, 'It pauses between attempts and exits right after the last one.');
        self::assertSame(['Worker database connection failed', 'Worker database connection failed'], $this->logger->messages('warning'));
        self::assertSame(['Worker exiting for restart: database connection unavailable'], $this->logger->messages('critical'));
        self::assertSame(['Worker started'], $this->logger->messages('info'), 'No reconnect, batch or finish record before the exit.');
        $exit = $this->record('Worker exiting for restart: database connection unavailable');
        self::assertSame(3, $exit['consecutive_connection_failures']);
        self::assertSame(1, $exit['exit_code']);
    }

    public function testSuccessfulBatchResetsTheConnectionFailureCount(): void
    {
        $first = new ScriptedConnection();
        $second = new ScriptedConnection();
        // fail, fail, connect (batch ok), lose it, fail, fail, connect (batch ok)
        $runner = $this->runner([null, null, $first, null, null, $second], function (int $sleeps) use ($first): void {
            if ($sleeps === 3) {
                $first->alive = false;
            }
            if ($sleeps === 6) {
                throw new StopLoop();
            }
        });

        $this->runUntilStopped($runner, 3);

        self::assertSame(1, $first->batches);
        self::assertSame(1, $second->batches);
        self::assertSame([], $this->logger->messages('critical'), 'Failures separated by a successful batch are not consecutive.');
        self::assertSame(2, $this->logger->count('Worker database connection re-established'));
    }

    public function testServerAnsweredBatchErrorResetsTheConnectionFailureCount(): void
    {
        $connection = new ScriptedConnection();
        $connection->batchFailure = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found');
        // fail, fail, connect (the server answers the batch with an error), lose it, fail
        $runner = $this->runner([null, null, $connection, null], function (int $sleeps) use ($connection): void {
            if ($sleeps === 3) {
                $connection->alive = false;
            }
            if ($sleeps === 4) {
                throw new StopLoop();
            }
        });

        $this->runUntilStopped($runner, 3);

        self::assertSame([], $this->logger->messages('critical'), 'The answered error separates the failures, so they are not consecutive.');
        self::assertSame(4, $this->connectAttempts);
    }

    public function testNonConnectionBatchErrorKeepsTheConnectionAndTheLoop(): void
    {
        $connection = new ScriptedConnection();
        $connection->batchFailure = new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'cd_email_queue' doesn't exist");
        $runner = $this->runner([$connection], function (int $sleeps): void {
            if ($sleeps === 5) {
                throw new StopLoop();
            }
        });

        $this->runUntilStopped($runner, 3);

        self::assertSame(1, $this->connectAttempts, 'A server-answered error never replaces the connection.');
        self::assertSame(5, $connection->batches);
        self::assertSame(5, $this->logger->count('Worker batch failed'));
        self::assertSame([], $this->logger->messages('critical'));
        self::assertSame(0, $this->logger->count('Worker database connection lost; reconnecting'));
    }

    public function testBatchFailureThatLosesTheConnectionCountsTowardsRestart(): void
    {
        $scripts = [];
        for ($i = 0; $i < 4; ++$i) {
            $script = new ScriptedConnection();
            $script->batchFailure = new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
            $script->aliveAfterBatchFailure = false;
            $scripts[] = $script;
        }

        $code = $this->runner($scripts)->run(10, true, 1, 3);

        self::assertSame(EmailWorkerRunner::EXIT_CONNECTION_UNAVAILABLE, $code);
        self::assertSame(3, $this->connectAttempts, 'Each lost connection is replaced, never reused.');
        self::assertSame(1, $scripts[0]->batches);
        self::assertSame(1, $scripts[2]->batches);
        self::assertSame(0, $scripts[3]->batches);
        self::assertSame(3, $this->logger->count('Worker batch failed'));
        self::assertSame(1, $this->logger->count('Worker exiting for restart: database connection unavailable'));
    }

    public function testConnectionLeftInsideATransactionIsNeverReused(): void
    {
        $first = new ScriptedConnection();
        $first->batchFailure = new PDOException('SQLSTATE[HY000]: General error: rollback lost');
        $first->inTransaction = true; // still answers, but carries an open transaction
        $second = new ScriptedConnection();
        $runner = $this->runner([$first, $second], function (int $sleeps): void {
            if ($sleeps === 2) {
                throw new StopLoop();
            }
        });

        $this->runUntilStopped($runner);

        self::assertSame(1, $first->batches);
        self::assertSame(1, $second->batches, 'The next batch must run on a fresh connection.');
        self::assertSame(2, $this->connectAttempts);
    }

    public function testOneShotRunExitsNonZeroOnItsFirstConnectionFailure(): void
    {
        $code = $this->runner([null])->run(10, false, 0, 3);

        self::assertSame(EmailWorkerRunner::EXIT_CONNECTION_UNAVAILABLE, $code);
        self::assertSame(1, $this->connectAttempts);
        self::assertSame(0, $this->sleeps);
    }

    public function testOneShotRunKeepsExitZeroOnANonConnectionBatchError(): void
    {
        $connection = new ScriptedConnection();
        $connection->batchFailure = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found');

        $code = $this->runner([$connection])->run(10, false, 0, 3);

        self::assertSame(EmailWorkerRunner::EXIT_OK, $code);
        self::assertSame(1, $this->logger->count('Worker batch failed'));
    }

    public function testStateFileTracksConsecutiveFailuresAndTheLastSuccess(): void
    {
        $path = $this->temporaryPath();
        $connection = new ScriptedConnection();
        $connection->batchFailure = new PDOException('SQLSTATE[40001]: 1213 Deadlock found');
        $observed = [];
        $runner = $this->runner([null, $connection], function (int $sleeps) use ($path, $connection, &$observed): void {
            $observed[] = EmailWorkerStateFile::read($path);
            if ($sleeps === 3) {
                $connection->batchFailure = null;
            }
            if ($sleeps === 4) {
                throw new StopLoop();
            }
        });

        try {
            $this->runUntilStopped($runner, 3, $path);
        } finally {
            unlink($path);
        }

        self::assertCount(4, $observed);
        [$connectFailed, $batchFailed, $batchFailedAgain, $succeeded] = $observed;
        self::assertNotNull($connectFailed);
        self::assertSame([1, 1], [$connectFailed->consecutiveBatchFailures, $connectFailed->consecutiveConnectionFailures]);
        self::assertNotNull($batchFailed);
        self::assertSame([2, 0], [$batchFailed->consecutiveBatchFailures, $batchFailed->consecutiveConnectionFailures]);
        self::assertNotNull($batchFailedAgain);
        self::assertSame(3, $batchFailedAgain->consecutiveBatchFailures);
        self::assertNull($batchFailedAgain->lastBatchSucceededAt);
        self::assertNotNull($succeeded);
        self::assertSame([0, 0], [$succeeded->consecutiveBatchFailures, $succeeded->consecutiveConnectionFailures]);
        self::assertNotNull($succeeded->lastBatchSucceededAt);
        self::assertSame(getmypid(), $succeeded->pid);
    }

    public function testUnwritableStateFileIsLoggedOnceAndNeverStopsTheWorker(): void
    {
        $path = sys_get_temp_dir() . '/missing-dir-' . bin2hex(random_bytes(6)) . '/worker.json';
        $runner = $this->runner([new ScriptedConnection()], function (int $sleeps): void {
            if ($sleeps === 3) {
                throw new StopLoop();
            }
        });

        $this->runUntilStopped($runner, 3, $path);

        self::assertSame(1, $this->logger->count('Worker state file not written'));
        self::assertSame(3, $this->logger->count('Batch processed'));
    }

    public function testProbeDoesNotReportTheDriverConnectionLossNotice(): void
    {
        $reported = $this->probeWarnings('PDO::query(): Packets out of order. Expected 1 received 0. Packet size=145');

        self::assertSame([], $reported, 'A dead connection is the expected probe failure.');
        self::assertSame(1, $this->logger->count('Worker database connection re-established'));
    }

    public function testProbeHandsEveryOtherWarningToThePreviousHandler(): void
    {
        $reported = $this->probeWarnings('Unrelated warning raised while probing');

        self::assertSame(['Unrelated warning raised while probing'], $reported);
    }

    public function testAtLeastOneConnectionFailureMustBeAllowed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->runner([new ScriptedConnection()])->run(10, true, 0, 0);
    }

    /** @return list<string> warnings that reached the handler installed before the run */
    private function probeWarnings(string $warning): array
    {
        $first = new ScriptedConnection();
        $runner = $this->runner([$first, new ScriptedConnection()], function (int $sleeps) use ($first, $warning): void {
            if ($sleeps === 1) {
                $first->alive = false;
                $first->probeWarning = $warning;
            }
            if ($sleeps === 2) {
                throw new StopLoop();
            }
        });
        $reported = [];
        set_error_handler(static function (int $severity, string $message) use (&$reported): bool {
            $reported[] = $message;

            return true;
        });
        try {
            $this->runUntilStopped($runner);
        } finally {
            restore_error_handler();
        }

        return $reported;
    }

    /**
     * @param list<ScriptedConnection|null> $connections null makes that connect attempt fail
     * @param (Closure(int): void)|null     $onSleep     called with the running sleep count
     */
    private function runner(array $connections, ?Closure $onSleep = null): EmailWorkerRunner
    {
        $connect = function () use (&$connections): PDO {
            ++$this->connectAttempts;
            $script = array_shift($connections);
            if ($script === null) {
                throw new PDOException('SQLSTATE[HY000] [2002] Connection refused');
            }
            $this->opened[] = $script;

            return $this->pdo($script);
        };
        $sleep = function (int $seconds) use ($onSleep): void {
            ++$this->sleeps;
            if ($this->sleeps > 50) {
                throw new \LogicException('Runaway worker loop: it should have stopped or exited by now.');
            }
            if ($onSleep !== null) {
                $onSleep($this->sleeps);
            }
        };

        return new EmailWorkerRunner($connect, $this->workerFactory(), $this->logger, $sleep);
    }

    private function runUntilStopped(EmailWorkerRunner $runner, int $maxConnectionFailures = 3, ?string $stateFile = null): void
    {
        try {
            $runner->run(10, true, 1, $maxConnectionFailures, $stateFile);
            self::fail('The loop was expected to keep running until stopped.');
        } catch (StopLoop) {
            // expected
        }
    }

    /** A PDO double that answers the worker's empty-queue batch and the runner's probe as scripted. */
    private function pdo(ScriptedConnection $script): PDO
    {
        $probe = $this->createMock(PDOStatement::class);
        $probe->method('fetchColumn')->willReturn(1);
        $metadata = $this->createMock(PDOStatement::class);
        $metadata->method('columnCount')->willReturn(1);
        $metadata->method('getColumnMeta')->willReturn(['name' => 'id']);
        $empty = $this->createMock(PDOStatement::class);
        $empty->method('fetchAll')->willReturn([]);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnCallback(static function (string $sql) use ($script, $probe, $metadata): PDOStatement {
            if ($sql === 'SELECT 1') {
                ++$script->probes;
                if ($script->probeWarning !== null) {
                    trigger_error($script->probeWarning, E_USER_WARNING);
                }
                if (!$script->alive) {
                    throw new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
                }

                return $probe;
            }
            if (!$script->alive) {
                throw new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
            }
            if ($script->batchFailure !== null) {
                $script->alive = $script->aliveAfterBatchFailure;
                throw $script->batchFailure;
            }

            return $metadata;
        });
        $pdo->method('beginTransaction')->willReturnCallback(static function () use ($script): bool {
            ++$script->batches;

            return true;
        });
        $pdo->method('prepare')->willReturn($empty);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('inTransaction')->willReturnCallback(static fn (): bool => $script->inTransaction);

        return $pdo;
    }

    /** @return Closure(PDO): EmailQueueWorker */
    private function workerFactory(): Closure
    {
        $crypto = (new \ReflectionClass(CryptoProvider::class))->newInstanceWithoutConstructor();
        $renderer = $this->createMock(EmailRendererInterface::class);
        $transport = $this->createMock(EmailTransportInterface::class);
        $contexts = $this->createMock(CryptoContextProviderInterface::class);

        return static fn (PDO $pdo): EmailQueueWorker => new EmailQueueWorker($pdo, $crypto, $renderer, $transport, $contexts, new NullLogger());
    }

    /** @return array<mixed> */
    private function record(string $message): array
    {
        foreach ($this->logger->records as $record) {
            if ($record['message'] === $message) {
                return $record['context'];
            }
        }
        self::fail('Missing log record: ' . $message);
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cd-worker-state-');
        self::assertIsString($path);

        return $path;
    }
}
