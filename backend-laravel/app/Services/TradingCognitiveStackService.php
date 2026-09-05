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
        private OpportunityFunnelLedgerService $funnel,
        private EvidenceOrthogonalityService $orthogonality,
        private LocationAtlasService $locations,
        private RiskHysteresisControllerService $riskHysteresis,
        private VolumeProvenanceContractService $volumeProvenance,
        private SessionNewsStateMachineService $sessionNews,
        private ConfirmationEntryContractService $confirmationEntry,
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
        $passport = (array) data_get($agent, 'composition_passport', data_get($agent, 'smart_composition.composition_passport', []));
        $foundryComposition = (string) data_get($passport, 'protocol') === CompositionAuthorityKernelService::PROTOCOL;
        $symbol = strtoupper((string) ($route['symbol'] ?? $context['symbol'] ?? 'XAUUSD'));
        $timeframe = strtoupper((string) ($route['timeframe'] ?? $context['timeframe'] ?? 'H1'));
        $location = $this->locations->thesis((array) ($context['location'] ?? []));
        $signals = array_merge((array) data_get($strategy, 'strategy_library_contract.strategy_spec.required_values', []), (array) data_get($passport, 'decision_tools', []));
        $orthogonality = $this->orthogonality->assess($signals);
        $riskHysteresis = $this->riskHysteresis->persist($symbol, $timeframe, (array) ($context['risk_metrics'] ?? []));
        $volume = $this->volumeProvenance->compile((array) ($context['volume_provenance'] ?? []));
        $sessionNews = $this->sessionNews->compile([
            ...((array) ($context['session_news'] ?? [])),
            'news_state' => $context['news_state'] ?? data_get($passport, 'news_state', 'normal'),
            'session_state' => $context['session_state'] ?? data_get($passport, 'session_handoff_state', 'unclassified'),
        ]);
        $routeActionable = ($route['decision'] ?? 'ABSTAIN') === 'TRADE';
        $entry = $this->confirmationEntry->compile(
            (array) ($context['entry_contract'] ?? []),
            $routeActionable,
            (bool) ($context['entry_contract_required'] ?? false),
            (bool) ($context['entry_contract_attested'] ?? true),
        );
        $entryRequired = (bool) ($context['entry_contract_required'] ?? false);
        $entryFillRequired = $entryRequired
            || ($entry['protocol'] ?? null) === ConfirmationEntryContractService::PROTOCOL;
        $entryFill = (array) ($context['entry_fill_admission'] ?? [
            'allowed' => ! $entryFillRequired,
            'status' => $entryFillRequired ? 'missing' : 'not_applicable',
            'reason' => $entryFillRequired ? 'ENTRY_FILL_ADMISSION_REQUIRED' : null,
        ]);
        $entryAttested = (bool) ($entry['attested'] ?? false);
        $setupReason = ! $entryAttested
            ? (($entry['reason_codes'][0] ?? null) ?: 'ENTRY_CONTRACT_ATTESTATION_FAILED')
            : 'ENTRY_SETUP_MISSING';
        $checks = [
            'setup_detected' => ['passed' => $routeActionable && $entryAttested && (bool) data_get($entry, 'checks.setup'), 'rejected_reason' => $setupReason],
            'regime_allowed' => ! (bool) ($state['transition'] ?? false),
            'htf_direction_allowed' => ($state['regime'] ?? 'unknown') !== 'unknown' && (bool) data_get($entry, 'checks.context'),
            'location_valid' => ['passed' => ($foundryComposition ? (bool) $location['trigger_admissible'] : true) && (bool) data_get($entry, 'checks.location'), 'rejected_reason' => $location['rejection_reason'] ?? 'ENTRY_LOCATION_INVALID'],
            'confirmation_valid' => ['passed' => (bool) data_get($entry, 'checks.confirmation'), 'rejected_reason' => 'ENTRY_CONFIRMATION_MISSING'],
            'trigger_confirmed' => ['passed' => (bool) data_get($entry, 'checks.trigger'), 'rejected_reason' => 'ENTRY_TRIGGER_MISSING'],
            'invalidation_valid' => ['passed' => (bool) data_get($entry, 'checks.invalidation'), 'rejected_reason' => 'ENTRY_INVALIDATION_INVALID'],
            'reward_space_valid' => ['passed' => (bool) data_get($entry, 'checks.reward_space'), 'rejected_reason' => 'ENTRY_REWARD_SPACE_INSUFFICIENT'],
            'chase_valid' => ['passed' => (bool) data_get($entry, 'checks.chase'), 'rejected_reason' => 'ENTRY_CHASING_VETO'],
            'session_valid' => ! (bool) ($context['session_blocked'] ?? false) && ($sessionNews['session_handoff_state'] ?? 'unclassified') !== 'late_session_decay',
            'volatility_valid' => ($state['volatility'] ?? 'normal') !== 'extreme',
            'news_clear' => ! (bool) ($context['news_risk'] ?? $state['news_risk'] ?? false) && ! (bool) ($sessionNews['news_quarantined'] ?? false) && (bool) data_get($entry, 'checks.event'),
            'spread_and_cost_valid' => [
                'passed' => ($state['spread_state'] ?? 'normal') !== 'high' && (bool) ($entryFill['allowed'] ?? false),
                'rejected_reason' => (string) ($entryFill['reason'] ?? 'ENTRY_SPREAD_AND_COST_INVALID'),
            ],
            'risk_approved' => $preflight['approved'],
            'executed' => $preflight['approved'] && $routeActionable
                && (bool) ($entry['executable'] ?? false) && (bool) ($entryFill['allowed'] ?? false),
        ];
        $funnel = $this->funnel->record([
            'opportunity_key' => (string) ($context['opportunity_key'] ?? data_get($route, 'router_decision.decision_key') ?? ''),
            'symbol' => $symbol, 'timeframe' => $timeframe, 'composition_id' => data_get($passport, 'composition_id'),
            'checks' => $checks, 'evidence_snapshot' => ['market_state' => $state, 'location' => $location, 'entry_contract' => $entry, 'entry_fill_admission' => $entryFill, 'orthogonality' => $orthogonality, 'session_news' => $sessionNews, 'volume' => $volume],
            'available_at' => $context['available_at'] ?? data_get($route, 'router_decision.decided_at') ?? now(),
            'decided_at' => now(),
        ]);
        if ($foundryComposition) {
            $location = $this->locations->record($symbol, $timeframe, (array) ($context['location'] ?? []));
        }
        $decision = $preflight['approved'] && $funnel['decision'] === 'EXECUTE' ? 'TRADE' : 'WAIT';

        return [
            'protocol' => self::PROTOCOL,
            'decision' => $decision,
            'reason_codes' => array_values(array_unique(array_filter([...$preflight['reason_codes'], ...($entry['reason_codes'] ?? []), $funnel['rejected_reason']]))),
            'market_state_estimator' => [
                'state' => $state,
                'state_key' => $state['state_key'] ?? null,
                'quality' => $this->stateQuality($state, $context),
                'transition_hazard' => (bool) ($state['transition'] ?? false),
            ],
            'strategy_proposer' => $strategy,
            'tactic_executor' => $tactic,
            'causal_edge_accounting' => [
                'opportunity_funnel' => $funnel,
                'location_thesis' => $location,
                'confirmation_entry_contract' => $entry,
                'entry_fill_admission' => $entryFill,
                'evidence_orthogonality' => $orthogonality,
                'temporal_authority' => data_get($passport, 'temporal_owners'),
                'risk_hysteresis' => $riskHysteresis,
                'volume_provenance' => $volume,
                'session_news_contract' => $sessionNews,
                'trade_path_laboratory_required_after_entry' => $foundryComposition,
                'promotion_evidence' => false,
            ],
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
                'professional_research_playbooks' => data_get($strategy, 'professional_playbook_toolbox.active_study_set', []),
                'model_dispatch_policy' => data_get($strategy, 'professional_playbook_toolbox.model_dispatch_policy', []),
                'confirmation_entry_contract' => $entry,
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
            'control_flow' => ['observe', 'fingerprint', 'horizon_bind', 'location_thesis', 'propose', 'compose', 'setup', 'independent_confirmation', 'exact_trigger', 'logical_invalidation', 'reward_space_and_chase_gate', 'compile_tactic', 'evidence_orthogonality_gate', 'temporal_authority_check', 'opportunity_funnel', 'risk_hysteresis_veto', 'execute_or_wait', 'trade_path_counterfactual', 'settle', 'reflect', 'mutate_one_axis', 'paired_replay', 'consolidate'],
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
        return ['mode' => 'paired_control_learning', 'failure_action' => 'reflect_then_one_bounded_repair', 'required_metrics' => ['net_edge_after_cost', 'profit_factor', 'drawdown', 'temporal_survival', 'regime_coverage', 'non_target_regression', 'abstention_quality', 'mae', 'mfe', 'mfe_capture_ratio', 'process_outcome_quality', 'seven_block_operating_system'], 'independent_windows' => 3, 'positive_windows_required' => 2, 'exact_control_required' => true, 'confirmed_skill_requires_independent_confirmation' => true, 'promotion_evidence' => false];
    }

    private function innovationContract(array $agent): array
    {
        $requested = (bool) ($agent['innovation_allowed'] ?? false);
        $stage = (string) ($agent['mastery_stage'] ?? 'apprentice');
        $allowed = $requested && in_array($stage, ['validated_specialist', 'strategy_master_candidate', 'master'], true);

        return ['mode' => $allowed ? 'bounded_innovation_shadow' : 'curriculum_locked', 'max_new_instruments' => 1, 'max_changed_genes' => 1, 'requires_control_pair' => true, 'requires_behavior_delta' => true, 'failure_cemetery' => true, 'live_execution' => false, 'promotion_evidence' => false];
    }
}
