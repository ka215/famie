<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;

class GuardedConnectionFactory extends ConnectionFactory
{
    /** @param array<string, mixed> $config */
    public function make(array $config, $name = null): Connection
    {
        $settings = $this->container['config'];
        if ($settings->get('app.env') === 'testing' || $settings->get('database-safety.test_run')
            || getenv('FAMIE_DB_TEST_RUN') === '1') {
            $profile = $settings->get('database-safety.profile');
            DestructiveDatabaseCommandGuard::assertTestConnection(
                (string) $settings->get('app.env'), $config,
                $settings->get('database-safety.profiles.'.$profile, []),
            );
        }

        return parent::make($config, $name);
    }
}
