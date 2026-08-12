<?php

namespace App\Services;

use App\Models\ModelMarketPerformance;
use App\Models\PaperSignal;

/**
 * Laravel-side contract for the XAUUSD H1 -> M15 pilot.
 *
 * Python is the canonical candle-level calculator. Laravel owns the sealed
 * request identity, fail-closed response guard and immutable passport. H1
 * never becomes a genetic parent of an M15 model; this service only creates
 * runtime context for the entry stream.
 */
class MultiTimeframePilotService
{
    public const PROTOCOL = 'xauusd_h1_m15_mtf_v1';

    /** @return array<string, mixed> */
    public function requestPayload(
        string $symbol,
        string $timeframe,
        ?string $strategy = null,
        ?string $contextSnapshotHash = null,
    ): array {
        $config = (array) config('services.mtf_pilot', []);
        $symbol = $this->normalizeSymbol($symbol);
        $timeframe = strtoupper($timeframe);
        $enabled = (bool) ($config['enabled'] ?? false)
            && $symbol === $this->normalizeSymbol((string) ($config['symbol'] ?? 'XAUUSD'))
            && $timeframe === strtoupper((string) ($config['entry_timeframe'] ?? 'M15'));

        $payload = [
            'protocol' => self::PROTOCOL,
            'enabled' => $enabled,
            'pilot_id' => (string) ($config['pilot_id'] ?? 'xauusd_h1_m15_v1'),
            'symbol' => $symbol,
            'regime_timeframe' => strtoupper((string) ($config['regime_timeframe'] ?? 'H1')),
            'entry_timeframe' => strtoupper((string) ($config['entry_timeframe'] ?? 'M15')),
            'mode' => (string) ($config['mode'] ?? 'h1_veto_m15_risk'),
            'entry_strategy' => $strategy,
            'max_h1_staleness_seconds' => (int) ($config['max_h1_staleness_seconds'] ?? 7200),
            'range_risk_multiplier' => (float) ($config['range_risk_multiplier'] ?? 0.75),
            'high_volatility_risk_multiplier' => (float) ($config['high_volatility_risk_multiplier'] ?? 0.65),
            'normal_volatility_risk_multiplier' => (float) ($config['normal_volatility_risk_multiplier'] ?? 1.0),
            'low_volatility_risk_multiplier' => (float) ($config['low_volatility_risk_multiplier'] ?? 0.85),
            'requires_closed_h1' => true,
            'genetic_parent_transfer' => false,
            'risk_sentinel' => [
                'agent' => 'risk_sentinel',
                'authority' => 'veto_or_wait_only',
                'can_change_strategy' => false,
                'can_change_gate_thresholds' => false,
            ],
            'context_snapshot_hash' => $contextSnapshotHash,
            'rule' => 'H1 is closed regime permission; M15 owns entry/timing; missing or stale H1 resolves to WAIT.',
        ];
        $payload['contract_hash'] = $this->hash($payload);

        return $payload;
    }

    public function isPilotCandidate(ModelMarketPerformance $candidate): bool
    {
        return (bool) data_get($this->requestPayload($candidate->symbol, $candidate->timeframe), 'enabled', false);
    }

    /**
     * Defense in depth at the Laravel evidence boundary. A Python response
     * without the expected MTF decision can never create an official BUY/SELL
     * paper signal for the pilot.
     *
     * @param array<string, mixed> $signal
     * @return array<string, mixed>
     */
    public function enforcePaperResponse(ModelMarketPerformance $candidate, array $signal): array
    {
        $contract = $this->requestPayload($candidate->symbol, $candidate->timeframe, $candidate->modelVersion?->strategy);
        if (! (bool) ($contract['enabled'] ?? false)) {
            return $signal;
        }

        $mtf = (array) ($signal['mtf_pilot'] ?? []);
        $context = (array) ($mtf['context'] ?? []);
        $original = strtoupper((string) ($signal['signal'] ?? 'WAIT'));
        $directional = in_array($original, ['BUY', 'SELL'], true);
        $valid = (string) ($mtf['protocol'] ?? '') === self::PROTOCOL
            && filled($mtf)
            && filled($context['h1_context_hash'] ?? null)
            && (string) ($context['status'] ?? '') === 'ready'
            && in_array((string) ($context['permission'] ?? ''), ['ALLOW', 'ALLOW_REDUCED'], true);
        $directionAllowed = ! $directional
            || ! filled($context['h1_direction'] ?? null)
            || (string) $context['h1_direction'] === $original;

        if (! $valid || ! $directionAllowed) {
            $signal['signal'] = 'WAIT';
            $signal['mtf_pilot'] = [
                ...$mtf,
                'decision' => 'WAIT',
                'reason' => ! $valid ? 'LARAVEL_MTF_CONTEXT_GUARD' : 'LARAVEL_MTF_DIRECTION_GUARD',
                'context' => $context,
            ];
            $signal['meta_agent'] = [
                ...((array) ($signal['meta_agent'] ?? [])),
                'decision' => 'WAIT',
                'reason' => $signal['mtf_pilot']['reason'],
                'mtf_veto' => true,
                'position_size_multiplier' => 0.0,
            ];
        }

        $signal['mtf_contract'] = $contract;
        return $signal;
    }

