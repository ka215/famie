<?php

// Read-only guard. Validate both .env and effective (possibly cached) configuration.
if ($argc !== 4 || ! in_array($argv[3], ['ka2_famie', 'ka2_famiestg'], true)) {
    fwrite(STDERR, "Invalid environment guard arguments\n");
    exit(1);
}

[$script, $backend, $expectedUrl, $expectedDatabase] = $argv;
require $backend.'/vendor/autoload.php';

try {
    $values = Dotenv\Dotenv::parse(file_get_contents($backend.'/.env'));
    $expected = [
        'APP_ENV' => 'production',
        'APP_URL' => $expectedUrl,
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => 'localhost',
        'DB_PORT' => '5432',
        'DB_DATABASE' => $expectedDatabase,
        'DB_USERNAME' => $expectedDatabase,
        'DB_SCHEMA' => $expectedDatabase,
    ];
    foreach ($expected as $key => $value) {
        if (($values[$key] ?? null) !== $value) {
            throw new RuntimeException('Unexpected .env setting: '.$key);
        }
    }
    if (! in_array(strtolower($values['APP_DEBUG'] ?? ''), ['false', '(false)', '0'], true)
        || ! empty($values['DB_URL'])) {
        throw new RuntimeException('APP_DEBUG must be disabled and DB_URL must be unset');
    }

    $app = require $backend.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (! $app->environment('production') || config('app.debug') || config('app.url') !== $expectedUrl
        || config('database.default') !== 'pgsql') {
        throw new RuntimeException('Unexpected effective application configuration');
    }
    $connection = Illuminate\Support\Facades\DB::connection();
    foreach (['host' => 'localhost', 'port' => '5432', 'database' => $expectedDatabase,
        'username' => $expectedDatabase, 'search_path' => $expectedDatabase] as $key => $value) {
        if ((string) $connection->getConfig($key) !== $value) {
            throw new RuntimeException('Unexpected effective database configuration: '.$key);
        }
    }
    $identity = $connection->selectOne('select current_database() as db, current_schema() as schema, current_user as username');
    if ($identity->db !== $expectedDatabase || $identity->schema !== $expectedDatabase || $identity->username !== $expectedDatabase) {
        throw new RuntimeException('Connected database identity differs from target');
    }
    echo "Application and database identity verified.\n";
} catch (Throwable $error) {
    // Connection exception messages can include credentials; never print them.
    fwrite(STDERR, "Environment/database identity check failed. Check .env, cached config and DB connectivity for the selected target.\n");
    exit(1);
}
