<?php

namespace App\Services;

use App\Models\CapabilityCell;

class CapabilityCellService
{
    /** @return array<string,mixed> */
    public function resolve(string $symbol, string $timeframe, array $state, array $context = []): array
    {
        $posterior = (array) ($state['posterior'] ?? []);
        $regime = (string) ($state['regime'] ?? array_key_first($posterior) ?? 'unknown');
        $session = (string) ($state['session'] ?? 'unknown');
        $risk = (string) ($context['risk_regime'] ?? ((float) ($state['transition_hazard'] ?? 0) >= .4 ? 'reduce_only' : 'normal'));
        $execution = (string) ($context['execution_environment'] ?? (($state['spread_state'] ?? 'unknown') === 'high' ? 'degraded' : 'normal'));
        $key = hash('sha256', implode('|', [strtoupper($symbol), strtoupper($timeframe), $regime, $session, $context['strategy_id'] ?? '', $context['tactic_id'] ?? '', $risk, $execution]));
        $cell = CapabilityCell::updateOrCreate(['cell_key' => $key], ['symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe), 'regime' => $regime, 'session' => $session, 'strategy_id' => $context['strategy_id'] ?? null, 'tactic_id' => $context['tactic_id'] ?? null, 'risk_regime' => $risk, 'execution_environment' => $execution, 'regime_probability' => (float) ($state['regime_probability'] ?? ($posterior[$regime] ?? 0)), 'transition_hazard' => (float) ($state['transition_hazard'] ?? 0), 'state_confidence' => (float) ($state['state_confidence'] ?? 0), 'state_posterior' => $posterior]);

        return ['cell' => $cell, 'cell_key' => $key, 'transfer_requires_independent_evidence' => true, 'promotion_evidence' => false];
    }
}
