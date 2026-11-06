<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Email;

use Maatify\Crypto\Contract\CryptoContextProviderInterface;
use Maatify\Crypto\DX\CryptoProvider;
use Maatify\EmailDelivery\Queue\DTO\EmailEnqueueReceiptDTO;
use Maatify\EmailDelivery\Queue\DTO\EmailEnqueueRequestDTO;
use Maatify\EmailDelivery\Queue\EmailEnqueueReceiptReaderInterface;
use Maatify\EmailDelivery\Queue\IdempotentEmailQueueWriterInterface;
use Maatify\EmailDelivery\Queue\PdoIdempotentEmailQueue;

/** Connection lifecycle only. Atomicity, identity and reconciliation remain package-owned. */
final readonly class AtomicEmailGateway implements IdempotentEmailQueueWriterInterface, EmailEnqueueReceiptReaderInterface
{
    public function __construct(private AtomicEmailConnectionFactory $connections, private CryptoProvider $crypto, private CryptoContextProviderInterface $contexts)
    {
    }

    private function queue(): PdoIdempotentEmailQueue
    {
        return new PdoIdempotentEmailQueue($this->connections->open(), $this->crypto, $this->contexts, 'channel-delivery:email:enqueue:fingerprint:v1');
    }

    public function enqueueOnce(EmailEnqueueRequestDTO $request): EmailEnqueueReceiptDTO
    {
        return $this->queue()->enqueueOnce($request);
    }

    public function find(string $consumerNamespace, string $requestKey): ?EmailEnqueueReceiptDTO
    {
        return $this->queue()->find($consumerNamespace, $requestKey);
    }
}
