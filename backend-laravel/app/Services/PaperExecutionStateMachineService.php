<?php

namespace App\Services;

use App\Models\ModelMarketPerformance;
use App\Models\PaperExecutionEvent;
use App\Models\PaperOrder;
use App\Models\PaperSignal;

/** Immutable order-lifecycle audit for the execution digital twin. */
class PaperExecutionStateMachineService
{
    public function record(ModelMarketPerformance $candidate, string $type, ?PaperSignal $signal = null, ?PaperOrder $order = null, array $data = []): PaperExecutionEvent
    {
        $key = hash('sha256', implode('|', [$candidate->id, $signal?->id, $order?->id, $type, $data['idempotency_suffix'] ?? '0']));
        return PaperExecutionEvent::firstOrCreate(['idempotency_key' => $key], [
            'model_market_performance_id' => $candidate->id, 'paper_signal_id' => $signal?->id, 'paper_order_id' => $order?->id,
            'event_type' => $type, 'provider' => $data['provider'] ?? null, 'occurred_at' => $data['occurred_at'] ?? now(),
            'requested_price' => $data['requested_price'] ?? null, 'filled_price' => $data['filled_price'] ?? null,
            'requested_units' => $data['requested_units'] ?? null, 'filled_units' => $data['filled_units'] ?? null,
            'latency_ms' => $data['latency_ms'] ?? null, 'reason' => $data['reason'] ?? null, 'retry_count' => $data['retry_count'] ?? 0,
            'payload' => ['protocol' => 'execution_digital_twin_state_machine_v1', ...((array) ($data['payload'] ?? []))],
        ]);
    }

    public function signalInvalidatedByDisconnect(ModelMarketPerformance $candidate, PaperSignal $signal): bool
    {
        return PaperExecutionEvent::query()->where('model_market_performance_id', $candidate->id)->where('paper_signal_id', $signal->id)
            ->where('event_type', 'provider_disconnected')->exists();
    }
}
