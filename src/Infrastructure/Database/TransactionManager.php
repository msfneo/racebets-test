<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

/**
 * Runs a unit of work in one transaction, retrying if InnoDB picks it as a
 * deadlock victim. Safe to retry because a retry only follows a full rollback.
 */
final class TransactionManager
{
    private const MAX_ATTEMPTS = 3;

    /** Deadlock found when trying to get lock. */
    private const ER_LOCK_DEADLOCK = '40001';

    /** Lock wait timeout exceeded. */
    private const ER_LOCK_WAIT_TIMEOUT = 'HY000';
    private const ER_LOCK_WAIT_TIMEOUT_DRIVER_CODE = 1205;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @template T
     *
     * @param callable(\PDO): T $work
     *
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        $attempt = 0;

        while (true) {
            ++$attempt;
            $this->pdo->beginTransaction();

            try {
                $result = $work($this->pdo);
                $this->pdo->commit();

                return $result;
            } catch (\Throwable $e) {
                $this->rollBackQuietly();

                if ($attempt < self::MAX_ATTEMPTS && $this->isRetryable($e)) {
                    // Growing back-off, so contending requests do not collide again.
                    \usleep($attempt * 10_000);

                    continue;
                }

                throw $e;
            }
        }
    }

    private function rollBackQuietly(): void
    {
        if (!$this->pdo->inTransaction()) {
            return;
        }

        try {
            $this->pdo->rollBack();
        } catch (\PDOException) {
            // Already rolled back server-side.
        }
    }

    private function isRetryable(\Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }

        $sqlState = $e->errorInfo[0] ?? $e->getCode();
        $driverCode = $e->errorInfo[1] ?? null;

        if ($sqlState === self::ER_LOCK_DEADLOCK) {
            return true;
        }

        return $sqlState === self::ER_LOCK_WAIT_TIMEOUT
            && $driverCode === self::ER_LOCK_WAIT_TIMEOUT_DRIVER_CODE;
    }
}
