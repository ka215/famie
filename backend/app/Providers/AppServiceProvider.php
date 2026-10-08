<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('account-mail-ip', fn (Request $request) => Limit::perMinute(10)->by('account-mail-ip:'.$request->ip()));
        RateLimiter::for('activity-likes', fn (Request $request) => Limit::perSecond(config('famie.like_rate_limit_per_second'))
            ->by('activity-likes:'.$request->user()->id)
            ->response(fn (Request $request, array $headers) => response()->json(['message' => '操作が続いています。少し待ってから再試行してください。'], 429, $headers)));
        foreach ([
            'max_group_members',
            'like_rate_limit_per_second',
            'max_group_categories',
            'register_window_seconds',
            'register_max_attempts',
            'register_cooldown_seconds',
        ] as $key) {
            if (! is_int(config("famie.{$key}")) || config("famie.{$key}") < 1) {
                throw new \LogicException("famie.{$key} must be a positive integer.");
            }
        }
    }
}
