<?php

namespace App\Services;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\CandidateGateDecision;
use App\Models\Candle;
use App\Models\EliteAgentPortfolio;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\PaperOrder;
use App\Models\PaperSignal;
use App\Models\ShadowVetoObservation;
use App\Models\Symbol;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Detects lifecycle/evidence failures without changing any promotion gate.
 * The only repair is a bounded requeue of a demonstrably abandoned full
 * replay.  All other findings are durable operational warnings.
 */
class LabLifecycleWatchdogService
{
    private const STALE_TRAINING_MINUTES = 30;
    private const FULL_STALL_MINUTES = 60;
    private const DRAFT_STALL_MINUTES = 90;
    private const MAX_SAFE_REQUEUES = 2;

    private const REQUIRED_AUDIT_TABLES = [
        'lab_evaluation_runs', 'lab_generations', 'lab_agents', 'model_market_performance',
        'candidate_gate_decisions', 'shadow_veto_observations', 'elite_agent_portfolios',
        'paper_signals', 'paper_orders', 'candles', 'symbols',
    ];

    /** @var Collection<int, LabAgent>|null */
    private ?Collection $agentAuditSnapshot = null;

    /** @var Collection<int, int>|null */
    private ?Collection $auditModelVersionIds = null;

    public function __construct(
        private readonly SystemLogService $logs,
        private readonly LabQueueJobInspector $queueJobs,
    ) {}

    public function inspect(bool $repair = false): array
    {
        $schemaFinding = $this->schemaPreflight();
        if ($schemaFinding !== null) return [$schemaFinding];

        // Both archive audits need the same replay metadata. Reuse one
        // snapshot for this pass instead of loading thousands of models twice.
        $this->agentAuditSnapshot = null;
        $this->auditModelVersionIds = null;
        $events = [];
        $events = [...$events, ...$this->watchStaleTraining($repair)];
        $events = [...$events, ...$this->watchFullValidationStalls($repair)];
        $events = [...$events, ...$this->watchOrphanedOpenRuns($repair)];
        $events = [...$events, ...$this->watchDraftDispatchStalls()];
        $events = [...$events, ...$this->watchMissingForwardLedgers()];
        $events = [...$events, ...$this->watchShadowConnection()];
        $events = [...$events, ...$this->watchElitePortfolioContracts()];
        $events = [...$events, ...$this->watchPaperCapture()];
        $events = [...$events, ...$this->watchPaperIntegrity()];

        return $events;
    }

    private function schemaPreflight(): ?array
    {
        try {
            $missing = array_values(array_filter(
                self::REQUIRED_AUDIT_TABLES,
                static fn (string $table): bool => ! Schema::hasTable($table),
            ));
        } catch (\Throwable $exception) {
            return $this->warn(
                'WATCHDOG_SCHEMA_UNAVAILABLE',
                'Lifecycle watchdog could not verify the evidence schema; no audit or repair was attempted.',
                [
                    'exception_class' => $exception::class,
                    'promotion_evidence' => false,
                ],
                0,
                'critical',
            );
        }

        if ($missing === []) return null;

        return $this->warn(
            'WATCHDOG_SCHEMA_INCOMPLETE',
            'Lifecycle watchdog found missing evidence tables; no audit or repair was attempted.',
            [
                'missing_tables' => $missing,
                'next_action' => 'run the reviewed database migrations before enabling evidence monitoring',
                'promotion_evidence' => false,
            ],
            0,
            'critical',
        );
    }

