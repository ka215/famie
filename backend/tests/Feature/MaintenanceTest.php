<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['famie.allowed_ips' => ['127.0.0.1'], 'app.maintenance.driver' => 'array']);
    }

    public function test_status_returns_uncached_ok_without_authentication(): void
    {
        $response = $this->getJson('/v1/status');

        $response->assertOk()->assertExactJson(['status' => 'ok'])
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_maintenance_returns_identifiable_503_before_authentication(): void
    {
        $this->app->maintenanceMode()->activate(['retry' => 60]);

        $response = $this->getJson('/v1/auth/me');

        $response->assertServiceUnavailable()
            ->assertJsonPath('code', 'maintenance')
            ->assertJsonPath('message', 'ただいまメンテナンス中です。しばらくしてから再試行してください。')
            ->assertHeader('Retry-After', '60')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_maintenance_rejects_writes_with_503_without_running_the_action(): void
    {
        $this->app->maintenanceMode()->activate([]);
        $called = false;
        Route::post('/v1/maintenance-write-test', function () use (&$called) {
            $called = true;

            return response()->noContent();
        });

        $response = $this->postJson('/v1/maintenance-write-test');

        $response->assertServiceUnavailable()->assertJsonPath('code', 'maintenance');
        $this->assertFalse($called);
    }

    public function test_disallowed_ip_still_receives_403_during_maintenance(): void
    {
        config(['famie.allowed_ips' => ['192.0.2.1']]);
        $this->app->maintenanceMode()->activate([]);

        $response = $this->getJson('/v1/status');

        $response->assertForbidden()->assertJsonMissing(['code' => 'maintenance']);
    }

    public function test_maintenance_api_returns_json_even_without_accept_header(): void
    {
        $this->app->maintenanceMode()->activate([]);

        $response = $this->get('/v1/status');

        $response->assertServiceUnavailable()->assertJsonPath('code', 'maintenance');
    }

    public function test_status_recovers_after_maintenance_is_disabled(): void
    {
        $this->app->maintenanceMode()->activate([]);
        $this->app->maintenanceMode()->deactivate();

        $response = $this->getJson('/v1/status');

        $response->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_unrelated_503_is_not_reported_as_maintenance(): void
    {
        Route::get('/v1/unavailable-test', fn () => abort(503, 'Unavailable'));

        $response = $this->getJson('/v1/unavailable-test');

        $response->assertServiceUnavailable()->assertJsonMissing(['code' => 'maintenance']);
    }
}
