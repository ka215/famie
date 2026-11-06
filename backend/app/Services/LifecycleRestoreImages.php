<?php

namespace App\Services;

use DirectoryIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class LifecycleRestoreImages
{
    private const LIMIT = 20000;

    public function __construct(private LifecycleRestoreReapply $restore, private LifecycleLedgerInspector $ledger) {}

    /** @return array<string,mixed> */
    public function check(string $checksum): array
    {
        $this->restore->isolatedTarget();

        return $this->ledger->inspect($checksum, consume: fn (array $snapshot): array => DB::transaction(function () use ($snapshot): array {
            $files = $this->files();
            $images = DB::table('activity_images')->limit(self::LIMIT + 1)->get(['id', 'group_id', 'activity_log_id', 'disk', 'path', 'bytes', 'retired_at', 'lifecycle_operation_id']);
            $queues = DB::table('activity_image_deletions')->limit(self::LIMIT + 1)->get(['activity_image_id', 'group_id', 'operation_id', 'disk', 'path', 'bytes']);
            $groups = DB::table('groups')->limit(self::LIMIT + 1)->get(['id', 'image_quota_bytes'])->keyBy('id');
            if (max($images->count(), $queues->count(), $groups->count()) > self::LIMIT) {
                throw new RuntimeException('Image assessment limit exceeded; no complete assessment is available.');
            }
            $logs = DB::table('activity_logs')->whereIn('id', $images->pluck('activity_log_id'))->get(['id', 'group_id'])->keyBy('id');
            $byId = $images->keyBy('id');
            $queuedIds = $queues->keyBy('activity_image_id');
            $known = [];
            $owners = [];
            $declared = [];
            $findings = [];
            $add = function (string $category, int $count = 1) use (&$findings): void {
                if ($count > 0) {
                    $findings[$category] = ($findings[$category] ?? 0) + $count;
                }
            };
            $add('unresolved_intent', $snapshot['unresolved']);
            $databaseBytes = 0;
            $queuedBytes = 0;
            foreach ($images as $image) {
                $databaseBytes += (int) $image->bytes;
                $declared[$image->group_id] = ($declared[$image->group_id] ?? 0) + (int) $image->bytes;
                if (! $groups->has($image->group_id) || $logs->get($image->activity_log_id)?->group_id !== $image->group_id) {
                    $add('image_owner_mismatch');
                }
                if ($image->bytes < 0) {
                    $add('invalid_recorded_size');
                }
                if ($image->disk !== 'local') {
                    $add('unsupported_image_disk');

                    continue;
                }
                $this->assertPath($image->path);
                if (isset($known[$image->path])) {
                    $add('duplicate_image_reference');
                }
                $known[$image->path] = true;
                $owners[$image->path][$image->group_id] = true;
                if (! array_key_exists($image->path, $files)) {
                    $add('missing_image_file');
                } elseif ($files[$image->path] !== (int) $image->bytes) {
                    $add('image_size_mismatch');
                }
                if ($image->retired_at !== null && ! $queuedIds->has($image->id)) {
                    $add('retired_image_without_queue');
                }
            }
            foreach ($queues as $queue) {
                $queuedBytes += (int) $queue->bytes;
                $image = $byId->get($queue->activity_image_id);
                if ($image === null || $image->retired_at === null || $image->group_id !== $queue->group_id
                    || $image->disk !== $queue->disk || $image->path !== $queue->path || (int) $image->bytes !== (int) $queue->bytes
                    || $image->lifecycle_operation_id !== $queue->operation_id) {
                    $add('deletion_queue_mismatch');
                }
                if ($queue->disk !== 'local') {
                    $add('unsupported_queue_disk');

                    continue;
                }
                $this->assertPath($queue->path);
                $known[$queue->path] = true;
                $owners[$queue->path][$queue->group_id] = true;
                if (isset($files[$queue->path]) && $files[$queue->path] !== (int) $queue->bytes) {
                    $add('queued_size_mismatch');
                }
            }
            $actual = [];
            $orphaned = 0;
            $orphanedBytes = 0;
            foreach ($files as $path => $bytes) {
                if (! isset($known[$path])) {
                    $orphaned++;
                    $orphanedBytes += $bytes;
                } elseif (count($owners[$path]) !== 1) {
                    $add('shared_file_ownership');
                } else {
                    $group = array_key_first($owners[$path]);
                    $actual[$group] = ($actual[$group] ?? 0) + $bytes;
                }
            }
            $add('orphaned_image_file', $orphaned);
            foreach ($groups as $group) {
                if (($declared[$group->id] ?? 0) > (int) $group->image_quota_bytes) {
                    $add('recorded_quota_exceeded');
                }
                if (($actual[$group->id] ?? 0) > ($declared[$group->id] ?? 0)) {
                    $add('physical_usage_exceeds_recorded');
                }
            }
            ksort($findings);

            return ['assessment_only' => true, 'database_changed' => false, 'files_changed' => false, 'reopen_allowed' => false,
                'scope' => 'isolated-local-activity-images', 'ledger_sha256' => $snapshot['sha256'],
                'image_rows' => $images->count(), 'queued_rows' => $queues->count(), 'physical_files' => count($files),
                'recorded_bytes' => $databaseBytes, 'queued_recorded_bytes' => $queuedBytes,
                'physical_bytes' => array_sum($files), 'orphaned_files' => $orphaned, 'unattributed_bytes' => $orphanedBytes,
                'findings' => $findings];
        }));
    }

    /** @return array<string,int> */
    private function files(): array
    {
        $disk = Storage::disk('local');
        $root = $disk->path('activity-images');
        if (! file_exists($root) && ! is_link($root)) {
            return [];
        }
        if (is_link($root) || ! is_dir($root)) {
            throw new RuntimeException('Image assessment root is not a regular directory.');
        }
        $pending = [['activity-images', 0]];
        $files = [];
        $visited = 0;
        while ($pending !== []) {
            [$relative, $depth] = array_pop($pending);
            foreach (new DirectoryIterator($disk->path($relative)) as $entry) {
                if ($entry->isDot()) {
                    continue;
                }
                if (++$visited > self::LIMIT || ($depth >= 8 && $entry->isDir()) || $entry->isLink()) {
                    throw new RuntimeException('Unsafe or excessive image tree; assessment is incomplete.');
                }
                $path = $relative.'/'.$entry->getFilename();
                $this->assertPath($path);
                if ($entry->isDir()) {
                    $pending[] = [$path, $depth + 1];

                    continue;
                }
                $stat = lstat($entry->getPathname());
                if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) {
                    throw new RuntimeException('Image tree contains a non-unique regular file.');
                }
                $files[$path] = $stat['size'];
            }
        }

        return $files;
    }

    private function assertPath(string $path): void
    {
        if (! preg_match('/\Aactivity-images\/[A-Za-z0-9][A-Za-z0-9_.\/-]*\z/', $path)
            || array_intersect(explode('/', $path), ['', '.', '..'])) {
            throw new RuntimeException('Image assessment path is ambiguous.');
        }
    }
}
