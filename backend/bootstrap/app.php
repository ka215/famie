<?php

use App\Http\Middleware\RestrictIpAddress;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $middleware->prepend(RestrictIpAddress::class);
        // API専用アプリのためゲストのリダイレクト先'login'ルートは存在しない
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (HttpException $exception): ?JsonResponse {
            if ($exception->getStatusCode() === 503 && app()->isDownForMaintenance()) {
                return response()->json([
                    'code' => 'maintenance',
                    'message' => 'ただいまメンテナンス中です。しばらくしてから再試行してください。',
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
    })->create();
