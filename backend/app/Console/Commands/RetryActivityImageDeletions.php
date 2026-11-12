<?php

namespace App\Console\Commands;

use App\Services\ActivityImageDeletionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('famie:retry-image-deletions')]
#[Description('Retry physical deletion of retired activity images')]
class RetryActivityImageDeletions extends Command
{
    public function handle(ActivityImageDeletionService $deletions): int
    {
        $failed = $deletions->process();
        $this->info("未削除: {$failed} 件");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
