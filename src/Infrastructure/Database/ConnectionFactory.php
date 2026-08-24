<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Support\Env;

final class ConnectionFactory
{
    public static function create(?string $database = null): \PDO
    {
        $dsn = \sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            Env::require('DB_HOST'),
            Env::get('DB_PORT', '3306'),
            $database ?? Env::require('DB_NAME'),
        );

        $pdo = new \PDO($dsn, Env::require('DB_USER'), Env::get('DB_PASSWORD', ''), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            // Real server-side prepares, not client-side interpolation.
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        // Pin the session to UTC so DATE() and the generated `occurred_on`
        // column agree with PHP whatever the server is configured for.
        $pdo->exec("SET time_zone = '+00:00'");

        // Reject silently-truncating writes.
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        return $pdo;
    }
}
