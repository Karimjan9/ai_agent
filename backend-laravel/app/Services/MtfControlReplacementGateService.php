<?php

namespace App\Services;

use App\Models\MtfAblationRun;
use App\Models\ModelMarketPerformance;
use App\Models\PaperOrder;
use App\Models\PaperSignal;
use App\Models\PaperSignalPassport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only control replacement decision.
 *
 * A paper result can be better than a replay result only after the candidate
 * has a real official paper sample. This service reports readiness and never
 * mutates the frozen control or candidate lifecycle.
 */
class MtfControlReplacementGateService
{
    public const PROTOCOL = 'xauusd_mtf_control_replacement_gate_v1';

    public function __construct(private PaperEvidenceReadinessService $paperReadiness) {}

    /** @return array<string, mixed> */
    public function inspect(string $symbol = 'XAUUSD'): array
    {
        $symbol = strtoupper(str_replace(['/', '_', '-'], '', trim($symbol)));
        $control = Schema::hasTable('mtf_ablation_runs')
            ? MtfAblationRun::query()
                ->where('symbol', $symbol)
                ->where('entry_timeframe', 'M15')
                ->where('status', 'completed')
                ->latest('completed_at')
                ->first()
            : null;
        $controlMetrics = (array) data_get($control?->variants, 'h1_veto_m15_risk', []);
        $globalPaperReadiness = $this->paperReadiness->inspect();
        $candidates = $this->paperCandidates($symbol);
        $evaluations = $candidates->map(fn (ModelMarketPerformance $candidate): array =>
            $this->evaluateCandidate($candidate, $controlMetrics, $globalPaperReadiness)
        )->values()->all();
        $ready = collect($evaluations)->firstWhere('ready', true);

        return [
            'protocol' => self::PROTOCOL,
            'symbol' => $symbol,
            'status' => $ready ? 'ready_for_operator_review' : 'blocked',
            'replacement_authorized' => false,
            'promotion_evidence' => false,
            'control' => [
                'run_id' => $control?->id,
                'data_hash' => $control?->data_hash,
                'execution_hash' => $control?->execution_hash,
                'metrics' => $this->metrics($controlMetrics),
            ],
            'candidate_count' => count($evaluations),
            'candidates' => $evaluations,
            'global_paper_readiness' => $globalPaperReadiness,
            'selected_candidate_id' => $ready['candidate_id'] ?? null,
            'blocking_reasons' => $ready ? [] : $this->blockingReasons($control, $evaluations),
            'rule' => 'Replacement requires official paper evidence that beats the frozen control after costs; monitor never applies replacement.',
        ];
    }

    /** @return \Illuminate\Support\Collection<int, ModelMarketPerformance> */
    private function paperCandidates(string $symbol)
    {
        if (! Schema::hasTable('paper_signal_passports') || ! Schema::hasTable('model_market_performance')) {
            return collect();
        }

        $ids = PaperSignalPassport::query()
            ->where('symbol', $symbol)
            ->where('entry_timeframe', 'M15')
            ->where('lane', 'official')
            ->pluck('model_market_performance_id')
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) return collect();

