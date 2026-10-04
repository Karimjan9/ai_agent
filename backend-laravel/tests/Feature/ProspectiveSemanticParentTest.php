<?php

namespace Tests\Feature;

use App\Models\AgentLearningCausalExperiment;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Services\CompositionAuthorityKernelService;
use App\Services\LabAgentPreflightService;
use App\Services\LabPopulationService;
use App\Services\ProspectiveRepairExperimentService;
use App\Services\StrategySemanticGroupService;
use App\Services\StrategyTacticRiskCompositionPlannerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/** Exact semantic ancestry and bounded, unobserved technical retry regression. */
class ProspectiveSemanticParentTest extends TestCase
{
    use RefreshDatabase;

    private function sourcePair(string $role = 'general', string $family = 'hybrid'): LabLearningLanePair
    {
        $fixture = new ProspectiveRepairExperimentTest('test_promising_screen_freezes_one_new_three_arm_program_without_relabeling_source');
        $pair = (new ReflectionMethod(ProspectiveRepairExperimentTest::class, 'sourcePair'))->invoke($fixture)
            ->load('candidateAgent.modelVersion', 'controlAgent.modelVersion');
        $pair->update(['strategy_family' => $family]);
        $group = app(StrategySemanticGroupService::class)->descriptor('XAUUSD', 'H1', $family, [
            'specialist_role' => $role, 'regime' => 'trend_up', 'volatility' => 'normal_volatility',
        ]);
        foreach ([$pair->candidateAgent, $pair->controlAgent] as $agent) {
            $metadata = (array) $agent->modelVersion->metadata;
            $metadata['semantic_group'] = $group;
            $parameters = app(\App\Services\StrategyParameterSchemaService::class)->defaults($family);
            $parameters['transition_wait_candles'] = $agent->id === $pair->candidate_agent_id ? 5 : 4;
            $agent->update(['strategy_family' => $family]);
            $agent->modelVersion->update(['strategy' => $family, 'metadata' => $metadata, 'parameters' => $parameters]);
        }
        return $pair->fresh('candidateAgent.modelVersion', 'controlAgent.modelVersion');
    }

    public function test_source_preserves_specialist_parent_separately_from_decision_context(): void
    {
        $pair = $this->sourcePair('trend_up_specialist');
        $service = app(ProspectiveRepairExperimentService::class);
        $source = $service->eligible('XAUUSD', 'H1', $pair->id);
        $this->assertNotNull($source);
        $this->assertSame('normal', $source['source_context_scope']['volatility']);
        $this->assertSame('trend_up_specialist', $source['source_parent_semantic_group']['role']);
        $planned = $service->materialize($service->seedPlan($source), 'XAUUSD', 'H1', 2);
        $this->assertSame('materialized', $planned['contract']['status']);
        foreach ($planned['plan'] as $slot) {
            $this->assertSame('trend_up_specialist', $slot['niche']['specialist_role']);
            $this->assertSame('normal_volatility', $slot['niche']['volatility']);
            $this->assertSame('normal', $slot['niche']['causal_learning_cohort']['source_context_scope']['volatility']);
        }
        $metadata = (array) $pair->controlAgent->modelVersion->metadata;
        $metadata['semantic_group'] = app(StrategySemanticGroupService::class)->descriptor('XAUUSD', 'H1', 'hybrid', [
            'specialist_role' => 'trend_up_specialist', 'regime' => 'trend_up', 'volatility' => 'high_volatility',
        ]);
        $pair->controlAgent->modelVersion->update(['metadata' => $metadata]);
        $this->assertNull($service->eligible('XAUUSD', 'H1', $pair->id));
        $metadata['semantic_group'] = app(StrategySemanticGroupService::class)->descriptor('XAUUSD', 'H1', 'hybrid', [
            'specialist_role' => 'trend_up_specialist', 'regime' => 'trend_up', 'volatility' => 'normal_volatility',
        ]);
        $pair->controlAgent->modelVersion->update(['metadata' => $metadata]);
        $candidateMetadata = (array) $pair->candidateAgent->modelVersion->metadata;
        $candidateMetadata['semantic_group'] = app(StrategySemanticGroupService::class)->descriptor('XAUUSD', 'H1', 'hybrid', [
            'specialist_role' => 'general', 'regime' => 'trend_up', 'volatility' => 'normal_volatility',
        ]);
        $pair->candidateAgent->modelVersion->update(['metadata' => $candidateMetadata]);
        $this->assertNull($service->eligible('XAUUSD', 'H1', $pair->id));
    }

