<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Money;
use PHPUnit\Framework\Attributes\Group;

/**
 * "Financial operations need to be implemented in a way that ensures data
 * integrity also for situations where different transaction requests are made
 * at the same moment."
 *
 * Each test below launches genuinely parallel OS processes — separate PHP
 * interpreters with their own database connections — released together by a
 * shared start timestamp. An implementation that read a balance, decided in PHP,
 * and wrote the result back would fail these: the classic lost update and the
 * classic overdraft both need exactly this overlap to appear.
 */
#[Group('concurrency')]
final class ConcurrencyTest extends IntegrationTestCase
{
    private const WORKERS = 10;

    /** How far ahead the workers are released, leaving time for them to boot. */
    private const BARRIER_DELAY_SECONDS = 2.0;

    /**
     * Ten simultaneous withdrawals of 20.00 against a balance of 100.00.
     *
     * Exactly five may succeed. Without the row lock, several workers would all
     * read 100.00, all conclude the withdrawal is affordable, and the balance
     * would end up negative — which the CHECK constraint would then reject as a
     * hard 500 rather than a clean "insufficient funds".
     */
    public function testConcurrentWithdrawalsCannotOverdrawTheAccount(): void
    {
        $customer = $this->createCustomer();
        $this->container->transactionService()->deposit($customer->id, Money::parsePositive('100.00'));

        $exitCodes = $this->runWorkers('withdraw', $customer->id, '20.00', self::WORKERS);

        $applied = \count(\array_filter($exitCodes, static fn (int $code): bool => $code === 0));
        $refused = \count(\array_filter($exitCodes, static fn (int $code): bool => $code === 3));

        self::assertSame(0, self::WORKERS - $applied - $refused, 'No worker may fail unexpectedly.');
        self::assertSame(5, $applied, 'Exactly five withdrawals of 20.00 fit into a balance of 100.00.');
        self::assertSame(5, $refused);

        $row = $this->customerRow($customer->id);

        self::assertSame(0, (int) $row['real_balance'], 'The balance must land exactly on zero.');
        self::assertGreaterThanOrEqual(0, (int) $row['real_balance']);

        self::assertSame(5, $this->countTransactions($customer->id, 'withdrawal'), 'One ledger row per applied withdrawal.');
    }

    /**
     * Nine simultaneous deposits, which must produce exactly three bonuses —
     * on the 3rd, 6th and 9th.
     *
     * This is the read-modify-write that is easiest to get wrong: `deposit_count`
     * is read to decide whether a bonus is due. If two workers can read the same
     * counter value, bonuses are duplicated or skipped and the money is simply
     * wrong.
     */
    public function testConcurrentDepositsAwardTheBonusExactlyEveryThirdTime(): void
    {
        $customer = $this->createCustomer();
        $this->setBonusPercent($customer->id, 10);

        $exitCodes = $this->runWorkers('deposit', $customer->id, '30.00', 9);

        self::assertSame([0], \array_values(\array_unique($exitCodes)), 'Every deposit must succeed.');

        $row = $this->customerRow($customer->id);

        self::assertSame(9, (int) $row['deposit_count']);
        self::assertSame(27_000, (int) $row['real_balance'], '9 x 30.00 = 270.00.');
        self::assertSame(900, (int) $row['bonus_balance'], 'Three bonuses of 10% on 30.00 = 9.00.');

        self::assertSame(9, $this->countTransactions($customer->id, 'deposit'));
        self::assertSame(3, $this->countTransactions($customer->id, 'bonus'));
    }

