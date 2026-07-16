<?php

namespace App\Services;

use App\Models\EvolutionProposal;
use App\Models\ModelVersion;
use App\Models\TrainingSession;
use App\Models\ModelMarketPerformance;
use Illuminate\Support\Str;

class AgentEvolutionService
{
    public function __construct(private StrategyParameterSchemaService $parameterSchemas) {}

    public function createProposalFromSession(TrainingSession $session): ?EvolutionProposal
    {
        $worstStrategy = $session->worst_strategy;

        if (! $worstStrategy) {
            return null;
        }

        $modelVersion = ModelVersion::query()
            ->where('strategy', $worstStrategy)
            ->latest()
            ->first();

        if (! $modelVersion) {
            return null;
        }

        $family = $this->parameterSchemas->family($worstStrategy);
        $championState = ModelMarketPerformance::query()
            ->with('modelVersion')
            ->where('symbol', $session->symbol)
            ->where('timeframe', $session->timeframe)
            ->where('strategy_family', $family)
            ->where('status', 'champion')
            ->first();
        $modelVersion = $championState?->modelVersion ?? $modelVersion;

        $marketState = ModelMarketPerformance::query()
            ->where('model_version_id', $modelVersion->id)
            ->where('symbol', $session->symbol)
            ->where('timeframe', $session->timeframe)
            ->first();
        $isStagnated = $marketState?->status === 'stagnated';

        $currentScore = (int) $session->worst_score;
        $worstScore = $session->strategyScores()
            ->where('strategy', $worstStrategy)
            ->latest()
            ->first();

        $isOverfit = (bool) ($worstScore?->is_overfit ?? false);
        $mcRiskOfRuin = (float) ($worstScore?->mc_risk_of_ruin_percent ?? 0);
        $mcWorstDrawdown = (float) ($worstScore?->mc_worst_drawdown_percent ?? 0);
        $dnaProfile = $worstScore?->dnaProfile;
        $hasMonteCarloRisk = $mcRiskOfRuin > 20 || $mcWorstDrawdown > 35;

        $hasDnaProblem = $dnaProfile
            && (
                (float) $dnaProfile->trend_dependency > 90
                || (float) $dnaProfile->adaptability_score < 35
                || (float) $dnaProfile->survival_score < 50
            );

        if ($currentScore >= 50 && ! $isOverfit && ! $hasMonteCarloRisk && ! $hasDnaProblem) {
            return null;
        }

        $oldParameters = $modelVersion->parameters ?? [];
        $regimePerformance = $worstScore?->regime_performance ?? [];
        $evolution = $this->buildEvolutionPlan(
            $worstStrategy,
            $oldParameters,
            $currentScore,
            $regimePerformance,
            $isOverfit,
            (float) ($worstScore?->forward_score ?? 0),
            $mcRiskOfRuin,
            $mcWorstDrawdown,
            $dnaProfile?->toArray() ?? [],
        );

        $newParameters = $this->parameterSchemas->clamp(
            $worstStrategy,
            $this->ensureWorkingMutation($worstStrategy, $oldParameters, $evolution['new_parameters']),
        );

        $existingOpen = EvolutionProposal::query()
            ->where('parent_model_version_id', $modelVersion->id)
            ->where('symbol', $session->symbol)
            ->where('timeframe', $session->timeframe)
            ->where('open_status', 'open')
            ->orderBy('id')
            ->first();
        if ($existingOpen) {
            return $existingOpen;
        }

        $parentNumber = (int) Str::after($modelVersion->version ?? 'v1', 'v');
        $latestProposedNumber = EvolutionProposal::query()
            ->where('parent_model_version_id', $modelVersion->id)
            ->where('symbol', $session->symbol)
            ->where('timeframe', $session->timeframe)
            ->pluck('proposed_version')
            ->map(fn (string $version): int => (int) Str::after($version, 'v'))
            ->max() ?? $parentNumber;
        $versionOffset = max(1, $latestProposedNumber - $parentNumber + 1);
        $first = null;
        for ($mutant = 1; $mutant <= 5; $mutant++) {
            $proposedVersion = $this->nextVersion($modelVersion->version ?? 'v1', $versionOffset + $mutant - 1);
            $mutantParameters = $this->mutantParameters($worstStrategy, $newParameters, $mutant, $isStagnated);
            $proposal = EvolutionProposal::query()->firstOrCreate([
                'parent_model_version_id' => $modelVersion->id,
                'symbol' => $session->symbol,
                'timeframe' => $session->timeframe,
                'proposed_version' => $proposedVersion,
                'open_status' => 'open',
            ], [
                'training_session_id' => $session->id,
                'model_version_id' => $modelVersion->id,
                'strategy' => $modelVersion->strategy ?? $worstStrategy,
                'symbol' => $session->symbol,
                'timeframe' => $session->timeframe,
                'strategy_family' => $family,
                'current_version' => $modelVersion->version ?? 'v1',
                'proposed_version' => $proposedVersion,
                'current_score' => $currentScore,
                'main_problem' => $isStagnated ? 'lineage_stagnated' : $evolution['main_problem'],
                'reason' => $isStagnated
                    ? 'Ketma-ket 3 avlod yaxshilanmadi; parametr diapazoni kengaytirildi va boshqa champion bilan crossover tavsiya qilindi.'
                    : $evolution['reason'],
                'proposal' => "Mutant {$mutant}/5. ".$evolution['proposal'],
                'old_parameters' => $oldParameters,
                'new_parameters' => $mutantParameters,
                'status' => 'pending',
            ]);
            $first ??= $proposal;
        }

        return $first;
    }

