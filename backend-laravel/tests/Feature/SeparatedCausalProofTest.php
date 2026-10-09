<?php

namespace Tests\Feature;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningMutationIntent;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvolutionCreditEvent;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\ModelVersion;
use App\Services\CausalLearningConfirmationService;
use App\Services\CausalSkillCreditBridgeService;
use App\Services\ScopedResearchCertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SeparatedCausalProofTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_beneficial_blind_trait_does_not_erase_component_contrast(): void
    {
        [$experiment, $pairs] = $this->question();
        $observations = app(CausalLearningConfirmationService::class)->scopeMeasurements($experiment);
        $this->assertTrue($observations['components']['memory_guided']['measured_component_passed']);
        $this->assertTrue($observations['components']['blinded']['measured_component_passed']);
        $this->assertFalse($observations['selector']['observed_window_effect']['passed']);
        $this->assertFalse($observations['selector']['single_question_is_selector_certificate']);
        $this->assertFalse($observations['components']['blinded']['independent_certificate_granted']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_component_diagnostic_still_requires_exact_control_and_no_regression(): void
    {
        [$experiment, $pairs] = $this->question();
        $pairs[0]->update(['non_target_regression' => ['status' => 'failed', 'safe' => false]]);
        $pairs[1]->update(['control_data_hash' => str_repeat('f', 64)]);
        $observations = app(CausalLearningConfirmationService::class)->scopeMeasurements($experiment);
        $this->assertFalse($observations['components']['memory_guided']['measured_component_passed']);
        $this->assertFalse($observations['components']['blinded']['measured_component_passed']);
        $this->assertFalse($observations['promotion_evidence']);
    }

    public function test_prospective_scope_never_borrows_legacy_confirmed_flag_or_credit(): void
    {
        [$experiment] = $this->question(false);
        $registry = app(ScopedResearchCertificateService::class);
        $certificate = $registry->register('component', $experiment, $this->design());
        $this->assertTrue($certificate['valid']);
        $experiment->update(['status' => 'confirmed', 'confirmed_at' => now(),
            'guided_beats_control' => true, 'guided_beats_blinded' => true]);
        $result = app(CausalSkillCreditBridgeService::class)->settle($experiment);
        $this->assertSame('SCOPED_ORIGINAL_CERTIFICATE_REQUIRED_NOT_LEGACY_CREDIT', $result['reason_code']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_draft_observations_do_not_consume_a_future_original_assessment(): void
    {
        [$experiment] = $this->question(false);
        $registry = app(ScopedResearchCertificateService::class);
        $component = $registry->register('component', $experiment, $this->design());
        $selector = $registry->register('selector', $experiment, $this->design());
        $experiment->update(['evidence' => ['outcomes' => $this->storedOutcomes]]);
        $owner = app(CausalLearningConfirmationService::class);
        $first = $owner->settleScopedProofs($experiment);
        $second = $owner->settleScopedProofs($experiment);
        $this->assertSame($first, $second);
        $this->assertFalse($first['confirmed']);
        $this->assertCount(2, $first['certificates']);
        $this->assertTrue($first['measurements']['components']['memory_guided']['measured_component_passed']);
        $this->assertDatabaseCount('scoped_research_certificates', 2);
        foreach ([$component, $selector] as $registration) {
            $this->assertFalse($registry->inspect($registration['certificate_id'])['authority']);
        }
        $this->assertSame(0, LabEvolutionCreditEvent::query()->count());
    }

    private array $storedOutcomes = [];

    private function question(bool $observed = true): array
    {
        $lab = AiLaboratory::create(['name' => 'Separated proof', 'symbol' => 'XAUUSD',
            'timeframe' => 'H1', 'strategy_families' => ['hybrid'], 'is_active' => false]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'test', 'population_size' => 3, 'status' => 'draft']);
        $agents = [];
        foreach ([2, 2, 1] as $i => $value) {
            $model = ModelVersion::create(['name' => 'scope-'.$i, 'strategy' => 'hybrid', 'version' => 'v1',
                'status' => 'testing', 'parameters' => ['entry_threshold' => $value]]);
            $agents[] = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test',
                'lifecycle_status' => 'draft', 'parameter_diff' => $i === 2 ? [] : ['entry_threshold' => ['old' => 1, 'new' => 2]]]);
        }
        $map = LabMutationResponseMap::create(['response_key' => hash('sha256', 'control'),
            'stage' => 'screening', 'status' => 'control', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'target' => 'profit_factor', 'lab_agent_id' => $agents[2]->id,
            'metadata' => ['control_contract' => ['protocol' => 'frozen_control_v2', 'control_only' => true,
                'role' => 'control', 'generation_id' => $generation->id,
                'data_hash' => str_repeat('d', 64), 'execution_hash' => str_repeat('e', 64)]]]);
        $pairs = [];
        foreach ([0, 1] as $i) {
            $pairs[] = LabLearningLanePair::create(['pair_key' => hash('sha256', 'pair'.$i),
                'lab_generation_id' => $generation->id, 'candidate_agent_id' => $agents[$i]->id,
                'control_agent_id' => $agents[2]->id, 'control_response_map_id' => $map->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'target' => 'profit_factor',
                'baseline_source' => 'control', 'pair_integrity_status' => 'verified', 'same_generation' => true,
                'candidate_data_hash' => str_repeat('d', 64), 'control_data_hash' => str_repeat('d', 64),
                'candidate_execution_hash' => str_repeat('e', 64), 'control_execution_hash' => str_repeat('e', 64),
                'non_target_regression' => ['status' => 'passed', 'safe' => true]]);
            AgentLearningMutationIntent::create(['intent_id' => (string) Str::uuid(), 'intent_key' => hash('sha256', 'intent'.$i),
                'lab_generation_id' => $generation->id, 'model_version_id' => $agents[$i]->model_version_id,
                'lab_agent_id' => $agents[$i]->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
                'strategy_family' => 'hybrid', 'target' => 'profit_factor', 'selected_gene' => 'entry_threshold',
                'influence_type' => $i === 0 ? 'memory_guided' : 'blinded_counterfactual', 'status' => 'bound',
                'baseline_hash' => hash('sha256', 'baseline'), 'parameter_hash' => hash('sha256', 'parameters'),
                'mutation_hash' => hash('sha256', 'mutation'), 'sealed_at' => now()]);
        }
        $this->storedOutcomes = ['memory_guided' => $this->outcome($agents[0], $pairs[0], 3),
            'blinded' => $this->outcome($agents[1], $pairs[1], 3),
            'frozen_control' => $this->outcome($agents[2], $pairs[0], 1)];
        $experiment = AgentLearningCausalExperiment::create(['experiment_key' => hash('sha256', 'separated'),
            'lab_generation_id' => $generation->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'target' => 'profit_factor', 'gene_key' => 'entry_threshold',
            'guided_agent_id' => $agents[0]->id, 'blinded_agent_id' => $agents[1]->id,
            'control_agent_id' => $agents[2]->id, 'status' => 'ready_for_replay',
            'evidence' => $observed ? ['outcomes' => $this->storedOutcomes] : []]);
        return [$experiment, $pairs];
    }

    private function outcome(LabAgent $agent, LabLearningLanePair $pair, int $score): array
    {
        return ['agent_id' => $agent->id, 'pair_id' => $pair->id, 'evidence_run_id' => 'diagnostic-'.$agent->id,
            'windows' => array_map(fn ($i) => ['id' => 'w'.$i, 'score' => $score, 'trades' => 20], range(1, 6)),
            'independence_verified' => true, 'purge_embargo_verified' => true, 'maximum_holding_bars' => 12,
            'execution_horizon_overlay_applied' => true, 'power_contract_declared' => true, 'power_quorum_verified' => true,
            'minimum_trades_per_window' => 8,
            'target_measurement' => ['metric' => 'profit_factor', 'direction' => 'higher', 'value' => $score]];
    }

    private function design(): array
    {
        return ['validation_start' => '2027-01-01T00:00:00Z', 'validation_end' => '2027-07-01T00:00:00Z',
            'evaluator_hash' => str_repeat('a', 64), 'context_hash' => str_repeat('c', 64),
            'execution_hash' => str_repeat('e', 64), 'owner_source_hash' => str_repeat('b', 64),
            'metric' => 'profit_factor', 'stopping_rule' => ['windows' => 6],
            'subject' => ['traits' => ['entry_threshold' => ['old' => 1, 'new' => 2]]]];
    }
}
