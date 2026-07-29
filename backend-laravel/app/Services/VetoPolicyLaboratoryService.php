<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\ShadowVetoObservation;
use App\Models\VetoPolicyEvaluation;

/** Contextual veto evidence. Counterfactual shadow data can guide research, never promotion. */
class VetoPolicyLaboratoryService
{
    public function evaluate(LabAgent $agent): array
    {
        $records = ShadowVetoObservation::query()->where('lab_agent_id', $agent->id)->get()
            ->groupBy(fn (ShadowVetoObservation $row) => implode('|', [
                $row->veto_reason, $row->market_regime ?: 'unknown', $row->volatility_regime ?: 'unknown', $row->spread_context ?: 'unknown',
            ]));
        $evaluations = [];
        foreach ($records as $context => $rows) {
            $values = $rows->map(fn ($row) => (float) $row->shadow_profit_percent)->values()->all();
            $count = count($values); $months = $rows->map(fn ($row) => optional($row->exit_time ?? $row->signal_time)->format('Y-m'))->filter()->unique()->count();
            $explorations = $rows->where('exploration_assigned', true)->count();
            // A doubly-robust estimator needs logged propensities.  The
            // counterfactuals here are still learning-only, so the result is
            // explicitly marked as such and cannot authorize a real-policy change.
            $mean = $count ? array_sum($values) / $count : 0.0;
            $dr = $count ? collect($rows)->avg(function ($row) use ($mean) {
                $p = max(.01, (float) ($row->p_allow ?? 0));
                return $mean + ($row->exploration_assigned ? ((float) $row->shadow_profit_percent - $mean) / $p : 0.0);
            }) : null;
            $variance = $count > 1 ? collect($values)->map(fn ($value) => ($value - $mean) ** 2)->sum() / ($count - 1) : 0.0;
            $lcb = $dr === null ? null : $dr - 1.645 * sqrt($variance / max(1, $count));
            [$reason] = explode('|', $context, 2);
            $eligible = $count >= 30 && $months >= 3 && $explorations >= 5 && ($lcb ?? -INF) > 0;
            $action = $eligible ? 'bounded_relaxation_experiment' : 'preserve_veto';
            $status = $count < 30 || $months < 3 ? 'insufficient_context_evidence'
                : ($explorations < 5 ? 'waiting_for_shadow_exploration' : ($eligible ? 'counterfactual_candidate' : 'negative_or_uncertain'));
            $record = VetoPolicyEvaluation::updateOrCreate([
                'lab_agent_id' => $agent->id, 'veto_reason' => $reason, 'context_key' => $context,
            ], [
                'sample_count' => $count, 'calendar_windows' => $months, 'doubly_robust_value' => $dr,
                'lower_confidence_bound' => $lcb, 'status' => $status, 'recommended_action' => $action,
                'evidence' => ['protocol' => 'contextual doubly-robust shadow OPE; counterfactual-only',
                    'exploration_count' => $explorations, 'mean_shadow_return_percent' => $mean,
                    'rule' => 'Only a positive lower confidence bound across three calendar windows may create a bounded mutation experiment.'],
            ]);
            $evaluations[] = ['id' => $record->id, 'veto_reason' => $reason, 'context' => $context,
                'status' => $status, 'recommended_action' => $action, 'sample_count' => $count,
                'lower_confidence_bound' => $lcb];
        }
        return ['protocol' => 'veto_policy_lab_v1', 'scope' => 'shadow counterfactuals only; no execution-policy auto-change', 'evaluations' => $evaluations];
    }

    public function recommendedTarget(string $symbol, string $timeframe, string $family): ?string
    {
        $evaluation = VetoPolicyEvaluation::query()->where('recommended_action', 'bounded_relaxation_experiment')
            ->whereHas('labAgent', fn ($query) => $query->where('symbol', $symbol)->where('timeframe', $timeframe)->where('strategy_family', $family))
            ->orderByDesc('lower_confidence_bound')->first();
        return match ($evaluation?->veto_reason) {
            'loss_cooldown' => 'shadow_veto_loss_cooldown', 'minimum_confidence' => 'shadow_veto_confidence',
            'high_volatility_veto' => 'shadow_veto_volatility', default => null,
        };
    }
}
