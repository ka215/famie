<?php

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

// Credentials stay in private files. Never print transport exceptions or .env contents.
umask(0077);
if ($argc < 5 || $argc > 7) {
    fwrite(STDERR, "Usage: set-mail-account.php BACKEND EXPECTED_FROM CREDENTIAL_FILE NEW_BACKUP_DIRECTORY [--check-smtp] [--apply]\n");
    exit(1);
}
[$script, $backend, $expectedFrom, $credentialFile, $backup] = $argv;
$options = array_slice($argv, 5);
if (array_diff($options, ['--check-smtp', '--apply']) || count($options) !== count(array_unique($options))) {
    fwrite(STDERR, "Invalid mail account options.\n");
    exit(1);
}
require $backend.'/vendor/autoload.php';
$path = $backend.'/.env';
$temporary = $path.'.mail-update-'.bin2hex(random_bytes(6));
try {
    $credentialPath = realpath($credentialFile);
    if (! $credentialPath || str_contains(str_replace('\\', '/', $credentialPath), '/public_html/')
        || (PHP_OS_FAMILY !== 'Windows' && (fileperms($credentialPath) & 0077) !== 0)) {
        throw new RuntimeException('Credentials must be private');
    }
    $credentials = Dotenv\Dotenv::parse(file_get_contents($credentialPath));
    $keys = ['MAIL_FROM_ADDRESS', 'MAIL_USERNAME', 'MAIL_PASSWORD'];
    if (array_diff(array_keys($credentials), $keys) || array_diff($keys, array_keys($credentials))
        || ! in_array($credentials['MAIL_FROM_ADDRESS'], ['noreply@famie.co', 'noreply@famie.ka2.org'], true)
        || $credentials['MAIL_FROM_ADDRESS'] !== $credentials['MAIL_USERNAME']) {
        throw new RuntimeException('Invalid account settings');
    }
    foreach ($credentials as $value) {
        if (! is_string($value) || $value === '' || strpbrk($value, "\r\n\0") !== false) {
            throw new RuntimeException('Missing or multiline credential');
        }
    }
    $original = file_get_contents($path);
    $current = Dotenv\Dotenv::parse($original);
    if (($current['MAIL_FROM_ADDRESS'] ?? null) !== $expectedFrom
        || ($current['MAIL_USERNAME'] ?? null) !== $expectedFrom
        || ($current['MAIL_MAILER'] ?? null) !== 'smtp'
        || ($current['MAIL_HOST'] ?? null) !== 's140.coreserver.jp'
        || ($current['MAIL_PORT'] ?? null) !== '587'
        || ! in_array(strtolower($current['MAIL_REQUIRE_TLS'] ?? ''), ['true', '(true)', '1'], true)) {
        throw new RuntimeException('Unexpected source mail configuration');
    }
    $updated = $original;
    foreach ($keys as $key) {
        $encoded = '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $credentials[$key]).'"';
        $updated = preg_replace_callback('/^'.preg_quote($key, '/').'=[^\r\n]*/m', static fn (): string => $key.'='.$encoded, $updated, -1, $count);
        if ($count !== 1) {
            throw new RuntimeException('Missing or duplicated mail setting');
        }
    }
    $roundTrip = Dotenv\Dotenv::parse($updated);
    foreach ($keys as $key) {
        if ($roundTrip[$key] !== $credentials[$key]) {
            throw new RuntimeException('Credential encoding failed');
        }
    }
    $backupParent = realpath(dirname($backup));
    if (! $backupParent || str_contains(str_replace('\\', '/', $backupParent), '/public_html/')
        || basename($backupParent) === 'public_html' || in_array(basename($backup), ['.', '..'], true)
        || file_exists($backup) || is_link($backup)) {
        throw new RuntimeException('Backup must be new and outside the public tree');
    }
    if (in_array('--check-smtp', $options, true)) {
        $transport = new EsmtpTransport('s140.coreserver.jp', 587, false);
        $transport->setAutoTls(true)->setRequireTls(true)
            ->setUsername($credentials['MAIL_USERNAME'])->setPassword($credentials['MAIL_PASSWORD']);
        $transport->getStream()->setTimeout(15);
        $transport->start();
        $transport->stop();
        echo "SMTP TLS/authentication passed; no email sent.\n";
    }
    if (! in_array('--apply', $options, true)) {
        echo "Mail account preflight passed; no configuration changed.\n";
        exit(0);
    }
    if (! mkdir($backup, 0700)) {
        throw new RuntimeException('Backup directory must be new');
    }
    if (! copy($path, $backup.'/backend.env') || ! chmod($backup.'/backend.env', 0600)) {
        throw new RuntimeException('Private backup failed');
    }
    if (file_put_contents($temporary, $updated, LOCK_EX) !== strlen($updated)
        || ! chmod($temporary, fileperms($path) & 0777) || ! rename($temporary, $path)) {
        throw new RuntimeException('Atomic configuration update failed');
    }
    echo "Mail account updated; regenerate config cache and verify effective settings.\n";
} catch (Throwable $error) {
    if (is_file($temporary)) {
        unlink($temporary);
    }
    fwrite(STDERR, "Mail account operation failed. Inspect source settings, private credentials, TLS/authentication and backup location privately.\n");
    exit(1);
}
