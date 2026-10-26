<?php

namespace App\Support;

use Illuminate\Foundation\Exceptions\Handler;
use Throwable;

class SafeExceptionHandler extends Handler
{
    public function renderForConsole($output, Throwable $e): void
    {
        if (app()->environment('local', 'testing')) {
            parent::renderForConsole($output, $e);

            return;
        }

        $output->writeln('<error>Famie command failed. Inspect the private operation log.</error>');
    }
}
