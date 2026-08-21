<?php

namespace App\Services;

use App\Models\InstrumentInvocationLedger;
use App\Models\LabAgent;
use App\Models\PaperOrder;
use App\Models\PaperSignal;
use App\Models\PaperSignalOutcome;
use App\Models\TradingInstrument;

class InstrumentInvocationLedgerService
{
    public const PROTOCOL = 'instrument_invocation_ledger_v1';

    public function recordDecision(PaperSignal $signal, ?LabAgent $agent = null): int
    {
        $agent ??= LabAgent::query()->where('model_version_id', $signal->model_version_id)->latest('id')->first();
        $router = (array) data_get($signal->payload, 'trading_instrument_router', []);
        $bundle = (array) data_get($signal->payload, 'instrument_bundle', data_get($signal->payload, 'trading_instrument_router.instrument_bundle', []));
        $keys = array_values((array) ($bundle['keys'] ?? data_get($signal->payload, 'trading_cognitive_stack.instrument_composer.instrument_keys', [])));
        $state = (array) ($router['state'] ?? []);
        $input = ['state' => $state, 'bundle' => $keys, 'decision' => $signal->decision, 'payload_hash' => $signal->payload_hash];
        $inputHash = hash('sha256', json_encode($input, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        foreach ($keys as $key) {
            $instrument = TradingInstrument::query()->where('instrument_key', $key)->first();
            InstrumentInvocationLedger::updateOrCreate(['invocation_key' => hash('sha256', implode('|', [$signal->id, $key, $inputHash]))], [
                'lab_agent_id' => $agent?->id, 'lab_generation_id' => $agent?->lab_generation_id,
                'paper_signal_id' => $signal->id, 'trading_instrument_id' => $instrument?->id,
                'instrument_key' => $key, 'symbol' => $signal->symbol, 'timeframe' => $signal->timeframe,
                'state_key' => data_get($state, 'state_key'), 'input_hash' => $inputHash,
                'output_hash' => hash('sha256', (string) $signal->payload_hash),
                'used_in_decision' => true, 'used_in_execution' => false, 'verdict' => 'invoked',
                'metadata' => [
                    'protocol' => self::PROTOCOL, 'tool_card' => $instrument?->definition,
                    'strategy' => data_get($signal->payload, 'trading_cognitive_stack.strategy_proposer'),
                    'tactic' => data_get($signal->payload, 'trading_cognitive_stack.tactic_executor'),
                    'risk' => data_get($signal->payload, 'trading_cognitive_stack.execution_risk_sentinel'),
                    'promotion_evidence' => false,
                ], 'invoked_at' => now(),
            ]);
        }
        return count($keys);
    }

    public function settle(PaperOrder $order, PaperSignalOutcome $outcome): int
    {
        $control = (array) data_get($order->paperSignal?->payload, 'instrument_control', []);
        $paired = (bool) data_get($control, 'control_contract.paired_isolated', false) && (array) data_get($control, 'metrics', []) !== [];
        $delta = $paired ? ['profit_percent' => (float) $outcome->profit_percent - (float) data_get($control, 'metrics.profit_percent', 0)] : [];
        $verdict = ! $paired ? 'not_sufficiently_tested' : ((float) $delta['profit_percent'] > 0 ? 'helped' : ((float) $delta['profit_percent'] < 0 ? 'harmed' : 'neutral'));
        return InstrumentInvocationLedger::query()->where('paper_signal_id', $order->paper_signal_id)->update([
            'paper_order_id' => $order->id, 'used_in_execution' => $order->status === 'closed', 'verdict' => $verdict,
            'causal_contribution' => $paired ? (float) $delta['profit_percent'] : null,
            'control_delta' => $delta ?: null, 'settled_at' => now(),
        ]);
    }
}
