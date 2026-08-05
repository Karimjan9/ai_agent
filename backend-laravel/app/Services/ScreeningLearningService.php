<?php

namespace App\Services;

use App\Models\AgentMemory;
use App\Models\LabAgent;
use App\Models\ModelVersion;
use App\Models\MutationMemory;

/**
 * Stores a cautious lesson from a fast screen. It influences the next search
 * direction, but only a full replay can produce a high-confidence hard ban.
 */
class ScreeningLearningService
{
    public function record(LabAgent $agent, ModelVersion $model, array $result, float $forwardScore): void
    {
        $trades = (int) ($result['total_trades'] ?? 0);
        $pf = (float) ($result['profit_factor'] ?? 0);
        $survival = (array) ($result['screening_survival'] ?? []);
        $survivalStatus = (string) ($survival['status'] ?? '');
        // A pretty aggregate PF is not a screen pass when the frozen 5k
        // profile says that the candidate collapses across time, regimes or
        // small parameter perturbations.  It is learning-only evidence,
        // never a harmful mutation verdict.
        $failure = $survivalStatus === 'insufficient_evidence'
            ? 'insufficient_evidence'
            : ($survivalStatus !== '' && $survivalStatus !== 'survivor'
                ? 'survival_'.strtolower((string) ($survival['reason_codes'][0] ?? 'instability'))
                : ($trades === 0 ? 'no_trade' : ($pf < 1.0 ? 'negative_edge' : ($pf < 1.3 ? 'weak_profit_factor' : 'screen_pass'))));
        $confidence = min(65, 45 + min(20, $trades));
        $funnel = (array) ($result['entry_funnel'] ?? []);
        $architecture = data_get($model->metadata, 'strategy_architecture');
        $scope = [
            'architecture' => $architecture,
            'market_regime' => data_get($model->metadata, 'mutation_scope'),
            'direction' => data_get($model->metadata, 'causal_experiment_lane.direction'),
            'volatility_regime' => data_get($model->metadata, 'causal_experiment_lane.volatility_regime'),
            'execution_contract_hash' => (string) data_get($result, 'execution_contract.execution_hash', hash('sha256', json_encode(data_get($result, 'execution_assumptions', []), JSON_PRESERVE_ZERO_FRACTION))),
        ];
        $cooldownRescueEffect = $this->cooldownRescueEffect($agent, $model, $result);
        $counterfactualEffect = $this->counterfactualEffect($agent, $model, $result);

        $actions = $this->actions($agent->strategy_family, $failure, $funnel);
        foreach (['entry_quality', 'exit_quality', 'architecture_quality'] as $type) {
            AgentMemory::updateOrCreate(
                ['source_type' => LabAgent::class, 'source_id' => $agent->id, 'memory_type' => 'screen_'.$type],
                [
                    'strategy' => $model->strategy,
                    'outcome' => $failure === 'screen_pass' ? 'candidate' : 'inconclusive',
                    'summary' => "Screen {$failure}: PF ".round($pf, 2).", {$trades} trades, forward ".round($forwardScore, 2).'.',
                    'lesson' => $actions[$type]['lesson'],
                    'strength' => $confidence,
                    'confidence_score' => $confidence,
                    'metadata' => [
                        'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe,
                        'strategy_family' => $agent->strategy_family, 'screen_failure' => $failure,
                        'entry_funnel' => $funnel, 'parameter_actions' => $actions[$type],
                    ],
                ],
            );
        }

        // Short data is not a falsification of the parameter or architecture.
        // It stays observable in AgentMemory, but cannot create a mutation
        // lesson until the strict screening window exists.
        if (in_array($failure, ['screen_pass', 'insufficient_evidence'], true)) {
            return;
        }

        foreach ($agent->parameter_diff ?? [] as $key => $change) {
            $memory = MutationMemory::updateOrCreate(
                ['lab_agent_id' => $agent->id, 'parameter_key' => $key],
                [
                    'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe,
                    'strategy_family' => $agent->strategy_family,
                    ...$scope,
                    'old_value' => ['value' => $change['old'] ?? null],
                    'new_value' => ['value' => $change['new'] ?? null],
                    'forward_delta' => 0, 'outcome' => 'screen_inconclusive',
                    'independent_confirmation_count' => 0,
                    'non_target_regression_status' => (string) data_get($result, 'differential_no_regression.status', 'not_applicable'),
                    'evidence_scope_status' => 'historical_failure_memory',
                    'confidence' => $confidence, 'decision' => "screen_{$failure}; no causal credit",
                    'behavioral_effect' => $key === 'loss_cooldown_candles' && $cooldownRescueEffect !== null
                        ? $cooldownRescueEffect
                        : ($counterfactualEffect !== null ? $counterfactualEffect
                        : ['causal_credit' => ['status' => 'screen_inconclusive', 'rule' => 'Only a paired full replay may label an individual parameter harmful or beneficial.']]),
                ],
            );
            $ledger = app(LabImmutableEvidenceService::class);
            $ledger->recordMutationCredit($memory, [
                'source' => 'screening_learning', 'screen_failure' => $failure,
                'result_hash' => $ledger->hash($result),
            ], data_get($result, 'evidence_run_id'));
        }

        if ($architecture) {
            $memory = MutationMemory::updateOrCreate(
                ['lab_agent_id' => $agent->id, 'parameter_key' => '__architecture'],
                [
                    'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe,
                    'strategy_family' => $agent->strategy_family,
                    ...$scope,
                    'old_value' => ['value' => null], 'new_value' => ['value' => $architecture],
                    'forward_delta' => 0, 'outcome' => 'screen_inconclusive',
                    'independent_confirmation_count' => 0,
                    'non_target_regression_status' => (string) data_get($result, 'differential_no_regression.status', 'not_applicable'),
                    'evidence_scope_status' => 'historical_failure_memory',
                    'confidence' => $confidence, 'decision' => "screen_{$failure}; architecture has no causal credit",
                    'behavioral_effect' => ['causal_credit' => ['status' => 'screen_inconclusive', 'rule' => 'A short screen cannot blacklist an architecture.']],
                ],
            );
            $ledger = app(LabImmutableEvidenceService::class);
            $ledger->recordMutationCredit($memory, [
                'source' => 'screening_learning', 'screen_failure' => $failure,
                'result_hash' => $ledger->hash($result),
            ], data_get($result, 'evidence_run_id'));
        }
    }

