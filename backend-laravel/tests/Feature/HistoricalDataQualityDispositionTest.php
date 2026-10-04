<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\GenerationSnapshotAdmissionService;
use App\Services\AcademyExperimentMaterializerService;
use App\Services\AutonomousLearningProgressDirectorService;
use App\Services\AutonomousModeService;
use App\Services\LabDatasetExportService;
use App\Services\MultiTimeframeSnapshotService;
use App\Services\ProspectiveRepairExperimentService;
use App\Services\ResearchLoopArbiterService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LearningVelocityGateService;
use App\Services\TechnicalFailureClassifierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HistoricalDataQualityDispositionTest extends TestCase
{
    use RefreshDatabase;

    private function gapFixture(): array
    {
        Queue::fake();
        $disk = 'historical-gap-fixture-'.bin2hex(random_bytes(8));
        config()->set('services.lab_evidence.disk', $disk);
        Storage::fake($disk);
        Storage::disk($disk)->put('m5.csv', "time,open,high,low,close\n2025-12-30 00:00:00,10,11,9,10\n");
        $path = Storage::disk($disk)->path('m5.csv');
        $lab = AiLaboratory::create(['name' => 'Native gap boundary fixture', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['confirmation_entry_mtf'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $manifest = ['protocol' => MultiTimeframeSnapshotService::PROTOCOL, 'bundle_hash' => str_repeat('b', 64),
            'datasets' => ['M5' => 'foundation_intraday_10y'], 'entry_last_candle_at' => '2025-12-30 00:00:00',
            'streams' => array_fill_keys(['M5', 'M15', 'H1', 'H4'], ['sha256' => hash_file('sha256', $path), 'path' => $path])];
        $gen = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'status' => 'screening',
            'trigger_type' => 'test', 'population_size' => 2, 'trigger_context' => ['mtf_bundle_manifest' => $manifest]]);
        $model = ModelVersion::create(['name' => 'Native immutable gap model', 'version' => 'gap-fixture-v1',
            'strategy' => 'confirmation_entry_mtf_v1', 'parameters' => ['risk_per_trade' => .01],
            'metadata' => ['base_strategy' => 'confirmation_entry_mtf_v1']]);
        $agent = LabAgent::create(['lab_generation_id' => $gen->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'confirmation_entry_mtf',
            'origin' => 'fixture', 'lifecycle_status' => 'screening', 'parameter_diff' => []]);
        $evidence = app(LabImmutableEvidenceService::class);
        $run = $evidence->beginRun($agent, 'screening', 'incremental', ['data_hash' => $manifest['bundle_hash']]);
        $evidence->attachRequest($run, ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'evaluation_mode' => 'incremental',
            'replay_dataset_hash' => $manifest['bundle_hash'], 'mtf_snapshot_manifest' => $manifest,
            'strategies' => [['strategy' => $model->strategy, 'version' => $model->version, 'parameters' => $model->parameters]]]);
        $this->travel(1)->seconds();
        $evidence->finishRun($run, 'technical_error', null, [], ['strategy_verdict' => 'withheld'],
            new \RuntimeException('Historical data hard-gate failed: 1 unexpected candle gaps.'));
        $agent->update(['lifecycle_status' => 'technical_quarantine']);
        $gen->update(['status' => 'technical_quarantine']);
        return [$gen->fresh(), $agent->fresh(['generation', 'modelVersion']), $run->fresh(), $manifest];
    }

    public function test_native_gap_history_closes_retry_debt_but_identical_bytes_remain_a_data_dependency(): void
    {
        [$gen, $agent, $run, $manifest] = $this->gapFixture();
        $before = $run->toArray();
        $artifacts = LabEvidenceArtifact::orderBy('id')->get()->toArray();
        $classifier = app(TechnicalFailureClassifierService::class);
        $decision = $classifier->forAgent($agent);
        $this->assertSame(TechnicalFailureClassifierService::CAPABILITY, $decision['class'], json_encode($decision));
        $this->assertFalse($decision['blocks_global_generation']);
        $this->assertSame('blocked_data_dependency', $decision['data_dependency']['status']);
        $this->assertSame($run->run_id, $decision['data_dependency']['run_id']);
        $this->assertFalse($decision['data_dependency']['scientific_question_budget_reset']);
        $readiness = app(GenerationSnapshotAdmissionService::class);
        $blocked = $readiness->historicalDatasetReadiness($manifest);
        $this->assertFalse($blocked['allowed']);
        $this->assertSame(['GENERATION_MTF_M5_KNOWN_CANDLE_GAP'], $blocked['reasons']);
        $this->assertFalse($readiness->historicalDatasetReadiness([...$manifest, 'bundle_hash' => str_repeat('c', 64),
            'source_evaluator_hash' => str_repeat('d', 64)])['allowed']);
        $other = $manifest; $other['streams']['M5']['sha256'] = str_repeat('e', 64);
        $this->assertTrue($readiness->historicalDatasetReadiness($other)['allowed']);
        $this->assertContains('GENERATION_MTF_M5_KNOWN_CANDLE_GAP', $readiness->inspect($gen)['reasons']);
        config()->set('services.lab_selection.learning_velocity_enabled', true);
        $velocity = app(LearningVelocityGateService::class)->inspect('XAUUSD', 'H1');
        $this->assertSame(0, $velocity['technical_recovery_agents'], json_encode($velocity));
        $this->assertNotSame('blocked_technical_recovery', $velocity['status']);
        $this->assertSame($before, $run->fresh()->toArray());
        $this->assertSame($artifacts, LabEvidenceArtifact::orderBy('id')->get()->toArray());
        Queue::assertNothingPushed();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('poisonShapes')]
    public function test_manual_or_poisoned_gap_labels_cannot_attest_an_immutable_dependency(string $shape): void
    {
        [$gen, $agent, $run, $manifest] = $this->gapFixture();
        if ($shape === 'manual_label') {
            LabEvaluationRun::whereKey($run->id)->delete();
            $agent->update(['decision_reason' => 'Historical data hard-gate failed: 1 unexpected candle gaps.']);
        }
        if ($shape === 'missing_original_model') LabEvidenceArtifact::where('artifact_type', 'model_runtime_identity')->delete();
        if ($shape === 'changed_parameters') $agent->modelVersion->update(['parameters' => ['risk_per_trade' => .02]]);
        if ($shape === 'wrong_response_hash') $run->update(['response_hash' => str_repeat('f', 64)]);
        if ($shape === 'unknown_gate') $run->update(['error_message' => 'Historical data hard-gate failed: invalid unknown data.']);
        $result = app(TechnicalFailureClassifierService::class)->forAgent($agent->fresh(['modelVersion', 'generation']));
        $this->assertSame(TechnicalFailureClassifierService::TRANSIENT, $result['class'], $shape);
        $this->assertTrue($result['blocks_global_generation']);
        $this->assertTrue(app(GenerationSnapshotAdmissionService::class)->historicalDatasetReadiness($manifest)['allowed']);
        Queue::assertNothingPushed();
    }

    public static function poisonShapes(): array
    {
        return array_map(fn ($shape) => [$shape], ['manual_label', 'missing_original_model', 'changed_parameters',
            'wrong_response_hash', 'unknown_gate']);
    }

    public function test_unreplayed_academy_candidate_inherits_only_its_exact_same_generation_control_dependency(): void
    {
        [$gen, $control] = $this->gapFixture();
        $model = ModelVersion::create(['name' => 'Unreplayed exact candidate', 'version' => 'gap-candidate-v1',
            'strategy' => 'confirmation_entry_mtf_v1', 'parameters' => ['risk_per_trade' => .01],
            'metadata' => ['learning_receipt' => ['control_agent_id' => $control->id]]]);
        $candidate = LabAgent::create(['lab_generation_id' => $gen->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'confirmation_entry_mtf',
            'origin' => 'academy_experiment', 'lifecycle_status' => 'technical_quarantine', 'parameter_diff' => [],
            'decision_reason' => 'FROZEN_CONTROL_REPLAY_INCOMPLETE']);
        $classifier = app(TechnicalFailureClassifierService::class);
        $projection = $classifier->forAgent($candidate);
        $this->assertSame(TechnicalFailureClassifierService::TERMINAL, $projection['class']);
        $this->assertFalse($projection['blocks_global_generation']);
        $this->assertSame('UPSTREAM_FROZEN_CONTROL_TERMINAL', $projection['reason_code']);
        $this->assertFalse(LabEvaluationRun::where('lab_agent_id', $candidate->id)->exists());
        $future = LabGeneration::create(['ai_laboratory_id' => $gen->ai_laboratory_id, 'generation' => 2,
            'status' => 'technical_quarantine', 'population_size' => 1, 'trigger_type' => 'test']);
        $candidate->update(['lab_generation_id' => $future->id]);
        $wrong = $classifier->forAgent($candidate->fresh(['modelVersion', 'generation']));
        $this->assertSame(TechnicalFailureClassifierService::TRANSIENT, $wrong['class']);
        $this->assertTrue($wrong['blocks_global_generation']);
        Queue::assertNothingPushed();
    }

    public function test_prequeue_reason_requires_native_bad_bytes_and_cannot_be_a_manual_classifier_waiver(): void
    {
        [$generation, , $run, $manifest] = $this->gapFixture();
        $model = ModelVersion::create(['name' => 'Future prequeue gap fixture', 'version' => 'future-gap-v1',
            'strategy' => 'confirmation_entry_mtf_v1', 'parameters' => [], 'metadata' => []]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'confirmation_entry_mtf',
            'origin' => 'fixture', 'lifecycle_status' => 'technical_quarantine', 'parameter_diff' => [],
            'decision_reason' => 'GENERATION_MTF_M5_KNOWN_CANDLE_GAP']);
        $classifier = app(TechnicalFailureClassifierService::class);
        $attested = $classifier->forAgent($agent->fresh(['generation', 'modelVersion']));
        $this->assertSame(TechnicalFailureClassifierService::CAPABILITY, $attested['class']);
        $this->assertFalse($attested['blocks_global_generation']);
        $this->assertSame($run->run_id, $attested['data_dependency']['run_id']);
        $manifest['streams']['M5']['sha256'] = str_repeat('f', 64);
        $generation->update(['trigger_context' => ['mtf_bundle_manifest' => $manifest]]);
        $unattested = $classifier->forAgent($agent->fresh(['generation', 'modelVersion']));
        $this->assertSame(TechnicalFailureClassifierService::TRANSIENT, $unattested['class']);
        $this->assertTrue($unattested['blocks_global_generation']);
        Queue::assertNothingPushed();
    }

    public function test_known_bad_dataset_blocks_cold_planning_without_creating_a_fourth_attempt(): void
    {
        [$generation, , , $manifest] = $this->gapFixture();
        $this->dataOwners($manifest);
        $before = LabEvaluationRun::orderBy('id')->get()->toArray();
        $result = app(AcademyExperimentMaterializerService::class)->coldStartProposal();
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('GENERATION_MTF_M5_KNOWN_CANDLE_GAP', $result['reason']);
        $this->assertFalse($result['data_readiness']['allowed']);
        $this->assertSame($generation->id, data_get($result, 'data_readiness.source_dependency.lab_generation_id'));
        $this->assertDatabaseCount('edge_academy_trials', 0);
        $this->assertDatabaseCount('edge_academy_passports', 0);
        $this->assertSame($before, LabEvaluationRun::orderBy('id')->get()->toArray());
        Queue::assertNothingPushed();
    }

    public function test_arbiter_waits_once_on_native_data_dependency_instead_of_opening_fresh_discovery(): void
    {
        [$generation, , $run, $manifest] = $this->gapFixture();
        $this->selectorOwners($manifest);
        $this->mock(ProspectiveRepairExperimentService::class, fn ($mock) => $mock->shouldReceive('eligible')->never());
        $arbiter = app(ResearchLoopArbiterService::class);
        $first = $arbiter->tick();
        $second = $arbiter->tick();
        $this->assertSame('WAIT_DATASET_CONTINUITY', $first['action'], json_encode($first));
        $this->assertNull($first['command']);
        $this->assertSame($run->run_id, data_get($first, 'evidence_snapshot.data_readiness.source_dependency.run_id'));
        $this->assertSame('duplicate_suppressed', $second['status']);
        $this->assertSame($generation->id, LabGeneration::latest('id')->value('id'));
        $this->assertDatabaseCount('edge_academy_trials', 0);
        Queue::assertNothingPushed();
    }

    public function test_admitted_and_earned_work_keep_priority_over_data_dependency(): void
    {
        [$generation, , , $manifest] = $this->gapFixture();
        $this->selectorOwners($manifest, 'EDGE_CONFIRMATION');
        $result = app(ResearchLoopArbiterService::class)->tick('XAUUSD', 'H1', true);
        $this->assertSame('EDGE_CONFIRMATION', $result['action']);
        $generation->update(['status' => 'screening']);
        $result = app(ResearchLoopArbiterService::class)->tick('XAUUSD', 'H1', true);
        $this->assertSame('SETTLE_EXISTING_GENERATION', $result['action']);
        Queue::assertNothingPushed();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('repairShapes')]
    public function test_only_verified_current_new_bytes_release_early_discovery_not_the_old_frozen_bundle(string $shape): void
    {
        [$generation, , $run, $manifest] = $this->gapFixture();
        $disk = Storage::disk(config('services.lab_evidence.disk'));
        $disk->put('repaired.csv', "time,open,high,low,close\n2025-12-30 00:00:00,10,12,9,11\n");
        $newPath = $disk->path('repaired.csv');
        $repair = ['protocol' => 'frozen_m5_gap_recovery_v1', 'verified' => true,
            'repair_hash' => str_repeat('d', 64), 'dataset_key' => 'foundation_intraday_gapfix_fixture',
            'original_bad_m5_sha256' => $manifest['streams']['M5']['sha256'],
            'prospective_m5_source_sha256' => hash_file('sha256', $newPath), 'prospective_m5_source_path' => $newPath,
            'economic_rows_sha256' => str_repeat('c', 64), 'quote_liquidity_inherited' => false,
            'independent_evidence' => false, 'promotion_evidence' => false];
        if ($shape === 'label_only') $repair['verified'] = false;
        if ($shape === 'wrong_path_sha') $repair['prospective_m5_source_sha256'] = str_repeat('e', 64);
        if ($shape === 'same_old_bytes') {
            $repair['prospective_m5_source_sha256'] = $manifest['streams']['M5']['sha256'];
            $repair['prospective_m5_source_path'] = $manifest['streams']['M5']['path'];
        }
        $this->selectorOwners($manifest, 'EDGE_GENESIS', $repair);
        $this->mock(ProspectiveRepairExperimentService::class, function ($mock) use ($shape): void {
            if ($shape === 'verified_new_bytes') $mock->shouldReceive('eligible')->once()->andReturn([
                'source_pair_id' => 99, 'source_hash' => str_repeat('9', 64)]);
            else $mock->shouldReceive('eligible')->never();
        });
        $before = $run->toArray();
        $result = app(ResearchLoopArbiterService::class)->tick('XAUUSD', 'H1', true);
        $this->assertSame($shape === 'verified_new_bytes' ? 'OPEN_PROSPECTIVE_REPAIR_EXPERIMENT'
            : 'WAIT_DATASET_CONTINUITY', $result['action'], json_encode($result));
        // Even a valid current repair does not exonerate old quoted bytes.
        $this->assertFalse(app(GenerationSnapshotAdmissionService::class)->historicalDatasetReadiness($manifest)['allowed']);
        $this->assertSame($before, $run->fresh()->toArray());
        $this->assertSame($generation->id, LabGeneration::latest('id')->value('id'));
        Queue::assertNothingPushed();
    }

    public static function repairShapes(): array
    {
        return array_map(fn ($shape) => [$shape], ['verified_new_bytes', 'label_only', 'wrong_path_sha', 'same_old_bytes']);
    }

    public function test_new_selected_dataset_waits_for_its_own_canonical_bundle_instead_of_copying_old_quotes(): void
    {
        [$generation, , , $manifest] = $this->gapFixture();
        $repair = ['protocol' => 'frozen_m5_gap_recovery_v1', 'verified' => true,
            'dataset_key' => 'foundation_intraday_gapfix_fixture'];
        $this->dataOwners($manifest, $repair);
        $owner = app(AcademyExperimentMaterializerService::class);
        $method = new \ReflectionMethod($owner, 'coldStartDependencies');
        $waiting = $method->invoke($owner, 'XAUUSD', 'H1');
        $this->assertFalse($waiting['ready']);
        $this->assertSame('ACADEMY_COLD_START_CURRENT_SEALED_MTF_BYTES_UNAVAILABLE', $waiting['reason']);
        $disk = Storage::disk(config('services.lab_evidence.disk'));
        $disk->put('new-bundle.csv', "time,open,high,low,close\n2025-12-30 00:00:00,10,12,9,11\n");
        $newManifest = $manifest;
        $newManifest['bundle_hash'] = str_repeat('c', 64);
        $newManifest['datasets']['M5'] = $repair['dataset_key'];
        $newManifest['streams']['M5'] = ['path' => $disk->path('new-bundle.csv'),
            'sha256' => hash_file('sha256', $disk->path('new-bundle.csv'))];
        LabGeneration::create(['ai_laboratory_id' => $generation->ai_laboratory_id, 'generation' => 2,
            'status' => 'completed', 'trigger_type' => 'test', 'trigger_context' => ['mtf_bundle_manifest' => $newManifest]]);
        $ready = $method->invoke($owner, 'XAUUSD', 'H1');
        $this->assertTrue($ready['ready'], json_encode($ready));
        $this->assertSame($newManifest['bundle_hash'], $ready['mtf_bundle_hash']);
        $this->assertSame($newManifest['streams']['M5']['sha256'], $ready['mtf_source_sha256']['M5']);
        $this->assertDatabaseCount('edge_academy_trials', 0);
        Queue::assertNothingPushed();
    }

    private function dataOwners(array $manifest, array $repair = []): void
    {
        $this->mock(LabDatasetExportService::class, fn ($mock) => $mock->shouldReceive('foundationDependencyWatermark')
            ->andReturn(['archive_present' => true, 'manifest_hash' => str_repeat('f', 64), 'path' => $manifest['streams']['H1']['path']]));
        $this->mock(MultiTimeframeSnapshotService::class, fn ($mock) => $mock->shouldReceive('agentValidationReadiness')
            ->andReturn(['ready' => true, 'streams' => [], 'entry_cutoff' => $manifest['entry_last_candle_at'],
                'prospective_m5_repair' => $repair]));
    }

    private function selectorOwners(array $manifest, string $director = 'EDGE_GENESIS', array $repair = []): void
    {
        config()->set('services.xauusd_organism.historical_research_until_champion', false);
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'native continuity dependency');
        $this->mock(AcademyExperimentMaterializerService::class, fn ($mock) => $mock->shouldReceive('proposal')
            ->andReturn(['status' => 'blocked', 'reason' => 'ACADEMY_PREPARATION_REPAIR_ALLOWANCE_EXHAUSTED']));
        $this->mock(AutonomousLearningProgressDirectorService::class, fn ($mock) => $mock->shouldReceive('advance')
            ->andReturn(['action' => $director, 'result' => ['status' => 'would_queue']]));
        $this->mock(MultiTimeframeSnapshotService::class, fn ($mock) => $mock->shouldReceive('agentValidationReadiness')
            ->andReturn(['ready' => true, 'streams' => [], 'entry_cutoff' => $manifest['entry_last_candle_at'],
                'prospective_m5_repair' => $repair]));
    }
}
