<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Http\Handler;

use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;
use Maatify\EmailDelivery\Queue\EmailQueueWriterInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class EnqueueEmailHandler
{
    public function __construct(
        private EmailQueueWriterInterface $queueWriter,
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface      $response
    ): ResponseInterface {
        // ── 1. Parse body ─────────────────────────────────────
        /** @var array<string, mixed>|null $body */
        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return $this->error($response, 400, 'invalid_body', 'Request body must be JSON.');
        }

        // ── 2. Validate required fields ───────────────────────
        $missing = [];

        foreach (['entity_type', 'recipient', 'template_key', 'language', 'sender_type'] as $field) {
            if (empty($body[$field])) {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            return $this->error(
                $response,
                422,
                'validation_error',
                'Missing required fields: ' . implode(', ', $missing)
            );
        }

        // ── 3. Validate types ─────────────────────────────────
        if (!is_string($body['entity_type']) || !is_string($body['recipient']) ||
            !is_string($body['template_key']) || !is_string($body['language']) ||
            !is_numeric($body['sender_type'])) {
            return $this->error($response, 422, 'validation_error', 'Invalid field types.');
        }

        $entityType  = $body['entity_type'];
        $entityId    = isset($body['entity_id']) && is_string($body['entity_id']) ? $body['entity_id'] : null;
        $recipient   = $body['recipient'];
        $templateKey = $body['template_key'];
        $language    = $body['language'];
        $senderType  = (int) $body['sender_type'];
        $priority    = isset($body['priority']) && is_numeric($body['priority']) ? (int) $body['priority'] : 5;

        /** @var array<string, mixed> $context */
        $context = isset($body['context']) && is_array($body['context'])
            ? $body['context']
            : [];

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return $this->error($response, 422, 'validation_error', 'Invalid recipient email address.');
        }

        if ($priority < 1 || $priority > 10) {
            return $this->error($response, 422, 'validation_error', 'Priority must be between 1 and 10.');
        }

        // ── 4. Enqueue ────────────────────────────────────────
        $this->queueWriter->enqueue(
            entityType:  $entityType,
            entityId:    $entityId,
            recipientEmail: $recipient,
            payload:     new EmailQueuePayloadDTO(
                context:     $context,
                templateKey: $templateKey,
                language:    $language,
            ),
            senderType:  $senderType,
            priority:    $priority,
        );

        // ── 5. Respond ────────────────────────────────────────
        $response->getBody()->write(
            json_encode(['status' => 'queued'], JSON_THROW_ON_ERROR)
        );

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(202);
    }

    private function error(
        ResponseInterface $response,
        int    $status,
        string $error,
        string $message
    ): ResponseInterface {
        $response->getBody()->write(
            json_encode(['error' => $error, 'message' => $message], JSON_THROW_ON_ERROR)
        );
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
