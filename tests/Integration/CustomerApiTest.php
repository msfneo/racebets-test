<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\BonusPolicy;

final class CustomerApiTest extends IntegrationTestCase
{
    public function testItRegistersACustomerWithARandomBonusRate(): void
    {
        $response = $this->request('POST', '/customers', [
            'gender' => 'male',
            'first_name' => 'Marco',
            'last_name' => 'Vella',
            'country' => 'MT',
            'email' => 'marco.vella@example.com',
        ]);

        self::assertSame(201, $response->status);

        $customer = $response->decoded()['data'];

        self::assertSame('male', $customer['gender']);
        self::assertSame('MT', $customer['country']);
        self::assertSame('marco.vella@example.com', $customer['email']);
        self::assertSame('0.00', $customer['balance']['real']);
        self::assertSame('0.00', $customer['balance']['bonus']);
        self::assertSame(0, $customer['deposit_count']);

        self::assertGreaterThanOrEqual(BonusPolicy::MIN_PERCENT, $customer['bonus_percent']);
        self::assertLessThanOrEqual(BonusPolicy::MAX_PERCENT, $customer['bonus_percent']);
    }

    public function testCountryAndEmailAreNormalised(): void
    {
        $response = $this->request('POST', '/customers', [
            'gender' => 'female',
            'first_name' => 'Anna',
            'last_name' => 'Schmidt',
            'country' => 'de',
            'email' => 'Anna.Schmidt@Example.COM',
        ]);

        $customer = $response->decoded()['data'];

        self::assertSame('DE', $customer['country']);
        self::assertSame('anna.schmidt@example.com', $customer['email']);
    }

    public function testEmailMustBeUniqueRegardlessOfCase(): void
    {
        $payload = [
            'gender' => 'other',
            'first_name' => 'Robin',
            'last_name' => 'Janssen',
            'country' => 'NL',
            'email' => 'robin@example.com',
        ];

        self::assertSame(201, $this->request('POST', '/customers', $payload)->status);

        $duplicate = $this->request('POST', '/customers', [...$payload, 'email' => 'ROBIN@example.com']);

        self::assertSame(409, $duplicate->status);
        self::assertSame('email_already_taken', $duplicate->decoded()['error']['code']);
    }

    public function testItReportsEveryValidationProblemAtOnce(): void
    {
        $response = $this->request('POST', '/customers', [
            'gender' => 'unknown',
            'first_name' => '',
            'country' => 'XX',
            'email' => 'not-an-email',
        ]);

        self::assertSame(422, $response->status);

        $details = $response->decoded()['error']['details'];

        self::assertArrayHasKey('gender', $details);
        self::assertArrayHasKey('first_name', $details);
        self::assertArrayHasKey('last_name', $details, 'A missing field must be reported as required.');
        self::assertArrayHasKey('country', $details);
        self::assertArrayHasKey('email', $details);
    }

    public function testItEditsTheDetailsGivenOnRegistration(): void
    {
        $customer = $this->createCustomer(['country' => 'MT', 'last_name' => 'Vella']);

        $response = $this->request('PATCH', '/customers/' . $customer->id, [
            'last_name' => 'Vella-Borg',
            'country' => 'IT',
        ]);

        self::assertSame(200, $response->status);

        $updated = $response->decoded()['data'];

        self::assertSame('Vella-Borg', $updated['last_name']);
        self::assertSame('IT', $updated['country']);
        self::assertSame($customer->firstName, $updated['first_name'], 'Omitted fields must be left alone.');
        self::assertSame($customer->bonusPercent, $updated['bonus_percent']);
    }

    public function testTheBonusRateCannotBeEditedByAClient(): void
    {
        $customer = $this->createCustomer();

        $response = $this->request('PATCH', '/customers/' . $customer->id, ['bonus_percent' => 99]);

        self::assertSame(422, $response->status);
        self::assertSame($customer->bonusPercent, $this->container->customerService()->get($customer->id)->bonusPercent);
    }

    public function testEditingToAnEmailThatIsTakenIsRejected(): void
    {
        $first = $this->createCustomer(['email' => 'first@example.com']);
        $second = $this->createCustomer(['email' => 'second@example.com']);

        $response = $this->request('PATCH', '/customers/' . $second->id, ['email' => 'first@example.com']);

        self::assertSame(409, $response->status);
        self::assertSame('second@example.com', $this->container->customerService()->get($second->id)->email);
        self::assertSame('first@example.com', $this->container->customerService()->get($first->id)->email);
    }

    public function testAnEmptyUpdateIsRejected(): void
    {
        $customer = $this->createCustomer();

        $response = $this->request('PATCH', '/customers/' . $customer->id, []);

        self::assertSame(422, $response->status);
    }

    public function testUnknownCustomersAndIdsProduceNotFound(): void
    {
        self::assertSame(404, $this->request('GET', '/customers/999999')->status);
        self::assertSame(404, $this->request('GET', '/customers/not-a-number')->status);
        self::assertSame(404, $this->request('GET', '/nope')->status);
    }

    public function testUnsupportedMethodsAreReported(): void
    {
        $customer = $this->createCustomer();

        $response = $this->request('DELETE', '/customers/' . $customer->id);

        self::assertSame(405, $response->status);
        self::assertSame('method_not_allowed', $response->decoded()['error']['code']);
    }

    public function testMalformedJsonIsRejectedBeforeItReachesTheDomain(): void
    {
        $response = $this->kernel->handle(new \App\Http\Request('POST', '/customers', [], '{"gender":'));

        self::assertSame(400, $response->status);
        self::assertSame('malformed_request', $response->decoded()['error']['code']);
    }

    public function testItListsCustomers(): void
    {
        $this->createCustomer();
        $this->createCustomer();

        $response = $this->request('GET', '/customers', null, ['limit' => '1']);

        self::assertSame(200, $response->status);

        $data = $response->decoded()['data'];

        self::assertSame(2, $data['total']);
        self::assertCount(1, $data['items']);
    }
}
