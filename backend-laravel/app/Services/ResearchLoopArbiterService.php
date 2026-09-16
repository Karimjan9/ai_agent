<?php

namespace App\Services;

use App\Jobs\RunScheduledArtisanCommandJob;
use App\Models\AgentLearningCausalExperiment;
use App\Models\CandidateHandoffEvent;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\MarketDriftSnapshot;
use App\Models\ModelMarketPerformance;
use App\Models\MtfStrategyResearchRun;
use App\Models\ResearchLoopDecision;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The only autonomous selector of new XAUUSD research work.
 *
 * Existing engines remain domain experts. The arbiter gives exactly one of
 * them ownership per tick from a frozen evidence snapshot, so locally valid
 * schedulers cannot race or starve the causal ladder.
 */
class ResearchLoopArbiterService
{
    public const PROTOCOL = 'research_loop_arbiter_v1';

    public const OWNER = self::class;

    private const HISTORICAL_LEARNING_MAINTENANCE_MINUTES = 30;

    private const ACTIVE_GENERATION_STATUSES = [
        'draft', 'queued', 'training', 'screening', 'full_queued', 'full_validation',
    ];

    private const CAUSAL_LADDER_ACTIONS = [
        'EDGE_DISCOVERY_RESUME',
        'AUTHORITY_DESCENDANT_PROOF',
        'AUTHORITY_INCUBATOR',
        'PROVISIONAL_SKILL_CARTRIDGE_CONFIRMATION',
        'EDGE_INDEPENDENT_REPLICATION',
        'EDGE_CONFIRMATION',
        'EDGE_ATTRIBUTION',
        'QUALITY_EVOLUTION_SYNTHESIS',
        'SKILL_CARTRIDGE_TRANSPLANT',
        'EDGE_ARCHITECTURE_REPAIR',
        'EDGE_HYPOTHESIS_COMPILED',
        'EDGE_GENESIS',
    ];

    public function __construct(
        private AutonomousModeService $autonomy,
        private ResearchClosureInvariantService $closure,
        private ResearchExperimentConversionKernelService $conversion,
        private LearningLaneService $learningLane,
        private AutonomousLearningProgressDirectorService $director,
        private MtfResearchCohortService $mtfCohorts,
        private MarketDriftDetectionService $drift,
    ) {}

