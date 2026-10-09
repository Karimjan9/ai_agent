<?php

namespace App\Jobs;

use App\Models\ResearchLoopDecision;
use App\Services\AutonomousModeService;
use App\Services\CanonicalResearchLanePriorityService;
use App\Services\GenerationAutonomyReceiptService;
use App\Services\ScheduledArtisanProcessRunnerService;
use App\Services\ScheduledCommandOutcomeClassifierService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Isolates every scheduled Artisan command from the singleton clock. */
class RunScheduledArtisanCommandJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // A worker can be recycled after reserving an otherwise idempotent
    // command. Permit exactly one transport-level replay so the Redis
    // visibility retry is executed instead of being rejected before handle().
    public int $tries = 2;

    public int $timeout;

    public int $uniqueFor;

    public bool $failOnTimeout = true;

    /**
     * Resolve the queue lane used by routes/console.php.
     *
     * The constructor lane is the serialized progress path for the canonical
     * XAUUSD organism. Legacy EURUSD/GBPUSD generation and validation work is
     * intentionally isolated on the research lane so it cannot postpone an
     * admitted XAUUSD generation's lifecycle tick.
     *
     * @param  array<string|int,mixed>  $arguments
     */
    public static function scheduledLane(string $command, array $arguments = []): string
    {
        $critical = [
            'trading:pump-learning-lane',
            'trading:process-canonical-learning-outbox',
            'trading:reconcile-screening-learning-projections',
            'trading:reconcile-cooperative-settlements',
            'trading:reconcile-instrument-pairs',
            'trading:recover-lab-replay-mutex',
            'trading:promote-lab-frontier',
            'trading:dispatch-mtf-powered-prior-validation',
        ];
        $constructors = [
            'trading:run-research-loop',
            'trading:consume-research-work',
            'trading:process-targeted-generations',
            'trading:lab-generation',
            'trading:admit-academy-experiment',
            'trading:advance-learning-progress',
            'trading:run-lifecycle-cycle',
            'trading:dispatch-lab',
            'trading:dispatch-controlled-targeted-rescue',
            'trading:dispatch-full-validation',
            'trading:admit-academy-experiment',
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
            'trading:detect-drift',
            'trading:lab-incremental',
            'trading:study-lab-failures',
            'trading:compile-failure-signatures',
            'trading:compile-causal-skills',
            'trading:compile-strategic-research-plans',
            'trading:prepare-gene-interactions',
            'trading:lab-generation',
            'trading:lab-learn-from-history',
            'trading:process-screening-learning-outbox',
            'trading:process-dual-track-evidence',
            'trading:dispatch-full-validation',
            'trading:mtf-ablation',
            'trading:mtf-strategy-research',
            'trading:dispatch-mtf-research-cycle',
            'trading:mtf-research-report',
            'trading:validate-elite-portfolios',
            'trading:audit-agent-lifecycle',
        ];
        $symbol = strtoupper(str_replace(['/', '_', '-'], '', (string) (
            $arguments['symbol'] ?? $arguments['--symbol'] ?? $arguments[0] ?? 'XAUUSD'
        )));
        $shadowPopulationWork = in_array($command, [
            'trading:lab-generation',
            'trading:dispatch-full-validation',
        ], true) && $symbol !== 'XAUUSD';

        if (in_array($command, $constructors, true) && ! $shadowPopulationWork) {
            return 'scheduler-constructor';
        }
        if (in_array($command, $critical, true)) {
            return 'scheduler-critical';
        }

        return in_array($command, $research, true) ? 'scheduler-research' : 'scheduler-ops';
    }

    /** @param array<string,mixed> $arguments */
    public function __construct(
        public string $command,
        public array $arguments = [],
        public string $lane = 'scheduler-ops',
        public ?int $researchLoopDecisionId = null,
    ) {
        $this->lane = in_array($lane, ['scheduler-critical', 'scheduler-constructor', 'scheduler-research'], true)
            ? $lane
            : 'scheduler-ops';
        // Normal research commands remain bounded to 15 minutes. Commands
        // capable of compiling a full population receive the same longer
        // lease: twenty immutable seats can legitimately take 20-35 minutes
        // on the local evidence store and remain resumable after interruption.
        $fullPopulationConstructor = in_array($this->command, [
            'trading:run-research-loop',
            'trading:consume-research-work',
            'trading:run-lifecycle-cycle',
            'trading:process-targeted-generations',
            'trading:detect-drift',
            'trading:lab-generation',
            'trading:advance-learning-progress',
            'trading:dispatch-full-validation',
            // A cold immutable-history refresh may aggregate the legacy
            // candle plane once before its revision cache is warm. Keep that
            // bounded scan below the research worker lease, not the generic
            // 15-minute scheduler budget.
            'trading:lab-learn-from-history',
        ], true) || ($this->command === 'trading:dispatch-lab'
            && (bool) ($this->arguments['--learning-confirmation'] ?? false));
        $this->timeout = $this->lane === 'scheduler-critical'
            ? 180
            : ($fullPopulationConstructor ? 2400 : 900);
        $connection = (string) config('queue.default', 'redis');
        $retryAfter = max(0, (int) config("queue.connections.{$connection}.retry_after", 0));
        // Keep the uniqueness lease beyond Redis visibility. Otherwise a
        // killed worker can leave the first delivery reserved while a later
        // scheduler tick dispatches a duplicate of the same command.
        $this->uniqueFor = max($this->timeout + 300, $retryAfter + 300);
        $this->onConnection($connection);
        $this->onQueue($this->lane);
    }

    public function uniqueId(): string
    {
        return hash('sha256', $this->command.'|'.$this->canonicalArguments());
    }

    public function handle(
        ScheduledCommandOutcomeClassifierService $outcomes,
        ?CanonicalResearchLanePriorityService $priority = null,
        ?ScheduledArtisanProcessRunnerService $processRunner = null,
    ): void {
        $priority ??= app(CanonicalResearchLanePriorityService::class);
        $processRunner ??= app(ScheduledArtisanProcessRunnerService::class);
        $started = microtime(true);
        $key = $this->statusCacheKey();
        if ($this->researchLoopDecisionIsTerminal()) {
            // A Redis-reserved delivery may return long after its dead worker
            // was reconciled. Its immutable decision has been fenced as
            // failed/deferred, so executing the old command would create
            // unowned work. Complete this transport retry as an explicit no-op.
            Cache::put($key, $this->status('skipped_terminal_decision', $started, 'Stale delivery fenced by terminal research-loop decision.'), now()->addDay());

            return;
        }
        // A queued child from before PAUSE must not start a new bounded
        // research action after the operator has paused the lineage. The
        // decision closes as deferred; RESUME changes the durable control
        // revision and allows the arbiter to select the same work again.
        if (in_array($this->lane, ['scheduler-constructor', 'scheduler-research'], true)
            && in_array((string) data_get(app(AutonomousModeService::class)->status('XAUUSD', 'H1'), 'state'), ['pausing', 'paused', 'safety_halt'], true)) {
            Cache::put($key, $this->status('deferred', $started, 'Research run paused or safety-halted; no child command executed.'), now()->addDay());
            $this->transitionResearchLoopDecision('deferred');

            return;
        }
        Cache::put($key, $this->status('running', $started), now()->addDay());
        $this->transitionResearchLoopDecision('running');

        try {
            // Database-heavy research compilers and ordinary lifecycle work
            // must not compete with an active Edge state machine for CPU,
            // memory or generation authority. Their source rows are durable,
            // so completing this tick as deferred is lossless; cadence will
            // schedule them again after the canonical owner settles.
            if (in_array($this->lane, ['scheduler-constructor', 'scheduler-research'], true)) {
                $ownership = $priority->edgeGenesisOwnership('XAUUSD', 'H1');
                $arbiterOwned = in_array($this->command, [
                    'trading:run-research-loop',
                    'trading:consume-research-work',
                    'trading:dispatch-mtf-research-cycle',
                    'trading:dispatch-mtf-playbook-prior',
                    'trading:dispatch-portfolio-member-replay',
                    'trading:validate-elite-portfolios',
                    'trading:run-lifecycle-cycle',
                    'trading:admit-academy-experiment',
                ], true) || ($this->command === 'trading:advance-learning-progress'
                    && (bool) ($this->arguments['--arbiter-authorized'] ?? false));
                if (($ownership['owned'] ?? false) === true && ! $arbiterOwned) {
                    $detail = 'Deferred to canonical Edge Genesis; immutable research source preserved for the next scheduler tick.';
                    Cache::put($key, $this->status('deferred', $started, $detail, [
                        'protocol' => 'scheduled_research_priority_arbitration_v1',
                        'reason' => 'EDGE_GENESIS_OWNS_RESEARCH_RUNTIME',
                        'ownership' => $ownership,
                        'promotion_evidence' => false,
                    ]), now()->addDay());
                    Log::info('Scheduled research yielded to canonical Edge Genesis.', [
                        'command' => $this->command,
                        'arguments' => $this->arguments,
                        'lane' => $this->lane,
                        'ownership' => $ownership,
                        'promotion_evidence' => false,
                    ]);
                    $this->transitionResearchLoopDecision('deferred');

                    return;
                }
            }
            $executionArguments = $this->arguments;
            if (in_array($this->command, ['trading:admit-academy-experiment', \App\Services\UnusedDraftPriceDiscoveryPreparationService::COMMAND], true)) {
                $executionArguments['--research-loop-decision'] = (int) $this->researchLoopDecisionId;
            }
            if (app()->runningUnitTests()) {
                // Unit/feature tests may use an in-memory database and mock
                // Artisan. Production commands must run as bounded children:
                // PHP on Windows has no pcntl alarm, so queue --timeout alone
                // cannot interrupt an in-process CPU loop.
                $exitCode = Artisan::call($this->command, $executionArguments);
                $output = trim(Artisan::output());
            } else {
                $execution = $processRunner->run(
                    $this->command,
                    $executionArguments,
                    max(30, $this->timeout - 30),
                );
                $exitCode = $execution['exit_code'];
                $output = $execution['output'];
            }
            $outcome = $outcomes->classify($this->command, $this->arguments, $exitCode, $output);
            if ((bool) ($outcome['throw'] ?? true)) {
                throw new RuntimeException("Scheduled command {$this->command} returned exit code {$exitCode}: ".substr($output, 0, 1000));
            }
            Cache::put($key, $this->status((string) $outcome['status'], $started, $output, $outcome), now()->addDay());
            $decisionStatus = (string) ($outcome['status'] ?? '') === 'completed' ? 'completed' : 'deferred';
            $this->transitionResearchLoopDecision($decisionStatus);
            if ($decisionStatus === 'completed' && $this->researchLoopDecisionId) {
                try {
                    $decision = ResearchLoopDecision::query()->find($this->researchLoopDecisionId);
                    if ($decision) {
                        app(GenerationAutonomyReceiptService::class)
                            ->recordSuccessorDecision($decision);
                    }
                } catch (\Throwable $receiptError) {
                    // The command outcome remains authoritative. A failed
                    // autonomy seal is independently retried and must not
                    // rewrite or re-run a completed constructor decision.
                    report($receiptError);
                    try {
                        SealGenerationAutonomyReceiptJob::dispatch((int) $this->researchLoopDecisionId)
                            ->delay(now()->addSeconds(15));
                    } catch (\Throwable $dispatchError) {
                        report($dispatchError);
                    }
                }
            }
            if ($exitCode !== 0) {
                Log::notice('Isolated scheduled Artisan command reached an expected fail-closed outcome.', [
                    'command' => $this->command,
                    'arguments' => $this->arguments,
                    'lane' => $this->lane,
                    'outcome' => $outcome,
                    'output' => substr($output, 0, 1000),
                ]);
            }
        } catch (\Throwable $exception) {
            Cache::put($key, $this->status('failed', $started, $exception->getMessage()), now()->addDay());
            $this->transitionResearchLoopDecision('failed');
            Log::error('Isolated scheduled Artisan command failed.', [
                'command' => $this->command,
                'arguments' => $this->arguments,
                'lane' => $this->lane,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->transitionResearchLoopDecision('failed');
    }

    private function transitionResearchLoopDecision(string $status): void
    {
        if (! $this->researchLoopDecisionId) {
            return;
        }

        $decision = ResearchLoopDecision::query()->find($this->researchLoopDecisionId);
        if (! $decision
            || (string) $decision->command !== $this->command
            || (string) $decision->queue !== $this->lane
            || ! in_array((string) $decision->status, ['selected', 'dispatched', 'running'], true)) {
            return;
        }
        $terminal = in_array($status, ['completed', 'deferred', 'failed'], true);
        $decision->update([
            'status' => $status,
            'completed_at' => $terminal ? now() : null,
        ]);
    }

    /** @return array<string,mixed> */
    private function status(string $status, float $started, string $detail = '', array $outcome = []): array
    {
        return [
            'protocol' => 'isolated_scheduled_artisan_command_v1',
            'command' => $this->command,
            'lane' => $this->lane,
            'status' => $status,
            'pid' => getmypid(),
            'hostname' => (string) (gethostname() ?: php_uname('n')),
            'updated_at' => now()->toIso8601String(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'detail' => substr($detail, 0, 1500),
            'outcome' => $outcome,
            'promotion_evidence' => false,
        ];
    }

    public function statusCacheKey(): string
    {
        return 'system:scheduled-command:'.$this->uniqueId();
    }

    private function researchLoopDecisionIsTerminal(): bool
    {
        if (! $this->researchLoopDecisionId) {
            return false;
        }
        $decision = ResearchLoopDecision::query()->find($this->researchLoopDecisionId);

        return ! $decision || in_array((string) $decision->status, ['completed', 'deferred', 'failed', 'publication_failed'], true);
    }

    private function canonicalArguments(): string
    {
        $arguments = $this->arguments;
        ksort($arguments);

        return (string) json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