    /** @return array<string, mixed> */
    public function passportAttributes(PaperSignal $paperSignal, array $signal, ?string $executionHash = null): array
    {
        $mtf = (array) ($signal['mtf_pilot'] ?? []);
        $context = (array) ($mtf['context'] ?? []);
        $passport = [
            'paper_signal_id' => $paperSignal->id,
            'model_market_performance_id' => $paperSignal->model_market_performance_id,
            'pilot_id' => (string) data_get($mtf, 'pilot_id', config('services.mtf_pilot.pilot_id', 'xauusd_h1_m15_v1')),
            'lane' => 'official',
            'symbol' => $paperSignal->symbol,
            'primary_timeframe' => $paperSignal->timeframe,
            'regime_timeframe' => (string) config('services.mtf_pilot.regime_timeframe', 'H1'),
            'entry_timeframe' => (string) config('services.mtf_pilot.entry_timeframe', 'M15'),
            'h1_context_hash' => data_get($context, 'h1_context_hash'),
            'h1_closed_at' => data_get($context, 'h1_closed_at'),
            'm15_decision_at' => $paperSignal->candle_time,
            'm15_strategy' => data_get($signal, 'mtf_contract.entry_strategy', $paperSignal->marketPerformance?->modelVersion?->strategy),
            'data_hash' => data_get($signal, 'execution_contract_preview.data_hash'),
            'code_hash' => data_get($signal, 'execution_contract_preview.code_version'),
            'parameter_hash' => data_get($signal, 'execution_contract_preview.strategy_hash'),
            'execution_hash' => $executionHash ?: data_get($signal, 'execution_contract_preview.execution_hash'),
            'mtf_contract_hash' => data_get($signal, 'mtf_contract.contract_hash'),
            'risk_decision' => (string) data_get($mtf, 'risk_decision', data_get($signal, 'meta_agent.decision', $paperSignal->decision)),
            'entry_reason' => (string) data_get($signal, 'meta_agent.reason', data_get($mtf, 'reason', 'UNKNOWN')),
            // Exit reason is intentionally carried as null at decision time;
            // the immutable paper_signal_outcomes row is the lifecycle close.
            'exit_reason' => null,
            'mtf_decision' => (string) data_get($mtf, 'decision', $paperSignal->decision),
            'h1_regime' => data_get($context, 'h1_regime'),
            'h1_permission' => data_get($context, 'permission'),
            'risk_multiplier' => (float) data_get($mtf, 'risk_multiplier', 1.0),
            'gate_decisions' => [
                'mtf' => data_get($mtf, 'decision'),
                'risk' => data_get($signal, 'meta_agent.decision'),
                'calendar' => data_get($signal, 'economic_calendar.active') === true ? 'WAIT' : 'ALLOW',
                'final_signal' => data_get($signal, 'signal', $paperSignal->decision),
            ],
            'counterfactuals' => (array) ($signal['counterfactuals'] ?? []),
            'payload' => $signal,
        ];
        $passport['passport_hash'] = $this->hash($passport);

        return $passport;
    }

    public function hash(array $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
    }

    private function normalizeSymbol(string $symbol): string
    {
        return strtoupper(str_replace(['/', '_', '-'], '', trim($symbol)));
    }

    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            } elseif (is_numeric($item) && ! is_string($item)) {
                $value[$key] = (float) $item;
            }
        }

        return $value;
    }
}
