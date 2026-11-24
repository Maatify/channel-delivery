<?php

declare(strict_types=1);

namespace Tests\Integration;

use Closure;
use DI\Container;
use Maatify\ChannelDelivery\Database\PdoConnectionFactory;
use Maatify\ChannelDelivery\Worker\EmailQueueWorkerFactory;
use Maatify\ChannelDelivery\Worker\EmailWorkerRunner;
use Maatify\EmailDelivery\Worker\EmailQueueWorker;
use PDO;
use Slim\App;
use Tests\Support\AtomicHttpMysqlTestCase;
use Tests\Support\RecordingLogger;
use Tests\Support\StopLoop;

/** Real MySQL sessions closed by the server between worker batches. */
final class EmailWorkerConnectionMysqlTest extends AtomicHttpMysqlTestCase
{
    private RecordingLogger $logger;
    /** @var list<array{id: string, wait_timeout: string}> sessions the worker opened, in order */
    private array $sessions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new RecordingLogger();
    }

    public function testEveryNewConnectionGetsTheConfiguredSessionSettings(): void
    {
        $factory = $this->factory(37);
        $first = $factory->createForWorker();
        $second = $factory->createForWorker();

        self::assertSame('37', self::scalar($first, 'SELECT @@SESSION.wait_timeout'));
        self::assertSame('37', self::scalar($second, 'SELECT @@SESSION.wait_timeout'));
        self::assertSame('1', self::scalar($first, 'SELECT @@SESSION.autocommit'));
        self::assertSame('1', self::scalar($second, 'SELECT @@SESSION.autocommit'));
        self::assertNotSame(self::scalar($first, 'SELECT CONNECTION_ID()'), self::scalar($second, 'SELECT CONNECTION_ID()'));
        self::assertSame((string) PdoConnectionFactory::DEFAULT_WAIT_TIMEOUT_SECONDS, self::scalar(PdoConnectionFactory::fromEnvironment($_ENV)->create(), 'SELECT @@SESSION.wait_timeout'));

        $_ENV['DB_SESSION_WAIT_TIMEOUT'] = '41';
        [, $container] = $this->application();
        $shared = $container->get(PDO::class);
        self::assertInstanceOf(PDO::class, $shared);
        self::assertSame('41', self::scalar($shared, 'SELECT @@SESSION.wait_timeout'), 'The shared HTTP/CLI connection uses the same factory.');
    }

    public function testContainerWorkerUsesTheFactorySessionAndSends(): void
    {
        $_ENV['DB_SESSION_WAIT_TIMEOUT'] = '45';
        [$app, $container] = $this->application();
        $this->accept($app, 'wired-1');
        $runner = $container->get(EmailWorkerRunner::class);
        self::assertInstanceOf(EmailWorkerRunner::class, $runner);

        self::assertSame(EmailWorkerRunner::EXIT_OK, $runner->run(10, false, 0));
        self::assertSame(1, $this->transport->calls);
    }

    public function testWorkerReconnectsAfterTheServerClosesItsIdleConnection(): void
    {
        [$app, $container] = $this->application();
        $this->accept($app, 'idle-1');
        $runner = $this->runner($container, $this->factory(1), function (int $sleeps) use ($app): void {
            if ($sleeps === 1) {
                self::assertSame(1, $this->transport->calls);
                $this->accept($app, 'idle-2'); // the API keeps accepting meanwhile
                sleep(3); // longer than the worker's 1 s session wait_timeout: the server closes it
                return;
            }
            throw new StopLoop();
        });

        $this->runUntilStopped($runner);

        self::assertSame(2, $this->transport->calls, 'The message accepted after the drop is sent on the next batch.');
        $this->assertReconnectedOnce();
        self::assertSame(['1', '1'], array_column($this->sessions, 'wait_timeout'), 'Session settings apply to the replacement connection too.');
    }

    public function testWorkerReconnectsAfterItsConnectionIsKilled(): void
    {
        [$app, $container] = $this->application();
        $this->accept($app, 'kill-1');
        $runner = $this->runner($container, $this->factory(), function (int $sleeps) use ($app): void {
            if ($sleeps === 1) {
                $this->pdo->exec('KILL CONNECTION ' . $this->sessions[0]['id']);
                $this->accept($app, 'kill-2');
                return;
            }
            throw new StopLoop();
        });

        $this->runUntilStopped($runner);

        self::assertSame(2, $this->transport->calls);
        $this->assertReconnectedOnce();
    }

    public function testRepeatedConnectionFailuresExitNonZeroForRestart(): void
    {
        [$app, $container] = $this->application();
        $this->accept($app, 'down-1');
        $real = $this->factory();
        $unreachable = PdoConnectionFactory::fromEnvironment(array_merge($_ENV, ['DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) self::closedPort()]));
        $attempts = 0;
        $connect = function () use ($real, $unreachable, &$attempts): PDO {
            ++$attempts;

            return $this->track($attempts === 1 ? $real : $unreachable);
        };
        $sleeps = 0;
        $runner = new EmailWorkerRunner($connect, $this->workers($container), $this->logger, function (int $seconds) use (&$sleeps): void {
            if (++$sleeps === 1) {
                // The server drops the worker and then stays unreachable.
                $this->pdo->exec('KILL CONNECTION ' . $this->sessions[0]['id']);
            }
            if ($sleeps > 20) {
                throw new \LogicException('Runaway worker loop: it should have exited for restart.');
            }
        });

        $code = $runner->run(10, true, 0, 3);

        self::assertSame(EmailWorkerRunner::EXIT_CONNECTION_UNAVAILABLE, $code);
        self::assertSame(4, $attempts, 'One real connection, then three failed attempts.');
        self::assertSame(1, $this->transport->calls);
        self::assertSame(1, $this->logger->count('Email queue connection was closed by the server; reconnecting before the claim'));
        self::assertSame(2, $this->logger->count('Worker database connection failed'));
        self::assertSame(['Worker exiting for restart: database connection unavailable'], $this->logger->messages('critical'));
        self::assertSame(0, $this->logger->count('Worker database connection re-established'));
        $exit = $this->logger->records[array_key_last($this->logger->records)];
        self::assertSame(3, $exit['context']['consecutive_connection_failures']);
        self::assertIsString($exit['context']['message']);
        self::assertStringContainsString('2002', $exit['context']['message']);
    }

    public function testNonConnectionBatchErrorKeepsTheSameConnection(): void
    {
        [$app, $container] = $this->application();
        $this->accept($app, 'parked-1');
        $this->pdo->exec('DROP TABLE IF EXISTS cd_email_queue_parked');
        $this->pdo->exec('RENAME TABLE cd_email_queue TO cd_email_queue_parked');
        $runner = $this->runner($container, $this->factory(), function (int $sleeps): void {
            if ($sleeps === 4) {
                $this->pdo->exec('RENAME TABLE cd_email_queue_parked TO cd_email_queue');
            }
            if ($sleeps === 5) {
                throw new StopLoop();
            }
        });

        try {
            $this->runUntilStopped($runner);
        } finally {
            if (self::scalar($this->pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cd_email_queue_parked'") === '1') {
                $this->pdo->exec('RENAME TABLE cd_email_queue_parked TO cd_email_queue');
            }
        }

        self::assertCount(1, $this->sessions, 'A server-answered error never replaces the connection.');
        self::assertSame(4, $this->logger->count('Worker batch failed'), 'More failures than the restart limit, and no exit.');
        self::assertSame([], $this->logger->messages('critical'));
        self::assertSame(0, $this->logger->count('Email queue connection was closed by the server; reconnecting before the claim'));
        self::assertSame(1, $this->transport->calls, 'The loop recovers on the same connection once the table is back.');
    }

    public function testLegacyOnlySchemaEnqueuesAndRecoversWithoutTheOptionalTables(): void
    {
        $this->pdo->exec('DROP TABLE cd_email_synthetic_requests, cd_email_consumer_bindings, cd_email_enqueue_receipts');
        $this->pdo->exec('ALTER TABLE cd_email_queue DROP INDEX uq_email_queue_enqueue_receipt, DROP COLUMN enqueue_receipt_id');

        [$app, $container] = $this->application();
        $shared = $container->get(PDO::class);
        self::assertInstanceOf(PDO::class, $shared);
        $shared->exec('SET SESSION time_zone = DEFAULT');
        self::assertSame(202, $this->request($app, path: '/api/v1/email/enqueue')->getStatusCode());
        self::assertSame(202, $this->request($app, path: '/api/v1/email/enqueue')->getStatusCode());
        $this->pdo->exec("UPDATE cd_email_queue SET status='processing', attempts=1, updated_at=NOW() - INTERVAL 360 SECOND WHERE id=1");
        $runner = $container->get(EmailWorkerRunner::class);
        self::assertInstanceOf(EmailWorkerRunner::class, $runner);

        self::assertSame(EmailWorkerRunner::EXIT_OK, $runner->run(10, false, 0));
        self::assertSame(2, $this->transport->calls);
        self::assertSame('1:sent:2,2:sent:1', self::scalar(
            $this->pdo,
            "SELECT GROUP_CONCAT(CONCAT(id, ':', status, ':', attempts) ORDER BY id) FROM cd_email_queue"
        ));
    }

    public function testServiceSchemaReclaimsExpiredLeasesAndSettlesTheAttemptCap(): void
    {
        [$app, $container] = $this->application();
        self::assertSame('timestamp', self::scalar($this->pdo, "
            SELECT DATA_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cd_email_queue' AND COLUMN_NAME = 'updated_at'
        "));
        self::assertSame('status,priority,id', self::scalar($this->pdo, "
            SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cd_email_queue' AND INDEX_NAME = 'idx_worker_poll_priority'
        "));

        $this->accept($app, 'expired-keyed');
        self::assertSame(202, $this->request($app, path: '/api/v1/email/enqueue')->getStatusCode());
        $this->accept($app, 'fresh-keyed');
        $this->accept($app, 'expired-at-cap');
        // The old worker updated this service's TIMESTAMP implicitly on each claim.
        $this->pdo->exec("UPDATE cd_email_queue SET status='processing', attempts=1");
        $this->pdo->exec("UPDATE cd_email_queue SET updated_at=NOW() - INTERVAL 120 SECOND WHERE id=2 AND enqueue_receipt_id IS NULL");
        $this->pdo->exec("UPDATE cd_email_queue SET updated_at=NOW() - INTERVAL 120 SECOND WHERE id=1");
        $this->pdo->exec("UPDATE cd_email_queue SET attempts=4, updated_at=NOW() - INTERVAL 120 SECOND WHERE id=4");

        $_ENV['EMAIL_PROCESSING_LEASE_SECONDS'] = '90';
        $factory = $this->factory();
        $runner = new EmailWorkerRunner(static function () use ($factory): PDO {
            $pdo = $factory->createForWorker();
            $pdo->exec("SET SESSION time_zone = '-05:00'");
            return $pdo;
        }, $this->workers($container), $this->logger);

        self::assertSame(EmailWorkerRunner::EXIT_OK, $runner->run(10, false, 0));
        self::assertSame(2, $this->transport->calls);
        self::assertSame('1:sent:2,2:sent:2,3:processing:1,4:failed:4', self::scalar(
            $this->pdo,
            "SELECT GROUP_CONCAT(CONCAT(id, ':', status, ':', attempts) ORDER BY id) FROM cd_email_queue"
        ));
        self::assertSame('processing_lease_expired', self::scalar($this->pdo, "SELECT last_error FROM cd_email_queue WHERE id=4"));
    }

    private function factory(int $waitTimeout = PdoConnectionFactory::DEFAULT_WAIT_TIMEOUT_SECONDS): PdoConnectionFactory
    {
        return PdoConnectionFactory::fromEnvironment(array_merge($_ENV, ['DB_SESSION_WAIT_TIMEOUT' => (string) $waitTimeout]));
    }

    /** @param Closure(int): void $onSleep called with the running sleep count */
    private function runner(Container $container, PdoConnectionFactory $factory, Closure $onSleep): EmailWorkerRunner
    {
        $sleeps = 0;

        return new EmailWorkerRunner(
            fn (): PDO => $this->track($factory),
            $this->workers($container),
            $this->logger,
            static function (int $seconds) use ($onSleep, &$sleeps): void {
                $onSleep(++$sleeps);
            }
        );
    }

    private function track(PdoConnectionFactory $factory): PDO
    {
        $pdo = $factory->createForWorker();
        $this->sessions[] = ['id' => self::scalar($pdo, 'SELECT CONNECTION_ID()'), 'wait_timeout' => self::scalar($pdo, 'SELECT @@SESSION.wait_timeout')];

        return $pdo;
    }

    /** @return Closure(Closure(): PDO, \Psr\Log\LoggerInterface): EmailQueueWorker */
    private function workers(Container $container): Closure
    {
        $container->set(\Psr\Log\LoggerInterface::class, $this->logger);
        $factory = $container->get(EmailQueueWorkerFactory::class);
        self::assertInstanceOf(EmailQueueWorkerFactory::class, $factory);

        return $factory->create(...);
    }

    private function runUntilStopped(EmailWorkerRunner $runner): void
    {
        try {
            $runner->run(10, true, 1, 3);
            self::fail('The loop was expected to keep running until stopped.');
        } catch (StopLoop) {
            // expected
        }
    }

    /** @param App<\Psr\Container\ContainerInterface> $app */
    private function accept(App $app, string $key): void
    {
        self::assertLessThan(300, $this->request($app, key: $key)->getStatusCode());
    }

    private function assertReconnectedOnce(): void
    {
        self::assertCount(2, $this->sessions);
        self::assertNotSame($this->sessions[0]['id'], $this->sessions[1]['id']);
        self::assertSame(1, $this->logger->count('Email queue connection was closed by the server; reconnecting before the claim'));
        self::assertSame(1, $this->logger->count('Worker database connection re-established'));
        self::assertSame(0, $this->logger->count('Worker batch failed'));
        self::assertSame([], $this->logger->messages('critical'));
    }

    private static function closedPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);
        $name = stream_socket_get_name($server, false);
        fclose($server);
        self::assertIsString($name);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
