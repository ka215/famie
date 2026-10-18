<?php

namespace Tests\Feature;

use App\Models\ActivityImage;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class UiLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['famie.allowed_ips' => ['127.0.0.1']]);
    }

    #[DataProvider('languageHeaders')]
    public function test_guest_errors_use_only_the_highest_priority_language(string $header, string $locale, string $message): void
    {
        $this->withHeader('Accept-Language', $header)->getJson('/v1/auth/me')
            ->assertUnauthorized()->assertHeader('Content-Language', $locale)
            ->assertJsonPath('message', $message);
    }

    public static function languageHeaders(): array
    {
        return [
            'Japanese region' => ['ja-JP', 'ja', 'もう一度ログインしてください。'],
            'Japanese' => ['ja', 'ja', 'もう一度ログインしてください。'],
            'English region' => ['en-US', 'en', 'Please log in again.'],
            'English in Japan' => ['en-JP', 'en', 'Please log in again.'],
            'unsupported first' => ['fr-FR, ja;q=0.8', 'en', 'Please log in again.'],
            'quality order' => ['en;q=0.5,ja;q=0.9', 'ja', 'もう一度ログインしてください。'],
            'equal priority' => ['en,ja', 'en', 'Please log in again.'],
            'excluded language' => ['ja;q=0,en;q=0.5', 'en', 'Please log in again.'],
            'missing' => ['', 'en', 'Please log in again.'],
            'invalid' => ['../../ja', 'en', 'Please log in again.'],
            'wildcard' => ['*', 'en', 'Please log in again.'],
        ];
    }

    #[TestWith(['admin'])]
    #[TestWith(['member'])]
    public function test_both_roles_can_save_only_their_own_language_and_clear_to_automatic(string $role): void
    {
        $user = $this->activeUser($role);
        $other = User::factory()->create(['ui_locale' => 'en']);
        Sanctum::actingAs($user);
        $this->getJson('/v1/auth/me')->assertOk()->assertJsonPath('user.ui_locale', null);
        $this->patchJson('/v1/auth/me', ['ui_locale' => 'ja'])
            ->assertOk()->assertJsonPath('user.ui_locale', 'ja')->assertHeader('Content-Language', 'ja');
        $this->assertSame('ja', $user->fresh()->ui_locale);
        $this->patchJson('/v1/auth/me', ['display_name' => 'New name'])
            ->assertOk()->assertJsonPath('user.ui_locale', 'ja');
        $this->patchJson('/v1/auth/me', ['ui_locale' => 'en'])
            ->assertOk()->assertJsonPath('user.ui_locale', 'en')->assertHeader('Content-Language', 'ja');
        $this->assertSame('en', $user->fresh()->ui_locale);
        $this->patchJson('/v1/auth/me', ['ui_locale' => null])
            ->assertOk()->assertJsonPath('user.ui_locale', null)->assertHeader('Content-Language', 'en');
        $this->assertNull($user->fresh()->ui_locale);
        $this->assertSame('en', $other->fresh()->ui_locale);
    }

    #[TestWith([''])]
    #[TestWith([' '])]
    #[TestWith([' ja '])]
    #[TestWith(['ja-JP'])]
    #[TestWith(['JA'])]
    #[TestWith(['auto'])]
    #[TestWith(['fr'])]
    #[TestWith([1])]
    #[TestWith([false])]
    #[TestWith([[]])]
    public function test_invalid_language_returns_422_without_saving_other_profile_fields(mixed $value): void
    {
        $user = $this->activeUser();
        Sanctum::actingAs($user);
        $original = $user->fresh()->getAttributes();
        $this->patchJson('/v1/auth/me', ['ui_locale' => $value, 'display_name' => 'Changed'])
            ->assertUnprocessable()->assertJsonValidationErrors('ui_locale');
        $this->assertSame($original, $user->fresh()->getAttributes());
    }

    public function test_authenticated_fixed_language_overrides_header_and_does_not_leak_to_next_request(): void
    {
        $user = $this->activeUser();
        $user->update(['ui_locale' => 'en']);
        Sanctum::actingAs($user);
        $this->withHeader('Accept-Language', 'ja')->patchJson('/v1/auth/me', ['display_name' => ''])
            ->assertUnprocessable()->assertHeader('Content-Language', 'en')
            ->assertJsonPath('errors.display_name.0', 'Enter a display name.');
        $user->update(['ui_locale' => null]);
        $this->patchJson('/v1/auth/me', ['display_name' => ''])
            ->assertUnprocessable()->assertHeader('Content-Language', 'ja')
            ->assertJsonPath('errors.display_name.0', '表示名を入力してください。');
    }

    public function test_family_admin_cannot_set_a_members_language(): void
    {
        $user = $this->activeUser('admin');
        $group = $user->membership->group;
        Sanctum::actingAs($user);
        $this->postJson('/v1/groups/'.$group->id.'/members', [
            'username' => 'new-member', 'display_name' => 'New member', 'role' => 'member',
            'password' => 'a sufficiently long phrase', 'password_confirmation' => 'a sufficiently long phrase', 'ui_locale' => 'en',
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['username' => 'new-member']);
        $member = $user->membership;
        $this->patchJson('/v1/groups/'.$group->id.'/members/'.$member->id, ['display_name' => 'Changed', 'role' => 'admin', 'ui_locale' => 'en'])
            ->assertUnprocessable();
        $this->assertNull($user->fresh()->ui_locale);
    }

    public function test_fixed_user_language_is_not_exposed_on_failed_login_or_public_reset(): void
    {
        Mail::fake();
        $user = $this->activeUser();
        $user->update(['ui_locale' => 'ja']);
        $known = $this->withHeader('Accept-Language', 'en')->postJson('/v1/auth/login', ['login' => $user->username, 'password' => 'wrong']);
        $unknown = $this->postJson('/v1/auth/login', ['login' => 'missing-user', 'password' => 'wrong']);
        $known->assertUnprocessable()->assertHeader('Content-Language', 'en');
        $this->assertSame($known->json(), $unknown->json());
        $registered = $this->postJson('/v1/auth/password/forgot', ['email' => $user->email]);
        $missing = $this->postJson('/v1/auth/password/forgot', ['email' => 'missing@example.test']);
        $registered->assertOk()->assertHeader('Content-Language', 'en');
        $this->assertSame($registered->json(), $missing->json());
        Mail::assertNothingSent();
    }

    public function test_login_and_registration_return_language_and_forbid_guest_preference_input(): void
    {
        $user = $this->activeUser();
        $user->update(['password' => 'a sufficiently long phrase', 'ui_locale' => 'en']);
        $this->postJson('/v1/auth/login', ['login' => $user->username, 'password' => 'a sufficiently long phrase'])
            ->assertOk()->assertJsonPath('user.ui_locale', 'en');
        $payload = ['group_name' => 'Family', 'username' => 'new-parent', 'display_name' => 'Parent', 'password' => 'a sufficiently long phrase', 'password_confirmation' => 'a sufficiently long phrase'];
        $this->postJson('/v1/auth/register', [...$payload, 'ui_locale' => 'en'])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['username' => 'new-parent']);
        $this->postJson('/v1/auth/register', $payload)->assertCreated()->assertJsonPath('user.ui_locale', null);
        $this->assertDatabaseHas('users', ['username' => 'new-parent', 'ui_locale' => null]);
    }

    public function test_a_registered_regional_locale_is_accepted_without_changing_the_resolver_or_validation(): void
    {
        config(['i18n.locales.pt-BR' => ['name' => 'Português', 'intl' => 'pt-BR', 'translation' => 'en']]);
        $user = $this->activeUser();
        Sanctum::actingAs($user);
        $this->withHeader('Accept-Language', 'pt-br')->patchJson('/v1/auth/me', ['ui_locale' => 'pt-BR'])
            ->assertOk()->assertJsonPath('user.ui_locale', 'pt-BR')->assertHeader('Content-Language', 'pt-BR');
        $this->assertSame('pt-BR', $user->fresh()->ui_locale);
        $this->withHeader('Accept-Language', 'ja')->patchJson('/v1/auth/me', ['display_name' => ''])
            ->assertUnprocessable()->assertHeader('Content-Language', 'pt-BR')
            ->assertJsonPath('errors.display_name.0', 'Enter a display name.');
    }

    #[TestWith(['ja', 'アクティビティを記録しました。', '自分の記録にはいいねできません。'])]
    #[TestWith(['en', 'Activity recorded.', 'You cannot like your own activity.'])]
    public function test_activity_lifecycle_uses_the_account_language_without_translating_user_data(string $locale, string $created, string $selfLike): void
    {
        $user = $this->activeUser();
        $user->update(['ui_locale' => $locale]);
        $group = Group::query()->sole();
        $category = Category::query()->create(['group_id' => $group->id, 'name' => '家事', 'color_code' => '#10B981', 'sort_order' => 1]);
        Sanctum::actingAs($user);
        $this->withHeader('Accept-Language', $locale === 'en' ? 'ja' : 'en');
        $path = "/v1/groups/{$group->id}/logs";
        $response = $this->postJson($path, [
            'category_id' => $category->id, 'activity_date' => '2026-09-25',
            'activity_time' => '23:59', 'content' => '原文の活動', 'note' => 'Original note',
        ])->assertCreated()->assertHeader('Content-Language', $locale)
            ->assertJsonPath('message', $created)->assertJsonPath('data.content', '原文の活動')
            ->assertJsonPath('data.category.name', '家事')->assertJsonPath('data.note', 'Original note');
        $id = $response->json('data.id');
        $list = $this->getJson($path)->assertOk()->assertHeader('Content-Language', $locale);
        $this->assertStringContainsString('Accept-Language', $list->headers->get('Vary'));
        $list->assertJsonPath('links.0.label', $locale === 'en' ? '&laquo; Previous' : '&laquo; 前へ')
            ->assertJsonPath('links.2.label', $locale === 'en' ? 'Next &raquo;' : '次へ &raquo;');
        $this->getJson("$path/counts?from=2026-09-25&to=2026-09-25")
            ->assertOk()->assertHeader('Content-Language', $locale)->assertJsonPath('data.2026-09-25', 1);
        $this->getJson("$path/counts?from=2026-09-25&to=2026-09-24")
            ->assertUnprocessable()->assertHeader('Content-Language', $locale)
            ->assertJsonPath('errors.to.0', $locale === 'en'
                ? 'The end date field must be a date after or equal to start date.'
                : '終了日には開始日以降の日付を指定してください。');
        $this->getJson("$path/$id")->assertOk()->assertHeader('Content-Language', $locale);
        $this->putJson("$path/$id/like")->assertForbidden()->assertHeader('Content-Language', $locale)->assertJsonPath('message', $selfLike);
        $this->putJson("$path/$id", ['revision' => 1, 'content' => 'Updated original'])
            ->assertOk()->assertHeader('Content-Language', $locale)->assertJsonPath('data.content', 'Updated original');
        $this->putJson("$path/$id", ['revision' => 1, 'content' => 'Stale'])
            ->assertConflict()->assertHeader('Content-Language', $locale);
        $group->forceFill(['images_enabled' => false])->save();
        $this->postJson($path, [
            'category_id' => $category->id, 'activity_date' => '2026-09-25', 'content' => 'Image attempt',
            'image' => UploadedFile::fake()->create('photo.jpg', 1, 'image/jpeg'),
        ])->assertUnprocessable()->assertHeader('Content-Language', $locale)
            ->assertJsonPath('message', $locale === 'en'
                ? 'Adding images is disabled for this family.'
                : 'この家族では画像の追加が無効です。');
        $this->post("$path/$id", [
            '_method' => 'PUT', 'remove_image' => true,
            'image' => UploadedFile::fake()->create('replacement.jpg', 1, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertHeader('Content-Language', $locale)
            ->assertJsonPath('errors.image.0', $locale === 'en'
                ? 'An image cannot be replaced and removed at the same time.'
                : '差し替えと削除は同時に指定できません。');
        $this->deleteJson("$path/$id")->assertOk()->assertHeader('Content-Language', $locale);
    }

    #[TestWith(['ja'])]
    #[TestWith(['en'])]
    public function test_gallery_language_headers_validation_and_image_binary(string $locale): void
    {
        Storage::fake('local');
        $user = $this->activeUser();
        $user->update(['ui_locale' => $locale]);
        $group = Group::query()->sole();
        $category = Category::query()->create(['group_id' => $group->id, 'name' => '家事', 'color_code' => '#10B981', 'sort_order' => 1]);
        $log = ActivityLog::query()->create(['group_id' => $group->id, 'user_id' => $user->id, 'category_id' => $category->id, 'activity_date' => '2026-10-18', 'content' => '原文の写真']);
        $image = ActivityImage::query()->create(['group_id' => $group->id, 'activity_log_id' => $log->id, 'disk' => 'local', 'path' => 'photo.webp', 'bytes' => 10, 'width' => 10, 'height' => 10, 'mime' => 'image/webp']);
        Storage::disk('local')->put('photo.webp', 'webp-bytes');
        Sanctum::actingAs($user);
        $this->withHeader('Accept-Language', $locale === 'en' ? 'ja' : 'en');
        $path = "/v1/groups/{$group->id}/gallery";
        $response = $this->getJson($path)->assertOk()->assertHeader('Content-Language', $locale)
            ->assertJsonPath('data.0.content', '原文の写真')->assertJsonPath('data.0.category.name', '家事')
            ->assertJsonPath('data.0.activity_date', '2026-10-18')
            ->assertJsonPath('links.0.label', $locale === 'en' ? '&laquo; Previous' : '&laquo; 前へ');
        $this->assertStringContainsString('Accept-Language', $response->headers->get('Vary'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->getJson($path.'?from=2026-11-01')->assertOk()->assertHeader('Content-Language', $locale)->assertJsonCount(0, 'data');
        $this->getJson($path.'?from=2026-10-18&to=2026-10-17')->assertUnprocessable()->assertHeader('Content-Language', $locale)
            ->assertJsonPath('errors.to.0', $locale === 'en' ? 'The end date field must be a date after or equal to start date.' : '終了日には開始日以降の日付を指定してください。');
        $this->getJson($path.'?user_id=999999&category_id=999999')->assertUnprocessable()->assertHeader('Content-Language', $locale)->assertJsonValidationErrors(['user_id', 'category_id']);
        $this->get("/v1/groups/{$group->id}/images/{$image->id}")->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeaderMissing('Content-Language')->assertStreamedContent('webp-bytes');
        $this->getJson("/v1/groups/{$group->id}/images/999999")->assertNotFound()->assertHeader('Content-Language', $locale);
    }

    private function activeUser(string $role = 'member'): User
    {
        $user = User::factory()->create();
        $group = Group::factory()->create();
        GroupMember::query()->create(['group_id' => $group->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active']);

        return $user;
    }
}
