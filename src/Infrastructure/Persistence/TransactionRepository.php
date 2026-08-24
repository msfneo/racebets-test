<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Money;
use App\Domain\Transaction;
use App\Domain\TransactionType;

final class TransactionRepository
{
    private const COLUMNS = '
        id, customer_id, parent_id, type, amount,
        real_balance_after, bonus_balance_after, country, occurred_at
    ';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * Appends a ledger row. `amount` is signed: negative for withdrawals.
     */
    public function append(
        int $customerId,
        TransactionType $type,
        Money $amount,
        Money $realBalanceAfter,
        Money $bonusBalanceAfter,
        string $country,
        \DateTimeImmutable $occurredAt,
        ?int $parentId = null,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO transactions
                (customer_id, parent_id, type, amount, real_balance_after,
                 bonus_balance_after, country, occurred_at)
             VALUES
                (:customer_id, :parent_id, :type, :amount, :real_balance_after,
                 :bonus_balance_after, :country, :occurred_at)',
        );

        $statement->execute([
            'customer_id' => $customerId,
            'parent_id' => $parentId,
            'type' => $type->value,
            'amount' => $amount->minor,
            'real_balance_after' => $realBalanceAfter->minor,
            'bonus_balance_after' => $bonusBalanceAfter->minor,
            'country' => $country,
            'occurred_at' => $occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return list<Transaction>
     */
    public function forCustomer(int $customerId, int $limit, int $offset): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM transactions
              WHERE customer_id = :customer_id
              ORDER BY id DESC
              LIMIT :limit OFFSET :offset',
        );
        $statement->bindValue('customer_id', $customerId, \PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, \PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, \PDO::PARAM_INT);
        $statement->execute();

        return \array_map(self::hydrate(...), $statement->fetchAll());
    }

    public function countForCustomer(int $customerId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM transactions WHERE customer_id = :customer_id');
        $statement->execute(['customer_id' => $customerId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Deposit/withdrawal totals grouped by day and country.
     *
     * Bonus rows are excluded: a bonus is not money the customer deposited, so
     * counting it would overstate both the deposit count and the deposit total.
     * The unique-customer count spans both types, matching "unique customers
     * doing at least one deposit or withdrawal" from the specification.
     *
     * @param \DateTimeImmutable $from inclusive lower bound
     * @param \DateTimeImmutable $to   exclusive upper bound
     *
     * @return list<array{
     *     date: string, country: string, unique_customers: int,
     *     deposit_count: int, deposit_amount: int,
     *     withdrawal_count: int, withdrawal_amount: int
     * }>
     */
    public function report(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $statement = $this->pdo->prepare(
            "SELECT
                 occurred_on                                                       AS date,
                 country,
                 COUNT(DISTINCT customer_id)                                       AS unique_customers,
                 SUM(CASE WHEN type = 'deposit'    THEN 1 ELSE 0 END)              AS deposit_count,
                 COALESCE(SUM(CASE WHEN type = 'deposit'    THEN amount END), 0)   AS deposit_amount,
                 SUM(CASE WHEN type = 'withdrawal' THEN 1 ELSE 0 END)              AS withdrawal_count,
                 COALESCE(SUM(CASE WHEN type = 'withdrawal' THEN amount END), 0)   AS withdrawal_amount
               FROM transactions
              WHERE type IN ('deposit', 'withdrawal')
                AND occurred_at >= :from
                AND occurred_at <  :to
              GROUP BY occurred_on, country
              ORDER BY occurred_on DESC, country ASC",
        );

        $statement->execute([
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ]);

        return \array_map(
            static fn (array $row): array => [
                'date' => (string) $row['date'],
                'country' => (string) $row['country'],
                'unique_customers' => (int) $row['unique_customers'],
                'deposit_count' => (int) $row['deposit_count'],
                'deposit_amount' => (int) $row['deposit_amount'],
                'withdrawal_count' => (int) $row['withdrawal_count'],
                'withdrawal_amount' => (int) $row['withdrawal_amount'],
            ],
            $statement->fetchAll(),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Transaction
    {
        return new Transaction(
            id: (int) $row['id'],
            customerId: (int) $row['customer_id'],
            parentId: $row['parent_id'] === null ? null : (int) $row['parent_id'],
            type: TransactionType::from((string) $row['type']),
            amount: Money::fromMinor((int) $row['amount']),
            realBalanceAfter: Money::fromMinor((int) $row['real_balance_after']),
            bonusBalanceAfter: Money::fromMinor((int) $row['bonus_balance_after']),
            country: (string) $row['country'],
            occurredAt: new \DateTimeImmutable((string) $row['occurred_at'], new \DateTimeZone('UTC')),
        );
    }
}
