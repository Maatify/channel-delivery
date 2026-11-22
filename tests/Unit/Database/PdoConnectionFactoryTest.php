<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Maatify\ChannelDelivery\Database\PdoConnectionFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PdoConnectionFactoryTest extends TestCase
{
    public function testUnsetOrEmptyWaitTimeoutUsesTheDefault(): void
    {
        self::assertSame('SET SESSION wait_timeout = 28800', PdoConnectionFactory::fromEnvironment([])->sessionInitCommand());
        self::assertSame('SET SESSION wait_timeout = 28800', PdoConnectionFactory::fromEnvironment(['DB_SESSION_WAIT_TIMEOUT' => ''])->sessionInitCommand());
    }

    public function testConfiguredWaitTimeoutIsApplied(): void
    {
        self::assertSame('SET SESSION wait_timeout = 600', PdoConnectionFactory::fromEnvironment(['DB_SESSION_WAIT_TIMEOUT' => '600'])->sessionInitCommand());
        self::assertSame('SET SESSION wait_timeout = 31536000', PdoConnectionFactory::fromEnvironment(['DB_SESSION_WAIT_TIMEOUT' => '31536000'])->sessionInitCommand());
    }

    #[DataProvider('invalidWaitTimeouts')]
    public function testInvalidWaitTimeoutIsRejected(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DB_SESSION_WAIT_TIMEOUT');
        PdoConnectionFactory::fromEnvironment(['DB_SESSION_WAIT_TIMEOUT' => $value]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidWaitTimeouts(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'fraction' => ['1.5'];
        yield 'text' => ['ten'];
        yield 'leading zero' => ['060'];
        yield 'above MySQL maximum' => ['31536001'];
        yield 'not a string' => [600];
    }

    public function testConstructorRejectsAnOutOfRangeTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PdoConnectionFactory('mysql:host=127.0.0.1', 'user', 'secret', 0);
    }
}
