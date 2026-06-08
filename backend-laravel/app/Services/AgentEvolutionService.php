<?php

namespace App\Services;

use App\Models\EvolutionProposal;
use App\Models\ModelVersion;
use App\Models\TrainingSession;
use Illuminate\Support\Str;

class AgentEvolutionService
{
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

        $currentScore = (int) $session->worst_score;

        if ($currentScore >= 50) {
            return null;
        }

        $oldParameters = $modelVersion->parameters ?? [];
        $worstScore = $session->strategyScores()
            ->where('strategy', $worstStrategy)
            ->latest()
            ->first();
        $regimePerformance = $worstScore?->regime_performance ?? [];
        $evolution = $this->buildEvolutionPlan($worstStrategy, $oldParameters, $currentScore, $regimePerformance);

        return EvolutionProposal::create([
            'training_session_id' => $session->id,
            'model_version_id' => $modelVersion->id,
            'strategy' => $worstStrategy,
            'current_version' => $modelVersion->version ?? 'v1',
            'proposed_version' => $this->nextVersion($modelVersion->version ?? 'v1'),
            'current_score' => $currentScore,
            'main_problem' => $evolution['main_problem'],
            'reason' => $evolution['reason'],
            'proposal' => $evolution['proposal'],
            'old_parameters' => $oldParameters,
            'new_parameters' => $evolution['new_parameters'],
            'status' => 'pending',
        ]);
    }

    private function buildEvolutionPlan(
        string $strategy,
        array $params,
        int $score,
        array $regimePerformance = [],
    ): array
    {
        $evolution = match ($strategy) {
            'breakout_v1' => $this->evolveBreakout($params, $score),
            'fibonacci_v1' => $this->evolveFibonacci($params, $score),
            'ema_rsi_v1' => $this->evolveEmaRsi($params, $score),
            'macd_trend_v1' => $this->evolveMacdTrend($params, $score),
            default => $this->genericEvolution($params, $score),
        };

        return $this->addRegimeNote($evolution, $regimePerformance);
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

    private function nextVersion(string $version): string
    {
        $number = (int) Str::after($version, 'v');

        return 'v'.($number + 1);
    }
}
