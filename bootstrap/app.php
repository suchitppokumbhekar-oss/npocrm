<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

// Force Indian Timezone for all date/time operations
date_default_timezone_set('Asia/Kolkata');

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\TrackUserActivity::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\AuditNonLeadActions::class);
        $middleware->validateCsrfTokens(except: [
            'api/leads',
            'webhooks/meta/lead',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('followup:remind')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('followup:escalate')->everyFifteenMinutes();
        $schedule->command('leads:ensure-next-actions')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('notifications:push')->everyMinute()->withoutOverlapping();
    })
    ->create();