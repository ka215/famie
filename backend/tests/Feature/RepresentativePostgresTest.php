<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RepresentativePostgresTest extends TestCase
{
    private ?string $schema = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('FAMIE_REPRESENTATIVE_PG_TEST') !== '1') {
            $this->markTestSkipped('Requires explicitly enabled isolated PostgreSQL representative schema.');
        }
        $this->assertSame('pgsql', DB::getDriverName());
        $this->schema = 'famie_representative_'.getmypid().'_'.bin2hex(random_bytes(4)).'_test';
        DB::statement('CREATE SCHEMA '.$this->schema);
        DB::select("select set_config('search_path', ?, false)", [$this->schema]);
        Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
        config(['famie.ip_restriction_enabled' => false]);
    }

    protected function tearDown(): void
    {
        if ($this->schema !== null) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::select("select set_config('search_path', 'public', false)");
            DB::statement('DROP SCHEMA '.$this->schema.' CASCADE');
        }
        parent::tearDown();
    }

    public function test_existing_family_migration_and_parent_protection_on_postgresql(): void
    {
        $migration = require database_path('migrations/2026_11_03_054833_add_representative_user_id_to_groups_table.php');
        $migration->down();
        $group = Group::factory()->create();
        $first = User::factory()->create();
        $parent = GroupMember::factory()->create(['group_id' => $group->id, 'role' => 'admin']);
        $representative = GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $first->id, 'role' => 'admin']);
        $migration->up();
        $this->assertDatabaseHas('groups', ['id' => $group->id, 'representative_user_id' => $first->id]);
        Sanctum::actingAs($parent->user);

        $this->putJson("/v1/groups/{$group->id}/members/{$representative->id}/status", ['status' => 'inactive'])->assertForbidden();

        $this->assertDatabaseHas('group_members', ['id' => $representative->id, 'role' => 'admin', 'status' => 'active']);
    }

    public function test_invalid_family_stops_before_altering_any_family_on_postgresql(): void
    {
        $migration = require database_path('migrations/2026_11_03_054833_add_representative_user_id_to_groups_table.php');
        $migration->down();
        $valid = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $valid->id, 'role' => 'admin']);
        $invalid = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $invalid->id, 'role' => 'member']);

        try {
            $migration->up();
            $this->fail('An ineligible first member must stop the migration.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Representative migration requires an active first parent.', $exception->getMessage());
        }

        $this->assertDatabaseCount('groups', 2);
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('groups', 'representative_user_id'));
    }
}
