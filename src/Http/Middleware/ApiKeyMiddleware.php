<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Http\Middleware;

use Maatify\ChannelDelivery\ApiKey\ApiKeyRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class ApiKeyMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'api_key';

    public function __construct(
        private ApiKeyRepository       $repository,
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(
        ServerRequestInterface  $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        // ── 1. Extract key from header ────────────────────────
        $rawKey = $request->getHeaderLine('X-Api-Key');

        if ($rawKey === '') {
            return $this->unauthorized('Missing X-Api-Key header');
        }

        // ── 2. Lookup in DB ───────────────────────────────────
        $apiKey = $this->repository->findByRawKey($rawKey);

        if ($apiKey === null) {
            return $this->unauthorized('Invalid API key');
        }

        // ── 3. Validate IP ────────────────────────────────────
        $clientIp = $this->resolveClientIp($request);

        if (!in_array($clientIp, $apiKey->ipWhitelist, true)) {
            return $this->forbidden("IP not allowed: {$clientIp}");
        }

        // ── 4. Touch last_used_at (fire and forget) ───────────
        $this->repository->touchLastUsed($apiKey->id);

        // ── 5. Pass API key to handler via attribute ──────────
        return $handler->handle(
            $request->withAttribute(self::ATTRIBUTE, $apiKey)
        );
    }

    private function resolveClientIp(ServerRequestInterface $request): string
    {
        // Trust X-Forwarded-For only if you're behind a known proxy.
        // For direct connections, use REMOTE_ADDR.
        $serverParams = $request->getServerParams();
        return (string) ($serverParams['REMOTE_ADDR'] ?? '');
    }

    private function unauthorized(string $message): ResponseInterface
    {
        return $this->jsonResponse(401, 'unauthorized', $message);
    }

    private function forbidden(string $message): ResponseInterface
    {
        return $this->jsonResponse(403, 'forbidden', $message);
    }

    private function jsonResponse(int $status, string $error, string $message): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write(
            json_encode(['error' => $error, 'message' => $message], JSON_THROW_ON_ERROR)
        );
        return $response->withHeader('Content-Type', 'application/json');
    }
}
