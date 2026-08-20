<?php

namespace App\Services;

use App\Models\DualTrackRun;
use App\Models\DualTrackCellPolicy;
use App\Models\DualTrackEvaluatorCalibration;
use App\Models\DualTrackEvolutionEvent;
use App\Models\DualTrackExchangePacket;
use App\Models\DualTrackLaneCredit;
use App\Models\DualTrackDiversityMetric;
use App\Models\DualTrackInferenceObservation;
use App\Models\DualTrackMemberCredit;
use App\Models\DualTrackGenomeArchive;
use App\Models\DualTrackGeneCemetery;
use App\Models\DualTrackOrganismHealthSnapshot;
use App\Models\DualTrackReflectionLesson;
use App\Models\DualTrackRedTeamTrial;
use App\Models\DualTrackPromotionDecision;
use App\Models\DualTrackSettlementState;
use App\Models\DualTrackSnapshotManifest;
use App\Models\DualTrackGenomeArchiveEvent;
use App\Models\DualTrackMemoryLesson;
use App\Models\DualTrackOutcome;
use App\Models\DualTrackEvidenceWorkItem;
use App\Models\DualTrackCellStatistic;
use App\Models\DualTrackDriftState;
use App\Models\DualTrackMemoryReplay;
use App\Models\DualTrackGeneProof;
use Illuminate\Support\Facades\Schema;

class DualTrackMonitorService
{
    public function __construct(private TwinIntelligenceProfileService $profiles) {}

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
        $packets = $this->hasTable('dual_track_exchange_packets')
            ? DualTrackExchangePacket::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->count()
            : 0;
        $credits = $this->hasTable('dual_track_lane_credits')
            ? DualTrackLaneCredit::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->get()
            : collect();
        $diversity = $this->hasTable('dual_track_diversity_metrics')
            ? DualTrackDiversityMetric::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->latest('id')->first()
            : null;
        $inference = $this->hasTable('dual_track_inference_observations')
            ? DualTrackInferenceObservation::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->get()
            : collect();
        $memberCredits = $this->hasTable('dual_track_member_credits')
            ? DualTrackMemberCredit::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->get()
            : collect();
        $archives = $this->hasTable('dual_track_genome_archives')
            ? DualTrackGenomeArchive::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->get()
            : collect();
        $cemetery = $this->hasTable('dual_track_gene_cemeteries')
            ? DualTrackGeneCemetery::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->get()
            : collect();
        $health = $this->hasTable('dual_track_organism_health_snapshots')
            ? DualTrackOrganismHealthSnapshot::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->latest('id')->get()->groupBy('lane')->map(fn ($items) => $items->first())
            : collect();
        $reflections = $this->hasTable('dual_track_reflection_lessons')
            ? DualTrackReflectionLesson::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->get()
            : collect();
        $redTeam = $this->hasTable('dual_track_red_team_trials')
            ? DualTrackRedTeamTrial::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->get()
            : collect();
        $promotions = $this->hasTable('dual_track_promotion_decisions')
            ? DualTrackPromotionDecision::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->latest('id')->get()
            : collect();
        $settlements = $this->hasTable('dual_track_settlement_states')
            ? DualTrackSettlementState::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->latest('id')->get()
            : collect();
        $manifests = $this->hasTable('dual_track_snapshot_manifests')
            ? DualTrackSnapshotManifest::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->latest('id')->get()
            : collect();
        $archiveEvents = $this->hasTable('dual_track_genome_archive_events')
            ? DualTrackGenomeArchiveEvent::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->count()
            : 0;
        $workItems = $this->hasTable('dual_track_evidence_work_items')
            ? DualTrackEvidenceWorkItem::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->get()
            : collect();
        $statistics = $this->hasTable('dual_track_cell_statistics')
            ? DualTrackCellStatistic::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->get()
            : collect();
        $drift = $this->hasTable('dual_track_drift_states')
            ? DualTrackDriftState::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->latest('id')->get()->groupBy('lane')->map(fn ($items) => $items->first())
            : collect();
        $memoryReplay = $this->hasTable('dual_track_memory_replay_queue')
            ? DualTrackMemoryReplay::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->get()
            : collect();
        $geneProofs = $this->hasTable('dual_track_gene_proofs')
            ? DualTrackGeneProof::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->latest('id')->get()
            : collect();

