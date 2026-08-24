<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

/**
 * Applies /migrations in filename order and records what ran, so `migrate` is
 * idempotent and safe on every container boot.
 */
final class Migrator
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $directory,
    ) {
    }

    /**
     * @return list<string> the migrations applied by this call
     */
    public function migrate(): array
    {
        $this->ensureLedgerTable();

        $applied = [];

        foreach ($this->pending() as $file) {
            $sql = \file_get_contents($file);

            if ($sql === false) {
                throw new \RuntimeException(\sprintf('Cannot read migration "%s".', $file));
            }

            // DDL is not transactional in MySQL, so each migration is a single
            // statement, recorded only after it succeeds.
            $this->pdo->exec($sql);

            $name = \basename($file);
            $this->pdo
                ->prepare('INSERT INTO schema_migrations (migration, applied_at) VALUES (:migration, UTC_TIMESTAMP(6))')
                ->execute(['migration' => $name]);

            $applied[] = $name;
        }

        return $applied;
    }

    /** Drops every table. Used by the test suite; not reachable over HTTP. */
    public function reset(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $tables = $this->pdo
            ->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')
            ->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            $this->pdo->exec(\sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @return list<string> absolute paths, in application order */
    private function pending(): array
    {
        $done = $this->pdo
            ->query('SELECT migration FROM schema_migrations')
            ->fetchAll(\PDO::FETCH_COLUMN);

        $done = \array_flip($done);

        $files = \glob($this->directory . '/*.sql') ?: [];
        \sort($files, \SORT_STRING);

        return \array_values(\array_filter(
            $files,
            static fn (string $file): bool => !isset($done[\basename($file)]),
        ));
    }

    private function ensureLedgerTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                migration  VARCHAR(255) NOT NULL,
                applied_at DATETIME(6)  NOT NULL,
                PRIMARY KEY (migration)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4',
        );
    }
}
