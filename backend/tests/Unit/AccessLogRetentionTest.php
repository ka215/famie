<?php

namespace Tests\Unit;

use App\Services\AccessLogRetention;
use PHPUnit\Framework\TestCase;

class AccessLogRetentionTest extends TestCase
{
    private string $root;

    private const CHECKED_AT = 1800000000;

    protected function tearDown(): void
    {
        if (isset($this->root) && dirname($this->root) === realpath(sys_get_temp_dir())) {
            foreach (glob($this->root.'/*') as $path) {
                if (is_dir($path) && ! is_link($path)) {
                    rmdir($path);
                } else {
                    unlink($path);
                }
            }
            rmdir($this->root);
        }
        parent::tearDown();
    }

    private function logs(): string
    {
        $this->root = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'famie-retention-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $base = $this->root.DIRECTORY_SEPARATOR.'example.log';
        foreach (['example.log', 'example.1.log', 'example.2.log', 'example.3.log', 'example.4.log'] as $name) {
            file_put_contents($this->root.'/'.$name, 'fixture content');
            touch($this->root.'/'.$name, self::CHECKED_AT);
        }

        return $base;
    }

    public function test_warns_after_25_days_and_escalates_after_30_days_without_changing_files(): void
    {
        $base = $this->logs();
        $monitor = new AccessLogRetention;
        file_put_contents($this->root.'/unrelated.log', 'unrelated');
        touch($this->root.'/unrelated.log', 1);
        foreach ([2160000 => 'ok', 2160001 => 'warning', 2592000 => 'warning', 2592001 => 'critical'] as $age => $expected) {
            touch($this->root.'/example.4.log', self::CHECKED_AT - $age);

            $this->assertSame($expected, $monitor->check($base, self::CHECKED_AT));
            $this->assertSame(self::CHECKED_AT - $age, filemtime($this->root.'/example.4.log'));
            $this->assertSame('fixture content', file_get_contents($this->root.'/example.4.log'));
        }
        $this->assertSame('unrelated', file_get_contents($this->root.'/unrelated.log'));
    }

    public function test_ignores_files_with_generation_after_the_log_extension(): void
    {
        $base = $this->logs();
        file_put_contents($this->root.'/example.log.4', 'unrelated legacy naming');
        touch($this->root.'/example.log.4', 1);

        $this->assertSame('ok', (new AccessLogRetention)->check($base, self::CHECKED_AT));
    }

    public function test_does_not_report_missing_or_future_dated_logs_as_healthy(): void
    {
        $base = $this->logs();
        unlink($this->root.'/example.2.log');
        $monitor = new AccessLogRetention;

        $this->assertSame('warning', $monitor->check($base, self::CHECKED_AT));
        touch($this->root.'/example.2.log', self::CHECKED_AT + 1);
        $this->assertSame('warning', $monitor->check($base, self::CHECKED_AT));
        touch($this->root.'/example.4.log', self::CHECKED_AT - 2592001);
        $this->assertSame('critical', $monitor->check($base, self::CHECKED_AT));
    }

    public function test_rejects_directory_instead_of_a_regular_log(): void
    {
        $base = $this->logs();
        unlink($this->root.'/example.1.log');
        mkdir($this->root.'/example.1.log');

        $this->assertSame('warning', (new AccessLogRetention)->check($base, self::CHECKED_AT));
    }

    public function test_rejects_invalid_or_relative_configuration(): void
    {
        $monitor = new AccessLogRetention;

        foreach ([null, '', 'example.log', "invalid\0.log", '/missing/example.txt'] as $path) {
            $this->assertSame('warning', $monitor->check($path, self::CHECKED_AT));
        }
    }

    public function test_rejects_hard_linked_log(): void
    {
        $base = $this->logs();
        unlink($this->root.'/example.1.log');
        link($base, $this->root.'/example.1.log');

        $this->assertSame('warning', (new AccessLogRetention)->check($base, self::CHECKED_AT));
    }

    public function test_rejects_symbolic_linked_log(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Creating symbolic links requires a separate Windows privilege. Covered on Linux CI.');
        }
        $base = $this->logs();
        unlink($this->root.'/example.1.log');
        symlink($base, $this->root.'/example.1.log');

        $this->assertSame('warning', (new AccessLogRetention)->check($base, self::CHECKED_AT));
    }

    public function test_rejects_a_symbolic_link_in_the_parent_path(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Creating symbolic links requires a separate Windows privilege. Covered on Linux CI.');
        }
        $this->logs();
        symlink($this->root, $this->root.'/alias');

        $this->assertSame('warning', (new AccessLogRetention)->check($this->root.'/alias/example.log', self::CHECKED_AT));
    }

    public function test_does_not_report_unreadable_logs_as_healthy(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX read permissions are covered on Linux CI.');
        }
        $base = $this->logs();
        chmod($this->root.'/example.1.log', 0000);
        clearstatcache(true, $this->root.'/example.1.log');
        if (is_readable($this->root.'/example.1.log')) {
            $this->markTestSkipped('This test requires an unprivileged user.');
        }

        $this->assertSame('warning', (new AccessLogRetention)->check($base, self::CHECKED_AT));
    }
}
