<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\Support\AtomicHttpMysqlTestCase;

final class AtomicEmailHttpBoundaryMysqlTest extends AtomicHttpMysqlTestCase
{
    /** @return iterable<string, array{string, string, list<string>, string, string, int}> */
    public static function rejectedRequests(): iterable
    {
        yield 'missing auth' => ['', '127.0.0.1', ['key'], '{}', 'application/json', 401];
        yield 'invalid auth' => ['wrong', '127.0.0.1', ['key'], '{}', 'application/json', 401];
        yield 'IP denied' => ['fixture-key-a', '192.0.2.1', ['key'], '{}', 'application/json', 403];
        yield 'missing identity' => ['fixture-key-a', '127.0.0.1', [], '{}', 'application/json', 422];
        yield 'duplicate identity' => ['fixture-key-a', '127.0.0.1', ['one', 'two'], '{}', 'application/json', 422];
        yield 'invalid identity' => ['fixture-key-a', '127.0.0.1', ['contains space'], '{}', 'application/json', 422];
        yield 'long identity' => ['fixture-key-a', '127.0.0.1', [str_repeat('x', 192)], '{}', 'application/json', 422];
        yield 'invalid JSON' => ['fixture-key-a', '127.0.0.1', ['key'], '{', 'application/json', 400];
        yield 'root list' => ['fixture-key-a', '127.0.0.1', ['key'], '[]', 'application/json', 422];
        yield 'unsupported type' => ['fixture-key-a', '127.0.0.1', ['key'], '{}', 'text/plain', 415];
        yield 'oversize before parsing' => ['fixture-key-a', '127.0.0.1', ['key'], str_repeat('{', 1048577), 'application/json', 413];
    }

    /** @param list<string> $keys */
    #[DataProvider('rejectedRequests')]
    public function testBoundaryRejectsWithoutQueueWrites(string $apiKey, string $ip, array $keys, string $raw, string $type, int $status): void
    {
        [$app] = $this->application();
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v2/email/enqueue', ['REMOTE_ADDR' => $ip])
            ->withHeader('X-Api-Key', $apiKey)->withHeader('Content-Type', $type)
            ->withHeader('Accept', 'application/json')->withBody((new StreamFactory())->createStream($raw));
        if ($keys !== []) {
            $request = $request->withHeader('Idempotency-Key', $keys);
        }
        $response = $app->handle($request);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
    }

    public function testRealRedisRejectsAuthenticatedRequestBeforeAcceptance(): void
    {
        $redis = new \Redis();
        self::assertTrue($redis->connect((string) getenv('CHANNEL_IT_REDIS_HOST'), 6379, 1));
        self::assertTrue($redis->ping());
        $_ENV['RATE_LIMIT_MAX_REQUESTS'] = '0';
        [$app] = $this->application();
        $response = $this->request($app);
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('rate_limit_exceeded', $this->json($response)['error']);
        self::assertSame(401, $this->request($app, apiKey: 'invalid')->getStatusCode());
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
    }

    public function testInvalidOperatorBindingIsUnavailableAndDoesNotLeak(): void
    {
        $this->pdo->exec("UPDATE cd_email_consumer_bindings SET consumer_namespace='invalid namespace' WHERE api_key_id=1");
        [$app] = $this->application();
        $response = $this->request($app);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['error' => 'enqueue_unavailable', 'acceptance' => 'unknown', 'retry_same_key' => true], $this->json($response));
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
    }
    /** @return iterable<string, array{string}> */
    public static function ambiguousObjects(): iterable
    {
        yield 'list-shaped object' => ['{"nested":{"0":"x"}}'];
        yield 'sparse integer key' => ['{"nested":{"2":"x"}}'];
        yield 'negative integer key' => ['{"nested":{"-1":"x"}}'];
        yield 'mixed integer and named keys' => ['{"nested":{"0":"x","name":"y"}}'];
        yield 'object below list' => ['{"nested":[{"0":"x"}]}'];
        yield 'empty nested object' => ['{"nested":{}}'];
        yield 'empty object below list' => ['{"nested":[{}]}'];
    }

    #[DataProvider('ambiguousObjects')]
    public function testRawAmbiguousObjectsAreRejectedBeforeAnyPersistence(string $context): void
    {
        [$app] = $this->application();
        $response = $app->handle($this->rawContextRequest($context));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_error', $this->json($response)['error']);
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
    }

    public function testRawObjectCannotReplayAnAcceptedListWithTheSameKey(): void
    {
        [$app] = $this->application();
        $accepted = $app->handle($this->rawContextRequest('{"nested":["x"]}'));
        self::assertSame(202, $accepted->getStatusCode());
        $rejected = $app->handle($this->rawContextRequest('{"nested":{"0":"x"}}'));
        self::assertSame(422, $rejected->getStatusCode());
        self::assertSame($this->json($accepted), $this->json($this->request($app, 'GET', key: 'raw-shape', path: '/api/v2/email/receipt')));
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
    }

    public function testRawNamedObjectsAndListsRemainAcceptedAndDistinct(): void
    {
        [$app] = $this->application();
        self::assertSame(202, $app->handle($this->rawContextRequest('{"nested":{"label":"x","01":"y","empty":[]}}'))->getStatusCode());
        self::assertSame(409, $app->handle($this->rawContextRequest('{"nested":["x","y",[]]}'))->getStatusCode());
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
        self::assertSame(202, $app->handle($this->rawContextRequest('{}')->withHeader('Idempotency-Key', 'empty-root'))->getStatusCode());
    }

    private function rawContextRequest(string $context): \Psr\Http\Message\ServerRequestInterface
    {
        $payload = $this->payload();
        unset($payload['context']);
        $raw = substr(json_encode($payload, JSON_THROW_ON_ERROR), 0, -1) . ',"context":' . $context . '}';
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/v2/email/enqueue', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Api-Key', 'fixture-key-a')->withHeader('Idempotency-Key', 'raw-shape')
            ->withHeader('Content-Type', 'application/json')->withHeader('Accept', 'application/json')
            ->withBody((new StreamFactory())->createStream($raw));
    }

}
