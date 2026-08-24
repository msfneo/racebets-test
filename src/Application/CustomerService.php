<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\BonusPolicy;
use App\Domain\Customer;
use App\Domain\Exception\CustomerNotFound;
use App\Domain\Gender;
use App\Infrastructure\Persistence\CustomerRepository;
use App\Support\Clock;

final readonly class CustomerService
{
    public function __construct(
        private CustomerRepository $customers,
        private Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws \App\Domain\Exception\ValidationException
     * @throws \App\Domain\Exception\EmailAlreadyTaken
     */
    public function create(array $payload): Customer
    {
        $fields = CustomerInput::forCreate($payload);
        $now = $this->clock->now();

        $id = $this->customers->insert(
            Gender::from($fields['gender']),
            $fields['first_name'],
            $fields['last_name'],
            $fields['country'],
            $fields['email'],
            BonusPolicy::randomPercent(),
            $now,
        );

        return $this->get($id);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws CustomerNotFound
     * @throws \App\Domain\Exception\ValidationException
     * @throws \App\Domain\Exception\EmailAlreadyTaken
     */
    public function update(int $id, array $payload): Customer
    {
        $fields = CustomerInput::forUpdate($payload);

        // Checked up front so a request for a non-existent customer reports 404
        // rather than a silent no-op.
        $this->get($id);

        $this->customers->updateDetails($id, $fields, $this->clock->now());

        return $this->get($id);
    }

    /**
     * @throws CustomerNotFound
     */
    public function get(int $id): Customer
    {
        return $this->customers->find($id) ?? throw CustomerNotFound::withId($id);
    }

    /**
     * @return array{items: list<Customer>, total: int, limit: int, offset: int}
     */
    public function list(int $limit, int $offset): array
    {
        return [
            'items' => $this->customers->all($limit, $offset),
            'total' => $this->customers->count(),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }
}
