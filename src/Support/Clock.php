<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Injected wherever "now" is needed, so that reporting windows and ledger
 * timestamps can be pinned to fixed values in tests.
 */
interface Clock
{
    /**
     * Always UTC — see the README section on time handling.
     */
    public function now(): \DateTimeImmutable;
}
