<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Email;

use Closure;
use PDO;

/** Every acceptance/lookup gets a new owned UTC session, even in a long-running HTTP host. */
final readonly class AtomicEmailConnectionFactory
{
    /** @param Closure(): PDO $factory */
    public function __construct(private Closure $factory)
    {
    }

    public function open(): PDO
    {
        return ($this->factory)();
    }
}
