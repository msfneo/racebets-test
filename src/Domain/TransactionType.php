<?php

declare(strict_types=1);

namespace App\Domain;

enum TransactionType: string
{
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';

    /**
     * Bonus money credited by the "every 3rd deposit" rule. Recorded as its own
     * ledger row rather than folded into the deposit amount, so that the report
     * can count and sum real deposits without subtracting bonuses back out.
     */
    case Bonus = 'bonus';
}
