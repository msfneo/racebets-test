<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Customer;
use App\Domain\Transaction;

/** The ledger row, the bonus row if one was earned, and the resulting customer. */
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
