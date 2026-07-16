<?php

namespace App\Services;

use App\Models\AgentBelief;
use App\Models\AgentHypothesis;
use App\Models\KnowledgeFact;
use App\Models\ScientistJournal;
use App\Models\StrategyScore;
use App\Models\TrainingSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class TradingScientistService
{
    public function recordTrainingSession(TrainingSession $session): void
    {
        if (! Schema::hasTable('agent_hypotheses')) {
            return;
        }

        $session->loadMissing(['strategyScores.dnaProfile']);

        foreach ($session->strategyScores as $score) {
            if ($score->agentHypotheses()->exists()) {
                continue;
            }

            $hypotheses = $this->createHypotheses($session, $score);
            $this->updateBeliefs($score, $hypotheses);
        }

        $session->load(['strategyScores.agentHypotheses', 'strategyScores.dnaProfile']);
        $this->writeJournal($session);
        $this->extractKnowledge($session);
    }

    private function createHypotheses(TrainingSession $session, StrategyScore $score): Collection
    {
        $trades = collect(data_get($score->raw_result, 'trades', []));

        if ($trades->isEmpty()) {
            $trades = collect([$this->syntheticTrade($score)]);
        }

        return $trades
            ->take(20)
            ->map(fn (array $trade): AgentHypothesis => $this->createHypothesis($session, $score, $trade));
    }

    private function createHypothesis(TrainingSession $session, StrategyScore $score, array $trade): AgentHypothesis
    {
        $profit = (float) data_get($trade, 'profit_percent', $score->net_profit_percent ?? 0);
        $status = $this->hypothesisStatus($profit, (string) data_get($trade, 'result', ''));
        $decision = $this->decisionFromTrade($trade);
        $marketRegime = data_get($trade, 'market_regime') ?: $this->dominantRegime($score);
        $volatilityRegime = data_get($trade, 'volatility_regime') ?: $this->dominantVolatility($score);
        $confidence = $this->decisionConfidence($score);
        $hypothesis = $this->hypothesisText($score->strategy, $decision, $marketRegime);

        $agentHypothesis = AgentHypothesis::create([
            'training_session_id' => $session->id,
            'strategy_score_id' => $score->id,
            'strategy' => $score->strategy,
            'symbol' => $score->symbol,
            'timeframe' => $score->timeframe,
            'decision' => $decision,
            'confidence' => $confidence,
            'market_regime' => $marketRegime,
            'volatility_regime' => $volatilityRegime,
            'hypothesis' => $hypothesis,
            'measurable_target' => [
                'horizon_candles' => 20,
                'expected_move_atr' => 1.5,
                'success_condition' => 'Trade direction should produce positive risk-adjusted outcome before exit.',
            ],
            'horizon_candles' => 20,
            'expected_move_atr' => 1.5,
            'actual_outcome' => [
                'result' => data_get($trade, 'result'),
                'profit_percent' => $profit,
                'entry_time' => data_get($trade, 'entry_time'),
                'exit_time' => data_get($trade, 'exit_time'),
                'exit_price' => data_get($trade, 'exit_price'),
            ],
            'status' => $status,
            'evaluation_summary' => $this->evaluationSummary($status, $profit, $marketRegime, $volatilityRegime),
            'evidence_snapshot' => [
                'score' => $score->score,
                'winrate' => $score->winrate,
                'profit_factor' => $score->profit_factor,
                'drawdown' => $score->max_drawdown_percent,
                'robustness_score' => $score->robustness_score,
                'is_overfit' => $score->is_overfit,
                'monte_carlo' => [
                    'risk_of_ruin_percent' => $score->mc_risk_of_ruin_percent,
                    'worst_drawdown_percent' => $score->mc_worst_drawdown_percent,
                ],
                'strategy_dna' => $score->dnaProfile?->only([
                    'aggression_score',
                    'trend_dependency',
                    'range_dependency',
                    'volatility_sensitivity',
                    'adaptability_score',
                    'recovery_score',
                    'survival_score',
                ]),
            ],
            'evaluated_at' => now(),
        ]);

        if ($status === 'failed') {
            $this->createCounterfactuals($session, $score, $agentHypothesis, $trade);
        }

        return $agentHypothesis;
    }

    private function createCounterfactuals(
        TrainingSession $session,
        StrategyScore $score,
        AgentHypothesis $hypothesis,
        array $trade,
    ): void {
        $profit = (float) data_get($trade, 'profit_percent', 0);
        $scenarios = [
            [
                'name' => 'delayed_entry_2_candles',
                'intervention' => ['entry_delay_candles' => 2],
                'lift' => max(0.25, abs($profit) * 0.35),
            ],
            [
                'name' => 'wider_atr_stop',
                'intervention' => ['stop_loss_atr_multiplier' => 1.5],
                'lift' => max(0.20, abs($profit) * 0.25),
            ],
        ];

        if (str_contains($score->strategy, 'rsi')) {
            $scenarios[] = [
                'name' => 'without_rsi_filter',
                'intervention' => ['remove_filter' => 'rsi_confirmation'],
                'lift' => max(0.75, abs($profit) * 0.75),
            ];
        }

        if (str_contains($score->strategy, 'breakout')) {
            $scenarios[] = [
                'name' => 'stricter_breakout_confirmation',
                'intervention' => ['confirmation_candles' => 2],
                'lift' => max(0.40, abs($profit) * 0.50),
            ];
        }

        foreach ($scenarios as $scenario) {
            $alternativeProfit = round($profit + $scenario['lift'], 2);

            $hypothesis->counterfactualRuns()->create([
                'strategy_score_id' => $score->id,
                'training_session_id' => $session->id,
                'scenario_name' => $scenario['name'],
                'intervention' => $scenario['intervention'],
                'baseline_result' => [
                    'profit_percent' => $profit,
                    'result' => data_get($trade, 'result'),
                ],
                'alternative_result' => [
                    'estimated_profit_percent' => $alternativeProfit,
                    'evidence_level' => 'heuristic_counterfactual',
                ],
                'delta_percent' => round($alternativeProfit - $profit, 2),
                'verdict' => $alternativeProfit > 0 ? 'improved' : 'still_failed',
                'explanation' => 'Alternative Reality Analysis: this is a deterministic sandbox estimate from stored trade metrics, not causal proof.',
            ]);
        }
    }

    private function updateBeliefs(StrategyScore $score, Collection $hypotheses): void
    {
        $confirmed = $hypotheses->where('status', 'confirmed')->count();
        $failed = $hypotheses->where('status', 'failed')->count();
        $sampleSize = max(1, $hypotheses->count());

        $this->upsertBelief($score, [
            'key' => 'trend_following',
            'label' => 'Trend following edge',
            'score' => $this->regimeEdgeScore($score, ['trend_up', 'trend_down'], $score->dnaProfile?->trend_dependency),
            'regime' => 'trend',
            'confirmed' => $confirmed,
            'failed' => $failed,
            'sample_size' => $sampleSize,
        ]);

        $this->upsertBelief($score, [
            'key' => 'regime_adaptability',
            'label' => 'Regime adaptability',
            'score' => $this->clamp((float) ($score->robustness_score ?? $score->dnaProfile?->adaptability_score ?? $score->score)),
            'regime' => null,
            'confirmed' => $confirmed,
            'failed' => $failed,
            'sample_size' => $sampleSize,
        ]);

        $this->upsertBelief($score, [
            'key' => 'survival_under_drawdown',
            'label' => 'Survival under drawdown',
            'score' => $this->survivalBeliefScore($score),
            'regime' => null,
            'confirmed' => $confirmed,
            'failed' => $failed,
            'sample_size' => $sampleSize,
        ]);

        if (str_contains($score->strategy, 'rsi')) {
            $this->upsertBelief($score, [
                'key' => 'rsi_confirmation',
                'label' => 'RSI confirmation quality',
                'score' => $this->clamp(((float) $score->winrate + (float) $score->score) / 2),
                'regime' => null,
                'confirmed' => $confirmed,
                'failed' => $failed,
                'sample_size' => $sampleSize,
            ]);
        }

        if (str_contains($score->strategy, 'breakout')) {
            $this->upsertBelief($score, [
                'key' => 'breakout_follow_through',
                'label' => 'Breakout follow-through',
                'score' => $this->clamp(((float) $score->profit_factor * 30) + ((float) $score->winrate / 2)),
                'regime' => null,
                'confirmed' => $confirmed,
                'failed' => $failed,
                'sample_size' => $sampleSize,
            ]);
        }
    }

    private function upsertBelief(StrategyScore $score, array $payload): void
    {
        $belief = AgentBelief::firstOrNew([
            'strategy' => $score->strategy,
            'belief_key' => $payload['key'],
            'regime' => $payload['regime'],
        ]);

        $oldSamples = (int) ($belief->sample_size ?? 0);
        $newSamples = (int) $payload['sample_size'];
        $oldScore = (float) ($belief->score ?? 50);
        $newScore = (float) $payload['score'];
        $totalSamples = $oldSamples + $newSamples;
        $weightedScore = $totalSamples > 0
            ? (($oldScore * $oldSamples) + ($newScore * $newSamples)) / $totalSamples
            : $newScore;

        $confirmed = (int) ($belief->confirmed_count ?? 0) + (int) $payload['confirmed'];
        $failed = (int) ($belief->failed_count ?? 0) + (int) $payload['failed'];
        [$low, $high] = $this->confidenceInterval($weightedScore, max(1, $totalSamples));

        $belief->fill([
            'belief_label' => $payload['label'],
            'score' => round($this->clamp($weightedScore), 2),
            'sample_size' => $totalSamples,
            'confirmed_count' => $confirmed,
            'failed_count' => $failed,
            'confidence_interval_low' => $low,
            'confidence_interval_high' => $high,
            'last_evidence_at' => now(),
            'evidence_summary' => "{$score->strategy} updated from session #{$score->training_session_id}: {$confirmed} confirmed, {$failed} failed hypotheses.",
            'metadata' => [
                'latest_strategy_score_id' => $score->id,
                'latest_score' => $score->score,
                'latest_profit_factor' => $score->profit_factor,
                'latest_robustness_score' => $score->robustness_score,
            ],
        ])->save();
    }

    private function writeJournal(TrainingSession $session): void
    {
        $hypotheses = $session->agentHypotheses()->get();
        $failed = $hypotheses->where('status', 'failed');
        $confirmed = $hypotheses->where('status', 'confirmed');
        $best = $session->strategyScores->sortByDesc('score')->first();
        $worst = $session->strategyScores->sortBy('score')->first();
        $regimeObservations = $this->sessionRegimeObservations($session);
        $mostFailed = $failed
            ->groupBy('hypothesis')
            ->sortByDesc(fn (Collection $items): int => $items->count())
            ->keys()
            ->first();

        ScientistJournal::updateOrCreate(
            ['training_session_id' => $session->id],
            [
                'title' => 'Scientist Journal #'.$session->id,
                'summary' => "Session #{$session->id}: {$hypotheses->count()} hypotheses, {$confirmed->count()} confirmed, {$failed->count()} failed.",
                'observations' => [
                    'regimes' => $regimeObservations,
                    'best_strategy' => $best?->strategy,
                    'worst_strategy' => $worst?->strategy,
                    'average_profit_factor' => $session->average_profit_factor,
                    'average_stability_score' => $session->average_stability_score,
                ],
                'most_failed_hypothesis' => $mostFailed,
                'conclusion' => $this->journalConclusion($session, $best, $worst, $failed->count(), $hypotheses->count()),
                'metrics' => [
                    'hypotheses' => $hypotheses->count(),
                    'confirmed' => $confirmed->count(),
                    'failed' => $failed->count(),
                    'counterfactuals' => $session->counterfactualRuns()->count(),
                    'knowledge_candidates' => KnowledgeFact::query()
                        ->where('source_type', TrainingSession::class)
                        ->where('source_id', $session->id)
                        ->count(),
                ],
            ],
        );
    }

    private function extractKnowledge(TrainingSession $session): void
    {
        foreach ($session->strategyScores as $score) {
            foreach (($score->regime_performance ?? []) as $regime => $data) {
                $profit = (float) ($data['profit_percent'] ?? 0);
                $trades = (int) ($data['trades'] ?? 0);

                if ($trades < 3 || abs($profit) < 1) {
                    continue;
                }

                $direction = $profit > 0 ? 'performs well' : 'performs poorly';
                $confidence = $this->clamp(62 + min(25, abs($profit) * 3) + min(10, $trades));
                $title = strtoupper($score->strategy)." {$direction} during {$regime}";

                KnowledgeFact::firstOrCreate(
                    [
                        'title' => $title,
                        'source_type' => TrainingSession::class,
                        'source_id' => $session->id,
                    ],
                    [
                        'fact' => "{$score->strategy} {$direction} during {$regime}: {$profit}% over {$trades} trades.",
                        'scope' => [
                            'strategy' => $score->strategy,
                            'symbol' => $score->symbol,
                            'timeframe' => $score->timeframe,
                            'market_regime' => $regime,
                        ],
                        'confidence_score' => round($confidence, 2),
                        'evidence_count' => $trades,
                        'status' => $confidence >= 85 ? 'validated' : 'provisional',
                        'discovered_at' => now(),
                        'last_seen_at' => now(),
                        'metadata' => [
                            'strategy_score_id' => $score->id,
                            'winrate' => $data['winrate'] ?? null,
                            'profit_factor' => $score->profit_factor,
                            'robustness_score' => $score->robustness_score,
                        ],
                    ],
                );
            }
        }
    }

    private function syntheticTrade(StrategyScore $score): array
    {
        return [
            'direction' => 'long',
            'result' => ((float) $score->net_profit_percent) >= 0 ? 'WIN' : 'LOSS',
            'profit_percent' => (float) $score->net_profit_percent,
            'market_regime' => $this->dominantRegime($score),
            'volatility_regime' => $this->dominantVolatility($score),
        ];
    }

    private function hypothesisText(string $strategy, string $decision, ?string $marketRegime): string
    {
        $direction = $decision === 'SELL' ? 'pastga' : 'yuqoriga';
        $regime = $marketRegime ?: 'current regime';

        if (str_contains($strategy, 'breakout')) {
            return "Breakout follow-through davom etadi va narx keyingi 20 candle ichida kamida 1.5 ATR {$direction} yuradi ({$regime}).";
        }

        if (str_contains($strategy, 'rsi')) {
            return "EMA trend va RSI confirmation signalini tasdiqlaydi; narx keyingi 20 candle ichida kamida 1.5 ATR {$direction} yuradi ({$regime}).";
        }

        if (str_contains($strategy, 'macd')) {
            return "MACD trend momentum saqlanadi va narx keyingi 20 candle ichida kamida 1.5 ATR {$direction} yuradi ({$regime}).";
        }

        return "Strategy signal bergan yo'nalish keyingi 20 candle ichida kamida 1.5 ATR {$direction} davom etadi ({$regime}).";
    }

    private function hypothesisStatus(float $profit, string $result): string
    {
        $normalized = strtoupper($result);

        if ($profit > 0 || $normalized === 'WIN') {
            return 'confirmed';
        }

        if ($profit < 0 || $normalized === 'LOSS') {
            return 'failed';
        }

        return 'inconclusive';
    }

    private function evaluationSummary(string $status, float $profit, ?string $marketRegime, ?string $volatilityRegime): string
    {
        $regime = $marketRegime ?: 'unknown regime';
        $volatility = $volatilityRegime ?: 'unknown volatility';

        return match ($status) {
            'confirmed' => "Hypothesis CONFIRMED: outcome {$profit}% in {$regime} / {$volatility}.",
            'failed' => "Hypothesis FAILED: outcome {$profit}% in {$regime} / {$volatility}.",
            default => "Hypothesis INCONCLUSIVE: outcome {$profit}% in {$regime} / {$volatility}.",
        };
    }

    private function decisionFromTrade(array $trade): string
    {
        return strtolower((string) data_get($trade, 'direction')) === 'short' ? 'SELL' : 'BUY';
    }

    private function decisionConfidence(StrategyScore $score): int
    {
        $values = array_filter([
            (float) $score->score,
            (float) ($score->stability_score ?? 0),
            (float) ($score->robustness_score ?? 0),
            (float) ($score->dnaProfile?->survival_score ?? 0),
        ], fn (float $value): bool => $value > 0);

        if (empty($values)) {
            return 50;
        }

        return (int) round($this->clamp(array_sum($values) / count($values)));
    }

    private function dominantRegime(StrategyScore $score): ?string
    {
        return collect($score->regime_performance ?? [])
            ->sortByDesc(fn (array $data): float => abs((float) ($data['profit_percent'] ?? 0)))
            ->keys()
            ->first();
    }

    private function dominantVolatility(StrategyScore $score): ?string
    {
        return collect($score->volatility_performance ?? [])
            ->sortByDesc(fn (array $data): float => abs((float) ($data['profit_percent'] ?? 0)))
            ->keys()
            ->first();
    }

    private function regimeEdgeScore(StrategyScore $score, array $regimes, mixed $fallback): float
    {
        $items = collect($score->regime_performance ?? [])->only($regimes);

        if ($items->isEmpty()) {
            return $this->clamp((float) ($fallback ?? $score->score ?? 50));
        }

        return $this->clamp($items->avg(fn (array $data): float => 50
            + ((float) ($data['profit_percent'] ?? 0) * 4)
            + (((float) ($data['winrate'] ?? 50) - 50) * 0.6)));
    }

    private function survivalBeliefScore(StrategyScore $score): float
    {
        $riskPenalty = (float) ($score->mc_risk_of_ruin_percent ?? 0);
        $drawdownPenalty = (float) ($score->mc_worst_drawdown_percent ?? $score->max_drawdown_percent ?? 0);
        $dnaSurvival = (float) ($score->dnaProfile?->survival_score ?? 70);

        return $this->clamp(($dnaSurvival * 0.5) + ((100 - $riskPenalty) * 0.3) + ((100 - $drawdownPenalty) * 0.2));
    }

    private function confidenceInterval(float $score, int $samples): array
    {
        $p = $this->clamp($score) / 100;
        $margin = 1.96 * sqrt(($p * (1 - $p)) / max(1, $samples)) * 100;

        return [
            round($this->clamp($score - $margin), 2),
            round($this->clamp($score + $margin), 2),
        ];
    }

    private function sessionRegimeObservations(TrainingSession $session): array
    {
        return $session->strategyScores
            ->flatMap(fn (StrategyScore $score): array => collect($score->regime_performance ?? [])
                ->map(fn (array $data, string $regime): array => [
                    'strategy' => $score->strategy,
                    'regime' => $regime,
                    'trades' => $data['trades'] ?? 0,
                    'winrate' => $data['winrate'] ?? 0,
                    'profit_percent' => $data['profit_percent'] ?? 0,
                ])
                ->values()
                ->all())
            ->values()
            ->all();
    }

    private function journalConclusion(
        TrainingSession $session,
        ?StrategyScore $best,
        ?StrategyScore $worst,
        int $failed,
        int $total,
    ): string {
        $failureRate = $total > 0 ? round(($failed / $total) * 100, 2) : 0;
        $text = "Session #{$session->id} ilmiy xulosasi: ";
        $text .= "best agent {$best?->strategy}, worst agent {$worst?->strategy}. ";
        $text .= "Hypothesis failure rate {$failureRate}%. ";

        if ($failureRate > 45) {
            $text .= 'Agentlar prediction sifatida yetarli darajada barqaror emas; belief scores pasaytirildi va counterfactual tests evolution uchun signal beradi.';
        } elseif ($session->average_profit_factor >= 1.3 && $session->average_stability_score >= 70) {
            $text .= 'Scientific evidence ijobiy: profit factor va stability yetarli, knowledge extraction uchun candidate facts yaratildi.';
        } else {
            $text .= "Natija mixed; keyingi sessionlarda bir xil regime va validation protocol bo'yicha takroriy evidence kerak.";
        }

        return $text;
    }

    private function clamp(float $value, float $min = 0, float $max = 100): float
    {
        return max($min, min($max, $value));
    }
}
