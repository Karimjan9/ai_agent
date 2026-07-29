<?php

namespace App\Services;

use App\Models\AgentMemory;
use App\Models\Candle;
use App\Models\ModelMarketPerformance;
use App\Models\PaperOrder;
use App\Models\PaperSignal;
use App\Models\PaperSignalOutcome;
use App\Models\Symbol;
use App\Services\MarketData\CandlePayloadService;
use App\Services\MarketData\MarketReadinessService;
use Illuminate\Support\Facades\Http;

class PaperTradingExecutionService
{
    public function __construct(
        private CandlePayloadService $candles,
        private MarketChampionService $champions,
        private PhaseTwoFoundationService $foundation,
        private MarketReadinessService $marketReadiness,
        private TradingRiskService $risk,
        private SpecialistPortfolioAllocator $allocator,
        private PaperConfidenceCalibrationService $calibration,
        private EconomicCalendarService $calendar,
        private CandidateGateDecisionService $gateDecisions,
        private PaperExecutionStateMachineService $executionState,
    ) {}

    public function run(): array
    {
        $stats = ['captured' => 0, 'opened' => 0, 'closed' => 0, 'candidates' => 0];
        $candidates = ModelMarketPerformance::with('modelVersion')
            ->where('evidence_status', 'valid')
            ->whereHas('modelVersion', fn ($query) => $query->where('evidence_status', 'valid'))
            ->whereIn('status', ['forward_validated', 'paper'])
            ->where('paper_status', '!=', 'failed')
            ->get();

        foreach ($candidates as $candidate) {
            if (! $this->marketReadiness->ready($candidate->symbol, $candidate->timeframe)) {
                $this->gateDecisions->recordPaperCapture($candidate, 'BLOCKED_BY_PROVIDER');
                continue;
            }

            $stats['candidates']++;
            $stats['closed'] += $this->reconcile($candidate);
            if (! PaperOrder::where('model_market_performance_id', $candidate->id)
                ->where('evidence_status', 'valid')->where('status', 'open')->exists()) {
                $stats['opened'] += $this->executePendingSignal($candidate);
            }
            $stats['captured'] += $this->captureLatestSignal($candidate, $candidates);
            $this->score($candidate);
        }

        return $stats;
    }

