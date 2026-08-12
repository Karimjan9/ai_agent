<?php

namespace App\Services;

use App\Models\ModelMarketPerformance;
use App\Models\PaperMtfShadowObservation;
use App\Models\PaperSignal;
use App\Models\PaperSignalPassport;
use Carbon\CarbonImmutable;

/** Immutable official passport + non-promotional MTF counterfactual ledger. */
class PaperMtfLedgerService
{
    public function __construct(private MultiTimeframePilotService $pilot) {}

    public function recordOfficial(PaperSignal $signal, array $payload): ?PaperSignalPassport
    {
        $candidate = $signal->marketPerformance;
        if (! $candidate || ! $this->pilot->isPilotCandidate($candidate)) {
            return null;
        }

        $attributes = $this->pilot->passportAttributes($signal, $payload);

        return PaperSignalPassport::firstOrCreate(
            ['paper_signal_id' => $signal->id],
            $attributes,
        );
    }

    public function recordShadow(ModelMarketPerformance $candidate, ?PaperSignal $signal, array $payload): int
    {
        if (! (bool) config('services.mtf_pilot.shadow_enabled', true)
            || ! $this->pilot->isPilotCandidate($candidate)) {
            return 0;
        }

        $counterfactuals = (array) ($payload['counterfactuals'] ?? []);
        if ($counterfactuals === []) {
            return 0;
        }

        $candleTime = $signal?->candle_time;
        if (! $candleTime && filled($payload['signal_time'] ?? null)) {
            $candleTime = CarbonImmutable::parse((string) $payload['signal_time'])->utc();
        }
        if (! $candleTime) {
            return 0;
        }

        $context = (array) data_get($payload, 'mtf_pilot.context', []);
        $pilotId = (string) data_get($payload, 'mtf_pilot.pilot_id', config('services.mtf_pilot.pilot_id', 'xauusd_h1_m15_v1'));
        $count = 0;
        foreach ($counterfactuals as $scenario => $item) {
            if (! is_array($item)) {
                continue;
            }
            $scenarioKey = (string) $scenario;
            // The official lane is already represented by paper_signals, and
            // H1-only is a context benchmark without an M15 entry topology;
            // neither belongs in the executable shadow ledger.
            if (in_array($scenarioKey, ['h1_m15_official', 'h1_only_context'], true)) {
                continue;
            }
            $decision = strtoupper((string) ($item['decision'] ?? 'WAIT'));
            $shadowContract = (array) ($item['execution_contract'] ?? []);
            $identity = implode('|', [
                $candidate->id, $pilotId, $candidate->symbol, $candidate->timeframe,
                $candleTime->toIso8601String(), $scenarioKey,
            ]);
            $idempotencyKey = hash('sha256', $identity);
            $shadowPayload = [
                'protocol' => 'mtf_shadow_twin_v1',
                'promotion_evidence' => false,
                'source_paper_signal_id' => $signal?->id,
                'source_payload_hash' => $payload['payload_hash'] ?? null,
                'scenario' => $item,
                'mtf_pilot' => $payload['mtf_pilot'] ?? [],
            ];
            $payloadHash = $this->pilot->hash($shadowPayload);
            PaperMtfShadowObservation::firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'model_market_performance_id' => $candidate->id,
                    'paper_signal_id' => $signal?->id,
                    'pilot_id' => $pilotId,
                    'lane' => 'shadow',
                    'scenario_key' => $scenarioKey,
                    'symbol' => $candidate->symbol,
                    'timeframe' => $candidate->timeframe,
                    'candle_time' => $candleTime,
                    'decision' => in_array($decision, ['BUY', 'SELL'], true) ? $decision : 'WAIT',
                    'price' => data_get($shadowContract, 'entry_price', $payload['price'] ?? null),
                    'stop_loss' => data_get($shadowContract, 'stop_loss'),
                    'take_profit' => data_get($shadowContract, 'take_profit'),
                    'confidence' => (float) ($payload['confidence'] ?? 0),
                    'h1_context_hash' => data_get($context, 'h1_context_hash'),
                    'h1_closed_at' => data_get($context, 'h1_closed_at'),
                    'idempotency_key' => $idempotencyKey,
                    'payload_hash' => $payloadHash,
                    'payload' => $shadowPayload,
                    'promotion_evidence' => false,
                    'observed_at' => now(),
                ],
            );
            $count++;
        }

        return $count;
    }
}
