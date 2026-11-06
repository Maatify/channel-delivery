<?php

declare(strict_types=1);

namespace Tests\Support;

use Maatify\EmailDelivery\DTO\RenderedEmailDTO;
use Maatify\EmailDelivery\Transport\EmailTransportInterface;

final class RecordingEmailTransport implements EmailTransportInterface
{
    public int $calls = 0;
    public function send(string $recipientEmail, RenderedEmailDTO $email, ?string $replyToEmail = null): void
    {
        ++$this->calls;
    }
}
