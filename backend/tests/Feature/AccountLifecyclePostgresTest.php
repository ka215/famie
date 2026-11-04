<?php

namespace Tests\Feature;

use App\Models\AccountLifecycleOperation;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountLifecyclePostgresTest extends TestCase
{
    private ?string $schema = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('FAMIE_LIFECYCLE_PG_TEST') !== '1') {
            $this->markTestSkipped('Requires explicitly enabled isolated PostgreSQL lifecycle schema.');
        }
        $this->assertSame('pgsql', DB::getDriverName());
        $this->schema = 'famie_lifecycle_'.getmypid().'_'.bin2hex(random_bytes(4)).'_test';
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

    public function test_member_deletion_uses_postgresql_constraints_and_partial_image_index(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'famie-lifecycle-pg-'.bin2hex(random_bytes(8));
        File::makeDirectory($root.DIRECTORY_SEPARATOR.'vault', 0700, true);
        File::makeDirectory($root.DIRECTORY_SEPARATOR.'ledger', 0700, true);
        $keypair = sodium_crypto_box_keypair();
        config([
            'famie.lifecycle_vault_root' => $root.DIRECTORY_SEPARATOR.'vault',
            'famie.lifecycle_ledger_root' => $root.DIRECTORY_SEPARATOR.'ledger',
            'famie.lifecycle_public_key' => base64_encode(sodium_crypto_box_publickey($keypair)),
            'famie.lifecycle_key_id' => 'pg-test-key',
        ]);
        $group = Group::factory()->create();
        $representative = GroupMember::factory()->for($group)->for(User::factory())->create(['role' => 'admin']);
        $member = GroupMember::factory()->for($group)->for(User::factory())->create(['status' => 'inactive']);
        $group->forceFill(['representative_user_id' => $representative->user_id])->save();
        $oldUsername = $member->user->username;
        Sanctum::actingAs($representative->user);
        $prepared = $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", [
            'kind' => 'member_delete', 'membership_id' => $member->id,
        ])->assertCreated();
        $storedOperation = AccountLifecycleOperation::query()->findOrFail($prepared->json('data.receipt_id'));
        $this->assertTrue($storedOperation->prepared_expires_at->isFuture(), $storedOperation->prepared_expires_at->toIso8601String());
        $committed = $this->postJson("/v1/groups/{$group->id}/lifecycle-operations/{$prepared->json('data.receipt_id')}/commit", [
            'current_password' => 'password', 'confirmation_code' => $prepared->json('data.confirmation_code'), 'confirmed' => true,
        ]);
        $this->assertSame(202, $committed->status(), $committed->getContent());

        $this->assertSoftDeleted('users', ['id' => $member->user_id]);
        $this->assertSoftDeleted('group_members', ['id' => $member->id]);
        User::factory()->create(['username' => $oldUsername]);
        $index = DB::table('pg_indexes')->where('schemaname', $this->schema)
            ->where('indexname', 'activity_images_active_position_unique')->value('indexdef');
        $this->assertIsString($index);
        $this->assertStringContainsString('WHERE (retired_at IS NULL)', $index);
    }
}
