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
 * Every balance change runs in one transaction that opens by locking the
 * customer row, so concurrent requests for the same customer serialise instead
 * of interleaving their read-modify-write.
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

            // Read under the lock, so two concurrent deposits cannot both see
            // the same counter and both award or both skip the bonus.
            $depositNumber = $customer->depositCount + 1;

            $bonus = BonusPolicy::qualifiesForBonus($depositNumber)
                ? BonusPolicy::bonusFor($amount, $customer->bonusPercent)
                : Money::zero();

            $this->customers->creditDeposit($customerId, $amount, $bonus, $now);

            $realAfter = $customer->realBalance->plus($amount);
            $bonusBefore = $customer->bonusBalance;
            $bonusAfter = $bonusBefore->plus($bonus);

            // The deposit row records the state before the bonus and the bonus
            // row the state after, so replaying the ledger in order reproduces
            // the balances.
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

            // Bonus money is excluded: only real money is withdrawable.
            $withdrawable = $customer->withdrawableBalance();

            if ($amount->isGreaterThan($withdrawable)) {
                throw InsufficientFunds::forWithdrawal($amount, $withdrawable);
            }

            $now = $this->clock->now();

            if (!$this->customers->debitWithdrawal($customerId, $amount, $now)) {
                // Unreachable while the row lock is held; refusing is the only
                // safe outcome if it ever is reached.
                throw InsufficientFunds::forWithdrawal($amount, $withdrawable);
            }

            $realAfter = $withdrawable->minus($amount);
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
