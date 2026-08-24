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
            // Any driver problem becomes an exception rather than a silent false.
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            // Real prepared statements: the placeholders are sent to the server
            // rather than interpolated client-side.
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        // The application reasons entirely in UTC; pin the session so that
        // NOW(), DATE() and the generated `occurred_on` column agree with PHP
        // regardless of how the server is configured.
        $pdo->exec("SET time_zone = '+00:00'");

        // Reject silently-truncating writes outright.
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        return $pdo;
    }
}
