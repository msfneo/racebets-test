<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Exception\ValidationException;

/**
 * A monetary amount in euro cents.
 *
 * Integer minor units throughout: a float cannot represent 0.10 exactly and
 * accumulates error as it is summed.
 */
final readonly class Money implements \JsonSerializable
{
    private const MINOR_PER_MAJOR = 100;

    /** ~99 billion EUR: well above any real amount, well below BIGINT overflow. */
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
     * @throws ValidationException
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
     * Accepts "100", "100.5", "100.50" and the equivalent integers. Floats are
     * tolerated but clients should send strings: a JSON float has already lost
     * precision before it reaches us.
     *
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

    /** Fixed scale of two: "100.00", "-200.45", "0.00". */
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
     * Two extra digits absorb binary representation noise — the 100.10 a client
     * typed arrives as 100.09999999999999 — while still preserving a genuine
     * third decimal, so "100.999" is rejected rather than silently rounded.
     */
    private static function floatToDecimalString(float $value): string
    {
        if (!\is_finite($value)) {
            return 'not-a-number';
        }

        return \rtrim(\rtrim(\sprintf('%.4F', $value), '0'), '.') ?: '0';
    }
}
