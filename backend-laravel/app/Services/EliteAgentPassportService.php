<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\ModelVersion;
use Illuminate\Support\Facades\File;

/**
 * Immutable, evidence-first manifest for a candidate approaching Forward.
 * It deliberately adds preflight checks; it never relaxes an existing gate.
 */
class EliteAgentPassportService
{
    public function __construct(private AgentConstitutionService $constitutions) {}

    public function build(ModelVersion $model, ?LabAgent $agent, array $result): array
    {
        $parameters = $model->parameters ?? [];
        ksort($parameters);
        $manifest = (array) data_get($result, 'data_manifest', []);
        $monthly = (array) data_get($result, 'monthly_passport', []);
        $redTeam = (array) data_get($result, 'red_team', []);
        $news = (array) data_get($redTeam, 'scenarios.news_window', []);
        $quorum = $this->eliteQuorum($result, $monthly, $redTeam);
        $constitution = $this->constitutions->verify($model, $result);
        $requiresEpistemicGate = (int) data_get($model->metadata, 'statistical_gate_version', 0) >= 3;
        $checks = [
            'signal_viability' => (int) data_get($result, 'entry_funnel.raw_strategy_signals', 0) > 0
                && (int) data_get($result, 'entry_funnel.accepted_entries', 0) > 0,
            'veto_regret' => array_key_exists('shadow_trade_count', (array) data_get($result, 'veto_regret', [])),
            'monthly_walk_forward' => (int) data_get($monthly, 'rolling_forward_wins', 0) >= 3
                && (int) data_get($monthly, 'failed_months', 0) === 0,
            'regime_coverage' => data_get($result, 'behavioral_diversity.status') !== 'near_duplicate'
                && (float) data_get($result, 'statistical_evidence.edge_quality.worst_regime_pf', 0) >= 1.0,
            'red_team_stress' => data_get($redTeam, 'scenarios.double_cost_execution.status') === 'assessed'
                && (bool) data_get($redTeam, 'scenarios.double_cost_execution.pass'),
            // The calendar must be explicitly aligned. "not assessed" is an
            // evidence gap, never silently treated as a pass.
            'calendar_alignment' => data_get($news, 'status') === 'assessed' && (bool) data_get($news, 'pass'),
            'data_manifest' => data_get($manifest, 'status') === 'ready' && filled(data_get($manifest, 'sha256')),
            'next_candle_execution' => str_contains((string) data_get($result, 'market_adaptive_replay.protocol', ''), 'next candle execution'),
            'sealed_holdout' => data_get($result, 'market_adaptive_replay.sealed_holdout.used_for_training') === false
                && data_get($result, 'market_adaptive_replay.sealed_holdout.used_for_evolution') === false,
            'secret_adversarial_arena' => data_get($result, 'secret_adversarial_arena.status') === 'passed',
            'temporal_firewall' => data_get($result, 'temporal_firewall.status') === 'passed'
                && data_get($result, 'permanent_unseen_challenge.status') === 'sealed',
            'elite_quorum' => $quorum['status'] === 'passed',
            'epistemic_boundary' => ! $requiresEpistemicGate || in_array(data_get($result, 'epistemic_boundary.unknown_state_action'), ['WAIT', 'REDUCE_RISK', 'ALLOW_WITH_GUARDS'], true),
            // Version 4 is reserved for a future population after the
            // independent cross-market harness exists. Existing v3 agents
            // remain subject to their unchanged gates, never grandfathered.
            'cross_market_transfer' => (int) data_get($model->metadata, 'statistical_gate_version', 0) < 4
                || data_get($result, 'cross_market_transfer_passport.status') === 'assessed',
            'pass_k_reliability' => (int) data_get($model->metadata, 'statistical_gate_version', 0) < 4
                || data_get($result, 'pass_k_reliability.status') === 'passed',
            'p0_failure_curriculum' => (int) data_get($model->metadata, 'statistical_gate_version', 0) < 4
                || (bool) data_get($result, 'failure_curriculum.p0_safety_passed', false),
        ];
        $failed = collect($checks)->filter(fn (bool $pass) => ! $pass)->keys()->map(
            fn (string $check) => 'FAILED_PASSPORT_'.strtoupper($check)
        )->values()->all();
        $parameterHash = hash('sha256', json_encode($parameters, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
        $resultHash = hash('sha256', json_encode($this->canonicalize($result), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));

        return [
            'protocol' => 'elite_agent_passport_v1',
            'status' => $failed === [] ? 'passed' : 'failed',
            'reason_codes' => $failed,
            'agent' => [
                'model_version_id' => $model->id, 'lab_agent_id' => $agent?->id,
                'parameters' => $parameters, 'parameter_hash' => $parameterHash,
                'strategy_architecture' => data_get($model->metadata, 'strategy_architecture'),
                'parent_a_model_version_id' => $agent?->parent_a_model_version_id,
                'parent_b_model_version_id' => $agent?->parent_b_model_version_id,
                'mutation_history' => $agent?->mutationMemories()->latest()->take(12)->get()->map(fn ($memory) => [
                    'parameter' => $memory->parameter_key, 'outcome' => $memory->outcome,
                    'gate_transition' => $memory->gate_transition,
                ])->all() ?? [],
            ],
            'data_manifest' => $manifest,
            'code_version' => $this->codeFingerprint(),
            'execution_assumptions' => data_get($result, 'execution_assumptions', []),
            'regime_performance' => data_get($result, 'regime_performance', []),
            'monthly_walk_forward' => $monthly,
            'veto_regret' => data_get($result, 'veto_regret', []),
            'stress_tests' => $redTeam,
            'secret_adversarial_arena' => data_get($result, 'secret_adversarial_arena', []),
            'temporal_firewall' => data_get($result, 'temporal_firewall', []),
            'elite_quorum' => $quorum,
            'agent_constitution' => $constitution,
            'calibration_status' => data_get($result, 'statistical_evidence.edge_quality.confidence_calibration', []),
            'epistemic_boundary' => data_get($result, 'epistemic_boundary', []),
            'trial_ledger' => data_get($result, 'trial_ledger', []),
            'cross_market_transfer_passport' => data_get($result, 'cross_market_transfer_passport', []),
            'pass_k_reliability' => data_get($result, 'pass_k_reliability', []),
            'preflight' => ['checks' => $checks, 'failed_checks' => $failed],
            'final_exam_result_hash' => $resultHash,
            'generated_at' => now()->utc()->toIso8601String(),
        ];
    }

    /** Freeze a near-forward parent; descendants must be separate model versions. */
    public function freezeIfFinalist(ModelVersion $model, array $passport, array $result): array
    {
        $nearForward = (float) data_get($result, 'profit_factor', 0) >= 1.30
            && (int) data_get($result, 'total_trades', 0) >= 24
            && (int) data_get($result, 'rolling_forward_wins', 0) >= 2
            && (float) data_get($result, 'max_drawdown_percent', 100) <= 15
            && (float) data_get($result, 'monte_carlo.risk_of_ruin_percent', 100) <= 10;
        if (! $nearForward) return $passport;

        $freeze = data_get($model->metadata, 'elite_agent_passport.freeze');
        if (! is_array($freeze)) {
            $freeze = [
                'status' => 'frozen', 'frozen_at' => now()->utc()->toIso8601String(),
                'parameter_hash' => data_get($passport, 'agent.parameter_hash'),
                'data_sha256' => data_get($passport, 'data_manifest.sha256'),
                'final_exam_result_hash' => data_get($passport, 'final_exam_result_hash'),
                'rule' => 'Final-exam observations cannot mutate this model; research continues only in a child fork.',
            ];
            $metadata = $model->metadata ?? [];
            data_set($metadata, 'elite_agent_passport.freeze', $freeze);
            $model->update(['metadata' => $metadata]);
        }
        $passport['freeze'] = $freeze;
        return $passport;
    }

    private function codeFingerprint(): array
    {
        $files = [
            base_path('app/Services/MarketChampionService.php'),
            base_path('app/Services/EliteAgentPassportService.php'),
            base_path('../ai-service-python/app/services/backtester.py'),
            base_path('../ai-service-python/app/services/market_adaptive_replay.py'),
        ];
        $hashes = collect($files)->filter(fn (string $file) => File::exists($file))->mapWithKeys(
            fn (string $file) => [basename($file) => hash_file('sha256', $file)]
        )->all();
        return ['protocol_version' => 1, 'files' => $hashes, 'sha256' => hash('sha256', json_encode($hashes))];
    }

    /** All independent final-exam lanes must retain the same edge claim. */
    private function eliteQuorum(array $result, array $monthly, array $redTeam): array
    {
        $checks = [
            'chronological_walk_forward' => (int) data_get($monthly, 'rolling_forward_wins', 0) >= 3
                && (int) data_get($monthly, 'failed_months', 0) === 0,
            'secret_adversarial_arena' => data_get($result, 'secret_adversarial_arena.status') === 'passed',
            'execution_cost_perturbation' => (float) data_get($result, 'pf_attribution.stress_cost.profit_factor', 0) >= 1.05
                && (bool) data_get($redTeam, 'scenarios.double_cost_execution.pass'),
            'temporal_firewall' => data_get($result, 'temporal_firewall.status') === 'passed',
        ];
        return ['status' => collect($checks)->every(fn (bool $pass) => $pass) ? 'passed' : 'failed', 'checks' => $checks,
            'rule' => 'Paper eligibility requires an independent chronological, execution, adversarial and leakage quorum.'];
    }

    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) if (is_array($item)) $value[$key] = $this->canonicalize($item);
        return $value;
    }
}
