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

// Five-minute tasks used to become due in one burst on Windows. Keep each
// command's cadence and overlap lock, but distribute read/append-only work
// across the five minute slots. State-changing tasks remain separate calls;
// this is scheduling pressure relief, not a semantic batch of gates.
$scheduleStaggeredFive = static function (string $command, array $arguments = [], int $offset = 0) use ($scheduleArtisan) {
    $offset = max(0, min(4, $offset));

    return $scheduleArtisan($command, $arguments)
        ->cron("{$offset}-59/5 * * * *")
        ->withoutOverlapping();
};

$scheduleArtisan('trading:daily-report')->dailyAt('23:50');
$scheduleArtisan('ops:backup-database', [
    '--retain' => config('database.backup.retention', 3),
])
    ->dailyAt(config('database.backup.schedule_time', '02:30'))
    ->withoutOverlapping();
// --symbol berilmasa, command barcha active market_symbols instrumentlarini yangilaydi.
$scheduleArtisan('market-data:update', ['--timeframe' => 'H1', '--limit' => 1000])
    ->hourly()
    ->withoutOverlapping();
// M15 is an entry stream for every active instrument, not an EURUSD-only
// side feed. H1 remains the slower regime/baseline stream below.
$scheduleArtisan('market-data:update', ['--timeframe' => 'M15', '--limit' => 500])
    ->everyFifteenMinutes()
    ->withoutOverlapping();
// Volume is a separate canonical feed. Keep its checkpoint cadence aligned
// with the entry stream so volume hypotheses do not silently run on an old
// tail while the price feed is fresh. The sync command is resumable and does
// not alter the no-volume control or promotion state.
$scheduleArtisan('market-data:sync-volume', ['symbol' => 'XAUUSD', '--timeframe' => 'M15', '--tail-hours' => 72])
    ->everyFifteenMinutes()
    ->withoutOverlapping();
$scheduleArtisan('market-data:sync-volume', ['symbol' => 'XAUUSD', '--timeframe' => 'H1', '--tail-hours' => 168])
    ->hourlyAt(12)
    ->withoutOverlapping();
// Long XAUUSD training history is deliberately backfilled in bounded,
// resumable chunks. It writes only market_training_candles and never replaces
// the canonical Twelve candle stream used by live/paper gates.
$scheduleArtisan('market-data:backfill-training', [
    '--symbol' => 'XAUUSD',
    '--timeframe' => 'M15',
    '--chunk-days' => 31,
    '--max-chunks' => 1,
    '--dataset' => 'foundation_10y',
    '--provider' => 'dukascopy',
    '--transport' => 'jetta',
])
    ->everyThirtyMinutes()
    ->withoutOverlapping();
$scheduleArtisan('market-data:backfill-training', [
    '--symbol' => 'XAUUSD',
    '--timeframe' => 'H1',
    '--chunk-days' => 31,
    '--max-chunks' => 1,
    '--dataset' => 'foundation_10y',
    '--provider' => 'dukascopy',
    '--transport' => 'jetta',
])
    ->everySixHours()
    ->withoutOverlapping();
$scheduleArtisan('market-data:audit', ['--timeframe' => 'H1'])
    ->hourlyAt(10)
    ->withoutOverlapping();
$scheduleArtisan('market-data:audit', ['--timeframe' => 'M15'])
    ->everyFifteenMinutes()
    ->withoutOverlapping();
$scheduleArtisan('trading:daily-workflow')
    ->dailyAt('00:30')
    ->withoutOverlapping();
$scheduleStaggeredFive('system:health-check', [], 0);
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
// M15 has its own population/evolution ledger. It may use the last CLOSED H1
// regime as context, but it must never inherit an H1 model as a parent.
$scheduleArtisan('trading:lab-generation', ['--timeframe' => 'M15'])
    ->hourlyAt(15)
    ->withoutOverlapping();
// Pair queues run only short screening. The expensive full validation is one
// global FIFO queue, which prevents the three markets from exhausting the
// shared Python service at the same time.
$scheduleArtisan('trading:dispatch-lab')
    ->hourlyAt(5)
    ->withoutOverlapping();
$scheduleArtisan('trading:dispatch-lab', ['--timeframe' => 'M15'])
    ->hourlyAt(20)
    ->withoutOverlapping();
// H1 remains the baseline/regime lane and M15 owns an independent
// entry/volume full-validation lane once its own foundation, fresh replay and
// closed-H1 evidence are ready. Never substitute H1 history for M15 prices or
// force a promotion from a stale/legacy screen.
$scheduleStaggeredFive('trading:dispatch-full-validation', [], 0);
    // Screening can finish after the old hourly selector has already run.
    // Poll for the newest eligible screened generation so a ready cohort is
    // picked up within one scheduler interval instead of waiting an hour.
