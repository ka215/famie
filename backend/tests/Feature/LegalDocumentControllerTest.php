<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegalDocumentControllerTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            unlink($path);
        }
        parent::tearDown();
    }

    public function test_public_preview_is_available_without_authentication_or_database_access(): void
    {
        $this->manifest($this->documents());
        DB::shouldReceive('connection')->never();

        $response = $this->getJson('/v1/legal/documents');

        $response->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.acceptance_enabled', false)
            ->assertJsonPath('data.0.publishedOn', null)
            ->assertJsonPath('data.0.effectiveOnRelease', '1.0.0')
            ->assertJsonPath('data.0.translations.en.url', '/legal/terms/1.2.3/en/')
            ->assertJsonPath('data.0.translations.ja.sha256', str_repeat('a', 64));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_missing_translation_returns_503_without_exposing_manifest_path(): void
    {
        $documents = $this->documents();
        unset($documents[0]['translations']['en']);
        $path = $this->manifest($documents);
        DB::shouldReceive('connection')->never();

        $response = $this->getJson('/v1/legal/documents');

        $response->assertServiceUnavailable()->assertJsonPath('code', 'legal_documents_unavailable');
        $this->assertStringNotContainsString($path, $response->getContent());
    }

    public function test_duplicate_current_document_returns_503(): void
    {
        $documents = $this->documents();
        $documents[] = $documents[0];
        $this->manifest($documents);
        DB::shouldReceive('connection')->never();

        $this->getJson('/v1/legal/documents')->assertServiceUnavailable();
    }

    public function test_external_document_url_returns_503(): void
    {
        $documents = $this->documents();
        $documents[0]['translations']['ja']['url'] = 'https://example.com/terms';
        $this->manifest($documents);
        DB::shouldReceive('connection')->never();

        $this->getJson('/v1/legal/documents')->assertServiceUnavailable();
    }

    public function test_internal_manifest_fields_are_not_exposed(): void
    {
        $documents = $this->documents();
        $documents[0]['internal_note'] = 'synthetic private marker';
        $documents[0]['translations']['ja']['internal_path'] = '/private/synthetic';
        $this->manifest($documents);
        DB::shouldReceive('connection')->never();

        $this->getJson('/v1/legal/documents')->assertOk()
            ->assertJsonMissingPath('data.0.internal_note')
            ->assertJsonMissingPath('data.0.translations.ja.internal_path');
    }

    public function test_malformed_json_returns_503_without_database_access(): void
    {
        $path = $this->manifest($this->documents());
        file_put_contents($path, '{');
        DB::shouldReceive('connection')->never();

        $this->getJson('/v1/legal/documents')->assertServiceUnavailable();
    }

    public function test_non_preview_without_publication_date_returns_503(): void
    {
        $documents = $this->documents();
        $documents[0]['preview'] = false;
        $this->manifest($documents);
        DB::shouldReceive('connection')->never();

        $this->getJson('/v1/legal/documents')->assertServiceUnavailable();
    }

    /** @param list<array<string, mixed>> $documents */
    private function manifest(array $documents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'famie-legal-fixture-');
        $this->assertNotFalse($path);
        $this->temporaryFiles[] = $path;
        file_put_contents($path, json_encode(['schemaVersion' => 1, 'documents' => $documents], JSON_THROW_ON_ERROR));
        config(['legal.manifest_path' => $path]);

        return $path;
    }

    /** @return list<array<string, mixed>> */
    private function documents(): array
    {
        return array_map(function (string $id): array {
            $translations = [];
            foreach (['ja', 'en'] as $locale) {
                $translations[$locale] = ['title' => 'Synthetic document', 'url' => "/legal/{$id}/1.2.3/{$locale}/", 'sha256' => str_repeat('a', 64)];
            }

            return ['id' => $id, 'version' => '1.2.3', 'publishedOn' => null, 'effectiveAt' => null,
                'effectiveOnRelease' => '1.0.0', 'authoritativeLocale' => 'ja', 'current' => true,
                'preview' => true, 'requiresAcceptance' => $id !== 'cookies', 'translations' => $translations];
        }, ['terms', 'privacy', 'cookies']);
    }
}
