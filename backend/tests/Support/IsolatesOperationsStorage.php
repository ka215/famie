<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

trait IsolatesOperationsStorage
{
    private function isolateOperationsStorage(): string
    {
        $root = base_path('storage/framework/testing');
        $path = $root.'/operations-'.Str::uuid();
        File::makeDirectory($path.'/logs', 0700, true);
        $this->app->useStoragePath($path);
        config(['logging.channels.operations.path' => $path.'/logs/operations.log']);
        $this->beforeApplicationDestroyed(function () use ($path, $root): void {
            $resolved = realpath($path);
            if ($resolved !== false && dirname($resolved) === realpath($root) && ! is_link($path)) {
                File::deleteDirectory($resolved);
            }
        });

        return $path;
    }
}
