<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabTrialLedger;
use App\Models\LighthouseVerticalLoopMonitorRun;
use App\Models\ModelMarketPerformance;
use App\Models\PaperSignal;
use App\Models\PaperSignalOutcome;
use App\Models\PaperSignalPassport;
use App\Models\RealityScore;
use App\Models\ServiceHealthCheck;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only readiness monitor for the XAUUSD lighthouse vertical loop.
 *
 * The monitor deliberately separates operational evidence from strategy
 * evidence. Technical gaps are reported as recovery work; failed gates are
 * reported as learning work. Neither path creates a candidate or relaxes a
 * threshold.
 */
class LighthouseVerticalLoopMonitoringService
{
    public const PROTOCOL = 'lighthouse_vertical_loop_monitor_v1';

    private const ACTIVE_GENERATION_STATUSES = [
        'draft', 'queued', 'screening', 'full_validation',
    ];

    public function __construct(
        private readonly LearningProtocolSafetyService $safety,
        private readonly LabQueueJobInspector $queue,
        private readonly LabImmutableEvidenceService $evidence,
        private readonly PaperEvidenceReadinessService $paperReadiness,
        private readonly SystemLogService $logs,
        private readonly LabPopulationService $population,
    ) {}

    /** @return array<string, mixed> */
    public function inspect(string $symbol = 'XAUUSD', string $timeframe = 'H1', bool $persist = true): array
    {
        $symbol = strtoupper(trim($symbol));
        $requestedTimeframe = strtoupper(trim($timeframe));
        $timeframe = $symbol === strtoupper((string) config('services.xauusd_organism.symbol', 'XAUUSD'))
            ? strtoupper((string) config('services.xauusd_organism.laboratory_storage_timeframe', 'H1'))
            : $requestedTimeframe;
        $now = CarbonImmutable::now('UTC');

        if ($symbol !== LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL
            || $timeframe !== LearningProtocolSafetyService::LIGHTHOUSE_TIMEFRAME) {
            return $this->blockedScopeReport($symbol, $timeframe, $now, $persist);
        }

        $lab = AiLaboratory::query()
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->where('is_active', true)
            ->first();
        $generation = $lab?->generations()
            ->with('agents.modelVersion')
            ->latest('generation')
            ->first();

        $checks = [];
        $addCheck = static function (array &$target, string $code, string $status, string $message, array $metrics = []): void {
            $target[] = compact('code', 'status', 'message', 'metrics');
        };

        $pause = $this->safety->generationCreationPaused();
        $scope = $this->scopeContract($lab, $generation);
        $addCheck(
            $checks,
            'STOP_LINE',
            $pause ? 'passed' : 'blocked',
            $pause
                ? 'Normal generation creation is paused; only the explicitly audited lighthouse rescue path may be considered.'
                : 'Normal generation creation is not paused; stop-the-line policy is not active.',
            ['generation_creation_paused' => $pause, 'controlled_rescue_only' => true],
        );
        $addCheck(
            $checks,
            'LIGHTHOUSE_SCOPE',
            $scope['ok'] ? 'passed' : 'blocked',
            $scope['message'],
            $scope,
        );

        // Keep the global operational queue visible, but also carry a
        // generation-owned queue projection. A research replay from another
        // generation may keep the global queue busy without withholding this
        // generation's final evidence boundary.
        $queue = $this->queueState($generation);
        $queueStatus = $queue['backlog']['total'] > 0 ? 'attention' : 'passed';
        if (($queue['replay']['status'] ?? 'unknown') === 'unknown') {
            $queueStatus = 'blocked';
        } elseif (($queue['replay']['status'] ?? 'unknown') === 'active') {
            $queueStatus = 'attention';
        }
        $addCheck(
            $checks,
            'QUEUE_AND_REPLAY_COORDINATION',
            $queueStatus,
            $queueStatus === 'passed'
                ? 'Lab queue is empty and the single replay lane is idle.'
                : ($queueStatus === 'attention'
                    ? (($queue['replay']['status'] ?? null) === 'active'
                        ? (($queue['generation_backlog']['total'] ?? null) === 0
                            ? 'A single replay is active for another research item; the current generation-owned queue is empty and the replay is being observed without interruption.'
                            : 'A single replay is active; it is being observed and must not be duplicated or interrupted.')
                        : 'Lab queue still has work; reconcile/recover apply is deferred until the queue drains.')
                    : 'Replay coordinator liveness is unavailable or active; recovery remains fail-closed.'),
            $queue,
        );

        if (! $generation) {
            $addCheck($checks, 'GENERATION', 'blocked', 'The XAUUSD organism has no generation to monitor.', []);

            return $this->finish($symbol, $timeframe, null, $now, $checks, [], $persist);
        }

        $evidence = $this->evidenceState($generation);
        $entryLayer = $this->entryLayerState();
        $milestones = $this->milestones($generation, $evidence, $entryLayer);
        $technical = $this->technicalState($generation, $evidence);
        $strategy = $this->strategyState($generation, $evidence);
        $paper = $this->paperState($generation, $milestones, $entryLayer);
        $reality = $this->realityState($generation, $milestones);

        if (($technical['evidence_state'] ?? 'FINAL') === 'EVIDENCE_IN_PROGRESS') {
            $addCheck(
                $checks,
                'EVIDENCE_STATE',
                'attention',
                'Evidence is still being produced; no final pipeline or strategy verdict is recorded yet.',
                [
                    'status' => 'EVIDENCE_IN_PROGRESS',
                    'generation_status' => $generation->status,
                    'in_progress_agent_ids' => $technical['in_progress_agent_ids'] ?? [],
                    'missing_screen_run_agent_ids' => $technical['missing_screen_run_agent_ids'] ?? [],
                    'promotion_evidence' => false,
                ],
            );
        }

        $addCheck(
            $checks,
            'TECHNICAL_VS_STRATEGY',
            $technical['technical_blocked'] ? 'blocked' : ($strategy['screening_failure_count'] > 0 ? 'attention' : 'passed'),
            $technical['technical_blocked']
                ? 'Technical/evidence gaps are withholding a strategy verdict; recovery is required before learning.'
                : ($strategy['screening_failure_count'] > 0
                    ? 'A clean strategy gate failure exists; convert it into a targeted mutation, not a random retry.'
                    : 'No technical error or clean screening failure is currently recorded.'),
            $technical + $strategy,
        );
        $addCheck(
            $checks,
            'MTF_ROLE_CONTRACT',
            $entryLayer['separate_population_active'] ? 'blocked' : 'passed',
            $entryLayer['message'],
            $entryLayer,
        );

        $candidateStatus = $milestones['candidate']['ready'] ? 'passed' : ($technical['technical_blocked'] ? 'blocked' : 'attention');
        $addCheck(
            $checks,
            'REPRODUCIBLE_CANDIDATE',
            $candidateStatus,
            $milestones['candidate']['message'],
            $milestones['candidate'],
        );
        $this->addMilestoneCheck($checks, 'FULL_REPLAY', $milestones['full_replay']);
        $this->addMilestoneCheck($checks, 'FORWARD_VALIDATION', $milestones['forward']);
        $this->addMilestoneCheck($checks, 'PAPER_SIGNAL', $milestones['paper_signal']);
        $this->addMilestoneCheck($checks, 'PAPER_OUTCOME', $milestones['paper_outcome']);
        $this->addMilestoneCheck($checks, 'REALITY_FEEDBACK', $milestones['reality_feedback']);

        $paperReadiness = $this->paperReadiness->inspect();
        $report = [
            'protocol' => self::PROTOCOL,
            'checked_at' => $now->toIso8601String(),
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'organism' => $this->organismContract(),
            'lab_id' => $lab?->id,
            'generation_id' => $generation->id,
            'generation' => $generation->generation,
            'generation_status' => $generation->status,
            'current_stage' => $this->currentStage($milestones),
            'next_operator_action' => $this->nextAction($milestones, $technical, $strategy, $queue),
            'milestones' => $milestones,
            'entry_layer' => $entryLayer,
            'technical_vs_strategy' => $technical + $strategy,
            'queue' => $queue,
            'paper_evidence_readiness' => $paperReadiness,
            'paper' => $paper,
            'reality' => $reality,
            'checks' => $checks,
            'promotion_evidence' => false,
            'operator_rule' => 'Monitor is read-only for candidates, strategies, thresholds, gates and paper promotion.',
        ];

        return $this->finish($symbol, $timeframe, $generation, $now, $checks, $report, $persist);
    }

