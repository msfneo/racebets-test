<?php

declare(strict_types=1);

namespace App\Http\Exception;

use App\Domain\Exception\ApiException;

final class MalformedRequest extends \RuntimeException implements ApiException
{
    public static function invalidJson(string $reason): self
    {
        return new self(\sprintf('Request body is not valid JSON: %s.', $reason));
    }

    public static function notAJsonObject(): self
    {
        return new self('Request body must be a JSON object.');
    }

    public function statusCode(): int
    {
        return 400;
    }

    public function errorCode(): string
    {
        return 'malformed_request';
    }

    public function details(): array
    {
        return [];
    }
}
