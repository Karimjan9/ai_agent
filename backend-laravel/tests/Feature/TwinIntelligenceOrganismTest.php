<?php

namespace Tests\Feature;

use App\Models\DualTrackLaneCredit;
use App\Models\DualTrackOutcome;
use App\Models\DualTrackRun;
use App\Models\DualTrackInferenceObservation;
use App\Models\DualTrackMemberCredit;
use App\Models\DualTrackGenomeArchive;
use App\Models\DualTrackOrganismHealthSnapshot;
use App\Models\DualTrackReflectionLesson;
use App\Models\DualTrackRedTeamTrial;
use App\Models\DualTrackCellPolicy;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Services\DualTrackLaneCreditService;
use App\Services\DualTrackOrchestratorService;
use App\Services\LaneSpecificRewardService;
use App\Services\DualTrackMemoryService;
use App\Services\DualTrackExchangeService;
use App\Services\TwinIntelligenceProfileService;
use App\Services\CouncilMemberCreditService;
use App\Services\TwinGenomeArchiveService;
use App\Services\CapabilityCellRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwinIntelligenceOrganismTest extends TestCase
{
    use RefreshDatabase;

    public function test_champion_and_council_have_different_operating_systems(): void
    {
        $profiles = app(TwinIntelligenceProfileService::class);
        $champion = $profiles->profile('champion');
        $council = $profiles->profile('council');

        $this->assertNotSame($champion['learning_objective'], $council['learning_objective']);
        $this->assertNotSame($champion['memory_namespace'], $council['memory_namespace']);
        $this->assertNotSame($champion['evolution_mode'], $council['evolution_mode']);
        $this->assertFalse($champion['transfer_policy']['status_transfer']);
        $this->assertFalse($council['transfer_policy']['status_transfer']);

        $packet = app(DualTrackExchangeService::class)->capabilityPacket(
            'council', 'champion', 'risk_warning', ['statement' => 'Avoid this regime.', 'cell_key' => 'XAUUSD|H1|range|high|signal'],
        );
        $this->assertTrue($packet['accepted']);
        $this->assertFalse($packet['status_transfer']);
    }

    public function test_same_market_outcome_produces_lane_specific_reward_semantics(): void
    {
        $base = [
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'task_type' => 'paper_signal',
            'cell_key' => 'XAUUSD|H1|trend_up|normal|paper_signal', 'decision' => 'WAIT',
            'outcome_status' => 'settled', 'actual_outcome' => 'avoided_loss',
            'profit_percent' => -1, 'regret' => 0, 'correct' => true, 'promotion_evidence' => false,
        ];
        $champion = DualTrackOutcome::create([...$base, 'outcome_key' => 'reward-champion', 'lane' => 'champion']);
        $council = DualTrackOutcome::create([...$base, 'outcome_key' => 'reward-council', 'lane' => 'council']);

        $service = app(LaneSpecificRewardService::class);
        $championReward = $service->score($champion);
        $councilReward = $service->score($council);

        $this->assertNotSame($championReward['learning_objective'], $councilReward['learning_objective']);
        $this->assertSame('risk_veto_success', $councilReward['credit_type']);
        $this->assertLessThan($councilReward['reward'], $championReward['reward']);

        $memory = app(DualTrackMemoryService::class)->settle($council);
        $this->assertSame('raw', $memory['layer']);
        $this->assertDatabaseHas('dual_track_memory_lessons', [
            'source_outcome_id' => $council->id,
            'memory_namespace' => 'council.institutional_memory',
            'learning_objective' => 'collective_reasoning_quality',
        ]);
    }

    public function test_runtime_records_typed_exchange_and_diversity_without_promotion(): void
    {
        $result = app(DualTrackOrchestratorService::class)->observeSignal(
            [
                'symbol' => 'EURUSD', 'timeframe' => 'M15', 'task_type' => 'paper_signal',
                'market_regime' => 'range', 'volatility_regime' => 'low', 'event_key' => 'twin-organism-1',
            ],
            ['decision' => 'BUY', 'confidence' => .8, 'expected_edge' => .4],
            ['decision' => 'WAIT', 'confidence' => .6, 'risk_warning' => 'regime uncertainty'],
            ['constitution_integrity' => true, 'snapshot_integrity' => true],
        );

        $this->assertSame('recorded', $result['exchange']['status']);
        $this->assertSame('productive_dissent_observed', $result['diversity']['status']);
        $this->assertCount(2, $result['exchange']['packets']);
        $this->assertFalse($result['exchange']['promotion_evidence']);
        $this->assertDatabaseCount('dual_track_exchange_packets', 2);
        $this->assertDatabaseCount('dual_track_diversity_metrics', 1);
    }

    public function test_counterfactual_credit_is_lane_scoped(): void
    {
        $run = DualTrackRun::create([
            'run_key' => 'credit-run', 'protocol' => 'test', 'symbol' => 'GBPUSD', 'timeframe' => 'H1',
            'task_type' => 'paper_signal', 'cell_key' => 'GBPUSD|H1|transition|high|paper_signal',
            'mode' => 'shadow', 'status' => 'observed', 'selected_lane' => 'incumbent',
            'selected_decision' => 'WAIT', 'champion_decision' => 'BUY', 'council_decision' => 'WAIT',
            'input_hash' => hash('sha256', 'credit-input'), 'output_hash' => hash('sha256', 'credit-output'),
            'scores' => [], 'champion_output' => [], 'council_output' => [], 'evidence' => [],
            'routing' => [], 'metadata' => [], 'promotion_evidence' => false,
        ]);
        $outcome = DualTrackOutcome::create([
            'outcome_key' => 'credit-outcome', 'dual_track_run_id' => $run->id, 'symbol' => 'GBPUSD',
            'timeframe' => 'H1', 'task_type' => 'paper_signal', 'cell_key' => $run->cell_key,
            'lane' => 'council', 'decision' => 'WAIT', 'outcome_status' => 'settled',
            'actual_outcome' => 'avoided_loss', 'profit_percent' => -1, 'correct' => true,
            'promotion_evidence' => false,
        ]);

        $credit = app(DualTrackLaneCreditService::class)->record($outcome);

        $this->assertSame('recorded', $credit['status']);
        $this->assertSame('risk_veto_success', $credit['credit_type']);
        $this->assertSame(1, DualTrackLaneCredit::query()->where('lane', 'council')->count());
        $this->assertFalse($credit['promotion_evidence']);
    }

    public function test_evolution_evidence_plane_records_independent_members_genomes_health_reflection_and_red_team(): void
    {
        $result = app(DualTrackOrchestratorService::class)->observeSignal(
            ['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'task_type' => 'paper_signal', 'market_regime' => 'range', 'volatility_regime' => 'high', 'event_key' => 'evidence-plane-1', 'snapshot_hash' => hash('sha256', 'snapshot')],
            ['decision' => 'BUY', 'confidence' => .8, 'inference' => ['process_id' => 'champion-call', 'context_hash' => hash('sha256', 'champion-context')]],
            ['decision' => 'WAIT', 'confidence' => .6, 'committee' => [
                ['agent' => 'direction', 'schema' => 'direction/v1', 'decision' => 'BUY'],
                ['agent' => 'skeptic', 'schema' => 'falsification/v1', 'decision' => 'WAIT'],
                ['agent' => 'risk', 'schema' => 'risk/v1', 'decision' => 'WAIT'],
            ], 'inference' => ['process_id' => 'council-call', 'context_hash' => hash('sha256', 'council-context')]],
        );

        $this->assertSame('recorded_independent_contexts', $result['inference']['status']);
        $this->assertSame(2, DualTrackInferenceObservation::query()->count());
        $this->assertGreaterThanOrEqual(3, DualTrackRedTeamTrial::query()->count());

        $model = ModelVersion::create(['name' => 'twin-evidence-model', 'strategy' => 'ema_rsi_v1', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => ['ema_fast' => 20, 'ema_slow' => 80], 'metadata' => []]);
        $candidate = ModelMarketPerformance::create(['model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router', 'status' => 'challenger', 'evidence_status' => 'valid']);
        $outcome = DualTrackOutcome::create([
            'outcome_key' => 'evidence-plane-outcome', 'dual_track_run_id' => $result['run_id'], 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'task_type' => 'paper_signal', 'cell_key' => 'XAUUSD|H1|range|high|paper_signal', 'lane' => 'council', 'decision' => 'WAIT',
            'outcome_status' => 'settled', 'actual_outcome' => 'loss', 'reward' => -1, 'profit_percent' => -1, 'regret' => 1, 'correct' => false, 'confidence' => .6, 'promotion_evidence' => false,
        ]);

        $memberCredit = app(CouncilMemberCreditService::class)->record($outcome);
        $archive = app(TwinGenomeArchiveService::class)->record($candidate, $outcome);
        app(\App\Services\OrganismHealthService::class)->record($outcome);
        app(\App\Services\TwinReflectionService::class)->record($outcome);

        $this->assertSame('recorded', $memberCredit['status']);
        $this->assertCount(3, DualTrackMemberCredit::query()->get());
        $this->assertSame('recorded', $archive['status']);
        $this->assertSame(1, DualTrackGenomeArchive::query()->count());
        $this->assertSame(1, DualTrackOrganismHealthSnapshot::query()->count());
        $this->assertSame(1, DualTrackReflectionLesson::query()->count());
        $this->assertSame('promotion_blocked', DualTrackOrganismHealthSnapshot::query()->first()->status);
        $this->assertDatabaseCount('dual_track_genome_archive_events', 1);
    }

    public function test_active_router_cannot_bypass_the_promotion_authority(): void
    {
        $cell = 'EURUSD|H1|trend_up|normal|signal';
        DualTrackCellPolicy::create([
            'policy_key' => 'cell:'.$cell, 'symbol' => 'EURUSD', 'timeframe' => 'H1', 'cell_key' => $cell,
            'mode' => 'active', 'recommended_lane' => 'council', 'active_lane' => 'council', 'status' => 'certified',
            'sample_count' => 100, 'minimum_samples' => 30, 'confidence_margin' => 10, 'disagreement_value' => 0,
            'lane_statistics' => [], 'risk_bounds' => [], 'policy' => [], 'policy_hash' => hash('sha256', 'policy'),
            'promotion_evidence' => false,
        ]);
        $previous = config('services.dual_track');
        config(['services.dual_track.mode' => 'active', 'services.dual_track.activate_certified_cells' => true]);
        try {
            $result = app(CapabilityCellRouterService::class)->decide([
                'symbol' => 'EURUSD', 'timeframe' => 'H1', 'market_regime' => 'trend_up', 'volatility_regime' => 'normal',
            ]);
        } finally {
            config(['services.dual_track' => $previous]);
        }

        $this->assertSame('incumbent', $result['route']);
        $this->assertFalse($result['promotion']['allowed']);
        $this->assertNotEmpty($result['promotion']['reasons']);
    }

    public function test_missing_risk_evidence_can_never_certify_a_cell(): void
    {
        $base = [
            'symbol' => 'GBPUSD', 'timeframe' => 'H1', 'task_type' => 'paper_signal',
            'cell_key' => 'GBPUSD|H1|range|normal|paper_signal', 'outcome_status' => 'settled',
            'lane' => 'champion', 'decision' => 'BUY', 'actual_outcome' => 'win', 'reward' => 1,
            'correct' => true, 'promotion_evidence' => false,
        ];
        for ($i = 0; $i < 30; $i++) DualTrackOutcome::create([...$base, 'outcome_key' => 'risk-missing-'.$i]);
        $result = app(\App\Services\DualTrackCellPolicyService::class)->update(DualTrackOutcome::query()->latest('id')->firstOrFail());

        $this->assertNotSame('certified', $result['status']);
        $this->assertDatabaseHas('dual_track_cell_policies', ['status' => 'candidate']);
    }

    public function test_materialized_statistics_drift_and_priority_replay_are_idempotent(): void
    {
        $outcome = DualTrackOutcome::create([
            'outcome_key' => 'runtime-v2-stats', 'symbol' => 'AUDUSD', 'timeframe' => 'H1',
            'task_type' => 'paper_signal', 'cell_key' => 'AUDUSD|H1|range|normal|paper_signal',
            'lane' => 'champion', 'decision' => 'BUY', 'outcome_status' => 'settled',
            'actual_outcome' => 'loss', 'reward' => -1, 'profit_percent' => -1, 'regret' => 1,
            'correct' => false, 'confidence' => .8, 'metadata' => ['risk_evidence_missing' => false],
            'promotion_evidence' => false,
        ]);

        $stats = app(\App\Services\DualTrackStatisticsService::class);
        $stats->record($outcome);
        $stats->record($outcome);

        $this->assertDatabaseHas('dual_track_cell_statistics', ['symbol' => 'AUDUSD', 'lane' => 'champion', 'settled_count' => 1, 'wins' => 0]);
        $this->assertDatabaseCount('dual_track_statistic_events', 1);

        $drift = app(\App\Services\DualTrackDriftEngineService::class)->observe($outcome);
        $this->assertContains($drift['state'], ['healthy', 'risk_reduce', 'quarantine', 'recover']);
        $memory = app(\App\Services\PrioritizedMemoryReplayService::class)->enqueue($outcome);
        $this->assertGreaterThan(1, $memory['priority_score']);
        $guidance = app(\App\Services\DualTrackHierarchicalEvidenceService::class)->guidance('AUDUSD', 'H1', $outcome->cell_key);
        $this->assertTrue($guidance['research_only']);
    }
}
