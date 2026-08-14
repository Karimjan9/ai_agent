<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\SystemEvent;

/**
 * Converts terminal screening observations into a bounded research study.
 *
 * This is a diagnostic plane only. It separates distinct failed agents from
 * repeated gate hits, excludes technical/legacy evidence, and records the
 * single semantic cell that a future rescue seat may touch. It never creates
 * mutation credit, parents, promotion evidence, or a new generation.
 */
class LabFailureStudyService
{
    public const PROTOCOL = 'lab_failure_study_v1';

    /** @return array<string, mixed> */
    public function study(?string $symbol = null, ?string $timeframe = null, bool $persist = false): array
    {
        $reports = [];
        $labs = AiLaboratory::query()
            ->where('is_active', true)
            ->when($symbol, fn ($query) => $query->where('symbol', strtoupper($symbol)))
            ->when($timeframe, fn ($query) => $query->where('timeframe', strtoupper($timeframe)))
            ->orderBy('symbol')->orderBy('timeframe')->get();

        foreach ($labs as $lab) {
            $reports[] = $this->studyLab($lab, $persist);
        }

        return [
            'protocol' => self::PROTOCOL,
            'generated_at' => now()->utc()->toIso8601String(),
            'reports' => $reports,
            'promotion_evidence' => false,
            'causal_prior_allowed' => false,
            'rule' => 'Failure study routes bounded research only; it cannot relax a gate or create a parent/credit.',
        ];
    }

