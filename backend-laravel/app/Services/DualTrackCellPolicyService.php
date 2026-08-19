<?php

namespace App\Services;

use App\Models\DualTrackCellPolicy;
use App\Models\DualTrackOutcome;
use Illuminate\Support\Facades\Schema;

/** Learns a conservative per-cell recommendation from settled lane outcomes. */
class DualTrackCellPolicyService
{
    public const PROTOCOL = 'dual_track_safe_cell_policy_v1';

    /** @return array<string, mixed> */
    public function route(array $context): array
    {
        $cell = DualTrackDecisionService::cellKey($context);
        $default = [
            'protocol' => self::PROTOCOL, 'cell_key' => $cell, 'available' => false,
            'status' => 'learning', 'recommended_lane' => 'incumbent', 'active_lane' => 'incumbent',
            'sample_count' => 0, 'promotion_evidence' => false,
        ];
        if (! $this->hasTable('dual_track_cell_policies')) return $default;

        $policy = DualTrackCellPolicy::query()->where('cell_key', $cell)->first();
        if (! $policy) return $default;

        $active = (string) config('services.dual_track.mode', 'shadow') === 'active'
            && (bool) config('services.dual_track.activate_certified_cells', false)
            && $policy->status === 'certified';

        return [
            'protocol' => self::PROTOCOL, 'cell_key' => $cell, 'available' => true,
            'status' => $policy->status, 'recommended_lane' => $policy->recommended_lane,
            'active_lane' => $active ? $policy->recommended_lane : 'incumbent',
            'sample_count' => $policy->sample_count, 'confidence_margin' => $policy->confidence_margin,
            'disagreement_value' => $policy->disagreement_value, 'lane_statistics' => $policy->lane_statistics,
            'risk_bounds' => $policy->risk_bounds, 'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function update(DualTrackOutcome $outcome): array
    {
        if (! $this->hasTable('dual_track_cell_policies')) return ['status' => 'unavailable', 'promotion_evidence' => false];

        $scope = ['symbol' => $outcome->symbol, 'timeframe' => $outcome->timeframe, 'cell_key' => $outcome->cell_key];
        $rows = DualTrackOutcome::query()->where($scope)->where('outcome_status', 'settled')->get();
        $minimum = max(1, (int) config('services.dual_track.cell_minimum_samples', 30));
        $statistics = [];
        foreach ($rows->groupBy('lane') as $lane => $laneRows) {
            $known = $laneRows->filter(fn (DualTrackOutcome $row): bool => $row->correct !== null);
            $wins = $known->filter(fn (DualTrackOutcome $row): bool => $row->correct === true)->count();
            $sample = $known->count();
            $meanReward = $known->avg(fn (DualTrackOutcome $row): float => (float) ($row->reward ?? 0)) ?? 0.0;
            $lower = $this->wilsonLowerBound($wins, $sample);
            $statistics[$lane] = [
                'sample_count' => $sample, 'wins' => $wins,
                'win_rate' => $sample > 0 ? round($wins / $sample, 6) : 0.0,
                'lower_bound' => round($lower, 6), 'mean_reward' => round($meanReward, 6),
                'score' => round(($lower * 100) + max(-10, min(10, $meanReward)), 6),
            ];
        }

        $eligible = collect($statistics)->filter(fn (array $stats): bool => $stats['sample_count'] >= $minimum);
        $ranked = $eligible->sortByDesc('score');
        $recommended = (string) ($ranked->keys()->first() ?: 'incumbent');
        $best = (float) ($ranked->first()['score'] ?? 0);
        $second = (float) ($ranked->skip(1)->first()['score'] ?? 0);
        $margin = $best - $second;
        $minimumMargin = (float) config('services.dual_track.cell_minimum_score_margin', 2.0);
        $riskViolation = $rows->contains(fn (DualTrackOutcome $row): bool => (float) ($row->risk_percent ?? 0) > (float) config('services.risk.max_risk_per_trade_percent', 1));
        $certified = $eligible->isNotEmpty() && $margin >= $minimumMargin && ! $riskViolation;
        $status = $certified ? 'certified' : ($rows->isEmpty() ? 'learning' : 'candidate');

        $policy = DualTrackCellPolicy::query()->updateOrCreate(
            ['policy_key' => 'cell:'.$outcome->cell_key],
            [
                ...$scope,
                'mode' => (string) config('services.dual_track.mode', 'shadow'),
                'recommended_lane' => $recommended,
                'active_lane' => 'incumbent',
                'status' => $status,
                'sample_count' => $rows->count(),
                'minimum_samples' => $minimum,
                'confidence_margin' => round($margin, 6),
                'disagreement_value' => round((float) $rows->sum(fn (DualTrackOutcome $row): float => (float) ($row->regret ?? 0)), 6),
                'lane_statistics' => $statistics,
                'risk_bounds' => ['risk_violation' => $riskViolation, 'promotion_evidence' => false],
                'policy' => ['protocol' => self::PROTOCOL, 'rule' => 'lower_bound_plus_reward_margin', 'promotion_evidence' => false],
                'policy_hash' => hash('sha256', json_encode([$scope, $statistics, $status], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
                'last_outcome_at' => $outcome->settled_at ?: now(),
                'certified_at' => $certified ? now() : null,
                'promotion_evidence' => false,
            ],
        );

        return ['status' => $policy->status, 'recommended_lane' => $policy->recommended_lane, 'sample_count' => $policy->sample_count, 'promotion_evidence' => false];
    }

    private function wilsonLowerBound(int $wins, int $sample): float
    {
        if ($sample < 1) return 0.0;
        $z = 1.96; $p = $wins / $sample; $denominator = 1 + ($z ** 2 / $sample);
        $centre = ($p + ($z ** 2 / (2 * $sample))) / $denominator;
        $margin = $z * sqrt(($p * (1 - $p) / $sample) + (($z ** 2) / (4 * ($sample ** 2)))) / $denominator;
        return max(0.0, $centre - $margin);
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
