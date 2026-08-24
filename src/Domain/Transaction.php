<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class Transaction implements \JsonSerializable
{
    public function __construct(
        public int $id,
        public int $customerId,
        public ?int $parentId,
        public TransactionType $type,
        public Money $amount,
        public Money $realBalanceAfter,
        public Money $bonusBalanceAfter,
        public string $country,
        public \DateTimeImmutable $occurredAt,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customerId,
            'parent_id' => $this->parentId,
            'type' => $this->type->value,
            'currency' => 'EUR',
            'amount' => $this->amount->format(),
            'balance_after' => [
                'real' => $this->realBalanceAfter->format(),
                'bonus' => $this->bonusBalanceAfter->format(),
                'total' => $this->realBalanceAfter->plus($this->bonusBalanceAfter)->format(),
            ],
            'country' => $this->country,
            'occurred_at' => $this->occurredAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
