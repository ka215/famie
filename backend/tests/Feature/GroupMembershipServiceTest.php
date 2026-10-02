<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\GroupMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class GroupMembershipServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivation_revokes_tokens_and_reactivation_preserves_identity_and_logs(): void
    {
        $group = Group::factory()->create();
        $admin = $this->member($group, 'admin');
        $member = $this->member($group, 'member');
        $category = Category::query()->create(['group_id' => $group->id, 'name' => '家事', 'sort_order' => 1]);
        $log = ActivityLog::query()->create([
            'group_id' => $group->id, 'user_id' => $member->user_id, 'category_id' => $category->id,
            'activity_date' => '2026-10-02', 'content' => '保持対象',
        ]);
        $member->user->createToken('test');
        $service = app(GroupMembershipService::class);

        $service->changeStatus($admin->user, $member, 'inactive');
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $member->user_id]);
        $this->assertDatabaseHas('activity_logs', ['id' => $log->id]);

        $restored = $service->changeStatus($admin->user, $member, 'active');
        $this->assertSame($member->id, $restored->id);
        $this->assertSame('active', $restored->status);
    }

    public function test_admin_cannot_deactivate_self_or_last_other_admin(): void
    {
        $group = Group::factory()->create();
        $admin = $this->member($group, 'admin');
        $service = app(GroupMembershipService::class);

        $this->expectException(HttpException::class);
        $service->changeStatus($admin->user, $admin, 'inactive');
    }

    private function member(Group $group, string $role): GroupMember
    {
        return GroupMember::query()->create([
            'group_id' => $group->id, 'user_id' => User::factory()->create()->id, 'role' => $role, 'status' => 'active',
        ])->load('user');
    }
}
