<?php

namespace App\Services;

use App\Models\AccountLifecycleOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;

class LifecycleImageEvidence
{
    private const PAGE_SIZE = 500;

    private const MAX_IMAGES = 10000;

    public function __construct(private LifecycleOperatorFiles $files, private LifecycleCleanupBudget $budget) {}

    public function capture(AccountLifecycleOperation $operation): ?string
    {
        $this->budget->start(10);
        $this->assertBudget();
        $subjects = $operation->subjects()->orderBy('user_id')->pluck('user_id')->all();
        $query = DB::table('activity_images as image')->join('activity_logs as log', 'log.id', '=', 'image.activity_log_id')
            ->where('log.group_id', $operation->group_id)->whereIn('log.user_id', $subjects);
        $count = $query->count();
        $this->assertBudget();
        if ($count === 0) {
            return null;
        }
        if ($count > self::MAX_IMAGES) {
            throw new RuntimeException('Source image evidence exceeds the bounded capture limit.');
        }
        $images = [];
        $cursor = 0;
        while (count($images) < $count) {
            $this->assertBudget();
            $rows = (clone $query)->where('image.id', '>', $cursor)->orderBy('image.id')
                ->limit(min(self::PAGE_SIZE, $count - count($images)))->lock(DB::getDriverName() === 'pgsql' ? 'for update of image' : true)
                ->get(['image.id', 'image.group_id', 'image.disk', 'image.path', 'image.bytes', 'image.lifecycle_operation_id', 'log.user_id']);
            $this->assertBudget();
            if ($rows->isEmpty()) {
                throw new RuntimeException('Source image set changed during capture.');
            }
            $references = DB::table('activity_images')->where('disk', 'local')->whereIn('path', $rows->pluck('path'))->get(['id', 'path'])->groupBy('path');
            $queues = DB::table('activity_image_deletions')->where('disk', 'local')->whereIn('path', $rows->pluck('path'))->get(['activity_image_id', 'path'])->groupBy('path');
            foreach ($rows as $row) {
                $this->assertBudget();
                if ($row->group_id !== $operation->group_id || $row->disk !== 'local' || $row->bytes < 0
                    || ($row->lifecycle_operation_id !== null && $row->lifecycle_operation_id !== $operation->id)
                    || ($references->get($row->path)?->count() ?? 0) !== 1
                    || ($queues->get($row->path)?->contains(fn ($queue) => $queue->activity_image_id !== $row->id) ?? false)) {
                    throw new RuntimeException('Source image ownership is ambiguous.');
                }
                $fingerprint = $this->fingerprint($row->path);
                $this->assertBudget();
                if ($fingerprint !== null && $fingerprint['bytes'] !== (int) $row->bytes) {
                    throw new RuntimeException('Source image size differs from its record.');
                }
                $images[] = ['image_id' => $row->id, 'user_id' => $row->user_id, 'disk' => 'local', 'path' => $row->path,
                    'bytes' => (int) $row->bytes, 'sha256' => $fingerprint['sha256'] ?? null];
                $cursor = $row->id;
            }
        }
        $data = ['format_version' => 1, 'receipt_id' => $operation->id, 'group_id' => $operation->group_id,
            'kind' => $operation->kind, 'subject_ids' => $subjects, 'images' => $images];
        $path = $this->files->imageEvidencePath($operation->id);
        $this->assertBudget();
        if (! file_exists($path) && ! is_link($path)) {
            $this->files->createImageEvidence($operation->id, $data);
        }
        $loaded = $this->files->readImageEvidence($operation->id);
        if ($loaded['data'] !== $data) {
            throw new RuntimeException('Existing image evidence must not be overwritten.');
        }
        $this->assertBudget();

        return $loaded['sha256'];
    }