    /** @return array<string, mixed> */
    private function blockedScopeReport(string $symbol, string $timeframe, CarbonImmutable $now, bool $persist): array
    {
        $checks = [[
            'code' => 'LIGHTHOUSE_SCOPE',
            'status' => 'blocked',
            'message' => 'Vertical-loop monitor is fail-closed outside the XAUUSD organism.',
            'metrics' => [
                'requested_symbol' => $symbol,
                'requested_timeframe' => $timeframe,
                'required_symbol' => LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL,
                'required_timeframe' => LearningProtocolSafetyService::LIGHTHOUSE_TIMEFRAME,
            ],
        ]];

        return $this->finish($symbol, $timeframe, null, $now, $checks, [
            'protocol' => self::PROTOCOL,
            'checked_at' => $now->toIso8601String(),
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'current_stage' => 'scope_blocked',
            'next_operator_action' => 'Monitor the single XAUUSD organism; H1 is only its storage key, M15 owns setup/confirmation, and M5 owns execution.',
            'milestones' => [],
            'promotion_evidence' => false,
        ], $persist);
    }

    /** @return array<string, mixed> */
    private function entryLayerState(): array
    {
        $archive = AiLaboratory::query()
            ->where('symbol', LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL)
            ->where('timeframe', 'M15')
            ->first();
        $archiveHasActiveGeneration = $archive?->generations()
            ->whereIn('status', self::ACTIVE_GENERATION_STATUSES)
            ->exists() ?? false;
        $separatePopulationActive = (bool) $archive?->is_active || $archiveHasActiveGeneration;

        return [
            'archive_lab_id' => $archive?->id,
            'archive_active' => (bool) $archive?->is_active,
            'archive_has_active_generation' => $archiveHasActiveGeneration,
            'separate_population_active' => $separatePopulationActive,
            'population_scope' => 'symbol',
            'timeframe_roles' => (array) config('services.xauusd_organism.timeframe_roles', []),
            // Compatibility fields remain zero/healthy for older consumers;
            // forward and paper evidence now belongs to the unified generation.
            'shadow_only' => true,
            'forward_ready' => ! $separatePopulationActive,
            'forward_count' => 0,
            'forward_performance_ids' => [],
            'full_replay_count' => 0,
            'parent_transfer_violations' => 0,
            'message' => $separatePopulationActive
                ? 'A separate XAUUSD M15 population is active; the unified organism contract is violated.'
                : 'M15 is the setup/confirmation role inside one XAUUSD population; no separate M15 population is active.',
        ];
    }

