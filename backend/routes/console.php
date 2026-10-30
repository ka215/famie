<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (config('operations.enabled')) {
    Schedule::command('famie:monitor-internal')->hourly()->withoutOverlapping(5);
    Schedule::command('famie:run-operation backup')->dailyAt(config('operations.backup_time'))->timezone('Asia/Tokyo')->withoutOverlapping(15);
    Schedule::command('famie:run-operation image-deletions')->dailyAt('04:00')->timezone('Asia/Tokyo')->withoutOverlapping(15);
}
