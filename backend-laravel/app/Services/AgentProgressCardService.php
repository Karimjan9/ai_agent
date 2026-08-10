<?php

namespace App\Services;

use App\Models\AgentProgressCard;
use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\ModelMarketPerformance;
use Illuminate\Support\Carbon;

/**
 * Keeps the agent's bounded growth path explicit and queryable.
 *
 * This service is a state projection, not a promotion authority. It never
 * relaxes a gate and never downgrades a previously reached stage. A failed
 * later replay is represented by status=blocked/quarantined while the last
 * earned stage remains visible for diagnosis.
 */
class AgentProgressCardService
{
    public const PROTOCOL = 'agent_progress_card_v1';

    public const STAGES = [
        'weak', 'diagnosed', 'repaired', 'specialist',
        'elite_candidate', 'paper_ready', 'challenger', 'champion',
    ];

    public function sync(
        LabAgent $agent,
        ?ModelMarketPerformance $performance = null,
        array $result = [],
        ?CandidateGateDecision $decision = null,
    ): AgentProgressCard {
        $agent->loadMissing('modelVersion', 'generation');
        $model = $agent->modelVersion;
        $tacticContract = data_get($model?->metadata, 'tactic_contract');
        if (! is_array($tacticContract) || $tacticContract === []) {
            $architecture = (string) data_get(
                $model?->metadata,
                'strategy_architecture',
                $agent->strategy_family,
            );
            $tacticContract = app(TacticCatalogueService::class)->for(
                $agent->strategy_family,
                $architecture,
                data_get($model?->metadata, 'generation_target'),
            );
        }
        $performance ??= $this->latestPerformance($agent);
        $decision ??= $this->latestDecision($agent, $performance);
        $observed = $this->observedResult($agent, $model, $performance, $result);
        $failureCodes = $this->failureCodes($observed, $decision);
        $primaryFailure = $this->primaryFailure($failureCodes);
        $changedGene = $this->changedGene($agent);
        $repairAttempt = $this->repairAttempt($agent, $model);
        $parentDiff = $this->parentDiff($agent, $performance, $observed);
        $gatesPassed = $this->gatesPassed($observed);
        $targetImproved = $this->targetImproved($observed, $parentDiff);
        $specialist = $this->isSpecialist($agent, $model);
        $candidateStage = $this->candidateStage(
            $agent,
            $performance,
            $decision,
            $observed,
            $primaryFailure,
            $repairAttempt,
            $targetImproved,
            $specialist,
        );

        $card = AgentProgressCard::query()->where('lab_agent_id', $agent->id)->first();
        $oldStage = $card?->stage;
        $stage = $this->highestStage($oldStage, $candidateStage);
        $status = $this->cardStatus($agent, $observed, $primaryFailure, $decision);
        $nextAction = $this->nextAction($stage, $primaryFailure, $status, $targetImproved);
        $freezeAt = $this->freezeAt($card?->frozen_at, $model, $observed, $stage);
        $history = (array) ($card?->stage_history ?? []);
        $evidenceRunId = (string) data_get($observed, 'evidence_run_id', '');
        $resultHash = hash('sha256', json_encode($observed, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
        $now = now();

        if ($card === null || $oldStage !== $stage) {
            $history[] = [
                'protocol' => self::PROTOCOL,
                'from' => $oldStage,
                'to' => $stage,
                'status' => $status,
                'primary_failure' => $primaryFailure,
                'changed_gene' => $changedGene,
                'repair_attempt' => $repairAttempt,
                'reason_codes' => $failureCodes,
                'evidence_run_id' => $evidenceRunId ?: null,
                'observed_at' => $now->utc()->toIso8601String(),
            ];
            // Keep the mutable projection bounded; the immutable evidence
            // ledger receives every transition separately below.
            $history = array_slice($history, -32);
        }

        $card = AgentProgressCard::query()->updateOrCreate(
            ['lab_agent_id' => $agent->id],
            [
                'model_version_id' => $model?->id,
                'symbol' => $agent->symbol,
                'timeframe' => $agent->timeframe,
                'strategy_family' => $agent->strategy_family,
                'tactic_id' => data_get($tacticContract, 'tactic_id'),
                'tactic_contract' => $tacticContract,
                'stage' => $stage,
                'status' => $status,
                'primary_failure' => $primaryFailure,
                'changed_gene' => $changedGene,
                'repair_attempt' => $repairAttempt,
                'parent_model_version_id' => $agent->parent_a_model_version_id ?: $agent->parent_b_model_version_id,
                'parent_diff' => $parentDiff,
                'gates_passed' => $gatesPassed,
                'failure_codes' => $failureCodes,
                'next_action' => $nextAction,
                'stage_history' => $history,
                'frozen_at' => $freezeAt,
                'last_evaluated_at' => $now,
                'last_evidence_run_id' => $evidenceRunId ?: $card?->last_evidence_run_id,
                'last_result_hash' => $resultHash,
            ],
        );

        if ($oldStage !== $stage) {
            try {
                app(LabImmutableEvidenceService::class)->recordLifecycle(
                    $agent,
                    'agent_progress_stage_transition',
                    [
                        'protocol' => self::PROTOCOL,
                        'from_stage' => $oldStage,
                        'to_stage' => $stage,
                        'status' => $status,
                        'primary_failure' => $primaryFailure,
                        'changed_gene' => $changedGene,
                        'repair_attempt' => $repairAttempt,
                        'gates_passed' => $gatesPassed,
                        'next_action' => $nextAction,
                        'result_hash' => $resultHash,
                    ],
                    'agent_progress',
                    $evidenceRunId ?: null,
                    null,
                    self::class,
                    null,
                    $oldStage,
                    $stage,
                );
            } catch (\Throwable $exception) {
                // The card is a projection. A ledger write problem must be
                // visible without rolling back the replay gate that produced
                // this projection.
                report($exception);
            }
        }

        return $card->fresh();
    }

    private function latestPerformance(LabAgent $agent): ?ModelMarketPerformance
    {
        return ModelMarketPerformance::query()
            ->where('model_version_id', $agent->model_version_id)
            ->where('symbol', $agent->symbol)
            ->where('timeframe', $agent->timeframe)
            ->latest('id')
            ->first();
    }

    private function latestDecision(LabAgent $agent, ?ModelMarketPerformance $performance): ?CandidateGateDecision
    {
        $query = CandidateGateDecision::query()
            ->when($performance, fn ($query) => $query->where('model_market_performance_id', $performance->id))
            ->whereIn('stage', ['screening', 'statistical_forward_gate', 'paper_observation', 'sealed_holdout'])
            ->latest('evaluated_at');
        if ($performance) {
            $query->where(function ($builder) use ($agent): void {
                $builder->where('lab_agent_id', $agent->id)->orWhereNull('lab_agent_id');
            });
        } else {
            $query->where('lab_agent_id', $agent->id);
        }
        return $query->first();
    }

    private function observedResult(
        LabAgent $agent,
        mixed $model,
        ?ModelMarketPerformance $performance,
        array $result,
    ): array {
        $metadata = (array) ($model?->metadata ?? []);
        return array_replace_recursive(
            (array) data_get($metadata, 'last_screen_result', []),
            (array) data_get($metadata, 'last_result', []),
            (array) ($performance?->metrics ?? []),
            $result,
            [
                'lifecycle_status' => $agent->lifecycle_status,
                'agent_id' => $agent->id,
                'model_version_id' => $agent->model_version_id,
            ],
        );
    }

    private function failureCodes(array $result, ?CandidateGateDecision $decision): array
    {
        $codes = array_merge(
            (array) ($decision?->reason_codes ?? []),
            (array) data_get($result, 'screening_survival.reason_codes', []),
            (array) data_get($result, 'elite_agent_passport.reason_codes', []),
        );
        $append = function (string $code) use (&$codes): void {
            if (! in_array($code, $codes, true)) $codes[] = $code;
        };

        if ((bool) data_get($result, 'is_overfit', false)
            || (float) data_get($result, 'selection_validation.probability_of_backtest_overfitting', 0) > .50
            || (data_get($result, 'statistical_evidence.deflated_sharpe.status') === 'assessed'
                && (float) data_get($result, 'statistical_evidence.deflated_sharpe.deflated_sharpe_probability', 1) < .95)) {
            $append('FAILED_OVERFIT');
        }
        if (data_get($result, 'monthly_passport.status') === 'seasonal_or_luck'
            || (int) data_get($result, 'monthly_passport.failed_months', 0) > 0) {
            $append('FAILED_CALENDAR_MONTH_SURVIVAL');
        }
        if ((bool) data_get($result, 'statistical_evidence.edge_quality.worst_regime_sampled', false)
            && (float) data_get($result, 'statistical_evidence.edge_quality.worst_regime_pf', 1) < 1.0) {
            $append('FAILED_REGIME_COVERAGE');
        }
        $stressProfile = (array) data_get($result, 'pf_attribution', []);
        $stressObserved = array_key_exists('stress_cost', $stressProfile)
            || data_get($result, 'execution_digital_twin.status') !== null;
        if ($stressObserved && ((float) data_get($result, 'pf_attribution.stress_cost.profit_factor', 0) < 1.05
            || (data_get($result, 'execution_digital_twin.status') === 'assessed'
                && ! (bool) data_get($result, 'execution_digital_twin.pass', false)))) {
            $append('FAILED_STRESS_COST');
        }
        if ((float) data_get($result, 'max_drawdown_percent', data_get($result, 'max_drawdown', 0)) > 15) {
            $append('FAILED_DRAWDOWN');
        }
        $quality = (array) data_get($result, 'data_quality', []);
        if ($quality !== [] && (data_get($quality, 'status') !== 'passed'
            || (int) data_get($quality, 'duplicate_timestamp_count', 0) > 0
            || (int) data_get($quality, 'non_monotonic_timestamp_pairs', 0) > 0
            || (int) data_get($quality, 'invalid_ohlc_rows', 0) > 0)) {
            $append('FAILED_DATA_QUALITY');
        }
        if (data_get($result, 'opportunity_recall.status') === 'assessed'
            && ((float) data_get($result, 'opportunity_recall.opportunity_recall', 1) < .20
                || (float) data_get($result, 'opportunity_recall.abstention_precision', 1) < .50)) {
            $append('FAILED_PASSPORT_OPPORTUNITY_RECALL');
        }

        return array_values(array_unique(array_filter($codes, fn ($code): bool => is_string($code) && $code !== '')));
    }

    private function primaryFailure(array $codes): ?string
    {
        $priorities = [
            'monthly_survival' => ['MONTH', 'CALENDAR', 'TEMPORAL'],
            'regime_coverage' => ['REGIME'],
            'elite_quorum' => ['ELITE_QUORUM', 'ELITE_PASSPORT'],
            'opportunity_recall' => ['OPPORTUNITY_RECALL', 'RECALL'],
            'stress' => ['STRESS', 'EXECUTION'],
            'parameter_robustness' => ['PARAMETER_PLATEAU', 'PARAMETER_STABILITY'],
            'drawdown' => ['DRAWDOWN', 'RUIN'],
            'overfit' => ['OVERFIT', 'NOISE', 'DSR', 'PBO'],
            'data_quality' => ['DATA_QUALITY', 'DATA_MANIFEST'],
        ];
        foreach ($priorities as $failure => $needles) {
            foreach ($codes as $code) {
                foreach ($needles as $needle) {
                    if (str_contains(strtoupper($code), $needle)) return $failure;
                }
            }
        }
        return null;
    }

    private function changedGene(LabAgent $agent): ?string
    {
        $keys = array_keys((array) $agent->parameter_diff);
        return count($keys) === 1 ? (string) $keys[0] : ($keys === [] ? null : 'mutation_bundle');
    }

    private function repairAttempt(LabAgent $agent, mixed $model): int
    {
        return max(
            (int) data_get($model?->metadata, 'repair_lineage.attempt', 0),
            (int) data_get($model?->metadata, 'repair_attempt', 0),
            (int) data_get($model?->metadata, 'generation_repair_attempt', 0),
        );
    }

    private function parentDiff(LabAgent $agent, ?ModelMarketPerformance $performance, array $result): array
    {
        $parentIds = app(ParentContributionGraphService::class)->ids($agent);
        $parentId = $parentIds[0] ?? null;
        $parents = $parentIds === []
            ? collect()
            : ModelMarketPerformance::query()
                ->whereIn('model_version_id', $parentIds)
                ->where('symbol', $agent->symbol)
                ->where('timeframe', $agent->timeframe)
                ->latest('id')
                ->get()
                ->unique('model_version_id')
                ->sortBy(fn (ModelMarketPerformance $candidate): int => (int) array_search(
                    (int) $candidate->model_version_id,
                    $parentIds,
                    true,
                ))
                ->values();
        $metrics = [];
        $parent = $parents->first();
        foreach (['forward_score', 'total_trades', 'profit_factor', 'max_drawdown_percent', 'rolling_forward_wins'] as $key) {
            $before = $parent ? data_get($parent->metrics, $key, $key === 'forward_score' ? $parent->forward_score : null) : null;
            $after = data_get($result, $key, data_get($performance?->metrics, $key));
            $metrics[$key] = [
                'before' => is_numeric($before) ? (float) $before : $before,
                'after' => is_numeric($after) ? (float) $after : $after,
                'delta' => is_numeric($before) && is_numeric($after) ? round((float) $after - (float) $before, 6) : null,
            ];
        }
        $parentMetrics = $parents->mapWithKeys(function (ModelMarketPerformance $candidate): array {
            $values = [];
            foreach (['forward_score', 'total_trades', 'profit_factor', 'max_drawdown_percent', 'rolling_forward_wins'] as $key) {
                $values[$key] = data_get($candidate->metrics, $key, $key === 'forward_score' ? $candidate->forward_score : null);
            }
            return [(string) $candidate->model_version_id => $values];
        })->all();
        $metricDeltaByParent = $parents->mapWithKeys(function (ModelMarketPerformance $candidate) use ($result, $performance): array {
            $values = [];
            foreach (['forward_score', 'total_trades', 'profit_factor', 'max_drawdown_percent', 'rolling_forward_wins'] as $key) {
                $before = data_get($candidate->metrics, $key, $key === 'forward_score' ? $candidate->forward_score : null);
                $after = data_get($result, $key, data_get($performance?->metrics, $key));
                $values[$key] = [
                    'before' => is_numeric($before) ? (float) $before : $before,
                    'after' => is_numeric($after) ? (float) $after : $after,
                    'delta' => is_numeric($before) && is_numeric($after)
                        ? round((float) $after - (float) $before, 6) : null,
                ];
            }
            return [(string) $candidate->model_version_id => $values];
        })->all();
        return [
            'protocol' => 'paired_parent_child_replay_v1',
            'parent_model_version_id' => $parentId,
            'parent_model_version_ids' => $parentIds,
            'parent_metrics' => $parentMetrics,
            'metric_delta_by_parent' => $metricDeltaByParent,
            'parameter_diff' => (array) $agent->parameter_diff,
            'metric_delta' => $metrics,
            'paired_replay' => data_get($result, 'paired_replay', data_get($result, 'paired_experiment', [])),
            'no_regression_contract' => data_get($result, 'no_regression_contract', []),
            'same_data_hash' => data_get($parent?->metrics, 'data_manifest.sha256') !== null
                && data_get($parent?->metrics, 'data_manifest.sha256') === data_get($result, 'data_manifest.sha256'),
            'same_execution_hash' => data_get($parent?->metrics, 'execution_contract.execution_hash') !== null
                && data_get($parent?->metrics, 'execution_contract.execution_hash') === data_get($result, 'execution_contract.execution_hash'),
            'promotion_evidence' => false,
        ];
    }

    private function gatesPassed(array $result): array
    {
        $passed = [];
        $add = function (string $name, bool $condition) use (&$passed): void {
            if ($condition) $passed[] = $name;
        };
        $add('monthly_survival', (int) data_get($result, 'monthly_passport.rolling_forward_wins', 0) >= 3
            && (int) data_get($result, 'monthly_passport.failed_months', 0) === 0);
        $add('regime_coverage', (bool) data_get($result, 'statistical_evidence.edge_quality.worst_regime_sampled', false)
            && (float) data_get($result, 'statistical_evidence.edge_quality.worst_regime_pf', 0) >= 1.0);
        $add('elite_quorum', data_get($result, 'elite_agent_passport.elite_quorum.status') === 'passed');
        $add('opportunity_recall', data_get($result, 'opportunity_recall.status') === 'assessed'
            && (float) data_get($result, 'opportunity_recall.opportunity_recall', 0) >= .20
            && (float) data_get($result, 'opportunity_recall.abstention_precision', 0) >= .50);
        $add('stress', (float) data_get($result, 'pf_attribution.stress_cost.profit_factor', 0) >= 1.05
            && (! data_get($result, 'execution_digital_twin.status')
                || (data_get($result, 'execution_digital_twin.status') === 'assessed'
                    && (bool) data_get($result, 'execution_digital_twin.pass', false))));
        $add('drawdown', (float) data_get($result, 'max_drawdown_percent', data_get($result, 'max_drawdown', 100)) <= 15
            && (float) data_get($result, 'monte_carlo.risk_of_ruin_percent', 100) <= 10);
        $add('overfit_control', ! (bool) data_get($result, 'is_overfit', false)
            && (! data_get($result, 'selection_validation.status')
                || (float) data_get($result, 'selection_validation.probability_of_backtest_overfitting', 0) <= .50));
        $quality = (array) data_get($result, 'data_quality', []);
        $add('data_quality', $quality === [] || (data_get($quality, 'status') === 'passed'
            && (int) data_get($quality, 'duplicate_timestamp_count', 0) === 0
            && (int) data_get($quality, 'non_monotonic_timestamp_pairs', 0) === 0
            && (int) data_get($quality, 'invalid_ohlc_rows', 0) === 0));
        $add('parameter_plateau', data_get($result, 'parameter_plateau.status') === 'assessed'
            && (bool) data_get($result, 'parameter_plateau.pass', false));
        $add('paired_replay', data_get($result, 'paired_replay.status', data_get($result, 'paired_experiment.status')) === 'confirmed');
        $add('no_regression', data_get($result, 'no_regression_contract.status') === 'passed');
        $add('gold_holdout', data_get($result, 'gold_holdout.protocol') === 'gold_holdout_v1'
            && data_get($result, 'gold_holdout.used_for_training') === false
            && data_get($result, 'gold_holdout.used_for_evolution') === false);
        $add('forward_quorum', (int) data_get($result, 'challenger_protocol.observed_forward_windows', 0) >= 3
            && (int) data_get($result, 'challenger_protocol.positive_forward_windows', 0) >= 3);
        $add('elite_passport', data_get($result, 'elite_agent_passport.status') === 'passed');
        return $passed;
    }

    private function targetImproved(array $result, array $parentDiff): bool
    {
        if (data_get($result, 'paired_replay.status', data_get($result, 'paired_experiment.status')) === 'confirmed') return true;
        if (data_get($result, 'no_regression_contract.status') !== 'passed') return false;
        $deltas = collect([(array) data_get($parentDiff, 'metric_delta', [])])
            ->merge(array_values((array) data_get($parentDiff, 'metric_delta_by_parent', [])));
        return $deltas->contains(fn (array $metrics): bool => collect($metrics)->contains(
            fn (array $item): bool => is_numeric($item['delta'] ?? null) && (float) $item['delta'] > 0,
        ));
    }

    private function isSpecialist(LabAgent $agent, mixed $model): bool
    {
        $metadata = (array) ($model?->metadata ?? []);
        return in_array($agent->strategy_family, ['hybrid', 'regime_ensemble', 'differential_router'], true)
            || data_get($metadata, 'g98_council_lane.protocol') !== null
            || data_get($metadata, 'portfolio_research_contract.protocol') !== null
            || data_get($metadata, 'differential_router_contract') !== null;
    }

    private function candidateStage(
        LabAgent $agent,
        ?ModelMarketPerformance $performance,
        ?CandidateGateDecision $decision,
        array $result,
        ?string $primaryFailure,
        int $repairAttempt,
        bool $targetImproved,
        bool $specialist,
    ): string {
        $lifecycle = (string) $agent->lifecycle_status;
        if ($lifecycle === 'champion' || $performance?->status === 'champion') return 'champion';
        if ($performance?->status === 'paper' || $performance?->paper_status === 'passed') return 'challenger';
        if ($lifecycle === 'forward_validated' || $performance?->status === 'forward_validated'
            || $decision?->stage === 'statistical_forward_gate' && $decision?->decision === 'passed') return 'paper_ready';
        if (data_get($result, 'elite_agent_passport.status') === 'passed') return 'elite_candidate';
        if ($specialist && $targetImproved) return 'specialist';
        if ($repairAttempt > 0 && $targetImproved) return 'repaired';
        if ($primaryFailure !== null || $decision?->decision === 'failed') return 'diagnosed';
        if ($specialist) return 'specialist';
        return 'weak';
    }

    private function highestStage(?string $old, string $candidate): string
    {
        $oldIndex = $old === null ? -1 : array_search($old, self::STAGES, true);
        $candidateIndex = array_search($candidate, self::STAGES, true);
        return self::STAGES[max($oldIndex === false ? -1 : $oldIndex, $candidateIndex === false ? 0 : $candidateIndex)];
    }

    private function cardStatus(LabAgent $agent, array $result, ?string $failure, ?CandidateGateDecision $decision): string
    {
        $lifecycle = (string) $agent->lifecycle_status;
        $quarantine = in_array($lifecycle, ['quarantined', 'technical_quarantine', 'overfit', 'evaluation_error'], true)
            || in_array($failure, ['overfit', 'data_quality'], true)
            || in_array('QUARANTINED_PROOF_REPLAY_MISMATCH', (array) ($decision?->reason_codes ?? []), true);
        if ($quarantine) return 'quarantined';
        if ($decision?->decision === 'failed' || $failure !== null) return 'blocked';
        return 'active';
    }

    private function nextAction(string $stage, ?string $failure, string $status, bool $targetImproved): string
    {
        if ($status === 'quarantined') return 'quarantine_no_more_tuning';
        return match ($failure) {
            'monthly_survival' => 'run_one_gene_monthly_repair',
            'regime_coverage' => 'run_differential_regime_specialist',
            'elite_quorum' => 'complete_elite_quorum_forward_windows',
            'opportunity_recall' => 'run_bounded_opportunity_recall_repair',
            'stress' => 'repair_exit_risk_topology',
            'parameter_robustness' => 'repair_one_gene_parameter_plateau',
            'drawdown' => 'repair_risk_exit_gene',
            'overfit' => 'quarantine_no_more_tuning',
            'data_quality' => 'repair_data_contract',
            default => match ($stage) {
                'weak' => 'diagnose_primary_failure',
                'diagnosed' => 'apply_one_gene_repair',
                'repaired' => 'run_independent_forward_confirmation',
                'specialist' => 'run_specialist_forward_confirmation',
                'elite_candidate' => 'complete_elite_passport_quorum',
                'paper_ready' => 'capture_immutable_paper_evidence',
                'challenger' => 'run_sealed_holdout_and_champion_comparison',
                'champion' => 'monitor_drift_and_recertify',
                default => $targetImproved ? 'freeze_and_recheck' : 'diagnose_primary_failure',
            },
        };
    }

    private function freezeAt(?Carbon $existing, mixed $model, array $result, string $stage): ?Carbon
    {
        if ($existing) return $existing;
        $metadataFreeze = data_get($model?->metadata, 'elite_agent_passport.freeze.frozen_at');
        if ($metadataFreeze) return Carbon::parse($metadataFreeze);
        if (array_search($stage, self::STAGES, true) >= array_search('elite_candidate', self::STAGES, true)
            && data_get($result, 'elite_agent_passport.status') === 'passed') return now();
        return null;
    }
}
