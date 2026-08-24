<?php

declare(strict_types=1);

use App\Support\Env;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

// The integration suite is destructive: it truncates tables between tests. Point
// it at the dedicated test schema so a run can never wipe the data you created
// by hand against the dev database.
$testDatabase = Env::get('DB_TEST_NAME', 'wallet_test');

if ($testDatabase === Env::get('DB_NAME')) {
    throw new RuntimeException(
        'DB_TEST_NAME must differ from DB_NAME; refusing to run the test suite against the development database.',
    );
}

\putenv('DB_NAME=' . $testDatabase);
$_ENV['DB_NAME'] = $testDatabase;
