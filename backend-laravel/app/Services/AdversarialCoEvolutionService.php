<?php

namespace App\Services;

use App\Models\LabAdversarialScenario;
use App\Models\LabAgent;
use Illuminate\Support\Facades\Schema;

/**
 * Bounded market-adversary population. A scenario is only a planned stress
 * hypothesis until the existing sealed red-team replay provides evidence.
 */
class AdversarialCoEvolutionService
{
    public const PROTOCOL = 'bounded_adversarial_coevolution_v1';

    /** @return array<int, array<string, mixed>> */
    public function plan(LabAgent $agent): array
    {
        if (! $this->available()) return [];
        $types = ['spread_2x', 'slippage_shock', 'delayed_execution', 'false_bos', 'volatility_transition', 'regime_boundary', 'calendar_shift', 'missed_candle', 'correlated_losses', 'prolonged_chop', 'rare_tail_event'];
        return collect($types)->map(function (string $type) use ($agent): array {
            $bounds = $this->historicalBounds($type);
            $key = hash('sha256', json_encode([self::PROTOCOL, $agent->id, $type, $bounds], JSON_UNESCAPED_SLASHES));
            $row = LabAdversarialScenario::query()->firstOrCreate(['scenario_key' => $key], [
                'symbol' => strtoupper($agent->symbol), 'timeframe' => strtoupper($agent->timeframe), 'strategy_family' => $agent->strategy_family,
                'lab_agent_id' => $agent->id, 'scenario_type' => $type, 'historical_bounds' => $bounds,
                'novelty_score' => 1, 'realism_score' => 1, 'failure_discovery_score' => 0, 'status' => 'planned',
                'result' => ['protocol' => self::PROTOCOL, 'same_snapshot_required' => true, 'lookahead_forbidden' => true, 'historical_bound_required' => true, 'promotion_evidence' => false],
            ]);
            return ['id' => $row->id, 'type' => $type, 'status' => $row->status, 'historical_bounds' => $bounds];
        })->all();
    }

    /** @return array<string, mixed> */
    public function settle(string $scenarioKey, array $sealedResult): array
    {
        if (! $this->available()) return ['status' => 'migration_pending', 'promotion_evidence' => false];
        $scenario = LabAdversarialScenario::query()->where('scenario_key', $scenarioKey)->first();
        if (! $scenario) return ['status' => 'missing', 'promotion_evidence' => false];
        $valid = (bool) data_get($sealedResult, 'independent_snapshot') && (bool) data_get($sealedResult, 'holdout_replayed') && (bool) data_get($sealedResult, 'lookahead_free');
        $realistic = (bool) data_get($sealedResult, 'within_historical_bounds');
        $failure = max(0.0, min(1.0, (float) data_get($sealedResult, 'damage_score', 0)));
        $scenario->update(['status' => $valid && $realistic ? 'completed' : 'blocked', 'realism_score' => $realistic ? 1 : 0,
            'failure_discovery_score' => $failure, 'result' => ['protocol' => self::PROTOCOL, ...$sealedResult, 'valid' => $valid, 'realistic' => $realistic, 'promotion_evidence' => false]]);
        return ['status' => $scenario->status, 'failure_discovery_score' => $failure, 'promotion_evidence' => false];
    }

    /** @return array<string, mixed> */
    private function historicalBounds(string $type): array
    {
        return match ($type) {
            'spread_2x' => ['spread_multiplier_min' => 1, 'spread_multiplier_max' => 2],
            'slippage_shock' => ['slippage_percentile_min' => 0.95, 'slippage_percentile_max' => 0.999],
            'delayed_execution' => ['delay_bars_min' => 0, 'delay_bars_max' => 2],
            'calendar_shift' => ['timestamp_shift_seconds_min' => -60, 'timestamp_shift_seconds_max' => 60],
            'rare_tail_event' => ['return_percentile_min' => 0.001, 'return_percentile_max' => 0.999],
            default => ['empirical_regime_only' => true, 'synthetic_outside_history_forbidden' => true],
        };
    }

    private function available(): bool { try { return Schema::hasTable('lab_adversarial_scenarios'); } catch (\Throwable) { return false; } }
}