    /**
     * A worker can die after the immutable run is opened and before the
     * queue middleware gets a chance to close it.  Such a row is not strategy
     * evidence and must not remain an apparent active replay forever.  We
     * only reconcile it after all three operational proofs hold: the owning
     * generation is terminal, no queue job still references the agent, and
     * the single Python replay lane explicitly reports idle.
     */
    private function watchOrphanedOpenRuns(bool $repair): array
    {
        $events = [];
        $runs = LabEvaluationRun::query()
            ->with(['generation', 'agent'])
            ->where('status', 'started')
            ->where('started_at', '<=', now()->subMinutes(self::STALE_TRAINING_MINUTES))
            ->orderBy('id')
            ->limit(100)
            ->get();

        if ($runs->isEmpty()) return $events;

        $laneIdle = $this->replayLaneIsIdle();
        $evidence = $repair && $laneIdle ? app(LabImmutableEvidenceService::class) : null;
        foreach ($runs as $run) {
            $generation = $run->generation;
            $agent = $run->agent;
            $screeningComplete = $generation
                && (string) $generation->status === 'screened'
                && ! $generation->agents()->whereIn('lifecycle_status', ['draft', 'queued', 'screening'])->exists();
            $terminalGeneration = $generation
                && (in_array((string) $generation->status, ['completed', 'technical_quarantine', 'abandoned'], true) || $screeningComplete);
            $hasQueuedJob = $agent ? $this->hasAnyLabJob($agent->id) : true;
            $context = [
                'run_id' => $run->run_id,
                'lab_evaluation_run_id' => $run->id,
                'lab_agent_id' => $run->lab_agent_id,
                'generation_id' => $run->lab_generation_id,
                'phase' => $run->phase,
                'started_at' => $run->started_at?->toIso8601String(),
                'generation_status' => $generation?->status,
                'screening_complete' => $screeningComplete,
                'agent_lifecycle_status' => $agent?->lifecycle_status,
                'queue_job_present' => $hasQueuedJob,
                'replay_lane_idle' => $laneIdle,
                'promotion_evidence' => false,
            ];

            if (! $terminalGeneration || $hasQueuedJob) {
                $events[] = $this->warn(
                    'OPEN_LAB_RUN_REQUIRES_REVIEW',
                    'An old immutable lab run remains open, but its lifecycle/queue proof is not sufficient for automatic reconciliation.',
                    $context,
                    $run->id,
                );
                continue;
            }

            $events[] = $this->warn(
                'ORPHANED_OPEN_LAB_RUN',
                'An old open lab run has no active queue/replay owner; it is operational retry evidence, not a strategy verdict.',
                $context,
                $run->id,
            );

            if ($evidence === null) continue;

            $evidence->finishIfOpen(
                $run,
                'retry_released',
                null,
                [],
                [
                    'reason_code' => 'ORPHANED_OPEN_RUN_RECONCILED',
                    'recovery_protocol' => 'orphaned_open_run_v1',
                    'terminal_generation_proven' => true,
                    'no_queue_job_proven' => true,
                    'replay_lane_idle_proven' => true,
                    'promotion_evidence' => false,
                ],
            );
            $events[] = $this->warn(
                'ORPHANED_OPEN_LAB_RUN_RECONCILED',
                'Closed the orphaned immutable run as retry_released after terminal lifecycle and idle-lane proofs; no strategy gate was changed.',
                $context,
                $run->id,
                'info',
            );
        }

        return $events;
    }

    private function hasAnyLabJob(int $agentId): bool
    {
        if (! DB::getSchemaBuilder()->hasTable('jobs')) return false;

        return $this->queueJobs->hasAgentJob($agentId, array_values(array_unique(array_merge(
            [(string) config('services.lab_queue.screening_queue', 'lab-screening')],
            [(string) config('services.lab_queue.frontier_queue', 'lab-frontier')],
            (array) config('services.lab_queue.legacy_screening_queues', []),
            ['lab-full-validation'],
        ))));
    }

