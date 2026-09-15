<?php

namespace App\Services;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabLearningLaneDispatch;
use App\Models\LabLifecycleCycle;
use App\Models\ModelMarketPerformance;
use App\Models\SystemEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Production-safe, resumable NeuroTrader agent lifecycle orchestrator.
 *
 * Each cycle is idempotent and lock-protected per symbol/timeframe. It only
 * advances lifecycle states that the existing project gates already allow; it
 * never bypasses screening, risk, paper, champion, or evidence gates, and it
 * never creates duplicate generations, agents, controls, jobs, replays, or
 * promotions.
 *
 * Lifecycle stages this cycle understands:
 *   preflight -> create generation -> create agents/controls -> queue screening
 *   -> screening -> screened -> full validation -> challenger -> forward ->
 *   paper -> champion -> learning settlement -> next gen / bounded recovery.
 */
class LabLifecycleOrchestrator
{
    public const PHASE_PREFLIGHT = 'preflight';

    public const PHASE_GENERATION = 'generation';

    public const PHASE_SCREENING = 'screening';

    public const PHASE_FULL_VALIDATION = 'full_validation';

    public const PHASE_FORWARD = 'forward';

    public const PHASE_LEARNING_RECOVERY = 'learning_recovery';

    public const PHASE_TECHNICAL_RECOVERY = 'technical_recovery';

    public const STATUS_READY = 'ready';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_BLOCKED = 'blocked';

    // A complete 20-seat constructor can legitimately spend well over ten
    // minutes compiling historical/causal contracts. Keep the cycle lease
    // above the scheduled constructor budget so a later five-minute tick
    // cannot mistake live construction for interrupted work.
    public const LOCK_TTL_SECONDS = 3000;

    public const MAX_RECOVERY_DISPATCH = 3;

    private readonly LabLifecycleErrorLogger $errors;

    /** @var array<string, mixed> */
    private array $lastGenerationOutcome = [];

    public function __construct(
        private readonly SystemLogService $logs,
        private readonly LabPopulationService $population,
        private readonly LabQueueStateService $queue,
        private readonly LabQueueJobInspector $queueJobs,
        private readonly LabAgentEvaluationService $evaluation,
        private readonly LabAgentPreflightService $preflight,
        private readonly LearningVelocityGateService $velocity,
        private readonly GenerationAdmissionDecisionService $admission,
        private readonly GenerationConstructionAdmissionService $constructionAdmission,
        private readonly GenerationConstructionReconciliationService $constructionReconciliation,
        private readonly LabGenerationTerminalBoundaryService $terminalBoundaries,
        private readonly LabDataEdgeAuditService $dataEdgeAudits,
    ) {
        $this->errors = new LabLifecycleErrorLogger;
    }

    public function run(string $symbol, string $timeframe = 'H1', ?string $cycleId = null, bool $startCycle = false): array
    {
        $symbol = strtoupper($symbol);
        $timeframe = $this->canonicalLaboratoryTimeframe($symbol, $timeframe);
        $cycleId = $cycleId ?? $this->generateCycleId();
        $lock = Cache::lock($this->lockKey($symbol, $timeframe), $this->lockTtl());
        $acquired = false;
        $stage = self::PHASE_PREFLIGHT;
        $checkpoint = null;

        try {
            if (! ($acquired = $lock->get())) {
                return $this->summarize($cycleId, $symbol, $timeframe, self::STATUS_PAUSED,
                    'Another cycle is already running for this symbol/timeframe.',
                    $stage, ['locked' => true]);
            }

            $this->touchCycle($cycleId);
            $checkpoint = $this->beginCheckpoint($cycleId, $symbol, $timeframe, $stage);
            $this->rotateLifecycleLogs();

            $preflight = $this->preflight($symbol, $timeframe);
            if (! $preflight['healthy']) {
                $this->errors->record($cycleId, $symbol, $timeframe, $stage,
                    new \RuntimeException('Lifecycle preflight blocked: '.(string) $preflight['reason']));

                return $this->summarize($cycleId, $symbol, $timeframe, self::STATUS_BLOCKED,
                    $preflight['reason'], $stage, []);
            }
            $stage = self::PHASE_GENERATION;

            // Operational failures are quarantined independently from
            // strategy evolution. They must not keep healthy peers in a
            // permanent evaluation_error state or freeze the next cycle.
            $quarantined = $this->quarantineEvaluationErrors($symbol, $timeframe, $cycleId, $stage);

            // Retry-budget exhaustion occurs before an evaluator request, so
            // generation admission may otherwise see an ordinary resumable
            // `screening` cohort and never enter its technical-recovery arm.
            // Intercept only this exact one-shot signature before resuming the
            // generation. The recovery command still enforces AI readiness,
            // empty lab queues, daily budget and frozen snapshot hashes.
            $retryAgent = LabAgent::query()
                ->with(['generation', 'modelVersion'])
                ->where('symbol', $symbol)
                ->where('timeframe', $timeframe)
                ->where('lifecycle_status', 'evaluation_error')
                ->latest('id')
                ->get()
                ->first(fn (LabAgent $agent): bool => $this->isRetryBudgetRecoveryPending($agent));
            if (! $startCycle && $retryAgent?->generation) {
                $recovered = $this->technicalRecovery($symbol, $timeframe, $cycleId, [
                    'state' => 'recovery_required',
                    'reason' => GenerationAdmissionDecisionService::RECOVER_TECHNICAL,
                    'generation_admission' => ['latest_generation_id' => (int) $retryAgent->lab_generation_id],
                ]);

                return $this->summarize(
                    $cycleId,
                    $symbol,
                    $timeframe,
                    ($recovered['dispatched'] ?? 0) > 0 ? self::STATUS_RUNNING : self::STATUS_PAUSED,
                    GenerationAdmissionDecisionService::RECOVER_TECHNICAL,
                    self::PHASE_TECHNICAL_RECOVERY,
                    [...$recovered, 'quarantined' => $quarantined],
                );
            }

            // A killed worker can leave the mutable generation projection in
            // screening even after a later bounded attempt made every agent
            // terminal. Repair only when agent, immutable-run, and queue
            // ownership all prove that no work remains.
            $this->terminalBoundaries->closeLatest($symbol, $timeframe);

            // A partial population is resumable only while no screening run
            // exists. Once evaluation has started, completing the remaining
            // seats would mix two different admission states into one cohort.
            // Preserve those runs as diagnostics and close the generation.
            $constructionReconciliation = $this->constructionReconciliation->reconcileLatest($symbol, $timeframe);
            if ((bool) data_get($constructionReconciliation, 'closed', false)) {
                return $this->summarize($cycleId, $symbol, $timeframe, self::STATUS_PAUSED,
                    'Contaminated incomplete generation was closed as diagnostic-only evidence.',
                    $stage, [
                        'construction_reconciliation' => $constructionReconciliation,
                        'next_action' => 'admit_a_fresh_generation_on_the_next_cycle',
                    ]);
            }

            // Strategy gate / deadlock guard: never create a normal generation
            // while locked by the strategy gate. Bounded recovery is allowed.
            $strategy = $this->strategyGateState($symbol, $timeframe, $startCycle);
            if ($strategy['state'] === 'recovery_required') {
                // Explicit cycle start prioritizes healthy strategy evolution.
                // Recovery backlog remains recorded for a later maintenance
                // cycle; it must not freeze every valid agent indefinitely.
                $mandatoryLearningPair = (int) data_get(
                    $strategy,
                    'generation_admission.learning_pair_priority.pair_id',
                    0,
                ) > 0;
                if ($startCycle && ! $mandatoryLearningPair) {
                    $strategy = ['state' => 'open', 'reason' => 'operator_start_prioritizes_healthy_agents', 'quarantined' => $quarantined];
                } else {
                    $technical = ($strategy['reason'] ?? null) === GenerationAdmissionDecisionService::RECOVER_TECHNICAL;
                    $recovered = $technical
                        ? $this->technicalRecovery($symbol, $timeframe, $cycleId, $strategy)
                        : $this->learningRecovery($symbol, $timeframe, $cycleId, $strategy);
                    $stage = $technical ? self::PHASE_TECHNICAL_RECOVERY : self::PHASE_LEARNING_RECOVERY;

                    return $this->summarize($cycleId, $symbol, $timeframe,
                        $recovered['dispatched'] > 0 ? self::STATUS_RUNNING : self::STATUS_PAUSED,
                        $strategy['reason'] ?? 'strategy_deadlock', $stage, [...$recovered, 'quarantined' => $quarantined]);
                }
            }
            if ($strategy['state'] === 'blocked') {
                return $this->summarize($cycleId, $symbol, $timeframe, self::STATUS_PAUSED,
                    $strategy['reason'] ?? 'generation_creation_paused', $stage, $strategy);
            }

            // 1. Generation step (idempotent): only if no active generation.
            $generation = $this->ensureGeneration(
                $symbol,
                $timeframe,
                $cycleId,
                $stage,
                $startCycle,
                (string) data_get($strategy, 'generation_trigger', ''),
            );
            if ($generation === null) {
                $outcome = $this->lastGenerationOutcome;
                $reason = (string) data_get($outcome, 'reason_code', 'GENERATION_CREATION_NOT_ADMITTED');
                $retryable = (bool) data_get($outcome, 'retryable', false);
                if ($reason === 'DATA_EDGE_AUDIT_REQUIRED') {
                    $auditGeneration = LabGeneration::query()
                        ->whereHas('laboratory', fn ($query) => $query
                            ->where('symbol', $symbol)->where('timeframe', $timeframe))
                        ->orderByDesc('generation')->orderByDesc('id')->first();
                    $audit = $auditGeneration
                        ? $this->dataEdgeAudits->recordFromFinalReport($auditGeneration)
                        : ['status' => 'blocked', 'reason_code' => 'GENERATION_NOT_FOUND'];
                    if (in_array((string) data_get($audit, 'status'), ['recorded', 'already_recorded'], true)) {
                        return $this->summarize($cycleId, $symbol, $timeframe, self::STATUS_PAUSED,
                            'Autonomous data/edge audit recorded; successor root portfolio will be retried on the next cycle.',
                            $stage, [
                                'generation_outcome' => [...$outcome, 'retryable' => true],
                                'data_edge_audit' => $audit,
                                'next_action' => 'create_data_edge_root_portfolio_next_cycle',
                            ]);
                    }
                    $outcome['data_edge_audit'] = $audit;
                }
                if ($this->successorRequestPending($symbol, $timeframe)) {
                    $this->recordSuccessorBlock($symbol, $timeframe, $reason, $retryable, $outcome);
                }

                return $this->summarize($cycleId, $symbol, $timeframe, self::STATUS_PAUSED,
                    'Generation creation blocked: '.$reason,
                    $stage, [
                        'generation_outcome' => $outcome,
                        'next_action' => $retryable ? 'retry_next_scheduled_cycle' : 'review_population_admission_reason',
                    ]);
            }
            $constructionAdmission = $this->constructionAdmission->inspect($generation);
            if (! (bool) data_get($constructionAdmission, 'allowed', false)) {
                return $this->summarize($cycleId, $symbol, $timeframe, self::STATUS_PAUSED,
                    'Generation construction is incomplete; screening remains fail-closed.',
                    $stage, [
                        'generation_outcome' => $this->lastGenerationOutcome,
                        'construction_admission' => $constructionAdmission,
                        'next_action' => 'continue_bounded_generation_construction',
                    ]);
            }
            if ((bool) ($strategy['consume_successor_request'] ?? false)
                && ((string) data_get($this->lastGenerationOutcome, 'status') === 'created'
                    || (string) $generation->trigger_type === 'operator_successor')) {
                $this->consumeSuccessorRequest($symbol, $timeframe, (int) $generation->id);
            }
            $stage = self::PHASE_GENERATION;

            // 2. Screening step.
            $this->dispatchScreening($generation, $cycleId, $stage);
            $stage = self::PHASE_SCREENING;

            // 3. Full validation step.
            $this->dispatchFullValidation($generation, $cycleId, $stage);
            $stage = self::PHASE_FULL_VALIDATION;

            // 4. Forward/paper step (observability only; gates enforced by project).
            $this->observeForward($generation, $cycleId, $stage);
            $stage = self::PHASE_FORWARD;

            $summary = $this->summarize($cycleId, $symbol, $timeframe, self::STATUS_COMPLETED,
                'Cycle advanced one pass; awaiting evidence.', $stage, [
                    'generation_id' => $generation->id,
                    'generation' => $generation->generation,
                ]);

            $this->logCycle($cycleId, $summary);

            return $summary;
        } catch (Throwable $e) {
            $this->errors->record($cycleId, $symbol, $timeframe, $stage, $e);

            return $this->summarize($cycleId, $symbol, $timeframe, self::STATUS_BLOCKED,
                'Cycle failed closed; see the lifecycle error log for the safe diagnostic.', $stage, ['exception' => $e::class]);
        } finally {
            if ($checkpoint !== null) {
                // summarize() persists the semantic terminal state. The
                // finally block owns timestamps only; overwriting every result
                // with `finished` destroys whether a cycle completed, paused,
                // or failed closed.
                $checkpoint->update(['stage' => $stage, 'heartbeat_at' => now()->utc(), 'finished_at' => now()->utc()]);
            }
            if ($acquired) {
                $lock->release();
            }
        }
    }

