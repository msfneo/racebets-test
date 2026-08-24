<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class CustomerNotFound extends \RuntimeException implements ApiException
{
    public static function withId(int $id): self
    {
        return new self(\sprintf('Customer %d does not exist.', $id));
    }

    public function statusCode(): int
    {
        return 404;
    }

    public function errorCode(): string
    {
        return 'customer_not_found';
    }

    public function details(): array
    {
        return [];
    }
}
