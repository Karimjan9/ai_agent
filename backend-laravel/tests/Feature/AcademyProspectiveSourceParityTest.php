<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\AcademyExperimentContractCompilerService;
use App\Services\LabImmutableEvidenceService;
use App\Services\ResearchReleaseSealService;
use App\Services\StrategyParameterSchemaService;
use App\Services\XauusdEdgeFormationAcademyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;


/** Isolated direct-settlement boundary tests; never production runtime evidence. */
class AcademyProspectiveSourceParityTest extends TestCase
{
    use RefreshDatabase;

    public static function sourceCases(): array
    {
        return array_map(fn (string $case): array => [$case],
            ['exact', 'bundle', 'execution', 'evaluator', 'python', 'source_protocol', 'M15', 'run_code', 'missing_source', 'arm_count', 'missing_blinded']);
    }

    #[DataProvider('sourceCases')]
    public function test_direct_settlement_requires_original_source_not_just_matching_arm_receipts(string $case): void
    {
        Storage::fake('academy_source_parity');
        config()->set('services.lab_evidence.disk', 'academy_source_parity');
        $producerPath = base_path('../ai-service-python/tests/support/semantic_stage_receipt_fixture.py');
        $stagedPython = base_path('../.runtime/semantic-dual-source-staging-2026-10-03/ai-service-python');
        if (getenv('ACADEMY_DUAL_SOURCE_STAGE') === '1' && is_file($stagedPython.'/tests/support/semantic_stage_receipt_fixture.py')) {
            $producerPath = $stagedPython.'/tests/support/semantic_stage_receipt_fixture.py';
            // Staged code is actually different bytes, not a renamed live hash.
            app()->instance(ResearchReleaseSealService::class, new class(dirname($stagedPython).'/backend-laravel') extends ResearchReleaseSealService {
                public function __construct(private string $stagedBase) {}
                public function pythonHash(): string
                {
                    $original = app()->basePath();
                    app()->setBasePath($this->stagedBase);
                    try { return parent::pythonHash(); }
                    finally { app()->setBasePath($original); }
                }
            });
        }
        $producer = new Process(['python', $producerPath,
            '--full-runtime-source-hash', app(LabImmutableEvidenceService::class)->codeHash(),
            '--python-source-hash', app(ResearchReleaseSealService::class)->pythonHash()],
            base_path('../ai-service-python'), timeout: 90);
        $producer->mustRun();
        $fixture = json_decode($producer->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $bindings = data_get($fixture, 'control.data_quality.decision_identity_receipt.bindings');
        $streams = collect(data_get($fixture, 'control.data_quality.decision_identity_receipt.dependency_identity.streams'))
            ->map(fn (array $stream): array => ['sha256' => $stream['actual_source_sha256']])->all();
        $identity = ['pre_2026_only' => true,
            'source_identity_protocol' => $bindings['source_identity_protocol'],
            // The archive identity is intentionally not the runtime bundle identity.
            'data_hash' => $streams['H1']['sha256'], 'execution_hash' => $bindings['execution_hash'],
            'mtf_bundle_hash' => $bindings['dataset_identity'], 'source_evaluator_hash' => $bindings['full_runtime_source_hash'],
            'python_source_hash' => $bindings['python_source_hash'],
            'mtf_bundle_manifest' => ['streams' => $streams]];
        match ($case) {
            'bundle' => $identity['mtf_bundle_hash'] = str_repeat('9', 64),
            'execution' => $identity['execution_hash'] = str_repeat('9', 64),
            'evaluator' => $identity['source_evaluator_hash'] = str_repeat('9', 64),
            'python' => $identity['python_source_hash'] = str_repeat('9', 64),
            'source_protocol' => $identity['source_identity_protocol'] = 'legacy_source_identity',
            'M15' => $identity['mtf_bundle_manifest']['streams']['M15']['sha256'] = str_repeat('9', 64),
            'missing_source' => $identity = [], default => null,
        };
        $compiler = app(AcademyExperimentContractCompilerService::class);
        $academy = app(XauusdEdgeFormationAcademyService::class);
        $lab = AiLaboratory::create(['name' => 'direct-source-'.$case, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['confirmation_entry_mtf'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'academy_experiment', 'status' => 'screened']);
        $parameters = [...app(StrategyParameterSchemaService::class)->defaults('confirmation_entry_mtf'),
            'location_tolerance_atr' => $fixture['control_parameters']['location_tolerance_atr'],
            'setup_topology_policy' => 'liquidity_sweep_reclaim'];
        $baseline = ModelVersion::create(['name' => 'source-parity-control', 'strategy' => 'confirmation_entry_mtf_v1',
            'version' => 'parity-control', 'generation' => 1, 'status' => 'testing', 'parameters' => $parameters,
            'metadata' => ['base_strategy' => 'confirmation_entry_mtf_v1']]);
        $parameters = (array) $baseline->fresh()->parameters;
        $passport = $academy->passport('XAUUSD', 'H1', ['composition_key' => 'source-parity-'.$case,
            'deepest_stage' => 'market_cartographer', 'strategy_family' => 'confirmation_entry_mtf',
            'baseline_model_version_id' => $baseline->id, 'baseline_parameters' => $parameters,
            'baseline_parameter_hash' => $compiler->parameterHash($parameters), 'prospective_source_identity' => $identity]);
        $planned = $academy->planContextLocationProbe($passport['passport_id']);
        $evidence = app(LabImmutableEvidenceService::class);
        $runs = []; $agents = []; $models = [];
        foreach (['control' => 'frozen_control', 'candidate' => 'candidate', 'blinded' => 'blinded_control'] as $arm => $role) {
            if ($case === 'missing_blinded' && $arm === 'blinded') continue;
            $model = $arm === 'control' ? $baseline : ModelVersion::create(['name' => 'source-parity-'.$arm,
                'strategy' => 'confirmation_entry_mtf_v1', 'version' => 'parity-'.$arm, 'generation' => 1,
                'status' => 'testing', 'parameters' => [...$parameters,
                    'location_tolerance_atr' => $fixture[$arm.'_parameters']['location_tolerance_atr']]]);
            $model->update(['metadata' => ['base_strategy' => 'confirmation_entry_mtf_v1',
                'academy_experiment' => ['academy_trial_id' => $planned['trial_id'], 'arm_role' => $role,
                    'arm_index' => array_search($arm, ['control', 'candidate', 'blinded'], true)]]]);
            $model = $model->fresh();
            $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'confirmation_entry_mtf',
                'origin' => 'academy_experiment', 'lifecycle_status' => 'screened', 'parameter_diff' => []]);
            $agent->load('modelVersion');
            $metrics = $fixture[$arm];
            $metrics['irrelevant_numeric_object'] = (object) ['0' => 'raw-byte transport fixture'];
            $run = LabEvaluationRun::create(['run_id' => (string) Str::uuid(), 'lab_generation_id' => $generation->id,
                'lab_agent_id' => $agent->id, 'model_version_id' => $model->id, 'phase' => 'screening', 'mode' => 'screen',
                'status' => 'completed', 'response_hash' => $evidence->hash($metrics), 'data_hash' => $metrics['data_hash'],
                'code_hash' => $case === 'run_code' && $arm === 'candidate' ? str_repeat('9', 64) : $bindings['full_runtime_source_hash'],
                'parameter_hash' => $evidence->parameterHash($agent), 'attempt' => 1]);
            $evidence->recordArtifact($run, 'evaluation_response', $metrics);
            $runs[$arm] = $run; $agents[$arm] = $agent; $models[$arm] = $model;
        }
        $comparison = ['role' => 'candidate', 'gene' => 'location_tolerance_atr'];
        foreach (['control', 'candidate'] as $arm) {
            $comparison[$arm.'_run_database_id'] = $runs[$arm]->id;
            $comparison[$arm.'_run_id'] = $runs[$arm]->run_id;
            $comparison[$arm.'_agent_id'] = $agents[$arm]->id;
            $comparison[$arm.'_model_version_id'] = $models[$arm]->id;
            $comparison[$arm.'_response_hash'] = $runs[$arm]->response_hash;
            $comparison[$arm.'_parameter_hash'] = $compiler->parameterHash((array) $models[$arm]->parameters);
        }
        $settled = $academy->settleTrial($planned['trial_id'], ['setup' => 100, 'trigger' => 100, 'closed_trade' => 0],
            ['primary_arm_evidence_complete' => true, 'expected_arm_count' => $case === 'arm_count' ? 2 : 3,
                'complete_arm_count' => $case === 'arm_count' ? 2 : 3, 'stage_comparisons' => [$comparison]]);
        $this->assertSame($case === 'exact' ? 'observed_stage_controllability' : 'no_proven_stage_progress',
            data_get($settled, 'stage_progress.status'), json_encode($settled));
        if ($case !== 'exact') {
            $this->assertFalse(data_get($settled, 'stage_progress.evidence_assessable'));
            $this->assertSame('settled_unassessable_stage_evidence', $settled['status']);
        }
        $this->assertSame(0, DB::table('edge_academy_passports')->find($passport['passport_id'])->stage_depth);
        $this->assertDatabaseCount('edge_academy_passports', $case === 'exact' ? 2 : 1);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        if ($case === 'exact') {
            $successor = DB::table('edge_academy_passports')->find(data_get($settled, 'stage_progress.successor_passport_id'));
            $this->assertSame($identity['data_hash'], data_get(json_decode($successor->frozen_upstream_contract, true), 'prospective_source_identity.data_hash'));
            $nextId = data_get($settled, 'stage_progress.next_trial.trial_id');
            $before = [DB::table('edge_academy_trials')->count(), DB::table('edge_academy_passports')->count()];
            $continuation = $academy->curriculumContinuationEvidence($nextId);
            $this->assertTrue($continuation['eligible'], json_encode($continuation));
            $this->assertSame($runs['candidate']->run_id, $continuation['source_candidate_run_id']);
            $this->assertFalse($continuation['credit_authority']);
            $this->assertSame($continuation, $academy->curriculumContinuationEvidence($nextId));
            $this->assertSame($before, [DB::table('edge_academy_trials')->count(), DB::table('edge_academy_passports')->count()]);
            $runs['candidate']->update(['code_hash' => str_repeat('9', 64)]);
            $this->assertFalse($academy->curriculumContinuationEvidence($nextId)['eligible']);
            $runs['candidate']->update(['code_hash' => $bindings['full_runtime_source_hash']]);
            $originalMetadata = $models['candidate']->metadata;
            $models['candidate']->update(['metadata' => [...$originalMetadata, 'academy_experiment' => [
                ...$originalMetadata['academy_experiment'], 'academy_trial_id' => $planned['trial_id'] + 99]]]);
            $this->assertFalse($academy->curriculumContinuationEvidence($nextId)['eligible']);
            $models['candidate']->update(['metadata' => $originalMetadata]);
            DB::table('edge_academy_trials')->where('id', $nextId)->update(['density_contract' => json_encode(['minimum_setup_events' => 1])]);
            $this->assertFalse($academy->curriculumContinuationEvidence($nextId)['eligible']);
        }
    }

    public function test_handcrafted_depth_without_a_completed_original_stage_proof_does_not_earn_continuation_priority(): void
    {
        $academy = app(XauusdEdgeFormationAcademyService::class);
        $parameters = app(StrategyParameterSchemaService::class)->defaults('confirmation_entry_mtf');
        $passport = $academy->passport('XAUUSD', 'H1', ['composition_key' => 'depth-is-not-proof',
            'deepest_stage' => 'setup_apprentice', 'baseline_parameters' => $parameters]);
        $trial = $academy->planSetupTopologyProbe($passport['passport_id']);
        $before = [DB::table('edge_academy_passports')->count(), DB::table('edge_academy_trials')->count()];
        $result = $academy->curriculumContinuationEvidence($trial['trial_id']);
        $this->assertFalse($result['eligible']);
        $this->assertSame('ACADEMY_ORIGINAL_STAGE_PROGRESS_REQUIRED', $result['reason']);
        $this->assertSame($result, $academy->curriculumContinuationEvidence($trial['trial_id']));
        $this->assertSame($before, [DB::table('edge_academy_passports')->count(), DB::table('edge_academy_trials')->count()]);
    }
}
