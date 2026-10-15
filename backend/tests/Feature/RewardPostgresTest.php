<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Reward;
use App\Models\RewardVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RewardPostgresTest extends TestCase
{
    private ?string $schema = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('FAMIE_REWARD_PG_TEST') !== '1') {
            $this->markTestSkipped('Requires explicitly enabled isolated PostgreSQL reward schema.');
        }
        $this->assertSame('pgsql', DB::getDriverName());
        $this->schema = 'famie_rewards_'.getmypid().'_'.bin2hex(random_bytes(4)).'_test';
        DB::statement('CREATE SCHEMA '.$this->schema);
        DB::select("select set_config('search_path', ?, false)", [$this->schema]);
        Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->schema !== null) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::select("select set_config('search_path', 'public', false)");
            DB::statement('DROP SCHEMA '.$this->schema.' CASCADE');
        }
        parent::tearDown();
    }

    private function family(): array
    {
        $group = Group::factory()->create();
        $member = GroupMember::factory()->create(['group_id' => $group->id, 'role' => 'admin']);
        $category = Category::query()->create(['group_id' => $group->id, 'name' => '勉強', 'sort_order' => 1]);

        return [$group, $member, $category];
    }

    private function definition(Group $group, GroupMember $member, string $status = 'active'): Reward
    {
        $r = Reward::factory()->create(['group_id' => $group->id, 'status' => $status]);
        $v = RewardVersion::factory()->create(['group_id' => $group->id, 'reward_id' => $r->id, 'required_count' => 1]);
        $v->targets()->create(['group_id' => $group->id, 'group_member_id' => $member->id]);

        return $r;
    }

    private function race(Group $group, GroupMember $actor, array $requests): array
    {
        $processes = [];
        $results = [];
        $token = $actor->user->createToken('reward-race')->plainTextToken;
        DB::beginTransaction();
        try {
            Group::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            foreach ($requests as $index => $request) {
                $worker = 'reward-race-'.$index.'-'.$group->id;
                $p = new Process([PHP_BINARY, base_path('tests/Support/reward-concurrent-request.php')], base_path(), timeout: 25);
                $p->setInput(json_encode([...$request, 'schema' => $this->schema, 'worker' => $worker, 'token' => $token], JSON_THROW_ON_ERROR));
                $p->start();
                $processes[] = $p;
                $deadline = microtime(true) + 10;
                do {
                    DB::select('select pg_stat_clear_snapshot()');
                    $waiting = DB::selectOne("select count(*) as count from pg_stat_activity where application_name=? and wait_event_type='Lock'", [$worker]);
                    if ((int) $waiting->count > 0) {
                        break;
                    }
                    $this->assertTrue($p->isRunning(), $p->getErrorOutput().$p->getOutput());
                    usleep(10000);
                } while (microtime(true) < $deadline);
                $this->assertGreaterThan(0, (int) $waiting->count, 'Request must wait on the actual group lock.');
            }
            DB::commit();
            foreach ($processes as $p) {
                $this->assertSame(0, $p->wait(), $p->getErrorOutput());
                $results[] = json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }
        }
    }

    public function test_concurrent_activity_writes_award_once_and_notification_claim_has_one_winner(): void
    {
        [$group,$member,$category] = $this->family();
        $this->definition($group, $member);
        $body = ['category_id' => $category->id, 'activity_date' => now()->toDateString(), 'content' => '活動'];
        $requests = array_fill(0, 2, ['method' => 'POST', 'path' => "/v1/groups/{$group->id}/logs", 'body' => $body]);
        $results = $this->race($group, $member, $requests);
        $this->assertSame([201, 201], array_column($results, 'status'));
        $this->assertDatabaseCount('reward_awards', 1);
        $claims = $this->race($group, $member, array_fill(0, 2, ['method' => 'POST', 'path' => "/v1/groups/{$group->id}/rewards/notifications", 'body' => []]));
        $this->assertSame([200, 200], array_column($claims, 'status'));
        $sizes = array_map(fn ($r) => count($r['body']['data']), $claims);
        sort($sizes);
        $this->assertSame([0, 1], $sizes);
    }

    public function test_concurrent_activation_never_exceeds_group_limit(): void
    {
        [$group,$member] = $this->family();
        $this->definition($group, $member);
        $this->definition($group, $member);
        $one = $this->definition($group, $member, 'draft');
        $two = $this->definition($group, $member, 'draft');
        $requests = [];
        foreach ([$one, $two] as $reward) {
            $requests[] = ['method' => 'PUT', 'path' => "/v1/groups/{$group->id}/rewards/{$reward->id}/status", 'body' => ['revision' => 1, 'status' => 'active']];
        }
        $results = $this->race($group, $member, $requests);
        $codes = array_column($results, 'status');
        sort($codes);
        $this->assertSame([200, 409], $codes);
        $this->assertSame(3, Reward::query()->where('status', 'active')->count());
    }

    public function test_composite_foreign_key_rejects_cross_family_target(): void
    {
        [$group,$member] = $this->family();
        $r = $this->definition($group, $member);
        [, $foreign] = $this->family();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('reward_targets')->insert(['group_id' => $group->id, 'reward_version_id' => $r->versions()->sole()->id, 'group_member_id' => $foreign->id]));
    }

    public function test_period_day_mode_requires_null_fixed_threshold_in_database(): void
    {
        [$group, $member] = $this->family();
        $reward = $this->definition($group, $member);
        $version = $reward->versions()->sole();
        $version->update(['count_mode' => 'period_days', 'required_count' => null]);
        $this->assertNull($version->fresh()->required_count);
        $this->expectException(QueryException::class);
        DB::transaction(fn () => $version->update(['required_count' => 1]));
    }

    public function test_a09_concurrent_creation_respects_management_limit_and_delete_frees_slot(): void
    {
        [$group, $member] = $this->family();
        for ($i = 0; $i < 5; $i++) {
            $draft = $this->definition($group, $member, $i < 3 ? 'active' : 'draft');
        }
        $body = ['name' => '枠競合', 'description' => '報酬', 'metric' => 'activities', 'color' => 'gold', 'required_count' => 1, 'target_ids' => [$member->id], 'start_month' => now()->format('Y-m'), 'cycle_months' => 1, 'timezone' => 'UTC'];
        $results = $this->race($group, $member, array_fill(0, 2, ['method' => 'POST', 'path' => "/v1/groups/{$group->id}/rewards", 'body' => $body]));
        $codes = array_column($results, 'status');
        sort($codes);
        $this->assertSame([201, 409], $codes);
        $this->assertSame(6, Reward::query()->count());
        $blocked = $this->race($group, $member, [['method' => 'PUT', 'path' => "/v1/groups/{$group->id}/rewards/{$draft->id}/status", 'body' => ['status' => 'active', 'revision' => 1]]]);
        $this->assertSame(409, $blocked[0]['status']);
        $active = Reward::query()->where('status', 'active')->firstOrFail();
        $stopped = $this->race($group, $member, [['method' => 'PUT', 'path' => "/v1/groups/{$group->id}/rewards/{$active->id}/status", 'body' => ['status' => 'stopped', 'revision' => 1]]]);
        $this->assertSame(200, $stopped[0]['status']);
        $activated = $this->race($group, $member, [['method' => 'PUT', 'path' => "/v1/groups/{$group->id}/rewards/{$draft->id}/status", 'body' => ['status' => 'active', 'revision' => 1]]]);
        $this->assertSame(200, $activated[0]['status']);
        $draft = Reward::query()->where('status', 'draft')->firstOrFail();
        $deleted = $this->race($group, $member, [['method' => 'DELETE', 'path' => "/v1/groups/{$group->id}/rewards/{$draft->id}", 'body' => ['revision' => 1]]]);
        $this->assertSame(200, $deleted[0]['status']);
        $replaced = $this->race($group, $member, [['method' => 'POST', 'path' => "/v1/groups/{$group->id}/rewards", 'body' => $body]]);
        $this->assertSame(201, $replaced[0]['status']);
        $this->assertSame(6, Reward::query()->count());
    }

    public function test_a15_concurrent_retry_schedules_one_preparation_and_awards_once(): void
    {
        [$group, $member, $category] = $this->family();
        $reward = $this->definition($group, $member);
        ActivityLog::query()->create(['group_id' => $group->id, 'user_id' => $member->user_id, 'category_id' => $category->id, 'activity_date' => now()->toDateString(), 'content' => '既存活動']);
        $group->forceFill(['reward_sync_status' => 'failed'])->save();
        $requests = array_fill(0, 2, ['method' => 'POST', 'path' => "/v1/groups/{$group->id}/rewards/prepare", 'body' => []]);
        $results = $this->race($group, $member, $requests);
        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertSame(1, array_sum(array_column($results, 'deferred_callbacks')));
        $this->assertSame('complete', $group->fresh()->reward_sync_status);
        $this->assertSame(now()->startOfMonth()->toDateString(), $reward->fresh()->prepared_period->toDateString());
        $this->assertDatabaseCount('reward_awards', 1);
    }
}
