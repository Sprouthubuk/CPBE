<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schedule as Scheduler;
use Sprouthub\Verity\Facades\Verity;

// Verity: publish, close, draw (on drand), redraws, audit chain checks and anchors.
Verity::schedule(app(Schedule::class));

Scheduler::command('orders:sweep')->everyFiveMinutes()->withoutOverlapping();
Scheduler::command('players:housekeeping')->everyFifteenMinutes()->withoutOverlapping();
Scheduler::command('platform:memberships')->everyFifteenMinutes()->withoutOverlapping();
Scheduler::command('platform:reports')->hourly()->withoutOverlapping();
Scheduler::command('platform:conversion --recover')->everyFifteenMinutes()->withoutOverlapping();
Scheduler::command('platform:conversion --segments --ad-spend')->dailyAt('04:15')->withoutOverlapping();
Scheduler::command('platform:end-prize-rules')->everyMinute()->withoutOverlapping();
Scheduler::command('platform:meta-events')->everyMinute()->withoutOverlapping();
