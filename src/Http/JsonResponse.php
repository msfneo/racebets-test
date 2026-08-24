<?php

declare(strict_types=1);

namespace App\Http;

final readonly class JsonResponse
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public static function ok(mixed $data, int $status = 200, array $headers = []): self
    {
        return new self($status, self::encode(['data' => $data]), $headers);
    }

    public static function created(mixed $data): self
    {
        return self::ok($data, 201);
    }

    /**
     * @param array<string, list<string>> $details
     * @param array<string, string>       $headers
     */
    public static function error(
        int $status,
        string $code,
        string $message,
        array $details = [],
        array $headers = [],
    ): self {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return new self($status, self::encode(['error' => $error]), $headers);
    }

    public function send(): void
    {
        \http_response_code($this->status);
        \header('Content-Type: application/json; charset=utf-8');

        foreach ($this->headers as $name => $value) {
            \header($name . ': ' . $value);
        }

        echo $this->body;
    }

    /**
     * Convenience accessor for tests.
     *
     * @return array<string, mixed>
     */
    public function decoded(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode($this->body, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private static function encode(mixed $payload): string
    {
        return \json_encode(
            $payload,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT,
        );
    }
}
