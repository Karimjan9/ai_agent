<?php

namespace App\Services;

use App\Jobs\RunScheduledArtisanCommandJob;
use App\Models\AgentLearningCausalExperiment;
use App\Models\CandidateGateDecision;
use App\Models\CandidateHandoffEvent;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\MarketDriftSnapshot;
use App\Models\ModelMarketPerformance;
use App\Models\MtfStrategyResearchRun;
use App\Models\ResearchLoopDecision;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    // The one-time cold Academy question may outrank new discovery, never
    // an admitted continuation, technical repair or evidence-earned proof.
    private const EARNED_CAUSAL_LADDER_ACTIONS = [
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

        // An operator pause is an admission fence, not a transport failure.
        // Check before stale-lease recovery so an intentionally stopped worker
        // cannot be classified as a crashed constructor during the pause.
        $controlState = (string) data_get($this->autonomy->status($symbol, $timeframe), 'state');
        if (in_array($controlState, ['pausing', 'paused', 'safety_halt'], true)) {
            return $this->blocked($controlState === 'safety_halt' ? 'RESEARCH_RUN_SAFETY_HALT' : 'RESEARCH_RUN_PAUSED');
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
            $controlState = (string) data_get($this->autonomy->status($symbol, $timeframe), 'state');
            if (in_array($controlState, ['pausing', 'paused', 'safety_halt'], true)) {
                return $this->blocked($controlState === 'safety_halt' ? 'RESEARCH_RUN_SAFETY_HALT' : 'RESEARCH_RUN_PAUSED');
            }
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

        $academy = Schema::hasTable('edge_academy_trials')
            ? app(AcademyExperimentMaterializerService::class)->proposal($symbol, $timeframe) : [];
        if (($academy['status'] ?? '') === 'pending_canonical_admission'
            && (int) ($academy['generation_id'] ?? 0) === (int) ($latest?->id ?? 0)) {
            return $this->decide($symbol, $timeframe, 'DISPATCH_ACADEMY_EXPERIMENT', 100,
                'trading:admit-academy-experiment', ['trial' => (int) $academy['trial_id']], 'scheduler-constructor',
                ['ACADEMY_DURABLE_CANONICAL_ADMISSION_INTENT'],
                ['generation' => $generation, 'academy_proposal' => $academy], $dryRun);
        }

        // Already-admitted work settles before any new hypothesis. STOP is
        // drain-first, so this branch intentionally precedes the mode check.
        if ($latest && (in_array((string) $latest->status, self::ACTIVE_GENERATION_STATUSES, true)
            || LabPopulationService::constructionIncomplete($latest))) {
            // A sealed replay can legitimately outlive several scheduler ticks.
            // Its immutable started run is progress in flight, not three failed
            // settlement attempts. Bound the wait by the Redis reservation
            // lease so an abandoned run still reaches lifecycle recovery.
            $runLease = max(1, min(4440, (int) config('queue.connections.redis.retry_after', 4500) - 60));
            $activeRun = Schema::hasTable('lab_evaluation_runs')
                ? LabEvaluationRun::query()
                    ->where('lab_generation_id', $latest->id)
                    ->whereIn('status', ['started', 'running', 'processing'])
                    ->where('started_at', '>=', now()->subSeconds($runLease))
                    ->latest('id')->first()
                : null;
            if ($activeRun) {
                return $this->decide($symbol, $timeframe, 'WAIT_EXISTING_GENERATION_REPLAY', 100,
                    null, [], null, ['SEALED_GENERATION_REPLAY_IN_FLIGHT'], [
                        'generation' => $generation,
                        'active_run_id' => (int) $activeRun->id,
                        'active_agent_id' => (int) $activeRun->lab_agent_id,
                        'active_phase' => (string) $activeRun->phase,
                    ], $dryRun);
            }
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

        $instrumentDebt = app(InstrumentInvocationLedgerService::class)->pendingResearchPairs($symbol, $timeframe);
        if ($instrumentDebt !== [] && ((int) $instrumentDebt[0]['generation_id'] === (int) $latest?->id
                || (int) ($instrumentDebt[0]['pending_candidate_invocations'] ?? 0) > 0
                || ! $this->recentAction('RECONCILE_EXACT_INSTRUMENT_PAIR', 30, $symbol))) {
            $debt = $instrumentDebt[0];
            return $this->decide($symbol, $timeframe, 'RECONCILE_EXACT_INSTRUMENT_PAIR', 93,
                'trading:reconcile-instrument-pairs', [0 => $symbol, '--timeframe' => $timeframe,
                    '--pair-id' => $debt['pair_id'], '--autonomous' => true, '--json' => true],
                'scheduler-critical', ['EXACT_INSTRUMENT_CONTROL_PROJECTION_READY'],
                ['generation' => $generation, 'instrument_projection' => $debt], $dryRun);
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

        $historicalPolicy = app(GenerationAdmissionDecisionService::class)->historicalResearchPolicy($symbol, $timeframe);
        // Clean zero-pass audit is a durable obligation regardless of whether
        // the next experiment is driven by live drift or a frozen archive.
        if ($historicalPolicy['eligible'] && $latest && $this->latestCanAutonomouslyRecordDataEdgeAudit($latest)) {
            return $this->decide($symbol, $timeframe, 'RECORD_AUTONOMOUS_DATA_EDGE_AUDIT', 88,
                'trading:run-lifecycle-cycle', ['--symbol' => $symbol, '--timeframe' => $timeframe, '--json' => true],
                'scheduler-constructor', ['ZERO_PASS_FINAL_REPORT_REQUIRES_DATA_EDGE_AUDIT'], [
                    'generation' => $generation, 'closure' => $this->compactClosure($closure),
                    'historical_research_policy' => $historicalPolicy,
                ], $dryRun);
        }

        // Inspect once, read-only, before ALL fresh discovery (including
        // live drift/degradation). Reuse this exact plan later; a spent cold
        // budget or absent repair candidate cannot hide earned proof.
        $director = $this->director->advance($symbol, $timeframe, false, true, true);
        $directorAction = (string) ($director['action'] ?? '');
        if (in_array($directorAction, self::EARNED_CAUSAL_LADDER_ACTIONS, true)
            && in_array((string) data_get($director, 'result.status'), ['would_queue', 'queued'], true)) {
            return $this->decide($symbol, $timeframe, $directorAction, $this->directorPriority($directorAction),
                'trading:advance-learning-progress', [0 => $symbol, '--timeframe' => $timeframe,
                    '--apply' => true, '--arbiter-authorized' => true, '--json' => true],
                'scheduler-constructor', ['CAUSAL_LADDER_ACTION_READY', 'EARNED_PROOF_PRECEDES_NEW_DISCOVERY'], [
                    'generation' => $generation, 'closure' => $this->compactClosure($closure),
                    'director' => $this->compactDirector($director),
                ], $dryRun);
        }

        // A depth label or legacy planned trial is not earned continuation.
        // The curriculum owner re-attests the actual original settled trial,
        // candidate baseline and source seal without changing its evidence.
        $academyContinuation = ($academy['status'] ?? '') === 'would_materialize'
            ? app(XauusdEdgeFormationAcademyService::class)->curriculumContinuationEvidence((int) $academy['trial_id']) : [];
        if (($academyContinuation['eligible'] ?? false) === true) {
            return $this->decide($symbol, $timeframe, 'OPEN_ACADEMY_EXPERIMENT', 90,
                'trading:admit-academy-experiment', ['trial' => (int) $academy['trial_id']], 'scheduler-constructor',
                ['ACADEMY_ATTESTED_CURRICULUM_CONTINUATION_READY', 'EARNED_PROOF_PRECEDES_NEW_DISCOVERY'], [
                    'generation' => $generation, 'academy_proposal' => $academy,
                    'academy_continuation' => $academyContinuation,
                ], $dryRun);
        }

        // Unchanged source bytes with an original, immutable continuity
        // failure cannot buy another twenty-agent discovery. Existing work
        // and earned continuation above retain priority. Only the existing
        // data owner's verified prospective repair can select new bytes.
        $dataReadiness = $this->freshDatasetContinuityReadiness($latest, $academy, $symbol);
        if (! $dataReadiness['allowed']) {
            return $this->decide($symbol, $timeframe, 'WAIT_DATASET_CONTINUITY', 89,
                null, [], null, ['GENERATION_MTF_M5_KNOWN_CANDLE_GAP'], [
                    'generation' => $generation, 'data_readiness' => $dataReadiness,
                    'academy_proposal' => $academy, 'promotion_evidence' => false,
                ], $dryRun);
        }

        // The cold lane is capped once per actual frozen input bytes. It may
        // precede new discovery, never an admitted or evidence-earned action.
        $coldAcademyReady = ($academy['status'] ?? '') === 'would_prepare_cold_start';
        if ($coldAcademyReady) {
            return $this->decide($symbol, $timeframe, 'OPEN_ACADEMY_EXPERIMENT', 89,
                'trading:admit-academy-experiment', ['trial' => 0], 'scheduler-constructor',
                ['ACADEMY_PROSPECTIVE_HYPOTHESIS_COLD_START_READY', 'BOUNDED_COLD_ACADEMY_PRECEDES_NEW_DISCOVERY'], [
                    'generation' => $generation, 'academy_proposal' => $academy,
                    'selection_policy' => [
                        'protocol' => 'academy_cold_start_once_before_new_discovery_v1',
                        'stable_input_budget_scope' => data_get($academy, 'cold_start.budget_scope'),
                        'deferred_new_director_action' => $directorAction !== '' ? $directorAction : null,
                        'active_work_preempted' => false,
                        'earned_proof_preempted' => false,
                    ],
                ], $dryRun);
        }

        $prospectiveRepair = app(ProspectiveRepairExperimentService::class)->eligible($symbol, $timeframe);
        if ($prospectiveRepair !== null) {
            return $this->decide($symbol, $timeframe, 'OPEN_PROSPECTIVE_REPAIR_EXPERIMENT', 89,
                'trading:run-lifecycle-cycle', ['--symbol' => $symbol, '--learning-confirmation' => true,
                    '--prospective-source-pair-id' => (int) $prospectiveRepair['source_pair_id'],
                    '--prospective-source-hash' => (string) $prospectiveRepair['source_hash'], '--json' => true],
                'scheduler-constructor', ['PROMISING_SCREEN_REQUIRES_FRESH_EXACT_TRIPLET'], [
                    'generation' => $generation, 'closure' => $this->compactClosure($closure),
                    'prospective_repair' => $prospectiveRepair,
                ], $dryRun);
        }
        // A confirmed operational change is a high-value trigger, but the
        // arbiter—not the detector—owns its generation admission.
        $degraded = ModelMarketPerformance::query()->where('symbol', $symbol)
            ->where('status', 'champion')->where('consecutive_no_improvement', '>=', 3)->exists();
        if (! $historicalPolicy['eligible'] && $degraded) {
            return $this->decide($symbol, $timeframe, 'OPEN_DEGRADATION_RESEARCH', 88,
                'trading:lab-generation', [0 => $symbol, '--timeframe' => $timeframe, '--trigger' => 'degradation'],
                'scheduler-constructor', ['CONFIRMED_CHAMPION_DEGRADATION'],
                ['generation' => $generation, 'closure' => $this->compactClosure($closure)], $dryRun);
        }
        $drift = $historicalPolicy['eligible'] ? [] : $this->drift->confirmation($symbol, $timeframe);
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

        // Reuse the read-only plan above for fresh discovery. The selected
        // child re-checks every runtime/safety admission before apply.
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

        if (in_array(($academy['status'] ?? ''), ['would_materialize', 'would_prepare_cold_start'], true)) {
            return $this->decide($symbol, $timeframe, 'OPEN_ACADEMY_EXPERIMENT', 81,
                'trading:admit-academy-experiment', ['trial' => (int) $academy['trial_id']], 'scheduler-constructor',
                [($academy['status'] ?? '') === 'would_prepare_cold_start'
                    ? 'ACADEMY_PROSPECTIVE_HYPOTHESIS_COLD_START_READY' : 'ACADEMY_SEALED_EXPERIMENT_READY'], ['generation' => $generation,
                    'academy_proposal' => $academy], $dryRun);
        }

        // Before champion, embedded archive experiments own exploration.
        // Timer-only standalone MTF/portfolio maintenance must not starve
        // them. An actually eligible powered prior still has precedence.
        $mtf = $this->mtfAllocation($symbol, (bool) $historicalPolicy['eligible']);
        if ($mtf['powered_prior_due']) {
            return $this->decide($symbol, $timeframe, 'SETTLE_MTF_POWERED_PRIOR', 80,
                'trading:dispatch-mtf-powered-prior-validation', [0 => $symbol],
                'scheduler-critical', ['MTF_POWERED_PRIOR_WINDOW_DUE'], [
                    'generation' => $generation, 'closure' => $this->compactClosure($closure),
                    'director' => $this->compactDirector($director), 'mtf' => $mtf,
                ], $dryRun);
        }
        if ($historicalPolicy['eligible']) {
            return $this->decide($symbol, $timeframe, 'OPEN_HISTORICAL_RESEARCH_GENERATION', 73,
                'trading:lab-generation', [0 => $symbol, '--timeframe' => $timeframe,
                    '--trigger' => GenerationAdmissionDecisionService::HISTORICAL_TRIGGER],
                'scheduler-constructor', ['PRE_PAPER_ARCHIVE_RESEARCH_READY_WITHOUT_LIVE_CANDLES'], [
                    'generation' => $generation, 'closure' => $this->compactClosure($closure),
                    'director' => $this->compactDirector($director), 'mtf' => $mtf,
                    'historical_research_policy' => $historicalPolicy,
                    'archive_dependency' => app(LabDatasetExportService::class)->foundationDependencyWatermark($symbol, $timeframe),
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

    /** Read-only early dependency check; the final frozen bundle still rechecks before queueing. */
    private function freshDatasetContinuityReadiness(?LabGeneration $latest, array $academy, string $symbol): array
    {
        $owner = app(GenerationSnapshotAdmissionService::class);
        // An explicitly selected, physically distinct clean discovery slice
        // may serve only this ready bounded Academy question. It cannot
        // certify the parent archive or unlock another generic full replay.
        $discovery = (array) data_get($academy, 'cold_start.dependencies.discovery_bundle_manifest', []);
        if (($academy['status'] ?? null) === 'would_materialize'
            && data_get($academy, 'identity.data_role') === 'pre_2026_discovery_only') {
            $discovery = (array) data_get($academy, 'identity.mtf_bundle_manifest', []);
        }
        if (in_array($academy['status'] ?? null, ['would_prepare_cold_start', 'would_materialize'], true)
            && $discovery !== []) {
            $scope = app(MultiTimeframeSnapshotService::class)->discoveryBundleReadiness($discovery);
            if (($scope['ready'] ?? false) !== true) return ['allowed' => false,
                'reasons' => [$scope['reason'] ?? 'PROSPECTIVE_CLEAN_DISCOVERY_SCOPE_INVALID'],
                'data_readiness' => $scope, 'promotion_evidence' => false];
            $checked = $owner->historicalDatasetReadiness($discovery);
            return [...$checked, 'discovery_only' => true, 'full_validation_eligible' => false,
                'independent_evidence' => false, 'parent_archive_repaired' => false];
        }
        $manifest = (array) data_get($academy, 'identity.mtf_bundle_manifest',
            data_get($latest?->trigger_context, 'mtf_bundle_manifest', []));
        if (($manifest['validation_bundle_protocol'] ?? null) === 'prospective_clean_discovery_bundle_v1') {
            // The clean question is spent/not ready. Evaluate the original
            // full input's outstanding dependency, not the slice's good SHA.
            $originalSha = (string) data_get($manifest, 'prospective_m5_repair.original_bad_m5_sha256', '');
            $prior = LabGeneration::query()->whereHas('laboratory', fn ($query) => $query->where('symbol', $symbol))
                ->where('trigger_context->mtf_bundle_manifest->streams->M5->sha256', $originalSha)->latest('id')->first();
            if ($prior) $manifest = (array) data_get($prior->trigger_context, 'mtf_bundle_manifest', []);
            else return ['allowed' => false, 'reasons' => ['DISCOVERY_SCOPE_DOES_NOT_AUTHORIZE_FULL_REPLAY'],
                'promotion_evidence' => false, 'discovery_only' => true];
        }
        $path = (string) data_get($manifest, 'streams.M5.path', '');
        $sha = (string) data_get($manifest, 'streams.M5.sha256', '');
        // Missing or drifted paths remain subject to ordinary snapshot
        // admission; they cannot attest a known-byte scientific dependency.
        if (preg_match('/^[a-f0-9]{64}$/D', $sha) !== 1 || ! is_file($path)
            || ! hash_equals($sha, (string) hash_file('sha256', $path))) {
            return ['allowed' => true, 'reasons' => [], 'promotion_evidence' => false];
        }
        $blocked = $owner->historicalDatasetReadiness($manifest);
        if ($blocked['allowed']) return $blocked;
        $current = app(MultiTimeframeSnapshotService::class)->agentValidationReadiness($symbol);
        $repair = (array) ($current['prospective_m5_repair'] ?? []);
        $newPath = (string) ($repair['prospective_m5_source_path'] ?? '');
        $newSha = (string) ($repair['prospective_m5_source_sha256'] ?? '');
        if (($current['ready'] ?? false) === true
            && in_array($repair['protocol'] ?? null, ['frozen_m5_gap_recovery_v1', 'frozen_m5_gap_recovery_v2', 'frozen_m5_gap_recovery_v3'], true)
            && ($repair['verified'] ?? false) === true
            && ($repair['original_bad_m5_sha256'] ?? null) === $sha
            && preg_match('/^[a-f0-9]{64}$/D', (string) ($repair['repair_hash'] ?? '')) === 1
            && preg_match('/^[a-f0-9]{64}$/D', $newSha) === 1 && ! hash_equals($sha, $newSha)
            && is_file($newPath) && hash_equals($newSha, (string) hash_file('sha256', $newPath))) {
            $prospective = $owner->historicalDatasetReadiness(['streams' => ['M5' => ['sha256' => $newSha, 'path' => $newPath]]]);
            if ($prospective['allowed']) return [...$prospective, 'prospective_m5_repair' => $repair,
                'original_dependency' => $blocked['source_dependency'], 'old_bundle_reused' => false];
            return $prospective;
        }

        return $blocked;
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
        $fidelity = $this->fidelityPlanForAction($action, $symbol, $timeframe, $evidence);
        if ($fidelity !== null) $evidence['research_fidelity_plan'] = $fidelity;
        if ($action === 'WAIT_DATASET_CONTINUITY') {
            $evidence['measurement_acquisition_proposal'] = app(MultiModalLearningPortfolioService::class)
                ->measurementDependencyProposal((array) ($evidence['data_readiness'] ?? []));
        }
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
            'question_fidelity_plan_hash' => $fidelity['plan_hash'] ?? null,
            'existing_readiness_priority_and_executor_admission_unchanged' => true,
        ];
        $evidenceHash = $this->hash($evidence);
        $stateSnapshot = $this->operationalStateSnapshot($evidence, $queue, $symbol, $timeframe);
        $stateHash = $this->hash($stateSnapshot);
        $baseDecisionKey = $this->hash([
            self::PROTOCOL,
            $symbol,
            $timeframe,
            $action,
            $this->canonicalArguments($arguments),
            $stateHash,
        ]);
        $decisionKey = $baseDecisionKey;
        if ($action === 'SETTLE_EXISTING_GENERATION') {
            // A lifecycle child may exit successfully after doing one bounded
            // pass while the same generation still owns the lineage. A single
            // completed key must not freeze that generation forever. Retry at
            // most twice, spaced apart; unchanged states then fail visibly
            // instead of producing an unbounded per-minute no-op loop.
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $candidateKey = $attempt === 0 ? $baseDecisionKey : $this->hash([$baseDecisionKey, 'bounded_settlement_retry', $attempt]);
                $prior = ResearchLoopDecision::query()->where('decision_key', $candidateKey)->first();
                if (! $prior) {
                    $decisionKey = $candidateKey;
                    break;
                }
                $decisionKey = $candidateKey;
                if (! in_array((string) $prior->status, ['completed', 'deferred', 'failed'], true)
                    || $prior->updated_at?->greaterThan(now()->subMinutes(5))) {
                    break;
                }
                if ($attempt === 2) {
                    if (! $dryRun) {
                        $this->autonomy->safetyHalt($symbol, $timeframe, 'UNCHANGED_GENERATION_AFTER_BOUNDED_SETTLEMENT_RETRIES');
                    }

                    return [
                        'protocol' => self::PROTOCOL,
                        'status' => 'safety_blocked',
                        'action' => 'SETTLE_EXISTING_GENERATION',
                        'reason_codes' => ['UNCHANGED_GENERATION_AFTER_BOUNDED_SETTLEMENT_RETRIES'],
                        'generation_id' => data_get($evidence, 'generation.id'),
                        'last_decision_id' => (int) $prior->id,
                        'promotion_evidence' => false,
                    ];
                }
            }
        }
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
        if ($command === 'trading:admit-academy-experiment' && $sameState) {
            // Recover only an undelivered outbox publication, never a command
            // that ran and was scientifically/technically refused. Two bounded
            // transport retries keep a crash between DB commit and Redis from
            // stranding the trial without introducing minute-based job spam.
            for ($attempt = 1; $attempt <= 2 && $sameState; $attempt++) {
                $probe = new RunScheduledArtisanCommandJob($command, $arguments, (string) $queue, (int) $sameState->id);
                if (! in_array($sameState->status, ['selected', 'dispatched', 'publication_failed'], true)
                    || $this->hasLiveScheduledCommandLock($sameState)
                    || Cache::get($probe->statusCacheKey()) !== null) break;
                if ($sameState->status !== 'publication_failed') $sameState->update(['status' => 'publication_failed', 'completed_at' => now()]);
                $decisionKey = $this->hash([$baseDecisionKey, 'bounded_academy_publication_retry', $attempt]);
                $payload['decision_key'] = $decisionKey;
                $sameState = ResearchLoopDecision::query()->where('decision_key', $decisionKey)->first();
            }
        }
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
                $decision->update(['status' => $command === 'trading:admit-academy-experiment'
                    ? 'publication_failed' : 'failed', 'completed_at' => now()]);
                throw $exception;
            }

            return [...$payload, 'status' => 'dispatched', 'decision_id' => (int) $decision->id];
        }

        return [...$payload, 'decision_id' => (int) $decision->id];
    }

    /** Read-only planning inside the selected readiness tier, never a second selector. */
    public function fidelityPlanForAction(string $action, string $symbol, string $timeframe, array $evidence): ?array
    {
        $kind = match ($action) {
            'EDGE_INDEPENDENT_REPLICATION' => 'independent_validation',
            'AUTHORITY_DESCENDANT_PROOF', 'SKILL_CARTRIDGE_TRANSPLANT' => 'descendant_proof',
            'EDGE_CONFIRMATION', 'PROVISIONAL_SKILL_CARTRIDGE_CONFIRMATION', 'OPEN_PROSPECTIVE_REPAIR_EXPERIMENT' => 'replication',
            'EDGE_ATTRIBUTION', 'EDGE_ARCHITECTURE_REPAIR', 'WAIT_DATASET_CONTINUITY' => 'diagnostic',
            'OPEN_ACADEMY_EXPERIMENT', 'EDGE_DISCOVERY_RESUME', 'EDGE_HYPOTHESIS_COMPILED', 'EDGE_GENESIS',
            'RUN_NORMAL_TWENTY_SEAT_LIFECYCLE', 'OPEN_HISTORICAL_RESEARCH_GENERATION',
            'RUN_MTF_ECONOMIC_INFORMATION_BATCH', 'EXPLORE_MTF_PLAYBOOK_PRIOR' => 'discovery',
            default => null,
        };
        if ($kind === null) return null;
        $source = (array) ($evidence['academy_proposal'] ?? []);
        $progress = (array) ($source['learning_progress'] ?? []);
        if ($action === 'OPEN_ACADEMY_EXPERIMENT' && $progress === [] && (int) ($source['passport_id'] ?? 0) > 0) {
            $progress = app(XauusdEdgeFormationAcademyService::class)->learningProgress((int) $source['passport_id']);
        }

        return app(MultiModalLearningPortfolioService::class)->planFidelity([
            'kind' => $kind, 'question_key' => $this->hash([$action, $source,
                data_get($evidence, 'generation.id'), data_get($evidence, 'director.next_action')]),
            'scope' => ['symbol' => $symbol, 'timeframe' => $timeframe],
            'source_references' => array_filter([
                'academy_identity' => $source['identity'] ?? null,
                'source_dependency' => data_get($evidence, 'data_readiness.source_dependency'),
                'prospective_repair' => $evidence['prospective_repair'] ?? null,
            ]),
            'learning_progress' => $progress,
            'criterion' => ['action' => $action, 'existing_owner_terminal_receipt_required' => true],
        ]);
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
    ): array {
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
            'run_control_revision' => data_get(app(AutonomousModeService::class)->status($symbol ?? 'XAUUSD', $timeframe ?? 'H1'), 'changed_at'),
        ];
        if (is_array(data_get($evidence, 'archive_dependency'))) {
            // A missing/repaired archive may make the same terminal lineage
            // executable later. Retry only on an actual dependency change,
            // never merely on another scheduler minute or new live candle.
            $state['archive_dependency'] = data_get($evidence, 'archive_dependency');
        }
        if (data_get($evidence, 'academy_proposal.status') === 'pending_canonical_admission' && $generation) {
            // An executed typed refusal is not an undelivered publication.
            // It becomes selectable again only when its actual admission
            // dependency changes, not on each scheduler minute. In particular
            // DB job counts cannot attest an unavailable Redis queue backend.
            try {
                $snapshot = app(LabQueueJobInspector::class)->queueSnapshot();
                $queueReady = ($snapshot['available'] ?? true) !== false
                    && is_numeric($snapshot['total'] ?? null)
                    && (int) $snapshot['total'] < max(1, (int) config('services.lab_selection.max_screening_jobs', 40));
                $queueAvailable = ($snapshot['available'] ?? true) !== false;
            } catch (Throwable) {
                $queueReady = false;
                $queueAvailable = false;
            }
            $snapshotAdmission = app(GenerationSnapshotAdmissionService::class)->inspect($generation);
            $reasons = array_values(array_unique((array) ($snapshotAdmission['reasons'] ?? [])));
            sort($reasons);
            $state['academy_admission_dependency'] = [
                'trial_id' => (int) data_get($evidence, 'academy_proposal.trial_id'),
                'generation_id' => $generation->id,
                'queue_available' => $queueAvailable,
                'queue_capacity_ready' => $queueReady,
                'snapshot_allowed' => ($snapshotAdmission['allowed'] ?? false) === true,
                'snapshot_reasons' => $reasons,
            ];
        }
        if ((int) data_get($evidence, 'mtf.powered_prior_id', 0) > 0) {
            // A newly eligible immutable prior is new work, not a timer tick.
            $state['powered_prior_id'] = (int) data_get($evidence, 'mtf.powered_prior_id');
        }

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
    private function mtfAllocation(string $symbol, bool $archiveFirst = false): array
    {
        if ($archiveFirst) {
            $source = app(MtfPoweredPriorValidationService::class)->nextEligible($symbol);

            return [
                'research_due' => false,
                'powered_prior_due' => $source !== null
                    && ! $this->recentAction('SETTLE_MTF_POWERED_PRIOR', 15, $symbol),
                'playbook_prior_due' => false,
                'powered_prior_id' => $source?->id,
                'reason' => 'ARCHIVE_RESEARCH_OWNS_PRE_CHAMPION_EXPLORATION',
                'standalone_maintenance_requires_actionable_evidence' => true,
            ];
        }
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
