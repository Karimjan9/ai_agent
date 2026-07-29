<?php

namespace App\Services;

use App\Models\ModelMarketPerformance;
use App\Models\RegimeReservoirEntry;

/** Recurring-state memory. It recalls a local adapter; it never transfers a champion. */
class RegimeReservoirService
{
    public function sync(ModelMarketPerformance $performance, array $result): array
    {
        $entries = [];
        foreach ((array) data_get($result, 'regime_performance', []) as $regime => $metrics) {
            foreach (array_keys((array) data_get($result, 'volatility_performance', [])) ?: [null] as $volatility) {
                $signature = hash('sha256', json_encode([$performance->symbol, $performance->timeframe, $regime, $volatility,
                    round((float) data_get($metrics, 'profit_factor', data_get($metrics, 'net_pf', 0)), 2),
                    round((float) data_get($result, 'opportunity_metrics.coverage', 0), 2)]));
                $pf = (float) data_get($metrics, 'profit_factor', data_get($metrics, 'net_pf', 0));
                $entry = RegimeReservoirEntry::updateOrCreate([
                    'symbol' => $performance->symbol, 'timeframe' => $performance->timeframe, 'regime' => $regime,
                    'volatility_regime' => $volatility, 'state_signature' => $signature,
                ], [
                    'adapter_model_version_id' => $performance->model_version_id,
                    'performance_posterior' => ['sample_count' => $performance->sample_count, 'profit_factor' => $pf,
                        'confidence' => min(1, $performance->sample_count / 50), 'source' => 'closed replay only'],
                    'known_failures' => (array) data_get($result, 'failure_focused_replay.targeted_windows', []),
                    'recovery_quality' => max(0, min(100, $pf * 35 - (float) data_get($result, 'max_drawdown_percent', 0))),
                    'last_seen_at' => now(),
                ]);
                $entries[] = ['id' => $entry->id, 'regime' => $regime, 'volatility_regime' => $volatility, 'signature' => $signature];
            }
        }
        return ['protocol' => 'regime_reservoir_v1', 'entries' => $entries,
            'rule' => 'Recall only changes the local adapter prior; calibration, risk and all gates require fresh evidence.'];
    }

    public function recall(string $symbol, string $timeframe, ?string $regime): ?array
    {
        if (! $regime) return null;
        $entry = RegimeReservoirEntry::query()->where(compact('symbol', 'timeframe', 'regime'))
            ->orderByDesc('recovery_quality')->orderByDesc('last_seen_at')->first();
        return $entry ? ['entry_id' => $entry->id, 'adapter_model_version_id' => $entry->adapter_model_version_id,
            'recovery_quality' => $entry->recovery_quality, 'rule' => 'Adapter prior only; no transferred performance or promotion.'] : null;
    }
}
