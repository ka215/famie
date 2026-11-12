<?php

namespace App\Services;

use RuntimeException;

class LifecycleCleanupBudget
{
    private ?int $deadline = null;

    public function start(?int $seconds = null): void
    {
        $seconds ??= config('famie.lifecycle_cleanup_seconds');
        if (! is_int($seconds) || $seconds < 1 || $seconds > 120) {
            throw new RuntimeException('Invalid lifecycle cleanup budget.');
        }
        $this->deadline = hrtime(true) + $seconds * 1000000000;
    }

    public function available(): bool
    {
        return $this->deadline !== null && hrtime(true) < $this->deadline;
    }
}
