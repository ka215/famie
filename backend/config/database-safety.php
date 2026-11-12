<?php

return [
    'test_run' => env('FAMIE_DB_TEST_RUN', false),
    'profile' => env('FAMIE_TEST_PROFILE', 'memory'),
    'profiles' => [
        'memory' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'e2e' => ['driver' => 'sqlite', 'root' => dirname(base_path()).'/frontend/.cache/e2e'],
        'postgres-local' => [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '55432',
            'database' => 'famie_test', 'username' => 'famie_test_runner',
        ],
        'postgres-migration' => [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '55432',
            'database' => 'famie_migration_test', 'username' => 'famie_migration_runner',
        ],
        'postgres-ci' => env('CI') && env('GITHUB_ACTIONS') ? [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '5432',
            'database' => 'famie_test', 'username' => 'famie_test_runner',
        ] : [],
    ],
];
