<?php

// Change only the three domain settings. Never print .env contents or credentials.
if ($argc !== 4 || ! in_array($argv[2], ['https://famie.ka2.org', 'https://famie.co'], true)
    || ! in_array($argv[3], ['https://famie.ka2.org', 'https://famie.co'], true)) {
    fwrite(STDERR, "Usage: set-domain-url.php BACKEND EXPECTED_URL NEW_URL\n");
    exit(1);
}
[$script, $backend, $expected, $next] = $argv;
require $backend.'/vendor/autoload.php';
$path = $backend.'/.env';
$temporary = $path.'.domain-migration-'.bin2hex(random_bytes(6));
try {
    $original = file_get_contents($path);
    $values = Dotenv\Dotenv::parse($original);
    $updated = $original;
    foreach (['APP_URL', 'FAMIE_FRONTEND_URL', 'CORS_ALLOWED_ORIGINS'] as $key) {
        if (($values[$key] ?? null) !== $expected) {
            throw new RuntimeException('Unexpected domain configuration');
        }
        $updated = preg_replace('/^'.preg_quote($key, '/').'=[^\r\n]*/m', $key.'='.$next, $updated, -1, $count);
        if ($count !== 1) {
            throw new RuntimeException('Missing or duplicated setting');
        }
    }
    if (file_put_contents($temporary, $updated, LOCK_EX) !== strlen($updated)
        || ! chmod($temporary, fileperms($path) & 0777) || ! rename($temporary, $path)) {
        throw new RuntimeException('Atomic environment update failed');
    }
    echo "Domain settings updated.\n";
} catch (Throwable $error) {
    if (is_file($temporary)) {
        unlink($temporary);
    }
    fwrite(STDERR, "Domain settings update failed; inspect the three URL settings privately.\n");
    exit(1);
}
