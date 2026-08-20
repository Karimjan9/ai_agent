<?php

namespace App\Services;

use App\Models\ExecutionTacticPosterior;
use App\Models\PaperOrder;
use App\Models\PaperSignalOutcome;

/** Learns a tactic only from an explicitly paired execution control. */
class ExecutionTacticSettlementService
{
    /** @return array<string,mixed> */
    public function settle(PaperOrder $order, PaperSignalOutcome $outcome): array
    {
        $tactic = (array) data_get($order->signal_context, 'execution_contract.tactical_contract', []);
        $control = (array) data_get($order->paperSignal?->payload, 'execution_control', []);
        if ($tactic === [] || ! (bool) data_get($control, 'control_contract.paired_isolated', false) || data_get($control, 'metrics') === null) {
            return ['status' => 'awaiting_paired_execution_control', 'promotion_evidence' => false];
        }
        $metrics = (array) data_get($control, 'metrics');
        $net = (float) $outcome->profit_percent / 100 - (float) data_get($order->signal_context, 'risk.estimated_round_trip_cost_percent', 0) / 100;
        $key = implode('|', [(string) data_get($tactic, 'entry', 'unknown'), (string) data_get($tactic, 'exit.stop', 'unknown'), (string) data_get($tactic, 'exit.target', 'unknown'), (string) data_get($tactic, 'sizing', 'unknown')]);
        $state = implode('|', [(string) ($order->paperSignal?->market_regime ?? 'unknown'), (string) ($order->paperSignal?->volatility_regime ?? 'unknown')]);
        $vector = ['net_expectancy' => $net, 'stop_efficiency' => (float) ($metrics['stop_efficiency'] ?? 0), 'premature_stop_rate' => (float) ($metrics['premature_stop_rate'] ?? 0), 'mfe_before_stop' => (float) ($metrics['mfe_before_stop'] ?? 0), 'mae_before_target' => (float) ($metrics['mae_before_target'] ?? 0), 'target_capture_ratio' => (float) ($metrics['target_capture_ratio'] ?? 0), 'time_to_target' => (float) ($metrics['time_to_target'] ?? 0), 'slippage_at_stop' => (float) ($metrics['slippage_at_stop'] ?? 0), 'control_incremental_lift' => (float) ($metrics['incremental_lift'] ?? 0)];
        $posterior = ExecutionTacticPosterior::firstOrNew(['tactic_key' => $key, 'symbol' => $order->symbol, 'timeframe' => $order->timeframe, 'state_key' => $state]);
        $old = (int) ($posterior->observations ?? 0);
        $n = $old + 1;
        $expectancy = (($old * (float) ($posterior->net_expectancy ?? 0)) + $net) / $n;
        $posterior->fill(['observations' => $n, 'net_expectancy' => $expectancy, 'uncertainty' => max(.05, 1 / sqrt($n)), 'mastery_stage' => $n >= 5 && $expectancy > 0 ? 'tactic_validated' : 'tactic_apprentice', 'value_vector' => $vector, 'last_observed_at' => now()])->save();

        return ['status' => 'recorded', 'posterior_id' => $posterior->id, 'promotion_evidence' => false];
    }
}
