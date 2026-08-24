<?php

/**
 * A single concurrent client, used by ConcurrencyTest.
 *
 * Run as its own OS process with its own database connection, so the
 * transactions really do overlap — an in-process loop would prove nothing about
 * row locking. All workers wait for a shared start timestamp, which lines their
 * requests up as closely as the scheduler allows.
 *
 * Usage: php worker.php <deposit|withdraw> <customerId> <amount> <startAtMicrotime>
 *
 * Exit codes: 0 = applied, 3 = refused (insufficient funds), 1 = unexpected error.
 */

declare(strict_types=1);

use App\Container;
use App\Domain\Exception\InsufficientFunds;
use App\Domain\Money;
use App\Support\Env;

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

Env::load(\dirname(__DIR__, 2) . '/.env');

[, $action, $customerId, $amount, $startAt] = $argv;

$container = new Container();
$service = $container->transactionService();

// Establish the connection before the barrier so that TCP setup and the
// handshake are not part of the contended window.
$container->pdo()->query('SELECT 1');

$waitMicroseconds = (int) (((float) $startAt - \microtime(true)) * 1_000_000);

if ($waitMicroseconds > 0) {
    \usleep($waitMicroseconds);
}

try {
    $money = Money::parsePositive($amount);

    if ($action === 'deposit') {
        $service->deposit((int) $customerId, $money);
    } else {
        $service->withdraw((int) $customerId, $money);
    }

    exit(0);
} catch (InsufficientFunds) {
    exit(3);
} catch (Throwable $e) {
    \fwrite(\STDERR, \sprintf('%s: %s', $e::class, $e->getMessage()));

    exit(1);
}