    /** @return array<string, mixed> */
    private function studyLab(AiLaboratory $lab, bool $persist): array
    {
        // A terminal cohort can be marked technical_quarantine when one or
        // more seats remain unrecoverable. That must not make the study fall
        // back to the previous generation: current clean failed decisions
        // are still valid diagnostic evidence, while quarantined seats are
        // excluded below. Only active/incomplete generations are ineligible.
        $generation = $lab->generations()
            ->whereIn('status', ['screened', 'technical_quarantine', 'completed', 'failed', 'abandoned'])
            ->latest('generation')->first();
        if (! $generation) {
            return [
                'symbol' => $lab->symbol,
                'timeframe' => $lab->timeframe,
                'status' => 'no_terminal_screened_generation',
                'source_generation_id' => null,
                'source_generation' => null,
                'distinct_actionable_failed_agents' => 0,
                'gate_hit_count' => 0,
                'screening_pass_agents' => [],
                'technical_excluded_agent_ids' => [],
                'legacy_excluded_agent_ids' => [],
                'evidence_complete_insufficient_agent_ids' => [],
                'failure_groups' => [],
                'target_cells' => [],
                'problem_study' => $this->problemStudy(0, 0, 1, 0),
                'promotion_evidence' => false,
                'causal_prior_allowed' => false,
            ];
        }

        $generation->loadMissing('agents');
        $agentIds = $generation->agents->pluck('id')->values()->all();
        $decisions = CandidateGateDecision::query()
            ->whereIn('lab_agent_id', $agentIds)
            ->where('stage', 'screening')
            ->orderByDesc('id')->get()
            ->groupBy('lab_agent_id')
            ->map(fn ($rows) => $rows->first());
        $evidence = app(LabImmutableEvidenceService::class);
        $failureGroups = [];
        $targetCells = [];
        $actionableAgentIds = [];
        $technicalExcluded = [];
        $legacyExcluded = [];
        $evidenceCompleteInsufficient = [];
        $passAgents = [];

        foreach ($generation->agents as $agent) {
            $decision = $decisions->get($agent->id);
            if (! $decision) {
                $technicalExcluded[] = (int) $agent->id;
                continue;
            }
            $run = LabEvaluationRun::query()
                ->where('lab_agent_id', $agent->id)
                ->where('phase', 'screening')->latest('id')->first();
            $legacy = $run !== null
                && ((string) $run->status === 'legacy_snapshot'
                    || (bool) data_get($run->metadata, 'historical', false));
            if ($legacy) {
                $legacyExcluded[] = (int) $agent->id;
                continue;
            }
            $eligible = $run ? $evidence->learningEligibility($run) : ['complete' => false];
            $reasons = array_values(array_unique(array_filter(array_map(
                static fn (mixed $reason): string => strtoupper(trim((string) $reason)),
                (array) $decision->reason_codes,
            ))));
            // ``insufficient_evidence`` is not automatically an infrastructure
            // failure.  A completed replay with a durable request/response,
            // trace, ledger and dataset hash may still lack the trade/sample
            // evidence required by a quality gate.  Only the immutable replay
            // eligibility result (or an explicit technical reason) belongs in
            // technical quarantine.
            $technical = ! $eligible['complete']
                || collect($reasons)->contains(fn (string $reason): bool => $this->isTechnicalReason($reason));
            if (! $eligible['complete'] || $technical) {
                $technicalExcluded[] = (int) $agent->id;
                continue;
            }
            if ((string) $decision->decision === 'insufficient_evidence') {
                $evidenceCompleteInsufficient[] = (int) $agent->id;
                continue;
            }
            if ((string) $decision->decision === 'passed') {
                $passAgents[] = (int) $agent->id;
                continue;
            }
            if ((string) $decision->decision !== 'failed') continue;

            $actionableAgentIds[] = (int) $agent->id;
            if ($reasons === []) $reasons = ['UNSPECIFIED_SCREENING_GATE'];
            foreach ($reasons as $reason) {
                $target = $this->targetForReason($reason);
                $group = $this->groupForTarget($target);
                $lane = $this->laneForTarget($target);
                $failureGroups[$reason] ??= [
                    'reason' => $reason,
                    'gate_hits' => 0,
                    'agent_ids' => [],
                    'target' => $target,
                    'research_group' => $group,
                    'research_lane' => $lane,
                    'protected_semantic_cell' => $target !== null,
                    'non_target_parent_freeze' => true,
                    'mutation_rule' => $this->mutationRule($target),
                ];
                $failureGroups[$reason]['gate_hits']++;
                $failureGroups[$reason]['agent_ids'][] = (int) $agent->id;
                if ($target === null) continue;
                $targetCells[$target] ??= [
                    'target' => $target,
                    'gate_hits' => 0,
                    'agent_ids' => [],
                    'research_group' => $group,
                    'research_lane' => $lane,
                    'protected_semantic_cell' => true,
                    'non_target_parent_freeze' => true,
                    'mutation_rule' => $this->mutationRule($target),
                ];
                $targetCells[$target]['gate_hits']++;
                $targetCells[$target]['agent_ids'][] = (int) $agent->id;
            }
        }

        foreach ($failureGroups as &$failure) {
            $failure['agent_ids'] = array_values(array_unique($failure['agent_ids']));
            $failure['distinct_agents'] = count($failure['agent_ids']);
            unset($failure['agent_ids']);
        }
        unset($failure);
        foreach ($targetCells as &$cell) {
            $cell['agent_ids'] = array_values(array_unique($cell['agent_ids']));
            $cell['distinct_agents'] = count($cell['agent_ids']);
            unset($cell['agent_ids']);
        }
        unset($cell);
        uasort($failureGroups, static fn (array $a, array $b): int =>
            ($b['gate_hits'] <=> $a['gate_hits']) ?: ($b['distinct_agents'] <=> $a['distinct_agents']));
        uasort($targetCells, static fn (array $a, array $b): int =>
            ($b['gate_hits'] <=> $a['gate_hits']) ?: ($b['distinct_agents'] <=> $a['distinct_agents']));

        $report = [
            'protocol' => self::PROTOCOL,
            'symbol' => $lab->symbol,
            'timeframe' => $lab->timeframe,
            'status' => 'studied',
            'source_generation_id' => (int) $generation->id,
            'source_generation' => (int) $generation->generation,
            'distinct_actionable_failed_agents' => count(array_unique($actionableAgentIds)),
            'gate_hit_count' => array_sum(array_map(static fn (array $row): int => (int) $row['gate_hits'], $failureGroups)),
            'screening_pass_agents' => array_values(array_unique($passAgents)),
            'technical_excluded_agent_ids' => array_values(array_unique($technicalExcluded)),
            'legacy_excluded_agent_ids' => array_values(array_unique($legacyExcluded)),
            'evidence_complete_insufficient_agent_ids' => array_values(array_unique($evidenceCompleteInsufficient)),
            'failure_groups' => array_values($failureGroups),
            'target_cells' => array_values($targetCells),
            'temporal_cell_analysis' => $this->cellAnalysisForGeneration($generation, array_unique($actionableAgentIds)),
            'dominant_failure' => array_values($failureGroups)[0]['reason'] ?? null,
            'next_action' => $actionableAgentIds === []
                ? 'evidence_recovery_or_quarantine'
                : 'targeted_rescue_before_full_validation',
            'problem_study' => $this->problemStudy(
                count(array_unique($actionableAgentIds)),
                count(array_unique($passAgents)),
                count(array_unique($technicalExcluded)),
                count(array_unique($legacyExcluded)),
            ),
            'promotion_evidence' => false,
            'causal_prior_allowed' => false,
            'mutation_credit_allowed' => false,
            'parent_selection_allowed' => false,
            'rule' => 'Study is diagnostic. Only one declared target cell may mutate; all non-target logic remains frozen until strict replay evidence exists.',
        ];
        $report['study_hash'] = hash('sha256', json_encode($report, JSON_UNESCAPED_SLASHES));

        if ($persist) {
            SystemEvent::updateOrCreate(
                ['event_key' => 'learning_protocol:failure_study:'.strtolower($lab->symbol).':'.strtolower($lab->timeframe)],
                [
                    'event_type' => 'learning_protocol_failure_study',
                    'source_type' => LabGeneration::class,
                    'source_id' => $generation->id,
                    'agent' => 'failure-study',
                    'symbol' => $lab->symbol,
                    'timeframe' => $lab->timeframe,
                    'severity' => 'info',
                    'summary' => 'Screening failure study updated; diagnostic routing only.',
                    'payload' => $report,
                    'occurred_at' => now(),
                ],
            );
        }

        return $report;
    }

