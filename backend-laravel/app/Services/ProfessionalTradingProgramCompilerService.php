<?php

namespace App\Services;

/**
 * Compiles one frozen XAUUSD research composition as a typed trading program.
 * It is a static compatibility boundary: it does not score, route, promote,
 * or create a trade. Runtime evidence remains the sole source of edge.
 */
class ProfessionalTradingProgramCompilerService
{
    public const PROTOCOL = 'xauusd_typed_trading_program_v1';

    /** @return array<string,mixed> */
    public function compile(array $proposal, array $components, array $temporal, array $invalidation, array $management): array
    {
        if (strtoupper((string) ($proposal['symbol'] ?? 'XAUUSD')) !== 'XAUUSD') {
            throw new \InvalidArgumentException('TYPED_PROGRAM_XAUUSD_SCOPE_REQUIRED');
        }
        if ((bool) ($proposal['strategy_override_allowed'] ?? false)) {
            throw new \InvalidArgumentException('TYPED_PROGRAM_STRATEGY_RISK_OVERRIDE_FORBIDDEN');
        }
        $roles = (array) data_get($temporal, 'roles', []);
        $requiredRoles = ['bias', 'setup', 'trigger', 'execution', 'invalidation', 'management'];
        $missing = array_values(array_filter($requiredRoles, fn (string $role): bool => empty($roles[$role])));
        if ($missing !== []) throw new \InvalidArgumentException('TYPED_PROGRAM_TEMPORAL_ROLE_MISSING:'.implode(',', $missing));
        if (! filled(data_get($invalidation, 'invalidation_model')) || ! filled(data_get($invalidation, 'target_model'))) {
            throw new \InvalidArgumentException('TYPED_PROGRAM_INVALIDATION_CONTRACT_REQUIRED');
        }
        if (empty($management['state_machine'])) throw new \InvalidArgumentException('TYPED_PROGRAM_EXIT_POLICY_REQUIRED');

        $families = array_values(array_unique(array_filter((array) ($proposal['confirmation_families'] ?? [
            'market_structure', 'price_reaction', 'volatility_participation',
        ]), 'is_string')));
        if (count($families) < 2) throw new \InvalidArgumentException('TYPED_PROGRAM_INDEPENDENT_CONFIRMATION_FAMILIES_REQUIRED');
        $nodes = [
            ['module' => 'mtf_context_gate', 'timeframe' => $roles['bias'], 'requires' => ['InstrumentAssignment', 'closed_candle'], 'provides' => 'DecisionContext', 'veto_codes' => ['mtf_not_ready', 'market_context_not_ready']],
            ['module' => 'regime_detector', 'timeframe' => $roles['bias'], 'requires' => ['DecisionContext'], 'provides' => 'RegimeEvidence', 'veto_codes' => ['regime_unclassified']],
            ['module' => 'strategy_runtime', 'timeframe' => $roles['execution'], 'requires' => ['DecisionContext', 'RegimeEvidence'], 'provides' => 'SignalIntent', 'veto_codes' => ['no_strategy_signal']],
            ['module' => 'tactic_runtime', 'timeframe' => $roles['execution'], 'requires' => ['DecisionContext', 'SignalIntent'], 'provides' => 'TacticDecision', 'veto_codes' => ['tactic_scope_mismatch']],
            ['module' => 'location_model', 'timeframe' => $roles['setup'], 'requires' => ['DecisionContext', 'RegimeEvidence', 'TacticDecision'], 'provides' => 'SetupLocation', 'veto_codes' => ['h1_location_missing', 'atr_invalid']],
            ['module' => 'setup_model', 'timeframe' => $roles['setup'], 'requires' => ['SetupLocation'], 'provides' => 'SetupCandidate', 'veto_codes' => ['m15_setup_missing']],
            ['module' => 'confirmation_engine', 'timeframe' => $roles['trigger'], 'requires' => ['SetupCandidate'], 'provides' => 'ConfirmationProof', 'families' => $families, 'veto_codes' => ['m15_direction_mismatch']],
            ['module' => 'entry_model', 'timeframe' => $roles['execution'], 'requires' => ['ConfirmationProof', 'TacticDecision'], 'provides' => 'EntryIntent', 'veto_codes' => ['entry_gate_rejected']],
            ['module' => 'invalidation_model', 'timeframe' => $roles['invalidation'], 'requires' => ['EntryIntent'], 'provides' => 'TradeIdeaFailure', 'model' => $invalidation['invalidation_model'], 'veto_codes' => ['invalidation_invalid']],
            ['module' => 'instrument_context_gate', 'timeframe' => $roles['execution'], 'requires' => ['EntryIntent', 'InstrumentAssignment'], 'provides' => 'InstrumentContextReceipt', 'veto_codes' => ['instrument_context_outside_scope']],
            ['module' => 'mtf_permission_gate', 'timeframe' => $roles['execution'], 'requires' => ['DecisionContext', 'InstrumentContextReceipt'], 'provides' => 'MtfPermissionReceipt', 'veto_codes' => ['mtf_permission_rejected']],
            ['module' => 'central_risk_governor', 'timeframe' => $roles['execution'], 'requires' => ['EntryIntent', 'TradeIdeaFailure', 'MtfPermissionReceipt'], 'provides' => 'RiskAuthorizedOrder', 'strategy_override_allowed' => false, 'veto_codes' => ['risk_rejected']],
            ['module' => 'order_execution', 'timeframe' => $roles['execution'], 'requires' => ['RiskAuthorizedOrder'], 'provides' => 'FillReceipt', 'veto_codes' => ['order_not_filled']],
            ['module' => 'management_policy', 'timeframe' => data_get($roles, 'management.initial'), 'requires' => ['FillReceipt'], 'provides' => 'ManagementReceipt', 'veto_codes' => ['management_not_reached']],
        ];
        $provided = ['InstrumentAssignment' => true, 'closed_candle' => true];
        foreach ($nodes as $node) {
            foreach ($node['requires'] as $type) if (! isset($provided[$type])) {
                throw new \InvalidArgumentException('TYPED_PROGRAM_DEPENDENCY_UNSATISFIED:'.$node['module'].':'.$type);
            }
            $provided[$node['provides']] = true;
        }
        $identity = ['components' => $components, 'roles' => $roles, 'families' => $families,
            'invalidation' => $invalidation['invalidation_model'], 'target' => $invalidation['target_model']];

        return ['protocol' => self::PROTOCOL, 'status' => 'typed_program_compiled',
            'runtime_protocol' => 'xauusd_executable_composition_program_v2',
            'program_id' => 'xau-program-'.substr(hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES)), 0, 24),
            'nodes' => $nodes, 'compatibility_graph' => ['type_chain_verified' => true,
                'independent_confirmation_families' => $families, 'risk_owner' => 'CentralRiskGovernor',
                'strategy_risk_override_allowed' => false, 'invalidation_required' => true],
            'promotion_evidence' => false];
    }
}