    /** @return array<string,mixed> */
    public function tick(string $symbol = 'XAUUSD', string $timeframe = 'H1', bool $dryRun = false): array
    {
        $symbol = strtoupper(str_replace(['/', '_', '-'], '', trim($symbol)));
        $organism = strtoupper((string) config('services.xauusd_organism.symbol', 'XAUUSD'));
        $timeframe = strtoupper((string) config('services.xauusd_organism.laboratory_storage_timeframe', 'H1'));
        if ($symbol !== $organism) {
            return $this->blocked('XAUUSD_ORGANISM_SCOPE_REQUIRED');
        }
        if (! Schema::hasTable('research_loop_decisions')) {
            return $this->blocked('RESEARCH_LOOP_DECISION_TABLE_MISSING');
        }

        // A diagnostic dry-run must remain available while Redis/workers are
        // intentionally stopped. It cannot lease, persist or dispatch.
        if ($dryRun) {
            return $this->tickLocked($symbol, $timeframe, true);
        }
        try {
            $lock = Cache::lock('research-loop-arbiter:'.$symbol.':'.$timeframe, 300);
            if (! $lock->get()) {
                return $this->blocked('RESEARCH_LOOP_ARBITER_ALREADY_RUNNING');
            }
        } catch (Throwable $exception) {
            return $this->blocked('RESEARCH_LOOP_LOCK_UNAVAILABLE', ['error_class' => $exception::class]);
        }
        try {
            $recovery = app(StaleAutonomousWorkRecoveryService::class)
                ->reconcile($symbol, $timeframe);
            $result = $this->tickLocked($symbol, $timeframe, $dryRun);

            return ($recovery['status'] ?? null) === 'recovered'
                ? [...$result, 'stale_work_recovery' => $recovery]
                : $result;
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // The decision has already been persisted before dispatch;
                // an unavailable release backend cannot grant a second owner.
            }
        }
    }

    /** @return array<string,mixed> */
    private function tickLocked(string $symbol, string $timeframe, bool $dryRun): array
    {
        $latest = LabGeneration::query()->whereHas('laboratory', fn ($query) => $query->where('symbol', $symbol)->where('timeframe', $timeframe)
        )->orderByDesc('generation')->orderByDesc('id')->first();
        $generation = $latest ? [
            'id' => (int) $latest->id,
            'generation' => (int) $latest->generation,
            'status' => (string) $latest->status,
            'agents' => $latest->agents()->count(),
            'planned' => count((array) data_get($latest->trigger_context, 'generation_plan', [])),
        ] : null;

        // Already-admitted work settles before any new hypothesis. STOP is
        // drain-first, so this branch intentionally precedes the mode check.
        if ($latest && (in_array((string) $latest->status, self::ACTIVE_GENERATION_STATUSES, true)
            || LabPopulationService::constructionIncomplete($latest))) {
            return $this->decide($symbol, $timeframe, 'SETTLE_EXISTING_GENERATION', 100,
                'trading:run-lifecycle-cycle', ['--symbol' => $symbol, '--json' => true],
                'scheduler-constructor', ['EXISTING_GENERATION_OWNS_RESEARCH_RUNTIME'],
                ['generation' => $generation], $dryRun);
        }

        // A screened causal triplet is admitted work, not historical
        // backlog. It must receive its serialized full replay and settle
        // before another generation can spend the twenty-seat budget. This
        // also repairs an older terminal cohort if a newer active generation
        // was already admitted before this invariant existed.
        $openCausal = $this->openCausalReplay($symbol, $timeframe);
        if ($openCausal) {
            $armIds = array_values(array_filter([
                $openCausal->guided_agent_id,
                $openCausal->blinded_agent_id,
                $openCausal->control_agent_id,
            ], fn (mixed $id): bool => (int) $id > 0));
            $replayActive = LabAgent::query()->whereIn('id', $armIds)
                ->whereIn('lifecycle_status', ['full_queued', 'full_validation', 'training'])
                ->exists();
            if ($replayActive) {
                return $this->decide($symbol, $timeframe, 'WAIT_CAUSAL_CONFIRMATION_REPLAY', 99,
                    null, [], null, ['CAUSAL_CONFIRMATION_REPLAY_IN_FLIGHT'], [
                        'generation' => $generation,
                        'causal_experiment_id' => (int) $openCausal->id,
                        'causal_generation_id' => (int) $openCausal->lab_generation_id,
                        'causal_status' => (string) $openCausal->status,
                        'arm_agent_ids' => $armIds,
                    ], $dryRun);
            }

            return $this->decide($symbol, $timeframe, 'SETTLE_CAUSAL_CONFIRMATION_REPLAY', 99,
                'trading:dispatch-full-validation', [0 => $symbol, '--timeframe' => $timeframe],
                'scheduler-research', ['UNSETTLED_CAUSAL_CONFIRMATION_OWNS_RESEARCH_RUNTIME'], [
                    'generation' => $generation,
                    'causal_experiment_id' => (int) $openCausal->id,
                    'causal_generation_id' => (int) $openCausal->lab_generation_id,
                    'causal_status' => (string) $openCausal->status,
                    'arm_agent_ids' => $armIds,
                ], $dryRun);
        }

        $closure = $this->closure->inspect($symbol, $timeframe, ! $dryRun);
        $repair = (array) ($closure['next_repair'] ?? []);
        if (($closure['healthy'] ?? false) !== true) {
            if (filled($repair['command'] ?? null)) {
                return $this->decide($symbol, $timeframe, (string) $repair['action'], 95,
                    (string) $repair['command'], (array) ($repair['arguments'] ?? []),
                    'scheduler-critical', ['RESEARCH_CLOSURE_PROJECTION_DEBT'],
                    ['generation' => $generation, 'closure' => $this->compactClosure($closure)], $dryRun);
            }

            return $this->decide($symbol, $timeframe, 'DEFER_INVALID_RESEARCH_CLOSURE', 95,
                null, [], null, ['RESEARCH_CLOSURE_INVARIANT_FAILED'],
                ['generation' => $generation, 'closure' => $this->compactClosure($closure)], $dryRun);
        }

        $mode = $this->autonomy->status($symbol, $timeframe);
        if (! (bool) data_get($mode, 'enabled', false)) {
            return $this->decide($symbol, $timeframe, 'DEFER_AUTONOMY_STOPPED', 90,
                null, [], null, ['AUTONOMOUS_MODE_STOPPED'],
                ['generation' => $generation, 'closure' => $this->compactClosure($closure),
                    'controller_profile' => data_get($mode, 'controller_profile')], $dryRun);
        }

        [$currentLearningPair, $historicalLearningPair, $priorityLearningPair] =
            $this->learningCurriculum($symbol, $timeframe, $latest);
        if ($currentLearningPair) {
            return $this->decide($symbol, $timeframe, 'PUMP_CANONICAL_LEARNING_PAIR', 93,
                'trading:pump-learning-lane', [0 => $symbol, '--timeframe' => $timeframe,
                    '--limit' => 1, '--pair-id' => (int) $currentLearningPair->id, '--autonomous' => true],
                'scheduler-critical', [$priorityLearningPair?->is($currentLearningPair)
                        ? 'VERIFIED_POSITIVE_LEARNING_PAIR_READY'
                        : 'CURRENT_GENERATION_EXACT_CONTROL_PAIR_READY'], [
                            'generation' => $generation, 'closure' => $this->compactClosure($closure),
                            'pair_id' => (int) $currentLearningPair->id,
                            'candidate_agent_id' => (int) $currentLearningPair->candidate_agent_id,
                            'pair_status' => (string) $currentLearningPair->status,
                            'curriculum_scope' => 'latest_generation',
                        ], $dryRun);
        }

        // Generation admission gives a target-aligned causal lesson priority
        // over drift/degradation exploration. Route that exact requirement
        // through the lifecycle owner, which seals the guided/blinded/frozen
        // control cohort. Previously the arbiter selected market drift first,
        // the constructor correctly answered DISPATCH_LEARNING, and both
        // services repeated that disagreement every three minutes forever.
        $causalLesson = app(CausalLearningCohortPlannerService::class)->eligibleLesson($symbol, $timeframe);
        if ($causalLesson) {
            return $this->decide($symbol, $timeframe, 'OPEN_CAUSAL_LEARNING_CONFIRMATION', 92,
                'trading:run-lifecycle-cycle', ['--symbol' => $symbol, '--json' => true],
                'scheduler-constructor', ['TARGET_ALIGNED_CAUSAL_LESSON_HAS_GENERATION_PRIORITY'], [
                    'generation' => $generation,
                    'closure' => $this->compactClosure($closure),
                    'lesson' => [
                        'id' => (int) $causalLesson->id,
                        'family' => (string) $causalLesson->strategy_family,
                        'target' => (string) $causalLesson->failure_class,
                        'gene_key' => (string) $causalLesson->parameter_key,
                    ],
                ], $dryRun);
        }

        // Durable evidence-earned work outranks fresh exploration. Dry-run
        // may inspect but must never acquire a lease.
        $work = $dryRun ? null : collect($this->conversion->claimForOwner(self::OWNER, 1))->first();
        if ($work) {
            return $this->decide($symbol, $timeframe, 'CONSUME_DURABLE_NEXT_WORK', 90,
                'trading:consume-research-work', [
                    0 => (int) $work->id,
                    '--lease-token' => (string) $work->lease_token,
                    '--fence' => (int) $work->fence_version,
                    '--json' => true,
                ], 'scheduler-constructor', ['OWNED_DURABLE_CONTINUATION_READY'], [
                    'generation' => $generation,
                    'closure' => $this->compactClosure($closure),
                    'work_item' => ['id' => (int) $work->id, 'type' => (string) $work->work_type,
                        'priority' => (int) $work->priority, 'receipt_id' => (int) $work->research_experiment_receipt_id],
                ], false);
        }

        $targetedRequest = CandidateHandoffEvent::query()->where('stage', 'waiting_for_targeted_generation')
            ->where('status', 'waiting')
            ->whereHas('generation.laboratory', fn ($query) => $query->where('symbol', $symbol)
                ->where('timeframe', $timeframe)->where('is_active', true))
            ->latest('id')->get()
            ->first(fn (CandidateHandoffEvent $event): bool => $event->targetedGenerationRetryDue());
        if ($targetedRequest) {
            return $this->decide($symbol, $timeframe, 'CONSUME_TARGETED_GENERATION_REQUEST', 89,
                'trading:process-targeted-generations', [], 'scheduler-constructor',
                ['DURABLE_TARGETED_HANDOFF_READY'], [
                    'generation' => $generation, 'closure' => $this->compactClosure($closure),
                    'candidate_handoff_event_id' => (int) $targetedRequest->id,
                    'source_generation_id' => (int) $targetedRequest->lab_generation_id,
                ], $dryRun);
        }

        // A confirmed operational change is a high-value trigger, but the
        // arbiter—not the detector—owns its generation admission.
        $degraded = ModelMarketPerformance::query()->where('symbol', $symbol)
            ->where('status', 'champion')->where('consecutive_no_improvement', '>=', 3)->exists();
        if ($degraded) {
            return $this->decide($symbol, $timeframe, 'OPEN_DEGRADATION_RESEARCH', 88,
                'trading:lab-generation', [0 => $symbol, '--timeframe' => $timeframe, '--trigger' => 'degradation'],
                'scheduler-constructor', ['CONFIRMED_CHAMPION_DEGRADATION'],
                ['generation' => $generation, 'closure' => $this->compactClosure($closure)], $dryRun);
        }
        $drift = $this->drift->confirmation($symbol, $timeframe);
        if (($drift['status'] ?? null) === 'confirmed' && ! $this->driftAlreadyConsumed($latest, $drift)) {
            return $this->decide($symbol, $timeframe, 'OPEN_MARKET_DRIFT_RESEARCH', 87,
                'trading:lab-generation', [0 => $symbol, '--timeframe' => $timeframe, '--trigger' => 'market_drift'],
                'scheduler-constructor', ['CONFIRMED_CANONICAL_MARKET_DRIFT'],
                ['generation' => $generation, 'closure' => $this->compactClosure($closure), 'drift' => $drift], $dryRun);
        }

        // Planning-only crosses the old feature-flag boundary but cannot
        // mutate. The selected child command re-checks every runtime/safety
        // admission before it receives apply authority.
        $director = $this->director->advance($symbol, $timeframe, false, true, true);
        $directorAction = (string) ($director['action'] ?? '');
        if (in_array($directorAction, self::CAUSAL_LADDER_ACTIONS, true)
            && in_array((string) data_get($director, 'result.status'), ['would_queue', 'queued'], true)) {
            return $this->decide($symbol, $timeframe, $directorAction, $this->directorPriority($directorAction),
                'trading:advance-learning-progress', [0 => $symbol, '--timeframe' => $timeframe,
                    '--apply' => true, '--arbiter-authorized' => true, '--json' => true],
                'scheduler-constructor', ['CAUSAL_LADDER_ACTION_READY'], [
                    'generation' => $generation, 'closure' => $this->compactClosure($closure),
                    'director' => $this->compactDirector($director),
                ], $dryRun);
        }

        // Give the unified H1/M15/M5 organism one bounded economic-information
        // batch per 30-minute allocation window; the dispatcher itself seals
        // an exact current-cohort control before any hypothesis.
        $mtf = $this->mtfAllocation($symbol);
        if ($mtf['powered_prior_due']) {
            return $this->decide($symbol, $timeframe, 'SETTLE_MTF_POWERED_PRIOR', 80,
                'trading:dispatch-mtf-powered-prior-validation', [0 => $symbol],
                'scheduler-critical', ['MTF_POWERED_PRIOR_WINDOW_DUE'], [
                    'generation' => $generation, 'closure' => $this->compactClosure($closure),
                    'director' => $this->compactDirector($director), 'mtf' => $mtf,
                ], $dryRun);
        }
        if ($mtf['research_due']) {
            return $this->decide($symbol, $timeframe, 'RUN_MTF_ECONOMIC_INFORMATION_BATCH', 72,
                'trading:dispatch-mtf-research-cycle', ['--symbol' => $symbol, '--limit' => 4, '--json' => true],
                'scheduler-research', ['MTF_INFORMATION_BUDGET_DUE'], [
                    'generation' => $generation, 'closure' => $this->compactClosure($closure),
                    'director' => $this->compactDirector($director), 'mtf' => $mtf,
                ], $dryRun);
        }
        if ($mtf['playbook_prior_due']) {
            return $this->decide($symbol, $timeframe, 'EXPLORE_MTF_PLAYBOOK_PRIOR', 68,
                'trading:dispatch-mtf-playbook-prior', [0 => $symbol, '--confirmation-first' => true],
                'scheduler-research', ['MTF_PLAYBOOK_INFORMATION_WINDOW_DUE'], [
                    'generation' => $generation, 'closure' => $this->compactClosure($closure),
                    'director' => $this->compactDirector($director), 'mtf' => $mtf,
                ], $dryRun);
        }

        $portfolioMember = $latest && (string) $latest->status === 'completed'
            ? LabAgent::query()->where('lab_generation_id', $latest->id)->where('lifecycle_status', 'screened')->first()
            : null;
        if ($portfolioMember && ! $this->recentAction('RUN_PORTFOLIO_MEMBER_REPLAY', 60, $symbol)) {
            return $this->decide($symbol, $timeframe, 'RUN_PORTFOLIO_MEMBER_REPLAY', 67,
                'trading:dispatch-portfolio-member-replay', [0 => $symbol, '--timeframe' => $timeframe],
                'scheduler-research', ['SEALED_COMPLEMENTARY_MEMBER_READY'], [
                    'generation' => $generation, 'closure' => $this->compactClosure($closure),
                    'member_agent_id' => (int) $portfolioMember->id,
                ], $dryRun);
        }
        $portfolioCandidates = ModelMarketPerformance::query()->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->where('evidence_status', 'valid')->whereIn('status', ['forward_validated', 'paper', 'challenger', 'stagnated', 'rejected'])
            ->count();
        if ($portfolioCandidates >= 2 && ! $this->recentAction('VALIDATE_ELITE_PORTFOLIO', 60, $symbol)) {
            return $this->decide($symbol, $timeframe, 'VALIDATE_ELITE_PORTFOLIO', 66,
                'trading:validate-elite-portfolios', [0 => $symbol, '--timeframe' => $timeframe],
                'scheduler-research', ['PORTFOLIO_INTERACTION_EVIDENCE_READY'], [
                    'generation' => $generation, 'closure' => $this->compactClosure($closure),
                    'candidate_count' => $portfolioCandidates,
                ], $dryRun);
        }

        // Historical exact-control rows remain durable, but they are a
        // maintenance curriculum rather than an admission barrier. Consume
        // at most one every bounded window after current/frontier work. This
        // prevents a large pre-v2 backlog from starving new 20-seat evidence
        // forever while still closing old scientific obligations over time.
        if ($historicalLearningPair
            && ! $this->recentAction('PUMP_HISTORICAL_LEARNING_PAIR', self::HISTORICAL_LEARNING_MAINTENANCE_MINUTES, $symbol)) {
            return $this->decide($symbol, $timeframe, 'PUMP_HISTORICAL_LEARNING_PAIR', 61,
                'trading:pump-learning-lane', [0 => $symbol, '--timeframe' => $timeframe,
                    '--limit' => 1, '--pair-id' => (int) $historicalLearningPair->id, '--autonomous' => true],
                'scheduler-critical', ['BOUNDED_HISTORICAL_EXACT_CONTROL_MAINTENANCE'], [
                    'generation' => $generation, 'closure' => $this->compactClosure($closure),
                    'pair_id' => (int) $historicalLearningPair->id,
                    'pair_generation_id' => (int) $historicalLearningPair->lab_generation_id,
                    'candidate_agent_id' => (int) $historicalLearningPair->candidate_agent_id,
                    'pair_status' => (string) $historicalLearningPair->status,
                    'curriculum_scope' => 'historical_maintenance',
                    'maintenance_window_minutes' => self::HISTORICAL_LEARNING_MAINTENANCE_MINUTES,
                ], $dryRun);
        }

        // Normal 20-seat lifecycle is the exploration fallback. Instrument
        // candidate/control pairs and memory-first mutations are embedded in
        // this constructor; they are no longer independent schedulers.
        return $this->decide($symbol, $timeframe, 'RUN_NORMAL_TWENTY_SEAT_LIFECYCLE', 60,
            'trading:run-lifecycle-cycle', ['--symbol' => $symbol, '--json' => true],
            'scheduler-constructor', ['NO_HIGHER_VALUE_CAUSAL_WORK_READY'], [
                'generation' => $generation, 'closure' => $this->compactClosure($closure),
                'director' => $this->compactDirector($director), 'mtf' => $mtf,
            ], $dryRun);
    }

    private function openCausalReplay(string $symbol, string $timeframe): ?AgentLearningCausalExperiment
    {
        if (! Schema::hasTable('agent_learning_causal_experiments')) {
            return null;
        }

        return AgentLearningCausalExperiment::query()
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->whereIn('status', ['ready_for_replay', 'outcomes_pending'])
            ->where('evidence->construction_validation->status', 'ready_for_replay')
            ->whereHas('generation', fn ($query) => $query
                ->whereIn('status', ['screened', 'completed', 'full_queued', 'full_validation'])
                ->whereHas('laboratory', fn ($lab) => $lab
                    ->where('symbol', $symbol)
                    ->where('timeframe', $timeframe)))
            ->oldest('id')
            ->first();
    }

    /** @return array<string,mixed> */
    private function decide(
        string $symbol,
        string $timeframe,
        string $action,
        int $priority,
        ?string $command,
        array $arguments,
        ?string $queue,
        array $reasons,
        array $evidence,
        bool $dryRun,
    ): array {
        $contract = [
            'protocol' => self::PROTOCOL,
            'owner' => self::OWNER,
            'selection_cardinality' => 1,
            'action' => $action,
            'priority' => $priority,
            'command' => $command,
            'arguments' => $arguments,
            'queue' => $queue,
            'new_generation_writers_outside_arbiter_forbidden' => true,
            'promotion_authority' => false,
        ];
        $evidenceHash = $this->hash($evidence);
        $decisionKey = $this->hash([
            self::PROTOCOL, $symbol, $timeframe, $action, $evidenceHash,
            // One immutable choice per minute. The child job remains unique,
            // while a genuinely unchanged active generation may still advance
            // on a later lifecycle tick.
            $command === null ? 'stable-defer' : now()->format('Y-m-d-H-i'),
        ]);
        $payload = [
            'protocol' => self::PROTOCOL,
            'status' => $dryRun ? 'dry_run' : ($command === null ? 'deferred' : 'selected'),
            'decision_key' => $decisionKey,
            'action' => $action,
            'priority' => $priority,
            'command' => $command,
            'arguments' => $arguments,
            'queue' => $queue,
            'reason_codes' => array_values(array_unique($reasons)),
            'evidence_hash' => $evidenceHash,
            'evidence_snapshot' => $evidence,
            'contract' => $contract,
            'promotion_evidence' => false,
        ];
        if ($dryRun) {
            return $payload;
        }

        $sameMinute = ResearchLoopDecision::query()->where('decision_key', $decisionKey)->first();
        if ($sameMinute) {
            return [...$payload, 'status' => 'duplicate_suppressed', 'decision_id' => (int) $sameMinute->id];
        }
        if ($command !== null && $queue !== null) {
            $canonicalArguments = $this->canonicalArguments($arguments);
            $inFlight = ResearchLoopDecision::query()
                ->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->where('command', $command)->where('queue', $queue)
                ->whereIn('status', ['selected', 'dispatched', 'running'])
                ->latest('id')->get()
                ->first(fn (ResearchLoopDecision $decision): bool => $this->canonicalArguments(
                    (array) $decision->arguments,
                ) === $canonicalArguments);
            if ($inFlight) {
                return [...$payload, 'status' => 'in_flight_suppressed', 'decision_id' => (int) $inFlight->id];
            }
        }

        $decision = ResearchLoopDecision::query()->firstOrCreate(['decision_key' => $decisionKey], [
            'symbol' => $symbol, 'timeframe' => $timeframe, 'action' => $action,
            'status' => $command === null ? 'deferred' : 'selected', 'priority' => $priority,
            'evidence_hash' => $evidenceHash, 'command' => $command, 'queue' => $queue,
            'arguments' => $arguments, 'reason_codes' => array_values(array_unique($reasons)),
            'evidence_snapshot' => $evidence, 'contract' => $contract,
            'completed_at' => $command === null ? now() : null,
        ]);
        if (! $decision->wasRecentlyCreated) {
            return [...$payload, 'status' => 'duplicate_suppressed', 'decision_id' => (int) $decision->id];
        }
        if ($command !== null && $queue !== null) {
            $decision->update(['status' => 'dispatched', 'dispatched_at' => now()]);
            try {
                RunScheduledArtisanCommandJob::dispatch($command, $arguments, $queue, (int) $decision->id);
            } catch (Throwable $exception) {
                $decision->update(['status' => 'failed', 'completed_at' => now()]);
                throw $exception;
            }

            return [...$payload, 'status' => 'dispatched', 'decision_id' => (int) $decision->id];
        }

        return [...$payload, 'decision_id' => (int) $decision->id];
    }

    private function canonicalArguments(array $arguments): string
    {
        ksort($arguments);

        return (string) json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return array<string,mixed> */
    private function mtfAllocation(string $symbol): array
    {
        if (! Schema::hasTable('model_market_performance')
            || ! Schema::hasTable('mtf_strategy_research_runs')) {
            return ['research_due' => false, 'powered_prior_due' => false, 'playbook_prior_due' => false,
                'reason' => 'MTF_RESEARCH_TABLES_MISSING'];
        }
        $candidate = $this->mtfCohorts->candidate($symbol);
        if (! $candidate) {
            return ['research_due' => false, 'powered_prior_due' => false,
                'playbook_prior_due' => false, 'reason' => 'CANONICAL_MTF_CANDIDATE_MISSING'];
        }
        $lastRun = MtfStrategyResearchRun::query()
            ->where('model_market_performance_id', $candidate->id)->latest('id')->first();
        $recent = fn (string $action, int $minutes): bool => ResearchLoopDecision::query()
            ->where('symbol', $symbol)->where('action', $action)
            ->where('created_at', '>=', now()->subMinutes($minutes))->exists();
        $recentResearch = $recent('RUN_MTF_ECONOMIC_INFORMATION_BATCH', 30);

        return [
            'research_due' => ! $recentResearch,
            'powered_prior_due' => ! $recent('SETTLE_MTF_POWERED_PRIOR', 15),
            'playbook_prior_due' => ! $recent('EXPLORE_MTF_PLAYBOOK_PRIOR', 30),
            'reason' => $recentResearch ? 'MTF_ALLOCATION_WINDOW_ALREADY_SPENT' : 'MTF_ALLOCATION_WINDOW_OPEN',
            'candidate_id' => (int) $candidate->id,
            'candidate_model_version_id' => (int) $candidate->model_version_id,
            'last_research_run_id' => $lastRun?->id,
            'last_research_data_hash' => $lastRun?->data_hash,
            'allocation_window_minutes' => 30,
        ];
    }

    private function driftAlreadyConsumed(?LabGeneration $latest, array $drift): bool
    {
        if (! $latest || (string) $latest->trigger_type !== 'market_drift') {
            return false;
        }
        $latestSnapshot = (int) ($drift['latest_snapshot_id'] ?? 0);
        $createdAfter = $latestSnapshot > 0
            ? MarketDriftSnapshot::query()->whereKey($latestSnapshot)->value('detected_at')
            : null;

        return $createdAfter !== null && $latest->created_at?->gte($createdAfter);
    }

    private function directorPriority(string $action): int
    {
        return match ($action) {
            'AUTHORITY_DESCENDANT_PROOF' => 86,
            'AUTHORITY_INCUBATOR' => 85,
            'PROVISIONAL_SKILL_CARTRIDGE_CONFIRMATION' => 84,
            'EDGE_INDEPENDENT_REPLICATION', 'EDGE_CONFIRMATION', 'EDGE_ATTRIBUTION' => 83,
            'SKILL_CARTRIDGE_TRANSPLANT', 'QUALITY_EVOLUTION_SYNTHESIS' => 82,
            default => 75,
        };
    }

    private function recentAction(string $action, int $minutes, string $symbol): bool
    {
        return ResearchLoopDecision::query()->where('symbol', $symbol)->where('action', $action)
            ->where('created_at', '>=', now()->subMinutes($minutes))->exists();
    }

    /**
     * @return array{0:?LabLearningLanePair,1:?LabLearningLanePair,2:?LabLearningLanePair}
     */
    private function learningCurriculum(
        string $symbol,
        string $timeframe,
        ?LabGeneration $latest,
    ): array {
        $priority = $this->learningLane->priorityResearchPair($symbol, $timeframe);
        $pairs = collect([$priority])->filter()
            ->concat($this->learningLane->pendingMicroPairs($symbol, $timeframe, null, 500))
            ->concat($this->learningLane->frontier($symbol, $timeframe, null, 500, false))
            ->unique(fn (LabLearningLanePair $pair): int => (int) $pair->id)
            ->values();
        $latestId = $latest ? (int) $latest->id : null;
        $current = $latestId === null
            ? null
            : $pairs->first(fn (LabLearningLanePair $pair): bool => (int) $pair->lab_generation_id === $latestId);
        $historical = $pairs->first(fn (LabLearningLanePair $pair): bool => $latestId === null
            || (int) $pair->lab_generation_id !== $latestId);

        return [$current, $historical, $priority];
    }

    /** @return array<string,mixed> */
    private function compactClosure(array $closure): array
    {
        return array_intersect_key($closure, array_flip([
            'protocol', 'status', 'healthy', 'receipt_count', 'missing_closure_receipt_ids',
            'ambiguous_closure_receipt_ids', 'owner_or_retry_missing_work_ids',
            'expired_lease_work_ids', 'duplicate_open_work_groups', 'projection_debt', 'work', 'next_repair',
        ]));
    }

    /** @return array<string,mixed> */
    private function compactDirector(array $director): array
    {
        return [
            'protocol' => $director['protocol'] ?? null,
            'status' => $director['status'] ?? null,
            'action' => $director['action'] ?? null,
            'reason' => $director['reason'] ?? null,
            'result_status' => data_get($director, 'result.status'),
            'result_reason' => data_get($director, 'result.reason'),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function blocked(string $reason, array $extra = []): array
    {
        return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => $reason,
            ...$extra, 'promotion_evidence' => false];
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
