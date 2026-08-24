<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\BonusPolicy;
use App\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BonusPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{0: int,1: bool}>
     */
    public static function depositNumbers(): iterable
    {
        yield 'first deposit' => [1, false];
        yield 'second deposit' => [2, false];
        yield 'third deposit earns the bonus' => [3, true];
        yield 'fourth deposit' => [4, false];
        yield 'sixth deposit earns the bonus' => [6, true];
        yield 'ninth deposit earns the bonus' => [9, true];
        yield 'a zeroth deposit cannot exist' => [0, false];
    }

    #[DataProvider('depositNumbers')]
    public function testEveryThirdDepositQualifies(int $depositNumber, bool $expected): void
    {
        self::assertSame($expected, BonusPolicy::qualifiesForBonus($depositNumber));
    }

    /**
     * The worked example from the specification: 10% on a 100 EUR deposit means
     * the balance grows by 110 EUR in total.
     */
    public function testTheWorkedExampleFromTheSpecification(): void
    {
        $deposit = Money::parse('100.00');
        $bonus = BonusPolicy::bonusFor($deposit, 10);

        self::assertSame('10.00', $bonus->format());
        self::assertSame('110.00', $deposit->plus($bonus)->format());
    }

    /**
     * @return iterable<string, array{0: string, 1: int, 2: string}>
     */
    public static function bonusAmounts(): iterable
    {
        yield 'minimum rate' => ['100.00', 5, '5.00'];
        yield 'maximum rate' => ['100.00', 20, '20.00'];
        yield 'rounds half up' => ['0.33', 15, '0.05'];   // 4.95 cents
        yield 'rounds down below half' => ['0.10', 12, '0.01']; // 1.2 cents
        yield 'exact half rounds up' => ['0.10', 5, '0.01'];    // 0.5 cents
        yield 'small deposit, small bonus' => ['0.01', 5, '0.00'];
        yield 'awkward amount' => ['77.77', 17, '13.22']; // 1322.09 cents
    }

    #[DataProvider('bonusAmounts')]
    public function testBonusIsRoundedHalfUpToTheCent(string $deposit, int $percent, string $expected): void
    {
        self::assertSame($expected, BonusPolicy::bonusFor(Money::parse($deposit), $percent)->format());
    }

    public function testTheRandomRateAlwaysFallsInTheAdvertisedRange(): void
    {
        $seen = [];

        for ($i = 0; $i < 500; ++$i) {
            $percent = BonusPolicy::randomPercent();

            self::assertGreaterThanOrEqual(BonusPolicy::MIN_PERCENT, $percent);
            self::assertLessThanOrEqual(BonusPolicy::MAX_PERCENT, $percent);

            $seen[$percent] = true;
        }

        // Both ends of the range must be reachable, not just the middle.
        self::assertArrayHasKey(BonusPolicy::MIN_PERCENT, $seen);
        self::assertArrayHasKey(BonusPolicy::MAX_PERCENT, $seen);
    }
}