    /**
     * Locate the weakest observable temporal cells from the immutable
     * response artifact. Month/session/direction labels are evidence
     * coordinates only: this method never turns them into a mutation feature
     * or a gate override.
     *
     * @param array<int, int>|null $onlyAgentIds
     * @return array<string, mixed>
     */
    public function cellAnalysisForGeneration(LabGeneration $generation, ?array $onlyAgentIds = null): array
    {
        $generation->loadMissing('agents');
        $allowedIds = $onlyAgentIds === null
            ? null
            : array_values(array_unique(array_map('intval', $onlyAgentIds)));
        $agentIds = $generation->agents
            ->when($allowedIds !== null, fn ($agents) => $agents->whereIn('id', $allowedIds))
            ->pluck('id')->values()->all();
        if ($agentIds === []) {
            return [
                'protocol' => 'temporal_failure_cell_audit_v1',
                'agent_count' => 0,
                'worst_frequency' => [],
                'dominant_cells' => [],
                'per_agent' => [],
                'diagnostic_only' => true,
                'promotion_evidence' => false,
            ];
        }

        $decisions = CandidateGateDecision::query()
            ->whereIn('lab_agent_id', $agentIds)
            ->where('stage', 'screening')
            ->latest('id')->get()
            ->groupBy('lab_agent_id')
            ->map(fn ($rows) => $rows->first());
        $evidence = app(LabImmutableEvidenceService::class);
        $dimensions = [
            'month' => 'pf_attribution.breakdown.by_month',
            'chunk' => 'pf_attribution.breakdown.by_temporal_chunk',
            'session' => 'pf_attribution.breakdown.by_session',
            'direction' => 'pf_attribution.breakdown.by_direction',
            'regime' => 'pf_attribution.breakdown.by_regime',
        ];
        $frequencies = array_fill_keys(array_keys($dimensions), []);
        $perAgent = [];

        foreach ($agentIds as $agentId) {
            $decision = $decisions->get($agentId);
            if (! $decision || (string) $decision->decision !== 'failed') continue;
            $reasons = array_values(array_unique(array_map(
                static fn (mixed $reason): string => strtoupper(trim((string) $reason)),
                (array) $decision->reason_codes,
            )));
            if (collect($reasons)->contains(fn (string $reason): bool => $this->isTechnicalReason($reason))) continue;
            $run = LabEvaluationRun::query()
                ->where('lab_agent_id', $agentId)
                ->where('phase', 'screening')
                ->latest('id')->first();
            if (! $run || ! $evidence->learningEligibility($run)['complete']) continue;
            $payload = $evidence->latestArtifactPayload($run);
            if (! is_array($payload)) continue;
            $breakdown = (array) data_get(
                $payload,
                'pf_attribution.breakdown',
                data_get($payload, 'pf_attribution', []),
            );
            $cells = [];
            foreach ($dimensions as $dimension => $path) {
                $cell = $this->weakestCell((array) data_get($breakdown, str_replace('pf_attribution.breakdown.', '', $path), []));
                if ($cell === null) continue;
                $cells[$dimension] = $cell;
                $key = (string) $cell['cell'];
                $frequencies[$dimension][$key] ??= [
                    'cell' => $key,
                    'agents' => 0,
                    'minimum_net_pf' => null,
                    'trade_counts' => [],
                ];
                $frequencies[$dimension][$key]['agents']++;
                $frequencies[$dimension][$key]['minimum_net_pf'] = $frequencies[$dimension][$key]['minimum_net_pf'] === null
                    ? $cell['net_pf']
                    : min((float) $frequencies[$dimension][$key]['minimum_net_pf'], (float) $cell['net_pf']);
                $frequencies[$dimension][$key]['trade_counts'][] = (int) $cell['trades'];
            }
            if ($cells !== []) {
                $perAgent[] = ['agent_id' => (int) $agentId, 'weakest_cells' => $cells];
            }
        }

        $worstFrequency = [];
        $dominantCells = [];
        foreach ($frequencies as $dimension => $rows) {
            $rows = array_values($rows);
            foreach ($rows as &$row) {
                $row['minimum_net_pf'] = round((float) $row['minimum_net_pf'], 4);
                $row['trade_count_min'] = min((array) $row['trade_counts']);
                $row['trade_count_max'] = max((array) $row['trade_counts']);
                unset($row['trade_counts']);
            }
            unset($row);
            usort($rows, static fn (array $a, array $b): int =>
                ((int) $b['agents'] <=> (int) $a['agents'])
                ?: ((float) $a['minimum_net_pf'] <=> (float) $b['minimum_net_pf']));
            $worstFrequency[$dimension] = $rows;
            if ($rows !== []) $dominantCells[$dimension] = $rows[0];
        }

        return [
            'protocol' => 'temporal_failure_cell_audit_v1',
            'agent_count' => count($perAgent),
            'worst_frequency' => $worstFrequency,
            'dominant_cells' => $dominantCells,
            'per_agent' => $perAgent,
            'minimum_trade_count_per_cell' => 3,
            'diagnostic_only' => true,
            'selection_rule' => 'Use cells to choose a research hypothesis only; never route or relax a quality gate by month/session/direction/regime label.',
            'promotion_evidence' => false,
        ];
    }

