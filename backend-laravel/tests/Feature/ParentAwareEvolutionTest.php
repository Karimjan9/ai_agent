<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabParentContextScore;
use App\Models\ModelVersion;
use App\Services\CouncilAblationService;
use App\Services\ParentAwareCreditService;
use App\Services\ParentMentorBrokerService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParentAwareEvolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_is_a_contextual_mentor_and_not_a_parameter_vector_copy(): void
    {
        $schema = app(StrategyParameterSchemaService::class)->defaults('trend');
        $parent = ModelVersion::create([
            'name' => 'parent-mentor', 'strategy' => 'parent-mentor', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => $schema,
            'metadata' => ['skill_mentor' => ['parameter_key' => 'trend_strength_min', 'direction' => 'increase']],
            'evidence_status' => 'valid',
        ]);
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Parent test', 'timeframe' => 'H1',
            'strategy_families' => ['trend'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 2, 'trigger_type' => 'test',
            'population_size' => 1, 'status' => 'draft', 'trigger_context' => [],
        ]);
        $parentAgent = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $parent->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend',
            'origin' => 'test', 'lifecycle_status' => 'forward_validated',
            'parameter_diff' => ['trend_strength_min' => ['old' => 20, 'new' => 24]],
        ]);

        $contract = app(ParentMentorBrokerService::class)->propose(
            collect([$parent]), 'XAUUSD', 'H1', 'trend', 'profit_factor',
            ['regime' => 'trend_up', 'volume_state' => 'normal', 'parent_lane' => 'mentor_assisted'],
            'mentor_assisted', array_keys($schema),
        );

        $this->assertSame('proposal_available', $contract['status']);
        $this->assertSame($parent->id, data_get($contract, 'parent_suggestion.parent_model_version_id'));
        $this->assertSame('trend_strength_min', data_get($contract, 'parent_suggestion.changed_gene'));
        $this->assertFalse(data_get($contract, 'inheritance_firewall.direct_parameter_vector_copy'));
        $this->assertTrue(data_get($contract, 'inheritance_firewall.child_specific_change_required'));
        $this->assertNotNull($parentAgent->id);
    }

    public function test_parent_credit_requires_autonomous_mentored_and_ablated_branches(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Credit test', 'timeframe' => 'H1',
            'strategy_families' => ['trend'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'population_size' => 1, 'status' => 'draft', 'trigger_context' => [],
        ]);
        $parent = ModelVersion::create([
            'name' => 'credit-parent', 'strategy' => 'credit-parent', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => ['trend_strength_min' => 20],
            'metadata' => [], 'evidence_status' => 'valid',
        ]);
        $candidate = ModelVersion::create([
            'name' => 'credit-child', 'strategy' => 'credit-child', 'version' => 'v2',
            'generation' => 2, 'status' => 'testing', 'parameters' => ['trend_strength_min' => 22],
            'metadata' => [
                'parent_mentor_broker' => [
                    'lane' => 'mentor_assisted',
                    'context' => ['regime' => 'trend_up', 'volume_state' => 'normal', 'cost_stress' => 'normal'],
                    'parent_suggestion' => [
                        'parent_model_version_id' => $parent->id,
                        'skill_key' => 'trend_strength_min',
                        'changed_gene' => 'trend_strength_min',
                    ],
                ],
            ],
            'evidence_status' => 'valid',
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $candidate->id,
            'parent_a_model_version_id' => $parent->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend',
            'origin' => 'test', 'lifecycle_status' => 'full_validation',
            'parameter_diff' => ['trend_strength_min' => ['old' => 20, 'new' => 22]],
        ]);

        $registered = app(ParentAwareCreditService::class)->registerCandidate($agent->fresh(['modelVersion']));
        $this->assertSame('awaiting_branches', $registered['status']);

        $result = app(ParentAwareCreditService::class)->recordFullReplay(
            $agent->fresh(['modelVersion']),
            [
                'evidence_run_id' => 'parent-cf-test',
                'parent_counterfactual' => [
                    'autonomous' => ['forward_score' => 1.00],
                    'mentored' => ['forward_score' => 1.20],
                    'ablated' => ['forward_score' => 1.05],
                ],
            ],
            null,
            (object) ['decision' => 'passed'],
        );

        $this->assertSame('parent_helpful', data_get($result, 'counterfactual.status'));
        $this->assertGreaterThan(0, (float) data_get($result, 'parent_incremental_value'));
        $this->assertSame(1, LabParentContextScore::query()->where('parent_model_version_id', $parent->id)->count());
    }

    public function test_council_ablation_is_planned_for_every_declared_member(): void
    {
        $first = ModelVersion::create([
            'name' => 'ablation-one', 'strategy' => 'ablation-one', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => [],
            'evidence_status' => 'valid',
        ]);
        $second = ModelVersion::create([
            'name' => 'ablation-two', 'strategy' => 'ablation-two', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => [],
            'evidence_status' => 'valid',
        ]);
        $contract = app(CouncilAblationService::class)->plan([
            ['role' => 'trend_up_specialist', 'model_version_id' => $first->id],
            ['role' => 'range_specialist', 'model_version_id' => $second->id],
        ], [
            'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
        ]);

        $this->assertSame('planned', $contract['status']);
        $this->assertSame(['trend_up_specialist', 'range_specialist'], $contract['missing_roles']);
        $this->assertFalse($contract['official_proxy_eligible']);
        $this->assertFalse($contract['promotion_evidence']);
    }
}
