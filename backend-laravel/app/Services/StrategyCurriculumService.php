<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\ModelVersion;
use App\Models\StrategyCurriculumContract;
use App\Models\StrategyInnovationTrial;
use App\Models\StrategyMasterPassport;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Binds each research agent to a bounded strategy lane before it can innovate. */
class StrategyCurriculumService
{
    public const PROTOCOL = 'strategy_mastery_curriculum_v1';

    public function enroll(ModelVersion $model, ?LabAgent $agent = null): StrategyCurriculumContract
    {
        $definition = $this->definition($model->strategy);
        $key = $agent ? "lab-agent:{$agent->id}:curriculum" : "model-version:{$model->id}:curriculum";

        return StrategyCurriculumContract::updateOrCreate(['contract_key' => $key], [
            'lab_agent_id' => $agent?->id, 'model_version_id' => $model->id, 'strategy_id' => $definition['strategy_id'],
            'strategy_version' => 'v1', 'tactic_id' => $definition['tactic_id'], 'mastery_lane' => $definition['mastery_lane'], 'training_stage' => 'apprentice', 'tactic_mastery_stage' => 'tactic_seed',
            'allowed_instruments' => $definition['allowed_instruments'], 'forbidden_instruments' => $definition['forbidden_instruments'],
            'target_regimes' => $definition['target_regimes'], 'target_sessions' => $definition['target_sessions'],
            'entry_contract' => $definition['entry_contract'], 'exit_contract' => $definition['exit_contract'], 'sizing_contract' => ['method' => 'volatility_scaled_fractional', 'capped_fractional' => true], 'risk_contract' => ['martingale' => 'forbidden', 'full_kelly' => 'forbidden', 'risk_sentinel_veto' => true],
            'control_contract' => ['protocol' => self::PROTOCOL, 'paired_isolated' => true, 'promotion_evidence' => false],
            'control_pair_key' => "{$key}:control",
            'innovation_budget' => 0, 'state' => 'active',
        ]);
    }

    public function assessMaster(ModelVersion $model, array $metrics): StrategyMasterPassport
    {
        $curriculum = $this->enroll($model);
        $checks = [
            'protocol_adherence' => (bool) ($metrics['protocol_adherence'] ?? false),
            'target_regime_coverage' => (float) ($metrics['target_regime_coverage'] ?? 0) >= .5,
            'net_edge_after_cost' => (float) ($metrics['net_edge_after_cost'] ?? 0) > 0,
            'temporal_survival' => (bool) ($metrics['temporal_survival'] ?? false),
            'non_target_regression' => (float) ($metrics['non_target_regression'] ?? 1) <= 0,
            'abstention_quality' => (float) ($metrics['abstention_quality'] ?? 0) >= .5,
            'incremental_lift' => (float) ($metrics['incremental_lift'] ?? 0) > 0,
            'independent_windows' => (int) ($metrics['independent_windows'] ?? 0) >= 3,
        ];
        $passed = ! in_array(false, $checks, true);

        return StrategyMasterPassport::updateOrCreate(['model_version_id' => $model->id], [
            'strategy_id' => $curriculum->strategy_id, 'mastery_stage' => $passed ? 'strategy_master_candidate' : 'specialist',
            'status' => $passed ? 'validated' : 'provisional', 'target_regimes' => $curriculum->target_regimes,
            'metrics' => $metrics, 'evidence' => ['protocol' => self::PROTOCOL, 'checks' => $checks, 'promotion_evidence' => false], 'assessed_at' => now(),
        ]);
    }