    private function buildEvolutionPlan(
        string $strategy,
        array $params,
        int $score,
        array $regimePerformance = [],
        bool $isOverfit = false,
        float $forwardScore = 0,
        float $mcRiskOfRuin = 0,
        float $mcWorstDrawdown = 0,
        array $dnaProfile = [],
    ): array
    {
        if ($mcRiskOfRuin > 20 || $mcWorstDrawdown > 35) {
            return $this->monteCarloRiskEvolution($params, $mcRiskOfRuin, $mcWorstDrawdown);
        }

        if ($isOverfit) {
            return $this->overfitEvolution($params, $forwardScore);
        }

        if (! empty($dnaProfile)) {
            $dnaEvolution = $this->dnaEvolution($params, $dnaProfile);
            if ($dnaEvolution) {
                return $dnaEvolution;
            }
        }

        $evolution = match ($this->parameterSchemas->family($strategy)) {
            'breakout' => $this->evolveBreakout($params, $score),
            'fibonacci' => $this->evolveFibonacci($params, $score),
            'ema_rsi' => $this->evolveEmaRsi($params, $score),
            'macd_trend' => $this->evolveMacdTrend($params, $score),
            default => $this->genericEvolution($params, $score),
        };

        return $this->addRegimeNote($evolution, $regimePerformance);
    }

    private function overfitEvolution(array $params, float $forwardScore): array
    {
        $new = $params;
        $new['reduce_complexity'] = true;
        $new['walk_forward_guard'] = true;

        if (isset($params['ema_fast'])) {
            $new['ema_fast'] = (int) $params['ema_fast'] + 5;
        }

        if (isset($params['ema_slow'])) {
            $new['ema_slow'] = (int) $params['ema_slow'] + 20;
        }

        return [
            'main_problem' => 'forward_score_collapsed',
            'reason' => "Forward score collapsed during walk-forward validation. Forward score: {$forwardScore}.",
            'proposal' => 'Reduce strategy complexity, slow down reactive parameters, and require future validation before promotion.',
            'new_parameters' => $new,
        ];
    }

