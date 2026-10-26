<?php

namespace App\Support;

use App\Services\InternalMonitor;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Monolog\LogRecord;
use Throwable;

class SafeLogProcessor
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $event = in_array($record->message, OperationLog::EVENTS, true) ? $record->message : 'application.message';
        $context = [];
        if (($record->context['exception'] ?? null) instanceof Throwable) {
            $context['exception_type'] = $record->context['exception']::class;
            $event = 'application.exception';
        }
        foreach (['actor_id', 'group_id', 'target_id', 'deletion_id', 'status', 'count', 'duration_ms', 'exit_code'] as $key) {
            if (isset($record->context[$key]) && is_int($record->context[$key]) && $record->context[$key] >= 0) {
                $context[$key] = $record->context[$key];
            }
        }
        if (in_array($record->context['outcome'] ?? null, ['success', 'failure', 'started'], true)) {
            $context['outcome'] = $record->context['outcome'];
        }
        if (in_array($record->context['job'] ?? null, ['backup', 'image-deletions', 'monitor'], true)) {
            $context['job'] = $record->context['job'];
        }
        if (in_array($record->context['check'] ?? null, InternalMonitor::CHECKS, true)) {
            $context['check'] = $record->context['check'];
        }
        $exceptionType = $context['exception_type'] ?? $record->context['exception_type'] ?? null;
        if (is_string($exceptionType) && strlen($exceptionType) <= 200 && preg_match('/\A[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\z/D', $exceptionType)) {
            $context['exception_type'] = $exceptionType;
        } else {
            unset($context['exception_type']);
        }
        $request = app()->bound('request') ? app('request') : null;
        $id = $request instanceof Request ? $request->attributes->get('operation_request_id') : null;
        if (! is_string($id) || ! Str::isUuid($id)) {
            if (! app()->bound('operation.run_id')) {
                app()->instance('operation.run_id', (string) Str::uuid());
            }
            $id = app('operation.run_id');
            if ($request instanceof Request) {
                $request->attributes->set('operation_request_id', $id);
            }
        }
        $environment = config('operations.environment');
        $version = config('operations.version');
        $context = [
            'environment' => in_array($environment, ['local', 'testing', 'staging', 'production'], true) ? $environment : 'unknown',
            'version' => is_string($version) && preg_match('/\A\d+\.\d+\.\d+\z/D', $version) ? $version : 'unknown',
            'request_id' => $id,
            ...$context,
        ];

        return $record->with(message: $event, context: $context, extra: [], channel: 'famie');
    }
}
