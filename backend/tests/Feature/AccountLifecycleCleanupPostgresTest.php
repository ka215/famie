<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class AccountLifecycleCleanupPostgresTest extends AccountLifecycleTest
{
    /** Replace RefreshDatabase's reset with a new schema on the isolated test instance. */
    public function refreshDatabase(): void
    {
        if (getenv('FAMIE_LIFECYCLE_PG_TEST') !== '1') {
            $this->markTestSkipped('Requires an explicitly enabled isolated PostgreSQL lifecycle schema.');
        }
        $this->assertSame('pgsql', DB::getDriverName());
        $schema = 'famie_lifecycle_regression_'.getmypid().'_'.bin2hex(random_bytes(4)).'_test';
        DB::statement('CREATE SCHEMA '.$schema);
        $this->beforeApplicationDestroyed(function () use ($schema): void {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::select("select set_config('search_path', 'public', false)");
            DB::statement('DROP SCHEMA '.$schema.' CASCADE');
        });
        DB::select("select set_config('search_path', ?, false)", [$schema]);
        Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
    }
}
