<?php

declare(strict_types=1);

namespace App\Http\Exception;

use App\Domain\Exception\ApiException;

final class MethodNotAllowed extends \RuntimeException implements ApiException
{
    /**
     * @param list<string> $allowed
     */
    private function __construct(string $message, public readonly array $allowed)
    {
        parent::__construct($message);
    }

    /**
     * @param list<string> $allowed
     */
    public static function for(string $method, string $path, array $allowed): self
    {
        \sort($allowed);

        return new self(
            \sprintf('%s is not allowed on %s. Allowed: %s.', $method, $path, \implode(', ', $allowed)),
            $allowed,
        );
    }

    public function statusCode(): int
    {
        return 405;
    }

    public function errorCode(): string
    {
        return 'method_not_allowed';
    }

    public function details(): array
    {
        return [];
    }
}
