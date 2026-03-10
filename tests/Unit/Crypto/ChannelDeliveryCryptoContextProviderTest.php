<?php

declare(strict_types=1);

namespace Tests\Unit\Crypto;

use Maatify\ChannelDelivery\Crypto\ChannelDeliveryCryptoContextProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChannelDeliveryCryptoContextProviderTest extends TestCase
{
    private ChannelDeliveryCryptoContextProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new ChannelDeliveryCryptoContextProvider();
    }

    #[DataProvider('contextMethodsProvider')]
    public function testContextIsVersioned(string $method): void
    {
        $context = $this->provider->$method();

        $this->assertStringContainsString(
            ':v',
            $context,
            "Context '{$context}' from {$method}() must be versioned (contain ':v')"
        );
    }

    #[DataProvider('contextMethodsProvider')]
    public function testContextIsNotEmpty(string $method): void
    {
        $this->assertNotEmpty($this->provider->$method());
    }

    public function testAllContextsAreUnique(): void
    {
        $contexts = array_map(
            fn (array $row) => $this->provider->{$row[0]}(),
            $this->contextMethodsProvider()
        );

        $this->assertCount(
            count(array_unique($contexts)),
            $contexts,
            'All context strings must be unique'
        );
    }

    /**
     * @return list<array{string}>
     */
    public static function contextMethodsProvider(): array
    {
        return [
            ['identifierEmail'],
            ['identifierPhone'],
            ['notificationEmailRecipient'],
            ['notificationEmailPayload'],
            ['emailQueueRecipient'],
            ['emailQueuePayload'],
            ['totpSeed'],
            ['systemSecret'],
            ['abuseProtection'],
        ];
    }
}
