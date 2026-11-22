<?php

declare(strict_types=1);

namespace Tests\Support;

/** Thrown from an injected sleep to end an otherwise endless worker loop in a test. */
final class StopLoop extends \RuntimeException
{
}
