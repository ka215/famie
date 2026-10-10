<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Signature('famie:retry-image-deletions')]
#[Description('Retry physical deletion of retired activity images')]
class RetryActivityImageDeletions extends Command
{
    public function handle(): int
    {
        $failed = 0;
        DB::table('activity_image_deletions')->orderBy('id')->chunkById(100, function ($rows) use (&$failed): void {
            foreach ($rows as $row) {
                try {
                    if (Storage::disk($row->disk)->delete($row->path)) {
                        DB::table('activity_image_deletions')->where('id', $row->id)->delete();
                    } else {
                        $failed++;
                    }
                } catch (Throwable $e) {
                    $failed++;
                    $this->error("画像 {$row->id}: {$e->getMessage()}");
                }
            }
        });
        $this->info("未削除: {$failed} 件");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
