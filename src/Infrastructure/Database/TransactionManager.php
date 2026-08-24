<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

/**
 * Runs a unit of work inside a single database transaction, retrying the whole
 * closure if InnoDB rolls it back as a deadlock victim.
 *
 * Deposits and withdrawals lock exactly one customer row, so deadlocks are not
 * expected in practice — but "not expected" is not a guarantee, and the cost of
 * being wrong is a failed financial request. The retry is safe because the
 * closure is only ever re-run after a *full* rollback: no partial work survives.
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
                    // Brief, growing back-off so two contending requests do not
                    // immediately collide again.
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
            // The server already rolled the transaction back for us.
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
