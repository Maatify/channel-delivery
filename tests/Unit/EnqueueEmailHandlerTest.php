<?php

declare(strict_types=1);

namespace Tests\Unit;

use Maatify\ChannelDelivery\Http\Handler\EnqueueEmailHandler;
use Maatify\EmailDelivery\Queue\EmailQueueWriterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

final class EnqueueEmailHandlerTest extends TestCase
{
    public function testValidRequestEnqueuesEmailAndReturns202(): void
    {
        // Arrange
        $writerMock = $this->createMock(EmailQueueWriterInterface::class);
        $writerMock->expects($this->once())
            ->method('enqueue');

        $handler = new EnqueueEmailHandler($writerMock);

        $requestMock = $this->createMock(ServerRequestInterface::class);
        $requestMock->method('getParsedBody')
            ->willReturn([
                'entity_type'  => 'user',
                'recipient'    => 'test@example.com',
                'template_key' => 'welcome',
                'language'     => 'en',
                'sender_type'  => 1,
            ]);

        $streamMock = $this->createMock(StreamInterface::class);
        $streamMock->expects($this->once())
            ->method('write')
            ->with('{"status":"queued"}');

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getBody')
            ->willReturn($streamMock);
        $responseMock->method('withHeader')
            ->willReturnSelf();
        $responseMock->method('withStatus')
            ->with(202)
            ->willReturnSelf();

        // Act
        $handler($requestMock, $responseMock);

        // Assert
        // Implicit via $this->once() on enqueue and write
    }

    public function testMissingBodyReturns400(): void
    {
        // Arrange
        $writerMock = $this->createMock(EmailQueueWriterInterface::class);
        $writerMock->expects($this->never())->method('enqueue');

        $handler = new EnqueueEmailHandler($writerMock);

        $requestMock = $this->createMock(ServerRequestInterface::class);
        $requestMock->method('getParsedBody')
            ->willReturn(null); // Invalid body

        $streamMock = $this->createMock(StreamInterface::class);
        $streamMock->expects($this->once())->method('write')->with($this->stringContains('invalid_body'));

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getBody')->willReturn($streamMock);
        $responseMock->method('withHeader')->willReturnSelf();
        $responseMock->method('withStatus')->with(400)->willReturnSelf();

        // Act
        $handler($requestMock, $responseMock);
    }
}
