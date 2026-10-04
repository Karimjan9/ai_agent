<?php

namespace Tests\Feature;

use App\Jobs\EvaluateLabScreeningBatchJob;
use App\Jobs\RunScheduledArtisanCommandJob;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\ResearchLoopDecision;
use App\Services\AcademyExperimentContractCompilerService;
use App\Services\AcademyExperimentMaterializerService;
use App\Services\CausalCompoundingKernelService;
use App\Services\AutonomousModeService;
use App\Services\AutonomousLearningProgressDirectorService;
use App\Services\LabDatasetExportService;
use App\Services\LabAgentEvaluationService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabQueueJobInspector;
use App\Services\MultiTimeframeSnapshotService;
use App\Services\ResearchLoopArbiterService;
use App\Services\ResearchReleaseSealService;
use App\Services\ScheduledCommandOutcomeClassifierService;
use App\Services\StrategyParameterSchemaService;
use App\Services\XauusdEdgeFormationAcademyService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/** Data providers are isolated; all planning/decision/materialization owners are real. */
class AcademyColdStartHandoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_passport_and_parameters_create_only_a_bounded_fresh_prospective_question(): void
    {
        Queue::fake();
        config()->set('services.xauusd_organism.historical_research_until_champion', false);
        [$source, $model, $oldPassport] = $this->sourceAndData();
        $director = Mockery::mock(AutonomousLearningProgressDirectorService::class);
        $director->shouldReceive('advance')->andReturn(['status' => 'blocked', 'reason' => 'EDGE_HYPOTHESIS_COMPILER_BLOCKED']);
        app()->instance(AutonomousLearningProgressDirectorService::class, $director);
        $materializer = app(AcademyExperimentMaterializerService::class);
        $proposal = $materializer->proposal();
        $this->assertSame('would_prepare_cold_start', $proposal['status'], json_encode($proposal));
        $this->assertSame('legacy_parameters_hypothesis_only', $proposal['source_role']);
        $this->assertSame(0, $proposal['stage_depth']);
        $this->assertDatabaseCount('edge_academy_trials', 0);
        $this->assertDatabaseCount('edge_academy_passports', 1);
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'prospective source acquisition');
        $open = app(ResearchLoopArbiterService::class)->tick();
        $this->assertSame('OPEN_ACADEMY_EXPERIMENT', $open['action'], json_encode($open));
        $this->assertSame(0, $open['arguments']['trial']);
        $job = new RunScheduledArtisanCommandJob($open['command'], $open['arguments'], $open['queue'], $open['decision_id']);
        $job->handle(app(ScheduledCommandOutcomeClassifierService::class));
        (new UniqueLock(Cache::store()))->release($job);
        $decision = ResearchLoopDecision::findOrFail($open['decision_id']);
        $this->assertSame('completed', $decision->status, json_encode($decision->outcome));
        $prepared = $materializer->proposal();
        $this->assertSame('pending_canonical_admission', $prepared['status'], json_encode($prepared));
        $generation = LabGeneration::findOrFail($prepared['generation_id']);
        $this->assertSame('draft', $generation->status);
        $this->assertSame(20, $generation->agents()->count());
        $this->assertSame(3, $generation->agents()->where('origin', 'academy_experiment')->count());
        foreach ($generation->agents()->with('modelVersion')->get() as $member) {
            $this->assertNull(data_get($member->modelVersion->metadata, 'edge_genesis'));
            $this->assertNull(data_get($member->modelVersion->metadata, 'full_stack_playbook'));
        }
        $this->assertNotNull(data_get($model->fresh()->metadata, 'edge_genesis')); // original source unchanged
        $control = $generation->agents()->with('modelVersion')->get()->first(fn ($agent) =>
            data_get($agent->modelVersion->metadata, 'academy_experiment.arm_role') === 'frozen_control');
        $compiler = app(AcademyExperimentContractCompilerService::class);
        $this->assertSame($compiler->parameterHash((array) $model->parameters), $compiler->parameterHash((array) $control->modelVersion->parameters));
        $restore = app(MultiTimeframeSnapshotService::class);
        $restore->shouldReceive('restoreAgentOwnedConfirmationValidationBundle')->once()
            ->with((array) data_get($generation->trigger_context, 'mtf_bundle_manifest'))
            ->andReturn(['bundle_hash' => str_repeat('b', 64), 'manifest' => data_get($generation->trigger_context, 'mtf_bundle_manifest')]);
        $method = new \ReflectionMethod(LabAgentEvaluationService::class, 'replayMtfBundle');
        $bundle = $method->invoke(app(LabAgentEvaluationService::class), $control, false);
        $this->assertSame(str_repeat('b', 64), $bundle['bundle_hash']);
        $this->assertSame(0, DB::table('edge_academy_passports')->find($oldPassport)->stage_depth);
        $this->assertDatabaseCount('edge_academy_passports', 2);
        $this->assertDatabaseCount('edge_academy_trials', 1);
        $passport = DB::table('edge_academy_passports')->where('id', '!=', $oldPassport)->first();
        $frozen = json_decode($passport->frozen_upstream_contract, true);
        $this->assertFalse(data_get($frozen, 'prospective_source_identity.cold_start.old_evidence_reused'));
        $this->assertSame($decision->id, data_get($frozen, 'prospective_source_identity.cold_start.arbiter_decision_id'));
        $this->assertSame('blocked', $materializer->coldStartProposal()['status']);
        $evidence = Mockery::mock(LabImmutableEvidenceService::class)->makePartial();
        $evidence->shouldReceive('codeHash')->andReturn(str_repeat('d', 64));
        app()->instance(LabImmutableEvidenceService::class, $evidence);
        $this->assertSame('ACADEMY_COLD_START_SCOPE_BUDGET_EXHAUSTED', $materializer->coldStartProposal()['reason']);
        $sourceGeneration = $source->generation;
        $context = $sourceGeneration->trigger_context;
        data_set($context, 'mtf_bundle_manifest.bundle_hash', str_repeat('9', 64));
        $sourceGeneration->update(['trigger_context' => $context]);
        $this->assertSame('ACADEMY_COLD_START_SCOPE_BUDGET_EXHAUSTED', $materializer->coldStartProposal()['reason']);
        data_set($context, 'mtf_bundle_manifest.bundle_hash', str_repeat('b', 64));
        $sourceGeneration->update(['trigger_context' => $context]);
        $evidence = Mockery::mock(LabImmutableEvidenceService::class)->makePartial();
        $evidence->shouldReceive('codeHash')->andReturn(str_repeat('e', 64));
        app()->instance(LabImmutableEvidenceService::class, $evidence);
        $this->assertDatabaseCount('causal_stage_mastery_assessments', 0);
        $this->assertDatabaseCount('evolutionary_authority_ledgers', 0);
        Queue::assertNotPushed(EvaluateLabScreeningBatchJob::class);

        // A redelivered OPEN reuses the same trial and generation. The next
        // durable action has the actual trial id and still calls the normal dispatcher.
        $decision->update(['status' => 'running']);
        $again = $materializer->prepareColdStart($decision);
        $this->assertSame($generation->id, $again['generation_id']);
        $this->assertTrue($again['reused']);
        $decision->update(['status' => 'completed']);
        $queue = Mockery::mock(LabQueueJobInspector::class)->makePartial();
        $queue->shouldReceive('queueSnapshot')->andReturn(['available' => false, 'ready' => 0, 'reserved' => 0, 'delayed' => 0, 'total' => 0, 'counts' => []]);
        app()->instance(LabQueueJobInspector::class, $queue);
        $dispatch = app(ResearchLoopArbiterService::class)->tick();
        $this->assertSame('DISPATCH_ACADEMY_EXPERIMENT', $dispatch['action']);
        $this->assertSame($prepared['trial_id'], $dispatch['arguments']['trial']);
        $dispatchJob = new RunScheduledArtisanCommandJob($dispatch['command'], $dispatch['arguments'], $dispatch['queue'], $dispatch['decision_id']);
        $dispatchJob->handle(app(ScheduledCommandOutcomeClassifierService::class));
        $this->assertSame('deferred', ResearchLoopDecision::findOrFail($dispatch['decision_id'])->status);
        $this->assertSame($generation->id, $materializer->proposal()['generation_id']);
        $this->assertDatabaseCount('edge_academy_trials', 1);
        Queue::assertNotPushed(EvaluateLabScreeningBatchJob::class);
    }

    public function test_changed_prospective_data_does_not_materialize_or_spend_the_question_budget(): void
    {
        Queue::fake();
        [$source, $model] = $this->sourceAndData();
        $materializer = app(AcademyExperimentMaterializerService::class);
        $proposal = $materializer->proposal();
        $decision = ResearchLoopDecision::create(['decision_key' => 'cold-source-change', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'action' => 'OPEN_ACADEMY_EXPERIMENT', 'priority' => 81, 'command' => 'trading:admit-academy-experiment',
            'arguments' => ['trial' => 0], 'status' => 'running', 'evidence_hash' => str_repeat('f', 64), 'reason_codes' => [], 'contract' => [],
            'evidence_snapshot' => ['academy_proposal' => $proposal], 'selected_at' => now()]);
        $model->update(['parameters' => [...$model->parameters, 'location_tolerance_atr' => 1.3]]);
        $result = $materializer->prepareColdStart($decision);
        $this->assertSame('ACADEMY_COLD_START_DEPENDENCY_CHANGED', $result['reason']);
        $this->assertDatabaseCount('edge_academy_trials', 0);
        $this->assertDatabaseCount('edge_academy_passports', 1);
        $this->assertSame('would_prepare_cold_start', $materializer->proposal()['status']);
        Queue::assertNothingPushed();
    }

    public function test_evaluator_change_after_prepare_cannot_dispatch_the_sealed_cohort(): void
    {
        Queue::fake();
        $this->sourceAndData();
        $materializer = app(AcademyExperimentMaterializerService::class);
        $proposal = $materializer->proposal();
        $open = ResearchLoopDecision::create(['decision_key' => 'cold-evaluator-fence-open', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'action' => 'OPEN_ACADEMY_EXPERIMENT', 'priority' => 81, 'command' => 'trading:admit-academy-experiment',
            'arguments' => ['trial' => 0], 'status' => 'running', 'evidence_hash' => str_repeat('f', 64), 'reason_codes' => [], 'contract' => [],
            'evidence_snapshot' => ['academy_proposal' => $proposal]]);
        $prepared = $materializer->prepareColdStart($open);
        $this->assertSame('pending_canonical_admission', $prepared['status'], json_encode($prepared));
        $pending = $materializer->proposal();
        $generation = LabGeneration::findOrFail($prepared['generation_id']);
        $this->assertSame(str_repeat('e', 64), data_get($generation->trigger_context, 'source_evaluator_hash'));
        $this->assertSame(app(ResearchReleaseSealService::class)->pythonHash(), data_get($generation->trigger_context, 'python_source_hash'));
        $this->assertSame('dual_runtime_source_identity_v1', data_get($generation->trigger_context, 'source_identity_protocol'));
        $dispatch = ResearchLoopDecision::create(['decision_key' => 'cold-evaluator-fence-dispatch', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'action' => 'DISPATCH_ACADEMY_EXPERIMENT', 'priority' => 100, 'command' => 'trading:admit-academy-experiment',
            'arguments' => ['trial' => $pending['trial_id']], 'status' => 'running', 'evidence_hash' => str_repeat('f', 64), 'reason_codes' => [], 'contract' => [],
            'evidence_snapshot' => ['academy_proposal' => $pending]]);
        $withoutSource = $pending['identity'];
        unset($withoutSource['source_evaluator_hash']);
        $this->assertSame('ACADEMY_PROSPECTIVE_IDENTITY_MISMATCH', $materializer->admit($pending['trial_id'], $pending['baseline_model_version_id'], $withoutSource, $dispatch)['reason']);
        $withoutPython = $pending['identity'];
        unset($withoutPython['python_source_hash']);
        $this->assertSame('ACADEMY_TYPED_PYTHON_SOURCE_SEAL_REQUIRED', $materializer->materialize(
            $pending['trial_id'], $pending['baseline_model_version_id'], $withoutPython, false)['reason']);
        $withoutType = $pending['identity'];
        unset($withoutType['source_identity_protocol']);
        $this->assertSame('ACADEMY_TYPED_RUNTIME_SOURCE_SEAL_REQUIRED', $materializer->materialize(
            $pending['trial_id'], $pending['baseline_model_version_id'], $withoutType, false)['reason']);
        $pythonSource = Mockery::mock(ResearchReleaseSealService::class)->makePartial();
        $pythonSource->shouldReceive('pythonHash')->andReturn(str_repeat('9', 64));
        app()->instance(ResearchReleaseSealService::class, $pythonSource);
        $this->assertSame('ACADEMY_FROZEN_PYTHON_EVALUATOR_CHANGED', $materializer->materialize(
            $pending['trial_id'], $pending['baseline_model_version_id'], $pending['identity'], false)['reason']);
        $changed = Mockery::mock(LabImmutableEvidenceService::class)->makePartial();
        $changed->shouldReceive('codeHash')->andReturn(str_repeat('d', 64));
        app()->instance(LabImmutableEvidenceService::class, $changed);
        $result = $materializer->admit($pending['trial_id'], $pending['baseline_model_version_id'], $pending['identity'], $dispatch);
        $this->assertSame('ACADEMY_FROZEN_EVALUATOR_CHANGED', $result['reason']);
        $this->assertSame('draft', $generation->fresh()->status);
        $this->assertSame('ACADEMY_COLD_START_SCOPE_BUDGET_EXHAUSTED', $materializer->coldStartProposal()['reason']);
        $this->assertDatabaseCount('edge_academy_trials', 1);
        Queue::assertNothingPushed();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('maximumWidthBaselineNames')]
    public function test_real_cold_materialization_preserves_twenty_seats_with_a_schema_maximum_baseline_name(string $name): void
    {
        Queue::fake();
        [$source, $model] = $this->sourceAndData();
        $parameters = [...(array) $model->parameters, 'setup_topology_policy' => 'pullback_rejection'];
        $model->update(['name' => $name, 'parameters' => $parameters]);
        $model = $model->fresh();
        $this->assertSame(96, mb_strlen($name, 'UTF-8'));
        // SQLite accepts overlong VARCHAR values, unlike production MySQL.
        // Model the canonical migration's character limit during this real
        // planner -> materializer -> kernel integration, not by mocking insert.
        DB::unprepared("CREATE TRIGGER academy_kernel_name_width BEFORE INSERT ON model_versions
            WHEN length(NEW.name) > 96 BEGIN SELECT RAISE(ABORT, 'MODEL_NAME_LENGTH_EXCEEDED'); END");
        $beforeParameters = app(AcademyExperimentContractCompilerService::class)->parameterHash($parameters);
        $materializer = app(AcademyExperimentMaterializerService::class);
        $proposal = $materializer->proposal();
        $this->assertSame('would_prepare_cold_start', $proposal['status'], json_encode($proposal));
        $this->assertSame(4, $proposal['primary_proof_seats']);
        $decision = ResearchLoopDecision::create(['decision_key' => 'cold-max-name-'.hash('sha256', $name),
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'action' => 'OPEN_ACADEMY_EXPERIMENT',
            'command' => 'trading:admit-academy-experiment', 'arguments' => ['trial' => 0], 'status' => 'running',
            'evidence_hash' => str_repeat('f', 64), 'reason_codes' => [], 'contract' => [],
            'evidence_snapshot' => ['academy_proposal' => $proposal]]);
        $prepared = $materializer->prepareColdStart($decision);
        $this->assertSame('pending_canonical_admission', $prepared['status'], json_encode($prepared));
        $generation = LabGeneration::findOrFail($prepared['generation_id']);
        $members = $generation->agents()->with('modelVersion')->get();
        $this->assertCount(20, $members);
        $this->assertSame(4, $members->where('origin', 'academy_experiment')->count());
        $kernel = $members->filter(fn (LabAgent $agent): bool => str_starts_with($agent->origin, 'compounding_discovery_'));
        $this->assertCount(16, $kernel);
        $this->assertSame(20, $members->pluck('model_version_id')->unique()->count());
        $this->assertSame(20, $members->pluck('modelVersion.name')->unique()->count());
        $this->assertSame($name, $model->fresh()->name);
        $this->assertSame($beforeParameters, app(AcademyExperimentContractCompilerService::class)->parameterHash((array) $model->fresh()->parameters));
        foreach ($kernel as $member) {
            $this->assertLessThanOrEqual(CausalCompoundingKernelService::MAX_MODEL_NAME_LENGTH,
                mb_strlen($member->modelVersion->name, 'UTF-8'));
            $this->assertTrue(mb_check_encoding($member->modelVersion->name, 'UTF-8'));
            $this->assertStringEndsWith(' I'.$generation->id, $member->modelVersion->name);
            $this->assertSame($model->id, data_get($member->modelVersion->metadata, 'causal_baseline_model_version_id'));
            $this->assertNull(data_get($member->modelVersion->metadata, 'genetic_parent_model_version_id'));
            $this->assertSame(data_get($generation->trigger_context, 'data_hash'), data_get($member->modelVersion->metadata, 'control_pair_contract.data_hash'));
        }
        foreach ($members->where('origin', 'academy_experiment') as $member) {
            $this->assertSame($model->id, data_get($member->modelVersion->metadata, 'academy_experiment.causal_baseline_model_version_id'));
            $armIndex = data_get($member->modelVersion->metadata, 'academy_experiment.arm_index');
            $this->assertSame(data_get($generation->trigger_context, 'compiled_contract.arms.'.$armIndex.'.parameter_hash'),
                app(AcademyExperimentContractCompilerService::class)->parameterHash((array) $member->modelVersion->parameters));
            if (data_get($member->modelVersion->metadata, 'academy_experiment.arm_role') === 'frozen_control') {
                $this->assertSame($beforeParameters,
                    app(AcademyExperimentContractCompilerService::class)->parameterHash((array) $member->modelVersion->parameters));
            }
        }
        $this->assertDatabaseCount('edge_academy_trials', 1);
        $this->assertDatabaseCount('causal_stage_mastery_assessments', 0);
        $this->assertDatabaseCount('evolutionary_authority_ledgers', 0);
        Queue::assertNothingPushed();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('baselineConstructorIdentityModes')]
    public function test_real_cold_twenty_seat_constructors_seal_current_parameters_and_keep_draft_guard_strict(string $mode): void
    {
        Queue::fake();
        [$source, $model] = $this->sourceAndData();
        $metadata = (array) $model->metadata;
        if ($mode === 'stale') {
            $metadata['parameter_fingerprint'] = str_repeat('0', 64);
            $metadata['universal_genome'] = [
                'core' => ['protocol' => 'old-core', 'family' => 'another_family'],
                'parent_model_version_ids' => [99999],
                'local_adapter' => ['parameters_hash' => str_repeat('1', 64)],
            ];
        } else {
            unset($metadata['parameter_fingerprint'], $metadata['universal_genome']);
        }
        $model->update(['metadata' => $metadata]);
        $originalMetadata = (array) $model->fresh()->metadata;
        $materializer = app(AcademyExperimentMaterializerService::class);
        $proposal = $materializer->proposal();
        $this->assertSame('would_prepare_cold_start', $proposal['status'], json_encode($proposal));
        $decision = ResearchLoopDecision::create(['decision_key' => 'cold-constructor-identity-'.$mode,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'action' => 'OPEN_ACADEMY_EXPERIMENT',
            'command' => 'trading:admit-academy-experiment', 'arguments' => ['trial' => 0], 'status' => 'running',
            'evidence_hash' => str_repeat('f', 64), 'reason_codes' => [], 'contract' => [],
            'evidence_snapshot' => ['academy_proposal' => $proposal]]);
        $prepared = $materializer->prepareColdStart($decision);
        $this->assertSame('pending_canonical_admission', $prepared['status'], json_encode($prepared));
        $generation = LabGeneration::findOrFail($prepared['generation_id']);
        $members = $generation->agents()->with('modelVersion')->get();
        $this->assertCount(20, $members);
        $this->assertSame($proposal['primary_proof_seats'], $members->where('origin', 'academy_experiment')->count());
        $schemas = app(StrategyParameterSchemaService::class);
        $guard = new \ReflectionMethod(\App\Console\Commands\DispatchLabGeneration::class, 'draftIntegrityViolations');
        $dispatcher = app(\App\Console\Commands\DispatchLabGeneration::class);
        $compiler = app(AcademyExperimentContractCompilerService::class);
        foreach ($members as $member) {
            $this->assertSame([], $guard->invoke($dispatcher, $member, $schemas), 'Fresh seat '.$member->id.' '.$member->origin);
            $this->assertSame([], data_get($member->modelVersion->metadata, 'universal_genome.parent_model_version_ids'));
            $this->assertSame($model->id, data_get($member->modelVersion->metadata, 'causal_baseline_model_version_id'));
            if ($member->origin === 'academy_experiment') {
                $index = data_get($member->modelVersion->metadata, 'academy_experiment.arm_index');
                $this->assertSame(data_get($generation->trigger_context, 'compiled_contract.arms.'.$index.'.parameter_hash'),
                    $compiler->parameterHash((array) $member->modelVersion->parameters));
            }
            // Model the MySQL object-key and integral numeric JSON roundtrip.
            // Those representation changes are not a new intervention.
            $before = (array) $member->modelVersion->parameters;
            $after = json_decode(json_encode(array_reverse($before, true), JSON_UNESCAPED_SLASHES), true);
            $this->assertSame($schemas->canonicalizeForIdentity($member->strategy_family, $before),
                $schemas->canonicalizeForIdentity($member->strategy_family, $after));
            $member->modelVersion->update(['parameters' => $after]);
            $member->unsetRelation('modelVersion')->load('modelVersion');
            $this->assertSame([], $guard->invoke($dispatcher, $member, $schemas), 'Roundtripped seat '.$member->id);
        }
        // A real value change after sealing is still corruption, not a repair.
        $tampered = $members->firstWhere('origin', 'academy_experiment');
        $parameters = (array) $tampered->modelVersion->parameters;
        $parameters['transition_wait_candles'] = (int) $parameters['transition_wait_candles'] + 1;
        $tampered->modelVersion->update(['parameters' => $parameters]);
        $tampered->unsetRelation('modelVersion')->load('modelVersion');
        $violations = $guard->invoke($dispatcher, $tampered, $schemas);
        $this->assertContains('PARAMETER_FINGERPRINT_MISMATCH', $violations);
        $this->assertContains('UNIVERSAL_PARAMETERS_HASH_MISMATCH', $violations);
        $this->assertSame($originalMetadata, (array) $model->fresh()->metadata);
        $this->assertDatabaseCount('causal_stage_mastery_assessments', 0);
        $this->assertDatabaseCount('evolutionary_authority_ledgers', 0);
        Queue::assertNothingPushed();
    }

    public static function baselineConstructorIdentityModes(): array
    {
        return ['legacy missing identity' => ['missing'], 'legacy stale identity' => ['stale']];
    }

    public static function maximumWidthBaselineNames(): array
    {
        return ['ascii schema maximum' => [str_repeat('x', 96)],
            'multibyte schema maximum' => [str_repeat('金', 96)]];
    }

    private function sourceAndData(): array
    {
        Storage::fake('academy_cold_fixture');
        $disk = Storage::disk('academy_cold_fixture');
        $disk->put('foundation.csv', "time,open,high,low,close\n2025-12-30 00:00:00,10,11,9,10\n");
        $disk->put('paper.csv', "time,open,high,low,close\n2026-01-02 00:00:00,10,11,9,10\n");
        $disk->put('paper.csv.manifest.json', json_encode(['promotion_evidence' => false, 'source_role' => 'paper_only']));
        $foundation = ['path' => $disk->path('foundation.csv'), 'sha256' => hash_file('sha256', $disk->path('foundation.csv')),
            'protocol' => 'foundation_training_archive_v1', 'manifest' => ['source_role' => 'foundation_training_only',
                'promotion_evidence' => false, 'last_candle_at' => '2025-12-30 00:00:00']];
        $datasets = Mockery::mock(LabDatasetExportService::class)->makePartial();
        $datasets->shouldReceive('foundationDependencyWatermark')->andReturn(['path' => $foundation['path'], 'archive_present' => true, 'manifest_hash' => str_repeat('f', 64), 'archive_bytes' => 10000, 'validated' => false]);
        $datasets->shouldReceive('ensureFoundationDataset')->andReturn($foundation);
        $datasets->shouldReceive('exportPaper')->andReturn($disk->path('paper.csv'));
        app()->instance(LabDatasetExportService::class, $datasets);
        $mtf = Mockery::mock(MultiTimeframeSnapshotService::class)->makePartial();
        $mtf->shouldReceive('agentValidationReadiness')->andReturn(['ready' => true, 'streams' => ['M5' => ['row_count' => 200000], 'M15' => ['row_count' => 20000], 'H1' => ['row_count' => 10000]], 'entry_cutoff' => '2025-12-30 00:00:00']);
        $mtf->shouldReceive('forAgentOwnedConfirmationValidation')->andReturn(['bundle_hash' => str_repeat('b', 64),
            'manifest' => ['protocol' => MultiTimeframeSnapshotService::PROTOCOL, 'entry_last_candle_at' => '2025-12-30 00:00:00',
                'streams' => array_fill_keys(['M5', 'M15', 'H1', 'H4'], ['sha256' => $foundation['sha256'], 'path' => $foundation['path']])]]);
        app()->instance(MultiTimeframeSnapshotService::class, $mtf);
        $evidence = Mockery::mock(LabImmutableEvidenceService::class)->makePartial();
        $evidence->shouldReceive('codeHash')->andReturn(str_repeat('e', 64));
        app()->instance(LabImmutableEvidenceService::class, $evidence);
        $lab = AiLaboratory::create(['name' => 'cold-source fixture', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'is_active' => true,
            'strategy_families' => ['confirmation_entry_mtf'], 'lifecycle_mode' => 'lighthouse']);
        $source = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'fixture', 'status' => 'completed',
            'trigger_context' => ['mtf_bundle_manifest' => ['protocol' => MultiTimeframeSnapshotService::PROTOCOL, 'bundle_hash' => str_repeat('b', 64), 'entry_last_candle_at' => '2025-12-30 00:00:00',
                'streams' => array_fill_keys(['M5', 'M15', 'H1', 'H4'], ['path' => $foundation['path'], 'sha256' => $foundation['sha256']])]]]);
        $model = ModelVersion::create(['name' => 'old hypothesis only', 'strategy' => 'old_hypothesis', 'version' => 'v0', 'generation' => 1,
            'status' => 'testing', 'parameters' => [...app(StrategyParameterSchemaService::class)->defaults('confirmation_entry_mtf'),
                'setup_topology_policy' => 'liquidity_sweep_reclaim'], 'metadata' => ['base_strategy' => 'confirmation_entry_mtf_v1',
                'edge_genesis' => ['protocol' => \App\Services\DependencyAwareEdgeGenesisFoundryService::PROTOCOL,
                    'mtf_bundle_hash' => str_repeat('0', 64), 'mtf_bundle_manifest' => ['bundle_hash' => str_repeat('0', 64)]],
                'full_stack_playbook' => ['protocol' => 'old_execution_owner']]]);
        $agent = LabAgent::create(['lab_generation_id' => $source->id, 'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'origin' => 'edge_genesis', 'strategy_family' => 'confirmation_entry_mtf', 'lifecycle_status' => 'completed', 'parameter_diff' => []]);
        $old = app(XauusdEdgeFormationAcademyService::class)->passport('XAUUSD', 'H1', ['composition_key' => 'legacy-inert-passport',
            'strategy_family' => 'confirmation_entry_mtf', 'deepest_stage' => 'market_cartographer']);
        return [$agent, $model->fresh(), $old['passport_id']];
    }
}
