<?php

namespace Tests\Feature;

use App\Mail\AccountMail;
use App\Models\GroupMember;
use App\Support\ReservedUsernames;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\TestCase;

class ReservedUsernameTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ?string $directory = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['famie.allowed_ips' => ['127.0.0.1']]);
        RateLimiter::clear('register:attempts:127.0.0.1');
        RateLimiter::clear('register:blocked:127.0.0.1');
    }

    protected function tearDown(): void
    {
        if ($this->directory !== null) {
            File::deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    private function payload(string $username): array
    {
        return ['group_name' => 'Test family', 'username' => $username, 'display_name' => 'Parent',
            'password' => 'river otter orchard sunset', 'password_confirmation' => 'river otter orchard sunset'];
    }

    public function test_pinned_dictionary_selects_only_approved_names_and_matches_case_insensitively(): void
    {
        $definition = json_decode(file_get_contents(resource_path('data/reserved-usernames.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(18, $definition['base_exact']);
        $this->assertCount(12, $definition['exact']);
        $reserved = new ReservedUsernames;
        foreach ([...$definition['base_exact'], ...$definition['exact']] as $name) {
            $this->assertTrue($reserved->contains($name), $name);
            $this->assertTrue($reserved->contains(strtoupper($name)), $name);
        }
        foreach (['about', 'blog', 'home', 'test', 'admin123', 'my-admin'] as $name) {
            $this->assertFalse($reserved->contains($name), $name);
        }
    }

    #[TestWith(['ja', 'admin', 'このアカウント名は使用できません。別の名前を入力してください。'])]
    #[TestWith(['en', 'famie_support', 'This username is unavailable. Choose another name.'])]
    public function test_registration_rejects_reserved_names_without_writing_records(string $locale, string $name, string $message): void
    {
        $this->postJson('/v1/auth/register/username-availability', ['username' => $name])->assertOk()->assertExactJson(['available' => false]);
        $this->withHeader('Accept-Language', $locale)->postJson('/v1/auth/register', $this->payload($name))
            ->assertUnprocessable()->assertJsonPath('errors.username.0', $message);
        foreach (['users', 'groups', 'group_members', 'personal_access_tokens'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    #[TestWith(['admin123'])]
    #[TestWith(['about'])]
    public function test_unselected_words_and_partial_matches_can_register(string $name): void
    {
        $this->postJson('/v1/auth/register/username-availability', ['username' => $name])->assertOk()->assertExactJson(['available' => true]);
        $this->postJson('/v1/auth/register', $this->payload($name))->assertCreated()->assertJsonPath('user.username', $name);
        $this->postJson('/v1/auth/register/username-availability', ['username' => $name])->assertOk()->assertExactJson(['available' => false]);
    }

    #[TestWith(['Admin'])]
    #[TestWith(['Family123'])]
    public function test_uppercase_input_remains_a_syntax_error(string $name): void
    {
        $this->postJson('/v1/auth/register/username-availability', ['username' => $name])->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->postJson('/v1/auth/register', $this->payload($name))->assertUnprocessable()
            ->assertJsonPath('errors.username.0', __('messages.username_format', [], 'ja'));
        $this->assertDatabaseCount('users', 0);
    }

    #[TestWith(['admin'])]
    #[TestWith(['member'])]
    public function test_parent_cannot_create_reserved_members_in_either_role(string $role): void
    {
        $parent = GroupMember::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($parent->user);
        $payload = $this->payload('support');
        unset($payload['group_name']);
        $this->postJson("/v1/groups/{$parent->group_id}/members", [...$payload, 'role' => $role])
            ->assertUnprocessable()->assertJsonPath('errors.username.0', 'このアカウント名は使用できません。別の名前を入力してください。');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('group_members', 1);
    }

    #[TestWith(['missing'])]
    #[TestWith(['broken-json'])]
    #[TestWith(['schema'])]
    #[TestWith(['unknown-key'])]
    #[TestWith(['source-key'])]
    #[TestWith(['source-type'])]
    #[TestWith(['hash'])]
    #[TestWith(['base-type'])]
    #[TestWith(['base-missing-selection'])]
    #[TestWith(['word-type'])]
    #[TestWith(['word-syntax'])]
    #[TestWith(['word-uppercase'])]
    #[TestWith(['duplicate'])]
    #[TestWith(['license'])]
    public function test_invalid_dictionary_fails_closed(string $case): void
    {
        $directory = $this->copyDictionary();
        $definition = json_decode(file_get_contents($directory.'/reserved-usernames.json'), true, 512, JSON_THROW_ON_ERROR);
        switch ($case) {
            case 'missing': unlink($directory.'/reserved-usernames-base.json');
                break;
            case 'broken-json': file_put_contents($directory.'/reserved-usernames.json', '{');
                break;
            case 'schema': $definition['schema_version'] = 2;
                break;
            case 'unknown-key': $definition['unexpected'] = [];
                break;
            case 'source-key': $definition['base_source']['unexpected'] = 'value';
                break;
            case 'source-type': $definition['base_source']['commit'] = [];
                break;
            case 'hash': $definition['base_source']['sha256'] = str_repeat('0', 64);
                break;
            case 'base-type':
                file_put_contents($directory.'/reserved-usernames-base.json', '{}');
                $definition['base_source']['sha256'] = hash('sha256', '{}');
                break;
            case 'base-missing-selection': $definition['base_exact'][] = 'unlisted-operator';
                break;
            case 'word-type': $definition['exact'][] = [];
                break;
            case 'word-syntax': $definition['exact'][] = "invalid\n";
                break;
            case 'word-uppercase': $definition['exact'][] = 'ADMIN';
                break;
            case 'duplicate': $definition['exact'][] = 'famie';
                break;
            case 'license': unlink($directory.'/reserved-usernames-base.LICENSE');
                break;
        }
        if ($case !== 'broken-json') {
            file_put_contents($directory.'/reserved-usernames.json', json_encode($definition, JSON_THROW_ON_ERROR));
        }
        $this->expectException(ServiceUnavailableHttpException::class);
        (new ReservedUsernames($directory))->contains('new-family');
    }

    public function test_cross_list_duplicates_are_combined_as_a_set(): void
    {
        $directory = $this->copyDictionary();
        $definition = json_decode(file_get_contents($directory.'/reserved-usernames.json'), true, 512, JSON_THROW_ON_ERROR);
        $definition['exact'][] = 'admin';
        file_put_contents($directory.'/reserved-usernames.json', json_encode($definition, JSON_THROW_ON_ERROR));
        $this->assertTrue((new ReservedUsernames($directory))->contains('AdMiN'));
    }

    public function test_missing_dictionary_blocks_new_accounts_but_preserves_existing_account_operations_and_authorization(): void
    {
        $parent = GroupMember::factory()->create(['role' => 'admin']);
        $member = GroupMember::factory()->create(['group_id' => $parent->group_id]);
        $member->user->update(['username' => 'admin']);
        $this->app->instance(ReservedUsernames::class, new ReservedUsernames(storage_path('missing-reserved-dictionary')));
        $this->postJson('/v1/auth/register/username-availability', ['username' => 'new-family'])->assertStatus(503)->assertJsonMissingPath('exception');
        $this->postJson('/v1/auth/register', $this->payload('new-family'))->assertStatus(503);
        $this->postJson('/v1/auth/login', ['login' => 'admin', 'password' => 'password'])->assertOk();
        Sanctum::actingAs($member->user);
        $this->getJson('/v1/auth/me')->assertOk()->assertJsonPath('user.username', 'admin');
        $this->patchJson('/v1/auth/me', ['display_name' => 'Updated'])->assertOk();
        $payload = $this->payload('another-family');
        unset($payload['group_name']);
        $this->postJson("/v1/groups/{$parent->group_id}/members", [...$payload, 'role' => 'member'])->assertForbidden();
        $this->putJson('/v1/auth/me/password', ['current_password' => 'password', 'new_password' => 'river otter orchard sunset', 'new_password_confirmation' => 'river otter orchard sunset'])->assertOk();
        Sanctum::actingAs($parent->user);
        $this->postJson("/v1/groups/{$parent->group_id}/members", [...$payload, 'role' => 'member'])->assertStatus(503);
        $path = "/v1/groups/{$parent->group_id}/members/{$member->id}";
        $this->patchJson($path, ['display_name' => 'Renamed', 'role' => 'member'])->assertOk();
        $this->putJson($path.'/status', ['status' => 'inactive'])->assertOk();
        $this->putJson($path.'/status', ['status' => 'active'])->assertOk();
        $this->assertDatabaseCount('users', 2);
        $this->assertSame('admin', $member->user->fresh()->username);
    }

    public function test_reserved_existing_account_can_verify_email_and_recover_password_when_dictionary_is_missing(): void
    {
        $member = GroupMember::factory()->create();
        $member->user->update(['username' => 'support']);
        Mail::fake();
        config(['famie.mail_restrict_recipients' => false, 'famie.frontend_url' => 'https://famie.example.test']);
        $this->app->instance(ReservedUsernames::class, new ReservedUsernames(storage_path('missing-reserved-dictionary')));
        Sanctum::actingAs($member->user);
        $this->postJson('/v1/auth/me/email', ['email' => 'support@example.test', 'current_password' => 'password'])->assertOk();
        $verification = Mail::sent(AccountMail::class, fn (AccountMail $mail) => $mail->kind === 'verify')->sole();
        parse_str(parse_url($verification->actionUrl, PHP_URL_FRAGMENT), $verificationData);
        $this->postJson('/v1/auth/email/verify', $verificationData)->assertOk();
        $this->assertNotNull($member->user->fresh()->email_verified_at);
        $this->travel(61)->seconds();
        $this->postJson('/v1/auth/password/forgot', ['email' => 'support@example.test'])->assertOk();
        $reset = Mail::sent(AccountMail::class, fn (AccountMail $mail) => $mail->kind === 'reset')->sole();
        parse_str(parse_url($reset->actionUrl, PHP_URL_FRAGMENT), $resetData);
        $this->postJson('/v1/auth/password/reset', [...$resetData,
            'password' => 'river otter orchard sunset', 'password_confirmation' => 'river otter orchard sunset'])->assertOk();
        $this->postJson('/v1/auth/login', ['login' => 'support', 'password' => 'river otter orchard sunset'])->assertOk();
        Mail::assertSent(AccountMail::class, fn (AccountMail $mail) => $mail->kind === 'reset_complete');
        $this->assertSame('support', $member->user->fresh()->username);
    }

    private function copyDictionary(): string
    {
        $this->directory = sys_get_temp_dir().'/famie-reserved-'.bin2hex(random_bytes(8));
        File::copyDirectory(resource_path('data'), $this->directory);

        return $this->directory;
    }
}