    /** @return array<string, mixed> */
    private function finish(string $symbol, string $timeframe, ?LabGeneration $generation, CarbonImmutable $now, array $checks, array $report, bool $persist): array
    {
        $blocked = collect($checks)->where('status', 'blocked')->count();
        $attention = collect($checks)->where('status', 'attention')->count();
        $status = $blocked > 0 ? 'critical' : ($attention > 0 ? 'warning' : 'ok');
        $score = max(0, 100 - ($blocked * 35) - ($attention * 10));
        $report = [
            ...$report,
            'status' => $status,
            'health_score' => $score,
            'checked_at' => $report['checked_at'] ?? $now->toIso8601String(),
            'checks' => $checks,
            'promotion_evidence' => false,
        ];

        $run = null;
        $persistenceRequested = $persist;
        if ($persist && $generation && $this->population->constructorIsActive($symbol, $timeframe)) {
            // The constructor owns a long-lived transaction while it seals a
            // generation. An observational snapshot must never wait on its
            // generation FK or turn healthy research into a failed scheduler
            // job. The next scheduled monitor tick persists the same derived
            // truth after the canonical owner releases its lease.
            $persist = false;
            $report['monitor_persistence'] = [
                'status' => 'deferred',
                'reason_code' => 'POPULATION_CONSTRUCTOR_ACTIVE',
                'retry_policy' => 'next_scheduler_tick',
                'canonical_evidence_affected' => false,
            ];
        }

        if ($persist) {
            try {
                $run = DB::transaction(function () use ($generation, $symbol, $timeframe, $status, $score, $report, $now): ?LighthouseVerticalLoopMonitorRun {
                    $run = null;
                    if (Schema::hasTable('lighthouse_vertical_loop_monitor_runs')) {
                        $run = LighthouseVerticalLoopMonitorRun::create([
                            'lab_generation_id' => $generation?->id,
                            'symbol' => $symbol,
                            'timeframe' => $timeframe,
                            'generation' => $generation?->generation,
                            'stage' => (string) ($report['current_stage'] ?? 'unknown'),
                            'status' => $status,
                            'health_score' => $score,
                            'report' => $report,
                            'checked_at' => $now,
                        ]);
                    }

                    if (Schema::hasTable('service_health_checks')) {
                        $key = "lighthouse_vertical_loop:{$symbol}:{$timeframe}";
                        $previous = ServiceHealthCheck::query()->where('service_key', $key)->first();
                        ServiceHealthCheck::updateOrCreate(
                            ['service_key' => $key],
                            [
                                'service_label' => "Lighthouse Vertical Loop {$symbol} {$timeframe}",
                                'status' => $status,
                                'health_score' => $score,
                                'last_ok_at' => $status === 'ok' ? now() : $previous?->last_ok_at,
                                'last_checked_at' => now(),
                                'stale_after_seconds' => 900,
                                'message' => (string) ($report['next_operator_action'] ?? "Vertical loop status: {$status}."),
                                'metrics' => $report,
                            ],
                        );

                        if ($previous?->status !== $status) {
                            $this->logs->write(
                                'lighthouse_vertical_loop_status_changed',
                                "Lighthouse vertical loop {$symbol} {$timeframe} status changed to {$status}.",
                                ['previous_status' => $previous?->status, 'status' => $status, 'report' => $report],
                                $status === 'critical' ? 'critical' : ($status === 'warning' ? 'warning' : 'info'),
                                'lighthouse_vertical_loop',
                                'monitor',
                                $status,
                                LighthouseVerticalLoopMonitorRun::class,
                                $run?->id,
                            );
                        }
                    }

                    return $run;
                });
            } catch (QueryException $exception) {
                if (! $this->isTransientLockContention($exception)) {
                    throw $exception;
                }

                // A constructor may acquire its lease in the narrow interval
                // after the preflight probe. Roll back this disposable
                // projection and let the next monitor tick persist it.
                $report['monitor_persistence'] = [
                    'status' => 'deferred',
                    'reason_code' => 'TRANSIENT_DATABASE_LOCK_CONTENTION',
                    'retry_policy' => 'next_scheduler_tick',
                    'canonical_evidence_affected' => false,
                ];
            }
        }

        if ($persistenceRequested && ! isset($report['monitor_persistence'])) {
            $report['monitor_persistence'] = [
                'status' => 'persisted',
                'reason_code' => null,
                'retry_policy' => null,
                'canonical_evidence_affected' => false,
            ];
        }

        return $report + ['monitor_run_id' => $run?->id];
    }

