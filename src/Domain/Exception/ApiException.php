<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Anything not implementing this is an unexpected failure: the kernel logs it
 * and answers 500.
 */
interface ApiException extends \Throwable
{
    public function statusCode(): int;

    /** Stable identifier, e.g. "insufficient_funds". */
    public function errorCode(): string;

    /** @return array<string, list<string>> per-field messages, keyed by field */
    public function details(): array;
}
