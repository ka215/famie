<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

class OperationLog
{
    /** @var list<string> */
    public const EVENTS = [
        'application.exception', 'application.message', 'http.server_error',
        'auth.login_failed', 'auth.login_succeeded', 'auth.logout', 'auth.password_changed',
        'auth.password_reset', 'auth.recovery_requested', 'auth.email_changed',
        'security.unauthenticated', 'security.permission_denied', 'security.resource_denied',
        'security.rate_limited', 'security.login_locked',
        'admin.member_created', 'admin.member_updated', 'admin.member_status_changed',
        'admin.member_password_reset', 'admin.group_updated', 'admin.category_created',
        'admin.category_updated', 'admin.category_deleted', 'admin.reward_created',
        'admin.reward_updated', 'admin.reward_deleted', 'admin.reward_status_changed',
        'admin.reward_prepared', 'admin.alert_read',
        'mail.delivery_failed', 'mail.recipient_blocked', 'mail.logging_mailer_blocked',
        'image.deletion_failed', 'operation.started', 'operation.finished', 'operation.failed',
        'monitor.failed', 'monitor.recovered', 'monitor.notification_failed',
    ];

    /** @param array<string, mixed> $context */
    public static function write(string $event, string $outcome, array $context = [], string $level = 'info'): void
    {
        try {
            Log::channel('operations')->log($level, $event, ['outcome' => $outcome, ...$context]);
        } catch (Throwable) {
            error_log('Famie operation log unavailable.');
        }
    }
}
