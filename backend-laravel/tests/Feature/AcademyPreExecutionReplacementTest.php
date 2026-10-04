<?php

namespace Tests\Feature;

require_once __DIR__.'/AcademyColdStartHandoffTest.php';

use App\Console\Commands\DispatchLabGeneration;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use App\Models\LabLifecycleEvent;
use App\Models\ResearchLoopDecision;
use App\Services\AcademyExperimentMaterializerService;
use App\Services\LabImmutableEvidenceService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;

/** Original technical history is immutable; every replacement is a new attempt. */
class AcademyPreExecutionReplacementTest extends AcademyColdStartHandoffTest
{
    private function unobservedIdentityFailure(): array
    {
        Queue::fake();
        [$source, $baseline] = (new \ReflectionMethod(AcademyColdStartHandoffTest::class, 'sourceAndData'))->invoke($this);
        $baseline->update(['parameters' => [...$baseline->parameters, 'setup_topology_policy' => 'pullback_rejection']]);
        $baseline = $baseline->fresh();
        $owner = app(AcademyExperimentMaterializerService::class);
        $proposal = $owner->proposal();
        $decision = $this->open($proposal, 'original');
        $prepared = $owner->prepareColdStart($decision);
        $this->assertSame('pending_canonical_admission', $prepared['status'], json_encode($prepared));
        $generation = LabGeneration::with('agents.modelVersion')->findOrFail($prepared['generation_id']);
        $quarantines = [];
        $guard = new \ReflectionMethod(DispatchLabGeneration::class, 'draftIntegrityViolations');
        foreach ($generation->agents as $agent) {
            // Model the OLD missing constructor seals even after the repaired
            // canonical constructor begins sealing new models correctly.
            $metadata = (array) $agent->modelVersion->metadata;
            unset($metadata['parameter_fingerprint'], $metadata['universal_genome']);
            $agent->modelVersion->update(['metadata' => $metadata]);
            $agent->load('modelVersion');
            $violations = $guard->invoke(app(DispatchLabGeneration::class), $agent, app(StrategyParameterSchemaService::class));
            $this->assertSame(['PARAMETER_FINGERPRINT_MISMATCH', 'UNIVERSAL_PARAMETERS_HASH_MISMATCH'], $violations);
            $agent->update(['lifecycle_status' => 'technical_quarantine']);
            $quarantines[] = ['agent_id' => $agent->id, 'violations' => $violations, 'promotion_evidence' => false];
            app(LabImmutableEvidenceService::class)->recordLifecycle($agent, 'draft_integrity_quarantine', [
                'reason_code' => 'DRAFT_IDENTITY_INTEGRITY_BREACH', 'violations' => $violations,
                'quality_verdict' => 'withheld', 'promotion_evidence' => false,
            ], 'screening', null, null, DispatchLabGeneration::class, null, 'draft', 'technical_quarantine');
        }
        $context = (array) $generation->trigger_context;
        $context['draft_integrity_quarantines'] = $quarantines;
        $generation->update(['status' => 'technical_quarantine', 'trigger_context' => $context]);
        $primary = $generation->agents->firstWhere('origin', 'academy_experiment');
        $settled = $owner->settleOutcome($primary->fresh());
        $this->assertSame('technical_quarantine', $settled['status']);
        $this->assertSame(0, LabEvaluationRun::where('lab_generation_id', $generation->id)->count());
        return [$owner, $generation->fresh('agents.modelVersion'), $prepared['trial_id'], $baseline, $proposal];
    }

    private function newSource(): void
    {
        $evidence = Mockery::mock(LabImmutableEvidenceService::class)->makePartial();
        $evidence->shouldReceive('codeHash')->andReturn(str_repeat('d', 64));
        app()->instance(LabImmutableEvidenceService::class, $evidence);
    }

    private function open(array $proposal, string $suffix): ResearchLoopDecision
    {
        return ResearchLoopDecision::create(['decision_key' => 'unobserved-academy-'.$suffix,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'action' => 'OPEN_ACADEMY_EXPERIMENT',
            'command' => 'trading:admit-academy-experiment', 'arguments' => ['trial' => 0], 'status' => 'running',
            'evidence_hash' => str_repeat('f', 64), 'reason_codes' => [], 'contract' => [],
            'evidence_snapshot' => ['academy_proposal' => $proposal]]);
    }

