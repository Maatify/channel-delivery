<?php

declare(strict_types=1);

namespace Tests\Integration;

use Maatify\ChannelDelivery\Email\AtomicEmailConnectionFactory;
use Maatify\EmailDelivery\Worker\EmailQueueWorker;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AtomicHttpMysqlTestCase;
use Tests\Support\AtomicTestPdo;

final class AtomicEmailHttpMysqlTest extends AtomicHttpMysqlTestCase
{
    public function testLegacySameHeaderStillCreatesTwoJobsAndReturnsTheOldQueuedBody(): void
    {
        [$app] = $this->application();
        for ($i = 0; $i < 2; ++$i) {
            $response = $this->request($app, path: '/api/v1/email/enqueue');
            self::assertSame(202, $response->getStatusCode());
            self::assertSame(['status' => 'queued'], $this->json($response));
        }
        self::assertSame('2', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
    }

    public function testRealHttpAuthDiAcceptanceReplayAndLookupUseTheSameReceipt(): void
    {
        [$app, $container] = $this->application();
        $first = $this->request($app);
        self::assertSame(202, $first->getStatusCode());
        self::assertSame('no-store', $first->getHeaderLine('Cache-Control'));
        self::assertSame('accepted', $this->json($first)['status']);
        $replay = $this->request($app);
        self::assertSame(202, $replay->getStatusCode());
        self::assertSame($this->json($first), $this->json($replay));
        $lookup = $this->request($app, 'GET', path: '/api/v2/email/receipt');
        self::assertSame(200, $lookup->getStatusCode());
        self::assertSame($this->json($first), $this->json($lookup));
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
        self::assertSame('consumer-a', self::scalar($this->pdo, 'SELECT consumer_namespace FROM cd_email_enqueue_receipts'));
        $atomic = $container->get(AtomicEmailConnectionFactory::class);
        self::assertInstanceOf(AtomicEmailConnectionFactory::class, $atomic);
        $owned = $atomic->open();
        $legacy = $container->get(PDO::class);
        self::assertInstanceOf(PDO::class, $legacy);
        self::assertSame('+00:00', self::scalar($owned, 'SELECT @@SESSION.time_zone'));
        self::assertSame('+03:00', self::scalar($legacy, 'SELECT @@SESSION.time_zone'));
        self::assertNotSame(self::scalar($owned, 'SELECT CONNECTION_ID()'), self::scalar($legacy, 'SELECT CONNECTION_ID()'));
        self::assertSame(0, $this->transport->calls, 'Acceptance never sends SMTP.');
    }

    public function testConflictIncludingFloatVersusIntegerCannotReplaceTheWinner(): void
    {
        [$app] = $this->application();
        $payload = $this->payload();
        $payload['context'] = ['value' => 1.0];
        self::assertSame(202, $this->request($app, payload: $payload)->getStatusCode());
        $payload['context'] = ['value' => 1];
        $conflict = $this->request($app, payload: $payload);
        self::assertSame(409, $conflict->getStatusCode());
        self::assertSame('idempotency_conflict', $this->json($conflict)['error']);
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
    }

    public function testScopeIsolationAndKeyRotationDoNotChangeConsumerIdentity(): void
    {
        [$app] = $this->application();
        $first = $this->request($app);
        self::assertSame(202, $first->getStatusCode());
        $this->pdo->exec('UPDATE cd_api_keys SET is_active=0 WHERE id=1');
        self::assertSame(401, $this->request($app)->getStatusCode());
        $rotated = $this->request($app, apiKey: 'rotated');
        self::assertSame(202, $rotated->getStatusCode());
        self::assertSame($this->json($first), $this->json($rotated));
        self::assertSame(404, $this->request($app, 'GET', apiKey: 'b', path: '/api/v2/email/receipt')->getStatusCode());
        self::assertSame(202, $this->request($app, apiKey: 'b')->getStatusCode());
        self::assertSame('2', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame(403, $this->request($app, apiKey: 'unbound')->getStatusCode());
        $payload = $this->payload();
        $payload['consumer_namespace'] = 'consumer-b';
        self::assertSame(422, $this->request($app, key: 'forged', apiKey: 'rotated', payload: $payload)->getStatusCode());
        self::assertSame('2', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
    }

    public function testQueueDeletionKeepsReceiptAndReplayNeverRecreatesTheJob(): void
    {
        [$app] = $this->application();
        $first = $this->request($app);
        self::assertSame(202, $first->getStatusCode());
        $this->pdo->exec('DELETE FROM cd_email_queue');
        $lookup = $this->request($app, 'GET', path: '/api/v2/email/receipt');
        self::assertSame(200, $lookup->getStatusCode());
        $json = $this->json($lookup);
        self::assertIsArray($json['receipt']);
        self::assertSame('queue_missing', $json['receipt']['queue_state']);
        self::assertSame(202, $this->request($app)->getStatusCode());
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
    }

    public function testActualWorkerSendsOneAcceptedJobWithFakeTransportAndLookupObservesSent(): void
    {
        [$app, $container] = $this->application();
        self::assertSame(202, $this->request($app)->getStatusCode());
        self::assertSame(202, $this->request($app)->getStatusCode());
        $worker = $container->get(EmailQueueWorker::class);
        self::assertInstanceOf(EmailQueueWorker::class, $worker);
        $worker->processBatch();
        self::assertSame(1, $this->transport->calls);
        $worker->processBatch();
        self::assertSame(1, $this->transport->calls);
        $json = $this->json($this->request($app, 'GET', path: '/api/v2/email/receipt'));
        self::assertIsArray($json['receipt']);
        self::assertSame('sent', $json['receipt']['queue_state']);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function badFields(): iterable
    {
        yield 'sender float' => ['sender_type', 1.0];
        yield 'sender numeric string' => ['sender_type', '1'];
        yield 'sender too large' => ['sender_type', 256];
        yield 'priority zero' => ['priority', 0];
        yield 'priority null' => ['priority', null];
        yield 'priority string' => ['priority', '5'];
        yield 'entity too long' => ['entity_type', str_repeat('x', 33)];
        yield 'context list' => ['context', ['a', 'b']];
        yield 'invalid reply' => ['reply_to', 'not-an-email'];
    }

    #[DataProvider('badFields')]
    public function testInvalidInputsDoNotWrite(string $field, mixed $value): void
    {
        [$app] = $this->application();
        $body = $this->payload();
        $body[$field] = $value;
        self::assertSame(422, $this->request($app, payload: $body)->getStatusCode());
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
    }

    public function testMissingPackageMigrationFailsClosedWhileLegacyStillWorks(): void
    {
        $this->pdo->exec('DROP TABLE cd_email_enqueue_receipts');
        [$app] = $this->application();
        $response = $this->request($app);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('unknown', $this->json($response)['acceptance']);
        self::assertStringNotContainsString('SQL', (string) $response->getBody());
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame(202, $this->request($app, path: '/api/v1/email/enqueue')->getStatusCode());
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function commitFailures(): iterable
    {
        yield 'throw before commit' => ['before-throw', false];
        yield 'false before commit' => ['before-false', false];
        yield 'throw after server commit' => ['after-throw', true];
        yield 'false after server commit' => ['after-false', true];
    }

    #[DataProvider('commitFailures')]
    public function testUnknownCommitUsesFreshSessionForLookupAndRetryEvenInTheSameApp(string $mode, bool $committed): void
    {
        $fault = new AtomicTestPdo();
        $fault->mode = $mode;
        $opened = 0;
        [$app] = $this->application(static function () use ($fault, &$opened): PDO {
            return ++$opened === 1 ? $fault : self::connect();
        });
        $response = $this->request($app);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('unknown', $this->json($response)['acceptance']);
        self::assertSame(true, $this->json($response)['retry_same_key']);
        self::assertSame(1, $fault->commitCalls, 'The real server transaction must reach the injected commit boundary.');
        self::assertStringNotContainsString('Fixture commit', (string) $response->getBody());
        self::assertSame($committed ? '1' : '0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        $lookup = $this->request($app, 'GET', path: '/api/v2/email/receipt');
        self::assertSame($committed ? 200 : 404, $lookup->getStatusCode());
        self::assertSame(202, $this->request($app)->getStatusCode());
        self::assertSame(3, $opened, 'The gateway opens a fresh owned connection for every operation.');
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
    }
    public function testFailureBetweenReceiptReservationAndQueueInsertRollsBackBoth(): void
    {
        $fault = new AtomicTestPdo();
        $fault->beforeExecute = static function (string $sql): void {
            if (str_starts_with($sql, 'INSERT INTO cd_email_queue')) {
                throw new \RuntimeException('Fixture queue insert refused');
            }
        };
        [$app] = $this->application(static fn (): PDO => $fault);
        self::assertSame(503, $this->request($app)->getStatusCode());
        self::assertSame(0, $fault->commitCalls);
        self::assertFalse($fault->inTransaction());
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        [$fresh] = $this->application();
        self::assertSame(202, $this->request($fresh)->getStatusCode());
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
    }

    public function testProductionCompiledContainerCanResolveBothNewRoutes(): void
    {
        $_ENV['APP_ENV'] = 'production';
        [$app] = $this->application();
        $accepted = $this->request($app);
        self::assertSame(202, $accepted->getStatusCode());
        $lookup = $this->request($app, 'GET', path: '/api/v2/email/receipt');
        self::assertSame(200, $lookup->getStatusCode());
        self::assertSame($this->json($accepted), $this->json($lookup));
    }

}
