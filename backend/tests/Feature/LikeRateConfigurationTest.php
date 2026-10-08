<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LikeRateConfigurationTest extends TestCase
{
    #[TestWith(['2', true])]
    #[TestWith(['5', true])]
    #[TestWith(['0', false])]
    #[TestWith(['-1', false])]
    #[TestWith(['1.5', false])]
    #[TestWith(['invalid', false])]
    #[TestWith(['true', false])]
    public function test_rate_configuration_accepts_positive_integers_and_rejects_invalid_values(string $value, bool $valid): void
    {
        $process = new Process([PHP_BINARY, '-r', 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo "LIKE_LIMIT=".config("famie.like_rate_limit_per_second");'], base_path(), [
            'FAMIE_LIKE_RATE_LIMIT_PER_SECOND' => $value,
            'APP_CONFIG_CACHE' => base_path('storage/framework/testing-like-config-not-cached.php'),
        ]);
        $process->run();

        if ($valid) {
            $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            $this->assertStringContainsString('LIKE_LIMIT='.$value, $process->getOutput());
        } else {
            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString('famie.like_rate_limit_per_second must be a positive integer.', $process->getOutput().$process->getErrorOutput());
        }
    }
}
