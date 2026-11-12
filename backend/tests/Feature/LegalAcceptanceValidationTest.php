<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LegalAcceptanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LegalAcceptanceValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::shouldReceive('connection')->never();
        Route::post('/_test/legal-validation', function (Request $request, LegalAcceptanceService $service) {
            return response()->json($service->validate($request->input('legal')));
        });
    }

    public function test_valid_input_uses_server_hash_and_separate_actions(): void
    {
        $documents = json_decode(file_get_contents(resource_path('legal-documents.json')), true, 64, JSON_THROW_ON_ERROR)['documents'];
        $this->postJson('/_test/legal-validation', ['legal' => $this->payload()])
            ->assertOk()->assertJsonCount(2)
            ->assertJsonPath('0.action', 'accept')->assertJsonPath('1.action', 'acknowledge')
            ->assertJsonPath('0.document_sha256', $documents[0]['translations']['ja']['sha256'])
            ->assertJsonMissingPath('0.user_id')->assertJsonMissingPath('0.recorded_at');
    }

    public function test_only_literal_true_is_accepted(): void
    {
        foreach ([false, 1, 'true', 'yes'] as $value) {
            $payload = $this->payload();
            $payload['terms']['accepted'] = $value;
            $this->postJson('/_test/legal-validation', ['legal' => $payload])
                ->assertUnprocessable()->assertJsonValidationErrors('legal.terms.accepted');
        }
    }

    public function test_privacy_acknowledgment_is_required_independently(): void
    {
        $payload = $this->payload();
        unset($payload['privacy']);
        $this->postJson('/_test/legal-validation', ['legal' => $payload])
            ->assertUnprocessable()->assertJsonValidationErrors('legal.privacy');
    }

    public function test_client_cannot_supply_identity_hash_or_time(): void
    {
        foreach (['user_id', 'document_sha256', 'recorded_at'] as $field) {
            $payload = $this->payload();
            $payload['terms'][$field] = 'synthetic';
            $this->postJson('/_test/legal-validation', ['legal' => $payload])
                ->assertUnprocessable()->assertJsonValidationErrors('legal.terms');
        }
    }

    public function test_stale_version_returns_current_documents_without_database_access(): void
    {
        $payload = $this->payload();
        $payload['privacy']['version'] = '999.0.0';
        $this->postJson('/_test/legal-validation', ['legal' => $payload])
            ->assertConflict()->assertJsonPath('code', 'legal_version_changed')->assertJsonCount(3, 'data');
    }

    public function test_missing_catalog_fails_closed_without_exposing_path(): void
    {
        config(['legal.manifest_path' => resource_path('missing-synthetic-legal.json')]);
        $response = $this->postJson('/_test/legal-validation', ['legal' => $this->payload()]);
        $response->assertServiceUnavailable()->assertJsonPath('code', 'legal_documents_unavailable');
        $this->assertStringNotContainsString('missing-synthetic-legal.json', $response->getContent());
    }

    public function test_disabled_feature_rejects_writes_before_database_access(): void
    {
        config(['legal.storage_enabled' => false, 'legal.acceptance_enabled' => false]);
        Route::post('/_test/legal-disabled', function (LegalAcceptanceService $service): void {
            $service->accept(new User, $this->payload());
        });
        $this->postJson('/_test/legal-disabled')->assertServiceUnavailable();
    }

    /** @return array<string, array<string, mixed>> */
    private function payload(): array
    {
        $documents = json_decode(file_get_contents(resource_path('legal-documents.json')), true, 64, JSON_THROW_ON_ERROR)['documents'];
        $versions = array_column($documents, 'version', 'id');

        return ['terms' => ['version' => $versions['terms'], 'locale' => 'ja', 'accepted' => true],
            'privacy' => ['version' => $versions['privacy'], 'locale' => 'en', 'acknowledged' => true]];
    }
}
