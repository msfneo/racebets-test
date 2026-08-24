<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Implemented by every exception that carries a deliberate HTTP representation.
 *
 * Anything that does not implement this is an unexpected failure: the kernel
 * logs it and answers 500 without leaking internals to the client.
 */
interface ApiException extends \Throwable
{
    public function statusCode(): int;

    /**
     * Stable, machine-readable error identifier, e.g. "insufficient_funds".
     */
    public function errorCode(): string;

    /**
     * Optional per-field details, keyed by field name.
     *
     * @return array<string, list<string>>
     */
    public function details(): array;
}