    private function actions(string $family, string $failure, array $funnel): array
    {
        $overFiltered = (int) ($funnel['flat_signal_opportunities'] ?? 0) >= 30
            && (int) ($funnel['accepted_entries'] ?? 0) < ((int) ($funnel['flat_signal_opportunities'] ?? 0) / 2);
        $entry = $failure === 'no_trade' || $overFiltered
            ? ['prioritize' => ['lookback', 'confirmation_candles', 'minimum_signal_confidence'], 'avoid' => [], 'lesson' => 'Screen lacked executable entries; relax only the diagnosed entry filter in G2.']
            : ['prioritize' => ['trend_strength_min', 'pullback_atr_fraction', 'minimum_signal_confidence'], 'avoid' => [], 'lesson' => 'Screen PF was weak; alter entry quality before adding trade frequency.'];
        $exit = ['prioritize' => ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles', 'partial_take_profit_fraction'], 'avoid' => [], 'lesson' => 'Weak screen economics requires adaptive ATR exit experiments, not a larger fixed stop.'];
        $architecture = ['prioritize' => [], 'avoid' => [], 'lesson' => "{$family} failed a short economic-edge screen; G2 must test a materially different topology before reuse."];

        return [
            'entry_quality' => $entry,
            'exit_quality' => $exit,
            'architecture_quality' => $architecture,
        ];
    }

    /** Persist observed, non-promotional effect for the 4→2/3 experiment.
     * A failed strict screen cannot label a gene harmful, but it must not be
     * indistinguishable from a mutation that never changed behaviour. */
    private function cooldownRescueEffect(LabAgent $agent, ModelVersion $model, array $result): ?array
    {
        $contract = (array) data_get($model->metadata, 'causal_rescue_contract', []);
        if (data_get($contract, 'kind') !== 'loss_cooldown_single_gene') return null;
        $parent = $agent->parentA;
        $baseline = (array) data_get($parent?->metadata, 'last_screen_result', []);
        $current = [
            'trades' => (int) data_get($result, 'total_trades', 0),
            'profit_factor' => round((float) data_get($result, 'profit_factor', 0), 4),
            'stress_profit_factor' => round((float) data_get($result, 'screening_survival.stress_cost_pf', 0), 4),
            'worst_regime_profit_factor' => data_get($result, 'screening_survival.worst_regime_pf'),
            'worst_temporal_chunk_profit_factor' => data_get($result, 'screening_survival.worst_temporal_chunk_pf', data_get($result, 'screening_survival.worst_window_pf')),
            'train_forward_gap' => round((float) data_get($result, 'screening_survival.train_forward_gap', 0), 4),
            'parameter_stability' => round((float) data_get($result, 'screening_survival.parameter_perturbation_ratio', 0), 4),
        ];
        $baselineEffect = [
            'trades' => (int) data_get($baseline, 'total_trades', 0),
            'profit_factor' => round((float) data_get($baseline, 'profit_factor', 0), 4),
            'stress_profit_factor' => round((float) data_get($baseline, 'screening_survival.stress_cost_pf', 0), 4),
        ];
        $effective = $current['trades'] !== $baselineEffect['trades']
            || abs($current['profit_factor'] - $baselineEffect['profit_factor']) >= .0001
            || abs($current['stress_profit_factor'] - $baselineEffect['stress_profit_factor']) >= .0001;
        $sibling = LabAgent::query()->with('modelVersion')->where('lab_generation_id', $agent->lab_generation_id)
            ->whereKeyNot($agent->id)->where('origin', 'causal_isolation')
            ->whereJsonContains('parameter_diff->loss_cooldown_candles->old', 4)->first();
        $siblingResult = (array) data_get($sibling?->modelVersion?->metadata, 'last_screen_result', []);
        $siblingEffect = $siblingResult === [] ? null : [
            'agent_id' => $sibling->id,
            'cooldown_candles' => data_get($sibling->parameter_diff, 'loss_cooldown_candles.new'),
            'trades' => (int) data_get($siblingResult, 'total_trades', 0),
            'profit_factor' => round((float) data_get($siblingResult, 'profit_factor', 0), 4),
            'stress_profit_factor' => round((float) data_get($siblingResult, 'screening_survival.stress_cost_pf', 0), 4),
        ];
        return [
            'causal_credit' => [
                'status' => 'screen_inconclusive',
                'rule' => 'Strict screening may measure a single-gene effect but cannot assign beneficial or harmful credit.',
            ],
            'causal_rescue' => [
                'kind' => 'loss_cooldown_single_gene',
                'source_agent_id' => data_get($contract, 'source_agent_id'),
                'variant' => data_get($contract, 'variant'),
                'baseline' => $baselineEffect, 'observed' => $current,
                'parameter_effective' => $effective,
                'sibling_variant' => $siblingEffect,
                'variant_separation_observed' => $siblingEffect === null ? null : (
                    $current['trades'] !== $siblingEffect['trades']
                    || abs($current['profit_factor'] - $siblingEffect['profit_factor']) >= .0001
                    || abs($current['stress_profit_factor'] - $siblingEffect['stress_profit_factor']) >= .0001
                ),
                'screen_contract_status' => 'failed',
                'rule' => 'No full replay or promotion unless every strict rescue threshold passes.',
            ],
        ];
    }

    /** Frozen-entry interventions become Blame Graph evidence, never a screen promotion shortcut. */
    private function counterfactualEffect(LabAgent $agent, ModelVersion $model, array $result): ?array
    {
        $contract = (array) data_get($model->metadata, 'counterfactual_exit_contract', []);
        if ($contract === []) return null;
        $baseline = (array) data_get($agent->parentA?->metadata, 'last_screen_result', []);
        $metrics = fn (array $row) => [
            'profit_factor' => round((float) data_get($row, 'profit_factor', 0), 4),
            'stress_profit_factor' => round((float) data_get($row, 'screening_survival.stress_cost_pf', data_get($row, 'pf_attribution.stress_cost.profit_factor', 0)), 4),
            'temporal_profit_factor' => round((float) data_get($row, 'screening_survival.worst_temporal_chunk_pf', 0), 4),
            'calendar_profit_factor' => round((float) data_get($row, 'screening_survival.worst_calendar_month_pf', 0), 4),
            'sample_count' => (int) data_get($row, 'total_trades', 0),
        ];
        $base = $metrics($baseline); $observed = $metrics($result);
        return ['causal_credit' => ['status' => 'screen_inconclusive', 'rule' => 'A frozen-entry screen records counterfactual deltas but cannot award causal credit.'],
            'counterfactual_replay' => ['kind' => 'exit_topology_single_gene', 'intervention' => data_get($contract, 'single_gene'),
                'baseline' => $base, 'observed' => $observed,
                'delta_pf' => round($observed['profit_factor'] - $base['profit_factor'], 4),
                'stress_delta' => round($observed['stress_profit_factor'] - $base['stress_profit_factor'], 4),
                'temporal_delta' => round($observed['temporal_profit_factor'] - $base['temporal_profit_factor'], 4),
                'calendar_delta' => round($observed['calendar_profit_factor'] - $base['calendar_profit_factor'], 4),
                'confidence_interval' => [null, null], 'entry_topology' => 'frozen', 'promotion_evidence' => false]];
    }
}