    private function isTransientLockContention(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return in_array($driverCode, [1205, 1213], true)
            || $sqlState === '40001'
            || str_contains(strtolower($exception->getMessage()), 'lock wait timeout')
            || str_contains(strtolower($exception->getMessage()), 'deadlock found');
    }

    /** @return array<string, mixed> */
    private function scopeContract(?AiLaboratory $lab, ?LabGeneration $generation): array
    {
        $activeLabs = AiLaboratory::query()->where('is_active', true)->get(['id', 'symbol', 'timeframe', 'lifecycle_mode']);
        $parallelXauusdLabs = $activeLabs->filter(fn (AiLaboratory $item): bool => strtoupper((string) $item->symbol) === LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL
            && strtoupper((string) $item->timeframe) !== LearningProtocolSafetyService::LIGHTHOUSE_TIMEFRAME
        )->map(fn (AiLaboratory $item): array => [
            'symbol' => $item->symbol,
            'timeframe' => $item->timeframe,
            'lifecycle_mode' => $item->lifecycle_mode,
        ])->values()->all();
        $violations = $activeLabs->filter(function (AiLaboratory $item): bool {
            $expected = strtoupper((string) $item->symbol) === LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL
                && strtoupper((string) $item->timeframe) === LearningProtocolSafetyService::LIGHTHOUSE_TIMEFRAME
                ? 'lighthouse'
                : 'shadow';

            return strtolower((string) $item->lifecycle_mode) !== $expected;
        })->map(fn (AiLaboratory $item): array => [
            'symbol' => $item->symbol,
            'timeframe' => $item->timeframe,
            'lifecycle_mode' => $item->lifecycle_mode,
        ])->values()->all();
        $activeShadow = LabGeneration::query()
            ->with('laboratory:id,symbol,timeframe,lifecycle_mode')
            ->whereIn('status', self::ACTIVE_GENERATION_STATUSES)
            ->get()
            ->filter(fn (LabGeneration $item): bool => ! ($item->laboratory
                    && strtoupper((string) $item->laboratory->symbol) === LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL
                    && strtoupper((string) $item->laboratory->timeframe) === LearningProtocolSafetyService::LIGHTHOUSE_TIMEFRAME)
                && strtolower((string) $item->laboratory?->lifecycle_mode) === 'shadow'
            )
            ->count();
        $unexpectedActive = LabGeneration::query()
            ->with('laboratory:id,symbol,timeframe,lifecycle_mode')
            ->whereIn('status', self::ACTIVE_GENERATION_STATUSES)
            ->get()
            ->filter(fn (LabGeneration $item): bool => ! ($item->laboratory
                    && strtoupper((string) $item->laboratory->symbol) === LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL
                    && strtoupper((string) $item->laboratory->timeframe) === LearningProtocolSafetyService::LIGHTHOUSE_TIMEFRAME)
                && strtolower((string) $item->laboratory?->lifecycle_mode) !== 'shadow'
            )
            ->count();

        $ok = $lab !== null
            && strtolower((string) $lab->lifecycle_mode) === 'lighthouse'
            && $violations === []
            && $parallelXauusdLabs === []
            && $unexpectedActive === 0;

        return [
            'ok' => $ok,
            'lighthouse_lab_id' => $lab?->id,
            'lighthouse_generation_id' => $generation?->id,
            'active_labs' => $activeLabs->count(),
            'active_shadow_generations' => $activeShadow,
            'lifecycle_mode_violations' => $violations,
            'parallel_xauusd_labs' => $parallelXauusdLabs,
            'unexpected_non_lighthouse_active_generations' => $unexpectedActive,
            'message' => $ok
                ? 'One XAUUSD multi-timeframe organism owns the production lineage; H1 is only its storage key.'
                : 'Lab scope does not match the single-organism contract; promotion remains blocked.',
        ];
    }

