<?php

namespace Tests\Feature;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AiLaboratory;
use App\Models\DescendantValueTrial;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvolutionCreditEvent;
use App\Models\LabGeneration;
use App\Models\LabSkillZooEntry;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentWorkItem;
use App\Models\ResearchExposureCaptureRecord;
use App\Services\AutonomousModeService;
use App\Services\CausalSkillCreditBridgeService;
use App\Services\ContextContractV2Service;
use App\Services\DescendantScopedExecutionService;
use App\Services\DescendantScopedProofService;
use App\Services\EvolutionaryAuthorityFoundryService;
use App\Services\ExecutionContractService;
use App\Services\InstrumentResearchWindowService;
use App\Services\LabImmutableEvidenceService;
use App\Services\MultiTimeframeSnapshotService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\ScopedResearchCertificateService;
use App\Services\StrategyParameterSchemaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Actual fixture bytes/artifacts test conditional software acceptance, never market edge. */
class ScopedResearchAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureRoot;

    private string $previousStorage;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        config()->set('services.instrument_policy.authorized_research_windows', []);
        config()->set('services.research_paper_epochs.authorized_paper_epochs', []);
        config()->set('services.learning_lane.causal_minimum_trades_per_window', 8);
        config()->set('services.learning_lane.causal_minimum_powered_windows', 6);
        config()->set('services.learning_lane.causal_minimum_positive_windows', 4);
        $this->travelTo(CarbonImmutable::parse('2026-10-09T00:00:00Z'));
        $this->fixtureRoot = sys_get_temp_dir().'/scoped-authority-software-'.bin2hex(random_bytes(8));
        $this->previousStorage = storage_path();
        app()->useStoragePath($this->fixtureRoot.'/storage');
        config()->set('filesystems.disks.local.root', $this->fixtureRoot.'/storage/app');
    }

    protected function tearDown(): void
    {
        app()->useStoragePath($this->previousStorage);
        if ($this->status()->isFailure() || $this->status()->isError()) {
            fwrite(STDERR, 'Synthetic original authority diagnostic fixture retained: '.$this->fixtureRoot."\n");
            parent::tearDown();

            return;
        }
        $resolved = realpath($this->fixtureRoot);
        $prefix = str_replace('\\', '/', realpath(sys_get_temp_dir())).'/scoped-authority-software-';
        if ($resolved && str_starts_with(str_replace('\\', '/', $resolved), $prefix)) {
            File::deleteDirectory($resolved);
        }
        parent::tearDown();
    }

    public function test_equal_useful_guided_and_blinded_vectors_can_confirm_component_without_selector_credit(): void
    {
        $fixture = $this->question('guided');
        $this->assertSame($fixture['agents']['guided']->modelVersion->parameters, $fixture['agents']['blinded']->modelVersion->parameters);
        $products = $this->originalProducts($fixture, .3);
        $registry = app(ScopedResearchCertificateService::class);
        $result = $registry->issueIndependent($fixture['certificate_id'], $products);

        $this->assertSame('independently_confirmed_component', $result['status'], json_encode($result));
        $this->assertTrue($result['confirmed_component']);
        $proof = $result['original_authority']['component'];
        $this->assertTrue($proof['confirmed']);
        $this->assertSame('context_bound_research_component', $proof['authority_type']);
        $this->assertSame(6, $proof['positive_windows']);
        $this->assertEqualsWithDelta(.3, $proof['statistics']['mean'], 1e-12);
        $this->assertGreaterThan(0, $proof['statistics']['lower_bound']);
        $this->assertFalse($proof['selector_superiority_required']);
        $this->assertFalse($proof['parent_eligible']);
        $this->assertFalse($proof['paper_or_live_authority']);
        $this->assertFalse($result['confirmed_selector']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->assertSame($result['authority_record_id'], $registry->issueIndependent($fixture['certificate_id'], $products)['authority_record_id']);

        $bridge = app(CausalSkillCreditBridgeService::class);
        $credit = $bridge->settleScopedCertificate($fixture['certificate_id']);
        $this->assertSame('credited', $credit['status'], json_encode($credit));
        $repeat = $bridge->settleScopedCertificate($fixture['certificate_id']);
        $this->assertSame($credit['event_id'], $repeat['event_id']);
        $this->assertFalse($repeat['newly_recorded']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 1);
        $event = LabEvolutionCreditEvent::findOrFail($credit['event_id']);
        $this->assertSame('causal_skill_credit', $event->event_type);
        $this->assertSame('component', $event->payload['authority_scope']);
        $this->assertNull($event->parent_model_version_id);
        $this->assertFalse($event->payload['selector_authority_granted']);
        $this->assertFalse($event->payload['global_parent_authority']);
        $cartridge = LabSkillZooEntry::findOrFail($credit['cartridge']['cartridge_id']);
        $this->assertSame('scoped_confirmed', $cartridge->status);
        $this->assertSame('scoped_component_confirmed', $cartridge->component_status);
        $this->assertNull($cartridge->genetic_parent_model_version_id);
        $this->assertSame('exact_trait_and_context_only', $cartridge->evidence['research_mentor_scope']);
        $this->assertDatabaseCount('skill_cartridge_revisions', 1);
        $this->assertSame('SCOPED_ORIGINAL_CERTIFICATE_REQUIRED_NOT_LEGACY_CREDIT', $bridge->settle($fixture['source']->fresh())['reason_code']);
    }

    public function test_blinded_role_has_its_own_positive_component_products(): void
    {
        $fixture = $this->question('blinded');
        $products = $this->originalProducts($fixture, .3);
        $result = app(ScopedResearchCertificateService::class)->issueIndependent($fixture['certificate_id'], $products);
        $this->assertTrue($result['scope_authority_confirmed'], json_encode($result));
        $this->assertSame((int) $fixture['agents']['blinded']->id, $result['original_authority']['component']['candidate_agent_id']);
        $this->assertFalse($result['confirmed_selector']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_negative_and_null_original_effects_are_terminal_without_credit(): void
    {
        foreach ([-.2, 0.0] as $index => $delta) {
            // Separate physical future years keep these two sealed scopes disjoint.
            $fixture = $this->question('guided', 2027 + $index);
            $products = $this->originalProducts($fixture, $delta);
            $result = app(ScopedResearchCertificateService::class)->issueIndependent($fixture['certificate_id'], $products);
            $this->assertSame('independent_negative_or_inconclusive', $result['status'], json_encode($result));
            $this->assertFalse($result['scope_authority_confirmed']);
            $this->assertSame('negative_or_inconclusive', $result['original_authority']['component']['status']);
            $this->assertSame('ORIGINAL_SCOPE_CERTIFICATE_NOT_CONFIRMED',
                app(CausalSkillCreditBridgeService::class)->settleScopedCertificate($fixture['certificate_id'])['reason_code']);
        }
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->assertDatabaseCount('lab_skill_zoo_entries', 0);
    }

    public function test_missing_window_and_reused_window_labels_never_complete_a_sealed_roster(): void
    {
        $fixture = $this->question('guided');
        $products = $this->originalProducts($fixture, .3);
        $registry = app(ScopedResearchCertificateService::class);
        $missing = $registry->issueIndependent($fixture['certificate_id'], array_slice($products, 0, 5));
        $this->assertFalse($missing['scope_authority_confirmed']);
        $this->assertSame('ALL_PREREGISTERED_WINDOW_PRODUCTS_REQUIRED', $missing['issuance']['reason_code']);
        $duplicate = $products;
        $duplicate[5] = $duplicate[0];
        $reused = $registry->issueIndependent($fixture['certificate_id'], $duplicate);
        $this->assertFalse($reused['scope_authority_confirmed']);
        $this->assertSame('ORIGINAL_WINDOW_PRODUCTS_NOT_PREREGISTERED', $reused['issuance']['reason_code']);
        $this->assertDatabaseMissing('scoped_research_certificates', ['record_type' => 'independent_assessment']);
    }

    public function test_complete_original_products_with_zero_or_few_context_trades_close_underpowered_without_credit(): void
    {
        foreach ([0, 3] as $index => $contextTrades) {
            $fixture = $this->question('guided', 2027 + $index);
            $products = $this->originalProducts($fixture, .3, contextTrades: $contextTrades);
            $this->assertCount(6, $products);
            $result = app(ScopedResearchCertificateService::class)->issueIndependent($fixture['certificate_id'], $products);
            $this->assertSame('independent_negative_or_inconclusive', $result['status'], json_encode($result));
            $this->assertFalse($result['confirmed_component']);
            $this->assertFalse($result['scope_authority_confirmed']);
            $proof = $result['original_authority']['component'];
            $this->assertSame('original_context_windows_underpowered', $proof['reason_code']);
            $this->assertCount(12, $proof['underpowered']);
            $this->assertCount(6, $proof['original_windows']);
            $this->assertSame('ORIGINAL_SCOPE_CERTIFICATE_NOT_CONFIRMED',
                app(CausalSkillCreditBridgeService::class)->settleScopedCertificate($fixture['certificate_id'])['reason_code']);
        }
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->assertDatabaseCount('lab_skill_zoo_entries', 0);
    }

    public function test_risk_regression_is_measured_terminal_refusal_even_with_positive_utility(): void
    {
        $fixture = $this->question('guided');
        $products = $this->originalProducts($fixture, .3, candidateDrawdown: 8);
        $result = app(ScopedResearchCertificateService::class)->issueIndependent($fixture['certificate_id'], $products);
        $this->assertSame('independent_negative_or_inconclusive', $result['status'], json_encode($result));
        $proof = $result['original_authority']['component'];
        $this->assertSame('unsafe_or_incomplete_non_target', $proof['reason_code']);
        $this->assertContains('drawdown', $proof['comparison']['non_target']['regressed_metrics']);
        $this->assertFalse($proof['confirmed']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_actual_reused_dataset_cannot_be_relabelled_as_a_second_month(): void
    {
        $fixture = $this->question('guided');
        $products = $this->originalProducts($fixture, .3);
        $records = config('services.instrument_policy.authorized_research_windows');
        $records[1]['dataset_sha256'] = $records[0]['dataset_sha256'];
        $records[1]['mtf_bundle_manifest'] = $records[0]['mtf_bundle_manifest'];
        config()->set('services.instrument_policy.authorized_research_windows', $records);
        $result = app(ScopedResearchCertificateService::class)->issueIndependent($fixture['certificate_id'], $products);
        $this->assertFalse($result['scope_authority_confirmed']);
        $this->assertSame('blocked_dependency', $result['issuance']['status']);
        $this->assertDatabaseMissing('scoped_research_certificates', ['record_type' => 'independent_assessment']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_caller_evidence_flags_without_original_products_cannot_issue_or_credit(): void
    {
        $fixture = $this->question('guided');
        $this->inputs($fixture);
        $fixture['source']->update(['status' => 'confirmed', 'guided_beats_control' => true, 'guided_beats_blinded' => true,
            'evidence' => ['experiment_kind' => 'memory_confirmation', 'authority' => true, 'component_effect' => ['passed' => true]]]);
        $result = app(ScopedResearchCertificateService::class)->issueIndependent($fixture['certificate_id'],
            ['confirmed' => true, 'component_credit' => true, 'valid_original_products' => true]);
        $this->assertFalse($result['scope_authority_confirmed']);
        $this->assertSame('ALL_PREREGISTERED_WINDOW_PRODUCTS_REQUIRED', $result['issuance']['reason_code']);
        $this->assertSame('ORIGINAL_SCOPE_CERTIFICATE_NOT_CONFIRMED',
            app(CausalSkillCreditBridgeService::class)->settleScopedCertificate($fixture['certificate_id'])['reason_code']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->assertDatabaseMissing('scoped_research_certificates', ['record_type' => 'independent_assessment']);
    }

    public static function inheritanceOutcomes(): array
    {
        return ['original T retained' => [true], 'ordinary child gain does not retain T' => [false]];
    }

    public function test_actual_native_component_request_signs_and_loads_one_whole_original_python_arm(): void
    {
        // Synthetic prices, real PHP compiler/release/transport and isolated
        // Python native producer. No outcome or economic authority is mocked.
        $namespace = 'scoped-native-software-'.bin2hex(random_bytes(8));
        app()->useStoragePath($this->previousStorage);
        config()->set('filesystems.disks.local.root', storage_path('app'));
        config()->set('services.internal_api.token', str_repeat('scoped-native-software-key-', 2));
        $cleanup = storage_path('app/lab-datasets/'.$namespace);
        try {
            $old = $this->fullParameterQuestion();
            $old['source']->generation->update(['status' => 'completed', 'completed_at' => now()]);
            $policy = ['protocol' => 'scoped_original_full_source_v1', 'evaluation_mode' => 'full',
                'selection' => 'entire_authorized_source', 'maximum_source_rows' => 4096, 'maximum_runtime_seconds' => 300,
                'warmup_rows' => 0, 'no_walk_forward_selection' => true, 'promotion_evidence' => false];
            $authorizationIds = array_map(fn ($index) => $namespace.'-'.$index, range(0, 5));
            $fresh = app(EvolutionaryAuthorityFoundryService::class)->preregisterScopedComponent($old['source'], [
                ...$old['design'], 'native_execution' => ['authorization_ids' => $authorizationIds, 'execution_timeframe' => 'M5',
                    'initial_capital' => 10000, 'risk_policy' => ['risk_per_trade_percent' => .5], 'full_replay_runtime_policy' => $policy]]);
            $this->assertSame('scoped_preregistered', $fresh['status'], json_encode($fresh));
            $fixture = [...$old, 'source' => (object) ['id' => $namespace]];
            $windows = $this->inputs($fixture);
            $authorized = config('services.instrument_policy.authorized_research_windows');
            foreach ($authorized as $index => &$record) {
                $record['authorization_id'] = $authorizationIds[$index];
            }
            unset($record);
            config()->set('services.instrument_policy.authorized_research_windows', $authorized);
            $certificateId = $fresh['certificate']['certificate_id'];
            $native = app(DescendantScopedExecutionService::class);
            $work = ResearchExperimentWorkItem::where('work_type', DescendantScopedExecutionService::COMPONENT_WORK_TYPE)->sole();
            $this->mock(AutonomousModeService::class, fn (MockInterface $mock) => $mock->shouldReceive('enabled')->andReturnTrue());
            $work->update(['status' => 'leased', 'attempts' => 1, 'lease_token' => 'actual-native-software-lease',
                'fence_version' => 1, 'lease_expires_at' => now()->addSeconds(2700)]);
            $ready = $native->inspectWork($work->fresh('receipt'));
            $this->assertTrue($ready['executable'], json_encode($ready));
            $this->assertCount(6, $ready['windows']);
            $prepared = $native->execute($work->fresh('receipt'));
            $this->assertSame('checkpointed', $prepared['status'], json_encode($prepared));
            $entry = $ready['windows'][0];
            $generationId = data_get($work->fresh()->result, 'matrix_preparation.'.$entry['window']['window_key'].'.generation_id');
            $generation = LabGeneration::findOrFail($generationId);
            $modelId = $ready['design']['subject']['arm_models']['candidate']['model_version_id'];
            $agent = $generation->agents()->with('modelVersion')->where('model_version_id', $modelId)->sole();
            $rawModel = $agent->modelVersion->getRawOriginal();
            $request = $native->compileRequest($generation, $agent, 'candidate', [...$ready, ...$entry]);
            $this->assertSame($rawModel, $agent->modelVersion->fresh()->getRawOriginal());
            $this->assertSame(InstrumentResearchWindowService::SCOPED_ORIGINAL_BUNDLE_PROTOCOL, data_get($request, 'mtf_snapshot_manifest.validation_bundle_protocol'));
            $this->assertSame('scoped_original_window_v1', data_get($request, 'policy_context.authorized_research_transport.scoped_original_window.protocol'));
            $this->assertSame($certificateId, data_get($request, 'policy_context.authorized_research_transport.scoped_original_window.certificate_id'));
            $this->assertSame($policy, data_get($request, 'policy_context.full_replay_runtime_policy'));
            $this->assertSame([], (array) $request['strategies'][0]['instrument_research_assignment']);
            $export = $cleanup.'/native-original-request.json';
            File::put($export, json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $process = new Process([getenv('SCOPED_PYTHON_EXECUTABLE') ?: 'python',
                base_path('tests/Fixtures/scoped_selector_python_admission.py'), $export, now()->utc()->toIso8601String(), '--native-replay'],
                dirname(base_path()).'/ai-service-python', ['PYTHONPATH' => dirname(base_path()).'/ai-service-python',
                    'PYTHONDONTWRITEBYTECODE' => '1', 'SCOPED_SELECTOR_FIXTURE_INTERNAL_KEY' => config('services.internal_api.token'),
                    'AI_REPLAY_IMMUTABLE_CACHE_DIR' => $cleanup.'/replay-cache',
                    'INTERNAL_API_TOKEN' => config('services.internal_api.token'), 'INTERNAL_API_TOKEN_FILE' => '']);
            $process->setTimeout(180);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $outputLines = preg_split('/\r?\n/', trim($process->getOutput()));
            $proof = json_decode(end($outputLines), true, flags: JSON_THROW_ON_ERROR);
            $this->assertTrue($proof['schema']);
            $this->assertTrue($proof['transport']);
            $this->assertTrue($proof['maturity_fence']);
            $this->assertTrue($proof['closed_mtf_context']);
            $this->assertSame(1, $proof['original_arm_count']);
            $this->assertTrue($proof['native_original_full_replay']);
            $this->assertSame(208, $proof['native_input_rows']);
            $this->assertSame(8, $proof['native_executed_clock_rows']);
            $this->assertFalse($proof['promotion_evidence']);
            $this->assertDatabaseCount('lab_evaluation_runs', 0);
            $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        } finally {
            $resolved = realpath($cleanup);
            $root = realpath($this->previousStorage.'/app/lab-datasets');
            if ($resolved && $root && str_replace('\\', '/', $resolved) === str_replace('\\', '/', $root).'/'.$namespace) {
                File::deleteDirectory($resolved);
            }
        }
    }

    #[DataProvider('inheritanceOutcomes')]
    public function test_original_four_arm_products_require_conditional_retention_before_inheritance_credit(bool $retained): void
    {
        // All results are explicit software fixtures. Real immutable owner
        // rows/bytes/capture/issuer/handoff are exercised, not market edge.
        $parent = $this->fullParameterQuestion();
        $registry = app(ScopedResearchCertificateService::class);
        $bridge = app(CausalSkillCreditBridgeService::class);
        $parentProducts = $this->originalProducts($parent, .3);
        $parentAuthority = $registry->issueIndependent($parent['certificate_id'], $parentProducts);
        $this->assertTrue($parentAuthority['original_authority']['component']['confirmed'], json_encode($parentAuthority));
        $parentCredit = $bridge->settleScopedCertificate($parent['certificate_id']);
        $this->assertSame('credited', $parentCredit['status'], json_encode($parentCredit));
        $parent['source']->generation->update(['status' => 'completed', 'completed_at' => now()]);
        $parentAuthorizations = config('services.instrument_policy.authorized_research_windows');
        $child = $this->fourArmQuestion($parent, $parentCredit['cartridge']['cartridge_id']);
        $windows = $this->inputs($child);
        config()->set('services.instrument_policy.authorized_research_windows', [...$parentAuthorizations,
            ...config('services.instrument_policy.authorized_research_windows')]);
        $immutable = app(LabImmutableEvidenceService::class);
        $products = [];
        $pfs = ['P' => 1.1, 'P+T' => 1.4, 'P+T+U' => $retained ? 1.5 : 1.2, 'P+U' => 1.2];
        foreach ($windows as $window) {
            $ids = [];
            foreach (DescendantScopedProofService::ARMS as $arm) {
                $agent = $child['agents'][$arm]->fresh(['modelVersion', 'generation']);
                $request = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'evaluation_mode' => 'full',
                    'dataset_path' => $window['manifest']['streams']['M5']['path'], 'replay_dataset_hash' => $window['manifest']['bundle_hash'],
                    'mtf_snapshot_manifest' => $window['manifest'], 'mtf_dataset_paths' => array_map(fn ($record) => $record['path'], $window['manifest']['streams']),
                    'maximum_holding_bars' => 1, 'execution_contract' => ['execution_hash' => $child['design']['execution_hash']],
                    'strategies' => [['lab_agent_id' => (int) $agent->id, 'strategy' => $agent->modelVersion->strategy,
                        'parameters' => $agent->modelVersion->parameters]]];
                $run = $immutable->beginRun($agent, 'full_validation', 'full', ['code_hash' => $child['design']['evaluator_hash']]);
                $immutable->attachRequest($run, $request, ['data_hash' => $window['manifest']['bundle_hash']]);
                $immutable->finishRun($run, 'completed', $this->nativeFixtureResult($child, $window, $pfs[$arm], 5));
                $this->assertNotNull($immutable->verifiedModelRuntimeIdentity($run->fresh()));
                $ids[$arm] = (int) $run->id;
            }
            $products[] = ['window_key' => $window['window']['window_key'], 'run_ids' => $ids];
        }
        $this->assertSame(24, ResearchExposureCaptureRecord::where('certificate_id', $child['certificate_id'])->where('record_type', 'request_ingress')->count());
        $this->assertDatabaseCount('lab_evolution_credit_events', 1);
        $issued = $registry->issueIndependent($child['certificate_id'], $products);
        $proof = (array) data_get($issued, 'original_authority.inheritance');
        $this->assertSame($retained, $proof['confirmed'] ?? null, json_encode($issued));
        $this->assertSame('context_bound_research_inheritance', $proof['authority_type']);
        $this->assertFalse($proof['paper_or_live_authority']);
        $this->assertFalse($proof['parent_eligible']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 1); // Issuer alone never grants credit.
        $terminal = app(DescendantScopedProofService::class)->recordIndependentTerminal($child['trial_id'], $child['certificate_id']);
        $this->assertSame($retained ? 'scoped_original_confirmed' : 'scoped_original_negative_or_inconclusive', $terminal['status'], json_encode($terminal));
        $credit = $bridge->settleScopedCertificate($child['certificate_id']);
        if ($retained) {
            $this->assertSame('credited', $credit['status'], json_encode($credit));
            $this->assertSame('inheritance', $credit['scope']);
            $this->assertSame($credit['event_id'], $bridge->settleScopedCertificate($child['certificate_id'])['event_id']);
            $event = LabEvolutionCreditEvent::findOrFail($credit['event_id']);
            $this->assertSame('inheritance_credit', $event->event_type);
            $this->assertSame($child['agents']['P+T+U']->model_version_id, $event->model_version_id);
            $this->assertNull($event->parent_model_version_id);
            $this->assertFalse($event->payload['global_parent_authority']);
            $this->assertDatabaseCount('lab_evolution_credit_events', 2);
        } else {
            $this->assertSame('ORIGINAL_SCOPE_CERTIFICATE_NOT_CONFIRMED', $credit['reason_code']);
            $this->assertDatabaseCount('lab_evolution_credit_events', 1);
        }
    }

    /** Full exact source vectors keep P/PT definitions unchanged in the fresh four-arm question. */
    private function fullParameterQuestion(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-09T00:00:00Z'));
        $schema = app(StrategyParameterSchemaService::class);
        $base = $schema->validate('hybrid', [...array_intersect_key($schema->defaults('hybrid'), $schema->schema('hybrid')),
            'minimum_confidence' => .55, 'time_stop_candles' => 1]);
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        $lab = AiLaboratory::firstOrCreate(['symbol' => 'XAUUSD', 'timeframe' => 'M5'],
            ['name' => 'Synthetic full-vector scoped issuer', 'strategy_families' => ['hybrid'], 'is_active' => false]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => LabGeneration::count() + 1,
            'trigger_type' => 'synthetic_full_vector_fixture', 'population_size' => 3, 'status' => 'draft']);
        $agents = [];
        $models = [];
        foreach (['guided', 'blinded', 'control'] as $role) {
            $parameters = [...$base, 'minimum_confidence' => $role === 'control' ? .55 : .6];
            $model = ModelVersion::create(['name' => 'synthetic-full-'.$role, 'strategy' => 'hybrid', 'version' => 'full-source-'.$role,
                'status' => 'testing', 'parameters' => $parameters, 'metadata' => ['execution_contract' => $execution]]);
            $parameters = $model->fresh()->parameters;
            $agents[$role] = LabAgent::withoutEvents(fn () => LabAgent::create(['lab_generation_id' => $generation->id,
                'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'strategy_family' => 'hybrid',
                'origin' => 'synthetic_full_vector_fixture', 'lifecycle_status' => 'draft',
                'parameter_diff' => $role === 'control' ? [] : ['minimum_confidence' => ['old' => .55, 'new' => .6]]]));
            $models[$role] = ['model_version_id' => (int) $model->id,
                'parameter_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($parameters)];
        }
        $source = AgentLearningCausalExperiment::create(['experiment_key' => 'synthetic-full-source-'.$generation->id,
            'lab_generation_id' => $generation->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'strategy_family' => 'hybrid',
            'target' => 'profit_factor', 'gene_key' => 'minimum_confidence', 'status' => 'ready_for_replay',
            'guided_agent_id' => $agents['guided']->id, 'blinded_agent_id' => $agents['blinded']->id, 'control_agent_id' => $agents['control']->id,
            'evidence' => ['experiment_kind' => 'memory_confirmation']]);
        $context = ['regime' => 'trend_up', 'volatility' => 'normal', 'session' => 'london', 'venue_phase' => 'london_interfix', 'direction' => 'BUY'];
        $periods = [];
        foreach (range(1, 6) as $month) {
            $from = CarbonImmutable::create(2027, $month, 1, 0, 0, 0, 'UTC');
            $periods[] = ['start_inclusive' => $from->toIso8601String(), 'end_exclusive' => $from->addMonth()->toIso8601String()];
        }
        $hash = app(LabImmutableEvidenceService::class)->codeHash();
        $design = ['authority_policy' => ScopedResearchCertificateService::AUTHORITY_POLICY,
            'validation_start' => $periods[0]['start_inclusive'], 'validation_end' => $periods[5]['end_exclusive'], 'validation_windows' => $periods,
            'evaluator_hash' => $hash, 'owner_source_hash' => $hash, 'context_hash' => app(ContextContractV2Service::class)->project($context)['identity_hash'],
            'execution_hash' => app(ExecutionContractService::class)->for('XAUUSD', 'M5')['execution_hash'],
            'metric' => 'profit_factor', 'stopping_rule' => ['minimum_trades_per_arm' => 8, 'minimum_effect' => .05, 'minimum_positive_windows' => 4],
            'statistical_guard' => ['method' => 'paired_window_bootstrap_percentile', 'replicates' => 500, 'seed' => 42, 'lower_quantile' => .05],
            'subject' => ['candidate_role' => 'guided', 'arm_models' => $models, 'context' => $context,
                'trait_delta' => ['gene' => 'minimum_confidence', 'old' => .55, 'new' => .6]],
            'exposure_policy' => ['protocol' => 'prospective_scoped_exposure_policy_v1', 'holding_fence_seconds' => 600,
                'execution_timeframe' => 'M5', 'context_timeframes' => ['H4', 'H1', 'M15'],
                'warmup_policy' => 'all_original_closed_source_rows_inside_registered_window', 'selection_policy' => 'frozen_before_first_event']];
        $registered = app(ScopedResearchCertificateService::class)->register('component', $source, $design);
        $this->assertTrue($registered['valid'], json_encode($registered));

        return ['certificate_id' => $registered['certificate_id'], 'source' => $source, 'agents' => $agents, 'design' => $design,
            'context' => $context, 'periods' => $periods, 'candidate_role' => 'guided', 'year' => 2027];
    }

    /** The test registry ingress is prospective; it supplies no caller outcome/authority flags. */
    private function fourArmQuestion(array $parent, int $cartridgeId): array
    {
        $epochs = app(ResearchPaperEpochContractService::class);
        $cartridge = LabSkillZooEntry::findOrFail($cartridgeId);
        $control = $parent['agents']['control']->fresh('modelVersion')->modelVersion;
        $candidate = $parent['agents']['guided']->fresh('modelVersion')->modelVersion;
        $base = $control->parameters;
        $trait = $candidate->parameters;
        $other = 'trend_ema_period';
        $otherValue = $base[$other] + 1;
        $vectors = ['P' => $base, 'P+T' => $trait, 'P+T+U' => [...$trait, $other => $otherValue], 'P+U' => [...$base, $other => $otherValue]];
        $lab = $parent['source']->generation->laboratory;
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => ((int) $lab->generations()->max('generation')) + 1,
            'trigger_type' => 'synthetic_original_four_arm_fixture', 'population_size' => 4, 'status' => 'draft']);
        $models = [];
        $agents = [];
        $parameters = [];
        foreach ($vectors as $arm => $vector) {
            $source = in_array($arm, ['P', 'P+U'], true) ? $control : $candidate;
            $version = 'fresh-original-'.str_replace('+', '-', $arm);
            $model = ModelVersion::create(['name' => 'synthetic four arm '.$arm, 'strategy' => $source->strategy, 'version' => $version,
                'status' => 'testing', 'parameters' => $vector, 'metadata' => app(EvolutionaryAuthorityFoundryService::class)
                    ->scopedChildMetadata($source, ['new_version' => $version, 'arm' => $arm, 'source_hypothesis_only' => true], $vector)]);
            $model = $model->fresh();
            $parameters[$arm] = $model->parameters;
            $models[$arm] = ['model_version_id' => (int) $model->id, 'parameter_hash' => $epochs->parameterHash($model->parameters),
                'runtime_hash' => $epochs->parameterHash(app(LabImmutableEvidenceService::class)->modelRuntimeBasis($model))];
            $agents[$arm] = LabAgent::withoutEvents(fn () => LabAgent::create(['lab_generation_id' => $generation->id,
                'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'strategy_family' => 'hybrid',
                'origin' => 'synthetic_four_arm_fixture', 'lifecycle_status' => 'draft']));
        }
        $this->assertSame($base, $parameters['P']);
        $this->assertSame($trait, $parameters['P+T']);
        $periods = [];
        foreach (range(1, 6) as $month) {
            $from = CarbonImmutable::create(2029, $month, 1, 0, 0, 0, 'UTC');
            $periods[] = ['start_inclusive' => $from->toIso8601String(), 'end_exclusive' => $from->addMonth()->toIso8601String()];
        }
        $revision = DB::table('skill_cartridge_revisions')->where('lab_skill_zoo_entry_id', $cartridge->id)->where('revision', $cartridge->revision)->sole();
        $topology = app(DescendantScopedProofService::class)->topology($parameters, $cartridge->gene_key);
        $design = app(ScopedResearchCertificateService::class)->normalizeProspectiveDesign('inheritance', [
            ...$parent['design'], 'protocol' => DescendantScopedProofService::PROTOCOL,
            'validation_start' => $periods[0]['start_inclusive'], 'validation_end' => $periods[5]['end_exclusive'], 'validation_windows' => $periods,
            'owner_source_hash' => hash_file('sha256', app_path('Services/DescendantScopedProofService.php')),
            'source_component_certificate_id' => $parent['certificate_id'],
            'subject' => ['arm_models' => $models, 'arm_parameters' => $parameters, 'context' => $parent['context'],
                'source_component_certificate_id' => $parent['certificate_id'], 'source_cartridge' => ['id' => (int) $cartridge->id,
                    'key' => $cartridge->cartridge_key, 'revision' => (int) $cartridge->revision,
                    'revision_payload_hash' => $epochs->parameterHash(json_decode($revision->payload, true))],
                'trait_delta' => $topology['trait_delta'], 'other_delta' => $topology['other_delta']]]);
        $designHash = $epochs->parameterHash($design);
        $trial = DescendantValueTrial::create(['trial_key' => $epochs->parameterHash(['synthetic_original_four_arm', $designHash]),
            'mentor_model_version_id' => $models['P+T']['model_version_id'], 'child_model_version_id' => $models['P+T+U']['model_version_id'],
            'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'strategy_family' => 'hybrid', 'window_key' => $epochs->parameterHash($periods),
            'status' => 'scoped_preregistered', 'evidence' => ['scoped_proof' => ['protocol' => DescendantScopedProofService::PROTOCOL,
                'design' => $design, 'design_hash' => $designHash]]]);
        $registered = app(ScopedResearchCertificateService::class)->register('inheritance', $trial, $design);
        $this->assertTrue($registered['valid'], json_encode($registered));

        return ['certificate_id' => $registered['certificate_id'], 'trial_id' => (int) $trial->id,
            'source' => (object) ['id' => 'original-inheritance-'.$trial->id], 'agents' => $agents, 'design' => $design,
            'context' => $parent['context'], 'periods' => $periods, 'year' => 2029];
    }

    private function question(string $candidateRole, int $year = 2027): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-09T00:00:00Z'));
        $lab = AiLaboratory::firstOrCreate(['symbol' => 'XAUUSD', 'timeframe' => 'M5'],
            ['name' => 'Synthetic scoped issuer', 'strategy_families' => ['hybrid'], 'is_active' => false]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => LabGeneration::count() + 1,
            'trigger_type' => 'synthetic_software_fixture', 'population_size' => 3, 'status' => 'draft']);
        $agents = [];
        $models = [];
        foreach (['guided', 'blinded', 'control'] as $role) {
            $parameters = ['minimum_confidence' => $role === 'control' ? .55 : .6];
            $model = ModelVersion::create(['name' => 'synthetic-'.$generation->id.'-'.$role, 'strategy' => 'hybrid',
                'version' => 'scoped-software-'.$generation->id.'-'.$role, 'status' => 'testing', 'parameters' => $parameters]);
            $agents[$role] = LabAgent::withoutEvents(fn () => LabAgent::create([
                'lab_generation_id' => $generation->id, 'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M5',
                'strategy_family' => 'hybrid', 'origin' => 'synthetic_software_fixture', 'lifecycle_status' => 'draft',
                'parameter_diff' => $role === 'control' ? [] : ['minimum_confidence' => ['old' => .55, 'new' => .6]],
            ]));
            $models[$role] = ['model_version_id' => (int) $model->id,
                'parameter_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($parameters)];
        }
        $source = AgentLearningCausalExperiment::create(['experiment_key' => 'synthetic-scoped-issuer-'.$generation->id,
            'lab_generation_id' => $generation->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'strategy_family' => 'hybrid',
            'target' => 'profit_factor', 'gene_key' => 'minimum_confidence', 'status' => 'ready_for_replay',
            'guided_agent_id' => $agents['guided']->id, 'blinded_agent_id' => $agents['blinded']->id,
            'control_agent_id' => $agents['control']->id, 'evidence' => ['experiment_kind' => 'memory_confirmation']]);
        $context = ['regime' => 'trend_up', 'volatility' => 'normal', 'session' => 'london', 'venue_phase' => 'london_interfix', 'direction' => 'BUY'];
        $periods = [];
        foreach (range(1, 6) as $month) {
            $from = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC');
            $periods[] = ['start_inclusive' => $from->toIso8601String(), 'end_exclusive' => $from->addMonth()->toIso8601String()];
        }
        $sourceHash = app(LabImmutableEvidenceService::class)->codeHash();
        $design = ['authority_policy' => ScopedResearchCertificateService::AUTHORITY_POLICY,
            'validation_start' => $periods[0]['start_inclusive'], 'validation_end' => $periods[5]['end_exclusive'], 'validation_windows' => $periods,
            'evaluator_hash' => $sourceHash, 'context_hash' => app(ContextContractV2Service::class)->project($context)['identity_hash'],
            'execution_hash' => hash('sha256', 'synthetic-fixed-execution'), 'owner_source_hash' => $sourceHash,
            'data_manifest_hash' => null, 'metric' => 'profit_factor',
            'stopping_rule' => ['minimum_trades_per_arm' => 8, 'minimum_effect' => .05, 'minimum_positive_windows' => 4],
            'statistical_guard' => ['method' => 'paired_window_bootstrap_percentile', 'replicates' => 500, 'seed' => 42, 'lower_quantile' => .05],
            'subject' => ['candidate_role' => $candidateRole, 'arm_models' => $models, 'context' => $context,
                'trait_delta' => ['gene' => 'minimum_confidence', 'old' => .55, 'new' => .6]],
            'exposure_policy' => ['protocol' => 'prospective_scoped_exposure_policy_v1', 'holding_fence_seconds' => 600,
                'execution_timeframe' => 'M5', 'context_timeframes' => ['H4', 'H1', 'M15'],
                'warmup_policy' => 'all_original_closed_source_rows_inside_registered_window', 'selection_policy' => 'frozen_before_first_event']];
        $registered = app(ScopedResearchCertificateService::class)->register('component', $source, $design);
        $this->assertTrue($registered['valid'], json_encode($registered));
        $this->assertFalse($registered['scope_authority_confirmed']);

        return ['certificate_id' => $registered['certificate_id'], 'source' => $source, 'agents' => $agents, 'design' => $design,
            'context' => $context, 'periods' => $periods, 'candidate_role' => $candidateRole, 'year' => $year];
    }

    private function inputs(array $fixture): array
    {
        $this->travelTo(CarbonImmutable::create($fixture['year'] + 1, 1, 1, 0, 0, 0, 'UTC'));
        $windows = [];
        $authorized = [];
        foreach ($fixture['periods'] as $index => $period) {
            $from = CarbonImmutable::parse($period['start_inclusive'])->addDays(9);
            $streams = [];
            foreach (['M5' => 300, 'H4' => 14400, 'H1' => 3600, 'M15' => 900] as $stream => $seconds) {
                $count = $stream === 'M5' ? 208 : 2;
                $rows = [];
                foreach (range(0, $count - 1) as $row) {
                    $rows[] = $from->addSeconds($row * $seconds)->toIso8601ZuluString().',100,101,99,100,10';
                }
                $path = storage_path('app/lab-datasets/'.$fixture['source']->id.'/'.$index.'/'.$stream.'.csv');
                File::ensureDirectoryExists(dirname($path));
                File::put($path, "time,open,high,low,close,volume\n".implode("\n", $rows)."\n");
                $streams[$stream] = ['path' => $path, 'sha256' => hash_file('sha256', $path), 'first_candle_at' => $from->toIso8601ZuluString(),
                    'last_candle_at' => $from->addSeconds(($count - 1) * $seconds)->toIso8601ZuluString(), 'rows' => $count];
            }
            $manifest = ['protocol' => MultiTimeframeSnapshotService::PROTOCOL,
                'streams' => $streams, 'bundle_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($streams)];
            $authorization = 'synthetic-scoped-'.$fixture['source']->id.'-'.$index;
            $authorized[] = ['authorization_id' => $authorization, 'research_epoch_id' => 'synthetic-software-only-'.$fixture['source']->id,
                'purpose' => 'instrument_independent_validation', 'dataset_sha256' => $manifest['bundle_hash'],
                ...$period, 'mtf_bundle_manifest' => $manifest];
            $windows[] = ['manifest' => $manifest, 'authorization_id' => $authorization, 'from' => $from, 'period' => $period];
        }
        config()->set('services.instrument_policy.authorized_research_windows', $authorized);
        foreach ($windows as $index => $window) {
            $sealed = app(InstrumentResearchWindowService::class)->seal($window['authorization_id'], $window['manifest']['bundle_hash']);
            $this->assertNotNull($sealed);
            $windows[$index]['window'] = $sealed;
        }

        return $windows;
    }

    private function originalProducts(array $fixture, float $effect, float $candidateDrawdown = 5, int $contextTrades = 8): array
    {
        $windows = $this->inputs($fixture);
        $products = [];
        $immutable = app(LabImmutableEvidenceService::class);
        foreach ($windows as $window) {
            $ids = [];
            foreach (['candidate' => $fixture['candidate_role'], 'control' => 'control'] as $arm => $role) {
                $agent = $fixture['agents'][$role]->fresh(['modelVersion', 'generation']);
                $request = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'evaluation_mode' => 'full',
                    'dataset_path' => $window['manifest']['streams']['M5']['path'], 'replay_dataset_hash' => $window['manifest']['bundle_hash'],
                    'mtf_snapshot_manifest' => $window['manifest'], 'mtf_dataset_paths' => array_map(fn ($file) => $file['path'], $window['manifest']['streams']),
                    'maximum_holding_bars' => 1, 'execution_contract' => ['execution_hash' => $fixture['design']['execution_hash']],
                    'strategies' => [['lab_agent_id' => (int) $agent->id, 'strategy' => $agent->modelVersion->strategy,
                        'parameters' => $agent->modelVersion->parameters]]];
                $run = $immutable->beginRun($agent, 'full_validation', 'full', ['code_hash' => $fixture['design']['evaluator_hash']]);
                $immutable->attachRequest($run, $request, ['data_hash' => $window['manifest']['bundle_hash']]);
                $result = $this->nativeFixtureResult($fixture, $window, $arm === 'candidate' ? 1.1 + $effect : 1.1,
                    $arm === 'candidate' ? $candidateDrawdown : 5, $contextTrades);
                $immutable->finishRun($run, 'completed', $result);
                $this->assertTrue($immutable->learningEligibility($run->fresh())['complete'], json_encode($immutable->learningEligibility($run->fresh())));
                $this->assertNotNull($immutable->verifiedModelRuntimeIdentity($run->fresh()));
                $ids[$arm] = (int) $run->id;
            }
            $products[] = ['window_key' => $window['window']['window_key'], 'run_ids' => $ids];
        }
        $this->assertSame(12, LabEvaluationRun::whereIn('model_version_id', array_map(fn ($agent) => $agent->model_version_id, $fixture['agents']))->count());
        $this->assertSame(12, ResearchExposureCaptureRecord::where('certificate_id', $fixture['certificate_id'])->where('record_type', 'request_ingress')->count());

        return $products;
    }

    /** Synthetic original output uses the native exact-context net_pf schema and eight mature trades. */
    private function nativeFixtureResult(array $fixture, array $window, float $pf, float $drawdown, int $contextTrades = 8): array
    {
        $trace = [];
        $ledger = [];
        foreach (range(200, 207) as $index) {
            $time = $window['from']->addMinutes($index * 5)->toIso8601ZuluString();
            $tradeContext = $index - 200 < $contextTrades ? $fixture['context']
                : array_replace($fixture['context'], ['session' => 'new_york', 'venue_phase' => 'comex_active']);
            $trace[] = ['candle_index' => $index, 'candle_time' => $time, 'event_type' => 'signal_evaluation', 'action' => 'BUY', 'accepted' => true,
                'context_axes' => $tradeContext];
            $ledger[] = ['id' => $index - 199, 'entry_time' => $time, 'exit_time' => $window['from']->addMinutes($index * 5 + 5)->toIso8601ZuluString(),
                'signal_time' => $time, 'direction' => 'BUY', 'pnl' => $index < 204 ? $pf : -1, 'context' => $tradeContext];
        }

        return ['total_trades' => 8, 'profit_factor' => $pf, 'max_drawdown_percent' => $drawdown,
            'monte_carlo' => ['risk_of_ruin_percent' => 2], 'trade_ledger' => $ledger,
            'trade_ledger_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($ledger), 'displayed_trade_count' => 8,
            'decision_trace' => $trace, 'data_quality' => ['decision_trace' => ['protocol' => 'candle_decision_trace_v1',
                'requested' => true, 'complete' => true, 'event_count' => 8, 'evaluated_candle_count' => 8, 'input_candle_count' => 208]],
            'pf_attribution' => ['summary' => ['net_pf' => $pf, 'cost_to_gross_profit_percent' => 10],
                'stress_cost' => ['profit_factor' => 1], 'by_volatility' => ['normal' => ['trades' => 8, 'net_pf' => 1]],
                'by_session' => ['london' => ['trades' => 8, 'net_pf' => 1]]],
            'statistical_evidence' => ['original_position_maturity' => ['protocol' => 'original_position_maturity_v1',
                'closed_trade_count' => count($ledger), 'open_position_count' => 0, 'censored_trade_count' => 0,
                'unknown_maturity_count' => 0, 'forced_terminal_close_applied' => false],
                'censored_trade_count' => 0, 'edge_quality' => ['worst_fold_profit_factor' => 1,
                    'worst_regime_pf' => 1, 'confidence_calibration' => ['calibration_score' => .8]]],
            'opportunity_recall' => ['abstention_precision' => .8],
            'replay_manifest' => ['first_candle_at' => $window['manifest']['streams']['M5']['first_candle_at'],
                'last_candle_at' => $window['manifest']['streams']['M5']['last_candle_at'],
                'data_partition' => ['screening_source' => 'authorized_post_paper_research_validation'],
                'instrument_research_window' => ['authorization_id' => $window['authorization_id'], 'research_epoch_id' => $window['window']['research_epoch_id']]],
            'instrument_research_trace' => ['context_source' => 'decision_time_trade_ledger', 'context_slice_protocol' => 'venue_phase_v1',
                'exact_context_slices' => [['context' => $fixture['context'], 'metrics' => ['trades' => $contextTrades,
                    'net_pf' => $contextTrades === 0 ? 0 : ($contextTrades <= 4 ? 99 : 4 * $pf / ($contextTrades - 4))]]]]];
    }
}
