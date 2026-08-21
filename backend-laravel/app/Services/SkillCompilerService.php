<?php

namespace App\Services;

use App\Models\CapabilitySkill;

/** Converts a causal research packet into a conditional capability, never a promotion. */
class SkillCompilerService
{
    public const PROTOCOL = 'capability_skill_compiler_v1';

    public function __construct(private CapabilityCellService $cells) {}

    /** @return array<string,mixed> */
    public function compile(array $packet): array
    {
        $windows = (int) data_get($packet, 'independent_windows.observed_windows', data_get($packet, 'independent_windows', 0));
        $positive = (int) data_get($packet, 'independent_windows.positive_windows', data_get($packet, 'positive_windows', 0));
        $control = (bool) data_get($packet, 'exact_control.paired_isolated', data_get($packet, 'exact_control.status') === 'available');
        $dataHash = (string) data_get($packet, 'data_hash', data_get($packet, 'exact_control.data_hash', ''));
        $executionHash = (string) data_get($packet, 'execution_hash', data_get($packet, 'exact_control.execution_hash', ''));
        $regression = (bool) data_get($packet, 'non_target_regression', false);
        $independent = (bool) data_get($packet, 'independent_confirmation', false);
        $confirmed = $control && $dataHash !== '' && $executionHash !== '' && $windows >= 3 && $positive >= 2 && ! $regression && $independent;
        $symbol = strtoupper((string) ($packet['symbol'] ?? 'UNKNOWN'));
        $timeframe = strtoupper((string) ($packet['timeframe'] ?? 'UNKNOWN'));
        $state = (string) ($packet['state_key'] ?? 'unknown');
        $key = hash('sha256', implode('|', [$symbol, $timeframe, $state, $packet['strategy_id'] ?? '', $packet['tactic_id'] ?? '', $dataHash, $executionHash]));
        $contract = ['protocol' => self::PROTOCOL, 'exact_control_required' => true, 'data_hash_required' => true, 'execution_hash_required' => true, 'required_windows' => 3, 'positive_windows_required' => 2, 'non_target_regression_forbidden' => true, 'independent_confirmation_required' => true, 'usable_for_routing' => $confirmed, 'promotion_evidence' => false];
        $cell = $this->cells->resolve($symbol, $timeframe, ['regime' => $packet['regime'] ?? explode('|', $state)[0], 'session' => $packet['session'] ?? 'unknown', 'regime_probability' => $packet['regime_probability'] ?? 0, 'transition_hazard' => $packet['transition_hazard'] ?? 0, 'state_confidence' => $packet['state_confidence'] ?? 0, 'posterior' => $packet['state_posterior'] ?? []], ['strategy_id' => $packet['strategy_id'] ?? null, 'tactic_id' => $packet['tactic_id'] ?? null, 'risk_regime' => $packet['risk_profile'] ?? 'normal', 'execution_environment' => $packet['execution_environment'] ?? 'normal']);
        $row = CapabilitySkill::updateOrCreate(['skill_key' => $key], ['capability_cell_id' => $cell['cell']->id, 'symbol' => $symbol, 'timeframe' => $timeframe, 'state_key' => $state, 'strategy_id' => $packet['strategy_id'] ?? null, 'tactic_id' => $packet['tactic_id'] ?? null, 'status' => $confirmed ? 'active' : 'provisional', 'data_hash' => $dataHash ?: null, 'execution_hash' => $executionHash ?: null, 'independent_windows' => $windows, 'positive_windows' => $positive, 'non_target_regression' => $regression, 'independently_confirmed' => $independent, 'contract' => $contract, 'evidence' => $packet, 'compiled_at' => now(), 'last_validated_at' => $confirmed ? now() : null, 'expires_at' => $confirmed ? now()->addDays(30) : null, 'reference_state_distribution' => (array) ($packet['state_posterior'] ?? []), 'current_state_distribution' => (array) ($packet['state_posterior'] ?? [])]);

        return ['status' => $row->status, 'skill_id' => $row->id, 'skill_key' => $row->skill_key, 'routing_eligible' => $confirmed, 'contract' => $contract];
    }
}