        return [
            'protocol' => DualTrackDecisionService::PROTOCOL,
            'available' => true,
            'status' => 'observed',
            'mode' => (string) config('services.dual_track.mode', 'shadow'),
            'organisms' => [
                'champion' => $this->profiles->contract('champion'),
                'council' => $this->profiles->contract('council'),
            ],
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
            'exchange_packets' => $packets,
            'inference' => [
                'sample_size' => $inference->count(), 'by_lane' => $inference->countBy('lane')->all(),
                'distinct_processes' => $inference->pluck('process_id')->unique()->count(),
                'independent_contexts' => $inference->where('evidence.independent_context', true)->count(),
            ],
            'member_credits' => [
                'sample_size' => $memberCredits->count(), 'completed' => $memberCredits->where('status', 'completed')->count(),
                'positive_marginal' => $memberCredits->where('marginal_credit', '>', 0)->count(),
            ],
            'genome_archive' => [
                'sample_size' => $archives->count(), 'elite_cells' => $archives->where('status', 'elite')->count(),
                'frontier_cells' => $archives->where('status', 'frontier')->count(),
            ],
            'gene_cemetery' => [
                'sample_size' => $cemetery->count(), 'buried' => $cemetery->where('status', 'buried')->count(),
                'resurrection_candidates' => $cemetery->where('status', 'resurrection_candidate')->count(),
            ],
            'organism_health' => $health->map(fn ($item): array => ['lane' => $item->lane, 'status' => $item->status, 'health_score' => $item->health_score, 'metrics' => $item->metrics])->values()->all(),
            'reflections' => ['sample_size' => $reflections->count(), 'confirmed' => $reflections->where('status', 'confirmed')->count()],
            'red_team' => ['sample_size' => $redTeam->count(), 'planned' => $redTeam->where('status', 'planned')->count(), 'completed' => $redTeam->where('status', 'completed')->count()],
            'promotion_authority' => [
                'sample_size' => $promotions->count(), 'allowed' => $promotions->where('allowed', true)->count(),
                'blocked' => $promotions->where('allowed', false)->count(), 'latest' => $promotions->first()?->only(['cell_key', 'requested_lane', 'status', 'allowed', 'reasons', 'evidence_hash', 'expires_at']),
            ],
            'settlement_state_machine' => [
                'sample_size' => $settlements->count(), 'completed' => $settlements->whereNotNull('completed_at')->count(),
                'failed' => $settlements->where('stage', 'failed')->count(), 'pending' => $settlements->whereNull('completed_at')->where('stage', '!=', 'failed')->count(),
            ],
            'snapshot_manifests' => ['sample_size' => $manifests->count(), 'sealed' => $manifests->where('status', 'sealed')->count(), 'latest_hash' => $manifests->first()?->snapshot_hash],
            'genome_archive_events' => $archiveEvents,
            'evidence_work_queue' => ['sample_size' => $workItems->count(), 'by_type' => $workItems->countBy('work_type')->all(), 'by_status' => $workItems->countBy('status')->all()],
            'materialized_statistics' => ['sample_size' => $statistics->count(), 'settled' => (int) $statistics->sum('settled_count'), 'by_lane' => $statistics->groupBy('lane')->map(fn ($rows): int => (int) $rows->sum('settled_count'))->all()],
            'drift' => $drift->map(fn ($row): array => ['lane' => $row->lane, 'state' => $row->state, 'sample_count' => $row->sample_count, 'cusum' => max((float) $row->cusum_positive, (float) $row->cusum_negative)])->values()->all(),
            'prioritized_memory_replay' => ['sample_size' => $memoryReplay->count(), 'queued' => $memoryReplay->whereIn('status', ['queued', 'retry'])->count(), 'replayed' => $memoryReplay->where('status', 'replayed')->count()],
            'gene_proofs' => ['sample_size' => $geneProofs->count(), 'proven' => $geneProofs->where('status', 'proven')->count(), 'failed' => $geneProofs->where('status', 'failed')->count()],
            'lane_credits' => [
                'sample_size' => $credits->count(),
                'by_lane' => $credits->countBy('lane')->all(),
                'reward_by_lane' => $credits->groupBy('lane')->map(fn ($laneRows): float => round((float) $laneRows->sum('reward'), 6))->all(),
            ],
            'diversity' => $diversity?->only([
                'status', 'behavioral_distance', 'confidence_distance', 'decision_agreement_rate',
                'useful_dissent_rate', 'memory_overlap_rate', 'council_redundancy_rate', 'sample_count',
            ]),
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
