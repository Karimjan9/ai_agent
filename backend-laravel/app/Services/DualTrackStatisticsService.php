<?php

namespace App\Services;

use App\Models\DualTrackCellStatistic;
use App\Models\DualTrackOutcome;
use App\Models\DualTrackStatisticEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Atomic O(1) cell/lane aggregates with an idempotent event ledger. */
class DualTrackStatisticsService
{
    public const PROTOCOL = 'dual_track_materialized_statistics_v1';

    public function record(DualTrackOutcome $outcome): array
    {
        if (! Schema::hasTable('dual_track_cell_statistics') || ! Schema::hasTable('dual_track_statistic_events')) return ['status' => 'unavailable', 'promotion_evidence' => false];
        return DB::transaction(function () use ($outcome): array {
            $eventKey = hash('sha256', self::PROTOCOL.'|'.$outcome->id.'|'.$outcome->lane);
            $event = DualTrackStatisticEvent::query()->firstOrCreate(['event_key' => $eventKey], ['dual_track_outcome_id' => $outcome->id, 'cell_key' => $outcome->cell_key, 'lane' => $outcome->lane]);
            if (! $event->wasRecentlyCreated) return ['status' => 'already_recorded', 'promotion_evidence' => false];
            $key = hash('sha256', self::PROTOCOL.'|'.$outcome->symbol.'|'.$outcome->timeframe.'|'.$outcome->cell_key.'|'.$outcome->lane);
            DualTrackCellStatistic::query()->firstOrCreate(['stat_key' => $key], ['symbol' => $outcome->symbol, 'timeframe' => $outcome->timeframe, 'cell_key' => $outcome->cell_key, 'lane' => $outcome->lane]);
            $stat = DualTrackCellStatistic::query()->where('stat_key', $key)->lockForUpdate()->firstOrFail();
            $reward = is_numeric($outcome->reward) ? (float) $outcome->reward : 0.0;
            $known = $outcome->correct !== null;
            $updates = [
                'settled_count' => (int) $stat->settled_count + 1,
                'known_count' => (int) $stat->known_count + ($known ? 1 : 0),
                'wins' => (int) $stat->wins + ($outcome->correct === true ? 1 : 0),
                'action_count' => (int) $stat->action_count + (in_array($outcome->decision, ['BUY', 'SELL'], true) ? 1 : 0),
                'risk_violation_count' => (int) $stat->risk_violation_count + (((bool) data_get($outcome->metadata, 'risk_evidence_missing', false)) ? 1 : 0),
                'reward_sum' => (float) $stat->reward_sum + $reward,
                'reward_sq_sum' => (float) $stat->reward_sq_sum + ($reward ** 2),
                'regret_sum' => (float) $stat->regret_sum + (float) ($outcome->regret ?? 0),
                'last_observed_at' => $outcome->settled_at ?: now(),
            ];
            $stat->update($updates);
            return ['status' => 'recorded', 'stat_id' => $stat->id, 'promotion_evidence' => false];
        });
    }

    public function forScope(string $symbol, string $timeframe, string $cellKey, ?string $lane = null)
    {
        if (! Schema::hasTable('dual_track_cell_statistics')) return collect();
        $query = DualTrackCellStatistic::query()->where(['symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe), 'cell_key' => $cellKey]);
        if ($lane !== null) $query->where('lane', $lane);
        return $query->get();
    }
}