    /** @return array{cell: string, net_pf: float, trades: int}|null */
    private function weakestCell(array $rows): ?array
    {
        $candidates = [];
        foreach ($rows as $key => $row) {
            if (! is_array($row)) continue;
            $trades = (int) data_get($row, 'trades', 0);
            if ($trades < 3) continue;
            $pf = (float) data_get($row, 'net_pf', data_get($row, 'profit_factor', 0));
            $candidates[] = ['cell' => (string) $key, 'net_pf' => $pf, 'trades' => $trades];
        }
        usort($candidates, static fn (array $a, array $b): int =>
            ((float) $a['net_pf'] <=> (float) $b['net_pf'])
            ?: ((int) $b['trades'] <=> (int) $a['trades']));

        return $candidates[0] ?? null;
    }

    private function isTechnicalReason(string $reason): bool
    {
        return $reason !== 'INSUFFICIENT_SCREENING_EVIDENCE'
            && (str_contains($reason, 'EVIDENCE')
            || str_contains($reason, 'TECHNICAL')
            || str_contains($reason, 'SNAPSHOT')
            || str_contains($reason, 'DATASET'));
    }

    private function targetForReason(string $reason): ?string
    {
        return match ($reason) {
            'FAILED_PROFIT_FACTOR' => 'profit_factor',
            'FAILED_STRESS_COST' => 'stress_cost',
            'FAILED_TEMPORAL_CHUNK_SURVIVAL',
            'FAILED_CALENDAR_MONTH_SURVIVAL',
            'FAILED_TRAIN_FORWARD_GAP',
            'FAILED_PARAMETER_STABILITY',
            'FAILED_SIGNAL_TIMING_STABILITY' => 'temporal_stability',
            'FAILED_REGIME_COVERAGE',
            'INSUFFICIENT_REGIME_EVIDENCE',
            'FAILED_TRANSITION' => 'regime_coverage',
            'FAILED_NON_TARGET_REGRESSION',
            'FAILED_DRAWDOWN',
            'FAILED_RUIN' => 'drawdown_risk',
            'FAILED_OVERFIT',
            'FAILED_STATISTICAL' => 'architecture',
            default => null,
        };
    }

