<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['famie.allowed_ips' => ['127.0.0.1']]);
    }

    #[TestWith(['parent'])]
    #[TestWith(['child'])]
    public function test_each_role_can_save_only_its_own_display_name(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $other = User::factory()->create(['display_name' => '別のユーザー']);
        $original = $user->only(['username', 'email', 'role', 'password']);
        Sanctum::actingAs($user);

        $response = $this->patchJson('/v1/auth/me', ['display_name' => '　新しい 名前😀　']);

        $response->assertOk()->assertJsonPath('user.display_name', '新しい 名前😀')
            ->assertJsonPath('user.id', $user->id)->assertJsonMissingPath('user.password');
        $this->assertSame('新しい 名前😀', $user->fresh()->display_name);
        $this->assertSame($original, $user->fresh()->only(['username', 'email', 'role', 'password']));
        $this->assertSame('別のユーザー', $other->fresh()->display_name);
    }

    #[DataProvider('validNames')]
    public function test_accepts_boundary_and_normalizes_whitespace(string $input, string $expected): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->patchJson('/v1/auth/me', ['display_name' => $input]);

        $response->assertOk()->assertJsonPath('user.display_name', $expected);
        $this->assertSame($expected, $user->fresh()->display_name);
    }

    public static function validNames(): array
    {
        return [
            '50 Japanese characters' => [str_repeat('あ', 50), str_repeat('あ', 50)],
            '50 emoji code points' => [str_repeat('😀', 50), str_repeat('😀', 50)],
            'invisible boundaries' => ["\u{0085}\u{200B}\u{FEFF}名前\u{00AD}\u{3000}", '名前'],
            'internal space and joined emoji' => ['家族 👨‍👩‍👧‍👦', '家族 👨‍👩‍👧‍👦'],
        ];
    }

    #[DataProvider('invalidNames')]
    public function test_rejects_invalid_names_with_422_and_a_japanese_reason(mixed $input, string $message): void
    {
        $user = User::factory()->create(['display_name' => '変更前']);
        Sanctum::actingAs($user);

        $response = $this->patchJson('/v1/auth/me', ['display_name' => $input]);

        $response->assertUnprocessable()->assertJsonValidationErrors('display_name')
            ->assertJsonPath('errors.display_name.0', $message);
        $this->assertSame('変更前', $user->fresh()->display_name);
    }

    public static function invalidNames(): array
    {
        return [
            'empty' => ['', '表示名を入力してください。'],
            'spaces' => [" \t\n　", '表示名を入力してください。'],
            'invisible only' => ["\u{200B}\u{FEFF}", '表示名を入力してください。'],
            'null' => [null, '表示名を入力してください。'],
            'number' => [123, '表示名は文字列で入力してください。'],
            '51 Japanese characters' => [str_repeat('あ', 51), '表示名は50文字以内で入力してください。'],
            '51 emoji code points' => [str_repeat('😀', 51), '表示名は50文字以内で入力してください。'],
        ];
    }

    #[TestWith(['parent'])]
    #[TestWith(['child'])]
    public function test_rejects_target_and_protected_fields_with_422(string $role): void
    {
        $user = User::factory()->create(['role' => $role, 'display_name' => '変更前']);
        $other = User::factory()->create(['display_name' => '別のユーザー']);
        $original = $user->fresh()->getAttributes();
        Sanctum::actingAs($user);

        $response = $this->patchJson('/v1/auth/me', [
            'display_name' => '書き換え', 'id' => $other->id, 'user_id' => $other->id,
            'username' => 'hijack', 'email' => 'hijack@example.test', 'role' => 'parent',
        ]);

        $response->assertUnprocessable()->assertJsonPath('errors.profile.0', '表示名以外は変更できません。');
        $this->assertSame($original, $user->fresh()->getAttributes());
        $this->assertSame('別のユーザー', $other->fresh()->display_name);
    }

    public function test_child_cannot_use_existing_parent_update_endpoint(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create(['display_name' => '別のユーザー']);
        Sanctum::actingAs($user);

        $response = $this->putJson("/v1/users/{$other->id}", ['display_name' => '書き換え']);

        $response->assertForbidden();
        $this->assertSame('別のユーザー', $other->fresh()->display_name);
    }

    public function test_rejects_missing_authentication_with_401(): void
    {
        $response = $this->patchJson('/v1/auth/me', ['display_name' => '未認証']);

        $response->assertUnauthorized();
    }
}
