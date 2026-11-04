<?php

namespace Tests\Feature;

use App\Models\AccountLifecycleOperation;
use App\Models\ActivityImage;
use App\Models\ActivityLike;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Reward;
use App\Models\RewardAward;
use App\Models\RewardPeriod;
use App\Models\RewardVersion;
use App\Models\User;
use App\Notifications\RepeatedLoginLockout;
use App\Services\AccountLifecycleCleanup;
use App\Services\ActivityImageDeletionService;
use App\Services\LifecycleLedger;
use App\Services\LifecycleVault;
use App\Services\OperationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\Support\IsolatesOperationsStorage;
use Tests\TestCase;

class AccountLifecycleTest extends TestCase
{
    use IsolatesOperationsStorage, RefreshDatabase;

    public function test_only_representative_can_prepare_deletion_for_an_inactive_non_representative(): void
    {
        [$group, $representative, $otherParent, $member] = $this->family();
        Sanctum::actingAs($otherParent->user);
        $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", [
            'kind' => 'member_delete', 'membership_id' => $member->id,
        ])->assertForbidden();

        Sanctum::actingAs($representative->user);
        $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", [
            'kind' => 'member_delete', 'membership_id' => $member->id,
        ])->assertStatus(409);
        $member->update(['status' => GroupMember::STATUS_INACTIVE]);
        $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", [
            'kind' => 'member_delete', 'membership_id' => $representative->id,
        ])->assertForbidden();
        $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", [
            'kind' => 'member_delete', 'membership_id' => $member->id, 'preserve_posts' => true,
        ])->assertUnprocessable();
    }

    public function test_member_deletion_masks_identity_erases_content_retires_images_and_retains_sent_likes(): void
    {
        $vault = $this->configureLifecycleStorage();
        Storage::fake('local');
        [$group, $representative, , $member] = $this->family();
        $member->update(['status' => GroupMember::STATUS_INACTIVE]);
        $oldUsername = $member->user->username;
        $oldEmail = $member->user->email;
        $member->user->forceFill([
            'email_verified_at' => now(), 'pending_email' => 'pending@example.test',
            'email_verification_token' => str_repeat('a', 64), 'email_verification_expires_at' => now()->addHour(),
        ])->save();
        $category = Category::query()->create(['group_id' => $group->id, 'name' => '家事', 'color_code' => '#10B981', 'sort_order' => 1]);
        $memberLog = ActivityLog::query()->create([
            'group_id' => $group->id, 'user_id' => $member->user_id, 'category_id' => $category->id,
            'activity_date' => '2026-11-03', 'content' => '消去対象本文', 'note' => '消去対象メモ',
        ]);
        Storage::disk('local')->put('activity-images/deleted.webp', 'image-bytes');
        $image = ActivityImage::query()->create([
            'group_id' => $group->id, 'activity_log_id' => $memberLog->id, 'disk' => 'local',
            'path' => 'activity-images/deleted.webp', 'bytes' => 11, 'width' => 10, 'height' => 10, 'mime' => 'image/webp', 'position' => 0,
        ]);
        $representativeLog = ActivityLog::query()->create([
            'group_id' => $group->id, 'user_id' => $representative->user_id, 'category_id' => $category->id,
            'activity_date' => '2026-11-03', 'content' => '残る投稿',
        ]);
        ActivityLike::query()->create(['group_id' => $group->id, 'activity_log_id' => $representativeLog->id, 'user_id' => $member->user_id]);

        Sanctum::actingAs($representative->user);
        $prepared = $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", [
            'kind' => 'member_delete', 'membership_id' => $member->id,
        ])->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $receipt = $prepared->json('data.receipt_id');
        $code = $prepared->json('data.confirmation_code');
        $this->assertFileDoesNotExist($vault.'/'.$receipt.'.json');

        app(OperationState::class)->locked('image-deletions', fn () => $this->postJson("/v1/groups/{$group->id}/lifecycle-operations/{$receipt}/commit", [
            'current_password' => 'password', 'confirmation_code' => $code, 'confirmed' => true,
        ])->assertStatus(202)->assertJsonPath('data.state', 'committed'));

        $this->assertSoftDeleted('users', ['id' => $member->user_id]);
        $this->assertSoftDeleted('group_members', ['id' => $member->id]);
        $this->assertDatabaseHas('users', [
            'id' => $member->user_id, 'email' => null, 'display_name' => 'Deleted member',
            'email_verified_at' => null, 'pending_email' => null, 'email_verification_token' => null, 'email_verification_expires_at' => null,
        ]);
        $this->assertDatabaseMissing('users', ['id' => $member->user_id, 'username' => $oldUsername]);
        $this->assertDatabaseHas('activity_logs', ['id' => $memberLog->id, 'content' => '', 'note' => null, 'lifecycle_operation_id' => $receipt]);
        $this->assertDatabaseHas('activity_images', ['id' => $image->id, 'lifecycle_operation_id' => $receipt]);
        $this->assertDatabaseHas('activity_image_deletions', ['activity_image_id' => $image->id, 'bytes' => 11]);
        $this->assertTrue(Storage::disk('local')->exists('activity-images/deleted.webp'));
        $this->assertDatabaseCount('activity_likes', 1);
        $this->getJson("/v1/groups/{$group->id}/logs/{$representativeLog->id}")->assertOk()->assertJsonPath('data.likes_count', 1);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $member->user_id]);
        User::query()->create(['username' => $oldUsername, 'display_name' => 'New account', 'email' => $oldEmail, 'password' => 'password']);

        $contents = File::get($vault.'/'.$receipt.'.json');
        $this->assertStringNotContainsString('消去対象本文', $contents);
        $this->assertStringNotContainsString($oldUsername, $contents);
        $this->postJson('/v1/lifecycle-operation-status', ['receipt_id' => $receipt, 'confirmation_code' => ($code[0] === 'x' ? 'y' : 'x').substr($code, 1)])
            ->assertNotFound();
        $this->postJson('/v1/lifecycle-operation-status', ['receipt_id' => $receipt, 'confirmation_code' => $code])
            ->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertJsonMissing(['group_id' => $group->id]);

        $hold = DB::table('account_lifecycle_holds')->insertGetId([
            'operation_id' => $receipt, 'scope' => 'all', 'reference' => 'queued-image-hold',
            'started_at' => now(), 'review_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(0, app(ActivityImageDeletionService::class)->process());
        $this->assertTrue(Storage::disk('local')->exists('activity-images/deleted.webp'));
        $this->assertDatabaseHas('activity_image_deletions', ['activity_image_id' => $image->id, 'attempts' => 0]);

        DB::table('account_lifecycle_holds')->where('id', $hold)->update(['released_at' => now()]);
        $this->assertSame(0, app(ActivityImageDeletionService::class)->process());
        $this->assertFalse(Storage::disk('local')->exists('activity-images/deleted.webp'));
        $this->assertDatabaseMissing('activity_images', ['id' => $image->id]);
        $leftoverToken = $member->user->createToken('simulated-leftover-token');
        auth()->forgetGuards();
        $this->getJson('/v1/auth/me', ['Authorization' => 'Bearer '.$leftoverToken->plainTextToken])->assertUnauthorized();
    }

    public function test_family_withdrawal_blocks_every_member_and_masks_the_family(): void
    {
        $this->configureLifecycleStorage();
        [$group, $representative, $otherParent, $member] = $this->family();
        $oldToken = $representative->user->createToken('revoked-on-withdrawal')->accessToken;
        Sanctum::actingAs($representative->user);
        $prepared = $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", ['kind' => 'group_withdraw'])
            ->assertCreated()->assertJsonPath('data.impact.members', 3);
        $this->postJson("/v1/groups/{$group->id}/lifecycle-operations/{$prepared->json('data.receipt_id')}/commit", [
            'current_password' => 'password', 'confirmation_code' => $prepared->json('data.confirmation_code'), 'confirmed' => true,
        ])->assertStatus(202);

        $this->assertDatabaseHas('groups', ['id' => $group->id, 'name' => 'Withdrawn family']);
        $this->assertNotNull($group->fresh()->withdrawn_at);
        foreach ([$representative, $otherParent, $member] as $membership) {
            $this->assertSoftDeleted('users', ['id' => $membership->user_id]);
            $this->assertSoftDeleted('group_members', ['id' => $membership->id]);
        }
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $oldToken->id]);
    }

    public function test_failed_confirmation_does_not_write_vault_or_delete_the_member(): void
    {
        $vault = $this->configureLifecycleStorage();
        [$group, $representative, , $member] = $this->family();
        $member->update(['status' => GroupMember::STATUS_INACTIVE]);
        Sanctum::actingAs($representative->user);
        $prepared = $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", [
            'kind' => 'member_delete', 'membership_id' => $member->id,
        ])->assertCreated();

        $this->postJson("/v1/groups/{$group->id}/lifecycle-operations/{$prepared->json('data.receipt_id')}/commit", [
            'current_password' => 'wrong-password',
            'confirmation_code' => $prepared->json('data.confirmation_code'),
            'confirmed' => true,
        ])->assertUnprocessable();

        $this->assertNotSoftDeleted('users', ['id' => $member->user_id]);
        $this->assertNotSoftDeleted('group_members', ['id' => $member->id]);
        $this->assertFileDoesNotExist($vault.'/'.$prepared->json('data.receipt_id').'.json');
        $this->assertDatabaseHas('account_lifecycle_operations', [
            'id' => $prepared->json('data.receipt_id'), 'state' => 'prepared',
        ]);
    }

    public function test_family_withdrawal_rejects_members_added_after_preparation_without_deleting_anyone(): void
    {
        $vault = $this->configureLifecycleStorage();
        [$group, $representative, $otherParent, $member] = $this->family();
        Sanctum::actingAs($representative->user);
        $prepared = $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", ['kind' => 'group_withdraw'])->assertCreated();
        $added = GroupMember::factory()->for($group)->for(User::factory())->create();
        $receipt = $prepared->json('data.receipt_id');

        $this->postJson("/v1/groups/{$group->id}/lifecycle-operations/{$receipt}/commit", [
            'current_password' => 'password', 'confirmation_code' => $prepared->json('data.confirmation_code'), 'confirmed' => true,
        ])->assertStatus(409);

        foreach ([$representative, $otherParent, $member, $added] as $membership) {
            $this->assertNotSoftDeleted('users', ['id' => $membership->user_id]);
            $this->assertNotSoftDeleted('group_members', ['id' => $membership->id]);
        }
        $this->assertNull($group->fresh()->withdrawn_at);
        $this->assertFileDoesNotExist($vault.'/'.$receipt.'.json');
        $this->assertDatabaseHas('account_lifecycle_operations', ['id' => $receipt, 'state' => 'prepared']);
    }

    public function test_family_cleanup_advances_every_batch_and_waits_for_all_personal_text(): void
    {
        $this->freezeTime();
        $this->configureLifecycleStorage();
        config(['famie.lifecycle_cleanup_batch' => 1]);
        [$group, $representative, , $member] = $this->family();
        $foreignCategory = Category::query()->create([
            'group_id' => Group::factory()->create()->id, 'name' => 'Unrelated category', 'sort_order' => 1,
        ]);
        for ($index = 0; $index < 3; $index++) {
            $category = Category::query()->create(['group_id' => $group->id, 'name' => 'Private '.$index, 'sort_order' => $index]);
            $reward = Reward::factory()->create(['group_id' => $group->id]);
            $version = RewardVersion::factory()->create([
                'group_id' => $group->id, 'reward_id' => $reward->id,
                'category_id' => $category->id, 'category_name' => $category->name,
            ]);
            $period = RewardPeriod::query()->create([
                'group_id' => $group->id, 'reward_id' => $reward->id, 'reward_version_id' => $version->id,
                'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'timezone' => 'UTC',
            ]);
            RewardAward::factory()->create([
                'group_id' => $group->id, 'reward_id' => $reward->id, 'reward_period_id' => $period->id,
                'group_member_id' => $member->id,
                'snapshot' => ['name' => 'Private reward', 'description' => 'Private description', 'category_name' => $category->name, 'color' => 'gold'],
            ]);
            $representative->user->notify(new RepeatedLoginLockout([
                'group_id' => $group->id, 'membership_id' => $member->id,
                'display_name' => 'Private member', 'username' => 'private-user', 'occurred_at' => now()->toIso8601String(),
            ]));
        }
        Sanctum::actingAs($representative->user);
        $prepared = $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", ['kind' => 'group_withdraw'])->assertCreated();
        $receipt = $prepared->json('data.receipt_id');

        $this->postJson("/v1/groups/{$group->id}/lifecycle-operations/{$receipt}/commit", [
            'current_password' => 'password', 'confirmation_code' => $prepared->json('data.confirmation_code'), 'confirmed' => true,
        ])->assertStatus(202);

        $this->assertDatabaseHas('account_lifecycle_operations', ['id' => $receipt, 'cleanup_state' => 'processing']);
        $this->assertSame(1, RewardVersion::query()->where('group_id', $group->id)->where('name', 'Deleted reward')->count());
        $cleanup = app(AccountLifecycleCleanup::class);
        $cleanup->process(AccountLifecycleOperation::query()->findOrFail($receipt));
        $this->assertDatabaseHas('account_lifecycle_operations', ['id' => $receipt, 'cleanup_state' => 'processing']);
        $cleanup->process(AccountLifecycleOperation::query()->findOrFail($receipt));

        $this->assertDatabaseHas('account_lifecycle_operations', ['id' => $receipt, 'cleanup_state' => 'complete']);
        foreach (Category::query()->where('group_id', $group->id)->get() as $category) {
            $this->assertSame('Deleted category '.$category->id, $category->name);
        }
        foreach (RewardVersion::query()->where('group_id', $group->id)->get() as $version) {
            $this->assertSame('', $version->description);
            $this->assertNull($version->category_name);
        }
        foreach (RewardAward::query()->where('group_id', $group->id)->get() as $award) {
            $this->assertSame('Deleted member', $award->snapshot['member_name']);
            $this->assertSame('Deleted reward', $award->snapshot['name']);
            $this->assertSame('', $award->snapshot['description']);
            $this->assertSame('gold', $award->snapshot['color']);
        }
        foreach (DB::table('notifications')->get() as $notification) {
            $data = json_decode($notification->data, true, flags: JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('username', $data);
            $this->assertSame('Deleted member', $data['display_name']);
        }
        $this->assertSame('Unrelated category', $foreignCategory->fresh()->name);
    }

    public function test_active_hold_preserves_content_and_expired_vault_until_explicit_release(): void
    {
        $this->freezeTime();
        $vault = $this->configureLifecycleStorage();
        [$group, $representative, , $member] = $this->family();
        $member->update(['status' => GroupMember::STATUS_INACTIVE]);
        $category = Category::query()->create(['group_id' => $group->id, 'name' => 'Private', 'sort_order' => 1]);
        $log = ActivityLog::query()->create([
            'group_id' => $group->id, 'user_id' => $member->user_id, 'category_id' => $category->id,
            'activity_date' => '2026-11-04', 'content' => 'Held content',
        ]);
        $representative->user->notify(new RepeatedLoginLockout([
            'group_id' => $group->id, 'membership_id' => $member->id,
            'display_name' => 'Held private name', 'username' => 'held-private-user', 'occurred_at' => now()->toIso8601String(),
        ]));
        Sanctum::actingAs($representative->user);
        $prepared = $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", [
            'kind' => 'member_delete', 'membership_id' => $member->id,
        ])->assertCreated();
        $receipt = $prepared->json('data.receipt_id');
        $hold = DB::table('account_lifecycle_holds')->insertGetId([
            'operation_id' => $receipt, 'scope' => 'all', 'reference' => 'test-hold',
            'started_at' => now(), 'review_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson("/v1/groups/{$group->id}/lifecycle-operations/{$receipt}/commit", [
            'current_password' => 'password', 'confirmation_code' => $prepared->json('data.confirmation_code'), 'confirmed' => true,
        ])->assertStatus(202);
        $this->getJson("/v1/groups/{$group->id}/security-alerts")->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.unread_total', 0);
        $this->travel(31)->days();

        $this->artisan('famie:cleanup-account-lifecycle')->assertSuccessful();

        $this->assertDatabaseHas('activity_logs', ['id' => $log->id, 'content' => 'Held content', 'content_erased_at' => null]);
        $this->assertDatabaseHas('account_lifecycle_credentials', ['operation_id' => $receipt]);
        $this->assertFileExists($vault.'/'.$receipt.'.json');
        $this->postJson('/v1/lifecycle-operation-status', [
            'receipt_id' => $receipt, 'confirmation_code' => $prepared->json('data.confirmation_code'),
        ])->assertNotFound();

        DB::table('account_lifecycle_holds')->where('id', $hold)->update(['released_at' => now()]);
        $this->artisan('famie:cleanup-account-lifecycle')->assertSuccessful();

        $this->assertDatabaseHas('activity_logs', ['id' => $log->id, 'content' => '']);
        $this->assertDatabaseMissing('account_lifecycle_credentials', ['operation_id' => $receipt]);
        $this->assertFileDoesNotExist($vault.'/'.$receipt.'.json');
    }

    public function test_committed_ledger_failure_returns_acceptance_and_is_repaired_before_cleanup(): void
    {
        $this->freezeTime();
        $vault = $this->configureLifecycleStorage();
        [$group, $representative, , $member] = $this->family();
        $member->update(['status' => GroupMember::STATUS_INACTIVE]);
        $ledger = app(LifecycleLedger::class);
        $attempts = 0;
        $this->mock(LifecycleLedger::class)->shouldReceive('append')->andReturnUsing(
            function (AccountLifecycleOperation $operation, string $event, array $context = []) use ($ledger, &$attempts): void {
                if ($event === 'committed' && ++$attempts === 1) {
                    throw new RuntimeException('Simulated external ledger outage');
                }
                $ledger->append($operation, $event, $context);
            },
        );
        Sanctum::actingAs($representative->user);
        $prepared = $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", [
            'kind' => 'member_delete', 'membership_id' => $member->id,
        ])->assertCreated();
        $receipt = $prepared->json('data.receipt_id');

        $this->postJson("/v1/groups/{$group->id}/lifecycle-operations/{$receipt}/commit", [
            'current_password' => 'password', 'confirmation_code' => $prepared->json('data.confirmation_code'), 'confirmed' => true,
        ])->assertStatus(202)->assertJsonPath('data.state', 'committed');

        $this->assertSoftDeleted('users', ['id' => $member->user_id]);
        $this->assertDatabaseHas('account_lifecycle_operations', [
            'id' => $receipt, 'cleanup_state' => 'processing', 'last_error_code' => 'ledger_commit_pending',
        ]);
        $this->travel(31)->days();
        $this->artisan('famie:cleanup-account-lifecycle')->assertSuccessful();

        $this->assertSame(2, $attempts);
        $this->assertDatabaseHas('account_lifecycle_operations', ['id' => $receipt, 'cleanup_state' => 'complete', 'last_error_code' => null]);
        $this->assertDatabaseHas('account_lifecycle_credentials', ['operation_id' => $receipt]);
        $this->assertFileExists($vault.'/'.$receipt.'.json');
        $this->artisan('famie:cleanup-account-lifecycle')->assertSuccessful();
        $this->assertDatabaseMissing('account_lifecycle_credentials', ['operation_id' => $receipt]);
        $this->assertFileDoesNotExist($vault.'/'.$receipt.'.json');
    }

    public function test_confirmation_requires_a_boolean_true_without_implicit_conversion(): void
    {
        $vault = $this->configureLifecycleStorage();
        [$group, $representative, , $member] = $this->family();
        $member->update(['status' => GroupMember::STATUS_INACTIVE]);
        Sanctum::actingAs($representative->user);
        $prepared = $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", [
            'kind' => 'member_delete', 'membership_id' => $member->id,
        ])->assertCreated();
        $receipt = $prepared->json('data.receipt_id');

        foreach (['true', 1, 'yes', false] as $confirmed) {
            $this->postJson("/v1/groups/{$group->id}/lifecycle-operations/{$receipt}/commit", [
                'current_password' => 'password', 'confirmation_code' => $prepared->json('data.confirmation_code'), 'confirmed' => $confirmed,
            ])->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        }

        $this->assertNotSoftDeleted('users', ['id' => $member->user_id]);
        $this->assertDatabaseHas('account_lifecycle_operations', ['id' => $receipt, 'state' => 'prepared']);
        $this->assertFileDoesNotExist($vault.'/'.$receipt.'.json');
    }

    public function test_confirmation_code_padding_is_rejected_without_trimming_or_deletion(): void
    {
        $vault = $this->configureLifecycleStorage();
        [$group, $representative, , $member] = $this->family();
        $member->update(['status' => GroupMember::STATUS_INACTIVE]);
        Sanctum::actingAs($representative->user);
        $prepared = $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", [
            'kind' => 'member_delete', 'membership_id' => $member->id,
        ])->assertCreated();
        $receipt = $prepared->json('data.receipt_id');
        $padded = ' '.$prepared->json('data.confirmation_code');

        $this->postJson("/v1/groups/{$group->id}/lifecycle-operations/{$receipt}/commit", [
            'current_password' => 'password', 'confirmation_code' => $padded, 'confirmed' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('confirmation_code');
        $this->postJson('/v1/lifecycle-operation-status', ['receipt_id' => $receipt, 'confirmation_code' => $padded])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmation_code');

        $this->assertNotSoftDeleted('users', ['id' => $member->user_id]);
        $this->assertFileDoesNotExist($vault.'/'.$receipt.'.json');
    }

    public function test_committed_retry_rechecks_credentials_without_rewriting_vault_or_extending_expiry(): void
    {
        $this->freezeTime();
        $vault = $this->configureLifecycleStorage();
        [$group, $representative, , $member] = $this->family();
        $member->update(['status' => GroupMember::STATUS_INACTIVE]);
        Sanctum::actingAs($representative->user);
        $prepared = $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", [
            'kind' => 'member_delete', 'membership_id' => $member->id,
        ])->assertCreated();
        $receipt = $prepared->json('data.receipt_id');
        $body = ['current_password' => 'password', 'confirmation_code' => $prepared->json('data.confirmation_code'), 'confirmed' => true];
        $path = "/v1/groups/{$group->id}/lifecycle-operations/{$receipt}/commit";
        $first = $this->postJson($path, $body)->assertStatus(202);
        $this->postJson("/v1/groups/{$group->id}/lifecycle-operations/{$receipt}/cancel")->assertStatus(409);
        $stored = File::get($vault.'/'.$receipt.'.json');
        $this->travel(1)->days();
        $code = $body['confirmation_code'];

        $this->postJson($path, [...$body, 'current_password' => 'wrong-password'])->assertUnprocessable();
        $this->postJson($path, [...$body, 'confirmation_code' => ($code[0] === 'x' ? 'y' : 'x').substr($code, 1)])->assertUnprocessable();
        $this->postJson($path, $body)->assertStatus(202)
            ->assertJsonPath('data.committed_at', $first->json('data.committed_at'))
            ->assertJsonPath('data.retention_until', $first->json('data.retention_until'));

        $this->assertSame($stored, File::get($vault.'/'.$receipt.'.json'));
        $this->travel(30)->days();
        $this->postJson($path, $body)->assertStatus(409);
    }

    public function test_existing_vault_evidence_is_not_overwritten_by_a_new_commit_attempt(): void
    {
        $vault = $this->configureLifecycleStorage();
        [$group, $representative, , $member] = $this->family();
        $member->update(['status' => GroupMember::STATUS_INACTIVE]);
        Sanctum::actingAs($representative->user);
        $prepared = $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", [
            'kind' => 'member_delete', 'membership_id' => $member->id,
        ])->assertCreated();
        $receipt = $prepared->json('data.receipt_id');
        File::put($vault.'/'.$receipt.'.json', 'Existing evidence must remain');

        $this->postJson("/v1/groups/{$group->id}/lifecycle-operations/{$receipt}/commit", [
            'current_password' => 'password', 'confirmation_code' => $prepared->json('data.confirmation_code'), 'confirmed' => true,
        ])->assertStatus(503);

        $this->assertSame('Existing evidence must remain', File::get($vault.'/'.$receipt.'.json'));
        $this->assertNotSoftDeleted('users', ['id' => $member->user_id]);
        $this->assertDatabaseHas('account_lifecycle_operations', ['id' => $receipt, 'state' => 'prepared']);
    }

    public function test_incomplete_ledger_record_stops_commit_and_preserves_reconciliation_evidence(): void
    {
        $vault = $this->configureLifecycleStorage();
        $ledger = dirname($vault).'/ledger/account-lifecycle.jsonl';
        File::put($ledger, '{"event":"intent"');
        [$group, $representative, , $member] = $this->family();
        $member->update(['status' => GroupMember::STATUS_INACTIVE]);
        Sanctum::actingAs($representative->user);
        $prepared = $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", [
            'kind' => 'member_delete', 'membership_id' => $member->id,
        ])->assertCreated();
        $receipt = $prepared->json('data.receipt_id');

        $this->postJson("/v1/groups/{$group->id}/lifecycle-operations/{$receipt}/commit", [
            'current_password' => 'password', 'confirmation_code' => $prepared->json('data.confirmation_code'), 'confirmed' => true,
        ])->assertStatus(503);

        $this->assertSame('{"event":"intent"', File::get($ledger));
        $this->assertFileExists($vault.'/'.$receipt.'.json');
        $this->assertNotSoftDeleted('users', ['id' => $member->user_id]);
        $this->assertDatabaseHas('account_lifecycle_operations', ['id' => $receipt, 'state' => 'prepared']);
    }

    public function test_hardlinked_ledger_is_rejected_without_modifying_its_external_target(): void
    {
        $vault = $this->configureLifecycleStorage();
        $outside = dirname($vault).'/external-fixture.txt';
        File::put($outside, "Preserve outside fixture\n");
        $this->assertTrue(link($outside, dirname($vault).'/ledger/account-lifecycle.jsonl'));
        [$group, $representative, , $member] = $this->family();
        $member->update(['status' => GroupMember::STATUS_INACTIVE]);
        Sanctum::actingAs($representative->user);
        $prepared = $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", [
            'kind' => 'member_delete', 'membership_id' => $member->id,
        ])->assertCreated();

        $this->postJson("/v1/groups/{$group->id}/lifecycle-operations/{$prepared->json('data.receipt_id')}/commit", [
            'current_password' => 'password', 'confirmation_code' => $prepared->json('data.confirmation_code'), 'confirmed' => true,
        ])->assertStatus(503);

        $this->assertSame("Preserve outside fixture\n", File::get($outside));
        $this->assertNotSoftDeleted('users', ['id' => $member->user_id]);
    }

    public function test_ledger_rejects_secret_context_and_vault_rejects_parent_path_deletion(): void
    {
        $vault = $this->configureLifecycleStorage();
        $outside = dirname($vault).'/external-fixture.json';
        File::put($outside, 'Preserve fixture');
        try {
            app(LifecycleVault::class)->delete('../external-fixture');
            $this->fail('A parent path was accepted.');
        } catch (ServiceUnavailableHttpException) {
            $this->assertSame('Preserve fixture', File::get($outside));
        }
        [$group, $representative] = $this->family();
        Sanctum::actingAs($representative->user);
        $prepared = $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", ['kind' => 'group_withdraw'])->assertCreated();
        $operation = AccountLifecycleOperation::query()->findOrFail($prepared->json('data.receipt_id'));
        try {
            app(LifecycleLedger::class)->append($operation, 'committed', ['confirmation_code' => 'Must never be logged']);
            $this->fail('Secret ledger context was accepted.');
        } catch (ServiceUnavailableHttpException) {
            $this->assertFileDoesNotExist(dirname($vault).'/ledger/account-lifecycle.jsonl');
        }
    }

    public function test_vault_can_be_decrypted_with_its_key_and_rejects_ciphertext_or_metadata_tampering(): void
    {
        $vault = $this->configureLifecycleStorage();
        $keypair = sodium_crypto_box_keypair();
        config(['famie.lifecycle_public_key' => base64_encode(sodium_crypto_box_publickey($keypair))]);
        [$group, $representative, , $member] = $this->family();
        $member->update(['status' => GroupMember::STATUS_INACTIVE]);
        $username = $member->user->username;
        Sanctum::actingAs($representative->user);
        $prepared = $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", [
            'kind' => 'member_delete', 'membership_id' => $member->id,
        ])->assertCreated();
        $receipt = $prepared->json('data.receipt_id');
        $this->postJson("/v1/groups/{$group->id}/lifecycle-operations/{$receipt}/commit", [
            'current_password' => 'password', 'confirmation_code' => $prepared->json('data.confirmation_code'), 'confirmed' => true,
        ])->assertStatus(202);
        $envelope = json_decode(File::get($vault.'/'.$receipt.'.json'), true, flags: JSON_THROW_ON_ERROR);
        $dataKey = sodium_crypto_box_seal_open(base64_decode($envelope['sealed_key'], true), $keypair);
        $this->assertNotFalse($dataKey);
        $aad = [
            'operation_id' => $envelope['operation_id'], 'retention_until' => $envelope['retention_until'],
            'environment' => $envelope['environment'], 'key_id' => $envelope['key_id'],
        ];
        $aadText = json_encode($aad, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $ciphertext = base64_decode($envelope['ciphertext'], true);
        $nonce = base64_decode($envelope['nonce'], true);
        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $aadText, $nonce, $dataKey);
            $this->assertNotFalse($plaintext);
            $payload = json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($username, $payload['subjects'][0]['username']);
            $this->assertSame($receipt, $payload['operation_id']);
            $corrupted = $ciphertext;
            $corrupted[0] = chr(ord($corrupted[0]) ^ 1);
            $this->assertFalse(sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($corrupted, $aadText, $nonce, $dataKey));
            $modified = json_encode([...$aad, 'key_id' => 'wrong-key'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $this->assertFalse(sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $modified, $nonce, $dataKey));
        } finally {
            sodium_memzero($dataKey);
            sodium_memzero($keypair);
            if (isset($plaintext) && is_string($plaintext)) {
                sodium_memzero($plaintext);
            }
        }
    }

    public function test_closing_a_prepared_confirmation_cancels_it_and_allows_immediate_reopening(): void
    {
        $this->freezeTime();
        $vault = $this->configureLifecycleStorage();
        [$group, $representative, , $member] = $this->family();
        $member->update(['status' => GroupMember::STATUS_INACTIVE]);
        Sanctum::actingAs($representative->user);
        $body = ['kind' => 'member_delete', 'membership_id' => $member->id];
        $first = $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", $body)->assertCreated();
        $receipt = $first->json('data.receipt_id');
        $operation = AccountLifecycleOperation::query()->findOrFail($receipt);
        $this->assertSame(now()->addMinutes(15)->getTimestamp(), $operation->prepared_expires_at->getTimestamp());

        $this->postJson("/v1/groups/{$group->id}/lifecycle-operations/{$receipt}/cancel")
            ->assertOk()->assertJsonPath('data.state', 'aborted');
        $this->postJson("/v1/groups/{$group->id}/lifecycle-operations/{$receipt}/cancel")->assertOk();

        $this->assertDatabaseMissing('account_lifecycle_credentials', ['operation_id' => $receipt]);
        $this->assertNotSoftDeleted('users', ['id' => $member->user_id]);
        $this->assertFileDoesNotExist($vault.'/'.$receipt.'.json');
        $this->postJson('/v1/lifecycle-operation-status', [
            'receipt_id' => $receipt, 'confirmation_code' => $first->json('data.confirmation_code'),
        ])->assertNotFound();
        $second = $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", $body)->assertCreated();
        $this->assertNotSame($receipt, $second->json('data.receipt_id'));
        $this->postJson("/v1/groups/{$group->id}/lifecycle-operations/{$second->json('data.receipt_id')}/commit", [
            'current_password' => 'password', 'confirmation_code' => $second->json('data.confirmation_code'), 'confirmed' => true,
        ])->assertStatus(202);
    }

    public function test_wrong_password_and_repeated_reopening_do_not_block_member_deletion_preparation(): void
    {
        $this->freezeTime();
        $this->configureLifecycleStorage();
        [$group, $representative, , $member] = $this->family();
        $member->update(['status' => GroupMember::STATUS_INACTIVE]);
        Sanctum::actingAs($representative->user);
        $path = "/v1/groups/{$group->id}/lifecycle-operations";
        $first = $this->postJson($path, ['kind' => 'group_withdraw'])->assertCreated();
        $receipt = $first->json('data.receipt_id');
        $this->postJson("{$path}/{$receipt}/commit", [
            'current_password' => 'wrong-password', 'confirmation_code' => $first->json('data.confirmation_code'), 'confirmed' => true,
        ])->assertUnprocessable();
        $this->postJson("{$path}/{$receipt}/cancel")->assertOk();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $prepared = $this->postJson($path, ['kind' => 'group_withdraw'])->assertCreated();
            $this->postJson("{$path}/{$prepared->json('data.receipt_id')}/cancel")->assertOk();
        }
        $this->postJson($path, ['kind' => 'member_delete', 'membership_id' => $member->id])
            ->assertCreated()->assertJsonPath('data.kind', 'member_delete');

        $this->assertNotSoftDeleted('users', ['id' => $member->user_id]);
        $this->assertNull($group->fresh()->withdrawn_at);
    }

    public function test_preparation_throttle_recovers_after_a_minute_without_weakening_password_attempt_limits(): void
    {
        $this->freezeTime();
        $this->configureLifecycleStorage();
        [$group, $representative] = $this->family();
        Sanctum::actingAs($representative->user);
        $path = "/v1/groups/{$group->id}/lifecycle-operations";
        $prepared = $this->postJson($path, ['kind' => 'group_withdraw'])->assertCreated();
        $receipt = $prepared->json('data.receipt_id');
        $body = ['current_password' => 'wrong-password', 'confirmation_code' => $prepared->json('data.confirmation_code'), 'confirmed' => true];
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson("{$path}/{$receipt}/commit", $body)->assertUnprocessable();
        }
        $this->postJson("{$path}/{$receipt}/commit", $body)->assertStatus(429)->assertHeader('Retry-After', '900');
        $this->postJson("{$path}/{$receipt}/cancel")->assertOk();
        for ($attempt = 0; $attempt < 9; $attempt++) {
            $this->postJson($path, ['kind' => 'group_withdraw'])->assertCreated();
        }
        $this->postJson($path, ['kind' => 'group_withdraw'])->assertStatus(429)->assertHeader('Retry-After', '60');

        $this->travel(61)->seconds();
        $next = $this->postJson($path, ['kind' => 'group_withdraw'])->assertCreated();
        $this->postJson("{$path}/{$next->json('data.receipt_id')}/commit", [
            ...$body, 'confirmation_code' => $next->json('data.confirmation_code'),
        ])->assertStatus(429);
        $this->assertNull($group->fresh()->withdrawn_at);
    }

    public function test_preparation_reports_shared_commit_lockout_without_consuming_password_attempts(): void
    {
        $this->freezeTime();
        $this->configureLifecycleStorage();
        [$group, $representative, , $member] = $this->family();
        $member->update(['status' => GroupMember::STATUS_INACTIVE]);
        Sanctum::actingAs($representative->user);
        $path = "/v1/groups/{$group->id}/lifecycle-operations";
        $prepared = $this->postJson($path, ['kind' => 'group_withdraw'])
            ->assertCreated()->assertJsonPath('data.commit_retry_after', 0);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->postJson("{$path}/{$prepared->json('data.receipt_id')}/commit", [
                'current_password' => 'wrong-password', 'confirmation_code' => $prepared->json('data.confirmation_code'), 'confirmed' => true,
            ])->assertUnprocessable();
        }
        $prepared = $this->postJson($path, ['kind' => 'group_withdraw'])
            ->assertCreated()->assertJsonPath('data.commit_retry_after', 0);
        $this->postJson("{$path}/{$prepared->json('data.receipt_id')}/commit", [
            'current_password' => 'wrong-password', 'confirmation_code' => $prepared->json('data.confirmation_code'), 'confirmed' => true,
        ])->assertUnprocessable();

        $memberBody = ['kind' => 'member_delete', 'membership_id' => $member->id];
        $this->postJson($path, $memberBody)->assertCreated()->assertJsonPath('data.commit_retry_after', 900);
        $this->postJson($path, ['kind' => 'group_withdraw'])->assertCreated()->assertJsonPath('data.commit_retry_after', 900);
        $this->travel(60)->seconds();
        $this->postJson($path, $memberBody)->assertCreated()->assertJsonPath('data.commit_retry_after', 840);
        $this->travel(841)->seconds();
        $this->postJson($path, ['kind' => 'group_withdraw'])->assertCreated()->assertJsonPath('data.commit_retry_after', 0);
    }

    public function test_preparation_reports_an_ip_lockout_even_for_an_actor_with_no_commit_attempts(): void
    {
        $this->freezeTime();
        $this->configureLifecycleStorage();
        for ($actor = 0; $actor < 4; $actor++) {
            [$group, $representative] = $this->family();
            Sanctum::actingAs($representative->user);
            $path = "/v1/groups/{$group->id}/lifecycle-operations";
            $prepared = $this->postJson($path, ['kind' => 'group_withdraw'])->assertCreated();
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $this->postJson("{$path}/{$prepared->json('data.receipt_id')}/commit", [
                    'current_password' => 'wrong-password', 'confirmation_code' => $prepared->json('data.confirmation_code'), 'confirmed' => true,
                ])->assertUnprocessable();
            }
        }
        [$group, $representative] = $this->family();
        Sanctum::actingAs($representative->user);
        $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", ['kind' => 'group_withdraw'])
            ->assertCreated()->assertJsonPath('data.commit_retry_after', 900);
    }

    public function test_reopening_replaces_an_uncommitted_confirmation_left_by_a_closed_tab(): void
    {
        $this->configureLifecycleStorage();
        [$group, $representative, , $member] = $this->family();
        $member->update(['status' => GroupMember::STATUS_INACTIVE]);
        Sanctum::actingAs($representative->user);
        $body = ['kind' => 'member_delete', 'membership_id' => $member->id];
        $first = $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", $body)->assertCreated();
        $oldId = $first->json('data.receipt_id');

        $second = $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", $body)->assertCreated();

        $this->assertDatabaseHas('account_lifecycle_operations', ['id' => $oldId, 'state' => 'aborted']);
        $this->assertDatabaseMissing('account_lifecycle_credentials', ['operation_id' => $oldId]);
        $this->postJson("/v1/groups/{$group->id}/lifecycle-operations/{$oldId}/commit", [
            'current_password' => 'password', 'confirmation_code' => $first->json('data.confirmation_code'), 'confirmed' => true,
        ])->assertStatus(409);
        $this->assertDatabaseHas('account_lifecycle_operations', ['id' => $second->json('data.receipt_id'), 'state' => 'prepared']);
        $this->assertNotSoftDeleted('users', ['id' => $member->user_id]);
    }

    public function test_confirmation_cancellation_rejects_other_actors_holds_and_preserved_evidence(): void
    {
        $vault = $this->configureLifecycleStorage();
        [$group, $representative, $otherParent, $member] = $this->family();
        $member->update(['status' => GroupMember::STATUS_INACTIVE]);
        Sanctum::actingAs($representative->user);
        $body = ['kind' => 'member_delete', 'membership_id' => $member->id];
        $prepared = $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", $body)->assertCreated();
        $receipt = $prepared->json('data.receipt_id');
        $path = "/v1/groups/{$group->id}/lifecycle-operations/{$receipt}/cancel";
        Sanctum::actingAs($otherParent->user);
        $this->postJson($path)->assertForbidden();
        $foreign = GroupMember::factory()->create(['role' => 'admin']);
        $foreign->group->forceFill(['representative_user_id' => $foreign->user_id])->save();
        Sanctum::actingAs($foreign->user);
        $this->postJson("/v1/groups/{$foreign->group_id}/lifecycle-operations/{$receipt}/cancel")->assertNotFound();
        Sanctum::actingAs($representative->user);
        $this->postJson($path, ['actor_user_id' => $representative->user_id])->assertUnprocessable();
        $hold = DB::table('account_lifecycle_holds')->insertGetId([
            'operation_id' => $receipt, 'scope' => 'all', 'reference' => 'cancellation-hold',
            'started_at' => now(), 'review_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson($path)->assertStatus(409);
        DB::table('account_lifecycle_holds')->where('id', $hold)->update(['released_at' => now()]);
        File::put($vault.'/'.$receipt.'.json', 'Preserve unconfirmed evidence');

        $this->postJson($path)->assertStatus(409);
        $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", $body)->assertStatus(409);

        $this->assertSame('Preserve unconfirmed evidence', File::get($vault.'/'.$receipt.'.json'));
        $this->assertDatabaseHas('account_lifecycle_operations', ['id' => $receipt, 'state' => 'prepared']);
        $this->assertDatabaseHas('account_lifecycle_credentials', ['operation_id' => $receipt]);
        $this->assertNotSoftDeleted('users', ['id' => $member->user_id]);
    }

    private function configureLifecycleStorage(): string
    {
        $this->isolateOperationsStorage();
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'famie-lifecycle-'.bin2hex(random_bytes(8));
        $vault = $root.DIRECTORY_SEPARATOR.'vault';
        $ledger = $root.DIRECTORY_SEPARATOR.'ledger';
        File::makeDirectory($vault, 0700, true);
        File::makeDirectory($ledger, 0700, true);
        $keypair = sodium_crypto_box_keypair();
        config([
            'famie.lifecycle_vault_root' => $vault,
            'famie.lifecycle_ledger_root' => $ledger,
            'famie.lifecycle_public_key' => base64_encode(sodium_crypto_box_publickey($keypair)),
            'famie.lifecycle_key_id' => 'test-key-1',
        ]);

        return $vault;
    }

    /** @return array{Group, GroupMember, GroupMember, GroupMember} */
    private function family(): array
    {
        $group = Group::factory()->create();
        $representative = GroupMember::factory()->for($group)->for(User::factory())->create(['role' => 'admin']);
        $otherParent = GroupMember::factory()->for($group)->for(User::factory())->create(['role' => 'admin']);
        $member = GroupMember::factory()->for($group)->for(User::factory())->create(['role' => 'member']);
        $group->forceFill(['representative_user_id' => $representative->user_id])->save();

        return [$group, $representative, $otherParent, $member];
    }
}
