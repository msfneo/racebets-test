<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reads configuration from the process environment.
 *
 * Under Docker Compose the variables are injected by the runtime; the optional
 * .env file only exists so the project can also be run against a MySQL server
 * you already have locally.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $file = [];

    private static bool $loaded = false;

    public static function load(string $path): void
    {
        self::$loaded = true;

        if (!\is_readable($path)) {
            return;
        }

        $lines = \file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);

        foreach ($lines ?: [] as $line) {
            $line = \trim($line);

            if ($line === '' || \str_starts_with($line, '#') || !\str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = \explode('=', $line, 2);

            self::$file[\trim($key)] = \trim(\trim($value), "\"'");
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = \getenv($key);

        if (\is_string($value) && $value !== '') {
            return $value;
        }

        return self::$file[$key] ?? $default;
    }

    public static function require(string $key): string
    {
        $value = self::get($key);

        if ($value === null || $value === '') {
            throw new \RuntimeException(\sprintf(
                'Missing required environment variable "%s".%s',
                $key,
                self::$loaded ? '' : ' No .env file has been loaded.',
            ));
        }

        return $value;
    }
}
