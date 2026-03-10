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

    /**
     * @param list<string> $trustedProxies
     *   List of trusted reverse proxy IPs.
     *   When set, X-Forwarded-For is trusted ONLY from these IPs.
     *   Configure via TRUSTED_PROXIES env (comma-separated).
     *   Leave empty for direct connections (no proxy).
     *
     * Examples:
     *   TRUSTED_PROXIES=10.0.0.1                  ← single proxy
     *   TRUSTED_PROXIES=10.0.0.1,10.0.0.2         ← multiple proxies
     *   TRUSTED_PROXIES=                           ← no proxy (direct)
     */
    public function __construct(
        private ApiKeyRepository         $repository,
        private ResponseFactoryInterface $responseFactory,
        private array                    $trustedProxies = [],
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

        // ── 4. Debounced last_used_at update ─────────────────
        // Writing last_used_at on every request is a synchronous DB write
        // that bottlenecks high-throughput enqueue endpoints.
        // We debounce: only update if not updated in the last 60 seconds.
        if ($this->shouldUpdateLastUsed($apiKey->lastUsedAt)) {
            $this->repository->touchLastUsed($apiKey->id);
        }

        // ── 5. Pass API key to handler via attribute ──────────
        return $handler->handle(
            $request->withAttribute(self::ATTRIBUTE, $apiKey)
        );
    }

    private function shouldUpdateLastUsed(?\DateTimeImmutable $lastUsedAt): bool
    {
        if ($lastUsedAt === null) {
            return true;
        }

        return (new \DateTimeImmutable())->getTimestamp() - $lastUsedAt->getTimestamp() > 60;
    }

    private function resolveClientIp(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        $remoteAddr   = (string) ($serverParams['REMOTE_ADDR'] ?? '');

        // Trust X-Forwarded-For ONLY if the request arrives from a known proxy.
        // Without this guard, any client can spoof their IP by sending
        // X-Forwarded-For: 1.2.3.4 and bypass IP whitelisting.
        if (
            $this->trustedProxies !== []
            && in_array($remoteAddr, $this->trustedProxies, true)
        ) {
            $forwarded = $request->getHeaderLine('X-Forwarded-For');
            if ($forwarded !== '') {
                // X-Forwarded-For: client, proxy1, proxy2
                // The leftmost IP is the original client.
                $clientIp = trim(explode(',', $forwarded)[0]);
                if (filter_var($clientIp, FILTER_VALIDATE_IP) !== false) {
                    return $clientIp;
                }
            }
        }

        return $remoteAddr;
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
