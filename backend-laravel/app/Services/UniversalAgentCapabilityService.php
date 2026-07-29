<?php

namespace App\Services;

use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;

/** Capability transfer is evidence transfer, never a cross-market promotion shortcut. */
class UniversalAgentCapabilityService
{
    public function genome(string $symbol, string $timeframe, string $family, string $architecture, array $parameters, ?ModelVersion $parent = null): array
    {
        $parentCore = (array) data_get($parent?->metadata, 'universal_genome.core', []);
        $core = $parentCore ?: [
            'protocol' => 'universal_core_v1', 'family' => $family,
            'invariants' => ['closed_candle_decision', 'next_candle_execution', 'cost_aware_ev', 'ood_abstention', 'bounded_risk'],
            'transfer_rule' => 'Only capability priors and falsified mutation directions may cross markets; no champion status or promotion evidence transfers.',
        ];
        return ['core' => $core, 'local_adapter' => [
            'symbol' => $symbol, 'timeframe' => $timeframe, 'architecture' => $architecture,
            'parameters_hash' => hash('sha256', json_encode($parameters, JSON_PRESERVE_ZERO_FRACTION)),
            'operating_envelope' => 'local_evidence_required',
        ], 'risk_guard' => ['unknown_state' => 'WAIT_OR_REDUCE_RISK', 'transfer_requires_leave_one_market_out' => true],
            'execution_adapter' => ['protocol' => 'reality_parity_execution_v1']];
    }

    public function selfKnowledge(array $result): array
    {
        $capability = (array) data_get($result, 'capability_vector', data_get($result, 'behavioral_diversity.capability_vector', []));
        $coverage = (float) data_get($result, 'opportunity_metrics.coverage', 0);
        $calibration = (float) data_get($result, 'statistical_evidence.edge_quality.confidence_calibration.score', 0);
        $drift = (float) data_get($result, 'market_adaptive_replay.adaptation.drift_score', 0);
        $unseen = data_get($result, 'permanent_unseen_challenge.status') === 'sealed';
        $knownness = max(0, min(100, ($coverage * 45) + ($calibration * .35) + ($unseen ? 10 : 0) - ($drift * 10)));
        return ['protocol' => 'epistemic_boundary_v1', 'knownness_score' => round($knownness, 2),
            'evidence_coverage' => round($coverage, 4), 'calibration_score' => round($calibration, 2), 'drift_score' => round($drift, 4),
            'unknown_state_action' => $knownness < 45 ? 'WAIT' : ($knownness < 70 ? 'REDUCE_RISK' : 'ALLOW_WITH_GUARDS'),
            'rule' => 'Unknown is not a failure; it is an explicit abstention/routing decision.'];
    }

    public function retention(?array $parent, array $current): array
    {
        $parentVector = (array) data_get($parent, 'capability_vector', []);
        $currentVector = (array) data_get($current, 'capability_vector', []);
        if ($parentVector === [] || $currentVector === []) return ['status' => 'baseline_unavailable'];
        $lost = collect($parentVector)->filter(fn ($score, $key) => is_numeric($score) && (float) $score >= 60
            && (float) data_get($currentVector, $key, 0) < (float) $score * .8)->keys()->values()->all();
        return ['status' => $lost === [] ? 'retained' : 'catastrophic_forgetting', 'lost_skills' => $lost,
            'rule' => 'A child must retain 80% of every parent skill that was already demonstrated at >=60.'];
    }

    public function certification(array $result, array $selfKnowledge, array $retention): array
    {
        $checks = [
            'in_domain_quality' => (float) data_get($result, 'profit_factor', 0) >= 1.30,
            'unseen_regime_quality' => (float) data_get($result, 'statistical_evidence.edge_quality.worst_regime_pf', 0) >= 1.0,
            'transition_quality' => (float) data_get($result, 'transition_homework.score', 0) >= 50,
            'cost_robustness' => (float) data_get($result, 'pf_attribution.stress_cost.profit_factor', 0) >= 1.05,
            'confidence_calibration' => (float) data_get($result, 'statistical_evidence.edge_quality.confidence_calibration.score', 0) >= 50,
            'ood_safety' => in_array($selfKnowledge['unknown_state_action'] ?? '', ['WAIT', 'REDUCE_RISK', 'ALLOW_WITH_GUARDS'], true),
            'old_skill_retention' => ($retention['status'] ?? '') === 'retained',
        ];
        $status = ($retention['status'] ?? '') === 'baseline_unavailable' ? 'waiting_for_retention_baseline'
            : (collect($checks)->every(fn ($pass) => $pass) ? 'passed' : 'insufficient');
        return ['protocol' => 'universal_certification_v1', 'status' => $status,
            'checks' => $checks, 'rule' => 'Certification is additional evidence; it never lowers or bypasses Forward/Paper gates.'];
    }