    /**
     * Deposits and withdrawals interleaved against the same account.
     *
     * The assertion is the invariant that matters: whatever order they land in,
     * the stored balance equals the sum of the ledger and never went negative.
     */
    public function testMixedTrafficKeepsTheLedgerAndTheBalanceInAgreement(): void
    {
        $customer = $this->createCustomer();
        $this->setBonusPercent($customer->id, 10);
        $this->container->transactionService()->deposit($customer->id, Money::parsePositive('100.00'));

        $processes = [];
        $startAt = \microtime(true) + self::BARRIER_DELAY_SECONDS;

        for ($i = 0; $i < 12; ++$i) {
            $action = $i % 2 === 0 ? 'deposit' : 'withdraw';
            $processes[] = $this->startWorker($action, $customer->id, '25.00', $startAt);
        }

        $exitCodes = \array_map($this->awaitWorker(...), $processes);

        self::assertNotContains(1, $exitCodes, 'No worker may fail unexpectedly.');

        $row = $this->customerRow($customer->id);

        self::assertGreaterThanOrEqual(0, (int) $row['real_balance']);

        $statement = self::connection()->prepare(
            "SELECT
                 COALESCE(SUM(CASE WHEN type <> 'bonus' THEN amount END), 0) AS real_sum,
                 COALESCE(SUM(CASE WHEN type  = 'bonus' THEN amount END), 0) AS bonus_sum,
                 SUM(CASE WHEN type = 'deposit' THEN 1 ELSE 0 END)           AS deposits
               FROM transactions WHERE customer_id = :id",
        );
        $statement->execute(['id' => $customer->id]);
        $sums = $statement->fetch();

        self::assertSame((int) $sums['real_sum'], (int) $row['real_balance'], 'Ledger and balance must agree.');
        self::assertSame((int) $sums['bonus_sum'], (int) $row['bonus_balance']);
        self::assertSame((int) $sums['deposits'], (int) $row['deposit_count']);

        // Every third deposit — and only every third — earned a bonus.
        self::assertSame(
            \intdiv((int) $sums['deposits'], 3),
            $this->countTransactions($customer->id, 'bonus'),
        );
    }

    /**
     * @return list<int> exit codes
     */
    private function runWorkers(string $action, int $customerId, string $amount, int $count): array
    {
        $startAt = \microtime(true) + self::BARRIER_DELAY_SECONDS;

        $processes = [];
        for ($i = 0; $i < $count; ++$i) {
            $processes[] = $this->startWorker($action, $customerId, $amount, $startAt);
        }

        return \array_map($this->awaitWorker(...), $processes);
    }

    /**
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function startWorker(string $action, int $customerId, string $amount, float $startAt): array
    {
        $command = \sprintf(
            '%s %s %s %d %s %.6F',
            \escapeshellarg(\PHP_BINARY),
            \escapeshellarg(\dirname(__DIR__) . '/Support/worker.php'),
            \escapeshellarg($action),
            $customerId,
            \escapeshellarg($amount),
            $startAt,
        );

        $pipes = [];
        $process = \proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname(__DIR__, 2),
            self::workerEnvironment(),
        );

        self::assertIsResource($process, 'Failed to start a worker process.');

        return [$process, $pipes];
    }

    /**
     * The suite redirects DB_NAME to the test schema at bootstrap, so the
     * workers need that value passed on rather than the ambient one. Built
     * explicitly because proc_open only accepts string values, and $_SERVER
     * carries `argv` as an array.
     *
     * @return array<string, string>
     */
    private static function workerEnvironment(): array
    {
        $environment = [];

        foreach (['PATH', 'DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASSWORD', 'DB_NAME'] as $name) {
            $value = \getenv($name);

            if (\is_string($value)) {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }

    /**
     * @param array{0: resource, 1: array<int, resource>} $worker
     */
    private function awaitWorker(array $worker): int
    {
        [$process, $pipes] = $worker;

        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);

        \fclose($pipes[1]);
        \fclose($pipes[2]);

        $exitCode = \proc_close($process);

        if ($exitCode === 1) {
            self::fail(\sprintf("A worker failed unexpectedly.\nstdout: %s\nstderr: %s", $stdout, $stderr));
        }

        return $exitCode;
    }

    private function countTransactions(int $customerId, string $type): int
    {
        $statement = self::connection()->prepare(
            'SELECT COUNT(*) FROM transactions WHERE customer_id = :id AND type = :type',
        );
        $statement->execute(['id' => $customerId, 'type' => $type]);

        return (int) $statement->fetchColumn();
    }
}