    private function preflight(string $symbol, string $timeframe): array
    {
        $queueSnapshot = $this->queue->snapshot($this->labQueues());
        if (($queueSnapshot['available'] ?? true) === false) {
            return ['healthy' => false, 'reason' => 'queue_transport_unavailable'];
        }
        $risk = $this->queueRisk($queueSnapshot, $symbol, $timeframe);
        if ($risk !== null) {
            return ['healthy' => false, 'reason' => $risk, 'queue' => $queueSnapshot];
        }
        // Active replays are legitimate work, not an outage. The learning-lane
        // queue mutex remains responsible for heavy-work admission.
        $aiHealthy = $this->aiServiceHealthy();
        $schedulerFresh = $this->schedulerHeartbeatFresh();

        if (! $aiHealthy || ! $schedulerFresh) {
            return ['healthy' => false,
                'reason' => 'runtime_unhealthy',
                'ai_healthy' => $aiHealthy,
                'scheduler_fresh' => $schedulerFresh,
            ];
        }

        return ['healthy' => true, 'reason' => 'ok', 'queue' => $queueSnapshot];
    }

    private function strategyGateState(string $symbol, string $timeframe, bool $startCycle = false): array
    {
        $lab = AiLaboratory::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->first();
        if (! $lab) {
            return ['state' => 'blocked', 'reason' => 'LABORATORY_NOT_FOUND', 'actionable_pending_dojo' => 0];
        }
        $latest = $lab->generations()->latest('generation')->first();

        // A persisted immutable population plan has already passed generation
        // admission. It must finish before learning recovery or any successor
        // admission can own the constructor lane. Otherwise an eligible causal
        // lesson can repeatedly request a new generation while the incomplete
        // lineage head prevents that generation from being created, leaving
        // both operations deadlocked. This exception cannot create a new
        // generation: ensureGeneration() may only resume the existing plan.
        if (LabPopulationService::constructionIncomplete($latest)) {
            $plannedSlots = count((array) data_get($latest?->trigger_context, 'generation_plan', []));

            return [
                'state' => 'open',
                'reason' => 'INCOMPLETE_GENERATION_OWNS_CONSTRUCTION_PRIORITY',
                'actionable_pending_dojo' => 0,
                'consume_successor_request' => false,
                'generation_admission' => [
                    'decision' => 'RESUME_EXISTING_GENERATION',
                    'latest_generation_id' => (int) $latest->id,
                    'planned_slots' => $plannedSlots,
                    'created_slots' => $latest->agents()->count(),
                ],
            ];
        }

        // An admitted, fully-constructed cohort owns the runtime until it is
        // terminal. Recomputing the next-generation learning/admission graph
        // on every screening tick cannot change that fact and used to hold
        // the serialized constructor worker for minutes. Technical retry
        // signatures have already been intercepted before this method.
        if ($latest && in_array((string) $latest->status, [
            'draft', 'queued', 'training', 'screening', 'full_queued', 'full_validation',
        ], true)) {
            return [
                'state' => 'open',
                'reason' => 'ACTIVE_GENERATION_OWNS_RUNTIME',
                'actionable_pending_dojo' => 0,
                'consume_successor_request' => false,
                'generation_admission' => [
                    'decision' => 'RESUME_EXISTING_GENERATION',
                    'latest_generation_id' => (int) $latest->id,
                    'latest_generation_status' => (string) $latest->status,
                ],
            ];
        }

        $pendingSuccessor = $this->successorRequestPending($symbol, $timeframe);

        $decision = $this->admission->decide($lab, $latest, [
            'trigger' => ($startCycle || $pendingSuccessor) ? 'operator_successor' : 'new_data',
            'operator_approved_successor' => $startCycle || $pendingSuccessor,
            'force' => $startCycle,
            'source' => 'lifecycle_orchestrator',
        ]);
        $actionable = (int) data_get($decision, 'learning_velocity.learning_starvation.actionable_pending_dojo', 0);
        $consume = $pendingSuccessor && $latest && in_array((string) $latest->status, [
            'screened', 'completed', 'technical_quarantine', 'abandoned', 'failed',
        ], true);
        $typed = (string) data_get($decision, 'decision', GenerationAdmissionDecisionService::BLOCK_HARD);
        if ($typed === GenerationAdmissionDecisionService::DISPATCH_LEARNING
            && (int) data_get($decision, 'learning_pair_priority.pair_id', 0) <= 0
            && (int) data_get($decision, 'causal_confirmation_priority.lesson_id', 0) > 0) {
            // DISPATCH_LEARNING has two intentionally distinct consumers.
            // Dojo rows use learningRecovery(); a canonical target-aligned
            // lesson needs a new guided/blinded/frozen-control generation.
            // Sending the latter through the Dojo branch dispatches zero
            // work and permanently starves the reserved causal curriculum.
            return [
                'state' => 'open',
                'reason' => 'CAUSAL_CONFIRMATION_GENERATION_PRIORITY',
                'generation_trigger' => 'learning_confirmation',
                'actionable_pending_dojo' => $actionable,
                'consume_successor_request' => false,
                'generation_admission' => $decision,
            ];
        }
        if (in_array($typed, [GenerationAdmissionDecisionService::DISPATCH_LEARNING, GenerationAdmissionDecisionService::RECOVER_TECHNICAL], true)) {
            return [
                'state' => 'recovery_required',
                'reason' => $typed,
                'actionable_pending_dojo' => $actionable,
                'generation_admission' => $decision,
            ];
        }
        if ($typed === GenerationAdmissionDecisionService::BLOCK_HARD) {
            return [
                'state' => 'blocked',
                'reason' => implode(',', (array) data_get($decision, 'reason_codes', [])) ?: $typed,
                'actionable_pending_dojo' => $actionable,
                'generation_admission' => $decision,
            ];
        }

        return [
            'state' => 'open',
            'reason' => $typed,
            'actionable_pending_dojo' => $actionable,
            'consume_successor_request' => $consume,
            'generation_admission' => $decision,
        ];
    }