// M15 now has an independent pre-2026 foundation and can enter the same
// sealed full-validation/council gates. Its H1 regime source is still passed
// separately by the evaluator and is always delayed until the H1 candle closes.
$scheduleStaggeredFive('trading:dispatch-full-validation', ['--timeframe' => 'M15'], 1);
// Research-only near-miss learning runs are admitted only with a paired
// control/parent/anchor baseline. The command defers while the serialized
// heavy lane is busy and never changes the promotion clock.
$scheduleStaggeredFive('trading:dispatch-learning-lane', [
    'XAUUSD',
    '--timeframe' => 'H1',
    '--limit' => 4,
], 2);
// A single-seat pump retries only after the queue, shared replay mutex and AI
// evaluator are idle. Micro-confirmation is enforced inside dispatch.
$scheduleArtisan('trading:pump-learning-lane', [
    'XAUUSD',
    '--timeframe' => 'H1',
    '--limit' => 1,
])
    ->everyMinute()
    ->withoutOverlapping();
// Operator-facing monitor commands are intentionally disabled. They produce
// diagnostic artifacts and are not part of the unattended worker lane.
$scheduleArtisan('trading:reconcile-lab-funnel')
    // Scheduled ticks are dry-run only. A state-changing reconciliation
    // requires an explicit operator-approved CLI invocation after the queue
    // is empty.
    ->everyFiveMinutes()
    ->withoutOverlapping();
$scheduleArtisan('trading:study-lab-failures', ['--persist' => true])
    // Failure grouping is a diagnostic learning step. It updates the
    // auditable study plane but never creates agents or relaxes a gate.
    ->everyFiveMinutes()
    ->withoutOverlapping();
$scheduleArtisan('trading:compile-failure-signatures', [
    'XAUUSD',
    '--timeframe' => 'H1',
])
    ->everyFiveMinutes()
    ->withoutOverlapping();
$scheduleArtisan('trading:compile-causal-skills', [
    'XAUUSD',
    '--timeframe' => 'H1',
    '--limit' => 500,
])
    ->everyFiveMinutes()
    ->withoutOverlapping();
$scheduleStaggeredFive('trading:compile-strategic-research-plans', [
    'XAUUSD',
    '--timeframe' => 'H1',
    '--limit' => 100,
], 4);
$scheduleArtisan('trading:prepare-gene-interactions', [
    'XAUUSD',
    '--timeframe' => 'H1',
    '--json' => true,
])
    ->everyFifteenMinutes()
    ->withoutOverlapping();
$scheduleArtisan('trading:dispatch-portfolio-member-replay')
    // This is a research-only second lane for strong niche members whose
    // broad standalone calendar gate failed. It never emits paper signals.
    ->hourlyAt(22)
    ->withoutOverlapping();
$scheduleStaggeredFive('trading:process-targeted-generations', [], 0);
// History learning is a read/append-only operation. It runs before the next
// generation planner and never changes a quality or paper gate.
$scheduleStaggeredFive('trading:lab-learn-from-history', [], 1);
$scheduleStaggeredFive('trading:process-screening-learning-outbox', [], 2);
$scheduleStaggeredFive('trading:process-dual-track-evidence', ['--limit' => 10], 3);
$scheduleStaggeredFive('trading:recover-lab-evaluation-errors', [], 3);
// Scheduled ticks are dry-run only. Same-generation replay recovery is
// dispatched only after an operator approval and an empty lab queue.
$scheduleStaggeredFive('trading:recover-incomplete-lab-evidence', ['--limit' => 6, '--scheduled-sweep' => true], 4);
// Only the XAUUSD H1 lighthouse may be proposed by the rescue scheduler.
// The tick is dry-run; creation still requires explicit operator approval.
$scheduleStaggeredFive('trading:dispatch-controlled-targeted-rescue', [
    'symbol' => 'XAUUSD',
    '--timeframe' => 'H1',
], 0);
// During the pause, retire only incomplete v1 handoffs that have no active
// agent or queued job. Completed cohorts and controlled rescue are untouched.
$scheduleStaggeredFive('trading:quarantine-stale-targeted-generations', ['--dry-run' => true], 1);
// Scheduled recovery is a dry-run. State-changing cancellation requires
// explicit --apply and operator approval after the queue has drained.
$scheduleStaggeredFive('trading:recover-stale-lab-batches', ['--older-than' => 180, '--limit' => 50, '--dry-run' => true], 2);
// A worker/process restart can leave a reserved job and the shared overlap
// lock behind. Run the explicit fail-safe path every minute; it only acts
// after the AI probe is idle and the reservation has exceeded the stale
// threshold, so a healthy long replay is never duplicated.
$scheduleArtisan('trading:recover-lab-replay-mutex', ['--force-stale' => true, '--stale-after' => 120, '--dry-run' => true])
    ->everyMinute()
    ->withoutOverlapping();
