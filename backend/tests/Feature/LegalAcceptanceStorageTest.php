<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\LegalAcceptance;
use App\Models\User;
use App\Services\LegalAcceptanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class LegalAcceptanceStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['legal.storage_enabled' => true, 'legal.acceptance_enabled' => true]);
    }

    public function test_repeated_submission_preserves_first_history_without_duplicates(): void
    {
        $user = User::factory()->create();
        $service = app(LegalAcceptanceService::class);
        $records = $this->records();
        $service->record($user, $records);
        $first = LegalAcceptance::query()->firstOrFail()->recorded_at;
        $this->travel(1)->hours();
        $records[0]['locale'] = 'en';
        $service->record($user, $records);

        $this->assertDatabaseCount('legal_acceptances', 2);
        $this->assertDatabaseHas('legal_acceptances', ['user_id' => $user->id, 'document_id' => 'terms', 'locale' => 'ja']);
        $this->assertTrue($first->equalTo(LegalAcceptance::query()->firstOrFail()->recorded_at));
    }

    public function test_cleanup_removes_only_target_history_even_when_acceptance_is_disabled(): void
    {
        $target = User::factory()->create();
        $other = User::factory()->create();
        $service = app(LegalAcceptanceService::class);
        $service->record($target, $this->records());
        $service->record($other, $this->records());
        config(['legal.acceptance_enabled' => false]);
        $service->forgetUser($target->id);

        $this->assertDatabaseMissing('legal_acceptances', ['user_id' => $target->id]);
        $this->assertDatabaseCount('legal_acceptances', 2);
        $this->assertDatabaseHas('legal_acceptances', ['user_id' => $other->id]);
    }

    public function test_cleanup_participates_in_caller_transaction(): void
    {
        $user = User::factory()->create();
        $service = app(LegalAcceptanceService::class);
        $service->record($user, $this->records());
        try {
            DB::transaction(function () use ($service, $user): void {
                $service->forgetUser($user->id);
                throw new RuntimeException('Synthetic rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic rollback', $exception->getMessage());
        }
        $this->assertDatabaseCount('legal_acceptances', 2);
    }

    public function test_endpoint_records_only_authenticated_representative_and_is_idempotent(): void
    {
        $member = $this->representative();
        Sanctum::actingAs($member->user);
        $payload = ['legal' => $this->payload()];
        $this->postJson('/v1/auth/legal-acceptances', $payload)->assertOk()->assertJsonPath('status', 'current');
        $this->postJson('/v1/auth/legal-acceptances', $payload)->assertOk();
        $this->assertDatabaseCount('legal_acceptances', 2);
        $this->assertDatabaseHas('legal_acceptances', ['user_id' => $member->user_id, 'document_id' => 'privacy', 'action' => 'acknowledge']);
    }

    public function test_other_administrator_cannot_submit_for_representative(): void
    {
        $representative = $this->representative();
        $other = GroupMember::factory()->for($representative->group)->create(['role' => 'admin']);
        Sanctum::actingAs($other->user);
        $this->postJson('/v1/auth/legal-acceptances', ['legal' => $this->payload()])->assertForbidden();
        $this->assertDatabaseCount('legal_acceptances', 0);
    }

    public function test_stale_version_does_not_leave_partial_history(): void
    {
        $member = $this->representative();
        Sanctum::actingAs($member->user);
        $payload = $this->payload();
        $payload['privacy']['version'] = '999.0.0';
        $this->postJson('/v1/auth/legal-acceptances', ['legal' => $payload])->assertConflict();
        $this->assertDatabaseCount('legal_acceptances', 0);
    }

    public function test_registration_requires_both_checks_before_creating_account(): void
    {
        $before = User::query()->count();
        $this->postJson('/v1/auth/register', $this->registration())
            ->assertUnprocessable()->assertJsonValidationErrors('legal');
        $this->assertSame($before, User::query()->count());
        $this->assertDatabaseCount('legal_acceptances', 0);
    }

    public function test_registration_records_new_user_in_same_transaction(): void
    {
        $this->postJson('/v1/auth/register', [...$this->registration(), 'legal' => $this->payload()])->assertCreated();
        $user = User::query()->where('username', 'legal_test_owner')->firstOrFail();
        $this->assertDatabaseCount('legal_acceptances', 2);
        $this->assertDatabaseHas('legal_acceptances', ['user_id' => $user->id, 'document_id' => 'terms', 'action' => 'accept']);
    }

    /** @return array<string, string> */
    private function registration(): array
    {
        return ['group_name' => 'Synthetic family', 'username' => 'legal_test_owner', 'display_name' => 'Synthetic owner',
            'password' => 'TestLegal!7xQ29', 'password_confirmation' => 'TestLegal!7xQ29'];
    }

    private function representative(): GroupMember
    {
        $group = Group::factory()->create();
        $member = GroupMember::factory()->for($group)->create(['role' => 'admin']);
        $group->forceFill(['representative_user_id' => $member->user_id])->save();

        return $member;
    }

    /** @return array<string, array<string, mixed>> */
    private function payload(): array
    {
        $documents = json_decode(file_get_contents(resource_path('legal-documents.json')), true, 64, JSON_THROW_ON_ERROR)['documents'];
        $versions = array_column($documents, 'version', 'id');

        return ['terms' => ['version' => $versions['terms'], 'locale' => 'ja', 'accepted' => true],
            'privacy' => ['version' => $versions['privacy'], 'locale' => 'en', 'acknowledged' => true]];
    }

    /** @return list<array<string, string>> */
    private function records(): array
    {
        return array_map(fn (string $id): array => ['document_id' => $id, 'document_version' => '0.1.0',
            'document_sha256' => str_repeat('a', 64), 'action' => $id === 'terms' ? 'accept' : 'acknowledge', 'locale' => 'ja'], ['terms', 'privacy']);
    }
}
