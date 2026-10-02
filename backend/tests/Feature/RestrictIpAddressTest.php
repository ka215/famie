<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RestrictIpAddressTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith([true, ['127.0.0.1'], 200])]
    #[TestWith([true, ['192.0.2.1'], 403])]
    #[TestWith([true, [], 403])]
    #[TestWith([false, ['192.0.2.1'], 200])]
    #[TestWith([false, [], 200])]
    #[TestWith([null, [], 403])]
    #[TestWith(['false', [], 403])]
    public function test_only_explicit_false_disables_ip_restriction(mixed $enabled, array $allowedIps, int $status): void
    {
        config(['famie.ip_restriction_enabled' => $enabled, 'famie.allowed_ips' => $allowedIps]);

        $this->getJson('/v1/status')->assertStatus($status);
    }

    public function test_missing_setting_keeps_ip_restriction_enabled(): void
    {
        config(['famie' => ['allowed_ips' => []]]);

        $this->getJson('/v1/status')->assertForbidden();
    }

    public function test_disabled_ip_restriction_does_not_bypass_authentication_or_authorization(): void
    {
        config(['famie.ip_restriction_enabled' => false, 'famie.allowed_ips' => []]);

        $group = Group::factory()->create();
        $this->getJson("/v1/groups/{$group->id}/members")->assertUnauthorized();
        $this->postJson("/v1/groups/{$group->id}/logs", [])->assertUnauthorized();

        $child = User::factory()->create();
        GroupMember::query()->create(['group_id' => $group->id, 'user_id' => $child->id, 'role' => 'member', 'status' => 'active']);
        Sanctum::actingAs($child);
        $this->getJson("/v1/groups/{$group->id}/members")->assertOk();
        $this->postJson("/v1/groups/{$group->id}/categories", ['name' => '禁止'])->assertForbidden();
        $this->assertDatabaseMissing('categories', ['name' => '禁止']);
    }

    public function test_disabled_ip_restriction_preserves_maintenance_503_and_rejects_writes(): void
    {
        config(['famie.ip_restriction_enabled' => false, 'famie.allowed_ips' => [], 'app.maintenance.driver' => 'array']);
        $this->app->maintenanceMode()->activate([]);

        $this->getJson('/v1/status')->assertServiceUnavailable()->assertJsonPath('code', 'maintenance');
        $this->postJson('/v1/groups/1/logs', [])->assertServiceUnavailable()->assertJsonPath('code', 'maintenance');
        $this->assertDatabaseCount('activity_logs', 0);
    }
}