    public function proposeInnovation(StrategyCurriculumContract $curriculum, array $instrumentKeys, array $behaviorContract): StrategyInnovationTrial
    {
        if ($curriculum->innovation_budget < 1 || $curriculum->training_stage !== 'validated_specialist') {
            throw new InvalidArgumentException('Bounded innovation faqat validated specialist uchun ochiladi.');
        }
        if (array_diff($instrumentKeys, (array) $curriculum->allowed_instruments) || array_intersect($instrumentKeys, (array) $curriculum->forbidden_instruments)) {
            throw new InvalidArgumentException('Innovation curriculum instrument envelope-dan chiqdi.');
        }
        if (! (bool) ($behaviorContract['trade_set_changed'] ?? false)) {
            throw new InvalidArgumentException('Innovation trial measurable behavior delta talab qiladi.');
        }

        return StrategyInnovationTrial::create([
            'trial_key' => (string) Str::uuid(), 'lab_agent_id' => $curriculum->lab_agent_id, 'status' => 'innovation_trial',
            'instrument_keys' => array_values($instrumentKeys),
            'control_contract' => ['protocol' => self::PROTOCOL, 'paired_isolated' => true, 'promotion_evidence' => false],
            'behavior_contract' => $behaviorContract, 'evidence' => ['promotion_evidence' => false],
        ]);
    }

    private function definition(string $strategy): array
    {
        $family = app(StrategyParameterSchemaService::class)->family($strategy);

        $definition = match ($family) {
            'fibonacci_structure_pullback' => ['strategy_id' => $family, 'mastery_lane' => 'fibonacci_structure', 'allowed_instruments' => ['dynamic_fibonacci_zone', 'confirmed_swing', 'liquidity_sweep', 'm15_rejection', 'atr_risk_envelope'], 'forbidden_instruments' => ['range_reentry', 'random_architecture_mix'], 'target_regimes' => ['trend_up', 'trend_down'], 'target_sessions' => ['london', 'london_new_york_overlap']],
            'bos_retest_continuation' => ['strategy_id' => $family, 'mastery_lane' => 'structure', 'allowed_instruments' => ['bos_event', 'confirmed_swing', 'volume_confirmation', 'atr_risk_envelope'], 'forbidden_instruments' => ['random_architecture_mix'], 'target_regimes' => ['trend_up', 'trend_down'], 'target_sessions' => ['london', 'london_new_york_overlap']],
            'choch_reversal' => ['strategy_id' => $family, 'mastery_lane' => 'transition', 'allowed_instruments' => ['choch_event', 'liquidity_sweep', 'transition_wait', 'atr_risk_envelope'], 'forbidden_instruments' => ['breakout_continuation'], 'target_regimes' => ['transition'], 'target_sessions' => ['london', 'new_york']],
            'liquidity_sweep_reversion' => ['strategy_id' => $family, 'mastery_lane' => 'liquidity', 'allowed_instruments' => ['support_resistance_zone', 'liquidity_pool', 'liquidity_sweep', 'cost_firewall'], 'forbidden_instruments' => ['trend_pullback'], 'target_regimes' => ['range'], 'target_sessions' => ['london', 'new_york']],
            default => ['strategy_id' => $family, 'mastery_lane' => 'core_strategy', 'allowed_instruments' => ['atr_risk_envelope', 'cost_aware_exit'], 'forbidden_instruments' => ['random_architecture_mix'], 'target_regimes' => ['trend_up', 'trend_down', 'range'], 'target_sessions' => ['london', 'new_york']],
        };

        return $definition + [
            'tactic_id' => match ($family) {
                'fibonacci_structure_pullback' => 'liquidity_sweep_rejection',
                'bos_retest_continuation' => 'bos_retest_entry',
                'choch_reversal' => 'choch_reversal_entry',
                'liquidity_sweep_reversion' => 'liquidity_sweep_entry',
                default => 'core_execution',
            },
            'entry_contract' => ['type' => match ($family) {
                'fibonacci_structure_pullback' => 'fibonacci_zone_rejection',
                'bos_retest_continuation' => 'breakout_retest',
                'choch_reversal' => 'choch_reversal',
                'liquidity_sweep_reversion' => 'liquidity_sweep_rejection',
                default => 'market_confirmation',
            }],
            'exit_contract' => ['stop' => $family === 'fibonacci_structure_pullback' ? 'structure_invalidation' : 'atr', 'target' => $family === 'fibonacci_structure_pullback' ? 'partial_r_then_trailing' : 'atr'],
        ];
    }
}