    private function replayLaneIsIdle(): bool
    {
        $url = rtrim((string) config('services.ai_service.url'), '/').'/api/replay-status';
        $token = (string) config('services.internal_api.token');
        if ($url === '/api/replay-status' || $token === '') return false;

        try {
            $response = Http::connectTimeout(2)->timeout(4)
                ->withHeaders(['X-Internal-Token' => $token])->get($url);
            if ($response->failed()) return false;
            $body = $response->json();
            return (string) data_get($body, 'protocol', '') !== ''
                && (int) data_get($body, 'active_requests', -1) === 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Draft is normal for one scheduler tick; beyond 90 minutes it is a
     * lifecycle finding, never an excuse to create a duplicate generation. */
    private function watchDraftDispatchStalls(): array
    {
        $events = [];
        LabGeneration::query()->with(['laboratory', 'agents'])
            ->whereIn('status', ['draft', 'queued'])
            ->where('updated_at', '<=', now()->subMinutes(self::DRAFT_STALL_MINUTES))
            ->each(function (LabGeneration $generation) use (&$events): void {
                $queued = $generation->agents->whereIn('lifecycle_status', ['queued', 'screening'])->count();
                $events[] = $this->warn('LAB_GENERATION_DISPATCH_STALLED', 'Generation remained draft/queued for over 90 minutes; no duplicate dispatch was created.', [
                    'generation_id' => $generation->id, 'generation' => $generation->generation,
                    'symbol' => $generation->laboratory?->symbol, 'timeframe' => $generation->laboratory?->timeframe,
                    'status' => $generation->status, 'queued_or_screening_agents' => $queued,
                    'next_action' => 'inspect scheduler, feed health and existing queue job before manual dispatch',
                ], $generation->id);
            });
        return $events;
    }

    private function watchStaleTraining(bool $repair): array
    {
        $events = [];
        $cutoff = now()->subMinutes(self::STALE_TRAINING_MINUTES);
        LabAgent::query()->with('modelVersion')->where('lifecycle_status', 'training')
            // A generation that already completed belongs to historical audit;
            // it must not be resurrected by the operational watchdog.
            ->whereHas('generation', fn ($query) => $query->where('status', 'full_validation'))
            ->where('updated_at', '<=', $cutoff)->each(function (LabAgent $agent) use (&$events, $repair): void {
                $retryCount = (int) data_get($agent->modelVersion?->metadata, 'full_validation_watchdog.retry_count', 0);
                $context = ['lab_agent_id' => $agent->id, 'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe,
                    'stale_since' => $agent->updated_at?->toIso8601String(), 'retry_count' => $retryCount];
                $events[] = $this->warn('STALE_TRAINING_AGENT', 'Full-validation agent remains in training without a completed lifecycle.', $context, $agent->id);

                if ($retryCount >= self::MAX_SAFE_REQUEUES) {
                    $events[] = $this->warn('FULL_VALIDATION_RETRY_EXHAUSTED', 'Stale full validation exhausted its two safe retries and now requires diagnosis; no gate status changed.', $context, $agent->id, 'error');
                    return;
                }
                if (! $repair || ! $this->safeToRequeue($agent)) {
                    return;
                }

                DB::transaction(function () use ($agent, $retryCount): void {
                    $locked = LabAgent::query()->lockForUpdate()->find($agent->id);
                    if (! $locked || $locked->lifecycle_status !== 'training' || ! $this->safeToRequeue($locked)) return;
                    $model = $locked->modelVersion()->lockForUpdate()->first();
                    if (! $model) return;
                    $metadata = $model->metadata ?? [];
                    data_set($metadata, 'full_validation_watchdog', [
                        'retry_count' => $retryCount + 1,
                        'last_requeued_at' => now()->toIso8601String(),
                        'reason' => 'STALE_TRAINING_AGENT',
                    ]);
                    $model->update(['metadata' => $metadata]);
                    $locked->update([
                        'lifecycle_status' => 'full_queued',
                        'decision_reason' => 'Watchdog safely requeued an abandoned full-validation job; promotion evidence unchanged.',
                    ]);
                    EvaluateLabAgentJob::dispatch($locked->id, $locked->symbol, 'full');
                });
                $events[] = $this->warn('STALE_TRAINING_AGENT_REQUEUED', 'Safely requeued stale full validation after verifying no job or replay evidence exists.', $context + ['retry_count' => $retryCount + 1], $agent->id, 'info');
            });
        return $events;
    }

    private function safeToRequeue(LabAgent $agent): bool
    {
        if ($this->hasQueuedFullJob($agent->id)) return false;
        if (ModelMarketPerformance::query()->where('model_version_id', $agent->model_version_id)
            ->where('symbol', $agent->symbol)->where('timeframe', $agent->timeframe)->exists()) return false;
        return ! is_array(data_get($agent->modelVersion?->metadata, 'full_validation_batch.item'));
    }

    private function hasQueuedFullJob(int $agentId): bool
    {
        if (! DB::getSchemaBuilder()->hasTable('jobs')) return false;
        return $this->queueJobs->hasAgentJob($agentId, ['lab-full-validation']);
    }

    private function watchFullValidationStalls(bool $repair = false): array
    {
        $events = [];
        LabGeneration::query()->where('status', 'full_validation')->with('agents')
            ->each(function (LabGeneration $generation) use (&$events, $repair): void {
                // Long replays are expected.  "stalled" specifically means
                // that no queued or reserved full-validation job remains. A
                // generation whose every agent is already terminal may be
                // finalized immediately once the global lane is idle; it
                // must not wait an arbitrary hour after an admission block.
                if ($generation->agents->contains(fn (LabAgent $agent) => $this->hasQueuedFullJob($agent->id))) return;
                $oldEnough = $generation->updated_at
                    && $generation->updated_at->lte(now()->subMinutes(self::FULL_STALL_MINUTES));
                $terminalBoundaryProven = $this->safeToFinalizeStalledGeneration($generation);
                if (! $oldEnough && ! $terminalBoundaryProven) return;
                $context = [
                    'generation_id' => $generation->id, 'generation' => $generation->generation,
                    'symbol' => $generation->laboratory?->symbol, 'updated_at' => $generation->updated_at?->toIso8601String(),
                    'terminal_boundary_proven' => $terminalBoundaryProven,
                ];
                if (! $repair || ! $terminalBoundaryProven) {
                    $events[] = $this->warn('FULL_VALIDATION_STALLED', $oldEnough
                        ? 'Generation has remained in full validation for over 60 minutes; no lifecycle state was changed.'
                        : 'Generation reached a terminal full-validation boundary but repair mode is disabled; no lifecycle state was changed.', $context, $generation->id);
                    return;
                }

                DB::transaction(function () use ($generation): void {
                    $locked = LabGeneration::query()->with('agents')->lockForUpdate()->find($generation->id);
                    if (! $locked || $locked->status !== 'full_validation') return;
                    if ($locked->agents->contains(fn (LabAgent $agent) => in_array($agent->lifecycle_status, ['queued', 'screening', 'training', 'full_queued'], true))) return;
                    $triggerContext = (array) $locked->trigger_context;
                    $triggerContext['stale_full_validation_recovery'] = [
                        'protocol' => 'safe_terminal_lifecycle_finalize_v1',
                        'finalized_at' => now()->toIso8601String(),
                        'reason' => 'no_active_job_or_replay_and_all_agents_terminal',
                        'gate_status_unchanged' => true,
                    ];
                    $locked->update([
                        'status' => 'completed',
                        'completed_at' => now(),
                        'trigger_context' => $triggerContext,
                    ]);
                });
                $events[] = $this->warn(
                    $oldEnough ? 'FULL_VALIDATION_STALE_FINALIZED' : 'FULL_VALIDATION_TERMINAL_FINALIZED',
                    $oldEnough
                        ? 'Safely finalized a stale full-validation generation after proving that no job/replay remained and every agent was terminal.'
                        : 'Safely finalized a terminal full-validation boundary without waiting for the stale threshold; no quality or promotion gate was changed.',
                    $context,
                    $generation->id,
                    'info',
                );
            });
        return $events;
    }

    private function safeToFinalizeStalledGeneration(LabGeneration $generation): bool
    {
        if ($generation->agents->isEmpty()) return false;
        if ($generation->agents->contains(fn (LabAgent $agent): bool => in_array($agent->lifecycle_status, ['queued', 'screening', 'training', 'full_queued'], true))) return false;
        if ($generation->agents->contains(fn (LabAgent $agent): bool => $this->hasQueuedFullJob($agent->id))) return false;
        if (LabEvaluationRun::query()
            ->where('lab_generation_id', $generation->id)
            ->where('phase', 'full_validation')
            ->where('status', 'started')
            ->exists()) return false;

        $url = rtrim((string) config('services.ai_service.url'), '/').'/api/replay-status';
        $token = (string) config('services.internal_api.token');
        if ($url === '/api/replay-status' || $token === '') return false;

        try {
            $response = Http::connectTimeout(2)->timeout(4)
                ->withHeaders(['X-Internal-Token' => $token])->get($url);
            if ($response->failed()) return false;
            $body = $response->json();
            return (string) data_get($body, 'protocol', '') !== ''
                && (int) data_get($body, 'active_requests', -1) === 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function watchMissingForwardLedgers(): array
    {
        $events = [];
        $generations = LabGeneration::query()
            ->where('status', 'completed')
            ->get(['id', 'generation']);

        if ($generations->isEmpty()) return $events;

        // Keep this audit bounded in query count.  The previous per-agent
        // exists() checks turned a normal 3,000-agent archive into thousands
        // of round trips every five minutes.
        $agents = $this->agentAuditSnapshot();
        $evaluatedModelVersions = $this->auditModelVersionIds()->flip();

        $evaluated = $agents->filter(fn (LabAgent $agent): bool =>
            (int) data_get($agent->modelVersion?->metadata, 'last_result.observability_protocol_version', 0) >= 1
            && $evaluatedModelVersions->has((int) $agent->model_version_id)
        );
        if ($evaluated->isEmpty()) return $events;

        $ledgerAgentIds = CandidateGateDecision::query()
            ->where('stage', 'statistical_forward_gate')
            ->whereIn('lab_agent_id', $evaluated->pluck('id')->all())
            ->pluck('lab_agent_id')
            ->flip();

        // Legacy generations are intentionally handled by the manual,
        // immutable backfill command.  This watchdog covers only a full
        // replay that advertises the post-change observability protocol.
        foreach ($generations as $generation) {
            $generationAgents = $evaluated->where('lab_generation_id', $generation->id);
            if ($generationAgents->isEmpty()) continue;

            $hasLedger = $generationAgents->contains(fn (LabAgent $agent): bool =>
                $ledgerAgentIds->has((int) $agent->id)
            );
            if (! $hasLedger) {
                $events[] = $this->warn('FORWARD_LEDGER_NOT_WRITTEN', 'Completed generation has full replay evidence but no statistical forward-gate decision.', [
                    'generation_id' => $generation->id, 'generation' => $generation->generation,
                    'evaluated_agents' => $generationAgents->pluck('id')->all(),
                ], $generation->id);
            }
        }
        return $events;
    }

    private function watchShadowConnection(): array
    {
        $events = [];
        $agents = $this->agentAuditSnapshot();
        $eligible = $agents->filter(function (LabAgent $agent): bool {
            $result = (array) data_get($agent->modelVersion?->metadata, 'last_result', []);
            return (int) data_get($result, 'observability_protocol_version', 0) >= 1
                && (int) data_get($result, 'entry_funnel.entry_rejection_count', 0) > 0
                && (int) data_get($result, 'veto_regret.shadow_trade_count', 0) === 0;
        });
        if ($eligible->isEmpty()) return $events;

        // The audit only needs membership, so resolve all observations once
        // instead of issuing one exists() query per agent.
        $shadowAgentIds = ShadowVetoObservation::query()
            ->whereIn('lab_agent_id', $eligible->pluck('id')->all())
            ->pluck('lab_agent_id')
            ->flip();

        $eligible->each(function (LabAgent $agent) use (&$events, $shadowAgentIds): void {
            if ($shadowAgentIds->has((int) $agent->id)) return;
            $result = (array) data_get($agent->modelVersion?->metadata, 'last_result', []);
            $rejected = (int) data_get($result, 'entry_funnel.entry_rejection_count', 0);
            $shadows = (int) data_get($result, 'veto_regret.shadow_trade_count', 0);
            $events[] = $this->warn('SHADOW_ENGINE_NOT_CONNECTED', 'Replay reported veto rejections but no shadow counterfactuals were persisted.', [
                'lab_agent_id' => $agent->id, 'rejected_signals' => $rejected, 'shadow_trade_count' => $shadows,
            ], $agent->id);
        });
        return $events;
    }

    /**
     * Anchor the archive audit to model versions that have actual replay
     * performance evidence. Screening-only agents have no forward/shadow
     * contract to audit and should not be loaded every five minutes.
     *
     * @return Collection<int, int>
     */
    private function auditModelVersionIds(): Collection
    {
        return $this->auditModelVersionIds ??= ModelMarketPerformance::query()
            ->whereNotNull('model_version_id')
            ->pluck('model_version_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * Load only the immutable identity and replay metadata needed by the
     * evidence-anchored archive audits. The snapshot is scoped to one inspect
     * pass so a later invocation cannot observe stale state.
     *
     * @return Collection<int, LabAgent>
     */
    private function agentAuditSnapshot(): Collection
    {
        $modelVersionIds = $this->auditModelVersionIds();
        if ($modelVersionIds->isEmpty()) return collect();

        return $this->agentAuditSnapshot ??= LabAgent::query()
            ->select(['id', 'lab_generation_id', 'model_version_id'])
            ->whereIn('model_version_id', $modelVersionIds->all())
            ->with(['modelVersion:id,metadata'])
            ->get();
    }

    private function watchPaperCapture(): array
    {
        $events = [];
        ModelMarketPerformance::query()->with('modelVersion')->where('status', 'forward_validated')->each(function (ModelMarketPerformance $candidate) use (&$events): void {
            // Council members are intentionally held out of individual paper
            // execution. Their missing signal is expected until the combined
            // portfolio proxy passes, so it is not a capture-path incident.
            $metadata = (array) ($candidate->modelVersion?->metadata ?? []);
            if (data_get($metadata, 'council_specialist_contract.protocol') === 'agent_council_v1'
                || data_get($metadata, 'portfolio_council_lane.protocol') === 'portfolio_council_v1') {
                return;
            }
            $symbolId = Symbol::query()->where('code', $candidate->symbol)->value('id');
            if (! $symbolId) return;
            $since = $candidate->updated_at ?: $candidate->created_at;
            $candles = Candle::query()->where('symbol_id', $symbolId)->where('timeframe', $candidate->timeframe)
                ->where('time', '>', $since)->count();
            if ($candles < 3 || PaperSignal::query()->where('model_market_performance_id', $candidate->id)->exists()) return;
            $events[] = $this->warn('PAPER_CAPTURE_BLOCKED', 'Forward-valid candidate has seen three candles without a recorded paper signal; no paper status was changed.', [
                'performance_id' => $candidate->id, 'symbol' => $candidate->symbol, 'timeframe' => $candidate->timeframe,
                'candles_since_forward_validation' => $candles,
                'reason' => 'NO_SIGNAL_OPPORTUNITY_OR_CAPTURE_PATH_REQUIRES_INSPECTION',
            ], $candidate->id);
        });
        return $events;
    }

    /**
     * A portfolio may remain marked active after a member is invalidated or
     * its proxy forward ledger disappears. Surface that drift every five
     * minutes; the watchdog never manufactures a new passport or mutates the
     * frozen evidence. Paper execution remains fail-closed in the meantime.
     */
    private function watchElitePortfolioContracts(): array
    {
        $events = [];
        EliteAgentPortfolio::query()->with('members.performance.modelVersion')
            ->whereIn('status', ['forward_validated', 'paper'])
            ->each(function (EliteAgentPortfolio $portfolio) use (&$events): void {
                $issues = [];
                if ($portfolio->gate_status !== 'passed') $issues[] = 'PORTFOLIO_GATE_NOT_PASSED';
                if (data_get($portfolio->evidence, 'gate.status') !== 'passed') $issues[] = 'PORTFOLIO_EVIDENCE_GATE_MISSING';
                if ($portfolio->members->count() < 2
                    || (int) $portfolio->member_count !== $portfolio->members->count()) {
                    $issues[] = 'PORTFOLIO_MEMBER_COUNT_MISMATCH';
                }

                $proxyId = (int) data_get($portfolio->evidence, 'portfolio_performance_id', 0);
                $proxy = $proxyId > 0
                    ? ModelMarketPerformance::with('modelVersion')->find($proxyId)
                    : null;
                if (! $proxy
                    || ! (bool) data_get($proxy->metrics, 'portfolio_proxy', false)
                    || (int) data_get($proxy->metrics, 'elite_portfolio_id', 0) !== (int) $portfolio->id
                    || $proxy->evidence_status !== 'valid'
                    || $proxy->modelVersion?->evidence_status !== 'valid') {
                    $issues[] = 'PORTFOLIO_PROXY_INVALID';
                } else {
                    $decision = CandidateGateDecision::query()
                        ->where('model_market_performance_id', $proxy->id)
                        ->where('stage', 'statistical_forward_gate')
                        ->latest('evaluated_at')
                        ->first();
                    if ($decision?->decision !== 'passed'
                        || data_get($decision->metrics, 'portfolio_forward_identity.attribution_status') !== 'portfolio_sealed') {
                        $issues[] = 'PORTFOLIO_FORWARD_LEDGER_INVALID';
                    }
                }

                foreach ($portfolio->members as $member) {
                    $performance = $member->performance;
                    if (! $performance
                        || ! in_array((string) $performance->status, ['forward_validated', 'paper'], true)
                        || $performance->evidence_status !== 'valid'
                        || $performance->modelVersion?->evidence_status !== 'valid') {
                        $issues[] = 'PORTFOLIO_MEMBER_EVIDENCE_INVALID';
                        continue;
                    }
                    $decision = CandidateGateDecision::query()
                        ->where('model_market_performance_id', $performance->id)
                        ->where('stage', 'statistical_forward_gate')
                        ->latest('evaluated_at')
                        ->first();
                    if ($decision?->decision !== 'passed'
                        || data_get($decision->metrics, 'elite_agent_passport.status') !== 'passed') {
                        $issues[] = 'PORTFOLIO_MEMBER_PASSPORT_INVALID';
                    }
                }

                $issues = array_values(array_unique($issues));
                if ($issues === []) return;
                $events[] = $this->warn(
                    'ELITE_PORTFOLIO_CONTRACT_DRIFT',
                    'Active multi-agent portfolio no longer satisfies its sealed member/proxy contract; paper routing remains fail-closed.',
                    [
                        'portfolio_id' => $portfolio->id,
                        'symbol' => $portfolio->symbol,
                        'timeframe' => $portfolio->timeframe,
                        'status' => $portfolio->status,
                        'gate_status' => $portfolio->gate_status,
                        'proxy_performance_id' => $proxyId ?: null,
                        'member_performance_ids' => $portfolio->members->pluck('model_market_performance_id')->values()->all(),
                        'issues' => $issues,
                        'promotion_evidence' => false,
                    ],
                    $portfolio->id,
                    'critical',
                );
            });
        return $events;
    }

    private function watchPaperIntegrity(): array
    {
        $events = [];
        PaperOrder::query()->where('evidence_status', 'valid')->whereNull('paper_signal_id')->each(function (PaperOrder $order) use (&$events): void {
            $events[] = $this->warn('PAPER_INTEGRITY_ERROR', 'Paper order has no immutable paper_signal_id; it cannot count as valid paper evidence.', [
                'paper_order_id' => $order->id, 'performance_id' => $order->model_market_performance_id,
            ], $order->id, 'critical');
        });
        return $events;
    }

    private function warn(string $code, string $message, array $context, int $sourceId, string $level = 'warning'): array
    {
        $key = 'lab-watchdog:'.sha1($code.':'.$sourceId);
        $isNew = Cache::add($key, true, now()->addMinutes(30));
        if ($isNew) {
            $this->logs->write($code, $message, $context, $level, 'lab_lifecycle_watchdog', 'inspect', 'open', null, $sourceId);
        }
        return ['code' => $code, 'source_id' => $sourceId, 'new' => $isNew, 'context' => $context];
    }
}
