<?php

namespace App\Services;

use App\Models\DualTrackCellPolicy;
use App\Models\DualTrackDiversityMetric;
use App\Models\DualTrackEvaluatorCalibration;
use App\Models\DualTrackMemberCredit;
use App\Models\DualTrackOrganismHealthSnapshot;
use App\Models\DualTrackOutcome;
use App\Models\DualTrackPromotionDecision;
use App\Models\DualTrackRedTeamTrial;
use App\Models\DualTrackSnapshotManifest;
use App\Models\DualTrackDriftState;
use App\Models\DualTrackCellStatistic;
use App\Models\ModelMarketPerformance;
use Illuminate\Support\Facades\Schema;

/**
 * The only authority allowed to turn a research recommendation into a live
 * lane. Every caller receives the same fail-closed decision record.
 */
class DualTrackPromotionDecisionService
{
    public const PROTOCOL = 'dual_track_promotion_authority_v1';

    /** @return array<string, mixed> */
    public function assess(array $context): array
    {
        $cell = DualTrackDecisionService::cellKey($context);
        $symbol = strtoupper((string) ($context['symbol'] ?? 'UNKNOWN'));
        $timeframe = strtoupper((string) ($context['timeframe'] ?? 'UNKNOWN'));
        $requested = (string) ($context['requested_lane'] ?? $context['lane'] ?? 'incumbent');
        $requested = in_array($requested, ['champion', 'council', 'hybrid'], true) ? $requested : 'incumbent';
        $promotionLanes = $requested === 'hybrid' ? ['champion', 'council'] : [$requested];
        $reasons = [];
        $evidence = ['protocol' => self::PROTOCOL, 'cell_key' => $cell, 'requested_lane' => $requested];

        $policy = $this->hasTable('dual_track_cell_policies')
            ? DualTrackCellPolicy::query()->where('cell_key', $cell)->first()
            : null;
        if (! $policy || $policy->status !== 'certified') $reasons[] = 'cell_policy_not_certified';
        $evidence['policy'] = $policy ? ['id' => $policy->id, 'status' => $policy->status, 'hash' => $policy->policy_hash] : null;

        $laneRows = $this->hasTable('dual_track_outcomes')
            ? DualTrackOutcome::query()->where(['symbol' => $symbol, 'timeframe' => $timeframe, 'cell_key' => $cell, 'outcome_status' => 'settled'])->whereIn('lane', $promotionLanes)->get()
            : collect();
        $minimum = max(1, (int) config('services.dual_track.cell_minimum_samples', 30));
        foreach ($promotionLanes as $lane) {
            if ($laneRows->where('lane', $lane)->count() < $minimum) $reasons[] = $lane.'_outcome_samples_missing';
        }
        if ($laneRows->contains(fn (DualTrackOutcome $row): bool => in_array($row->decision, ['BUY', 'SELL'], true)
            && (! is_numeric($row->risk_percent) || (float) $row->risk_percent > (float) config('services.risk.max_risk_per_trade_percent', 1)))) {
            $reasons[] = 'risk_evidence_missing_or_exceeded';
        }
        $evidence['outcomes'] = ['count' => $laneRows->count(), 'minimum' => $minimum];

        $calibration = $this->hasTable('dual_track_evaluator_calibrations')
            ? DualTrackEvaluatorCalibration::query()->where('cell_key', $cell)->latest('sample_count')->first()
            : null;
        $calibrationMin = max(1, (int) config('services.dual_track.evaluator_minimum_samples', 20));
        if (! $calibration || (int) $calibration->sample_count < $calibrationMin || (float) ($calibration->calibration_error ?? 1) > (float) config('services.dual_track.max_evaluator_calibration_error', .2)) {
            $reasons[] = 'evaluator_calibration_not_ready';
        }
        $evidence['calibration'] = $calibration ? ['id' => $calibration->id, 'samples' => $calibration->sample_count, 'error' => $calibration->calibration_error, 'status' => $calibration->status] : null;

        $diversity = $this->hasTable('dual_track_diversity_metrics')
            ? DualTrackDiversityMetric::query()->where('cell_key', $cell)->latest('id')->first()
            : null;
        $diversityMin = max(1, (int) config('services.twin_intelligence.diversity_minimum_samples', 20));
        if (! $diversity || (int) $diversity->sample_count < $diversityMin || $diversity->status === 'DIVERSITY_COLLAPSE'
            || (float) $diversity->behavioral_distance < .05 || (float) $diversity->memory_overlap_rate >= .95
            || (float) $diversity->council_redundancy_rate >= .95) {
            $reasons[] = 'lane_diversity_not_proven';
        }
        $evidence['diversity'] = $diversity ? ['id' => $diversity->id, 'samples' => $diversity->sample_count, 'behavioral_distance' => $diversity->behavioral_distance, 'memory_overlap' => $diversity->memory_overlap_rate, 'redundancy' => $diversity->council_redundancy_rate] : null;

        $healthRows = $this->hasTable('dual_track_organism_health_snapshots')
            ? DualTrackOrganismHealthSnapshot::query()->where('cell_key', $cell)->whereIn('lane', $promotionLanes)->latest('id')->get()->groupBy('lane')->map(fn ($items) => $items->first())
            : collect();
        foreach ($promotionLanes as $lane) {
            if (! $healthRows->has($lane) || $healthRows[$lane]->status !== 'promotion_ready') $reasons[] = $lane.'_organism_health_not_ready';
        }
        $evidence['health'] = $healthRows->map(fn ($row): array => ['id' => $row->id, 'lane' => $row->lane, 'status' => $row->status, 'metrics' => $row->metrics])->values()->all();

        if (in_array('council', $promotionLanes, true)) {
            $memberReplayCount = $this->hasTable('dual_track_member_credits')
                ? DualTrackMemberCredit::query()->where(['cell_key' => $cell, 'status' => 'completed'])->count()
                : 0;
            if ($memberReplayCount < $minimum) $reasons[] = 'council_member_ablation_replay_missing';
            $evidence['member_credit'] = ['completed_replays' => $memberReplayCount, 'minimum' => $minimum];
        }

        $redTeams = collect();
        $latestRedTeamRunId = null;
        if ($this->hasTable('dual_track_red_team_trials')) {
            $redQuery = DualTrackRedTeamTrial::query()->where('cell_key', $cell);
            $latestRedTeamRunId = (clone $redQuery)->latest('id')->value('dual_track_run_id');
            if ($latestRedTeamRunId) $redTeams = $redQuery->where('dual_track_run_id', $latestRedTeamRunId)->get();
        }
        $redMinimum = max(1, (int) config('services.twin_intelligence.red_team_minimum_trials', 4));
        $redPassed = $redTeams->count() >= $redMinimum && $redTeams->every(fn (DualTrackRedTeamTrial $trial): bool => $trial->status === 'completed'
            && (float) ($trial->damage_score ?? 1) <= (float) config('services.twin_intelligence.red_team_damage_threshold', .25));
        if (! $redPassed) $reasons[] = 'red_team_not_completed_or_failed';
        $evidence['red_team'] = ['count' => $redTeams->count(), 'minimum' => $redMinimum, 'latest_run_id' => $latestRedTeamRunId, 'passed' => $redPassed];

        $manifestHash = (string) ($context['snapshot_manifest_hash'] ?? data_get($context, 'snapshot_manifest.snapshot_hash', $context['snapshot_hash'] ?? ''));
        $manifest = $manifestHash !== '' && $this->hasTable('dual_track_snapshot_manifests')
            ? DualTrackSnapshotManifest::query()->where('snapshot_hash', $manifestHash)->where('status', 'sealed')->first()
            : null;
        if ((bool) config('services.twin_intelligence.require_snapshot_manifest', true) && ! $manifest) $reasons[] = 'canonical_snapshot_manifest_missing';
        $evidence['snapshot_manifest'] = $manifest ? ['id' => $manifest->id, 'hash' => $manifest->snapshot_hash, 'dataset_hash' => $manifest->dataset_hash] : null;

        $candidate = isset($context['candidate_id']) && $this->hasTable('model_market_performance')
            ? ModelMarketPerformance::query()->find((int) $context['candidate_id'])
            : null;
        $drift = $this->hasTable('dual_track_drift_states')
            ? DualTrackDriftState::query()->where('cell_key', $cell)->whereIn('lane', $promotionLanes)->latest('id')->get()->groupBy('lane')->map(fn ($items) => $items->first())
            : collect();
        foreach ($promotionLanes as $lane) {
            if ($drift->has($lane) && in_array((string) $drift[$lane]->state, ['risk_reduce', 'quarantine'], true)) $reasons[] = $lane.'_drift_state_'.$drift[$lane]->state;
        }
        $evidence['drift'] = $drift->map(fn ($row): array => ['lane' => $row->lane, 'state' => $row->state, 'score' => max((float) $row->cusum_positive, (float) $row->cusum_negative), 'samples' => $row->sample_count])->values()->all();
        $evidence['hierarchical_guidance'] = app(DualTrackHierarchicalEvidenceService::class)->guidance($symbol, $timeframe, $cell);
        $forward = $this->forwardEvidence($candidate);
        if (! $forward['passed']) $reasons[] = 'forward_holdout_evidence_missing';
        $evidence['forward'] = $forward;

        $evidenceHash = hash('sha256', json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $decisionKey = hash('sha256', self::PROTOCOL.'|'.$symbol.'|'.$timeframe.'|'.$cell.'|'.$requested.'|'.$evidenceHash);
        $allowed = $reasons === [];
        $record = null;
        if ($this->hasTable('dual_track_promotion_decisions')) {
            $record = DualTrackPromotionDecision::query()->updateOrCreate(
                ['decision_key' => $decisionKey],
                ['symbol' => $symbol, 'timeframe' => $timeframe, 'cell_key' => $cell, 'requested_lane' => $requested,
                    'status' => $allowed ? 'allowed' : 'blocked', 'allowed' => $allowed, 'reasons' => $reasons,
                    'evidence' => $evidence, 'evidence_hash' => $evidenceHash,
                    'expires_at' => now()->addMinutes((int) config('services.twin_intelligence.promotion_decision_ttl_minutes', 15)),
                    'promotion_evidence' => false],
            );
        }

        return ['protocol' => self::PROTOCOL, 'allowed' => $allowed, 'status' => $allowed ? 'allowed' : 'blocked',
            'reasons' => $reasons, 'evidence' => $evidence, 'evidence_hash' => $evidenceHash,
            'decision_id' => $record?->id, 'promotion_evidence' => false];
    }

    /** @return array<string, mixed> */
    private function forwardEvidence(?ModelMarketPerformance $candidate): array
    {
        if (! $candidate) return ['passed' => false, 'reason' => 'candidate_missing'];
        $proof = $this->hasTable('dual_track_gene_proofs')
            ? \App\Models\DualTrackGeneProof::query()->where('model_market_performance_id', $candidate->id)->latest('id')->first()
            : null;
        $passed = (string) $candidate->evidence_status === 'valid'
            && (int) $candidate->sample_count >= 30
            && (int) $candidate->rolling_windows_count >= 3
            && (int) $candidate->rolling_forward_wins >= 2
            && (float) $candidate->forward_score > 0
            && data_get($candidate->metrics, 'is_overfit', false) !== true
            && $proof?->status === 'proven';
        return ['passed' => $passed, 'candidate_id' => $candidate->id, 'sample_count' => $candidate->sample_count,
            'rolling_windows' => $candidate->rolling_windows_count, 'rolling_wins' => $candidate->rolling_forward_wins,
            'forward_score' => $candidate->forward_score, 'evidence_status' => $candidate->evidence_status, 'gene_proof_status' => $proof?->status];
    }

    private function hasTable(string $table): bool
    {
        try { return Schema::hasTable($table); } catch (\Throwable) { return false; }
    }
}
