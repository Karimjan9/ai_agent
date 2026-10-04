<?php

namespace Tests\Feature;

require_once __DIR__.'/AcademyCleanDiscoveryHandoffTest.php';

use App\Jobs\EvaluateLabScreeningBatchJob;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use App\Models\ResearchLoopDecision;
use App\Services\AcademyExperimentContractCompilerService;
use App\Services\AcademyExperimentMaterializerService;
use App\Services\FrozenControlScreeningAdmissionService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabQueueJobInspector;
use App\Services\ProspectiveRepairProbeWindowService;
use App\Services\ResearchReleaseSealService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/** Real twenty-seat owners and immutable pre-execution envelopes; no market output is simulated. */
class AcademyMtfValidatorReplacementTest extends TestCase
{
    use RefreshDatabase;

    private ?AcademyCleanDiscoveryHandoffTest $dependencyFixture = null;

    protected function tearDown(): void
    {
        if ($this->dependencyFixture) {
            foreach ((new \ReflectionProperty($this->dependencyFixture, 'fixtureDirectories'))->getValue($this->dependencyFixture) as $path) File::deleteDirectory($path);
        }
        parent::tearDown();
    }

    private function open(array $proposal, string $suffix): ResearchLoopDecision
    {
        return ResearchLoopDecision::create(['decision_key' => 'mtf-validator-'.$suffix,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'action' => 'OPEN_ACADEMY_EXPERIMENT',
            'command' => 'trading:admit-academy-experiment', 'arguments' => ['trial' => 0], 'status' => 'running',
            'evidence_hash' => str_repeat('f', 64), 'reason_codes' => [], 'contract' => [],
            'evidence_snapshot' => ['academy_proposal' => $proposal]]);
    }

