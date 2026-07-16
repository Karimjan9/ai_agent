<?php

namespace App\Services;

use App\Models\AgentBelief;
use App\Models\AgentMemory;
use App\Models\AgentPsychologySnapshot;
use App\Models\AgentReputation;
use App\Models\InternalDebate;
use App\Models\StrategyScore;
use App\Models\TrainingSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class AgentMindService
{
    public function recordTrainingSession(TrainingSession $session): void
    {
        if (! Schema::hasTable('agent_psychology_snapshots')) {
            return;
        }

        $session->loadMissing([
            'strategyScores.agentHypotheses',
            'strategyScores.dnaProfile',
        ]);

        $snapshots = collect();

        foreach ($session->strategyScores as $score) {
            if ($score->psychologySnapshots()->where('training_session_id', $session->id)->exists()) {
                continue;
            }

            $snapshot = $this->createPsychologySnapshot($session, $score);
            $this->createSelfReflection($score, $snapshot);
            $this->createMemoryIfNeeded($score, $snapshot);
            $this->updateReputation($score, $snapshot);
            $this->createEvolutionTriggers($score, $snapshot);
            $snapshots->push($snapshot);
        }

        if ($snapshots->isNotEmpty() && ! $session->internalDebates()->exists()) {
            $this->createInternalDebate($session, $snapshots);
        }
    }

    private function createPsychologySnapshot(TrainingSession $session, StrategyScore $score): AgentPsychologySnapshot
    {
        $hypotheses = $score->agentHypotheses;
        $totalHypotheses = max(1, $hypotheses->count());
        $confirmed = $hypotheses->where('status', 'confirmed')->count();
        $failed = $hypotheses->where('status', 'failed')->count();
        $hypothesisAccuracy = ($confirmed / $totalHypotheses) * 100;
        $failureRate = ($failed / $totalHypotheses) * 100;
        $beliefTrust = $this->beliefTrust($score->strategy);
        $confidence = $this->clamp(($hypothesisAccuracy * 0.55) + ((float) $score->score * 0.25) + ((float) ($score->robustness_score ?? $score->score) * 0.20));
        $trust = $this->clamp(($beliefTrust * 0.60) + ($hypothesisAccuracy * 0.25) + ((float) $score->profit_factor * 10));
        $stress = $this->stressScore($score, $failureRate);
        $adaptationPressure = $this->adaptationPressure($score, $failureRate);
        $stability = $this->clamp((float) ($score->stability_score ?? 50));
        $learningRate = round($this->clamp(0.04 + ($adaptationPressure / 1000) + ($failureRate / 1500), 0.01, 0.30), 4);

        return AgentPsychologySnapshot::create([
            'training_session_id' => $session->id,
            'strategy_score_id' => $score->id,
            'strategy' => $score->strategy,
            'confidence' => round($confidence, 2),
            'stress' => round($stress, 2),
            'trust' => round($trust, 2),
            'adaptation_pressure' => round($adaptationPressure, 2),
            'stability' => round($stability, 2),
            'learning_rate' => $learningRate,
            'state' => $this->state($stress, $adaptationPressure),
            'metrics' => [
                'hypotheses' => $hypotheses->count(),
                'confirmed_hypotheses' => $confirmed,
                'failed_hypotheses' => $failed,
                'hypothesis_accuracy' => round($hypothesisAccuracy, 2),
                'belief_trust' => round($beliefTrust, 2),
                'score' => $score->score,
                'profit_factor' => $score->profit_factor,
                'drawdown' => $score->max_drawdown_percent,
                'risk_of_ruin' => $score->mc_risk_of_ruin_percent,
                'worst_drawdown' => $score->mc_worst_drawdown_percent,
                'robustness_score' => $score->robustness_score,
                'is_overfit' => $score->is_overfit,
                'dominant_bad_regime' => $this->worstRegime($score),
            ],
        ]);
    }

    private function createSelfReflection(StrategyScore $score, AgentPsychologySnapshot $snapshot): void
    {
        $worstRegime = $this->worstRegime($score);
        $reflection = "Session #{$snapshot->training_session_id}: ";

        if ((float) $snapshot->adaptation_pressure >= 85) {
            $reflection .= 'My current assumptions are mismatched with the latest market evidence. ';
        } elseif ((float) $snapshot->stress >= 80) {
            $reflection .= 'My recent outcomes are unstable and stress is elevated. ';
        } elseif ((float) $snapshot->confidence >= 75) {
            $reflection .= 'My hypotheses are mostly aligned with the observed outcomes. ';
        } else {
            $reflection .= 'My evidence is mixed and I need more validation before increasing trust. ';
        }

        if ($worstRegime) {
            $reflection .= "Weakest observed regime: {$worstRegime}. ";
        }

        $suggestedAction = $this->suggestedAction($score, $snapshot, $worstRegime);

        $snapshot->selfReflections()->create([
            'training_session_id' => $snapshot->training_session_id,
            'strategy_score_id' => $score->id,
            'strategy' => $score->strategy,
            'reflection' => trim($reflection),
            'observations' => [
                'score' => $score->score,
                'winrate' => $score->winrate,
                'profit_factor' => $score->profit_factor,
                'stress' => $snapshot->stress,
                'trust' => $snapshot->trust,
                'adaptation_pressure' => $snapshot->adaptation_pressure,
                'worst_regime' => $worstRegime,
            ],
            'suggested_action' => $suggestedAction,
            'stress' => $snapshot->stress,
            'adaptation_pressure' => $snapshot->adaptation_pressure,
        ]);
    }

    private function createMemoryIfNeeded(StrategyScore $score, AgentPsychologySnapshot $snapshot): void
    {
        $worstRegime = $this->worstRegime($score);

        if ((float) $snapshot->stress < 65 && (float) $snapshot->adaptation_pressure < 65 && ! $worstRegime) {
            return;
        }

        AgentMemory::create([
            'strategy' => $score->strategy,
            'memory_type' => (float) $snapshot->adaptation_pressure >= 80 ? 'market_mismatch' : 'performance_event',
            'market_regime' => $worstRegime,
            'volatility_regime' => $this->worstVolatility($score),
            'training_session_id' => $snapshot->training_session_id,
            'summary' => "{$score->strategy} recorded {$snapshot->state} state in session #{$snapshot->training_session_id}.",
            'lesson' => $this->memoryLesson($score, $snapshot, $worstRegime),
            'strength' => round(max((float) $snapshot->stress, (float) $snapshot->adaptation_pressure), 2),
            'source_type' => AgentPsychologySnapshot::class,
            'source_id' => $snapshot->id,
            'metadata' => [
                'score' => $score->score,
                'profit_factor' => $score->profit_factor,
                'drawdown' => $score->max_drawdown_percent,
                'risk_of_ruin' => $score->mc_risk_of_ruin_percent,
            ],
        ]);
    }

    private function updateReputation(StrategyScore $score, AgentPsychologySnapshot $snapshot): void
    {
        $reputation = AgentReputation::firstOrNew(['strategy' => $score->strategy]);
        $sessions = (int) ($reputation->sessions_count ?? 0);
        $newSessions = $sessions + 1;
        $calibration = $this->clamp(100 - abs((float) $snapshot->confidence - (float) $score->score));
        $survival = $this->clamp(100 - ((float) ($score->mc_risk_of_ruin_percent ?? 0) * 1.4) - ((float) ($score->mc_worst_drawdown_percent ?? 0) * 0.5));
        $sessionReputation = $this->clamp(
            ((float) $snapshot->trust * 0.25)
            + ((float) $snapshot->stability * 0.25)
            + ($calibration * 0.20)
            + ($survival * 0.20)
            + ((100 - (float) $snapshot->stress) * 0.10)
        );

        $reputation->fill([
            'reputation_score' => $this->weighted((float) ($reputation->reputation_score ?? 50), $sessions, $sessionReputation),
            'stability_score' => $this->weighted((float) ($reputation->stability_score ?? 50), $sessions, (float) $snapshot->stability),
            'trust_score' => $this->weighted((float) ($reputation->trust_score ?? 50), $sessions, (float) $snapshot->trust),
            'calibration_score' => $this->weighted((float) ($reputation->calibration_score ?? 50), $sessions, $calibration),
            'survival_score' => $this->weighted((float) ($reputation->survival_score ?? 50), $sessions, $survival),
            'sessions_count' => $newSessions,
            'last_training_session_id' => $snapshot->training_session_id,
            'reasons' => [
                'latest_state' => $snapshot->state,
                'latest_stress' => $snapshot->stress,
                'latest_trust' => $snapshot->trust,
                'latest_adaptation_pressure' => $snapshot->adaptation_pressure,
                'latest_score' => $score->score,
            ],
        ])->save();
    }

    private function createEvolutionTriggers(StrategyScore $score, AgentPsychologySnapshot $snapshot): void
    {
        if ((float) $snapshot->stress > 80) {
            $snapshot->evolutionTriggers()->create([
                'training_session_id' => $snapshot->training_session_id,
                'strategy_score_id' => $score->id,
                'strategy' => $score->strategy,
                'trigger_type' => 'stress',
                'trigger_value' => $snapshot->stress,
                'threshold' => 80,
                'reason' => "{$score->strategy} stress exceeded 80. Self-observation suggests defensive evolution review.",
                'payload' => [
                    'state' => $snapshot->state,
                    'suggested_action' => $this->suggestedAction($score, $snapshot, $this->worstRegime($score)),
                ],
            ]);
        }

        if ((float) $snapshot->adaptation_pressure > 85) {
            $snapshot->evolutionTriggers()->create([
                'training_session_id' => $snapshot->training_session_id,
                'strategy_score_id' => $score->id,
                'strategy' => $score->strategy,
                'trigger_type' => 'adaptation_pressure',
                'trigger_value' => $snapshot->adaptation_pressure,
                'threshold' => 85,
                'reason' => "{$score->strategy} adaptation pressure exceeded 85. Market/strategy mismatch requires evolution review.",
                'payload' => [
                    'state' => $snapshot->state,
                    'worst_regime' => $this->worstRegime($score),
                    'suggested_action' => $this->suggestedAction($score, $snapshot, $this->worstRegime($score)),
                ],
            ]);
        }
    }

    private function createInternalDebate(TrainingSession $session, Collection $snapshots): void
    {
        $buyVotes = $snapshots->filter(fn (AgentPsychologySnapshot $snapshot): bool => (float) $snapshot->confidence >= 70 && (float) $snapshot->stress < 65)->count();
        $noVotes = $snapshots->filter(fn (AgentPsychologySnapshot $snapshot): bool => (float) $snapshot->stress >= 80 || (float) $snapshot->adaptation_pressure >= 85)->count();
        $finalDecision = $buyVotes > $noVotes ? 'BUY' : ($noVotes > $buyVotes ? 'NO' : 'WAIT');
        $consensus = $snapshots->avg(fn (AgentPsychologySnapshot $snapshot): float => abs((float) $snapshot->confidence - (float) $snapshot->stress));

        $debate = InternalDebate::create([
            'training_session_id' => $session->id,
            'symbol' => $session->symbol,
            'timeframe' => $session->timeframe,
            'final_decision' => $finalDecision,
            'consensus_score' => round($this->clamp((float) $consensus), 2),
            'context' => [
                'buy_votes' => $buyVotes,
                'no_votes' => $noVotes,
                'wait_votes' => max(0, $snapshots->count() - $buyVotes - $noVotes),
            ],
        ]);

        foreach ($snapshots as $snapshot) {
            $stance = $this->debateStance($snapshot);
            $debate->arguments()->create([
                'strategy' => $snapshot->strategy,
                'stance' => $stance,
                'confidence' => $snapshot->confidence,
                'argument' => $this->debateArgument($snapshot, $stance),
                'evidence' => [
                    'stress' => $snapshot->stress,
                    'trust' => $snapshot->trust,
                    'adaptation_pressure' => $snapshot->adaptation_pressure,
                    'state' => $snapshot->state,
                ],
            ]);
        }
    }

    private function stressScore(StrategyScore $score, float $failureRate): float
    {
        $drawdown = (float) ($score->max_drawdown_percent ?? 0);
        $riskOfRuin = (float) ($score->mc_risk_of_ruin_percent ?? 0);
        $lossStreak = (float) ($score->max_consecutive_losses ?? 0);
        $recentPenalty = $this->recentDegradationPenalty($score);

        return $this->clamp(
            ($failureRate * 0.45)
            + ($drawdown * 1.2)
            + ($riskOfRuin * 0.9)
            + ($lossStreak * 4)
            + ($score->is_overfit ? 25 : 0)
            + $recentPenalty
        );
    }

    private function adaptationPressure(StrategyScore $score, float $failureRate): float
    {
        $robustnessGap = 100 - (float) ($score->robustness_score ?? 50);
        $dnaAdaptabilityGap = 100 - (float) ($score->dnaProfile?->adaptability_score ?? 65);
        $trendDependency = (float) ($score->dnaProfile?->trend_dependency ?? 0);
        $rangeLoss = (float) data_get($score->regime_performance, 'range.profit_percent', 0) < 0 ? 18 : 0;
        $worstRegimeLoss = $this->worstRegime($score) ? 12 : 0;

        return $this->clamp(
            ($robustnessGap * 0.35)
            + ($dnaAdaptabilityGap * 0.25)
            + ($failureRate * 0.20)
            + ($trendDependency > 85 ? 12 : 0)
            + $rangeLoss
            + $worstRegimeLoss
            + ($score->is_overfit ? 18 : 0)
        );
    }

    private function beliefTrust(string $strategy): float
    {
        $beliefs = AgentBelief::query()->where('strategy', $strategy)->get();

        if ($beliefs->isEmpty()) {
            return 50;
        }

        return (float) $beliefs->avg('score');
    }

    private function recentDegradationPenalty(StrategyScore $score): float
    {
        $recent = StrategyScore::query()
            ->where('strategy', $score->strategy)
            ->where('id', '<=', $score->id)
            ->latest()
            ->take(5)
            ->get();

        if ($recent->count() < 3) {
            return 0;
        }

        $weakSessions = $recent->filter(fn (StrategyScore $item): bool => (float) $item->score < 50 || (float) $item->net_profit_percent < 0)->count();

        return $weakSessions >= 3 ? 18 : 0;
    }

    private function state(float $stress, float $adaptationPressure): string
    {
        if ($adaptationPressure >= 85) {
            return 'adaptation_required';
        }

        if ($stress >= 80) {
            return 'stressed';
        }

        if ($stress >= 55 || $adaptationPressure >= 60) {
            return 'watch';
        }

        return 'stable';
    }

    private function worstRegime(StrategyScore $score): ?string
    {
        return collect($score->regime_performance ?? [])
            ->filter(fn (array $data): bool => (int) ($data['trades'] ?? 0) >= 3 && (float) ($data['profit_percent'] ?? 0) < 0)
            ->sortBy(fn (array $data): float => (float) ($data['profit_percent'] ?? 0))
            ->keys()
            ->first();
    }

    private function worstVolatility(StrategyScore $score): ?string
    {
        return collect($score->volatility_performance ?? [])
            ->filter(fn (array $data): bool => (int) ($data['trades'] ?? 0) >= 3 && (float) ($data['profit_percent'] ?? 0) < 0)
            ->sortBy(fn (array $data): float => (float) ($data['profit_percent'] ?? 0))
            ->keys()
            ->first();
    }

    private function suggestedAction(StrategyScore $score, AgentPsychologySnapshot $snapshot, ?string $worstRegime): string
    {
        if ((float) $snapshot->adaptation_pressure >= 85) {
            return $worstRegime
                ? "Add regime-aware guardrails for {$worstRegime} and reduce dependency on the current dominant pattern."
                : 'Run regime-specific validation and lower adaptation threshold before next promotion.';
        }

        if ((float) $snapshot->stress >= 80) {
            return 'Reduce risk, increase confirmation, and schedule evolution review before further promotion.';
        }

        if ((float) $snapshot->trust < 45) {
            return 'Collect more out-of-sample evidence before trusting this strategy family.';
        }

        return 'Keep monitoring with the same validation protocol and preserve current guardrails.';
    }

    private function memoryLesson(StrategyScore $score, AgentPsychologySnapshot $snapshot, ?string $worstRegime): string
    {
        if ($worstRegime) {
            return "When {$worstRegime} appears again, lower trust in {$score->strategy} unless fresh evidence improves.";
        }

        if ((float) $snapshot->stress >= 80) {
            return 'High stress state should reduce risk and trigger defensive validation.';
        }

        return 'Adaptation pressure rose; compare future sessions against this context before evolving.';
    }

    private function debateStance(AgentPsychologySnapshot $snapshot): string
    {
        if ((float) $snapshot->stress >= 80 || (float) $snapshot->adaptation_pressure >= 85) {
            return 'NO';
        }

        if ((float) $snapshot->confidence >= 70 && (float) $snapshot->trust >= 60) {
            return 'BUY';
        }

        return 'WAIT';
    }

    private function debateArgument(AgentPsychologySnapshot $snapshot, string $stance): string
    {
        return match ($stance) {
            'BUY' => "{$snapshot->strategy}: confidence and trust are strong enough to support the signal.",
            'NO' => "{$snapshot->strategy}: stress or adaptation pressure is too high for safe continuation.",
            default => "{$snapshot->strategy}: evidence is mixed; wait for stronger confirmation.",
        };
    }

    private function weighted(float $oldValue, int $oldSamples, float $newValue): float
    {
        $total = $oldSamples + 1;

        return round($this->clamp((($oldValue * $oldSamples) + $newValue) / max(1, $total)), 2);
    }

    private function clamp(float $value, float $min = 0, float $max = 100): float
    {
        return max($min, min($max, $value));
    }
}
