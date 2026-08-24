<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Customer;
use App\Domain\Exception\EmailAlreadyTaken;
use App\Domain\Gender;
use App\Domain\Money;

/**
 * Raw SQL over PDO — no ORM, no query builder.
 */
final class CustomerRepository
{
    private const COLUMNS = '
        id, gender, first_name, last_name, country, email, bonus_percent,
        real_balance, bonus_balance, deposit_count, created_at, updated_at
    ';

    /** @var list<string> the only columns PATCH /customers/{id} may write */
    private const UPDATABLE_COLUMNS = ['gender', 'first_name', 'last_name', 'country', 'email'];

    /** Duplicate entry for key. */
    private const ER_DUP_ENTRY = '23000';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @throws EmailAlreadyTaken
     */
    public function insert(
        Gender $gender,
        string $firstName,
        string $lastName,
        string $country,
        string $email,
        int $bonusPercent,
        \DateTimeImmutable $now,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO customers
                (gender, first_name, last_name, country, email, bonus_percent, created_at, updated_at)
             VALUES
                (:gender, :first_name, :last_name, :country, :email, :bonus_percent, :created_at, :updated_at)',
        );

        try {
            $statement->execute([
                'gender' => $gender->value,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'country' => $country,
                'email' => $email,
                'bonus_percent' => $bonusPercent,
                'created_at' => self::formatTimestamp($now),
                'updated_at' => self::formatTimestamp($now),
            ]);
        } catch (\PDOException $e) {
            // Relying on the unique index rather than a prior SELECT is what
            // makes this safe against two simultaneous registrations of the
            // same address: the database is the single arbiter.
            if (($e->errorInfo[0] ?? null) === self::ER_DUP_ENTRY) {
                throw EmailAlreadyTaken::forEmail($email);
            }

            throw $e;
        }

        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $id): ?Customer
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM customers WHERE id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return \is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * Loads a customer and holds an exclusive row lock until the surrounding
     * transaction ends.
     *
     * This is the core of the concurrency guarantee: two deposits for the same
     * customer serialise here, so the read of `deposit_count` and the write of
     * the new balance cannot interleave. Callers must already be inside a
     * transaction, otherwise the lock is released immediately and the guarantee
     * is void — hence the explicit check.
     */
    public function findForUpdate(int $id): ?Customer
    {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('findForUpdate() must be called inside a database transaction.');
        }

        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM customers WHERE id = :id FOR UPDATE',
        );
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return \is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * @param array<string, string|int> $fields already-validated column values
     *
     * @throws EmailAlreadyTaken
     */
    public function updateDetails(int $id, array $fields, \DateTimeImmutable $now): void
    {
        if ($fields === []) {
            return;
        }

        $assignments = [];
        foreach (\array_keys($fields) as $column) {
            // Column names cannot be bound as parameters, so the set of columns
            // an update may touch is fixed here rather than trusted from the
            // caller. bonus_percent is deliberately absent: it is drawn once at
            // registration and is not editable.
            if (!\in_array($column, self::UPDATABLE_COLUMNS, true)) {
                throw new \LogicException(\sprintf('Column "%s" is not updatable.', $column));
            }

            $assignments[] = \sprintf('%s = :%s', $column, $column);
        }
        $assignments[] = 'updated_at = :updated_at';

        $statement = $this->pdo->prepare(
            'UPDATE customers SET ' . \implode(', ', $assignments) . ' WHERE id = :id',
        );

        try {
            $statement->execute([...$fields, 'updated_at' => self::formatTimestamp($now), 'id' => $id]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? null) === self::ER_DUP_ENTRY) {
                throw EmailAlreadyTaken::forEmail((string) ($fields['email'] ?? ''));
            }

            throw $e;
        }
    }

    /**
     * Credits a deposit and, when the rule applies, its bonus.
     *
     * The balances are incremented with a relative UPDATE rather than written as
     * an absolute value computed in PHP. Combined with the row lock taken by
     * findForUpdate(), that removes any possibility of a lost update.
     */
    public function creditDeposit(int $id, Money $real, Money $bonus, \DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE customers
                SET real_balance  = real_balance + :real,
                    bonus_balance = bonus_balance + :bonus,
                    deposit_count = deposit_count + 1,
                    updated_at    = :updated_at
              WHERE id = :id',
        );

        $statement->execute([
            'real' => $real->minor,
            'bonus' => $bonus->minor,
            'updated_at' => self::formatTimestamp($now),
            'id' => $id,
        ]);
    }

    /**
     * Debits a withdrawal.
     *
     * The `real_balance >= :amount` predicate is redundant while the caller holds
     * the row lock, and that is exactly the point: if a future refactor ever
     * loses the lock, this writes zero rows instead of overdrawing the account.
     *
     * @return bool false when the balance was insufficient
     */
    public function debitWithdrawal(int $id, Money $amount, \DateTimeImmutable $now): bool
    {
        // :amount and :minimum carry the same value but need distinct names:
        // emulated prepares are switched off, so the statement goes to MySQL as
        // positional parameters and a name cannot appear twice.
        $statement = $this->pdo->prepare(
            'UPDATE customers
                SET real_balance = real_balance - :amount,
                    updated_at   = :updated_at
              WHERE id = :id
                AND real_balance >= :minimum',
        );

        $statement->execute([
            'amount' => $amount->minor,
            'minimum' => $amount->minor,
            'updated_at' => self::formatTimestamp($now),
            'id' => $id,
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * @return list<Customer>
     */
    public function all(int $limit, int $offset): array
    {
        // Bound as integers rather than interpolated; LIMIT placeholders need
        // explicit PDO::PARAM_INT because emulated prepares are switched off.
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM customers ORDER BY id DESC LIMIT :limit OFFSET :offset',
        );
        $statement->bindValue('limit', $limit, \PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, \PDO::PARAM_INT);
        $statement->execute();

        return \array_map(self::hydrate(...), $statement->fetchAll());
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Customer
    {
        return new Customer(
            id: (int) $row['id'],
            gender: Gender::from((string) $row['gender']),
            firstName: (string) $row['first_name'],
            lastName: (string) $row['last_name'],
            country: (string) $row['country'],
            email: (string) $row['email'],
            bonusPercent: (int) $row['bonus_percent'],
            realBalance: Money::fromMinor((int) $row['real_balance']),
            bonusBalance: Money::fromMinor((int) $row['bonus_balance']),
            depositCount: (int) $row['deposit_count'],
            createdAt: self::parseTimestamp((string) $row['created_at']),
            updatedAt: self::parseTimestamp((string) $row['updated_at']),
        );
    }

    private static function formatTimestamp(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parseTimestamp(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