    private function dnaEvolution(array $params, array $dnaProfile): ?array
    {
        $trendDependency = (float) ($dnaProfile['trend_dependency'] ?? 0);
        $adaptabilityScore = (float) ($dnaProfile['adaptability_score'] ?? 100);
        $survivalScore = (float) ($dnaProfile['survival_score'] ?? 100);

        if ($trendDependency > 90) {
            $new = $params;
            $new['range_capability'] = true;
            $new['volatility_filter'] = true;

            return [
                'main_problem' => 'excessive_trend_dependency',
                'reason' => "Strategy DNA shows trend dependency at {$trendDependency}%. Range capability is too weak.",
                'proposal' => 'Reduce trend dependency by adding range-market confirmation and volatility filtering.',
                'new_parameters' => $new,
            ];
        }

        if ($adaptabilityScore < 35) {
            $new = $params;
            $new['market_regime_filter'] = true;
            $new['adaptive_thresholds'] = true;

            return [
                'main_problem' => 'low_adaptability',
                'reason' => "Strategy DNA adaptability is low at {$adaptabilityScore}%.",
                'proposal' => 'Add regime-aware thresholds so the strategy can survive more market types.',
                'new_parameters' => $new,
            ];
        }

        if ($survivalScore < 50) {
            $new = $params;
            $new['risk_multiplier'] = 0.5;
            $new['recovery_guard'] = true;

            return [
                'main_problem' => 'weak_survival_dna',
                'reason' => "Strategy DNA survival score is weak at {$survivalScore}%.",
                'proposal' => 'Reduce risk and add recovery guardrails before the next generation.',
                'new_parameters' => $new,
            ];
        }

        return null;
    }

    private function monteCarloRiskEvolution(
        array $params,
        float $riskOfRuin,
        float $worstDrawdown,
    ): array {
        $new = $params;
        $new['risk_multiplier'] = 0.5;
        $new['confirmation_candles'] = ($params['confirmation_candles'] ?? 1) + 1;
        $new['avoid_high_volatility'] = true;

        return [
            'main_problem' => 'high_risk_of_ruin',
            'reason' => "Monte Carlo risk of ruin is too high. Risk of ruin: {$riskOfRuin}%, worst drawdown: {$worstDrawdown}%.",
            'proposal' => 'Reduce risk per trade, increase confirmation, and avoid high volatility regimes.',
            'new_parameters' => $new,
        ];
    }

    private function evolveBreakout(array $params, int $score): array
    {
        $new = $params;
        $new['lookback'] = ($params['lookback'] ?? 20) + 10;
        $new['atr_multiplier'] = round(($params['atr_multiplier'] ?? 0.2) + 0.2, 2);
        $new['confirmation_candles'] = max(($params['confirmation_candles'] ?? 1), 2);

        return [
            'main_problem' => 'false_breakout',
            'reason' => 'Breakout agent past score oldi. Ehtimol, u shovqinli breakoutlarga juda tez kiryapti.',
            'proposal' => 'ATR filterni kuchaytirish, lookback oynasini kattalashtirish va candle confirmation qoshish.',
            'new_parameters' => $new,
        ];
    }

    private function evolveFibonacci(array $params, int $score): array
    {
        $new = $params;
        $new['lookback'] = ($params['lookback'] ?? 50) + 25;
        $new['tolerance'] = max(($params['tolerance'] ?? 0.002) - 0.0005, 0.001);
        $new['trend_confirmation'] = true;

        return [
            'main_problem' => 'weak_fibonacci_confirmation',
            'reason' => 'Fibonacci agent past score oldi. Faqat 0.618 yaqinligi yetarli confirmation bermayapti.',
            'proposal' => 'Trend confirmation qoshish, tolerance kamaytirish va swing lookbackni kattalashtirish.',
            'new_parameters' => $new,
        ];
    }

    private function evolveEmaRsi(array $params, int $score): array
    {
        $new = $params;
        $new['rsi_buy_max'] = min(($params['rsi_buy_max'] ?? 70) - 5, 65);
        $new['rsi_sell_min'] = max(($params['rsi_sell_min'] ?? 30) + 5, 35);
        $new['atr_filter'] = true;

        return [
            'main_problem' => 'late_entry',
            'reason' => 'EMA/RSI agent past score oldi. RSI juda kech signal berayotgan bolishi mumkin.',
            'proposal' => 'RSI chegaralarini konservativ qilish va ATR volatility filter qoshish.',
            'new_parameters' => $new,
        ];
    }

