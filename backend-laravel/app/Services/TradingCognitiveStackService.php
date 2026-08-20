<?php

namespace App\Services;

/**
 * The immutable hand-off between market interpretation, strategy, tactic,
 * risk, and learning. It is intentionally a plan/contract, never a promotion API.
 */
class TradingCognitiveStackService
{
    public const PROTOCOL = 'trading_cognitive_stack_v1';

    public function __construct(
        private TradingInstrumentOperatingSystemService $instruments,
        private StrategyProposerService $strategies,
        private TacticExecutorService $tactics,
    ) {}

    /** @return array<string,mixed> */
    public function plan(string $symbol, string $timeframe, array $context = [], array $agent = []): array
    {
        return $this->planFromRoute($this->instruments->route($symbol, $timeframe, $context), $context, $agent);
    }

    /** @return array<string,mixed> */
    public function planFromRoute(array $route, array $context = [], array $agent = []): array
    {
        $state = (array) ($route['state'] ?? []);
        $strategy = $this->strategies->propose($route, $context, $agent);
        $tactic = $this->tactics->compile($route, $strategy, $context);
        $preflight = $this->preflight($route, $state, $context);
        $decision = $preflight['approved'] && ($route['decision'] ?? 'ABSTAIN') === 'TRADE' ? 'TRADE' : 'WAIT';

        return [
            'protocol' => self::PROTOCOL,
            'decision' => $decision,
            'reason_codes' => $preflight['reason_codes'],
            'market_state_estimator' => [
                'state' => $state,
                'state_key' => $state['state_key'] ?? null,
                'quality' => $this->stateQuality($state, $context),
                'transition_hazard' => (bool) ($state['transition'] ?? false),
            ],
            'strategy_proposer' => $strategy,
            'tactic_executor' => $tactic,
            'risk_sentinel' => [
                'approved_preflight' => $preflight['approved'],
                'reason_codes' => $preflight['reason_codes'],
                'authority' => 'final_veto_and_executable_sizing',
                'sizing_policy' => 'capped_fractional_uncertainty_shrunk',
                'guards' => ['martingale' => 'forbidden', 'full_kelly' => 'forbidden', 'geometric_compounding' => 'paper_research_only', 'risk_increase_after_loss' => 'forbidden'],
            ],
            'instrument_composer' => [
                'playbook_key' => $route['playbook']?->playbook_key,
                'instrument_keys' => array_values((array) ($route['playbook']?->instrument_keys ?? [])),
                'candidate_control_comparison' => true,
                'composition_posterior_required' => true,
            ],
            'learning_reflector' => $this->learningContract(),
            'innovation_manager' => $this->innovationContract($agent),
            'council_governor' => [
                'compare_selected_and_alternatives' => true,
                'same_state_required' => true,
                'same_data_hash_required' => true,
                'same_execution_hash_required' => true,
                'winner_can_only_route_within_existing_gate' => true,
            ],
            'invariants' => [
                'promotion_evidence' => false,
                'strategy_cannot_set_risk' => true,
                'tactic_cannot_override_risk' => true,
                'abstention_is_valid_output' => true,
                'future_training_data_forbidden_on_h1_m15' => [2026],
                'future_year_data_mode' => 'paper_only',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function brainContract(): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'brains' => ['market_state_estimator', 'strategy_proposer', 'instrument_composer', 'tactic_executor', 'risk_sentinel', 'execution_quality_monitor', 'learning_reflector', 'innovation_manager', 'council_governor'],
            'control_flow' => ['observe', 'fingerprint', 'propose', 'compose', 'compile_tactic', 'risk_veto', 'execute_or_wait', 'settle', 'reflect', 'mutate_one_axis', 'paired_replay', 'consolidate'],
            'authority_order' => ['data_integrity', 'risk_sentinel', 'execution_contract', 'strategy', 'tactic', 'innovation'],
            'promotion_evidence' => false,
        ];
    }

    private function preflight(array $route, array $state, array $context): array
    {
        $reasons = [];
        if (($route['decision'] ?? 'ABSTAIN') !== 'TRADE') {
            $reasons[] = (string) ($route['reason_code'] ?? 'ROUTER_ABSTAIN');
        }
        if (array_key_exists('feed_healthy', $context) && ! (bool) $context['feed_healthy']) {
            $reasons[] = 'FEED_NOT_HEALTHY';
        }
        if ((bool) ($context['news_risk'] ?? $state['news_risk'] ?? false)) {
            $reasons[] = 'NEWS_RISK';
        }
        if ((bool) ($state['transition'] ?? false)) {
            $reasons[] = 'TRANSITION_HAZARD';
        }
        if (($state['spread_state'] ?? 'normal') === 'high') {
            $reasons[] = 'COST_FIREWALL';
        }
        if ((float) ($context['daily_loss_percent'] ?? 0) >= (float) config('services.risk.daily_loss_limit_percent', 2)) {
            $reasons[] = 'DAILY_LOSS_LIMIT';
        }
        if ((float) ($context['drawdown_percent'] ?? 0) >= (float) config('services.risk.sentinel_max_drawdown_percent', 15)) {
            $reasons[] = 'MAX_DRAWDOWN_REACHED';
        }
        if ((float) ($context['risk_of_ruin_percent'] ?? 0) > (float) config('services.risk.sentinel_max_risk_of_ruin_percent', 10)) {
            $reasons[] = 'RISK_OF_RUIN_LIMIT';
        }

        return ['approved' => $reasons === [], 'reason_codes' => array_values(array_unique($reasons))];
    }

    private function stateQuality(array $state, array $context): string
    {
        if (($state['regime'] ?? 'unknown') === 'unknown' || ($state['spread_atr_ratio'] ?? null) === null) {
            return 'insufficient';
        }
        if ((bool) ($state['transition'] ?? false) || ($state['spread_state'] ?? '') === 'high') {
            return 'hazardous';
        }

        return array_key_exists('feed_healthy', $context) && ! $context['feed_healthy'] ? 'stale' : 'usable';
    }

    private function learningContract(): array
    {
        return ['mode' => 'paired_control_learning', 'failure_action' => 'reflect_then_one_bounded_repair', 'required_metrics' => ['net_edge_after_cost', 'profit_factor', 'drawdown', 'temporal_survival', 'regime_coverage', 'non_target_regression', 'abstention_quality', 'mae', 'mfe'], 'independent_windows' => 3, 'positive_windows_required' => 2, 'exact_control_required' => true, 'confirmed_skill_requires_independent_confirmation' => true, 'promotion_evidence' => false];
    }

    private function innovationContract(array $agent): array
    {
        $requested = (bool) ($agent['innovation_allowed'] ?? false);
        $stage = (string) ($agent['mastery_stage'] ?? 'apprentice');
        $allowed = $requested && in_array($stage, ['validated_specialist', 'strategy_master_candidate', 'master'], true);

        return ['mode' => $allowed ? 'bounded_innovation_shadow' : 'curriculum_locked', 'max_new_instruments' => 1, 'max_changed_genes' => 1, 'requires_control_pair' => true, 'requires_behavior_delta' => true, 'failure_cemetery' => true, 'live_execution' => false, 'promotion_evidence' => false];
    }
}
