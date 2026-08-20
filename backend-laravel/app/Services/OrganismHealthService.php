<?php

namespace App\Services;

use App\Models\DualTrackDiversityMetric;
use App\Models\DualTrackEvaluatorCalibration;
use App\Models\DualTrackOrganismHealthSnapshot;
use App\Models\DualTrackOutcome;
use App\Models\DualTrackRedTeamTrial;
use App\Models\ModelMarketPerformance;
use Illuminate\Support\Facades\Schema;

/** Multi-axis health vector; no scalar score can bypass a failed hard gate. */
class OrganismHealthService
{
    public const PROTOCOL = 'twin_organism_health_vector_v1';

    /** @return array<string, mixed> */
    public function record(DualTrackOutcome $outcome, ?ModelMarketPerformance $candidate = null): array
    {
        if (! Schema::hasTable('dual_track_organism_health_snapshots')) return ['status' => 'unavailable', 'promotion_evidence' => false];
        // Health is a hot path after every settlement. Use one SQL aggregate
        // instead of hydrating the full cell history into PHP.
        $summary = DualTrackOutcome::query()->where('cell_key', $outcome->cell_key)->where('lane', $outcome->lane)->where('outcome_status', 'settled')
            ->selectRaw('COUNT(*) AS sample_count, SUM(correct IS NOT NULL) AS known_count, SUM(correct = 1) AS wins, AVG(CASE WHEN risk_percent IS NOT NULL AND risk_percent <= ? THEN 1 ELSE 0 END) AS risk_score, AVG(CASE WHEN correct IS NOT NULL AND confidence IS NOT NULL THEN 1 - ABS(confidence - CASE WHEN correct = 1 THEN 1 ELSE 0 END) END) AS calibration_score, AVG(regret) AS regret_avg', [(float) config('services.risk.max_risk_per_trade_percent', 1)])->first();
        $sampleCount = (int) ($summary?->sample_count ?? 0);
        $knownCount = (int) ($summary?->known_count ?? 0);
        $edge = $knownCount > 0 ? max(0, min(1, (int) ($summary?->wins ?? 0) / $knownCount)) : .0;
        $risk = $sampleCount > 0 ? (float) ($summary?->risk_score ?? 0) : 0;
        $calibration = (float) ($summary?->calibration_score ?? 0);
        $calibrationRow = $this->hasTable('dual_track_evaluator_calibrations')
            ? DualTrackEvaluatorCalibration::query()->where('cell_key', $outcome->cell_key)->orderByDesc('sample_count')->first()
            : null;
        $calibrationSamples = (int) ($calibrationRow?->sample_count ?? 0);
        $diversity = $this->hasTable('dual_track_diversity_metrics')
            ? DualTrackDiversityMetric::query()->where('cell_key', $outcome->cell_key)->latest('id')->first()
            : null;
        $diversityScore = $diversity ? max(0, min(1, ((float) $diversity->behavioral_distance + (float) $diversity->confidence_distance) / 2)) : 0;
        $regret = max(0, min(1, 1 - ((float) ($summary?->regret_avg ?? 0) / 2)));
        $learningVelocity = min(1, $sampleCount / max(1, (int) config('services.dual_track.cell_minimum_samples', 30)));
        $forwardEvidence = $this->hasForwardEvidence($candidate);
        $redTeam = collect();
        if ($this->hasTable('dual_track_red_team_trials')) {
            // A failed stress replay from an obsolete run must not poison a
            // cell forever. Evaluate the complete latest trial batch.
            $redQuery = DualTrackRedTeamTrial::query()->where('cell_key', $outcome->cell_key);
            $latestRunId = (clone $redQuery)->latest('id')->value('dual_track_run_id');
            if ($latestRunId) $redTeam = $redQuery->where('dual_track_run_id', $latestRunId)->get();
        }
        $redTeamPassed = $redTeam->isNotEmpty() && $redTeam->every(fn (DualTrackRedTeamTrial $trial): bool => $trial->status === 'completed' && (float) ($trial->damage_score ?? 1) <= (float) config('services.twin_intelligence.red_team_damage_threshold', .25));
        $metrics = [
            'edge' => round($edge, 6), 'risk' => round($risk, 6), 'calibration' => round($calibration, 6),
            'diversity' => round($diversityScore, 6), 'recovery_speed' => round($learningVelocity, 6),
            'learning_velocity' => round($learningVelocity, 6), 'regret_control' => round($regret, 6),
            'sample_count' => $sampleCount, 'calibration_samples' => $calibrationSamples,
            'forward_evidence' => $forwardEvidence, 'red_team_passed' => $redTeamPassed,
        ];
        $gate = $this->promotionGate($metrics, $outcome->lane, $outcome->cell_key);
        $key = hash('sha256', self::PROTOCOL.'|'.$outcome->outcome_key);
        $snapshot = DualTrackOrganismHealthSnapshot::query()->updateOrCreate(
            ['health_key' => $key],
            ['dual_track_run_id' => $outcome->dual_track_run_id, 'symbol' => $outcome->symbol, 'timeframe' => $outcome->timeframe, 'cell_key' => $outcome->cell_key, 'lane' => $outcome->lane, 'metrics' => $metrics, 'health_score' => round(collect($metrics)->only(['edge', 'risk', 'calibration', 'diversity', 'recovery_speed', 'learning_velocity', 'regret_control'])->avg(), 6), 'status' => $gate['allowed'] ? 'promotion_ready' : 'promotion_blocked', 'evidence' => ['protocol' => self::PROTOCOL, 'gate' => $gate, 'promotion_evidence' => false], 'promotion_evidence' => false],
        );
        return ['status' => $snapshot->status, 'health_id' => $snapshot->id, 'health_score' => $snapshot->health_score, 'metrics' => $metrics, 'gate' => $gate, 'promotion_evidence' => false];
    }

