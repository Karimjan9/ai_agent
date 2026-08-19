<?php

namespace App\Services;

use App\Models\DualTrackMemoryLesson;
use App\Models\DualTrackOutcome;
use Illuminate\Support\Facades\Schema;

/** Scoped institutional memory: raw observations never become constitution. */
class DualTrackMemoryService
{
    public const PROTOCOL = 'dual_track_layered_memory_v1';

    /** @return array<string, mixed> */
    public function settle(DualTrackOutcome $outcome): array
    {
        if (! Schema::hasTable('dual_track_memory_lessons')) return ['status' => 'unavailable', 'promotion_evidence' => false];
        $failure = $outcome->actual_outcome !== 'win';
        $statement = sprintf('%s lane %s in %s produced %s.', strtoupper($outcome->lane), $outcome->decision, $outcome->cell_key, $outcome->actual_outcome ?: 'unknown');
        $key = hash('sha256', self::PROTOCOL.'|'.$outcome->outcome_key);
        $raw = DualTrackMemoryLesson::query()->updateOrCreate(
            ['lesson_key' => $key],
            [
                'layer' => $failure ? 'failure' : 'raw', 'status' => $outcome->outcome_status,
                'symbol' => $outcome->symbol, 'timeframe' => $outcome->timeframe, 'cell_key' => $outcome->cell_key,
                'lane' => $outcome->lane, 'failure_signature' => $failure ? ($outcome->metadata['failure_signature'] ?? 'dual_track_outcome_failure') : null,
                'statement' => $statement, 'lesson' => $failure ? 'Require a bounded repair and fresh evidence before reuse.' : 'Retain as a scoped observation until repeated confirmation.',
                'sample_count' => 1, 'confidence' => 0.0, 'source_run_id' => $outcome->dual_track_run_id, 'source_outcome_id' => $outcome->id,
                'evidence' => ['protocol' => self::PROTOCOL, 'promotion_evidence' => false], 'verified_at' => null,
                'promotion_evidence' => false,
            ],
        );

        $confirmed = DualTrackOutcome::query()->where('cell_key', $outcome->cell_key)->where('lane', $outcome->lane)->where('outcome_status', 'settled')->where('correct', $failure ? false : true)->count();
        $minimum = max(2, (int) config('services.dual_track.memory_minimum_confirmations', 3));
        if (! $failure && $confirmed >= $minimum) {
            $raw->update(['layer' => 'verified', 'status' => 'verified', 'sample_count' => $confirmed, 'confidence' => min(1, $confirmed / max($minimum, 1)), 'verified_at' => now(), 'evidence' => ['protocol' => self::PROTOCOL, 'confirmation_count' => $confirmed, 'promotion_evidence' => false]]);
        }
        return ['status' => $raw->status, 'layer' => $raw->layer, 'lesson_id' => $raw->id, 'promotion_evidence' => false];
    }
}
