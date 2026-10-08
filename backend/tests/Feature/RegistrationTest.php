<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\InitialCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['famie.allowed_ips' => ['127.0.0.1'], 'famie.register_max_attempts' => 5]);
        RateLimiter::clear('register:attempts:127.0.0.1');
        RateLimiter::clear('register:blocked:127.0.0.1');
    }

    public function test_registration_atomically_creates_family_admin_and_initial_categories(): void
    {
        $response = $this->postJson('/v1/auth/register', [
            'group_name' => '山田家', 'username' => 'yamada', 'display_name' => '山田',
            'password' => 'password', 'password_confirmation' => 'password',
        ]);

        $response->assertCreated()->assertJsonPath('user.show_name_suffix', true)->assertJsonPath('membership.role', 'admin')->assertJsonPath('membership.group.name', '山田家')
            ->assertJsonStructure(['access_token', 'user' => ['id'], 'membership' => ['group' => ['id']]]);
        $groupId = $response->json('membership.group.id');
        $this->assertDatabaseHas('group_members', ['group_id' => $groupId, 'role' => 'admin', 'status' => 'active']);
        $this->assertDatabaseHas('users', ['username' => 'yamada', 'email' => null]);
        $this->assertDatabaseCount('categories', count(InitialCategories::ITEMS));
    }

    public function test_registration_rejects_email_without_disclosing_whether_it_is_registered(): void
    {
        User::factory()->create(['email' => 'registered@example.test']);

        $payload = [
            'group_name' => '山田家', 'username' => 'yamada', 'display_name' => '山田',
            'password' => 'password', 'password_confirmation' => 'password',
        ];

        $unknownEmailResponse = $this->postJson('/v1/auth/register', [
            ...$payload,
            'email' => 'unknown@example.test',
        ]);

        $knownEmailResponse = $this->postJson('/v1/auth/register', [
            ...$payload,
            'email' => 'registered@example.test',
        ]);

        $unknownEmailResponse->assertUnprocessable()->assertJsonValidationErrors('registration');
        $knownEmailResponse->assertUnprocessable()->assertJsonValidationErrors('registration');
        $this->assertSame($unknownEmailResponse->json('errors.registration'), $knownEmailResponse->json('errors.registration'));
        $this->assertDatabaseMissing('users', ['username' => 'yamada']);
    }

    public function test_sixth_registration_attempt_from_same_ip_is_rate_limited(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/v1/auth/register', [])->assertUnprocessable();
        }

        $this->postJson('/v1/auth/register', [])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_username_rules_and_availability_are_consistent_with_registration(): void
    {
        $this->postJson('/v1/auth/register/username-availability', ['username' => 'Parent 1'])
            ->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->postJson('/v1/auth/register/username-availability', ['username' => 'parent.one-1'])
            ->assertOk()->assertExactJson(['available' => true]);

        $this->postJson('/v1/auth/register', [
            'group_name' => '登録済み家族', 'username' => 'parent.one-1', 'display_name' => '親',
            'password' => 'password', 'password_confirmation' => 'password',
        ])->assertCreated();

        $this->postJson('/v1/auth/register/username-availability', ['username' => 'parent.one-1'])
            ->assertOk()->assertExactJson(['available' => false]);
        $this->postJson('/v1/auth/register', [
            'group_name' => '別家族', 'username' => 'parent.one-1', 'display_name' => '別の親',
            'password' => 'password', 'password_confirmation' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('username')
            ->assertJsonPath('errors.username.0', 'このログインIDは既に使用されています。');
    }
}
