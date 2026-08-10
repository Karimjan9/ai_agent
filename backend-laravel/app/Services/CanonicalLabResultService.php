<?php

namespace App\Services;

use App\Models\LabCandleDecisionEvent;
use App\Models\LabEvaluationRun;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only projection of the canonical laboratory evidence plane.
 *
 * lab_evaluation_runs is the source of truth for replay facts. Mutable
 * champion/performance tables may be used for selection, but they must never
 * be used to invent dashboard or report metrics when no immutable run exists.
 */
class CanonicalLabResultService
{
    public const SOURCE = 'lab_evaluation_runs';

    /** @return Builder<LabEvaluationRun> */
    public function completedRuns(): Builder
    {
        return LabEvaluationRun::query()
            ->with(['agent.modelVersion', 'modelVersion', 'generation'])
            ->where('status', 'completed')
            ->whereIn('phase', ['screening', 'full_validation', 'manual_backtest'])
            ->whereNotNull('finished_at');
    }

    public function latest(): ?LabEvaluationRun
    {
        // The dashboard is also reachable during a first install and from
        // lightweight test schemas. Missing canonical storage means
        // "no evidence yet", not a 500 and never a fallback to mutable
        // performance tables.
        if (! Schema::hasTable('lab_evaluation_runs')) {
            return null;
        }

        return $this->completedRuns()
            ->latest('finished_at')
            ->latest('id')
            ->first();
    }