    /** @return array<string, mixed> */
    public function promotionGate(array $metrics, string $lane, string $cellKey): array
    {
        $reasons = [];
        if ((int) ($metrics['sample_count'] ?? 0) < (int) config('services.dual_track.cell_minimum_samples', 30)) $reasons[] = 'minimum_cell_outcomes_missing';
        if ((float) ($metrics['calibration'] ?? 0) < .7) $reasons[] = 'confidence_not_calibrated';
        if ((int) ($metrics['calibration_samples'] ?? 0) < (int) config('services.dual_track.evaluator_minimum_samples', 20)) $reasons[] = 'evaluator_calibration_samples_missing';
        if ((float) ($metrics['risk'] ?? 0) < 1) $reasons[] = 'risk_gate_failed';
        if ((float) ($metrics['diversity'] ?? 0) < .05) $reasons[] = 'diversity_evidence_missing';
        if (! (bool) ($metrics['forward_evidence'] ?? false)) $reasons[] = 'live_forward_evidence_missing';
        if (! (bool) ($metrics['red_team_passed'] ?? false)) $reasons[] = 'red_team_evidence_missing';
        return ['allowed' => $reasons === [], 'lane' => $lane, 'cell_key' => $cellKey, 'reasons' => $reasons, 'promotion_evidence' => false];
    }

    private function hasForwardEvidence(?ModelMarketPerformance $candidate): bool
    {
        if (! $candidate) return false;
        return (string) ($candidate->evidence_status ?? '') === 'valid'
            && (int) ($candidate->sample_count ?? 0) >= 30
            && (int) ($candidate->rolling_windows_count ?? 0) >= 3
            && (int) ($candidate->rolling_forward_wins ?? 0) >= 2
            && (float) ($candidate->forward_score ?? 0) > 0
            && data_get($candidate->metrics, 'is_overfit', false) !== true;
    }

    private function hasTable(string $table): bool
    {
        try { return Schema::hasTable($table); } catch (\Throwable) { return false; }
    }
}