    public static function families(): array
    {
        return [['hybrid'], ['differential_router']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('families')]
    public function test_exact_genetic_parent_survives_real_three_arm_construction_preflight(string $family): void
    {
        $pair = $this->sourcePair('trend_up_specialist', $family);
        $service = app(ProspectiveRepairExperimentService::class);
        $source = $service->eligible('XAUUSD', 'H1', $pair->id);
        $this->assertNotNull($source);
        $generation = LabGeneration::create(['ai_laboratory_id' => $pair->generation->ai_laboratory_id,
            'generation' => 2, 'population_size' => 3, 'status' => 'draft', 'trigger_type' => 'learning_confirmation',
            'data_fingerprint' => str_repeat('a', 64)]);
        $materialized = $service->materialize($service->seedPlan($source), 'XAUUSD', 'H1', $generation->id);
        $plan = collect($materialized['plan'])->map(function ($slot) {
            $slot['niche']['composition_passport'] = app(CompositionAuthorityKernelService::class)->bindLearningExperiment(
                $slot['niche']['composition_passport'], $slot['niche']['causal_learning_cohort']);
            return $slot;
        })->all();
        $plan = app(StrategyTacticRiskCompositionPlannerService::class)->bindRuntimeOwnership($plan)['plan'];
        $generation->update(['trigger_context' => ['adaptive_evolution_policy' => [
            'causal_learning_counterfactual_cohort' => $materialized['contract']]]]);
        $population = app(LabPopulationService::class);
        $constructor = new ReflectionMethod($population, 'createAgent');
        foreach ($plan as $i => $slot) {
            $failure = null;
            $args = [$generation->fresh('laboratory'), $slot['family'], $slot['origin'], $i + 1,
                $slot['target'], $slot['niche'], null, null, 0, &$failure];
            $this->assertTrue($constructor->invokeArgs($population, $args), (string) $failure);
        }
        foreach ($generation->agents()->get() as $arm) {
            $errors = app(LabAgentPreflightService::class)->inspect($arm)['errors'];
            $this->assertNotContains('NON_EXACT_SEMANTIC_PARENT', $errors);
            $this->assertNotContains('NON_EXACT_SEMANTIC_PARENT_GRAPH_LINK', $errors);
            $this->assertSame(data_get($pair->controlAgent->modelVersion->metadata, 'semantic_group.key'),
                data_get($arm->modelVersion->metadata, 'semantic_group.key'));
        }
        $experiment = AgentLearningCausalExperiment::where('lab_generation_id', $generation->id)->firstOrFail();
        $this->assertSame($source['source_parent_semantic_group'],
            data_get($experiment->evidence, 'prospective_parent_semantic_group'));
        $blindGene = data_get($experiment->evidence, 'blinded_selector.gene');
        $this->assertTrue((bool) data_get(app(\App\Services\DependencyAwareEdgeGenesisFoundryService::class)
            ->mutationAdmission($pair->controlAgent->modelVersion, $blindGene), 'allowed'));
    }

    public function test_technical_retry_is_bounded_and_never_reuses_scientific_evidence(): void
    {
        $pair = $this->sourcePair();
        $service = app(ProspectiveRepairExperimentService::class);
        $original = $service->eligible('XAUUSD', 'H1', $pair->id);
        $generation = LabGeneration::create(['ai_laboratory_id' => $pair->generation->ai_laboratory_id,
            'generation' => 2, 'population_size' => 3, 'status' => 'technical_quarantine',
            'trigger_type' => 'learning_confirmation']);
        $arms = [];
        foreach (range(1, 3) as $index) {
            $model = $pair->controlAgent->modelVersion->replicate();
            $model->name = 'technical-retry-arm-'.$index;
            $model->save();
            $arms[] = LabAgent::create(['lab_generation_id' => $generation->id,
                'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
                'strategy_family' => 'hybrid', 'origin' => 'prospective_repair',
                'lifecycle_status' => 'technical_quarantine',
                'decision_reason' => 'Strict preflight: NON_EXACT_SEMANTIC_PARENT']);
        }
        $experiment = AgentLearningCausalExperiment::create([
            'experiment_key' => hash('sha512', 'technical-semantic-parent'),
            'lab_generation_id' => $generation->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'gene_key' => 'transition_wait_candles',
            'guided_agent_id' => $arms[0]->id, 'blinded_agent_id' => $arms[1]->id,
            'control_agent_id' => $arms[2]->id, 'status' => 'ready_for_replay',
            'evidence' => ['experiment_kind' => ProspectiveRepairExperimentService::KIND,
                'source_pair_id' => $pair->id, 'prospective_source_hash' => str_repeat('f', 64)],
        ]);
        $this->assertNull($service->eligible('XAUUSD', 'H1', $pair->id));
        $experiment->update(['status' => 'invalid_counterfactual_contract']);
        $retry = $service->eligible('XAUUSD', 'H1', $pair->id);
        $this->assertNotNull($retry);
        $this->assertSame($experiment->id, $retry['technical_retry_of_experiment_id']);
        $this->assertNotSame($original['source_hash'], $retry['source_hash']);
        $arms[0]->update(['decision_reason' => 'Strict preflight: OTHER_TECHNICAL_FAILURE']);
        $this->assertNull($service->eligible('XAUUSD', 'H1', $pair->id));
        $arms[0]->update(['decision_reason' => 'Strict preflight: NON_EXACT_SEMANTIC_PARENT']);
        $experiment->replicate(['id'])->fill(['experiment_key' => hash('sha512', 'second-technical-attempt')])->save();
        $this->assertNull($service->eligible('XAUUSD', 'H1', $pair->id));
    }

    public function test_any_replay_receipt_blocks_the_unobserved_technical_retry(): void
    {
        $pair = $this->sourcePair();
        $generation = LabGeneration::create(['ai_laboratory_id' => $pair->generation->ai_laboratory_id,
            'generation' => 2, 'population_size' => 3, 'status' => 'technical_quarantine']);
        $arms = [];
        foreach (range(1, 3) as $index) {
            $model = $pair->controlAgent->modelVersion->replicate();
            $model->name = 'observed-technical-arm-'.$index;
            $model->save();
            $arms[] = LabAgent::create(['lab_generation_id' => $generation->id,
                'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
                'origin' => 'prospective_repair', 'lifecycle_status' => 'technical_quarantine',
                'decision_reason' => 'Strict preflight: NON_EXACT_SEMANTIC_PARENT']);
        }
        AgentLearningCausalExperiment::create([
            'experiment_key' => hash('sha512', 'observed-technical-attempt'),
            'lab_generation_id' => $generation->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'gene_key' => 'transition_wait_candles',
            'guided_agent_id' => $arms[0]->id, 'blinded_agent_id' => $arms[1]->id,
            'control_agent_id' => $arms[2]->id, 'status' => 'invalid_counterfactual_contract',
            'evidence' => ['experiment_kind' => ProspectiveRepairExperimentService::KIND,
                'source_pair_id' => $pair->id, 'prospective_source_hash' => str_repeat('f', 64)],
        ]);
        $service = app(ProspectiveRepairExperimentService::class);
        $this->assertNotNull($service->eligible('XAUUSD', 'H1', $pair->id));
        app(\App\Services\LabImmutableEvidenceService::class)->beginRun($arms[0], 'screening', 'screen');
        $this->assertNull($service->eligible('XAUUSD', 'H1', $pair->id));
    }
}