// Promote only incomplete evidence recoveries. This is an ordering change on
// the existing database job; it never duplicates work or adds a second AI
// replay worker. Ordinary screening remains backpressured while the frontier
// boundary is drained.
$scheduleArtisan('trading:promote-lab-frontier')
    ->everyMinute()
    ->withoutOverlapping();
$scheduleStaggeredFive('trading:paper-monitor', [], 3);
// This is the primary outcome monitor: it records the exact first missing
// milestone from reproducible candidate through reality feedback. It never
// creates a generation or promotes a paper candidate.
$scheduleStaggeredFive('trading:monitor-lighthouse-loop', ['--symbol' => 'XAUUSD', '--timeframe' => 'H1'], 4);
// XAUUSD MTF shadow outcomes are reconciled under the same next-M15 execution
// contract, but they never write official paper orders or promotion evidence.
$scheduleArtisan('trading:reconcile-mtf-shadow', ['--symbol' => 'XAUUSD', '--limit' => 50])
    ->everyFifteenMinutes()
    ->withoutOverlapping();
// The monitor records closed-H1 alignment, M15 freshness, Risk Sentinel
// behavior, passport integrity, paper lifecycle and ablation-control health.
// It is read-only with respect to strategy and gates.
$scheduleArtisan('trading:monitor-mtf-pilot', ['--symbol' => 'XAUUSD'])
    ->everyFifteenMinutes()
    ->withoutOverlapping();
// Keep the best rejected near-miss candidates visible in the shadow twin;
// idempotency prevents duplicate observations for the same candle/scenario.
$scheduleArtisan('trading:mtf-shadow-candidates', ['--symbol' => 'XAUUSD', '--limit' => 3])
    ->hourlyAt(50)
    ->withoutOverlapping();
// Four-lane ablation is research-only and costed with the sealed execution
// contract. Its immutable result is monitored, never used to auto-promote.
$scheduleArtisan('trading:mtf-ablation', ['--symbol' => 'XAUUSD'])
    ->dailyAt('02:20')
    ->withoutOverlapping();
// Strategy hypotheses are intentionally operator-triggered because each
// hypothesis is an expensive replay. The report itself is cheap and can run
// unattended without changing a strategy, gate, or paper state.
$scheduleArtisan('trading:mtf-research-report', ['--symbol' => 'XAUUSD', '--lookback-hours' => 720])
    ->dailyAt('03:10')
    ->withoutOverlapping();
$scheduleArtisan('trading:validate-elite-portfolios')
    // Individual forward validation remains the first gate. This replay is
    // idle until at least two strict members exist, then certifies the
    // combined routing interaction on the same canonical execution contract.
    ->hourlyAt(25)
    ->withoutOverlapping();
$scheduleStaggeredFive('trading:watch-lab-lifecycle', [], 0);
// The watchdog repairs only bounded abandoned replays.  This broader audit
// is read-only for agent/evidence state and records the complete lifecycle
// contract (population, lineage, queue, data, volume, evidence and gates).
// Keep the frequent monitor shallow so scheduler CPU is not consumed by a
// second full historical/volume scan while the replay lane is busy.
$scheduleArtisan('trading:audit-agent-lifecycle', ['--shallow' => true])
    ->everyFifteenMinutes()
    ->withoutOverlapping();
// A deep lifecycle pass remains part of the operational cadence, but runs
// hourly and can never change an agent/gate/evidence status.
$scheduleArtisan('trading:audit-agent-lifecycle')
    ->hourlyAt(35)
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
$scheduleArtisan('trading:sync-economic-calendar', ['--provider' => 'alpha_vantage_news'])
    // Alpha Vantage's free tier is limited to about 25 requests/day: four
    // calls/day keeps a large reserve for diagnostics and manual checks.
    ->everySixHours()
    ->withoutOverlapping();
$scheduleArtisan('trading:sync-economic-calendar', ['--provider' => 'currents_api_news'])
    // CurrentsAPI has the larger daily allowance, so it can refresh every
    // hour and provide a current headline-risk veto.
    ->hourlyAt(8)
    ->withoutOverlapping();
$scheduleArtisan('trading:detect-drift')->hourlyAt(45)->withoutOverlapping();
$scheduleArtisan('trading:release-holdouts')->hourlyAt(40)->withoutOverlapping();
// Database backups are written to the configured G: volume by the scheduled
// ops:backup-database task above. Never add a local C: dump fallback here.
// Gate-decision backfill is intentionally manual: it records reasons from
// existing immutable replay evidence and never changes promotion status.
