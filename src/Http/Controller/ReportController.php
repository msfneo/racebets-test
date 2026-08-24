<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\ReportService;
use App\Http\JsonResponse;
use App\Http\Request;

final readonly class ReportController
{
    public function __construct(private ReportService $reports)
    {
    }

    public function transactions(Request $request): JsonResponse
    {
        return JsonResponse::ok($this->reports->transactions(
            $request->queryParameter('from'),
            $request->queryParameter('to'),
        ));
    }
}