    public function test_one_changed_source_attempt_preserves_the_original_question_and_all_technical_history(): void
    {
        [$owner, $original, $trialId, $baseline, $oldProposal] = $this->unobservedIdentityFailure();
        $oldTrial = DB::table('edge_academy_trials')->find($trialId);
        $oldGeneration = $original->toArray();
        $this->assertSame('ACADEMY_COLD_START_SCOPE_BUDGET_EXHAUSTED', $owner->proposal()['reason']);
        $this->newSource();
        $proposal = $owner->proposal();
        $debugPreview = app(\App\Services\XauusdEdgeFormationAcademyService::class)->previewColdStartExperiment((array) $baseline->parameters);
        $debugTrialCompiled = app(\App\Services\AcademyExperimentContractCompilerService::class)->compile([
            'axis' => $debugPreview['axis'], 'arms' => json_decode($oldTrial->arms, true),
        ], (array) $baseline->parameters, ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5']);
        $this->assertEquals($debugPreview['compiled_contract'], $debugTrialCompiled);
        $this->assertEquals($debugPreview['event_density_contract'], json_decode($oldTrial->density_contract, true));
        foreach ($original->agents->where('origin', 'academy_experiment') as $member) {
            $this->assertSame(hash('sha256', json_encode($debugPreview['compiled_contract'])), data_get($member->modelVersion->metadata, 'academy_experiment.contract_hash'));
        }
        $this->assertSame('would_prepare_cold_start', $proposal['status'], json_encode($proposal));
        $this->assertSame($baseline->id, $proposal['baseline_model_version_id']);
        $this->assertSame($oldProposal['cold_start']['budget_scope'], $proposal['cold_start']['budget_scope']);
        $this->assertSame($oldProposal['cold_start']['preview_hash'], $proposal['cold_start']['preview_hash']);
        $this->assertSame($trialId, data_get($proposal, 'cold_start.technical_replacement.technical_retry_of_trial_id'));
        $this->assertFalse(data_get($proposal, 'cold_start.technical_replacement.scientific_question_budget_reset'));
        $decision = $this->open($proposal, 'replacement');
        $fresh = $owner->prepareColdStart($decision);
        $this->assertSame('pending_canonical_admission', $fresh['status'], json_encode($fresh));
        $this->assertNotSame($trialId, $fresh['trial_id']);
        $this->assertNotSame($original->id, $fresh['generation_id']);
        $this->assertSame(20, LabGeneration::findOrFail($fresh['generation_id'])->agents()->count());
        $this->assertSame(4, LabGeneration::findOrFail($fresh['generation_id'])->agents()->where('origin', 'academy_experiment')->count());
        $this->assertEquals($oldTrial, DB::table('edge_academy_trials')->find($trialId));
        $this->assertSame($oldGeneration, $original->fresh('agents.modelVersion')->toArray());
        $again = $owner->prepareColdStart($decision);
        $this->assertSame($fresh['generation_id'], $again['generation_id']);
        $this->assertTrue($again['reused']);
        $this->assertSame('ACADEMY_COLD_START_SCOPE_BUDGET_EXHAUSTED', $owner->coldStartProposal()['reason']);
        $this->assertDatabaseCount('edge_academy_trials', 2);
        $this->assertDatabaseCount('causal_stage_mastery_assessments', 0);
        $this->assertDatabaseCount('evolutionary_authority_ledgers', 0);
        $this->assertDatabaseHas('research_experiment_work_items', ['work_type' => 'academy_technical_quarantine', 'status' => 'blocked', 'attempts' => 0]);
        Queue::assertNothingPushed();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ineligibleReplacementChanges')]
    public function test_any_observed_result_unattested_failure_or_changed_question_blocks_replacement(string $change): void
    {
        [$owner, $generation, $trialId, $baseline] = $this->unobservedIdentityFailure();
        $this->newSource();
        $agent = $generation->agents->first();
        if ($change === 'run') LabEvaluationRun::create(['run_id' => 'observed-replacement', 'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id, 'model_version_id' => $agent->model_version_id, 'phase' => 'screening', 'mode' => 'test', 'attempt' => 1, 'status' => 'technical_error']);
        if ($change === 'artifact') LabEvidenceArtifact::create(['artifact_id' => 'orphan-observation', 'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id, 'artifact_type' => 'replay_result', 'sha256' => str_repeat('c', 64), 'byte_size' => 0, 'content_encoding' => 'json', 'recorded_at' => now()]);
        if ($change === 'missing_attestation') LabLifecycleEvent::where('lab_agent_id', $agent->id)->where('event_type', 'draft_integrity_quarantine')->delete();
        if ($change === 'other_error') {
            $context = $generation->trigger_context;
            $context['draft_integrity_quarantines'][0]['violations'][] = 'UNRELATED_COMPILER_FAILURE';
            $generation->update(['trigger_context' => $context]);
        }
        if ($change === 'screen_result') $agent->modelVersion->update(['metadata' => [...$agent->modelVersion->metadata, 'last_screen_result' => ['total_trades' => 0]]]);
        if ($change === 'changed_arm') $agent->modelVersion->update(['parameters' => [...$agent->modelVersion->parameters, 'max_chase_atr' => 1.1]]);
        if ($change === 'changed_baseline') $baseline->update(['parameters' => [...$baseline->parameters, 'max_chase_atr' => 1.1]]);
        if ($change === 'missing_agent') $agent->delete();
        if ($change === 'scientific_terminal') DB::table('edge_academy_trials')->where('id', $trialId)->update(['status' => 'settled_underpowered']);
        $this->assertSame('ACADEMY_COLD_START_SCOPE_BUDGET_EXHAUSTED', $owner->coldStartProposal()['reason'], $change);
        $this->assertDatabaseCount('edge_academy_trials', 1);
        Queue::assertNothingPushed();
    }

    public static function ineligibleReplacementChanges(): array
    {
        return array_map(fn ($value) => [$value], ['run', 'artifact', 'missing_attestation', 'other_error', 'screen_result',
            'changed_arm', 'changed_baseline', 'missing_agent', 'scientific_terminal']);
    }
}
