<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Handler;

use Maatify\ChannelDelivery\Http\Handler\EnqueueEmailHandler;
use Maatify\EmailDelivery\Queue\EmailQueueWriterInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Tests for EnqueueEmailHandler — focusing on the validation fixes
 * introduced after Jules audit:
 *
 *   - empty() replaced with isset() to allow integer 0 for sender_type
 *   - All required field combinations validated correctly
 */
final class EnqueueEmailHandlerTest extends TestCase
{
    private EnqueueEmailHandler $handler;

    /** @var EmailQueueWriterInterface&MockObject */
    private EmailQueueWriterInterface $writer;

    protected function setUp(): void
    {
        $this->writer  = $this->createMock(EmailQueueWriterInterface::class);
        $this->handler = new EnqueueEmailHandler($this->writer);
    }

    // ── Helpers ───────────────────────────────────────────────

    /**
     * @param array<string, mixed> $body
     */
    private function makeRequest(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/email/enqueue');
        return $request->withParsedBody($body);
    }

    private function makeResponse(): \Psr\Http\Message\ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'entity_type'  => 'user',
            'entity_id'    => '123',
            'recipient'    => 'ahmed@example.com',
            'template_key' => 'welcome',
            'language'     => 'ar',
            'sender_type'  => 1,
            'context'      => ['user_name' => 'Ahmed'],
        ];
    }

    // ── Validation: isset() fix ───────────────────────────────

    /**
     * Critical regression: empty(0) === true would reject sender_type=0.
     * isset(0) === true — this must be accepted as a valid value.
     */
    #[Test]
    public function testSenderTypeZeroIsAccepted(): void
    {
        $this->writer->expects($this->once())->method('enqueue');

        $payload               = $this->validPayload();
        $payload['sender_type'] = 0;

        $response = ($this->handler)(
            $this->makeRequest($payload),
            $this->makeResponse()
        );

        $this->assertSame(202, $response->getStatusCode());
    }

    #[Test]
    public function testSenderTypeOneIsAccepted(): void
    {
        $this->writer->expects($this->once())->method('enqueue');

        $response = ($this->handler)(
            $this->makeRequest($this->validPayload()),
            $this->makeResponse()
        );

        $this->assertSame(202, $response->getStatusCode());
    }

    #[Test]
    public function testMissingSenderTypeReturns422(): void
    {
        $this->writer->expects($this->never())->method('enqueue');

        $payload = $this->validPayload();
        unset($payload['sender_type']);

        $response = ($this->handler)(
            $this->makeRequest($payload),
            $this->makeResponse()
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('sender_type', (string) $response->getBody());
    }

    #[Test]
    public function testEmptyStringRecipientReturns422(): void
    {
        $this->writer->expects($this->never())->method('enqueue');

        $payload              = $this->validPayload();
        $payload['recipient'] = '';

        $response = ($this->handler)(
            $this->makeRequest($payload),
            $this->makeResponse()
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function testInvalidEmailReturns422(): void
    {
        $this->writer->expects($this->never())->method('enqueue');

        $payload              = $this->validPayload();
        $payload['recipient'] = 'not-an-email';

        $response = ($this->handler)(
            $this->makeRequest($payload),
            $this->makeResponse()
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Invalid recipient', (string) $response->getBody());
    }

    #[Test]
    public function testMissingRequiredFieldsReturns422(): void
    {
        $this->writer->expects($this->never())->method('enqueue');

        $response = ($this->handler)(
            $this->makeRequest([]),
            $this->makeResponse()
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function testNonArrayBodyReturns400(): void
    {
        $this->writer->expects($this->never())->method('enqueue');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/email/enqueue')
            ->withParsedBody(null);

        $response = ($this->handler)($request, $this->makeResponse());

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function testPriorityOutOfRangeReturns422(): void
    {
        $this->writer->expects($this->never())->method('enqueue');

        $payload             = $this->validPayload();
        $payload['priority'] = 99;

        $response = ($this->handler)(
            $this->makeRequest($payload),
            $this->makeResponse()
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Priority', (string) $response->getBody());
    }

    #[Test]
    public function testValidPayloadResponds202WithQueuedStatus(): void
    {
        $this->writer->expects($this->once())->method('enqueue');

        $response = ($this->handler)(
            $this->makeRequest($this->validPayload()),
            $this->makeResponse()
        );

        $this->assertSame(202, $response->getStatusCode());

        /** @var array{status: string} $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('queued', $body['status']);
    }

    #[Test]
    public function testContextIsOptional(): void
    {
        $this->writer->expects($this->once())->method('enqueue');

        $payload = $this->validPayload();
        unset($payload['context']);

        $response = ($this->handler)(
            $this->makeRequest($payload),
            $this->makeResponse()
        );

        $this->assertSame(202, $response->getStatusCode());
    }

    #[Test]
    public function testEntityIdIsOptional(): void
    {
        $this->writer->expects($this->once())->method('enqueue');

        $payload = $this->validPayload();
        unset($payload['entity_id']);

        $response = ($this->handler)(
            $this->makeRequest($payload),
            $this->makeResponse()
        );

        $this->assertSame(202, $response->getStatusCode());
    }
}
