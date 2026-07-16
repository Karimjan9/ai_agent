<?php

namespace App\Services;

use App\Models\MarketStateSnapshot;
use App\Models\MarketSymbol;
use App\Models\SignalMarketSnapshot;
use App\Models\StrategyScore;
use App\Models\SymbolProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class InstrumentIntelligenceService
{
    public function refresh(?array $symbols = null, array $timeframes = ['M15', 'H1']): Collection
    {
        if (! Schema::hasTable('symbol_profiles')) {
            return collect();
        }

        $query = MarketSymbol::query()->where('is_active', true)->orderBy('priority');

        if ($symbols) {
            $query->whereIn('symbol', $symbols);
        }

        return $query->get()
            ->flatMap(fn (MarketSymbol $marketSymbol): Collection => collect($timeframes)
                ->map(fn (string $timeframe): SymbolProfile => $this->refreshOne($marketSymbol, $timeframe)))
            ->values();
    }

    private function refreshOne(MarketSymbol $marketSymbol, string $timeframe): SymbolProfile
    {
        $scores = StrategyScore::query()
            ->where('symbol', $marketSymbol->symbol)
            ->where('timeframe', $timeframe)
            ->latest()
            ->take(200)
            ->get();
        $signals = Schema::hasTable('signal_market_snapshots')
            ? SignalMarketSnapshot::query()->where('symbol', $marketSymbol->symbol)->where('timeframe', $timeframe)->latest()->take(500)->get()
            : collect();
        $states = MarketStateSnapshot::query()
            ->where('symbol', $marketSymbol->symbol)
            ->where('timeframe', $timeframe)
            ->latest('time')
            ->take(500)
            ->get();

        $strategyStats = $this->strategyStats($scores);
        $sessionStats = $this->sessionStats($states);
        $bestStrategy = collect($strategyStats)->sortByDesc('score')->keys()->first();
        $worstStrategy = collect($strategyStats)->sortBy('score')->keys()->first();
        $bestSession = collect($sessionStats)->sortByDesc('opportunity_score')->keys()->first();
        $worstSession = collect($sessionStats)->sortBy('opportunity_score')->keys()->first();
        $currentRegime = $states->first()?->market_state;
        $volatility = $this->volatilityScore($states);
        $trendCleanliness = $this->trendCleanliness($states);
        $newsSensitivity = $this->newsSensitivity($marketSymbol, $volatility);
        $observations = max($scores->count(), $signals->count(), $states->count());

        return SymbolProfile::updateOrCreate(
            ['symbol' => $marketSymbol->symbol, 'timeframe' => $timeframe],
            [
                'market_symbol_id' => $marketSymbol->id,
                'category' => $marketSymbol->category ?: $marketSymbol->market_type,
                'best_session' => $bestSession,
                'worst_session' => $worstSession,
                'best_strategy' => $bestStrategy,
                'worst_strategy' => $worstStrategy,
                'current_regime' => $currentRegime,
                'news_sensitivity_score' => round($newsSensitivity, 2),
                'volatility_profile_score' => round($volatility, 2),
                'trend_cleanliness_score' => round($trendCleanliness, 2),
                'winrate' => round((float) $scores->avg('winrate'), 2),
                'profit_factor' => round((float) $scores->avg('profit_factor'), 2),
                'signals_count' => $signals->count(),
                'paper_trades_count' => $signals->whereIn('signal_type', ['paper', 'paper_candidate'])->count(),
                'observations_count' => $observations,
                'confidence_score' => round(min(95, 35 + min(35, $observations * 1.5) + ($scores->count() > 0 ? 15 : 0) + ($signals->count() > 0 ? 10 : 0)), 2),
                'summary' => $this->summary($marketSymbol->symbol, $timeframe, $bestSession, $bestStrategy, $currentRegime),
                'session_stats' => $sessionStats,
                'strategy_stats' => $strategyStats,
                'metadata' => [
                    'profile_source' => 'instrument_intelligence_foundation',
                    'scores_count' => $scores->count(),
                    'signals_count' => $signals->count(),
                    'market_states_count' => $states->count(),
                ],
            ],
        );
    }

    private function strategyStats(Collection $scores): array
    {
        return $scores->groupBy('strategy')
            ->map(fn (Collection $items): array => [
                'score' => round((float) $items->avg('score'), 2),
                'winrate' => round((float) $items->avg('winrate'), 2),
                'profit_factor' => round((float) $items->avg('profit_factor'), 2),
                'observations' => $items->count(),
            ])
            ->all();
    }

    private function sessionStats(Collection $states): array
    {
        if ($states->isEmpty()) {
            return [
                'asian' => ['opportunity_score' => 50, 'observations' => 0],
                'london' => ['opportunity_score' => 50, 'observations' => 0],
                'new_york' => ['opportunity_score' => 50, 'observations' => 0],
                'london_new_york_overlap' => ['opportunity_score' => 50, 'observations' => 0],
            ];
        }

        return $states->groupBy(fn (MarketStateSnapshot $state): string => $this->sessionFor((int) $state->time->format('H')))
            ->map(fn (Collection $items): array => [
                'opportunity_score' => round((float) $items->avg('trend_score') * 0.45 + (float) $items->avg('momentum_score') * 0.35 + (float) $items->avg('liquidity_proxy_score') * 0.2, 2),
                'observations' => $items->count(),
            ])
            ->all();
    }

    private function sessionFor(int $hour): string
    {
        if ($hour >= 12 && $hour <= 16) {
            return 'london_new_york_overlap';
        }

        if ($hour >= 7 && $hour < 12) {
            return 'london';
        }

        if ($hour > 16 && $hour <= 21) {
            return 'new_york';
        }

        return 'asian';
    }

    private function volatilityScore(Collection $states): float
    {
        if ($states->isEmpty()) {
            return 50;
        }

        return min(100, max(0, (float) $states->avg('expansion_score') * 0.55 + (float) $states->avg('panic_score') * 0.45));
    }

    private function trendCleanliness(Collection $states): float
    {
        if ($states->isEmpty()) {
            return 50;
        }

        return min(100, max(0, (float) $states->avg('trend_score') * 0.65 + (100 - (float) $states->avg('compression_score')) * 0.35));
    }

    private function newsSensitivity(MarketSymbol $symbol, float $volatility): float
    {
        $base = $symbol->symbol === 'XAUUSD' ? 78 : ($symbol->symbol === 'EURUSD' ? 48 : 55);

        return min(100, max(0, ($base * 0.7) + ($volatility * 0.3)));
    }

    private function summary(string $symbol, string $timeframe, ?string $bestSession, ?string $bestStrategy, ?string $regime): string
    {
        return "{$symbol} {$timeframe} profile: best session ".($bestSession ?: 'unknown').", best strategy ".($bestStrategy ?: 'unknown').", current regime ".($regime ?: 'unknown').'.';
    }
}
