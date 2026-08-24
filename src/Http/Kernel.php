<?php

declare(strict_types=1);

namespace App\Http;

use App\Container;
use App\Domain\Exception\ApiException;
use App\Http\Exception\MethodNotAllowed;

/**
 * Wires the routing table and turns every exception into a JSON response.
 */
final class Kernel
{
    private readonly Router $router;

    public function __construct(private readonly Container $container)
    {
        $this->router = $this->buildRouter();
    }

    public function handle(Request $request): JsonResponse
    {
        try {
            return $this->router->dispatch($request);
        } catch (ApiException $e) {
            return $this->renderApiException($e);
        } catch (\Throwable $e) {
            // Never leak an internal message or stack trace to an API client.
            \error_log(\sprintf(
                '[%s] %s in %s:%d%s%s',
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                \PHP_EOL,
                $e->getTraceAsString(),
            ));

            return JsonResponse::error(500, 'internal_error', 'An unexpected error occurred.');
        }
    }

    private function renderApiException(ApiException $e): JsonResponse
    {
        $headers = $e instanceof MethodNotAllowed
            ? ['Allow' => \implode(', ', $e->allowed)]
            : [];

        return JsonResponse::error(
            $e->statusCode(),
            $e->errorCode(),
            $e->getMessage(),
            $e->details(),
            $headers,
        );
    }

    private function buildRouter(): Router
    {
        $customers = $this->container->customerController();
        $transactions = $this->container->transactionController();
        $reports = $this->container->reportController();

        return (new Router())
            ->add('GET', '/health', fn (Request $r) => JsonResponse::ok(['status' => 'ok']))

            ->add('POST', '/customers', $customers->create(...))
            ->add('GET', '/customers', $customers->index(...))
            ->add('GET', '/customers/{id}', $customers->show(...))
            ->add('PATCH', '/customers/{id}', $customers->update(...))
            ->add('PUT', '/customers/{id}', $customers->update(...))

            ->add('POST', '/customers/{id}/deposits', $transactions->deposit(...))
            ->add('POST', '/customers/{id}/withdrawals', $transactions->withdraw(...))
            ->add('GET', '/customers/{id}/transactions', $transactions->index(...))

            ->add('GET', '/reports/transactions', $reports->transactions(...));
    }
}
