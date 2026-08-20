<?php

namespace App\Services;

use App\Models\DualTrackDriftState;
use App\Models\DualTrackOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Lane-scoped CUSUM with hysteresis: warn -> reduce risk -> quarantine -> recover. */
class DualTrackDriftEngineService
{
    public const PROTOCOL = 'dual_track_cusum_drift_v1';

    public function observe(DualTrackOutcome $outcome): array
    {
        if (! Schema::hasTable('dual_track_drift_states') || ! is_numeric($outcome->reward)) return ['status' => 'unavailable', 'promotion_evidence' => false];
        return DB::transaction(function () use ($outcome): array {
            $key = hash('sha256', self::PROTOCOL.'|'.$outcome->symbol.'|'.$outcome->timeframe.'|'.$outcome->cell_key.'|'.$outcome->lane);
            DualTrackDriftState::query()->firstOrCreate(['state_key' => $key], ['symbol' => $outcome->symbol, 'timeframe' => $outcome->timeframe, 'cell_key' => $outcome->cell_key, 'lane' => $outcome->lane]);
            $state = DualTrackDriftState::query()->where('state_key', $key)->lockForUpdate()->firstOrFail();
            $value = (float) $outcome->reward;
            $n = (int) $state->sample_count;
            $baseline = $n > 0 ? (float) $state->baseline_mean : $value;
            $k = (float) config('services.twin_intelligence.drift_cusum_slack', .05);
            $positive = max(0.0, (float) $state->cusum_positive + ($value - $baseline - $k));
            $negative = max(0.0, (float) $state->cusum_negative + ($baseline - $value - $k));
            $threshold = (float) config('services.twin_intelligence.drift_cusum_threshold', 2.5);
            $warnThreshold = $threshold * .45;
            $score = max($positive, $negative);
            $previous = (string) $state->state;
            $next = $score >= $threshold ? 'quarantine' : ($score >= $warnThreshold ? 'risk_reduce' : ($previous === 'quarantine' ? 'recover' : 'healthy'));
            if ($previous === 'recover' && $score < $warnThreshold) $next = 'healthy';
            $state->update([
                'state' => $next, 'baseline_mean' => $n > 0 ? (($baseline * $n + $value) / ($n + 1)) : $value,
                'cusum_positive' => $positive, 'cusum_negative' => $negative, 'last_value' => $value,
                'sample_count' => $n + 1, 'warning_count' => (int) $state->warning_count + ($next !== 'healthy' ? 1 : 0),
                'last_change_at' => $next !== $previous ? now() : $state->last_change_at,
                'evidence' => ['protocol' => self::PROTOCOL, 'previous_state' => $previous, 'score' => round($score, 6), 'promotion_evidence' => false],
            ]);
            return ['status' => $next, 'state' => $next, 'score' => round($score, 6), 'promotion_evidence' => false];
        });
    }
}
