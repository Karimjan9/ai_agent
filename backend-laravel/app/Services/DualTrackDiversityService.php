<?php

namespace App\Services;

use App\Models\DualTrackDiversityMetric;
use App\Models\DualTrackRun;
use Illuminate\Support\Facades\Schema;

/** Detects organism collapse, useless uncoupling and redundant collective behavior. */
class DualTrackDiversityService
{
    public const PROTOCOL = 'twin_intelligence_diversity_guard_v1';

    /** @return array<string, mixed> */
    public function record(DualTrackRun $run, array $champion, array $council, ?array $outcome = null): array
    {
        $championDecision = strtoupper((string) ($champion['decision'] ?? 'WAIT'));
        $councilDecision = strtoupper((string) ($council['decision'] ?? 'WAIT'));
        $confidenceDistance = abs((float) ($champion['confidence'] ?? 0) - (float) ($council['confidence'] ?? 0));
        $decisionDistance = $championDecision === $councilDecision ? 0.0 : 1.0;
        $sample = DualTrackRun::query()->where('cell_key', $run->cell_key)->count();
        $agreement = $sample > 0 ? DualTrackRun::query()->where('cell_key', $run->cell_key)->whereColumn('champion_decision', 'council_decision')->count() / $sample : 0.0;
        $usefulDissent = ($outcome['avoided_loss'] ?? false) === true ? 1.0 : 0.0;
        $memoryOverlap = $this->jaccard(
            (array) data_get($champion, 'memory_keys', data_get($champion, 'skills', [])),
            (array) data_get($council, 'memory_keys', data_get($council, 'skills', [])),
        );
        $committee = $this->committee($council);
        $councilRedundancy = $this->redundancy($committee);
        // Role/schema diversity is not the same as behavioral diversity. A
        // Council can have five different labels and still clone Champion's
        // decisions. Keep member redundancy as a separate metric and derive
        // lane distance only from observed output behavior.
        $behavioralDistance = round(min(1.0, ($decisionDistance * .75) + ($confidenceDistance * .25)), 6);
        $status = $sample >= (int) config('services.twin_intelligence.diversity_minimum_samples', 20)
            && $agreement >= (float) config('services.twin_intelligence.max_agreement_rate', .95)
            && $behavioralDistance < .05
            ? 'DIVERSITY_COLLAPSE'
            : ($decisionDistance > 0 ? 'productive_dissent_observed' : 'agreement_observed');

        if (! $this->hasTable('dual_track_diversity_metrics')) return ['status' => $status, 'promotion_evidence' => false];
        $key = hash('sha256', self::PROTOCOL.'|'.$run->run_key);
        $metric = DualTrackDiversityMetric::query()->updateOrCreate(
            ['metric_key' => $key],
            [
                'dual_track_run_id' => $run->id, 'symbol' => $run->symbol, 'timeframe' => $run->timeframe,
                'cell_key' => $run->cell_key, 'behavioral_distance' => $behavioralDistance,
                'confidence_distance' => $confidenceDistance, 'decision_agreement_rate' => $agreement,
                'useful_dissent_rate' => $usefulDissent, 'memory_overlap_rate' => $memoryOverlap,
                'council_redundancy_rate' => $councilRedundancy, 'sample_count' => $sample, 'status' => $status,
                'evidence' => ['protocol' => self::PROTOCOL, 'committee_member_count' => count($committee), 'promotion_evidence' => false],
                'promotion_evidence' => false,
            ],
        );

        return ['status' => $metric->status, 'metric_id' => $metric->id, 'behavioral_distance' => $behavioralDistance, 'memory_overlap_rate' => $memoryOverlap, 'council_redundancy_rate' => $councilRedundancy, 'agreement_rate' => round($agreement, 6), 'promotion_evidence' => false];
    }

    /** @return array<int, array<string, mixed>> */
    private function committee(array $council): array
    {
        $members = data_get($council, 'committee', data_get($council, 'agents', []));
        if (isset($members['agents']) && is_array($members['agents'])) $members = $members['agents'];
        return array_values(array_filter((array) $members, 'is_array'));
    }

    /** @param array<int, array<string, mixed>> $members */
    private function redundancy(array $members): float
    {
        if (count($members) < 2) return 0.0;
        $pairs = 0; $same = 0;
        for ($i = 0; $i < count($members); $i++) {
            for ($j = $i + 1; $j < count($members); $j++) {
                $pairs++;
                $left = [strtoupper((string) ($members[$i]['decision'] ?? 'WAIT')), (string) ($members[$i]['schema'] ?? $members[$i]['role'] ?? '')];
                $right = [strtoupper((string) ($members[$j]['decision'] ?? 'WAIT')), (string) ($members[$j]['schema'] ?? $members[$j]['role'] ?? '')];
                if ($left === $right) $same++;
            }
        }
        return round($same / max(1, $pairs), 6);
    }

    private function jaccard(array $left, array $right): float
    {
        $left = array_values(array_unique(array_map('strval', $left)));
        $right = array_values(array_unique(array_map('strval', $right)));
        $union = array_unique([...$left, ...$right]);
        return $union === [] ? 0.0 : round(count(array_intersect($left, $right)) / count($union), 6);
    }

    private function hasTable(string $table): bool
    {
        try { return Schema::hasTable($table); } catch (\Throwable) { return false; }
    }
}
