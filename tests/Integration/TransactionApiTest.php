<?php

declare(strict_types=1);

namespace App\Tests\Integration;

final class TransactionApiTest extends IntegrationTestCase
{
    public function testADepositCreditsTheRealBalance(): void
    {
        $customer = $this->createCustomer();

        $response = $this->request('POST', "/customers/{$customer->id}/deposits", ['amount' => '100.00']);

        self::assertSame(201, $response->status);

        $data = $response->decoded()['data'];

        self::assertSame('deposit', $data['transaction']['type']);
        self::assertSame('100.00', $data['transaction']['amount']);
        self::assertNull($data['bonus'], 'The first deposit does not earn a bonus.');
        self::assertSame('100.00', $data['customer']['balance']['real']);
        self::assertSame('0.00', $data['customer']['balance']['bonus']);
    }

    /**
     * The worked example from the specification: a customer on 10% depositing
     * 100 EUR as their 3rd deposit sees their balance grow by 110 EUR.
     */
    public function testEveryThirdDepositIsAwardedTheBonus(): void
    {
        $customer = $this->createCustomer();
        $this->setBonusPercent($customer->id, 10);

        $first = $this->request('POST', "/customers/{$customer->id}/deposits", ['amount' => '100.00']);
        $second = $this->request('POST', "/customers/{$customer->id}/deposits", ['amount' => '100.00']);
        $third = $this->request('POST', "/customers/{$customer->id}/deposits", ['amount' => '100.00']);

        self::assertNull($first->decoded()['data']['bonus']);
        self::assertNull($second->decoded()['data']['bonus']);

        $bonus = $third->decoded()['data']['bonus'];

        self::assertNotNull($bonus);
        self::assertSame('bonus', $bonus['type']);
        self::assertSame('10.00', $bonus['amount']);
        self::assertSame($third->decoded()['data']['transaction']['id'], $bonus['parent_id']);

        $balance = $third->decoded()['data']['customer']['balance'];

        self::assertSame('300.00', $balance['real']);
        self::assertSame('10.00', $balance['bonus']);
        self::assertSame('310.00', $balance['total']);
        self::assertSame('300.00', $balance['withdrawable'], 'Bonus money is not withdrawable.');
    }

    public function testTheBonusRecursEveryThirdDeposit(): void
    {
        $customer = $this->createCustomer();
        $this->setBonusPercent($customer->id, 20);

        $awarded = [];

        for ($i = 1; $i <= 7; ++$i) {
            $response = $this->request('POST', "/customers/{$customer->id}/deposits", ['amount' => '50.00']);

            if ($response->decoded()['data']['bonus'] !== null) {
                $awarded[] = $i;
            }
        }

        self::assertSame([3, 6], $awarded);
        self::assertSame(
            '20.00',
            $this->container->customerService()->get($customer->id)->bonusBalance->format(),
            'Two bonuses of 20% on 50.00.',
        );
    }

    public function testWithdrawalDebitsOnlyRealMoney(): void
    {
        $customer = $this->createCustomer();
        $this->setBonusPercent($customer->id, 10);

        // Three deposits of 100 leave 300 real, and only the third earns a
        // bonus, so 10 bonus.
        for ($i = 0; $i < 3; ++$i) {
            $this->request('POST', "/customers/{$customer->id}/deposits", ['amount' => '100.00']);
        }

        $response = $this->request('POST', "/customers/{$customer->id}/withdrawals", ['amount' => '250.00']);

        self::assertSame(201, $response->status);

        $data = $response->decoded()['data'];

        self::assertSame('-250.00', $data['transaction']['amount'], 'Withdrawals are stored as negative amounts.');
        self::assertSame('50.00', $data['customer']['balance']['real']);
        self::assertSame('10.00', $data['customer']['balance']['bonus'], 'The bonus balance is untouched.');
    }

