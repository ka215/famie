<?php

namespace Tests\Feature;

use App\Models\ActivityLike;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ActivityLikeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['famie.allowed_ips' => ['127.0.0.1'], 'famie.like_rate_limit_per_second' => 100]);
    }

    #[TestWith(['admin'])]
    #[TestWith(['member'])]
    public function test_add_remove_and_retry_preserve_one_like_and_return_current_state(string $role): void
    {
        [$group, $owner, $actor, $log] = $this->family($role);
        Sanctum::actingAs($actor);
        $path = "/v1/groups/{$group->id}/logs/{$log->id}/like";
        $this->putJson($path)->assertOk()->assertExactJson(['data' => ['id' => $log->id, 'likes_count' => 1, 'liked_by_me' => true, 'can_like' => true]]);
        $firstId = ActivityLike::query()->sole()->id;
        $this->putJson($path)->assertOk()->assertJsonPath('data.likes_count', 1);
        $this->assertSame($firstId, ActivityLike::query()->sole()->id);
        $this->getJson("/v1/groups/{$group->id}/logs")->assertOk()->assertJsonPath('data.0.liked_by_me', true);
        Sanctum::actingAs($owner);
        $this->getJson("/v1/groups/{$group->id}/logs/{$log->id}")->assertOk()->assertJsonPath('data.can_like', false)->assertJsonPath('data.likes_count', 1)->assertJsonPath('data.liked_by_me', false);
        Sanctum::actingAs($actor);
        $this->deleteJson($path)->assertOk()->assertJsonPath('data.likes_count', 0)->assertJsonPath('data.liked_by_me', false);
        $this->deleteJson($path)->assertOk()->assertJsonPath('data.likes_count', 0);
        $this->assertDatabaseCount('activity_likes', 0);
        $this->putJson($path)->assertOk()->assertJsonPath('data.likes_count', 1);
        $this->assertDatabaseCount('activity_likes', 1);
    }

    public function test_rejects_unauthenticated_self_forged_and_cross_family_operations(): void
    {
        [$group, $owner, $actor, $log] = $this->family();
        [$otherGroup, , , $otherLog] = $this->family();
        $path = "/v1/groups/{$group->id}/logs/{$log->id}/like";
        $this->putJson($path)->assertUnauthorized();
        Sanctum::actingAs($owner);
        $this->putJson($path)->assertForbidden();
        $this->deleteJson($path)->assertForbidden();
        Sanctum::actingAs($actor);
        $this->putJson($path, ['user_id' => $owner->id])->assertUnprocessable();
        $this->deleteJson($path, ['likes_count' => 0])->assertUnprocessable();
        $this->putJson("/v1/groups/{$group->id}/logs/{$otherLog->id}/like")->assertNotFound();
        $this->putJson("/v1/groups/{$otherGroup->id}/logs/{$otherLog->id}/like")->assertNotFound();
        $this->assertDatabaseCount('activity_likes', 0);
    }

    public function test_deactivation_hides_likes_without_deleting_and_restoration_counts_them_again(): void
    {
        [$group, $owner, $actor, $log] = $this->family();
        $actorMembership = $actor->membership;
        Sanctum::actingAs($actor);
        $this->putJson("/v1/groups/{$group->id}/logs/{$log->id}/like")->assertOk();
        $actorMembership->update(['status' => 'inactive']);
        $this->deleteJson("/v1/groups/{$group->id}/logs/{$log->id}/like")->assertNotFound();
        Sanctum::actingAs($owner);
        $this->getJson("/v1/groups/{$group->id}/logs")->assertJsonPath('data.0.likes_count', 0);
        $this->assertDatabaseCount('activity_likes', 1);
        $actorMembership->update(['status' => 'active']);
        $this->getJson("/v1/groups/{$group->id}/logs")->assertJsonPath('data.0.likes_count', 1);
        $owner->membership->update(['status' => 'inactive']);
        Sanctum::actingAs($actor);
        $this->getJson("/v1/groups/{$group->id}/logs")->assertJsonCount(0, 'data');
        $this->getJson("/v1/groups/{$group->id}/logs/{$log->id}")->assertNotFound();
        $this->putJson("/v1/groups/{$group->id}/logs/{$log->id}/like")->assertNotFound();
        $owner->membership->update(['status' => 'active']);
        $this->getJson("/v1/groups/{$group->id}/logs")->assertJsonPath('data.0.likes_count', 1);
    }

    public function test_activity_date_category_and_recipient_control_counts_and_deletion_cascades(): void
    {
        [$group, $owner, $actor, $log] = $this->family();
        Sanctum::actingAs($actor);
        $this->putJson("/v1/groups/{$group->id}/logs/{$log->id}/like")->assertOk();
        $filter = "/v1/groups/{$group->id}/logs?from=2026-10-08&to=2026-10-08&user_id={$owner->id}&category_id={$log->category_id}";
        $this->getJson($filter)->assertJsonPath('data.0.likes_count', 1)->assertJsonPath('total', 1);
        $this->getJson("/v1/groups/{$group->id}/logs?user_id={$actor->id}")->assertJsonCount(0, 'data');
        Sanctum::actingAs($owner);
        $this->putJson("/v1/groups/{$group->id}/logs/{$log->id}", ['activity_date' => '2026-09-30', 'content' => '編集後'])->assertJsonPath('data.likes_count', 1);
        $this->getJson($filter)->assertJsonCount(0, 'data');
        $category = Category::query()->create(['group_id' => $group->id, 'name' => '運動', 'color_code' => '#000000', 'sort_order' => 2]);
        $this->putJson("/v1/groups/{$group->id}/logs/{$log->id}", ['category_id' => $category->id])->assertJsonPath('data.likes_count', 1);
        $this->getJson("/v1/groups/{$group->id}/logs?category_id={$log->category_id}")->assertJsonCount(0, 'data');
        $this->deleteJson("/v1/groups/{$group->id}/logs/{$log->id}")->assertOk();
        $this->assertDatabaseCount('activity_likes', 0);
    }

    public function test_rate_limit_is_shared_across_methods_cards_and_tokens_and_recovers(): void
    {
        config(['famie.like_rate_limit_per_second' => 2]);
        $this->freezeTime();
        [$group, , $actor, $log] = $this->family();
        $other = $log->replicate();
        $other->save();
        $first = $actor->createToken('first')->plainTextToken;
        $second = $actor->createToken('second')->plainTextToken;
        $this->withToken($first)->putJson("/v1/groups/{$group->id}/logs/{$log->id}/like")->assertOk();
        $this->withToken($second)->deleteJson("/v1/groups/{$group->id}/logs/{$log->id}/like")->assertOk();
        $this->withToken($first)->putJson("/v1/groups/{$group->id}/logs/{$other->id}/like")->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertDatabaseCount('activity_likes', 0);
        $this->travel(2)->seconds();
        $this->withToken($second)->putJson("/v1/groups/{$group->id}/logs/{$other->id}/like")->assertOk();
        $this->assertDatabaseCount('activity_likes', 1);
        config(['famie.like_rate_limit_per_second' => 3]);
        $this->withToken($first)->putJson("/v1/groups/{$group->id}/logs/{$log->id}/like")->assertOk();
        $this->withToken($first)->deleteJson("/v1/groups/{$group->id}/logs/{$log->id}/like")->assertOk();
    }

    #[TestWith(['activity'])]
    #[TestWith(['member'])]
    #[TestWith(['duplicate'])]
    public function test_database_rejects_cross_family_references_and_duplicate_likes(string $kind): void
    {
        [$group, , $actor, $log] = $this->family();
        [, , $otherActor, $otherLog] = $this->family();
        $attributes = ['group_id' => $group->id, 'activity_log_id' => $log->id, 'user_id' => $actor->id];
        ActivityLike::query()->create($attributes);
        if ($kind === 'activity') {
            $attributes['activity_log_id'] = $otherLog->id;
        } elseif ($kind === 'member') {
            $attributes['user_id'] = $otherActor->id;
        }
        $this->expectException(QueryException::class);
        ActivityLike::query()->create($attributes);
    }

    public function test_list_query_count_does_not_grow_per_card_and_counts_only_own_family(): void
    {
        [$group, , $actor, $log] = $this->family();
        Sanctum::actingAs($actor);
        $this->putJson("/v1/groups/{$group->id}/logs/{$log->id}/like")->assertOk();
        DB::enableQueryLog();
        $this->getJson("/v1/groups/{$group->id}/logs")->assertJsonPath('data.0.likes_count', 1);
        $singleCount = count(DB::getQueryLog());
        for ($i = 0; $i < 12; $i++) {
            $copy = $log->replicate();
            $copy->save();
        }
        DB::flushQueryLog();
        $this->getJson("/v1/groups/{$group->id}/logs")->assertJsonPath('total', 13);
        $this->assertSame($singleCount, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    /** @return array{Group, User, User, ActivityLog} */
    private function family(string $actorRole = 'member'): array
    {
        $group = Group::factory()->create();
        $owner = User::factory()->create();
        $actor = User::factory()->create();
        GroupMember::query()->create(['group_id' => $group->id, 'user_id' => $owner->id, 'role' => 'admin', 'status' => 'active']);
        GroupMember::query()->create(['group_id' => $group->id, 'user_id' => $actor->id, 'role' => $actorRole, 'status' => 'active']);
        $category = Category::query()->create(['group_id' => $group->id, 'name' => '勉強', 'color_code' => '#000000', 'sort_order' => 1]);
        $log = ActivityLog::query()->create(['group_id' => $group->id, 'user_id' => $owner->id, 'category_id' => $category->id, 'activity_date' => '2026-10-08', 'content' => '記録']);

        return [$group, $owner, $actor, $log];
    }
}
