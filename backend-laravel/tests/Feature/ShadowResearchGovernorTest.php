<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\ShadowResearchGovernorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShadowResearchGovernorTest extends TestCase
{
    use RefreshDatabase;

    public function test_smart_courage_uses_the_declared_twenty_seat_budget(): void
    {
        $allocation = app(ShadowResearchGovernorService::class)->allocation(20);

        $this->assertSame([
            'frozen_control' => 1,
            'targeted_repair' => 7,
            'proven_gene_refinement' => 4,
            'architecture_explorer' => 3,
            'robustness_split_specialist' => 2,
            'volume_m15_specialist' => 2,
            'bounded_random_adversarial' => 1,
        ], $allocation['counts']);
        $this->assertSame(.55, $allocation['evidence_driven_share']);
        $this->assertSame(.40, $allocation['controlled_exploration_share']);
        $this->assertSame(.05, $allocation['control_share']);
        $this->assertFalse($allocation['promotion_evidence']);
    }

    public function test_rescue_blocked_shadow_escape_uses_one_six_five_four_three_one_without_targeted_repair(): void
    {
        $allocation = app(ShadowResearchGovernorService::class)->allocation(20, true);

        $this->assertSame([
            'frozen_control' => 1,
            'architecture_explorer' => 6,
            'robustness_split_specialist' => 5,
            'volume_m15_specialist' => 4,
            'regime_abstention_specialist' => 3,
            'bounded_random_adversarial' => 1,
        ], $allocation['counts']);
        $this->assertTrue($allocation['targeted_rescue_blocked']);
        $this->assertSame(.90, $allocation['controlled_exploration_share']);
        $this->assertArrayNotHasKey('targeted_repair', $allocation['counts']);
    }

    public function test_shadow_allocation_materializes_controls_per_execution_lane(): void
    {
        $plan = collect(range(1, 20))->map(fn (int $slot): array => [
            'origin' => 'g98_council',
            'family' => 'hybrid',
            'target' => 'regime_coverage',
            'niche' => ['role' => 'existing_role'],
        ])->all();
        $assessment = [
            'allowed' => true,
            'scope' => ['symbol' => 'XAUUSD', 'timeframe' => 'H1'],
        ];

        $adapted = app(ShadowResearchGovernorService::class)->applyAllocation($plan, $assessment, 33);
        $roles = collect($adapted)->map(fn (array $slot): string => (string) data_get($slot, 'niche.shadow_research_lane.role'));

        $this->assertSame([
            'frozen_control' => 2,
            'targeted_repair' => 7,
            'proven_gene_refinement' => 4,
            'architecture_explorer' => 3,
            'robustness_split_specialist' => 2,
            'volume_m15_specialist' => 1,
            'bounded_random_adversarial' => 1,
        ], $roles->countBy()->all());
        $this->assertTrue(collect($adapted)->every(fn (array $slot): bool =>
            data_get($slot, 'niche.shadow_only') === true
            && data_get($slot, 'niche.promotion_evidence') === false
            && data_get($slot, 'niche.mutation_credit') === false
        ));
        $this->assertTrue((bool) data_get($adapted[0], 'niche.control_only'));
        $this->assertSame('monthly_survival', $adapted[0]['target']);
        $this->assertSame('price', data_get($adapted[0], 'niche.control_pair_contract.execution_lane'));
        $this->assertTrue(collect($adapted)->filter(fn (array $slot): bool =>
            data_get($slot, 'niche.control_only', false) === true
        )->pluck('niche.control_pair_contract.execution_lane')->contains('volume'));

        $architecture = collect($adapted)->filter(fn (array $slot): bool =>
            data_get($slot, 'niche.shadow_research_lane.role') === 'architecture_explorer'
        );
        $this->assertSame($architecture->count(), $architecture->pluck('niche.entry_topology_variant')->unique()->count());
        $this->assertTrue($architecture->every(fn (array $slot): bool =>
            in_array(data_get($slot, 'niche.entry_topology_variant'), [
                'regime_consensus_v1', 'transition_hazard_v1', 'breakout_retest_v1',
                'trend_regime_confirmation_v1', 'range_reentry_confirmation_v1',
                'volatility_persistence_v1',
            ], true)
        ));

        $robustness = collect($adapted)->filter(fn (array $slot): bool =>
            data_get($slot, 'niche.shadow_research_lane.role') === 'robustness_split_specialist'
        );
        $this->assertSame($robustness->count(), $robustness->pluck('niche.shadow_mutation_gene')->unique()->count());

        $volume = collect($adapted)->filter(fn (array $slot): bool =>
            data_get($slot, 'niche.shadow_research_lane.role') === 'volume_m15_specialist'
        );
        $this->assertTrue($volume->every(fn (array $slot): bool =>
            data_get($slot, 'niche.control_only', false) === false
            && data_get($slot, 'niche.shadow_mutation_gene') !== null
        ));
    }

    public function test_rescue_blocked_shadow_regime_slots_become_temporal_survival_probes(): void
    {
        $plan = collect(range(1, 20))->map(fn (int $slot): array => [
            'origin' => 'g98_council',
            'family' => 'hybrid',
            'target' => 'regime_coverage',
            'niche' => ['role' => 'existing_role'],
        ])->all();
        $assessment = [
            'allowed' => true,
            'escape_lane' => true,
            'scope' => ['symbol' => 'XAUUSD', 'timeframe' => 'H1'],
        ];

        $adapted = app(ShadowResearchGovernorService::class)->applyAllocation($plan, $assessment, 46, true);
        $temporal = collect($adapted)->filter(fn (array $slot): bool =>
            data_get($slot, 'niche.specialist_role') === 'temporal_survival_drift_abstention_specialist'
        )->values();

        $this->assertCount(3, $temporal);
        $this->assertSame('temporal_stability', $temporal->pluck('target')->unique()->first());
        $this->assertSame([
            'expiry_age_probe',
            'expiry_half_life_probe',
            'drift_threshold_probe',
        ], $temporal->map(fn (array $slot): string => (string) data_get($slot, 'niche.temporal_hypothesis.variant'))->all());
        $this->assertTrue($temporal->every(fn (array $slot): bool =>
            data_get($slot, 'niche.shadow_only') === true
            && data_get($slot, 'niche.temporal_hypothesis.independent_evidence_required') === true
            && data_get($slot, 'niche.temporal_hypothesis.mutation_credit') === false
            && data_get($slot, 'niche.temporal_hypothesis.promotion_evidence') === false
        ));
    }

    public function test_mixed_family_and_volume_lanes_receive_exact_frozen_controls(): void
    {
        $plan = collect(range(1, 20))->map(function (int $slot): array {
            return [
                'origin' => 'g98_council',
                'family' => in_array($slot, [16, 19], true) ? 'hybrid' : 'differential_router',
                'target' => 'regime_coverage',
                'niche' => ['role' => 'existing_role'],
            ];
        })->all();

        $adapted = app(ShadowResearchGovernorService::class)->applyAllocation($plan, [
            'allowed' => true,
            'scope' => ['symbol' => 'XAUUSD', 'timeframe' => 'H1'],
        ], 47);

        $controls = collect($adapted)->filter(fn (array $slot): bool =>
            data_get($slot, 'niche.control_only', false) === true
        );
        $pairs = $controls->map(fn (array $slot): string =>
            data_get($slot, 'niche.control_pair_contract.execution_lane').'|'.data_get($slot, 'niche.control_pair_contract.strategy_family')
        )->values();

        $this->assertCount(4, $controls);
        $this->assertCount(4, $pairs->unique());
        $this->assertTrue(collect($adapted)->filter(fn (array $slot): bool =>
            data_get($slot, 'niche.control_only', false) === false
        )->every(fn (array $slot): bool =>
            data_get($slot, 'niche.control_pair_contract.required_for_candidate') === true
            && data_get($slot, 'niche.control_pair_contract.same_strategy_family') === true
        ));
    }

    public function test_complete_control_failure_opens_shadow_but_incomplete_control_does_not(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Shadow governor test', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'population_size' => 1, 'status' => 'screened', 'trigger_context' => [],
        ]);
        $dataHash = str_repeat('a', 64);
        $executionHash = str_repeat('b', 64);
        $result = [
            'total_trades' => 20,
            'profit_factor' => .80,
            'data_manifest' => ['snapshot_sha256' => $dataHash],
            'execution_contract' => ['execution_hash' => $executionHash],
        ];
        $model = ModelVersion::create([
            'name' => 'shadow-control', 'strategy' => 'shadow-control', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => [],
            'metadata' => [
                'g98_council_lane' => ['control_only' => true],
                'last_screen_result' => $result,
            ], 'evidence_status' => 'valid',
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'g98_council', 'lifecycle_status' => 'screened',
            'parameter_diff' => [], 'sample_count' => 20, 'profit_factor' => .80,
        ]);
        LabEvaluationRun::create([
            'run_id' => 'shadow-control-run', 'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id, 'model_version_id' => $model->id,
            'phase' => 'screening', 'mode' => 'screening', 'attempt' => 1,
            'status' => 'completed', 'metrics' => $result,
        ]);
        CandidateGateDecision::create([
            'lab_agent_id' => $agent->id, 'stage' => 'screening', 'decision' => 'failed',
            'reason_codes' => ['FAILED_PROFIT_FACTOR'], 'metrics' => $result,
            'evaluated_at' => now(),
        ]);

        $service = app(ShadowResearchGovernorService::class);
        $allowed = $service->assess($generation->fresh(['agents.modelVersion']));
        $this->assertTrue($allowed['allowed']);
        $this->assertSame('control_failed_shadow_allowed', $allowed['status']);
        $this->assertFalse($allowed['promotion_evidence']);

        $agent->update(['lifecycle_status' => 'technical_quarantine']);
        $technical = $service->assess($generation->fresh(['agents.modelVersion']));
        $this->assertFalse($technical['allowed']);
        $this->assertSame('evidence_incomplete', $technical['status']);

        LabEvaluationRun::query()->delete();
        $blocked = $service->assess($generation->fresh(['agents.modelVersion']));
        $this->assertFalse($blocked['allowed']);
        $this->assertSame('evidence_incomplete', $blocked['status']);
    }
}
