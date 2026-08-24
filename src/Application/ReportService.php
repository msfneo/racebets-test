<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Exception\ValidationException;
use App\Domain\Money;
use App\Infrastructure\Persistence\TransactionRepository;
use App\Support\Clock;

/**
 * Deposit and withdrawal totals per country and day.
 */
final readonly class ReportService
{
    /** Both bounds are inclusive dates, so this is the default window length. */
    public const DEFAULT_WINDOW_DAYS = 7;

    /** Guards against a client asking for an unbounded scan. */
    private const MAX_WINDOW_DAYS = 366;

    public function __construct(
        private TransactionRepository $ledger,
        private Clock $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function transactions(?string $fromInput, ?string $toInput): array
    {
        [$from, $to] = $this->resolveWindow($fromInput, $toInput);

        // `to` is inclusive for the caller, so the query runs up to the start of
        // the following day. Doing it this way — rather than 23:59:59 — keeps
        // transactions recorded in the last second of the day inside the window.
        $rows = $this->ledger->report($from, $to->modify('+1 day'));

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'currency' => 'EUR',
            'timezone' => 'UTC',
            'rows' => \array_map(
                static fn (array $row): array => [
                    'date' => $row['date'],
                    'country' => $row['country'],
                    'unique_customers' => $row['unique_customers'],
                    'deposits' => [
                        'count' => $row['deposit_count'],
                        'amount' => Money::fromMinor($row['deposit_amount'])->format(),
                    ],
                    'withdrawals' => [
                        'count' => $row['withdrawal_count'],
                        // Negative, as in the example report in the spec.
                        'amount' => Money::fromMinor($row['withdrawal_amount'])->format(),
                    ],
                ],
                $rows,
            ),
        ];
    }

    /**
     * Resolves the requested window, defaulting to the last 7 days: today plus
     * the six days before it, all in UTC.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} both at 00:00:00
     *
     * @throws ValidationException
     */
    private function resolveWindow(?string $fromInput, ?string $toInput): array
    {
        $errors = [];

        $today = $this->clock->now()->setTime(0, 0);

        $to = $toInput === null ? $today : self::parseDate($toInput, 'to', $errors);
        $from = $fromInput === null
            ? ($to ?? $today)->modify(\sprintf('-%d days', self::DEFAULT_WINDOW_DAYS - 1))
            : self::parseDate($fromInput, 'from', $errors);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        \assert($from instanceof \DateTimeImmutable && $to instanceof \DateTimeImmutable);

        if ($from > $to) {
            throw ValidationException::forField('from', 'The "from" date must not be after the "to" date.');
        }

        $days = (int) $from->diff($to)->days + 1;

        if ($days > self::MAX_WINDOW_DAYS) {
            throw ValidationException::forField(
                'from',
                \sprintf('The reporting window may not exceed %d days (requested %d).', self::MAX_WINDOW_DAYS, $days),
            );
        }

        return [$from, $to];
    }

    /**
     * @param array<string, list<string>> $errors
     *
     * @param-out array<string, list<string>> $errors
     */
    private static function parseDate(string $value, string $field, array &$errors): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            \trim($value),
            new \DateTimeZone('UTC'),
        );

        // createFromFormat is lenient about impossible dates like 2025-02-31,
        // which it rolls forward; the warning count is what catches those.
        $parseResult = \DateTimeImmutable::getLastErrors();

        if ($date === false || ($parseResult !== false && ($parseResult['warning_count'] + $parseResult['error_count']) > 0)) {
            $errors[$field][] = 'Must be a date in YYYY-MM-DD format.';

            return null;
        }

        return $date;
    }
}
