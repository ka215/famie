<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use App\Support\InitialCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
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

    #[TestWith(['ja-JP', 'ja'])]
    #[TestWith(['en-US', 'en'])]
    #[TestWith(['fr', 'en'])]
    public function test_initial_categories_use_registration_language_and_stay_unchanged_after_language_switch(string $header, string $locale): void
    {
        $response = $this->withHeader('Accept-Language', $header)->postJson('/v1/auth/register', [
            'group_name' => 'Original family', 'username' => 'language-family', 'display_name' => 'Parent',
            'password' => 'Famie-registration-2026!', 'password_confirmation' => 'Famie-registration-2026!',
        ])->assertCreated()->assertJsonPath('user.ui_locale', null);
        $groupId = $response->json('membership.group.id');
        $categories = Category::query()->where('group_id', $groupId)->orderBy('sort_order')->get();
        $this->assertSame(array_values(__('categories', [], $locale)), $categories->pluck('name')->all());
        $this->assertSame(array_column(InitialCategories::ITEMS, 'color_code'), $categories->pluck('color_code')->all());
        $this->assertSame(range(1, 7), $categories->pluck('sort_order')->all());
        Sanctum::actingAs(User::query()->findOrFail($response->json('user.id')));
        $this->patchJson('/v1/auth/me', ['ui_locale' => $locale === 'en' ? 'ja' : 'en'])->assertOk();
        $this->getJson('/v1/groups/'.$groupId.'/categories')->assertOk()->assertJsonPath('0.name', $categories[0]->name);
        $this->assertSame($categories->pluck('name')->all(), $categories->map(fn ($category) => $category->fresh()->name)->all());
    }

    public function test_registration_atomically_creates_family_admin_and_initial_categories(): void
    {
        $response = $this->postJson('/v1/auth/register', [
            'group_name' => '山田家', 'username' => 'yamada', 'display_name' => '山田',
            'password' => 'Famie-registration-2026!', 'password_confirmation' => 'Famie-registration-2026!',
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
            'password' => 'Famie-registration-2026!', 'password_confirmation' => 'Famie-registration-2026!',
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
            'password' => 'Famie-registration-2026!', 'password_confirmation' => 'Famie-registration-2026!',
        ])->assertCreated();

        $this->postJson('/v1/auth/register/username-availability', ['username' => 'parent.one-1'])
            ->assertOk()->assertExactJson(['available' => false]);
        $this->postJson('/v1/auth/register', [
            'group_name' => '別家族', 'username' => 'parent.one-1', 'display_name' => '別の親',
            'password' => 'Famie-registration-2026!', 'password_confirmation' => 'Famie-registration-2026!',
        ])->assertUnprocessable()->assertJsonValidationErrors('username')
            ->assertJsonPath('errors.username.0', 'このログインIDは既に使用されています。');
    }
}
