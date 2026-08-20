<?php

namespace App\Services;

use App\Models\DualTrackReflectionLesson;
use App\Models\DualTrackOutcome;
use Illuminate\Support\Facades\Schema;

/** Episodic reflection that becomes durable only after independent repeats. */
class TwinReflectionService
{
    public const PROTOCOL = 'twin_reflection_memory_v1';

    /** @return array<string, mixed> */
    public function record(DualTrackOutcome $outcome): array
    {
        if (! Schema::hasTable('dual_track_reflection_lessons')) return ['status' => 'unavailable', 'promotion_evidence' => false];
        $failure = (string) (data_get($outcome->metadata, 'failure_class') ?: data_get($outcome->metadata, 'failure_signature') ?: $outcome->actual_outcome ?: 'unknown');
        $reflection = $this->reflection($outcome, $failure);
        $key = hash('sha256', self::PROTOCOL.'|'.$outcome->lane.'|'.$outcome->cell_key.'|'.$failure);
        $existing = DualTrackReflectionLesson::query()->where('reflection_key', $key)->first();
        $confirmations = (int) ($existing?->independent_confirmations ?? 0);
        $independent = DualTrackOutcome::query()->where('cell_key', $outcome->cell_key)->where('lane', $outcome->lane)
            ->whereJsonContains('metadata->failure_class', $failure)->distinct('dual_track_run_id')->count('dual_track_run_id');
        $confirmations = max($confirmations + ($existing ? 0 : 1), $independent);
        $promoted = $confirmations >= (int) config('services.twin_intelligence.reflection_minimum_confirmations', 2);
        $lesson = DualTrackReflectionLesson::query()->updateOrCreate(
            ['reflection_key' => $key],
            [
                'dual_track_outcome_id' => $outcome->id, 'symbol' => $outcome->symbol, 'timeframe' => $outcome->timeframe,
                'cell_key' => $outcome->cell_key, 'lane' => $outcome->lane, 'failure_class' => $failure,
                'reflection' => $reflection, 'independent_confirmations' => $confirmations,
                'status' => $promoted ? 'confirmed' : 'provisional',
                'evidence' => ['protocol' => self::PROTOCOL, 'independent_run_count' => $independent, 'promotion_evidence' => false],
                'promoted_at' => $promoted ? ($existing?->promoted_at ?: now()) : null,
            ],
        );
        return ['status' => $lesson->status, 'reflection_id' => $lesson->id, 'confirmations' => $confirmations, 'promotion_evidence' => false];
    }

    private function reflection(DualTrackOutcome $outcome, string $failure): string
    {
        return sprintf('%s lane observed %s in %s. Re-test the declared decision under an independent snapshot and penalize confidence if the same failure repeats.', ucfirst($outcome->lane), $failure, $outcome->cell_key);
    }
}
