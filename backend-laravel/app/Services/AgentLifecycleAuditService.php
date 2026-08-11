<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\AgentKnowledgeCard;
use App\Models\CandidateGateDecision;
use App\Models\EliteAgentPortfolio;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Services\MarketData\HistoricalDataQualityService;
use App\Services\MarketData\MarketVolumeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only operational audit for the complete laboratory lifecycle.
 *
 * This service deliberately does not repair agent state, rewrite evidence or
 * create a candidate.  It joins the mutable lifecycle projection with the
 * immutable evidence plane and reports where the next safe action belongs.
 * The only write made by the audit is an optional summary row in system_logs.
 */
class AgentLifecycleAuditService
{
    public const PROTOCOL = 'agent_lifecycle_audit_v1';

    private const ACTIVE_GENERATION_STATUSES = [
        'draft', 'queued', 'screening', 'full_validation',
    ];

    private const OPEN_AGENT_STATUSES = [
        'draft', 'queued', 'screening', 'full_queued', 'training', 'evaluation_error',
    ];

    private const TERMINAL_SCREEN_RUN_STATUSES = [
        'completed', 'technical_error', 'retry_released', 'skipped', 'legacy_snapshot',
    ];

    private const ALLOWED_PARENTLESS_MODES = [
        'no_parent_available',
        'semantic_group_root_default_seed',
        'exact_semantic_group_screening_seed',
        'control_root_seed_inheritance',
        'first_generation_seed',
    ];

