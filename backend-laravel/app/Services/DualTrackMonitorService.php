<?php

namespace App\Services;

use App\Models\DualTrackRun;
use App\Models\DualTrackCellPolicy;
use App\Models\DualTrackEvaluatorCalibration;
use App\Models\DualTrackEvolutionEvent;
use App\Models\DualTrackMemoryLesson;
use App\Models\DualTrackOutcome;
use Illuminate\Support\Facades\Schema;

class DualTrackMonitorService
{
    /** @return array<string, mixed> */
    public function report(string $symbol, string $timeframe, int $limit = 100): array
    {
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        if (! $this->hasTable('dual_track_runs')) {
            return [
                'protocol' => DualTrackDecisionService::PROTOCOL,
                'available' => false,
                'status' => 'migration_required',
                'promotion_evidence' => false,
            ];
        }

        $rows = DualTrackRun::query()
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->latest('id')
            ->limit(max(1, min(1000, $limit)))
            ->get();

        $byLane = $rows->countBy('selected_lane')->all();
        $byStatus = $rows->countBy('status')->all();
        $disagreements = $rows->filter(fn (DualTrackRun $row): bool => filled($row->disagreement_code))->count();
        $outcomes = $this->hasTable('dual_track_outcomes')
            ? DualTrackOutcome::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->latest('id')->limit(1000)->get()
            : collect();
        $policies = $this->hasTable('dual_track_cell_policies')
            ? DualTrackCellPolicy::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->get()
            : collect();
        $calibrations = $this->hasTable('dual_track_evaluator_calibrations')
            ? DualTrackEvaluatorCalibration::query()->get()
            : collect();
        $lessons = $this->hasTable('dual_track_memory_lessons')
            ? DualTrackMemoryLesson::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->count()
            : 0;
        $evolutionEvents = $this->hasTable('dual_track_evolution_events')
            ? DualTrackEvolutionEvent::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->count()
            : 0;

        return [
            'protocol' => DualTrackDecisionService::PROTOCOL,
            'available' => true,
            'status' => 'observed',
            'mode' => (string) config('services.dual_track.mode', 'shadow'),
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'sample_size' => $rows->count(),
            'by_lane' => $byLane,
            'by_status' => $byStatus,
            'disagreements' => $disagreements,
            'outcomes' => [
                'sample_size' => $outcomes->count(),
                'settled' => $outcomes->where('outcome_status', 'settled')->count(),
                'by_lane' => $outcomes->countBy('lane')->all(),
                'by_status' => $outcomes->countBy('outcome_status')->all(),
            ],
            'policies' => [
                'sample_size' => $policies->count(),
                'by_status' => $policies->countBy('status')->all(),
                'active_lanes' => $policies->countBy('active_lane')->all(),
            ],
            'calibration' => [
                'sample_size' => $calibrations->count(),
                'by_status' => $calibrations->countBy('status')->all(),
            ],
            'memory_lessons' => $lessons,
            'evolution_events' => $evolutionEvents,
            'cells' => $rows->groupBy('cell_key')->map(fn ($cellRows, string $cell): array => [
                'cell_key' => $cell,
                'samples' => $cellRows->count(),
                'lanes' => $cellRows->countBy('selected_lane')->all(),
                'disagreements' => $cellRows->filter(fn (DualTrackRun $row): bool => filled($row->disagreement_code))->count(),
                'latest_status' => $cellRows->first()?->status,
            ])->values()->all(),
            'latest' => $rows->first()?->only([
                'run_key', 'cell_key', 'status', 'selected_lane', 'selected_decision',
                'champion_decision', 'council_decision', 'disagreement_code', 'created_at',
            ]),
            'promotion_evidence' => false,
        ];
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
