<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivityLogControllerTest extends TestCase
{
    use RefreshDatabase;

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
