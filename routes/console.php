<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Alert admins + project managers about unassigned leads every 5 minutes
Schedule::command('leads:alert-unassigned')
    ->everyFiveMinutes()
    ->withoutOverlapping();