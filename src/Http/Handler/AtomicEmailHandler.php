<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Http\Handler;

use InvalidArgumentException;
use JsonException;
use Maatify\ChannelDelivery\ApiKey\DTO\ApiKeyDTO;
use Maatify\ChannelDelivery\Email\ConsumerNamespaceRepositoryInterface;
use Maatify\ChannelDelivery\Http\Middleware\ApiKeyMiddleware;
use Maatify\EmailDelivery\Exception\EmailEnqueueConflictException;
use Maatify\ChannelDelivery\Email\EmailRequestParser;
use Maatify\EmailDelivery\Queue\EmailEnqueueReceiptReaderInterface;
use Maatify\EmailDelivery\Queue\IdempotentEmailQueueWriterInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/** Additive v2: confirmed queue acceptance, not an SMTP delivery promise. */
final readonly class AtomicEmailHandler
{
    public function __construct(
        private IdempotentEmailQueueWriterInterface $writer,
        private EmailEnqueueReceiptReaderInterface $reader,
        private ConsumerNamespaceRepositoryInterface $consumers,
        private LoggerInterface $logger,
        private EmailRequestParser $messages,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->operate($request, $response, false);
    }

    public function receipt(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->operate($request, $response, true);
    }

    private function operate(ServerRequestInterface $request, ResponseInterface $response, bool $lookup): ResponseInterface
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
            $keys = $request->getHeader('Idempotency-Key');
            if (count($keys) !== 1 || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,190}\z/', $keys[0]) !== 1) {
                return $this->json($response, 422, ['error' => 'invalid_idempotency_key']);
            }
            if ($lookup) {
                $receipt = $this->reader->find($namespace, $keys[0]);
            } else {
                if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
                    return $this->json($response, 415, ['error' => 'unsupported_media_type']);
                }
                $receipt = $this->writer->enqueueOnce($this->messages->parse($request)->toEnqueueRequest($namespace, $keys[0]));
            }
            return $receipt === null
                ? $this->json($response, 404, ['error' => 'receipt_not_found'])
                : $this->json($response, $lookup ? 200 : 202, ['status' => 'accepted', 'receipt' => $receipt->jsonSerialize()]);
        } catch (JsonException) {
            return $this->json($response, 400, ['error' => 'invalid_json']);
        } catch (InvalidArgumentException) {
            return $this->json($response, 422, ['error' => 'validation_error']);
        } catch (EmailEnqueueConflictException) {
            return $this->json($response, 409, ['error' => 'idempotency_conflict']);
        } catch (Throwable $failure) {
            // Do not expose crypto/SQL messages, identity strings, context or credentials.
            $this->logger->error('Atomic email operation unavailable', ['exception_type' => $failure::class]);
            return $this->json($response, 503, ['error' => 'enqueue_unavailable', 'acceptance' => 'unknown', 'retry_same_key' => true]);
        }
    }

    /** @param array<string, mixed> $body */
    private function json(ResponseInterface $response, int $status, array $body): ResponseInterface
    {
        $response->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
    }
}