    private function groupForTarget(?string $target): ?string
    {
        return match ($target) {
            'profit_factor', 'stress_cost' => 'volatility_session_stability',
            'temporal_stability' => 'monthly_survival',
            'regime_coverage' => 'regime_coverage',
            'drawdown_risk' => 'exit_topology',
            'architecture' => 'portfolio_router',
            default => null,
        };
    }

    private function laneForTarget(?string $target): ?string
    {
        return match ($target) {
            'profit_factor', 'stress_cost' => 'pf_stress_cost',
            'temporal_stability' => 'temporal_calendar_stability',
            'regime_coverage' => 'regime_specialist',
            'drawdown_risk' => 'non_target_regression',
            'architecture' => 'architecture_control',
            default => null,
        };
    }

    private function mutationRule(?string $target): string
    {
        return $target === null
            ? 'No targeted mutation: reason is unmapped until reviewed.'
            : 'Mutate only '.$target.'; freeze every non-target semantic cell and parent logic.';
    }

    /** @return array<string, mixed> */
    private function problemStudy(int $actionableFailures, int $screeningPasses, int $technicalExcluded, int $legacyExcluded): array
    {
        $pipelineEvidenceIssue = ($technicalExcluded + $legacyExcluded) > 0;
        $agentQualityIssue = $actionableFailures > 0;
        $screeningBottleneck = $screeningPasses === 0;

        return [
            'pipeline_evidence_layer' => $pipelineEvidenceIssue ? 'requires_recovery_or_quarantine' : 'no_current_excluded_evidence',
            'agent_quality_layer' => $agentQualityIssue ? 'evidence_complete_agents_failed_quality_gates' : 'no_actionable_quality_failure',
            'classification' => $pipelineEvidenceIssue && $agentQualityIssue
                ? 'pipeline_and_agent_quality_are_separate'
                : ($pipelineEvidenceIssue ? 'pipeline_evidence_only' : ($agentQualityIssue ? 'agent_quality_only' : 'no_actionable_failure')),
            'screening_is_bottleneck' => $screeningBottleneck,
            'funnel_blocker' => $screeningBottleneck
                ? 'screening_pass_required_before_full_validation'
                : 'screening_survivor_available_for_strict_full_validation',
            'recommended_action' => $pipelineEvidenceIssue
                ? 'recover_or_quarantine_excluded_evidence_then_run_bounded_targeted_rescue'
                : ($agentQualityIssue ? 'run_bounded_targeted_rescue_with_non_target_parent_freeze' : 'wait_for_strict_validation_result'),
            'counts' => [
                'distinct_actionable_failed_agents' => $actionableFailures,
                'screening_pass_agents' => $screeningPasses,
                'technical_excluded_agents' => $technicalExcluded,
                'legacy_excluded_agents' => $legacyExcluded,
            ],
            'promotion_evidence' => false,
            'causal_prior_allowed' => false,
        ];
    }
}
