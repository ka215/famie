<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LoginSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['famie.ip_restriction_enabled' => false]);
        Cache::flush();
    }

    public function test_five_failures_block_even_a_correct_password_for_ten_minutes(): void
    {
        $this->freezeTime();
        $user = $this->activeUser();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/v1/auth/login', ['login' => $user->username, 'password' => 'incorrect'])
                ->assertUnprocessable()->assertJsonValidationErrors('login');
        }

        $this->postJson('/v1/auth/login', ['login' => $user->username, 'password' => 'password'])
            ->assertTooManyRequests()->assertHeader('Retry-After', '600');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->travel(599)->seconds();
        $this->postJson('/v1/auth/login', ['login' => $user->username, 'password' => 'password'])->assertTooManyRequests();
        $this->travel(1)->seconds();
        $this->postJson('/v1/auth/login', ['login' => $user->username, 'password' => 'password'])->assertOk();
    }

    public function test_email_alias_case_and_spoofed_device_headers_do_not_bypass_the_account_limit(): void
    {
        $user = $this->activeUser();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withHeaders(['User-Agent' => 'device-'.$attempt, 'X-Device-ID' => (string) $attempt])
                ->postJson('/v1/auth/login', ['login' => $user->username, 'password' => 'incorrect'])->assertUnprocessable();
        }

        $this->withHeaders(['X-Forwarded-For' => '203.0.113.21', 'X-Device-ID' => 'another-device'])
            ->postJson('/v1/auth/login', ['login' => strtoupper($user->email), 'password' => 'password'])->assertTooManyRequests();
    }

    public function test_failure_limit_does_not_block_another_family_member_on_the_same_ip(): void
    {
        $first = $this->activeUser();
        $second = $this->activeUser();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/v1/auth/login', ['login' => $first->username, 'password' => 'incorrect'])->assertUnprocessable();
        }

        $this->postJson('/v1/auth/login', ['login' => $second->username, 'password' => 'password'])->assertOk();
    }

    public function test_success_clears_previous_failures(): void
    {
        $user = $this->activeUser();
        for ($round = 0; $round < 2; $round++) {
            for ($attempt = 0; $attempt < 4; $attempt++) {
                $this->postJson('/v1/auth/login', ['login' => $user->username, 'password' => 'incorrect'])->assertUnprocessable();
            }
            $this->postJson('/v1/auth/login', ['login' => $user->username, 'password' => 'password'])->assertOk();
        }
    }

    public function test_ip_limit_blocks_spraying_across_different_login_ids(): void
    {
        config(['famie.login_ip_per_minute' => 3]);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/v1/auth/login', ['login' => 'missing-'.$attempt, 'password' => 'incorrect'])->assertUnprocessable();
        }

        $this->postJson('/v1/auth/login', ['login' => 'missing-other', 'password' => 'incorrect'])
            ->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_sql_injection_and_unknown_accounts_receive_the_same_generic_error(): void
    {
        $user = $this->activeUser();
        $known = $this->postJson('/v1/auth/login', ['login' => $user->username, 'password' => 'incorrect']);
        $injection = $this->postJson('/v1/auth/login', ['login' => "' OR 1=1 --", 'password' => 'password']);

        $known->assertUnprocessable()->assertJsonValidationErrors('login');
        $injection->assertUnprocessable()->assertJsonValidationErrors('login')->assertJsonMissingPath('access_token');
        $this->assertSame($known->json('errors'), $injection->json('errors'));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    private function activeUser(): User
    {
        $user = User::factory()->create();
        GroupMember::query()->create(['group_id' => Group::factory()->create()->id, 'user_id' => $user->id, 'role' => 'admin', 'status' => 'active']);

        return $user;
    }
}
