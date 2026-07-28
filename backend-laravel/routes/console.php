<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$scheduleArtisan = static function (string $command, array $arguments = []) {
    // On Windows, Schedule::command() starts a separate console PHP process
    // for each due task, briefly flashing a CMD window. Running through the
    // scheduler process keeps the task headless under PM2.
    return Schedule::call(static fn (): int => Artisan::call($command, $arguments))
        ->name($command.':'.md5(json_encode($arguments)));
};

$scheduleArtisan('trading:daily-report')->dailyAt('23:50');
$scheduleArtisan('training:repair-metrics')->dailyAt('23:40')->withoutOverlapping();
// --symbol berilmasa, command barcha active market_symbols instrumentlarini yangilaydi.
$scheduleArtisan('market-data:update', ['--timeframe' => 'H1', '--limit' => 1000])
    ->hourly()
    ->withoutOverlapping();
$scheduleArtisan('market-data:audit', ['--timeframe' => 'H1'])
    ->hourlyAt(10)
    ->withoutOverlapping();
$scheduleArtisan('trading:daily-workflow')
    ->dailyAt('00:30')
    ->withoutOverlapping();
$scheduleArtisan('trading:evolve', ['--limit' => 5])
    ->weeklyOn(1, '01:30')
    ->withoutOverlapping();
$scheduleArtisan('system:health-check')
    ->everyFiveMinutes()
    ->withoutOverlapping();
$scheduleArtisan('market:health')
    ->everyMinute()
    ->withoutOverlapping();
$scheduleArtisan('profiles:refresh')
    ->dailyAt('01:15')
    ->withoutOverlapping();
$scheduleArtisan('market-intelligence:sync-cot', ['--limit' => 12])
    ->weeklyOn(5, '16:00')
    ->timezone('America/New_York')
    ->withoutOverlapping();
// A federal holiday can delay CFTC's normal Friday publication. Monday catches
// a delayed release without making COT part of intraday trading logic.
$scheduleArtisan('market-intelligence:sync-cot', ['--limit' => 12])
    ->weeklyOn(1, '16:00')
    ->timezone('America/New_York')
    ->withoutOverlapping();
if (config('services.secondary_intelligence.enabled', false)) {
    $scheduleArtisan('knowledge:mine')->monthlyOn(1, '04:00')->withoutOverlapping();
    $scheduleArtisan('future:simulate')->monthlyOn(1, '04:30')->withoutOverlapping();
    $scheduleArtisan('meta:audit')->monthlyOn(1, '03:00')->withoutOverlapping();
    $scheduleArtisan('civilization:sync')->monthlyOn(1, '03:30')->withoutOverlapping();
    $scheduleArtisan('laws:discover')->monthlyOn(1, '04:00')->withoutOverlapping();
    $scheduleArtisan('causal:discover')->monthlyOn(1, '04:30')->withoutOverlapping();
    $scheduleArtisan('theory:generate')->monthlyOn(1, '05:00')->withoutOverlapping();
    $scheduleArtisan('reality:verify')->dailyAt('05:30')->withoutOverlapping();
}

// Primary AI Learning cadence.
$scheduleArtisan('trading:lab-incremental')
    ->hourlyAt(40)
    ->withoutOverlapping();
$scheduleArtisan('trading:lab-generation')
    // New drift evidence is detected hourly. Build the corresponding
    // generation at the next hour so it can be screened five minutes later;
    // leaving this daily strands otherwise valid Generation drafts.
    ->hourlyAt(0)
    ->withoutOverlapping();
// Pair queues run only short screening. The expensive full validation is one
// global FIFO queue, which prevents the three markets from exhausting the
// shared Python service at the same time.
$scheduleArtisan('trading:dispatch-lab')
    ->hourlyAt(5)
    ->withoutOverlapping();
$scheduleArtisan('trading:dispatch-full-validation')
    ->hourlyAt(20)
    ->withoutOverlapping();
$scheduleArtisan('trading:paper-monitor')
    ->everyFiveMinutes()
    ->withoutOverlapping();
$scheduleArtisan('trading:watch-lab-lifecycle', ['--repair' => true])
    ->everyFiveMinutes()
    ->withoutOverlapping();
$scheduleArtisan('trading:sync-economic-calendar')
    ->everySixHours()
    ->withoutOverlapping();
$scheduleArtisan('trading:sync-economic-calendar --provider=alpha_vantage_news')
    // Alpha Vantage's free tier is limited to about 25 requests/day: four
    // calls/day keeps a large reserve for diagnostics and manual checks.
    ->everySixHours()
    ->withoutOverlapping();
$scheduleArtisan('trading:sync-economic-calendar --provider=currents_api_news')
    // CurrentsAPI has the larger daily allowance, so it can refresh every
    // hour and provide a current headline-risk veto.
    ->hourlyAt(8)
    ->withoutOverlapping();
$scheduleArtisan('trading:detect-drift')->hourlyAt(45)->withoutOverlapping();
$scheduleArtisan('trading:release-holdouts')->hourlyAt(40)->withoutOverlapping();
$scheduleArtisan('ops:backup-database', ['--retain' => 14])->dailyAt('02:15')->withoutOverlapping();
// Gate-decision backfill is intentionally manual: it records reasons from
// existing immutable replay evidence and never changes promotion status.
