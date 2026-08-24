<?php

declare(strict_types=1);

namespace App\Http\Exception;

use App\Domain\Exception\ApiException;

final class RouteNotFound extends \RuntimeException implements ApiException
{
    public static function for(string $method, string $path): self
    {
        return new self(\sprintf('No route matches %s %s.', $method, $path));
    }

    public function statusCode(): int
    {
        return 404;
    }

    public function errorCode(): string
    {
        return 'route_not_found';
    }

    public function details(): array
    {
        return [];
    }
}
