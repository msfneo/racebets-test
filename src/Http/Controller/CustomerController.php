<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\CustomerService;
use App\Http\JsonResponse;
use App\Http\Request;

final readonly class CustomerController
{
    public function __construct(private CustomerService $customers)
    {
    }

    public function create(Request $request): JsonResponse
    {
        return JsonResponse::created($this->customers->create($request->json()));
    }

    public function show(Request $request): JsonResponse
    {
        return JsonResponse::ok($this->customers->get(Identifier::fromPath($request, 'id')));
    }

    public function update(Request $request): JsonResponse
    {
        return JsonResponse::ok(
            $this->customers->update(Identifier::fromPath($request, 'id'), $request->json()),
        );
    }

    public function index(Request $request): JsonResponse
    {
        [$limit, $offset] = Pagination::fromRequest($request);

        return JsonResponse::ok($this->customers->list($limit, $offset));
    }
}
