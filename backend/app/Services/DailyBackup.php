<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class DailyBackup
{
    public function __construct(private OperationState $state) {}

    public function run(): string
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Daily backup requires PostgreSQL.');
        }
        $root = $this->state->directory().'/backups';
        if (is_link($root) || (! is_dir($root) && ! mkdir($root, 0700))) {
            throw new RuntimeException('Backup directory unavailable.');
        }
        $id = now()->utc()->format('Ymd-His').'-'.Str::uuid();
        $directory = $root.'/.partial-'.$id;
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Backup directory unavailable.');
        }
        $started = microtime(true);
        $this->state->locked('image-deletions', function () use ($directory, $started): void {
            DB::transaction(function () use ($directory, $started): void {
                DB::statement("SET LOCAL lock_timeout = '5s'");
                DB::statement("SET LOCAL idle_in_transaction_session_timeout = '300s'");
                DB::statement('LOCK TABLE activity_images, activity_image_deletions IN SHARE MODE');
                $disks = DB::table('activity_images')->distinct()->pluck('disk')
                    ->merge(DB::table('activity_image_deletions')->distinct()->pluck('disk'))
                    ->push(config('famie.image_disk'))->unique()->values();
                $files = [];
                foreach ($disks as $disk) {
                    if (! is_string($disk) || ! preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D', $disk)
                        || config('filesystems.disks.'.$disk.'.driver') !== 'local') {
                        throw new RuntimeException('Unsupported image disk.');
                    }
                    $imageRoot = config('filesystems.disks.'.$disk.'.root');
                    if (! is_string($imageRoot) || is_link($imageRoot)) {
                        throw new RuntimeException('Invalid image root.');
                    }
                    $path = $imageRoot.'/activity-images';
                    if (is_link($path)) {
                        throw new RuntimeException('Invalid image root.');
                    }
                    $archiveName = 'images-'.$disk.'.tar';
                    $archive = new PharData($directory.'/'.$archiveName);
                    $archive->addEmptyDir('activity-images');
                    if (is_dir($path)) {
                        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
                        foreach ($iterator as $file) {
                            if ($file->isLink() || microtime(true) - $started > 180) {
                                throw new RuntimeException('Image backup could not complete safely.');
                            }
                            if ($file->isFile()) {
                                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($path) + 1));
                                $archive->addFile($file->getPathname(), 'activity-images/'.$relative);
                            }
                        }
                    }
                    unset($archive);
                    chmod($directory.'/'.$archiveName, 0600);
                    $files[$archiveName] = hash_file('sha256', $directory.'/'.$archiveName);
                }
                $connection = DB::connection()->getConfig();
                $schema = DB::selectOne('select current_schema() as name')->name;
                $dump = $directory.'/database.dump';
                $result = Process::env(['PGPASSWORD' => (string) ($connection['password'] ?? '')])->timeout(60)->run([
                    'pg_dump', '--host='.(string) $connection['host'], '--port='.(string) $connection['port'],
                    '--username='.(string) $connection['username'], '--schema='.$schema, '--format=custom', '--file='.$dump, '--dbname='.(string) $connection['database'],
                ]);
                if (! $result->successful() || ! is_file($dump) || filesize($dump) === 0) {
                    throw new RuntimeException('Database backup failed.');
                }
                chmod($dump, 0600);
                $listing = Process::timeout(10)->run(['pg_restore', '--list', $dump]);
                if (! $listing->successful() || ! preg_match('/TABLE DATA .* users\s/', $listing->output()) || ! preg_match('/TABLE DATA .* activity_logs\s/', $listing->output())) {
                    throw new RuntimeException('Backup structure validation failed.');
                }
                $files['database.dump'] = hash_file('sha256', $dump);
                if (file_put_contents($dump.'.sha256', $files['database.dump']."  database.dump\n") === false) {
                    throw new RuntimeException('Backup checksum write failed.');
                }
                chmod($dump.'.sha256', 0600);
                if (file_put_contents($directory.'/manifest.json', json_encode(['created_at' => now()->utc()->toIso8601String(), 'files' => $files], JSON_THROW_ON_ERROR)) === false) {
                    throw new RuntimeException('Backup manifest write failed.');
                }
                chmod($directory.'/manifest.json', 0600);
            });
        });
        if (! rename($directory, $root.'/'.$id)) {
            throw new RuntimeException('Backup finalization failed.');
        }
        $this->prune();

        return $id;
    }

    public function prune(): void
    {
        $root = $this->state->directory().'/backups';
        if (! is_dir($root)) {
            return;
        }
        if (is_link($root)) {
            throw new RuntimeException('Unsafe backup cleanup root.');
        }
        $resolvedRoot = realpath($root);
        foreach ([...(glob($root.'/*', GLOB_ONLYDIR) ?: []), ...(glob($root.'/.partial-*', GLOB_ONLYDIR) ?: [])] as $path) {
            if (is_link($path) || ! preg_match('/\A(?:\.partial-)?(\d{8})-\d{6}-[a-f0-9-]{36}\z/D', basename($path), $match)
                || $match[1] >= now()->utc()->subDays(7)->format('Ymd')) {
                continue;
            }
            $resolved = realpath($path);
            if ($resolved === false || dirname($resolved) !== $resolvedRoot || (! str_starts_with(basename($path), '.partial-') && ! is_file($resolved.'/manifest.json'))) {
                throw new RuntimeException('Unsafe backup cleanup target.');
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->isLink()) {
                    throw new RuntimeException('Unsafe backup cleanup target.');
                }
            }
            if (! File::deleteDirectory($resolved)) {
                throw new RuntimeException('Backup cleanup failed.');
            }
        }
    }
}
