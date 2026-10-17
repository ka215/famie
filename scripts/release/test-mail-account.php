<?php

// Isolated fixture credentials only. Never access the application's real .env or SMTP.
umask(0077);
$autoload = realpath(__DIR__.'/../../backend/vendor/autoload.php');
if (! $autoload) {
    throw new RuntimeException('Install existing backend dependencies first.');
}
require $autoload;
$fixture = sys_get_temp_dir().'/famie-mail-account-'.bin2hex(random_bytes(6));
mkdir($fixture, 0700);
mkdir($fixture.'/vendor', 0700);
file_put_contents($fixture.'/vendor/autoload.php', '<?php require '.var_export($autoload, true).';');
$old = 'noreply@famie.ka2.org';
$new = 'noreply@famie.co';
$original = "# preserved\r\nMAIL_MAILER=smtp\r\nMAIL_HOST=s140.coreserver.jp\r\nMAIL_PORT=587\r\nMAIL_REQUIRE_TLS=true\r\nMAIL_FROM_ADDRESS=$old\r\nMAIL_USERNAME=$old\r\nMAIL_PASSWORD=fixture-old\r\nAPP_KEY=fixture-unchanged\r\nFAMIE_MAIL_RESTRICT_RECIPIENTS=true\r\n";
$credentials = <<<'ENV'
MAIL_FROM_ADDRESS=noreply@famie.co
MAIL_USERNAME=noreply@famie.co
MAIL_PASSWORD="test'\"dollar\$back\\slash#é"
ENV;
$credentialPath = $fixture.'/account.env';
file_put_contents($credentialPath, $credentials);
$run = function (string $expected, string $backup, bool $apply, bool $pass) use ($fixture, $credentialPath): void {
    $command = [PHP_BINARY, __DIR__.'/server/set-mail-account.php', $fixture, $expected, $credentialPath, $fixture.'/'.$backup];
    if ($apply) {
        $command[] = '--apply';
    }
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if ((proc_close($process) === 0) !== $pass || str_contains($output, 'dollar') || str_contains($output, 'fixture-old')) {
        throw new RuntimeException('Unexpected result or credential disclosure.');
    }
};
file_put_contents($fixture.'/.env', $original);
$run($old, 'first-backup', false, true);
if (file_get_contents($fixture.'/.env') !== $original || file_exists($fixture.'/first-backup')) {
    throw new RuntimeException('Preflight mutated files.');
}
$run($old, 'first-backup', true, true);
$updated = file_get_contents($fixture.'/.env');
$parsed = Dotenv\Dotenv::parse($updated);
if ($parsed['MAIL_FROM_ADDRESS'] !== $new || $parsed['MAIL_USERNAME'] !== $new
    || $parsed['MAIL_PASSWORD'] !== 'test\'"dollar$back\\slash#é'
    || $parsed['APP_KEY'] !== 'fixture-unchanged' || $parsed['FAMIE_MAIL_RESTRICT_RECIPIENTS'] !== 'true'
    || file_get_contents($fixture.'/first-backup/backend.env') !== $original
    || str_replace("\r\n", '', $updated) !== str_replace(["\r", "\n"], '', $updated)) {
    throw new RuntimeException('Update, escaping, CRLF or backup failed.');
}
$run($old, 'wrong-source', true, false);
if (file_get_contents($fixture.'/.env') !== $updated) {
    throw new RuntimeException('Source validation changed configuration.');
}
file_put_contents($fixture.'/.env', $original);
$invalidCredentials = [
    str_replace('noreply@famie.co', 'unexpected@example.test', $credentials),
    "MAIL_FROM_ADDRESS=$new\nMAIL_USERNAME=$new\nMAIL_PASSWORD=\n",
    $credentials."\nAPP_DEBUG=true\n",
];
foreach ($invalidCredentials as $invalid) {
    file_put_contents($credentialPath, $invalid);
    $run($old, 'invalid-input', true, false);
    if (file_get_contents($fixture.'/.env') !== $original) {
        throw new RuntimeException('Invalid credentials changed configuration.');
    }
}
file_put_contents($credentialPath, $credentials);
$run($old, 'first-backup', true, false);
file_put_contents($fixture.'/.env', $original."MAIL_USERNAME=$old\r\n");
$run($old, 'duplicate', true, false);
file_put_contents($fixture.'/.env', $original);
mkdir($fixture.'/public_html', 0700);
$run($old, 'public_html/backup', true, false);
if (file_get_contents($fixture.'/.env') !== $original) {
    throw new RuntimeException('Rejected operation changed configuration.');
}
echo "PASS: preflight, special-character credentials, backup, recipient restriction preservation and 7 rejections. Fixture: $fixture\n";
