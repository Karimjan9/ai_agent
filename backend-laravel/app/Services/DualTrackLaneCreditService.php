<?php

namespace App\Services;

use App\Models\DualTrackLaneCredit;
use App\Models\DualTrackOutcome;
use Illuminate\Support\Facades\Schema;

/** Counterfactual credit assignment keeps lane and agent contribution separate. */
class DualTrackLaneCreditService
{
    public const PROTOCOL = 'twin_intelligence_counterfactual_credit_v1';

    public function __construct(private LaneSpecificRewardService $rewards) {}

    /** @return array<string, mixed> */
    public function record(DualTrackOutcome $outcome): array
    {
        if (! $this->hasTable('dual_track_lane_credits')) return ['status' => 'unavailable', 'promotion_evidence' => false];
        $reward = $this->rewards->score($outcome);
        $peer = DualTrackOutcome::query()
            ->where('dual_track_run_id', $outcome->dual_track_run_id)
            ->where('lane', '!=', $outcome->lane)
            ->first();
        $peerReward = $peer ? $this->rewards->score($peer)['reward'] : null;
        $delta = $peerReward === null ? null : round((float) $reward['reward'] - (float) $peerReward, 6);
        $key = hash('sha256', self::PROTOCOL.'|'.$outcome->outcome_key);
        $credit = DualTrackLaneCredit::query()->updateOrCreate(
            ['credit_key' => $key],
            [
                'dual_track_run_id' => $outcome->dual_track_run_id,
                'dual_track_outcome_id' => $outcome->id,
                'symbol' => $outcome->symbol, 'timeframe' => $outcome->timeframe,
                'cell_key' => $outcome->cell_key, 'lane' => $outcome->lane,
                'agent_key' => data_get($outcome->metadata, 'agent_key'),
                'credit_type' => $reward['credit_type'], 'reward' => $reward['reward'],
                'counterfactual_delta' => $delta, 'components' => $reward['components'],
                'evidence' => [
                    'protocol' => self::PROTOCOL, 'learning_objective' => $reward['learning_objective'],
                    'peer_lane' => $peer?->lane, 'peer_reward' => $peerReward, 'promotion_evidence' => false,
                ],
                'promotion_evidence' => false,
            ],
        );

        return [
            'status' => 'recorded', 'credit_id' => $credit->id, 'reward' => $reward['reward'],
            'counterfactual_delta' => $delta, 'credit_type' => $reward['credit_type'],
            'promotion_evidence' => false,
        ];
    }

    private function hasTable(string $table): bool
    {
        try { return Schema::hasTable($table); } catch (\Throwable) { return false; }
    }
}
