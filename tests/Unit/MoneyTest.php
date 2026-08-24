<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Exception\ValidationException;
use App\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    /**
     * @return iterable<string, array{0: mixed, 1: int}>
     */
    public static function acceptedAmounts(): iterable
    {
        yield 'integer euros as string' => ['100', 10_000];
        yield 'one decimal place' => ['100.5', 10_050];
        yield 'two decimal places' => ['100.50', 10_050];
        yield 'cents only' => ['0.07', 7];
        yield 'native integer' => [250, 25_000];
        yield 'surrounding whitespace' => ['  12.34  ', 1_234];
        yield 'the largest supported amount' => ['99999999999.99', 9_999_999_999_999];
    }

    #[DataProvider('acceptedAmounts')]
    public function testItParsesDecimalAmountsIntoMinorUnits(mixed $input, int $expectedMinor): void
    {
        self::assertSame($expectedMinor, Money::parse($input)->minor);
    }

    /**
     * A float that reaches PHP as 100.09999999999999 must still be understood as
     * 100.10, while a genuine third decimal must still be rejected.
     */
    public function testItAbsorbsFloatRepresentationNoiseWithoutRoundingRealPrecision(): void
    {
        self::assertSame(10_010, Money::parse(100.10)->minor);
        self::assertSame(30, Money::parse(0.1 + 0.2)->minor);

        $this->expectException(ValidationException::class);
        Money::parse(100.999);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function rejectedAmounts(): iterable
    {
        yield 'three decimal places' => ['1.234'];
        yield 'negative' => ['-5.00'];
        yield 'not a number' => ['abc'];
        yield 'empty string' => [''];
        yield 'thousands separator' => ['1,000.00'];
        yield 'scientific notation' => ['1e3'];
        yield 'boolean' => [true];
        yield 'array' => [[]];
        yield 'null' => [null];
        yield 'beyond the supported range' => ['99999999999999.00'];
    }

    #[DataProvider('rejectedAmounts')]
    public function testItRejectsMalformedAmounts(mixed $input): void
    {
        $this->expectException(ValidationException::class);

        Money::parse($input);
    }

    public function testParsePositiveRejectsZero(): void
    {
        self::assertSame(0, Money::parse('0')->minor);

        $this->expectException(ValidationException::class);
        Money::parsePositive('0.00');
    }

    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function formattedAmounts(): iterable
    {
        yield 'zero' => [0, '0.00'];
        yield 'cents only' => [7, '0.07'];
        yield 'tens of cents' => [70, '0.70'];
        yield 'whole euros' => [10_000, '100.00'];
        yield 'negative, as withdrawals are stored' => [-20_045, '-200.45'];
        yield 'negative cents' => [-5, '-0.05'];
    }

    #[DataProvider('formattedAmounts')]
    public function testItFormatsWithAFixedScaleOfTwo(int $minor, string $expected): void
    {
        self::assertSame($expected, Money::fromMinor($minor)->format());
    }

    public function testArithmeticStaysInIntegerMinorUnits(): void
    {
        $balance = Money::parse('110.00');
        $bonus = Money::parse('10.00');

        self::assertSame('100.00', $balance->minus($bonus)->format());
        self::assertSame('120.00', $balance->plus($bonus)->format());
        self::assertSame('-110.00', $balance->negated()->format());

        self::assertTrue($balance->isGreaterThan($bonus));
        self::assertFalse($bonus->isGreaterThan($balance));
        self::assertTrue(Money::parse('10.00')->equals($bonus));
    }

    /**
     * Summing a tenth of a euro ten times is exactly one euro — the reason
     * balances are integers rather than floats.
     */
    public function testRepeatedAdditionDoesNotDrift(): void
    {
        $total = Money::zero();

        for ($i = 0; $i < 10; ++$i) {
            $total = $total->plus(Money::parse('0.10'));
        }

        self::assertSame('1.00', $total->format());
        self::assertSame(100, $total->minor);
    }

    public function testItSerialisesAsAFixedScaleString(): void
    {
        self::assertSame('"12.30"', json_encode(Money::fromMinor(1_230)));
    }
}