    /** @return array<string, mixed> */
    private function evidenceState(LabGeneration $generation): array
    {
        $agents = $generation->agents;
        $agentIds = $agents->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        $modelIds = $agents->pluck('model_version_id')->map(fn ($id): int => (int) $id)->values()->all();
        $performances = ModelMarketPerformance::query()
            ->whereIn('model_version_id', $modelIds ?: [0])
            ->where('symbol', LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL)
            ->where('timeframe', LearningProtocolSafetyService::LIGHTHOUSE_TIMEFRAME)
            ->get();
        $screenDecisions = CandidateGateDecision::query()
            ->whereIn('lab_agent_id', $agentIds ?: [0])
            ->where('stage', 'screening')
            ->get();
        $fullRuns = LabEvaluationRun::query()
            ->where('lab_generation_id', $generation->id)
            ->where('phase', 'full_validation')
            ->latest('id')
            ->get()
            ->groupBy('lab_agent_id');
        $trialRows = Schema::hasTable('lab_trial_ledger')
            ? LabTrialLedger::query()->where('lab_generation_id', $generation->id)->get()
            : collect();

        $canonicalTrialRows = $trialRows->filter(fn (LabTrialLedger $row): bool => (string) $row->identity_status === 'canonical'
            && $this->isSha256($row->data_manifest_hash)
            && $this->isSha256($row->identity_fingerprint)
        );
        $passedScreenAgents = $screenDecisions->where('decision', 'passed')->pluck('lab_agent_id')->filter()->unique()->values();
        $candidateAgentIds = $passedScreenAgents->filter(function ($agentId) use ($canonicalTrialRows): bool {
            return $canonicalTrialRows->where('lab_agent_id', (int) $agentId)->isNotEmpty();
        })->map(fn ($id): int => (int) $id)->values();

        return [
            'agents' => $agents,
            'agent_ids' => $agentIds,
            'model_ids' => $modelIds,
            'performances' => $performances,
            'screen_decisions' => $screenDecisions,
            'full_runs' => $fullRuns,
            'trial_rows' => $trialRows,
            'canonical_trial_rows' => $canonicalTrialRows,
            'candidate_agent_ids' => $candidateAgentIds->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function milestones(LabGeneration $generation, array $state, array $entryLayer): array
    {
        $candidateIds = collect($state['candidate_agent_ids']);
        $performances = $state['performances'];
        $fullPassIds = [];
        foreach ($candidateIds as $agentId) {
            $run = collect($state['full_runs']->get($agentId, collect()))->first();
            if ($run && $run->status === 'completed' && $this->evidence->learningEligibility($run)['complete']) {
                $fullPassIds[] = (int) $agentId;
            }
        }
        $fullPass = collect($fullPassIds);
        $forwardPassIds = $performances
            ->filter(function (ModelMarketPerformance $performance) use ($state, $candidateIds): bool {
                $agent = collect($state['agents'])->firstWhere('model_version_id', $performance->model_version_id);

                return $agent
                    && $candidateIds->contains((int) $agent->id)
                    && in_array((string) $performance->status, ['forward_validated', 'paper', 'champion'], true)
                    && (string) $performance->evidence_status === 'valid';
            })
            ->values();
        $forwardAgentIds = $forwardPassIds->map(function (ModelMarketPerformance $performance) use ($state): int {
            return (int) collect($state['agents'])->firstWhere('model_version_id', $performance->model_version_id)?->id;
        })->filter()->values();
        $forwardPass = $forwardAgentIds->intersect($fullPass)->isNotEmpty();
        $paperSignals = Schema::hasTable('paper_signals')
            ? PaperSignal::query()->whereIn('model_market_performance_id', $forwardPassIds->pluck('id')->all() ?: [0])->where('symbol', LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL)->get()
            : collect();
        $passports = Schema::hasTable('paper_signal_passports')
            ? PaperSignalPassport::query()->whereIn('paper_signal_id', $paperSignals->pluck('id')->all() ?: [0])->where('lane', 'official')->get()
            : collect();
        $validPassports = $passports->filter(fn (PaperSignalPassport $passport): bool => collect([
            $passport->h1_context_hash,
            $passport->data_hash,
            $passport->code_hash,
            $passport->parameter_hash,
            $passport->execution_hash,
            $passport->mtf_contract_hash,
            $passport->passport_hash,
        ])->every(fn (mixed $hash): bool => $this->isSha256($hash)));
        $paperOutcomes = Schema::hasTable('paper_signal_outcomes')
            ? PaperSignalOutcome::query()->whereIn('paper_signal_id', $validPassports->pluck('paper_signal_id')->all() ?: [0])->get()
            : collect();
        $realityScores = Schema::hasTable('reality_scores')
            ? RealityScore::query()
                ->whereIn('source_id', $forwardPassIds->pluck('id')->all() ?: [0])
                ->whereIn('source_type', [ModelMarketPerformance::class, 'model_market_performance'])
                ->get()
            : collect();

        $candidateReady = $candidateIds->isNotEmpty();
        $fullReady = $candidateReady && $fullPass->isNotEmpty();
        $forwardReady = $fullReady && $forwardPass;
        $paperSignalReady = $forwardReady
            && ! (bool) $entryLayer['separate_population_active']
            && $validPassports->isNotEmpty();
        $paperOutcomeReady = $paperSignalReady && $paperOutcomes->isNotEmpty();
        $realityReady = $paperOutcomeReady && $realityScores->isNotEmpty();

        return [
            'candidate' => [
                'ready' => $candidateReady,
                'count' => $candidateIds->count(),
                'agent_ids' => $candidateIds->all(),
                'canonical_trial_count' => $state['canonical_trial_rows']->count(),
                'message' => $candidateReady
                    ? 'At least one screening-passed candidate has a canonical replay identity.'
                    : 'No reproducible screening candidate exists yet; technical gaps must be recovered before failed agents become mutation evidence.',
            ],
            'full_replay' => [
                'ready' => $fullReady,
                'count' => $fullPass->count(),
                'agent_ids' => $fullPass->all(),
                'message' => $fullReady ? 'At least one candidate has a complete full-replay evidence chain.' : 'No candidate has completed a complete full replay.',
            ],
            'forward' => [
                'ready' => $forwardReady,
                'count' => $forwardPassIds->count(),
                'performance_ids' => $forwardPassIds->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'message' => $forwardReady ? 'At least one full-replay candidate passed the forward gate with valid evidence.' : 'Forward validation is not yet passed.',
            ],
            'paper_signal' => [
                'ready' => $paperSignalReady,
                'count' => $validPassports->count(),
                'signal_ids' => $paperSignals->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'entry_layer_forward_count' => 0,
                'official_passport_count' => $passports->count(),
                'valid_passport_count' => $validPassports->count(),
                'passport_integrity_missing_hash_count' => $passports->count() - $validPassports->count(),
                'message' => $paperSignalReady
                    ? 'An official passport proves the unified H4/H1/M15/M5 organism evidence chain.'
                    : 'Paper signal is blocked until the unified generation passes forward validation and an official MTF passport exists.',
            ],
            'paper_outcome' => [
                'ready' => $paperOutcomeReady,
                'count' => $paperOutcomes->count(),
                'outcome_ids' => $paperOutcomes->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'message' => $paperOutcomeReady ? 'At least one immutable paper signal has a closed outcome.' : 'No paper outcome exists yet.',
            ],
            'reality_feedback' => [
                'ready' => $realityReady,
                'count' => $realityScores->count(),
                'score_ids' => $realityScores->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'message' => $realityReady ? 'Reality verification feedback is attached to the candidate.' : 'Reality feedback is not attached; paper outcome evidence is still required first.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function technicalState(LabGeneration $generation, array $state): array
    {
        $agents = $state['agents'];
        $runs = LabEvaluationRun::query()
            ->where('lab_generation_id', $generation->id)
            ->whereIn('phase', ['screening', 'full_validation'])
            ->latest('id')
            ->get();
        $latestByAgentPhase = $runs
            ->groupBy(fn (LabEvaluationRun $run): string => (string) $run->lab_agent_id.':'.(string) $run->phase)
            ->map(fn ($rows): LabEvaluationRun => $rows->first());
        $technicalRows = collect();
        $incompleteEvidence = collect();
        $missingScreenAgentIds = collect();
        $inProgressAgentIds = collect();

        foreach ($agents as $agent) {
            $screenRun = $latestByAgentPhase->get((string) $agent->id.':screening');
            if (! $screenRun) {
                $missingScreenAgentIds->push((int) $agent->id);
            } else {
                $eligibility = $this->evidence->learningEligibility($screenRun);
                if ($screenRun->status === 'started') {
                    $inProgressAgentIds->push((int) $agent->id);
                } elseif ($screenRun->status !== 'completed' || ! $eligibility['complete']) {
                    $technicalRows->push([
                        'agent_id' => (int) $agent->id,
                        'run_id' => $screenRun->run_id,
                        'phase' => 'screening',
                        'status' => $screenRun->status,
                        'reason_codes' => $eligibility['reason_codes'] ?? [],
                    ]);
                    $incompleteEvidence->push([
                        'agent_id' => (int) $agent->id,
                        'run_id' => $screenRun->run_id,
                        'phase' => 'screening',
                        'reason_codes' => $eligibility['reason_codes'] ?? [],
                    ]);
                }
            }

            $fullRun = $latestByAgentPhase->get((string) $agent->id.':full_validation');
            if ($fullRun) {
                $eligibility = $this->evidence->learningEligibility($fullRun);
                if ($fullRun->status === 'started') {
                    $inProgressAgentIds->push((int) $agent->id);
                } elseif ($fullRun->status !== 'completed' || ! $eligibility['complete']) {
                    $technicalRows->push([
                        'agent_id' => (int) $agent->id,
                        'run_id' => $fullRun->run_id,
                        'phase' => 'full_validation',
                        'status' => $fullRun->status,
                        'reason_codes' => $eligibility['reason_codes'] ?? [],
                    ]);
                    $incompleteEvidence->push([
                        'agent_id' => (int) $agent->id,
                        'run_id' => $fullRun->run_id,
                        'phase' => 'full_validation',
                        'reason_codes' => $eligibility['reason_codes'] ?? [],
                    ]);
                }
            }
        }

        $technicalAgents = $agents->whereIn('lifecycle_status', ['evaluation_error', 'technical_quarantine']);
        $maxAttempts = $runs->filter(function (LabEvaluationRun $run): bool {
            return str_contains(strtolower((string) $run->error_class), 'maxattemptsexceeded')
                || str_contains(strtolower((string) $run->error_message), 'maxattemptsexceeded');
        })->count();
        $generationInProgress = in_array((string) $generation->status, LabPopulationService::ACTIVE_GENERATION_STATUSES, true);
        $evidenceInProgress = $generationInProgress
            || $inProgressAgentIds->isNotEmpty()
            || $missingScreenAgentIds->isNotEmpty();
        $technicalBlocked = ! $evidenceInProgress && ($technicalRows->isNotEmpty()
            || $technicalAgents->isNotEmpty()
            || $missingScreenAgentIds->isNotEmpty());

        return [
            'technical_error_run_count' => $technicalRows->count(),
            'technical_agent_count' => $technicalAgents->count() + $technicalRows->pluck('agent_id')->unique()->count(),
            'technical_rows' => $technicalRows->values()->all(),
            'missing_screen_run_agent_ids' => $missingScreenAgentIds->all(),
            'in_progress_agent_ids' => $inProgressAgentIds->unique()->values()->all(),
            'evidence_state' => $evidenceInProgress ? 'EVIDENCE_IN_PROGRESS' : 'FINAL',
            'all_agents_terminal' => ! $evidenceInProgress,
            'incomplete_evidence_count' => $incompleteEvidence->count(),
            'incomplete_evidence' => $incompleteEvidence->values()->all(),
            'max_attempts_exceeded_count' => $maxAttempts,
            'technical_blocked' => $technicalBlocked,
            'technical_next_action' => $technicalBlocked
                ? 'Drain the lab queue, then run dry-run recovery. Treat these rows as technical evidence only.'
                : 'No technical recovery is currently required.',
        ];
    }

    /** @return array<string, mixed> */
    private function strategyState(LabGeneration $generation, array $state): array
    {
        $decisions = $state['screen_decisions'];
        $failed = $decisions->where('decision', 'failed');

        return [
            'screening_decision_count' => $decisions->count(),
            'screening_pass_count' => $decisions->where('decision', 'passed')->count(),
            'screening_failure_count' => $failed->count(),
            'screening_failure_reasons' => $failed->flatMap(fn (CandidateGateDecision $decision): array => (array) $decision->reason_codes)->countBy()->all(),
            'strategy_failure_is_separate_from_technical' => true,
            'strategy_next_action' => $failed->isNotEmpty()
                ? 'Compile gate failures into targeted mutations; do not randomly remutate the same failed candidate.'
                : 'Wait for clean screening evidence before drawing a strategy conclusion.',
        ];
    }

    /** @return array<string, mixed> */
    private function paperState(LabGeneration $generation, array $milestones, array $entryLayer): array
    {
        $forwardIds = (array) data_get($milestones, 'forward.performance_ids', []);
        $signals = Schema::hasTable('paper_signals')
            ? PaperSignal::query()->whereIn('model_market_performance_id', $forwardIds ?: [0])->where('symbol', LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL)->get()
            : collect();
        $passports = Schema::hasTable('paper_signal_passports')
            ? PaperSignalPassport::query()->whereIn('paper_signal_id', $signals->pluck('id')->all() ?: [0])->get()
            : collect();
        $validPassports = $passports->filter(fn (PaperSignalPassport $passport): bool => collect([
            $passport->h1_context_hash,
            $passport->data_hash,
            $passport->code_hash,
            $passport->parameter_hash,
            $passport->execution_hash,
            $passport->mtf_contract_hash,
            $passport->passport_hash,
        ])->every(fn (mixed $hash): bool => $this->isSha256($hash)));

        return [
            'signal_count' => $signals->count(),
            'official_passport_count' => $passports->where('lane', 'official')->count(),
            'valid_passport_count' => $validPassports->where('lane', 'official')->count(),
            'passport_missing_count' => max(0, $signals->count() - $validPassports->count()),
            'passport_integrity_missing_hash_count' => $passports->count() - $validPassports->count(),
            'paper_clock_started' => $validPassports->where('lane', 'official')->isNotEmpty() && (bool) data_get($milestones, 'paper_signal.ready', false),
            'mtf_contract' => 'One XAUUSD population: H4 macro + H1 regime/location + M15 setup/confirmation + M5 execution/invalidation + Risk Sentinel passport',
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function realityState(LabGeneration $generation, array $milestones): array
    {
        $scores = (array) data_get($milestones, 'reality_feedback.score_ids', []);
        $enabled = (bool) config('services.secondary_intelligence.enabled', false);

        return [
            'secondary_intelligence_enabled' => $enabled,
            'reality_feedback_count' => count($scores),
            'status' => count($scores) > 0 ? 'observed' : ($enabled ? 'awaiting_verification' : 'frozen_by_policy'),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function queueState(?LabGeneration $generation = null): array
    {
        $backlog = $this->queue->labQueueBacklog();
        $queues = $this->queue->labQueues();
        $snapshot = $this->queue->queueSnapshot($queues);
        $stats = (array) ($snapshot['stats'] ?? []);
        $rows = collect((array) ($snapshot['rows'] ?? []));
        $oldest = collect($stats)->pluck('oldest_created_at')->filter(fn ($value): bool => $value !== null)->min();
        $oldestReserved = collect($stats)->pluck('oldest_reserved_at')->filter(fn ($value): bool => $value !== null)->min();
        $maxAttempts = collect($stats)->pluck('max_attempts')->filter(fn ($value): bool => $value !== null)->max() ?: 0;
        $replay = $this->replayState();
        $generationBacklog = $generation
            ? $this->queue->generationQueueBacklog($generation->agents->pluck('id')->map(fn ($id): int => (int) $id)->all())
            : null;

        return [
            'backlog' => [
                ...$backlog,
                'reserved' => (int) collect($stats)->sum('reserved'),
                'pending' => (int) collect($stats)->sum('pending'),
                'oldest_age_seconds' => $oldest ? max(0, now()->timestamp - (int) $oldest) : null,
                'oldest_reserved_age_seconds' => $oldestReserved ? max(0, now()->timestamp - (int) $oldestReserved) : null,
                'max_attempts' => (int) $maxAttempts,
                'state_known' => ($snapshot['available'] ?? true) !== false,
            ],
            'generation_backlog' => $generationBacklog,
            'configured_queues' => $queues,
            'priority_contract' => [
                'full_validation_queue' => (string) config('services.lab_queue.full_validation_queue', 'lab-full-validation'),
                'frontier_queue' => (string) config('services.lab_queue.frontier_queue', 'lab-frontier'),
                'screening_queue' => (string) config('services.lab_queue.screening_queue', 'lab-screening'),
                'shared_mutex_key' => (string) config('services.lab_queue.replay_mutex_key', 'neurotrader-ai-heavy-replay'),
                'concurrency_policy' => 'one replay coordinator; priority changes order, never concurrency',
            ],
            'replay' => $replay,
            'apply_policy' => [
                'automatic_apply' => false,
                'apply_allowed_only_when_queue_empty' => $backlog['total'] === 0,
                'operator_approval_required' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function replayState(): array
    {
        return app(ReplayLivenessProbeService::class)->probe();
    }

    private function addMilestoneCheck(array &$checks, string $code, array $milestone): void
    {
        $checks[] = [
            'code' => $code,
            'status' => $milestone['ready'] ? 'passed' : 'attention',
            'message' => $milestone['message'],
            'metrics' => $milestone,
        ];
    }

    private function currentStage(array $milestones): string
    {
        foreach (['candidate', 'full_replay', 'forward', 'paper_signal', 'paper_outcome', 'reality_feedback'] as $stage) {
            if (! (bool) data_get($milestones, "{$stage}.ready", false)) {
                return $stage;
            }
        }

        return 'reality_feedback_complete';
    }

    private function nextAction(array $milestones, array $technical, array $strategy, array $queue): string
    {
        if (($queue['backlog']['total'] ?? 0) > 0) {
            return 'Drain the single lab queue first; keep reconcile/recover in dry-run mode until it is empty.';
        }
        if (($queue['replay']['status'] ?? 'unknown') === 'unknown') {
            return 'Verify the single replay coordinator and protected replay-status endpoint before any recovery apply.';
        }
        if (($queue['replay']['status'] ?? 'unknown') === 'active') {
            return 'Wait for the active single replay to finish; do not duplicate, cancel or apply recovery while it is running.';
        }
        if ($technical['technical_blocked']) {
            return ($queue['backlog']['total'] ?? 0) === 0
                && ($queue['replay']['status'] ?? 'unknown') === 'ok'
                ? 'Queue va replay lane bo‘sh: listed technical rows uchun dry-run recovery natijasini ko‘rib chiqing; apply faqat operator tasdig‘i bilan.'
                : $technical['technical_next_action'];
        }
        if (($strategy['screening_failure_count'] ?? 0) > 0 && ! $milestones['candidate']['ready']) {
            return $strategy['strategy_next_action'];
        }

        return match ($this->currentStage($milestones)) {
            'candidate' => 'Complete clean screening and canonical trial identity for one candidate.',
            'full_replay' => 'Dispatch full replay only for the reproducible candidate after the queue contract is satisfied.',
            'forward' => 'Run forward validation on the complete full-replay candidate; do not start the paper clock yet.',
            'paper_signal' => 'Capture the immutable official paper passport after forward validation passes.',
            'paper_outcome' => 'Run the paper lifecycle to a closed outcome with fill/cost evidence.',
            'reality_feedback' => 'Attach reality verification feedback after paper outcomes; keep live disabled.',
            default => 'Review the completed evidence passport and wait for the next reality observation.',
        };
    }

    private function isSha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/i', trim($value)) === 1;
    }

    /** @return array<string, mixed> */
    private function organismContract(): array
    {
        return [
            'scope' => (string) config('services.xauusd_organism.symbol', 'XAUUSD'),
            'population_scope' => (string) config('services.xauusd_organism.population_scope', 'symbol'),
            'laboratory_storage_timeframe' => (string) config('services.xauusd_organism.laboratory_storage_timeframe', 'H1'),
            'execution_timeframe' => (string) config('services.xauusd_organism.execution_timeframe', 'M5'),
            'timeframe_roles' => (array) config('services.xauusd_organism.timeframe_roles', []),
        ];
    }
}
