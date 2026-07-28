<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\ShadowVetoObservation;
use Carbon\CarbonImmutable;

/** Persists bounded counterfactual samples; aggregate statistics remain in the immutable replay result. */
class ShadowVetoLedgerService
{
    public function record(LabAgent $agent, array $result, string $stage): void
    {
        $ledger = (array) data_get($result, 'veto_regret', []);
        foreach ((array) data_get($ledger, 'sample_records', []) as $record) {
            $signalTime = data_get($record, 'signal_time');
            if (! $signalTime) continue;
            ShadowVetoObservation::updateOrCreate(
                [
                    'lab_agent_id' => $agent->id, 'stage' => $stage,
                    'veto_reason' => (string) data_get($record, 'veto_reason', 'unknown'),
                    'signal_time' => CarbonImmutable::parse($signalTime),
                ],
                [
                    'market_regime' => data_get($record, 'market_regime'),
                    'volatility_regime' => data_get($record, 'volatility_regime'),
                    'direction' => data_get($record, 'direction'),
                    'entry_time' => data_get($record, 'entry_time') ? CarbonImmutable::parse(data_get($record, 'entry_time')) : null,
                    'exit_time' => data_get($record, 'exit_time') ? CarbonImmutable::parse(data_get($record, 'exit_time')) : null,
                    'shadow_profit' => (float) data_get($record, 'shadow_profit', 0),
                    'shadow_loss' => (float) data_get($record, 'shadow_loss', 0),
                    'shadow_profit_percent' => (float) data_get($record, 'shadow_profit_percent', 0),
                    'outcome' => (string) data_get($record, 'outcome', 'LOSS'),
                    'metadata' => ['exit_reason' => data_get($record, 'exit_reason'), 'source' => 'shadow_veto_ledger_v1'],
                ],
            );
        }
    }
}