        return ModelMarketPerformance::query()
            ->with('modelVersion')
            ->whereIn('id', $ids)
            ->whereIn('status', ['forward_validated', 'paper'])
            ->where('paper_status', '!=', 'failed')
            ->where('evidence_status', 'valid')
            ->latest('updated_at')
            ->get();
    }

    /** @return array<string, mixed> */
    private function evaluateCandidate(ModelMarketPerformance $candidate, array $control, array $globalPaperReadiness): array
    {
        $orders = Schema::hasTable('paper_orders')
            ? PaperOrder::query()
                ->where('model_market_performance_id', $candidate->id)
                ->where('evidence_status', 'valid')
                ->where('status', 'closed')
                ->orderBy('closed_at')
                ->get()
            : collect();
        $profits = $orders->pluck('profit_percent')->map(fn ($value): float => (float) $value)->all();
        $metrics = $this->metricsFromProfits($profits);
        $firstSignal = Schema::hasTable('paper_signals')
            ? PaperSignal::query()->where('model_market_performance_id', $candidate->id)->oldest('created_at')->first()
            : null;
        $days = $firstSignal?->created_at
            ? CarbonImmutable::parse($firstSignal->created_at)->diffInSeconds(CarbonImmutable::now('UTC')) / 86400
            : 0.0;
        $minimumSignals = max(1, (int) config('services.paper_observation.min_signals', 1000));
        $minimumTrades = max(1, (int) config('services.paper_observation.min_closed_trades', 200));
        $minimumDays = max(1, (int) config('services.paper_observation.min_days', 90));
        $signalCount = Schema::hasTable('paper_signals')
            ? PaperSignal::query()->where('model_market_performance_id', $candidate->id)->count()
            : 0;
        $controlPf = (float) ($control['profit_factor'] ?? 0);
        $controlDd = (float) ($control['max_drawdown_percent'] ?? 100);
        $reasons = [];
        if (! in_array((string) $candidate->status, ['forward_validated', 'paper'], true)) $reasons[] = 'candidate_not_forward_validated';
        if ((string) $candidate->paper_status === 'failed') $reasons[] = 'candidate_paper_failed';
        if ($signalCount < $minimumSignals) $reasons[] = 'paper_signal_sample_incomplete';
        if (count($profits) < $minimumTrades) $reasons[] = 'paper_closed_trade_sample_incomplete';
        if ($days < $minimumDays) $reasons[] = 'paper_observation_window_incomplete';
        if ($metrics['net_profit_percent'] <= 0 || $metrics['profit_factor'] < (float) config('services.paper_observation.min_profit_factor', 1.3)) {
            $reasons[] = 'candidate_paper_economic_gate_failed';
        }
        if ($metrics['max_drawdown_percent'] > (float) config('services.paper_observation.max_drawdown_percent', 15)) {
            $reasons[] = 'candidate_paper_drawdown_gate_failed';
        }
        if (! (bool) ($globalPaperReadiness['ready'] ?? false)) {
            $reasons[] = 'global_paper_readiness_incomplete';
        }
        if ($control === []) $reasons[] = 'frozen_control_missing';
        if ($control !== [] && ! (
            $metrics['profit_factor'] > $controlPf
            && $metrics['net_profit_percent'] > (float) ($control['net_profit_percent'] ?? 0)
            && $metrics['max_drawdown_percent'] <= $controlDd
        )) {
            $reasons[] = 'paper_result_does_not_beat_frozen_control';
        }

        return [
            'candidate_id' => $candidate->id,
            'strategy' => $candidate->modelVersion?->strategy,
            'status' => $candidate->status,
            'paper_status' => $candidate->paper_status,
            'ready' => $reasons === [],
            'reason_codes' => $reasons,
            'signals' => $signalCount,
            'closed_trades' => count($profits),
            'observation_days' => round($days, 2),
            'paper_metrics' => $metrics,
            'control_metrics' => $this->metrics($control),
            'promotion_evidence' => false,
            'replacement_authorized' => false,
        ];
    }

    /** @param array<string, mixed>|null $control @param list<array<string, mixed>> $evaluations @return list<string> */
    private function blockingReasons(?MtfAblationRun $control, array $evaluations): array
    {
        if (! $control) return ['frozen_control_missing'];
        if ($evaluations === []) return ['no_official_forward_paper_candidate'];
        return collect($evaluations)
            ->flatMap(fn (array $row): array => (array) ($row['reason_codes'] ?? []))
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string, float|int> */
    private function metrics(array $metrics): array
    {
        return [
            'total_trades' => (int) ($metrics['total_trades'] ?? 0),
            'profit_factor' => (float) ($metrics['profit_factor'] ?? 0),
            'net_profit_percent' => (float) ($metrics['net_profit_percent'] ?? 0),
            'max_drawdown_percent' => (float) ($metrics['max_drawdown_percent'] ?? 100),
        ];
    }

    /** @param list<float> $profits @return array<string, float|int> */
    private function metricsFromProfits(array $profits): array
    {
        $grossProfit = array_sum(array_filter($profits, fn (float $value): bool => $value > 0));
        $grossLoss = abs(array_sum(array_filter($profits, fn (float $value): bool => $value <= 0)));
        $balance = 10000.0;
        $peak = $balance;
        $drawdown = 0.0;
        foreach ($profits as $profit) {
            $balance *= 1 + $profit / 100;
            $peak = max($peak, $balance);
            $drawdown = max($drawdown, $peak > 0 ? (($peak - $balance) / $peak) * 100 : 100);
        }

        return [
            'total_trades' => count($profits),
            'profit_factor' => round($grossLoss > 0 ? $grossProfit / $grossLoss : ($grossProfit > 0 ? 99.0 : 0.0), 4),
            'net_profit_percent' => round(($balance - 10000) / 100, 4),
            'max_drawdown_percent' => round($drawdown, 4),
        ];
    }
}
