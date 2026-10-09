<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Models\LabAgent;
use App\Models\ModelVersion;
use App\Models\NativeQualifiedSoloSelection;
use App\Services\AutonomousModeService;
use App\Services\LabPopulationService;
use App\Services\LearningVelocityGateService;
use App\Services\NativeQualifiedSoloSelectionService;
use App\Services\ResearchExperimentConversionKernelService;
use App\Services\ResearchLoopArbiterService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\SpecialistCouncilPanelReservationService;
use App\Services\SpecialistCouncilResearchFeedbackService;
use App\Services\SpecialistCouncilAuthorizedArmExecutionService;
use App\Services\SpecialistCouncilLifecycleService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabAgentEvaluationService;
use App\Services\ResearchReleaseSealService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__.'/SpecialistCouncilPanelReservationTest.php';

/** Original register/constructor guards are real; fixture source observations are explicitly not market evidence. */
class NativeQualifiedSoloSelectionTest extends TestCase
{
    use RefreshDatabase;
    use OriginalCouncilPanelReservationFixture;

    private string $root;
    private string $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2028-01-01T00:00:00Z'));
        $this->root = sys_get_temp_dir().'/native-solo-selection-test-'.bin2hex(random_bytes(8));
        $this->originalStorage = storage_path();
        File::ensureDirectoryExists($this->root.'/app/lab-datasets'); $this->app->useStoragePath($this->root);
        config(['services.internal_api.token' => 'fixture-native-solo-server-key-at-least-32-characters',
            'services.market_data.provider' => 'csv', 'services.lab_selection.constructor_initial_seat_budget' => 12,
            'services.instrument_policy.authorized_research_windows' => [], 'services.research_paper_epochs.authorized_paper_epochs' => []]);
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'fixture', 'conditional native SOLO ownership tests');
        $this->mock(LearningVelocityGateService::class, fn ($m) => $m->shouldReceive('inspect')->andReturn(['status' => 'healthy', 'allowed' => true]));
        AiLaboratory::create(['name' => 'native SOLO nonmarket fixture', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['ema_rsi'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        $resolved = realpath($this->root); $prefix = str_replace('\\', '/', (string) realpath(sys_get_temp_dir())).'/native-solo-selection-test-';
        if ($resolved && str_starts_with(str_replace('\\', '/', $resolved), $prefix)) File::deleteDirectory($resolved);
        parent::tearDown();
    }

    public function test_empty_original_qualified_roster_blocks_without_unqualified_fallback_or_dispatch(): void
    {
        [$work, $input] = $this->fixture();
        $input['solo_selection'] = ['protocol' => NativeQualifiedSoloSelectionService::PROTOCOL];
        try { app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $input, 'fixture'); $this->fail('An unqualified source was ranked.'); }
        catch (\LogicException $error) { $this->assertSame('NO_ORIGINAL_QUALIFIED_STANDALONE_ROSTER', $error->getMessage()); }
        $this->assertNull(data_get($work->fresh()->payload, 'pending_panel_intent'));
        $this->assertDatabaseCount('native_qualified_solo_selections', 0); $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertSame(0, LabGeneration::where('trigger_type', LabPopulationService::AUTHORIZED_COUNCIL_PANEL_TRIGGER)->count());
    }

    public function test_original_native_qualification_reservation_derives_all_sources_and_fixed_strict_exam_before_any_dispatch(): void
    {
        [$work, $input, $parent, $models] = $this->fixture();
        $before = array_map(fn ($model) => [$model->parameters, $model->metadata], $models);
        $input['standalone_qualification'] = ['protocol' => NativeQualifiedSoloSelectionService::QUALIFICATION_PROTOCOL];
        $result = app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $input, 'fixture');
        $this->assertTrue($result['executable'], json_encode($result));
        $body = app(SpecialistCouncilPanelReservationService::class)->body($work->fresh());
        $panel = $body['standalone_qualification_panel'];
        $this->assertCount(4, $panel['sources']); $this->assertCount(11, $body['arm_roots']);
        $this->assertSame(20, $panel['criteria']['minimum_paired_trades']);
        $this->assertSame(500, $panel['criteria']['bootstrap_simulations']); $this->assertSame(42, $panel['criteria']['bootstrap_seed']);
        $this->assertSame(1.10, $panel['criteria']['bootstrap_pf_5_percentile_minimum']);
        $solos = array_values(array_filter($body['arm_roots'], fn ($r) => $r['kind'] === 'solo'));
        $this->assertCount(4, $solos);
        foreach ($solos as $root) {
            $this->assertEquals(1, $root['standalone_source']['capital_weight']);
            $source = $root['standalone_source']; $member = collect($parent->manifest['members'])->firstWhere('specialist_id', $source['specialist_id']);
            $this->assertSame($member['passport_hash'], $source['passport_hash']);
            $this->assertSame($member['parameters'], $root['parameters']);
        }
        foreach ($models as $key => $model) $this->assertSame($before[$key], [$model->fresh()->parameters, $model->fresh()->metadata]);
        $this->assertDatabaseCount('lab_evaluation_runs', 0); $this->assertDatabaseCount('native_qualified_solo_selections', 0);
        $this->assertFalse($body['promotion_evidence']); $this->assertFalse($body['independent_evidence_claimed']);
    }

    public function test_caller_ranking_qualification_and_threshold_flags_are_refused(): void
    {
        [$work, $input] = $this->fixture();
        $input['standalone_qualification'] = ['protocol' => NativeQualifiedSoloSelectionService::QUALIFICATION_PROTOCOL, 'qualified' => true];
        $this->expectExceptionMessage('SOLO_ORIGINAL_SERVER_SELECTION_DECLARATION_REQUIRED');
        app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $input, 'fixture');
    }

    public function test_native_preparation_budget_is_server_derived_and_cannot_be_expanded_by_a_resealed_flag(): void
    {
        [$work, $input] = $this->fixture();
        $input['standalone_qualification'] = ['protocol' => NativeQualifiedSoloSelectionService::QUALIFICATION_PROTOCOL];
        app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $input, 'fixture');
        $owner = app(SpecialistCouncilPanelReservationService::class); $body = $owner->body($work->fresh());
        $this->assertSame(4, $body['native_preparation_policy']['units_per_lease']);
        $this->assertSame(33, $body['native_preparation_policy']['unit_count']);
        $this->assertSame(9, $body['native_preparation_policy']['digest_deliveries']);
        $this->assertSame(10, $body['native_preparation_policy']['preparation_deliveries']);
        $this->assertSame(900, $body['native_preparation_policy']['maximum_delivery_seconds']);
        $this->assertSame(55, $body['max_lease_deliveries']);
        $body['native_preparation_policy']['units_per_lease'] = 100;
        $body['resolution_hash'] = app(ResearchPaperEpochContractService::class)->parameterHash(array_diff_key($body,
            array_flip(['resolution_hash', 'reservation_hash', 'server_seal'])));
        $body['reservation_hash'] = $body['resolution_hash'];
        // Even access to the test-only server signer does not turn a changed
        // budget into the original fixed owner policy or create qualification.
        $body['server_seal'] = (new \ReflectionMethod($owner, 'seal'))->invoke($owner, array_diff_key($body, ['server_seal' => true]));
        $work->update(['payload' => [...$work->fresh()->payload, 'pending_panel_intent' => $body, 'followup_resolution' => $body]]);
        $this->expectExceptionMessage('COUNCIL_PANEL_SERVER_RESERVATION_SEAL_INVALID');
        $owner->body($work->fresh());
    }

    public function test_exact_original_fixture_source_artifact_build_and_current_byte_verification_succeed_before_canonical_preparation(): void
    {
        [$work, $input] = $this->fixture();
        $input['standalone_qualification'] = ['protocol' => NativeQualifiedSoloSelectionService::QUALIFICATION_PROTOCOL];
        app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $input, 'fixture');
        $releases = app(ResearchReleaseSealService::class);
        $built = $releases->buildSourceArtifact();
        $this->assertIsArray($built['reference']);
        $verified = $releases->verifySourceArtifact($built['reference'], true);
        $this->assertIsArray($verified);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertSame(0, LabGeneration::where('trigger_type', LabPopulationService::AUTHORIZED_COUNCIL_PANEL_TRIGGER)->count());
        $this->assertDatabaseCount('native_qualified_solo_selections', 0);
    }

    public function test_canonical_constructor_prepares_all_three_qualified_source_exam_windows_and_exact_full_requests_without_http(): void
    {
        [$work, $input] = $this->fixture();
        $input['standalone_qualification'] = ['protocol' => NativeQualifiedSoloSelectionService::QUALIFICATION_PROTOCOL];
        app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $input, 'fixture');
        $kernel = app(ResearchExperimentConversionKernelService::class); $owner = app(SpecialistCouncilPanelReservationService::class);
        $priorDigests = 0; $definitionHash = null; $originalVersionId = null;
        for ($delivery = 0; $delivery < 16; $delivery++) {
            $lease = $kernel->claimForOwner(ResearchLoopArbiterService::class, 1);
            $this->assertCount(1, $lease, json_encode($work->fresh()->only(['status', 'last_error'])));
            $pending = data_get($work->fresh()->result, 'native_panel_preparation_progress');
            if (is_array($pending) && ! is_array(data_get($work->fresh()->result, 'panel_preparation'))) {
                $pendingUnit = $pending['definition']['units'][0];
                try {
                    $owner->assertNativeExecutionBarrier($lease[0], LabGeneration::findOrFail($pendingUnit['generation_id']),
                        array_diff_key($pendingUnit, ['generation_id' => true]));
                    $this->fail('No original arm may dispatch while any panel digest is missing.');
                } catch (\LogicException $error) {
                    $this->assertSame('COUNCIL_PANEL_NATIVE_PREPARATION_DEFINITION_OR_PREFIX_DRIFT', $error->getMessage());
                }
            }
            $started = hrtime(true);
            $result = $owner->execute($lease[0]);
            $wall = (hrtime(true) - $started) / 1e9;
            fwrite(STDERR, 'native-solo-original-delivery '.json_encode(['ordinal' => $delivery + 1,
                'reason' => $result['reason'] ?? null, 'wall_seconds' => round($wall, 3)], JSON_THROW_ON_ERROR).PHP_EOL);
            $this->assertContains($result['reason'] ?? null, ['COUNCIL_PANEL_NEXT_PREREGISTERED_RESERVATION',
                'COUNCIL_PANEL_ORIGINAL_CONSTRUCTION_CONTINUATION', 'COUNCIL_PANEL_ORIGINAL_PREPARATION_SEALED',
                'COUNCIL_PANEL_ORIGINAL_DEFINITION_SEALED', 'COUNCIL_PANEL_ORIGINAL_REQUEST_DIGEST_CONTINUATION'], json_encode($result));
            $progress = data_get($work->fresh()->result, 'native_panel_preparation_progress');
            if (is_array($progress)) {
                $this->assertLessThan(900, $wall, 'Every original definition/digest delivery must fit its unchanged production budget.');
                $definitionHash ??= $progress['definition_hash']; $originalVersionId ??= $progress['definition']['panel_version_id'];
                $this->assertSame($definitionHash, $progress['definition_hash']);
                $this->assertSame($originalVersionId, $progress['definition']['panel_version_id']);
                $this->assertGreaterThanOrEqual($priorDigests, count($progress['digests']));
                $this->assertLessThanOrEqual(4, count($progress['digests']) - $priorDigests);
                $priorDigests = count($progress['digests']);
                $this->assertDatabaseCount('lab_evaluation_runs', 0);
                if (! is_array(data_get($work->fresh()->result, 'panel_preparation'))) {
                    $this->assertSame(3, LabGeneration::where('status', 'research_reserved')->count());
                    $this->assertSame(0, LabAgent::whereHas('generation', fn ($query) => $query->where(
                        'trigger_type', LabPopulationService::AUTHORIZED_COUNCIL_PANEL_TRIGGER))->where('lifecycle_status', '!=', 'draft')->count());
                }
            }
            if (($result['reason'] ?? null) === 'COUNCIL_PANEL_ORIGINAL_PREPARATION_SEALED') {
                $this->assertLessThan(900, $wall, 'Original atomic preparation must fit its unchanged production delivery budget.');
                break;
            }
        }
        $prepared = data_get($work->fresh()->result, 'panel_preparation');
        $this->assertIsArray($prepared, json_encode($work->fresh()->result));
        $this->assertCount(33, $prepared['units']);
        $this->assertSame(33, $priorDigests);
        $this->assertSame(10, data_get($work->fresh()->payload, 'followup_resolution.native_preparation_policy.preparation_deliveries'));
        $this->assertSame(55, data_get($work->fresh()->payload, 'followup_resolution.max_lease_deliveries'));
        $this->assertSame(3, LabGeneration::where('trigger_type', LabPopulationService::AUTHORIZED_COUNCIL_PANEL_TRIGGER)->count());
        $lease = $kernel->claimForOwner(ResearchLoopArbiterService::class, 1); $this->assertCount(1, $lease);
        $solos = array_filter($prepared['units'], fn ($unit) => $unit['kind'] === 'solo');
        $this->assertCount(12, $solos);
        foreach ($solos as $unit) {
            $request = app(SpecialistCouncilAuthorizedArmExecutionService::class)->compileRequest(
                LabGeneration::findOrFail($unit['generation_id']), array_diff_key($unit, ['generation_id' => true]), $lease[0]);
            $strategy = $request['strategies'][0]; $runtime = $strategy['specialist_council_contract'];
            $this->assertCount(1, $runtime['members']); $this->assertSame(1.0, $runtime['members'][0]['capital_weight']);
            $this->assertSame($runtime['solo_source_member']['risk_per_trade_percent'], $runtime['members'][0]['risk_per_trade_percent']);
            $this->assertSame($runtime['solo_source_member']['horizon'], $runtime['members'][0]['horizon']);
            $this->assertSame('full', $request['evaluation_mode']);
            $declaration = $strategy['native_standalone_qualification'];
            $this->assertSame(NativeQualifiedSoloSelectionService::QUALIFICATION_PROTOCOL, $declaration['protocol']);
            $this->assertSame($prepared['plan_hash'], $declaration['panel_plan_hash']);
            $this->assertSame(20, $declaration['criteria']['minimum_paired_trades']);
            $this->assertFalse($request['policy_context']['authorized_research_transport']['independent_evidence']);
        }
        $this->assertDatabaseCount('lab_evaluation_runs', 0); $this->assertDatabaseCount('native_qualified_solo_selections', 0);
    }

    /** Conditional ready-unit scaffolding isolates the real public compiler; it is not canonical construction/admission proof. */
    public function test_public_full_compiler_installs_original_native_projection_for_nonaggregate_standalone_carrier(): void
    {
        [$work, $input, $parent] = $this->fixture();
        $input['standalone_qualification'] = ['protocol' => NativeQualifiedSoloSelectionService::QUALIFICATION_PROTOCOL];
        app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $input, 'fixture');
        $lease = app(ResearchExperimentConversionKernelService::class)->claimForOwner(ResearchLoopArbiterService::class, 1)[0];
        $body = app(SpecialistCouncilPanelReservationService::class)->body($lease); $record = $body['windows'][0];
        $root = collect($body['arm_roots'])->firstWhere('kind', 'solo'); $source = ModelVersion::findOrFail($root['source_model_version_id']);
        $clone = ModelVersion::create(['name' => 'conditional ready original standalone carrier', 'strategy' => $source->strategy,
            'version' => 'conditional-ready-v1', 'status' => 'testing', 'parameters' => $source->parameters,
            'metadata' => [...$source->metadata, 'authorized_specialist_council_panel_seed' => ['arm_key' => $root['arm_key']]]]);
        $manifest = array_diff_key($parent->manifest, array_flip(['manifest_hash', 'epoch_contract', 'promotion_evidence']));
        $manifest['version'] = 'conditional-ready-source-projection'; $manifest['evaluation_policy']['solo_model_version_id'] = $clone->id;
        unset($manifest['evaluation_policy']['solo_model_version_id_hash']);
        $lifecycle = app(SpecialistCouncilLifecycleService::class); $version = $lifecycle->registerDraft($manifest, 'conditional-ready-creator');
        $cohort = LabGeneration::create(['ai_laboratory_id' => AiLaboratory::firstOrFail()->id, 'generation' => 2,
            'trigger_type' => LabPopulationService::AUTHORIZED_COUNCIL_PANEL_TRIGGER, 'status' => 'research_reserved', 'population_size' => 1,
            'trigger_context' => ['fixture_ready_unit_not_canonical_constructor_proof' => true, 'mtf_bundle_manifest' => $record['mtf_bundle_manifest'],
                'mtf_bundle_hash' => $record['window']['dataset_sha256'], 'specialist_council_authorized_panel' => ['protocol' => SpecialistCouncilPanelReservationService::COHORT_PROTOCOL,
                    'work_item_id' => (int) $work->id, 'work_key' => $work->work_key, 'reservation_hash' => $body['reservation_hash'],
                    'window_key' => $record['window']['window_key'], 'evaluator_id' => $body['evaluator_id']]]]);
        $agent = LabAgent::create(['lab_generation_id' => $cohort->id, 'model_version_id' => $clone->id, 'symbol' => 'XAUUSD',
            'timeframe' => 'H1', 'strategy_family' => 'ema_rsi', 'origin' => 'conditional_ready_unit_not_admission', 'lifecycle_status' => 'draft', 'parameter_diff' => []]);
        $policy = ['protocol' => 'specialist_council_original_full_source_v1', 'evaluation_mode' => 'full',
            'selection' => 'entire_authorized_source', 'maximum_source_rows' => 200000, 'maximum_runtime_seconds' => 600,
            'warmup_rows' => 0, 'no_walk_forward_selection' => true, 'promotion_evidence' => false];
        $file = $record['transport_proof']['files']['M5']; $scope = ['start_inclusive' => $file['start_inclusive'],
            'end_exclusive' => CarbonImmutable::parse($file['last_candle_at'])->addMinutes(5)->toIso8601String(),
            'rows' => $file['rows'], 'decision_rows' => $file['rows'] - 1, 'warmup_rows' => 0,
            'policy_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($policy)];
        $arm = ['arm_key' => 'w1:solo', 'kind' => 'solo', 'window_key' => $record['window']['window_key'], 'model_version_id' => (int) $clone->id,
            'model_hash' => app(\App\Services\SpecialistCouncilContractService::class)->modelHash($clone), 'evaluation_phase' => 'full_validation',
            'expected_start_inclusive' => $scope['start_inclusive'], 'expected_end_exclusive' => $scope['end_exclusive'],
            'evaluation_scope' => $scope, 'standalone_source' => $root['standalone_source']];
        $original = app(NativeQualifiedSoloSelectionService::class)->originalPlan($parent);
        $plan = ['protocol' => SpecialistCouncilLifecycleService::PLAN_PROTOCOL, 'version_id' => (int) $version->id,
            'manifest_hash' => $version->manifest_hash, 'purpose' => 'independent', 'objective' => $original['objective'],
            'execution_timeframe' => 'M5', 'execution_hash' => $original['execution_hash'], 'initial_capital' => $original['initial_capital'],
            'cost_model' => $original['cost_model'], 'risk_policy' => $original['risk_policy'], 'full_replay_runtime_policy' => $policy,
            'windows' => [$record['window']['window_key'] => [...$record['window'], 'evaluation_scope' => $scope]], 'arms' => [$arm['arm_key'] => $arm],
            'panel_work_item_id' => (int) $work->id, 'panel_reservation_hash' => $body['reservation_hash'],
            'standalone_qualification_panel' => $body['standalone_qualification_panel'], 'promotion_evidence' => false];
        $hash = app(ResearchPaperEpochContractService::class)->parameterHash($plan);
        DB::table('specialist_council_evaluation_plans')->insert(['specialist_council_version_id' => $version->id,
            'evaluator_id' => $body['evaluator_id'], 'plan' => json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            'plan_hash' => $hash, 'sealed_at' => now()->utc(), 'created_at' => now(), 'updated_at' => now()]);
        $lifecycle->attachEvaluationArm($version, $arm['arm_key'], $clone);
        $unit = [...$arm, 'lab_agent_id' => (int) $agent->id, 'panel_version_id' => (int) $version->id, 'plan_hash' => $hash];
        $context = $cohort->trigger_context; $context['specialist_council_authorized_panel'] = [...$context['specialist_council_authorized_panel'],
            'panel_version_id' => (int) $version->id, 'plan_hash' => $hash, 'arm_units' => [$unit]]; $cohort->update(['trigger_context' => $context]);
        app(ResearchReleaseSealService::class)->seal($cohort);
        $bundle = ['bundle_hash' => $record['window']['dataset_sha256'], 'manifest' => $record['mtf_bundle_manifest']];
        $member = app(LabAgentEvaluationService::class)->specialistCouncilMemberPayload($clone->fresh(), 'M5', $bundle, $record['window']['dataset_sha256'], 'XAUUSD');
        $this->assertArrayNotHasKey('specialist_council_contract', $member); // original producer branch omitted this required aggregate view
        $expected = $lifecycle->runtimeContractForModel($clone->fresh(), 'M5', $record['window']['dataset_sha256'], $original['execution_hash'], $bundle, 'XAUUSD');
        $diagnostic = ['protocol' => 'conditional_native_solo_compiler_diagnostic_v1', 'canonical_admission' => false,
            'market_or_independent_evidence' => false, 'producer_member_has_native_contract' => false,
            'expected_native_contract_hash' => $expected['contract_hash'], 'original_plan_hash' => $hash,
            'actual_native_contract_hash' => null, 'reason' => null];
        $diagnosticDirectory = base_path('../.runtime'); File::ensureDirectoryExists($diagnosticDirectory);
        try {
            $request = app(SpecialistCouncilAuthorizedArmExecutionService::class)->compileRequest($cohort->fresh(), $unit, $lease);
            $diagnostic['actual_native_contract_hash'] = data_get($request, 'strategies.0.specialist_council_contract.contract_hash');
            $diagnostic['request_hash'] = app(LabImmutableEvidenceService::class)->hash($request);
        } catch (\Throwable $error) {
            $diagnostic['reason'] = preg_match('/^[A-Z][A-Z0-9_]{1,150}$/D', $error->getMessage()) === 1
                ? $error->getMessage() : 'CONDITIONAL_COMPILER_BOUNDARY_ERROR';
            throw $error;
        } finally {
            File::put($diagnosticDirectory.'/native-solo-compiler-boundary-diagnostic-20261009.json',
                json_encode($diagnostic, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        $this->assertSame($expected, $request['strategies'][0]['specialist_council_contract']);
        $this->assertCount(1, $expected['members']); $this->assertSame(1.0, $expected['members'][0]['capital_weight']);
        $this->assertSame($expected['solo_source_member']['horizon'], $expected['members'][0]['horizon']);
        $this->assertSame($hash, $request['strategies'][0]['native_standalone_qualification']['panel_plan_hash']);
        $this->assertFalse($request['policy_context']['authorized_research_transport']['independent_evidence']);
        $transportSource = $request['policy_context']['authorized_research_transport']['original_council_arm']['native_standalone_source'];
        $this->assertSame('native_standalone_source_transport_v1', $transportSource['protocol']);
        $this->assertSame($root['standalone_source']['source_projection_hash'], $transportSource['source_projection_hash']);
        $this->assertFalse($transportSource['qualified_evidence']);
        $this->assertFalse($transportSource['economic_authority']);
        $refused = function (array $altered, string $reason) use ($cohort): void {
            try {
                app(\App\Services\InstrumentResearchWindowService::class)->bindReplayRequest($cohort->fresh(), $altered);
                $this->fail('A changed original standalone transport must be refused.');
            } catch (\LogicException | \RuntimeException $error) {
                $this->assertSame($reason, $error->getMessage());
            }
        };
        $changed = $request;
        $changed['strategies'][0]['specialist_council_contract']['members'][0]['risk_per_trade_percent'] = 99;
        $refused($changed, 'RESEARCH_TRANSPORT_ORIGINAL_NATIVE_PROGRAM_DRIFT');
        $changed = $request;
        $changed['strategies'][0]['native_standalone_qualification']['criteria']['minimum_paired_trades'] = 1;
        $refused($changed, 'RESEARCH_TRANSPORT_STANDALONE_ORIGINAL_STATISTICS_DRIFT');
        $kernel = app(ResearchExperimentConversionKernelService::class);
        $this->assertTrue($kernel->defer($lease, 'CONDITIONAL_REQUEST_BYTE_STABILITY_CHECK', true));
        $fresh = $kernel->claimForOwner(ResearchLoopArbiterService::class, 1); $this->assertCount(1, $fresh);
        $this->assertNotSame($lease->lease_token, $fresh[0]->lease_token);
        $this->assertGreaterThan($lease->fence_version, $fresh[0]->fence_version);
        $recompiled = app(SpecialistCouncilAuthorizedArmExecutionService::class)->compileRequest($cohort->fresh(), $unit, $fresh[0]);
        $wire = static fn (array $value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $this->assertSame($wire($request), $wire($recompiled), 'Operational lease/fence changes cannot change original signed request bytes.');
        $this->assertSame(app(LabImmutableEvidenceService::class)->hash($request), app(LabImmutableEvidenceService::class)->hash($recompiled));
        try {
            app(SpecialistCouncilAuthorizedArmExecutionService::class)->execute($cohort->fresh(), $unit, $fresh[0],
                'research_loop_arbiter', $fresh[0]->lease_token, $fresh[0]->fence_version);
            $this->fail('A conditional compiled request cannot dispatch an incomplete canonical panel.');
        } catch (\LogicException $error) {
            $this->assertSame('COUNCIL_PANEL_NATIVE_PREPARATION_PREFIX_SEAL_INVALID', $error->getMessage());
        }
        $work->update(['status' => 'deferred']);
        $refused($request, 'COUNCIL_PANEL_WORK_LEASE_NOT_CURRENT');
        $this->assertDatabaseCount('lab_evaluation_runs', 0); $this->assertDatabaseCount('native_qualified_solo_selections', 0);
    }

    public function test_original_eligible_task_requires_the_same_role_scope_and_qualified_horizon(): void
    {
        [, , $parent] = $this->fixture();
        $owner = app(NativeQualifiedSoloSelectionService::class);
        $panel = $owner->sealQualificationSources($parent, $owner->originalPlan($parent), now()->utc()->toIso8601String());
        $source = $panel['sources'][0]; $member = collect($parent->manifest['members'])->firstWhere('specialist_id', $source['specialist_id']);
        $guard = new \ReflectionMethod($owner, 'matchesOriginalTask');
        $this->assertTrue($guard->invoke($owner, $member, $source));
        $foreign = $source; $foreign['horizon']['max_holding_seconds'] += 300;
        $this->assertFalse($guard->invoke($owner, $member, $foreign));
        $foreign = $source; $foreign['role'] = 'not_the_original_role';
        $this->assertFalse($guard->invoke($owner, $member, $foreign));
        $foreign = $source; $foreign['scope']['symbols'] = ['EURUSD'];
        $this->assertFalse($guard->invoke($owner, $member, $foreign));
        $this->assertDatabaseCount('native_qualified_solo_selections', 0);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_a_resealed_source_projection_cannot_change_original_parameter_or_horizon(): void
    {
        [, , $parent] = $this->fixture();
        $owner = app(NativeQualifiedSoloSelectionService::class); $original = $owner->originalPlan($parent);
        $panel = $owner->sealQualificationSources($parent, $original, now()->utc()->toIso8601String());
        $panel['sources'][0]['horizon']['max_holding_seconds'] += 300;
        $panel['sources'][0]['source_projection_hash'] = app(ResearchPaperEpochContractService::class)->parameterHash(array_diff_key($panel['sources'][0], ['source_projection_hash' => true]));
        $panel['source_panel_hash'] = app(ResearchPaperEpochContractService::class)->parameterHash(array_diff_key($panel, ['source_panel_hash' => true]));
        $this->expectExceptionMessage('SOLO_ORIGINAL_QUALIFICATION_SOURCE_OR_CRITERIA_DRIFT');
        $owner->assertQualificationSources($panel, $parent, $original);
    }

    public function test_ranking_uses_original_fixed_objective_and_stable_tie_break_not_caller_best_label(): void
    {
        $owner = app(NativeQualifiedSoloSelectionService::class);
        $rows = [['qualification_id' => 3, 'specialist_id' => 'a', 'net_profit' => 10, 'max_drawdown_percent' => 2],
            ['qualification_id' => 2, 'specialist_id' => 'z', 'net_profit' => 10, 'max_drawdown_percent' => 2],
            ['qualification_id' => 1, 'specialist_id' => 'b', 'net_profit' => 5, 'max_drawdown_percent' => 1]];
        $this->assertSame([2, 3, 1], array_column($owner->rank('net_return_at_equal_risk', $rows), 'qualification_id'));
        $this->assertSame([1, 2, 3], array_column($owner->rank('lower_risk_at_equal_return', $rows, 5), 'qualification_id'));
        $this->assertSame([2, 3], array_column($owner->rank('lower_risk_at_equal_return', $rows, 10), 'qualification_id'));
        try { $owner->rank('lower_risk_at_equal_return', $rows); $this->fail('Equal return was invented after outcomes.'); }
        catch (\LogicException $error) { $this->assertSame('SOLO_SELECTION_PREREGISTERED_EQUAL_RETURN_FLOOR_REQUIRED', $error->getMessage()); }
        $this->expectExceptionMessage('SOLO_SELECTION_FIXED_OBJECTIVE_REQUIRED'); $owner->rank('highest_seen_validation_pf', $rows);
    }

    public function test_original_selection_receipt_cannot_be_rewritten_or_deleted(): void
    {
        $row = NativeQualifiedSoloSelection::create(['specialist_council_version_id' => 999, 'panel_kind' => 'selection',
            'plan_hash' => str_repeat('a', 64), 'selection_hash' => str_repeat('b', 64), 'receipt' => ['fixture_not_proof' => true], 'created_at' => now()->utc()]);
        foreach (['update', 'delete'] as $action) {
            try { $action === 'update' ? $row->update(['receipt' => ['qualified' => true]]) : $row->delete(); $this->fail('Original receipt changed.'); }
            catch (\LogicException $error) { $this->assertSame('ORIGINAL_SOLO_SELECTION_IS_IMMUTABLE', $error->getMessage()); }
        }
    }

    public function test_scoped_exam_requires_positive_aggregate_power_maturity_statistical_and_external_risk_guards_together(): void
    {
        [, , $parent] = $this->fixture(); $owner = app(NativeQualifiedSoloSelectionService::class);
        $criteria = $owner->criteria($parent);
        $stats = ['protocol' => 'native_standalone_qualification_statistics_v1', 'status' => 'complete',
            'criteria_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($criteria),
            'censored_trade_count' => 0, 'unknown_maturity_count' => 0, 'bootstrap' => ['status' => 'assessed',
                'method' => 'bootstrap_profit_factor', 'simulations' => 500, 'seed' => 42, 'trade_count' => 20,
                'pf_5_percentile_lower_bound' => 1.2]];
        $rows = array_fill(0, 3, ['metrics' => ['net_profit' => 10.0, 'matured_trades' => 20.0, 'censored_trades' => 0.0,
            'max_drawdown_percent' => 1.0, 'max_daily_loss_percent' => 1.0, 'max_gross_exposure_percent' => 10.0, 'max_total_risk_percent' => 1.0], 'statistics' => $stats]);
        $criterion = new \ReflectionMethod(NativeQualifiedSoloSelectionService::class, 'sourceReasons');
        $this->assertSame([], $criterion->invoke($owner, $rows, $parent, $criteria, true)); // conditional numerical guard only
        $bad = $rows; $bad[2]['metrics']['net_profit'] = -50.0;
        $this->assertContains('SOLO_ORIGINAL_POSITIVE_VALUE_NOT_REPLICATED', $criterion->invoke($owner, $bad, $parent, $criteria, true));
        $bad = $rows; $bad[0]['statistics']['bootstrap']['pf_5_percentile_lower_bound'] = 1.0;
        $this->assertContains('SOLO_ORIGINAL_BOOTSTRAP_EVIDENCE_INSUFFICIENT', $criterion->invoke($owner, $bad, $parent, $criteria, true));
        $bad = $rows; $bad[0]['metrics']['matured_trades'] = 8.0;
        $this->assertContains('SOLO_ORIGINAL_MATURE_HORIZON_POWER_INSUFFICIENT', $criterion->invoke($owner, $bad, $parent, $criteria, true));
        $bad = $rows; $bad[0]['statistics']['censored_trade_count'] = 1; $bad[0]['statistics']['status'] = 'incomplete';
        $this->assertContains('SOLO_ORIGINAL_BOOTSTRAP_EVIDENCE_INSUFFICIENT', $criterion->invoke($owner, $bad, $parent, $criteria, true));
        $bad = $rows; $bad[0]['metrics']['max_total_risk_percent'] = 100.0;
        $this->assertContains('SOLO_ORIGINAL_EXTERNAL_RISK_LIMIT_EXCEEDED', $criterion->invoke($owner, $bad, $parent, $criteria, true));
        $this->assertDatabaseCount('native_qualified_solo_selections', 0); $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }
}