    public function __construct(
        private readonly LabAgentPreflightService $preflight,
        private readonly HistoricalDataQualityService $historicalData,
        private readonly MarketVolumeService $volumes,
        private readonly SystemLogService $logs,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function audit(
        ?string $symbol = null,
        ?string $timeframe = null,
        bool $deep = true,
        bool $persist = true,
    ): array {
        $query = AiLaboratory::query()->where('is_active', true)->orderBy('id');
        if ($symbol !== null && $symbol !== '') {
            $query->where('symbol', strtoupper($symbol));
        }
        if ($timeframe !== null && $timeframe !== '') {
            $query->where('timeframe', strtoupper($timeframe));
        }

        $queue = $this->queueHealth();
        $scopes = $query->get()
            ->map(fn (AiLaboratory $lab): array => $this->auditLaboratory($lab, $deep, $queue['agent_ids']))
            ->values()
            ->all();
        $replay = $this->replayHealth();

        $checks = collect($scopes)
            ->flatMap(fn (array $scope) => $scope['checks'])
            ->push($queue['check'], $replay['check']);
        if ($scopes === []) {
            $checks->push($this->check(
                'NO_ACTIVE_LABORATORIES',
                'blocked',
                'No active laboratory matched the requested audit scope.',
                [],
                'critical',
            ));
        }

        $blocked = $checks->where('status', 'blocked')->count();
        $attention = $checks->where('status', 'attention')->count();
        $inProgress = $checks->where('status', 'in_progress')->count();
        $status = $blocked > 0 ? 'blocked' : ($attention > 0 ? 'attention' : ($inProgress > 0 ? 'in_progress' : 'healthy'));

        $report = [
            'protocol' => self::PROTOCOL,
            'observed_at' => now()->utc()->toIso8601String(),
            'scope' => [
                'symbol' => $symbol ? strtoupper($symbol) : null,
                'timeframe' => $timeframe ? strtoupper($timeframe) : null,
                'deep' => $deep,
            ],
            'summary' => [
                'status' => $status,
                'laboratory_count' => count($scopes),
                'blocked_checks' => $blocked,
                'attention_checks' => $attention,
                'in_progress_checks' => $inProgress,
                'promotion_evidence' => false,
            ],
            'queue' => $queue['metrics'],
            'replay' => $replay['metrics'],
            'laboratories' => $scopes,
            'strengths' => $this->strengths($scopes, $queue['metrics'], $replay['metrics']),
            'recommendations' => $this->recommendations($scopes, $queue['metrics'], $replay['metrics']),
            'findings' => $checks
                ->filter(fn (array $check): bool => ! in_array($check['status'], ['passed', 'in_progress', 'info'], true))
                ->values()
                ->all(),
            'promotion_evidence' => false,
        ];

        if ($persist) {
            $this->persist($report);
        }

        return $report;
    }

    /** @return array<string, mixed> */
    private function auditLaboratory(AiLaboratory $lab, bool $deep, array $queuedAgentIds): array
    {
        $generation = LabGeneration::query()
            ->where('ai_laboratory_id', $lab->id)
            ->with('agents.modelVersion')
            ->orderByDesc('id')
            ->first();

        if (! $generation) {
            return [
                'lab_id' => $lab->id,
                'symbol' => $lab->symbol,
                'timeframe' => $lab->timeframe,
                'generation_id' => null,
                'generation' => null,
                'generation_status' => null,
                'agent_count' => 0,
                'status' => 'blocked',
                'metrics' => [],
                'checks' => [$this->check(
                    'GENERATION_MISSING',
                    'blocked',
                    'Active laboratory has no generation to monitor.',
                    ['lab_id' => $lab->id],
                    'critical',
                )],
            ];
        }

        $agents = $generation->agents;
        $checks = [
            $this->populationCheck($generation, $agents),
            $this->constructorCheck($generation, $agents),
            $this->lineageCheck($generation, $agents, $deep),
            $this->lifecycleCheck($generation, $agents, $queuedAgentIds),
            $this->evidenceCheck($generation, $agents),
            $this->datasetCheck($generation, $lab, $deep),
            $this->regimeCheck($generation, $agents),
            $this->volumeCheck($generation, $agents, $lab, $deep),
            $this->gateCheck($generation, $agents),
            $this->checkpointCheck($generation, $agents),
            $this->forwardEliteCheck($generation, $agents, $lab),
        ];

        $scopeStatus = collect($checks)->contains('status', 'blocked')
            ? 'blocked'
            : (collect($checks)->contains('status', 'attention')
                ? 'attention'
                : (collect($checks)->contains('status', 'in_progress') ? 'in_progress' : 'healthy'));

        $statusCounts = $agents->groupBy('lifecycle_status')->map->count()->all();
        $screened = $agents->where('lifecycle_status', 'screened')->count();
        $preflight = collect($checks)->firstWhere('code', 'LINEAGE_AND_PREFLIGHT');
        $forwardElite = collect($checks)->firstWhere('code', 'FORWARD_ELITE_LIFECYCLE');

        return [
            'lab_id' => $lab->id,
            'symbol' => $lab->symbol,
            'timeframe' => $lab->timeframe,
            'generation_id' => $generation->id,
            'generation' => $generation->generation,
            'generation_status' => $generation->status,
            'agent_count' => $agents->count(),
            'status' => $scopeStatus,
            'metrics' => [
                'planned_population' => (int) $generation->population_size,
                'agent_status_counts' => $statusCounts,
                'screened_agents' => $screened,
                'preflight_passed' => (int) data_get($preflight, 'metrics.passed', 0),
                'preflight_failed' => (int) data_get($preflight, 'metrics.failed', 0),
                'forward_elite_stage' => data_get($forwardElite, 'metrics.next_stage', 'unknown'),
                'forward_gate_passed' => (int) data_get($forwardElite, 'metrics.forward_gate_passed_count', 0),
                'elite_portfolio_passed' => (int) data_get($forwardElite, 'metrics.elite_portfolio_passed_count', 0),
                'promotion_evidence' => false,
            ],
            'checks' => $checks,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function populationCheck(LabGeneration $generation, $agents): array
    {
        $context = (array) $generation->trigger_context;
        $contract = (array) data_get($context, 'population_group_contract', []);
        $actual = $agents->count();
        $expected = (int) $generation->population_size;
        $contractExpected = (int) data_get($contract, 'planned_population', 0);
        $groupCounts = $agents->groupBy(fn (LabAgent $agent): string => $this->agentGroup($agent))->map->count()->all();
        $plannedGroups = [];
        foreach ((array) data_get($contract, 'groups', []) as $key => $group) {
            $plannedGroups[(string) $key] = (int) data_get($group, 'planned_seats', 0);
        }
        $groupMismatches = [];
        foreach ($plannedGroups as $group => $seats) {
            if ((int) ($groupCounts[$group] ?? 0) !== $seats) {
                $groupMismatches[$group] = [
                    'expected' => $seats,
                    'actual' => (int) ($groupCounts[$group] ?? 0),
                ];
            }
        }
        $extraGroups = array_diff_key($groupCounts, $plannedGroups);
        $balancedExpected = (bool) data_get($contract, 'balanced_core', false);
        $contractMismatch = $actual !== $expected
            || ($contractExpected > 0 && $contractExpected !== $actual)
            || ($balancedExpected && $groupMismatches !== [])
            || ($balancedExpected && $extraGroups !== []);
        $active = in_array((string) $generation->status, self::ACTIVE_GENERATION_STATUSES, true);

        return $this->check(
            'POPULATION_GROUP_CONTRACT',
            $contractMismatch ? ($active ? 'blocked' : 'attention') : 'passed',
            $contractMismatch
                ? 'Generation population does not match its declared council/group contract.'
                : 'Population size and declared council groups are aligned.',
            [
                'generation_id' => $generation->id,
                'planned_population' => $expected,
                'actual_population' => $actual,
                'contract_planned_population' => $contractExpected ?: null,
                'group_counts' => $groupCounts,
                'planned_groups' => $plannedGroups,
                'group_mismatches' => $groupMismatches,
                'extra_groups' => $extraGroups,
                'balanced_core' => $balancedExpected,
                'promotion_evidence' => false,
            ],
            $contractMismatch && $active ? 'critical' : ($contractMismatch ? 'warning' : 'info'),
        );
    }

    /** @return array<string, mixed> */
    private function constructorCheck(LabGeneration $generation, $agents): array
    {
        $context = (array) $generation->trigger_context;
        $audit = (array) data_get($context, 'constructor_audit', []);
        $invalid = [];
        $zeroDiff = [];
        foreach ($agents as $agent) {
            $metadata = (array) ($agent->modelVersion?->metadata ?? []);
            $control = $this->isControlOnly($agent);
            $invariant = (array) data_get($metadata, 'mutation_constructor_invariant', []);
            if (! $control && data_get($invariant, 'status') !== 'passed') {
                $invalid[] = $agent->id;
            }
            if (! $control && $this->isZeroDiff((array) $agent->parameter_diff)) {
                $zeroDiff[] = $agent->id;
            }
        }
        $skipped = (array) data_get($audit, 'skipped_zero_diff_slots', []);
        $hasIssue = $invalid !== [] || $zeroDiff !== [] || $skipped !== [];
        $active = in_array((string) $generation->status, self::ACTIVE_GENERATION_STATUSES, true);

        return $this->check(
            'CONSTRUCTOR_INVARIANT',
            $hasIssue ? ($active ? 'blocked' : 'attention') : 'passed',
            $hasIssue
                ? 'One-gene/control constructor evidence has an invalid or zero-diff member.'
                : 'Constructor invariant is present for every non-control agent.',
            [
                'generation_id' => $generation->id,
                'invalid_agent_ids' => array_slice($invalid, 0, 20),
                'zero_diff_agent_ids' => array_slice($zeroDiff, 0, 20),
                'skipped_zero_diff_slots' => array_slice($skipped, 0, 20),
                'planned_slots' => (int) data_get($audit, 'planned_slots', $generation->population_size),
                'created_agents' => (int) data_get($audit, 'created_agents', $agents->count()),
                'control_only_count' => $agents->filter(fn (LabAgent $agent): bool => $this->isControlOnly($agent))->count(),
                'promotion_evidence' => false,
            ],
            $hasIssue && $active ? 'critical' : ($hasIssue ? 'warning' : 'info'),
        );
    }

    /** @return array<string, mixed> */
    private function lineageCheck(LabGeneration $generation, $agents, bool $deep): array
    {
        $parentless = [];
        $parentlessProtocolMissing = [];
        $preflightFailures = [];
        $preflightPasses = 0;
        $parentModes = [];

        foreach ($agents as $agent) {
            $metadata = (array) ($agent->modelVersion?->metadata ?? []);
            $lineage = (array) data_get($metadata, 'semantic_lineage', []);
            $inheritance = (array) data_get($metadata, 'parent_inheritance_protocol', []);
            $hasParent = filled($agent->parent_a_model_version_id) || filled($agent->parent_b_model_version_id);
            $mode = (string) data_get($lineage, 'mode', '');
            $parentModes[$mode !== '' ? $mode : 'missing'] = ($parentModes[$mode !== '' ? $mode : 'missing'] ?? 0) + 1;

            if (! $hasParent) {
                $parentless[] = $agent->id;
                if (! in_array($mode, self::ALLOWED_PARENTLESS_MODES, true)
                    && ! in_array((string) data_get($inheritance, 'parent_selection', ''), [
                        'no_parent_available', 'exact_group_root_default', 'control_root_seed_inheritance',
                    ], true)) {
                    $parentlessProtocolMissing[] = $agent->id;
                }
            }

            if (! $deep) {
                continue;
            }

            try {
                $inspection = $this->preflight->inspect($agent, 'screening');
                if ((bool) data_get($inspection, 'passed', false)) {
                    $preflightPasses++;
                } else {
                    $preflightFailures[$agent->id] = array_values((array) data_get($inspection, 'errors', []));
                }
            } catch (\Throwable $exception) {
                $preflightFailures[$agent->id] = ['PREFLIGHT_EXCEPTION:'.$exception::class];
            }
        }

        $failed = $parentlessProtocolMissing !== [] || $preflightFailures !== [];
        $active = in_array((string) $generation->status, self::ACTIVE_GENERATION_STATUSES, true);
        $failureSamples = [];
        foreach ($preflightFailures as $agentId => $errors) {
            $failureSamples[] = ['agent_id' => $agentId, 'errors' => array_slice($errors, 0, 8)];
            if (count($failureSamples) >= 20) break;
        }

        return $this->check(
            'LINEAGE_AND_PREFLIGHT',
            $failed ? ($active ? 'blocked' : 'attention') : 'passed',
            $failed
                ? 'Lineage/preflight found an integrity problem; parentless roots are not failures when explicitly declared.'
                : 'Parent compatibility and agent preflight are valid; explicit parentless roots are accepted.',
            [
                'generation_id' => $generation->id,
                'parentless_count' => count($parentless),
                'parentless_agent_ids' => array_slice($parentless, 0, 20),
                'parentless_protocol_missing_ids' => array_slice($parentlessProtocolMissing, 0, 20),
                'parent_mode_counts' => $parentModes,
                'preflight_checked' => $deep ? $agents->count() : 0,
                'passed' => $preflightPasses,
                'failed' => count($preflightFailures),
                'failure_samples' => $failureSamples,
                'promotion_evidence' => false,
            ],
            $failed && $active ? 'critical' : ($failed ? 'warning' : 'info'),
        );
    }

    /** @return array<string, mixed> */
    private function lifecycleCheck(LabGeneration $generation, $agents, array $queuedAgentIds): array
    {
        $open = $agents->filter(fn (LabAgent $agent): bool => in_array((string) $agent->lifecycle_status, self::OPEN_AGENT_STATUSES, true));
        $screened = $agents->where('lifecycle_status', 'screened');
        $queued = $agents->whereIn('lifecycle_status', ['queued', 'full_queued']);
        $queuedMissing = $queued->filter(fn (LabAgent $agent): bool => ! in_array((int) $agent->id, $queuedAgentIds, true))->pluck('id')->values()->all();
        $screenedMissingRun = [];
        if (Schema::hasTable('lab_evaluation_runs') && $screened->isNotEmpty()) {
            $completedAgentIds = LabEvaluationRun::query()
                ->where('lab_generation_id', $generation->id)
                ->where('phase', 'screening')
                ->where('status', 'completed')
                ->whereIn('lab_agent_id', $screened->pluck('id'))
                ->pluck('lab_agent_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->all();
            $screenedMissingRun = $screened
                ->reject(fn (LabAgent $agent): bool => in_array((int) $agent->id, $completedAgentIds, true))
                ->pluck('id')->values()->all();
        }

        $statusMismatch = (string) $generation->status === 'screened' && $open->isNotEmpty();
        $issue = $queuedMissing !== [] || $screenedMissingRun !== [] || $statusMismatch;
        $active = in_array((string) $generation->status, self::ACTIVE_GENERATION_STATUSES, true);
        $progress = $open->isNotEmpty() && ! $issue;

        return $this->check(
            'LIFECYCLE_STATUS_ALIGNMENT',
            $issue ? ($active ? 'blocked' : 'attention') : ($progress ? 'in_progress' : 'passed'),
            $issue
                ? 'Agent lifecycle projection and queue/evidence state are not aligned.'
                : ($progress ? 'Lifecycle is active and waiting for the next serialized replay step.' : 'Lifecycle status is terminal and aligned.'),
            [
                'generation_id' => $generation->id,
                'generation_status' => $generation->status,
                'agent_status_counts' => $agents->groupBy('lifecycle_status')->map->count()->all(),
                'open_agent_count' => $open->count(),
                'queued_agent_count' => $queued->count(),
                'queued_agent_missing_job_ids' => array_slice($queuedMissing, 0, 20),
                'screened_agent_missing_completed_run_ids' => array_slice($screenedMissingRun, 0, 20),
                'generation_status_mismatch' => $statusMismatch,
                'promotion_evidence' => false,
            ],
            $issue && $active ? 'critical' : ($issue ? 'warning' : 'info'),
        );
    }

    /** @return array<string, mixed> */
    private function evidenceCheck(LabGeneration $generation, $agents): array
    {
        if (! Schema::hasTable('lab_evaluation_runs') || ! Schema::hasTable('lab_evidence_artifacts')) {
            return $this->check(
                'IMMUTABLE_EVIDENCE_COVERAGE',
                'blocked',
                'Immutable lab evidence tables are unavailable.',
                ['generation_id' => $generation->id, 'promotion_evidence' => false],
                'critical',
            );
        }

        $runs = LabEvaluationRun::query()->where('lab_generation_id', $generation->id)->get();
        $screenRuns = $runs->where('phase', 'screening');
        $completed = $screenRuns->where('status', 'completed');
        $terminal = $screenRuns->whereIn('status', self::TERMINAL_SCREEN_RUN_STATUSES);
        $responseRuns = $completed->filter(fn (LabEvaluationRun $run): bool => filled($run->response_hash));
        $artifacts = LabEvidenceArtifact::query()->where('lab_generation_id', $generation->id)->get();
        $traceRuns = $artifacts->where('artifact_type', 'decision_trace_manifest')
            ->filter(fn (LabEvidenceArtifact $artifact): bool => (bool) data_get($artifact->metadata, 'complete', data_get($artifact->payload, 'complete', false)))
            ->pluck('run_id')->filter()->unique();
        $requestRuns = $artifacts->where('artifact_type', 'evaluation_request')->pluck('run_id')->filter()->unique();
        $ledgerRuns = $artifacts
            ->whereIn('artifact_type', ['trade_ledger', 'agent_trade_ledger'])
            ->filter(fn (LabEvidenceArtifact $artifact): bool => (bool) data_get($artifact->metadata, 'complete', false))
            ->pluck('run_id')->filter()->unique();
        $screened = $agents->where('lifecycle_status', 'screened');
        $screenedAgentIds = $completed->pluck('lab_agent_id')->filter()->unique()->all();
        $missingScreen = $screened->reject(fn (LabAgent $agent): bool => in_array((int) $agent->id, $screenedAgentIds, true))->pluck('id')->values()->all();
        $strictMissing = $responseRuns->filter(fn (LabEvaluationRun $run): bool => ! $requestRuns->contains($run->run_id)
            || ! $traceRuns->contains($run->run_id)
            || ! $ledgerRuns->contains($run->run_id))->pluck('run_id')->values()->all();
        $active = in_array((string) $generation->status, self::ACTIVE_GENERATION_STATUSES, true);
        $evidenceIssue = $strictMissing !== [] && $responseRuns->isNotEmpty();
        $hardIssue = ($generation->status === 'screened' && ($missingScreen !== [] || $strictMissing !== []))
            || ($generation->status === 'full_validation' && $screenRuns->isEmpty() && $agents->isNotEmpty());
        $inProgress = $active && $agents->whereIn('lifecycle_status', self::OPEN_AGENT_STATUSES)->isNotEmpty();

        return $this->check(
            'IMMUTABLE_EVIDENCE_COVERAGE',
            $hardIssue
                ? ($active ? 'blocked' : 'attention')
                : ($evidenceIssue
                    ? 'attention'
                    : ($inProgress && $completed->isEmpty() ? 'in_progress' : 'passed')),
            $hardIssue
                ? 'Terminal agent state is missing an immutable run/request/trace/ledger chain.'
                : ($evidenceIssue
                    ? 'Completed response runs have incomplete trace or trade-ledger artifacts; this remains diagnostic and cannot count toward promotion.'
                    : ($inProgress && $completed->isEmpty()
                    ? 'Immutable evidence is being built as queued agents enter the replay lane.'
                    : 'Immutable lifecycle/evaluation evidence is complete for the observed terminal screen runs.')),
            [
                'generation_id' => $generation->id,
                'run_count' => $runs->count(),
                'screen_run_count' => $screenRuns->count(),
                'completed_screen_run_count' => $completed->count(),
                'terminal_screen_run_count' => $terminal->count(),
                'response_run_count' => $responseRuns->count(),
                'trace_run_count' => $traceRuns->count(),
                'request_artifact_run_count' => $requestRuns->count(),
                'ledger_run_count' => $ledgerRuns->count(),
                'missing_screen_agent_ids' => array_slice($missingScreen, 0, 20),
                'incomplete_trace_run_ids' => array_slice($strictMissing, 0, 20),
                'artifact_count' => $artifacts->count(),
                'promotion_evidence' => false,
            ],
            $hardIssue && $active ? 'critical' : (($hardIssue || $evidenceIssue) ? 'warning' : 'info'),
        );
    }

    /** @return array<string, mixed> */
    private function datasetCheck(LabGeneration $generation, AiLaboratory $lab, bool $deep): array
    {
        $quality = $this->historicalData->inspect($lab->symbol, $lab->timeframe);
        $context = (array) $generation->trigger_context;
        $price = (array) data_get($context, 'canonical_dataset_snapshots.price', []);
        $path = (string) data_get($price, 'path', '');
        $declaredHash = (string) data_get($price, 'sha256', data_get($price, 'manifest.sha256', ''));
        $actualHash = $path !== '' && is_file($path) ? hash_file('sha256', $path) : null;
        $snapshotValid = $path !== '' && $declaredHash !== '' && is_string($actualHash) && hash_equals($declaredHash, $actualHash);
        $full = $deep
            ? $this->historicalData->fullReplayCoverage(
                $lab->symbol,
                $lab->timeframe,
                is_array(data_get($price, 'manifest')) ? data_get($price, 'manifest') : null,
                is_array(data_get($context, 'canonical_dataset_snapshots.foundation.manifest'))
                    ? data_get($context, 'canonical_dataset_snapshots.foundation.manifest')
                    : null,
            )
            : ['status' => 'skipped', 'reasons' => []];
        $issues = [];
        if (($quality['status'] ?? 'blocked') !== 'ready') $issues[] = 'HISTORICAL_DATA_NOT_READY';
        if (! $snapshotValid) $issues[] = 'GENERATION_PRICE_SNAPSHOT_HASH_INVALID_OR_MISSING';
        if ($deep && ($full['status'] ?? 'blocked') !== 'ready') $issues[] = 'FULL_REPLAY_COVERAGE_NOT_READY';
        $active = in_array((string) $generation->status, self::ACTIVE_GENERATION_STATUSES, true);
        $fullReplayBlockingStatuses = [
            'full_validation', 'completed', 'screened', 'forward_validated',
            'paper', 'promoted', 'champion', 'active', 'elite',
        ];
        $fullReplayBlocking = in_array((string) $generation->status, $fullReplayBlockingStatuses, true)
            || (string) $generation->status === 'full_queued';
        $preDispatch = in_array((string) $generation->status, ['draft', 'queued'], true)
            && ! $snapshotValid
            && ! in_array('HISTORICAL_DATA_NOT_READY', $issues, true);
        $onlyPendingSnapshot = $preDispatch
            && count(array_diff($issues, ['GENERATION_PRICE_SNAPSHOT_HASH_INVALID_OR_MISSING'])) === 0;
        $onlyFullReplayCoverageIssue = $issues !== []
            && count(array_diff($issues, ['FULL_REPLAY_COVERAGE_NOT_READY'])) === 0
            && $snapshotValid
            && ($quality['status'] ?? 'blocked') === 'ready';
        $checkStatus = $issues === []
            ? 'passed'
            : ($onlyPendingSnapshot
                ? 'in_progress'
                : ($onlyFullReplayCoverageIssue && ! $fullReplayBlocking
                    ? 'attention'
                    : ($active ? 'blocked' : 'attention')));
        $severity = $checkStatus === 'blocked'
            ? 'critical'
            : ($checkStatus === 'attention' ? 'warning' : 'info');

        return $this->check(
            'DATASET_FOUNDATION_AND_SNAPSHOT',
            $checkStatus,
            $issues === []
                ? 'Canonical price history and frozen generation snapshot are valid.'
                : ($onlyPendingSnapshot
                    ? 'Generation is created but not dispatched; its immutable price snapshot will be frozen at queue admission.'
                    : ($onlyFullReplayCoverageIssue && ! $fullReplayBlocking
                        ? 'Screening snapshot is valid; full replay remains blocked until required foundation coverage is repaired.'
                        : 'Market data or the frozen generation snapshot needs repair before promotion/full replay.')),
            [
                'generation_id' => $generation->id,
                'historical_quality' => $quality,
                'snapshot_protocol' => data_get($price, 'protocol'),
                'snapshot_path' => $path,
                'snapshot_declared_hash' => $declaredHash,
                'snapshot_actual_hash' => $actualHash,
                'snapshot_hash_valid' => $snapshotValid,
                'full_replay_coverage' => $full,
                'full_replay_blocking' => $fullReplayBlocking,
                'issues' => $issues,
                'promotion_evidence' => false,
            ],
            $severity,
        );
    }

    /** @return array<string, mixed> */
    private function regimeCheck(LabGeneration $generation, $agents): array
    {
        if (strtoupper((string) $generation->laboratory?->timeframe) !== 'M15') {
            return $this->check(
                'M15_CLOSED_H1_REGIME',
                'info',
                'Closed H1 regime snapshot is not required for an H1 laboratory.',
                ['required' => false, 'promotion_evidence' => false],
                'info',
            );
        }

        $context = (array) $generation->trigger_context;
        $regime = (array) data_get($context, 'canonical_dataset_snapshots.regime', []);
        $path = (string) data_get($regime, 'path', '');
        $declaredHash = (string) data_get($regime, 'sha256', data_get($regime, 'manifest.sha256', ''));
        $actualHash = $path !== '' && is_file($path) ? hash_file('sha256', $path) : null;
        $snapshotValid = data_get($regime, 'protocol') === 'lab_generation_regime_snapshot_v1'
            && $path !== '' && $declaredHash !== '' && is_string($actualHash) && hash_equals($declaredHash, $actualHash);
        $completedRuns = Schema::hasTable('lab_evaluation_runs')
            ? LabEvaluationRun::query()->where('lab_generation_id', $generation->id)->where('phase', 'screening')->where('status', 'completed')->get()
            : collect();
        $expectedHash = $declaredHash;
        $mismatches = [];
        foreach ($completedRuns as $run) {
            $observed = (string) data_get($run->request_meta, 'dataset_manifest.regime_snapshot_sha256', '');
            if ($observed !== $expectedHash) $mismatches[] = ['run_id' => $run->run_id, 'observed_hash' => $observed];
        }
        $active = in_array((string) $generation->status, self::ACTIVE_GENERATION_STATUSES, true);
        $issue = ! $snapshotValid || $mismatches !== [] || ($generation->status === 'screened' && $completedRuns->isEmpty());
        $inProgress = $snapshotValid && $completedRuns->isEmpty() && $active;

        return $this->check(
            'M15_CLOSED_H1_REGIME',
            $issue ? ($inProgress ? 'in_progress' : ($active ? 'blocked' : 'attention')) : 'passed',
            $issue
                ? ($inProgress
                    ? 'Frozen H1 regime is valid; M15 screen evidence has not arrived yet.'
                    : 'M15 closed-H1 regime snapshot or screen hash is missing/stale.')
                : 'Every completed M15 screen run references the frozen closed-H1 regime hash.',
            [
                'generation_id' => $generation->id,
                'snapshot_protocol' => data_get($regime, 'protocol'),
                'snapshot_path' => $path,
                'declared_hash' => $declaredHash,
                'actual_hash' => $actualHash,
                'snapshot_hash_valid' => $snapshotValid,
                'completed_screen_runs' => $completedRuns->count(),
                'mismatched_runs' => array_slice($mismatches, 0, 20),
                'closed_candle_cutoff' => data_get($regime, 'manifest.last_closed_candle_at'),
                'promotion_evidence' => false,
            ],
            $issue && ! $inProgress && $active ? 'critical' : ($issue && ! $inProgress ? 'warning' : 'info'),
        );
    }

    /** @return array<string, mixed> */
    private function volumeCheck(LabGeneration $generation, $agents, AiLaboratory $lab, bool $deep): array
    {
        $volumeAgents = $agents->filter(function (LabAgent $agent): bool {
            $metadata = (array) ($agent->modelVersion?->metadata ?? []);

            return data_get($metadata, 'volume_research_contract.protocol') === 'volume_council_v1'
                || (bool) data_get($metadata, 'volume_research_contract.enabled', false)
                || data_get($agent->modelVersion?->parameters, 'volume_lane', 'none') !== 'none';
        });
        if (! $deep) {
            return $this->check(
                'CANONICAL_VOLUME_CONTRACT',
                'info',
                'Deep volume inspection was skipped for this audit run.',
                ['required' => $volumeAgents->isNotEmpty(), 'volume_agent_count' => $volumeAgents->count(), 'promotion_evidence' => false],
                'info',
            );
        }

        try {
            $quality = $this->volumes->inspect($lab->symbol, $lab->timeframe);
        } catch (\Throwable $exception) {
            $quality = ['status' => 'volume_unavailable', 'reasons' => ['VOLUME_AUDIT_EXCEPTION:'.$exception::class]];
        }
        $passed = ($quality['status'] ?? '') === 'passed';
        $required = $volumeAgents->isNotEmpty() || strtoupper((string) $lab->timeframe) === 'M15';

        $status = $passed
            ? 'passed'
            : ($required ? 'blocked' : 'info');

        return $this->check(
            'CANONICAL_VOLUME_CONTRACT',
            $status,
            $passed
                ? ($volumeAgents->isEmpty()
                    ? 'Canonical volume is fresh and ready; current cohort remains an explicit no-volume baseline lane.'
                    : 'Canonical volume is fresh and valid for the volume specialist lane.')
                : ($required
                    ? 'Canonical volume is not ready for a volume-dependent replay.'
                    : 'Canonical volume is not required by the current H1 baseline cohort.'),
            [
                'required_for_current_cohort' => $required,
                'volume_agent_count' => $volumeAgents->count(),
                'quality' => $quality,
                'volume_research_lane' => $volumeAgents->isEmpty() ? 'shadow_only' : 'active',
                'promotion_evidence' => false,
            ],
            ! $passed && $required ? 'critical' : 'info',
        );
    }

    /** @return array<string, mixed> */
    private function gateCheck(LabGeneration $generation, $agents): array
    {
        $advancedStatuses = ['challenger', 'forward_validated', 'paper', 'promoted', 'champion', 'active', 'elite'];
        $advanced = $agents->whereIn('lifecycle_status', $advancedStatuses);
        $gateByAgent = collect();
        if (Schema::hasTable('candidate_gate_decisions') && $agents->isNotEmpty()) {
            $gateByAgent = DB::table('candidate_gate_decisions')
                ->whereIn('lab_agent_id', $agents->pluck('id'))
                ->get(['lab_agent_id', 'stage', 'decision'])
                ->groupBy('lab_agent_id');
        }
        $missingGate = $advanced->filter(fn (LabAgent $agent): bool => ! $gateByAgent->has($agent->id))->pluck('id')->values()->all();
        $claims = [];
        foreach ($agents as $agent) {
            $metadata = (array) ($agent->modelVersion?->metadata ?? []);
            foreach ([
                'promotion_evidence',
                'elite_agent_passport.promotion_evidence',
                'last_result.promotion_evidence',
                'last_screen_result.promotion_evidence',
            ] as $path) {
                if (data_get($metadata, $path) === true) {
                    $claims[] = $agent->id.':'.$path;
                }
            }
        }
        $issue = $missingGate !== [] || $claims !== [];
        $active = in_array((string) $generation->status, self::ACTIVE_GENERATION_STATUSES, true);

        return $this->check(
            'GATE_AND_PROMOTION_FAIL_CLOSED',
            $issue ? ($active ? 'blocked' : 'attention') : 'passed',
            $issue
                ? 'An advanced agent or promotion claim lacks a corresponding auditable gate contract.'
                : 'No agent bypassed the candidate gate; diagnostic screen results remain non-promotion evidence.',
            [
                'generation_id' => $generation->id,
                'advanced_agent_count' => $advanced->count(),
                'gate_decision_count' => $gateByAgent->flatten(1)->count(),
                'advanced_without_gate_ids' => array_slice($missingGate, 0, 20),
                'promotion_claims' => array_slice($claims, 0, 20),
                'promotion_evidence' => false,
            ],
            $issue && $active ? 'critical' : ($issue ? 'warning' : 'info'),
        );
    }

    /** @return array<string, mixed> */
    private function checkpointCheck(LabGeneration $generation, $agents): array
    {
        $context = (array) $generation->trigger_context;
        $groupContract = (array) data_get($context, 'population_group_contract', []);
        $council = (array) data_get($context, 'specialist_council_contract', []);
        $missingMembership = $agents->filter(function (LabAgent $agent): bool {
            return data_get($agent->modelVersion?->metadata, 'specialist_council_membership.protocol') !== 'specialist_council_v1';
        })->pluck('id')->values()->all();
        $globalChampionClaims = $agents->filter(fn (LabAgent $agent): bool => data_get($agent->modelVersion?->metadata, 'specialist_council_membership.global_champion') === true)->pluck('id')->values()->all();
        $normalPopulation = (int) $generation->population_size >= 20 && $generation->trigger_type !== 'volume_context_council';
        $issues = [];
        if ($normalPopulation && data_get($groupContract, 'protocol') !== 'population_group_checkpoint_v1') $issues[] = 'POPULATION_GROUP_CHECKPOINT_MISSING';
        if ($normalPopulation && data_get($council, 'protocol') !== 'specialist_council_v1') $issues[] = 'SPECIALIST_COUNCIL_CONTRACT_MISSING';
        if ($normalPopulation && $missingMembership !== []) $issues[] = 'SPECIALIST_MEMBERSHIP_MISSING';
        if ($globalChampionClaims !== []) $issues[] = 'GLOBAL_CHAMPION_FORBIDDEN';
        $active = in_array((string) $generation->status, self::ACTIVE_GENERATION_STATUSES, true);

        return $this->check(
            'COUNCIL_CHECKPOINT_INHERITANCE',
            $issues === [] ? 'passed' : ($active ? 'blocked' : 'attention'),
            $issues === []
                ? 'Council groups, complementary specialist seats and checkpoint rules are declared.'
                : 'Council/checkpoint declaration is incomplete or a singleton champion claim appeared.',
            [
                'generation_id' => $generation->id,
                'population_group_protocol' => data_get($groupContract, 'protocol'),
                'specialist_council_protocol' => data_get($council, 'protocol'),
                'group_checkpoint_input_count' => count((array) data_get($context, 'group_checkpoint_inputs', [])),
                'missing_membership_ids' => array_slice($missingMembership, 0, 20),
                'global_champion_claim_ids' => array_slice($globalChampionClaims, 0, 20),
                'issues' => $issues,
                'promotion_evidence' => false,
            ],
            $issues !== [] && $active ? 'critical' : ($issues !== [] ? 'warning' : 'info'),
        );
    }

    /**
     * Audit the lifecycle boundary after full replay.  Screening results are
     * intentionally not treated as forward evidence: a model must have a
     * same-scope performance row, a statistical forward decision, a sealed
     * passport and (when applicable) paper evidence before it can appear in
     * an elite portfolio.  This method is read-only and never creates a gate,
     * portfolio, paper row or knowledge-card update.
     *
     * @return array<string, mixed>
     */
    private function forwardEliteCheck(LabGeneration $generation, $agents, AiLaboratory $lab): array
    {
        $requiredTables = [
            'model_market_performance',
            'candidate_gate_decisions',
            'elite_agent_portfolios',
            'elite_agent_portfolio_members',
            'paper_trading_evaluations',
        ];
        $missingTables = array_values(array_filter(
            $requiredTables,
            static fn (string $table): bool => ! Schema::hasTable($table),
        ));
        if ($missingTables !== []) {
            return $this->check(
                'FORWARD_ELITE_LIFECYCLE',
                'blocked',
                'Forward/elite lifecycle cannot be verified because required evidence tables are missing.',
                [
                    'generation_id' => $generation->id,
                    'missing_tables' => $missingTables,
                    'next_stage' => 'repair_evidence_schema',
                    'promotion_evidence' => false,
                ],
                'critical',
            );
        }

        $modelVersionIds = $agents->pluck('model_version_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values();
        $performances = $modelVersionIds->isEmpty()
            ? collect()
            : ModelMarketPerformance::query()
                ->with('modelVersion')
                ->whereIn('model_version_id', $modelVersionIds->all())
                ->where('symbol', $lab->symbol)
                ->where('timeframe', $lab->timeframe)
                ->get();
        $performanceByModel = $performances->keyBy(fn (ModelMarketPerformance $performance): int => (int) $performance->model_version_id);
        $performanceIds = $performances->pluck('id')->map(fn ($id): int => (int) $id)->values();
        $agentIds = $agents->pluck('id')->map(fn ($id): int => (int) $id)->values();

        $forwardDecisions = ($performanceIds->isEmpty() && $agentIds->isEmpty())
            ? collect()
            : CandidateGateDecision::query()
                ->where('stage', 'statistical_forward_gate')
                ->where(function ($query) use ($performanceIds, $agentIds): void {
                    if ($performanceIds->isNotEmpty()) {
                        $query->whereIn('model_market_performance_id', $performanceIds->all());
                        if ($agentIds->isNotEmpty()) $query->orWhereIn('lab_agent_id', $agentIds->all());
                    } elseif ($agentIds->isNotEmpty()) {
                        $query->whereIn('lab_agent_id', $agentIds->all());
                    }
                })
                ->orderByDesc('evaluated_at')
                ->get();
        $forwardByPerformance = $forwardDecisions
            ->filter(fn (CandidateGateDecision $decision): bool => $decision->model_market_performance_id !== null)
            ->groupBy('model_market_performance_id')
            ->map(fn ($rows) => $rows->sortByDesc('evaluated_at')->first());

        $forwardStatuses = ['forward_validated', 'paper', 'promoted', 'champion', 'active', 'elite'];
        $paperStatuses = ['paper', 'promoted', 'champion', 'active', 'elite'];
        $fullReplayStatuses = ['challenger', 'forward_validated', 'paper', 'promoted', 'champion', 'active', 'elite', 'rejected', 'overfit', 'stagnated'];
        $forwardCandidates = $performances->whereIn('status', $forwardStatuses)->values();
        $fullReplayCandidates = $performances->whereIn('status', $fullReplayStatuses)->values();
        $advancedAgentStatuses = ['challenger', 'forward_validated', 'paper', 'promoted', 'champion', 'active', 'elite'];
        $advancedAgents = $agents->whereIn('lifecycle_status', $advancedAgentStatuses);

        $missingPerformanceAgentIds = $advancedAgents
            ->filter(fn (LabAgent $agent): bool => ! $performanceByModel->has((int) $agent->model_version_id))
            ->pluck('id')->values()->all();
        $missingForwardGatePerformanceIds = [];
        $forwardGateMismatchPerformanceIds = [];
        $missingForwardPassportPerformanceIds = [];
        $invalidForwardEvidencePerformanceIds = [];
        $missingPaperEvidencePerformanceIds = [];
        $forwardGatePassedCount = 0;
        $paperReadyCount = 0;
        $paperPassedCount = 0;

        $paperEvaluations = $performances->isEmpty()
            ? collect()
            : DB::table('paper_trading_evaluations')
                ->whereIn('model_market_performance_id', $performanceIds->all())
                ->orderByDesc('updated_at')
                ->get()
                ->groupBy('model_market_performance_id')
                ->map(fn ($rows) => $rows->first());

        foreach ($forwardCandidates as $performance) {
            /** @var CandidateGateDecision|null $decision */
            $decision = $forwardByPerformance->get((int) $performance->id);
            if (! $decision) {
                $missingForwardGatePerformanceIds[] = (int) $performance->id;
                continue;
            }
            if ($decision->decision !== 'passed') {
                $forwardGateMismatchPerformanceIds[] = (int) $performance->id;
                continue;
            }
            $forwardGatePassedCount++;
            if ($performance->evidence_status !== 'valid'
                || $performance->modelVersion?->evidence_status !== 'valid') {
                $invalidForwardEvidencePerformanceIds[] = (int) $performance->id;
            }
            if (data_get($decision->metrics, 'elite_agent_passport.status') !== 'passed') {
                $missingForwardPassportPerformanceIds[] = (int) $performance->id;
            }
            if (in_array((string) $performance->status, $paperStatuses, true)) {
                $paperReadyCount++;
                $paper = $paperEvaluations->get((int) $performance->id);
                if ((string) $performance->paper_status === 'passed' || (string) data_get($paper, 'status') === 'passed') {
                    $paperPassedCount++;
                } elseif (! $paper) {
                    $missingPaperEvidencePerformanceIds[] = (int) $performance->id;
                }
            }
        }

        $portfolios = EliteAgentPortfolio::query()
            ->with('members.performance.modelVersion')
            ->where('symbol', $lab->symbol)
            ->where('timeframe', $lab->timeframe)
            ->get();
        $elitePortfolioPassedIds = [];
        $portfolioContractIssues = [];
        $portfolioGateFailures = [];
        $eliteKnowledgeMissing = [];

        foreach ($portfolios as $portfolio) {
            $isPassed = $portfolio->gate_status === 'passed'
                || data_get($portfolio->evidence, 'gate.status') === 'passed';
            if (! $isPassed) {
                if (in_array((string) $portfolio->status, ['blocked', 'failed'], true)
                    || in_array((string) $portfolio->gate_status, ['blocked', 'failed'], true)
                    || data_get($portfolio->evidence, 'gate.status') === 'failed') {
                    $portfolioGateFailures[(int) $portfolio->id] = [
                        'status' => $portfolio->status,
                        'gate_status' => $portfolio->gate_status,
                        'reasons' => (array) ($portfolio->gate_reasons ?? data_get($portfolio->evidence, 'gate.reason_codes', [])),
                    ];
                }
                continue;
            }

            $issues = [];
            if ($portfolio->gate_status !== 'passed' || data_get($portfolio->evidence, 'gate.status') !== 'passed') {
                $issues[] = 'ELITE_PORTFOLIO_GATE_PROJECTION_MISMATCH';
            }
            if ($portfolio->members->count() < 2 || (int) $portfolio->member_count !== $portfolio->members->count()) {
                $issues[] = 'ELITE_PORTFOLIO_MEMBER_COUNT_MISMATCH';
            }

            $proxyId = (int) data_get($portfolio->evidence, 'portfolio_performance_id', 0);
            $proxy = $proxyId > 0 ? ModelMarketPerformance::with('modelVersion')->find($proxyId) : null;
            if (! $proxy
                || ! (bool) data_get($proxy->metrics, 'portfolio_proxy', false)
                || (int) data_get($proxy->metrics, 'elite_portfolio_id', 0) !== (int) $portfolio->id
                || $proxy->evidence_status !== 'valid'
                || $proxy->modelVersion?->evidence_status !== 'valid') {
                $issues[] = 'ELITE_PORTFOLIO_PROXY_INVALID';
            } else {
                $proxyDecision = CandidateGateDecision::query()
                    ->where('model_market_performance_id', $proxy->id)
                    ->where('stage', 'statistical_forward_gate')
                    ->latest('evaluated_at')
                    ->first();
                if ($proxyDecision?->decision !== 'passed'
                    || data_get($proxyDecision->metrics, 'portfolio_forward_identity.attribution_status') !== 'portfolio_sealed') {
                    $issues[] = 'ELITE_PORTFOLIO_FORWARD_LEDGER_INVALID';
                }
            }

            foreach ($portfolio->members as $member) {
                $performance = $member->performance;
                if (! $performance
                    || ! in_array((string) $performance->status, ['forward_validated', 'paper'], true)
                    || $performance->evidence_status !== 'valid'
                    || $performance->modelVersion?->evidence_status !== 'valid') {
                    $issues[] = 'ELITE_MEMBER_EVIDENCE_INVALID';
                    continue;
                }
                $decision = CandidateGateDecision::query()
                    ->where('model_market_performance_id', $performance->id)
                    ->where('stage', 'statistical_forward_gate')
                    ->latest('evaluated_at')
                    ->first();
                if ($decision?->decision !== 'passed'
                    || data_get($decision->metrics, 'elite_agent_passport.status') !== 'passed') {
                    $issues[] = 'ELITE_MEMBER_PASSPORT_INVALID';
                }
                $memberAgent = LabAgent::query()->where('model_version_id', $performance->model_version_id)->latest('id')->first();
                $card = $memberAgent
                    ? AgentKnowledgeCard::query()->where('lab_agent_id', $memberAgent->id)->first()
                    : null;
                if (! $card || $card->skill_stage !== 'elite_council_member') {
                    $eliteKnowledgeMissing[] = [
                        'portfolio_id' => $portfolio->id,
                        'performance_id' => $performance->id,
                        'lab_agent_id' => $memberAgent?->id,
                    ];
                }
            }

            $issues = array_values(array_unique($issues));
            if ($issues === []) {
                $elitePortfolioPassedIds[] = (int) $portfolio->id;
            } else {
                $portfolioContractIssues[(int) $portfolio->id] = $issues;
            }
        }

        $issues = [];
        if ($missingPerformanceAgentIds !== []) $issues[] = 'ADVANCED_AGENT_PERFORMANCE_MISSING';
        if ($missingForwardGatePerformanceIds !== []) $issues[] = 'FORWARD_GATE_MISSING';
        if ($forwardGateMismatchPerformanceIds !== []) $issues[] = 'FORWARD_GATE_STATUS_MISMATCH';
        if ($missingForwardPassportPerformanceIds !== []) $issues[] = 'FORWARD_PASSPORT_MISSING';
        if ($invalidForwardEvidencePerformanceIds !== []) $issues[] = 'FORWARD_EVIDENCE_INVALID';
        if ($missingPaperEvidencePerformanceIds !== []) $issues[] = 'PAPER_EVIDENCE_MISSING';
        if ($portfolioGateFailures !== []) $issues[] = 'ELITE_PORTFOLIO_GATE_FAILED';
        if ($portfolioContractIssues !== []) $issues[] = 'ELITE_PORTFOLIO_CONTRACT_INVALID';
        if ($eliteKnowledgeMissing !== []) $issues[] = 'ELITE_KNOWLEDGE_CHECKPOINT_MISSING';

        $activeGeneration = in_array((string) $generation->status, self::ACTIVE_GENERATION_STATUSES, true);
        $hasOpenAgents = $agents->whereIn('lifecycle_status', self::OPEN_AGENT_STATUSES)->isNotEmpty();
        $hasForwardCandidate = $forwardCandidates->isNotEmpty();
        $hasPassedForward = $forwardGatePassedCount > 0;
        $hasPortfolio = $portfolios->isNotEmpty();
        $nextStage = match (true) {
            $issues !== [] => 'repair_or_replay_failed_boundary',
            $elitePortfolioPassedIds !== [] => 'elite_observation_and_checkpoint_retention',
            $hasPassedForward && $paperReadyCount > $paperPassedCount => 'paper_evidence',
            $hasPassedForward && ! $hasPortfolio => 'council_member_complement_and_elite_replay',
            $hasForwardCandidate && ! $hasPassedForward => 'forward_gate_review',
            $fullReplayCandidates->isNotEmpty() && ! $hasForwardCandidate => 'forward_gate_pending_or_failed',
            $activeGeneration || $hasOpenAgents => 'complete_screening_then_full_validation',
            default => 'await_next_generation_or_targeted_handoff',
        };

        $status = $issues !== []
            ? ($activeGeneration ? 'blocked' : 'attention')
            : (($activeGeneration || $fullReplayCandidates->isNotEmpty() || $hasPortfolio) ? 'in_progress' : 'passed');
        $severity = $issues !== [] ? ($activeGeneration ? 'critical' : 'warning') : 'info';

        return $this->check(
            'FORWARD_ELITE_LIFECYCLE',
            $status,
            $issues !== []
                ? 'Forward/elite lifecycle has a contract or evidence mismatch; no promotion status was changed.'
                : ($elitePortfolioPassedIds !== []
                    ? 'Forward and elite portfolio contracts are currently aligned; continued paper/holdout observation is required.'
                    : 'Forward and elite lifecycle is being monitored; no elite promotion was manufactured.'),
            [
                'generation_id' => $generation->id,
                'next_stage' => $nextStage,
                'performance_status_counts' => $performances->groupBy('status')->map->count()->all(),
                'full_replay_performance_count' => $fullReplayCandidates->count(),
                'forward_candidate_count' => $forwardCandidates->count(),
                'forward_gate_passed_count' => $forwardGatePassedCount,
                'forward_gate_missing_performance_ids' => array_values(array_unique($missingForwardGatePerformanceIds)),
                'forward_gate_mismatch_performance_ids' => array_values(array_unique($forwardGateMismatchPerformanceIds)),
                'forward_passport_missing_performance_ids' => array_values(array_unique($missingForwardPassportPerformanceIds)),
                'forward_evidence_invalid_performance_ids' => array_values(array_unique($invalidForwardEvidencePerformanceIds)),
                'paper_ready_count' => $paperReadyCount,
                'paper_passed_count' => $paperPassedCount,
                'paper_evidence_missing_performance_ids' => array_values(array_unique($missingPaperEvidencePerformanceIds)),
                'elite_portfolio_count' => $portfolios->count(),
                'elite_portfolio_passed_count' => count($elitePortfolioPassedIds),
                'elite_portfolio_passed_ids' => $elitePortfolioPassedIds,
                'elite_portfolio_gate_failures' => $portfolioGateFailures,
                'elite_portfolio_contract_issues' => $portfolioContractIssues,
                'elite_knowledge_missing' => $eliteKnowledgeMissing,
                'advanced_agent_missing_performance_ids' => array_values(array_unique($missingPerformanceAgentIds)),
                'issues' => $issues,
                'promotion_evidence' => false,
            ],
            $severity,
        );
    }

    /** @return array<string, mixed> */
    private function queueHealth(): array
    {
        if (! Schema::hasTable('jobs')) {
            return [
                'agent_ids' => [],
                'metrics' => ['available' => false, 'promotion_evidence' => false],
                'check' => $this->check('QUEUE_HEALTH', 'blocked', 'Queue table is unavailable; queued lifecycle cannot be verified.', [], 'critical'),
            ];
        }

        $queues = array_values(array_unique(array_merge(
            [(string) config('services.lab_queue.screening_queue', 'lab-screening')],
            [(string) config('services.lab_queue.frontier_queue', 'lab-frontier')],
            (array) config('services.lab_queue.legacy_screening_queues', []),
            ['lab-full-validation'],
        )));
        $rows = DB::table('jobs')->whereIn('queue', $queues)->orderBy('id')->limit(5000)->get(['id', 'queue', 'attempts', 'reserved_at', 'created_at', 'payload']);
        $now = now()->timestamp;
        $pending = $rows->whereNull('reserved_at');
        $reserved = $rows->whereNotNull('reserved_at');
        $oldestCreated = $rows->map(fn ($row): int => $this->epoch($row->created_at))->filter(fn (int $time): bool => $time > 0)->min();
        $oldestReserved = $reserved->map(fn ($row): int => $this->epoch($row->reserved_at))->filter(fn (int $time): bool => $time > 0)->min();
        $backlogAge = $oldestCreated ? max(0, $now - $oldestCreated) : 0;
        $reservedAge = $oldestReserved ? max(0, $now - $oldestReserved) : 0;
        $maxAttempts = (int) ($rows->max('attempts') ?? 0);
        $highAttemptJobs = $rows->filter(fn ($row): bool => (int) $row->attempts >= 3)->count();
        $staleReserved = $reserved->filter(fn ($row): bool => $this->epoch($row->reserved_at) > 0 && ($now - $this->epoch($row->reserved_at)) > 1800)->count();
        $recentRetryReleases = Schema::hasTable('lab_evaluation_runs')
            ? LabEvaluationRun::query()
                ->where('status', 'retry_released')
                ->where('created_at', '>=', now()->subMinutes(15))
                ->count()
            : 0;
        $agentIds = [];
        foreach ($rows as $row) {
            $agentId = $this->jobAgentId((string) $row->payload);
            if ($agentId !== null) $agentIds[] = $agentId;
        }
        $agentIds = array_values(array_unique($agentIds));
        // A high attempt count can be historical backlog left by an earlier
        // worker topology. Treat it separately from a live storm so the
        // monitor does not keep calling an old attempt number "active
        // contention" after the release burst has stopped.
        $retryStorm = $staleReserved > 0 || $recentRetryReleases >= 5;
        $issues = [];
        if ($backlogAge > 7200) $issues[] = 'QUEUE_BACKLOG_OLDER_THAN_TWO_HOURS';
        elseif ($backlogAge > 1800) $issues[] = 'QUEUE_BACKLOG_OLDER_THAN_THIRTY_MINUTES';
        if ($retryStorm) $issues[] = 'QUEUE_RETRY_OR_STALE_RESERVATION';
        elseif ($highAttemptJobs > 0) $issues[] = 'QUEUE_HIGH_ATTEMPT_BACKLOG';
        $status = $issues === [] ? 'passed' : (in_array('QUEUE_BACKLOG_OLDER_THAN_TWO_HOURS', $issues, true) ? 'blocked' : 'attention');

        return [
            'agent_ids' => $agentIds,
            'metrics' => [
                'available' => true,
                'queues' => $queues,
                'total' => $rows->count(),
                'pending' => $pending->count(),
                'reserved' => $reserved->count(),
                'oldest_job_age_seconds' => $backlogAge,
                'oldest_reserved_age_seconds' => $reservedAge,
                'stale_reserved_count' => $staleReserved,
                'max_attempts' => $maxAttempts,
                'high_attempt_jobs' => $highAttemptJobs,
                'recent_retry_releases_15m' => $recentRetryReleases,
                'retry_storm' => $retryStorm,
                'promotion_evidence' => false,
            ],
            'check' => $this->check(
                'QUEUE_HEALTH',
                $status,
                $issues === []
                    ? 'Canonical queue is available without a retry storm.'
                    : 'Queue throughput or retry hygiene needs attention; no job was modified by this audit.',
                ['issues' => $issues, 'promotion_evidence' => false],
                $status === 'blocked' ? 'critical' : ($status === 'attention' ? 'warning' : 'info'),
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function replayHealth(): array
    {
        $url = rtrim((string) config('services.ai_service.url'), '/').'/api/replay-status';
        $token = (string) config('services.internal_api.token');
        if ($url === '/api/replay-status' || $token === '') {
            return [
                'metrics' => ['available' => false, 'promotion_evidence' => false],
                'check' => $this->check('REPLAY_LANE_HEALTH', 'attention', 'AI replay status endpoint is not configured; lane health is unknown.', [], 'warning'),
            ];
        }
        try {
            $response = Http::connectTimeout(2)->timeout(4)->withHeaders(['X-Internal-Token' => $token])->get($url);
            $body = (array) $response->json();
            $active = (int) data_get($body, 'active_requests', -1);
            $protocol = (string) data_get($body, 'protocol', '');
            $healthy = $response->successful() && $active >= 0 && $protocol !== '';
            $metrics = [
                'available' => $healthy,
                'active_requests' => $active,
                'protocol' => $protocol,
                'last_termination' => data_get($body, 'last_termination'),
                'promotion_evidence' => false,
            ];

            return [
                'metrics' => $metrics,
                'check' => $this->check(
                    'REPLAY_LANE_HEALTH',
                    $healthy ? 'passed' : 'blocked',
                    $healthy ? 'AI replay lane responds with a bounded worker protocol.' : 'AI replay lane health could not be verified.',
                    $metrics,
                    $healthy ? 'info' : 'critical',
                ),
            ];
        } catch (\Throwable $exception) {
            return [
                'metrics' => ['available' => false, 'exception' => $exception::class, 'promotion_evidence' => false],
                'check' => $this->check('REPLAY_LANE_HEALTH', 'blocked', 'AI replay status request failed; no promotion decision is allowed.', ['exception' => $exception::class], 'critical'),
            ];
        }
    }

    /** @return array<int, string> */
    private function strengths(array $scopes, array $queue, array $replay): array
    {
        $strengths = [];
        if ($scopes !== [] && collect($scopes)->every(fn (array $scope): bool => data_get($scope, 'status') !== 'blocked')) {
            $strengths[] = 'All selected laboratories have a monitorable latest generation; no hidden reset or automatic status rewrite was used.';
        }
        if ((int) ($queue['max_attempts'] ?? 0) < 3 && ! (bool) ($queue['retry_storm'] ?? false)) {
            $strengths[] = 'Queue has no current retry storm or stale reservation signal.';
        }
        if (($replay['available'] ?? false) === true) {
            $strengths[] = 'AI replay lane is reachable and reports its bounded worker protocol.';
        }
        if (collect($scopes)->contains(fn (array $scope): bool => data_get($this->scopeCheck($scope, 'M15_CLOSED_H1_REGIME'), 'status') === 'passed')) {
            $strengths[] = 'At least one M15 scope proves closed-H1 regime hash alignment.';
        }
        if (collect($scopes)->contains(fn (array $scope): bool => data_get($this->scopeCheck($scope, 'CANONICAL_VOLUME_CONTRACT'), 'status') === 'passed')) {
            $strengths[] = 'Canonical volume contract is available for audited M15 research.';
        }
        if ($strengths === []) $strengths[] = 'Audit is fail-closed and is preserving diagnostic evidence while issues are investigated.';

        return $strengths;
    }

    /** @return array<int, string> */
    private function recommendations(array $scopes, array $queue, array $replay): array
    {
        $recommendations = [];
        if ((int) ($queue['oldest_job_age_seconds'] ?? 0) > 1800) {
            $recommendations[] = 'Serialized replay lane is the current throughput bottleneck; keep one shared mutex and reduce payload/replay cost before considering a second worker.';
        }
        if (collect($scopes)->contains(fn (array $scope): bool => data_get($scope, 'generation_status') === 'screening')) {
            $recommendations[] = 'Let active screening finish, then run full validation only from completed immutable screen evidence; do not mark screened agents promoted manually.';
        }
        if (collect($scopes)->contains(function (array $scope): bool {
            $check = $this->scopeCheck($scope, 'DATASET_FOUNDATION_AND_SNAPSHOT');

            return in_array('FULL_REPLAY_COVERAGE_NOT_READY', (array) data_get($check, 'metrics.issues', []), true);
        })) {
            $recommendations[] = 'Repair the missing long-history foundation coverage before any full validation; current screening remains diagnostic only.';
        }
        if (collect($scopes)->contains(fn (array $scope): bool => data_get($this->scopeCheck($scope, 'CANONICAL_VOLUME_CONTRACT'), 'metrics.volume_research_lane') === 'shadow_only')) {
            $recommendations[] = 'Use the existing no-volume control plus bounded volume council after a screened parent has a passed shadow experiment; volume remains a measured specialist, not an unverified global feature.';
        }
        if (collect($scopes)->contains(fn (array $scope): bool => data_get($scope, 'generation_status') === 'screened')) {
            $recommendations[] = 'Treat “no eligible full candidate” as an evidence result and use targeted handoff/checkpoint continuation, not a global champion shortcut.';
        }
        if (! ($replay['available'] ?? false)) {
            $recommendations[] = 'Restore replay-status observability before allowing any full-validation or promotion action.';
        }

        return array_values(array_unique($recommendations));
    }

    /** @param array<string, mixed> $report */
    private function persist(array $report): void
    {
        if (! Schema::hasTable('system_logs')) return;

        $summary = (array) ($report['summary'] ?? []);
        $level = match ((string) ($summary['status'] ?? 'attention')) {
            'blocked' => 'error',
            'attention' => 'warning',
            default => 'info',
        };
        $scopes = collect((array) ($report['laboratories'] ?? []))->map(fn (array $scope): array => [
            'symbol' => $scope['symbol'] ?? null,
            'timeframe' => $scope['timeframe'] ?? null,
            'generation_id' => $scope['generation_id'] ?? null,
            'generation' => $scope['generation'] ?? null,
            'status' => $scope['status'] ?? null,
            'agent_count' => $scope['agent_count'] ?? 0,
            'metrics' => $scope['metrics'] ?? [],
            'findings' => collect((array) ($scope['checks'] ?? []))
                ->filter(fn (array $check): bool => ! in_array($check['status'], ['passed', 'in_progress', 'info'], true))
                ->map(fn (array $check): array => ['code' => $check['code'], 'status' => $check['status'], 'severity' => $check['severity']])
                ->values()->all(),
        ])->values()->all();

        $this->logs->write(
            'AGENT_LIFECYCLE_AUDIT',
            'Agent lifecycle audit completed; evidence and promotion gates were not changed.',
            [
                'protocol' => self::PROTOCOL,
                'summary' => $summary,
                'queue' => $report['queue'] ?? [],
                'replay' => $report['replay'] ?? [],
                'laboratories' => $scopes,
                'strengths' => $report['strengths'] ?? [],
                'recommendations' => $report['recommendations'] ?? [],
                'promotion_evidence' => false,
            ],
            $level,
            'agent_lifecycle_audit',
            'audit',
            (string) ($summary['status'] ?? 'attention'),
        );
    }

    /** @return array<string, mixed>|null */
    private function scopeCheck(array $scope, string $code): ?array
    {
        $check = collect((array) ($scope['checks'] ?? []))->firstWhere('code', $code);

        return is_array($check) ? $check : null;
    }

    /** @param array<string, mixed> $metrics */
    private function check(string $code, string $status, string $message, array $metrics = [], string $severity = 'info'): array
    {
        return [
            'code' => $code,
            'status' => $status,
            'severity' => $severity,
            'message' => $message,
            'metrics' => [...$metrics, 'promotion_evidence' => false],
            'promotion_evidence' => false,
        ];
    }

    private function agentGroup(LabAgent $agent): string
    {
        $metadata = (array) ($agent->modelVersion?->metadata ?? []);

        return (string) (
            data_get($metadata, 'population_group.key')
            ?: data_get($metadata, 'specialist_council_membership.group_key')
            ?: data_get($metadata, 'generation_target')
            ?: 'unassigned'
        );
    }

    private function isControlOnly(LabAgent $agent): bool
    {
        $metadata = (array) ($agent->modelVersion?->metadata ?? []);

        return (bool) data_get($metadata, 'mutation_constructor_invariant.control_only', false)
            || (bool) data_get($metadata, 'g98_council_lane.control_only', false)
            || data_get($metadata, 'role_complete_council.role_control.type') === 'no_change_control';
    }

    private function isZeroDiff(array $diff): bool
    {
        if ($diff === []) return true;

        return collect($diff)->every(function ($change): bool {
            if (! is_array($change) || ! array_key_exists('old', $change) || ! array_key_exists('new', $change)) return false;

            return json_encode($change['old'], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)
                === json_encode($change['new'], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES);
        });
    }

    private function epoch(mixed $value): int
    {
        if (is_numeric($value)) return (int) $value;
        if ($value === null || $value === '') return 0;
        try {
            return CarbonImmutable::parse((string) $value, 'UTC')->timestamp;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function jobAgentId(string $payload): ?int
    {
        $decoded = json_decode($payload, true);
        $serialized = (string) data_get(is_array($decoded) ? $decoded : [], 'data.command', '');
        if (preg_match('/s:\d+:"labAgentId";i:(\d+)/', $serialized, $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }
}
