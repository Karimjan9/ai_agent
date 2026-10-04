<?php

namespace Tests\Feature;

require_once file_exists(__DIR__.'/AcademyPreExecutionReplacementTest.php')
    ? __DIR__.'/AcademyPreExecutionReplacementTest.php'
    : dirname(__DIR__, 2).'/backend-laravel/tests/Feature/AcademyPreExecutionReplacementTest.php';

use App\Jobs\EvaluateLabAgentJob;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ResearchLoopDecision;
use App\Services\AcademyExperimentMaterializerService;
use App\Services\AutonomousModeService;
use App\Services\LabImmutableEvidenceService;
use App\Services\OperatorApprovalService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Mockery;

/** Real 20-seat creation and immutable request/error owner; fixture-only, no market claim. */
class AcademyPreparationContainmentTest extends AcademyPreExecutionReplacementTest
{
    private array $checkpointPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->checkpointPaths as $path) File::delete($path);
        parent::tearDown();
    }

    private function preparationFailure(bool $activeKernel = false): array
    {
        [$owner, $original, $originalTrial, $baseline] = (new \ReflectionMethod(AcademyPreExecutionReplacementTest::class, 'unobservedIdentityFailure'))->invoke($this);
        (new \ReflectionMethod(AcademyPreExecutionReplacementTest::class, 'newSource'))->invoke($this);
        $proposal = $owner->proposal();
        $decision = (new \ReflectionMethod(AcademyPreExecutionReplacementTest::class, 'open'))->invoke($this, $proposal, 'preparation-second');
        $prepared = $owner->prepareColdStart($decision);
        $generation = LabGeneration::with('agents.modelVersion', 'laboratory')->findOrFail($prepared['generation_id']);
        $generation->agents()->update(['lifecycle_status' => 'queued']);
        $generation->update(['status' => 'screening']);
        $control = $generation->agents->first(fn ($a) => data_get($a->modelVersion->metadata, 'academy_experiment.arm_role') === 'frozen_control');
        $identity = (array) data_get($generation->trigger_context, 'prospective_source_identity');
        $request = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'evaluation_mode' => 'incremental',
            'dataset_path' => data_get($identity, 'mtf_bundle_manifest.streams.M5.path'), 'dataset_tail_rows' => null,
            'replay_dataset_hash' => $identity['mtf_bundle_hash'], 'execution_contract' => ['execution_hash' => $identity['execution_hash']],
            'strategies' => [['lab_agent_id' => $control->id, 'strategy' => $control->modelVersion->strategy,
                'version' => $control->modelVersion->version, 'parameters' => $control->modelVersion->parameters]]];
        $evidenceDisk = 'preparation_fixture_'.\Illuminate\Support\Str::uuid();
        Storage::fake($evidenceDisk);
        config()->set('services.lab_evidence.disk', $evidenceDisk);
        $ledger = app(LabImmutableEvidenceService::class);
        $run = LabEvaluationRun::create(['run_id' => (string) \Illuminate\Support\Str::uuid(),
            'lab_generation_id' => $generation->id, 'lab_agent_id' => $control->id, 'model_version_id' => $control->model_version_id,
            'phase' => 'screening', 'mode' => 'screen', 'attempt' => 1, 'status' => 'started', 'started_at' => now()->subSeconds(900),
            'code_hash' => $identity['source_evaluator_hash'], 'parameter_hash' => $ledger->parameterHash($control), 'data_hash' => $identity['mtf_bundle_hash']]);
        $ledger->attachRequest($run, $request, ['data_hash' => $identity['mtf_bundle_hash']]);
        $script = <<<'PY'
