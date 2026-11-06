<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\StreamFactory;
use Throwable;

/** Runs before JSON body parsing, only for the new v2 email routes. */
final readonly class AtomicEmailBodyLimitMiddleware implements MiddlewareInterface
{
    public const MAX_BYTES = 1048576;

    public function __construct(private ResponseFactoryInterface $responses)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array($request->getUri()->getPath(), ['/api/v2/email/enqueue', '/api/v2/email/receipt', '/api/v2/email/synthetic'], true)) {
            return $handler->handle($request);
        }
        try {
            $stream = $request->getBody();
            if (($stream->getSize() ?? 0) > self::MAX_BYTES) {
                return $this->error(413, 'body_too_large');
            }
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $raw = '';
            while (!$this->atEnd($stream) && strlen($raw) <= self::MAX_BYTES) {
                $chunk = $stream->read(min(8192, self::MAX_BYTES + 1 - strlen($raw)));
                if ($chunk === '' && !$this->atEnd($stream)) {
                    return $this->error(400, 'unreadable_body');
                }
                $raw .= $chunk;
            }
            if (strlen($raw) > self::MAX_BYTES) {
                return $this->error(413, 'body_too_large');
            }
            $request = $request->withBody((new StreamFactory())->createStream($raw));
        } catch (Throwable) {
            return $this->error(400, 'unreadable_body');
        }
        return $handler->handle($request)->withHeader('Cache-Control', 'no-store');
    }

    /** @phpstan-impure */
    private function atEnd(StreamInterface $stream): bool
    {
        return $stream->eof();
    }

    private function error(int $status, string $error): ResponseInterface
    {
        $response = $this->responses->createResponse($status);
        $response->getBody()->write(json_encode(['error' => $error], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
    }
}
