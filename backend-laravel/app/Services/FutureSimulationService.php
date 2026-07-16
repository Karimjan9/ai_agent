<?php

namespace App\Services;

use App\Models\FutureDiscovery;
use App\Models\FutureProbabilityNode;
use App\Models\FutureScenario;
use App\Models\FutureSimulationRun;
use App\Models\FutureStressTest;
use App\Models\FutureTimelineForecast;
use App\Models\KnowledgeClaim;
use App\Models\MarketGenome;
use App\Models\MarketStateSnapshot;
use App\Models\StrategyScore;
use App\Models\StrategySurvivalForecast;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class FutureSimulationService
{
    public function simulate(string $symbol = 'XAUUSD', string $timeframe = 'H1', int $scenarioCount = 1000, array $horizons = [10, 25, 50]): ?FutureSimulationRun
    {
        if (! Schema::hasTable('future_simulation_runs')) {
            return null;
        }

        $snapshot = MarketStateSnapshot::query()
            ->with(['marketSpecies', 'genome'])
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->latest('time')
            ->first();

        if (! $snapshot || ! $snapshot->genome) {
            return null;
        }

        $genome = $snapshot->genome;
        $priors = $this->knowledgePriors($snapshot);
        $probabilities = $this->scenarioProbabilities($genome, $snapshot, $priors);
        $futureConfidence = $this->futureConfidence($snapshot, $probabilities, $priors);
        $planningBias = $this->planningBias($probabilities, $futureConfidence);

        $run = FutureSimulationRun::create([
            'market_state_snapshot_id' => $snapshot->id,
            'market_genome_id' => $genome->id,
            'market_species_id' => $snapshot->market_species_id,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'scenario_count' => $scenarioCount,
            'max_horizon_candles' => max($horizons),
            'random_seed' => crc32($symbol.$timeframe.$snapshot->time->toDateTimeString()),
            'status' => 'completed',
            'current_confidence' => (float) $snapshot->confidence_score,
            'future_confidence' => $futureConfidence,
            'planning_bias' => $planningBias,
            'current_market_vector' => $genome->vector,
            'knowledge_prior_summary' => $priors,
            'summary' => $this->summary($snapshot, $probabilities, $futureConfidence, $planningBias),
        ]);

        $this->recordScenarios($run, $probabilities, $scenarioCount, $genome, $priors);
        $this->recordProbabilityTree($run, $snapshot, $probabilities);
        $this->recordTimeline($run, $probabilities, $horizons, $genome, $priors);
        $this->recordStrategySurvival($run, $probabilities, $priors);
        $this->recordStressTests($run, $probabilities, $genome, $priors);
        $this->recordDiscoveries($run, $snapshot, $probabilities, $priors);

        return $run;
    }

    private function knowledgePriors(MarketStateSnapshot $snapshot): array
    {
        $speciesName = $snapshot->marketSpecies?->name;
        $claims = collect();

        if (Schema::hasTable('knowledge_claims')) {
            $claims = KnowledgeClaim::query()
                ->where(function ($query) use ($speciesName, $snapshot): void {
                    $query->where('claim', 'like', '%'.$snapshot->market_state.'%');

                    if ($speciesName) {
                        $query->orWhere('claim', 'like', '%'.$speciesName.'%');
                        $query->orWhereJsonContains('scope->market_species', $speciesName);
                    }
                })
                ->orderByDesc('confidence_score')
                ->take(20)
                ->get();
        }

        $failurePressure = (float) $claims
            ->whereIn('claim_type', ['failure_cause', 'strategy_species_performance'])
            ->filter(fn (KnowledgeClaim $claim): bool => str_contains(strtolower($claim->claim), 'struggle') || str_contains(strtolower($claim->claim), 'failure') || str_contains(strtolower($claim->claim), 'death'))
            ->avg('confidence_score');

        $opportunityPressure = (float) $claims
            ->filter(fn (KnowledgeClaim $claim): bool => str_contains(strtolower($claim->claim), 'performs better') || str_contains(strtolower($claim->claim), 'superior') || str_contains(strtolower($claim->claim), 'validated'))
            ->avg('confidence_score');

        return [
            'species' => $speciesName,
            'market_state' => $snapshot->market_state,
            'claim_count' => $claims->count(),
            'failure_pressure' => round($failurePressure ?: 0, 2),
            'opportunity_pressure' => round($opportunityPressure ?: 0, 2),
            'claim_ids' => $claims->pluck('id')->values()->all(),
        ];
    }

    private function scenarioProbabilities(MarketGenome $genome, MarketStateSnapshot $snapshot, array $priors): array
    {
        $trend = (float) $genome->trend;
        $momentum = (float) $genome->momentum;
        $panic = (float) $genome->panic;
        $compression = (float) $genome->compression;
        $liquidity = (float) $genome->liquidity_proxy;
        $failurePressure = (float) ($priors['failure_pressure'] ?? 0);
        $opportunityPressure = (float) ($priors['opportunity_pressure'] ?? 0);

        $scores = [
            'bull_continuation' => max(5, ($trend * 0.38) + ($momentum * 0.34) + ($liquidity * 0.12) + ($opportunityPressure * 0.12) - ($panic * 0.20)),
            'range_reversion' => max(5, (100 - abs($trend - 50)) * 0.34 + $compression * 0.32 + (100 - $momentum) * 0.18),
            'panic_event' => max(4, $panic * 0.48 + (100 - $liquidity) * 0.20 + $failurePressure * 0.18),
            'fake_breakout' => max(4, $compression * 0.34 + $panic * 0.24 + (100 - $liquidity) * 0.16 + ($snapshot->structure_state === 'trap' ? 25 : 0)),
            'trend_reversal' => max(4, (100 - $trend) * 0.20 + $panic * 0.26 + (100 - $momentum) * 0.16 + $failurePressure * 0.16),
        ];

        $total = max(1, array_sum($scores));
        $probabilities = [];

        foreach ($scores as $key => $score) {
            $probabilities[$key] = round($score / $total, 4);
        }

        return $probabilities;
    }

    private function recordScenarios(FutureSimulationRun $run, array $probabilities, int $scenarioCount, MarketGenome $genome, array $priors): void
    {
        $labels = [
            'bull_continuation' => 'Bull Continuation',
            'range_reversion' => 'Range Reversion',
            'panic_event' => 'Panic Event',
            'fake_breakout' => 'Fake Breakout',
            'trend_reversal' => 'Trend Reversal',
        ];

        foreach ($probabilities as $key => $probability) {
            $risk = $this->scenarioRisk($key, $genome, $priors);
            FutureScenario::create([
                'future_simulation_run_id' => $run->id,
                'scenario_key' => $key,
                'scenario_label' => $labels[$key],
                'simulated_count' => (int) round($scenarioCount * $probability),
                'probability' => $probability,
                'expected_return' => $this->expectedReturn($key, $genome, $risk),
                'risk_score' => $risk,
                'confidence_score' => $this->clamp(45 + ($probability * 100 * 0.55) + max(0, (int) $priors['claim_count'] * 2)),
                'state_path' => $this->statePath($key),
                'drivers' => [
                    'market_vector' => $genome->vector,
                    'knowledge_priors' => $priors,
                ],
            ]);
        }
    }

    private function recordProbabilityTree(FutureSimulationRun $run, MarketStateSnapshot $snapshot, array $probabilities): void
    {
        $root = FutureProbabilityNode::create([
            'future_simulation_run_id' => $run->id,
            'node_key' => 'current_market',
            'label' => 'Current Market: '.($snapshot->marketSpecies?->name ?? $snapshot->market_state),
            'probability' => 1,
            'horizon_candles' => 0,
            'node_type' => 'root',
            'metadata' => [
                'market_state' => $snapshot->market_state,
                'structure_state' => $snapshot->structure_state,
            ],
        ]);

        foreach ($probabilities as $key => $probability) {
            FutureProbabilityNode::create([
                'future_simulation_run_id' => $run->id,
                'parent_id' => $root->id,
                'node_key' => $key,
                'label' => str($key)->replace('_', ' ')->title()->toString(),
                'probability' => $probability,
                'horizon_candles' => $run->max_horizon_candles,
                'node_type' => 'scenario',
                'metadata' => ['probability_percent' => round($probability * 100, 2)],
            ]);
        }
    }

    private function recordTimeline(FutureSimulationRun $run, array $probabilities, array $horizons, MarketGenome $genome, array $priors): void
    {
        foreach ($horizons as $horizon) {
            $decay = min(0.45, $horizon / 140);
            $bull = max(0.02, $probabilities['bull_continuation'] * (1 - $decay));
            $panic = min(0.85, $probabilities['panic_event'] + ($decay * 0.35));
            $reversal = min(0.85, $probabilities['trend_reversal'] + ($decay * 0.28));
            $range = max(0.02, 1 - $bull - $panic - $reversal);
            $total = max(1, $bull + $range + $panic + $reversal);

            FutureTimelineForecast::create([
                'future_simulation_run_id' => $run->id,
                'horizon_candles' => $horizon,
                'bull_probability' => round($bull / $total, 4),
                'range_probability' => round($range / $total, 4),
                'panic_probability' => round($panic / $total, 4),
                'reversal_probability' => round($reversal / $total, 4),
                'confidence_score' => $this->clamp((float) $run->future_confidence - ($horizon / 3)),
                'drivers' => [
                    'decay' => round($decay, 4),
                    'momentum' => $genome->momentum,
                    'knowledge_claims' => $priors['claim_count'],
                ],
            ]);
        }
    }

    private function recordStrategySurvival(FutureSimulationRun $run, array $probabilities, array $priors): void
    {
        $scores = StrategyScore::query()
            ->where('symbol', $run->symbol)
            ->where('timeframe', $run->timeframe)
            ->latest()
            ->take(12)
            ->get()
            ->unique('strategy');

        if ($scores->isEmpty()) {
            $scores = StrategyScore::query()->latest()->take(12)->get()->unique('strategy');
        }

        foreach ($scores as $score) {
            $scenarioBreakdown = $this->strategyScenarioBreakdown($score, $probabilities);
            $survival = $this->strategySurvival($score, $scenarioBreakdown, $priors);
            $futureConfidence = $this->clamp(((float) $score->score * 0.45) + ((float) ($score->robustness_score ?? 50) * 0.30) + ($survival * 100 * 0.25));

            StrategySurvivalForecast::create([
                'future_simulation_run_id' => $run->id,
                'strategy_score_id' => $score->id,
                'strategy' => $score->strategy,
                'current_confidence' => (float) $score->score,
                'future_confidence' => $futureConfidence,
                'survival_probability' => round($survival, 4),
                'future_robustness' => $this->clamp((float) ($score->robustness_score ?? $score->score) - ($probabilities['panic_event'] * 25) - ($probabilities['trend_reversal'] * 18)),
                'recommended_action' => $this->recommendedAction($survival, $futureConfidence),
                'scenario_breakdown' => $scenarioBreakdown,
                'planning_adjustments' => [
                    'position_size_multiplier' => $this->positionSizeMultiplier($survival, $futureConfidence),
                    'knowledge_failure_pressure' => $priors['failure_pressure'] ?? 0,
                ],
            ]);
        }
    }

    private function recordStressTests(FutureSimulationRun $run, array $probabilities, MarketGenome $genome, array $priors): void
    {
        $tests = [
            'volatility_x2' => ['Volatility x2', ['volatility_multiplier' => 2.0], ($probabilities['panic_event'] + $probabilities['fake_breakout']) * 100],
            'liquidity_minus_50' => ['Liquidity -50%', ['liquidity_proxy_multiplier' => 0.5], (100 - (float) $genome->liquidity_proxy) * 0.7 + ($priors['failure_pressure'] ?? 0) * 0.25],
            'trend_reversal' => ['Trend Reversal', ['trend_flip' => true], $probabilities['trend_reversal'] * 100 + max(0, 60 - (float) $genome->momentum) * 0.3],
        ];

        foreach ($tests as $key => [$label, $parameters, $impact]) {
            $impact = $this->clamp((float) $impact);
            $survivalRate = round(max(0.05, 1 - ($impact / 115)), 4);
            FutureStressTest::create([
                'future_simulation_run_id' => $run->id,
                'stress_key' => $key,
                'stress_label' => $label,
                'impact_score' => $impact,
                'survival_rate' => $survivalRate,
                'confidence_score' => $this->clamp(55 + $impact * 0.25 + min(15, (int) $priors['claim_count'] * 2)),
                'risk_level' => $impact >= 70 ? 'high' : ($impact >= 40 ? 'medium' : 'low'),
                'planning_note' => $impact >= 70 ? 'Reduce exposure until future robustness improves.' : 'Monitor but no immediate forced reduction.',
                'parameters' => $parameters,
            ]);
        }
    }

    private function recordDiscoveries(FutureSimulationRun $run, MarketStateSnapshot $snapshot, array $probabilities, array $priors): void
    {
        $trendFailure = $this->clamp(($probabilities['trend_reversal'] + $probabilities['panic_event']) * 100 + (($priors['failure_pressure'] ?? 0) * 0.15));

        if ($trendFailure < 55) {
            return;
        }

        FutureDiscovery::updateOrCreate(
            ['title' => "Future risk after {$snapshot->market_state} on {$run->symbol} {$run->timeframe}"],
            [
                'future_simulation_run_id' => $run->id,
                'discovery' => "{$snapshot->market_state} with current volatility/liquidity_proxy implies ".round($trendFailure, 2)."% probability pressure toward trend failure or panic scenarios.",
                'discovery_type' => 'future_risk_pattern',
                'confidence_score' => $this->clamp(55 + $trendFailure * 0.35 + min(15, (int) $priors['claim_count'] * 2)),
                'evidence_count' => max(1, (int) $priors['claim_count'] + (int) $run->scenario_count),
                'status' => $trendFailure >= 75 ? 'validated' : 'provisional',
                'scope' => [
                    'symbol' => $run->symbol,
                    'timeframe' => $run->timeframe,
                    'market_state' => $snapshot->market_state,
                ],
                'metadata' => [
                    'trend_failure_pressure' => round($trendFailure, 2),
                    'probabilities' => $probabilities,
                    'priors' => $priors,
                ],
            ],
        );
    }

    private function strategyScenarioBreakdown(StrategyScore $score, array $probabilities): array
    {
        $trendBias = str_contains($score->strategy, 'ema') || str_contains($score->strategy, 'trend') || str_contains($score->strategy, 'macd');
        $breakoutBias = str_contains($score->strategy, 'breakout');
        $rangeBias = str_contains($score->strategy, 'fibonacci') || str_contains($score->strategy, 'rsi');

        return [
            'bull_continuation' => round($probabilities['bull_continuation'] * ($trendBias ? 1.18 : 0.95), 4),
            'range_reversion' => round($probabilities['range_reversion'] * ($rangeBias ? 1.12 : 0.92), 4),
            'panic_event' => round($probabilities['panic_event'] * ((float) ($score->max_drawdown_percent ?? 0) > 15 ? 1.22 : 0.92), 4),
            'fake_breakout' => round($probabilities['fake_breakout'] * ($breakoutBias ? 1.28 : 0.88), 4),
            'trend_reversal' => round($probabilities['trend_reversal'] * ($trendBias ? 1.15 : 0.95), 4),
        ];
    }

    private function strategySurvival(StrategyScore $score, array $scenarioBreakdown, array $priors): float
    {
        $base = ((float) $score->score * 0.0035)
            + ((float) ($score->robustness_score ?? 50) * 0.0025)
            + ((float) ($score->stability_score ?? 50) * 0.0015)
            + min(0.18, (float) $score->profit_factor * 0.05);

        $riskPenalty = ($scenarioBreakdown['panic_event'] * 0.22)
            + ($scenarioBreakdown['fake_breakout'] * 0.18)
            + ($scenarioBreakdown['trend_reversal'] * 0.15)
            + (($priors['failure_pressure'] ?? 0) / 100 * 0.10)
            + ((float) ($score->mc_risk_of_ruin_percent ?? 0) / 100 * 0.20)
            + ((float) $score->max_drawdown_percent / 100 * 0.10);

        return round(max(0.02, min(0.98, $base - $riskPenalty)), 4);
    }

    private function futureConfidence(MarketStateSnapshot $snapshot, array $probabilities, array $priors): float
    {
        $dominant = max($probabilities);
        $risk = $probabilities['panic_event'] + $probabilities['trend_reversal'];

        return $this->clamp((float) $snapshot->confidence_score * 0.45 + ($dominant * 100 * 0.35) + min(15, (int) $priors['claim_count'] * 2) - ($risk * 15));
    }

    private function planningBias(array $probabilities, float $futureConfidence): string
    {
        if ($futureConfidence < 45 || ($probabilities['panic_event'] + $probabilities['trend_reversal']) > 0.45) {
            return 'defensive';
        }

        if ($probabilities['bull_continuation'] > 0.5 && $futureConfidence >= 60) {
            return 'risk_on';
        }

        return 'neutral';
    }

    private function recommendedAction(float $survival, float $futureConfidence): string
    {
        if ($survival < 0.45 || $futureConfidence < 45) {
            return 'reduce';
        }

        if ($survival > 0.72 && $futureConfidence > 65) {
            return 'maintain_or_scale';
        }

        return 'maintain';
    }

    private function positionSizeMultiplier(float $survival, float $futureConfidence): float
    {
        return round(max(0.25, min(1.25, ($survival * 0.85) + ($futureConfidence / 100 * 0.45))), 2);
    }

    private function scenarioRisk(string $key, MarketGenome $genome, array $priors): float
    {
        return match ($key) {
            'bull_continuation' => $this->clamp(35 + ((100 - (float) $genome->liquidity_proxy) * 0.10)),
            'range_reversion' => $this->clamp(42 + ((float) $genome->compression * 0.08)),
            'panic_event' => $this->clamp(68 + ((100 - (float) $genome->liquidity_proxy) * 0.18) + (($priors['failure_pressure'] ?? 0) * 0.12)),
            'fake_breakout' => $this->clamp(62 + ((float) $genome->compression * 0.18)),
            'trend_reversal' => $this->clamp(58 + ((float) $genome->panic * 0.16)),
            default => 50,
        };
    }

    private function expectedReturn(string $key, MarketGenome $genome, float $risk): float
    {
        $base = match ($key) {
            'bull_continuation' => ((float) $genome->momentum / 100) * 2.4,
            'range_reversion' => 0.35,
            'panic_event' => -1.8,
            'fake_breakout' => -0.9,
            'trend_reversal' => -0.6,
            default => 0,
        };

        return round($base - ($risk / 100 * 0.45), 2);
    }

    private function statePath(string $key): array
    {
        return match ($key) {
            'bull_continuation' => ['current', 'trend_follow_through', 'bull_expansion'],
            'range_reversion' => ['current', 'compression', 'balanced_range'],
            'panic_event' => ['current', 'liquidity_drop', 'panic'],
            'fake_breakout' => ['current', 'breakout_attempt', 'trap'],
            'trend_reversal' => ['current', 'momentum_decay', 'reversal'],
            default => ['current', 'unknown'],
        };
    }

    private function summary(MarketStateSnapshot $snapshot, array $probabilities, float $futureConfidence, string $planningBias): string
    {
        $dominant = collect($probabilities)->sortDesc()->keys()->first();
        $probability = round(($probabilities[$dominant] ?? 0) * 100, 2);

        return "Future simulation sees {$dominant} as dominant path at {$probability}% from {$snapshot->market_state}; future confidence {$futureConfidence}%, planning bias {$planningBias}.";
    }

    private function clamp(float $value, float $min = 0, float $max = 100): float
    {
        return max($min, min($max, $value));
    }
}