    /**
     * The specification's example: with a balance of 110 EUR of which 10 EUR is
     * bonus, the largest possible withdrawal is 100 EUR.
     */
    public function testBonusMoneyCannotBeWithdrawn(): void
    {
        $customer = $this->createCustomer();
        $this->setBonusPercent($customer->id, 10);

        // 40 + 40 + 20 = 100.00 real, plus 10% of the third deposit as bonus.
        $this->request('POST', "/customers/{$customer->id}/deposits", ['amount' => '40.00']);
        $this->request('POST', "/customers/{$customer->id}/deposits", ['amount' => '40.00']);
        $this->request('POST', "/customers/{$customer->id}/deposits", ['amount' => '20.00']);

        $balance = $this->container->customerService()->get($customer->id);

        self::assertSame('100.00', $balance->realBalance->format());
        self::assertSame('2.00', $balance->bonusBalance->format());
        self::assertSame('102.00', $balance->totalBalance()->format());

        $tooMuch = $this->request('POST', "/customers/{$customer->id}/withdrawals", ['amount' => '102.00']);

        self::assertSame(422, $tooMuch->status);
        self::assertSame('insufficient_funds', $tooMuch->decoded()['error']['code']);

        $exact = $this->request('POST', "/customers/{$customer->id}/withdrawals", ['amount' => '100.00']);

        self::assertSame(201, $exact->status);
        self::assertSame('0.00', $exact->decoded()['data']['customer']['balance']['real']);
        self::assertSame('2.00', $exact->decoded()['data']['customer']['balance']['bonus']);
    }

    public function testTheBalanceCanNeverGoBelowZero(): void
    {
        $customer = $this->createCustomer();

        $response = $this->request('POST', "/customers/{$customer->id}/withdrawals", ['amount' => '0.01']);

        self::assertSame(422, $response->status);
        self::assertSame(0, (int) $this->customerRow($customer->id)['real_balance']);
    }

    public function testAFailedWithdrawalLeavesNoLedgerRow(): void
    {
        $customer = $this->createCustomer();
        $this->request('POST', "/customers/{$customer->id}/deposits", ['amount' => '10.00']);

        $this->request('POST', "/customers/{$customer->id}/withdrawals", ['amount' => '99.00']);

        $history = $this->request('GET', "/customers/{$customer->id}/transactions")->decoded()['data'];

        self::assertSame(1, $history['total'], 'Only the deposit should be recorded.');
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalidAmounts(): iterable
    {
        yield 'zero' => ['0.00'];
        yield 'negative' => ['-10.00'];
        yield 'sub-cent precision' => ['10.001'];
        yield 'not a number' => ['ten'];
        yield 'boolean' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidAmounts')]
    public function testInvalidAmountsAreRejected(mixed $amount): void
    {
        $customer = $this->createCustomer();

        $response = $this->request('POST', "/customers/{$customer->id}/deposits", ['amount' => $amount]);

        self::assertSame(422, $response->status);
        self::assertSame('validation_failed', $response->decoded()['error']['code']);
    }

    public function testAMissingAmountIsRejected(): void
    {
        $customer = $this->createCustomer();

        self::assertSame(422, $this->request('POST', "/customers/{$customer->id}/deposits", [])->status);
    }

    public function testTransactionsAgainstAnUnknownCustomerAreNotFound(): void
    {
        self::assertSame(404, $this->request('POST', '/customers/424242/deposits', ['amount' => '1.00'])->status);
        self::assertSame(404, $this->request('POST', '/customers/424242/withdrawals', ['amount' => '1.00'])->status);
        self::assertSame(404, $this->request('GET', '/customers/424242/transactions')->status);
    }

    /**
     * The running balances on the customer row must always equal the sum of the
     * ledger; that is the invariant the whole design protects.
     */
    public function testTheLedgerReconcilesWithTheStoredBalances(): void
    {
        $customer = $this->createCustomer();
        $this->setBonusPercent($customer->id, 15);

        foreach (['100.00', '25.50', '73.25', '10.00', '40.00', '5.75'] as $amount) {
            $this->request('POST', "/customers/{$customer->id}/deposits", ['amount' => $amount]);
        }

        $this->request('POST', "/customers/{$customer->id}/withdrawals", ['amount' => '120.00']);
        $this->request('POST', "/customers/{$customer->id}/withdrawals", ['amount' => '30.50']);

        $statement = self::connection()->prepare(
            "SELECT
                 COALESCE(SUM(CASE WHEN type <> 'bonus' THEN amount END), 0) AS real_sum,
                 COALESCE(SUM(CASE WHEN type  = 'bonus' THEN amount END), 0) AS bonus_sum
               FROM transactions WHERE customer_id = :id",
        );
        $statement->execute(['id' => $customer->id]);
        $sums = $statement->fetch();

        $row = $this->customerRow($customer->id);

        self::assertSame((int) $sums['real_sum'], (int) $row['real_balance']);
        self::assertSame((int) $sums['bonus_sum'], (int) $row['bonus_balance']);
        self::assertSame(6, (int) $row['deposit_count']);
    }
}