    private function assertBudget(): void
    {
        if (! $this->budget->available()) {
            throw new RuntimeException('Source image evidence budget exhausted; preserve accounts and source files.');
        }
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return list<array<string,mixed>>
     */
    public function read(string $receipt, array $evidence, ?string $sync = null): array
    {
        $checksum = $evidence['image_manifest_sha256'] ?? null;
        if (! is_string($checksum)) {
            throw new RuntimeException('Independently recorded image evidence is required.');
        }
        $loaded = $sync === null ? $this->files->readImageEvidence($receipt) : $this->files->readCopiedImageEvidence($sync, $receipt);
        $data = $loaded['data'];
        $fields = ['format_version', 'receipt_id', 'group_id', 'kind', 'subject_ids', 'images'];
        $expected = $evidence['subject_ids'];
        sort($expected);
        $actual = $data['subject_ids'] ?? null;
        if (is_array($actual)) {
            sort($actual);
        }
        if (! hash_equals($checksum, $loaded['sha256']) || count($data) !== count($fields) || array_diff(array_keys($data), $fields)
            || ($data['format_version'] ?? null) !== 1 || ($data['receipt_id'] ?? null) !== $receipt
            || ($data['group_id'] ?? null) !== $evidence['group_id'] || ($data['kind'] ?? null) !== $evidence['kind'] || $actual !== $expected
            || ! is_array($data['images'] ?? null) || ! array_is_list($data['images']) || count($data['images']) > self::MAX_IMAGES) {
            throw new RuntimeException('Image evidence disagrees with committed identity.');
        }
        $ids = [];
        $paths = [];
        foreach ($data['images'] as $image) {
            if (! is_array($image) || count($image) !== 6 || array_diff(array_keys($image), ['image_id', 'user_id', 'disk', 'path', 'bytes', 'sha256'])
                || ! is_int($image['image_id'] ?? null) || $image['image_id'] < 1 || isset($ids[$image['image_id']])
                || ! in_array($image['user_id'] ?? null, $expected, true) || ($image['disk'] ?? null) !== 'local'
                || ! is_string($image['path'] ?? null) || isset($paths[$image['path']]) || ! is_int($image['bytes'] ?? null) || $image['bytes'] < 0
                || (! is_null($image['sha256'] ?? null) && (! is_string($image['sha256']) || ! preg_match('/\A[a-f0-9]{64}\z/', $image['sha256'])))) {
                throw new RuntimeException('Image evidence mapping is ambiguous.');
            }
            $this->assertPath($image['path']);
            $ids[$image['image_id']] = true;
            $paths[$image['path']] = true;
        }

        return $data['images'];
    }

    /** @return array{bytes:int,sha256:string}|null */
    public function fingerprint(string $relative, ?string $expected = null, bool $delete = false): ?array
    {
        if ($delete) {
            app(LifecycleRestoreReapply::class)->isolatedTarget();
        }
        $this->assertPath($relative);
        $disk = Storage::disk('local');
        if (! $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            throw new RuntimeException('Image evidence requires local storage.');
        }
        $path = rtrim($disk->path(''), '/\\');
        if (is_link($path)) {
            throw new RuntimeException('Linked image storage is unavailable.');
        }
        foreach (explode('/', $relative) as $part) {
            $path .= '/'.$part;
            if (is_link($path)) {
                throw new RuntimeException('Linked image evidence target is unavailable.');
            }
        }
        clearstatcache(true, $path);
        if (! file_exists($path)) {
            return null;
        }
        $before = lstat($path);
        if ($before === false || ($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1 || $before['size'] > 20971520) {
            throw new RuntimeException('Unsafe or oversized image evidence target.');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Image evidence target is unreadable.');
        }
        try {
            if (! flock($handle, ($delete ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
                throw new RuntimeException('Image evidence target is busy.');
            }
            $opened = fstat($handle);
            if ($opened === false || $opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev'] || $opened['size'] !== $before['size'] || $opened['nlink'] !== 1) {
                throw new RuntimeException('Image evidence target changed.');
            }
            $hash = hash_init('sha256');
            if (hash_update_stream($hash, $handle, 20971521) !== $opened['size']) {
                throw new RuntimeException('Image evidence target read was incomplete.');
            }
            $checksum = hash_final($hash);
            clearstatcache(true, $path);
            $named = lstat($path);
            if ($named === false || is_link($path) || $named['ino'] !== $opened['ino'] || $named['dev'] !== $opened['dev']
                || $named['size'] !== $opened['size'] || $named['nlink'] !== 1 || ($expected !== null && ! hash_equals($expected, $checksum))) {
                throw new RuntimeException('Image evidence fingerprint changed.');
            }
            if ($delete && ($expected === null || ! unlink($path))) {
                throw new RuntimeException('Verified image removal failed.');
            }

            return ['bytes' => $opened['size'], 'sha256' => $checksum];
        } finally {
            fclose($handle);
        }
    }

    private function assertPath(string $path): void
    {
        if (! preg_match('/\Aactivity-images\/[A-Za-z0-9][A-Za-z0-9_.\/-]*\z/', $path)
            || array_intersect(explode('/', $path), ['', '.', '..'])) {
            throw new RuntimeException('Image evidence path is ambiguous.');
        }
    }
}
