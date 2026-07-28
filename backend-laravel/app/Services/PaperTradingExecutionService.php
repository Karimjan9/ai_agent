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
            [
                'symbol' => $candidate->symbol,
                'timeframe' => $candidate->timeframe,
                'strategy' => $model->strategy,
                'base_strategy' => data_get($model->metadata, 'base_strategy') ?: $candidate->strategy_family.'_v1',
                'parameters' => $model->parameters ?? [],
                'candles' => $rows,
            ],
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
        if (! $calibrated['allowed']) {
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

        PaperSignal::create([
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

        $entry = (float) $entryCandle->open;
        $isBuy = $signal->decision === 'BUY';
        $executionSignal = array_merge($signal->payload, [
            'signal' => $signal->decision,
            'signal_time' => $signal->candle_time->toIso8601String(),
            'entry_candle_time' => $entryCandle->time->toIso8601String(),
            'price' => $entry,
            'stop_loss' => $entry * ($isBuy ? 0.995 : 1.005),
            'take_profit' => $entry * ($isBuy ? 1.01 : 0.99),
        ]);
        $risk = $this->risk->canOpen($candidate, $executionSignal);
        $baseUnits = (float) config('services.paper.units', 1);
        $stopRisk = abs($entry - $executionSignal['stop_loss']) / max($entry, 0.0000001) * 100
            + $risk['estimated_round_trip_cost_percent'];
        $sizeMultiple = min(5.0, (float) config('services.risk.max_risk_per_trade_percent', 1) / max($stopRisk, 0.000001));

        if (! $risk['allowed']) {
            PaperOrder::create([
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
            'signal_context' => ['signal' => $executionSignal, 'risk' => $risk, 'position_size_multiple' => $sizeMultiple],
            'broker_payload' => null,
        ]);
        $order->fills()->create([
            'fill_type' => 'entry',
            'price' => $entry,
            'cost_percent' => $risk['estimated_round_trip_cost_percent'] / 2,
            'filled_at' => $entryCandle->time,
            'payload' => null,
        ]);
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
            if ($order->paper_signal_id) {
                $outcome = PaperSignalOutcome::firstOrCreate(['paper_signal_id' => $order->paper_signal_id], [
                    'paper_order_id' => $order->id,
                    'outcome' => $profit > 0 ? 'win' : ($profit < 0 ? 'loss' : 'flat'),
                    'exit_price' => $price,
                    'profit_percent' => $profit,
                    'exit_reason' => $exitReason,
                    'payload' => ['broker' => $order->broker, 'closed_at' => now()->toIso8601String()],
                ]);
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
        $symbolId = Symbol::where('code', $order->symbol)->value('id');
        if (! $symbolId) return null;
        $candles = Candle::where('symbol_id', $symbolId)->where('timeframe', $order->timeframe)
            ->where('time', '>=', $order->opened_at)->orderBy('time')->get();

        foreach ($candles as $candle) {
            $open = (float) $candle->open;
            $stop = (float) $order->stop_loss;
            $target = (float) $order->take_profit;
            $isBuy = $order->direction === 'BUY';
            if (($isBuy && $open <= $stop) || (! $isBuy && $open >= $stop)) {
                return $this->paperExit($order, $open, 'gap_stop');
            }
            if (($isBuy && $open >= $target) || (! $isBuy && $open <= $target)) {
                return $this->paperExit($order, $open, 'gap_target');
            }

            $stopHit = $isBuy ? (float) $candle->low <= $stop : (float) $candle->high >= $stop;
            $targetHit = $isBuy ? (float) $candle->high >= $target : (float) $candle->low <= $target;
            if ($stopHit) return $this->paperExit($order, $stop, 'intrabar_stop');
            if ($targetHit) return $this->paperExit($order, $target, 'intrabar_target');
        }

        return null;
    }

    private function paperExit(PaperOrder $order, float $price, string $reason): array
    {
        $gross = $order->direction === 'BUY'
            ? ($price - $order->entry_price) / $order->entry_price * 100
            : ($order->entry_price - $price) / $order->entry_price * 100;
        $profit = ($gross - $this->risk->estimatedRoundTripCostPercent($order->symbol, (float) $order->entry_price))
            * (float) data_get($order->signal_context, 'position_size_multiple', 1);

        return [$price, round($profit, 4), $reason];
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

    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
