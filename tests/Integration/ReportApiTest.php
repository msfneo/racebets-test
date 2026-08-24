<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Money;

final class ReportApiTest extends IntegrationTestCase
{
    public function testItGroupsDepositsAndWithdrawalsByDateAndCountry(): void
    {
        $maltese = $this->createCustomer(['country' => 'MT', 'email' => 'mt-1@example.com']);
        $otherMaltese = $this->createCustomer(['country' => 'MT', 'email' => 'mt-2@example.com']);
        $german = $this->createCustomer(['country' => 'DE', 'email' => 'de-1@example.com']);

        $this->clock->travelTo('2026-03-10 09:00:00');
        $this->deposit($maltese->id, '100.00');
        $this->deposit($maltese->id, '50.25');
        $this->deposit($otherMaltese->id, '20.00');
        $this->withdraw($maltese->id, '30.00');
        $this->deposit($german->id, '75.00');

        $this->clock->travelTo('2026-03-11 09:00:00');
        $this->deposit($german->id, '10.00');

        $rows = $this->reportRows('2026-03-10', '2026-03-11');

        self::assertCount(3, $rows);

        // Newest day first, then country ascending.
        self::assertSame(['2026-03-11', 'DE'], [$rows[0]['date'], $rows[0]['country']]);
        self::assertSame(['2026-03-10', 'DE'], [$rows[1]['date'], $rows[1]['country']]);
        self::assertSame(['2026-03-10', 'MT'], [$rows[2]['date'], $rows[2]['country']]);

        $malta = $rows[2];

        self::assertSame(2, $malta['unique_customers']);
        self::assertSame(3, $malta['deposits']['count']);
        self::assertSame('170.25', $malta['deposits']['amount']);
        self::assertSame(1, $malta['withdrawals']['count']);
        self::assertSame('-30.00', $malta['withdrawals']['amount'], 'Withdrawal totals are negative.');
    }

    /**
     * A customer who only withdraws still counts as a unique customer for the
     * day: the specification counts customers "doing at least one deposit or
     * withdrawal".
     */
    public function testUniqueCustomersSpansBothTransactionTypes(): void
    {
        $depositor = $this->createCustomer(['country' => 'IE', 'email' => 'dep@example.com']);
        $withdrawer = $this->createCustomer(['country' => 'IE', 'email' => 'wit@example.com']);

        $this->clock->travelTo('2026-03-12 08:00:00');
        $this->deposit($withdrawer->id, '80.00');

        $this->clock->travelTo('2026-03-13 08:00:00');
        $this->deposit($depositor->id, '10.00');
        $this->withdraw($withdrawer->id, '80.00');

        $rows = $this->reportRows('2026-03-13', '2026-03-13');

        self::assertCount(1, $rows);
        self::assertSame(2, $rows[0]['unique_customers']);
        self::assertSame(1, $rows[0]['deposits']['count']);
        self::assertSame(1, $rows[0]['withdrawals']['count']);
    }

    /**
     * Bonus money was never deposited by the customer, so it must not inflate
     * the deposit count or the deposit total.
     */
    public function testBonusRowsAreExcludedFromTheTotals(): void
    {
        $customer = $this->createCustomer(['country' => 'MT']);
        $this->setBonusPercent($customer->id, 20);

        $this->clock->travelTo('2026-03-14 10:00:00');
        $this->deposit($customer->id, '100.00');
        $this->deposit($customer->id, '100.00');
        $this->deposit($customer->id, '100.00'); // earns 20.00 bonus

        self::assertSame('20.00', $this->container->customerService()->get($customer->id)->bonusBalance->format());

        $rows = $this->reportRows('2026-03-14', '2026-03-14');

        self::assertSame(3, $rows[0]['deposits']['count'], 'The bonus is not a fourth deposit.');
        self::assertSame('300.00', $rows[0]['deposits']['amount'], 'The bonus is not deposited money.');
    }

