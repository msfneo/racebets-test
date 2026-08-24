<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Injected so reporting windows and ledger timestamps can be pinned in tests.
 */
interface Clock
{
    /** Always UTC. */
    public function now(): \DateTimeImmutable;
}
