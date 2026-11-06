<?php

namespace Tests\Feature;

use App\Services\LifecycleAudit;
use App\Services\LifecycleOperatorKey;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class LifecycleKeyRecoveryTest extends TestCase
{
    private string $root;

    private string $oldPassphrase = 'synthetic old passphrase only';

    private string $newPassphrase = 'synthetic portable passphrase only';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/famie-key-recovery-'.bin2hex(random_bytes(8));
        foreach (['source', 'destination', 'audit'] as $directory) {
            File::makeDirectory($this->root.'/'.$directory, 0700, true);
        }
        config(['famie.lifecycle_audit_root' => $this->root.'/audit']);
        $this->beforeApplicationDestroyed(function (): void {
            if (! is_link($this->root) && dirname(realpath($this->root)) === realpath(sys_get_temp_dir())
                && preg_match('/\Afamie-key-recovery-[a-f0-9]{16}\z/', basename($this->root))) {
                File::deleteDirectory($this->root);
            }
        });
    }

    public function test_reprotection_preserves_the_key_and_decrypts_an_older_sealed_message(): void
    {
        $keys = new LifecycleOperatorKey;
        $original = $keys->create('portable-test', $this->oldPassphrase);
        $sealed = sodium_crypto_box_seal('synthetic earlier vault key', base64_decode($original['public_key'], true));
        $backup = $keys->reprotect($original, 'portable-test', $this->oldPassphrase, $this->newPassphrase);
        $this->assertSame($original['public_key'], $backup['public_key']);
        $this->assertSame($original['key_id'], $backup['key_id']);
        $this->assertNotSame($original['cipher']['ciphertext'], $backup['cipher']['ciphertext']);
        $keys->verify($original, 'portable-test', $this->oldPassphrase);
        $keys->withKey($backup, 'portable-test', $this->newPassphrase, function ($pair) use ($sealed): void {
            $this->assertSame('synthetic earlier vault key', sodium_crypto_box_seal_open($sealed, $pair));
        });
        $this->expectException(RuntimeException::class);
        $keys->verify($backup, 'portable-test', $this->oldPassphrase);
    }

    public function test_native_plan_needs_no_database_or_secret_and_changes_no_files(): void
    {
        $arguments = $this->fixture();
        $result = $this->native('plan', $arguments);
        $this->assertSame(0, $result->exitCode());
        $data = json_decode($result->output(), true, 16, JSON_THROW_ON_ERROR);
        $this->assertFalse($data['database_bootstrapped']);
        $this->assertFalse($data['server_public_key_verified']);
        $this->assertFalse($data['key_verified']);
        $this->assertSame(0, $data['files_created']);
        $this->assertSame([], File::files($this->root.'/audit'));
        $this->assertSame([], File::files($this->root.'/destination'));
    }

    public function test_native_creation_and_separate_process_verification_preserve_originals(): void
    {
        $arguments = $this->fixture();
        $before = File::get($this->root.'/source/key.json');
        $create = $this->native('create', $arguments, [$this->oldPassphrase, $this->newPassphrase]);
        $this->assertSame(0, $create->exitCode(), $create->errorOutput());
        $result = json_decode($create->output(), true, 16, JSON_THROW_ON_ERROR);
        $this->assertTrue($result['key_verified']);
        $this->assertSame(2, $result['files_created']);
        $this->assertSame($before, File::get($this->root.'/source/key.json'));
        $arguments['key-file'] = $this->root.'/destination/portable-test.key.json';
        $arguments['public-file'] = $this->root.'/destination/portable-test.public.json';
        $arguments['expected-source-sha256'] = $result['backup_sha256'];
        unset($arguments['destination']);
        $verify = $this->native('verify', $arguments, [$this->newPassphrase]);
        $this->assertSame(0, $verify->exitCode(), $verify->errorOutput());
        $this->assertTrue(json_decode($verify->output(), true, 16, JSON_THROW_ON_ERROR)['key_verified']);
        $combined = $create->output().$create->errorOutput().$verify->output().$verify->errorOutput().File::get($this->auditPath());
        $this->assertStringNotContainsString($this->oldPassphrase, $combined);
        $this->assertStringNotContainsString($this->newPassphrase, $combined);
        $this->assertSame(0, app(LifecycleAudit::class)->prune()['expired']);
    }

    #[TestWith(['wrong-passphrase'])]
    #[TestWith(['checksum'])]
    #[TestWith(['public-key'])]
    #[TestWith(['existing-file'])]
    #[TestWith(['hardlink'])]
    #[TestWith(['audit'])]
    #[TestWith(['short-new-passphrase'])]
    public function test_native_recovery_refuses_untrusted_inputs_without_replacing_originals(string $case): void
    {
        $arguments = $this->fixture();
        $original = File::get($this->root.'/source/key.json');
        $passphrase = $this->oldPassphrase;
        $newPassphrase = $this->newPassphrase;
        if ($case === 'wrong-passphrase') {
            $passphrase = 'synthetic wrong passphrase only';
        } elseif ($case === 'checksum') {
            $arguments['expected-source-sha256'] = str_repeat('a', 64);
        } elseif ($case === 'public-key') {
            $public = (new LifecycleOperatorKey)->create('portable-test', $this->oldPassphrase);
            File::put($arguments['public-file'], json_encode(['key_id' => 'portable-test', 'public_key' => $public['public_key']], JSON_THROW_ON_ERROR));
        } elseif ($case === 'existing-file') {
            File::put($this->root.'/destination/portable-test.key.json', 'existing protected copy');
        } elseif ($case === 'hardlink') {
            $this->assertTrue(link($arguments['key-file'], $this->root.'/linked-key'));
        } elseif ($case === 'short-new-passphrase') {
            $newPassphrase = 'short';
        } else {
            File::put($this->auditPath(), 'incomplete synthetic audit');
        }
        $result = $this->native('create', $arguments, [$passphrase, $newPassphrase]);
        $this->assertNotSame(0, $result->exitCode());
        $this->assertSame('', $result->output());
        $this->assertSame($original, File::get($this->root.'/source/key.json'));
        $this->assertStringNotContainsString($passphrase, $result->errorOutput());
        if ($case === 'existing-file') {
            $this->assertSame('existing protected copy', File::get($this->root.'/destination/portable-test.key.json'));
        } else {
            $this->assertSame([], File::files($this->root.'/destination'));
        }
    }

    public function test_native_and_laravel_key_attempts_share_the_same_audit_window(): void
    {
        $arguments = $this->fixture();
        unset($arguments['destination']);
        $audit = app(LifecycleAudit::class);
        for ($index = 0; $index < 4; $index++) {
            $audit->record('decrypt', 'started', 'ops-main', keyId: 'portable-test');
        }
        $fifth = $this->native('verify', $arguments, [$this->oldPassphrase]);
        $this->assertSame(0, $fifth->exitCode(), $fifth->errorOutput());
        $sixth = $this->native('verify', $arguments, [$this->oldPassphrase]);
        $this->assertNotSame(0, $sixth->exitCode());
        $this->expectException(RuntimeException::class);
        $audit->record('key.reprotect', 'started', 'another-operator', keyId: 'portable-test');
    }

    public function test_powershell_plan_is_read_only_and_does_not_import_a_dpapi_file(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows operator wrapper.');
        }
        $arguments = $this->fixture();
        $result = Process::timeout(15)->run(['pwsh', '-NoProfile', '-NonInteractive', '-File', base_path('../scripts/lifecycle-key-recovery.ps1'),
            '-Mode', 'Create', '-KeyFile', $arguments['key-file'], '-PublicFile', $arguments['public-file'], '-KeyId', 'portable-test',
            '-DestinationDirectory', $arguments['destination'], '-DpapiPassphraseFile', $this->root.'/missing-protected-passphrase.clixml']);
        $this->assertSame(0, $result->exitCode(), $result->errorOutput());
        $this->assertSame('plan', json_decode($result->output(), true, 16, JSON_THROW_ON_ERROR)['mode']);
        $this->assertSame([], File::files($this->root.'/destination'));
        $this->assertSame([], File::files($this->root.'/audit'));
    }

    public function test_powershell_dpapi_bridge_uses_hidden_inputs_and_private_stdin_for_a_synthetic_key(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows DPAPI bridge.');
        }
        $arguments = $this->fixture();
        $quote = fn ($value) => "'".str_replace("'", "''", $value)."'";
        $dpapi = $this->root.'/synthetic-passphrase.clixml';
        $script = '$ErrorActionPreference = "Stop"; '
            .'$oldText = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String([Console]::In.ReadLine())); '
            .'$global:syntheticRecoveryPassphrase = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String([Console]::In.ReadLine())); '
            .'ConvertTo-SecureString $oldText -AsPlainText -Force | Export-Clixml -LiteralPath '.$quote($dpapi).'; '
            .'function Read-Host { param($Prompt, [switch]$AsSecureString) ConvertTo-SecureString $global:syntheticRecoveryPassphrase -AsPlainText -Force }; '
            .'& '.$quote(base_path('../scripts/lifecycle-key-recovery.ps1')).' -Mode Create -Apply -KeyFile '.$quote($arguments['key-file'])
            .' -PublicFile '.$quote($arguments['public-file']).' -KeyId portable-test -ExpectedSourceSha256 '.$quote($arguments['expected-source-sha256'])
            .' -DestinationDirectory '.$quote($arguments['destination']).' -AuditDirectory '.$quote($arguments['audit-directory'])
            .' -Operator ops-test -DpapiPassphraseFile '.$quote($dpapi);
        $input = base64_encode($this->oldPassphrase).PHP_EOL.base64_encode($this->newPassphrase).PHP_EOL;
        $result = Process::timeout(20)->input($input)->run(['pwsh', '-NoProfile', '-NonInteractive', '-Command', $script]);
        $this->assertSame(0, $result->exitCode(), $result->errorOutput());
        $data = json_decode($result->output(), true, 16, JSON_THROW_ON_ERROR);
        $this->assertTrue($data['key_verified']);
        $this->assertStringNotContainsString($this->newPassphrase, $result->output().$result->errorOutput());
        $this->assertSame($arguments['expected-source-sha256'], hash_file('sha256', $arguments['key-file']));
    }

    /** @return array<string,string> */
    private function fixture(): array
    {
        $key = (new LifecycleOperatorKey)->create('portable-test', $this->oldPassphrase);
        $path = $this->root.'/source/key.json';
        $public = $this->root.'/source/public.json';
        File::put($path, json_encode($key, JSON_THROW_ON_ERROR));
        File::put($public, json_encode(['key_id' => 'portable-test', 'public_key' => $key['public_key']], JSON_THROW_ON_ERROR));

        return ['key-file' => $path, 'public-file' => $public, 'key-id' => 'portable-test', 'expected-source-sha256' => hash_file('sha256', $path),
            'destination' => $this->root.'/destination', 'audit-directory' => $this->root.'/audit', 'operator' => 'ops-test'];
    }

    /** @param array<string,string> $arguments @param list<string> $secrets */
    private function native(string $mode, array $arguments, array $secrets = []): ProcessResult
    {
        $command = [PHP_BINARY, base_path('../scripts/lifecycle-key-recovery.php'), '--mode='.$mode];
        foreach ($arguments as $key => $value) {
            $command[] = '--'.$key.'='.$value;
        }
        $input = implode(PHP_EOL, array_map('base64_encode', $secrets)).PHP_EOL;

        return Process::timeout(10)->env(['DB_CONNECTION' => 'disabled-key-recovery', 'DB_URL' => '', 'APP_ENV' => 'key-recovery'])->input($input)->run($command);
    }

    private function auditPath(): string
    {
        return $this->root.'/audit/audit-'.now()->utc()->format('Y-m-d').'.jsonl';
    }
}
