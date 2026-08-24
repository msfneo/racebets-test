<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The deposit bonus rules, in one place.
 */
final class BonusPolicy
{
    /** Every Nth deposit of a customer earns a bonus. */
    public const EVERY_NTH_DEPOSIT = 3;

    public const MIN_PERCENT = 5;
    public const MAX_PERCENT = 20;

    /**
     * Drawn once at registration and fixed for the lifetime of the customer.
     * random_int() is used rather than rand()/mt_rand() — it is the correct
     * default whenever a value has monetary consequences.
     */
    public static function randomPercent(): int
    {
        return \random_int(self::MIN_PERCENT, self::MAX_PERCENT);
    }

    /**
     * @param int $depositNumber the customer's 1-based deposit counter, i.e. this
     *                           deposit is their $depositNumber-th
     */
    public static function qualifiesForBonus(int $depositNumber): bool
    {
        return $depositNumber > 0 && $depositNumber % self::EVERY_NTH_DEPOSIT === 0;
    }

    /**
     * Bonus on a qualifying deposit, rounded half up to the nearest cent.
     *
     * Integer arithmetic throughout: 33 cents at 15% is 495/100 cents, which
     * rounds to 5 cents rather than depending on float behaviour.
     */
    public static function bonusFor(Money $deposit, int $percent): Money
    {
        if (!$deposit->isPositive()) {
            return Money::zero();
        }

        return Money::fromMinor(\intdiv($deposit->minor * $percent + 50, 100));
    }
}
