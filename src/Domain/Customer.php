<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class Customer implements \JsonSerializable
{
    public function __construct(
        public int $id,
        public Gender $gender,
        public string $firstName,
        public string $lastName,
        public string $country,
        public string $email,
        public int $bonusPercent,
        public Money $realBalance,
        public Money $bonusBalance,
        public int $depositCount,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    public function totalBalance(): Money
    {
        return $this->realBalance->plus($this->bonusBalance);
    }

    /** Bonus money is not withdrawable, so the ceiling is the real balance. */
    public function withdrawableBalance(): Money
    {
        return $this->realBalance;
    }

    /** Deposits still needed before the next bonus. */
    public function depositsUntilNextBonus(): int
    {
        $position = $this->depositCount % BonusPolicy::EVERY_NTH_DEPOSIT;

        return BonusPolicy::EVERY_NTH_DEPOSIT - $position;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'gender' => $this->gender->value,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'country' => $this->country,
            'email' => $this->email,
            'bonus_percent' => $this->bonusPercent,
            'balance' => [
                'currency' => 'EUR',
                'real' => $this->realBalance->format(),
                'bonus' => $this->bonusBalance->format(),
                'total' => $this->totalBalance()->format(),
                'withdrawable' => $this->withdrawableBalance()->format(),
            ],
            'deposit_count' => $this->depositCount,
            'deposits_until_next_bonus' => $this->depositsUntilNextBonus(),
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at' => $this->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
