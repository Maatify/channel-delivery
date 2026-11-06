<?php

declare(strict_types=1);

namespace Tests\Support;

use DI\Container;
use Maatify\ChannelDelivery\ApiKey\ApiKeyRepository;
use Maatify\ChannelDelivery\Application\Application;
use Maatify\ChannelDelivery\Email\AtomicEmailConnectionFactory;
use Maatify\EmailDelivery\Transport\EmailTransportInterface;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

abstract class AtomicHttpMysqlTestCase extends TestCase
{
    protected PDO $pdo;
    protected RecordingEmailTransport $transport;
    /** @var array<string, mixed>|null */
    private ?array $beforeEnvironment = null;

    protected function setUp(): void
    {
        if (getenv('CHANNEL_IT_DSN') === false) {
            if (getenv('CHANNEL_IT_REQUIRED') === '1') {
                self::fail('Required isolated MySQL fixture is missing.');
            }
            self::markTestSkipped('Explicit isolated _test MySQL fixture required.');
        }
        $this->pdo = self::connect();
        $this->resetSchema();
        $dsn = (string) getenv('CHANNEL_IT_DSN');
        preg_match('/(?:^|;)host=([^;]+)/', substr($dsn, 6), $host);
        preg_match('/(?:^|;)port=([^;]+)/', substr($dsn, 6), $port);
        $database = self::scalar($this->pdo, 'SELECT DATABASE()');
        if (!isset($host[1])) {
            throw new \RuntimeException('The HTTP fixture requires explicit TCP host.');
        }
        $this->beforeEnvironment = $_ENV;
        $_ENV = array_merge($_ENV, ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
            'APP_NAME' => 'Fixture', 'SUPPORT_EMAIL' => 'fixture@example.invalid',
            'DB_HOST' => $host[1], 'DB_PORT' => $port[1] ?? '3306', 'DB_DATABASE' => $database,
            'DB_USERNAME' => (string) getenv('CHANNEL_IT_USER'), 'DB_PASSWORD' => (string) getenv('CHANNEL_IT_PASS'),
            'CRYPTO_ACTIVE_KEY_ID' => 'fixture-v1', 'CRYPTO_KEYS' => json_encode([['id' => 'fixture-v1', 'key' => str_repeat('k', 32)]], JSON_THROW_ON_ERROR),
            'REDIS_HOST' => (string) getenv('CHANNEL_IT_REDIS_HOST'), 'REDIS_PORT' => '6379', 'REDIS_TIMEOUT' => '0.2', 'REDIS_PASSWORD' => '', 'REDIS_DB' => '0',
            'SYNTHETIC_EMAIL_RETENTION_SECONDS' => '3600', 'RATE_LIMIT_MAX_REQUESTS' => '10000', 'RATE_LIMIT_WINDOW_SECONDS' => '60', 'TRUSTED_PROXIES' => '',
        ]);
        $apiKeys = new ApiKeyRepository($this->pdo);
        foreach (['a', 'rotated', 'b', 'unbound'] as $name) {
            $apiKeys->create('fixture-' . $name, 'fixture-key-' . $name, ['127.0.0.1']);
        }
        $this->pdo->exec("INSERT INTO cd_email_consumer_bindings (api_key_id, consumer_namespace) VALUES (1,'consumer-a'),(2,'consumer-a'),(3,'consumer-b')");
        $this->transport = new RecordingEmailTransport();
    }

    public static function connect(): PDO
    {
        $dsn = getenv('CHANNEL_IT_DSN');
        if ($dsn === false || !str_starts_with($dsn, 'mysql:')) {
            throw new \RuntimeException('Only an explicit MySQL fixture is accepted.');
        }
        $pdo = new PDO($dsn, (string) getenv('CHANNEL_IT_USER'), (string) getenv('CHANNEL_IT_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        if (!str_ends_with(self::scalar($pdo, 'SELECT DATABASE()'), '_test')) {
            throw new \RuntimeException('Refusing a non-test database.');
        }
        $pdo->exec("SET SESSION time_zone='+00:00'");
        return $pdo;
    }

    private function resetSchema(): void
    {
        foreach (['cd_email_synthetic_requests', 'cd_email_consumer_bindings', 'cd_email_enqueue_receipts', 'cd_email_queue', 'cd_api_keys'] as $table) {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
        $root = dirname(__DIR__, 2);
        foreach (['migrations/001_create_email_queue.sql', 'migrations/002_create_api_keys.sql',
            'migrations/003_optimize_worker_poll_index.sql', 'migrations/004_create_email_consumer_bindings.sql',
            'vendor/maatify/email-delivery/schema/mysql/002_email_enqueue_receipts.sql',
            'vendor/maatify/email-delivery/schema/mysql/003_email_queue_receipt_binding.sql',
            'vendor/maatify/email-delivery/schema/mysql/004_email_synthetic_requests.sql'] as $file) {
            $sql = file_get_contents($root . '/' . $file);
            if ($sql === false) {
                throw new \RuntimeException('Missing package fixture SQL.');
            }
            $this->pdo->exec($sql);
        }
    }

    /**
     * @return array{App<\Psr\Container\ContainerInterface>, Container}
     * @param (\Closure(): PDO)|null $atomicOverride
     */
    protected function application(?\Closure $atomicOverride = null): array
    {
        $container = require dirname(__DIR__, 2) . '/config/container.php';
        self::assertInstanceOf(Container::class, $container);
        $container->set(LoggerInterface::class, new NullLogger());
        $container->set(EmailTransportInterface::class, $this->transport);
        if ($atomicOverride !== null && class_exists(AtomicEmailConnectionFactory::class)) {
            $container->set(AtomicEmailConnectionFactory::class, new AtomicEmailConnectionFactory($atomicOverride));
        }
        $legacy = $container->get(PDO::class);
        self::assertInstanceOf(PDO::class, $legacy);
        $legacy->exec("SET SESSION time_zone='+03:00'");
        return [Application::create($container), $container];
    }

    /** @return array<string, mixed> */
    protected function payload(): array
    {
        return ['entity_type' => 'user', 'entity_id' => 'fixture', 'recipient' => 'fixture@example.invalid',
            'template_key' => 'welcome', 'language' => 'en', 'sender_type' => 1, 'context' => ['user_name' => 'Fixture', 'activation_link' => 'https://example.invalid/fixture']];
    }

    /**
     * @param App<\Psr\Container\ContainerInterface> $app
     * @param array<string, mixed>|null $payload
     */
    protected function request(App $app, string $method = 'POST', string $key = 'request-1', string $apiKey = 'a', ?array $payload = null, string $path = '/api/v2/email/enqueue'): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Api-Key', 'fixture-key-' . $apiKey)->withHeader('Idempotency-Key', $key)->withHeader('Content-Type', 'application/json')->withHeader('Accept', 'application/json');
        if ($method === 'POST') {
            $request = $request->withBody((new StreamFactory())->createStream(json_encode($payload ?? $this->payload(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)));
        }
        return $app->handle($request);
    }

    /** @return array<string, mixed> */
    protected function json(ResponseInterface $response): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        return $data;
    }

    protected static function scalar(PDO $pdo, string $sql): string
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new \RuntimeException('Fixture query unavailable.');
        }
        $value = $statement->fetchColumn();
        if (!is_int($value) && !is_string($value)) {
            throw new \RuntimeException('Fixture scalar missing.');
        }
        return (string) $value;
    }

    protected function tearDown(): void
    {
        if ($this->beforeEnvironment !== null) {
            $_ENV = $this->beforeEnvironment;
        }
    }
}