    private function evolveMacdTrend(array $params, int $score): array
    {
        $new = $params;
        $new['ema_trend'] = 150;
        $new['avoid_low_volume'] = true;
        $new['confirmation_candles'] = 2;

        return [
            'main_problem' => 'late_macd_signal',
            'reason' => 'MACD agent past score oldi. MACD signal kechikishi yoki kuchsiz trendda signal berishi mumkin.',
            'proposal' => 'EMA trend filterni kuchaytirish va confirmation candle qoshish.',
            'new_parameters' => $new,
        ];
    }

    private function genericEvolution(array $params, int $score): array
    {
        $new = $params;
        $new['risk_filter'] = true;

        return [
            'main_problem' => 'low_score',
            'reason' => 'Agent past score oldi, lekin aniq muammo klassifikatsiya qilinmadi.',
            'proposal' => 'Risk filter, volatility filter va signal confirmation qoshish.',
            'new_parameters' => $new,
        ];
    }

    private function addRegimeNote(array $evolution, array $regimePerformance): array
    {
        $worstRegime = $this->findWorstRegime($regimePerformance);

        if (! $worstRegime) {
            return $evolution;
        }

        $evolution['reason'] .= " Eng yomon market regime: {$worstRegime}.";
        $evolution['proposal'] .= " Keyingi versiyada {$worstRegime} holati uchun alohida filter qo'shish kerak.";
        $evolution['new_parameters']['avoid_regime'] = $worstRegime;

        return $evolution;
    }

    private function findWorstRegime(array $regimePerformance): ?string
    {
        $worstRegime = null;
        $worstProfit = null;

        foreach ($regimePerformance as $regime => $data) {
            $trades = $data['trades'] ?? 0;
            $profit = $data['profit_percent'] ?? 0;

            if ($trades < 5) {
                continue;
            }

            if ($worstProfit === null || $profit < $worstProfit) {
                $worstProfit = $profit;
                $worstRegime = $regime;
            }
        }

        return $worstRegime;
    }

    private function nextVersion(string $version, int $offset = 1): string
    {
        $number = (int) Str::after($version, 'v');

        return 'v'.($number + $offset);
    }

    private function ensureWorkingMutation(string $strategy, array $old, array $proposed): array
    {
        $clean = $this->parameterSchemas->clamp($strategy, $proposed);
        if ($clean !== $this->parameterSchemas->clamp($strategy, $old)) {
            return $clean;
        }

        return match ($this->parameterSchemas->family($strategy)) {
            'breakout' => [...$clean, 'lookback' => min(100, (int) ($clean['lookback'] ?? 20) + 10)],
            'ema_rsi' => [...$clean, 'ema_fast' => min(200, (int) ($clean['ema_fast'] ?? 50) + 5)],
            'fibonacci' => [...$clean, 'lookback' => min(300, (int) ($clean['lookback'] ?? 50) + 10)],
            'macd_trend' => [...$clean, 'ema_trend' => min(500, (int) ($clean['ema_trend'] ?? 100) + 10)],
            default => $clean,
        };
    }

    private function mutantParameters(string $strategy, array $base, int $mutant, bool $wideRange): array
    {
        $step = $wideRange ? $mutant * 2 : $mutant;
        $values = match ($this->parameterSchemas->family($strategy)) {
            'breakout' => [...$base,
                'lookback' => ($base['lookback'] ?? 20) + ($step - 1) * 10,
                'atr_multiplier' => ($base['atr_multiplier'] ?? 0.2) + ($step - 1) * 0.1,
                'confirmation_candles' => min(5, max(1, ($base['confirmation_candles'] ?? 1) + (($mutant - 1) % 3))),
            ],
            'ema_rsi' => [...$base, 'ema_fast' => ($base['ema_fast'] ?? 50) + ($step - 1) * 5],
            'fibonacci' => [...$base, 'lookback' => ($base['lookback'] ?? 50) + ($step - 1) * 15],
            'macd_trend' => [...$base, 'ema_trend' => ($base['ema_trend'] ?? 100) + ($step - 1) * 20],
            default => $base,
        };

        return $this->parameterSchemas->clamp($strategy, $values);
    }
}
