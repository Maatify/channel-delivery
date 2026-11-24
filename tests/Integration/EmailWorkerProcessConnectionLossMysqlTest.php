<?php

declare(strict_types=1);

namespace Tests\Integration;

use Maatify\ChannelDelivery\Worker\EmailQueueStallMonitor;
use Maatify\ChannelDelivery\Worker\EmailWorkerState;
use Maatify\ChannelDelivery\Worker\EmailWorkerStateFile;
use Psr\Log\NullLogger;
use Tests\Support\AtomicHttpMysqlTestCase;

/** Black-box recovery and monitoring tests for the DI-wired worker in a separate process. */
final class EmailWorkerProcessConnectionLossMysqlTest extends AtomicHttpMysqlTestCase
{
    private string $directory = '';
    /** @var resource|null */
    private $process = null;

    public function testLoopRecoversWhenTheServerKillsItsConnection(): void
    {
        [$app] = $this->application();
        self::assertLessThan(300, $this->request($app, key: 'process-1')->getStatusCode());
        // Connections that exist before the worker starts are not the worker's.
        $before = $this->connectionIds();
        $this->startWorker();
        $this->waitForSent(1, 15.0, 'before the connection loss');

        $worker = array_values(array_diff($this->connectionIds(), $before));
        self::assertCount(1, $worker, 'The worker holds exactly one connection.');
        $this->pdo->exec('KILL CONNECTION ' . (int) $worker[0]);

        self::assertLessThan(300, $this->request($app, key: 'process-2')->getStatusCode());
        $this->waitForSent(2, 10.0, 'after the server killed the worker connection');

        self::assertNotNull($this->process);
        self::assertTrue(proc_get_status($this->process)['running'], 'The worker loop keeps running.');
        $log = $this->log();
        self::assertStringContainsString('Worker database connection re-established', $log);
        self::assertStringNotContainsString('Worker exiting for restart', $log);
        $this->assertCleanOutput();
    }

    public function testLoopReconnectsAfterItsSessionIdleTimeoutWithCleanOutput(): void
    {
        [$app] = $this->application();
        self::assertLessThan(300, $this->request($app, key: 'idle-process-1')->getStatusCode());
        // The worker idles 3 s between batches, past its own 2 s session wait_timeout.
        $this->startWorker(['DB_SESSION_WAIT_TIMEOUT' => '2'], 3);
        $this->waitForSent(1, 15.0, 'before the idle timeout');
        sleep(4);

        self::assertLessThan(300, $this->request($app, key: 'idle-process-2')->getStatusCode());
        $this->waitForSent(2, 10.0, 'after the server closed the idle worker connection');

        self::assertNotNull($this->process);
        self::assertTrue(proc_get_status($this->process)['running']);
        $log = $this->log();
        self::assertStringContainsString('Email queue connection was closed by the server; reconnecting before the claim', $log);
        self::assertStringContainsString('Worker database connection re-established', $log);
        self::assertStringNotContainsString('Worker batch failed', $log);
        $this->assertCleanOutput();
    }

    public function testSlowSendOutlastsWaitTimeoutAndRecordsSentWithoutResending(): void
    {
        [$app] = $this->application();
        self::assertSame(202, $this->request($app, key: 'slow-process-1')->getStatusCode());
        $this->startWorker(['DB_SESSION_WAIT_TIMEOUT' => '2'], delayFirstSend: 4);
        $this->waitForSent(1, 15.0, 'after a send outlasted the database session');

        self::assertSame(202, $this->request($app, key: 'slow-process-2')->getStatusCode());
        $this->waitForSent(2, 10.0, 'in the next batch');

        self::assertSame('2', self::scalar($this->pdo, "SELECT COUNT(*) FROM cd_email_queue WHERE status = 'sent' AND attempts = 1 AND sent_at IS NOT NULL"));
        $log = $this->log();
        self::assertStringContainsString('Email queue connection was closed by the server; reconnecting once', $log);
        self::assertSame(2, substr_count($log, '"message":"Recording transport invoked"'));
        self::assertSame(2, substr_count($log, '"message":"Email sent"'));
        self::assertStringNotContainsString('Worker batch failed', $log);
        self::assertCleanOutput();
    }

