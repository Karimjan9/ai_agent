<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\DependencyAwareEdgeGenesisFoundryService;
use App\Services\LabImmutableEvidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademyFoundrySourceAdmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_tested_stage_or_unattested_success_does_not_become_mastery(): void
    {
        $method = new \ReflectionMethod(DependencyAwareEdgeGenesisFoundryService::class, 'academyStageFor');
        $service = app(DependencyAwareEdgeGenesisFoundryService::class);
        $valid = ['protocol' => \App\Services\CausalStageMasteryDirectorService::PROTOCOL,
            'status' => 'controllable', 'target_stage' => 'setup', 'evidence_assessable' => true, 'semantic_effect_observed' => true,
            'candidate_counts' => ['setup' => 4], 'control_counts' => ['setup' => 3],
            'checks' => ['upstream_identity_preserved' => true, 'decision_identity_valid' => true,
                'parameter_changed' => true, 'target_transition_changed' => true, 'minimum_event_delta_reached' => true,
                'duplicate_behavior' => true, 'behavior_excessive' => true]];
        $this->assertSame('confirmation_specialist', $method->invoke($service, ['status' => 'assessed', 'assessment' => $valid]));
        $this->assertSame('market_cartographer', $method->invoke($service, ['target_stage' => 'entry', 'status' => 'assessed']));
        $this->assertSame('market_cartographer', $method->invoke($service, ['assessment' => [...$valid, 'status' => 'non_controlling_axis']]));
        $this->assertSame('market_cartographer', $method->invoke($service, ['assessment' => [...$valid,
            'checks' => ['upstream_identity_preserved' => false, 'decision_identity_valid' => true]]]));
        $this->assertSame('market_cartographer', $method->invoke($service, ['assessment' => [...$valid, 'candidate_counts' => ['setup' => 2]]]));
        $this->assertSame('execution_specialist', $method->invoke($service, ['assessment' => [...$valid, 'target_stage' => 'trigger',
            'candidate_counts' => ['trigger' => 4], 'control_counts' => ['trigger' => 3]]]));
        $this->assertSame('management_specialist', $method->invoke($service, ['assessment' => [...$valid, 'target_stage' => 'entry',
            'candidate_counts' => ['entry' => 4], 'control_counts' => ['entry' => 3]]]));
    }

    public function test_invalid_or_missing_source_never_admits_a_prospective_baseline(): void
    {
        [$agent] = $this->source();
        $method = new \ReflectionMethod(DependencyAwareEdgeGenesisFoundryService::class, 'academySourceAdmission');
        $service = app(DependencyAwareEdgeGenesisFoundryService::class);
        $this->assertSame('ACADEMY_EDGE_SOURCE_IDENTITY_INVALID', $method->invoke($service, $agent, [], false)['reason']);
        $this->assertSame('ACADEMY_IMMUTABLE_SOURCE_RUN_REQUIRED', $method->invoke($service, $agent, [], true)['reason']);
    }

    public function test_source_adapter_requires_exact_primary_ownership_parameters_and_original_diagnostic(): void
    {
        [$agent, $run, $original] = $this->source();
        // The durable-artifact service is exercised separately by the immutable
        // evidence and canonical handoff integration suites. This test isolates
        // Foundry's adapter: no alternate mutable projection may seed a trial.
        $evidence = \Mockery::mock(LabImmutableEvidenceService::class)->makePartial();
        $evidence->shouldReceive('learningEligibility')->andReturn(['complete' => true, 'reason_codes' => []]);
        $evidence->shouldReceive('latestArtifactPayload')->andReturn($original);
        $this->app->instance(LabImmutableEvidenceService::class, $evidence);
        $method = new \ReflectionMethod(DependencyAwareEdgeGenesisFoundryService::class, 'academySourceAdmission');
        $service = app(DependencyAwareEdgeGenesisFoundryService::class);
        $result = [...$original, 'evidence_run_id' => $run->run_id];
        $admission = $method->invoke($service, $agent, $result, true);
        $this->assertTrue($admission['eligible']);
        $this->assertFalse($admission['promotion_evidence']);
        $this->assertSame($run->code_hash, $admission['source_code_hash']);
        $this->assertSame(hash('sha256', 'python-source'), $admission['python_source_hash']);
        $this->assertSame('dual_runtime_source_identity_v1', $admission['source_identity_protocol']);
        $this->assertNotSame($admission['source_code_hash'], $admission['python_source_hash']);
        $changed = $result;
        $changed['edge_formation_academy_diagnostic']['oracle_opportunity_edge_r'] = .9;
        $this->assertSame('ACADEMY_SOURCE_PROJECTION_MISMATCH', $method->invoke($service, $agent, $changed, true)['reason']);
        $this->assertSame('ACADEMY_SOURCE_PROJECTION_MISMATCH', $method->invoke($service, $agent,
            [...$result, 'after_cost_expectancy_r' => 5.], true)['reason']);
        $run->update(['response_hash' => hash('sha256', 'forged-response')]);
        $this->assertSame('ACADEMY_SOURCE_RESPONSE_HASH_INVALID', $method->invoke($service, $agent, $result, true)['reason']);
        $run->update(['response_hash' => $evidence->hash($original)]);
        $agent->modelVersion->update(['parameters' => ['swing_lookback' => 61]]);
        $this->assertSame('ACADEMY_SOURCE_PARAMETERS_CHANGED', $method->invoke($service, $agent, $result, true)['reason']);
        $run->update(['status' => 'technical_error']);
        $this->assertSame('ACADEMY_IMMUTABLE_SOURCE_IDENTITY_MISMATCH', $method->invoke($service, $agent, $result, true)['reason']);
    }

    public function test_legacy_single_hash_is_not_relabelled_as_a_python_source_seal(): void
    {
        [$agent, $run, $original] = $this->source();
        unset($original['data_quality']);
        $responseHash = app(LabImmutableEvidenceService::class)->hash($original);
        $run->update(['response_hash' => $responseHash]);
        LabEvidenceArtifact::where('run_id', $run->run_id)->update(['sha256' => $responseHash, 'payload' => $original]);
        $evidence = \Mockery::mock(LabImmutableEvidenceService::class)->makePartial();
        $evidence->shouldReceive('learningEligibility')->andReturn(['complete' => true, 'reason_codes' => []]);
        $evidence->shouldReceive('latestArtifactPayload')->andReturn($original);
        $this->app->instance(LabImmutableEvidenceService::class, $evidence);
        $method = new \ReflectionMethod(DependencyAwareEdgeGenesisFoundryService::class, 'academySourceAdmission');
        $admission = $method->invoke(app(DependencyAwareEdgeGenesisFoundryService::class), $agent,
            [...$original, 'evidence_run_id' => $run->run_id], true);

        $this->assertTrue($admission['eligible']); // Original observation is preserved.
        $this->assertNull($admission['python_source_hash']); // Prospective guards cannot consume it.
        $this->assertNull($admission['source_identity_protocol']);
        $this->assertFalse($admission['promotion_evidence']);
    }

    private function source(): array
    {
        $lab = AiLaboratory::create(['name' => 'Academy source adapter', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['confirmation_entry_mtf'], 'is_active' => true]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'edge_genesis', 'trigger_context' => [], 'population_size' => 1, 'status' => 'completed']);
        $model = ModelVersion::create(['name' => 'Academy source', 'strategy' => 'confirmation_entry_mtf', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => ['swing_lookback' => 60], 'metadata' => []]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'confirmation_entry_mtf',
            'origin' => 'edge_genesis', 'parameter_diff' => [], 'lifecycle_status' => 'rejected']);
        $agent->load('modelVersion');
        $original = ['data_manifest' => ['sha256' => hash('sha256', 'source')],
            'execution_contract' => ['execution_hash' => hash('sha256', 'execution')],
            'data_quality' => ['decision_identity_receipt' => ['bindings' => [
                'source_identity_protocol' => 'dual_runtime_source_identity_v1',
                'full_runtime_source_hash' => hash('sha256', 'source-code'),
                'source_evaluator_hash' => hash('sha256', 'python-source'),
                'python_source_hash' => hash('sha256', 'python-source')]]],
            'edge_formation_academy_diagnostic' => ['oracle_opportunity_edge_r' => .6], 'after_cost_expectancy_r' => .2];
        $run = LabEvaluationRun::create(['run_id' => (string) \Illuminate\Support\Str::uuid(),
            'lab_agent_id' => $agent->id, 'model_version_id' => $model->id, 'lab_generation_id' => $generation->id,
            'phase' => 'full_validation', 'mode' => 'full', 'attempt' => 1, 'status' => 'completed',
            'parameter_hash' => app(LabImmutableEvidenceService::class)->parameterHash($agent), 'code_hash' => hash('sha256', 'source-code'),
            'response_hash' => app(LabImmutableEvidenceService::class)->hash($original)]);
        LabEvidenceArtifact::create(['artifact_id' => (string) \Illuminate\Support\Str::uuid(), 'run_id' => $run->run_id,
            'lab_agent_id' => $agent->id, 'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'artifact_type' => 'evaluation_response', 'sha256' => $run->response_hash, 'payload' => $original,
            'metadata' => [], 'recorded_at' => now()]);

        return [$agent, $run, $original];
    }
}
