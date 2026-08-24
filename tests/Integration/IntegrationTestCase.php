<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Container;
use App\Domain\Customer;
use App\Http\JsonResponse;
use App\Http\Kernel;
use App\Http\Request;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that run against the real MySQL schema.
 *
 * Requests are dispatched straight through the kernel rather than over a socket:
 * the whole stack from routing to SQL is exercised, without needing a web server
 * running for the suite.
 */
abstract class IntegrationTestCase extends TestCase
{
    private static ?\PDO $connection = null;

    protected Container $container;
    protected FrozenClock $clock;
    protected Kernel $kernel;

    public static function setUpBeforeClass(): void
    {
        self::connection();
    }

    protected function setUp(): void
    {
        $this->truncateAll();

        $this->clock = new FrozenClock();
        $this->container = new Container(self::connection(), $this->clock);
        $this->kernel = new Kernel($this->container);
    }

    protected static function connection(): \PDO
    {
        if (self::$connection instanceof \PDO) {
            return self::$connection;
        }

        self::$connection = ConnectionFactory::create();

        // Bring the test schema up to date on first use, so a fresh checkout can
        // run the suite without a separate migrate step.
        (new Migrator(self::$connection, \dirname(__DIR__, 2) . '/migrations'))->migrate();

        return self::$connection;
    }

    /**
     * @param array<string, mixed>|null    $body
     * @param array<string, string> $query
     */
    protected function request(string $method, string $path, ?array $body = null, array $query = []): JsonResponse
    {
        return $this->kernel->handle(new Request(
            $method,
            $path,
            $query,
            // Cast to an object so that an empty payload is encoded as `{}` and
            // not as `[]`, which the kernel rightly refuses as not-an-object.
            $body === null ? '' : json_encode((object) $body, \JSON_THROW_ON_ERROR),
        ));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function createCustomer(array $overrides = []): Customer
    {
        static $sequence = 0;
        ++$sequence;

        return $this->container->customerService()->create([
            'gender' => 'female',
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'country' => 'MT',
            'email' => \sprintf('customer%d@example.com', $sequence),
            ...$overrides,
        ]);
    }

    /**
     * Forces a customer's bonus rate, which is otherwise random, so that bonus
     * assertions can use exact figures.
     */
    protected function setBonusPercent(int $customerId, int $percent): void
    {
        $statement = self::connection()->prepare(
            'UPDATE customers SET bonus_percent = :percent WHERE id = :id',
        );
        $statement->execute(['percent' => $percent, 'id' => $customerId]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function customerRow(int $id): array
    {
        $statement = self::connection()->prepare('SELECT * FROM customers WHERE id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();
        self::assertIsArray($row, \sprintf('Customer %d should exist.', $id));

        return $row;
    }

    private function truncateAll(): void
    {
        $pdo = self::connection();

        // TRUNCATE cannot run while the foreign keys are enforced, and it resets
        // AUTO_INCREMENT so ids are predictable from test to test.
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('TRUNCATE TABLE transactions');
        $pdo->exec('TRUNCATE TABLE customers');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
