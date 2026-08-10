<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$scheduleArtisan = static function (string $command, array $arguments = []) {
    // On Windows, Schedule::command() starts a separate console PHP process
    // for each due task, briefly flashing a CMD window. Running through the
    // scheduler process keeps the task headless under PM2.
    // A provider outage must not abort the entire minute's schedule. This is
    // especially important for optional intelligence feeds such as CurrentsAPI:
    // the feed becomes stale/failed, while market-data and lab tasks continue.
    return Schedule::call(static function () use ($command, $arguments): int {
        try {
            return Artisan::call($command, $arguments);
        } catch (\Throwable $exception) {
            $message = preg_replace(
                '/([?&](?:apiKey|apikey|key|token|access_token)=)[^&]+/i',
                '$1[REDACTED]',
                $exception->getMessage()
            ) ?: get_class($exception);

            Log::error('Scheduled artisan task failed; scheduler tick isolated.', [
                'command' => $command,
                'arguments' => $arguments,
                'exception_class' => get_class($exception),
                'message' => $message,
            ]);

            return 1;
        }
    })
        ->name($command.':'.md5(json_encode($arguments)));
};

$scheduleArtisan('trading:daily-report')->dailyAt('23:50');
// --symbol berilmasa, command barcha active market_symbols instrumentlarini yangilaydi.
$scheduleArtisan('market-data:update', ['--timeframe' => 'H1', '--limit' => 1000])
    ->hourly()
    ->withoutOverlapping();
$scheduleArtisan('market-data:update', ['--symbol' => 'EURUSD', '--timeframe' => 'M15', '--limit' => 500])
    ->everyFifteenMinutes()
    ->withoutOverlapping();
$scheduleArtisan('market-data:audit', ['--timeframe' => 'H1'])
    ->hourlyAt(10)
    ->withoutOverlapping();
$scheduleArtisan('market-data:audit', ['EURUSD', '--timeframe' => 'M15'])
    ->everyFifteenMinutes()
    ->withoutOverlapping();
$scheduleArtisan('trading:daily-workflow')
    ->dailyAt('00:30')
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
    $scheduleArtisan('meta:audit')->monthlyOn(1, '03:00')->withoutOverlapping();
    $scheduleArtisan('civilization:sync')->monthlyOn(1, '03:30')->withoutOverlapping();
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
    // Screening can finish after the old hourly selector has already run.
    // Poll for the newest eligible screened generation so a ready cohort is
    // picked up within one scheduler interval instead of waiting an hour.
    ->everyFiveMinutes()
    ->withoutOverlapping();
$scheduleArtisan('trading:dispatch-portfolio-member-replay')
    // This is a research-only second lane for strong niche members whose
    // broad standalone calendar gate failed. It never emits paper signals.
    ->hourlyAt(22)
    ->withoutOverlapping();
$scheduleArtisan('trading:process-targeted-generations')
    ->everyFiveMinutes()
    ->withoutOverlapping();
$scheduleArtisan('trading:lab-learn-from-history')
    // History learning is a read/append-only operation. It runs before the
    // next generation planner and never changes a quality or paper gate.
    ->everyFiveMinutes()
    ->withoutOverlapping();
$scheduleArtisan('trading:process-screening-learning-outbox')
    ->everyFiveMinutes()
    ->withoutOverlapping();
$scheduleArtisan('trading:recover-lab-evaluation-errors')
    ->everyFiveMinutes()
    ->withoutOverlapping();
$scheduleArtisan('trading:recover-stale-lab-batches', ['--older-than' => 180, '--limit' => 50])
    ->everyFiveMinutes()
    ->withoutOverlapping();
$scheduleArtisan('trading:recover-lab-replay-mutex')
    ->everyMinute()
    ->withoutOverlapping();
$scheduleArtisan('trading:paper-monitor')
    ->everyFiveMinutes()
    ->withoutOverlapping();
$scheduleArtisan('trading:validate-elite-portfolios')
    // Individual forward validation remains the first gate. This replay is
    // idle until at least two strict members exist, then certifies the
    // combined routing interaction on the same canonical execution contract.
    ->hourlyAt(25)
    ->withoutOverlapping();
$scheduleArtisan('trading:watch-lab-lifecycle', ['--repair' => true])
    ->everyFiveMinutes()
    ->withoutOverlapping();
$scheduleArtisan('trading:sync-economic-calendar')
    ->everySixHours()
    ->withoutOverlapping();
$scheduleArtisan('trading:sync-official-us-calendar')
    // Paid FMP history can be unavailable (for example HTTP 402). This
    // immutable official-release fallback keeps historical USD calendar
    // alignment auditable without turning a missing provider into a pass.
    ->dailyAt('00:15')
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
// Database backups are managed outside this application. Do not create large
// local dumps on the trading host unless an operator explicitly runs the
// manual ops:backup-database command.
// Gate-decision backfill is intentionally manual: it records reasons from
// existing immutable replay evidence and never changes promotion status.
