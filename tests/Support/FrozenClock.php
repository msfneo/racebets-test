<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Clock;

/**
 * A clock the tests move by hand, so that reporting windows and ledger dates are
 * deterministic instead of depending on when the suite happens to run.
 */
final class FrozenClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(string $now = '2026-03-15 12:00:00')
    {
        $this->now = new \DateTimeImmutable($now, new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function travelTo(string $moment): void
    {
        $this->now = new \DateTimeImmutable($moment, new \DateTimeZone('UTC'));
    }
}
