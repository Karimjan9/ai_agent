<?php

namespace App\Services;

use App\Models\DualTrackEvaluatorCalibration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DualTrackEvaluatorCalibrationService
{
    public const PROTOCOL = 'dual_track_evaluator_calibration_v1';

    /** @return array<string, mixed> */
    public function record(string $evaluator, string $cellKey, float $probability, bool $correct, array $evidence = []): array
    {
        if (! Schema::hasTable('dual_track_evaluator_calibrations')) return ['status' => 'unavailable', 'promotion_evidence' => false];
        $probability = max(0.0, min(1.0, $probability));
        return DB::transaction(function () use ($evaluator, $cellKey, $probability, $correct, $evidence): array {
            $key = $evaluator.'|'.$cellKey;
            // Re-read under a row lock. Settlement is resumable and can be
            // concurrent; a plain firstOrNew + save would silently overwrite
            // another outcome's sample and bin update.
            DualTrackEvaluatorCalibration::query()->firstOrCreate(
                ['calibration_key' => $key],
                ['evaluator' => $evaluator, 'cell_key' => $cellKey, 'sample_count' => 0, 'correct_count' => 0,
                    'false_positive_count' => 0, 'false_negative_count' => 0, 'brier_score' => 0,
                    'calibration_error' => 0, 'reputation_score' => 0, 'bins' => [], 'evidence' => [], 'status' => 'uncalibrated'],
            );
            $row = DualTrackEvaluatorCalibration::query()->where('calibration_key', $key)->lockForUpdate()->firstOrFail();
            $sample = (int) $row->sample_count + 1;
            $correctCount = (int) $row->correct_count + ($correct ? 1 : 0);
            $bin = min(4, (int) floor($probability * 5));
            $bins = (array) $row->bins; $bins[$bin] ??= ['samples' => 0, 'correct' => 0, 'probability_sum' => 0.0];
            $bins[$bin]['samples']++; $bins[$bin]['correct'] += $correct ? 1 : 0; $bins[$bin]['probability_sum'] += $probability;
            $calibrationError = collect($bins)->sum(function (array $item): float {
                $mean = (float) $item['probability_sum'] / max(1, (int) $item['samples']);
                $actual = (int) $item['correct'] / max(1, (int) $item['samples']);
                return abs($mean - $actual) * (int) $item['samples'];
            }) / $sample;
            $brier = ((float) ($row->brier_score ?? 0) * max(0, $sample - 1) + (($probability - ($correct ? 1 : 0)) ** 2)) / $sample;
            $minimum = max(1, (int) config('services.dual_track.evaluator_minimum_samples', 20));
            $row->fill([
                'evaluator' => $evaluator, 'cell_key' => $cellKey, 'sample_count' => $sample,
                'correct_count' => $correctCount, 'false_positive_count' => (int) $row->false_positive_count + (!$correct ? 1 : 0),
                'false_negative_count' => (int) $row->false_negative_count,
                'brier_score' => round($brier, 6), 'calibration_error' => round($calibrationError, 6),
                'reputation_score' => round($correctCount / $sample, 6), 'bins' => $bins,
                'evidence' => [...(array) $row->evidence, ...$evidence, 'protocol' => self::PROTOCOL],
                'status' => $sample >= $minimum ? 'calibrated' : 'uncalibrated', 'last_observed_at' => now(),
            ])->save();
            return ['status' => $row->status, 'sample_count' => $row->sample_count, 'reputation_score' => $row->reputation_score, 'calibration_error' => $row->calibration_error, 'promotion_evidence' => false];
        });
    }

    /** @return array<string, mixed> */
    public function trust(string $evaluator, string $cellKey): array
    {
        if (! Schema::hasTable('dual_track_evaluator_calibrations')) return ['status' => 'unavailable', 'trusted' => false, 'promotion_evidence' => false];
        $row = DualTrackEvaluatorCalibration::query()->where('evaluator', $evaluator)->where('cell_key', $cellKey)->first();
        $trusted = $row && $row->status === 'calibrated' && (float) $row->calibration_error <= (float) config('services.dual_track.max_evaluator_calibration_error', .20);
        return ['status' => $row?->status ?? 'uncalibrated', 'trusted' => (bool) $trusted, 'reputation_score' => $row?->reputation_score, 'promotion_evidence' => false];
    }
}
