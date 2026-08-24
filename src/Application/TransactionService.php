<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\BonusPolicy;
use App\Domain\Exception\CustomerNotFound;
use App\Domain\Exception\InsufficientFunds;
use App\Domain\Money;
use App\Domain\Transaction;
use App\Domain\TransactionType;
use App\Infrastructure\Database\TransactionManager;
use App\Infrastructure\Persistence\CustomerRepository;
use App\Infrastructure\Persistence\TransactionRepository;
use App\Support\Clock;

/**
 * Deposits and withdrawals.
 *
 * Every balance change happens inside one database transaction that begins by
 * taking an exclusive lock on the customer row (SELECT ... FOR UPDATE). Two
 * requests for the same customer therefore run strictly one after the other:
 * the second one only reads `deposit_count` and `real_balance` after the first
 * has committed. Requests for *different* customers never contend, because the
 * lock is on a single row rather than the table.
 *
 * Three further layers back this up, so that a mistake anywhere in the chain
 * fails loudly rather than corrupting a balance:
 *
 *   1. Balances are written as relative UPDATEs (`balance = balance + :x`), not
 *      as an absolute value computed in PHP, so a lost update is impossible.
 *   2. The withdrawal UPDATE carries an `AND real_balance >= :amount` predicate
 *      and reports how many rows it touched.
 *   3. The `real_balance >= 0` CHECK constraint in the schema is the final
 *      backstop at the storage layer.
 */
final readonly class TransactionService
{
    public function __construct(
        private TransactionManager $unitOfWork,
        private CustomerRepository $customers,
        private TransactionRepository $ledger,
        private Clock $clock,
    ) {
    }

    /**
     * @throws CustomerNotFound
     */
    public function deposit(int $customerId, Money $amount): TransactionResult
    {
        return $this->unitOfWork->transactional(function () use ($customerId, $amount): TransactionResult {
            $customer = $this->customers->findForUpdate($customerId)
                ?? throw CustomerNotFound::withId($customerId);

            $now = $this->clock->now();

            // The counter is read under the lock, so two concurrent deposits
            // cannot both see the same value and both award (or both skip) the
            // 3rd-deposit bonus.
            $depositNumber = $customer->depositCount + 1;

            $bonus = BonusPolicy::qualifiesForBonus($depositNumber)
                ? BonusPolicy::bonusFor($amount, $customer->bonusPercent)
                : Money::zero();

            $this->customers->creditDeposit($customerId, $amount, $bonus, $now);

            $realAfter = $customer->realBalance->plus($amount);
            $bonusBefore = $customer->bonusBalance;
            $bonusAfter = $bonusBefore->plus($bonus);

            // The deposit row records the state before the bonus is applied and
            // the bonus row the state after, so replaying the ledger in order
            // reproduces the balances exactly.
            $depositId = $this->ledger->append(
                customerId: $customerId,
                type: TransactionType::Deposit,
                amount: $amount,
                realBalanceAfter: $realAfter,
                bonusBalanceAfter: $bonusBefore,
                country: $customer->country,
                occurredAt: $now,
            );

            $deposit = new Transaction(
                $depositId, $customerId, null, TransactionType::Deposit,
                $amount, $realAfter, $bonusBefore, $customer->country, $now,
            );

            $bonusTransaction = null;

            if ($bonus->isPositive()) {
                $bonusId = $this->ledger->append(
                    customerId: $customerId,
                    type: TransactionType::Bonus,
                    amount: $bonus,
                    realBalanceAfter: $realAfter,
                    bonusBalanceAfter: $bonusAfter,
                    country: $customer->country,
                    occurredAt: $now,
                    parentId: $depositId,
                );

                $bonusTransaction = new Transaction(
                    $bonusId, $customerId, $depositId, TransactionType::Bonus,
                    $bonus, $realAfter, $bonusAfter, $customer->country, $now,
                );
            }

            return new TransactionResult(
                $deposit,
                $bonusTransaction,
                $this->customers->find($customerId) ?? throw CustomerNotFound::withId($customerId),
            );
        });
    }

    /**
     * @throws CustomerNotFound
     * @throws InsufficientFunds
     */
    public function withdraw(int $customerId, Money $amount): TransactionResult
    {
        return $this->unitOfWork->transactional(function () use ($customerId, $amount): TransactionResult {
            $customer = $this->customers->findForUpdate($customerId)
                ?? throw CustomerNotFound::withId($customerId);

            // Only real money is withdrawable; the bonus balance is excluded.
            $withdrawable = $customer->withdrawableBalance();

            if ($amount->isGreaterThan($withdrawable)) {
                throw InsufficientFunds::forWithdrawal($amount, $withdrawable);
            }

            $now = $this->clock->now();

            if (!$this->customers->debitWithdrawal($customerId, $amount, $now)) {
                // Unreachable while the row lock above is held. If it ever does
                // happen, refusing the withdrawal is the only correct outcome.
                throw InsufficientFunds::forWithdrawal($amount, $withdrawable);
            }

            $realAfter = $withdrawable->minus($amount);

            // Stored negative, matching the sign convention of the report.
            $signedAmount = $amount->negated();

            $id = $this->ledger->append(
                customerId: $customerId,
                type: TransactionType::Withdrawal,
                amount: $signedAmount,
                realBalanceAfter: $realAfter,
                bonusBalanceAfter: $customer->bonusBalance,
                country: $customer->country,
                occurredAt: $now,
            );

            $withdrawal = new Transaction(
                $id, $customerId, null, TransactionType::Withdrawal,
                $signedAmount, $realAfter, $customer->bonusBalance, $customer->country, $now,
            );

            return new TransactionResult(
                $withdrawal,
                null,
                $this->customers->find($customerId) ?? throw CustomerNotFound::withId($customerId),
            );
        });
    }

    /**
     * @return array{items: list<Transaction>, total: int, limit: int, offset: int}
     *
     * @throws CustomerNotFound
     */
    public function history(int $customerId, int $limit, int $offset): array
    {
        if ($this->customers->find($customerId) === null) {
            throw CustomerNotFound::withId($customerId);
        }

        return [
            'items' => $this->ledger->forCustomer($customerId, $limit, $offset),
            'total' => $this->ledger->countForCustomer($customerId),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }
}
