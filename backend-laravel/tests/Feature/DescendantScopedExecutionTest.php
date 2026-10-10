<?php

namespace Tests\Feature;

use App\Jobs\EvaluateLabAgentJob;
use App\Jobs\ExecuteScopedResearchArmJob;
use App\Models\AgentLearningCausalExperiment;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabSkillZooEntry;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentWorkItem;
use App\Models\ScopedResearchCertificate;
use App\Models\SystemEvent;
use App\Services\AutonomousModeService;
use App\Services\CompositionAuthorityKernelService;
use App\Services\ContextContractV2Service;
use App\Services\DescendantScopedExecutionService;
use App\Services\DescendantScopedProofService;
use App\Services\EvolutionaryAuthorityFoundryService;
use App\Services\ExecutionContractService;
use App\Services\InstrumentResearchWindowService;
use App\Services\LabAgentEvaluationService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabInstrumentResearchService;
use App\Services\LabPopulationService;
use App\Services\MultiTimeframeSnapshotService;
use App\Services\ResearchExperimentConversionKernelService;
use App\Services\ResearchExperimentWorkConsumerService;
use App\Services\ResearchLoopArbiterService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\ResearchReleaseSealService;
use App\Services\ResearchWindowExposureInventoryService;
use App\Services\ScopedDescendantCandidatePreparationService;
use App\Services\ScopedResearchCertificateService;
use App\Services\ScopedSelectorPanelService;
use App\Services\StrategyParameterSchemaService;
use App\Services\TacticCatalogueService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Synthetic admission/fence tests. No independent market authority is established. */
class DescendantScopedExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-09T08:00:00Z');
        config(['services.internal_api.token' => str_repeat('scoped-native-test-', 3),
            'services.instrument_policy.authorized_research_windows' => []]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_native_prospective_work_requires_an_original_confirmed_component_certificate(): void
    {
        [$cartridge, $models, $scope] = $this->fixture(false);
        $result = app(DescendantScopedProofService::class)->preregister($cartridge, $models, $scope);

        $this->assertSame('DESCENDANT_INDEPENDENT_SOURCE_COMPONENT_CERTIFICATE_REQUIRED', $result['reason_code']);
        $this->assertDatabaseCount('descendant_value_trials', 0);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        Http::assertNothingSent();
    }

    public function test_fewer_than_six_windows_cannot_shrink_the_original_matrix(): void
    {
        [$cartridge, $models, $scope] = $this->fixture();
        $scope['validation_windows'] = array_slice($scope['validation_windows'], 0, 3);
        $scope['native_execution']['authorization_ids'] = array_slice($scope['native_execution']['authorization_ids'], 0, 3);
        $result = app(DescendantScopedProofService::class)->preregister($cartridge, $models, $scope);

        $this->assertSame('DESCENDANT_SIX_OR_CONFIGURED_ORIGINAL_WINDOWS_REQUIRED', $result['reason_code']);
        $this->assertDatabaseCount('descendant_value_trials', 0);
        Http::assertNothingSent();
    }

    public function test_native_risk_metric_is_sealed_without_using_the_legacy_profit_only_gate(): void
    {
        [$cartridge, $models, $scope] = $this->fixture(true, 'drawdown');
        $registered = app(DescendantScopedProofService::class)->preregister($cartridge, $models, $scope);
        $this->assertSame('scoped_preregistered', $registered['status'], json_encode($registered));
        $this->assertSame('drawdown', $registered['certificate']['design']['metric']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_native_trial_cannot_be_marked_terminal_without_the_original_independent_owner_record(): void
    {
        [$cartridge, $models, $scope] = $this->fixture();
        $owner = app(DescendantScopedProofService::class);
        $registered = $owner->preregister($cartridge, $models, $scope);
        $this->assertSame('scoped_preregistered', $registered['status'], json_encode($registered));
        $result = $owner->recordIndependentTerminal($registered['trial_id'], $registered['certificate']['certificate_id']);
        $this->assertSame('DESCENDANT_INDEPENDENT_ORIGINAL_TERMINAL_REQUIRED', $result['reason_code']);
        $this->assertDatabaseHas('descendant_value_trials', ['id' => $registered['trial_id'], 'status' => 'scoped_preregistered', 'settled_at' => null]);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_native_component_and_descendant_refuse_intrinsic_programme_changes_before_sealing(): void
    {
        [$cartridge, $models, $scope] = $this->fixture();
        $models['P+T']->update(['metadata' => [...$models['P+T']->metadata,
            'risk_governor' => ['protocol' => 'synthetic_frozen_risk_v1', 'maximum_cost_percent' => .9]]]);
        $descendant = app(DescendantScopedProofService::class)->preregister($cartridge, $models, $scope);
        $this->assertSame('DESCENDANT_SHARED_RUNTIME_PROGRAM_REQUIRED', $descendant['reason_code']);
        $lab = AiLaboratory::create(['name' => 'programme guard fixture', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['ema_rsi'], 'is_active' => false]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'status' => 'draft', 'population_size' => 3]);
        $agents = [];
        foreach (['guided' => 'P+T', 'blinded' => 'P+U', 'control' => 'P'] as $role => $arm) {
            $gene = $role === 'guided' ? 'ema_fast' : 'rsi_period';
            $agents[$role] = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $models[$arm]->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'ema_rsi', 'origin' => 'synthetic_guard_fixture',
                'lifecycle_status' => 'draft', 'parameter_diff' => $role === 'control' ? [] : [
                    $gene => ['old' => $models['P']->parameters[$gene], 'new' => $models[$arm]->parameters[$gene]]]]);
        }
        $source = AgentLearningCausalExperiment::create(['experiment_key' => 'synthetic-programme-guard',
            'lab_generation_id' => $generation->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'ema_rsi',
            'target' => 'profit_factor', 'gene_key' => 'ema_fast', 'status' => 'planned',
            'guided_agent_id' => $agents['guided']->id, 'blinded_agent_id' => $agents['blinded']->id, 'control_agent_id' => $agents['control']->id]);
        try {
            app(DescendantScopedExecutionService::class)->prepareComponentExecution([
                ...$scope, 'subject' => ['candidate_role' => 'guided']], $source);
            $this->fail('Different original intrinsic programmes were admitted.');
        } catch (\LogicException $error) {
            $this->assertSame('SCOPED_COMPONENT_EXACT_INTRINSIC_PROGRAMME_REQUIRED', $error->getMessage());
        }
        $this->assertDatabaseCount('scoped_research_certificates', 0);
        $this->assertDatabaseCount('descendant_value_trials', 0);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        Http::assertNothingSent();
    }

    public function test_missing_actual_windows_keep_existing_arbiter_work_blocked_without_constructing_a_cohort(): void
    {
        [$cartridge, $models, $scope] = $this->fixture();
        $registered = app(DescendantScopedProofService::class)->preregister($cartridge, $models, $scope);
        $this->assertSame('scoped_preregistered', $registered['status'], json_encode($registered));
        $native = app(DescendantScopedExecutionService::class);
        $work = $native->registerWork($registered['trial_id'], $registered['certificate']['certificate_id']);
        $this->assertSame('recorded', $work['status'], json_encode($work));
        $item = ResearchExperimentWorkItem::findOrFail($work['work_id']);
        $this->assertSame(DescendantScopedExecutionService::WORK_TYPE, $item->work_type);
        $this->assertSame(ResearchLoopArbiterService::class, data_get($item->payload, 'owner'));
        $this->assertSame(ResearchExperimentWorkConsumerService::class, data_get($item->payload, 'executor'));
        $this->assertFalse($native->inspectWork($item)['executable']);
        $this->assertSame([], app(ResearchExperimentConversionKernelService::class)->claimForOwner(ResearchLoopArbiterService::class));
        $this->assertSame('blocked', $item->fresh()->status);
        $this->assertDatabaseCount('lab_generations', 0);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        Http::assertNothingSent();
    }

    public function test_expired_work_fence_cannot_construct_or_transport_any_original_arm(): void
    {
        [$cartridge, $models, $scope] = $this->fixture();
        $registered = app(DescendantScopedProofService::class)->preregister($cartridge, $models, $scope);
        $work = app(DescendantScopedExecutionService::class)->registerWork($registered['trial_id'], $registered['certificate']['certificate_id']);
        $item = ResearchExperimentWorkItem::findOrFail($work['work_id']);
        $item->update(['status' => 'leased', 'lease_token' => 'expired-original-token', 'fence_version' => 1,
            'lease_expires_at' => now()->subSecond()]);
        $result = app(DescendantScopedExecutionService::class)->execute($item->fresh());

        $this->assertSame('stale_lease', $result['status']);
        $this->assertSame('DESCENDANT_WORK_LEASE_NOT_CURRENT', $result['reason_code']);
        $this->assertDatabaseCount('lab_generations', 0);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        Http::assertNothingSent();
    }

    public function test_typed_descendant_cohort_cannot_enter_generic_full_or_screening_projection(): void
    {
        [$cartridge, $models] = $this->fixture();
        $lab = AiLaboratory::create(['name' => 'synthetic native projection guard', 'symbol' => 'XAUUSD',
            'timeframe' => 'H1', 'strategy_families' => ['ema_rsi'], 'is_active' => false]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'status' => 'research_reserved',
            'population_size' => 4, 'trigger_context' => ['scoped_descendant_execution' => ['protocol' => DescendantScopedExecutionService::PROTOCOL]]]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $models['P']->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'ema_rsi', 'origin' => 'scoped_descendant_original', 'lifecycle_status' => 'draft']);
        foreach (['evaluate', 'screen'] as $method) {
            try {
                app(LabAgentEvaluationService::class)->{$method}($agent);
                $this->fail('Generic projection consumed typed research.');
            } catch (\RuntimeException $error) {
                $this->assertSame('SCOPED_DESCENDANT_ORIGINAL_EXECUTOR_REQUIRED', $error->getMessage());
            }
        }
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        Http::assertNothingSent();
    }

    public function test_future_selector_reservation_blocks_ordinary_delivery_before_any_attempt_even_for_old_serialized_jobs(): void
    {
        [, $models] = $this->fixture();
        $lab = AiLaboratory::create(['name' => 'future reservation ordinary guard fixture', 'symbol' => 'XAUUSD',
            'timeframe' => 'H1', 'strategy_families' => ['ema_rsi'], 'is_active' => false]);
        $cohort = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'status' => 'draft', 'population_size' => 3]);
        $agent = LabAgent::create(['lab_generation_id' => $cohort->id, 'model_version_id' => $models['P']->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'ema_rsi', 'origin' => 'synthetic_guard_fixture',
            'lifecycle_status' => 'draft']);
        $oldJob = new EvaluateLabAgentJob($agent->id, 'XAUUSD', 'screen');
        $this->mock(ScopedSelectorPanelService::class, fn (MockInterface $mock) => $mock->shouldReceive('reservesOrdinaryEvaluation')->andReturnTrue());
        $called = false;
        $oldJob->middleware()[0]->handle($oldJob, function () use (&$called): void {
            $called = true;
        });
        $this->assertFalse($called);
        $oldJob->failed(new \LogicException('software_fixture_wrong_ordinary_queue'));
        foreach (['evaluate', 'screen', 'screenBatch'] as $method) {
            try {
                $owner = app(LabAgentEvaluationService::class);
                $method === 'screenBatch' ? $owner->screenBatch([$agent->id], 'XAUUSD') : $owner->{$method}($agent);
                $this->fail('Reserved selector original entered ordinary evaluation.');
            } catch (\RuntimeException $error) {
                $this->assertSame('SCOPED_SELECTOR_ORIGINAL_EXECUTOR_REQUIRED', $error->getMessage());
            }
        }
        try {
            new EvaluateLabAgentJob($agent->id, 'XAUUSD', 'full');
            $this->fail('New ordinary job reserved a selector source.');
        } catch (\LogicException $error) {
            $this->assertSame('SCOPED_SELECTOR_ORIGINAL_EXECUTOR_REQUIRED', $error->getMessage());
        }
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertSame('draft', $agent->fresh()->lifecycle_status);
        Http::assertNothingSent();
    }

    public function test_native_wire_freezes_the_whole_matrix_then_delivers_only_one_original_arm_per_fence(): void
    {
        $this->nativeWire(false);
    }

    public function test_component_native_wire_uses_two_original_arms_and_no_placeholder_descendant(): void
    {
        $this->nativeWire(true);
    }

    public function test_native_http_body_preserves_the_captured_float_and_empty_dictionary_hash(): void
    {
        $this->nativeWire(false, 'normal', true);
    }

    public static function publicationInterruptions(): array
    {
        return ['lease expires during HTTP' => ['expired'], 'owner changes during HTTP' => ['reassigned'],
            'operator stops while authorized HTTP completes' => ['stopped']];
    }

    #[DataProvider('publicationInterruptions')]
    public function test_original_publication_keeps_current_facts_but_quarantines_ownership_loss(string $interruption): void
    {
        $this->nativeWire(false, $interruption);
    }

    public static function settlementInterruptions(): array
    {
        return [
            'STOP before issuer' => ['before', 'stopped'],
            'expiry before issuer' => ['before', 'expired'],
            'STOP during issuer' => ['issuer', 'stopped'],
            'expiry during issuer' => ['issuer', 'expired'],
            'STOP immediately before commit' => ['commit', 'stopped'],
            'expiry immediately before commit' => ['commit', 'expired'],
            'current owner completes negative question' => ['commit', 'none'],
        ];
    }

    public function test_native_commit_permission_requires_an_existing_valid_running_normalized_control_and_never_mutates_it(): void
    {
        config(['services.autonomous_mode.default_enabled' => true,
            'services.xauusd_organism.symbol' => 'XAUUSD', 'services.xauusd_organism.laboratory_storage_timeframe' => 'H1']);
        $owner = app(AutonomousModeService::class);
        $this->assertTrue($owner->enabled('xau-usd', 'M5')); // Baseline defaults remain unchanged.
        $this->assertFalse(DB::transaction(fn (): bool => $owner->enabledForCommit('xau-usd', 'M5')));
        $row = SystemEvent::create(['event_type' => 'autonomous_mode_control', 'event_key' => 'autonomy:mode:XAUUSD:H1',
            'agent' => 'synthetic_test', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'severity' => 'info',
            'summary' => 'Synthetic isolated current-read control', 'occurred_at' => now(),
            'payload' => ['protocol' => AutonomousModeService::PROTOCOL, 'enabled' => true, 'state' => 'running']]);
        $raw = $row->fresh()->getRawOriginal();
        $this->assertTrue(DB::transaction(fn (): bool => $owner->enabledForCommit('xau-usd', 'M5')));
        $this->assertSame($raw, $row->fresh()->getRawOriginal());
        foreach ([
            ['protocol' => AutonomousModeService::PROTOCOL, 'enabled' => true, 'state' => 'paused'],
            ['protocol' => AutonomousModeService::PROTOCOL, 'enabled' => true, 'state' => 'stopped'],
            ['protocol' => AutonomousModeService::PROTOCOL, 'enabled' => true, 'state' => 'safety_halt'],
            ['protocol' => AutonomousModeService::PROTOCOL, 'enabled' => 'true', 'state' => 'running'],
            ['enabled' => true, 'state' => 'running'],
        ] as $malformedOrNonRunning) {
            $row->update(['payload' => $malformedOrNonRunning]);
            $raw = $row->fresh()->getRawOriginal();
            $this->assertFalse(DB::transaction(fn (): bool => $owner->enabledForCommit('xau-usd', 'M5')));
            $this->assertSame($raw, $row->fresh()->getRawOriginal());
        }
        $this->assertDatabaseCount('system_events', 1);
    }

    public function test_native_commit_permission_compiles_a_mysql_current_locking_read_from_the_real_owner(): void
    {
        $connection = DB::connection();
        $originalGrammar = $connection->getQueryGrammar();
        Schema::partialMock()->shouldReceive('hasTable')->with('system_events')->andReturnTrue();
        try {
            $connection->setQueryGrammar(new MySqlGrammar($connection));
            $queries = DB::transaction(fn (): array => $connection->pretend(
                fn (): bool => app(AutonomousModeService::class)->enabledForCommit('xau-usd', 'M5')));
        } finally {
            $connection->setQueryGrammar($originalGrammar);
        }
        $selects = array_values(array_filter($queries,
            fn (array $query): bool => str_contains($query['query'], 'from `system_events`')));
        $this->assertCount(1, $selects);
        $this->assertStringContainsString('lock in share mode', strtolower($selects[0]['query']));
        $this->assertSame(['autonomy:mode:XAUUSD:H1'], $selects[0]['bindings']);
        $this->assertDatabaseCount('system_events', 0);
    }

    #[DataProvider('settlementInterruptions')]
    public function test_new_settlement_writes_roll_back_on_stop_or_expiry_but_completed_original_facts_remain(string $stage, string $interruption): void
    {
        // This isolates transaction ownership, not independent market proof.
        // The issuer writes an explicitly synthetic append-only probe only.
        [$cartridge, $models, $scope] = $this->fixture();
        $registered = app(DescendantScopedProofService::class)->preregister($cartridge, $models, $scope);
        $work = ResearchExperimentWorkItem::findOrFail($registered['native_work']['work_id']);
        $work->update(['work_type' => DescendantScopedExecutionService::COMPONENT_WORK_TYPE,
            'status' => 'leased', 'lease_token' => 'synthetic-atomic-settlement', 'fence_version' => 1,
            'lease_expires_at' => now()->addSeconds(2700)]);
        $lab = AiLaboratory::create(['name' => 'synthetic atomic scope boundary', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['ema_rsi'], 'is_active' => false]);
        $cohort = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'population_size' => 2, 'status' => 'research_reserved']);
        $agent = LabAgent::create(['lab_generation_id' => $cohort->id, 'model_version_id' => $models['P']->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'ema_rsi',
            'origin' => 'synthetic_atomic_fact', 'lifecycle_status' => 'screened']);
        $fact = LabEvaluationRun::create(['run_id' => 'synthetic-already-completed-original', 'lab_generation_id' => $cohort->id,
            'lab_agent_id' => $agent->id, 'model_version_id' => $models['P']->id, 'phase' => 'full_validation',
            'mode' => 'synthetic_test', 'attempt' => 1, 'status' => 'completed', 'finished_at' => now(),
            'metadata' => ['synthetic_original_fact_only' => true]]);
        $rawFact = $fact->fresh()->getRawOriginal();
        $enabled = true;
        $this->mock(AutonomousModeService::class, function (MockInterface $mock) use (&$enabled): void {
            $mock->shouldReceive('enabled', 'enabledForCommit')->andReturnUsing(function () use (&$enabled): bool {
                return $enabled;
            });
        });
        $interrupt = function () use ($interruption, &$enabled): void {
            if ($interruption === 'stopped') {
                $enabled = false;
            }
            if ($interruption === 'expired') {
                CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(3600));
            }
        };
        $certificateId = $registered['certificate']['certificate_id'];
        $recordCount = DB::table('scoped_research_certificates')->count();
        $registry = app(ScopedResearchCertificateService::class);
        $registry->shouldReceive('issueIndependent')->with($certificateId, [])->times($stage === 'before' ? 0 : 1)
            ->andReturnUsing(function () use ($certificateId, $stage, $interrupt): array {
                $original = ScopedResearchCertificate::findOrFail($certificateId);
                $probe = $original->replicate();
                $probe->fill(['certificate_key' => hash('sha256', 'synthetic atomic issuer probe'),
                    'record_type' => 'independent_assessment', 'parent_certificate_id' => $certificateId,
                    'payload' => ['synthetic_transaction_probe_only' => true]]);
                $probe->save();
                if ($stage === 'issuer') {
                    $interrupt();
                }

                return ['original_authority' => ['component' => ['status' => 'negative_or_inconclusive', 'confirmed' => false]]];
            });
        if ($stage === 'commit' && $interruption !== 'none') {
            $realConversion = app(ResearchExperimentConversionKernelService::class);
            $conversion = \Mockery::mock(ResearchExperimentConversionKernelService::class)->makePartial();
            $conversion->shouldReceive('complete')->once()->andReturnUsing(function ($item, array $result) use ($realConversion, $interrupt): bool {
                $completed = $realConversion->complete($item, $result);
                $interrupt();

                return $completed;
            });
            $this->instance(ResearchExperimentConversionKernelService::class, $conversion);
        }
        if ($stage === 'before') {
            $interrupt();
        }
        $executor = app(DescendantScopedExecutionService::class);
        $method = new \ReflectionMethod($executor, 'settleOriginalMatrix');
        try {
            $result = $method->invoke($executor, $work->fresh(), ['certificate_id' => $certificateId, 'trial_id' => null], [], [$cohort]);
            $this->assertSame('none', $interruption, 'STOP/expiry must roll back before returning completion.');
            $this->assertSame('completed', $result['status']);
        } catch (\LogicException $error) {
            $this->assertNotSame('none', $interruption);
            $this->assertSame($interruption === 'stopped' ? 'AUTONOMOUS_MODE_STOPPED' : 'DESCENDANT_WORK_LEASE_NOT_CURRENT', $error->getMessage());
        }
        $this->assertSame($rawFact, $fact->fresh()->getRawOriginal());
        $this->assertSame($interruption === 'none' ? 'completed' : 'research_reserved', $cohort->fresh()->status);
        $this->assertSame($interruption === 'none' ? 'settled' : 'leased', $work->fresh()->status);
        $this->assertSame($recordCount + ($interruption === 'none' ? 1 : 0), DB::table('scoped_research_certificates')->count());
        $this->assertDatabaseCount('research_experiment_work_items', 1);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        Http::assertNothingSent();
    }

    public function test_component_question_reference_namespace_cannot_steal_an_active_twenty_seat_generation(): void
    {
        [, $models, $scope] = $this->fixture();
        $lab = AiLaboratory::create(['name' => 'ordinary G263 software fixture', 'symbol' => 'XAUUSD',
            'timeframe' => 'H1', 'strategy_families' => ['ema_rsi'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $sourceGeneration = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 262,
            'status' => 'completed', 'population_size' => 3]);
        $sourceAgents = [];
        foreach (['guided' => 'P+T', 'blinded' => 'P+U', 'control' => 'P'] as $role => $arm) {
            $gene = $role === 'guided' ? 'ema_fast' : 'rsi_period';
            $sourceAgents[$role] = LabAgent::create(['lab_generation_id' => $sourceGeneration->id,
                'model_version_id' => $models[$arm]->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
                'strategy_family' => 'ema_rsi', 'origin' => 'synthetic_discovery_source', 'lifecycle_status' => 'completed',
                'parameter_diff' => $role === 'control' ? [] : [$gene => [
                    'old' => $models['P']->parameters[$gene], 'new' => $models[$arm]->parameters[$gene]]]]);
        }
        $source = AgentLearningCausalExperiment::create(['experiment_key' => 'old-discovery-question-with-active-G263',
            'lab_generation_id' => $sourceGeneration->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'ema_rsi',
            'target' => 'profit_factor', 'gene_key' => 'ema_fast', 'status' => 'completed',
            'guided_agent_id' => $sourceAgents['guided']->id, 'blinded_agent_id' => $sourceAgents['blinded']->id,
            'control_agent_id' => $sourceAgents['control']->id]);
        $active = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 263, 'status' => 'full_validation',
            'population_size' => 20, 'trigger_type' => 'synthetic_existing_ordinary_owner']);
        for ($seat = 0; $seat < 20; $seat++) {
            $model = ModelVersion::create(['name' => 'ordinary immutable seat '.$seat, 'strategy' => 'ema_rsi_v1',
                'version' => 'ordinary-software-'.$seat, 'status' => 'testing', 'parameters' => $models['P']->parameters,
                'metadata' => $models['P']->metadata]);
            LabAgent::create(['lab_generation_id' => $active->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'ema_rsi',
                'origin' => 'ordinary_owner', 'lifecycle_status' => 'full_validation']);
        }
        $activeAttributes = $active->fresh()->getAttributes();
        $ordinaryAgents = $active->agents()->orderBy('id')->get()->map->getAttributes()->all();
        $labAttributes = $lab->fresh()->getAttributes();
        $design = [...$scope, 'protocol' => 'synthetic_original_component_factory_design',
            'context_hash' => app(ContextContractV2Service::class)->project($scope['context'])['identity_hash'],
            'authority_policy' => ScopedResearchCertificateService::AUTHORITY_POLICY,
            'owner_source_hash' => hash_file('sha256', __FILE__), 'subject' => ['candidate_role' => 'guided', 'context' => $scope['context']],
            'native_execution' => array_diff_key($scope['native_execution'], ['source_component_certificate_id' => true]),
            'research_only' => true, 'promotion_evidence' => false];
        $registered = app(EvolutionaryAuthorityFoundryService::class)->preregisterScopedComponent($source, $design);
        $this->assertSame('scoped_preregistered', $registered['status'], json_encode($registered));
        $this->assertSame($activeAttributes, $active->fresh()->getAttributes());
        $this->assertSame($ordinaryAgents, $active->agents()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($labAttributes, $lab->fresh()->getAttributes());
        $this->assertSame(263, (int) $lab->generations()->max('generation'));
        $this->assertSame($active->id, $lab->generations()->latest('generation')->value('id'));
        $this->assertSame($active->id, $lab->generations()->latest('id')->value('id'));
        $this->assertSame($active->id, LabGeneration::whereHas('laboratory', fn ($query) => $query
            ->where('symbol', 'XAUUSD')->where('timeframe', 'H1'))->orderByDesc('generation')->orderByDesc('id')->value('id'));
        $fresh = AgentLearningCausalExperiment::with('generation.laboratory')->findOrFail($registered['fresh_experiment_id']);
        $this->assertSame('Q:H1', $fresh->generation->laboratory->timeframe);
        $this->assertFalse($fresh->generation->laboratory->is_active);
        $this->assertSame(EvolutionaryAuthorityFoundryService::SCOPED_QUESTION_REFERENCE_MODE, $fresh->generation->laboratory->lifecycle_mode);
        $this->assertSame('H1', $fresh->timeframe);
        $this->assertSame(['H1'], $fresh->generation->agents()->pluck('timeframe')->unique()->values()->all());
        $this->assertFalse(data_get($fresh->generation->trigger_context, 'source_reference_container.market_timeframe_namespace'));
        $this->assertFalse(data_get($fresh->generation->trigger_context, 'source_reference_container.runtime_population'));
        $this->assertSame('M5', $registered['certificate']['design']['native_execution']['execution_timeframe']);
        // Even an adversarial ready projection cannot let execution steal the
        // ordinary stream. The real constructor's mutex/active barrier owns it.
        $work = ResearchExperimentWorkItem::where('work_type', DescendantScopedExecutionService::COMPONENT_WORK_TYPE)->sole();
        $work->update(['status' => 'leased', 'lease_token' => 'synthetic-active-barrier', 'fence_version' => 1,
            'lease_expires_at' => now()->addSeconds(2700)]);
        $window = ['window_key' => 'synthetic-ready-projection', ...$scope['validation_windows'][0]];
        $proof = ['status' => 'ready', 'executable' => true, 'certificate_id' => $registered['certificate']['certificate_id'],
            'trial_id' => null, 'design' => $registered['certificate']['design'], 'design_hash' => $registered['certificate']['design_hash'],
            'window' => $window, 'windows' => [['window' => $window]], 'manifest' => []];
        $this->mock(AutonomousModeService::class, fn (MockInterface $mock) => $mock->shouldReceive('enabled')->andReturnTrue());
        $executor = \Mockery::mock(DescendantScopedExecutionService::class, [app(DescendantScopedProofService::class),
            app(ScopedResearchCertificateService::class), app(InstrumentResearchWindowService::class), app(LabImmutableEvidenceService::class),
            app(ResearchExperimentConversionKernelService::class), app(ResearchReleaseSealService::class)])->makePartial();
        $executor->shouldReceive('inspectWork')->andReturn($proof);
        $this->instance(DescendantScopedExecutionService::class, $executor);
        try {
            app(LabPopulationService::class)->buildScopedDescendant($work->fresh(), $proof);
            $this->fail('A scoped execution cohort stole active ordinary G263.');
        } catch (\LogicException $error) {
            $this->assertSame('DESCENDANT_ANOTHER_GENERATION_OWNS_STREAM', $error->getMessage());
        }
        $this->assertSame($activeAttributes, $active->fresh()->getAttributes());
        $this->assertSame($ordinaryAgents, $active->agents()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame(2, $lab->generations()->count());
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        Http::assertNothingSent();
    }

    public function test_real_compound_source_recipe_binds_original_two_and_four_arm_requests_without_model_mutation(): void
    {
        // Actual programme owners compile this software fixture. Only the
        // release/transport admission is substituted; there are no outcomes.
        $kernel = app(CompositionAuthorityKernelService::class);
        $schema = app(StrategyParameterSchemaService::class);
        $base = $schema->validate('differential_router_v1',
            array_intersect_key($schema->defaults('differential_router'), $schema->schema('differential_router')));
        $passport = $kernel->freeze(['symbol' => 'XAUUSD', 'strategy_id' => 'mix_011_differential_router',
            'tactic_id' => 'frozen_parent_differential_router', 'risk_id' => 'atr_risk_envelope',
            'management_id' => 'parameter_preserving_research', 'data_hash' => str_repeat('a', 64),
            'execution_hash' => str_repeat('c', 64), 'data_contract' => ['m5_canonical' => true,
                'closed_at_available_at' => true, 'backward_only_alignment' => true],
            'horizon_mode' => 'day_structure', 'horizon_contract' => ['overnight_allowed' => false],
            'confirmation_families' => ['price_reaction', 'market_structure']]);
        $assignment = ['protocol' => LabInstrumentResearchService::PROTOCOL,
            'hash_protocol' => LabInstrumentResearchService::HASH_PROTOCOL, 'status' => 'assigned',
            'parameter_hash' => str_repeat('2', 64), 'selected_keys' => ['atr_risk_envelope'],
            'selected' => [['instrument_key' => 'atr_risk_envelope', 'role' => 'frozen_support',
                'activation_contract' => ['closed_candle_only' => true, 'maximum_risk_percent' => .5]]],
            'source_components' => ['strategy_library_id' => 'mix_011_differential_router',
                'tactic_library_key' => 'frozen_parent_differential_router', 'risk_library_id' => 'atr_risk_envelope',
                'management_id' => 'parameter_preserving_research', 'composition_id' => $passport['composition_id']]];
        $assignment['assignment_hash'] = (new \ReflectionMethod(LabInstrumentResearchService::class, 'hash'))
            ->invoke(app(LabInstrumentResearchService::class), $assignment);
        $source = ModelVersion::create(['name' => 'old actual compound software source',
            'strategy' => 'differential_router_v1', 'version' => 'old-source-v1', 'status' => 'testing', 'parameters' => $base,
            'metadata' => ['base_strategy' => 'differential_router_v1', 'strategy_family' => 'differential_router',
                'strategy_architecture' => 'frozen_parent_differential_router',
                'tactic_contract' => app(TacticCatalogueService::class)->for('differential_router', 'frozen_parent_differential_router'),
                'smart_composition' => ['composition_passport' => $passport], 'instrument_research_assignment' => $assignment]]);
        $sourceAttributes = $source->fresh()->getAttributes();
        $lab = AiLaboratory::create(['name' => 'compound scoped compile fixture', 'symbol' => 'XAUUSD',
            'timeframe' => 'H1', 'strategy_families' => ['differential_router'], 'is_active' => false]);
        $cohort = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'status' => 'evaluating', 'population_size' => 4]);
        $vectors = ['P' => $base, 'P+T' => [...$base, 'trend_up_ema_period' => $base['trend_up_ema_period'] + 1],
            'P+T+U' => [...$base, 'trend_up_ema_period' => $base['trend_up_ema_period'] + 1,
                'trend_down_roc_period' => $base['trend_down_roc_period'] + 1],
            'P+U' => [...$base, 'trend_down_roc_period' => $base['trend_down_roc_period'] + 1]];
        $agents = [];
        foreach ($vectors as $arm => $parameters) {
            $version = 'prospective-software-'.str_replace('+', '-', $arm);
            $metadata = app(EvolutionaryAuthorityFoundryService::class)->scopedChildMetadata($source,
                ['arm' => $arm, 'new_version' => $version, 'source_hypothesis_only' => true], $parameters);
            $model = ModelVersion::create(['name' => $version, 'strategy' => $source->strategy,
                'version' => $version, 'status' => 'testing', 'parameters' => $parameters, 'metadata' => $metadata]);
            $agents[$arm] = LabAgent::create(['lab_generation_id' => $cohort->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
                'origin' => 'synthetic_compile_fixture', 'lifecycle_status' => 'draft']);
        }
        $releases = \Mockery::mock(ResearchReleaseSealService::class)->makePartial();
        $releases->shouldReceive('bindGenerationRequest')->andReturnUsing(fn ($generation, array $request): array => [
            ...$request, 'policy_context' => [...$request['policy_context'],
                'authorized_research_transport' => ['window' => ['window_key' => 'synthetic-original-window']]]]);
        $this->instance(ResearchReleaseSealService::class, $releases);
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        foreach ([['candidate' => 'P+T', 'control' => 'P'], array_combine(array_keys($agents), array_keys($agents))] as $roles) {
            $subject = [];
            foreach ($roles as $arm => $modelArm) {
                $subject[$arm] = ['model_version_id' => (int) $agents[$modelArm]->model_version_id];
            }
            $proof = ['certificate_id' => 42, 'trial_id' => count($roles) === 4 ? 7 : null, 'design_hash' => str_repeat('f', 64),
                'design' => ['execution_hash' => $execution['execution_hash'], 'subject' => ['arm_models' => $subject],
                    'exposure_policy' => ['holding_fence_seconds' => 259200],
                    'native_execution' => ['purpose' => count($roles) === 4 ? DescendantScopedExecutionService::PURPOSE : DescendantScopedExecutionService::COMPONENT_PURPOSE,
                        'initial_capital' => 10000, 'risk_policy' => ['risk_per_trade_percent' => .5], 'full_replay_runtime_policy' => []]],
                'window' => ['window_key' => 'synthetic-original-window', 'dataset_sha256' => str_repeat('b', 64),
                    'start_inclusive' => '2027-01-01T00:00:00Z', 'end_exclusive' => '2027-02-01T00:00:00Z'],
                'manifest' => ['protocol' => MultiTimeframeSnapshotService::PROTOCOL, 'bundle_hash' => str_repeat('b', 64),
                    'streams' => array_fill_keys(['M5', 'H4', 'H1', 'M15'], ['path' => 'synthetic-future.csv', 'sha256' => str_repeat('d', 64)])]];
            $fences = [];
            foreach ($roles as $arm => $modelArm) {
                $agent = $agents[$modelArm]->fresh('modelVersion');
                $original = $agent->modelVersion->getAttributes();
                $request = app(DescendantScopedExecutionService::class)->compileRequest($cohort, $agent, $arm, $proof);
                $contract = $request['composition_runtime_contract'];
                $this->assertSame($request['strategies'][0]['composition_runtime_contract'], $contract);
                $this->assertSame($agent->modelVersion->parameters, $request['strategies'][0]['parameters']);
                $this->assertTrue(data_get($contract, 'runtime_bindings.strategy.bound'));
                $this->assertTrue(data_get($contract, 'runtime_bindings.management.bound'));
                $this->assertTrue(data_get($contract, 'execution_authority.instrument.bound'));
                $this->assertTrue(data_get($contract, 'execution_authority.mtf.bound'));
                $this->assertNotSame($passport['composition_id'], $contract['composition_id']);
                $this->assertSame('parameter_preserving_research', data_get($contract, 'components.management_id'));
                $this->assertSame($original, $agent->modelVersion->fresh()->getAttributes());
                $this->assertSame($assignment['selected'], $request['strategies'][0]['instrument_research_assignment']['selected']);
                $this->assertTrue(data_get($request, 'policy_context.scoped_program_binding.source_model_metadata_unchanged'));
                $this->assertSame($request['strategies'][0]['mtf_pilot'], $request['mtf_pilot']);
                $this->assertSame('not_requested', $request['volume_context']['status']);
                $fences[] = data_get($request, 'policy_context.scoped_position_maturity_fence');
            }
            $this->assertCount(1, array_unique(array_map('serialize', $fences)));
        }
        $this->assertSame($sourceAttributes, $source->fresh()->getAttributes());
        // A source-owned fixed manager cannot turn a declared time-stop T
        // into an executable intervention by merely cloning its old recipe.
        $fixedPassport = $kernel->freeze(['symbol' => 'XAUUSD', 'strategy_id' => 'mix_011_differential_router',
            'tactic_id' => 'frozen_parent_differential_router', 'risk_id' => 'atr_risk_envelope',
            'management_id' => 'balanced_professional', 'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('c', 64),
            'data_contract' => ['m5_canonical' => true, 'closed_at_available_at' => true, 'backward_only_alignment' => true],
            'horizon_mode' => 'day_structure', 'horizon_contract' => ['overnight_allowed' => false],
            'confirmation_families' => ['price_reaction', 'market_structure']]);
        $fixedAssignment = array_diff_key($assignment, ['assignment_hash' => true]);
        $fixedAssignment['source_components']['management_id'] = 'balanced_professional';
        $fixedAssignment['source_components']['composition_id'] = $fixedPassport['composition_id'];
        $fixedAssignment['assignment_hash'] = (new \ReflectionMethod(LabInstrumentResearchService::class, 'hash'))
            ->invoke(app(LabInstrumentResearchService::class), $fixedAssignment);
        $fixedMetadata = [...$source->metadata, 'smart_composition' => ['composition_passport' => $fixedPassport],
            'instrument_research_assignment' => $fixedAssignment];
        $fixedGeneration = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 2,
            'status' => 'draft', 'population_size' => 3]);
        $fixedAgents = [];
        foreach (['control' => $base, 'guided' => [...$base, 'time_stop_candles' => 24],
            'blinded' => [...$base, 'trend_down_roc_period' => $base['trend_down_roc_period'] + 1]] as $role => $parameters) {
            $model = ModelVersion::create(['name' => 'fixed original '.$role, 'strategy' => $source->strategy,
                'version' => 'fixed-source-'.$role, 'status' => 'testing', 'parameters' => $parameters, 'metadata' => $fixedMetadata]);
            $gene = $role === 'guided' ? 'time_stop_candles' : 'trend_down_roc_period';
            $fixedAgents[$role] = LabAgent::create(['lab_generation_id' => $fixedGeneration->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router', 'origin' => 'synthetic_manager_fixture',
                'lifecycle_status' => 'draft', 'parameter_diff' => $role === 'control' ? [] : [
                    $gene => ['old' => $base[$gene], 'new' => $parameters[$gene]]]]);
        }
        $fixedQuestion = AgentLearningCausalExperiment::create(['experiment_key' => 'real-fixed-manager-source-question',
            'lab_generation_id' => $fixedGeneration->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
            'target' => 'profit_factor', 'gene_key' => 'time_stop_candles', 'status' => 'planned',
            'guided_agent_id' => $fixedAgents['guided']->id, 'blinded_agent_id' => $fixedAgents['blinded']->id,
            'control_agent_id' => $fixedAgents['control']->id]);
        try {
            app(DescendantScopedExecutionService::class)->prepareComponentExecution([
                'subject' => ['candidate_role' => 'guided'], 'validation_windows' => []], $fixedQuestion);
            $this->fail('Source management erased the original named T intervention.');
        } catch (\LogicException $error) {
            $this->assertSame('SCOPED_COMPONENT_ORIGINAL_TRAIT_OVERRIDDEN_BY_SOURCE_MANAGEMENT', $error->getMessage());
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_missing_actual_volume_provenance_cannot_silently_disable_a_required_original_lane(): void
    {
        [, $models] = $this->fixture();
        $required = clone $models['P+T'];
        $required->parameters = [...$required->parameters, 'volume_lane' => 'confirm_participation'];
        try {
            app(LabAgentEvaluationService::class)->scopedOriginalVolumeContext([$models['P'], $required], 'XAUUSD',
                ['bundle_hash' => str_repeat('a', 64), 'manifest' => ['protocol' => MultiTimeframeSnapshotService::PROTOCOL]]);
            $this->fail('The volume-required arm became a no-volume control.');
        } catch (\RuntimeException $error) {
            $this->assertSame('SCOPED_ORIGINAL_AUTHORIZED_VOLUME_PROVENANCE_REQUIRED', $error->getMessage());
        }
        $this->assertSame('none', $models['P+T']->fresh()->parameters['volume_lane']);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        Http::assertNothingSent();
    }

    public function test_existing_foundry_freezes_one_other_gene_hypothesis_then_waits_for_future_server_slots(): void
    {
        [$cartridge, $models] = $this->fixture();
        $proposal = app(EvolutionaryAuthorityFoundryService::class)->proposeScopedDescendant(999);
        $this->assertSame('prospective_hypothesis_dependency', $proposal['status'], json_encode($proposal));
        $this->assertNotSame($cartridge->gene_key, $proposal['selection']['gene']);
        $this->assertSame(0, $proposal['selection']['memory_inputs']);
        $this->assertFalse($proposal['execution_authorized']);
        $this->assertFalse($proposal['parent_validation_windows_reusable']);
        $owner = app(ScopedDescendantCandidatePreparationService::class);
        $registered = $owner->register(999);
        $this->assertSame('recorded', $registered['status'], json_encode($registered));
        $item = ResearchExperimentWorkItem::findOrFail($registered['work_id']);
        $ready = $owner->inspectWork($item);
        $this->assertFalse($ready['executable']);
        $this->assertSame('SCOPED_DESCENDANT_FUTURE_SERVER_ROSTER_REQUIRED', $ready['reason_code']);
        $again = $owner->register(999);
        $this->assertSame('already_registered', $again['status']);
        $this->assertSame($registered['work_id'], $again['work_id']);
        $this->assertDatabaseCount('model_versions', 4);
        $this->assertDatabaseCount('descendant_value_trials', 0);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_registered_future_slots_create_pristine_four_arm_question_with_parent_guards_before_any_data(): void
    {
        [$cartridge, $models] = $this->fixture();
        $owner = app(ScopedDescendantCandidatePreparationService::class);
        $registered = $owner->register(999);
        $work = ResearchExperimentWorkItem::findOrFail($registered['work_id']);
        $proposal = data_get($work->payload, 'frozen_proposal');
        $slots = [];
        foreach ($proposal['window_draft'] as $index => $period) {
            $slots[] = [
                ...$period, 'authorization_id' => 'synthetic-future-slot-'.$index,
                'research_epoch_id' => 'synthetic-future-child-epoch', 'purpose' => 'instrument_independent_validation'];
        }
        config(['services.instrument_policy.authorized_research_windows' => $slots]);
        $this->mock(AutonomousModeService::class, fn (MockInterface $mock) => $mock->shouldReceive('enabled')->andReturnTrue());
        $this->assertTrue($owner->inspectWork($work)['executable']);
        $work->update(['status' => 'leased', 'attempts' => 1, 'lease_token' => 'synthetic-future-constructor',
            'fence_version' => 1, 'lease_expires_at' => now()->addSeconds(900)]);
        $result = $owner->execute($work->fresh());
        $this->assertSame('completed', $result['status'], json_encode($result));
        $certificate = $result['registration']['certificate'];
        $design = $certificate['design'];
        $parent = app(ScopedResearchCertificateService::class)->inspect(999)['design'];
        $this->assertSame($parent['statistical_guard'], $design['statistical_guard']);
        $this->assertSame($parent['risk_guard'], $design['risk_guard']);
        $this->assertSame(999, $design['subject']['source_component_certificate_id']);
        $this->assertDatabaseCount('model_versions', 8);
        $this->assertDatabaseCount('descendant_value_trials', 1);
        $this->assertDatabaseCount('research_exposure_capture_records', 1);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('lab_generations', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        Http::assertNothingSent();
        $nativeWork = ResearchExperimentWorkItem::findOrFail($result['registration']['native_work']['work_id']);
        $this->assertSame('blocked', $nativeWork->status);
        $this->assertFalse(app(DescendantScopedExecutionService::class)->inspectWork($nativeWork)['executable']);
        foreach ($design['subject']['arm_models'] as $identity) {
            $this->assertNotContains($identity['model_version_id'], array_map(fn ($model) => $model->id, $models));
        }
    }

    public function test_real_native_clock_is_rederived_from_original_bytes_and_a_resealed_wrong_schedule_is_refused(): void
    {
        // Real Python producer, synthetic candles: this establishes wire and
        // clock behavior only, never unused market data or economic authority.
        [$cartridge, $models, $scope] = $this->fixture();
        $path = tempnam(sys_get_temp_dir(), 'scoped-native-clock-');
        $this->assertNotFalse($path);
        try {
            $handle = fopen($path, 'wb');
            fputcsv($handle, ['time', 'open', 'high', 'low', 'close', 'volume'], ',', '"', '');
            $start = CarbonImmutable::parse('2025-01-06T00:00:00Z');
            for ($index = 0; $index < 220; $index++) {
                $price = 2000 + 3 * sin($index / 12);
                fputcsv($handle, [$start->addMinutes($index * 5)->toIso8601ZuluString(), $price, $price + 1, $price - 1, $price, 100], ',', '"', '');
            }
            fclose($handle);
            $hash = hash_file('sha256', $path);
            $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
            $requests = [];
            foreach ($models as $arm => $model) {
                $requests[$arm] = ['symbol' => 'XAUUSD', 'timeframe' => 'M5',
                    'strategy' => 'ema_rsi_v1', 'base_strategy' => 'ema_rsi', 'version' => $model->version,
                    'parameters' => $model->parameters, 'initial_balance' => 10000,
                    'dataset_path' => $path, 'replay_dataset_hash' => $hash,
                    'execution' => $execution['parameters'], 'execution_contract' => $execution,
                    'execution_hash' => $execution['execution_hash'],
                    'policy_context' => ['full_replay_runtime_policy' => $scope['native_execution']['full_replay_runtime_policy']]];
            }
            $python = <<<'PY'
import json, sys
from app.schemas import SimpleBacktestRequest
from app.services.backtester import run_simple_ema_rsi_backtest
print(json.dumps({key: run_simple_ema_rsi_backtest(SimpleBacktestRequest.model_validate(request)).model_dump(mode='json') for key, request in json.load(sys.stdin).items()}))
PY;
            $process = new Process(['python', '-c', $python], dirname(base_path()).'/ai-service-python');
            $process->setTimeout(120);
            $process->setInput(json_encode($requests, JSON_THROW_ON_ERROR));
            $process->mustRun();
            $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $input = ['files' => ['M5' => ['path' => $path, 'sha256' => $hash, 'rows' => 220]]];
            $owner = app(DescendantScopedExecutionService::class);
            foreach ($requests as $arm => $request) {
                $clock = data_get($results[$arm], 'data_quality.replay_executed_clock');
                $this->assertSame($request['execution_hash'], $clock['execution_hash'], json_encode($clock));
                $this->assertSame(app(ResearchPaperEpochContractService::class)->parameterHash($request['policy_context']['full_replay_runtime_policy']), $clock['policy_hash']);
                $this->assertSame(app(ResearchPaperEpochContractService::class)->parameterHash(array_diff_key($clock, ['receipt_hash' => true, 'receipt_json' => true])), $clock['receipt_hash']);
                $owner->verifyOriginalExecutedClock($results[$arm], $request, $input);
                $this->assertSame(20, data_get($results[$arm], 'data_quality.replay_executed_clock.decision_rows'));
            }
            $clock = $results['P']['data_quality']['replay_executed_clock'];
            $clock['schedule_hash'] = hash('sha256', 'a validly hashed wrong actual schedule');
            $unsigned = array_diff_key($clock, ['receipt_hash' => true, 'receipt_json' => true]);
            $clock['receipt_hash'] = app(ResearchPaperEpochContractService::class)->parameterHash($unsigned);
            $clock['receipt_json'] = json_encode($unsigned, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $results['P']['data_quality']['replay_executed_clock'] = $clock;
            try {
                $owner->verifyOriginalExecutedClock($results['P'], $requests['P'], $input);
                $this->fail('A resealed wrong schedule was accepted.');
            } catch (\LogicException $error) {
                $this->assertSame('DESCENDANT_ORIGINAL_EXECUTED_CLOCK_PHYSICAL_SCHEDULE_MISMATCH', $error->getMessage());
            }
            $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        } finally {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }

    private function nativeWire(bool $component, string $duringTransport = 'normal', bool $wireFloatProbe = false): void
    {
        // The substituted readiness and transport producers are synthetic.
        // This tests constructor/request/checkpoint behavior, not market proof.
        [$cartridge, $models, $scope] = $this->fixture();
        if ($wireFloatProbe) {
            $scope['native_execution']['initial_capital'] = 10000.0;
        }
        if ($component) {
            $lab = AiLaboratory::create(['name' => 'synthetic original component source', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
                'strategy_families' => ['ema_rsi'], 'is_active' => false]);
            $sourceGeneration = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'status' => 'completed', 'population_size' => 3]);
            $sourceAgents = [];
            foreach (['guided' => 'P+T', 'blinded' => 'P+U', 'control' => 'P'] as $role => $arm) {
                $gene = $role === 'guided' ? 'ema_fast' : 'rsi_period';
                $diff = $role === 'control' ? [] : [$gene => ['old' => $models['P']->parameters[$gene], 'new' => $models[$arm]->parameters[$gene]]];
                $sourceAgents[$role] = LabAgent::create(['lab_generation_id' => $sourceGeneration->id, 'model_version_id' => $models[$arm]->id,
                    'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'ema_rsi', 'origin' => 'synthetic_source',
                    'lifecycle_status' => 'draft', 'parameter_diff' => $diff]);
            }
            $source = AgentLearningCausalExperiment::create(['experiment_key' => 'synthetic-native-component-source',
                'lab_generation_id' => $sourceGeneration->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'ema_rsi',
                'target' => 'profit_factor', 'gene_key' => 'ema_fast', 'status' => 'planned',
                'guided_agent_id' => $sourceAgents['guided']->id, 'blinded_agent_id' => $sourceAgents['blinded']->id,
                'control_agent_id' => $sourceAgents['control']->id, 'evidence' => []]);
            $design = ['protocol' => 'synthetic_prospective_component_matrix_v1',
                'validation_start' => $scope['validation_start'], 'validation_end' => $scope['validation_end'],
                'validation_windows' => $scope['validation_windows'], 'exposure_policy' => $scope['exposure_policy'],
                'authority_policy' => ScopedResearchCertificateService::AUTHORITY_POLICY,
                'evaluator_hash' => $scope['evaluator_hash'], 'execution_hash' => $scope['execution_hash'],
                'context_hash' => app(ContextContractV2Service::class)->project($scope['context'])['identity_hash'],
                'owner_source_hash' => hash_file('sha256', __FILE__), 'metric' => 'profit_factor',
                'stopping_rule' => $scope['stopping_rule'], 'subject' => ['candidate_role' => 'guided', 'context' => $scope['context']],
                'native_execution' => array_diff_key($scope['native_execution'], ['source_component_certificate_id' => true]),
                'research_only' => true, 'promotion_evidence' => false];
            $oldRun = LabEvaluationRun::create(['run_id' => 'synthetic-observed-discovery-source',
                'lab_generation_id' => $sourceGeneration->id, 'lab_agent_id' => $sourceAgents['guided']->id,
                'model_version_id' => $models['P+T']->id, 'phase' => 'screening', 'mode' => 'synthetic_test',
                'attempt' => 1, 'status' => 'completed', 'metadata' => ['hypothesis_only_not_market_proof' => true]]);
            $fresh = app(EvolutionaryAuthorityFoundryService::class)->preregisterScopedComponent($source, $design);
            $this->assertSame('scoped_preregistered', $fresh['status'], json_encode($fresh));
            $this->assertTrue($fresh['source_hypothesis_only']);
            $this->assertFalse($fresh['old_outcomes_upgraded']);
            $certificate = $fresh['certificate'];
            $this->assertTrue($certificate['valid'], json_encode($certificate));
            $registration = app(DescendantScopedExecutionService::class)->registerComponentWork($certificate['certificate_id']);
            $work = ResearchExperimentWorkItem::findOrFail($registration['work_id']);
            $actual = $certificate['design']['subject']['arm_models'];
            $models['P+T'] = ModelVersion::findOrFail($actual['candidate']['model_version_id']);
            $models['P'] = ModelVersion::findOrFail($actual['control']['model_version_id']);
            $this->assertNotSame((int) $sourceAgents['guided']->model_version_id, (int) $models['P+T']->id);
            $this->assertNotSame((int) $sourceAgents['control']->model_version_id, (int) $models['P']->id);
            $this->assertSame('completed', $oldRun->fresh()->status);
            $this->assertSame('already_registered', app(EvolutionaryAuthorityFoundryService::class)->preregisterScopedComponent($source, $design)['status']);
            $trialId = null;
        } else {
            $registered = app(DescendantScopedProofService::class)->preregister($cartridge, $models, $scope);
            $this->assertSame('scoped_preregistered', $registered['status'], json_encode($registered));
            $work = ResearchExperimentWorkItem::findOrFail($registered['native_work']['work_id']);
            $certificate = app(ScopedResearchCertificateService::class)->inspect($registered['certificate']['certificate_id']);
            $trialId = $registered['trial_id'];
        }
        $entries = [];
        foreach ($scope['validation_windows'] as $index => $period) {
            $hash = hash('sha256', 'synthetic matrix dataset '.$index);
            $streams = [];
            foreach (['M5', 'H4', 'H1', 'M15'] as $frame) {
                $streams[$frame] = [
                    'path' => 'synthetic-unread-'.$index.'-'.$frame.'.csv', 'sha256' => $hash, 'rows' => 300];
            }
            $entries[] = ['window' => ['window_key' => hash('sha256', 'synthetic matrix window '.$index),
                'dataset_sha256' => $hash, ...$period], 'manifest' => ['protocol' => MultiTimeframeSnapshotService::PROTOCOL,
                    'bundle_hash' => $hash, 'streams' => $streams]];
            $entries[$index]['original_input'] = ['files' => []];
        }
        $proof = ['protocol' => DescendantScopedExecutionService::PROTOCOL, 'status' => 'ready', 'executable' => true,
            'trial_id' => $trialId, 'certificate_id' => $certificate['certificate_id'],
            'design' => $certificate['design'], 'design_hash' => $certificate['design_hash'], 'windows' => $entries];
        $modeEnabled = true;
        $this->mock(AutonomousModeService::class, function (MockInterface $mock) use (&$modeEnabled): void {
            $mock->shouldReceive('enabled')->andReturnUsing(function () use (&$modeEnabled): bool {
                return $modeEnabled;
            });
        });
        $windows = \Mockery::mock(InstrumentResearchWindowService::class)->makePartial();
        $windows->shouldReceive('bindReplayRequest')->andReturnUsing(function ($generation, array $request): array {
            $request['policy_context']['authorized_research_transport'] = ['window' => [
                'window_key' => data_get($generation->trigger_context, 'scoped_descendant_execution.window_key')]];

            return $request;
        });
        $this->instance(InstrumentResearchWindowService::class, $windows);
        $capture = \Mockery::mock(ResearchWindowExposureInventoryService::class, [app(ResearchPaperEpochContractService::class)])->makePartial();
        $capture->shouldReceive('captureAttachedRequest')->andReturnNull();
        $this->instance(ResearchWindowExposureInventoryService::class, $capture);
        $releases = \Mockery::mock(ResearchReleaseSealService::class)->makePartial();
        $releases->shouldReceive('responseValid')->andReturnTrue();
        $releases->shouldReceive('pythonHash')->andReturn($certificate['design']['native_execution']['python_source_hash']);
        $this->instance(ResearchReleaseSealService::class, $releases);
        $immutable = \Mockery::mock(LabImmutableEvidenceService::class)->makePartial();
        $immutable->shouldReceive('codeHash')->andReturn($scope['evaluator_hash']);
        $immutable->shouldReceive('replayEvidenceCompleteness')->andReturn(['complete' => true, 'reason_codes' => []]);
        $this->instance(LabImmutableEvidenceService::class, $immutable);
        $executor = \Mockery::mock(DescendantScopedExecutionService::class, [app(DescendantScopedProofService::class),
            app(ScopedResearchCertificateService::class), $windows, $immutable,
            app(ResearchExperimentConversionKernelService::class), $releases])->makePartial();
        $executor->shouldReceive('inspectWork')->andReturn($proof);
        $executor->shouldReceive('verifyOriginalExecutedClock')->andReturnNull();
        $this->instance(DescendantScopedExecutionService::class, $executor);
        if (! $component) {
            AiLaboratory::create(['name' => 'synthetic native matrix wire', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
                'strategy_families' => ['ema_rsi'], 'is_active' => false]);
        }
        CarbonImmutable::setTestNow('2027-08-01T00:00:00Z');
        config(['queue.default' => 'database']);
        Bus::fake([ExecuteScopedResearchArmJob::class]);
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        Http::fake(['*/api/backtest/run-all' => function () use ($execution, $duringTransport, $work, &$modeEnabled) {
            if ($duringTransport === 'expired') {
                CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(3600));
            }
            if ($duringTransport === 'reassigned') {
                $work->fresh()->update(['lease_token' => 'replacement-scoped-owner', 'fence_version' => 99]);
            }
            if ($duringTransport === 'stopped') {
                $modeEnabled = false;
            }

            return Http::response(['leaderboard' => [['strategy' => 'ema_rsi_v1', 'result' => ['total_trades' => 0,
                'execution_contract' => $execution, 'data_quality' => [], 'decision_trace' => [], 'trade_ledger' => []]]]], 200);
        }]);
        // Each preparation delivery compiles one original window, keeping the
        // scheduler's bounded child separate from the long replay worker.
        for ($fence = 1; $fence <= 6; $fence++) {
            $work->refresh()->update(['status' => 'leased', 'attempts' => $fence, 'lease_token' => 'synthetic-preparation-'.$fence,
                'fence_version' => $fence, 'lease_expires_at' => now()->addSeconds(2700)]);
            $prepared = $executor->execute($work->fresh());
            $this->assertSame('checkpointed', $prepared['status'], json_encode($prepared));
            Http::assertNothingSent();
            Bus::assertNothingDispatched();
        }
        $work->refresh()->update(['status' => 'leased', 'attempts' => 7, 'lease_token' => 'synthetic-original-one',
            'fence_version' => 7, 'lease_expires_at' => now()->addSeconds(2700)]);
        $first = $executor->execute($work->fresh());
        $this->assertSame('queued', $first['status'], json_encode([$first, 'generations' => LabGeneration::count(), 'runs' => LabEvaluationRun::count()]));
        $this->assertDatabaseCount('lab_generations', $component ? 8 : 6);
        $this->assertDatabaseCount('lab_agents', $component ? 18 : 24);
        $this->assertDatabaseCount('lab_evaluation_runs', $component ? 1 : 0);
        $this->assertCount($component ? 12 : 24, data_get($work->fresh()->result, 'four_arm_barrier.original_request_hashes'));
        Http::assertNothingSent();
        $firstJob = null;
        Bus::assertDispatched(ExecuteScopedResearchArmJob::class, function ($job) use (&$firstJob): bool {
            $firstJob = $job;

            return $job->queue === 'lab-full-validation' && $job->timeout === 1980
                && $job->producerBudgetSeconds === 1620 && $job->fenceVersion === 7;
        });
        $firstJob->handle($executor);
        if ($duringTransport !== 'normal') {
            $run = LabEvaluationRun::where('phase', 'full_validation')->sole();
            $this->assertSame($duringTransport === 'stopped' ? 'completed' : 'technical_error', $run->status);
            $raw = app(LabImmutableEvidenceService::class)->latestArtifactPayload($run, 'evaluation_response');
            $this->assertSame(0, $raw['total_trades']); // Actual received bytes are retained, not rewritten as absence.
            if ($duringTransport === 'stopped') {
                $this->assertSame('blocked', $work->fresh()->status);
                $this->assertSame('AUTONOMOUS_MODE_STOPPED', $work->fresh()->last_error);
            } else {
                $this->assertTrue(data_get($run->metadata, 'original_response_retained'));
                $this->assertTrue(data_get($run->metadata, 'unassessable_original_observation'));
                $this->assertSame('DESCENDANT_WORK_LEASE_NOT_CURRENT', data_get($run->metadata, 'reason_code'));
            }
            $this->assertSame(1, count(data_get($work->fresh()->result, 'original_arms')));
            $firstJob->handle($executor);
            $executor->execute($work->fresh('receipt'));
            Http::assertSentCount(1);
            $this->assertDatabaseCount('lab_evaluation_runs', 1);
            $this->assertDatabaseCount('lab_evolution_credit_events', 0);
            $this->assertSame($duringTransport === 'stopped' ? 'completed' : 'technical_error', $run->fresh()->status);

            return;
        }
        $this->assertSame('ready', $work->fresh()->status);
        $this->assertDatabaseCount('lab_evaluation_runs', $component ? 2 : 1);
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $fence = $request['policy_context']['scoped_position_maturity_fence'];

            return $fence['protocol'] === 'scoped_original_maturity_fence_v1'
                && (int) CarbonImmutable::parse($fence['end_exclusive'])->diffInSeconds(CarbonImmutable::parse($fence['entry_end_exclusive']), true)
                    === $fence['holding_fence_seconds'];
        });
        $firstJob->handle($executor); // A consumed old lease cannot publish a second run.
        $this->assertDatabaseCount('lab_evaluation_runs', $component ? 2 : 1);
        Http::assertSentCount(1);
        $firstRun = LabEvaluationRun::where('phase', 'full_validation')->sole();
        $this->assertSame($models[$component ? 'P+T' : 'P']->id, $firstRun->model_version_id);
        $this->assertSame('completed', $firstRun->status);
        $this->assertSame($component ? DescendantScopedExecutionService::COMPONENT_PURPOSE : DescendantScopedExecutionService::PURPOSE,
            data_get($firstRun->metadata, 'scoped_research.purpose'));
        if ($wireFloatProbe) {
            $body = null;
            Http::assertSent(function ($sent) use (&$body): bool {
                $body = $sent->body();

                return true;
            });
            $decoded = json_decode($body, false, flags: JSON_THROW_ON_ERROR);
            $this->assertIsFloat($decoded->initial_balance);
            $this->assertSame(10000.0, $decoded->initial_balance);
            $this->assertInstanceOf(\stdClass::class, $decoded->strategies[0]->instrument_research_assignment);
            $this->assertSame([], get_object_vars($decoded->strategies[0]->instrument_research_assignment));
            $this->assertSame($firstRun->request_hash, hash('sha256', $body));
            $this->assertNotSame($firstRun->request_hash, hash('sha256', json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));

            return;
        }
        $work->refresh()->update(['status' => 'leased', 'attempts' => 8, 'lease_token' => 'synthetic-original-two',
            'fence_version' => 8, 'lease_expires_at' => now()->addSeconds(2700)]);
        $second = $executor->execute($work->fresh());
        $this->assertSame('queued', $second['status'], json_encode($second));
        $secondJob = null;
        Bus::assertDispatched(ExecuteScopedResearchArmJob::class, function ($job) use (&$secondJob): bool {
            if ($job->fenceVersion !== 8) {
                return false;
            }
            $secondJob = $job;

            return true;
        });
        $secondJob->handle($executor);
        $this->assertSame('ready', $work->fresh()->status);
        $this->assertDatabaseCount('lab_evaluation_runs', $component ? 3 : 2);
        $this->assertSame($models[$component ? 'P' : 'P+T']->id, LabEvaluationRun::latest('id')->first()->model_version_id);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->assertSame(0, DB::table('lab_agents')->whereNotNull('parent_a_model_version_id')->count());
        if ($component) {
            $this->assertDatabaseCount('descendant_value_trials', 0);
        }
    }

    private function fixture(bool $confirmSource = true, string $metric = 'profit_factor'): array
    {
        $schema = app(StrategyParameterSchemaService::class);
        $base = $schema->validate('ema_rsi_v1', array_intersect_key($schema->defaults('ema_rsi'), $schema->schema('ema_rsi')));
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        $gene = 'ema_fast';
        $other = 'rsi_period';
        $old = $base[$gene];
        $new = $old + 1;
        $vectors = ['P' => $base, 'P+T' => [...$base, $gene => $new],
            'P+T+U' => [...$base, $gene => $new, $other => $base[$other] + 1],
            'P+U' => [...$base, $other => $base[$other] + 1]];
        $models = [];
        foreach ($vectors as $arm => $parameters) {
            $models[$arm] = ModelVersion::create([
                'name' => 'synthetic native '.$arm, 'strategy' => 'ema_rsi_v1', 'version' => 'synthetic-native-v1',
                'status' => 'testing', 'parameters' => $parameters,
                'metadata' => ['base_strategy' => 'ema_rsi', 'execution_contract' => $execution]]);
        }
        $evidence = ['intervention' => ['old_value' => $old, 'tested_value' => $new], 'scoped_component_certificate_id' => 999];
        $cartridge = LabSkillZooEntry::create(['skill_key' => 'synthetic-native-trait', 'cartridge_key' => 'synthetic-native-trait',
            'model_version_id' => $models['P+T']->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'ema_rsi',
            'gene_key' => $gene, 'module_key' => 'entry', 'niche_key' => 'test', 'status' => 'scoped_confirmed',
            'component_status' => 'scoped_component_confirmed', 'revision' => 1, 'evidence' => $evidence]);
        DB::table('skill_cartridge_revisions')->insert(['lab_skill_zoo_entry_id' => $cartridge->id, 'revision' => 1,
            'revision_key' => hash('sha256', 'synthetic-native-revision'), 'payload' => json_encode($evidence),
            'sealed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $context = ['regime' => 'trend_up', 'volatility' => 'normal', 'session' => 'london', 'direction' => 'BUY'];
        $contextHash = app(ContextContractV2Service::class)->project($context)['identity_hash'];
        $registry = \Mockery::mock(ScopedResearchCertificateService::class, [app(ResearchPaperEpochContractService::class)])->makePartial();
        (function (MockInterface $mock) use ($confirmSource, $contextHash, $context, $execution, $models, $gene, $old, $new, $metric): void {
            $mock->shouldReceive('inspect')->with(999)->andReturn(['valid' => true, 'scope' => 'component',
                'source_id' => 999, 'certificate_id' => 999, 'design_hash' => hash('sha256', 'synthetic source design'),
                'design' => ['context_hash' => $contextHash, 'validation_start' => '2027-01-01T00:00:00Z',
                    'validation_end' => '2027-07-01T00:00:00Z', 'execution_hash' => $execution['execution_hash'],
                    'evaluator_hash' => app(LabImmutableEvidenceService::class)->codeHash(),
                    'metric' => $metric, 'stopping_rule' => ['minimum_trades_per_arm' => 8, 'minimum_effect' => .01],
                    'statistical_guard' => ['method' => 'paired_window_bootstrap_percentile', 'replicates' => 1000, 'seed' => 123, 'lower_quantile' => .05],
                    'risk_guard' => ['max_drawdown_percent' => 9.0, 'max_risk_of_ruin_percent' => 4.0],
                    'exposure_policy' => ['protocol' => 'prospective_scoped_exposure_policy_v1', 'holding_fence_seconds' => 259200,
                        'execution_timeframe' => 'M5', 'context_timeframes' => ['H4', 'H1', 'M15'],
                        'warmup_policy' => 'all_original_closed_source_rows_inside_registered_window', 'selection_policy' => 'frozen_before_first_event'],
                    'native_execution' => ['execution_timeframe' => 'M5', 'initial_capital' => 10000,
                        'risk_policy' => ['risk_per_trade_percent' => .5], 'full_replay_runtime_policy' => [
                            'protocol' => 'scoped_original_full_source_v1', 'evaluation_mode' => 'full',
                            'selection' => 'entire_authorized_source', 'maximum_source_rows' => 200000,
                            'maximum_runtime_seconds' => 1620, 'warmup_rows' => 0, 'no_walk_forward_selection' => true, 'promotion_evidence' => false]]],
                'original_authority' => ['component' => ['confirmed' => $confirmSource,
                    'authority_type' => 'context_bound_research_component', 'candidate_model_version_id' => (int) $models['P+T']->id,
                    'control_model_version_id' => (int) $models['P']->id, 'context_hash' => $contextHash, 'context' => $context,
                    'trait_delta' => ['gene' => $gene, 'old' => $old, 'new' => $new]]]]);
        })($registry);
        $this->instance(ScopedResearchCertificateService::class, $registry);
        $windows = [];
        for ($month = 1; $month <= 6; $month++) {
            $windows[] = ['start_inclusive' => sprintf('2027-%02d-01T00:00:00Z', $month),
                'end_exclusive' => sprintf('2027-%02d-01T00:00:00Z', $month + 1)];
        }
        $scope = ['validation_start' => '2027-01-01T00:00:00Z', 'validation_end' => '2027-07-01T00:00:00Z',
            'context' => $context, 'evaluator_hash' => app(LabImmutableEvidenceService::class)->codeHash(),
            'execution_hash' => $execution['execution_hash'], 'metric' => $metric,
            'stopping_rule' => ['minimum_trades_per_arm' => 8, 'minimum_effect' => .01], 'validation_windows' => $windows,
            'exposure_policy' => ['protocol' => 'prospective_scoped_exposure_policy_v1', 'holding_fence_seconds' => 259200,
                'execution_timeframe' => 'M5', 'context_timeframes' => ['H4', 'H1', 'M15'],
                'warmup_policy' => 'all_original_closed_source_rows_inside_registered_window', 'selection_policy' => 'frozen_before_first_event'],
            'native_execution' => ['source_component_certificate_id' => 999,
                'authorization_ids' => ['synthetic-1', 'synthetic-2', 'synthetic-3', 'synthetic-4', 'synthetic-5', 'synthetic-6'],
                'execution_timeframe' => 'M5', 'initial_capital' => 10000,
                'risk_policy' => ['risk_per_trade_percent' => .5],
                'full_replay_runtime_policy' => ['protocol' => 'scoped_original_full_source_v1', 'evaluation_mode' => 'full',
                    'selection' => 'entire_authorized_source', 'maximum_source_rows' => 200000,
                    'maximum_runtime_seconds' => 1620, 'warmup_rows' => 0,
                    'no_walk_forward_selection' => true, 'promotion_evidence' => false]]];

        return [$cartridge, $models, $scope];
    }
}
