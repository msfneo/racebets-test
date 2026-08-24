<?php

declare(strict_types=1);

namespace App;

use App\Application\CustomerService;
use App\Application\ReportService;
use App\Application\TransactionService;
use App\Http\Controller\CustomerController;
use App\Http\Controller\ReportController;
use App\Http\Controller\TransactionController;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\TransactionManager;
use App\Infrastructure\Persistence\CustomerRepository;
use App\Infrastructure\Persistence\TransactionRepository;
use App\Support\Clock;
use App\Support\SystemClock;

/**
 * Explicit lazy service locator.
 *
 * A container library would earn its keep in a larger codebase; at this size a
 * dozen typed factory methods are easier to follow and impossible to
 * misconfigure. Tests construct it with a fixed clock and their own connection.
 */
final class Container
{
    private ?\PDO $pdo = null;
    private ?TransactionManager $transactionManager = null;
    private ?CustomerRepository $customerRepository = null;
    private ?TransactionRepository $transactionRepository = null;
    private ?CustomerService $customerService = null;
    private ?TransactionService $transactionService = null;
    private ?ReportService $reportService = null;

    public function __construct(
        ?\PDO $pdo = null,
        private readonly Clock $clock = new SystemClock(),
    ) {
        $this->pdo = $pdo;
    }

    public function pdo(): \PDO
    {
        return $this->pdo ??= ConnectionFactory::create();
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    public function transactionManager(): TransactionManager
    {
        return $this->transactionManager ??= new TransactionManager($this->pdo());
    }

    public function customerRepository(): CustomerRepository
    {
        return $this->customerRepository ??= new CustomerRepository($this->pdo());
    }

    public function transactionRepository(): TransactionRepository
    {
        return $this->transactionRepository ??= new TransactionRepository($this->pdo());
    }

    public function customerService(): CustomerService
    {
        return $this->customerService ??= new CustomerService($this->customerRepository(), $this->clock);
    }

    public function transactionService(): TransactionService
    {
        return $this->transactionService ??= new TransactionService(
            $this->transactionManager(),
            $this->customerRepository(),
            $this->transactionRepository(),
            $this->clock,
        );
    }

    public function reportService(): ReportService
    {
        return $this->reportService ??= new ReportService($this->transactionRepository(), $this->clock);
    }

    public function customerController(): CustomerController
    {
        return new CustomerController($this->customerService());
    }

    public function transactionController(): TransactionController
    {
        return new TransactionController($this->transactionService());
    }

    public function reportController(): ReportController
    {
        return new ReportController($this->reportService());
    }
}
