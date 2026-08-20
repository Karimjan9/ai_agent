<?php

namespace App\Services;

use App\Models\PlaybookComposition;

/**
 * Strategy brain: chooses a conditional thesis and an instrument lane.
 * It has no authority over executable risk, sizing, promotion, or data policy.
 */
class StrategyProposerService
{
    public const PROTOCOL = 'strategy_proposer_brain_v1';

    /** @return array<string,mixed> */
    public function propose(array $route, array $context = [], array $agent = []): array
    {
        $playbook = $route['playbook'] ?? null;
        $playbookKey = $playbook instanceof PlaybookComposition ? (string) $playbook->playbook_key : null;
        $strategyId = (string) ($agent['strategy_id'] ?? $this->strategyFor($playbookKey));
        $masteryStage = (string) ($agent['mastery_stage'] ?? 'apprentice');
        $abstention = ($route['decision'] ?? 'ABSTAIN') !== 'TRADE';
        $innovationAllowed = (bool) ($agent['innovation_allowed'] ?? false);
        $validated = in_array($masteryStage, ['validated_specialist', 'strategy_master_candidate', 'master'], true);

        return [
            'protocol' => self::PROTOCOL,
            'status' => $abstention ? 'WAIT_THESIS' : 'CONDITIONAL_THESIS',
            'strategy_id' => $strategyId,
            'playbook_key' => $playbookKey,
            'mastery_stage' => $masteryStage,
            'hypothesis' => $this->hypothesis($playbookKey, $route['state'] ?? []),
            'state_scope' => [
                'regime' => data_get($route, 'state.regime', 'unknown'),
                'm15_regime' => data_get($route, 'state.m15_regime', 'unknown'),
                'session' => data_get($route, 'state.session', 'unknown'),
                'volatility' => data_get($route, 'state.volatility', 'unknown'),
            ],
            'selected_instruments' => array_values((array) ($playbook?->instrument_keys ?? [])),
            'alternatives' => collect((array) ($route['candidates'] ?? []))->map(fn (array $candidate): array => [
                'playbook_key' => $candidate['playbook_key'] ?? null,
                'instrument_keys' => array_values((array) ($candidate['instrument_keys'] ?? [])),
                'score' => $candidate['score'] ?? null,
                'observations' => $candidate['observations'] ?? 0,
                'abstention' => (bool) ($candidate['abstention'] ?? false),
            ])->values()->all(),
            'innovation_lane' => [
                'requested' => $innovationAllowed,
                'status' => $innovationAllowed && $validated ? 'BOUNDED_SHADOW_ALLOWED' : 'CURRICULUM_LOCKED',
                'max_new_relations' => 1,
                'max_changed_axis' => 1,
                'allowed_axes' => ['entry_topology', 'confirmation_order', 'exit_policy', 'state_filter', 'cost_filter'],
                'requires_behavior_delta' => true,
            ],
            'cannot' => ['set_position_size', 'raise_risk', 'override_risk_sentinel', 'create_promotion_evidence', 'use_future_training_data'],
            'evidence_contract' => $this->evidenceContract(),
        ];
    }

    private function strategyFor(?string $playbookKey): string
    {
        return match (true) {
            str_contains((string) $playbookKey, 'fibonacci') => 'fibonacci_structure_pullback',
            str_contains((string) $playbookKey, 'bos_retest') => 'bos_retest_continuation',
            str_contains((string) $playbookKey, 'choch') => 'choch_reversal',
            str_contains((string) $playbookKey, 'liquidity') => 'liquidity_sweep_reversion',
            str_contains((string) $playbookKey, 'range') => 'mean_reversion',
            str_contains((string) $playbookKey, 'breakout') => 'breakout',
            str_contains((string) $playbookKey, 'compression') => 'volatility',
            default => 'regime_conditional_hybrid',
        };
    }

    private function hypothesis(?string $playbookKey, array $state): string
    {
        if ($playbookKey === null) {
            return 'No eligible playbook; preserve capital and collect a diagnostic observation.';
        }

        return sprintf('%s is conditionally useful in %s/%s/%s after cost and execution friction.', $playbookKey, $state['regime'] ?? 'unknown', $state['session'] ?? 'unknown', $state['volatility'] ?? 'unknown');
    }

    private function evidenceContract(): array
    {
        return [
            'paired_isolated_control' => true,
            'same_generation' => true,
            'same_data_hash' => true,
            'same_execution_hash' => true,
            'independent_windows' => 3,
            'positive_windows_required' => 2,
            'non_target_regression_required' => true,
            'promotion_evidence' => false,
        ];
    }
}
