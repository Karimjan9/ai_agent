<?php

namespace Tests\Feature;

use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabLearningLanePair;
use App\Models\ModelVersion;
use App\Models\PlaybookComposition;
use App\Services\InstrumentPolicyConsumptionService;
use App\Services\CompositionAuthorityKernelService;
use App\Services\ExecutionContractService;
use App\Services\LabAgentEvaluationService;
use App\Services\InstrumentResearchWindowService;
use App\Services\InstrumentValidationEvidenceService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabInstrumentResearchService;
use App\Services\PaperAuthorityAdmissionService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\ResearchReleaseSealService;
use App\Services\StrategyParameterSchemaService;
use App\Services\TradingInstrumentOperatingSystemService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\InstrumentValidationFixture;
use Tests\TestCase;

/** Actual source/artifact/confirmation owners, with explicitly synthetic utility facts. */
class PostPaperConfirmedTraitAdmissionTest extends TestCase
{
    use RefreshDatabase;
    use InstrumentValidationFixture;

    private static array $producerCache = [];
    private string $sourceRoot;
    private string $originalStorage;
    private const INTERNAL_KEY = 'fixture-only-internal-auth-key-32-characters';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStorage = storage_path();
        $this->sourceRoot = sys_get_temp_dir().'/confirmed-trait-source-test-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->sourceRoot.'/app/lab-datasets');
        $this->app->useStoragePath($this->sourceRoot);
        config(['filesystems.disks.trait_source_fixture' => ['driver' => 'local', 'root' => $this->sourceRoot.'/app/lab-datasets'],
            'services.internal_api.token' => self::INTERNAL_KEY]);
    }

    protected function tearDown(): void
    {
        if (isset($this->originalStorage)) $this->app->useStoragePath($this->originalStorage);
        if (isset($this->sourceRoot)) {
            $resolved = realpath($this->sourceRoot);
            $prefix = str_replace('\\', '/', (string) realpath(sys_get_temp_dir())).'/confirmed-trait-source-test-';
            if ($resolved && str_starts_with(str_replace('\\', '/', $resolved), $prefix)) File::deleteDirectory($resolved);
        }
        parent::tearDown();
    }

    public function test_original_archive_plus_one_confirmed_executable_trait_can_use_only_its_new_paper_epoch(): void
    {
        [$model, $parent, $passport] = $this->traitCandidate();
        $service = app(PaperAuthorityAdmissionService::class);
        $this->assertSame(0, DB::table('paper_authority_admissions')->where('model_version_id', $parent->id)->count());
        $result = $service->admit($model, 'XAUUSD', 'H1', $passport);
        $this->assertSame('e3_paper_candidate', $result['status'], json_encode($result));
        $evidence = json_decode(DB::table('paper_authority_admissions')->value('evidence'), true);
        $proof = $evidence['post_paper_trait_provenance'];
        $this->assertTrue($proof['allowed']);
        $this->assertSame('archive_baseline_confirmed_runtime_seal_preserving_trait', $proof['provenance_kind']);
        $this->assertFalse($proof['arbitrary_post_paper_training_authorized']);
        $this->assertCount(3, $proof['source_windows']);
        $this->assertTrue($service->verifyFrozenCandidate($model, 'XAUUSD', 'H1', $passport)['allowed']);
        $this->assertSame('PAPER_EPOCH_NOT_OPEN_FOR_OBSERVATION', $service->observationReadiness($model, 'XAUUSD', 'H1')['reason_code']);
        $first = $service->postPaperTraitProvenance($model, 'XAUUSD', '2027-05-02T00:00:00Z', $evidence['epoch_contract']);
        $this->assertSame($first, $service->postPaperTraitProvenance($model, 'XAUUSD', '2027-05-02T00:00:00Z', $evidence['epoch_contract']));
        $this->travelTo(CarbonImmutable::parse('2028-01-15T00:00:00Z'));
        $order = new \App\Models\PaperOrder(['opened_at' => '2028-01-02T00:00:00Z', 'closed_at' => '2028-01-03T00:00:00Z',
            'signal_context' => ['smart_discipline' => ['approved' => true]]]);
        $order->created_at = CarbonImmutable::parse('2028-01-02T00:00:00Z');
        $outcome = $service->prospectiveOutcomeContract($model, 'XAUUSD', 'H1', [$order]);
        $this->assertSame('paper_2028', $outcome['paper_window_key']);
        $this->assertSame('e3_paper_candidate', $service->recordProspectiveOutcome($model, 'XAUUSD', 'H1',
            [...$outcome, 'paper_window_key' => 'paper_2026', 'paper_gate_passed' => true])['status']);
        $this->assertSame('e4_evidence_ready', $service->recordProspectiveOutcome($model, 'XAUUSD', 'H1',
            [...$outcome, 'paper_gate_passed' => true])['status']);
        $this->assertTrue($service->championEligible($model, 'XAUUSD', 'H1'));
        $this->assertDatabaseCount('paper_orders', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $metadata = $model->metadata;
        data_set($metadata, 'smart_composition.composition_passport.typed_program.nodes.0.module', 'other_executable_node');
        $model->update(['metadata' => $metadata]);
        $this->assertFalse($service->verifyFrozenCandidate($model, 'XAUUSD', 'H1', $passport)['allowed']);
    }

    public function test_original_provenance_rejects_extra_genes_components_context_cost_sources_and_late_seals(): void
    {
        [$model, $parent, $passport, $pair] = $this->traitCandidate();
        $service = app(PaperAuthorityAdmissionService::class);
        $epoch = app(ResearchPaperEpochContractService::class)->paperContractForCandidate('paper_2028', '2027-05-02T00:00:00Z');
        $parameters = $model->parameters; $metadata = $model->metadata;
        $this->assertTrue($service->postPaperTraitProvenance($model, 'XAUUSD', '2027-05-02T00:00:00Z', $epoch)['allowed']);
        $originalOwner = app(LabImmutableEvidenceService::class);
        $changedRelease = \Mockery::mock(LabImmutableEvidenceService::class)->makePartial();
        $changedRelease->shouldReceive('codeHash')->andReturn(hash('sha256', 'new-release-not-revalidated'));
        app()->instance(LabImmutableEvidenceService::class, $changedRelease);
        $this->assertSame('RELEASE_TRAIT_REVALIDATION_REQUIRED', $service->postPaperTraitProvenance($model, 'XAUUSD', '2027-05-02T00:00:00Z', $epoch)['reason_code']);
        app()->instance(LabImmutableEvidenceService::class, $originalOwner);
        $this->assertSame($metadata['instrument_learning_consumption'], $model->fresh()->metadata['instrument_learning_consumption']);
        $model->update(['parameters' => [...$parameters, 'risk_multiplier' => .3]]);
        $this->assertSame('PAPER_TRAIT_TOTAL_PARAMETER_DELTA_MISMATCH', $service->postPaperTraitProvenance($model, 'XAUUSD', '2027-05-02T00:00:00Z', $epoch)['reason_code']);
        $this->assertSame('withheld', $service->admit($model, 'XAUUSD', 'H1', [...$passport, 'training_pre_2026' => true])['status']);
        $model->update(['parameters' => $parameters]);
        foreach (['tactic', 'risk_governor', 'trade_management', 'specialist_context_contract', 'execution_contract'] as $key) {
            $model->update(['metadata' => [...$metadata, $key => ['changed' => true]]]);
            $this->assertSame('PAPER_TRAIT_UNOWNED_RUNTIME_COMPONENT_CHANGE', $service->postPaperTraitProvenance($model, 'XAUUSD', '2027-05-02T00:00:00Z', $epoch)['reason_code'], $key);
        }
        $model->update(['metadata' => [...$metadata, 'parameter_fingerprint' => str_repeat('f', 64)]]);
        $this->assertSame('PAPER_TRAIT_CURRENT_PARAMETER_SEAL_INVALID', $service->postPaperTraitProvenance($model, 'XAUUSD', '2027-05-02T00:00:00Z', $epoch)['reason_code']);
        $model->update(['metadata' => $metadata]);
        $sourceRun = LabEvaluationRun::where('run_id', $pair->candidate_evidence_run_id)->firstOrFail();
        $finished = $sourceRun->finished_at;
        $sourceRun->update(['finished_at' => '2027-06-01T00:00:00Z']);
        $this->assertSame('PAPER_TRAIT_ORIGINAL_SOURCE_MODEL_OR_TIME_INVALID', $service->postPaperTraitProvenance($model, 'XAUUSD', '2027-05-02T00:00:00Z', $epoch)['reason_code']);
        $sourceRun->update(['finished_at' => $finished]);
        $sourceModel = ModelVersion::findOrFail($sourceRun->model_version_id); $sourceMetadata = $sourceModel->metadata;
        $sourceModel->update(['metadata' => [...$sourceMetadata, 'risk_governor' => ['changed' => true]]]);
        $this->assertSame('PAPER_TRAIT_CONFIRMED_CONSUMPTION_INVALID', $service->postPaperTraitProvenance($model, 'XAUUSD', '2027-05-02T00:00:00Z', $epoch)['reason_code']);
        $sourceModel->update(['metadata' => $sourceMetadata]);
        $seal = LabEvidenceArtifact::where('run_id', $sourceRun->run_id)->where('artifact_type', 'model_runtime_identity')->firstOrFail();
        $created = $seal->created_at; $seal->forceFill(['created_at' => $finished->copy()->addMinute()])->save();
        $this->assertFalse($service->postPaperTraitProvenance($model, 'XAUUSD', '2027-05-02T00:00:00Z', $epoch)['allowed']);
        $seal->forceFill(['created_at' => $created])->save();
        $original = app(LabImmutableEvidenceService::class)->latestArtifactPayload($sourceRun, 'evaluation_request');
        $response = app(LabImmutableEvidenceService::class)->latestArtifactPayload($sourceRun, 'evaluation_response');
        $scope = new \ReflectionMethod(PaperAuthorityAdmissionService::class, 'sourceFilesInside');
        $window = data_get($pair->metadata, 'instrument_research_window_receipt');
        $transport = new \ReflectionMethod(PaperAuthorityAdmissionService::class, 'originalResearchTransportMatches');
        $this->assertTrue($transport->invoke($service, $original, $window, $sourceRun));
        $this->assertFalse($transport->invoke($service, [...$original, 'policy_context' => []], $window, $sourceRun));
        $treatment = new \ReflectionMethod(PaperAuthorityAdmissionService::class, 'instrumentTreatment');
        $assignment = $original['instrument_research_assignment'];
        $changed = $assignment;
        data_set($changed, 'selected.0.activation_contract.context.declared_context.venue_phase', 'comex_maintenance');
        $this->assertNotSame($treatment->invoke($service, $assignment, 'volume_lane'), $treatment->invoke($service, $changed, 'volume_lane'));
        $this->assertTrue($scope->invoke($service, $original, $response,
            CarbonImmutable::parse($window['start_inclusive']), CarbonImmutable::parse($window['end_exclusive'])));
        foreach (['mtf_streams', 'related_mtf_streams', 'related_mtf_dataset_paths', 'foundation_dataset_path'] as $plane) {
            $this->assertFalse($scope->invoke($service, [...$original, $plane => $plane === 'foundation_dataset_path' ? 'unbound.csv' : ['unbound']],
                $response, CarbonImmutable::parse($window['start_inclusive']), CarbonImmutable::parse($window['end_exclusive'])), $plane);
        }
        $parent->update(['metadata' => [...$parent->metadata, 'tactic' => ['new' => true]]]);
        $this->assertFalse($service->postPaperTraitProvenance($model, 'XAUUSD', '2027-05-02T00:00:00Z', $epoch)['allowed']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_future_archive_uses_original_modern_source_not_training_label_and_never_backfills_old_seal(): void
    {
        [$model, $parent, $passport] = $this->traitCandidate();
        $service = app(PaperAuthorityAdmissionService::class);
        $epoch = app(ResearchPaperEpochContractService::class)->paperContractForCandidate('paper_2028', '2027-05-02T00:00:00Z');
        $this->assertTrue($service->archivePaperProvenance($parent, 'XAUUSD', '2027-05-02T00:00:00Z', $epoch)['allowed']);
        $run = LabEvaluationRun::where('model_version_id', $parent->id)->firstOrFail();
        $finished = $run->finished_at;
        $run->update(['finished_at' => '2027-02-01T00:00:00Z']);
        $this->assertFalse($service->archivePaperProvenance($parent, 'XAUUSD', '2027-05-02T00:00:00Z', $epoch)['allowed'],
            'A future-tuned model cannot become an original archive baseline by replaying old CSV later.');
        $run->update(['finished_at' => $finished]);
        LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'model_runtime_identity')->delete();
        $source = app(LabImmutableEvidenceService::class)->latestArtifactPayload($run, 'evaluation_request');
        app(LabImmutableEvidenceService::class)->attachRequest($run, $source);
        $this->assertSame(0, LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'model_runtime_identity')->count());
        $this->assertFalse($service->archivePaperProvenance($parent, 'XAUUSD', '2027-05-02T00:00:00Z', $epoch)['allowed']);
        $this->prePaperAuthority($parent);
        $this->assertSame('withheld', $service->admit($parent, 'XAUUSD', 'H1', [...$passport, 'training_pre_2026' => true])['status']);
    }

    private function traitCandidate(): array
    {
        Storage::fake('trait_provenance_fixture'); config()->set('services.lab_evidence.disk', 'trait_provenance_fixture');
        $this->travelTo(CarbonImmutable::parse('2027-05-02T00:00:00Z'));
        config()->set('services.research_paper_epochs.authorized_paper_epochs', [[
            'protocol' => ResearchPaperEpochContractService::FUTURE_PAPER_PROTOCOL, 'window_key' => 'paper_2028',
            'authorization_id' => 'approved-forward-2028', 'purpose' => 'prospective_paper_forward', 'approved' => true,
            'authorized_at' => '2026-12-15T00:00:00Z', 'start_inclusive' => '2028-01-01T00:00:00Z',
            'end_exclusive' => '2029-01-01T00:00:00Z', 'candidate_must_be_frozen_before_observation' => true, 'research_uses_forbidden' => true,
        ]]);
        $archive = $this->producer('2025-12-22');
        $baseline = json_decode(json_encode($archive['control_parameters']), true);
        $this->assertSame('none', $baseline['volume_lane']);
        $this->assertSame('low_volume_risk_firewall', $archive['candidate_parameters']['volume_lane']);
        $this->assertGreaterThan($archive['control']['volume_fixture_execution']['vetoes'], $archive['candidate']['volume_fixture_execution']['vetoes']);
        $context = ['regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal', 'venue_phase' => 'london_am_fix',
            'direction' => 'BUY', 'strategy_family' => 'confirmation_entry_mtf', 'spread_atr_ratio' => .1];
        $operating = app(TradingInstrumentOperatingSystemService::class); $operating->seedDefaults();
        $playbook = PlaybookComposition::create(['playbook_key' => 'exact-volume-trait-bundle', 'label' => 'Synthetic exact volume trait',
            'symbol' => 'XAUUSD', 'timeframe' => 'M15', 'promotion_state' => 'research_only', 'instrument_keys' => ['volume_confirmation'],
            'preconditions' => [], 'metadata' => ['protocol' => 'exact_instrument_research_bundle_v1', 'primary_instrument_key' => 'volume_confirmation']]);
        $future = []; $manifests = [];
        foreach ([2, 3, 4] as $month) {
            $future[$month] = $this->producer(sprintf('2027-%02d-10', $month));
            $manifests[] = ['authorization_id' => 'source-'.$month, 'research_epoch_id' => 'synthetic-exact-postpaper',
                'start_inclusive' => sprintf('2027-%02d-01T00:00:00Z', $month), 'end_exclusive' => sprintf('2027-%02d-01T00:00:00Z', $month + 1),
                'dataset_sha256' => $future[$month]['control']['data_hash'], 'purpose' => 'instrument_independent_validation'];
        }
        config()->set('services.instrument_policy.authorized_research_windows', $manifests);
        $parent = ModelVersion::create(['name' => 'original archive baseline', 'strategy' => $archive['source_request']['strategy'],
            'version' => 'synthetic-source-v1', 'generation' => 1, 'status' => 'testing', 'parameters' => $baseline,
            'metadata' => $this->sealedMetadata($baseline, $archive)]);
        $firstPair = null;
        foreach ($manifests as $index => $manifest) {
            $this->travelTo(CarbonImmutable::parse('2027-05-02T00:00:00Z'));
            $month = $index + 2; $window = app(InstrumentResearchWindowService::class)->seal($manifest['authorization_id'], $manifest['dataset_sha256']);
            $facts = $this->exactValidationFacts($context, $window, 'actual-source-'.$month, 'volume_lane', 'none', 'low_volume_risk_firewall', $baseline);
            $pair = LabLearningLanePair::where('pair_key', $facts['source_receipt']['pair_key'])->firstOrFail(); $firstPair ??= $pair;
            foreach (['control', 'candidate'] as $arm) {
                $run = LabEvaluationRun::findOrFail($facts['source_receipt'][$arm.'_evidence_run_id']);
                $sourceModel = ModelVersion::findOrFail($run->model_version_id);
                $sourceModel->update(['strategy' => $parent->strategy, 'metadata' => [...$sourceModel->metadata,
                    ...$this->sealedMetadata($sourceModel->parameters, $future[$month]),
                    ...($arm === 'control' ? ['control_contract' => ['protocol' => 'frozen_control_v2', 'control_only' => true]] : [])]]);
                $this->originalRun($run, $future[$month], $arm, $manifest['end_exclusive']);
                $facts['source_receipt'][$arm.'_request_hash'] = $run->fresh()->request_hash;
                $facts['source_receipt'][$arm.'_response_hash'] = $run->fresh()->response_hash;
                $facts['source_receipt'][$arm.'_parameter_hash'] = app(ResearchPaperEpochContractService::class)->parameterHash($sourceModel->fresh()->parameters);
            }
            $execution = $future[$month]['control']['execution_hash'];
            $pair->update(['candidate_execution_hash' => $execution, 'control_execution_hash' => $execution]);
            $controlMap = $pair->controlResponseMap;
            $controlMap->update(['metadata' => array_replace_recursive($controlMap->metadata,
                ['control_contract' => ['execution_hash' => $execution]])]);
            $facts['source_receipt']['execution_hash'] = $execution;
            $evaluator = LabEvaluationRun::findOrFail($facts['source_receipt']['control_evidence_run_id'])->code_hash;
            $facts['source_receipt']['evaluator_hash'] = $evaluator;
            $facts['source_receipt'] = app(InstrumentValidationEvidenceService::class)->sealSource($facts['source_receipt']);
            $facts['tested_intervention'] = app(InstrumentValidationEvidenceService::class)->sealDelta('volume_lane', 'none', 'low_volume_risk_firewall',
                app(ResearchPaperEpochContractService::class)->parameterHash($baseline), $evaluator, $operating->fingerprint('XAUUSD', 'M15', $context));
            $pair = $pair->fresh(['candidateAgent.modelVersion', 'controlAgent.modelVersion', 'controlResponseMap']);
            $this->assertTrue($pair->isVerifiedControlPair(), 'Actual original control identity must remain exact.');
            foreach (['control', 'candidate'] as $sourceArm) {
                $sourceRun = LabEvaluationRun::findOrFail($facts['source_receipt'][$sourceArm.'_evidence_run_id']);
                $this->assertNotNull(app(LabImmutableEvidenceService::class)->verifiedModelRuntimeIdentity($sourceRun), 'Original model seal '.$sourceArm);
            }
            $this->assertTrue((new \ReflectionMethod(InstrumentValidationEvidenceService::class, 'sourceValid'))->invoke(
                app(InstrumentValidationEvidenceService::class), $facts['source_receipt'], $facts['tested_intervention'], $window), 'Actual original source owner sourceValid');
            $outcome = [...$facts, 'evidence_key' => 'source-outcome-'.$month, 'source_type' => 'synthetic_real_owner_fixture',
                'instrument_research_window_receipt' => $window, 'control_contract' => ['paired_isolated' => true, 'data_hash' => $manifest['dataset_sha256']],
                'metrics' => ['net_edge' => .03, 'cost_penalty' => .001, 'drawdown_penalty' => .2, 'profit_factor' => 1.2],
                'control_metrics' => ['net_edge' => 0, 'profit_factor' => 1.0]];
            $instrument = $operating->recordEvidence('volume_confirmation', 'XAUUSD', 'M15', $context, $outcome);
            $bundle = $operating->recordPlaybookEvidence($playbook->playbook_key, 'XAUUSD', 'M15', $context, [...$outcome, 'evidence_key' => 'bundle-'.$month]);
        }
        $this->assertSame('confirmed', $instrument->decay_state, json_encode(app(\App\Services\InstrumentPosteriorAuthorityService::class)->assess($instrument))); $this->assertSame('confirmed', $bundle->decay_state);
        $controlAgent = LabAgent::findOrFail($firstPair->control_agent_id);
        $archiveAgent = LabAgent::create(['lab_generation_id' => $controlAgent->lab_generation_id, 'model_version_id' => $parent->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'confirmation_entry_mtf', 'origin' => 'synthetic_test', 'lifecycle_status' => 'screened', 'parameter_diff' => []]);
        $archiveRun = LabEvaluationRun::create(['run_id' => (string) \Illuminate\Support\Str::uuid(), 'lab_agent_id' => $archiveAgent->id,
            'lab_generation_id' => $archiveAgent->lab_generation_id, 'model_version_id' => $parent->id, 'phase' => 'screening', 'mode' => 'synthetic_test',
            'attempt' => 1, 'status' => 'started']);
        $this->originalRun($archiveRun, $archive, 'control', '2026-12-30T00:00:00Z');
        $this->travelTo(CarbonImmutable::parse('2027-05-02T00:00:00Z'));
        $policy = app(LabInstrumentResearchService::class)->mutationPolicy('XAUUSD', 'confirmation_entry_mtf', [
            'regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal', 'venue_phase' => 'london_am_fix',
            'direction' => 'buy', 'spread_liquidity_state' => 'normal', 'transition_state' => 'stable']);
        $parameters = [...$baseline, 'volume_lane' => 'low_volume_risk_firewall'];
        $receipt = app(InstrumentPolicyConsumptionService::class)->receipt($policy, ['volume_lane' => ['old' => 'none', 'new' => 'low_volume_risk_firewall']], $parameters);
        $this->assertSame('policy_aligned_mutation_observed', $receipt['status']);
        $model = ModelVersion::create(['name' => 'one confirmed trait fork', 'strategy' => $parent->strategy, 'version' => 'synthetic-child-v1',
            'generation' => 2, 'status' => 'testing', 'parameters' => $parameters, 'metadata' => [...$this->sealedMetadata($parameters, $archive),
                'mutation_constructor_invariant' => ['parent_model_version_id' => $parent->id],
                'instrument_learning_policy' => $policy, 'instrument_learning_consumption' => $receipt]]);
        $this->prePaperAuthority($model);
        $passport = ['passport_hash' => str_repeat('c', 64), 'execution_hash' => $archive['control']['execution_hash'],
            'confirmation_entry_hash' => str_repeat('e', 64), 'risk_governor_hash' => str_repeat('f', 64), 'trade_management_hash' => str_repeat('1', 64),
            'training_pre_2026' => false, 'paper_window_key' => 'paper_2028'];
        return [$model, $parent, $passport, $firstPair];
    }

    private function originalRun(LabEvaluationRun $run, array $fixture, string $arm, string $finished): void
    {
        $this->travelTo(CarbonImmutable::parse($finished)->addHour());
        $agent = $run->agent()->with('modelVersion', 'generation')->firstOrFail();
        $assignment = app(LabInstrumentResearchService::class)->assignment($agent);
        $manifest = $fixture[$arm]['source_request']['mtf_snapshot_manifest'];
        $contract = app(LabAgentEvaluationService::class)->compositionRuntimeContract($agent, $assignment, 'M5',
            ['bundle_hash' => $fixture[$arm]['data_hash'], 'manifest' => $manifest], $fixture[$arm]['data_hash']);
        $this->assertSame('xauusd_composition_runtime_contract_v3', $contract['protocol']);
        $request = $fixture[$arm]['source_request'];
        $request['composition_runtime_contract'] = $contract;
        $request['instrument_research_assignment'] = $assignment;
        $request['parameters'] = $agent->modelVersion->parameters;
        foreach ($fixture['source_files_base64'] as $timeframe => $encoded) {
            $relative = 'original-source/'.$fixture[$arm]['data_hash'].'/'.$timeframe.'.csv';
            Storage::disk('trait_source_fixture')->put($relative, base64_decode($encoded, true));
            $path = Storage::disk('trait_source_fixture')->path($relative);
            $request['mtf_snapshot_manifest']['streams'][$timeframe]['path'] = $path;
            if ($timeframe === 'M5') $request['dataset_path'] = $path; else $request['mtf_dataset_paths'][$timeframe] = $path;
        }
        if (CarbonImmutable::parse($finished)->year >= 2027) {
            $request['evaluation_mode'] = 'full';
            $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
            $hashes = [];
            foreach ($agent->generation->agents()->with('modelVersion')->get() as $peer) {
                $peer->modelVersion->update(['metadata' => [...$peer->modelVersion->metadata, 'execution_contract' => $execution]]);
                $hashes[(string) $peer->id] = $execution['execution_hash'];
            }
            $release = array_diff_key($request['research_release'], array_flip(['release_hash', 'promotion_evidence', 'sealed_at']));
            $release['agent_execution_hashes'] = $hashes;
            $request['research_release'] = [...$release, 'release_hash' => app(ExecutionContractService::class)->hashParameters($release), 'promotion_evidence' => false];
            $agent->generation->update(['trigger_context' => ['research_release' => $request['research_release'],
                'mtf_bundle_hash' => $request['replay_dataset_hash'], 'mtf_bundle_manifest' => $request['mtf_snapshot_manifest']]]);
            $request = app(InstrumentResearchWindowService::class)->bindReplayRequest($agent->generation, $request);
            $this->assertNotEmpty(data_get($request, 'policy_context.authorized_research_transport.hmac_sha256'));
        }
        foreach (['mtf_streams', 'mtf_dataset_tail_rows', 'related_mtf_streams', 'related_mtf_dataset_paths', 'related_mtf_dataset_tail_rows',
            'specialist_context_contract', 'volume_context', 'runtime_ensemble_policy', 'policy_context'] as $map) {
            if (($request[$map] ?? null) === []) $request[$map] = new \stdClass;
        }
        $fixture = $this->producer($fixture['source_date'], [$arm => ['source_request' => $request,
            'test_data_root' => $this->sourceRoot, 'fixture_clock' => now()->toIso8601String(), 'fixture_only_internal_key' => self::INTERNAL_KEY]]);
        $request = $fixture[$arm]['source_request'];
        $evidence = app(LabImmutableEvidenceService::class);
        $run->update(['status' => 'started', 'started_at' => now(), 'finished_at' => null,
            'code_hash' => $request['research_release']['source_hash'], 'data_hash' => $fixture[$arm]['data_hash'],
            'parameter_hash' => $evidence->parameterHash($run->agent()->firstOrFail())]);
        $evidence->attachRequest($run, $request);
        $evidence->finishRun($run, 'completed', array_diff_key($fixture[$arm], ['source_request' => true]));
    }

    private function producer(string $date, array $arms = []): array
    {
        $source = app(LabImmutableEvidenceService::class)->codeHash(); $python = app(ResearchReleaseSealService::class)->pythonHash();
        $arms['execution_parameters'] = app(ExecutionContractService::class)->parameters('XAUUSD');
        $key = $date.'|'.$source.'|'.$python.'|'.hash('sha256', json_encode($arms));
        if (! isset(self::$producerCache[$key])) {
            $path = base_path('../ai-service-python/tests/support/semantic_stage_receipt_fixture.py');
            if (getenv('POST_PAPER_TRAIT_STAGE') === '1') $path = base_path('../.runtime/post-paper-trait-staging-2026-10-03/semantic_stage_receipt_fixture.py');
            $process = new Process(['python', $path, '--full-runtime-source-hash', $source, '--python-source-hash', $python,
                '--start-date', $date, '--include-sources', '--confirmed-volume-trait', '--confirmation-source', '--arm-inputs-stdin', '--control-limit', '1', '--candidate-limit', '1', '--blinded-limit', '1'], base_path('../ai-service-python'));
            $process->setInput(json_encode($arms));
            $process->setTimeout(60); $process->run(); $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            self::$producerCache[$key] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        $this->assertFalse(self::$producerCache[$key]['market_replay_proven']);
        return self::$producerCache[$key];
    }

    private function sealedMetadata(array $parameters, array $fixture): array
    {
        $canonical = app(StrategyParameterSchemaService::class)->canonicalizeForIdentity('confirmation_entry_mtf', $parameters);
        return app(CompositionAuthorityKernelService::class)->confirmationReplayMetadata([
            'execution_contract' => app(ExecutionContractService::class)->for('XAUUSD', 'M5'),
            'parameter_fingerprint' => hash('sha256', 'confirmation_entry_mtf|'.json_encode($canonical, JSON_PRESERVE_ZERO_FRACTION)),
            'universal_genome' => ['local_adapter' => ['parameters_hash' => hash('sha256', json_encode($canonical, JSON_PRESERVE_ZERO_FRACTION))]]],
            ['data_hash' => $fixture['control']['data_hash'], 'execution_hash' => $fixture['control']['execution_hash'],
                'mtf_bundle_manifest' => $fixture['source_request']['mtf_snapshot_manifest']]);
    }

    private function prePaperAuthority(ModelVersion $model): void
    {
        $checks = ['research_mentor_authority' => true, 'screening_passed' => true, 'full_replay_passed' => true,
            'positive_absolute_settlement' => true, 'forward_or_paper_evidence' => false, 'performance_credit_earned' => false,
            'two_improving_descendants' => true, 'two_inheritance_credits_earned' => true, 'context_trust_confirmed' => true];
        DB::table('evolutionary_authority_ledgers')->insert(['authority_key' => hash('sha256', 'fixture-'.$model->id),
            'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'confirmation_entry_mtf',
            'authority_stage' => 'breeder_candidate', 'status' => 'research_mentor_granted', 'data_hash' => str_repeat('a', 64),
            'execution_hash' => str_repeat('b', 64), 'evidence' => json_encode(['incubation_passed' => true, 'passport' => ['passed' => true],
                'research_mentor_authority' => ['eligible' => true], 'economic_parent_authority' => ['eligible' => false, 'checks' => $checks],
                'promotion_evidence' => false]), 'evaluated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
