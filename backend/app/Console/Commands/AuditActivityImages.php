<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Signature('famie:audit-images')]
#[Description('Compare activity image records with private storage files')]
class AuditActivityImages extends Command
{
    public function handle(): int
    {
        $images = DB::table('activity_images')->get(['disk', 'path']);
        $deletions = DB::table('activity_image_deletions')->get(['disk', 'path']);
        $known = [];
        $missing = 0;
        foreach ($images as $image) {
            $known[$image->disk][$image->path] = true;
            if (! Storage::disk($image->disk)->exists($image->path)) {
                $this->warn("DBにありファイルがない: {$image->disk}:{$image->path}");
                $missing++;
            }
        }
        foreach ($deletions as $deletion) {
            $known[$deletion->disk][$deletion->path] = true;
        }
        $orphaned = 0;
        foreach (array_unique([...array_keys($known), config('famie.image_disk')]) as $disk) {
            foreach (Storage::disk($disk)->allFiles('activity-images') as $path) {
                if (! isset($known[$disk][$path])) {
                    $this->warn("DBにないファイル: {$disk}:{$path}");
                    $orphaned++;
                }
            }
        }
        $this->info("不足 {$missing} 件、孤立 {$orphaned} 件、削除待ち {$deletions->count()} 件");

        return $missing === 0 && $orphaned === 0 ? self::SUCCESS : self::FAILURE;
    }
}
