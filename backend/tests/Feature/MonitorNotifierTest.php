<?php

namespace Tests\Feature;

use App\Mail\OperationAlert;
use App\Services\MonitorNotifier;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class MonitorNotifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['operations.recipient' => 'operator@example.test', 'operations.mailer' => 'smtp']);
    }

    public function test_two_failures_notify_once_then_remind_after_an_hour_and_recover_after_two_successes(): void
    {
        $this->freezeTime();
        Mail::fake();
        $notifier = app(MonitorNotifier::class);
        $id = (string) Str::uuid();
        $state = $notifier->update('database', 'critical', [], $id);
        Mail::assertNothingSent();
        $state = $notifier->update('database', 'critical', $state, $id);
        $state = $notifier->update('database', 'critical', $state, $id);
        Mail::assertSentCount(1);
        $this->travel(60)->minutes();
        $state = $notifier->update('database', 'critical', $state, $id);
        Mail::assertSentCount(2);
        $state = $notifier->update('database', 'ok', $state, $id);
        $this->assertTrue($state['open']);
        $state = $notifier->update('database', 'ok', $state, $id);
        $this->assertFalse($state['open']);
        Mail::assertSent(OperationAlert::class, fn (OperationAlert $mail): bool => $mail->severity === 'recovered' && $mail->hasTo('operator@example.test'));
        Mail::assertSentCount(3);
    }

    public function test_severity_escalation_is_immediate_and_suppression_expires(): void
    {
        $this->freezeTime();
        Mail::fake();
        $id = (string) Str::uuid();
        $notifier = app(MonitorNotifier::class);
        $state = $notifier->update('capacity', 'warning', [], $id);
        $state = $notifier->update('capacity', 'critical', $state, $id);
        Mail::assertSentCount(2);
        config(['operations.suppressed_until' => now()->addHours(2)->toIso8601String()]);
        $this->travel(61)->minutes();
        $state = $notifier->update('capacity', 'critical', $state, $id);
        Mail::assertSentCount(2);
        $this->assertArrayHasKey('suppressed_until', $state);
        $this->travel(60)->minutes();
        $notifier->update('capacity', 'critical', $state, $id);
        Mail::assertSentCount(3);
    }

    public function test_invalid_or_logging_transport_is_rejected_and_is_retried_next_time(): void
    {
        $this->freezeTime();
        Mail::fake();
        config(['operations.mailer' => 'log']);
        $notifier = app(MonitorNotifier::class);
        $id = (string) Str::uuid();
        $state = $notifier->update('capacity', 'warning', [], $id);
        $this->assertFalse($state['open']);
        Mail::assertNothingSent();
        config(['operations.mailer' => 'smtp']);
        $state = $notifier->update('capacity', 'warning', $state, $id);
        $this->assertTrue($state['open']);
        Mail::assertSentCount(1);
    }

    public function test_smtp_failure_does_not_mark_notification_delivered(): void
    {
        Mail::shouldReceive('mailer')->with('smtp')->andReturnSelf();
        Mail::shouldReceive('to')->with('operator@example.test')->andThrow(new \RuntimeException('private-smtp-secret'));
        $state = app(MonitorNotifier::class)->update('capacity', 'critical', [], (string) Str::uuid());
        $this->assertFalse($state['open']);
        $this->assertSame(0, $state['notified_at']);
    }

    public function test_operator_email_contains_only_safe_check_metadata(): void
    {
        $this->freezeTime();
        $mail = new OperationAlert('database', 'critical', '21aa889b-61db-4f96-96c2-c74680c208d9');
        $mail->assertSeeInText('database');
        $mail->assertSeeInText('critical');
        $mail->assertSeeInText('21aa889b-61db-4f96-96c2-c74680c208d9');
        $mail->assertDontSeeInText('operator@example.test');
    }
}
