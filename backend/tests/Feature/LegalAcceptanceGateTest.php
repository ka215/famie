<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\LegalAcceptance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\Support\IsolatesOperationsStorage;
use Tests\TestCase;

class LegalAcceptanceGateTest extends TestCase
{
    use IsolatesOperationsStorage, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['legal.storage_enabled' => true, 'legal.acceptance_enabled' => true]);
    }

    public function test_unconfirmed_representative_is_restricted_until_both_actions_are_recorded(): void
    {
        $member = $this->representative();
        Sanctum::actingAs($member->user);
        $this->getJson('/v1/auth/me')->assertOk()->assertJsonPath('legal.required', true);
        $this->patchJson('/v1/auth/me', ['display_name' => 'Changed'])->assertStatus(428)->assertJsonPath('code', 'legal_acceptance_required');
        $this->assertDatabaseMissing('users', ['id' => $member->user_id, 'display_name' => 'Changed']);
        $this->postJson('/v1/auth/legal-acceptances', ['legal' => $this->payload()])->assertOk();
        $this->getJson('/v1/auth/me')->assertOk()->assertJsonPath('legal.required', false);
        $this->patchJson('/v1/auth/me', ['display_name' => 'Changed'])->assertOk();
    }

    public function test_other_administrators_are_not_restricted_and_cannot_confirm_for_representative(): void
    {
        $representative = $this->representative();
        $other = GroupMember::factory()->for($representative->group)->create(['role' => 'admin']);
        Sanctum::actingAs($other->user);
        $this->getJson('/v1/auth/me')->assertOk()->assertJsonPath('legal.required', false);
        $this->patchJson('/v1/auth/me', ['display_name' => 'Other admin'])->assertOk();
        $this->postJson('/v1/auth/legal-acceptances', ['legal' => $this->payload()])->assertForbidden();
        $this->assertDatabaseCount('legal_acceptances', 0);
    }

    public function test_editorial_revision_can_preserve_acceptance_but_material_revision_requires_review(): void
    {
        $member = $this->representative();
        Sanctum::actingAs($member->user);
        foreach (['terms' => 'accept', 'privacy' => 'acknowledge'] as $id => $action) {
            LegalAcceptance::query()->create(['user_id' => $member->user_id, 'document_id' => $id,
                'document_version' => '0.0.9', 'document_sha256' => str_repeat('a', 64),
                'action' => $action, 'locale' => 'ja', 'recorded_at' => now()]);
        }
        config(['legal.accepted_versions' => ['terms' => ['0.0.9', '0.1.0'], 'privacy' => ['0.0.9', '0.1.0']]]);
        $this->getJson('/v1/auth/me')->assertOk()->assertJsonPath('legal.required', false);
        config(['legal.accepted_versions.terms' => ['0.1.0']]);
        $this->getJson('/v1/auth/me')->assertOk()->assertJsonPath('legal.required', true);
        $this->patchJson('/v1/auth/me', ['display_name' => 'Blocked'])->assertStatus(428);
    }

    public function test_manifest_version_not_in_accepted_versions_fails_closed(): void
    {
        $member = $this->representative();
        Sanctum::actingAs($member->user);
        config(['legal.accepted_versions.terms' => ['0.0.9']]);
        $this->getJson('/v1/auth/me')->assertServiceUnavailable();
    }

    public function test_missing_privacy_acknowledgment_does_not_remove_restriction(): void
    {
        $member = $this->representative();
        Sanctum::actingAs($member->user);
        LegalAcceptance::query()->create(['user_id' => $member->user_id, 'document_id' => 'terms',
            'document_version' => '0.1.0', 'document_sha256' => str_repeat('a', 64),
            'action' => 'accept', 'locale' => 'ja', 'recorded_at' => now()]);
        $this->getJson('/v1/auth/me')->assertOk()->assertJsonPath('legal.required', true);
    }

    public function test_unconfirmed_representative_can_read_documents_and_log_out(): void
    {
        $member = $this->representative();
        Sanctum::actingAs($member->user);
        $this->getJson('/v1/legal/documents')->assertOk();
        $this->postJson('/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('legal_acceptances', 0);
    }

    public function test_unconfirmed_representative_can_withdraw_without_accepting_documents(): void
    {
        $this->isolateOperationsStorage();
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'famie-legal-'.bin2hex(random_bytes(8));
        File::makeDirectory($root.'/vault', 0700, true);
        File::makeDirectory($root.'/ledger', 0700);
        $this->beforeApplicationDestroyed(function () use ($root): void {
            if (! is_link($root) && dirname(realpath($root)) === realpath(sys_get_temp_dir())) {
                File::deleteDirectory($root);
            }
        });
        $keypair = sodium_crypto_box_keypair();
        config(['famie.lifecycle_vault_root' => $root.'/vault', 'famie.lifecycle_ledger_root' => $root.'/ledger',
            'famie.lifecycle_public_key' => base64_encode(sodium_crypto_box_publickey($keypair)), 'famie.lifecycle_key_id' => 'synthetic-legal-test']);
        $member = $this->representative();
        Sanctum::actingAs($member->user);
        $path = '/v1/groups/'.$member->group_id.'/lifecycle-operations';
        $prepared = $this->postJson($path, ['kind' => 'group_withdraw'])->assertCreated();
        $this->postJson($path.'/'.$prepared->json('data.receipt_id').'/cancel')->assertOk();
        $prepared = $this->postJson($path, ['kind' => 'group_withdraw'])->assertCreated();
        $this->postJson($path.'/'.$prepared->json('data.receipt_id').'/commit', [
            'current_password' => 'password', 'confirmation_code' => $prepared->json('data.confirmation_code'), 'confirmed' => true,
        ])->assertStatus(202);
        $this->assertSoftDeleted('users', ['id' => $member->user_id]);
        $this->assertDatabaseCount('legal_acceptances', 0);
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
        return ['terms' => ['version' => '0.1.0', 'locale' => 'ja', 'accepted' => true],
            'privacy' => ['version' => '0.1.0', 'locale' => 'en', 'acknowledged' => true]];
    }
}