    public function testUnrecordedSendRemainsVisibleAfterWorkerIsKilledMidBatch(): void
    {
        [$app] = $this->application();
        $path = $this->processDirectory() . '/state.json';
        $previousHeartbeat = time() - 2;
        (new EmailWorkerStateFile($path, new NullLogger()))->write(new EmailWorkerState(1, $previousHeartbeat, 0, 0, $previousHeartbeat));
        $monitor = new EmailQueueStallMonitor($this->pdo);
        self::assertSame('ok', $monitor->report(600, 3, 600, [$path], time())['status']);

        self::assertSame(202, $this->request($app, key: 'outcome-process-1')->getStatusCode());
        self::assertSame(202, $this->request($app, key: 'outcome-process-2')->getStatusCode());
        $this->pdo->exec("UPDATE cd_email_queue SET scheduled_at = '2000-01-01 00:00:00'");
        $this->pdo->exec("CREATE TRIGGER fixture_reject_sent BEFORE UPDATE ON cd_email_queue FOR EACH ROW
            BEGIN
                IF OLD.id = 1 AND OLD.status = 'processing' AND NEW.status = 'sent' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture outcome failure';
                END IF;
            END");

        $this->startWorker(stateFile: $path, holdSecondSend: true);
        $this->waitFor(fn (): bool => str_contains($this->log(), 'Recording transport holding second send'));
        self::assertStringContainsString('Email sent but status write failed', $this->log());
        self::assertStringNotContainsString('Batch processed', $this->log());
        self::assertStringNotContainsString('Worker batch completed with unrecorded outcomes', $this->log());
        $killedPid = $this->killWorker();

        $report = $monitor->report(600, 3, 600, [$path], time());
        self::assertSame(['outcome_write_failed'], $report['alerts'], 'The monitor must alert without a completed batch.');
        $interrupted = EmailWorkerStateFile::read($path);
        self::assertNotNull($interrupted);
        self::assertSame($previousHeartbeat, $interrupted->updatedAt, 'A mid-batch event must not refresh heartbeat age.');
        self::assertNotNull($interrupted->lastOutcomeWriteFailedAt);
        self::assertSame('2', self::scalar($this->pdo, "SELECT COUNT(*) FROM cd_email_queue WHERE status = 'processing' AND attempts = 1"));

        $restarted = $this->restartWorker($path, $killedPid);
        self::assertSame($interrupted->lastOutcomeWriteFailedAt, $restarted->lastOutcomeWriteFailedAt);
        self::assertSame(['outcome_write_failed'], $monitor->report(600, 3, 600, [$path], time())['alerts']);
    }

    public function testLeaseExhaustionRemainsVisibleAfterWorkerIsKilledMidBatch(): void
    {
        [$app] = $this->application();
        $path = $this->processDirectory() . '/state.json';
        $previousHeartbeat = time() - 2;
        (new EmailWorkerStateFile($path, new NullLogger()))->write(new EmailWorkerState(1, $previousHeartbeat, 0, 0, $previousHeartbeat));
        $monitor = new EmailQueueStallMonitor($this->pdo);
        self::assertSame('ok', $monitor->report(600, 3, 600, [$path], time())['status']);

        self::assertSame(202, $this->request($app, key: 'lease-process-exhausted')->getStatusCode());
        self::assertSame(202, $this->request($app, key: 'lease-process-in-flight')->getStatusCode());
        $this->pdo->exec("UPDATE cd_email_queue SET status = 'processing', attempts = 4,
            updated_at = NOW() - INTERVAL 600 SECOND WHERE id = 1");
        $this->pdo->exec("UPDATE cd_email_queue SET scheduled_at = '2000-01-01 00:00:00' WHERE id = 2");

        // The worker settles the expired row at claim time, then blocks in the other job's transport.
        $this->startWorker(delayFirstSend: 60, stateFile: $path);
        $this->waitFor(fn (): bool => str_contains($this->log(), 'Recording transport invoked'));
        self::assertStringContainsString('"reason":"processing_lease_expired"', $this->log());
        self::assertStringNotContainsString('Batch processed', $this->log());
        $killedPid = $this->killWorker();

        self::assertSame('1', self::scalar($this->pdo, "SELECT COUNT(*) FROM cd_email_queue
            WHERE id = 1 AND status = 'failed' AND attempts = 4 AND last_error = 'processing_lease_expired' AND sent_at IS NULL"));
        self::assertSame('1', self::scalar($this->pdo, "SELECT COUNT(*) FROM cd_email_queue
            WHERE id = 2 AND status = 'processing' AND attempts = 1 AND sent_at IS NULL"));
        self::assertSame(
            ['processing_lease_exhausted'],
            $monitor->report(600, 3, 600, [$path], time())['alerts'],
            'The monitor must alert without a completed batch.',
        );
        $interrupted = EmailWorkerStateFile::read($path);
        self::assertNotNull($interrupted);
        self::assertSame($killedPid, $interrupted->pid);
        self::assertSame($previousHeartbeat, $interrupted->updatedAt, 'Settling a lease does not refresh the heartbeat.');
        self::assertNotNull($interrupted->lastLeaseExhaustedAt);

        $restarted = $this->restartWorker($path, $killedPid);
        self::assertSame($interrupted->lastLeaseExhaustedAt, $restarted->lastLeaseExhaustedAt);
        self::assertSame(['processing_lease_exhausted'], $monitor->report(600, 3, 600, [$path], time())['alerts']);
        self::assertStringNotContainsString('"reason":"processing_lease_expired"', $this->log());
    }

    private function killWorker(): int
    {
        self::assertNotNull($this->process);
        $process = $this->process;
        $before = proc_get_status($process);
        self::assertTrue($before['running']);
        self::assertTrue(proc_terminate($process, 9));
        $this->waitFor(static function () use ($process): bool {
            $status = proc_get_status($process);
            if ($status['running']) {
                return false;
            }
            self::assertTrue($status['signaled']);
            self::assertSame(9, $status['termsig'], 'The worker was killed by SIGKILL.');

            return true;
        });
        proc_close($process);
        $this->process = null;
        $this->assertCleanOutput();

        return $before['pid'];
    }

    private function restartWorker(string $path, int $killedPid): EmailWorkerState
    {
        self::assertTrue(unlink($this->directory . '/worker.log'));
        $this->startWorker(stateFile: $path);
        self::assertNotNull($this->process);
        $restartPid = proc_get_status($this->process)['pid'];
        self::assertNotSame($killedPid, $restartPid);
        $this->waitFor(static fn (): bool => EmailWorkerStateFile::read($path)?->pid === $restartPid);
        $restarted = EmailWorkerStateFile::read($path);
        self::assertNotNull($restarted);
        self::assertNotNull($restarted->lastBatchSucceededAt, 'The restarted worker completed an empty poll.');
        self::assertStringNotContainsString('Recording transport invoked', $this->log());
        $this->assertCleanOutput();

        return $restarted;
    }

    private function assertCleanOutput(): void
    {
        foreach (['stdout', 'stderr'] as $stream) {
            self::assertSame('', (string) file_get_contents($this->directory . '/' . $stream), 'No PHP warnings, notices or deprecations on ' . $stream);
        }
    }

    /** @param array<string, string> $environment */
    private function startWorker(
        array $environment = [],
        int $sleep = 1,
        int $delayFirstSend = 0,
        ?string $stateFile = null,
        bool $holdSecondSend = false,
    ): void {
        $spec = $this->processDirectory() . '/spec.json';
        file_put_contents($spec, json_encode([
            'env' => array_merge($_ENV, $environment),
            'log' => $this->directory . '/worker.log',
            'batch' => 10,
            'sleep' => $sleep,
            'delay_first_send' => $delayFirstSend,
            'state_file' => $stateFile,
            'hold_second_send' => $holdSecondSend,
        ], JSON_THROW_ON_ERROR));
        $process = proc_open(
            [PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', '-d', 'log_errors=0', dirname(__DIR__) . '/Support/email-worker-process.php', $spec],
            [0 => ['pipe', 'r'], 1 => ['file', $this->directory . '/stdout', 'w'], 2 => ['file', $this->directory . '/stderr', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $this->process = $process;
    }

    private function processDirectory(): string
    {
        if ($this->directory === '') {
            $this->directory = sys_get_temp_dir() . '/cd-worker-process-' . bin2hex(random_bytes(6));
            self::assertTrue(mkdir($this->directory, 0700));
        }

        return $this->directory;
    }

    /** @param \Closure(): bool $observed */
    private function waitFor(\Closure $observed): void
    {
        $deadline = microtime(true) + 15.0;
        do {
            if ($observed()) {
                return;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        self::fail("Worker checkpoint was not reached.\n" . $this->log() . (string) file_get_contents($this->directory . '/stderr'));
    }

    private function waitForSent(int $expected, float $seconds, string $when): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            $sent = (int) self::scalar($this->pdo, "SELECT COUNT(*) FROM cd_email_queue WHERE status = 'sent'");
            if ($sent >= $expected) {
                return;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        self::fail(sprintf(
            "Only %d of %d accepted messages were sent %s within %.0f s.\nWorker running: %s\nWorker log tail:\n%s\nstderr:\n%s",
            $sent,
            $expected,
            $when,
            $seconds,
            $this->process !== null && proc_get_status($this->process)['running'] ? 'yes' : 'no',
            implode("\n", array_slice(explode("\n", trim($this->log())), -8)),
            (string) @file_get_contents($this->directory . '/stderr')
        ));
    }

    /** @return list<string> ids of the open connections to the fixture database */
    private function connectionIds(): array
    {
        $statement = $this->pdo->query('SHOW FULL PROCESSLIST');
        self::assertNotFalse($statement);
        $database = self::scalar($this->pdo, 'SELECT DATABASE()');
        $ids = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            self::assertIsArray($row);
            $id = $row['Id'] ?? null;
            self::assertTrue(is_int($id) || is_string($id));
            if (($row['db'] ?? null) === $database) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    private function log(): string
    {
        $log = @file_get_contents($this->directory . '/worker.log');

        return $log === false ? '' : $log;
    }

    protected function tearDown(): void
    {
        if ($this->process !== null) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
        if ($this->directory !== '' && is_dir($this->directory)) {
            foreach (glob($this->directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }
}
