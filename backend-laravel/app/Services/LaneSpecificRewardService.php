<?php

namespace App\Services;

use App\Models\DualTrackOutcome;

/** Different reward semantics for execution and governance organisms. */
class LaneSpecificRewardService
{
    public const PROTOCOL = 'twin_intelligence_lane_reward_v1';

    public function __construct(private TwinIntelligenceProfileService $profiles) {}

    /** @return array<string, mixed> */
    public function score(DualTrackOutcome $outcome): array
    {
        $lane = (string) $outcome->lane;
        $profile = $this->profiles->profile($lane);
        $profit = max(-1.0, min(1.0, (float) ($outcome->profit_percent ?? 0)));
        $regret = max(0.0, min(1.0, (float) ($outcome->regret ?? 0)));
        $correct = $outcome->correct === true;
        $actual = (string) ($outcome->actual_outcome ?? 'unknown');

        if ($lane === 'champion') {
            $components = [
                'profit' => $profit,
                'risk_adjusted_return' => $profit - $regret,
                'execution_quality' => $correct ? 1.0 : -1.0,
                'temporal_stability' => $actual === 'win' ? 1.0 : -1.0,
                'cost_efficiency' => $profit > 0 ? 1.0 : ($profit < 0 ? -1.0 : 0.0),
            ];
            $failureClass = $correct ? null : (in_array($actual, ['loss', 'missed_opportunity'], true) ? 'execution_loss' : 'execution_uncertainty');
            $creditType = $correct ? 'execution_success' : 'execution_failure';
        } else {
            $components = [
                'decision_quality' => $correct ? 1.0 : -1.0,
                'risk_avoided' => $actual === 'avoided_loss' ? 1.0 : ($actual === 'missed_opportunity' ? -1.0 : 0.0),
                'useful_dissent' => $actual === 'avoided_loss' ? 1.0 : 0.0,
                'regime_coverage' => $actual === 'counterfactual_unknown' ? -.5 : .5,
                'calibration' => $correct ? 1.0 : -1.0,
                'complementarity' => $actual === 'avoided_loss' || $actual === 'missed_opportunity' ? 1.0 : 0.0,
            ];
            $failureClass = $correct ? null : match ($actual) {
                'missed_opportunity' => 'coverage_gap',
                'loss' => 'oversight_failure',
                default => 'collective_uncertainty',
            };
            $creditType = $correct ? ($actual === 'avoided_loss' ? 'risk_veto_success' : 'council_success') : 'council_failure';
        }

        $weights = (array) $profile['reward_weights'];
        $reward = 0.0;
        foreach ($weights as $key => $weight) $reward += (float) ($components[$key] ?? 0) * (float) $weight;

        return [
            'protocol' => self::PROTOCOL, 'lane' => $lane,
            'reward' => round(max(-1.0, min(1.0, $reward)), 6),
            'components' => $components, 'credit_type' => $creditType,
            'failure_class' => $failureClass, 'learning_objective' => $profile['learning_objective'],
            'memory_namespace' => $profile['memory_namespace'],
            'promotion_policy' => $profile['promotion_policy'],
            'transfer_policy' => $profile['transfer_policy'],
            'promotion_evidence' => false,
        ];
    }
}
