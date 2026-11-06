<?php

declare(strict_types=1);

namespace Tests\Unit;

use Maatify\ChannelDelivery\Http\Middleware\AtomicEmailBodyLimitMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class AtomicEmailBodyLimitMiddlewareTest extends TestCase
{
    public function testUnknownSizeNonSeekableBodyIsReadOnlyToLimitPlusOne(): void
    {
        $read = 0;
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getSize')->willReturn(null);
        $stream->method('isSeekable')->willReturn(false);
        $stream->method('eof')->willReturn(false);
        $stream->expects(self::never())->method('__toString');
        $stream->method('read')->willReturnCallback(static function (int $length) use (&$read): string {
            $read += $length;
            return str_repeat('x', $length);
        });
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v2/email/enqueue')->withBody($stream);
        $response = (new AtomicEmailBodyLimitMiddleware(new ResponseFactory()))->process($request, $handler);
        self::assertSame(413, $response->getStatusCode());
        self::assertSame(AtomicEmailBodyLimitMiddleware::MAX_BYTES + 1, $read);
    }

    public function testLegacyBypassesBodyInspectionAndCacheHeaderChanges(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->expects(self::never())->method('getSize');
        $stream->expects(self::never())->method('read');
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/email/enqueue')->withBody($stream);
        $original = (new ResponseFactory())->createResponse(202);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with($request)->willReturn($original);
        self::assertSame($original, (new AtomicEmailBodyLimitMiddleware(new ResponseFactory()))->process($request, $handler));
    }

    public function testNonProgressingStreamFailsClosed(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getSize')->willReturn(null);
        $stream->method('isSeekable')->willReturn(false);
        $stream->method('eof')->willReturn(false);
        $stream->method('read')->willReturn('');
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v2/email/enqueue')->withBody($stream);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        self::assertSame(400, (new AtomicEmailBodyLimitMiddleware(new ResponseFactory()))->process($request, $handler)->getStatusCode());
    }
}
