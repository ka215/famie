<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\GroupMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['famie.allowed_ips' => ['127.0.0.1']]);
    }

    public function test_admin_edits_then_deletes_unused_category(): void
    {
        $admin = GroupMember::factory()->create(['role' => 'admin']);
        $category = $this->category($admin);
        Sanctum::actingAs($admin->user);

        $this->putJson($this->path($category), ['name' => ' 新カテゴリ ', 'color_code' => '#123456'])->assertOk();
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => '新カテゴリ', 'color_code' => '#123456', 'sort_order' => 1]);
        $this->deleteJson($this->path($category))->assertOk();
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_inactive_members_history_prevents_category_deletion_with_422(): void
    {
        $admin = GroupMember::factory()->create(['role' => 'admin']);
        $member = GroupMember::factory()->create(['group_id' => $admin->group_id, 'status' => 'inactive']);
        $category = $this->category($admin);
        $log = ActivityLog::query()->create([
            'group_id' => $admin->group_id, 'user_id' => $member->user_id, 'category_id' => $category->id,
            'activity_date' => '2026-10-03', 'content' => '非表示の履歴',
        ]);
        Sanctum::actingAs($admin->user);

        $this->deleteJson($this->path($category))->assertUnprocessable()->assertJsonPath('message', 'ログが存在するカテゴリは削除できません。');
        $this->assertModelExists($category);
        $this->assertModelExists($log);
    }

    public function test_duplicate_name_and_invalid_color_return_422(): void
    {
        $admin = GroupMember::factory()->create(['role' => 'admin']);
        $first = $this->category($admin);
        $second = Category::query()->create(['group_id' => $admin->group_id, 'name' => '別カテゴリ', 'sort_order' => 2]);
        Sanctum::actingAs($admin->user);

        $this->putJson($this->path($second), ['name' => $first->name, 'color_code' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors([
            'name' => '同じ名前のカテゴリが存在します。',
            'color_code' => '色は#に続く6桁の16進数で指定してください。',
        ]);
        $this->assertDatabaseHas('categories', ['id' => $second->id, 'name' => '別カテゴリ']);
    }

    public function test_member_cannot_edit_or_delete_categories_and_other_family_is_hidden(): void
    {
        $member = GroupMember::factory()->create();
        $category = $this->category($member);
        Sanctum::actingAs($member->user);
        $this->putJson($this->path($category), ['name' => '不正変更'])->assertForbidden();
        $this->deleteJson($this->path($category))->assertForbidden();
        $other = GroupMember::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($other->user);
        $this->deleteJson("/v1/groups/{$other->group_id}/categories/{$category->id}")->assertNotFound();
        $this->assertModelExists($category);
    }

    private function category(GroupMember $member): Category
    {
        return Category::query()->create(['group_id' => $member->group_id, 'name' => '家事', 'color_code' => '#123456', 'sort_order' => 1]);
    }

    private function path(Category $category): string
    {
        return "/v1/groups/{$category->group_id}/categories/{$category->id}";
    }
}
