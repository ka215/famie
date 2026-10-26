<?php

namespace App\Http\Middleware;

use App\Models\GroupMember;
use App\Support\OperationLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TraceRequest
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) Str::uuid();
        $request->attributes->set('operation_request_id', $id);
        $response = $next($request);
        $response->headers->set('X-Request-ID', $id);
        try {
            $this->record($request, $response);
        } catch (Throwable) {
            error_log('Famie request audit unavailable.');
        }

        return $response;
    }

    private function record(Request $request, Response $response): void
    {
        $status = $response->getStatusCode();
        $route = $request->route();
        $action = $route instanceof Route ? class_basename($route->getActionName()) : '';
        $operation = match ($action) {
            'AuthController@login' => $status < 400 ? 'auth.login_succeeded' : 'auth.login_failed',
            'AuthController@logout' => 'auth.logout',
            'AuthController@updatePassword' => 'auth.password_changed',
            'PasswordResetController@store' => 'auth.recovery_requested',
            'PasswordResetController@update' => 'auth.password_reset',
            'EmailController@store', 'EmailController@resend', 'EmailController@verify', 'EmailController@destroy' => 'auth.email_changed',
            'UserController@store' => 'admin.member_created',
            'UserController@update' => 'admin.member_updated',
            'UserController@updateStatus' => 'admin.member_status_changed',
            'UserController@resetPassword' => 'admin.member_password_reset',
            'GroupController@update' => 'admin.group_updated',
            'CategoryController@store' => 'admin.category_created',
            'CategoryController@update' => 'admin.category_updated',
            'CategoryController@destroy' => 'admin.category_deleted',
            'RewardController@store' => 'admin.reward_created',
            'RewardController@update' => 'admin.reward_updated',
            'RewardController@destroy' => 'admin.reward_deleted',
            'RewardController@status' => 'admin.reward_status_changed',
            'RewardController@prepare' => 'admin.reward_prepared',
            'LoginSecurityAlertController@read' => 'admin.alert_read',
            default => null,
        };
        $event = match (true) {
            $status === 429 => 'security.rate_limited',
            $status === 401 => 'security.unauthenticated',
            $status === 403 => 'security.permission_denied',
            $status === 404 && $request->user() !== null && $request->is('v1/groups/*') => 'security.resource_denied',
            $status >= 500 => $request->attributes->get('operation_exception_reported') ? null : 'http.server_error',
            default => $operation,
        };
        if ($event === null) {
            return;
        }
        $context = ['status' => $status];
        if ($request->user() !== null && $action !== 'AuthController@login') {
            $context['actor_id'] = (int) $request->user()->getAuthIdentifier();
            $membership = $request->attributes->get('group_membership');
            if ($membership instanceof GroupMember) {
                $context['group_id'] = (int) $membership->group_id;
                foreach (['member', 'category', 'reward'] as $parameter) {
                    $target = $request->route($parameter);
                    if (is_numeric($target) && ctype_digit((string) $target) && strlen((string) $target) < 19) {
                        $context['target_id'] = (int) $target;
                        break;
                    }
                }
            }
        }
        OperationLog::write($event, $status < 400 ? 'success' : 'failure', $context, $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info'));
    }
}
