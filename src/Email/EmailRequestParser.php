<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Email;

use InvalidArgumentException;
use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;

/** Shared v2 HTTP validation, including raw JSON shape preservation. */
final readonly class EmailRequestParser
{
    public function parse(ServerRequestInterface $request): EmailHttpMessage
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
        return new EmailHttpMessage($entityType, $entity, $recipient,
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

}
