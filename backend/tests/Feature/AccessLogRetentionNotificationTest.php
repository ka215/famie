<?php

namespace Tests\Feature;

use App\Mail\OperationAlert;
use App\Services\InternalMonitor;
use App\Services\MonitorNotifier;
use Illuminate\Support\Facades\Mail;
use Tests\Support\IsolatesOperationsStorage;
use Tests\TestCase;

class AccessLogRetentionNotificationTest extends TestCase
{
    use IsolatesOperationsStorage;

    public function test_checks_opted_in_logs_and_notifies_escalation_and_recovery_without_duplicate_mail(): void
    {
        $this->freezeTime();
        $root = $this->isolateOperationsStorage();
        $base = $root.'/example.log';
        foreach (['', '.1', '.2', '.3', '.4'] as $suffix) {
            file_put_contents($base.$suffix, 'private fixture content');
            touch($base.$suffix, now()->timestamp);
        }
        config([
            'operations.access_log_monitor_enabled' => false,
            'operations.access_log_path' => $base,
            'operations.recipient' => 'operator@example.invalid',
            'operations.mailer' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'operations.suppressed_until' => null,
            'operations.quota_roots' => [],
            'famie.lifecycle_monitor_enabled' => false,
        ]);
        Mail::fake();
        $monitor = app(InternalMonitor::class);
        $notifier = app(MonitorNotifier::class);

        $this->assertArrayNotHasKey('access-log-retention', $monitor->check());
        config(['operations.access_log_monitor_enabled' => true]);
        touch($base.'.4', now()->subDays(26)->timestamp);
        $severity = $monitor->check()['access-log-retention'];
        $this->assertSame('warning', $severity);
        $state = $notifier->update('access-log-retention', $severity, [], 'fixture-run');
        $state = $notifier->update('access-log-retention', $severity, $state, 'fixture-run');
        Mail::assertSentCount(1);

        touch($base.'.4', now()->subDays(31)->timestamp);
        $severity = $monitor->check()['access-log-retention'];
        $this->assertSame('critical', $severity);
        $state = $notifier->update('access-log-retention', $severity, $state, 'fixture-run');
        Mail::assertSentCount(2);

        touch($base.'.4', now()->timestamp);
        $severity = $monitor->check()['access-log-retention'];
        $this->assertSame('ok', $severity);
        $state = $notifier->update('access-log-retention', $severity, $state, 'fixture-run');
        Mail::assertSentCount(2);
        $state = $notifier->update('access-log-retention', $severity, $state, 'fixture-run');

        $this->assertFalse($state['open']);
        Mail::assertSentCount(3);
        foreach (['warning', 'critical', 'recovered'] as $expected) {
            Mail::assertSent(OperationAlert::class, function (OperationAlert $mail) use ($expected, $base): bool {
                $this->assertStringNotContainsString($base, $mail->render());
                $this->assertStringNotContainsString('private fixture content', $mail->render());

                return $mail->check === 'access-log-retention' && $mail->severity === $expected;
            });
        }
    }
}