    public function forDate(CarbonInterface|string $date): Collection
    {
        if (! Schema::hasTable('lab_evaluation_runs')) {
            return collect();
        }

        return $this->completedRuns()
            ->whereDate('finished_at', $date)
            ->orderBy('finished_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Convert the different evaluator metric envelopes into one stable UI
     * contract. Missing values stay zero/null; no historical fallback is
     * allowed here because that would reintroduce the hardcoded dashboard bug.
     */
    public function summary(LabEvaluationRun $run): array
    {
        $metrics = (array) $run->metrics;
        $result = (array) data_get($metrics, 'agent_result', data_get($metrics, 'result', $metrics));
        if (isset($result['result']) && is_array($result['result'])) {
            $result = $result['result'];
        }

        $trades = (int) ($result['total_trades'] ?? data_get($metrics, 'total_trades', 0));
        $wins = (int) ($result['wins'] ?? data_get($metrics, 'wins', 0));
        $losses = (int) ($result['losses'] ?? data_get($metrics, 'losses', 0));
        $winrate = $result['winrate'] ?? $result['win_rate'] ?? data_get($metrics, 'winrate');
        $winrate = $winrate === null && $trades > 0 ? ($wins / $trades) * 100 : $winrate;

        $request = (array) data_get($run->request_meta, 'payload', []);
        $symbol = (string) ($run->agent?->symbol
            ?? data_get($result, 'symbol', data_get($result, 'instrument', data_get($request, 'symbol', ''))));
        $timeframe = (string) ($run->agent?->timeframe ?? data_get($result, 'timeframe', data_get($request, 'timeframe', '')));
        $strategy = (string) ($run->modelVersion?->strategy
            ?? $run->agent?->modelVersion?->strategy
            ?? data_get($result, 'strategy', data_get($request, 'strategy', '')));
        $period = (string) data_get($result, 'period', '');
        if ($period === '') {
            $from = data_get($request, 'from_date');
            $to = data_get($request, 'to_date');
            $period = $from || $to ? sprintf('%s - %s', $from ?: '—', $to ?: '—') : '—';
        }

        return [
            'source' => self::SOURCE,
            'run_id' => $run->run_id,
            'phase' => $run->phase,
            'finished_at' => $run->finished_at,
            'strategy' => $strategy !== '' ? $strategy : '—',
            'instrument' => $this->displaySymbol($symbol),
            'symbol' => $symbol !== '' ? $symbol : null,
            'timeframe' => $timeframe !== '' ? $timeframe : '—',
            'period' => $period,
            'trades' => $trades,
            'wins' => $wins,
            'losses' => $losses,
            'winrate' => $winrate === null ? null : round((float) $winrate, 2),
            'profit_factor' => $this->number($result['profit_factor'] ?? data_get($metrics, 'profit_factor')),
            'max_drawdown' => $this->number($result['max_drawdown_percent'] ?? $result['max_drawdown'] ?? data_get($metrics, 'max_drawdown_percent')),
            'net_profit' => $this->number($result['net_profit_percent'] ?? data_get($metrics, 'net_profit_percent')),
            'conclusion' => (string) ($result['conclusion'] ?? data_get($metrics, 'conclusion', '')),
            'metrics' => $result,
        ];
    }

    public function aggregate(Collection $runs): array
    {
        $summaries = $runs->map(fn (LabEvaluationRun $run): array => $this->summary($run));
        $totalTrades = (int) $summaries->sum('trades');
        $totalWins = (int) $summaries->sum('wins');
        $totalLosses = (int) $summaries->sum('losses');
        $winrate = $totalTrades > 0 ? round(($totalWins / $totalTrades) * 100, 2) : 0.0;

        return [
            'source' => self::SOURCE,
            'runs' => $summaries,
            'total_backtests' => $summaries->count(),
            'total_trades' => $totalTrades,
            'total_wins' => $totalWins,
            'total_losses' => $totalLosses,
            'average_winrate' => $winrate,
            'average_profit' => $this->average($summaries->pluck('net_profit')),
            'average_profit_factor' => $this->average($summaries->pluck('profit_factor')),
            'average_drawdown' => $this->average($summaries->pluck('max_drawdown')),
            'symbol' => $this->singleOrNull($summaries->pluck('symbol')),
            'timeframe' => $this->singleOrNull($summaries->pluck('timeframe')),
            'strategy' => $this->singleOrNull($summaries->pluck('strategy')),
        ];
    }

    /** @return array<int, array{type:string, count:int}> */
    public function topMistakes(CarbonInterface|string $date): array
    {
        if (! class_exists(LabCandleDecisionEvent::class)
            || ! Schema::hasTable('lab_candle_decision_events')) {
            return [];
        }

        $runIds = $this->forDate($date)->pluck('run_id');
        if ($runIds->isEmpty()) {
            return [];
        }

        return DB::table('lab_candle_decision_events')
            ->whereIn('run_id', $runIds)
            ->whereNotNull('rejection_code')
            ->select('rejection_code')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('rejection_code')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($item): array => [
                'type' => (string) $item->rejection_code,
                'count' => (int) $item->total,
            ])
            ->values()
            ->all();
    }

    /** @return Collection<int, object> */
    public function latestRejections(int $limit = 20): Collection
    {
        if (! Schema::hasTable('lab_candle_decision_events')) {
            return collect();
        }

        $runIds = $this->completedRuns()->select('run_id');

        return DB::table('lab_candle_decision_events')
            ->whereIn('run_id', $runIds)
            ->whereNotNull('rejection_code')
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(static fn (object $event): object => (object) [
                'mistake_type' => (string) $event->rejection_code,
                'description' => 'Canonical lab rejection: '.(string) $event->rejection_code,
                'suggestion' => 'Bu rejection uchun yangi evidence va sabab taqsimotini tekshiring.',
                'backtest_run_id' => (string) ($event->run_id ?? '—'),
            ]);
    }

    private function displaySymbol(string $symbol): string
    {
        $normalized = strtoupper(str_replace(['_', '/'], '', trim($symbol)));
        if (strlen($normalized) === 6) {
            return substr($normalized, 0, 3).'/'.substr($normalized, 3);
        }

        return $symbol !== '' ? $symbol : '—';
    }

    private function number(mixed $value): float
    {
        return $value === null || $value === '' ? 0.0 : round((float) $value, 2);
    }

    private function average(Collection $values): float
    {
        $values = $values->filter(fn ($value): bool => $value !== null);

        return $values->isEmpty() ? 0.0 : round((float) $values->avg(), 2);
    }

    private function singleOrNull(Collection $values): ?string
    {
        $values = $values->filter(fn ($value): bool => $value !== null && $value !== '—')->unique()->values();

        return $values->count() === 1 ? (string) $values->first() : null;
    }
}
