<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Http\Handler;

use InvalidArgumentException;
use JsonException;
use Maatify\ChannelDelivery\ApiKey\DTO\ApiKeyDTO;
use Maatify\ChannelDelivery\Email\ConsumerNamespaceRepositoryInterface;
use Maatify\ChannelDelivery\Http\Middleware\ApiKeyMiddleware;
use Maatify\EmailDelivery\Exception\EmailEnqueueConflictException;
use Maatify\EmailDelivery\Queue\DTO\EmailEnqueueRequestDTO;
use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;
use Maatify\EmailDelivery\Queue\EmailEnqueueReceiptReaderInterface;
use Maatify\EmailDelivery\Queue\IdempotentEmailQueueWriterInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

/** Additive v2: confirmed queue acceptance, not an SMTP delivery promise. */
final readonly class AtomicEmailHandler
{
    public function __construct(
        private IdempotentEmailQueueWriterInterface $writer,
        private EmailEnqueueReceiptReaderInterface $reader,
        private ConsumerNamespaceRepositoryInterface $consumers,
        private LoggerInterface $logger,
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
                $receipt = $this->writer->enqueueOnce($this->request($request, $namespace, $keys[0]));
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

    private function request(ServerRequestInterface $request, string $namespace, string $key): EmailEnqueueRequestDTO
    {
        $raw = (string) $request->getBody();
        // Also bound direct invocations; the v2 middleware bounds before global body parsing.
        if (strlen($raw) > 1048576) {
            throw new InvalidArgumentException('Body exceeds the v2 limit.');
        }
        $object = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
        if (!$object instanceof stdClass) {
            throw new InvalidArgumentException('Body must be an object.');
        }
        $allowed = ['entity_type', 'entity_id', 'recipient', 'template_key', 'language', 'sender_type', 'priority', 'context', 'reply_to'];
        foreach (array_keys(get_object_vars($object)) as $name) {
            if (!in_array($name, $allowed, true)) {
                throw new InvalidArgumentException('Unknown request field.');
            }
        }
        if (property_exists($object, 'context')) {
            if (!$object->context instanceof stdClass) {
                throw new InvalidArgumentException('Context must be an object.');
            }
            // Validate object/list distinctions BEFORE associative decoding can erase them.
            $this->assertContextShape($object->context, true);
        }
        // Decode the ORIGINAL bytes: re-encoding without preserve-zero would collapse float1.0 into int1.
        /** @var array<string, mixed> $body */
        $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        $entityType = $this->string($body, 'entity_type', 32);
        $recipient = $this->string($body, 'recipient', 320);
        $template = $this->string($body, 'template_key', 100);
        $language = $this->string($body, 'language', 5);
        $entity = $body['entity_id'] ?? null;
        $reply = $body['reply_to'] ?? null;
        if ($reply === '') {
            $reply = null;
        }
        if (($entity !== null && (!is_string($entity) || strlen($entity) > 64))
            || ($reply !== null && (!is_string($reply) || strlen($reply) > 320 || filter_var($reply, FILTER_VALIDATE_EMAIL) === false))
            || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid optional string or email.');
        }
        $sender = $body['sender_type'] ?? null;
        $priority = array_key_exists('priority', $body) ? $body['priority'] : 5;
        if (!is_int($sender) || $sender < 0 || $sender > 255 || !is_int($priority) || $priority < 1 || $priority > 10) {
            throw new InvalidArgumentException('Invalid integer.');
        }
        /** @var array<string, mixed> $context */
        $context = $body['context'] ?? [];
        return new EmailEnqueueRequestDTO($namespace, $key, $entityType, $entity, $recipient,
            new EmailQueuePayloadDTO($context, $template, $language, $reply), $sender, $priority);
    }

    /** The package accepts arrays; reject JSON objects whose shape cannot be retained safely. */
    private function assertContextShape(mixed $value, bool $root = false): void
    {
        if ($value instanceof stdClass) {
            $fields = get_object_vars($value);
            // The context root is always an object by contract; nested {} would become [].
            if (!$root && $fields === []) {
                throw new InvalidArgumentException('Nested empty objects are not supported.');
            }
            foreach ($fields as $name => $child) {
                if (preg_match('/\A(?:0|-?[1-9][0-9]*)\z/', (string) $name) === 1) {
                    throw new InvalidArgumentException('Integer-shaped object keys are not supported.');
                }
                $this->assertContextShape($child);
            }
        } elseif (is_array($value)) {
            foreach ($value as $child) {
                $this->assertContextShape($child);
            }
        }
    }

    /** @param array<string, mixed> $body */
    private function string(array $body, string $name, int $maximum): string
    {
        $value = $body[$name] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > $maximum) {
            throw new InvalidArgumentException('Invalid required string.');
        }
        return $value;
    }

    /** @param array<string, mixed> $body */
    private function json(ResponseInterface $response, int $status, array $body): ResponseInterface
    {
        $response->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
    }
}
