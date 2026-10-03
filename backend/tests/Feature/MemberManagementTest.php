<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\GroupMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MemberManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['famie.allowed_ips' => ['127.0.0.1']]);
    }

    public function test_admin_updates_name_and_role_without_disclosing_credentials(): void
    {
        $admin = GroupMember::factory()->create(['role' => 'admin']);
        $member = GroupMember::factory()->create(['group_id' => $admin->group_id]);
        Sanctum::actingAs($admin->user);

        $this->patchJson($this->path($member), ['display_name' => ' 新しい名前 ', 'role' => 'admin'])
            ->assertOk()->assertJsonPath('data.display_name', '新しい名前')->assertJsonPath('data.role', 'admin')
            ->assertJsonMissingPath('data.email')->assertJsonMissingPath('data.password');

        $this->assertDatabaseHas('users', ['id' => $member->user_id, 'display_name' => '新しい名前']);
        $this->assertDatabaseHas('group_members', ['id' => $member->id, 'role' => 'admin']);
    }

    public function test_inactive_list_is_separate_and_only_contains_own_family_without_email(): void
    {
        $admin = GroupMember::factory()->create(['role' => 'admin']);
        $inactive = GroupMember::factory()->create(['group_id' => $admin->group_id, 'status' => 'inactive']);
        GroupMember::factory()->create(['status' => 'inactive']);
        Sanctum::actingAs($admin->user);

        $this->getJson("/v1/groups/{$admin->group_id}/members/inactive")->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.membership_id', $inactive->id)->assertJsonMissingPath('0.email');
        $this->getJson("/v1/groups/{$admin->group_id}/members")->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.membership_id', $admin->id);
    }

    public function test_unauthenticated_management_requests_return_401(): void
    {
        $member = GroupMember::factory()->create();
        $this->getJson("/v1/groups/{$member->group_id}/members/inactive")->assertUnauthorized();
        $this->patchJson($this->path($member), [])->assertUnauthorized();
        $this->putJson($this->path($member).'/status', [])->assertUnauthorized();
    }

    public function test_member_management_returns_403_without_changing_records(): void
    {
        $member = GroupMember::factory()->create();
        Sanctum::actingAs($member->user);
        $this->getJson("/v1/groups/{$member->group_id}/members/inactive")->assertForbidden();
        $this->patchJson($this->path($member), ['display_name' => '不正変更', 'role' => 'admin'])->assertForbidden();
        $this->putJson($this->path($member).'/status', ['status' => 'inactive'])->assertForbidden();
        $this->assertDatabaseHas('group_members', ['id' => $member->id, 'role' => 'member', 'status' => 'active']);
        $this->assertDatabaseMissing('users', ['id' => $member->user_id, 'display_name' => '不正変更']);
    }

    public function test_other_family_and_membership_ids_return_404(): void
    {
        $admin = GroupMember::factory()->create(['role' => 'admin']);
        $other = GroupMember::factory()->create();
        Sanctum::actingAs($admin->user);
        $this->getJson("/v1/groups/{$other->group_id}/members/inactive")->assertNotFound();
        $this->patchJson("/v1/groups/{$admin->group_id}/members/{$other->id}", ['display_name' => '越境', 'role' => 'admin'])->assertNotFound();
        $this->putJson("/v1/groups/{$admin->group_id}/members/{$other->id}/status", ['status' => 'inactive'])->assertNotFound();
        $this->assertDatabaseHas('group_members', ['id' => $other->id, 'role' => 'member', 'status' => 'active']);
    }

    #[TestWith([['display_name' => '', 'role' => 'member'], 'display_name', '表示名を入力してください。'])]
    #[TestWith([['display_name' => '名前', 'role' => 'owner'], 'role', '役割が不正です。'])]
    #[TestWith([['display_name' => '名前', 'role' => 'member', 'email' => null], 'member', '許可されていない項目が含まれています。'])]
    #[TestWith([['display_name' => '名前', 'role' => 'member', 'username' => 'changed'], 'member', '許可されていない項目が含まれています。'])]
    public function test_invalid_edits_return_422_and_preserve_member(array $payload, string $field, string $message): void
    {
        $admin = GroupMember::factory()->create(['role' => 'admin']);
        $target = GroupMember::factory()->create(['group_id' => $admin->group_id]);
        $original = $target->user->display_name;
        Sanctum::actingAs($admin->user);
        $this->patchJson($this->path($target), $payload)->assertUnprocessable()->assertJsonValidationErrors([$field => $message]);
        $this->assertDatabaseHas('users', ['id' => $target->user_id, 'display_name' => $original]);
        $this->assertDatabaseHas('group_members', ['id' => $target->id, 'role' => 'member']);
    }

    public function test_self_demotion_and_deactivation_return_422_but_name_edit_succeeds(): void
    {
        $admin = GroupMember::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin->user);
        $this->patchJson($this->path($admin), ['display_name' => '不正変更', 'role' => 'member'])->assertUnprocessable();
        $this->putJson($this->path($admin).'/status', ['status' => 'inactive'])->assertUnprocessable();
        $this->assertDatabaseHas('group_members', ['id' => $admin->id, 'role' => 'admin', 'status' => 'active']);
        $this->patchJson($this->path($admin), ['display_name' => '自分の名前', 'role' => 'admin'])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $admin->user_id, 'display_name' => '自分の名前']);
    }

    public function test_name_length_boundary_is_enforced(): void
    {
        $admin = GroupMember::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin->user);
        $this->patchJson($this->path($admin), ['display_name' => str_repeat('😀', 50), 'role' => 'admin'])->assertOk();
        $this->patchJson($this->path($admin), ['display_name' => str_repeat('😀', 51), 'role' => 'admin'])->assertUnprocessable()
            ->assertJsonValidationErrors(['display_name' => '表示名は50文字以内で入力してください。']);
        $this->assertDatabaseHas('users', ['id' => $admin->user_id, 'display_name' => str_repeat('😀', 50)]);
    }

    #[TestWith([[], '所属状態を指定してください。'])]
    #[TestWith([['status' => 'deleted'], '所属状態が不正です。'])]
    public function test_invalid_status_returns_422_without_change(array $payload, string $message): void
    {
        $admin = GroupMember::factory()->create(['role' => 'admin']);
        $member = GroupMember::factory()->create(['group_id' => $admin->group_id]);
        Sanctum::actingAs($admin->user);
        $this->putJson($this->path($member).'/status', $payload)->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => $message]);
        $this->assertDatabaseHas('group_members', ['id' => $member->id, 'status' => 'active']);
    }

    public function test_status_endpoint_rejects_role_changes_and_repeated_restore_keeps_identity(): void
    {
        config(['famie.max_group_members' => 1]);
        $admin = GroupMember::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin->user);
        $this->putJson($this->path($admin).'/status', ['status' => 'active', 'role' => 'member'])->assertUnprocessable();
        $this->putJson($this->path($admin).'/status', ['status' => 'active'])->assertOk()->assertJsonPath('data.membership_id', $admin->id);
        $this->assertDatabaseHas('group_members', ['id' => $admin->id, 'status' => 'active', 'role' => 'admin']);
    }

    public function test_login_rechecks_membership_under_lock_before_issuing_token(): void
    {
        $member = GroupMember::factory()->create();
        $changed = false;
        DB::listen(function ($query) use ($member, &$changed): void {
            if (! $changed && str_contains($query->sql, '"groups"') && DB::transactionLevel() > 1) {
                $changed = true;
                GroupMember::query()->whereKey($member->id)->update(['status' => 'inactive']);
            }
        });

        $this->postJson('/v1/auth/login', ['login' => $member->user->username, 'password' => 'password'])->assertForbidden();
        $this->assertTrue($changed);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $member->user_id]);
    }

    public function test_deactivation_revokes_token_and_hides_logs_until_restored(): void
    {
        $admin = GroupMember::factory()->create(['role' => 'admin']);
        $member = GroupMember::factory()->create(['group_id' => $admin->group_id]);
        $category = Category::query()->create(['group_id' => $admin->group_id, 'name' => '家事', 'sort_order' => 1]);
        $log = ActivityLog::query()->create([
            'group_id' => $admin->group_id, 'user_id' => $member->user_id, 'category_id' => $category->id,
            'activity_date' => '2026-10-03', 'content' => '保持する履歴',
        ]);
        $member->user->createToken('old');
        Sanctum::actingAs($admin->user);

        $this->putJson($this->path($member).'/status', ['status' => 'inactive'])->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $member->user_id]);
        $this->getJson("/v1/groups/{$admin->group_id}/logs/{$log->id}")->assertNotFound();
        $this->getJson("/v1/groups/{$admin->group_id}/logs")->assertJsonPath('total', 0);
        $this->patchJson($this->path($member), ['display_name' => '変更', 'role' => 'member'])->assertUnprocessable();
        $this->postJson('/v1/auth/login', ['login' => $member->user->username, 'password' => 'password'])->assertForbidden();

        $this->putJson($this->path($member).'/status', ['status' => 'active'])->assertOk()->assertJsonPath('data.membership_id', $member->id);
        $this->getJson("/v1/groups/{$admin->group_id}/logs")->assertJsonPath('total', 1)->assertJsonFragment(['id' => $log->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $member->user_id]);
        $this->postJson('/v1/auth/login', ['login' => $member->user->username, 'password' => 'password'])->assertOk();
    }

    public function test_restoration_over_capacity_returns_422_without_restoring(): void
    {
        config(['famie.max_group_members' => 1]);
        $admin = GroupMember::factory()->create(['role' => 'admin']);
        $member = GroupMember::factory()->create(['group_id' => $admin->group_id, 'status' => 'inactive']);
        Sanctum::actingAs($admin->user);
        $this->putJson($this->path($member).'/status', ['status' => 'active'])->assertUnprocessable()
            ->assertJsonPath('message', '家族メンバーの上限に達しています。');
        $this->assertDatabaseHas('group_members', ['id' => $member->id, 'status' => 'inactive']);
    }

    public function test_demoted_admin_cannot_make_a_subsequent_management_request(): void
    {
        $admin = GroupMember::factory()->create(['role' => 'admin']);
        $other = GroupMember::factory()->create(['group_id' => $admin->group_id, 'role' => 'admin']);
        Sanctum::actingAs($admin->user);
        $this->patchJson($this->path($other), ['display_name' => '変更済み', 'role' => 'member'])->assertOk();
        Sanctum::actingAs($other->user);
        $this->putJson($this->path($admin).'/status', ['status' => 'inactive'])->assertForbidden();
        $this->assertDatabaseHas('group_members', ['id' => $admin->id, 'role' => 'admin', 'status' => 'active']);
    }

    public function test_write_rechecks_membership_after_group_lock(): void
    {
        $admin = GroupMember::factory()->create(['role' => 'admin']);
        $member = GroupMember::factory()->create(['group_id' => $admin->group_id]);
        Sanctum::actingAs($admin->user);
        $changed = false;
        DB::listen(function ($query) use ($admin, &$changed): void {
            if (! $changed && str_contains($query->sql, '"groups"') && DB::transactionLevel() > 1) {
                $changed = true;
                GroupMember::query()->whereKey($admin->id)->update(['status' => 'inactive']);
            }
        });

        $this->putJson($this->path($member).'/status', ['status' => 'inactive'])->assertNotFound();
        $this->assertTrue($changed);
        $this->assertDatabaseHas('group_members', ['id' => $member->id, 'status' => 'active']);
    }

    private function path(GroupMember $member): string
    {
        return "/v1/groups/{$member->group_id}/members/{$member->id}";
    }
}
