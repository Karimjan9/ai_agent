<?php

namespace App\Services;

use App\Models\CapabilityCausalAttribution;
use App\Models\PaperOrder;
use App\Models\PaperSignalOutcome;

/** Separates a settled paper result into auditable decision-surface causes. */
class CausalAttributionService
{
    public const PROTOCOL = 'capability_causal_attribution_v1';

    /** @return array<string,mixed> */
    public function attribute(PaperOrder $order, PaperSignalOutcome $outcome): array
    {
        $audit = (array) data_get($outcome->payload, 'self_audit', []);
        $signal = $order->paperSignal;
        $losses = array_map('strval', (array) ($audit['loss_taxonomy'] ?? []));
        $profit = (float) $outcome->profit_percent;
        $contributions = ['strategy' => .20, 'tactic' => .20, 'execution' => .20, 'risk' => .20, 'market_luck' => .20];
        if ($profit > 0) {
            $contributions['market_luck'] = .35;
        }
        if (in_array('regime_mismatch', $losses, true) || in_array('wrong_direction', $losses, true)) {
            $contributions['strategy'] = .45;
        }
        if (in_array('stop_too_close', $losses, true) || in_array('target_too_far', $losses, true)) {
            $contributions['tactic'] = .45;
        }
        if (in_array('cost_destroyed_edge', $losses, true)) {
            $contributions['execution'] = .45;
        }
        if ((string) data_get($order->signal_context, 'risk_sentinel.decision') === 'SHRINK') {
            $contributions['risk'] = .35;
        }
        $total = array_sum($contributions);
        foreach ($contributions as $key => $value) {
            $contributions[$key] = round($value / $total, 6);
        }
        arsort($contributions);
        $primary = (string) array_key_first($contributions);
        $key = 'paper-outcome:'.$outcome->id;
        $row = CapabilityCausalAttribution::updateOrCreate(['attribution_key' => $key], [
            'paper_order_id' => $order->id, 'paper_signal_outcome_id' => $outcome->id,
            'symbol' => $order->symbol, 'timeframe' => $order->timeframe, 'primary_cause' => $primary,
            'contributions' => $contributions,
            'evidence' => ['profit_percent' => $profit, 'exit_reason' => $outcome->exit_reason, 'self_audit' => $audit, 'state' => ['regime' => $signal?->market_regime, 'volatility' => $signal?->volatility_regime]],
            'attributed_at' => now(),
        ]);

        return ['protocol' => self::PROTOCOL, 'status' => 'recorded', 'attribution_id' => $row->id, 'primary_cause' => $primary, 'contributions' => $contributions, 'promotion_evidence' => false];
    }
}
