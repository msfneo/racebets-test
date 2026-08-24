<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Exception\MalformedRequest;

/**
 * A deliberately small request abstraction.
 *
 * It exists so controllers never read superglobals, which in turn is what lets
 * the functional tests drive the kernel in-process without a web server.
 */
final class Request
{
    /** @var array<string, mixed>|null */
    private ?array $decodedBody = null;

    /** @var array<string, string> */
    private array $pathParameters = [];

    /**
     * @param array<string, string|array<mixed>> $query
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        private readonly string $rawBody = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = \strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = \parse_url($uri, \PHP_URL_PATH);

        return new self(
            $method,
            \is_string($path) ? $path : '/',
            $_GET,
            (string) \file_get_contents('php://input'),
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    public function withPathParameters(array $parameters): self
    {
        $clone = clone $this;
        $clone->pathParameters = $parameters;
        $clone->decodedBody = $this->decodedBody;

        return $clone;
    }

    public function pathParameter(string $name): string
    {
        return $this->pathParameters[$name] ?? '';
    }

    public function queryParameter(string $name): ?string
    {
        $value = $this->query[$name] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * The decoded JSON body, or an empty array when no body was sent.
     *
     * @return array<string, mixed>
     *
     * @throws MalformedRequest
     */
    public function json(): array
    {
        if ($this->decodedBody !== null) {
            return $this->decodedBody;
        }

        $body = \trim($this->rawBody);

        if ($body === '') {
            return $this->decodedBody = [];
        }

        // Checked before decoding because json_decode(..., true) collapses both
        // `{}` and `[]` to an empty PHP array, which makes them indistinguishable
        // afterwards.
        if ($body[0] !== '{') {
            throw MalformedRequest::notAJsonObject();
        }

        try {
            $decoded = \json_decode($body, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw MalformedRequest::invalidJson($e->getMessage());
        }

        \assert(\is_array($decoded));

        /** @var array<string, mixed> $decoded */
        return $this->decodedBody = $decoded;
    }
}