    private function legacyStrategyGateState(string $symbol, string $timeframe): array
    {
        // Delegate to the existing learning-protocol safety service rather than
        // re-implementing the strategy deadlock / starvation heuristic.
        if (! app(LearningProtocolSafetyService::class)->generationCreationPaused()) {
            return ['state' => 'open', 'reason' => 'strategy_gate_open',
                'actionable_pending_dojo' => 0];
        }

        // Count actionable pending dojo tasks as pending screening/full jobs;
        // legacy‑invalid queues are intentionally not trusted here.
        $pending = (int) ($this->queue->snapshot($this->labQueues())['total'] ?? 0);

        return [
            'state' => 'blocked',
            'reason' => 'strategy_deadlock_or_learning_starvation',
            'actionable_pending_dojo' => $pending,
        ];
    }

    private function learningRecovery(string $symbol, string $timeframe, string $cycleId, array $strategy): array
    {
        // Bounded, operator-approved recovery. Reuse the append-only recovery
        // contract the project already ships; never exceeds 3 valid jobs.
        $limit = min(self::MAX_RECOVERY_DISPATCH,
            max(1, (int) config('services.lifecycle_orchestrator.max_recovery_dispatch', self::MAX_RECOVERY_DISPATCH)));

        $dispatched = 0;
        $records = [];

        $priorityPairId = (int) data_get($strategy, 'generation_admission.learning_pair_priority.pair_id', 0);
        if ($priorityPairId > 0) {
            try {
                Artisan::call('trading:pump-learning-lane', [
                    0 => strtoupper($symbol),
                    '--timeframe' => strtoupper($timeframe),
                    '--limit' => 1,
                    '--pair-id' => $priorityPairId,
                    '--autonomous' => true,
                ]);
                $records['priority_pair_id'] = $priorityPairId;
                $records['learning_lane_output'] = trim(Artisan::output());
                $dispatched = LabLearningLaneDispatch::query()
                    ->where('pair_id', $priorityPairId)
                    ->whereIn('status', ['selected', 'queued', 'running'])
                    ->count();
            } catch (Throwable $e) {
                $this->errors->record($cycleId, $symbol, $timeframe, self::PHASE_LEARNING_RECOVERY, $e);
                $records['error_class'] = $e::class;
            }

            return [
                'dispatched' => $dispatched,
                'limit' => 1,
                'records' => $records,
                'strategy' => $strategy,
                'priority_learning_pair' => true,
            ];
        }

        $cooldownKey = 'lifecycle-recovery:'.strtoupper($symbol).':'.strtoupper($timeframe);
        $dailyLimit = max(1, (int) config('services.lifecycle_orchestrator.max_recovery_dispatch_per_day', 9));
        // This budget protects the exceptional recovery lane. Normal
        // autonomous/full-replay dispatches have their own admission limits
        // and must not consume recovery seats, otherwise a healthy replay day
        // can strand newly-created actionable dojo work until midnight.
        $todayDispatches = LabLearningLaneDispatch::query()
            ->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))
            ->where('dispatch_key', 'like', 'recovery:dojo:%')
            ->where('selected_at', '>=', now('Asia/Tashkent')->startOfDay()->utc())->count();
        $allocatedMicroSeats = LabLearningLaneDispatch::query()
            ->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))
            ->where('stage', 'micro')->where('micro_status', 'pending')
            ->whereIn('status', ['retry_ready', 'selected'])->count();
        if (Cache::has($cooldownKey) || ($todayDispatches >= $dailyLimit && $allocatedMicroSeats === 0)) {
            return ['dispatched' => 0, 'limit' => $limit, 'records' => [], 'strategy' => $strategy,
                'paused_reason' => Cache::has($cooldownKey) ? 'recovery_cooldown' : 'daily_recovery_budget_exhausted'];
        }

        if ((int) data_get($strategy, 'actionable_pending_dojo', 0) > 0) {
            try {
                $remainingDailySeats = max(0, $dailyLimit - $todayDispatches);
                $reconciled = 0;
                if ($remainingDailySeats > 0) {
                    Artisan::call('trading:reconcile-learning-recovery', [
                        'symbol' => strtoupper($symbol),
                        '--timeframe' => $timeframe,
                        // Never allocate beyond the remaining daily budget.
                        '--limit' => min($limit, $remainingDailySeats),
                        '--apply' => true,
                        '--autonomous' => true,
                        '--json' => true,
                    ]);
                    $out = json_decode(Artisan::output(), true);
                    $records = is_array($out) ? $out : [];
                    $reconciled = (int) data_get($records, 'dojo_recovery_queued', 0);
                } else {
                    // A seat already counted against the daily allocation
                    // must be allowed to reach a terminal state. Blocking its
                    // execution here strands retry_ready work forever.
                    $records = [
                        'dojo_recovery_queued' => 0,
                        'reconciliation_skipped_reason' => 'daily_budget_full_existing_retry',
                        'allocated_micro_seats' => $allocatedMicroSeats,
                    ];
                }

                // Reconciliation creates retry_ready records only. Existing
                // actionable rows may already have been reconciled by an
                // earlier cycle, so dispatch must not depend on this cycle
                // creating a new row. The command remains bounded and
                // idempotent over the same verified pair identities.
                Artisan::call('trading:dispatch-learning-lane', [
                    'symbol' => strtoupper($symbol),
                    '--timeframe' => $timeframe,
                    '--limit' => $limit,
                    '--autonomous' => true,
                    // Recovery consumes the already-paired, immutable
                    // frontier. Re-materializing and deduplicating every
                    // historical response map here made a one-seat cycle
                    // memory-unbounded and delayed the scheduler for minutes.
                    '--retry-queued' => true,
                ]);
                $dispatched = LabLearningLaneDispatch::query()
                    ->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))
                    ->whereIn('status', ['selected', 'queued', 'running'])
                    ->count();
                $records['reconciled'] = $reconciled;
                $records['learning_lane_output'] = trim(Artisan::output());
                if ($dispatched > 0) {
                    Cache::put($cooldownKey, true, now()->addSeconds(max(60, (int) config('services.lifecycle_orchestrator.recovery_cooldown_seconds', 900))));
                }
            } catch (Throwable $e) {
                $this->errors->record($cycleId, $symbol, $timeframe, self::PHASE_LEARNING_RECOVERY, $e);
            }
        }

        return [
            'dispatched' => $dispatched,
            'limit' => $limit,
            'records' => $records,
            'strategy' => $strategy,
        ];
    }

    /**
     * Re-open only the latest generation's immutable transport timeouts.
     *
     * This is deliberately separate from learning recovery: it has its own
     * daily budget, one-shot per-agent counter, audited machine authority,
     * authenticated AI readiness probe and frozen dataset hash contract.
     */
    private function technicalRecovery(string $symbol, string $timeframe, string $cycleId, array $strategy): array
    {
        if (! (bool) config('services.lifecycle_orchestrator.autonomous_technical_recovery_enabled', false)) {
            return ['dispatched' => 0, 'strategy' => $strategy, 'paused_reason' => 'autonomous_technical_recovery_disabled'];
        }

        $generationId = (int) data_get($strategy, 'generation_admission.latest_generation_id', 0);
        $generation = $generationId > 0 ? LabGeneration::query()->find($generationId) : null;
        if (! $generation) {
            return ['dispatched' => 0, 'strategy' => $strategy, 'paused_reason' => 'technical_recovery_generation_missing'];
        }

        $limit = max(1, min(2, (int) config('services.lifecycle_orchestrator.autonomous_technical_recovery_max_dispatch', 2)));
        $dailyLimit = max(1, (int) config('services.lifecycle_orchestrator.autonomous_technical_recovery_daily_limit', 2));
        $today = now('Asia/Tashkent')->startOfDay()->utc();
        $todayDispatches = SystemEvent::query()
            ->where('event_type', 'lab_autonomous_recovery_authorization')
            ->where('occurred_at', '>=', $today)
            ->get()
            ->sum(fn (SystemEvent $event): int => count((array) data_get($event->payload, 'agent_ids', [])));
        $remaining = max(0, $dailyLimit - $todayDispatches);
        if ($remaining === 0) {
            return ['dispatched' => 0, 'strategy' => $strategy, 'paused_reason' => 'daily_technical_recovery_budget_exhausted'];
        }

        $cooldownKey = 'lifecycle-technical-recovery:'.strtoupper($symbol).':'.strtoupper($timeframe);
        if (Cache::has($cooldownKey)) {
            return ['dispatched' => 0, 'strategy' => $strategy, 'paused_reason' => 'technical_recovery_cooldown'];
        }

        try {
            // A frozen-control waiter can expire during a Redis/worker outage
            // without ever calling the evaluator. Recover that exact
            // infrastructure signature through the same daily, one-shot,
            // hash-verified authority as transport timeouts. It never
            // broadens recovery to an economic/quality failure.
            $retryBudgetPending = $generation->agents()->with('modelVersion')
                ->whereIn('lifecycle_status', ['evaluation_error', 'technical_quarantine'])
                ->get()
                ->contains(fn (LabAgent $agent): bool => $this->isRetryBudgetRecoveryPending($agent));
            $exitCode = Artisan::call('trading:recover-lab-evaluation-errors', [
                'symbol' => strtoupper($symbol),
                '--timeframe' => strtoupper($timeframe),
                '--generation' => (int) $generation->generation,
                '--limit' => min($limit, $remaining),
                '--mode' => 'screen',
                $retryBudgetPending ? '--after-retry-budget-repair' : '--after-timeout-budget-repair' => true,
                '--apply' => true,
                '--autonomous' => true,
                '--json' => true,
            ]);
            $output = trim(Artisan::output());
            $record = json_decode($output, true);
            $dispatched = $exitCode === 0 && is_array($record) ? (int) ($record['dispatched'] ?? 0) : 0;
            if ($dispatched > 0) {
                Cache::put($cooldownKey, true, now()->addSeconds(max(60, (int) config(
                    'services.lifecycle_orchestrator.autonomous_technical_recovery_cooldown_seconds',
                    60,
                ))));
            }

            return [
                'dispatched' => $dispatched,
                'limit' => min($limit, $remaining),
                'records' => is_array($record) ? $record : ['command_output' => $output, 'exit_code' => $exitCode],
                'strategy' => $strategy,
            ];
        } catch (Throwable $e) {
            $this->errors->record($cycleId, $symbol, $timeframe, self::PHASE_TECHNICAL_RECOVERY, $e, (int) $generation->id);

            return ['dispatched' => 0, 'strategy' => $strategy, 'paused_reason' => 'technical_recovery_failed_closed'];
        }
    }

    private function ensureGeneration(string $symbol, string $timeframe, string $cycleId, string $stage, bool $startCycle = false, ?string $admittedTrigger = null): ?LabGeneration
    {
        $this->lastGenerationOutcome = [
            'status' => 'blocked', 'reason_code' => 'GENERATION_CREATION_NOT_STARTED', 'retryable' => false,
        ];
        // Construction authority belongs only to the newest generation in
        // the lineage. Older draft/technical-quarantine rows are immutable
        // historical evidence; attempting to resume one after a successor
        // exists can permanently hide that active successor from lifecycle
        // dispatch. Resolve the lineage head once and make every branch below
        // operate on that same row.
        $latest = LabGeneration::query()
            ->whereHas('laboratory', fn ($q) => $q->where('symbol', $symbol)->where('timeframe', $timeframe))
            ->orderByDesc('generation')
            ->orderByDesc('id')
            ->first();
        // A brand-new 'draft' generation with no queued/queued-screening work is
        // not yet an active pipeline: it is handed off to this very cycle. Any
        // generation that has already entered queued/screening/full stages is
        // resumable and must not be duplicated.
        $draft = $latest !== null && (string) $latest->status === 'draft' ? $latest : null;
        if ($draft !== null) {
            $plannedSlots = count((array) data_get($draft->trigger_context, 'generation_plan', []));
            $existingSlots = $draft->agents()->count();
            if ($plannedSlots > 0 && $existingSlots < $plannedSlots) {
                // The generation row and immutable plan are persisted before
                // its agents are compiled. A normal 20-seat build can remain
                // draft for many minutes, so a later scheduler tick must not
                // run the recovery constructor concurrently. Only the same
                // age boundary used for interrupted-draft recovery may take
                // ownership of missing slots.
                $constructorIsActive = $this->population->constructorIsActive($symbol, $timeframe);
                $constructionIsStale = ! $constructorIsActive
                    || $draft->created_at === null
                    || $draft->created_at->lt(now()->subSeconds($this->draftTimeout()));
                if (! $constructionIsStale) {
                    $this->lastGenerationOutcome = [
                        'status' => 'existing',
                        'reason_code' => 'GENERATION_CONSTRUCTION_ACTIVE',
                        'retryable' => true,
                        'generation_id' => (int) $draft->id,
                        'planned_slots' => $plannedSlots,
                        'created_slots' => $existingSlots,
                    ];

                    return $draft;
                }
                $continuation = $this->population->continueInterruptedConstruction((int) $draft->id, 4);
                $continued = $continuation['generation'] ?? $draft->fresh(['laboratory']);
                $complete = (string) data_get($continuation, 'status') === 'complete';
                $this->lastGenerationOutcome = [
                    'status' => $complete ? 'created' : 'blocked',
                    'reason_code' => $complete ? 'GENERATION_CONSTRUCTION_COMPLETE' : 'GENERATION_CONSTRUCTION_IN_PROGRESS',
                    'retryable' => ! $complete,
                    'generation_id' => (int) $draft->id,
                    'created_slots' => (array) data_get($continuation, 'created_slots', []),
                    'completed_slots' => (array) data_get($continuation, 'completed_slots', []),
                    'failures' => (array) data_get($continuation, 'failures', []),
                ];

                return $complete ? $continued : null;
            }
            $this->lastGenerationOutcome = ['status' => 'existing', 'reason_code' => 'DRAFT_GENERATION_RESUMABLE', 'retryable' => true, 'generation_id' => (int) $draft->id];

            return $draft;
        }

        $quarantinedDraft = $latest !== null && (string) $latest->status === 'technical_quarantine'
            ? $latest
            : null;
        if ($quarantinedDraft !== null) {
            $plannedSlots = count((array) data_get($quarantinedDraft->trigger_context, 'generation_plan', []));
            $existingSlots = $quarantinedDraft->agents()->count();
            if ($plannedSlots > 0 && $existingSlots < $plannedSlots) {
                $continuation = $this->population->continueInterruptedConstruction((int) $quarantinedDraft->id, 4);
                $complete = (string) data_get($continuation, 'status') === 'complete';
                $this->lastGenerationOutcome = [
                    'status' => $complete ? 'created' : 'blocked',
                    'reason_code' => $complete ? 'GENERATION_CONSTRUCTION_COMPLETE' : 'GENERATION_CONSTRUCTION_IN_PROGRESS',
                    'retryable' => ! $complete,
                    'generation_id' => (int) $quarantinedDraft->id,
                    'created_slots' => (array) data_get($continuation, 'created_slots', []),
                    'completed_slots' => (array) data_get($continuation, 'completed_slots', []),
                    'failures' => (array) data_get($continuation, 'failures', []),
                ];

                return $complete ? ($continuation['generation'] ?? $quarantinedDraft->fresh(['laboratory'])) : null;
            }
        }

        // Historical interrupted cohorts remain auditable, but only the most
        // recent generation may block its successor. A stale older `queued`
        // row must never freeze the laboratory forever.
        $active = $latest !== null && in_array((string) $latest->status,
            ['queued', 'screening', 'full_queued', 'training', 'full_validation'], true);

        if ($active) {
            // An active generation is resumable work, not a creation error.
            // Return it so the same idempotent cycle can continue dispatching
            // undelivered screening/full-validation work instead of reporting
            // a false admission block on every scheduler tick.
            $this->lastGenerationOutcome = ['status' => 'existing', 'reason_code' => 'LATEST_GENERATION_RESUMABLE', 'retryable' => true, 'generation_id' => (int) $latest->id];

            return $latest->fresh(['laboratory']);
        }

        try {
            $trigger = $admittedTrigger === 'learning_confirmation'
                ? 'learning_confirmation'
                : (($startCycle || $this->successorRequestPending($symbol, $timeframe))
                    ? 'operator_successor'
                    : ((string) data_get($latest?->trigger_context, 'data_edge_audit.protocol') === LabDataEdgeAuditService::PROTOCOL
                        && (string) data_get($latest?->trigger_context, 'latest_generation_report.next_action') === 'data_edge_audit_completed'
                            ? 'data_edge_audit'
                            : 'new_data'));
            $generation = $this->population->build($symbol, $trigger, $trigger === 'operator_successor', $timeframe);
            $this->lastGenerationOutcome = $this->population->lastBuildOutcome();

            return $generation;
        } catch (Throwable $e) {
            $this->errors->record($cycleId, $symbol, $timeframe, $stage, $e);
            throw $e; // fail closed via outer try/catch -> blocked
        }
    }

    /** @return array<int, int> */
    private function quarantineEvaluationErrors(string $symbol, string $timeframe, string $cycleId, string $stage): array
    {
        $agents = LabAgent::query()
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->where('lifecycle_status', 'evaluation_error')
            ->with(['generation', 'modelVersion'])
            ->get();
        $ids = [];
        foreach ($agents as $agent) {
            // This exact failure happened before an evaluator request and is
            // still eligible for the lifecycle's one-shot, daily-bounded,
            // frozen-snapshot recovery. Quarantining it here would erase the
            // classifier before technicalRecovery() can select it. After its
            // single repair attempt is consumed, the next cycle isolates it
            // like every other unresolved evaluator error.
            if ($this->isRetryBudgetRecoveryPending($agent)) {
                continue;
            }

            $agent->update([
                'lifecycle_status' => 'technical_quarantine',
                'decision_reason' => 'Technical quarantine: evaluator error isolated by lifecycle cycle; strategy verdict withheld.',
            ]);
            $ids[] = (int) $agent->id;
            try {
                app(LabImmutableEvidenceService::class)->recordLifecycle($agent->fresh(), 'technical_quarantine', [
                    'reason_code' => 'EVALUATION_ERROR_ISOLATED',
                    'cycle_id' => $cycleId,
                    'strategy_verdict' => 'withheld',
                    'promotion_evidence' => false,
                ], $stage, null, null, self::class);
            } catch (Throwable $e) {
                $this->errors->record($cycleId, $symbol, $timeframe, $stage, $e, (int) $agent->lab_generation_id, (int) $agent->id);
            }
        }

        return $ids;
    }

    private function isRetryBudgetRecoveryPending(LabAgent $agent): bool
    {
        $reason = strtolower((string) $agent->decision_reason);
        $classification = app(TechnicalFailureClassifierService::class)->forAgent($agent);

        return (int) data_get($agent->modelVersion?->metadata, 'retry_budget_repair_recovery_attempts', 0) < 1
            && str_contains($reason, 'strategy verdict withheld')
            && (string) data_get($classification, 'reason_code') === 'REPLAY_RETRY_BUDGET_EXHAUSTED';
    }

    private function dispatchScreening(LabGeneration $generation, string $cycleId, string $stage): void
    {
        if ($generation->status === 'draft') {
            try {
                LabGeneration::query()->where('id', (int) $generation->id)
                    ->update(['status' => 'queued']);
                $generation->status = 'queued';
            } catch (Throwable $e) {
                $this->errors->record($cycleId, (string) $generation->laboratory->symbol,
                    (string) $generation->laboratory->timeframe, $stage, $e, (int) $generation->id);

                return;
            }
        }

        foreach ($generation->agents()->whereIn('lifecycle_status', ['draft', 'queued'])->cursor() as $agent) {
            // Explicit technical recovery owns its frozen contract and queue
            // batch. If that batch is cancelled or disappears, silently
            // replacing it with an ordinary job would drop both the recovery
            // contract and batch cancellation authority. Fail closed and let
            // the bounded recovery/reconciliation path decide what happens.
            if ($this->isExplicitTechnicalRecoveryDispatch($agent)) {
                continue;
            }
            if ($this->agentHasScreeningJob((int) $agent->id)) {
                continue;
            } // idempotency

            try {
                EvaluateLabAgentJob::dispatch((int) $agent->id, $agent->symbol, 'screen');
            } catch (Throwable $e) {
                $this->errors->record($cycleId, (string) $agent->symbol, (string) $agent->timeframe,
                    $stage, $e, (int) $generation->id, (int) $agent->id);
            }
        }
    }

    private function dispatchFullValidation(LabGeneration $generation, string $cycleId, string $stage): void
    {
        $eligible = $generation->agents()
            ->where('lifecycle_status', 'screened')
            ->whereExists(fn ($q) => $q->from('candidate_gate_decisions')
                ->select(DB::raw(1))
                ->whereColumn('lab_agent_id', 'lab_agents.id')
                ->where('stage', 'screening')
                ->where('decision', 'passed'))
            ->get();

        foreach ($eligible as $agent) {
            if ($this->agentHasFullJob((int) $agent->id)) {
                continue;
            } // idempotency

            try {
                EvaluateLabAgentJob::dispatch((int) $agent->id, $agent->symbol, 'full');
            } catch (Throwable $e) {
                $this->errors->record($cycleId, (string) $agent->symbol, (string) $agent->timeframe,
                    $stage, $e, (int) $generation->id, (int) $agent->id);
            }
        }
    }

    private function observeForward(LabGeneration $generation, string $cycleId, string $stage): void
    {
        // Read-only observation of forward/paper progress; no promotion here.
        $screened = LabAgent::where('lab_generation_id', $generation->id)
            ->where('lifecycle_status', 'screened')->count('id');

        $forward = ModelMarketPerformance::where('symbol', $generation->laboratory->symbol)
            ->where('timeframe', $generation->laboratory->timeframe)
            ->where('status', 'forward_validated')->count('id');

        $this->logs->write('LIFECYCLE_CYCLE_FORWARD_OBSERVATION',
            'Observed forward/paper progress without changing promotion state.',
            [
                'generation_id' => $generation->id,
                'generation' => $generation->generation,
                'screened_agents' => $screened,
                'forward_validated' => $forward,
            ], 'info', 'lifecycle_orchestrator', $stage, 'forward');
    }

    private function agentHasScreeningJob(int $agentId): bool
    {
        return $this->queueJobs->hasAgentJob($agentId, [(string) config('services.lab_queue.screening_queue', 'lab-screening')]);
    }

    private function isExplicitTechnicalRecoveryDispatch(LabAgent $agent): bool
    {
        $metadata = (array) ($agent->modelVersion?->metadata ?? []);
        $attempts = (int) data_get($metadata, 'evaluator_recovery_attempts', 0);
        $lastRecovery = data_get($metadata, 'last_evaluator_recovery_at');
        if ($attempts < 1 || ! is_string($lastRecovery) || trim($lastRecovery) === '') {
            return false;
        }

        try {
            $recoveryAt = Carbon::parse($lastRecovery);
        } catch (Throwable) {
            return false;
        }

        // Model metadata can be cloned into a later descendant. Only a
        // recovery recorded after this exact agent was created owns its queue
        // admission; inherited history must not suppress ordinary screening.
        return $agent->created_at !== null && $recoveryAt->greaterThanOrEqualTo($agent->created_at);
    }

    private function agentHasFullJob(int $agentId): bool
    {
        return $this->queueJobs->hasAgentJob($agentId, [(string) config('services.lab_queue.full_validation_queue', 'lab-full-validation')]);
    }

    private function aiServiceHealthy(): bool
    {
        $url = rtrim((string) config('services.ai_service.url'), '/').'/api/replay-status';
        $token = (string) config('services.internal_api.token');
        if ($url === '/api/replay-status' || $token === '') {
            return false;
        }

        try {
            $response = Http::connectTimeout(2)->timeout(4)
                ->withHeaders(['X-Internal-Token' => $token])->get($url);
            if ($response->failed()) {
                return false;
            }
            $body = $response->json();

            return (string) data_get($body, 'protocol', '') !== ''
                && (int) data_get($body, 'active_requests', -1) >= 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function schedulerHeartbeatFresh(): bool
    {
        $heartbeat = Cache::get('system:scheduler-heartbeat');
        if (! $heartbeat) {
            return false;
        }
        try {
            return Carbon::parse((string) $heartbeat)->greaterThanOrEqualTo(
                now()->subSeconds(max(60, (int) config('services.scheduler.lease_seconds', 900)))
            );
        } catch (Throwable) {
            return false;
        }
    }

    private function labQueues(): array
    {
        return array_values(array_unique(array_merge(
            [(string) config('services.lab_queue.screening_queue', 'lab-screening')],
            [(string) config('services.lab_queue.full_validation_queue', 'lab-full-validation')],
            [(string) config('services.lab_queue.frontier_queue', 'lab-frontier')],
            [(string) config('services.lab_queue.learning_queue', 'lab-learning')],
        )));
    }

    private function lockKey(string $symbol, string $timeframe): string
    {
        return 'lifecycle-cycle:'.strtoupper($symbol).':'.$timeframe;
    }

    private function lockTtl(): int
    {
        return max(60, (int) config('services.lifecycle_orchestrator.lock_ttl_seconds', self::LOCK_TTL_SECONDS));
    }

    private function successorRequestPending(string $symbol, string $timeframe): bool
    {
        $event = SystemEvent::query()->where('event_key', "lifecycle:successor-request:{$symbol}:{$timeframe}")->first();

        return (string) data_get($event?->payload, 'status') === 'pending';
    }

    private function currentGenerationTerminal(string $symbol, string $timeframe): bool
    {
        $generation = LabGeneration::query()->whereHas('laboratory', fn ($q) => $q->where('symbol', $symbol)->where('timeframe', $timeframe))->latest('id')->first();
        if (! $generation || ! in_array((string) $generation->status, ['screened', 'completed', 'abandoned', 'failed'], true)) {
            return false;
        }

        return ! $generation->agents()->whereIn('lifecycle_status', ['draft', 'queued', 'screening', 'training', 'full_queued', 'full_validation'])->exists();
    }

    private function currentGenerationIsOperatorSuccessor(string $symbol, string $timeframe): bool
    {
        $generation = LabGeneration::query()
            ->whereHas('laboratory', fn ($q) => $q->where('symbol', $symbol)->where('timeframe', $timeframe))
            ->latest('id')->first();

        return $generation !== null
            && (string) $generation->trigger_type === 'operator_successor'
            && in_array((string) $generation->status, ['draft', 'queued', 'screening', 'full_queued', 'training', 'full_validation'], true);
    }

    private function currentGenerationNeedsConstruction(string $symbol, string $timeframe): bool
    {
        $generation = LabGeneration::query()
            ->whereHas('laboratory', fn ($q) => $q->where('symbol', $symbol)->where('timeframe', $timeframe))
            ->latest('id')->first();
        if ($generation === null || (string) $generation->trigger_type !== 'operator_successor') {
            return false;
        }
        if (! in_array((string) $generation->status, ['draft', 'technical_quarantine'], true)) {
            return false;
        }

        $plannedSlots = count((array) data_get($generation->trigger_context, 'generation_plan', []));

        return $plannedSlots > 0 && $generation->agents()->count() < $plannedSlots;
    }

    private function consumeSuccessorRequest(string $symbol, string $timeframe, int $generationId): void
    {
        $event = SystemEvent::query()->where('event_key', "lifecycle:successor-request:{$symbol}:{$timeframe}")->first();
        if (! $event) {
            return;
        }
        $event->update(['payload' => [...((array) $event->payload), 'status' => 'consumed', 'successor_generation_id' => $generationId, 'consumed_at' => now()->utc()->toIso8601String()]]);
    }

    /** @param array<string, mixed> $outcome */
    private function recordSuccessorBlock(string $symbol, string $timeframe, string $reason, bool $retryable, array $outcome): void
    {
        $event = SystemEvent::query()->where('event_key', "lifecycle:successor-request:{$symbol}:{$timeframe}")->first();
        if (! $event) {
            return;
        }
        $payload = (array) $event->payload;
        $attempts = (int) ($payload['creation_attempts'] ?? 0) + 1;
        $event->update(['payload' => [...$payload,
            'status' => 'pending',
            'creation_attempts' => $attempts,
            'last_attempt_at' => now()->utc()->toIso8601String(),
            'last_block_reason' => $reason,
            'last_block_retryable' => $retryable,
            'last_outcome' => $outcome,
            'next_action' => $retryable ? 'retry_next_scheduled_cycle' : 'operator_review_population_admission_reason',
        ]]);
    }

    private function draftTimeout(): int
    {
        return max(60, (int) config('services.lifecycle_orchestrator.draft_timeout_seconds', 5400));
    }

    private function queueRisk(array $snapshot, string $symbol, string $timeframe): ?string
    {
        // Research-only toolbox priors cannot promote an agent and must not
        // poison the canonical generation circuit breaker when they yield or
        // retry. Canonical screening/full-validation rows remain fail-closed.
        $canonicalRows = collect((array) ($snapshot['rows'] ?? []))->reject(function (array $row): bool {
            $payload = (string) ($row['payload'] ?? '');

            return str_contains($payload, 'App\\\\Jobs\\\\EvaluateMtfPlaybookPriorJob')
                || str_contains($payload, 'App\\Jobs\\EvaluateMtfPlaybookPriorJob')
                || str_contains($payload, 'App\\\\Jobs\\\\ValidateMtfPoweredPriorJob')
                || str_contains($payload, 'App\\Jobs\\ValidateMtfPoweredPriorJob');
        });
        $maxAttempts = (int) ($canonicalRows->max(fn (array $row): int => (int) ($row['attempts'] ?? 0)) ?? 0);
        $staleReserved = 0;
        foreach ((array) ($snapshot['stats'] ?? []) as $stats) {
            $staleReserved += (int) ($stats['stale_reserved_count'] ?? 0);
        }
        if ($maxAttempts >= (int) config('services.lifecycle_orchestrator.max_job_attempts', 3)) {
            return 'queue_retry_storm';
        }
        if ($staleReserved >= (int) config('services.lifecycle_orchestrator.max_stale_reserved_jobs', 1)) {
            return 'queue_stale_reserved_jobs';
        }
        if ($this->uncontainedRecentFailedJobs($symbol, $timeframe)
            >= (int) config('services.lifecycle_orchestrator.max_failed_jobs', 1)) {
            return 'queue_failed_jobs';
        }

        return null;
    }

    /**
     * Failed queue rows are transport evidence, not an everlasting circuit
     * breaker. Once the exact EvaluateLabAgentJob failure is durably attached
     * to an agent and immutable technical_error run, the lifecycle must be
     * allowed to reach its bounded quarantine/recovery stage. Any unknown,
     * cross-scope or incompletely projected failure remains fail-closed.
     */
    private function uncontainedRecentFailedJobs(string $symbol, string $timeframe): int
    {
        if (! Schema::hasTable('failed_jobs')) {
            return 0;
        }

        return DB::table('failed_jobs')->whereIn('queue', $this->labQueues())
            ->where('failed_at', '>=', now()->subSeconds(max(60, (int) config('services.lifecycle_orchestrator.failed_job_window_seconds', 3600))))
            ->where('payload', 'not like', '%EvaluateMtfPlaybookPriorJob%')
            ->where('payload', 'not like', '%ValidateMtfPoweredPriorJob%')
            ->get(['payload', 'failed_at'])
            ->reject(fn ($row): bool => $this->failedEvaluationIsDurablyContained(
                (string) $row->payload,
                $symbol,
                $timeframe,
                $row->failed_at,
            ))
            ->count();
    }

    private function failedEvaluationIsDurablyContained(
        string $payloadJson,
        string $symbol,
        string $timeframe,
        mixed $failedAt = null,
    ): bool {
        $payload = json_decode($payloadJson, true);
        if (! is_array($payload)
            || (string) data_get($payload, 'displayName') !== EvaluateLabAgentJob::class) {
            return false;
        }

        $serialized = (string) data_get($payload, 'data.command', '');
        if (! preg_match('/s:10:"labAgentId";i:(\d+);/', $serialized, $matches)) {
            return false;
        }

        $agent = LabAgent::query()->find((int) $matches[1]);
        if (! $agent
            || strtoupper((string) $agent->symbol) !== strtoupper($symbol)
            || strtoupper((string) $agent->timeframe) !== strtoupper($timeframe)) {
            return false;
        }

        $technicalEvidenceExists = LabEvaluationRun::query()
            ->where('lab_agent_id', $agent->id)
            ->whereIn('phase', ['screening', 'full_validation'])
            ->where('status', 'technical_error')
            ->exists();
        if (! $technicalEvidenceExists) {
            return false;
        }
        if (in_array((string) $agent->lifecycle_status, ['evaluation_error', 'technical_quarantine'], true)) {
            return true;
        }

        // A successful bounded recovery supersedes the failed transport row.
        // Keep the old failed_jobs record for audit, but do not let it reopen
        // the circuit breaker after newer immutable evidence has completed.
        if (! filled($failedAt)) {
            return false;
        }
        $expectedPhase = null;
        if (preg_match('/s:4:"mode";s:\d+:"([^"]+)";/', $serialized, $modeMatch)) {
            $expectedPhase = (string) $modeMatch[1] === 'screen' ? 'screening' : 'full_validation';
        }
        $recovery = LabEvaluationRun::query()
            ->where('lab_agent_id', $agent->id)
            ->where('status', 'completed')
            ->where('finished_at', '>=', $failedAt);
        if ($expectedPhase !== null) {
            $recovery->where('phase', $expectedPhase);
        } else {
            $recovery->whereIn('phase', ['screening', 'full_validation']);
        }

        return $recovery->exists();
    }

    private function beginCheckpoint(string $cycleId, string $symbol, string $timeframe, string $stage): ?LabLifecycleCycle
    {
        if (! Schema::hasTable('lab_lifecycle_cycles')) {
            return null;
        }

        return LabLifecycleCycle::updateOrCreate(['cycle_id' => $cycleId], [
            'symbol' => $symbol, 'timeframe' => $timeframe, 'status' => 'running', 'stage' => $stage,
            'summary' => null, 'context' => ['protocol' => 'lifecycle_checkpoint_v1'],
            'started_at' => now()->utc(), 'heartbeat_at' => now()->utc(), 'finished_at' => null,
        ]);
    }

    private function rotateLifecycleLogs(): void
    {
        $cutoff = now('Asia/Tashkent')->subDays(max(1, (int) config('services.lifecycle_orchestrator.log_retention_days', 14)))->startOfDay();
        foreach (['lifecycle-errors', 'lifecycle-summaries'] as $folder) {
            $dir = storage_path('logs/neurotrader/'.$folder);
            if (! is_dir($dir)) {
                continue;
            }
            foreach (glob($dir.DIRECTORY_SEPARATOR.'*.jsonl') ?: [] as $path) {
                $date = basename($path, '.jsonl');
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && Carbon::createFromFormat('Y-m-d', $date, 'Asia/Tashkent')->lt($cutoff)) {
                    @unlink($path);
                }
            }
        }
    }

    private function generateCycleId(): string
    {
        return 'lc-'.now(config('app.timezone'))->format('YmdHis').'-'.substr(md5(random_bytes(8)), 0, 6);
    }

    private function touchCycle(string $cycleId): void
    {
        Cache::put('lifecycle-cycle:'.$cycleId.':alive', true, now()->addMinutes(15));
    }

    private function summarize(string $cycleId, string $symbol, string $timeframe, string $status, string $summary, string $stage, array $extra): array
    {
        $result = [
            'cycle_id' => $cycleId,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'status' => $status,
            'stage' => $stage,
            'summary' => $summary,
            'at' => now()->toIso8601String(),
            'data' => $extra,
        ];

        // The orchestrator is consumed by operators and schedulers, not only
        // by developers. Keep the detailed evidence in `data`, but always
        // expose one deterministic, short decision at the top level.
        $result['decision'] = $this->cycleDecision($result);

        if (Schema::hasTable('lab_lifecycle_cycles')) {
            LabLifecycleCycle::query()->where('cycle_id', $cycleId)->update([
                'status' => $status,
                'stage' => $stage,
                'summary' => $summary,
                'context' => [
                    'protocol' => 'lifecycle_checkpoint_v1',
                    'decision' => $result['decision'],
                    'data' => $extra,
                ],
                'heartbeat_at' => now()->utc(),
            ]);
        }

        return $result;
    }

    /** @return array{state: string, code: string, message: string, action: string} */
    private function cycleDecision(array $result): array
    {
        $status = (string) ($result['status'] ?? 'blocked');
        $data = (array) ($result['data'] ?? []);
        $reason = (string) data_get($data, 'generation_outcome.reason_code', '');
        if ($reason === '') {
            $reason = (string) data_get($data, 'next_action', '');
        }

        if ($status === self::STATUS_COMPLETED) {
            return ['state' => 'ok', 'code' => 'CYCLE_ADVANCED',
                'message' => 'Cycle bajarildi; evidence queue nazoratda.', 'action' => 'monitor_next_cycle'];
        }
        if ($status === self::STATUS_PAUSED && $reason === 'LATEST_GENERATION_ACTIVE') {
            return ['state' => 'running', 'code' => 'GENERATION_RESUMABLE',
                'message' => 'Mavjud generation davom etmoqda.', 'action' => 'retry_next_cycle'];
        }
        if ($status === self::STATUS_PAUSED) {
            return ['state' => 'waiting', 'code' => $reason !== '' ? $reason : 'CYCLE_PAUSED',
                'message' => 'Cycle hozircha kutmoqda.', 'action' => 'retry_next_cycle'];
        }

        return ['state' => 'blocked', 'code' => $reason !== '' ? $reason : 'CYCLE_BLOCKED',
            'message' => 'Cycle xavfsiz to‘xtadi; evidence o‘zgartirilmadi.', 'action' => 'inspect_and_recover'];
    }

    /** @return array{state: string, code: string, message: string, action: string} */
    private function briefStatus(?LabGeneration $latest, array $queue, array $generationMonitor, array $successorPayload): array
    {
        $row = (array) ($generationMonitor[0] ?? []);
        $planned = (int) ($row['planned'] ?? 0);
        $actual = (int) ($row['actual'] ?? 0);
        $stages = (array) ($row['stages'] ?? []);
        $queued = (int) ($queue['total'] ?? 0);
        $generation = $latest?->generation;

        if ($latest === null) {
            return ['state' => 'waiting', 'code' => 'NO_GENERATION',
                'message' => 'Generation mavjud emas.', 'action' => 'start_cycle'];
        }
        if ((string) data_get($successorPayload, 'status') === 'pending') {
            return ['state' => 'waiting', 'code' => (string) data_get($successorPayload, 'last_block_reason', 'SUCCESSOR_PENDING'),
                'message' => "G{$generation} successor navbatda.", 'action' => 'run_next_cycle'];
        }
        if ($queued > 0 || in_array((string) $latest->status, ['draft', 'queued', 'screening', 'full_queued', 'training', 'full_validation'], true)) {
            $screening = (int) ($stages['screening'] ?? 0);

            return ['state' => 'running', 'code' => 'GENERATION_IN_PROGRESS',
                'message' => "G{$generation}: {$actual}/{$planned}, screening={$screening}, queue={$queued}.",
                'action' => 'run_next_cycle'];
        }
        if ($planned > 0 && $actual < $planned) {
            return ['state' => 'blocked', 'code' => 'POPULATION_INCOMPLETE',
                'message' => "G{$generation}: {$actual}/{$planned} agent; quarantine saqlandi.", 'action' => 'resume_construction'];
        }

        return ['state' => 'waiting', 'code' => 'EVIDENCE_WAITING',
            'message' => "G{$generation} terminal; keyingi admission kutilmoqda.", 'action' => 'monitor_next_cycle'];
    }

    private function logCycle(string $cycleId, array $summary): void
    {
        try {
            $path = $this->summaryPath();
            $line = json_encode($summary, JSON_UNESCAPED_SLASHES);
            if ($line !== false) {
                file_put_contents($path, $line."\n", FILE_APPEND | LOCK_EX);
            }
        } catch (Throwable) {
            // Summaries are best-effort; never fail the cycle.
        }
    }

    private function summaryPath(): string
    {
        $dir = storage_path('logs/neurotrader/lifecycle-summaries');
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        return $dir.'/'.Carbon::now('Asia/Tashkent')->format('Y-m-d').'.jsonl';
    }

    public function status(string $symbol, string $timeframe): array
    {
        $symbol = strtoupper($symbol);
        $timeframe = $this->canonicalLaboratoryTimeframe($symbol, $timeframe);
        $latest = LabGeneration::query()
            ->whereHas('laboratory', fn ($q) => $q->where('symbol', $symbol)->where('timeframe', $timeframe))
            ->latest('id')->first();

        $agents = LabAgent::query()
            ->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->selectRaw('lifecycle_status as status, count(*) as count')
            ->groupBy('lifecycle_status')->pluck('count', 'status')->toArray();

        $queue = $this->queue->snapshot($this->labQueues());
        $velocity = $this->velocity->summary($symbol, $timeframe);
        $checkpoint = Schema::hasTable('lab_lifecycle_cycles')
            ? LabLifecycleCycle::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->latest('id')->first()
            : null;
        $recovery = LabLearningLaneDispatch::query()->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status')->toArray();
        $generationMonitor = $this->generationMonitor($symbol, $timeframe);
        $cohortMonitor = $this->cohortMonitor($symbol, $timeframe);
        $autonomy = app(AutonomousModeService::class)->status($symbol, $timeframe);
        $successorRequest = SystemEvent::query()
            ->where('event_key', "lifecycle:successor-request:{$symbol}:{$timeframe}")
            ->first();
        $successorPayload = (array) ($successorRequest?->payload ?? []);
        $successorPending = (string) data_get($successorPayload, 'status') === 'pending';
        // A consumed historical request may retain its old failure advice.
        // Only a pending request owns the current operator-facing next action.
        $successorNextAction = $successorPending
            ? (string) data_get($successorPayload, 'next_action', '')
            : '';
        if ($successorNextAction === '' && $successorPending) {
            $successorNextAction = $this->currentGenerationNeedsConstruction($symbol, $timeframe)
                ? 'continue_successor_construction_next_cycle'
                : 'create_or_resume_successor_next_cycle';
        }
        $lastCycleReason = (string) data_get($checkpoint?->context, 'data.reason', '');
        $nextAction = ! (bool) data_get($autonomy, 'enabled', false)
            ? (string) data_get($autonomy, 'next_action', 'monitor_only_until_ai_start')
            : ($successorNextAction !== ''
                ? $successorNextAction
                : ($lastCycleReason !== '' ? $lastCycleReason : ($velocity['next_action'] ?? null)));
        $brief = $this->briefStatus($latest, $queue, $generationMonitor, $successorPayload);
        if (! (bool) data_get($autonomy, 'enabled', false)) {
            $brief = [
                'state' => data_get($autonomy, 'state', 'stopped'),
                'code' => 'AUTONOMY_STOPPED',
                'message' => 'Avtonom yangi ishlar to‘xtatilgan; monitoring davom etmoqda.',
                'action' => data_get($autonomy, 'next_action', 'monitor_only_until_ai_start'),
            ];
        }

        return [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'organism' => [
                'scope' => $symbol,
                'population_scope' => $symbol === strtoupper((string) config('services.xauusd_organism.symbol', 'XAUUSD'))
                    ? (string) config('services.xauusd_organism.population_scope', 'symbol')
                    : 'symbol_timeframe',
                'laboratory_storage_timeframe' => $timeframe,
                'execution_timeframe' => $symbol === strtoupper((string) config('services.xauusd_organism.symbol', 'XAUUSD'))
                    ? (string) config('services.xauusd_organism.execution_timeframe', 'M5')
                    : $timeframe,
                'timeframe_roles' => $symbol === strtoupper((string) config('services.xauusd_organism.symbol', 'XAUUSD'))
                    ? (array) config('services.xauusd_organism.timeframe_roles', [])
                    : [$timeframe => 'laboratory_and_execution'],
            ],
            'generation' => $latest?->generation,
            'generation_status' => $latest?->status,
            'generation_id' => $latest?->id,
            'population_contract' => [
                'expected_per_normal_generation' => (int) config('services.lab_selection.population_size', 20),
                'autonomous_generation_authority' => self::class,
                'autonomous_specialized_cohorts_enabled' => (bool) config('services.edge_director.autonomous_specialized_cohorts_enabled', false),
                'latest_trigger_type' => $latest?->trigger_type,
                'latest_planned' => $generationMonitor[0]['planned'] ?? null,
                'latest_actual' => $generationMonitor[0]['actual'] ?? null,
                'latest_complete' => $generationMonitor[0]['complete'] ?? null,
                'latest_is_normal_contract' => ($generationMonitor[0]['planned'] ?? null) === (int) config('services.lab_selection.population_size', 20)
                    && ($generationMonitor[0]['actual'] ?? null) === (int) config('services.lab_selection.population_size', 20),
            ],
            'autonomous_mode' => $autonomy,
            'agent_counts' => $agents,
            'generation_stages' => $generationMonitor,
            'cohort_diversity' => $cohortMonitor,
            'locked' => (bool) Cache::has($this->lockKey($symbol, $timeframe)),
            'scheduler_healthy' => $this->schedulerHeartbeatFresh(),
            'queue' => $queue,
            'recovery' => $recovery,
            'learning_gate' => ['status' => $velocity['status'] ?? null, 'allowed' => $velocity['allowed'] ?? null,
                'next_action' => $velocity['next_action'] ?? null,
                'actionable_pending_dojo' => data_get($velocity, 'learning_starvation.actionable_pending_dojo', 0)],
            'successor_request' => $successorRequest ? [
                'status' => data_get($successorPayload, 'status'),
                'creation_attempts' => (int) data_get($successorPayload, 'creation_attempts', 0),
                'last_block_reason' => data_get($successorPayload, 'last_block_reason'),
                'last_block_retryable' => data_get($successorPayload, 'last_block_retryable'),
                'last_attempt_at' => data_get($successorPayload, 'last_attempt_at'),
                'next_action' => $successorNextAction !== '' ? $successorNextAction : null,
            ] : null,
            'brief' => $brief,
            'last_cycle_reason' => $lastCycleReason !== '' ? $lastCycleReason : null,
            'next_action' => $nextAction,
            'checkpoint' => $checkpoint ? ['cycle_id' => $checkpoint->cycle_id, 'status' => $checkpoint->status,
                'stage' => $checkpoint->stage, 'heartbeat_at' => $checkpoint->heartbeat_at?->toIso8601String()] : null,
            'errors_today' => $this->errorSummary(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function generationMonitor(string $symbol, string $timeframe): array
    {
        return LabGeneration::query()
            ->whereHas('laboratory', fn ($query) => $query->where('symbol', $symbol)->where('timeframe', $timeframe))
            ->with('agents:id,lab_generation_id,lifecycle_status')
            ->latest('generation')
            ->limit(5)
            ->get()
            ->map(function (LabGeneration $generation): array {
                $agents = $generation->agents;
                $counts = $agents->countBy(fn (LabAgent $agent): string => (string) $agent->lifecycle_status)->all();
                $stages = [
                    'construction' => $agents->whereIn('lifecycle_status', ['draft'])->count(),
                    'screening' => $agents->whereIn('lifecycle_status', ['queued', 'screening', 'screened'])->count(),
                    'full_validation' => $agents->whereIn('lifecycle_status', ['full_queued', 'training', 'full_validation', 'challenger'])->count(),
                    'forward' => $agents->where('lifecycle_status', 'forward_validated')->count(),
                    'paper' => $agents->where('lifecycle_status', 'paper')->count(),
                    'champion' => $agents->where('lifecycle_status', 'champion')->count(),
                    'quarantine' => $agents->whereIn('lifecycle_status', ['evaluation_error', 'technical_quarantine', 'quarantined', 'legacy_quarantine'])->count(),
                    'rejected' => $agents->where('lifecycle_status', 'rejected')->count(),
                ];
                $planned = (int) data_get($generation->trigger_context, 'population_group_contract.planned_population', $generation->population_size);
                $actual = $agents->count();

                return [
                    'generation' => (int) $generation->generation,
                    'generation_id' => (int) $generation->id,
                    'trigger_type' => (string) $generation->trigger_type,
                    'status' => (string) $generation->status,
                    'planned' => $planned,
                    'actual' => $actual,
                    'complete' => $planned > 0 && $actual === $planned,
                    'stages' => $stages,
                    'lifecycle_statuses' => $counts,
                ];
            })->values()->all();
    }

    private function canonicalLaboratoryTimeframe(string $symbol, string $requested): string
    {
        if (strtoupper($symbol) === strtoupper((string) config('services.xauusd_organism.symbol', 'XAUUSD'))) {
            return strtoupper((string) config('services.xauusd_organism.laboratory_storage_timeframe', 'H1'));
        }

        return strtoupper($requested);
    }

    /** @return array<string, mixed> */
    private function cohortMonitor(string $symbol, string $timeframe): array
    {
        $rows = LabGeneration::query()
            ->whereHas('laboratory', fn ($query) => $query->where('symbol', $symbol)->where('timeframe', $timeframe))
            ->latest('generation')->limit(5)->get();
        $fingerprints = $rows->map(function (LabGeneration $generation): array {
            $plan = (array) data_get($generation->trigger_context, 'generation_plan', []);
            $projection = $plan !== []
                ? array_map(fn (array $slot): array => [
                    'family' => data_get($slot, 'family'),
                    'target' => data_get($slot, 'target'),
                    'research_group' => data_get($slot, 'research_group'),
                    'gene' => data_get($slot, 'niche.declared_gene'),
                    'value' => data_get($slot, 'niche.declared_value'),
                    'lane' => data_get($slot, 'niche.experiment_lane', data_get($slot, 'niche.hybrid_evolution_lane')),
                ], $plan)
                : ['legacy_generation' => $generation->id];

            return [
                'generation' => (int) $generation->generation,
                'fingerprint' => hash('sha256', json_encode($projection, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
                'plan_slots' => count($plan),
            ];
        })->values();
        $unique = $fingerprints->pluck('fingerprint')->unique()->values();

        return [
            'lookback_generations' => $fingerprints->count(),
            'unique_cohorts' => $unique->count(),
            'duplicate_cohort_count' => max(0, $fingerprints->count() - $unique->count()),
            'variation_status' => $fingerprints->count() < 2
                ? 'insufficient_history'
                : ($unique->count() === 1 ? 'repeated_cohort' : 'varied'),
            'generations' => $fingerprints->all(),
        ];
    }

    private function errorSummary(): array
    {
        $path = storage_path('logs/neurotrader/lifecycle-errors/'.Carbon::now('Asia/Tashkent')->format('Y-m-d').'.jsonl');
        if (! is_file($path)) {
            return ['count' => 0, 'latest' => null];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $latest = $lines === [] ? null : json_decode((string) end($lines), true);

        return ['count' => count($lines), 'latest' => is_array($latest) ? [
            'stage' => $latest['stage'] ?? null, 'severity' => $latest['severity'] ?? null,
            'error_code' => $latest['error_code'] ?? null, 'safe_message' => $latest['safe_message'] ?? null,
        ] : null];
    }
}
