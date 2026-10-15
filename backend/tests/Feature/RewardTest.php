<?php

namespace Tests\Feature;

use App\Models\ActivityLike;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Reward;
use App\Models\RewardAward;
use App\Services\RewardService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RewardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['famie.allowed_ips' => ['127.0.0.1'], 'famie.like_rate_limit_per_second' => 100]);
    }

    private function family(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00', 'UTC'));
        $group = Group::factory()->create();
        $admin = GroupMember::factory()->create(['group_id' => $group->id, 'role' => 'admin']);
        $child = GroupMember::factory()->create(['group_id' => $group->id, 'role' => 'member']);
        $category = Category::query()->create(['group_id' => $group->id, 'name' => '勉強', 'sort_order' => 1, 'color_code' => '#123456']);
        Sanctum::actingAs($admin->user);

        return [$group, $admin, $child, $category];
    }

    private function payload(GroupMember $child, array $overrides = []): array
    {
        return [...['name' => '勉強10日', 'description' => 'おやつ', 'metric' => 'activities', 'color' => 'bronze', 'required_count' => 2, 'category_id' => null, 'target_ids' => [$child->id], 'start_month' => '2026-10', 'cycle_months' => 1, 'timezone' => 'UTC'], ...$overrides];
    }

    private function createReward(Group $group, GroupMember $child, array $overrides = []): int
    {
        return $this->postJson("/v1/groups/{$group->id}/rewards", $this->payload($child, $overrides))->assertCreated()->json('data.id');
    }

    private function activate(Group $group, int $id): void
    {
        $this->putJson("/v1/groups/{$group->id}/rewards/{$id}/status", ['status' => 'active', 'revision' => 1])->assertOk();
    }

    public function test_a01_existing_ten_days_award_immediately_on_activation(): void
    {
        [$group, $admin, $child, $category] = $this->family();
        for ($day = 1; $day <= 10; $day++) {
            ActivityLog::query()->create(['group_id' => $group->id, 'user_id' => $child->user_id, 'category_id' => $category->id, 'activity_date' => sprintf('2026-10-%02d', $day), 'content' => '定義前の活動']);
        }
        $id = $this->createReward($group, $child, ['required_count' => 10]);
        $this->assertDatabaseCount('reward_awards', 0);
        $this->activate($group, $id);
        $this->assertSame(10, RewardAward::query()->sole()->achieved_count);
    }

    public function test_a02_category_filter_precedes_distinct_activity_dates(): void
    {
        [$group, $admin, $child, $category] = $this->family();
        $other = Category::query()->create(['group_id' => $group->id, 'name' => '運動', 'sort_order' => 2]);
        $id = $this->createReward($group, $child, ['required_count' => 3, 'category_id' => $category->id]);
        $this->activate($group, $id);
        Sanctum::actingAs($child->user);
        foreach ([[$category->id, '01'], [$category->id, '01'], [$other->id, '02'], [$category->id, '03']] as [$categoryId, $day]) {
            $this->postJson("/v1/groups/{$group->id}/logs", ['category_id' => $categoryId, 'activity_date' => '2026-10-'.$day, 'content' => '活動'])->assertCreated();
        }
        $this->getJson("/v1/groups/{$group->id}/rewards")->assertJsonPath('data.0.progress', 2);
        $this->assertDatabaseCount('reward_awards', 0);
        $this->postJson("/v1/groups/{$group->id}/logs", ['category_id' => $category->id, 'activity_date' => '2026-10-02', 'content' => '絞り込み後に加算'])->assertCreated();
        $this->assertSame(3, RewardAward::query()->sole()->achieved_count);
    }

    public function test_a05_removed_like_lowers_progress_but_preserves_award(): void
    {
        [$group, $admin, $child, $category] = $this->family();
        $id = $this->createReward($group, $child, ['metric' => 'likes', 'required_count' => 10]);
        $this->activate($group, $id);
        for ($i = 0; $i < 10; $i++) {
            $log = ActivityLog::query()->create(['group_id' => $group->id, 'user_id' => $child->user_id, 'category_id' => $category->id, 'activity_date' => '2026-10-01', 'content' => '活動']);
            $this->putJson("/v1/groups/{$group->id}/logs/{$log->id}/like")->assertOk();
        }
        $this->deleteJson("/v1/groups/{$group->id}/logs/{$log->id}/like")->assertOk();
        Sanctum::actingAs($child->user);
        $this->getJson("/v1/groups/{$group->id}/rewards")->assertJsonPath('data.0.progress', 9)->assertJsonPath('data.0.achieved_count', 10)->assertJsonPath('data.0.awarded', true);
        $this->assertDatabaseCount('reward_awards', 1);
    }

    public function test_a04_tenth_like_after_deadline_does_not_award_expired_period(): void
    {
        [$group, $admin, $child, $category] = $this->family();
        $id = $this->createReward($group, $child, ['metric' => 'likes', 'required_count' => 10]);
        $this->activate($group, $id);
        for ($i = 0; $i < 10; $i++) {
            $log = ActivityLog::query()->create(['group_id' => $group->id, 'user_id' => $child->user_id, 'category_id' => $category->id, 'activity_date' => '2026-10-01', 'content' => '活動']);
            if ($i < 9) {
                $this->putJson("/v1/groups/{$group->id}/logs/{$log->id}/like")->assertOk();
            }
        }
        $this->assertDatabaseCount('reward_awards', 0);
        $this->travelTo(CarbonImmutable::parse('2026-11-01', 'UTC'));
        $this->putJson("/v1/groups/{$group->id}/logs/{$log->id}/like")->assertOk();
        $service = app(RewardService::class);
        $this->assertSame(10, $service->count($group, $service->currentVersion(Reward::query()->findOrFail($id)), $child, ['start' => CarbonImmutable::parse('2026-10-01', 'UTC'), 'end' => CarbonImmutable::parse('2026-11-01', 'UTC')]));
        $this->assertDatabaseCount('reward_awards', 0);
    }

    public function test_a10_recreated_same_name_is_independent_and_old_award_survives(): void
    {
        [$group, $admin, $child, $category] = $this->family();
        ActivityLog::query()->create(['group_id' => $group->id, 'user_id' => $child->user_id, 'category_id' => $category->id, 'activity_date' => '2026-10-01', 'content' => '活動']);
        $old = $this->createReward($group, $child, ['required_count' => 1]);
        $this->activate($group, $old);
        $this->putJson("/v1/groups/{$group->id}/rewards/{$old}/status", ['revision' => 2, 'status' => 'stopped'])->assertOk();
        $this->deleteJson("/v1/groups/{$group->id}/rewards/{$old}", ['revision' => 3])->assertOk();
        $new = $this->createReward($group, $child, ['required_count' => 1]);
        $this->assertNotSame($old, $new);
        $this->activate($group, $new);
        $this->assertDatabaseCount('reward_awards', 2);
        Sanctum::actingAs($child->user);
        $this->getJson("/v1/groups/{$group->id}/rewards/awards")->assertJsonPath('data.0.deleted', false)->assertJsonPath('data.1.deleted', true);
    }

    public function test_a14_missing_timezone_defaults_to_utc_and_cannot_be_changed(): void
    {
        [$group, $admin, $child] = $this->family();
        $payload = $this->payload($child);
        unset($payload['timezone']);
        $id = $this->postJson("/v1/groups/{$group->id}/rewards", $payload)->assertCreated()->assertJsonPath('data.timezone', 'UTC')->json('data.id');
        $this->putJson("/v1/groups/{$group->id}/rewards/{$id}", [...$payload, 'timezone' => 'America/Los_Angeles', 'revision' => 1])->assertUnprocessable();
        $this->getJson("/v1/groups/{$group->id}/rewards/manage")->assertJsonPath('data.0.timezone', 'UTC');
    }

    public function test_a15_failed_preparation_rolls_back_and_retry_recovers_without_duplicate_awards(): void
    {
        [$group, $admin, $child, $category] = $this->family();
        $id = $this->createReward($group, $child, ['required_count' => 1]);
        $this->activate($group, $id);
        ActivityLog::query()->create(['group_id' => $group->id, 'user_id' => $child->user_id, 'category_id' => $category->id, 'activity_date' => '2026-10-01', 'content' => '既存活動']);
        $token = now()->format('Y-m-d H:i:s');
        $group->forceFill(['reward_sync_status' => 'processing', 'reward_sync_started_at' => $token])->save();
        $failing = Mockery::mock(RewardService::class)->makePartial();
        $failing->shouldReceive('evaluate')->once()->andReturnUsing(function (Group $locked): void {
            (new RewardService)->evaluate($locked);
            throw new \RuntimeException('Injected failure after award creation');
        });
        $failing->prepare($group->id, $token);
        $this->assertSame('failed', $group->fresh()->reward_sync_status);
        $this->assertDatabaseCount('reward_awards', 0);
        $this->assertDatabaseCount('reward_periods', 0);
        $this->postJson("/v1/groups/{$group->id}/rewards/prepare")->assertJsonPath('status', 'processing');
        $this->assertSame('complete', $group->fresh()->reward_sync_status);
        $this->assertDatabaseCount('reward_awards', 1);
        $this->postJson("/v1/groups/{$group->id}/rewards/prepare")->assertJsonPath('status', 'complete');
        $this->assertDatabaseCount('reward_awards', 1);
        $this->putJson("/v1/groups/{$group->id}/rewards/{$id}/status", ['revision' => 2, 'status' => 'stopped'])->assertOk();
    }

    public function test_a15_expired_processing_can_restart_but_old_worker_cannot_complete_it(): void
    {
        [$group, $admin, $child] = $this->family();
        $id = $this->createReward($group, $child);
        $this->activate($group, $id);
        $old = now()->subMinutes(3)->format('Y-m-d H:i:s');
        $current = now()->format('Y-m-d H:i:s');
        $group->forceFill(['reward_sync_status' => 'processing', 'reward_sync_started_at' => $current])->save();
        app(RewardService::class)->prepare($group->id, $old);
        $this->assertSame('processing', $group->fresh()->reward_sync_status);
        $group->refresh()->forceFill(['reward_sync_started_at' => $old])->save();
        $this->postJson("/v1/groups/{$group->id}/rewards/prepare")->assertJsonPath('status', 'processing');
        $this->assertSame('complete', $group->fresh()->reward_sync_status);
    }

    #[TestWith(['2026-10', 1, 'Asia/Tokyo', 31])]
    #[TestWith(['2026-11', 1, 'UTC', 30])]
    #[TestWith(['2027-02', 1, 'UTC', 28])]
    #[TestWith(['2028-02', 1, 'UTC', 29])]
    #[TestWith(['2026-10', 3, 'UTC', 92])]
    #[TestWith(['2027-03', 1, 'America/New_York', 31])]
    public function test_period_days_awards_only_after_every_day_and_freezes_threshold(string $month, int $cycle, string $zone, int $days): void
    {
        [$group, $admin, $child, $category] = $this->family();
        $start = CarbonImmutable::parse($month.'-01', $zone);
        $end = $start->addMonthsNoOverflow($cycle);
        $this->travelTo($start);
        $id = $this->createReward($group, $child, ['start_month' => $month, 'cycle_months' => $cycle, 'timezone' => $zone, 'count_mode' => 'period_days', 'required_count' => null]);
        $this->activate($group, $id);
        $this->travelTo($end->subHour());
        for ($day = $start; $day->lessThan($end->subDay()); $day = $day->addDay()) {
            ActivityLog::query()->create(['group_id' => $group->id, 'user_id' => $child->user_id, 'category_id' => $category->id, 'content' => '活動', 'activity_date' => $day->toDateString()]);
        }
        DB::transaction(fn () => app(RewardService::class)->evaluate($group));
        $this->assertDatabaseCount('reward_awards', 0);
        $this->getJson("/v1/groups/{$group->id}/rewards/manage")->assertJsonPath('data.0.version.required_count', $days);
        Sanctum::actingAs($child->user);
        $this->postJson("/v1/groups/{$group->id}/logs", ['category_id' => $category->id, 'activity_date' => $end->subDay()->toDateString(), 'content' => '最後の日'])->assertCreated();
        $this->assertSame($days, RewardAward::query()->sole()->snapshot['required_count']);
        $this->travelTo($end->addDay());
        $this->getJson("/v1/groups/{$group->id}/rewards/awards")->assertJsonPath('data.0.snapshot.required_count', $days);
    }

    public function test_period_day_mode_change_is_reserved_for_next_cycle(): void
    {
        [$group, $admin, $child] = $this->family();
        $id = $this->createReward($group, $child);
        $this->activate($group, $id);
        $this->putJson("/v1/groups/{$group->id}/rewards/{$id}", [...$this->payload($child, ['count_mode' => 'period_days', 'required_count' => null]), 'revision' => 2])->assertOk()->assertJsonPath('data.version.required_count', 2)->assertJsonPath('data.pending_version.required_count', 30);
        $this->travelTo(CarbonImmutable::parse('2026-11-02', 'UTC'));
        $this->getJson("/v1/groups/{$group->id}/rewards/manage")->assertJsonPath('data.0.version.count_mode', 'period_days')->assertJsonPath('data.0.version.required_count', 30);
        $this->putJson("/v1/groups/{$group->id}/rewards/{$id}", [...$this->payload($child, ['required_count' => 5]), 'revision' => 3])->assertOk()->assertJsonPath('data.pending_version.count_mode', 'fixed');
    }

    public function test_period_days_likes_count_total_even_on_a_single_activity_date(): void
    {
        [$group, $admin, $child, $category] = $this->family();
        for ($i = 0; $i < 31; $i++) {
            $log = ActivityLog::query()->create(['group_id' => $group->id, 'user_id' => $child->user_id, 'category_id' => $category->id, 'content' => '活動', 'activity_date' => '2026-10-01']);
            ActivityLike::query()->create(['group_id' => $group->id, 'activity_log_id' => $log->id, 'user_id' => $admin->user_id]);
        }
        $id = $this->createReward($group, $child, ['metric' => 'likes', 'count_mode' => 'period_days', 'required_count' => null]);
        $this->activate($group, $id);
        $this->assertSame(31, RewardAward::query()->sole()->snapshot['required_count']);
        $this->assertSame(31, RewardAward::query()->sole()->achieved_count);
    }

    public function test_period_days_does_not_accept_client_supplied_threshold(): void
    {
        [$group, $admin, $child] = $this->family();
        $this->postJson("/v1/groups/{$group->id}/rewards", $this->payload($child, ['count_mode' => 'period_days', 'required_count' => 1]))->assertUnprocessable();
        $this->postJson("/v1/groups/{$group->id}/rewards", $this->payload($child, ['count_mode' => 'unknown']))->assertUnprocessable();
    }

    public function test_activity_days_award_once_and_survive_deletion(): void
    {
        [$group,$admin,$child,$category] = $this->family();
        $id = $this->createReward($group, $child);
        $this->activate($group, $id);
        Sanctum::actingAs($child->user);
        $path = "/v1/groups/{$group->id}/logs";
        $payload = ['category_id' => $category->id, 'activity_date' => '2026-10-01', 'content' => '勉強'];
        $this->postJson($path, $payload)->assertCreated();
        $this->postJson($path, $payload)->assertCreated();
        $this->assertDatabaseCount('reward_awards', 0);
        $log = $this->postJson($path, [...$payload, 'activity_date' => '2026-10-02'])->assertCreated()->json('data.id');
        $this->assertDatabaseCount('reward_awards', 1);
        $this->deleteJson("$path/$log")->assertOk();
        $this->getJson("/v1/groups/{$group->id}/rewards")->assertJsonPath('data.0.progress', 1)->assertJsonPath('data.0.awarded', true)->assertJsonPath('data.0.achieved_count', 2);
        Sanctum::actingAs($admin->user);
        $this->putJson("/v1/groups/{$group->id}/rewards/{$id}/status", ['status' => 'stopped', 'revision' => 2])->assertOk();
        $this->deleteJson("/v1/groups/{$group->id}/rewards/{$id}", ['revision' => 3])->assertOk();
        Sanctum::actingAs($child->user);
        $this->getJson("/v1/groups/{$group->id}/rewards/awards")->assertJsonPath('data.0.deleted', true)->assertJsonPath('data.0.snapshot.name', '勉強10日');
        $this->assertDatabaseCount('reward_awards', 1);
    }

    public function test_previous_month_likes_never_award_after_end_and_new_cycle_is_independent(): void
    {
        [$group,$admin,$child,$category] = $this->family();
        $id = $this->createReward($group, $child, ['metric' => 'likes', 'required_count' => 1]);
        $this->activate($group, $id);
        $log = ActivityLog::query()->create(['group_id' => $group->id, 'user_id' => $child->user_id, 'category_id' => $category->id, 'content' => '勉強', 'activity_date' => '2026-10-10']);
        $this->travelTo(CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC'));
        $this->putJson("/v1/groups/{$group->id}/logs/{$log->id}/like")->assertOk();
        $this->assertDatabaseCount('reward_awards', 0);
        $new = ActivityLog::query()->create(['group_id' => $group->id, 'user_id' => $child->user_id, 'category_id' => $category->id, 'content' => '勉強', 'activity_date' => '2026-11-01']);
        $this->putJson("/v1/groups/{$group->id}/logs/{$new->id}/like")->assertOk();
        $this->assertDatabaseCount('reward_awards', 1);
        $this->assertSame('2026-11-01', RewardAward::query()->sole()->snapshot['starts_on']);
    }

    public function test_edit_is_reserved_and_restart_includes_stopped_days(): void
    {
        [$group,$admin,$child,$category] = $this->family();
        $id = $this->createReward($group, $child);
        $this->activate($group, $id);
        $this->putJson("/v1/groups/{$group->id}/rewards/{$id}/status", ['status' => 'stopped', 'revision' => 2])->assertOk();
        $this->putJson("/v1/groups/{$group->id}/rewards/{$id}", [...$this->payload($child, ['required_count' => 3]), 'revision' => 3])->assertOk()->assertJsonPath('data.version.required_count', 2)->assertJsonPath('data.pending_version.required_count', 3);
        Sanctum::actingAs($child->user);
        foreach (['2026-10-01', '2026-10-02'] as $date) {
            $this->postJson("/v1/groups/{$group->id}/logs", ['category_id' => $category->id, 'activity_date' => $date, 'content' => '勉強'])->assertCreated();
        }
        $this->assertDatabaseCount('reward_awards', 0);
        Sanctum::actingAs($admin->user);
        $this->putJson("/v1/groups/{$group->id}/rewards/{$id}/status", ['status' => 'active', 'revision' => 4])->assertOk();
        $this->assertDatabaseCount('reward_awards', 1);
        $this->assertSame(2, RewardAward::query()->sole()->snapshot['required_count']);
        $this->travelTo(CarbonImmutable::parse('2026-11-05', 'UTC'));
        $this->getJson("/v1/groups/{$group->id}/rewards/manage")->assertJsonPath('data.0.version.required_count', 3);
    }

    public function test_future_edit_applies_initially_and_timezone_remains_fixed(): void
    {
        [$group,$admin,$child] = $this->family();
        $id = $this->createReward($group, $child, ['start_month' => '2026-11', 'timezone' => 'Asia/Tokyo', 'cycle_months' => 3]);
        $this->activate($group, $id);
        $payload = $this->payload($child, ['start_month' => '2026-11', 'timezone' => 'Asia/Tokyo', 'cycle_months' => 3, 'required_count' => 4]);
        $this->putJson("/v1/groups/{$group->id}/rewards/{$id}", [...$payload, 'revision' => 2])->assertOk()->assertJsonPath('data.version.required_count', 4)->assertJsonPath('data.ends_on', '2027-01-31');
        $this->putJson("/v1/groups/{$group->id}/rewards/{$id}", [...$payload, 'timezone' => 'UTC', 'revision' => 3])->assertUnprocessable();
        $this->assertDatabaseCount('reward_versions', 1);
    }

    public function test_limits_category_reference_and_draft_disposal(): void
    {
        [$group,$admin,$child,$category] = $this->family();
        $id = $this->createReward($group, $child, ['category_id' => $category->id]);
        $this->deleteJson("/v1/groups/{$group->id}/categories/{$category->id}")->assertUnprocessable();
        $this->putJson("/v1/groups/{$group->id}/rewards/{$id}/status", ['status' => 'stopped', 'revision' => 1])->assertConflict();
        $this->deleteJson("/v1/groups/{$group->id}/rewards/{$id}", ['revision' => 1])->assertOk();
        $this->assertDatabaseMissing('rewards', ['id' => $id]);
        $this->assertDatabaseCount('reward_versions', 0);
        $this->assertDatabaseCount('reward_targets', 0);
        $this->deleteJson("/v1/groups/{$group->id}/categories/{$category->id}")->assertOk();
        for ($i = 0; $i < 6; $i++) {
            $next = $this->createReward($group, $child);
            if ($i < 3) {
                $this->activate($group, $next);
            }
        }
        $this->postJson("/v1/groups/{$group->id}/rewards", $this->payload($child))->assertConflict()->assertJsonPath('limit', 6);
        $this->putJson("/v1/groups/{$group->id}/rewards/{$next}/status", ['status' => 'active', 'revision' => 1])->assertConflict()->assertJsonPath('limit', 3);
    }

    public function test_draft_is_not_evaluated_until_activation_even_after_editing(): void
    {
        [$group, $admin, $child, $category] = $this->family();
        $id = $this->createReward($group, $child, ['required_count' => 1]);
        Sanctum::actingAs($child->user);
        $this->postJson("/v1/groups/{$group->id}/logs", ['category_id' => $category->id, 'activity_date' => '2026-10-01', 'content' => '下書き中の活動'])->assertCreated();
        $this->postJson("/v1/groups/{$group->id}/rewards/prepare")->assertJsonPath('status', 'complete');
        Sanctum::actingAs($admin->user);
        $this->putJson("/v1/groups/{$group->id}/rewards/{$id}", [...$this->payload($child), 'required_count' => 1, 'revision' => 1])->assertOk();
        foreach (['reward_progress', 'reward_periods', 'reward_awards'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->putJson("/v1/groups/{$group->id}/rewards/{$id}/status", ['status' => 'active', 'revision' => 2])->assertOk();
        $this->assertDatabaseCount('reward_awards', 1);
        $this->putJson("/v1/groups/{$group->id}/rewards/{$id}/status", ['status' => 'draft', 'revision' => 3])->assertUnprocessable();
        $this->deleteJson("/v1/groups/{$group->id}/rewards/{$id}", ['revision' => 3])->assertConflict();
    }

    public function test_draft_with_unexpected_progress_cannot_be_physically_deleted(): void
    {
        [$group, $admin, $child] = $this->family();
        $id = $this->createReward($group, $child);
        DB::table('reward_progress')->insert(['group_id' => $group->id, 'reward_id' => $id, 'group_member_id' => $child->id, 'starts_on' => '2026-10-01', 'count' => 0, 'updated_at' => now()]);
        $this->deleteJson("/v1/groups/{$group->id}/rewards/{$id}", ['revision' => 1])->assertConflict();
        $this->assertDatabaseHas('rewards', ['id' => $id, 'status' => 'draft']);
        $this->assertDatabaseCount('reward_targets', 1);
    }

    public function test_notification_is_consumed_once_and_other_children_cannot_read_awards(): void
    {
        [$group,$admin,$child,$category] = $this->family();
        ActivityLog::query()->create(['group_id' => $group->id, 'user_id' => $child->user_id, 'category_id' => $category->id, 'content' => '勉強', 'activity_date' => '2026-10-01']);
        $id = $this->createReward($group, $child, ['required_count' => 1]);
        $this->activate($group, $id);
        $this->postJson("/v1/groups/{$group->id}/rewards/notifications")->assertJsonCount(0, 'data');
        Sanctum::actingAs($child->user);
        $this->postJson("/v1/groups/{$group->id}/rewards/notifications")->assertJsonCount(1, 'data');
        $this->postJson("/v1/groups/{$group->id}/rewards/notifications")->assertJsonCount(0, 'data');
        $this->assertNotNull(RewardAward::query()->sole()->read_at);
        $other = GroupMember::factory()->create(['group_id' => $group->id]);
        Sanctum::actingAs($other->user);
        $this->getJson("/v1/groups/{$group->id}/rewards/awards?member_id={$child->id}")->assertForbidden();
        $this->getJson("/v1/groups/{$group->id}/rewards/manage")->assertForbidden();
    }

    public function test_cross_family_and_forged_fields_are_rejected(): void
    {
        [$group,$admin,$child] = $this->family();
        $foreign = GroupMember::factory()->create();
        $this->postJson("/v1/groups/{$group->id}/rewards", $this->payload($foreign))->assertUnprocessable();
        $this->postJson("/v1/groups/{$group->id}/rewards", [...$this->payload($child), 'group_id' => $foreign->group_id])->assertUnprocessable();
        $this->getJson("/v1/groups/{$foreign->group_id}/rewards")->assertNotFound();
        $this->assertDatabaseCount('rewards', 0);
    }

    public function test_future_preparation_awards_current_period_and_skips_missed_periods(): void
    {
        [$group,$admin,$child,$category] = $this->family();
        $id = $this->createReward($group, $child, ['start_month' => '2026-11', 'required_count' => 1]);
        $this->activate($group, $id);
        ActivityLog::query()->create(['group_id' => $group->id, 'user_id' => $child->user_id, 'category_id' => $category->id, 'activity_date' => '2026-11-01', 'content' => '既存活動']);
        $this->assertDatabaseCount('reward_awards', 0);
        $this->travelTo(CarbonImmutable::parse('2026-11-03', 'UTC'));
        $this->postJson("/v1/groups/{$group->id}/rewards/prepare")->assertJsonPath('status', 'processing');
        $this->assertDatabaseCount('reward_awards', 1);
        $this->assertSame('complete', $group->fresh()->reward_sync_status);
        $this->travelTo(CarbonImmutable::parse('2027-01-03', 'UTC'));
        $this->postJson("/v1/groups/{$group->id}/rewards/prepare")->assertJsonPath('status', 'processing');
        $this->assertDatabaseCount('reward_awards', 1);
        $this->assertSame('2027-01-01', Reward::query()->findOrFail($id)->prepared_period->toDateString());
    }

    public function test_stopped_progress_is_frozen_and_member_restore_preserves_awards(): void
    {
        [$group,$admin,$child,$category] = $this->family();
        $id = $this->createReward($group, $child, ['required_count' => 1]);
        $this->activate($group, $id);
        $this->putJson("/v1/groups/{$group->id}/rewards/{$id}/status", ['status' => 'stopped', 'revision' => 2])->assertOk();
        Sanctum::actingAs($child->user);
        $this->postJson("/v1/groups/{$group->id}/logs", ['category_id' => $category->id, 'activity_date' => '2026-10-01', 'content' => '停止中活動'])->assertCreated();
        $this->getJson("/v1/groups/{$group->id}/rewards")->assertJsonPath('data.0.progress', 0);
        Sanctum::actingAs($admin->user);
        $this->putJson("/v1/groups/{$group->id}/rewards/{$id}/status", ['status' => 'active', 'revision' => 3])->assertOk();
        $this->assertDatabaseCount('reward_awards', 1);
        $this->putJson("/v1/groups/{$group->id}/members/{$child->id}/status", ['status' => 'inactive'])->assertOk();
        $this->getJson("/v1/groups/{$group->id}/rewards/awards?member_id={$child->id}")->assertNotFound();
        $this->putJson("/v1/groups/{$group->id}/members/{$child->id}/status", ['status' => 'active'])->assertOk();
        $this->getJson("/v1/groups/{$group->id}/rewards/awards?member_id={$child->id}")->assertJsonCount(1, 'data');
    }

    public function test_saved_timezone_changes_three_month_period_at_exact_boundary(): void
    {
        [$group,$admin,$child] = $this->family();
        $id = $this->createReward($group, $child, ['timezone' => 'Asia/Tokyo', 'cycle_months' => 3]);
        $this->activate($group, $id);
        $this->travelTo(CarbonImmutable::parse('2026-11-01', 'Asia/Tokyo'));
        $this->getJson("/v1/groups/{$group->id}/rewards/manage")->assertJsonPath('data.0.starts_on', '2026-10-01')->assertJsonPath('data.0.ends_on', '2026-12-31');
        $this->travelTo(CarbonImmutable::parse('2026-12-31 14:59:59', 'UTC'));
        $this->getJson("/v1/groups/{$group->id}/rewards/manage")->assertJsonPath('data.0.starts_on', '2026-10-01')->assertJsonPath('data.0.ends_on', '2026-12-31');
        $this->travelTo(CarbonImmutable::parse('2026-12-31 15:00:00', 'UTC'));
        $this->getJson("/v1/groups/{$group->id}/rewards/manage")->assertJsonPath('data.0.starts_on', '2027-01-01')->assertJsonPath('data.0.ends_on', '2027-03-31');
    }

    public function test_processing_allows_reads_and_logs_but_refuses_management_updates(): void
    {
        [$group,$admin,$child,$category] = $this->family();
        $id = $this->createReward($group, $child, ['required_count' => 1]);
        $this->activate($group, $id);
        $likeReward = $this->createReward($group, $child, ['metric' => 'likes', 'required_count' => 1]);
        $this->activate($group, $likeReward);
        $group->forceFill(['reward_sync_status' => 'processing', 'reward_sync_started_at' => now()])->save();
        $this->getJson("/v1/groups/{$group->id}/rewards/manage")->assertOk()->assertJsonPath('sync_status', 'processing');
        $this->putJson("/v1/groups/{$group->id}/rewards/{$id}/status", ['status' => 'stopped', 'revision' => 2])->assertConflict();
        Sanctum::actingAs($child->user);
        $this->travelTo(CarbonImmutable::parse('2026-11-01', 'UTC'));
        $this->getJson("/v1/groups/{$group->id}/rewards")->assertJsonPath('data.0.progress', null);
        $this->travelTo(CarbonImmutable::parse('2026-10-13', 'UTC'));
        $this->postJson("/v1/groups/{$group->id}/logs", ['category_id' => $category->id, 'activity_date' => '2026-10-01', 'content' => '集計中活動'])->assertCreated();
        $this->assertDatabaseCount('reward_awards', 1);
        Sanctum::actingAs($admin->user);
        $log = ActivityLog::query()->sole();
        $this->putJson("/v1/groups/{$group->id}/logs/{$log->id}/like")->assertOk();
        $this->assertDatabaseCount('reward_awards', 2);
    }

    #[TestWith(['required_count', 0])]
    #[TestWith(['required_count', 10001])]
    #[TestWith(['cycle_months', 13])]
    #[TestWith(['metric', 'weighted'])]
    #[TestWith(['color', 'red'])]
    #[TestWith(['name', []])]
    #[TestWith(['target_ids', []])]
    #[TestWith(['timezone', 'invalid-zone'])]
    public function test_invalid_definition_returns_422_without_saving(string $field, mixed $value): void
    {
        [$group,$admin,$child] = $this->family();
        $this->postJson("/v1/groups/{$group->id}/rewards", $this->payload($child, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('rewards', 0);
    }
}
