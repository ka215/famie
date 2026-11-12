<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RepresentativeManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['famie.ip_restriction_enabled' => false]);
    }

    public function test_registration_fixes_the_representative_and_later_parents_are_not_representatives(): void
    {
        $response = $this->postJson('/v1/auth/register', $this->registration())
            ->assertCreated()->assertJsonPath('membership.is_representative', true);
        $groupId = $response->json('membership.group.id');
        $userId = $response->json('user.id');
        $this->assertDatabaseHas('groups', ['id' => $groupId, 'representative_user_id' => $userId]);

        $created = $this->withToken($response->json('access_token'))->postJson("/v1/groups/{$groupId}/members", [
            'username' => 'second-parent', 'display_name' => 'Second parent', 'role' => 'admin',
            'password' => 'river otter orchard sunset', 'password_confirmation' => 'river otter orchard sunset',
        ])->assertCreated()->assertJsonPath('data.is_representative', false);
        $this->assertDatabaseHas('groups', ['id' => $groupId, 'representative_user_id' => $userId]);
        $this->assertDatabaseHas('group_members', ['user_id' => $created->json('data.id'), 'role' => 'admin']);
    }

    #[TestWith(['demote'])]
    #[TestWith(['deactivate'])]
    #[TestWith(['reset'])]
    public function test_other_parent_cannot_change_representative_role_status_or_password_and_returns_403(string $operation): void
    {
        [$group, $representative, $parent] = $this->family();
        $representative->user->createToken('retained');
        $oldHash = $representative->user->password;
        Sanctum::actingAs($parent->user);
        $path = "/v1/groups/{$group->id}/members/{$representative->id}";

        $response = match ($operation) {
            'demote' => $this->patchJson($path, ['display_name' => 'Should not change', 'role' => 'member']),
            'deactivate' => $this->putJson($path.'/status', ['status' => 'inactive']),
            'reset' => $this->putJson($path.'/password', [
                'current_password' => 'password', 'new_password' => 'river otter orchard sunset',
                'new_password_confirmation' => 'river otter orchard sunset',
            ]),
        };

        $response->assertForbidden()->assertJsonPath('message', '代表管理者の役割・利用状態・パスワードを他のメンバー管理操作で変更することはできません。');
        $this->assertDatabaseHas('group_members', ['id' => $representative->id, 'role' => 'admin', 'status' => 'active']);
        $this->assertDatabaseHas('groups', ['id' => $group->id, 'representative_user_id' => $representative->user_id]);
        $this->assertSame($oldHash, $representative->user->fresh()->password);
        $this->assertDatabaseMissing('users', ['id' => $representative->user_id, 'display_name' => 'Should not change']);
        $this->assertSame(1, $representative->user->tokens()->count());
    }

    public function test_name_only_edit_and_own_password_change_remain_available(): void
    {
        [$group, $representative, $parent] = $this->family();
        Sanctum::actingAs($parent->user);
        $this->patchJson("/v1/groups/{$group->id}/members/{$representative->id}", [
            'display_name' => 'Updated name', 'role' => 'admin',
        ])->assertOk()->assertJsonPath('data.is_representative', true);
        $this->assertDatabaseHas('users', ['id' => $representative->user_id, 'display_name' => 'Updated name']);

        Sanctum::actingAs($representative->user);
        $this->putJson('/v1/auth/me/password', [
            'current_password' => 'password', 'new_password' => 'river otter orchard sunset',
            'new_password_confirmation' => 'river otter orchard sunset',
        ])->assertOk();
        $this->assertTrue(Hash::check('river otter orchard sunset', $representative->user->fresh()->password));
    }

    public function test_identity_and_member_lists_identify_only_the_fixed_representative(): void
    {
        [$group, $representative, $parent] = $this->family();
        Sanctum::actingAs($representative->user);
        $this->getJson('/v1/auth/me')->assertOk()->assertJsonPath('membership.is_representative', true);
        $this->getJson("/v1/groups/{$group->id}/members")->assertOk()
            ->assertJsonPath('0.is_representative', true)->assertJsonPath('1.is_representative', false);
        Sanctum::actingAs($parent->user);
        $this->getJson('/v1/auth/me')->assertOk()->assertJsonPath('membership.is_representative', false);
    }

    public function test_representative_id_cannot_be_changed_through_group_or_member_updates(): void
    {
        [$group, $representative, $parent] = $this->family();
        Sanctum::actingAs($representative->user);
        $this->patchJson("/v1/groups/{$group->id}", ['representative_user_id' => $parent->user_id])->assertUnprocessable();
        $this->patchJson("/v1/groups/{$group->id}/members/{$parent->id}", [
            'display_name' => 'Parent', 'role' => 'admin', 'is_representative' => true,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('groups', ['id' => $group->id, 'representative_user_id' => $representative->user_id]);
    }

    public function test_registration_rejects_representative_injection_without_creating_a_family(): void
    {
        $this->postJson('/v1/auth/register', [...$this->registration(), 'representative_user_id' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('groups', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_other_family_cannot_access_or_edit_the_representative(): void
    {
        [$group, $representative] = $this->family();
        $other = GroupMember::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($other->user);
        $this->patchJson("/v1/groups/{$group->id}/members/{$representative->id}", [
            'display_name' => 'Cross family', 'role' => 'member',
        ])->assertNotFound();
        $this->assertDatabaseHas('group_members', ['id' => $representative->id, 'role' => 'admin']);
    }

    public function test_migration_uses_minimum_user_id_instead_of_membership_creation_order(): void
    {
        $migration = require database_path('migrations/2026_11_03_054833_add_representative_user_id_to_groups_table.php');
        $migration->down();
        $group = Group::factory()->create();
        $firstUser = User::factory()->create();
        $later = GroupMember::factory()->create(['group_id' => $group->id, 'role' => 'admin']);
        GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $firstUser->id, 'role' => 'admin']);

        $migration->up();

        $this->assertDatabaseHas('groups', ['id' => $group->id, 'representative_user_id' => $firstUser->id]);
        $this->assertDatabaseHas('group_members', ['id' => $later->id, 'status' => 'active']);
    }

    #[TestWith(['member', 'active'])]
    #[TestWith(['admin', 'inactive'])]
    public function test_migration_stops_for_an_ineligible_first_member_without_choosing_another_parent(string $role, string $status): void
    {
        $migration = require database_path('migrations/2026_11_03_054833_add_representative_user_id_to_groups_table.php');
        $migration->down();
        $group = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->id, 'role' => $role, 'status' => $status]);
        GroupMember::factory()->create(['group_id' => $group->id, 'role' => 'admin']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Representative migration requires an active first parent.');
        $migration->up();
    }

    /** @return array{Group, GroupMember, GroupMember} */
    private function family(): array
    {
        $group = Group::factory()->create();
        $representative = GroupMember::factory()->create(['group_id' => $group->id, 'role' => 'admin']);
        $parent = GroupMember::factory()->create(['group_id' => $group->id, 'role' => 'admin']);
        $group->forceFill(['representative_user_id' => $representative->user_id])->save();

        return [$group, $representative, $parent];
    }

    /** @return array<string, string> */
    private function registration(): array
    {
        return [
            'group_name' => 'Representative family', 'username' => 'representative-parent', 'display_name' => 'Representative',
            'password' => 'river otter orchard sunset', 'password_confirmation' => 'river otter orchard sunset',
        ];
    }
}
