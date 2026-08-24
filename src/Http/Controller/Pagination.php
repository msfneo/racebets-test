<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\Exception\ValidationException;
use App\Http\Request;

final class Pagination
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;

    /**
     * @return array{0: int, 1: int} limit and offset
     *
     * @throws ValidationException
     */
    public static function fromRequest(Request $request): array
    {
        return [
            self::integer($request, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT),
            self::integer($request, 'offset', 0, 0, \PHP_INT_MAX),
        ];
    }

    private static function integer(Request $request, string $name, int $default, int $min, int $max): int
    {
        $raw = $request->queryParameter($name);

        if ($raw === null || $raw === '') {
            return $default;
        }

        if (\preg_match('/^\d{1,18}$/', $raw) !== 1) {
            throw ValidationException::forField($name, 'Must be a non-negative integer.');
        }

        $value = (int) $raw;

        if ($value < $min || $value > $max) {
            throw ValidationException::forField(
                $name,
                \sprintf('Must be between %d and %d.', $min, $max),
            );
        }

        return $value;
    }
}
