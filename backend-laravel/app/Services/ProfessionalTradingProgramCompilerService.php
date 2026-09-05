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
            ['module' => 'regime_detector', 'timeframe' => $roles['bias'], 'requires' => ['closed_candle'], 'provides' => 'RegimeEvidence'],
            ['module' => 'location_model', 'timeframe' => $roles['setup'], 'requires' => ['RegimeEvidence'], 'provides' => 'SetupLocation'],
            ['module' => 'setup_model', 'timeframe' => $roles['setup'], 'requires' => ['SetupLocation'], 'provides' => 'SetupCandidate'],
            ['module' => 'confirmation_engine', 'timeframe' => $roles['trigger'], 'requires' => ['SetupCandidate'], 'provides' => 'ConfirmationProof', 'families' => $families],
            ['module' => 'entry_model', 'timeframe' => $roles['execution'], 'requires' => ['ConfirmationProof'], 'provides' => 'ExecutableOrder'],
            ['module' => 'invalidation_model', 'timeframe' => $roles['invalidation'], 'requires' => ['ExecutableOrder'], 'provides' => 'TradeIdeaFailure', 'model' => $invalidation['invalidation_model']],
            ['module' => 'central_risk_governor', 'timeframe' => $roles['execution'], 'requires' => ['ExecutableOrder', 'TradeIdeaFailure'], 'provides' => 'RiskAuthorizedOrder', 'strategy_override_allowed' => false],
            ['module' => 'management_policy', 'timeframe' => data_get($roles, 'management.initial'), 'requires' => ['RiskAuthorizedOrder'], 'provides' => 'ExitPolicy'],
        ];
        $provided = ['closed_candle' => true];
        foreach ($nodes as $node) {
            foreach ($node['requires'] as $type) if (! isset($provided[$type])) {
                throw new \InvalidArgumentException('TYPED_PROGRAM_DEPENDENCY_UNSATISFIED:'.$node['module'].':'.$type);
            }
            $provided[$node['provides']] = true;
        }
        $identity = ['components' => $components, 'roles' => $roles, 'families' => $families,
            'invalidation' => $invalidation['invalidation_model'], 'target' => $invalidation['target_model']];

        return ['protocol' => self::PROTOCOL, 'status' => 'typed_program_compiled',
            'program_id' => 'xau-program-'.substr(hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES)), 0, 24),
            'nodes' => $nodes, 'compatibility_graph' => ['type_chain_verified' => true,
                'independent_confirmation_families' => $families, 'risk_owner' => 'CentralRiskGovernor',
                'strategy_risk_override_allowed' => false, 'invalidation_required' => true],
            'promotion_evidence' => false];
    }
}
