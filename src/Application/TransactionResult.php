<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Customer;
use App\Domain\Transaction;

/**
 * What a completed deposit or withdrawal produced: the ledger row, the bonus row
 * if the deposit earned one, and the customer's state afterwards.
 */
final readonly class TransactionResult implements \JsonSerializable
{
    public function __construct(
        public Transaction $transaction,
        public ?Transaction $bonus,
        public Customer $customer,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'transaction' => $this->transaction,
            'bonus' => $this->bonus,
            'customer' => $this->customer,
        ];
    }
}
