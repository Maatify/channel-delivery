<?php

declare(strict_types=1);

namespace Tests\Integration;

use DI\Container;
use Maatify\ChannelDelivery\Worker\EmailQueueStallMonitor;
use Maatify\ChannelDelivery\Worker\EmailWorkerRunner;
use Maatify\ChannelDelivery\Worker\EmailWorkerStateFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AtomicHttpMysqlTestCase;
use Tests\Support\RecordingEmailTransport;

final class EmailWorkerUpgradeMysqlTest extends AtomicHttpMysqlTestCase
{
    public function testExhaustedHistoricalPendingBlocksUntilOperatorSettlement(): void
    {
        [$app, $container] = $this->application();
        self::assertSame(202, $this->request($app, path: '/api/v1/email/enqueue')->getStatusCode());
        self::assertSame(202, $this->request($app, key: 'upgrade-deferred')->getStatusCode());
        self::assertSame(202, $this->request($app, key: 'upgrade-valid')->getStatusCode());
        self::assertSame(202, $this->request($app, key: 'upgrade-expired')->getStatusCode());
        $this->pdo->exec("UPDATE cd_email_queue SET scheduled_at='2000-01-01 00:00:00'");
        $this->pdo->exec("UPDATE cd_email_queue SET attempts=4, last_error='recovered_from_zombie_processing' WHERE id=1");
        $this->pdo->exec("UPDATE cd_email_queue SET attempts=5, scheduled_at='2099-01-01 00:00:00' WHERE id=2");
        $this->pdo->exec('UPDATE cd_email_queue SET attempts=3 WHERE id=3');
        $this->pdo->exec("UPDATE cd_email_queue SET attempts=4, status='processing', updated_at=NOW() - INTERVAL 600 SECOND WHERE id=4");
        $identity = self::scalar($this->pdo, "
            SELECT GROUP_CONCAT(CONCAT(id, ':', attempts, ':', COALESCE(HEX(enqueue_receipt_id), '-'), ':', SHA2(payload_encrypted, 256)) ORDER BY id)
            FROM cd_email_queue WHERE id IN (1, 2)
        ");
        $receipts = self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts');
        $runner = $this->runner($container);

        $code = $runner->run(10, false, 0);
        self::assertSame('1:pending:4,2:pending:5,3:pending:3,4:processing:4', $this->states());
        self::assertSame(3, $code);
        self::assertSame(0, $this->transport->calls);
        self::assertContains('exhausted_pending', $this->report(null)['alerts']);

        // Explicit settlement of reviewed rows, while the old workers and reset are stopped.
        $this->pdo->exec("UPDATE cd_email_queue SET status='failed', last_error='legacy_attempt_limit_reached'
            WHERE id=1 AND status='pending' AND attempts>=4");
        self::assertSame(3, $runner->run(10, false, 0), 'Even a deferred exhausted row must block startup.');
        self::assertSame(0, $this->transport->calls);
        $this->pdo->exec("UPDATE cd_email_queue SET status='failed', last_error='legacy_attempt_limit_reached'
            WHERE id=2 AND status='pending' AND attempts>=4");

        self::assertSame(0, $runner->run(10, false, 0));
        self::assertSame(1, $this->transport->calls, 'The only send belongs to the row with attempts remaining.');
        self::assertSame('1:failed:4,2:failed:5,3:sent:4,4:failed:4', $this->states());
        self::assertSame('processing_lease_expired', self::scalar($this->pdo, 'SELECT last_error FROM cd_email_queue WHERE id=4'));
        self::assertSame($identity, self::scalar($this->pdo, "
            SELECT GROUP_CONCAT(CONCAT(id, ':', attempts, ':', COALESCE(HEX(enqueue_receipt_id), '-'), ':', SHA2(payload_encrypted, 256)) ORDER BY id)
            FROM cd_email_queue WHERE id IN (1, 2)
        "));
        self::assertSame($receipts, self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
    }

    #[DataProvider('outcomes')]
    public function testUnrecordedOutcomeAlertsAcrossEmptyPollAndRecovers(string $target): void
    {
        $this->transport = new RecordingEmailTransport(static function (int $calls) use ($target): void {
            if ($target === 'pending' && $calls === 1) {
                throw new \RuntimeException('fixture SMTP failure');
            }
        });
        [$app, $container] = $this->application();
        self::assertSame(202, $this->request($app, key: 'failed-write-' . $target)->getStatusCode());
        $path = tempnam(sys_get_temp_dir(), 'worker-f16-');
        self::assertIsString($path);
        $this->pdo->exec("CREATE TRIGGER fixture_reject_outcome BEFORE UPDATE ON cd_email_queue FOR EACH ROW
            BEGIN
                IF OLD.status = 'processing' AND NEW.status = '$target' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture outcome failure';
                END IF;
            END");
        $runner = $this->runner($container);
        try {
            self::assertSame(0, $runner->run(10, false, 0, stateFile: $path));
            self::assertSame(1, $this->transport->calls);
            self::assertSame('1:processing:1', $this->states());
            self::assertContains('outcome_write_failed', $this->report($path)['alerts']);
            $state = EmailWorkerStateFile::read($path);
            self::assertNotNull($state);
            self::assertSame(0, $state->consecutiveConnectionFailures);
            self::assertNull($state->lastBatchSucceededAt);

            self::assertSame(0, $runner->run(10, false, 0, stateFile: $path));
            self::assertSame(1, $this->transport->calls, 'An empty poll neither resends nor erases the alert.');
            self::assertContains('outcome_write_failed', $this->report($path)['alerts']);

            $this->pdo->exec('DROP TRIGGER fixture_reject_outcome');
            $this->pdo->exec('UPDATE cd_email_queue SET updated_at=NOW() - INTERVAL 600 SECOND');
            self::assertSame(0, $runner->run(10, false, 0, stateFile: $path));
            self::assertSame('1:sent:2', $this->states());
            self::assertSame(2, $this->transport->calls);
            self::assertSame('ok', $this->report($path, time() + 601)['status']);
        } finally {
            $this->pdo->exec('DROP TRIGGER IF EXISTS fixture_reject_outcome');
            unlink($path);
        }
    }

    public function testCapSettlementIsVisibleAndOrdinarySmtpRetryDoesNotAlert(): void
    {
        $this->transport = new RecordingEmailTransport(static function (): void {
            throw new \RuntimeException('fixture SMTP failure');
        });
        [$app, $container] = $this->application();
        self::assertSame(202, $this->request($app, key: 'ordinary-retry')->getStatusCode());
        $path = tempnam(sys_get_temp_dir(), 'worker-f16-');
        self::assertIsString($path);
        try {
            $runner = $this->runner($container);
            self::assertSame(0, $runner->run(10, false, 0, stateFile: $path));
            self::assertSame('1:pending:1', $this->states());
            self::assertSame('ok', $this->report($path)['status']);

            // Simulate the last attempt expiring before another poll.
            $this->pdo->exec("UPDATE cd_email_queue SET attempts=4, status='processing',
                updated_at=NOW() - INTERVAL 600 SECOND");
            self::assertSame(0, $runner->run(10, false, 0, stateFile: $path));
            self::assertSame('1:failed:4', $this->states());
            self::assertSame(1, $this->transport->calls);
            self::assertSame(['processing_lease_exhausted'], $this->report($path)['alerts']);
        } finally {
            unlink($path);
        }
    }

    /** @return array<string, array{string}> */
    public static function outcomes(): array
    {
        return ['record sent' => ['sent'], 'record retry' => ['pending']];
    }

    private function runner(Container $container): EmailWorkerRunner
    {
        $runner = $container->get(EmailWorkerRunner::class);
        self::assertInstanceOf(EmailWorkerRunner::class, $runner);

        return $runner;
    }

    private function states(): string
    {
        return self::scalar($this->pdo, "SELECT GROUP_CONCAT(CONCAT(id, ':', status, ':', attempts) ORDER BY id) FROM cd_email_queue");
    }

    /** @return array{status: string, alerts: list<string>} */
    private function report(?string $stateFile, ?int $now = null): array
    {
        return (new EmailQueueStallMonitor($this->pdo))->report(600, 3, 3600, $stateFile === null ? [] : [$stateFile], $now ?? time());
    }
}
