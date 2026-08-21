<?php

namespace App\Services;

use App\Models\Candle;
use App\Models\KnowledgeFact;
use App\Models\MarketDiscovery;
use App\Models\MarketGenome;
use App\Models\MarketMemory;
use App\Models\MarketSimilarityMatch;
use App\Models\MarketSpecies;
use App\Models\MarketStateSnapshot;
use App\Models\StrategySpeciesPerformance;
use App\Models\Symbol;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class MarketRealityService
{
    public function __construct(
        private FeatureValueCatalogService $featureCatalog,
        private FeatureSnapshotService $featureSnapshots,
    ) {}

    public function analyzeSymbol(Symbol $symbol, string $timeframe = 'H1', int $limit = 500): void
    {
        if (! Schema::hasTable('market_state_snapshots')) {
            return;
        }

        $candles = Candle::query()
            ->where('symbol_id', $symbol->id)
            ->where('timeframe', $timeframe)
            ->orderByDesc('time')
            ->limit($limit)
            ->get()
            ->sortBy('time')
            ->values();

        if ($candles->count() < 25) {
            return;
        }

        $this->featureCatalog->seed();
        $createdGenomes = collect();

        foreach ($candles as $index => $candle) {
            if ($index < 20) {
                continue;
            }

            $window = $candles->slice(max(0, $index - 20), 20)->values();
            $features = $this->features($candle, $window);
            $state = $this->classifyState($features);
            $this->featureSnapshots->capture($symbol->code, $timeframe, CarbonImmutable::parse($candle->time), $this->canonicalFeatureValues($features, $state));
            $species = $this->speciesFor($state, $features);
            $snapshot = $this->upsertSnapshot($symbol, $candle, $timeframe, $features, $state, $species);
            $this->upsertProbabilities($snapshot, $features, $state);
            $genome = $this->upsertGenome($snapshot, $species, $features);
            $this->createMemoryIfNeeded($snapshot, $genome, $features, $state);
            $createdGenomes->push($genome);
        }

        $latest = $createdGenomes->last();
        if ($latest) {
            $this->recordSimilarity($latest);
        }

        $this->extractDiscoveries();
    }

    public function recordStrategyPerformance(TrainingSession $session): void
    {
        if (! Schema::hasTable('strategy_species_performance')) {
            return;
        }

        $session->loadMissing('strategyScores');
        $species = $this->latestSpecies($session->symbol, $session->timeframe);

        if (! $species) {
            return;
        }

        foreach ($session->strategyScores as $score) {
            StrategySpeciesPerformance::updateOrCreate(
                [
                    'strategy_score_id' => $score->id,
                    'market_species_id' => $species->id,
                ],
                [
                    'training_session_id' => $session->id,
                    'strategy' => $score->strategy,
                    'species_code' => $species->code,
                    'species_name' => $species->name,
                    'trades' => (int) $score->total_trades,
                    'winrate' => (float) $score->winrate,
                    'profit_percent' => (float) $score->net_profit_percent,
                    'confidence_score' => $this->clamp(45 + min(35, (int) $score->total_trades / 4) + ((float) $score->profit_factor * 5)),
                    'evidence' => [
                        'score' => $score->score,
                        'profit_factor' => $score->profit_factor,
                        'drawdown' => $score->max_drawdown_percent,
                        'robustness_score' => $score->robustness_score,
                    ],
                ],
            );
        }
    }

    private function features(Candle $candle, Collection $window): array
    {
        $open = (float) $candle->open;
        $high = (float) $candle->high;
        $low = (float) $candle->low;
        $close = (float) $candle->close;
        $volume = (float) ($candle->volume ?? 0);
        $range = max(0.00001, $high - $low);
        $body = abs($close - $open);
        $avgRange = max(0.00001, (float) $window->avg(fn (Candle $item): float => (float) $item->high - (float) $item->low));
        $avgVolume = max(1, (float) $window->avg(fn (Candle $item): float => (float) ($item->volume ?? 0)));
        $firstClose = (float) $window->first()->close;
        $lastClose = (float) $window->last()->close;
        $trendMove = $lastClose - $firstClose;
        $pctChange = $open !== 0.0 ? (($close - $open) / $open) * 100 : 0;
        $windowHigh = (float) $window->max('high');
        $windowLow = (float) $window->min('low');
        $volumeRatio = $volume / $avgVolume;
        $rangeRatio = $range / $avgRange;

        $trendScore = $this->clamp(50 + (($trendMove / max(0.00001, $avgRange)) * 4));
        $panicScore = $this->clamp((abs($pctChange) * 18) + max(0, $volumeRatio - 1) * 20 + max(0, $rangeRatio - 1.5) * 20);
        $compressionScore = $this->clamp((1 / max(0.2, $rangeRatio)) * 45 + ($body / $range < 0.35 ? 25 : 0));
        $expansionScore = $this->clamp(max(0, $rangeRatio - 1) * 45 + max(0, $body / $range - 0.5) * 35);
        $momentumScore = $this->clamp(abs($trendMove / max(0.00001, $avgRange)) * 8 + abs($pctChange) * 10);
        $liquidityProxy = $this->clamp(50 + (($volumeRatio - 1) * 25) - max(0, $rangeRatio - 2) * 10);
        $breakoutUp = $high > $windowHigh && $close > $windowHigh;
        $breakoutDown = $low < $windowLow && $close < $windowLow;
        $fakeBreakout = ($high > $windowHigh && $close < $windowHigh) || ($low < $windowLow && $close > $windowLow);

        return [
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => $volume,
            'range' => round($range, 5),
            'avg_range' => round($avgRange, 5),
            'volume_ratio' => round($volumeRatio, 4),
            'range_ratio' => round($rangeRatio, 4),
            'pct_change' => round($pctChange, 4),
            'trend_score' => round($trendScore, 2),
            'panic_score' => round($panicScore, 2),
            'compression_score' => round($compressionScore, 2),
            'expansion_score' => round($expansionScore, 2),
            'momentum_score' => round($momentumScore, 2),
            'liquidity_proxy_score' => round($liquidityProxy, 2),
            'breakout_up' => $breakoutUp,
            'breakout_down' => $breakoutDown,
            'fake_breakout' => $fakeBreakout,
        ];
    }

    /** @return array<string,mixed> */
    private function canonicalFeatureValues(array $features, array $state): array
    {
        return [
            ...$features,
            'body' => abs((float) $features['close'] - (float) $features['open']),
            'wick' => (float) $features['range'] - abs((float) $features['close'] - (float) $features['open']),
            'true_range' => (float) $features['range'], 'atr' => (float) $features['avg_range'],
            'relative_volume' => (float) $features['volume_ratio'], 'displacement_atr' => (float) $features['range_ratio'],
            'liquidity_quality' => (float) $features['liquidity_proxy_score'],
            'volatility_state' => $features['expansion_score'] >= 70 ? 'high' : ($features['compression_score'] >= 70 ? 'low' : 'normal'),
            'state_confidence' => (float) $state['confidence_score'] / 100,
            'transition_hazard' => $features['fake_breakout'] ? .75 : min(.5, (float) $features['panic_score'] / 200),
            'regime_probability' => (float) $state['confidence_score'] / 100,
            'lookahead_safe' => true,
        ];
    }

    private function classifyState(array $features): array
    {
        $marketState = 'balanced_range';
        $structureState = 'range';

        if ($features['panic_score'] >= 75 && $features['liquidity_proxy_score'] < 45) {
            $marketState = 'liquidity_vacuum';
        } elseif ($features['panic_score'] >= 75) {
            $marketState = 'panic';
        } elseif ($features['fake_breakout']) {
            $marketState = 'volatile_fake_breakout';
            $structureState = 'trap';
        } elseif ($features['compression_score'] >= 75) {
            $marketState = 'compression';
        } elseif ($features['expansion_score'] >= 70 && $features['trend_score'] >= 60) {
            $marketState = 'bull_expansion';
            $structureState = 'breakout';
        } elseif ($features['expansion_score'] >= 70 && $features['trend_score'] < 40) {
            $marketState = 'bear_expansion';
            $structureState = 'breakout';
        } elseif ($features['trend_score'] >= 70 && $features['momentum_score'] >= 55) {
            $marketState = 'slow_bull_expansion';
            $structureState = 'trend';
        } elseif ($features['trend_score'] <= 30 && $features['momentum_score'] >= 55) {
            $marketState = 'bear_trend_pressure';
            $structureState = 'trend';
        }

        if ($features['breakout_up'] || $features['breakout_down']) {
            $structureState = 'breakout';
        }

        return [
            'market_state' => $marketState,
            'liquidity_state' => $features['liquidity_proxy_score'] >= 65 ? 'high_proxy' : ($features['liquidity_proxy_score'] <= 35 ? 'low_proxy' : 'normal_proxy'),
            'momentum_state' => $features['momentum_score'] >= 70 ? 'strong' : ($features['momentum_score'] <= 30 ? 'weak' : 'normal'),
            'structure_state' => $structureState,
            'confidence_score' => $this->confidence($features, $marketState),
        ];
    }

    private function speciesFor(array $state, array $features): MarketSpecies
    {
        $name = match ($state['market_state']) {
            'slow_bull_expansion' => 'Slow Bull Expansion',
            'volatile_fake_breakout' => 'Volatile Fake Breakout',
            'liquidity_vacuum' => 'Liquidity Vacuum',
            'panic' => 'Fear Expansion',
            'compression' => 'Volatility Compression',
            'bull_expansion' => 'Bull Expansion',
            'bear_expansion' => 'Bear Expansion',
            'bear_trend_pressure' => 'Bear Trend Pressure',
            default => 'Balanced Range',
        };
        $code = 'SPC_'.strtoupper(substr(hash('crc32b', $name), 0, 6));
        $danger = $this->clamp(($features['panic_score'] * 0.45) + ((100 - $features['liquidity_proxy_score']) * 0.25) + ($features['fake_breakout'] ? 25 : 0));
        $opportunity = $this->clamp(($features['trend_score'] * 0.35) + ($features['momentum_score'] * 0.30) + ($features['expansion_score'] * 0.20) - ($danger * 0.15));

        $species = MarketSpecies::updateOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'dominant_state' => $state['market_state'],
                'description' => "{$name} inferred from OHLCV-derived trend, momentum, compression, expansion and liquidity_proxy features.",
                'danger_score' => round($danger, 2),
                'opportunity_score' => round($opportunity, 2),
                'signature' => [
                    'market_state' => $state['market_state'],
                    'liquidity_state' => $state['liquidity_state'],
                    'momentum_state' => $state['momentum_state'],
                    'structure_state' => $state['structure_state'],
                ],
            ],
        );

        $species->versions()->updateOrCreate(
            ['version' => 1],
            [
                'signature' => $species->signature,
                'confidence_score' => $state['confidence_score'],
                'sample_size' => $species->snapshots()->count() + 1,
            ],
        );

        return $species;
    }

    private function upsertSnapshot(Symbol $symbol, Candle $candle, string $timeframe, array $features, array $state, MarketSpecies $species): MarketStateSnapshot
    {
        return MarketStateSnapshot::updateOrCreate(
            [
                'symbol' => $symbol->code,
                'timeframe' => $timeframe,
                'time' => $candle->time,
            ],
            [
                'symbol_id' => $symbol->id,
                'candle_id' => $candle->id,
                'market_species_id' => $species->id,
                'market_state' => $state['market_state'],
                'liquidity_state' => $state['liquidity_state'],
                'momentum_state' => $state['momentum_state'],
                'structure_state' => $state['structure_state'],
                'confidence_score' => $state['confidence_score'],
                'trend_score' => $features['trend_score'],
                'panic_score' => $features['panic_score'],
                'compression_score' => $features['compression_score'],
                'expansion_score' => $features['expansion_score'],
                'momentum_score' => $features['momentum_score'],
                'liquidity_proxy_score' => $features['liquidity_proxy_score'],
                'features' => $features,
                'explanation' => $this->explanation($state, $features, $species),
            ],
        );
    }

    private function upsertProbabilities(MarketStateSnapshot $snapshot, array $features, array $state): void
    {
        $scores = [
            $state['market_state'] => $state['confidence_score'],
            'panic' => $features['panic_score'],
            'compression' => $features['compression_score'],
            'bull_expansion' => $features['trend_score'] >= 50 ? $features['expansion_score'] : 0,
            'volatile_fake_breakout' => $features['fake_breakout'] ? 78 : 8,
            'balanced_range' => max(0, 100 - $features['momentum_score'] - ($features['expansion_score'] / 2)),
        ];

        $total = max(1, array_sum($scores));

        foreach ($scores as $stateName => $score) {
            $snapshot->probabilities()->updateOrCreate(
                ['state' => $stateName],
                ['probability' => round($score / $total, 4)],
            );
        }
    }

    private function upsertGenome(MarketStateSnapshot $snapshot, MarketSpecies $species, array $features): MarketGenome
    {
        $vector = [
            'trend' => $features['trend_score'],
            'panic' => $features['panic_score'],
            'compression' => $features['compression_score'],
            'momentum' => $features['momentum_score'],
            'liquidity_proxy' => $features['liquidity_proxy_score'],
            'expansion' => $features['expansion_score'],
        ];
        $hash = hash('sha256', json_encode([
            'symbol' => $snapshot->symbol,
            'timeframe' => $snapshot->timeframe,
            'time' => $snapshot->time->toDateTimeString(),
            'vector' => $vector,
        ], JSON_THROW_ON_ERROR));

        return MarketGenome::updateOrCreate(
            ['genome_hash' => $hash],
            [
                'market_state_snapshot_id' => $snapshot->id,
                'market_species_id' => $species->id,
                'symbol' => $snapshot->symbol,
                'timeframe' => $snapshot->timeframe,
                'time' => $snapshot->time,
                'vector' => $vector,
                'trend' => $vector['trend'],
                'panic' => $vector['panic'],
                'compression' => $vector['compression'],
                'momentum' => $vector['momentum'],
                'liquidity_proxy' => $vector['liquidity_proxy'],
            ],
        );
    }

    private function createMemoryIfNeeded(MarketStateSnapshot $snapshot, MarketGenome $genome, array $features, array $state): void
    {
        if ($features['panic_score'] < 75 && ! $features['fake_breakout'] && $features['compression_score'] < 82) {
            return;
        }

        MarketMemory::firstOrCreate([
            'market_state_snapshot_id' => $snapshot->id,
            'memory_type' => $features['fake_breakout'] ? 'trap_event' : 'market_event',
        ], [
            'market_species_id' => $snapshot->market_species_id,
            'symbol' => $snapshot->symbol,
            'timeframe' => $snapshot->timeframe,
            'market_state' => $state['market_state'],
            'summary' => "{$snapshot->symbol} {$snapshot->timeframe} entered {$state['market_state']} at {$snapshot->time->format('Y-m-d H:i')}.",
            'lesson' => $this->memoryLesson($state['market_state']),
            'strength' => max($features['panic_score'], $features['compression_score'], $features['expansion_score']),
            'evidence' => [
                'genome_id' => $genome->id,
                'features' => $features,
                'species' => $snapshot->marketSpecies?->name,
            ],
        ]);
    }

    private function recordSimilarity(MarketGenome $current): void
    {
        $past = MarketGenome::query()
            ->where('id', '<>', $current->id)
            ->where('symbol', $current->symbol)
            ->where('timeframe', $current->timeframe)
            ->latest('time')
            ->take(200)
            ->get()
            ->map(fn (MarketGenome $genome): array => [
                'genome' => $genome,
                'score' => $this->similarity($current->vector, $genome->vector),
            ])
            ->filter(fn (array $item): bool => $item['score'] >= 80)
            ->sortByDesc('score')
            ->take(5);

        foreach ($past as $item) {
            MarketSimilarityMatch::updateOrCreate(
                [
                    'current_market_genome_id' => $current->id,
                    'matched_market_genome_id' => $item['genome']->id,
                ],
                [
                    'similarity_score' => round($item['score'], 2),
                    'lesson' => "Current market is {$item['score']}% similar to {$item['genome']->time->format('Y-m-d H:i')}. Review memories before increasing confidence.",
                ],
            );
        }
    }

    private function extractDiscoveries(): void
    {
        $states = MarketStateSnapshot::query()
            ->selectRaw('market_state, count(*) as evidence_count, avg(confidence_score) as avg_confidence, avg(panic_score) as avg_panic, avg(compression_score) as avg_compression')
            ->groupBy('market_state')
            ->get();

        foreach ($states as $state) {
            if ((int) $state->evidence_count < 5) {
                continue;
            }

            $confidence = $this->clamp((float) $state->avg_confidence + min(20, (int) $state->evidence_count));
            $title = "Market state {$state->market_state} repeated {$state->evidence_count} times";
            $discovery = "{$state->market_state} appears as a recurring market species pattern with avg confidence ".round((float) $state->avg_confidence, 2).'%.';

            MarketDiscovery::updateOrCreate(
                ['title' => $title],
                [
                    'discovery' => $discovery,
                    'market_state' => $state->market_state,
                    'confidence_score' => round($confidence, 2),
                    'evidence_count' => (int) $state->evidence_count,
                    'status' => $confidence >= 85 ? 'validated' : 'provisional',
                    'metadata' => [
                        'avg_panic' => round((float) $state->avg_panic, 2),
                        'avg_compression' => round((float) $state->avg_compression, 2),
                    ],
                ],
            );

            if (Schema::hasTable('knowledge_facts')) {
                KnowledgeFact::firstOrCreate([
                    'title' => "Market discovery: {$state->market_state}",
                    'source_type' => MarketDiscovery::class,
                ], [
                    'fact' => $discovery,
                    'scope' => ['market_state' => $state->market_state],
                    'confidence_score' => round($confidence, 2),
                    'evidence_count' => (int) $state->evidence_count,
                    'status' => $confidence >= 85 ? 'validated' : 'provisional',
                    'discovered_at' => now(),
                    'last_seen_at' => now(),
                    'metadata' => ['source' => 'MarketRealityService'],
                ]);
            }
        }
    }

    private function latestSpecies(string $symbol, string $timeframe): ?MarketSpecies
    {
        return MarketStateSnapshot::query()
            ->with('marketSpecies')
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->latest('time')
            ->first()
            ?->marketSpecies;
    }

    private function confidence(array $features, string $state): float
    {
        $dominant = max(
            $features['panic_score'],
            $features['compression_score'],
            $features['expansion_score'],
            $features['momentum_score'],
        );

        return $this->clamp(45 + ($dominant * 0.45) + ($state !== 'balanced_range' ? 12 : 0));
    }

    private function explanation(array $state, array $features, MarketSpecies $species): string
    {
        return "{$species->name}: trend={$features['trend_score']}, momentum={$features['momentum_score']}, compression={$features['compression_score']}, panic={$features['panic_score']}, liquidity_proxy={$features['liquidity_proxy_score']}.";
    }

    private function memoryLesson(string $marketState): string
    {
        return match ($marketState) {
            'volatile_fake_breakout' => 'Reduce breakout confidence until follow-through confirms.',
            'liquidity_vacuum' => 'Lower position confidence; OHLCV liquidity_proxy indicates unstable movement.',
            'panic' => 'Expect burst behavior and wider error bars.',
            'compression' => 'Prepare for expansion but avoid assuming direction too early.',
            default => 'Compare similar historical market genomes before increasing risk.',
        };
    }

    private function similarity(array $a, array $b): float
    {
        $keys = ['trend', 'panic', 'compression', 'momentum', 'liquidity_proxy'];
        $distance = 0;

        foreach ($keys as $key) {
            $distance += abs((float) ($a[$key] ?? 0) - (float) ($b[$key] ?? 0));
        }

        return $this->clamp(100 - ($distance / count($keys)));
    }

    private function clamp(float $value, float $min = 0, float $max = 100): float
    {
        return max($min, min($max, $value));
    }
}