    /**
     * A core may cross a market boundary only as a prior.  This passport is
     * intentionally incomplete until a frozen leave-one-market-out replay is
     * supplied; it never copies champion or paper status.
     */
    public function transferPassport(ModelVersion $model, array $result): array
    {
        $core = (array) data_get($model->metadata, 'universal_genome.core', []);
        $local = (array) data_get($model->metadata, 'universal_genome.local_adapter', []);
        $assessments = (array) data_get($result, 'leave_one_market_out.assessments', []);
        $required = ['XAUUSD', 'EURUSD', 'GBPUSD'];
        $covered = collect($assessments)->pluck('unseen_market')->filter()->unique()->values()->all();
        $checks = [
            'core_present' => $core !== [],
            'local_adapter_present' => $local !== [],
            'leave_one_market_out_complete' => count(array_intersect($required, $covered)) === count($required),
            'no_transferred_promotion' => ! (bool) data_get($result, 'leave_one_market_out.transferred_champion_status', false),
        ];
        $status = ! $checks['core_present'] || ! $checks['local_adapter_present'] ? 'invalid_genome'
            : (! $checks['leave_one_market_out_complete'] ? 'waiting_for_leave_one_market_out'
                : (collect($checks)->every(fn ($pass) => $pass) ? 'assessed' : 'failed'));
        return [
            'protocol' => 'leave_one_market_out_transfer_v1', 'status' => $status, 'checks' => $checks,
            'covered_unseen_markets' => $covered, 'required_unseen_markets' => $required,
            'metrics' => ['transfer_gain' => null, 'adaptation_cost' => null, 'cross_market_regression' => null,
                'retention_score' => null, 'adapter_mutation_count' => null, 'time_to_safe_abstention' => null],
            'rule' => 'Only a frozen experiment may fill the metrics; absent evidence is never treated as transfer success.',
        ];
    }

    /** pass^k evidence ledger; a single replay pass is explicitly insufficient. */
    public function passKReliability(array $result): array
    {
        $checks = (array) data_get($result, 'pass_k_checks', []);
        $required = 54; // 3 seeds × 3 windows × 3 cost profiles × 2 epochs.
        $catastrophic = data_get($result, 'temporal_firewall.status') === 'failed'
            || data_get($result, 'secret_adversarial_arena.status') === 'failed';
        $passed = collect($checks)->filter(fn ($item) => data_get($item, 'passed') === true)->count();
        return [
            'protocol' => 'pass_k_reliability_v1', 'required_independent_checks' => $required,
            'observed_independent_checks' => count($checks), 'passed_checks' => $passed,
            'status' => $catastrophic ? 'catastrophic_failure' : (count($checks) < $required ? 'waiting_for_independent_checks'
                : ($passed === $required ? 'passed' : 'failed')),
            'rule' => 'A catastrophic execution, leakage or OOD failure invalidates elite reliability; it cannot be averaged away.',
        ];
    }

    public function skillAtlas(ModelMarketPerformance $performance, array $vector): array
    {
        $traits = collect($vector)->filter(fn ($v) => is_numeric($v))->map(fn ($v) => round((float) $v, 2))->all();
        return ['protocol' => 'quality_diversity_skill_atlas_v1', 'market' => $performance->symbol, 'timeframe' => $performance->timeframe,
            'agent_model_version_id' => $performance->model_version_id, 'capabilities' => $traits,
            'archive_key' => implode('|', collect($traits)->map(fn ($value, $key) => $key.':'.(int) floor($value / 20))->all()),
            'rule' => 'Archive diverse capability niches, not merely the highest global PF.'];
    }
}
