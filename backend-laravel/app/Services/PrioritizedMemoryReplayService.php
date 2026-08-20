<?php

namespace App\Services;

use App\Models\DualTrackMemoryLesson;
use App\Models\DualTrackMemoryReplay;
use App\Models\DualTrackOutcome;
use Illuminate\Support\Facades\Schema;

/** Rare, costly and surprising lessons receive replay priority. */
class PrioritizedMemoryReplayService
{
    public const PROTOCOL = 'dual_track_prioritized_memory_replay_v1';

    public function enqueue(DualTrackOutcome $outcome, ?DualTrackMemoryLesson $lesson = null): array
    {
        if (! Schema::hasTable('dual_track_memory_replay_queue')) return ['status' => 'unavailable', 'promotion_evidence' => false];
        $failure = $outcome->correct === false || in_array($outcome->actual_outcome, ['loss', 'missed_opportunity'], true);
        $dissent = $outcome->run?->disagreement_code !== null && $outcome->run?->disagreement_code !== 'agreement';
        $regret = abs((float) ($outcome->regret ?? 0));
        $priority = round(1 + ($failure ? 3 : 0) + ($dissent ? 2 : 0) + min(5, $regret * 2) + (is_null($outcome->confidence) ? 1 : 0), 6);
        $key = hash('sha256', self::PROTOCOL.'|'.$outcome->outcome_key);
        $row = DualTrackMemoryReplay::query()->updateOrCreate(['replay_key' => $key], [
            'dual_track_outcome_id' => $outcome->id, 'dual_track_memory_lesson_id' => $lesson?->id,
            'symbol' => $outcome->symbol, 'timeframe' => $outcome->timeframe, 'cell_key' => $outcome->cell_key, 'lane' => $outcome->lane,
            'priority_score' => $priority, 'priority_reason' => implode(',', array_filter([$failure ? 'rare_failure' : null, $dissent ? 'productive_dissent' : null, $regret > 0 ? 'high_regret' : null])),
            'status' => 'queued', 'available_at' => now(), 'evidence' => ['protocol' => self::PROTOCOL, 'promotion_evidence' => false],
        ]);
        return ['status' => $row->status, 'priority_score' => $row->priority_score, 'replay_id' => $row->id, 'promotion_evidence' => false];
    }

    public function claim(int $limit = 8)
    {
        if (! Schema::hasTable('dual_track_memory_replay_queue')) return collect();
        return DualTrackMemoryReplay::query()->whereIn('status', ['queued', 'retry'])->where(function ($query): void { $query->whereNull('available_at')->orWhere('available_at', '<=', now()); })->orderByDesc('priority_score')->limit(max(1, min(50, $limit)))->get();
    }
}