import hashlib,json,sys
from app.main import _write_replay_checkpoint,_replay_checkpoint_root
r=json.loads(sys.argv[1])
c=r['strategies'][0]
ph=hashlib.sha256(json.dumps(c['parameters'],sort_keys=True,separators=(',',':'),default=str).encode()).hexdigest()
key=str(c['lab_agent_id'])
candidate={'candidate_key':key,'strategy':c['strategy'],'version':c['version'],'parameters_hash':ph}
cohort={'symbol':r['symbol'],'timeframe':r['timeframe'],'candidates':[candidate],'dataset_path':r.get('dataset_path'),'dataset_tail_rows':r.get('dataset_tail_rows')}
h=hashlib.sha256(json.dumps(cohort,sort_keys=True,separators=(',',':'),default=str).encode()).hexdigest()
_write_replay_checkpoint(h,'snapshot_loaded',{'completed_candidates':[],'pending_candidates':[key]},source_rows=200000,foundation_rows=0)
print(str(_replay_checkpoint_root()/(h+'.json')))
PY;
        $producer = new Process(['python', '-c', $script, json_encode($request)], base_path('../ai-service-python'), timeout: 90);
        $producer->mustRun();
        $checkpoint = trim($producer->getOutput());
        $this->checkpointPaths[] = $checkpoint;
        $ledger->finishRun($run, 'technical_error', null, [], ['reason_code' => 'EVALUATOR_TRANSPORT_ERROR',
            'quality_verdict' => 'withheld', 'promotion_evidence' => false],
            new \RuntimeException('{"detail":"Bounded AI replay exceeded 900s; strategy verdict withheld."}'));
        $control->update(['lifecycle_status' => 'evaluation_error']);
        $batch = Bus::batch($generation->agents->map(fn ($a) => new EvaluateLabAgentJob($a->id, 'XAUUSD', 'screen'))->all())
            ->name('XAUUSD H1 Lab G'.$generation->generation.' screening')->allowFailures()->dispatch();
        $generation->refresh();
        $generation->update(['trigger_context' => [...(array) $generation->trigger_context, 'queue_batches' => ['screening' => [$batch->id]]]]);
        if ($activeKernel) {
            $kernel = $generation->agents->first(fn ($a) => data_get($a->modelVersion->metadata, 'control_pair_contract.role') === 'control');
            $active = LabEvaluationRun::create(['run_id' => (string) \Illuminate\Support\Str::uuid(),
                'lab_generation_id' => $generation->id, 'lab_agent_id' => $kernel->id, 'model_version_id' => $kernel->model_version_id,
                'phase' => 'screening', 'mode' => 'screen', 'attempt' => 1, 'status' => 'started', 'started_at' => now(),
                'code_hash' => $identity['source_evaluator_hash'], 'parameter_hash' => $ledger->parameterHash($kernel)]);
            $kernel->update(['lifecycle_status' => 'screening']);
        }
        app(AutonomousModeService::class)->pause('XAUUSD', 'H1', 'fixture', 'bounded preparation fault');
        return compact('owner', 'original', 'originalTrial', 'baseline', 'generation', 'control', 'run', 'checkpoint', 'batch')
            + ['active' => $active ?? null, 'trial_id' => $prepared['trial_id']];
    }

    private function approve(array $fixture): array
    {
        return app(OperatorApprovalService::class)->requireForApply('academy-preparation-containment', 'fixture', 'output-preserving preparation repair',
            ['generation_id' => $fixture['generation']->id, 'trial_id' => $fixture['trial_id']]);
    }

    public function test_preparation_containment_commits_before_scoped_cancellation_and_waits_for_active_drain(): void
    {
        $f = $this->preparationFailure(true);
        $before = $f['run']->fresh()->toArray();
        $sourceBefore = $f['baseline']->fresh()->toArray();
        $preview = $f['owner']->containPreparation($f['trial_id'], $f['generation']->id, $f['checkpoint']);
        $this->assertSame('would_contain_preparation', $preview['status'], json_encode($preview));
        $this->assertFalse(Bus::findBatch($f['batch']->id)->cancelled());
        $this->assertNull(data_get($f['generation']->fresh()->trigger_context, 'academy_preparation_containment'));
        $apply = $f['owner']->containPreparation($f['trial_id'], $f['generation']->id, $f['checkpoint'], true, false, $this->approve($f));
        $this->assertSame('containment_draining', $apply['status'], json_encode($apply));
        $this->assertTrue(Bus::findBatch($f['batch']->id)->cancelled());
        $record = data_get($f['generation']->fresh()->trigger_context, 'academy_preparation_containment');
        $this->assertSame('draining', $record['status']);
        $this->assertFalse($record['scientific_question_budget_reset']);
        $this->assertSame($before, $f['run']->fresh()->toArray());
        $this->assertSame('started', $f['active']->fresh()->status);
        $this->assertSame('containment_draining', $f['owner']->containPreparation($f['trial_id'], $f['generation']->id, null, false, true)['status']);
        $this->assertSame(1, \App\Models\LabEvidenceArtifact::where('artifact_type', 'academy_preparation_checkpoint')->count());
        $f['owner']->containPreparation($f['trial_id'], $f['generation']->id, null, true, false, $this->approve($f));
        $this->assertSame($record, data_get($f['generation']->fresh()->trigger_context, 'academy_preparation_containment'));
        app(LabImmutableEvidenceService::class)->finishRun($f['active'], 'technical_error', null, [], [],
            new \RuntimeException('{"detail":"Bounded AI replay exceeded 900s; strategy verdict withheld."}'));
        $final = $f['owner']->containPreparation($f['trial_id'], $f['generation']->id, null, true, true, $this->approve($f));
        $this->assertSame('contained_terminal', $final['status'], json_encode($final));
        $this->assertSame(20, $f['generation']->agents()->where('lifecycle_status', 'technical_quarantine')->count());
        $this->assertSame('technical_quarantine', DB::table('edge_academy_trials')->find($f['trial_id'])->status);
        $this->assertSame('technical_quarantine', $f['generation']->fresh()->status);
        $classifier = app(\App\Services\TechnicalFailureClassifierService::class);
        foreach ($f['generation']->fresh('agents.modelVersion')->agents as $member) {
            $disposition = $classifier->forAgent($member);
            $this->assertSame('IMMUTABLE_ACADEMY_PREPARATION_CONTAINMENT', $disposition['reason_code'], json_encode($disposition));
            $this->assertFalse($disposition['blocks_global_generation']);
            $this->assertFalse($disposition['replacement_authorized']);
        }
        $this->assertSame($before, $f['run']->fresh()->toArray());
        $this->assertSame($sourceBefore, $f['baseline']->fresh()->toArray());
        $this->assertDatabaseCount('causal_stage_mastery_assessments', 0);
        $this->assertDatabaseCount('evolutionary_authority_ledgers', 0);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forgeries')]
    public function test_preparation_containment_refuses_observed_result_wrong_seal_checkpoint_or_foreign_batch(string $change): void
    {
        $f = $this->preparationFailure();
        if ($change === 'science') $f['run']->update(['status' => 'completed']);
        if ($change === 'source') $f['run']->update(['code_hash' => str_repeat('0', 64)]);
        if ($change === 'parameter') $f['control']->modelVersion->update(['parameters' => [...$f['control']->modelVersion->parameters, 'transition_wait_candles' => 99]]);
        if ($change === 'batch') DB::table('job_batches')->where('id', $f['batch']->id)->update(['name' => 'foreign generation']);
        if ($change === 'checkpoint') {
            $value = json_decode(File::get($f['checkpoint']), true);
            $value['stage'] = 'features_ready';
            File::put($f['checkpoint'], json_encode($value));
        }
        if ($change === 'question') {
            $context = (array) $f['generation']->fresh()->trigger_context;
            data_set($context, 'compiled_contract.arms.0.parameter_hash', str_repeat('0', 64));
            $f['generation']->update(['trigger_context' => $context]);
        }
        $result = $f['owner']->containPreparation($f['trial_id'], $f['generation']->id, $f['checkpoint'], true, false, $this->approve($f));
        $this->assertSame('blocked', $result['status'], json_encode($result));
        $this->assertFalse(Bus::findBatch($f['batch']->id)->cancelled());
        $this->assertNull(data_get($f['generation']->fresh()->trigger_context, 'academy_preparation_containment'));
    }

    public static function forgeries(): array
    {
        return array_map(fn ($v) => [$v], ['science', 'source', 'parameter', 'batch', 'checkpoint', 'question']);
    }

    private function containedPreparation(): array
    {
        $f = $this->preparationFailure();
        $recorded = $f['owner']->containPreparation($f['trial_id'], $f['generation']->id, $f['checkpoint'], true, false, $this->approve($f));
        $this->assertSame('containment_draining', $recorded['status'], json_encode($recorded));
        $closed = $f['owner']->containPreparation($f['trial_id'], $f['generation']->id, null, true, true, $this->approve($f));
        $this->assertSame('contained_terminal', $closed['status'], json_encode($closed));
        return $f;
    }

    private function repairedPreparationSource(): void
    {
        $evidence = Mockery::mock(LabImmutableEvidenceService::class)->makePartial();
        $evidence->shouldReceive('codeHash')->andReturn(str_repeat('c', 64));
        app()->instance(LabImmutableEvidenceService::class, $evidence);
        $release = Mockery::mock(\App\Services\ResearchReleaseSealService::class)->makePartial();
        $release->shouldReceive('pythonHash')->andReturn(str_repeat('a', 64));
        app()->instance(\App\Services\ResearchReleaseSealService::class, $release);
    }

    public function test_preparation_repair_has_one_changed_source_attempt_same_question_and_no_budget_reset(): void
    {
        $f = $this->containedPreparation();
        $before = $f['generation']->fresh('agents.modelVersion')->toArray();
        $oldRun = $f['run']->fresh()->toArray();
        $oldTrial = DB::table('edge_academy_trials')->find($f['trial_id']);
        $this->assertSame('blocked', $f['owner']->coldStartProposal()['status']);
        $this->repairedPreparationSource();
        $proposal = $f['owner']->coldStartProposal();
        $this->assertSame('would_prepare_cold_start', $proposal['status'], json_encode($proposal));
        $this->assertSame($f['baseline']->id, $proposal['baseline_model_version_id']);
        $this->assertSame(data_get($f['generation']->trigger_context, 'prospective_source_identity.cold_start.budget_scope'), $proposal['cold_start']['budget_scope']);
        $this->assertSame($f['trial_id'], data_get($proposal, 'cold_start.preparation_replacement.technical_retry_of_trial_id'));
        $this->assertFalse(data_get($proposal, 'cold_start.preparation_replacement.scientific_question_budget_reset'));
        config()->set('services.lab_selection.learning_velocity_enabled', true);
        $velocity = app(\App\Services\LearningVelocityGateService::class)->inspect('XAUUSD', 'H1');
        $this->assertSame(0, $velocity['technical_recovery_agents'], json_encode($velocity));
        $this->assertNotSame('blocked_technical_recovery', $velocity['status']);
        ResearchLoopDecision::query()->update(['status' => 'completed']);
        config()->set('services.xauusd_organism.historical_research_until_champion', false);
        $director = Mockery::mock(\App\Services\AutonomousLearningProgressDirectorService::class);
        $director->shouldReceive('advance')->andReturn(['status' => 'blocked', 'reason' => 'EDGE_HYPOTHESIS_COMPILER_BLOCKED']);
        app()->instance(\App\Services\AutonomousLearningProgressDirectorService::class, $director);
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'bounded contained preparation repair');
        $selected = app(\App\Services\ResearchLoopArbiterService::class)->tick('XAUUSD', 'H1', true);
        $this->assertSame('OPEN_ACADEMY_EXPERIMENT', $selected['action'], json_encode($selected));
        $this->assertSame(0, $selected['arguments']['trial']);
        $decision = (new \ReflectionMethod(AcademyPreExecutionReplacementTest::class, 'open'))->invoke($this, $proposal, 'preparation-third');
        $fresh = $f['owner']->prepareColdStart($decision);
        $this->assertSame('pending_canonical_admission', $fresh['status'], json_encode($fresh));
        $next = LabGeneration::with('agents.modelVersion')->findOrFail($fresh['generation_id']);
        $this->assertSame(20, $next->agents->count());
        $this->assertSame(4, $next->agents->where('origin', 'academy_experiment')->count());
        $this->assertNotSame($f['generation']->id, $next->id);
        $this->assertSame(data_get($f['generation']->trigger_context, 'compiled_contract'), data_get($next->trigger_context, 'compiled_contract'));
        $oldPairs = (array) data_get($f['generation']->trigger_context, 'causal_compounding_kernel.pairs');
        $newPairs = (array) data_get($next->trigger_context, 'causal_compounding_kernel.pairs');
        $this->assertCount(count($oldPairs), $newPairs);
        foreach ($oldPairs as $index => $oldPair) {
            $newPair = $newPairs[$index];
            $this->assertSame($oldPair['gene'], $newPair['gene']);
            $this->assertSame($oldPair['old_value'], $newPair['old_value']);
            $this->assertSame($oldPair['tested_value'], $newPair['tested_value']);
            foreach (['control_agent_id', 'candidate_agent_id'] as $role) {
                $old = $f['generation']->agents->firstWhere('id', $oldPair[$role]);
                $new = $next->agents->firstWhere('id', $newPair[$role]);
                $this->assertNotSame($old->id, $new->id);
                $this->assertEquals($old->modelVersion->parameters, $new->modelVersion->parameters);
                $this->assertEquals($old->parameter_diff, $new->parameter_diff);
            }
        }
        $this->assertSame($before, $f['generation']->fresh('agents.modelVersion')->toArray());
        $this->assertSame($oldRun, $f['run']->fresh()->toArray());
        $this->assertEquals($oldTrial, DB::table('edge_academy_trials')->find($f['trial_id']));
        $again = $f['owner']->prepareColdStart($decision);
        $this->assertSame($next->id, $again['generation_id']);
        $this->assertTrue($again['reused']);
        $this->assertSame('ACADEMY_PREPARATION_REPAIR_ALLOWANCE_EXHAUSTED', $f['owner']->coldStartProposal()['reason']);
        $this->assertDatabaseCount('edge_academy_trials', 3);
        $this->assertDatabaseCount('causal_stage_mastery_assessments', 0);
        $this->assertDatabaseCount('evolutionary_authority_ledgers', 0);
        // Only the original admitted batch exists; preparation itself never
        // bypasses the canonical dispatcher to publish replacement replay.
        Queue::assertCount(20);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('repairForgeries')]
    public function test_preparation_repair_refuses_changed_source_history_baseline_or_scientific_evidence(string $change): void
    {
        $f = $this->containedPreparation();
        $this->repairedPreparationSource();
        if ($change === 'science') $f['run']->update(['status' => 'completed']);
        if ($change === 'source') $f['run']->update(['code_hash' => str_repeat('0', 64)]);
        if ($change === 'baseline') $f['baseline']->update(['parameters' => [...$f['baseline']->parameters, 'max_chase_atr' => 1.1]]);
        if ($change === 'kernel') {
            $kernel = $f['generation']->agents->first(fn ($a) => data_get($a->modelVersion->metadata, 'control_pair_contract.role') === 'candidate');
            $kernel->modelVersion->update(['parameters' => [...$kernel->modelVersion->parameters, 'transition_wait_candles' => 99]]);
        }
        if ($change === 'disposition') {
            $context = (array) $f['generation']->fresh()->trigger_context;
            data_set($context, 'academy_preparation_containment.run_evidence_hash', str_repeat('0', 64));
            $f['generation']->update(['trigger_context' => $context]);
        }
        if ($change === 'data') {
            $context = (array) $f['generation']->fresh()->trigger_context;
            data_set($context, 'data_hash', str_repeat('0', 64));
            $f['generation']->update(['trigger_context' => $context]);
        }
        if ($change === 'approval') \App\Models\SystemEvent::where('event_type', 'learning_protocol_operator_approval')->delete();
        if ($change === 'manifest') {
            $context = (array) $f['generation']->fresh()->trigger_context;
            data_set($context, 'mtf_bundle_manifest.streams.M5.sha256', str_repeat('0', 64));
            $f['generation']->update(['trigger_context' => $context]);
        }
        $proposal = $f['owner']->coldStartProposal();
        $this->assertSame('blocked', $proposal['status'], json_encode($proposal));
        $classification = app(\App\Services\TechnicalFailureClassifierService::class)->forAgent($f['control']->fresh());
        $this->assertNotSame('IMMUTABLE_ACADEMY_PREPARATION_CONTAINMENT', $classification['reason_code'], json_encode($classification));
        $this->assertDatabaseCount('edge_academy_trials', 2);
        Queue::assertCount(20);
    }

    public static function repairForgeries(): array
    {
        return array_map(fn ($value) => [$value], ['science', 'source', 'baseline', 'kernel', 'disposition', 'data', 'approval', 'manifest']);
    }
}
