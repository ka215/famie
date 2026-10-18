<?php

use App\Http\Middleware\EnsureActiveGroupMembership;
use App\Http\Middleware\RestrictIpAddress;
use App\Http\Middleware\SetUiLocale;
use App\Support\UiLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trimStrings(except: ['new_password', 'new_password_confirmation']);
        $middleware->prepend([SetUiLocale::class, RestrictIpAddress::class]);
        $middleware->alias(['group.member' => EnsureActiveGroupMembership::class]);
        // API専用アプリのためゲストのリダイレクト先'login'ルートは存在しない
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (HttpException $exception): ?JsonResponse {
            if ($exception->getStatusCode() === 503 && app()->isDownForMaintenance()) {
                return response()->json([
                    'code' => 'maintenance',
                    'message' => __('errors.maintenance', [], UiLocale::fromHeader(request()->header('Accept-Language'))),
                ], 503, [
                    'Cache-Control' => 'no-store, max-age=0',
                    'Retry-After' => '60',
                ]);
            }

            return null;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('v1/*', 'api/*') || $request->expectsJson(),
        );
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if (! $response instanceof JsonResponse) {
                return $response;
            }
            $locale = $request->attributes->get('ui_locale', UiLocale::fromHeader($request->header('Accept-Language')));
            $translation = config('i18n.locales.'.$locale.'.translation', 'en');
            $data = $response->getData(true);
            $status = $response->getStatusCode();
            $key = match (true) {
                ($data['code'] ?? null) === 'maintenance' => 'maintenance',
                $status >= 500 => 'server',
                $status === 401 => 'unauthenticated',
                $status === 404 => 'not_found',
                $exception instanceof ValidationException => 'validation',
                $status === 429 && ($data['message'] ?? '') === 'Too Many Attempts.' => 'throttled',
                $status === 403 && ($data['message'] ?? '') === 'This action is unauthorized.' => 'forbidden',
                default => null,
            };
            if ($key !== null) {
                $data['message'] = __('errors.'.$key, [], $translation);
                if ($status >= 500) {
                    $data = array_intersect_key($data, array_flip(['message', 'code']));
                }
                $response->setData($data);
            }

            return UiLocale::decorate($response, $request, true);
        });
    })->create();
