<?php

namespace App\Services;

use App\Jobs\RunScheduledArtisanCommandJob;
use App\Models\AgentLearningCausalExperiment;
use App\Models\CandidateGateDecision;
use App\Models\CandidateHandoffEvent;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\MarketDriftSnapshot;
use App\Models\ModelMarketPerformance;
use App\Models\MtfStrategyResearchRun;
use App\Models\ResearchLoopDecision;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Log;
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
    // v3 fences decisions produced before scheduled command outcomes
    // distinguished an exit-0 lifecycle pause from an achieved transition,
    // and before causal candidates inherited their frozen control's terminal
    // disposition. The protocol is part of decision_key, so this repaired
    // deployment receives one fresh bounded attempt without restoring
    // minute-based no-op spam.
    public const PROTOCOL = 'research_loop_arbiter_v3';

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
        private CausalLearningCohortService $causalCohorts,
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

        // A terminal technical causal arm is already-admitted work, but the
        // generic active-generation lifecycle cannot settle its scientific
        // disposition. Detect it first or an active `full_validation`
        // generation will run the same no-op lifecycle tick every minute and
        // permanently shadow the dedicated terminal settlement command.
        $openCausal = $this->openCausalReplay($symbol, $timeframe, $latest?->id);
        if ($openCausal) {
            $armIds = array_values(array_filter([
                $openCausal->guided_agent_id,
                $openCausal->blinded_agent_id,
                $openCausal->control_agent_id,
            ], fn (mixed $id): bool => (int) $id > 0));
            $technicalDisposition = $this->causalCohorts->technicalTerminalDisposition($openCausal);
            if (($technicalDisposition['eligible'] ?? false) === true) {
                return $this->decide($symbol, $timeframe, 'FINALIZE_CAUSAL_TECHNICAL_QUARANTINE', 101,
                    'trading:settle-causal-technical-quarantine', [
                        0 => (int) $openCausal->id,
                        '--json' => true,
                    ], 'scheduler-critical', ['CAUSAL_TECHNICAL_ARM_REQUIRES_TERMINAL_DISPOSITION'], [
                        'generation' => $generation,
                        'causal_experiment_id' => (int) $openCausal->id,
                        'causal_generation_id' => (int) $openCausal->lab_generation_id,
                        'causal_status' => (string) $openCausal->status,
                        'arm_agent_ids' => $armIds,
                        'technical_disposition' => $technicalDisposition,
                    ], $dryRun);
            }
        }

        // Already-admitted work settles before any new hypothesis. STOP is
        // drain-first, so this branch intentionally precedes the mode check.
        if ($latest && (in_array((string) $latest->status, self::ACTIVE_GENERATION_STATUSES, true)
            || LabPopulationService::constructionIncomplete($latest))) {
            return $this->decide($symbol, $timeframe, 'SETTLE_EXISTING_GENERATION', 100,
                'trading:run-lifecycle-cycle', [
                    '--symbol' => $symbol,
                    '--expected-generation-id' => (int) $latest->id,
                    '--settle-only' => true,
                    '--json' => true,
                ],
                'scheduler-constructor', ['EXISTING_GENERATION_OWNS_RESEARCH_RUNTIME'],
                ['generation' => $generation], $dryRun);
        }

        // A screened causal triplet is admitted work, not historical
        // backlog. It must receive its serialized full replay and settle
        // before another generation can spend the twenty-seat budget. This
        // also repairs an older terminal cohort if a newer active generation
        // was already admitted before this invariant existed.
        if ($openCausal) {
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
                'trading:dispatch-full-validation', [
                    0 => $symbol,
                    '--timeframe' => $timeframe,
                    '--causal-experiment-id' => (int) $openCausal->id,
                ],
                'scheduler-constructor', ['UNSETTLED_CAUSAL_CONFIRMATION_OWNS_RESEARCH_RUNTIME'], [
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

        // A terminal generation can still own one bounded technical repair.
        // The new-generation constructor refuses that work with
        // RECOVER_TECHNICAL; selecting drift/new-data first only creates an
        // exit-zero blocked writer decision and strands the next passport.
        // Ask the same velocity authority used by generation admission, but
        // only when this lineage head actually has quarantined agents.
        if ($latest && in_array((string) $latest->status, ['screened', 'completed', 'technical_quarantine', 'abandoned', 'failed'], true)
            && $latest->agents()->whereIn('lifecycle_status', ['evaluation_error', 'technical_quarantine'])->exists()) {
            $velocity = app(LearningVelocityGateService::class)->inspect($latest->laboratory);
            if ((string) data_get($velocity, 'status') === 'blocked_technical_recovery') {
                $recoveryEnabled = (bool) config('services.lifecycle_orchestrator.autonomous_technical_recovery_enabled', false);

                return $this->decide($symbol, $timeframe,
                    $recoveryEnabled ? 'RECOVER_LATEST_TECHNICAL_EVIDENCE' : 'DEFER_TECHNICAL_RECOVERY_DISABLED',
                    94,
                    $recoveryEnabled ? 'trading:run-lifecycle-cycle' : null,
                    $recoveryEnabled ? ['--symbol' => $symbol, '--json' => true] : [],
                    $recoveryEnabled ? 'scheduler-constructor' : null,
                    ['LATEST_GENERATION_TECHNICAL_RECOVERY_REQUIRED'], [
                        'generation' => $generation,
                        'closure' => $this->compactClosure($closure),
                        'technical_recovery_agents' => (int) data_get($velocity, 'technical_recovery_agents', 0),
                        'recovery_enabled' => $recoveryEnabled,
                    ], $dryRun);
            }
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
                'trading:run-lifecycle-cycle', [
                    '--symbol' => $symbol,
                    '--learning-confirmation' => true,
                    '--json' => true,
                ],
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
            if ($latest && $this->latestHasZeroPassScreening($latest)) {
                if ($this->latestCanAutonomouslyRecordDataEdgeAudit($latest)) {
                    return $this->decide($symbol, $timeframe, 'RECORD_AUTONOMOUS_DATA_EDGE_AUDIT', 88,
                        'trading:run-lifecycle-cycle', [
                            '--symbol' => $symbol,
                            '--timeframe' => $timeframe,
                            '--json' => true,
                        ], 'scheduler-constructor', [
                            'ZERO_PASS_FINAL_REPORT_REQUIRES_DATA_EDGE_AUDIT',
                            'AUDIT_MUST_BE_RECORDED_BY_LIFECYCLE_OWNER',
                        ], [
                            'generation' => $generation,
                            'closure' => $this->compactClosure($closure),
                            'drift' => $drift,
                            'generation_report' => [
                                'protocol' => data_get($latest->trigger_context, 'latest_generation_report.protocol'),
                                'report_state' => data_get($latest->trigger_context, 'latest_generation_report.report_state'),
                                'next_action' => data_get($latest->trigger_context, 'latest_generation_report.next_action'),
                                'technical_completion_rate' => data_get($latest->trigger_context, 'latest_generation_report.kpis.technical_completion_rate'),
                                'pipeline_failure_count' => data_get($latest->trigger_context, 'latest_generation_report.kpis.pipeline_failure_count'),
                            ],
                            'promotion_evidence' => false,
                        ], $dryRun);
                }

                // A market-drift trigger is intentionally rejected by the
                // admission contract immediately after a zero-pass cohort.
                // If the final report requests a clean data-edge audit, route
                // through its lifecycle owner above. Otherwise accumulate on
                // the normal fresh-data route with frozen MTF admission.
                return $this->decide($symbol, $timeframe, 'ACCUMULATE_FRESH_DATA_AFTER_ZERO_PASS', 87,
                    'trading:lab-generation', [0 => $symbol, '--timeframe' => $timeframe, '--trigger' => 'new_data'],
                    'scheduler-constructor', [
                        'CONFIRMED_CANONICAL_MARKET_DRIFT',
                        'ZERO_PASS_COHORT_REQUIRES_FRESH_DATA_ADMISSION',
                    ], [
                        'generation' => $generation,
                        'closure' => $this->compactClosure($closure),
                        'drift' => $drift,
                        'admission_trigger' => 'new_data',
                        'fresh_candle_minimum' => $timeframe === 'M15' ? 96 : 24,
                        'promotion_evidence' => false,
                    ], $dryRun);
            }

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

    private function openCausalReplay(string $symbol, string $timeframe, ?int $latestGenerationId): ?AgentLearningCausalExperiment
    {
        if (! Schema::hasTable('agent_learning_causal_experiments')) {
            return null;
        }

        $query = AgentLearningCausalExperiment::query()
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->whereIn('status', ['ready_for_replay', 'outcomes_pending'])
            ->where('evidence->construction_validation->status', 'ready_for_replay')
            ->whereHas('generation', fn ($query) => $query
                ->whereIn('status', ['screened', 'completed', 'technical_quarantine', 'full_queued', 'full_validation'])
                ->whereHas('laboratory', fn ($lab) => $lab
                    ->where('symbol', $symbol)
                    ->where('timeframe', $timeframe)));
        if ($latestGenerationId !== null) {
            $query->orderByRaw('CASE WHEN lab_generation_id = ? THEN 0 ELSE 1 END', [$latestGenerationId]);
        }

        return $query->oldest('id')
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
        $stateSnapshot = $this->operationalStateSnapshot($evidence, $queue, $symbol, $timeframe);
        $stateHash = $this->hash($stateSnapshot);
        $decisionKey = $this->hash([
            self::PROTOCOL,
            $symbol,
            $timeframe,
            $action,
            $this->canonicalArguments($arguments),
            $stateHash,
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
            'state_hash' => $stateHash,
            'state_snapshot' => $stateSnapshot,
            'contract' => $contract,
            'promotion_evidence' => false,
        ];
        if ($dryRun) {
            return $payload;
        }

        $sameState = ResearchLoopDecision::query()->where('decision_key', $decisionKey)->first();
        if ($sameState) {
            return [...$payload, 'status' => 'duplicate_suppressed', 'decision_id' => (int) $sameState->id];
        }
        if ($command !== null && $queue !== null) {
            $canonicalArguments = $this->canonicalArguments($arguments);
            $leaseProbe = new RunScheduledArtisanCommandJob($command, $arguments, $queue);
            $inFlightCutoff = now()->subSeconds(max(1, (int) $leaseProbe->uniqueFor + 60));
            $inFlight = ResearchLoopDecision::query()
                ->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->where('command', $command)->where('queue', $queue)
                ->whereIn('status', ['selected', 'dispatched', 'running'])
                ->where('updated_at', '>=', $inFlightCutoff)
                ->latest('id')->get()
                ->first(function (ResearchLoopDecision $decision) use ($command, $canonicalArguments): bool {
                    $argumentsMatch = $command === 'trading:run-lifecycle-cycle'
                        || $this->canonicalArguments((array) $decision->arguments) === $canonicalArguments;
                    if (! $argumentsMatch) {
                        return false;
                    }
                    if ($this->hasLiveScheduledCommandLock($decision)) {
                        return true;
                    }

                    // The database row alone is not proof that a queued child
                    // still owns the command. Fence a recent orphan so a late
                    // Redis delivery becomes an explicit no-op and the arbiter
                    // can select the same work from its current snapshot.
                    $decision->update(['status' => 'failed', 'completed_at' => now()]);
                    Log::warning('Fenced an in-flight research decision without a live unique queue lock.', [
                        'decision_id' => (int) $decision->id,
                        'command' => (string) $decision->command,
                        'queue' => (string) $decision->queue,
                        'reason_code' => 'RESEARCH_DECISION_UNIQUE_QUEUE_LOCK_MISSING',
                        'promotion_evidence' => false,
                    ]);

                    return false;
                });
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

    private function hasLiveScheduledCommandLock(ResearchLoopDecision $decision): bool
    {
        $job = new RunScheduledArtisanCommandJob(
            (string) $decision->command,
            (array) $decision->arguments,
            (string) $decision->queue,
            (int) $decision->id,
        );

        try {
            $probe = Cache::lock(UniqueLock::getKey($job), 1);
            if (! $probe->get()) {
                return true;
            }

            $probe->release();

            return false;
        } catch (Throwable $exception) {
            // Cache uncertainty must not permit two owners of one command.
            report($exception);

            return true;
        }
    }

    private function canonicalArguments(array $arguments): string
    {
        ksort($arguments);

        return (string) json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Stable operational watermark for no-op suppression. Time is
     * deliberately absent: an unchanged generation cannot enqueue another
     * lifecycle child merely because the scheduler reached a new minute.
     *
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function operationalStateSnapshot(
        array $evidence,
        ?string $queue,
        ?string $symbol = null,
        ?string $timeframe = null,
    ): array
    {
        $generationId = (int) data_get($evidence, 'generation.id', 0);
        $generation = $generationId > 0
            ? LabGeneration::query()->with('agents.modelVersion')->find($generationId)
            : null;
        $terminalStatuses = [
            'screened', 'completed', 'rejected', 'overfit', 'stagnated',
            'challenger', 'forward_validated', 'paper', 'promoted', 'champion',
            'active', 'elite', 'archived', 'technical_quarantine', 'quarantined',
            'legacy_quarantine', 'abandoned', 'failed',
        ];
        $terminalAgents = $generation
            ? $generation->agents->whereIn('lifecycle_status', $terminalStatuses)->count()
            : 0;
        // A bounded recovery may find that the prior immutable run used a
        // different dataset. Sealing that refusal changes admission authority
        // without changing generation status or agent terminal count. Include
        // the durable disposition in the state key so the successor decision
        // can be selected again exactly once after this repair, while
        // unchanged no-op scheduler ticks remain deduplicated.
        $terminalRecoveryAgentIds = $generation
            ? $generation->agents
                ->filter(fn (LabAgent $agent): bool => data_get(
                    $agent->modelVersion?->metadata,
                    'technical_recovery_terminal_disposition.protocol',
                ) === 'frozen_recovery_contract_terminal_v1')
                ->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all()
            : [];
        $openRunCount = $generation && Schema::hasTable('lab_evaluation_runs')
            ? DB::table('lab_evaluation_runs')
                ->where('lab_generation_id', $generation->id)
                ->whereNotIn('status', ['completed', 'technical_error', 'skipped', 'retry_released'])
                ->count()
            : 0;
        $queueWatermark = ['available' => false];
        if (Schema::hasTable('jobs')) {
            // The selected scheduler job must not become an input to its own
            // next decision hash. Otherwise a command entering/leaving the
            // constructor queue flips 0 -> 1 -> 0 and creates an endless pair
            // of otherwise identical decisions. Only downstream laboratory
            // work is part of the research state watermark.
            $queues = array_values(array_unique(array_filter([
                (string) config('services.lab_queue.screening_queue', 'lab-screening'),
                (string) config('services.lab_queue.frontier_queue', 'lab-frontier'),
                (string) config('services.lab_queue.full_validation_queue', 'lab-full-validation'),
                (string) config('services.lab_queue.learning_queue', 'lab-learning'),
                (string) config('services.lab_queue.default_queue', 'lab-xauusd'),
            ])));
            $jobs = DB::table('jobs')->whereIn('queue', $queues);
            $queueWatermark = [
                'available' => true,
                'scope' => 'downstream_lab_runtime',
                'queues' => $queues,
                'selected_scheduler_queue' => $queue,
                'count' => (int) (clone $jobs)->count(),
                'max_id' => (int) ((clone $jobs)->max('id') ?? 0),
                'max_available_at' => (int) ((clone $jobs)->max('available_at') ?? 0),
            ];
        }
        $settlementWatermark = ['available' => false];
        if ($generation && Schema::hasTable('settlement_watermarks')) {
            $rows = DB::table('settlement_watermarks')
                ->where('lab_generation_id', $generation->id)
                ->orderBy('watermark_key')->get();
            $settlementWatermark = [
                'available' => true,
                'count' => $rows->count(),
                'states' => $rows->map(fn (object $row): array => [
                    'key' => (string) $row->watermark_key,
                    'status' => (string) ($row->status ?? ''),
                    'updated_at' => (string) ($row->updated_at ?? ''),
                ])->all(),
            ];
        }

        $state = [
            'generation_id' => $generationId ?: null,
            'generation_status' => $generation?->status ?? data_get($evidence, 'generation.status'),
            'terminal_agent_count' => $terminalAgents,
            'terminal_recovery_agent_ids' => $terminalRecoveryAgentIds,
            'agent_count' => $generation?->agents->count() ?? (int) data_get($evidence, 'generation.agents', 0),
            'open_run_count' => $openRunCount,
            'queue_watermark' => $queueWatermark,
            'settlement_watermark' => $settlementWatermark,
        ];

        if ($generation) {
            // A lifecycle audit changes successor authority without changing
            // generation status or candle count. Include its compact gate
            // disposition so a deferred audit child can be reselected once,
            // while unchanged scheduler minutes remain deduplicated.
            $state['generation_gate_watermark'] = [
                'report_protocol' => data_get($generation->trigger_context, 'latest_generation_report.protocol'),
                'report_state' => data_get($generation->trigger_context, 'latest_generation_report.report_state'),
                'report_next_action' => data_get($generation->trigger_context, 'latest_generation_report.next_action'),
                'data_edge_audit_protocol' => data_get($generation->trigger_context, 'data_edge_audit.protocol'),
                'data_edge_audit_recorded_at' => data_get($generation->trigger_context, 'data_edge_audit.recorded_at'),
            ];
        }

        // An unchanged new-data attempt must stay deduplicated while the
        // admission threshold is still unmet, then become eligible exactly
        // when another bounded block of canonical candles arrives. Without
        // this watermark, a blocked child leaves one completed decision key
        // that suppresses every future attempt forever.
        if (data_get($evidence, 'admission_trigger') === 'new_data'
            && $generation
            && $symbol !== null
            && $timeframe !== null
            && Schema::hasTable('symbols')
            && Schema::hasTable('candles')) {
            $baselineCount = data_get($generation->trigger_context, 'data_count');
            $symbolId = DB::table('symbols')->where('code', strtoupper($symbol))->value('id');
            if (is_numeric($baselineCount) && $symbolId) {
                $minimumFreshCandles = strtoupper($timeframe) === 'M15' ? 96 : 24;
                $currentCount = (int) DB::table('candles')
                    ->where('symbol_id', (int) $symbolId)
                    ->where('timeframe', strtoupper($timeframe))
                    ->count();
                $newCandles = max(0, $currentCount - (int) $baselineCount);
                if ($newCandles >= $minimumFreshCandles) {
                    $state['fresh_data_retry_watermark'] = [
                        'protocol' => 'fresh_data_retry_watermark_v1',
                        'generation_id' => (int) $generation->id,
                        'new_candle_window' => intdiv($newCandles, $minimumFreshCandles),
                        'minimum_new_candles' => $minimumFreshCandles,
                    ];
                }
            }
        }

        return $state;
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

    private function latestHasZeroPassScreening(LabGeneration $generation): bool
    {
        if (! Schema::hasTable('candidate_gate_decisions')) {
            return false;
        }

        $agentIds = $generation->agents()->pluck('id');
        if ($agentIds->isEmpty()) {
            return false;
        }

        $screen = CandidateGateDecision::query()
            ->whereIn('lab_agent_id', $agentIds)
            ->where('stage', 'screening');

        return $screen->exists() && ! (clone $screen)->where('decision', 'passed')->exists();
    }

    private function latestCanAutonomouslyRecordDataEdgeAudit(LabGeneration $generation): bool
    {
        $context = (array) $generation->trigger_context;
        $report = (array) data_get($context, 'latest_generation_report', []);

        return (string) data_get($report, 'protocol') === LabGenerationReportService::PROTOCOL
            && (string) data_get($report, 'report_state') === 'FINAL'
            && (string) data_get($report, 'next_action') === 'data_edge_audit_required'
            && ! app(LabDataEdgeAuditService::class)->ownsAudit($generation)
            && (float) data_get($report, 'kpis.technical_completion_rate', 0) >= 100
            && (int) data_get($report, 'kpis.pipeline_failure_count', 0) === 0;
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