    private function failedValidator(bool $duplicateAttempt = false): array
    {
        $rows = [];
        for ($i = 0; $i < 15512; $i++) $rows[] = ['time' => gmdate('Y-m-d\TH:i:s\Z', strtotime('2025-09-01T00:00:00Z') + $i * 300)];
        $temporaryProbe = app(ProspectiveRepairProbeWindowService::class)->seal($rows, str_repeat('b', 64), str_repeat('e', 64), 'fixture', 15000, 512);
        $scope = ['protocol' => 'prospective_clean_discovery_scope_v1', 'parent_dataset_key' => 'fixture_corrected_parent',
            'parent_fork_price_sha256' => str_repeat('a', 64), 'parent_economic_rows_sha256' => str_repeat('c', 64),
            'selected_price_sha256' => str_repeat('d', 64), 'scope_hash' => str_repeat('7', 64),
            'calendar' => [...\Illuminate\Support\Arr::only($temporaryProbe, ['loaded_rows', 'evaluated_rows', 'warmup_rows',
                'loaded_start', 'loaded_end', 'evaluated_start', 'evaluated_end', 'evaluated_month_counts']),
                'selected_unexpected_gaps' => 0, 'full_source_unexpected_gaps' => 24],
            'independent_evidence' => false, 'full_validation_eligible' => false, 'paper_eligible' => false, 'promotion_evidence' => false];
        $this->dependencyFixture = new AcademyCleanDiscoveryHandoffTest('test_prepared_twenty_seat_discovery_reuses_exact_bundle_and_preserves_parent_budget');
        [$source, $baseline, $manifest] = (new \ReflectionMethod($this->dependencyFixture, 'configured'))->invoke($this->dependencyFixture, $scope);
        $baseline->update(['parameters' => json_decode(File::get(__DIR__.'/../Support/academy_confirmation_source.json'), true)['parameters']]);
        $baseline->refresh();
        $release = Mockery::mock(ResearchReleaseSealService::class)->makePartial();
        $release->shouldReceive('pythonHash')->andReturn(str_repeat('9', 64));
        $release->shouldReceive('currentSourceArtifact')->andReturn(null);
        app()->instance(ResearchReleaseSealService::class, $release);
        $queue = Mockery::mock(LabQueueJobInspector::class)->makePartial();
        $queue->shouldReceive('generationQueueBacklog')->andReturn(['available' => true, 'total' => 0]);
        app()->instance(LabQueueJobInspector::class, $queue);
        $owner = app(AcademyExperimentMaterializerService::class); $originalProposal = $owner->proposal();
        $this->assertSame('would_prepare_cold_start', $originalProposal['status']);
        $prepared = $owner->prepareColdStart($this->open($originalProposal, 'original'));
        $this->assertSame('pending_canonical_admission', $prepared['status'], json_encode($prepared));
        $generation = LabGeneration::with('agents.modelVersion')->findOrFail($prepared['generation_id']);
        $this->assertSame(4, $generation->agents->where('origin', 'academy_experiment')->count());
        $generation = $release->seal($generation)->fresh('agents.modelVersion');
        app()->instance('research.worker_boot_source_hash', str_repeat('e', 64));
        $evidenceDisk = 'validator_fixture_'.Str::uuid(); Storage::fake($evidenceDisk); config()->set('services.lab_evidence.disk', $evidenceDisk);
        $ledger = app(LabImmutableEvidenceService::class);
        $identity = (array) data_get($generation->trigger_context, 'prospective_source_identity');
        $probe = app(ProspectiveRepairProbeWindowService::class)->seal($rows, $identity['mtf_bundle_hash'], $identity['execution_hash'],
            'academy_clean_discovery:'.$identity['mtf_bundle_hash'].':'.$scope['scope_hash'], 15000, 512);
        $controlAdmission = app(FrozenControlScreeningAdmissionService::class);
        $controls = $generation->agents->filter(fn ($a) => $controlAdmission->isControl($a));
        $this->assertCount(9, $controls);
        foreach ($controls as $a) {
            $request = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'evaluation_mode' => 'incremental', 'dataset_tail_rows' => null,
                'dataset_path' => $manifest['streams']['M5']['path'], 'replay_dataset_hash' => $identity['mtf_bundle_hash'],
                'execution_contract' => $identity['execution_contract'], 'mtf_snapshot_manifest' => $manifest,
                'mtf_pilot' => ['enabled' => true, 'activation_status' => 'execution_stream_bound'],
                'policy_context' => ['prospective_probe_window' => $probe, 'prospective_clean_discovery_scope' => $scope],
                'strategies' => [['lab_agent_id' => $a->id, 'strategy' => $a->modelVersion->strategy, 'version' => $a->modelVersion->version,
                    'base_strategy' => 'confirmation_entry_mtf_v1', 'parameters' => $a->modelVersion->parameters]]];
            $attempts = $duplicateAttempt && $a->id === $controls->first()->id ? 2 : 1;
            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                $run = $ledger->beginRun($a, 'screening', 'screen', ['attempt' => $attempt, 'data_hash' => $identity['mtf_bundle_hash']]);
                $request = $release->bindRequest($run, $request);
                $ledger->attachRequest($run, $request, ['data_hash' => $identity['mtf_bundle_hash']]);
                $ledger->finishRun($run, 'technical_error', null, [], ['reason_code' => 'EVALUATOR_TRANSPORT_ERROR',
                    'quality_verdict' => 'withheld', 'promotion_evidence' => false], new \RuntimeException('{"detail":"AUTONOMOUS_MTF_MANIFEST_INVALID"}'));
            }
            $a->update(['lifecycle_status' => 'technical_quarantine']);
        }
        // Use the actual batch admission owner to close the eleven dependent
        // candidates. It creates no evaluator run or invented science receipt.
        $dependents = $generation->agents->reject(fn ($a) => $controlAdmission->isControl($a));
        $generation->agents()->whereIn('id', $dependents->pluck('id'))->update(['lifecycle_status' => 'queued']);
        foreach ($dependents->pluck('id')->chunk(6) as $ids) {
            (new EvaluateLabScreeningBatchJob($ids->values()->all(), 'XAUUSD', $generation->id))->handle(
                app(\App\Services\LabAgentEvaluationService::class), $controlAdmission, app(\App\Services\LearningTechnicalCircuitBreakerService::class));
        }
        $generation->update(['status' => 'technical_quarantine', 'completed_at' => now()]);
        $generation->refresh()->load('agents.modelVersion');
        $this->assertSame(20, $generation->agents->where('lifecycle_status', 'technical_quarantine')->count());
        $settled = $owner->settleOutcome($generation->agents->firstWhere('origin', 'academy_experiment'));
        $this->assertSame('technical_quarantine', $settled['status'], json_encode($settled));
        return compact('owner', 'generation', 'prepared', 'baseline', 'originalProposal', 'probe', 'manifest');
    }

    private function changedSource(): void
    {
        $ledger = Mockery::mock(LabImmutableEvidenceService::class)->makePartial(); $ledger->shouldReceive('codeHash')->andReturn(str_repeat('d', 64));
        app()->instance(LabImmutableEvidenceService::class, $ledger);
        $release = Mockery::mock(ResearchReleaseSealService::class)->makePartial(); $release->shouldReceive('pythonHash')->andReturn(str_repeat('8', 64));
        $release->shouldReceive('currentSourceArtifact')->andReturn(null); app()->instance(ResearchReleaseSealService::class, $release);
    }

    public function test_one_native_validator_repair_preserves_exact_twenty_vectors_and_cannot_chain(): void
    {
        $f = $this->failedValidator(true); $this->changedSource();
        $before = $f['generation']->fresh('agents.modelVersion')->toArray();
        $runsBefore = LabEvaluationRun::orderBy('id')->get()->toArray();
        $oldTrial = DB::table('edge_academy_trials')->find($f['prepared']['trial_id']);
        $proposal = $f['owner']->proposal();
        $this->assertSame('would_prepare_cold_start', $proposal['status'], json_encode($proposal));
        $replacement = $proposal['cold_start']['validator_replacement'];
        $this->assertSame(AcademyExperimentMaterializerService::VALIDATOR_REPLACEMENT_PROTOCOL, $replacement['protocol']);
        $this->assertCount(11, $replacement['unexecuted_dependent_arms']);
        $this->assertSame($f['originalProposal']['cold_start']['budget_scope'], $proposal['cold_start']['budget_scope']);
        $this->assertSame($f['originalProposal']['cold_start']['key'], $replacement['original_question_key']);
        $this->assertFalse($replacement['scientific_question_budget_reset']);
        $decision = $this->open($proposal, 'replacement'); $fresh = $f['owner']->prepareColdStart($decision);
        $this->assertSame('pending_canonical_admission', $fresh['status'], json_encode($fresh));
        $newGeneration = LabGeneration::with('agents.modelVersion')->findOrFail($fresh['generation_id']);
        $compiler = app(AcademyExperimentContractCompilerService::class);
        $vectors = fn ($g) => $g->agents->map(fn ($a) => [$a->origin,
            $compiler->parameterHash((array) $a->modelVersion->parameters), $a->parameter_diff])->values()->all();
        $this->assertSame($vectors($f['generation']), $vectors($newGeneration));
        $this->assertSame(20, $newGeneration->agents->count());
        $this->assertSame([], array_intersect($f['generation']->agents->pluck('id')->all(), $newGeneration->agents->pluck('id')->all()));
        $this->assertSame($f['manifest'], data_get($newGeneration->trigger_context, 'mtf_bundle_manifest'));
        $this->assertSame($before, $f['generation']->fresh('agents.modelVersion')->toArray());
        $this->assertSame($runsBefore, LabEvaluationRun::orderBy('id')->get()->toArray());
        $this->assertEquals($oldTrial, DB::table('edge_academy_trials')->find($f['prepared']['trial_id']));
        $this->assertSame($fresh['generation_id'], $f['owner']->prepareColdStart($decision)['generation_id']);
        $this->assertSame('ACADEMY_VALIDATOR_REPLACEMENT_ALLOWANCE_EXHAUSTED', $f['owner']->coldStartProposal()['reason']);
        $newGeneration->update(['trigger_context' => [...$newGeneration->trigger_context,
            'academy_preparation_containment' => ['protocol' => AcademyExperimentMaterializerService::PREPARATION_CONTAINMENT_PROTOCOL, 'status' => 'terminal']]]);
        $this->assertSame('ACADEMY_VALIDATOR_REPLACEMENT_ALLOWANCE_EXHAUSTED', $f['owner']->coldStartProposal()['reason']);
        $this->assertDatabaseCount('edge_academy_trials', 2);
        $this->assertDatabaseCount('causal_stage_mastery_assessments', 0);
        $this->assertDatabaseCount('evolutionary_authority_ledgers', 0);
        Queue::assertNothingPushed();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidEvidence')]
    public function test_observed_missing_or_unattested_program_never_receives_a_replacement(string $change): void
    {
        $f = $this->failedValidator(); $this->changedSource();
        $run = LabEvaluationRun::where('lab_generation_id', $f['generation']->id)->first();
        $dependent = $f['generation']->agents->first(fn ($a) => ! app(FrozenControlScreeningAdmissionService::class)->isControl($a));
        if ($change === 'other_error') $run->update(['error_message' => '{"detail":"SOME_OTHER_FAILURE"}']);
        if ($change === 'wrong_source') $run->update(['code_hash' => str_repeat('0', 64)]);
        if ($change === 'wrong_worker') $run->update(['metadata' => [...$run->metadata, 'worker_boot_source_hash' => str_repeat('0', 64)]]);
        if ($change === 'completed') $run->update(['status' => 'completed']);
        if ($change === 'wrong_generation') $run->update(['lab_generation_id' => $f['generation']->id - 1]);
        if ($change === 'missing_arm') $dependent->delete();
        if ($change === 'unattested_dependent') $dependent->update(['decision_reason' => 'some other quarantine']);
        if ($change === 'changed_arm') $dependent->modelVersion->update(['parameters' => [...$dependent->modelVersion->parameters, 'max_chase_atr' => 1.1]]);
        if ($change === 'changed_baseline') $f['baseline']->update(['parameters' => [...$f['baseline']->parameters, 'max_chase_atr' => 1.1]]);
        if ($change === 'observed_projection') $dependent->update(['profit_factor' => 0]);
        if ($change === 'observed_run_metric') $run->update(['metrics' => ['profit_factor' => 0]]);
        if ($change === 'missing_identity') LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'model_runtime_identity')->delete();
        if ($change === 'missing_request') LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_request')->delete();
        if ($change === 'extra_scientific_artifact') LabEvidenceArtifact::create(['artifact_id' => (string) Str::uuid(),
            'lab_agent_id' => $dependent->id, 'artifact_type' => 'decision_trace', 'sha256' => str_repeat('c', 64),
            'byte_size' => 0, 'content_encoding' => 'json', 'recorded_at' => now()]);
        if ($change === 'missing_control_run') {
            $ledger = app(LabImmutableEvidenceService::class);
            LabEvidenceArtifact::where('run_id', $run->run_id)->delete(); $run->delete();
        }
        if ($change === 'swapped_control') {
            $kernelCandidate = $f['generation']->agents->first(fn ($a) => data_get($a->modelVersion->metadata, 'control_pair_contract.role') === 'candidate');
            $otherControl = $f['generation']->agents->first(fn ($a) => app(FrozenControlScreeningAdmissionService::class)->isControl($a)
                && (int) $a->id !== (int) data_get($kernelCandidate->modelVersion->metadata, 'control_pair_contract.control_agent_id'));
            $metadata = $kernelCandidate->modelVersion->metadata;
            data_set($metadata, 'control_pair_contract.control_agent_id', $otherControl->id);
            $kernelCandidate->modelVersion->update(['metadata' => $metadata]);
        }
        if ($change === 'extra_scientific_response') {
            $ledger = app(LabImmutableEvidenceService::class);
            $artifact = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_response')->first();
            $payload = $ledger->readArtifactPayload($artifact); $payload['incremental_survival'] = ['evaluated_rows' => 15000];
            $artifact->delete(); $newArtifact = $ledger->recordArtifact($run, 'evaluation_response', $payload);
            $run->update(['response_hash' => $newArtifact->sha256]);
        }
        if ($change === 'wrong_passport_protocol') {
            $row = DB::table('edge_academy_trials')->find($f['prepared']['trial_id']);
            $passport = DB::table('edge_academy_passports')->find($row->edge_academy_passport_id);
            $frozen = json_decode($passport->frozen_upstream_contract, true); $frozen['protocol'] = 'legacy_claim';
            DB::table('edge_academy_passports')->where('id', $passport->id)->update(['frozen_upstream_contract' => json_encode($frozen)]);
        }
        if ($change === 'inline_response') {
            $ledger = app(LabImmutableEvidenceService::class);
            $artifact = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_response')->first();
            $payload = $ledger->readArtifactPayload($artifact);
            $artifact->update(['payload' => $payload, 'storage_path' => null, 'content_encoding' => 'json', 'metadata' => []]);
        }
        if ($change === 'unchanged_python') {
            $release = Mockery::mock(ResearchReleaseSealService::class)->makePartial(); $release->shouldReceive('pythonHash')->andReturn(str_repeat('9', 64));
            app()->instance(ResearchReleaseSealService::class, $release);
        }
        if ($change === 'active') $run->update(['status' => 'started', 'finished_at' => null]);
        $proposal = $f['owner']->coldStartProposal();
        $this->assertSame('blocked', $proposal['status'], $change.' '.json_encode($proposal));
        $this->assertArrayNotHasKey('validator_replacement', $proposal['cold_start'] ?? []);
        $this->assertDatabaseCount('edge_academy_trials', 1); Queue::assertNothingPushed();
    }

    public static function invalidEvidence(): array
    {
        return array_map(fn ($v) => [$v], ['other_error', 'wrong_source', 'wrong_worker', 'completed', 'wrong_generation',
            'missing_arm', 'unattested_dependent', 'changed_arm', 'changed_baseline', 'observed_projection',
            'missing_identity', 'missing_request', 'extra_scientific_artifact', 'unchanged_python', 'active',
            'missing_control_run', 'swapped_control', 'extra_scientific_response', 'wrong_passport_protocol', 'inline_response', 'observed_run_metric']);
    }

    public function test_classifier_rechecks_raw_original_bytes_and_does_not_spend_an_old_work_item(): void
    {
        $f = $this->failedValidator();
        $classifier = app(\App\Services\TechnicalFailureClassifierService::class);
        $worksBefore = DB::table('research_experiment_work_items')->get()->toArray();
        foreach ($f['generation']->agents as $a) {
            $classified = $classifier->forAgent($a);
            $this->assertSame('IMMUTABLE_ACADEMY_MTF_VALIDATOR_REFUSAL', $classified['reason_code'], json_encode($classified));
            $this->assertFalse($classified['blocks_global_generation']);
            $this->assertFalse($classified['scientific_outcome_observed']);
        }
        $this->assertEquals($worksBefore, DB::table('research_experiment_work_items')->get()->toArray());
        $run = LabEvaluationRun::where('lab_generation_id', $f['generation']->id)->first();
        $artifact = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_response')->first();
        Storage::disk(data_get($artifact->metadata, 'storage_disk'))->put($artifact->storage_path, 'corrupted immutable gzip');
        $this->assertNull($f['owner']->validatorTerminalDispositionForAgent($f['generation']->agents->first()));
        $this->assertNotSame('IMMUTABLE_ACADEMY_MTF_VALIDATOR_REFUSAL', $classifier->forAgent($f['generation']->agents->first())['reason_code']);
        Queue::assertNothingPushed();
    }
}
