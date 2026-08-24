<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class ValidationException extends \RuntimeException implements ApiException
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(
        private readonly array $errors,
        string $message = 'The request payload failed validation.',
    ) {
        parent::__construct($message);
    }

    public static function forField(string $field, string $message): self
    {
        return new self([$field => [$message]]);
    }

    public function statusCode(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'validation_failed';
    }

    public function details(): array
    {
        return $this->errors;
    }
}
