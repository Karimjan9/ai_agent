<?php

namespace Tests\Feature;

use App\Jobs\ProjectLabCandleDecisionEvents;
use App\Jobs\EvaluateLabScreeningBatchJob;
use App\Jobs\EvaluateLabAgentJob;
use App\Jobs\RunScheduledArtisanCommandJob;
use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningSettlement;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\MarketTrainingArchive;
use App\Models\ModelVersion;
use App\Models\ResearchLoopDecision;
use App\Services\CandidateGateDecisionService;
use App\Services\FrozenControlScreeningAdmissionService;
use App\Services\ImmutableGenerationContractService;
use App\Services\LabAgentEvaluationService;
use App\Services\LabGenerationContextService;
use App\Services\LabGenerationTerminalBoundaryService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabInstrumentResearchService;
use App\Services\LabPopulationService;
use App\Services\LabQueueJobInspector;
use App\Services\MultiTimeframeSnapshotService;
use App\Services\OperatorApprovalService;
use App\Services\ProspectiveRepairProbeWindowService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\ResearchReleaseSealService;
use App\Services\ResearchLoopArbiterService;
use App\Services\ScheduledCommandOutcomeClassifierService;
use App\Services\UnusedDraftPriceDiscoveryPreparationService as Owner;
use App\Services\UnusedDraftPriceDiscoveryReceiptService as Receipts;
use App\Services\MarketData\SecondaryM5ResearchRecoveryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/** Synthetic bounded PHP fixtures, never production replay or untouched-market evidence. */
class UnusedDraftPriceDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private array $rows = [];
    private array $manifest = [];
    private array $proof = [];
    private array $nativeReadiness = [];
    private string $directory;
    private int $queueTotal = 0;
    private bool $constructorActive = false;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); Http::preventStrayRequests();
        $this->directory = storage_path('app/lab-datasets/tests/unused-price-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($this->directory);
        $queue = Mockery::mock(LabQueueJobInspector::class)->makePartial();
        $queue->shouldReceive('labQueues')->andReturn(['lab-screening']);
        $queue->shouldReceive('generationQueueBacklog')->andReturnUsing(fn () => ['available' => true,
            'backend' => 'database', 'total' => $this->queueTotal, 'rows' => $this->queueTotal === 0 ? [] : [['id' => 'fixture']]]);
        app()->instance(LabQueueJobInspector::class, $queue);
        $population = Mockery::mock(LabPopulationService::class)->makePartial();
        $population->shouldReceive('constructorIsActive')->andReturnUsing(fn () => $this->constructorActive);
        app()->instance(LabPopulationService::class, $population);
        $release = Mockery::mock(ResearchReleaseSealService::class)->makePartial();
        $release->shouldReceive('assertCurrent')->andReturnNull();
        $release->shouldReceive('responseValid')->andReturn(true);
        app()->instance(ResearchReleaseSealService::class, $release);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && str_starts_with($this->directory, storage_path('app/lab-datasets/tests/'))) File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function fixture(): LabGeneration
    {
        $lab = AiLaboratory::create(['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'name' => 'Price fixture',
            'is_active' => true, 'lifecycle_mode' => 'lighthouse', 'strategy_families' => ['trend']]);
        $plan = []; $pairs = [];
        for ($slot = 1; $slot <= 20; $slot++) {
            $plan[] = ['family' => 'trend', 'origin' => 'g98_council', 'target' => 'fixture', 'research_group' => 'repair_pair'];
            if ($slot % 2 === 1) $pairs[] = ['pair_index' => intdiv($slot, 2) + 1, 'control_slot' => $slot, 'candidate_slot' => $slot + 1,
                'block_key' => hash('sha256', 'block-'.$slot), 'block_type' => 'repair_pair',
                'pair_key' => hash('sha256', 'pair-'.$slot), 'control_arm' => 'exact_frozen_control', 'candidate_arm' => 'candidate'];
        }
        $generation = LabGeneration::unguarded(fn () => LabGeneration::create(['id' => 357, 'ai_laboratory_id' => $lab->id, 'generation' => 263,
            'population_size' => 20, 'trigger_type' => 'historical_research', 'status' => 'draft',
            'data_fingerprint' => str_repeat('f', 64), 'trigger_context' => ['generation_plan' => $plan,
                'historical_research_admission' => ['protocol' => 'pre_paper_historical_research_v1', 'research_before' => '2026-01-01T00:00:00Z',
                    'research_only' => true, 'requires_live_freshness' => false, 'archive_sha256' => str_repeat('f', 64)],
                'research_allocation_budget' => ['original_population' => 20],
                'control_pairing_contract' => ['protocol' => 'exact_frozen_control_pair_v2', 'mode' => 'cooperative_experiment_blocks',
                    'materialized_controls' => $pairs, 'primary_proof_slots' => [], 'uncertainty_abstain_slots' => []],
                'constructor_audit' => ['protocol' => 'agent_constructor_invariant_v1', 'planned_slots' => 20, 'created_agents' => 20,
                    'skipped_zero_diff_slots' => []]]]));
        for ($slot = 1; $slot <= 20; $slot++) {
            $controlId = 3414 + intdiv($slot - 1, 2) * 2;
            $pair = $pairs[intdiv($slot - 1, 2)];
            $model = ModelVersion::unguarded(fn () => ModelVersion::create(['id' => 3502 + $slot, 'name' => 'Price '.$slot,
                'strategy' => 'trend_g263_a'.$slot, 'version' => 'v1', 'generation' => 263, 'status' => 'testing',
                'parameters' => ['lookback' => 20 + $slot, 'volume_lane' => 'none'],
                'metadata' => ['execution_contract' => ['execution_hash' => str_repeat('e', 64)],
                    'cooperative_experiment_block' => ['block_key' => $pair['block_key'], 'block_type' => 'repair_pair'],
                    'control_pair_contract' => ['control_agent_id' => $controlId, 'generation_id' => 357],
                    'causal_learning_intent' => ['hypothesis_only' => true],
                    'control_contract' => $slot % 2 === 1 ? ['protocol' => 'frozen_control_v2', 'control_only' => true,
                        'role' => 'control', 'generation_id' => 357] : null]]));
            $agent = LabAgent::unguarded(fn () => LabAgent::create(['id' => 3413 + $slot, 'lab_generation_id' => 357, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend', 'origin' => 'g98_council', 'lifecycle_status' => 'draft',
                'parameter_diff' => $slot % 2 === 1 ? [] : ['lookback' => ['before' => 20 + $slot - 1, 'after' => 20 + $slot]]]));
            AgentLearningEpisode::create(['episode_id' => (string) Str::uuid(), 'decision_key' => 'price-episode-'.$slot,
                'lab_agent_id' => $agent->id, 'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
                'strategy_family' => 'trend', 'stage' => 'mutation_selection', 'status' => 'open',
                'decision' => $slot % 2 === 1 ? 'CONTROL' : 'MUTATE', 'data_hash' => str_repeat('f', 64),
                'context_hash' => hash('sha256', 'context-'.$slot),
                'execution_hash' => str_repeat('e', 64), 'decision_context' => ['original_block_key' => $pair['block_key']], 'opened_at' => now()]);
        }
        $generation->load('laboratory');
        $immutable = app(ImmutableGenerationContractService::class)->compile($generation, $plan,
            ['data_hash' => str_repeat('f', 64), 'execution_hash' => str_repeat('e', 64)]);
        app(LabGenerationContextService::class)->update($generation, fn ($ctx) => [...$ctx, 'immutable_generation_contract' => $immutable]);
        $this->proof = ['protocol' => SecondaryM5ResearchRecoveryService::PROTOCOL, 'verified' => true, 'provider' => 'mixed',
            'dataset_key' => 'research_mixed_gapfix_fixture', 'repair_hash' => str_repeat('c', 64),
            'original_bad_m5_sha256' => str_repeat('d', 64), 'prospective_m5_source_sha256' => str_repeat('9', 64),
            'economic_rows_sha256' => str_repeat('8', 64), 'row_attribution_sha256' => str_repeat('7', 64),
            'original_budget_scope_anchor' => ['protocol' => 'native_discovery_budget_anchor_v1', 'parent_fork_price_sha256' => str_repeat('6', 64),
                'parent_economic_rows_sha256' => str_repeat('5', 64), 'scientific_question_budget_reset' => false],
            'calendar_scope' => ['full_source_unexpected_after' => 0, 'whole_native_archive_continuity_proven' => false],
            'quote_liquidity_inherited' => false, 'independent_evidence' => false, 'promotion_evidence' => false];
        MarketTrainingArchive::create(['dataset_key' => $this->proof['dataset_key'], 'provider' => 'mixed', 'symbol' => 'XAUUSD',
            'timeframe' => 'M5', 'target_from' => '2024-01-01', 'target_to' => '2026-01-01',
            'status' => 'complete', 'row_count' => 200254, 'metrics' => ['secondary_m5_research_receipt' => [
                'native_parent_price_sha256' => str_repeat('a', 64), 'native_equivalence_proven' => false,
                'volume_available' => false, 'quote_liquidity_inherited' => false]]]);
        $secondary = Mockery::mock(SecondaryM5ResearchRecoveryService::class)->makePartial();
        $secondary->shouldReceive('verify')->andReturnUsing(fn ($archive) => $archive->provider === 'mixed' ? $this->proof : null);
        app()->instance(SecondaryM5ResearchRecoveryService::class, $secondary);
        $start = CarbonImmutable::parse('2025-09-01T00:00:00Z');
        for ($index = 0; $index < 15512; $index++) $this->rows[] = ['time' => $start->addMinutes(5 * $index)->format('Y-m-d\TH:i:s\Z'),
            'open' => 2000, 'high' => 2001, 'low' => 1999, 'close' => 2000, 'volume' => 1];
        $csv = "time,open,high,low,close,volume\n";
        foreach ($this->rows as $row) {
            $row['time'] = CarbonImmutable::parse($row['time'])->utc()->format('Y-m-d H:i:s');
            $csv .= implode(',', $row)."\n";
        }
        File::put($this->directory.'/m5.csv', $csv);
        $probe = app(ProspectiveRepairProbeWindowService::class)->seal($this->rows, str_repeat('b', 64), str_repeat('e', 64), 'fixture', 15000, 512);
        $calendar = array_intersect_key($probe, array_flip(['loaded_rows', 'evaluated_rows', 'warmup_rows', 'loaded_start', 'loaded_end',
            'evaluated_start', 'evaluated_end', 'evaluated_month_counts']));
        $this->manifest = ['protocol' => MultiTimeframeSnapshotService::PROTOCOL,
            'validation_bundle_protocol' => MultiTimeframeSnapshotService::DISCOVERY_BUNDLE_PROTOCOL, 'bundle_hash' => str_repeat('b', 64),
            'provider' => 'mixed', 'data_role' => 'pre_2026_discovery_only', 'prospective_m5_repair' => $this->proof,
            'streams' => ['M5' => ['path' => $this->directory.'/m5.csv', 'sha256' => hash_file('sha256', $this->directory.'/m5.csv'), 'row_count' => 15512]],
            'discovery_scope' => ['parent_dataset_key' => $this->proof['dataset_key'], 'calendar' => [...$calendar,
                'selected_unexpected_gaps' => 0, 'full_source_unexpected_gaps' => 0]],
            'quote_spread_provenance' => ['status' => 'unavailable', 'quote_liquidity_inherited' => false],
            'independent_evidence' => false, 'full_validation_eligible' => false, 'paper_eligible' => false, 'promotion_evidence' => false];
        $mtf = Mockery::mock(MultiTimeframeSnapshotService::class)->makePartial();
        $this->nativeReadiness = ['ready' => false, 'reason' => 'HISTORICAL_M5_CONTINUITY_SCOPE_UNRESOLVED',
            'full_source_unexpected_gaps' => 17, 'prospective_m5_repair' => ['verified' => true,
                'prospective_m5_source_path' => $this->directory.'/native.csv',
                'prospective_m5_source_sha256' => str_repeat('a', 64), 'calendar_scope' => ['remaining_missing_utc' => ['fixture17']]]];
        File::put($this->directory.'/native.csv', "synthetic native fixture bytes only\n");
        $mtf->shouldReceive('agentValidationReadiness')->andReturnUsing(fn () => $this->nativeReadiness);
        $mtf->shouldReceive('discoveryBundleReadiness')->andReturnUsing(fn ($manifest) => ['allowed' => $manifest === $this->manifest]);
        $mtf->shouldReceive('forProspectiveCleanDiscovery')->with('XAUUSD', $this->proof['dataset_key'], 15000, 512)->andReturnUsing(fn () =>
            ['bundle_hash' => $this->manifest['bundle_hash'], 'manifest' => $this->manifest]);
        app()->instance(MultiTimeframeSnapshotService::class, $mtf);
        $instruments = Mockery::mock(LabInstrumentResearchService::class)->makePartial();
        $instruments->shouldReceive('assignment')->andReturnUsing(function ($agent) {
            $assignment = ['protocol' => LabInstrumentResearchService::PROTOCOL, 'status' => 'no_executable_instrument_match',
                'lab_agent_id' => $agent->id, 'lab_generation_id' => $agent->lab_generation_id,
                'model_version_id' => $agent->model_version_id, 'assignment_hash' => hash('sha256', 'fixture-'.$agent->id)];
            $agent->modelVersion->update(['metadata' => [...(array) $agent->modelVersion->metadata, 'instrument_research_assignment' => $assignment]]);
            return $assignment;
        });
        app()->instance(LabInstrumentResearchService::class, $instruments);
        return $generation->fresh();
    }

    private function registered(LabGeneration $generation): array
    {
        $owner = app(Owner::class);
        $request = $owner->registration($generation, $this->proof['dataset_key'], 'New prospective attributed price question, not Root1');
        $approval = app(OperatorApprovalService::class)->requireForApply('unused-draft-price-discovery-intent', 'test-owner',
            'Synthetic fixture only', ['intent_hash' => $request['intent_hash'], 'generation_id' => $generation->id]);
        return $owner->registerIntent($generation, $request, $approval);
    }

    private function decision(LabGeneration $generation): ResearchLoopDecision
    {
        $proposal = app(Owner::class)->proposal($generation->fresh());
        $this->assertSame('would_prepare', $proposal['status'], json_encode($proposal));
        return ResearchLoopDecision::create(['decision_key' => hash('sha256', random_bytes(16)), 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'status' => 'running', 'action' => 'PREPARE_UNUSED_DRAFT_PRICE_DISCOVERY', 'command' => Owner::COMMAND,
            'queue' => 'scheduler-constructor', 'arguments' => ['generation' => 357], 'evidence_hash' => str_repeat('3', 64),
            'reason_codes' => ['SYNTHETIC_EXPLICIT_PRICE_QUESTION'],
            'evidence_snapshot' => ['price_discovery_proposal' => $proposal],
            'contract' => ['owner' => ResearchLoopArbiterService::class, 'selection_cardinality' => 1]]);
    }

    private function prepared(): LabGeneration
    {
        $generation = $this->fixture(); $this->registered($generation);
        app(Owner::class)->prepareFromDecision($this->decision($generation));
        return $generation->fresh();
    }

    public function test_original_twenty_genomes_pairs_constructor_and_native_seventeen_dependency_are_unchanged(): void
    {
        $generation = $this->fixture(); $before = app(Owner::class)->snapshot($generation);
        $intent = $this->registered($generation);
        $decision = $this->decision($generation);
        $prepared = app(Owner::class)->prepareFromDecision($decision);
        $fresh = $generation->fresh();
        $this->assertSame($before, app(Owner::class)->snapshot($fresh));
        $this->assertSame(20, $fresh->agents()->where('lifecycle_status', 'draft')->count());
        $this->assertSame(17, data_get($fresh->trigger_context, 'unused_draft_native_full_dependency.full_source_unexpected_gaps'));
        $this->assertFalse(data_get($fresh->trigger_context, 'unused_draft_native_full_dependency.native_archive_repaired'));
        $this->assertSame($intent['native_full_dependency_hash'], $prepared['native_full_dependency_hash']);
        $this->assertSame($prepared, app(Owner::class)->prepareFromDecision($decision));
        $this->assertSame(20, ModelVersion::whereNotNull('metadata->'.Owner::MODEL_SEAL)->count());
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('candidate_gate_decisions', 0);
        Queue::assertNothingPushed();
    }

    public function test_preview_labels_and_deployment_hash_do_not_renew_physical_question_and_root1_is_not_opened(): void
    {
        $generation = $this->fixture(); $owner = app(Owner::class);
        $a = $owner->registration($generation, $this->proof['dataset_key'], 'Question A');
        $b = $owner->registration($generation, $this->proof['dataset_key'], 'Different label');
        $this->assertSame($a['physical_question_key'], $b['physical_question_key']);
        $this->assertNotSame($a['intent_hash'], $b['intent_hash']);
        $this->assertFalse($a['old_root1_allowance_reopened']);
        $this->assertSame(1, $a['resource_contract']['maximum_attempts_per_original_agent']);
        $this->assertSame(14800, $a['resource_contract']['expected_ordinary_decision_candle_coverage']);
        $this->assertDatabaseCount('edge_academy_trials', 0);
        $this->assertDatabaseCount('research_experiment_work_items', 0);
    }

    public function test_registration_requires_persisted_exact_approval_not_caller_permission_boolean(): void
    {
        $generation = $this->fixture(); $owner = app(Owner::class);
        $request = $owner->registration($generation, $this->proof['dataset_key'], 'Question');
        $this->expectExceptionMessage('UNUSED_PRICE_DISCOVERY_ORIGINAL_OPERATOR_APPROVAL_REQUIRED');
        $owner->registerIntent($generation, $request, ['approved' => true]);
    }

    public function test_queue_or_constructor_ownership_blocks_registration_before_any_seal(): void
    {
        $generation = $this->fixture(); $this->queueTotal = 1;
        try { app(Owner::class)->registration($generation, $this->proof['dataset_key'], 'Question'); $this->fail(); }
        catch (\LogicException $error) { $this->assertStringContainsString('GENERATION_QUEUE_NOT_PROVEN_EMPTY', $error->getMessage()); }
        $this->queueTotal = 0; $this->constructorActive = true;
        try { app(Owner::class)->registration($generation, $this->proof['dataset_key'], 'Question'); $this->fail(); }
        catch (\LogicException $error) { $this->assertStringContainsString('CONSTRUCTOR_LEASE_ACTIVE', $error->getMessage()); }
        $this->assertNull(data_get($generation->fresh()->trigger_context, Owner::INTENT));
    }

    public function test_original_model_or_allocation_drift_blocks_preparation_without_partial_models_or_bundle(): void
    {
        $generation = $this->fixture(); $this->registered($generation); $decision = $this->decision($generation);
        $model = ModelVersion::findOrFail(3503); $model->update(['parameters' => [...$model->parameters, 'lookback' => 999]]);
        $this->expectExceptionMessage('UNUSED_PRICE_DISCOVERY_CURRENT_ARBITER_PROPOSAL_DRIFT');
        app(Owner::class)->prepareFromDecision($decision);
    }

    public function test_preparation_requires_running_original_arbiter_and_current_control_revision(): void
    {
        $generation = $this->fixture(); $this->registered($generation); $decision = $this->decision($generation);
        $decision->update(['status' => 'selected']);
        $this->expectExceptionMessage('UNUSED_PRICE_DISCOVERY_RUNNING_CURRENT_ARBITER_DECISION_REQUIRED');
        app(Owner::class)->prepareFromDecision($decision);
    }

    public function test_full_direct_gate_and_stale_multi_agent_batch_are_denied_before_evidence_or_authority(): void
    {
        $generation = $this->prepared(); $agent = $generation->agents()->with('modelVersion', 'generation')->first();
        try { app(LabAgentEvaluationService::class)->evaluate($agent); $this->fail(); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('FULL_VALIDATION_FORBIDDEN', $error->getMessage()); }
        try { app(CandidateGateDecisionService::class)->recordScreening($agent, ['profit_factor' => 999, 'total_trades' => 999]); $this->fail(); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('CANDIDATE_GATE_FORBIDDEN', $error->getMessage()); }
        $generation->agents()->update(['lifecycle_status' => 'queued']);
        try { app(LabAgentEvaluationService::class)->screenBatch([3414, 3415], 'XAUUSD'); $this->fail(); }
        catch (\RuntimeException $error) { $this->assertSame('PROSPECTIVE_SCREEN_REQUIRES_SINGLE_CANDIDATE_JOB', $error->getMessage()); }
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('candidate_gate_decisions', 0);
        Queue::assertNothingPushed();
    }

    public function test_persisted_model_seals_keep_deleted_context_fail_closed(): void
    {
        $generation = $this->prepared();
        app(LabGenerationContextService::class)->update($generation, fn ($context) => array_diff_key($context, array_flip([Owner::OWNER, Owner::INTENT, 'mtf_bundle_manifest'])));
        $agent = $generation->fresh()->agents()->with('modelVersion', 'generation')->first();
        $this->assertTrue(app(Owner::class)->declares($agent->generation));
        $this->expectExceptionMessage('UNUSED_PRICE_DISCOVERY_ORIGINAL_INTENT_INVALID');
        app(LabImmutableEvidenceService::class)->beginRun($agent, 'screening', 'screen');
    }

    public function test_attempt_cap_is_original_physical_agent_not_label_or_code_retry(): void
    {
        $generation = $this->prepared(); $agent = $generation->agents()->with('modelVersion', 'generation')->first();
        $run = app(LabImmutableEvidenceService::class)->beginRun($agent, 'screening', 'screen');
        $this->assertSame(1, $run->attempt);
        try { app(LabImmutableEvidenceService::class)->beginRun($agent, 'screening', 'renamed-mode'); $this->fail(); }
        catch (\LogicException $error) { $this->assertStringContainsString('ORIGINAL_AGENT_ATTEMPT_EXHAUSTED', $error->getMessage()); }
        $this->assertDatabaseCount('lab_evaluation_runs', 1);
    }

    private function originalScreen(LabAgent $agent, bool $shiftedTime = false, bool $extraEvent = false): LabEvaluationRun
    {
        $agent->load('modelVersion', 'generation'); $evidence = app(LabImmutableEvidenceService::class);
        $run = $evidence->beginRun($agent, 'screening', 'incremental');
        $probe = app(ProspectiveRepairProbeWindowService::class)->seal($this->rows, $this->manifest['bundle_hash'], str_repeat('e', 64), 'fixture-original', 15000, 512);
        $request = app(Owner::class)->bindRequest($agent, ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'evaluation_mode' => 'incremental',
            'replay_dataset_hash' => $this->manifest['bundle_hash'], 'mtf_snapshot_manifest' => $this->manifest,
            'related_mtf_dataset_paths' => (object) [], 'policy_context' => ['prospective_probe_window' => $probe],
            'research_release' => ['source_hash' => $run->code_hash, 'dataset_hash' => $this->manifest['bundle_hash']]]);
        $evidence->attachRequest($run, $request, ['data_hash' => $this->manifest['bundle_hash']]);
        $trace = [];
        for ($index = 200; $index < 15000; $index++) $trace[] = ['candle_index' => $index, 'candle_time' => $this->rows[512 + $index]['time'],
            'event_type' => 'signal_evaluation', 'action' => $index === 201 ? 'BUY' : 'WAIT', 'accepted' => $index === 201,
            'rejection_code' => $index === 201 ? null : 'unknown_quote_input'];
        if ($shiftedTime) $trace[0]['candle_time'] = $this->rows[713]['time'];
        if ($extraEvent) $trace[] = ['candle_index' => 201, 'candle_time' => $this->rows[713]['time'],
            'event_type' => 'position_management', 'action' => 'EXIT', 'accepted' => true];
        $response = ['total_trades' => 0, 'trade_ledger' => [], 'trades' => [], 'displayed_trade_count' => 0,
            'trade_ledger_hash' => hash('sha256', '[]'), 'decision_trace' => $trace,
            'prospective_probe_window_receipt' => [...$probe, 'complete' => true],
            'data_quality' => ['research_release_receipt' => [], 'decision_trace' => ['protocol' => 'candle_decision_trace_v1',
                'requested' => true, 'complete' => true, 'event_count' => count($trace), 'evaluated_candle_count' => 14800, 'input_candle_count' => 15000]]];
        $evidence->finishRun($run, 'completed', $response, [], ['reason_code' => Owner::RESEARCH_ONLY]);
        $agent->update(['lifecycle_status' => 'screened']);
        return $run->fresh();
    }

    public function test_original_artifact_receipt_measures_true_14800_decisions_and_neutrally_closes_original_pair(): void
    {
        $generation = $this->prepared(); $agents = $generation->agents()->with('modelVersion')->orderBy('id')->get();
        $candidate = $agents[1];
        $this->assertSame('waiting', app(FrozenControlScreeningAdmissionService::class)->admission($candidate)['status']);
        $controlRun = $this->originalScreen($agents[0]);
        $receipt = app(Receipts::class)->record($controlRun);
        $this->assertSame(14800, $receipt['decision_facts']['observed_evaluated_candle_coverage']);
        $this->assertSame(1, $receipt['decision_facts']['decision_event_counts']['ENTRY']);
        $this->assertSame(14799, $receipt['decision_facts']['decision_event_counts']['WAIT']);
        $this->assertSame(15000, $receipt['probe_window_receipt']['evaluated_rows']);
        $this->assertSame(512, $receipt['probe_window_receipt']['warmup_rows']);
        $this->assertSame(200, $receipt['decision_facts']['unchanged_ordinary_execution_warmup_rows']);
        $this->assertFalse(app(LabImmutableEvidenceService::class)->learningEligibility($controlRun)['complete']);
        $this->assertSame('ready', app(FrozenControlScreeningAdmissionService::class)->admission($candidate->fresh('modelVersion'))['status']);
        $candidateRun = $this->originalScreen($candidate);
        app(Receipts::class)->record($candidateRun);
        $before = $generation->fresh()->trigger_context;
        $this->assertCount(1, $before[Receipts::PAIRS]);
        $this->assertSame('neutral_research_observation_only', array_values($before[Receipts::PAIRS])[0]['outcome']);
        $this->assertCount(2, $before[Receipts::RECEIPTS]);
        $this->assertDatabaseCount('agent_learning_settlements', 2);
        $this->assertSame(0.0, (float) AgentLearningSettlement::sum('selection_reward'));
        app(Receipts::class)->record($candidateRun);
        $this->assertSame($before, $generation->fresh()->trigger_context);
        $this->assertDatabaseCount('agent_learning_settlements', 2);
        foreach (['candidate_gate_decisions', 'cooperative_experiment_settlements', 'lab_mutation_response_maps',
            'screening_learning_outbox', 'research_experiment_work_items', 'evolutionary_authority_ledgers'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        Queue::assertPushed(ProjectLabCandleDecisionEvents::class, 2);
        $this->assertSame(2, array_sum(array_map('count', Queue::pushedJobs())));
    }

    public function test_close_safeguard_requires_twenty_real_originals_not_status_projection_or_empty_watermark(): void
    {
        $generation = $this->prepared(); $generation->agents()->update(['lifecycle_status' => 'screened']);
        $generation->update(['status' => 'screening']);
        $closed = app(LabGenerationTerminalBoundaryService::class)->closeIfTerminal($generation);
        $this->assertFalse($closed['closed']);
        $this->assertSame('UNUSED_PRICE_DISCOVERY_ORIGINAL_PROJECTION_NOT_TERMINAL', $closed['reason_code']);
        $this->assertNull($generation->fresh()->completed_at);
        $this->assertSame('screening', $generation->fresh()->status);
        $this->assertDatabaseCount('agent_learning_settlements', 0);
    }

    public function test_classifier_cannot_call_a_typed_refusal_or_registration_a_completed_preparation(): void
    {
        $classifier = app(ScheduledCommandOutcomeClassifierService::class);
        foreach (['blocked', 'deferred', 'registered'] as $status) $this->assertSame('deferred',
            $classifier->classify(Owner::COMMAND, [], 0, json_encode(['status' => $status]))['status']);
        $this->assertSame('completed', $classifier->classify(Owner::COMMAND, [], 0, '{"status":"prepared"}')['status']);
    }

    public function test_foreign_generation_copied_model_seal_cannot_screen_or_create_an_ordinary_run(): void
    {
        $generation = $this->prepared(); $source = ModelVersion::findOrFail(3503);
        $foreign = LabGeneration::create(['ai_laboratory_id' => $generation->ai_laboratory_id, 'generation' => 264,
            'population_size' => 1, 'trigger_type' => 'test', 'status' => 'draft', 'trigger_context' => []]);
        $model = ModelVersion::create(['name' => 'Copied owner', 'strategy' => 'trend', 'version' => 'v1', 'generation' => 264,
            'status' => 'testing', 'parameters' => $source->parameters, 'metadata' => [Owner::MODEL_SEAL => data_get($source->metadata, Owner::MODEL_SEAL)]]);
        $agent = LabAgent::create(['lab_generation_id' => $foreign->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend', 'origin' => 'test', 'lifecycle_status' => 'draft']);
        try { app(LabAgentEvaluationService::class)->screen($agent); $this->fail(); }
        catch (\LogicException $error) { $this->assertStringContainsString('ORPHAN_OR_FOREIGN_MODEL_SEAL', $error->getMessage()); }
        try { app(LabImmutableEvidenceService::class)->beginRun($agent, 'screening', 'screen'); $this->fail(); }
        catch (\LogicException $error) { $this->assertStringContainsString('ORPHAN_OR_FOREIGN_MODEL_SEAL', $error->getMessage()); }
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_genuine_failed_job_keeps_one_original_envelope_and_uses_only_neutral_owned_technical_projection(): void
    {
        $generation = $this->prepared(); $agent = $generation->agents()->with('modelVersion', 'generation')->first();
        $run = app(LabImmutableEvidenceService::class)->beginRun($agent, 'screening', 'screen');
        $agent->update(['lifecycle_status' => 'screening']);
        $job = new EvaluateLabScreeningBatchJob([$agent->id], 'XAUUSD', 0, 357, 'H1');
        $job->failed(new \RuntimeException('Synthetic original transport failure'));
        $run->refresh();
        $this->assertSame('technical_error', $run->status);
        $receipt = data_get($generation->fresh()->trigger_context, Receipts::RECEIPTS.'.'.$agent->id);
        $this->assertSame('technical_unassessable', $receipt['kind']);
        $this->assertTrue($receipt['original_terminal_envelope']);
        $this->assertFalse($receipt['request_available']);
        $this->assertNull($receipt['decision_facts']);
        $this->assertFalse($receipt['measurement_available']);
        $this->assertSame('technical_quarantine', $agent->fresh()->lifecycle_status);
        $this->assertSame(Receipts::class, AgentLearningSettlement::sole()->source_type);
        $this->assertDatabaseCount('lab_evaluation_runs', 1);
        $before = $run->response_hash;
        $job->failed(new \RuntimeException('Late callback must not rewrite original'));
        $this->assertSame($before, $run->fresh()->response_hash);
        $this->assertDatabaseCount('lab_evaluation_runs', 1);
        $this->assertDatabaseCount('agent_learning_settlements', 1);
        $this->assertDatabaseCount('candidate_gate_decisions', 0);
        $this->assertDatabaseCount('screening_learning_outbox', 0);
        $this->assertDatabaseCount('candidate_handoff_events', 0);
    }

    public function test_failed_control_has_real_pre_execution_candidate_refusal_without_manufactured_run_or_coverage(): void
    {
        $generation = $this->prepared(); $agents = $generation->agents()->with('modelVersion', 'generation')->orderBy('id')->get();
        $control = $agents[0]; $candidate = $agents[1];
        $run = app(LabImmutableEvidenceService::class)->beginRun($control, 'screening', 'screen');
        app(LabImmutableEvidenceService::class)->finishRun($run, 'technical_error', ['malformed' => 'actual non-null original response'], [],
            ['reason_code' => 'SYNTHETIC_PRODUCER_FAILURE']);
        app(Receipts::class)->terminal($run->fresh());
        $candidate->update(['lifecycle_status' => 'queued']);
        $job = new EvaluateLabScreeningBatchJob([$candidate->id], 'XAUUSD', 0, 357, 'H1');
        $job->handle(app(LabAgentEvaluationService::class), app(FrozenControlScreeningAdmissionService::class),
            app(\App\Services\LearningTechnicalCircuitBreakerService::class));
        $receipt = data_get($generation->fresh()->trigger_context, Receipts::RECEIPTS.'.'.$candidate->id);
        $this->assertSame('pre_execution_control_refusal', $receipt['kind']);
        $this->assertSame($run->run_id, $receipt['original_control_run_id']);
        $this->assertNull($receipt['original_run_id']);
        $this->assertNull($receipt['decision_facts']);
        $this->assertFalse($receipt['replay_executed']);
        $this->assertSame(0, $receipt['attempts_executed']);
        $this->assertSame('technical_error', $run->fresh()->status);
        $this->assertDatabaseCount('lab_evaluation_runs', 1);
        $this->assertDatabaseCount('agent_learning_settlements', 2);
        $pair = array_values(data_get($generation->fresh()->trigger_context, Receipts::PAIRS))[0];
        $this->assertFalse($pair['measurement_available']);
        $this->assertSame('neutral_technical_or_pre_execution_disposition', $pair['outcome']);
        $this->assertDatabaseCount('cooperative_experiment_settlements', 0);
    }

    public function test_missing_or_legacy_inline_original_artifacts_cannot_close_a_new_owner(): void
    {
        $generation = $this->prepared(); $agent = $generation->agents()->with('modelVersion', 'generation')->first();
        $run = app(LabImmutableEvidenceService::class)->beginRun($agent, 'screening', 'screen');
        app(LabImmutableEvidenceService::class)->finishRun($run, 'technical_error', null);
        $artifact = \App\Models\LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_response')->sole();
        $artifact->update(['storage_path' => null, 'payload' => ['terminal_replay_envelope' => ['status' => 'technical_error']],
            'metadata' => ['storage_protocol' => 'legacy_inline']]);
        $this->expectExceptionMessage('UNUSED_PRICE_DISCOVERY_ACTUAL_COMPRESSED_ORIGINAL_ARTIFACT_REQUIRED');
        app(Receipts::class)->terminal($run->fresh());
    }

    public function test_price_publication_retries_are_two_undelivered_transports_not_executed_question_retries(): void
    {
        $arbiter = app(ResearchLoopArbiterService::class); $choose = new \ReflectionMethod($arbiter, 'decide');
        foreach (['PREPARE_UNUSED_DRAFT_PRICE_DISCOVERY', 'DISPATCH_UNUSED_DRAFT_PRICE_DISCOVERY'] as $action) {
            $arguments = ['generation' => 357, '--dispatch' => $action === 'DISPATCH_UNUSED_DRAFT_PRICE_DISCOVERY'];
            $args = ['XAUUSD', 'H1', $action, 100, Owner::COMMAND, $arguments, 'scheduler-constructor', ['READY'],
                ['price_discovery_proposal' => ['status' => 'fixture', 'intent_hash' => hash('sha256', $action)]], false];
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $result = $choose->invokeArgs($arbiter, $args);
                $this->assertSame('dispatched', $result['status']);
                (new UniqueLock(Cache::store()))->release(new RunScheduledArtisanCommandJob($result['command'],
                    $result['arguments'], $result['queue'], $result['decision_id']));
            }
            $this->assertSame('duplicate_suppressed', $choose->invokeArgs($arbiter, $args)['status']);
        }
        $this->assertDatabaseCount('research_loop_decisions', 6);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_completed_malformed_original_response_is_preserved_but_only_technical_disposition_is_published(): void
    {
        $generation = $this->prepared(); $agent = $generation->agents()->with('modelVersion', 'generation')->first();
        $evidence = app(LabImmutableEvidenceService::class); $run = $evidence->beginRun($agent, 'screening', 'screen');
        $request = app(Owner::class)->bindRequest($agent, ['symbol' => 'XAUUSD', 'timeframe' => 'M5',
            'mtf_snapshot_manifest' => $this->manifest, 'policy_context' => [], 'related_mtf_dataset_paths' => (object) []]);
        $evidence->attachRequest($run, $request, ['data_hash' => $this->manifest['bundle_hash']]);
        $evidence->finishRun($run, 'completed', ['unexpected_non_null_response' => true]);
        $run->refresh(); $before = $run->response_hash;
        $receipt = app(Receipts::class)->terminal($run);
        $this->assertSame('technical_unassessable', $receipt['kind']);
        $this->assertFalse($receipt['measurement_available']);
        $this->assertNull($receipt['decision_facts']);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame($before, $run->fresh()->response_hash);
        $this->assertSame($receipt['receipt_hash'], app(Receipts::class)->terminal($run->fresh())['receipt_hash']);
        $this->assertDatabaseCount('lab_evaluation_runs', 1);
        $this->assertDatabaseCount('agent_learning_settlements', 1);
    }

    public function test_executed_and_semantically_deferred_price_commands_never_receive_publication_retry(): void
    {
        $arbiter = app(ResearchLoopArbiterService::class); $choose = new \ReflectionMethod($arbiter, 'decide');
        foreach (['PREPARE_UNUSED_DRAFT_PRICE_DISCOVERY', 'DISPATCH_UNUSED_DRAFT_PRICE_DISCOVERY'] as $action) {
            foreach (['running', 'completed', 'deferred', 'failed'] as $status) {
                $arguments = ['generation' => 357, '--dispatch' => $action === 'DISPATCH_UNUSED_DRAFT_PRICE_DISCOVERY'];
                $args = ['XAUUSD', 'H1', $action, 100, Owner::COMMAND, $arguments, 'scheduler-constructor', ['READY'],
                    ['price_discovery_proposal' => ['status' => $status, 'intent_hash' => hash('sha256', $action.$status)]], false];
                $original = $choose->invokeArgs($arbiter, $args);
                (new UniqueLock(Cache::store()))->release(new RunScheduledArtisanCommandJob($original['command'],
                    $original['arguments'], $original['queue'], $original['decision_id']));
                ResearchLoopDecision::findOrFail($original['decision_id'])->update(['status' => $status]);
                $duplicate = $choose->invokeArgs($arbiter, $args);
                $this->assertSame('duplicate_suppressed', $duplicate['status']);
                $this->assertSame($original['decision_id'], $duplicate['decision_id']);
            }
        }
        $this->assertDatabaseCount('research_loop_decisions', 8);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_stored_trace_must_match_physical_m5_utc_not_only_claimed_index_coverage(): void
    {
        $generation = $this->prepared(); $agent = $generation->agents()->with('modelVersion', 'generation')->first();
        $run = $this->originalScreen($agent, shiftedTime: true); $hash = $run->response_hash;
        $receipt = app(Receipts::class)->terminal($run);
        $this->assertSame('technical_unassessable', $receipt['kind']);
        $this->assertSame('UNUSED_PRICE_DISCOVERY_ORIGINAL_DECISION_PHYSICAL_UTC_MISMATCH', $receipt['technical_reason']);
        $this->assertFalse($receipt['measurement_available']);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame($hash, $run->fresh()->response_hash);
        $this->assertDatabaseCount('lab_evaluation_runs', 1);
    }

    public function test_multiple_original_events_per_candle_are_counted_separately_from_unique_coverage(): void
    {
        $generation = $this->prepared(); $agent = $generation->agents()->with('modelVersion', 'generation')->first();
        $receipt = app(Receipts::class)->record($this->originalScreen($agent, extraEvent: true));
        $this->assertSame(14800, $receipt['decision_facts']['observed_evaluated_candle_coverage']);
        $this->assertSame(14801, $receipt['decision_facts']['observed_evaluated_event_count']);
        $this->assertSame(14801, array_sum($receipt['decision_facts']['decision_event_counts']));
        $this->assertSame(1, $receipt['decision_facts']['decision_event_counts']['EXIT']);
        $this->assertFalse($receipt['economic_credit_allowed']);
    }

    public function test_single_agent_failed_callback_does_not_manufacture_an_attempt_and_original_failure_avoids_ordinary_fanout(): void
    {
        $generation = $this->prepared(); $agent = $generation->agents()->with('modelVersion', 'generation')->first();
        $job = new EvaluateLabAgentJob($agent->id, 'XAUUSD', 'screen');
        $job->failed(new \RuntimeException('Synthetic failure before a genuine original attempt'));
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('agent_learning_settlements', 0);
        $run = app(LabImmutableEvidenceService::class)->beginRun($agent, 'screening', 'screen');
        $job->failed(new \RuntimeException('Synthetic failure of the original attempt'));
        $this->assertSame('technical_error', $run->fresh()->status);
        $this->assertDatabaseCount('lab_evaluation_runs', 1);
        $this->assertSame(Receipts::class, AgentLearningSettlement::sole()->source_type);
        foreach (['candidate_gate_decisions', 'candidate_handoff_events', 'screening_learning_outbox', 'lab_mutation_response_maps'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_twenty_known_terminal_technical_and_pre_execution_dispositions_close_only_after_owned_queue_drain(): void
    {
        $generation = $this->prepared(); $before = app(Owner::class)->snapshot($generation);
        $agents = $generation->agents()->with('modelVersion', 'generation')->orderBy('id')->get();
        for ($index = 0; $index < 20; $index += 2) {
            $run = app(LabImmutableEvidenceService::class)->beginRun($agents[$index], 'screening', 'screen');
            app(LabImmutableEvidenceService::class)->finishRun($run, 'technical_error', null);
            app(Receipts::class)->terminal($run->fresh());
            app(Receipts::class)->refuseControl($agents[$index + 1], $agents[$index]->id);
        }
        $generation->update(['status' => 'screening']);
        $this->queueTotal = 1;
        $this->assertFalse(app(LabGenerationTerminalBoundaryService::class)->closeIfTerminal($generation)['closed']);
        $this->queueTotal = 0;
        $closed = app(LabGenerationTerminalBoundaryService::class)->closeIfTerminal($generation);
        $this->assertTrue($closed['closed'], json_encode($closed));
        $this->assertSame('technical_quarantine', $generation->fresh()->status);
        $this->assertSame(10, data_get($generation->fresh()->trigger_context, 'generation_terminal_recovery.price_discovery_disposition.original_terminal_run_count'));
        $this->assertSame(10, data_get($generation->fresh()->trigger_context, 'generation_terminal_recovery.price_discovery_disposition.withheld_pre_execution_originals'));
        $this->assertSame(0, data_get($generation->fresh()->trigger_context, 'generation_terminal_recovery.price_discovery_disposition.measured_originals'));
        $this->assertSame($before, app(Owner::class)->snapshot($generation->fresh()));
        $this->assertDatabaseCount('lab_evaluation_runs', 10);
        $this->assertSame(10, LabEvaluationRun::where('status', 'technical_error')->count());
        $this->assertDatabaseCount('agent_learning_settlements', 20);
        $this->assertSame(20, AgentLearningSettlement::where('source_type', Receipts::class)->where('evidence_state', 'neutral')->count());
        $this->assertCount(10, data_get($generation->fresh()->trigger_context, Receipts::PAIRS));
        $this->assertCount(20, data_get($generation->fresh()->trigger_context, Receipts::RECEIPTS));
        $this->assertSame(0.0, (float) AgentLearningSettlement::sum('selection_reward'));
        $this->assertSame(17, data_get($generation->fresh()->trigger_context, 'unused_draft_native_full_dependency.full_source_unexpected_gaps'));
        foreach (['candidate_gate_decisions', 'cooperative_experiment_settlements', 'screening_learning_outbox', 'evolutionary_authority_ledgers'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_terminal_price_question_waits_on_fresh_native_dependency_without_replay_or_ordinary_recovery(): void
    {
        $generation = $this->prepared(); $generation->update(['status' => 'technical_quarantine']);
        $generation->agents()->update(['lifecycle_status' => 'technical_quarantine']);
        $this->mock(\App\Services\AcademyExperimentMaterializerService::class, fn ($mock) => $mock->shouldReceive('proposal')->andReturn([]));
        $this->mock(\App\Services\ResearchClosureInvariantService::class, fn ($mock) => $mock->shouldReceive('inspect')->andReturn(['healthy' => true]));
        $this->mock(\App\Services\CausalLearningCohortPlannerService::class, fn ($mock) => $mock->shouldReceive('eligibleLesson')->andReturnNull());
        $this->mock(\App\Services\InstrumentInvocationLedgerService::class, fn ($mock) => $mock->shouldReceive('pendingResearchPairs')->andReturn([]));
        $this->mock(\App\Services\LearningLaneService::class, function ($mock) {
            $mock->shouldReceive('priorityResearchPair')->andReturnNull();
            $mock->shouldReceive('pendingMicroPairs', 'frontier')->andReturn(collect());
        });
        $this->mock(\App\Services\AutonomousLearningProgressDirectorService::class, fn ($mock) => $mock->shouldReceive('advance')->andReturn(['action' => 'WAIT']));
        $conversion = Mockery::mock(\App\Services\ResearchExperimentConversionKernelService::class);
        $conversion->shouldReceive('claimForOwner')->andReturn([], [], [
            new \App\Models\ResearchExperimentWorkItem(['id' => 91, 'work_type' => 'unrelated_authorized_original_panel',
                'priority' => 99, 'research_experiment_receipt_id' => 77, 'lease_token' => 'fixture-existing-lease', 'fence_version' => 1, 'result' => []]),
        ]);
        app()->instance(\App\Services\ResearchExperimentConversionKernelService::class, $conversion);
        $arbiter = app(ResearchLoopArbiterService::class); $choose = new \ReflectionMethod($arbiter, 'tickLocked');
        $before = $choose->invoke($arbiter, 'XAUUSD', 'H1', false);
        $this->assertSame('WAIT_UNUSED_DRAFT_PRICE_DISCOVERY_DEPENDENCY', $before['action']);
        $this->assertNull($before['command']);
        $this->assertSame(0, app(\App\Services\LearningVelocityGateService::class)->inspect($generation->laboratory)['technical_recovery_agents']);
        File::put($this->directory.'/native.csv', "different synthetic native fixture bytes only\n");
        $this->nativeReadiness['ready'] = true;
        $this->nativeReadiness['full_source_unexpected_gaps'] = 0;
        $this->nativeReadiness['prospective_m5_repair']['prospective_m5_source_sha256'] = hash_file('sha256', $this->directory.'/native.csv');
        $after = $choose->invoke($arbiter, 'XAUUSD', 'H1', false);
        $this->assertSame('WAIT_UNUSED_DRAFT_PRICE_DISCOVERY_DEPENDENCY', $after['action']);
        $this->assertNull($after['command']);
        $this->assertNotSame($before['decision_key'], $after['decision_key']);
        $this->assertNotSame(data_get($before, 'evidence_snapshot.price_current_native_dependency.dependency_hash'),
            data_get($after, 'evidence_snapshot.price_current_native_dependency.dependency_hash'));
        $this->assertTrue(data_get($after, 'evidence_snapshot.price_current_native_dependency.ready'));
        $this->assertFalse(data_get($after, 'evidence_snapshot.price_current_native_dependency.old_owner_replay_authorized'));
        $this->assertSame(17, data_get($generation->fresh()->trigger_context, 'unused_draft_native_full_dependency.full_source_unexpected_gaps'));
        $ready = $choose->invoke($arbiter, 'XAUUSD', 'H1', false);
        $this->assertSame('CONSUME_DURABLE_NEXT_WORK', $ready['action']);
        $this->assertSame('trading:consume-research-work', $ready['command']);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
    }
}
