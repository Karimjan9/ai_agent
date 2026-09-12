<?php

use App\Jobs\AdvanceIntradayTrainingArchiveJob;
use App\Jobs\RunScheduledArtisanCommandJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$scheduleArtisan = static function (string $command, array $arguments = []) {
    // The singleton owns cadence only. Every command runs under a unique,
    // bounded worker so a slow provider/audit cannot freeze the clock.
    // Critical causal-loop dispatches get a private lane and therefore
    // cannot be starved by unrelated operational work.
    $critical = [
        'trading:pump-learning-lane',
        'trading:process-canonical-learning-outbox',
        'trading:reconcile-screening-learning-projections',
        'trading:recover-lab-replay-mutex',
        'trading:promote-lab-frontier',
        'trading:dispatch-mtf-powered-prior-validation',
    ];
    // A full population constructor must not wait behind the general research
    // compiler backlog. This lane is still single-concurrency and every entry
    // point additionally shares the canonical population mutex.
    $constructors = [
        'trading:run-research-loop',
        'trading:consume-research-work',
        'trading:process-targeted-generations',
        'trading:detect-drift',
        'trading:lab-generation',
        'trading:advance-learning-progress',
        'trading:run-lifecycle-cycle',
        // These commands may all enter LabPopulationService::build(). Keep
        // every autonomous XAUUSD population writer on one worker, including
        // normally read-mostly dispatch/recovery entry points.
        'trading:dispatch-lab',
        'trading:lab-incremental',
        'trading:dispatch-controlled-targeted-rescue',
        // Dataset sealing, consistency checks and candidate export regularly
        // exceed the short critical budget. Serialize this heavy coordinator
        // with generation construction so the two cannot race for snapshots.
        'trading:dispatch-full-validation',
    ];
    $research = [
        'market-data:backfill-intraday-shadow',
        'market-data:repair-intraday-shadow-gaps',
        'market-data:backfill-training',
        'market-data:audit',
        'meta:audit',
        'causal:discover',
        'theory:generate',
        'reality:verify',
        'trading:study-lab-failures',
        'trading:compile-failure-signatures',
        'trading:compile-causal-skills',
        'trading:compile-strategic-research-plans',
        'trading:prepare-gene-interactions',
        // Scheduled direct generations are EURUSD/GBPUSD shadow research.
        // Keep them off the XAUUSD constructor lane so the top-of-hour batch
        // cannot delay the canonical organism lifecycle.
        'trading:lab-generation',
        'trading:lab-learn-from-history',
        'trading:process-screening-learning-outbox',
        'trading:process-dual-track-evidence',
        'trading:mtf-ablation',
        'trading:mtf-strategy-research',
        'trading:dispatch-mtf-research-cycle',
        'trading:mtf-research-report',
        'trading:validate-elite-portfolios',
        'trading:audit-agent-lifecycle',
    ];
    $shadowDirectGeneration = $command === 'trading:lab-generation'
        && strtoupper((string) ($arguments[0] ?? 'XAUUSD')) !== 'XAUUSD';
    $lane = in_array($command, $constructors, true) && ! $shadowDirectGeneration
        ? 'scheduler-constructor'
        : (in_array($command, $critical, true)
            ? 'scheduler-critical'
            : (in_array($command, $research, true) ? 'scheduler-research' : 'scheduler-ops'));

    return Schedule::job(
        new RunScheduledArtisanCommandJob($command, $arguments, $lane),
        $lane,
        config('queue.default', 'redis'),
    )->name($command.':'.md5(json_encode($arguments)));
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
$scheduleArtisan('market-data:update', ['--symbol' => 'XAUUSD', '--timeframe' => 'H1', '--limit' => 1000])
    ->hourly()
    ->withoutOverlapping();
// M15 is an entry stream for every active instrument, not an EURUSD-only
// side feed. H1 remains the slower regime/baseline stream below.
$scheduleArtisan('market-data:update', ['--symbol' => 'XAUUSD', '--timeframe' => 'M15', '--limit' => 500])
    ->everyFifteenMinutes()
    ->withoutOverlapping();
// XAUUSD M1 is retained as a BID-only shadow plane. M5/M30 are rebuilt only
// from that same source, and the command keeps the M1 execution gate closed
// until independent bid/ask, cost and latency evidence is available.
$scheduleArtisan('market-data:backfill-intraday-shadow', ['--symbol' => 'XAUUSD'])
    ->everyFifteenMinutes()
    ->withoutOverlapping();
// Any primary-feed M1 hole is repaired only after a day-level source
// agreement check; accepted repairs also rebuild their derived M5/M30 bars.
$scheduleArtisan('market-data:repair-intraday-shadow-gaps', ['--symbol' => 'XAUUSD'])
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
// Pre-2026 intraday foundation is a second, strictly isolated research store.
// It advances in small checkpoints so multi-million M1 rows never block the
// paper lane or mingle with 2026 forward evidence.
Schedule::job(
    new AdvanceIntradayTrainingArchiveJob,
    config('services.lab_queue.market_maintenance_queue', 'market-maintenance'),
    config('queue.default', 'redis'),
)
    // Scheduler only dispatches. The unique maintenance worker owns the
    // bounded provider/DB work, so learning heartbeats cannot be blocked by
    // a slow historical day and repeated ticks cannot create a backlog.
    ->everyMinute()
    ->name('market-data:backfill-intraday-training-dispatch')
    ->withoutOverlapping();
$scheduleArtisan('market-data:audit', ['symbol' => 'XAUUSD', '--timeframe' => 'H1'])
    ->hourlyAt(10)
    ->withoutOverlapping();
$scheduleArtisan('market-data:audit', ['symbol' => 'XAUUSD', '--timeframe' => 'M15'])
    ->everyFifteenMinutes()
    ->withoutOverlapping();
$scheduleArtisan('market-data:audit', ['symbol' => 'XAUUSD', '--timeframe' => 'M1'])
    ->everyFifteenMinutes()
    ->withoutOverlapping();
$scheduleArtisan('market-data:audit', ['symbol' => 'XAUUSD', '--timeframe' => 'M5'])
    ->everyFifteenMinutes()
    ->withoutOverlapping();
$scheduleArtisan('market-data:audit', ['symbol' => 'XAUUSD', '--timeframe' => 'M30'])
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
// XAUUSD generation ownership belongs exclusively to the lifecycle
// orchestrator below. Keep this legacy cadence only for non-XAUUSD research.
foreach (['EURUSD', 'GBPUSD'] as $shadowSymbol) {
    $scheduleArtisan('trading:lab-generation', [0 => $shadowSymbol])
        ->hourlyAt(0)
        ->withoutOverlapping();
}
// Legacy non-XAUUSD research symbols may retain M15 shadow populations.
// XAUUSD is deliberately excluded: M15 is a role inside its one organism.
foreach (['EURUSD', 'GBPUSD'] as $shadowSymbol) {
    $scheduleArtisan('trading:lab-generation', [0 => $shadowSymbol, '--timeframe' => 'M15'])
        ->hourlyAt(15)
        ->withoutOverlapping();
}
// The lifecycle orchestrator owns XAUUSD population creation and directly
// dispatches every missing screening seat. The old generic H1/M15 dispatches
// were redundant; the M15 variant also aliased back to XAUUSD's H1 storage
// key and could race the ten-minute causal constructor. Shadow laboratories
// remain research-only and are not screened through the canonical organism.
// Non-XAUUSD shadow labs retain their independent M15 validation cadence.
// XAUUSD's inactive M15 archive is skipped: its M15 setup/confirmation and M5
// execution evidence travels inside the single H1-keyed organism generation.
// Never substitute H1 prices for lower-timeframe inputs or promote stale data.
// The XAUUSD arbiter/lifecycle owns both screening and full-validation
// settlement. Preserve only explicit non-XAUUSD shadow validation cadences.
foreach (['EURUSD', 'GBPUSD'] as $shadowSymbol) {
    $scheduleStaggeredFive('trading:dispatch-full-validation', [0 => $shadowSymbol, '--timeframe' => 'H1'], 0);
    $scheduleStaggeredFive('trading:dispatch-full-validation', [0 => $shadowSymbol, '--timeframe' => 'M15'], 1);
}
// A single-seat pump retries only after the queue, shared replay mutex and AI
// evaluator are idle. Micro-confirmation is enforced inside dispatch. Fresh
// pairs are materialized by the screening projection; the scheduler does not
// rescan the entire historical response-map plane every five minutes.
// Exact-control learning pairs are a high-priority arbiter continuation.
$scheduleArtisan('trading:process-canonical-learning-outbox', ['--limit' => 25])
    ->everyMinute()
    ->withoutOverlapping();
// Turn one canonical provisional lesson at a time into a bounded causal
// experiment. Guided/blinded/control consume three reserved seats inside the
// ordinary twenty-seat organism cohort; the other seats keep exploring. Only
// the causal triplet receives full replay, and it cannot promote or trade.
// Direct XAUUSD learning-confirmation construction is disabled. The Research
// Loop Arbiter ranks this continuation against authority, MTF and lifecycle.
// Operator-facing monitor commands are intentionally disabled. They produce
// diagnostic artifacts and are not part of the unattended worker lane.
$scheduleArtisan('trading:reconcile-lab-funnel')
    // Scheduled ticks are dry-run only. A state-changing reconciliation
    // requires an explicit operator-approved CLI invocation after the queue
    // is empty.
    // One-minute probes let the Director prove two stable idle observations
    // without sleeping inside the scheduler. Exactly-once cohort identity
    // still limits materialization to one immutable research cohort.
    ->everyMinute()
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
// Portfolio-member replay is admitted by the arbiter after causal/MTF work.
// Durable XAUUSD targeted handoffs are consumed only after the Research Loop
// Arbiter selects them; this former direct schedule was a second writer.
// History learning is a read/append-only operation. It runs before the next
// generation planner and never changes a quality or paper gate.
$scheduleStaggeredFive('trading:lab-learn-from-history', [
    'symbol' => 'XAUUSD',
    '--timeframe' => 'H1',
], 1);
$scheduleStaggeredFive('trading:process-screening-learning-outbox', [], 2);
$scheduleStaggeredFive('trading:reconcile-screening-learning-projections', [
    '--limit' => 6,
    '--scheduled-sweep' => true,
    '--apply' => true,
], 2);
$scheduleStaggeredFive('trading:process-dual-track-evidence', ['--limit' => 10], 3);
$scheduleStaggeredFive('trading:recover-lab-evaluation-errors', [], 3);
// Scheduled ticks are dry-run only. Same-generation replay recovery is
// dispatched only after an operator approval and an empty lab queue.
$scheduleStaggeredFive('trading:recover-incomplete-lab-evidence', ['--limit' => 6, '--scheduled-sweep' => true], 4);
// Only the unified XAUUSD organism may be proposed by the rescue scheduler;
// H1 below is its legacy storage key, not its population or execution scope.
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
$scheduleArtisan('trading:recover-lab-replay-mutex', [
    '--force-stale' => true,
    '--stale-after' => 120,
    '--dry-run' => true,
    '--scheduled-sweep' => true,
])
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
$scheduleStaggeredFive('trading:monitor-lighthouse-loop', ['--symbol' => 'XAUUSD'], 4);
// XAUUSD MTF shadow outcomes reconcile M15 setup evidence with the organism's
// M5 execution contract; they never write promotion evidence.
$scheduleArtisan('trading:reconcile-mtf-shadow', ['--symbol' => 'XAUUSD', '--limit' => 50])
    ->everyFifteenMinutes()
    ->withoutOverlapping();
// The monitor records closed-H1 regime alignment, M15 setup freshness, Risk
// Sentinel behavior, passport integrity, paper lifecycle and ablation-control
// health inside the one XAUUSD organism. Production execution remains M5.
// It is read-only with respect to strategy and gates.
$scheduleArtisan('trading:monitor-mtf-pilot', ['--symbol' => 'XAUUSD'])
    ->everyFifteenMinutes()
    ->withoutOverlapping();
// Keep the best rejected near-miss candidates visible in the shadow twin;
// idempotency prevents duplicate observations for the same candle/scenario.
$scheduleArtisan('trading:mtf-shadow-candidates', ['--symbol' => 'XAUUSD', '--limit' => 3])
    ->hourlyAt(50)
    ->withoutOverlapping();
// Daily ablation remains a recovery checkpoint. The current-cohort dispatcher
// below normally creates it on demand and then advances bounded hypotheses.
// MTF control sealing is an arbiter-owned experiment family.
// START authorizes one bounded current-cohort action per tick: seal an exact
// control first, then spend at most four seats on highest economic-information
// hypotheses. The child runs in the hidden research lane and cannot promote.
// Economic-information batches are selected by the same arbiter.
// The report remains diagnostic and never changes a strategy or gate.
$scheduleArtisan('trading:mtf-research-report', ['--symbol' => 'XAUUSD', '--lookback-hours' => 720])
    ->dailyAt('03:10')
    ->withoutOverlapping();
// Advance one research prior at a time without blocking the scheduler. The
// unique queued job sits behind full-validation, re-checks the current runtime
// identity at execution time and gives the five Confirmation & Entry models
// first research priority. These rows remain E1/prior-only and cannot promote.
// Playbook prior exploration is allocated by the Research Loop Arbiter.
// Reconcile a powered paper-shadow prior into model-owned historical evidence.
// Candidate and frozen control receive nine disjoint purged folds; even a
// supported E2 candidate remains unable to trade, parent or promote.
// Powered-prior settlement is allocated by the same arbiter.
// Edge -> attribution -> cartridge transfer -> authority incubation is one
// research state machine. This director opens at most one expensive cohort
// per tick, keeps 2026 paper data excluded and never bypasses safety/promotion
// authority. Expected WAIT outcomes are scheduler successes, not failures.
// The Director retains domain logic but receives apply authority only from
// the Research Loop Arbiter after one evidence-ranked action is selected.
// Academy arms settle through their immutable replay metrics. This runs on a
// separate, no-new-replay lane so active evaluations cannot postpone a
// terminal receipt or its durable next-work decision.
$scheduleArtisan('trading:reconcile-academy-experiments', [
    'symbol' => 'XAUUSD',
    '--timeframe' => 'H1',
    '--apply' => true,
    '--json' => true,
])
    ->everyMinute()
    ->withoutOverlapping();
// Edge Genesis uses a distinct evidence ledger from Academy. Once all its
// existing arms are terminal, close it through the same immutable receipt
// contract; this projection never queues another replay or grants authority.
$scheduleArtisan('trading:reconcile-edge-experiment-receipts', [
    'symbol' => 'XAUUSD',
    '--timeframe' => 'H1',
    '--apply' => true,
    '--json' => true,
])
    ->everyMinute()
    ->withoutOverlapping();
// Combined portfolio replay is likewise an arbiter-owned research action.
$scheduleStaggeredFive('trading:watch-lab-lifecycle', [], 0);
// The watchdog repairs only bounded abandoned replays.  This broader audit
// is read-only for agent/evidence state and records the complete lifecycle
// contract (population, lineage, queue, data, volume, evidence and gates).
// Keep the frequent monitor shallow so scheduler CPU is not consumed by a
// second full historical/volume scan while the replay lane is busy.
$scheduleArtisan('trading:audit-agent-lifecycle', ['symbol' => 'XAUUSD', '--shallow' => true])
    ->everyFifteenMinutes()
    ->withoutOverlapping();
// A deep lifecycle pass remains part of the operational cadence, but runs
// hourly and can never change an agent/gate/evidence status.
$scheduleArtisan('trading:audit-agent-lifecycle', ['symbol' => 'XAUUSD'])
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

// The single XAUUSD Research Loop Arbiter ranks closure repair, causal proof,
// MTF information work and the normal 20-seat lifecycle. Domain services may
// execute its selected action, but none is scheduled as a second autonomous
// new-work selector.
$scheduleArtisan('trading:run-research-loop', [
    '--symbol' => 'XAUUSD',
    '--json' => true,
])
    ->description('Single autonomous XAUUSD research-loop owner')
    ->everyMinute()
    ->withoutOverlapping();
// Database backups are written to the configured G: volume by the scheduled
// ops:backup-database task above. Never add a local C: dump fallback here.
// Gate-decision backfill is intentionally manual: it records reasons from
// existing immutable replay evidence and never changes promotion status.
