<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Email;

use Maatify\EmailDelivery\Queue\DTO\EmailEnqueueRequestDTO;
use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;
use Maatify\EmailDelivery\Synthetic\DTO\SyntheticEmailRequestDTO;

/** Validated HTTP message, with explicit separate real and inert adapters. */
final readonly class EmailHttpMessage
{
    public function __construct(
        public string $entityType,
        public ?string $entityId,
        public string $recipient,
        public EmailQueuePayloadDTO $payload,
        public int $senderType,
        public int $priority,
    ) {
    }

    public function toEnqueueRequest(string $namespace, string $key): EmailEnqueueRequestDTO
    {
        return new EmailEnqueueRequestDTO($namespace, $key, $this->entityType, $this->entityId,
            $this->recipient, $this->payload, $this->senderType, $this->priority);
    }

    public function toSyntheticRequest(string $namespace): SyntheticEmailRequestDTO
    {
        return new SyntheticEmailRequestDTO($namespace, $this->entityType, $this->entityId,
            $this->recipient, $this->payload, $this->senderType, $this->priority);
    }
}
