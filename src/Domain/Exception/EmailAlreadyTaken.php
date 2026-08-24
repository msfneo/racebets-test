<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class EmailAlreadyTaken extends \RuntimeException implements ApiException
{
    public static function forEmail(string $email): self
    {
        return new self(\sprintf('A customer with the email address "%s" already exists.', $email));
    }

    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'email_already_taken';
    }

    public function details(): array
    {
        return ['email' => ['This email address is already registered.']];
    }
}
