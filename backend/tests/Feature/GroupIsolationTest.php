<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GroupIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['famie.allowed_ips' => ['127.0.0.1']]);
    }

    public function test_group_resources_are_isolated_for_lists_details_and_writes(): void
    {
        [$firstGroup, $firstUser, $firstCategory] = $this->family('一組目');
        [$secondGroup, $secondUser, $secondCategory] = $this->family('二組目');
        $secondLog = ActivityLog::query()->create([
            'group_id' => $secondGroup->id, 'user_id' => $secondUser->id, 'category_id' => $secondCategory->id,
            'activity_date' => '2026-10-02', 'content' => '別家族の記録',
        ]);
        Sanctum::actingAs($firstUser);

        $this->getJson("/v1/groups/{$firstGroup->id}/logs")->assertOk()->assertJsonMissing(['content' => '別家族の記録']);
        $this->getJson("/v1/groups/{$secondGroup->id}/logs")->assertNotFound();
        $this->getJson("/v1/groups/{$firstGroup->id}/logs/{$secondLog->id}")->assertNotFound();
        $this->postJson("/v1/groups/{$firstGroup->id}/logs", [
            'category_id' => $secondCategory->id, 'activity_date' => '2026-10-02', 'content' => '越境',
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('activity_logs', ['group_id' => $firstGroup->id, 'content' => '越境']);
        $this->assertDatabaseHas('categories', ['id' => $firstCategory->id, 'group_id' => $firstGroup->id]);
    }

    public function test_inactive_member_cannot_use_group_and_their_logs_are_hidden_until_reactivated(): void
    {
        [$group, $admin, $category] = $this->family('家族');
        $member = User::factory()->create();
        $membership = GroupMember::query()->create([
            'group_id' => $group->id, 'user_id' => $member->id, 'role' => 'member', 'status' => 'active',
        ]);
        ActivityLog::query()->create([
            'group_id' => $group->id, 'user_id' => $member->id, 'category_id' => $category->id,
            'activity_date' => '2026-10-02', 'content' => '保持される記録',
        ]);
        $membership->update(['status' => 'inactive']);
        Sanctum::actingAs($admin);

        $this->getJson("/v1/groups/{$group->id}/logs")->assertOk()->assertJsonMissing(['content' => '保持される記録']);
        $this->assertDatabaseHas('activity_logs', ['content' => '保持される記録']);
        Sanctum::actingAs($member);
        $this->getJson("/v1/groups/{$group->id}/logs")->assertNotFound();

        $membership->update(['status' => 'active']);
        Sanctum::actingAs($admin);
        $this->getJson("/v1/groups/{$group->id}/logs")->assertJsonFragment(['content' => '保持される記録']);
    }

    public function test_member_list_does_not_disclose_email_addresses(): void
    {
        [$group, $admin] = $this->family('家族');
        $admin->update(['email' => 'private@example.test']);
        Sanctum::actingAs($admin);

        $this->getJson("/v1/groups/{$group->id}/members")
            ->assertOk()
            ->assertJsonFragment(['id' => $admin->id, 'username' => $admin->username])
            ->assertJsonMissingPath('0.email')
            ->assertJsonMissing(['email' => 'private@example.test']);
    }

    /** @return array{Group, User, Category} */
    private function family(string $name): array
    {
        $group = Group::factory()->create(['name' => $name]);
        $user = User::factory()->create();
        GroupMember::query()->create(['group_id' => $group->id, 'user_id' => $user->id, 'role' => 'admin', 'status' => 'active']);
        $category = Category::query()->create(['group_id' => $group->id, 'name' => '家事', 'color_code' => '#10B981', 'sort_order' => 1]);

        return [$group, $user, $category];
    }
}