    private function captureLatestSignal(ModelMarketPerformance $candidate, $universe): int
    {
        $rows = $this->candles->candlesForBacktest($candidate->symbol, $candidate->timeframe, 1000);
        if (count($rows) < 200) {
            $this->gateDecisions->recordPaperCapture($candidate, 'NO_SIGNAL_OPPORTUNITY', ['available_candles' => count($rows)]);
            return 0;
        }

        $model = $candidate->modelVersion;
        $response = Http::timeout(120)->acceptJson()
            ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])->post(
            rtrim(config('services.ai_service.url'), '/').'/api/paper/signal',
            $this->aiRequest($candidate, $rows),
        );
        if ($response->failed()) {
            $this->gateDecisions->recordPaperCapture($candidate, 'BLOCKED_BY_AI_SERVICE', ['http_status' => $response->status()]);
            return 0;
        }

        $signal = $response->json();
        $rawConfidence = max(0, min(1, (float) ($signal['confidence'] ?? 0)));
        $calibrated = $this->calibration->calibrate($candidate, (string) ($signal['market_regime'] ?? 'unknown'), $rawConfidence);
        $news = $this->calendar->veto($candidate->symbol);
        $signal['raw_confidence'] = $rawConfidence;
        $signal['calibration'] = $calibrated;
        $signal['economic_calendar'] = $news;
        if (($signal['meta_agent']['decision'] ?? null) === 'WAIT') {
            $signal['signal'] = 'WAIT';
            $signal['meta_reason'] = $signal['meta_agent']['reason'] ?? 'META_AGENT_WAIT';
        } elseif (! $calibrated['allowed']) {
            $signal['signal'] = 'WAIT';
            $signal['calibration_reason'] = 'Calibrated paper probability is below the minimum execution threshold.';
        } elseif ($news['active']) {
            $signal['signal'] = 'WAIT';
            $signal['news_reason'] = 'High-impact economic event execution veto.';
        } elseif (! $this->allocator->ownsRegime(
            $candidate, $universe,
            (string) ($signal['market_regime'] ?? 'unknown'),
            (string) ($signal['volatility_regime'] ?? 'normal_volatility'),
        )) {
            $signal['signal'] = 'WAIT';
            $signal['allocator_reason'] = 'Another independent specialist owns the current regime risk budget.';
        }
        $captureReason = match (true) {
            ! $calibrated['allowed'] => 'BLOCKED_BY_CALIBRATION',
            isset($signal['meta_reason']) => 'BLOCKED_BY_META_AGENT',
            $news['active'] => 'BLOCKED_BY_CALENDAR',
            isset($signal['allocator_reason']) => 'BLOCKED_BY_ALLOCATOR',
            ($signal['signal'] ?? 'WAIT') === 'WAIT' => 'NO_SIGNAL_OPPORTUNITY',
            default => 'SIGNAL_CAPTURED',
        };
        $this->gateDecisions->recordPaperCapture($candidate, $captureReason, [
            'signal_time' => $signal['signal_time'] ?? null,
            'market_regime' => $signal['market_regime'] ?? 'unknown',
            'decision' => $signal['signal'] ?? 'WAIT',
        ]);
        $candleTime = $signal['signal_time'] ?? null;
        if (! $candleTime || PaperSignal::query()
            ->where('model_market_performance_id', $candidate->id)
            ->where('symbol', $candidate->symbol)
            ->where('timeframe', $candidate->timeframe)
            ->where('candle_time', $candleTime)
            ->exists()) {
            return 0;
        }

        $signalSnapshot = $this->foundation->captureSignalMarketSnapshot([
            'signal_type' => 'paper_candidate',
            'signal_key' => "paper:{$candidate->id}:{$candleTime}",
            'strategy' => $model->strategy,
            'symbol' => $candidate->symbol,
            'timeframe' => $candidate->timeframe,
            'signal' => $signal['signal'] ?? 'WAIT',
            'confidence' => round($rawConfidence * 100, 2),
            'hypothesis' => 'Forward-validated candidate emitted an immutable paper signal.',
        ]);

        $paperSignal = PaperSignal::create([
            'model_market_performance_id' => $candidate->id,
            'model_version_id' => $model->id,
            'signal_market_snapshot_id' => $signalSnapshot?->id,
            'symbol' => $candidate->symbol,
            'timeframe' => $candidate->timeframe,
            'candle_time' => $candleTime,
            'decision' => $signal['signal'] ?? 'WAIT',
            'price' => $signal['price'] ?? 0,
            'stop_loss' => $signal['stop_loss'] ?? null,
            'take_profit' => $signal['take_profit'] ?? null,
            'confidence' => round(((float) data_get($signal, 'calibration.confidence', $rawConfidence)) * 100, 2),
            'market_regime' => $signal['market_regime'] ?? 'unknown',
            'volatility_regime' => $signal['volatility_regime'] ?? 'normal_volatility',
            'payload' => $signal,
            'payload_hash' => hash('sha256', json_encode($this->canonicalize($signal), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)),
        ]);
        $this->executionState->record($candidate, 'signal_created', $paperSignal, null, ['provider' => 'canonical_market_data',
            'requested_price' => $paperSignal->price, 'payload' => ['candle_time' => $paperSignal->candle_time?->toIso8601String()]]);

        return 1;
    }

    private function executePendingSignal(ModelMarketPerformance $candidate): int
    {
        $signal = PaperSignal::query()
            ->where('model_market_performance_id', $candidate->id)
            ->whereIn('decision', ['BUY', 'SELL'])
            ->whereDoesntHave('order')
            ->oldest('candle_time')
            ->first();
        if (! $signal) {
            return 0;
        }
        if ($this->executionState->signalInvalidatedByDisconnect($candidate, $signal)) {
            $this->executionState->record($candidate, 'cancelled', $signal, null, ['reason' => 'STALE_AFTER_PROVIDER_DISCONNECT']);
            return 0;
        }

        $symbolId = Symbol::where('code', $signal->symbol)->value('id');
        $entryCandle = $symbolId ? Candle::query()
            ->where('symbol_id', $symbolId)
            ->where('timeframe', $signal->timeframe)
            ->where('time', '>', $signal->candle_time)
            ->oldest('time')
            ->first() : null;
        if (! $entryCandle) {
            return 0;
        }

        $rows = $this->candles->candlesForBacktest($candidate->symbol, $candidate->timeframe, 1000);
        $contractResponse = Http::timeout(120)->acceptJson()
            ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])->post(
                rtrim(config('services.ai_service.url'), '/').'/api/paper/execution-contract',
                ['request' => $this->aiRequest($candidate, $rows), 'entry_market_price' => (float) $entryCandle->open,
                    'signal_time' => $signal->candle_time->toIso8601String()],
            );
        if ($contractResponse->failed()) {
            $this->executionState->record($candidate, 'provider_disconnected', $signal, null, ['provider' => 'ai_execution_contract', 'reason' => 'EXECUTION_CONTRACT_UNAVAILABLE', 'latency_ms' => 120000]);
            $this->gateDecisions->recordPaperCapture($candidate, 'BLOCKED_BY_EXECUTION_CONTRACT', ['paper_signal_id' => $signal->id, 'http_status' => $contractResponse->status()]);
            return 0;
        }
        $contract = (array) $contractResponse->json();
        if (($contract['decision'] ?? 'WAIT') !== $signal->decision) {
            $this->gateDecisions->recordPaperCapture($candidate, 'BLOCKED_BY_META_AGENT', ['paper_signal_id' => $signal->id, 'meta_reason' => data_get($contract, 'meta_agent.reason')]);
            return 0;
        }
        $entry = (float) $contract['entry_price'];
        $executionSignal = array_merge($signal->payload, [
            'signal' => $signal->decision, 'signal_time' => $signal->candle_time->toIso8601String(),
            'entry_candle_time' => $entryCandle->time->toIso8601String(), 'price' => $entry,
            'stop_loss' => (float) $contract['stop_loss'], 'take_profit' => (float) $contract['take_profit'],
            'execution_contract' => $contract,
        ]);
        $risk = $this->risk->canOpen($candidate, $executionSignal);
        $baseUnits = (float) config('services.paper.units', 1);
        $sizeMultiple = (float) $contract['position_size_multiple'];

        if (! $risk['allowed']) {
            $blockedOrder = PaperOrder::create([
                'model_market_performance_id' => $candidate->id,
                'paper_signal_id' => $signal->id,
                'broker' => 'risk_gate',
                'symbol' => $candidate->symbol,
                'timeframe' => $candidate->timeframe,
                'direction' => $signal->decision,
                'units' => 0,
                'entry_price' => $entry,
                'stop_loss' => $executionSignal['stop_loss'],
                'take_profit' => $executionSignal['take_profit'],
                'status' => 'blocked',
                'opened_at' => $entryCandle->time,
                'signal_context' => ['signal' => $executionSignal, 'risk' => $risk],
            ]);
            $this->executionState->record($candidate, 'rejected', $signal, $blockedOrder, ['provider' => 'risk_gate', 'requested_price' => $entry, 'reason' => $risk['reason'] ?? 'RISK_VETO']);
            $this->foundation->recordEvent([
                'event_type' => 'paper_signal_blocked',
                'agent' => $candidate->modelVersion->strategy,
                'symbol' => $candidate->symbol,
                'timeframe' => $candidate->timeframe,
                'severity' => 'warning',
                'summary' => "Paper signal blocked: {$risk['reason']}",
                'payload' => ['paper_signal_id' => $signal->id, 'risk' => $risk],
            ]);
            $this->gateDecisions->recordPaperCapture($candidate, 'BLOCKED_BY_RISK', ['paper_signal_id' => $signal->id, 'risk_reason' => $risk['reason'] ?? null]);
            return 0;
        }

        $broker = 'simulated';
        $units = $baseUnits * $sizeMultiple;

        $order = PaperOrder::create([
            'model_market_performance_id' => $candidate->id,
            'paper_signal_id' => $signal->id,
            'broker' => $broker,
            'external_order_id' => null,
            'symbol' => $candidate->symbol,
            'timeframe' => $candidate->timeframe,
            'direction' => $signal->decision,
            'units' => $units,
            'entry_price' => $entry,
            'stop_loss' => $executionSignal['stop_loss'],
            'take_profit' => $executionSignal['take_profit'],
            'status' => 'open',
            'opened_at' => $entryCandle->time,
            'signal_context' => ['signal' => $executionSignal, 'risk' => $risk, 'position_size_multiple' => $sizeMultiple, 'execution_contract' => $contract],
            'broker_payload' => ['execution_contract' => $contract],
        ]);
        $this->executionState->record($candidate, 'order_submitted', $signal, $order, ['provider' => $broker, 'requested_price' => $entry, 'requested_units' => $units]);
        $order->fills()->create([
            'fill_type' => 'entry',
            'price' => $entry,
            'cost_percent' => $risk['estimated_round_trip_cost_percent'] / 2,
            'filled_at' => $entryCandle->time,
            'payload' => null,
        ]);
        $this->executionState->record($candidate, 'filled', $signal, $order, ['provider' => $broker, 'requested_price' => $entry, 'filled_price' => $entry, 'requested_units' => $units, 'filled_units' => $units]);
        $candidate->update(['status' => 'paper', 'paper_status' => 'running']);

        return 1;
    }

    private function reconcile(ModelMarketPerformance $candidate): int
    {
        $closed = 0;
        $orders = PaperOrder::where('model_market_performance_id', $candidate->id)
            ->where('evidence_status', 'valid')->where('status', 'open')->get();
        foreach ($orders as $order) {
            $result = $this->simulatedExit($order);
            if (! $result) continue;
            [$price, $profit, $exitReason] = $result;

            $order->update(['exit_price' => $price, 'profit_percent' => $profit, 'status' => 'closed', 'closed_at' => now()]);
            $order->fills()->create([
                'fill_type' => 'exit',
                'price' => $price,
                'cost_percent' => $this->risk->estimatedRoundTripCostPercent($order->symbol, (float) $order->entry_price) / 2,
                'filled_at' => now(),
                'payload' => ['exit_reason' => $exitReason],
            ]);
            $this->executionState->record($candidate, 'closed', $order->paperSignal, $order, ['provider' => $order->broker, 'filled_price' => $price, 'filled_units' => $order->units, 'reason' => $exitReason]);
            if ($order->paper_signal_id) {
                $outcome = PaperSignalOutcome::firstOrCreate(['paper_signal_id' => $order->paper_signal_id], [
                    'paper_order_id' => $order->id,
                    'outcome' => $profit > 0 ? 'win' : ($profit < 0 ? 'loss' : 'flat'),
                    'exit_price' => $price,
                    'profit_percent' => $profit,
                    'exit_reason' => $exitReason,
                    'payload' => ['broker' => $order->broker, 'closed_at' => now()->toIso8601String()],
                ]);
                $audit = $this->selfAudit($candidate, $order, $exitReason, $profit);
                $outcome->update(['payload' => [...((array) $outcome->payload), 'self_audit' => $audit]]);
                if ($outcome->wasRecentlyCreated && $order->paperSignal) {
                    $this->calibration->learn($candidate, $order->paperSignal);
                }
            }
            $this->recordClosedOrderMemory($candidate, $order->fresh());
            $closed++;
        }

        return $closed;
    }

    private function simulatedExit(PaperOrder $order): ?array
    {
        $candidate = $order->marketPerformance()->with('modelVersion')->first();
        $contract = (array) data_get($order->signal_context, 'execution_contract', []);
        if (! $candidate || $contract === []) return null;
        $response = Http::timeout(120)->acceptJson()
            ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])->post(
                rtrim(config('services.ai_service.url'), '/').'/api/paper/advance-contract', [
                    'request' => $this->aiRequest($candidate, $this->candles->candlesForBacktest($candidate->symbol, $candidate->timeframe, 1000)),
                    'contract' => $contract, 'entry_time' => $order->opened_at?->toIso8601String(),
                ],
            );
        if ($response->failed() || ! $response->json('closed')) return null;
        $result = $response->json();
        return [(float) $result['exit_price'], (float) $result['profit_percent'], (string) $result['exit_reason']];
    }

    private function score(ModelMarketPerformance $candidate): void
    {
        $orders = PaperOrder::where('model_market_performance_id', $candidate->id)
            ->where('evidence_status', 'valid')->where('status', 'closed')->orderBy('closed_at')->get();
        if ($orders->isEmpty()) return;
        $wins = $orders->where('profit_percent', '>', 0)->sum('profit_percent');
        $loss = abs($orders->where('profit_percent', '<=', 0)->sum('profit_percent'));
        $balance = 10000;
        $peak = $balance;
        $drawdown = 0;
        foreach ($orders as $order) {
            $balance *= 1 + $order->profit_percent / 100;
            $peak = max($peak, $balance);
            $drawdown = max($drawdown, ($peak - $balance) / $peak * 100);
        }
        $this->champions->recordPaperResult($candidate, [
            'sample_count' => $orders->count(),
            'profit_factor' => $loss > 0 ? $wins / $loss : ($wins > 0 ? 99 : 0),
            'max_drawdown' => round($drawdown, 2),
            'net_profit_percent' => round(($balance - 10000) / 100, 2),
            'order_ids' => $orders->pluck('id')->all(),
        ]);
    }

    private function recordClosedOrderMemory(ModelMarketPerformance $candidate, PaperOrder $order): void
    {
        if (AgentMemory::query()->where('source_type', PaperOrder::class)->where('source_id', $order->id)->exists()) return;
        $profit = (float) $order->profit_percent;
        $this->foundation->writeExperienceMemory([
            'strategy' => $candidate->modelVersion?->strategy ?? $candidate->strategy_family,
            'memory_type' => 'paper_execution',
            'outcome' => $profit > 0 ? 'win' : 'loss',
            'summary' => "Paper {$order->direction} {$order->symbol} closed at {$profit}%.",
            'lesson' => $profit > 0 ? 'Retain this setup for further paper evidence.' : 'Reduce setup confidence until more evidence exists.',
            'strength' => min(100, 50 + abs($profit) * 10),
            'confidence_score' => 55,
            'source_type' => PaperOrder::class,
            'source_id' => $order->id,
            'metadata' => ['paper_signal_id' => $order->paper_signal_id, 'entry_price' => $order->entry_price, 'exit_price' => $order->exit_price, 'profit_percent' => $profit],
        ]);
    }

    /** Immutable post-trade explanation: future mutations receive a taxonomy, not a vague "PF low" label. */
    private function selfAudit(ModelMarketPerformance $candidate, PaperOrder $order, string $exitReason, float $profit): array
    {
        $signal = $order->paperSignal;
        $payload = (array) ($signal?->payload ?? []);
        $meta = (array) data_get($payload, 'meta_agent', []);
        $constitution = (array) data_get($candidate->modelVersion?->metadata, 'agent_constitution', []);
        $allowed = (array) ($constitution['allowed_regimes'] ?? []);
        $mistakes = [];
        if ($profit <= 0 && str_contains($exitReason, 'stop')) $mistakes[] = 'stop_too_close';
        if ($profit <= 0 && str_contains($exitReason, 'time_stop')) $mistakes[] = 'target_too_far';
        if ($allowed !== [] && ! in_array($signal?->market_regime, $allowed, true)) $mistakes[] = 'regime_mismatch';
        if ((float) data_get($meta, 'expected_value.net_expected_value_percent', 0) > 0 && $profit <= 0) $mistakes[] = 'wrong_direction';
        if ((float) data_get($meta, 'expected_value.net_expected_value_percent', 0) <= 0 && $profit <= 0) $mistakes[] = 'cost_destroyed_edge';
        return [
            'protocol' => 'professional_self_audit_v1', 'predicted_direction' => $signal?->decision,
            'predicted_confidence' => $signal?->confidence, 'expected_value' => data_get($meta, 'expected_value', []),
            'actual_profit_percent' => round($profit, 5), 'exit_reason' => $exitReason,
            'market_regime' => $signal?->market_regime, 'volatility_regime' => $signal?->volatility_regime,
            'loss_taxonomy' => $mistakes, 'primary_failure' => $mistakes[0] ?? null,
            'rule' => 'This audit is learning evidence only; it never rewrites the immutable paper order.',
        ];
    }

    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private function aiRequest(ModelMarketPerformance $candidate, array $rows): array
    {
        $model = $candidate->modelVersion;
        return [
            'symbol' => $candidate->symbol, 'timeframe' => $candidate->timeframe,
            'strategy' => $model->strategy,
            'base_strategy' => data_get($model->metadata, 'base_strategy') ?: $candidate->strategy_family.'_v1',
            'parameters' => $model->parameters ?? [], 'candles' => $rows,
            'policy_context' => [
                'constitution' => data_get($model->metadata, 'agent_constitution', []),
                'sample_count' => (int) $candidate->sample_count,
                'calibration_score' => (float) data_get($model->metadata, 'capability_vector.calibration', 50),
                'stress_cost_pf' => (float) data_get($candidate->metrics, 'pf_attribution.stress_cost.profit_factor', 0),
            ],
            // The same execution profile is used by screening/full replay and
            // now by paper execution-contract generation.
            'initial_balance' => 10000, 'risk_per_trade' => 1,
            'execution' => [
                'spread_points' => str_starts_with($candidate->symbol, 'XAU') ? 20 : 12,
                'point_size' => str_starts_with($candidate->symbol, 'XAU') ? .01 : .00001,
                'commission_percent' => .01, 'slippage_points' => 2, 'swap_per_day_percent' => .002,
                'allowed_sessions_utc' => ['1-22'], 'intrabar_policy' => 'conservative', 'max_gap_multiple' => 96,
                'reject_unexpected_gaps' => false, 'stop_loss_percent' => .5, 'take_profit_percent' => 1, 'max_leverage' => 5,
            ],
        ];
    }
}
