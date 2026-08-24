<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use App\Domain\Money;

final class InsufficientFunds extends \RuntimeException implements ApiException
{
    private function __construct(
        string $message,
        private readonly Money $requested,
        private readonly Money $withdrawable,
    ) {
        parent::__construct($message);
    }

    public static function forWithdrawal(Money $requested, Money $withdrawable): self
    {
        return new self(
            \sprintf(
                'Withdrawal of %s EUR exceeds the withdrawable balance of %s EUR. Bonus money cannot be withdrawn.',
                $requested->format(),
                $withdrawable->format(),
            ),
            $requested,
            $withdrawable,
        );
    }

    public function statusCode(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'insufficient_funds';
    }

    public function details(): array
    {
        return [
            'amount' => [
                \sprintf(
                    'Requested %s EUR but only %s EUR is withdrawable.',
                    $this->requested->format(),
                    $this->withdrawable->format(),
                ),
            ],
        ];
    }
}
