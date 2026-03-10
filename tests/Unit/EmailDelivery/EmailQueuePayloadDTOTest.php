<?php

declare(strict_types=1);

namespace Tests\Unit\EmailDelivery;

use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;
use PHPUnit\Framework\TestCase;

final class EmailQueuePayloadDTOTest extends TestCase
{
    public function testToArrayContainsAllFields(): void
    {
        $dto = new EmailQueuePayloadDTO(
            context:     ['user_name' => 'Ahmed', 'link' => 'https://example.com'],
            templateKey: 'welcome',
            language:    'en'
        );

        $result = $dto->toArray();

        $this->assertSame(['user_name' => 'Ahmed', 'link' => 'https://example.com'], $result['context']);
        $this->assertSame('welcome', $result['templateKey']);
        $this->assertSame('en', $result['language']);
    }

    public function testToArrayIsJsonSerializable(): void
    {
        $dto = new EmailQueuePayloadDTO(
            context:     ['key' => 'value'],
            templateKey: 'welcome',
            language:    'ar'
        );

        $json = json_encode($dto->toArray(), JSON_THROW_ON_ERROR);

        $this->assertJson($json);

        /** @var array{context: array<string,mixed>, templateKey: string, language: string} $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('welcome', $decoded['templateKey']);
        $this->assertSame('ar', $decoded['language']);
    }
}
