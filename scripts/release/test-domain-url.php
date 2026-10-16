<?php

// Private disposable configuration only; no application boot or database access.
$autoload = realpath(__DIR__.'/../../backend/vendor/autoload.php');
if (! $autoload) {
    throw new RuntimeException('Install existing backend dependencies first.');
}
umask(0077);
$fixture = sys_get_temp_dir().'/famie-domain-url-'.bin2hex(random_bytes(6));
mkdir($fixture, 0700);
mkdir($fixture.'/vendor', 0700);
file_put_contents($fixture.'/vendor/autoload.php', '<?php require '.var_export($autoload, true).';');
$old = 'https://famie.ka2.org';
$new = 'https://famie.co';
$original = "# keep this comment\r\nAPP_URL=$old\r\nFAMIE_FRONTEND_URL=\"$old\"\r\nCORS_ALLOWED_ORIGINS=$old\r\nDB_DATABASE=unchanged\r\nAPP_KEY=fixture-only\r\n";
$run = function (string $expected, string $next, bool $pass) use ($fixture): void {
    $process = proc_open([PHP_BINARY, __DIR__.'/server/set-domain-url.php', $fixture, $expected, $next],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if ((proc_close($process) === 0) !== $pass) {
        throw new RuntimeException('Unexpected update result: '.$output);
    }
};
file_put_contents($fixture.'/.env', $original);
$run($old, $new, true);
$expected = str_replace([$old, 'FAMIE_FRONTEND_URL="'.$new.'"'], [$new, 'FAMIE_FRONTEND_URL='.$new], $original);
// str_replace applies both replacements in order.
if (file_get_contents($fixture.'/.env') !== $expected) {
    throw new RuntimeException('Non-domain content or CRLF changed.');
}
$run($new, $old, true);
foreach (['APP_URL', 'FAMIE_FRONTEND_URL', 'CORS_ALLOWED_ORIGINS'] as $key) {
    $invalid = preg_replace('/^'.preg_quote($key, '/').'=[^\r\n]*/m', $key.'=https://unexpected.example', $original);
    file_put_contents($fixture.'/.env', $invalid);
    $run($old, $new, false);
    if (file_get_contents($fixture.'/.env') !== $invalid) {
        throw new RuntimeException('Failed validation changed the file.');
    }
}
$invalid = $original."APP_URL=$old\r\n";
file_put_contents($fixture.'/.env', $invalid);
$run($old, $new, false);
if (file_get_contents($fixture.'/.env') !== $invalid) {
    throw new RuntimeException('Duplicate setting was modified.');
}
echo "PASS: forward/reverse URL changes, CRLF and unrelated settings, 4 rejected configurations. Fixture: $fixture\n";
