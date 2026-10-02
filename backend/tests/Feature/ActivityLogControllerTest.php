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

class ActivityLogControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_owner_can_update_and_delete_a_family_log(): void
    {
        config(['famie.allowed_ips' => ['127.0.0.1']]);
        $group = Group::factory()->create();
        $owner = $this->member($group, 'member');
        $other = $this->member($group, 'admin');
        $category = Category::query()->create(['group_id' => $group->id, 'name' => '家事', 'color_code' => '#10B981', 'sort_order' => 1]);
        $log = ActivityLog::query()->create([
            'group_id' => $group->id, 'user_id' => $owner->id, 'category_id' => $category->id,
            'activity_date' => '2026-09-25', 'content' => '元の記録',
        ]);

        Sanctum::actingAs($other);
        $this->putJson("/v1/groups/{$group->id}/logs/{$log->id}", ['content' => '改変'])->assertForbidden();
        $this->deleteJson("/v1/groups/{$group->id}/logs/{$log->id}")->assertForbidden();
        $this->getJson("/v1/groups/{$group->id}/logs/{$log->id}")->assertOk();

        Sanctum::actingAs($owner);
        $this->putJson("/v1/groups/{$group->id}/logs/{$log->id}", ['content' => '更新'])->assertOk();
        $this->deleteJson("/v1/groups/{$group->id}/logs/{$log->id}")->assertOk();
        $this->assertModelMissing($log);
    }

    private function member(Group $group, string $role): User
    {
        $user = User::factory()->create();
        GroupMember::query()->create(['group_id' => $group->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active']);

        return $user;
    }
}
