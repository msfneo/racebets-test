<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Exception\ValidationException;

/**
 * A monetary amount in euro cents.
 *
 * Everything financial in this application is an integer number of minor units.
 * Floats never touch a balance: they cannot represent 0.10 exactly, and summing
 * them drifts. Amounts arriving over HTTP are parsed from their decimal string
 * representation straight into cents.
 */
final readonly class Money implements \JsonSerializable
{
    private const MINOR_PER_MAJOR = 100;

    /**
     * ~99 billion EUR. Far above any plausible amount, far below the point where
     * summing a BIGINT ledger column could overflow.
     */
    private const MAX_MINOR = 9_999_999_999_999;

    private const DECIMAL_PATTERN = '/^(?<whole>\d{1,13})(?:\.(?<fraction>\d{1,2}))?$/';

    private function __construct(public int $minor)
    {
    }

    public static function fromMinor(int $minor): self
    {
        if (\abs($minor) > self::MAX_MINOR) {
            throw new \InvalidArgumentException('Monetary amount is out of the supported range.');
        }

        return new self($minor);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parses a positive decimal amount supplied by a client.
     *
     * Accepts "100", "100.5", "100.50" and the equivalent integers. Floats are
     * tolerated for convenience but clients are encouraged to send strings —
     * see the README — because a JSON float has already lost precision by the
     * time it reaches us.
     *
     * @throws ValidationException when the value is not a well-formed positive amount
     */
    public static function parsePositive(mixed $value, string $field = 'amount'): self
    {
        $money = self::parse($value, $field);

        if (!$money->isPositive()) {
            throw ValidationException::forField($field, 'Amount must be greater than 0.');
        }

        return $money;
    }

    /**
     * @throws ValidationException
     */
    public static function parse(mixed $value, string $field = 'amount'): self
    {
        $raw = match (true) {
            \is_int($value) => (string) $value,
            \is_float($value) => self::floatToDecimalString($value),
            \is_string($value) => \trim($value),
            default => throw ValidationException::forField(
                $field,
                'Amount must be a decimal number, ideally sent as a string (e.g. "100.00").',
            ),
        };

        if (\preg_match(self::DECIMAL_PATTERN, $raw, $matches) !== 1) {
            throw ValidationException::forField(
                $field,
                'Amount must be a positive decimal with at most 2 decimal places (e.g. "100.00").',
            );
        }

        $fraction = \str_pad($matches['fraction'] ?? '', 2, '0');
        $minor = (int) $matches['whole'] * self::MINOR_PER_MAJOR + (int) $fraction;

        if ($minor > self::MAX_MINOR) {
            throw ValidationException::forField($field, 'Amount exceeds the maximum supported value.');
        }

        return new self($minor);
    }

    /**
     * Renders as a fixed-scale decimal string: "100.00", "-200.45", "0.00".
     */
    public function format(): string
    {
        $sign = $this->minor < 0 ? '-' : '';
        $absolute = \abs($this->minor);

        return \sprintf(
            '%s%d.%02d',
            $sign,
            \intdiv($absolute, self::MINOR_PER_MAJOR),
            $absolute % self::MINOR_PER_MAJOR,
        );
    }

    public function plus(self $other): self
    {
        return self::fromMinor($this->minor + $other->minor);
    }

    public function minus(self $other): self
    {
        return self::fromMinor($this->minor - $other->minor);
    }

    public function negated(): self
    {
        return new self(-$this->minor);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->minor > $other->minor;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor;
    }

    public function jsonSerialize(): string
    {
        return $this->format();
    }

    /**
     * Renders a float at 4 decimal places and trims the padding.
     *
     * Two extra digits are enough to absorb binary representation noise — the
     * 100.10 a client typed arrives as 100.09999999999999 and comes back out as
     * "100.1" — while still preserving a genuine third decimal, so "100.999" is
     * rejected by the pattern above instead of being silently rounded.
     */
    private static function floatToDecimalString(float $value): string
    {
        if (!\is_finite($value)) {
            return 'not-a-number';
        }

        return \rtrim(\rtrim(\sprintf('%.4F', $value), '0'), '.') ?: '0';
    }
}
