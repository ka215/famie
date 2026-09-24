<?php

// Run against an existing Laravel checkout, but only modify a private temporary fixture.
// The real database is accessed read-only by the valid baseline guard.
if ($argc !== 4) {
    fwrite(STDERR, "Usage: php test-environment.php BACKEND EXPECTED_URL EXPECTED_DATABASE\n");
    exit(1);
}
[$script, $backend, $url, $database] = $argv;
umask(0077);
$fixture = sys_get_temp_dir().'/famie-environment-'.bin2hex(random_bytes(6));
mkdir($fixture, 0700);
require $backend.'/vendor/autoload.php';
$original = file_get_contents($backend.'/.env');
mkdir($fixture.'/vendor');
file_put_contents($fixture.'/vendor/autoload.php', '<?php require '.var_export($backend.'/vendor/autoload.php', true).';');
$run = function (string $path, bool $pass, array $environment = []) use ($url, $database, $fixture): void {
    $process = proc_open([PHP_BINARY, __DIR__.'/check-deploy-environment.php', $path, $url, $database],
        [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['file', $fixture.'/result.log', 'a'], 2 => ['file', $fixture.'/result.log', 'a']],
        $pipes, null, $environment ? array_merge(getenv(), $environment) : null);
    if (! is_resource($process) || (proc_close($process) === 0) !== $pass) {
        throw new RuntimeException('Unexpected guard result. Inspect private fixture: '.$fixture);
    }
};
$run($backend, true);
foreach (['APP_URL' => 'https://wrong.example', 'APP_ENV' => 'local', 'APP_DEBUG' => 'true',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => 'wrong_database', 'DB_SCHEMA' => 'public',
    'DB_USERNAME' => 'wrong_user', 'DB_HOST' => 'remote.example', 'DB_PORT' => '9999',
    'DB_URL' => 'pgsql://wrong.example/wrong'] as $key => $value) {
    $modified = preg_replace('/^'.preg_quote($key, '/').'=.*\R?/m', '', $original);
    file_put_contents($fixture.'/.env', rtrim($modified)."\n".$key.'='.$value."\n");
    $run($fixture, false);
}
// Simulate stale config that disagrees with .env; no live config file is changed.
file_put_contents($fixture.'/stale-config.php', '<?php return '.var_export([
    'app' => ['env' => 'production', 'debug' => true, 'url' => $url],
    'database' => ['default' => 'pgsql'],
], true).';');
$run($backend, false, ['APP_CONFIG_CACHE' => $fixture.'/stale-config.php']);
echo "PASS: real read-only identity, 10 invalid .env settings, stale cached configuration.\n";
echo "Private fixture retained: $fixture\n";
