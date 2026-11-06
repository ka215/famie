<?php

namespace App\Services;

use App\Support\DestructiveDatabaseCommandGuard;
use App\Support\LifecycleStoragePath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;

class LifecycleProcessContext
{
    /** @return array<string,string|false> */
    public function environment(bool $required = false): array
    {
        if (! config('famie.lifecycle_process_test_enabled')) {
            if ($required) {
                throw new RuntimeException('Lifecycle child context must be explicitly enabled.');
            }

            return [];
        }
        if (! app()->environment('testing') || ! config('database-safety.test_run')
            || config('database-safety.profile') !== 'postgres-local' || DB::transactionLevel() !== 0
            || app()->configurationIsCached() || config('mail.default') !== 'array'
            || config('operations.mailer') !== 'array' || config('mail.mailers.array.transport') !== 'array') {
            throw new RuntimeException('Lifecycle child tests require an isolated, committed PostgreSQL fixture without SMTP.');
        }
        $connection = DB::connection()->getConfig();
        DestructiveDatabaseCommandGuard::assertTestConnection('testing', $connection, config('database-safety.profiles.postgres-local'));
        $identity = DB::selectOne("select current_database() as db, current_user as role, inet_server_port() as port,
            current_schema() as schema, current_setting('search_path') as search_path,
            exists(select 1 from pg_roles where rolname = current_user and (rolsuper or rolcreatedb or rolcreaterole or rolreplication or rolbypassrls)) as privileged");
        if ($identity->db !== 'famie_test' || $identity->role !== 'famie_test_runner' || $identity->port !== 55432 || $identity->privileged
            || ! preg_match('/\Afamie_lifecycle_regression_\d+_[a-f0-9]{8}_test\z/', (string) $identity->schema)
            || $identity->search_path !== $identity->schema || ($connection['search_path'] ?? null) !== $identity->schema) {
            throw new RuntimeException('Lifecycle child target is not a dedicated disposable schema.');
        }
        $owner = DB::selectOne('select pg_get_userbyid(nspowner) as owner from pg_namespace where nspname = ?', [$identity->schema]);
        if (($owner->owner ?? null) !== 'famie_test_runner') {
            throw new RuntimeException('Lifecycle child schema owner differs.');
        }
        $workspace = realpath(dirname(storage_path()));
        if ($workspace === false || dirname($workspace) !== realpath(sys_get_temp_dir())
            || ! preg_match('/\Afamie-(?:f03|restore-drill)-[a-f0-9]{16}\z/', basename($workspace))) {
            throw new RuntimeException('Lifecycle child storage is outside a generated fixture.');
        }
        $runtime = $this->directory($workspace.'/runtime', $workspace);
        if ($this->normalize(storage_path()) !== $this->normalize($runtime)) {
            throw new RuntimeException('Lifecycle child runtime differs.');
        }
        $images = $this->directory($runtime.'/app/private', $workspace);
        $disk = Storage::disk('local');
        if (! $disk->getAdapter() instanceof LocalFilesystemAdapter || $this->normalize($disk->path('')) !== $this->normalize($images)) {
            throw new RuntimeException('Lifecycle child images are not inside the private fixture.');
        }
        $cache = $this->directory($runtime.'/cache', $workspace);
        foreach (['config.php', 'routes.php'] as $name) {
            if (file_exists($cache.'/'.$name) || is_link($cache.'/'.$name)) {
                throw new RuntimeException('Lifecycle child configuration must not use an existing cache.');
            }
        }
        $this->directory($runtime.'/logs', $workspace);
        $environment = [];
        foreach (getenv() as $name => $value) {
            if (str_starts_with($name, 'DB_') || str_starts_with($name, 'PG') || str_starts_with($name, 'MAIL_')) {
                $environment[$name] = false;
            }
        }
        $environment = array_replace($environment, [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'), 'APP_URL' => 'http://127.0.0.1',
            'APP_CONFIG_CACHE' => $cache.'/config.php', 'APP_ROUTES_CACHE' => $cache.'/routes.php',
            'APP_SERVICES_CACHE' => $cache.'/services.php', 'APP_PACKAGES_CACHE' => $cache.'/packages.php', 'APP_EVENTS_CACHE' => $cache.'/events.php',
            'LARAVEL_STORAGE_PATH' => $runtime, 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
            'DB_HOST' => '127.0.0.1', 'DB_PORT' => '55432', 'DB_DATABASE' => 'famie_test', 'DB_USERNAME' => 'famie_test_runner',
            'DB_PASSWORD' => (string) $connection['password'], 'DB_SCHEMA' => $identity->schema, 'DB_SSLMODE' => 'prefer', 'DB_TIMEZONE' => '+00:00',
            'FAMIE_DB_TEST_RUN' => '1', 'FAMIE_TEST_PROFILE' => 'postgres-local', 'FAMIE_LIFECYCLE_PROCESS_TEST' => 'true',
            'FAMIE_LIFECYCLE_MONITOR_ENABLED' => config('famie.lifecycle_monitor_enabled') ? 'true' : 'false',
            'FAMIE_LIFECYCLE_OPERATOR_ENABLED' => config('famie.lifecycle_operator_enabled') ? 'true' : 'false',
            'FAMIE_LIFECYCLE_KEY_ID' => (string) config('famie.lifecycle_key_id'), 'FAMIE_LIFECYCLE_PUBLIC_KEY' => (string) config('famie.lifecycle_public_key'),
            'FAMIE_OPERATIONS_ENABLED' => config('operations.enabled') ? 'true' : 'false', 'FAMIE_OPERATIONS_ENVIRONMENT' => 'testing',
            'FAMIE_OPERATIONS_MAILER' => 'array', 'FAMIE_OPERATIONS_RECIPIENT' => 'operator@example.test',
            'MAIL_MAILER' => 'array', 'MAIL_URL' => '', 'MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => '1', 'MAIL_USERNAME' => '', 'MAIL_PASSWORD' => '',
            'FAMIE_OPERATIONS_SUPPRESSED_UNTIL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'FILESYSTEM_DISK' => 'local', 'LOG_CHANNEL' => 'stderr',
        ]);
        foreach (['vault', 'ledger', 'operator', 'audit', 'export'] as $name) {
            $key = 'famie.lifecycle_'.$name.'_root';
            $path = LifecycleStoragePath::root($key, 'messages.lifecycle_vault_unavailable');
            $environment['FAMIE_LIFECYCLE_'.strtoupper($name).'_ROOT'] = $this->directory($path, $workspace);
        }

        return $environment;
    }

    private function directory(string $path, string $workspace): string
    {
        $resolved = realpath($path);
        if ($resolved === false || ! is_dir($resolved) || $this->normalize($path) !== $this->normalize($resolved)
            || ! str_starts_with($this->normalize($resolved).'/', $this->normalize($workspace).'/')) {
            throw new RuntimeException('Lifecycle child path escapes its fixture.');
        }
        for ($part = $path; dirname($part) !== $part; $part = dirname($part)) {
            if (is_link($part)) {
                throw new RuntimeException('Lifecycle child links are not allowed.');
            }
        }

        return $resolved;
    }

    private function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}
