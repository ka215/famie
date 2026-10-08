<?php

namespace Tests\Feature;

use App\Models\ActivityLike;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Group;
use App\Models\GroupMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PostgresConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('FAMIE_CONCURRENCY_TEST') !== '1') {
            $this->markTestSkipped('Requires a dedicated PostgreSQL test database and concurrent PHP processes.');
        }
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertStringEndsWith('_test', DB::getDatabaseName());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_deactivation_and_login_leave_no_live_token(bool $loginFirst): void
    {
        [$group, $admin, $member] = $this->family();
        $disable = $this->operation($admin, 'PUT', "/v1/groups/{$group->id}/members/{$member->id}/status", ['status' => 'inactive']);
        $login = $this->operation($member, 'POST', '/v1/auth/login', ['login' => $member->user->username, 'password' => 'password']);
        $results = $this->race($group, $loginFirst ? [$login, $disable] : [$disable, $login]);
        $this->assertSame($loginFirst ? [200, 200] : [200, 403], array_column($results, 'status'), json_encode($results));
        $this->assertDatabaseHas('group_members', ['id' => $member->id, 'status' => 'inactive']);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $member->user_id]);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_deactivation_and_activity_save_preserve_consistent_history(bool $saveFirst): void
    {
        [$group, $admin, $member, $category] = $this->family();
        $disable = $this->operation($admin, 'PUT', "/v1/groups/{$group->id}/members/{$member->id}/status", ['status' => 'inactive']);
        $save = $this->operation($member, 'POST', "/v1/groups/{$group->id}/logs", ['category_id' => $category->id, 'activity_date' => '2026-10-04', 'content' => '競合検証']);
        $results = $this->race($group, $saveFirst ? [$save, $disable] : [$disable, $save]);
        $this->assertSame($saveFirst ? [201, 200] : [200, 404], array_column($results, 'status'), json_encode($results));
        $this->assertSame($saveFirst ? 1 : 0, $group->activityLogs()->count());
        $this->assertDatabaseHas('group_members', ['id' => $member->id, 'status' => 'inactive']);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_restore_and_creation_never_exceed_capacity(bool $createFirst): void
    {
        [$group, $admin, $member] = $this->family();
        $member->update(['status' => 'inactive']);
        $restore = $this->operation($admin, 'PUT', "/v1/groups/{$group->id}/members/{$member->id}/status", ['status' => 'active']);
        $create = $this->operation($admin, 'POST', "/v1/groups/{$group->id}/members", [
            'username' => 'concurrent'.$group->id, 'display_name' => '追加対象', 'role' => 'member', 'password' => 'password', 'password_confirmation' => 'password',
        ]);
        $results = $this->race($group, $createFirst ? [$create, $restore] : [$restore, $create], 2);
        $this->assertSame($createFirst ? [201, 422] : [200, 422], array_column($results, 'status'), json_encode($results));
        $this->assertSame(2, $group->memberships()->where('status', 'active')->count());
        $this->assertSame($createFirst ? 'inactive' : 'active', $member->fresh()->status);
    }

    #[TestWith(['role'])]
    #[TestWith(['status'])]
    public function test_mutual_admin_changes_preserve_one_active_admin(string $operation): void
    {
        [$group, $admin, $member] = $this->family();
        $member->update(['role' => 'admin']);
        $suffix = $operation === 'status' ? '/status' : '';
        $method = $operation === 'status' ? 'PUT' : 'PATCH';
        $body = $operation === 'status' ? ['status' => 'inactive'] : ['display_name' => '管理者', 'role' => 'member'];
        $first = $this->operation($admin, $method, "/v1/groups/{$group->id}/members/{$member->id}{$suffix}", $body);
        $second = $this->operation($member, $method, "/v1/groups/{$group->id}/members/{$admin->id}{$suffix}", $body);
        $results = $this->race($group, [$first, $second]);
        $this->assertSame([200, $operation === 'status' ? 404 : 403], array_column($results, 'status'), json_encode($results));
        $this->assertSame(1, $group->memberships()->where('status', 'active')->where('role', 'admin')->count());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_category_delete_and_activity_save_never_leave_broken_reference(bool $saveFirst): void
    {
        [$group, $admin, $member, $category] = $this->family();
        $delete = $this->operation($admin, 'DELETE', "/v1/groups/{$group->id}/categories/{$category->id}", []);
        $save = $this->operation($member, 'POST', "/v1/groups/{$group->id}/logs", ['category_id' => $category->id, 'activity_date' => '2026-10-04', 'content' => 'カテゴリ競合']);
        $results = $this->race($group, $saveFirst ? [$save, $delete] : [$delete, $save]);
        $this->assertSame($saveFirst ? [201, 422] : [200, 422], array_column($results, 'status'), json_encode($results));
        $this->assertSame($saveFirst ? 1 : 0, $group->activityLogs()->count());
        $this->assertSame($saveFirst, Category::query()->whereKey($category->id)->exists());
    }

    #[TestWith(['verify'])]
    #[TestWith(['reset'])]
    public function test_account_link_can_only_be_consumed_once(string $kind): void
    {
        [$group, , $member] = $this->family();
        $operation = $this->accountOperation($member, $kind);
        $results = $this->race($group, [$operation, $operation]);
        $this->assertSame([200, 422], array_column($results, 'status'), json_encode($results));
    }

    #[TestWith([false, 'verify'])]
    #[TestWith([true, 'verify'])]
    #[TestWith([false, 'reset'])]
    #[TestWith([true, 'reset'])]
    public function test_deactivation_and_account_link_are_serialized(bool $linkFirst, string $kind): void
    {
        [$group, $admin, $member] = $this->family();
        $link = $this->accountOperation($member, $kind);
        $disable = $this->operation($admin, 'PUT', "/v1/groups/{$group->id}/members/{$member->id}/status", ['status' => 'inactive']);
        $results = $this->race($group, $linkFirst ? [$link, $disable] : [$disable, $link]);
        $this->assertSame($linkFirst ? [200, 200] : [200, 422], array_column($results, 'status'), json_encode($results));
        $this->assertSame(0, $member->user->tokens()->count());
        $this->assertNull($member->user->fresh()->pending_email);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_email_change_and_password_reset_invalidate_each_other(bool $resetFirst): void
    {
        [$group, , $member] = $this->family();
        $verify = $this->accountOperation($member, 'verify');
        $reset = $this->accountOperation($member, 'reset');
        $results = $this->race($group, $resetFirst ? [$reset, $verify] : [$verify, $reset]);
        $this->assertSame([200, 422], array_column($results, 'status'), json_encode($results));
    }

    public function test_two_families_cannot_confirm_the_same_email(): void
    {
        [$group, , $member] = $this->family();
        [$otherGroup, , $otherMember] = $this->family();
        $email = 'shared-'.Str::lower(Str::random(12)).'@example.test';
        $first = $this->accountOperation($member, 'verify', $email);
        $second = $this->accountOperation($otherMember, 'verify', $email);
        $results = $this->race([$group, $otherGroup], [$first, $second]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 422], $statuses, json_encode($results));
        $this->assertSame(1, DB::table('users')->where('email', $email)->count());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_reset_and_old_password_login_leave_no_live_token(bool $loginFirst): void
    {
        [$group, , $member] = $this->family();
        $reset = $this->accountOperation($member, 'reset');
        $login = $this->operation($member, 'POST', '/v1/auth/login', ['login' => $member->user->username, 'password' => 'password']);
        $results = $this->race($group, $loginFirst ? [$login, $reset] : [$reset, $login]);
        $this->assertSame($loginFirst ? [200, 200] : [200, 422], array_column($results, 'status'), json_encode($results));
        $this->assertSame(0, $member->user->tokens()->count());
        $this->assertTrue(Hash::check('new-password', $member->user->fresh()->password));
    }

    #[TestWith(['PUT', 'PUT', 1])]
    #[TestWith(['PUT', 'DELETE', 0])]
    #[TestWith(['DELETE', 'PUT', 1])]
    #[TestWith(['DELETE', 'DELETE', 0])]
    public function test_like_requests_serialize_and_preserve_the_final_state(string $first, string $second, int $count): void
    {
        [$group, $admin, $member, $category] = $this->family();
        $log = $group->activityLogs()->create(['user_id' => $admin->user_id, 'category_id' => $category->id, 'activity_date' => '2026-10-08', 'content' => 'いいね競合']);
        $path = "/v1/groups/{$group->id}/logs/{$log->id}/like";
        $results = $this->race($group, [$this->operation($member, $first, $path, []), $this->operation($member, $second, $path, [])]);
        $this->assertSame([200, 200], array_column($results, 'status'), json_encode($results));
        $this->assertSame($count, $log->likes()->count());
    }

    #[TestWith([true, 'sender'])]
    #[TestWith([false, 'sender'])]
    #[TestWith([true, 'owner'])]
    #[TestWith([false, 'owner'])]
    #[TestWith([true, 'delete'])]
    #[TestWith([false, 'delete'])]
    public function test_like_and_visibility_changes_never_leave_visible_invalid_likes(bool $likeFirst, string $change): void
    {
        [$group, $admin, $member, $category] = $this->family();
        $log = $group->activityLogs()->create(['user_id' => $member->user_id, 'category_id' => $category->id, 'activity_date' => '2026-10-08', 'content' => 'いいね競合']);
        $sender = GroupMember::factory()->create(['group_id' => $group->id]);
        $like = $this->operation($sender, 'PUT', "/v1/groups/{$group->id}/logs/{$log->id}/like", []);
        $update = $change === 'delete'
            ? $this->operation($member, 'DELETE', "/v1/groups/{$group->id}/logs/{$log->id}", [])
            : $this->operation($admin, 'PUT', "/v1/groups/{$group->id}/members/".($change === 'sender' ? $sender->id : $member->id).'/status', ['status' => 'inactive']);
        $results = $this->race($group, $likeFirst ? [$like, $update] : [$update, $like]);
        $this->assertSame($likeFirst ? [200, 200] : [200, 404], array_column($results, 'status'), json_encode($results));
        $this->assertSame($likeFirst && $change !== 'delete' ? 1 : 0, ActivityLike::query()->where('activity_log_id', $log->id)->count());
        if ($change === 'sender') {
            $this->assertSame(0, ActivityLog::query()->withLikeState($admin->user_id)->findOrFail($log->id)->likes_count);
        }
    }

    private function accountOperation(GroupMember $member, string $kind, ?string $email = null): array
    {
        $user = $member->user->fresh();
        if ($kind === 'verify') {
            $token = Str::random(64);
            $user->forceFill(['pending_email' => $email ?? 'new-'.$user->id.'@example.test', 'email_verification_token' => hash('sha256', $token), 'email_verification_expires_at' => now()->addHour()])->save();

            return $this->operation($member, 'POST', '/v1/auth/email/verify', ['id' => $user->id, 'token' => $token]);
        }
        $user->forceFill(['email_verified_at' => now()])->save();

        return $this->operation($member, 'POST', '/v1/auth/password/reset', ['id' => $user->id, 'token' => Password::createToken($user), 'password' => 'new-password', 'password_confirmation' => 'new-password']);
    }

    /** @return array{Group, GroupMember, GroupMember, Category} */
    private function family(): array
    {
        $group = Group::factory()->create();
        $admin = GroupMember::factory()->create(['group_id' => $group->id, 'role' => 'admin']);
        $member = GroupMember::factory()->create(['group_id' => $group->id]);
        $category = Category::query()->create(['group_id' => $group->id, 'name' => '競合カテゴリ', 'sort_order' => 1]);

        return [$group, $admin, $member, $category];
    }

    /** @return array<string, mixed> */
    private function operation(GroupMember $actor, string $method, string $path, array $body): array
    {
        return ['method' => $method, 'path' => $path, 'body' => $body, 'token' => $actor->user->createToken('race')->plainTextToken];
    }

    /** @return array<int, array<string, mixed>> */
    private function race(Group|array $group, array $operations, int $limit = 10): array
    {
        $groups = is_array($group) ? $group : [$group];
        $group = $groups[0];
        $processes = [];
        DB::beginTransaction();
        try {
            foreach ($groups as $lockedGroup) {
                Group::query()->whereKey($lockedGroup->id)->lockForUpdate()->firstOrFail();
            }
            foreach ($operations as $index => $operation) {
                $worker = 'famie-race-'.$group->id.'-'.$index;
                $process = new Process([PHP_BINARY, base_path('tests/Support/concurrent-request.php')], base_path(), timeout: 25);
                $process->setInput(json_encode([...$operation, 'worker' => $worker, 'limit' => $limit], JSON_THROW_ON_ERROR));
                $process->start();
                $processes[] = $process;
                $deadline = microtime(true) + 15;
                do {
                    DB::select('select pg_stat_clear_snapshot()');
                    $waiting = DB::selectOne("select count(*) as count from pg_stat_activity where application_name = ? and wait_event_type = 'Lock'", [$worker]);
                    if ((int) $waiting->count > 0) {
                        break;
                    }
                    $this->assertTrue($process->isRunning(), $process->getErrorOutput().$process->getOutput());
                    usleep(10000);
                } while (microtime(true) < $deadline);
                $this->assertGreaterThan(0, (int) $waiting->count, 'Worker must actually wait for a PostgreSQL row lock.');
            }
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }
}
