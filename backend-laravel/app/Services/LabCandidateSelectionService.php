<?php

namespace App\Services;

use Illuminate\Support\Collection;

class LabCandidateSelectionService
{
    /**
     * A finalist count is never pre-decided. Every non-dominated, behaviourally
     * distinct research claim that has enough screening evidence proceeds to
     * full replay; it may be zero or the whole viable frontier.
     */
    public function select(Collection $agents): Collection
    {
        $ranked = $agents->filter(fn ($agent) => $this->isWorthFullReplay($agent))
            ->sortByDesc(fn ($agent) => [
            (float) $agent->profit_factor,
            $this->coverage($agent),
            $this->rollingConsistency($agent),
            $this->stressRobustness($agent),
            (float) $agent->forward_score,
            (int) $agent->sample_count,
            -(float) $agent->max_drawdown,
        ])->values();
        $front = $ranked->filter(fn ($candidate) => ! $ranked->contains(
            fn ($other) => $other->id !== $candidate->id && $this->dominates($other, $candidate)
        ))->values();
        return $front->filter(fn ($agent) => ! $this->isNearDuplicate($agent, $front))->values();
    }

    private function isWorthFullReplay(object $agent): bool
    {
        // This is not a promotion gate. It only avoids burning a historical
        // replay on a candidate that emitted no observable evidence at all.
        return (int) $agent->sample_count >= (int) config('services.lab_selection.minimum_screening_trades', 10)
            && (float) $agent->forward_score > 0
            && (float) $agent->profit_factor > 0
            && $this->validOpportunities($agent) > 0;
    }

    private function isNearDuplicate(object $candidate, Collection $front): bool
    {
        $metrics = data_get($candidate, 'modelVersion.metadata.last_result.behavioral_diversity', []);
        if (data_get($metrics, 'status') === 'near_duplicate') return true;
        // Screening currently has no batch diversity evidence. Do not discard
        // a different family simply because its indicators look similar; full
        // replay computes signal/trade/equity similarity before promotion.
        return false;
    }

    private function dominates(object $left, object $right): bool
    {
        $betterOrEqual = (float) $left->profit_factor >= (float) $right->profit_factor
            && $this->coverage($left) >= $this->coverage($right)
            && $this->rollingConsistency($left) >= $this->rollingConsistency($right)
            && $this->stressRobustness($left) >= $this->stressRobustness($right)
            && (float) $left->forward_score >= (float) $right->forward_score
            && (float) $left->max_drawdown <= (float) $right->max_drawdown
            && (float) $left->risk_of_ruin <= (float) $right->risk_of_ruin
            && (int) $left->sample_count >= (int) $right->sample_count;
        $strictlyBetter = (float) $left->profit_factor > (float) $right->profit_factor
            || $this->coverage($left) > $this->coverage($right)
            || $this->rollingConsistency($left) > $this->rollingConsistency($right)
            || $this->stressRobustness($left) > $this->stressRobustness($right)
            || (float) $left->forward_score > (float) $right->forward_score
            || (float) $left->max_drawdown < (float) $right->max_drawdown
            || (float) $left->risk_of_ruin < (float) $right->risk_of_ruin
            || (int) $left->sample_count > (int) $right->sample_count;
        return $betterOrEqual && $strictlyBetter;
    }

    private function result(object $agent): array
    {
        return (array) data_get($agent, 'modelVersion.metadata.last_screen_result', []);
    }

    private function validOpportunities(object $agent): int
    {
        return (int) data_get($this->result($agent), 'opportunity_metrics.valid_signal_opportunities', data_get($this->result($agent), 'entry_funnel.flat_signal_opportunities', $agent->sample_count ?? 0));
    }

    private function coverage(object $agent): float
    {
        $result = $this->result($agent);
        $opportunities = $this->validOpportunities($agent);
        return (float) data_get($result, 'opportunity_metrics.coverage', $opportunities ? (int) data_get($result, 'entry_funnel.accepted_entries', 0) / $opportunities : 0);
    }

    private function rollingConsistency(object $agent): int
    {
        return (int) data_get($this->result($agent), 'monthly_passport.rolling_forward_wins', data_get($this->result($agent), 'window_survival.positive_windows', 0));
    }

    private function stressRobustness(object $agent): float
    {
        return (float) data_get($this->result($agent), 'pf_attribution.stress_cost.profit_factor', $agent->profit_factor);
    }
}
