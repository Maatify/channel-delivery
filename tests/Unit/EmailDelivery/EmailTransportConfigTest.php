<?php

declare(strict_types=1);

namespace Tests\Unit\EmailDelivery;

use DI\Container;
use Maatify\EmailDelivery\Config\EmailTransportConfigDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailTransportConfigTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        $_ENV = [
            'APP_ENV' => 'testing',
            'MAIL_HOST' => '127.0.0.1',
            'MAIL_PORT' => '1025',
            'MAIL_USERNAME' => 'fixture',
            'MAIL_PASSWORD' => 'fixture',
            'MAIL_FROM_ADDRESS' => 'fixture@example.invalid',
            'MAIL_FROM_NAME' => 'Fixture',
        ];
    }

    protected function tearDown(): void
    {
        $_ENV = $this->environment;
    }

    #[DataProvider('supportedModes')]
    public function testSupportedSmtpModesKeepTheirMeaning(?string $value): void
    {
        if ($value !== null) {
            $_ENV['MAIL_ENCRYPTION'] = $value;
        }

        self::assertSame($value, $this->configuration()->encryption);
    }

    /** @return array<string, array{string|null}> */
    public static function supportedModes(): array
    {
        return [
            'omitted' => [null],
            'empty' => [''],
            'STARTTLS' => ['tls'],
            'implicit TLS' => ['ssl'],
        ];
    }

    public function testInvalidSmtpModeFailsBeforeTransportCanBeCreated(): void
    {
        $_ENV['MAIL_ENCRYPTION'] = 'starttls';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MAIL_ENCRYPTION must be tls, ssl or empty.');
        $this->configuration();
    }

    private function configuration(): EmailTransportConfigDTO
    {
        $container = require dirname(__DIR__, 3) . '/config/container.php';
        self::assertInstanceOf(Container::class, $container);
        $configuration = $container->get(EmailTransportConfigDTO::class);
        self::assertInstanceOf(EmailTransportConfigDTO::class, $configuration);

        return $configuration;
    }
}
