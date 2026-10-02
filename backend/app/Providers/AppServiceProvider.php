<?php

namespace App\Providers;

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
        foreach ([
            'max_group_members',
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
