<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Http\Handler;

use InvalidArgumentException;
use JsonException;
use Maatify\ChannelDelivery\ApiKey\DTO\ApiKeyDTO;
use Maatify\ChannelDelivery\Email\ConsumerNamespaceRepositoryInterface;
use Maatify\ChannelDelivery\Email\EmailRequestParser;
use Maatify\ChannelDelivery\Http\Middleware\ApiKeyMiddleware;
use Maatify\EmailDelivery\Synthetic\SyntheticEmailRecorderInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/** Explicit trusted no-send endpoint; no real writer or maintenance port is injected. */
final readonly class SyntheticEmailHandler
{
    public function __construct(
        private SyntheticEmailRecorderInterface $recorder,
        private ConsumerNamespaceRepositoryInterface $consumers,
        private EmailRequestParser $messages,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $apiKey = $request->getAttribute(ApiKeyMiddleware::ATTRIBUTE);
        if (!$apiKey instanceof ApiKeyDTO || !$apiKey->isActive || $apiKey->id < 1) {
            return $this->json($response, 401, ['error' => 'unauthorized']);
        }
        try {
            $namespace = $this->consumers->findForApiKey($apiKey->id);
            if ($namespace === null) {
                return $this->json($response, 403, ['error' => 'consumer_not_configured']);
            }
            if ($request->hasHeader('Idempotency-Key')) {
                return $this->json($response, 422, ['error' => 'synthetic_idempotency_not_supported']);
            }
            if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
                return $this->json($response, 415, ['error' => 'unsupported_media_type']);
            }
            $record = $this->recorder->record($this->messages->parse($request)->toSyntheticRequest($namespace));
            return $this->json($response, 202, ['status' => 'recorded', 'record' => [
                'record_id' => $record->recordId, 'recorded_at' => $record->recordedAtUtc, 'expires_at' => $record->expiresAtUtc,
            ]]);
        } catch (JsonException) {
            return $this->json($response, 400, ['error' => 'invalid_json']);
        } catch (InvalidArgumentException) {
            return $this->json($response, 422, ['error' => 'validation_error']);
        } catch (Throwable $failure) {
            $this->logger->error('Synthetic email recording unavailable', ['exception_type' => $failure::class]);
            return $this->json($response, 503, ['error' => 'recording_unavailable', 'recording' => 'unknown']);
        }
    }

    /** @param array<string, mixed> $body */
    private function json(ResponseInterface $response, int $status, array $body): ResponseInterface
    {
        $response->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
    }
}
