<?php

declare(strict_types=1);

namespace App\Domain;

enum TransactionType: string
{
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';

    /**
     * Its own row rather than folded into the deposit amount, so the report can
     * sum real deposits without subtracting bonuses back out.
     */
    case Bonus = 'bonus';
}
