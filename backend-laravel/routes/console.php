<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('trading:daily-report')->dailyAt('23:50');
Schedule::command('training:repair-metrics')->dailyAt('23:40')->withoutOverlapping()->runInBackground();
// --symbol berilmasa, command barcha active market_symbols instrumentlarini yangilaydi.
Schedule::command('market-data:update --timeframe=H1 --limit=1000')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
Schedule::command('market-data:audit --timeframe=H1')
    ->hourlyAt(10)
    ->withoutOverlapping()
    ->runInBackground();
Schedule::command('trading:daily-workflow')
    ->dailyAt('00:30')
    ->withoutOverlapping()
    ->runInBackground();
Schedule::command('trading:evolve --limit=5')
    ->weeklyOn(1, '01:30')
    ->withoutOverlapping()
    ->runInBackground();
Schedule::command('system:health-check')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
Schedule::command('market:health')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
Schedule::command('profiles:refresh')
    ->dailyAt('01:15')
    ->withoutOverlapping()
    ->runInBackground();
Schedule::command('market-intelligence:sync-cot --limit=12')
    ->weeklyOn(5, '16:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->runInBackground();
// A federal holiday can delay CFTC's normal Friday publication. Monday catches
// a delayed release without making COT part of intraday trading logic.
Schedule::command('market-intelligence:sync-cot --limit=12')
    ->weeklyOn(1, '16:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->runInBackground();
if (config('services.secondary_intelligence.enabled', false)) {
    Schedule::command('knowledge:mine')->monthlyOn(1, '04:00')->withoutOverlapping()->runInBackground();
    Schedule::command('future:simulate')->monthlyOn(1, '04:30')->withoutOverlapping()->runInBackground();
    Schedule::command('meta:audit')->monthlyOn(1, '03:00')->withoutOverlapping()->runInBackground();
    Schedule::command('civilization:sync')->monthlyOn(1, '03:30')->withoutOverlapping()->runInBackground();
    Schedule::command('laws:discover')->monthlyOn(1, '04:00')->withoutOverlapping()->runInBackground();
    Schedule::command('causal:discover')->monthlyOn(1, '04:30')->withoutOverlapping()->runInBackground();
    Schedule::command('theory:generate')->monthlyOn(1, '05:00')->withoutOverlapping()->runInBackground();
    Schedule::command('reality:verify')->dailyAt('05:30')->withoutOverlapping()->runInBackground();
}

// Primary AI Learning cadence.
Schedule::command('trading:lab-incremental')
    ->hourlyAt(40)
    ->withoutOverlapping()
    ->runInBackground();
Schedule::command('trading:lab-generation')
    // New drift evidence is detected hourly. Build the corresponding
    // generation at the next hour so it can be screened five minutes later;
    // leaving this daily strands otherwise valid Generation drafts.
    ->hourlyAt(0)
    ->withoutOverlapping()
    ->runInBackground();
// Pair queues run only short screening. The expensive full validation is one
// global FIFO queue, which prevents the three markets from exhausting the
// shared Python service at the same time.
Schedule::command('trading:dispatch-lab')
    ->hourlyAt(5)
    ->withoutOverlapping()
    ->runInBackground();
Schedule::command('trading:dispatch-full-validation --top=3')
    ->hourlyAt(20)
    ->withoutOverlapping()
    ->runInBackground();
Schedule::command('trading:paper-monitor')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
Schedule::command('trading:detect-drift')->hourlyAt(45)->withoutOverlapping()->runInBackground();
Schedule::command('trading:release-holdouts')->hourlyAt(40)->withoutOverlapping()->runInBackground();
Schedule::command('ops:backup-database --retain=14')->dailyAt('02:15')->withoutOverlapping();
