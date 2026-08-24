<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\TransactionService;
use App\Domain\Exception\ValidationException;
use App\Domain\Money;
use App\Http\JsonResponse;
use App\Http\Request;

final readonly class TransactionController
{
    public function __construct(private TransactionService $transactions)
    {
    }

    public function deposit(Request $request): JsonResponse
    {
        return JsonResponse::created($this->transactions->deposit(
            Identifier::fromPath($request, 'id'),
            self::amount($request),
        ));
    }

    public function withdraw(Request $request): JsonResponse
    {
        return JsonResponse::created($this->transactions->withdraw(
            Identifier::fromPath($request, 'id'),
            self::amount($request),
        ));
    }

    public function index(Request $request): JsonResponse
    {
        [$limit, $offset] = Pagination::fromRequest($request);

        return JsonResponse::ok($this->transactions->history(
            Identifier::fromPath($request, 'id'),
            $limit,
            $offset,
        ));
    }

    /**
     * @throws ValidationException
     */
    private static function amount(Request $request): Money
    {
        $payload = $request->json();

        $unknown = \array_diff(\array_keys($payload), ['amount']);

        if ($unknown !== []) {
            throw ValidationException::forField('_', \sprintf(
                'Unknown field(s): %s. The only accepted field is "amount".',
                \implode(', ', $unknown),
            ));
        }

        if (!\array_key_exists('amount', $payload)) {
            throw ValidationException::forField('amount', 'This field is required.');
        }

        return Money::parsePositive($payload['amount']);
    }
}