    public function testTheDefaultWindowIsTheLastSevenDaysInclusive(): void
    {
        $customer = $this->createCustomer(['country' => 'MT']);

        $this->clock->travelTo('2026-03-15 12:00:00');

        // Today, the oldest day still inside the window, and one day too old.
        $this->depositOn($customer->id, '2026-03-15 06:00:00', '10.00');
        $this->depositOn($customer->id, '2026-03-09 06:00:00', '20.00');
        $this->depositOn($customer->id, '2026-03-08 23:59:59', '40.00');

        $this->clock->travelTo('2026-03-15 12:00:00');

        $response = $this->request('GET', '/reports/transactions');
        $data = $response->decoded()['data'];

        self::assertSame('2026-03-09', $data['from']);
        self::assertSame('2026-03-15', $data['to']);

        $dates = \array_column($data['rows'], 'date');

        self::assertContains('2026-03-15', $dates);
        self::assertContains('2026-03-09', $dates);
        self::assertNotContains('2026-03-08', $dates, 'The 8th falls outside a 7-day window ending on the 15th.');
    }

    public function testTheWindowBoundariesAreInclusive(): void
    {
        $customer = $this->createCustomer(['country' => 'MT']);

        // The very last moment of the closing day must still be included.
        $this->depositOn($customer->id, '2026-03-20 23:59:59', '15.00');
        $this->depositOn($customer->id, '2026-03-18 00:00:00', '25.00');

        $rows = $this->reportRows('2026-03-18', '2026-03-20');

        self::assertSame(['2026-03-20', '2026-03-18'], \array_column($rows, 'date'));
    }

    /**
     * A report is a historical record: changing a customer's country today must
     * not rewrite what was reported for last week.
     */
    public function testCountryIsSnapshotAtTransactionTime(): void
    {
        $customer = $this->createCustomer(['country' => 'MT']);

        $this->depositOn($customer->id, '2026-03-14 10:00:00', '100.00');

        $this->request('PATCH', '/customers/' . $customer->id, ['country' => 'DE']);

        $this->depositOn($customer->id, '2026-03-15 10:00:00', '50.00');

        $rows = $this->reportRows('2026-03-14', '2026-03-15');

        self::assertSame([['2026-03-15', 'DE'], ['2026-03-14', 'MT']], \array_map(
            static fn (array $row): array => [$row['date'], $row['country']],
            $rows,
        ));
    }

    public function testAnEmptyWindowReturnsNoRows(): void
    {
        $this->createCustomer();

        self::assertSame([], $this->reportRows('2026-01-01', '2026-01-31'));
    }

    /**
     * @return iterable<string, array{0: array<string, string>}>
     */
    public static function invalidWindows(): iterable
    {
        yield 'malformed from' => [['from' => '10-03-2026']];
        yield 'malformed to' => [['to' => 'yesterday']];
        yield 'impossible date' => [['from' => '2026-02-31']];
        yield 'inverted range' => [['from' => '2026-03-10', 'to' => '2026-03-01']];
        yield 'window too wide' => [['from' => '2000-01-01', 'to' => '2026-01-01']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidWindows')]
    public function testInvalidWindowsAreRejected(array $query): void
    {
        $response = $this->request('GET', '/reports/transactions', null, $query);

        self::assertSame(422, $response->status);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reportRows(string $from, string $to): array
    {
        $response = $this->request('GET', '/reports/transactions', null, ['from' => $from, 'to' => $to]);

        self::assertSame(200, $response->status);

        return $response->decoded()['data']['rows'];
    }

    private function deposit(int $customerId, string $amount): void
    {
        $this->container->transactionService()->deposit($customerId, Money::parsePositive($amount));
    }

    private function withdraw(int $customerId, string $amount): void
    {
        $this->container->transactionService()->withdraw($customerId, Money::parsePositive($amount));
    }

    private function depositOn(int $customerId, string $moment, string $amount): void
    {
        $this->clock->travelTo($moment);
        $this->deposit($customerId, $amount);
    }
}
