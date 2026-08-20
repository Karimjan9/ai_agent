<?php

namespace App\Services;

use App\Models\PaperOrder;
use App\Models\PaperSignalOutcome;
use InvalidArgumentException;

/** Projects a settled paper result only when its sealed paired control exists. */
class InstrumentSettlementProjectionService
{
    public function __construct(private TradingInstrumentOperatingSystemService $instruments) {}

    /** @return array<string,mixed> */
    public function settle(PaperOrder $order, PaperSignalOutcome $outcome): array
    {
        $signal = $order->paperSignal;
        $router = (array) data_get($signal?->payload, 'trading_instrument_router', []);
        $playbookKey = (string) ($router['playbook_key'] ?? '');
        if ($playbookKey === '') {
            return ['status' => 'not_routed'];
        }
        $control = (array) data_get($signal?->payload, 'instrument_control', []);
        if (! (bool) data_get($control, 'control_contract.paired_isolated', false) || data_get($control, 'metrics') === null) {
            return ['status' => 'awaiting_paired_control', 'promotion_evidence' => false];
        }

        $context = (array) ($router['state'] ?? []);
        $metrics = [
            'net_edge' => (float) $outcome->profit_percent / 100,
            'cost_penalty' => (float) data_get($order->signal_context, 'risk.estimated_round_trip_cost_percent', 0) / 100,
            'drawdown_penalty' => max(0, -(float) $outcome->profit_percent) / 100,
            'incremental_lift' => (float) data_get($control, 'metrics.incremental_lift', 0),
            'survival_value' => (float) data_get($control, 'metrics.survival_value', 0),
            'regime_coverage_value' => (float) data_get($control, 'metrics.regime_coverage_value', 0),
        ];
        $evidence = [
            'evidence_key' => "paper-outcome:{$outcome->id}:playbook", 'source_type' => PaperSignalOutcome::class, 'source_key' => (string) $outcome->id,
            'metrics' => $metrics, 'control_metrics' => (array) data_get($control, 'metrics'), 'control_contract' => (array) data_get($control, 'control_contract'),
        ];
        try {
            $posterior = $this->instruments->recordPlaybookEvidence($playbookKey, $order->symbol, $order->timeframe, $context, $evidence);
        } catch (InvalidArgumentException $exception) {
            return ['status' => 'awaiting_paired_control', 'reason' => $exception->getMessage(), 'promotion_evidence' => false];
        }

        return ['status' => 'recorded', 'playbook_posterior_id' => $posterior->id, 'promotion_evidence' => false];
    }
}
