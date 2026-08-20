<?php

namespace App\Services;

use App\Models\DualTrackCellStatistic;
use Illuminate\Support\Facades\Schema;

/** Parent evidence accelerates research only; it can never authorize promotion. */
class DualTrackHierarchicalEvidenceService
{
    public const PROTOCOL = 'dual_track_hierarchical_evidence_v1';

    /** @return array<string, mixed> */
    public function guidance(string $symbol, string $timeframe, string $cellKey): array
    {
        $base = ['protocol' => self::PROTOCOL, 'research_only' => true, 'exact_cell' => $cellKey, 'parent_levels' => []];
        if (! Schema::hasTable('dual_track_cell_statistics')) return $base;
        $parts = explode('|', $cellKey);
        $symbol = strtoupper($symbol); $timeframe = strtoupper($timeframe);
        $regime = (string) ($parts[2] ?? 'unknown');
        $parentScopes = [
            'symbol_timeframe_regime' => fn ($query) => $query->where('symbol', $symbol)->where('timeframe', $timeframe)->where('cell_key', 'like', $symbol.'|'.$timeframe.'|'.$regime.'|%'),
            'symbol_timeframe' => fn ($query) => $query->where('symbol', $symbol)->where('timeframe', $timeframe),
        ];
        foreach ($parentScopes as $level => $scope) {
            $rows = DualTrackCellStatistic::query()->whereIn('lane', ['champion', 'council'])->tap($scope)->get();
            $base['parent_levels'][$level] = [
                'sample_count' => (int) $rows->sum('settled_count'),
                'lanes' => $rows->groupBy('lane')->map(fn ($items): array => ['sample_count' => (int) $items->sum('settled_count'), 'wins' => (int) $items->sum('wins'), 'reward_sum' => round((float) $items->sum('reward_sum'), 6)])->all(),
                'use' => 'research_guidance_only',
            ];
        }
        return $base;
    }
}
