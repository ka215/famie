<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ActivityLogControllerTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['parent', 'parent'])]
    #[TestWith(['parent', 'child'])]
    #[TestWith(['child', 'parent'])]
    #[TestWith(['child', 'child'])]
    public function test_other_users_receive_403_and_cannot_change_or_delete_logs(string $actorRole, string $ownerRole): void
    {
        config(['famie.allowed_ips' => ['127.0.0.1']]);
        $actor = User::factory()->create(['role' => $actorRole]);
        $owner = User::factory()->create(['role' => $ownerRole]);
        $log = $this->createLog($owner);
        Sanctum::actingAs($actor);

        $this->putJson("/v1/logs/{$log->id}", ['content' => '改変', 'user_id' => $actor->id])->assertForbidden();
        $this->deleteJson("/v1/logs/{$log->id}")->assertForbidden();

        $this->assertDatabaseHas('activity_logs', ['id' => $log->id, 'user_id' => $owner->id, 'content' => '元の記録']);
        $this->getJson("/v1/logs/{$log->id}")->assertOk();
    }

    #[TestWith(['parent'])]
    #[TestWith(['child'])]
    public function test_owner_can_update_and_delete_their_log(string $role): void
    {
        config(['famie.allowed_ips' => ['127.0.0.1']]);
        $owner = User::factory()->create(['role' => $role]);
        $log = $this->createLog($owner);
        Sanctum::actingAs($owner);

        $this->putJson("/v1/logs/{$log->id}", ['content' => '更新した記録'])->assertOk();
        $this->assertDatabaseHas('activity_logs', ['id' => $log->id, 'content' => '更新した記録']);

        $this->deleteJson("/v1/logs/{$log->id}")->assertOk();
        $this->assertModelMissing($log);
    }

    public function test_guest_cannot_update_or_delete_logs(): void
    {
        config(['famie.allowed_ips' => ['127.0.0.1']]);
        $log = $this->createLog(User::factory()->create());

        $this->putJson("/v1/logs/{$log->id}", ['content' => '改変'])->assertUnauthorized();
        $this->deleteJson("/v1/logs/{$log->id}")->assertUnauthorized();

        $this->assertDatabaseHas('activity_logs', ['id' => $log->id, 'content' => '元の記録']);
    }

    private function createLog(User $owner): ActivityLog
    {
        $category = Category::create(['name' => '家事', 'color_code' => '#10B981']);

        return ActivityLog::create([
            'user_id' => $owner->id,
            'category_id' => $category->id,
            'activity_date' => '2026-09-25',
            'content' => '元の記録',
        ]);
    }

    public function test_returns_requested_activity_log_with_related_data(): void
    {
        config(['famie.allowed_ips' => ['127.0.0.1']]);
        $user = User::factory()->create();
        $category = Category::create([
            'name' => '家事',
            'color_code' => '#10B981',
        ]);
        $log = ActivityLog::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'activity_date' => '2026-09-22',
            'activity_time' => '18:30',
            'content' => '食器を片付けた',
            'note' => null,
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson("/v1/logs/{$log->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $log->id)
            ->assertJsonPath('data.user.display_name', $user->display_name)
            ->assertJsonPath('data.category.name', '家事');
    }
}
