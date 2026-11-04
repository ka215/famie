<?php

namespace App\Providers;

use App\Support\DestructiveDatabaseCommandGuard;
use App\Support\GuardedConnectionFactory;
use App\Support\SafeExceptionHandler;
use App\Support\SafeLogManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\Migrations\MigrateCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand;
use Illuminate\Database\Console\Migrations\RollbackCommand;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->extend('db.factory', fn ($factory, $app) => new GuardedConnectionFactory($app));
        foreach ([FreshCommand::class, RefreshCommand::class, ResetCommand::class, RollbackCommand::class, WipeCommand::class, MigrateCommand::class] as $class) {
            if ($class !== MigrateCommand::class) {
                $class::prohibit();
            }
            $this->app->bindMethod([$class, 'handle'], function ($command, $app) use ($class): int {
                $settings = $app['config'];
                $name = $command->option('database') ?: $settings->get('database.default');
                $connection = $settings->get('database.connections.'.$name, []);
                $profile = $settings->get('database-safety.profile');
                $approved = $settings->get('database-safety.profiles.'.$profile, []);
                DestructiveDatabaseCommandGuard::assertAllowed($command->getName(), (string) $settings->get('app.env'), $connection, $approved);
                if ($settings->get('app.env') === 'testing' || $settings->get('database-safety.test_run') || getenv('FAMIE_DB_TEST_RUN') === '1') {
                    DestructiveDatabaseCommandGuard::assertTestConnection((string) $settings->get('app.env'), $connection, $approved);
                }

                $destructive = in_array($command->getName(), DestructiveDatabaseCommandGuard::COMMANDS, true);
                if ($destructive) {
                    $class::prohibit(false);
                }
                try {
                    return (int) $command->handle();
                } finally {
                    if ($destructive) {
                        $class::prohibit();
                    }
                }
            });
        }
        $this->app->singleton('log', fn ($app) => new SafeLogManager($app));
        $this->app->singleton(ExceptionHandler::class, SafeExceptionHandler::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login-ip', fn (Request $request) => Limit::perMinute(config('famie.login_ip_per_minute'))->by('login-ip:'.$request->ip())
            ->response(fn (Request $request, array $headers) => response()->json(['message' => __('messages.login_ip_limited')], 429, $headers)));
        RateLimiter::for('account-mail-ip', fn (Request $request) => Limit::perMinute(10)->by('account-mail-ip:'.$request->ip()));
        RateLimiter::for('member-password-reset', fn (Request $request) => Limit::perMinutes(10, 5)->by('member-password-reset:'.$request->user()->id));
        RateLimiter::for('activity-likes', fn (Request $request) => Limit::perSecond(config('famie.like_rate_limit_per_second'))
            ->by('activity-likes:'.$request->user()->id)
            ->response(fn (Request $request, array $headers) => response()->json(['message' => __('messages.operation_limited')], 429, $headers)));
        RateLimiter::for('lifecycle-prepare', fn (Request $request) => Limit::perMinute(10)->by('lifecycle-prepare:v2:'.$request->user()->id));
        RateLimiter::for('lifecycle-commit', fn (Request $request) => [
            Limit::perMinutes(15, 5)->by('lifecycle-commit:user:'.$request->user()->id),
            Limit::perMinutes(15, 20)->by('lifecycle-commit:ip:'.$request->ip()),
        ]);
        RateLimiter::for('lifecycle-status', fn (Request $request) => [
            Limit::perMinute(30)->by('lifecycle-status:ip:'.$request->ip()),
            Limit::perMinute(10)->by('lifecycle-status:receipt:'.hash('sha256', (string) $request->input('receipt_id'))),
        ]);
        foreach ([
            'max_group_members',
            'like_rate_limit_per_second',
            'max_group_categories',
            'register_window_seconds',
            'register_max_attempts',
            'register_cooldown_seconds',
            'login_window_seconds',
            'login_max_attempts',
            'login_cooldown_seconds',
            'login_ip_per_minute',
            'lifecycle_prepare_minutes',
            'lifecycle_retention_days',
            'lifecycle_cleanup_batch',
        ] as $key) {
            if (! is_int(config("famie.{$key}")) || config("famie.{$key}") < 1) {
                throw new \LogicException("famie.{$key} must be a positive integer.");
            }
        }
    }
}
