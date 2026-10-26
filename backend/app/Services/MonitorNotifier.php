<?php

namespace App\Services;

use App\Mail\OperationAlert;
use App\Support\OperationLog;
use Illuminate\Support\Facades\Mail;
use Throwable;

class MonitorNotifier
{
    /** @param array<string, mixed> $previous @return array<string, mixed> */
    public function update(string $check, string $severity, array $previous, string $runId): array
    {
        $now = now()->timestamp;
        $failed = $severity !== 'ok';
        $state = [
            'failures' => $failed ? ($previous['failures'] ?? 0) + 1 : 0,
            'successes' => $failed ? 0 : ($previous['successes'] ?? 0) + 1,
            'open' => (bool) ($previous['open'] ?? false),
            'severity' => $severity,
            'notified_at' => (int) ($previous['notified_at'] ?? 0),
            'notified_severity' => $previous['notified_severity'] ?? 'ok',
        ];
        $suppressedUntil = config('operations.suppressed_until');
        if (is_string($suppressedUntil) && $suppressedUntil !== '' && strtotime($suppressedUntil) > $now) {
            $state['suppressed_until'] = $suppressedUntil;

            return $state;
        }
        $threshold = in_array($check, ['database', 'storage'], true) ? 2 : 1;
        $alert = $failed && $state['failures'] >= $threshold && (
            ! $state['open'] || $now - $state['notified_at'] >= 3600 || ($severity === 'critical' && $state['notified_severity'] !== 'critical')
        );
        $recovered = ! $failed && $state['open'] && $state['successes'] >= 2;
        if (! $alert && ! $recovered) {
            return $state;
        }
        if ($this->send($check, $recovered ? 'recovered' : $severity, $runId)) {
            $state['open'] = ! $recovered;
            $state['notified_at'] = $now;
            $state['notified_severity'] = $severity;
            OperationLog::write($recovered ? 'monitor.recovered' : 'monitor.failed', $recovered ? 'success' : 'failure', ['check' => $check], $recovered ? 'info' : 'warning');
        }

        return $state;
    }

    public function send(string $check, string $severity, string $runId): bool
    {
        $recipient = config('operations.recipient');
        $mailer = config('operations.mailer');
        if (! in_array($check, InternalMonitor::CHECKS, true) || ! in_array($severity, ['warning', 'critical', 'recovered', 'test'], true)
            || ! filter_var($recipient, FILTER_VALIDATE_EMAIL) || ! is_string($mailer)
            || config('mail.mailers.'.$mailer.'.transport') !== 'smtp') {
            OperationLog::write('monitor.notification_failed', 'failure', [], 'warning');

            return false;
        }
        try {
            Mail::mailer($mailer)->to($recipient)->send(new OperationAlert($check, $severity, $runId));

            return true;
        } catch (Throwable) {
            OperationLog::write('monitor.notification_failed', 'failure', [], 'warning');

            return false;
        }
    }
}
