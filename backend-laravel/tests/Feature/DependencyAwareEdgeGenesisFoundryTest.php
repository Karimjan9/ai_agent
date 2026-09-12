<?php

namespace Tests\Feature;

use App\Jobs\EvaluateLabAgentJob;
use App\Jobs\EvaluateMtfPlaybookPriorJob;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabSkillZooEntry;
use App\Models\ModelVersion;
use App\Services\CanonicalResearchLanePriorityService;
use App\Services\DependencyAwareEdgeGenesisFoundryService;
use App\Services\DirectResearchReplayAdmissionService;
use App\Services\ExecutionContractService;
use App\Services\FullStackPlaybookMasteryService;
use App\Services\GenerationAdmissionDecisionService;
use App\Services\LabAgentPreflightService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class DependencyAwareEdgeGenesisFoundryTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_transplant_metric_is_scope_bound_and_does_not_claim_confirmed_transfer(): void
    {
        $h1 = LabSkillZooEntry::create([
            'skill_key' => 'dashboard-h1', 'cartridge_key' => hash('sha256', 'dashboard-h1'), 'revision' => 1,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'module_key' => 'entry',
            'niche_key' => 'all', 'gene_key' => 'entry_mode', 'status' => 'confirmed', 'evidence' => [],
        ]);
        $m15 = LabSkillZooEntry::create([
            'skill_key' => 'dashboard-m15', 'cartridge_key' => hash('sha256', 'dashboard-m15'), 'revision' => 1,
            'symbol' => 'XAUUSD', 'timeframe' => 'M15', 'strategy_family' => 'hybrid', 'module_key' => 'entry',
            'niche_key' => 'all', 'gene_key' => 'entry_mode', 'status' => 'confirmed', 'evidence' => [],
        ]);
        foreach ([
            [$h1->id, 'XAUUSD', 'H1', 'passed'],
            [$h1->id, 'XAUUSD', 'H1', 'failed'],
            [$m15->id, 'XAUUSD', 'M15', 'passed'],
        ] as $index => [$entryId, $symbol, $timeframe, $status]) {
            DB::table('skill_cartridge_transplant_trials')->insert([
                'trial_key' => hash('sha256', 'dashboard-transplant-'.$index),
                'lab_skill_zoo_entry_id' => $entryId, 'symbol' => $symbol, 'timeframe' => $timeframe,
                'mode' => 'exact_replication', 'status' => $status, 'context' => json_encode([]),
                'evidence' => json_encode(['promotion_evidence' => false]), 'settled_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $dashboard = app(DependencyAwareEdgeGenesisFoundryService::class)->dashboard('XAUUSD', 'H1');
        $metric = $dashboard['transplant_arm_pass_rate'];

        $this->assertArrayNotHasKey('transplant_success_rate', $dashboard);
        $this->assertSame(.5, $metric['value']);
        $this->assertSame(1, data_get($metric, 'numerator.value'));
        $this->assertSame(2, data_get($metric, 'denominator.value'));
        $this->assertSame('transplant_arm_trial', substr((string) data_get($metric, 'denominator.unique_subject_type'), -20));
        $this->assertSame('XAUUSD', data_get($metric, 'scope.symbol'));
        $this->assertSame('H1', data_get($metric, 'scope.laboratory_timeframe'));
        $this->assertStringContainsString('not confirmed transfer', $metric['interpretation']);
    }

    public function test_edge_model_identity_is_deterministic_and_fits_the_production_name_column(): void
    {
        $service = app(DependencyAwareEdgeGenesisFoundryService::class);
        $method = new \ReflectionMethod($service, 'boundedModelName');
        $method->setAccessible(true);
        $hash = str_repeat('a', 64);
        $name = $method->invoke($service, 163,
            'Break and Retest Compiled m5_retest_expiry_minutes Compiled m5_minimum_displacement_atr',
            'compiled_negative_control', $hash);

        $this->assertLessThanOrEqual(96, mb_strlen($name));
        $this->assertStringContainsString('aaaaaaaa', $name);
        $this->assertStringEndsWith('compiled_negative_control', $name);
        $this->assertSame($name, $method->invoke($service, 163,
            'Break and Retest Compiled m5_retest_expiry_minutes Compiled m5_minimum_displacement_atr',
            'compiled_negative_control', $hash));
    }

    public function test_axis_behavior_credit_requires_a_real_control_relative_trace_change(): void
    {
        $service = app(DependencyAwareEdgeGenesisFoundryService::class);
        $method = new \ReflectionMethod($service, 'axisBehaviorChanged');
        $method->setAccessible(true);
        $control = ['event_hash' => str_repeat('a', 64), 'signal_hash' => str_repeat('b', 64),
            'funnel_hash' => str_repeat('c', 64), 'observability_hash' => str_repeat('f', 64)];

        $this->assertFalse($method->invoke($service, $control, $control));
        $this->assertTrue($method->invoke($service, $control,
            [...$control, 'funnel_hash' => str_repeat('d', 64)]));
        $this->assertTrue($method->invoke($service, $control,
            [...$control, 'event_hash' => str_repeat('e', 64)]));
        $this->assertTrue($method->invoke($service, $control,
            [...$control, 'observability_hash' => str_repeat('0', 64)]));
    }

    public function test_compiled_child_inherits_the_full_source_composition_except_its_declared_axis(): void
    {
        $service = app(DependencyAwareEdgeGenesisFoundryService::class);
        $method = new \ReflectionMethod($service, 'runtimeForArm');
        $method->setAccessible(true);
        $source = app(StrategyParameterSchemaService::class)->defaults('confirmation_entry_mtf');
        $source['entry_model'] = 'breakout_retest';
        $source['swing_lookback'] = 60;
        $packet = [
            'key' => 'break_retest_compiled_1234567890',
            'compiled_axis' => 'rejection_wick_ratio',
            'compiled_arm_values' => ['compiled_primary' => .4],
        ];

        $runtime = $method->invoke($service, $packet, 'compiled_primary',
            DependencyAwareEdgeGenesisFoundryService::EVIDENCE_COMPILED_REVISION, $source);
        $this->assertSame('breakout_retest', $runtime['parameters']['entry_model']);
        $this->assertSame(60, $runtime['parameters']['swing_lookback']);
        $this->assertSame(.4, $runtime['parameters']['rejection_wick_ratio']);
    }

    public function test_parameter_identity_treats_integer_and_float_representations_as_the_same_numeric_value(): void
    {
        $service = app(DependencyAwareEdgeGenesisFoundryService::class);
        $diff = new \ReflectionMethod($service, 'diff');
        $diff->setAccessible(true);
        $hash = new \ReflectionMethod($service, 'parameterHash');
        $hash->setAccessible(true);

        $integer = ['nested' => ['minimum_reward_space_r' => 2, 'swing_lookback' => 60]];
        $float = ['nested' => ['swing_lookback' => 60.0, 'minimum_reward_space_r' => 2.0]];

        $this->assertSame([], $diff->invoke($service, $integer, $float));
        $this->assertSame($hash->invoke($service, $integer), $hash->invoke($service, $float));
        $this->assertSame(['minimum_reward_space_r' => ['old' => 2, 'new' => 2.1]],
            $diff->invoke($service, ['minimum_reward_space_r' => 2], ['minimum_reward_space_r' => 2.1]));
    }

    public function test_compiled_control_numeric_representation_quarantine_has_a_bounded_audited_recovery(): void
    {
        Queue::fake();
        $lab = AiLaboratory::create(['name' => 'Compiled control recovery', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 191,
            'trigger_type' => 'edge_genesis', 'trigger_context' => [], 'population_size' => 1, 'status' => 'completed']);
        $baseline = ModelVersion::create(['name' => 'Compiled source', 'strategy' => 'compiled-source', 'version' => 'v1',
            'generation' => 190, 'status' => 'testing', 'parameters' => ['minimum_reward_space_r' => 2], 'metadata' => []]);
        $genesisKey = hash('sha256', 'compiled-control-numeric-recovery');
        $dataHash = hash('sha256', 'compiled-control-data');
        $executionHash = hash('sha256', 'compiled-control-execution');
        $model = ModelVersion::create(['name' => 'Compiled control', 'strategy' => 'compiled-control', 'version' => 'v1',
            'generation' => 191, 'status' => 'testing', 'parameters' => ['minimum_reward_space_r' => 2.0],
            'evidence_status' => 'stale_quarantine', 'invalidation_reason' => 'strict_lab_agent_preflight_failed',
            'metadata' => ['preflight_quarantine' => ['errors' => ['ZERO_DIFF_INVARIANT_FAILED']],
                'edge_genesis' => ['protocol' => DependencyAwareEdgeGenesisFoundryService::PROTOCOL,
                    'architecture_revision' => DependencyAwareEdgeGenesisFoundryService::EVIDENCE_COMPILED_REVISION,
                    'genesis_key' => $genesisKey, 'arm' => 'compiled_control', 'data_hash' => $dataHash,
                    'execution_hash' => $executionHash, 'causal_baseline_model_version_id' => $baseline->id,
                    'intervention_attestation' => ['protocol' => 'edge_genesis_intervention_attestation_v1',
                        'control_identity' => true, 'source_parameter_hash' => 'legacy-int-hash',
                        'consumed_parameter_hash' => 'legacy-float-hash',
                        'actual_parameter_diff' => ['minimum_reward_space_r' => ['old' => 2, 'new' => 2.0]]]]]]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'confirmation_entry_mtf',
            'origin' => 'edge_genesis', 'lifecycle_status' => 'technical_quarantine',
            'parameter_diff' => ['minimum_reward_space_r' => ['old' => 2, 'new' => 2.0]],
            'decision_reason' => 'Technical quarantine: strict lab preflight failed (ZERO_DIFF_INVARIANT_FAILED).']);
        $passportId = DB::table('edge_genesis_passports')->insertGetId(['genesis_key' => $genesisKey,
            'lab_generation_id' => $generation->id, 'baseline_model_version_id' => $baseline->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'phase' => 'EDGE_DISCOVERY',
            'status' => 'queued', 'data_hash' => $dataHash, 'execution_hash' => $executionHash,
            'context' => '{}', 'evidence' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('edge_genesis_trials')->insert(['trial_key' => hash('sha256', 'compiled-control-trial'),
            'edge_genesis_passport_id' => $passportId, 'lab_agent_id' => $agent->id, 'model_version_id' => $model->id,
            'packet_key' => 'compiled-fixture', 'emitter' => 'evidence_compiler', 'arm' => 'compiled_control',
            'stage' => 'two_fold_discovery', 'status' => 'queued', 'evidence' => '{}',
            'created_at' => now(), 'updated_at' => now()]);

        $foundry = app(DependencyAwareEdgeGenesisFoundryService::class);
        $this->assertSame(1, $foundry->resumePendingTrials('XAUUSD', 'H1', false)['seats']);
        $this->assertSame('queued', $foundry->resumePendingTrials('XAUUSD', 'H1', true)['status']);
        $agent = $agent->fresh(['modelVersion']);
        $this->assertSame([], $agent->parameter_diff);
        $this->assertSame('valid', $agent->modelVersion->evidence_status);
        $this->assertSame([], data_get($agent->modelVersion->metadata, 'edge_genesis.intervention_attestation.actual_parameter_diff'));
        $this->assertSame(data_get($agent->modelVersion->metadata, 'edge_genesis.intervention_attestation.source_parameter_hash'),
            data_get($agent->modelVersion->metadata, 'edge_genesis.intervention_attestation.consumed_parameter_hash'));
        $this->assertSame('edge_genesis_numeric_control_identity_recovery_v1',
            data_get($agent->modelVersion->metadata, 'admission_metadata_recovery_history.0.protocol'));
        Queue::assertPushed(EvaluateLabAgentJob::class, 1);
    }

    public function test_compiled_direct_source_parameter_map_is_reduced_to_one_canonical_audit_hash(): void
    {
        $service = app(DependencyAwareEdgeGenesisFoundryService::class);
        $method = new \ReflectionMethod($service, 'withoutLargeRepairPayload');
        $method->setAccessible(true);

        $result = $method->invoke($service, [
            'protocol' => 'compiled_hypothesis',
            'source_parameters' => [
                'entry_mode' => 'balanced',
                'minimum_independent_confirmations' => 2,
                'temporal_roles' => ['bias' => 'H1', 'trigger' => 'M5'],
            ],
        ]);

        $this->assertArrayNotHasKey('source_parameters', $result);
        $this->assertSame(['compiled_source'], array_keys($result['source_parameter_hashes']));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['source_parameter_hashes']['compiled_source']);
        $this->assertSame('compiled_hypothesis', $result['protocol']);
    }

    public function test_risk_mutation_is_locked_until_a_confirmed_and_attributed_edge_exists(): void
    {
        $foundry = app(DependencyAwareEdgeGenesisFoundryService::class);
        $blocked = $foundry->mutationAdmission(null, 'loss_cooldown_candles');

        $this->assertFalse($blocked['allowed']);
        $this->assertSame('RISK_MUTATION_BEFORE_EDGE_CONFIRMATION', $blocked['reason']);
        $this->assertTrue($blocked['architecture_genesis_required']);
    }

    public function test_genesis_creates_four_packets_times_five_frozen_risk_arms_and_advances_only_with_edge_contract(): void
    {
        Queue::fake();
        $lab = AiLaboratory::create(['name' => 'Edge Genesis XAUUSD H1', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $coverage = $this->canonicalCoverageFixture();
        $data = $coverage['data_hash'];
        $execution = (string) app(ExecutionContractService::class)->for('XAUUSD', DependencyAwareEdgeGenesisFoundryService::EXECUTION_TIMEFRAME)['execution_hash'];
        $mtfHash = str_repeat('c', 64);
        $mtfBundle = ['bundle_hash' => $mtfHash, 'manifest' => [
            'bundle_hash' => $mtfHash, 'validation_bundle_protocol' => 'agent_owned_mtf_foundation_bundle_v1',
            'data_role' => 'pre_2026_foundation_training_only', 'promotion_evidence' => false,
        ]];
        $foundry = app(DependencyAwareEdgeGenesisFoundryService::class);

        $this->assertSame('blocked', $foundry->materialize($lab, $data, $execution, false)['status']);
        $unsealed = $foundry->materialize($lab, $data, $execution, true, $mtfBundle);
        $this->assertSame('blocked', $unsealed['status']);
        $this->assertSame('CANONICAL_GENERATION_COVERAGE_MUST_BE_SEALED_BEFORE_DISPATCH', $unsealed['reason']);
        $created = $foundry->materialize($lab, $data, $execution, true, $mtfBundle,
            DependencyAwareEdgeGenesisFoundryService::INITIAL_REVISION, $coverage['snapshots']);

        $this->assertSame('queued', $created['status']);
        $this->assertSame(20, $created['seats']);
        $this->assertDatabaseCount('edge_genesis_passports', 4);
        $this->assertDatabaseCount('edge_genesis_trials', 20);
        $this->assertDatabaseCount('lab_agents', 20);
        $this->assertSame(20, LabAgent::query()->with('modelVersion')->get()->pluck('modelVersion.strategy')->unique()->count());
        $this->assertSame(16, LabAgent::query()->with('modelVersion')->get()->filter(
            fn (LabAgent $seat): bool => data_get($seat->modelVersion->metadata, 'base_strategy') === 'confirmation_entry_mtf_v1'
        )->count());
        $this->assertSame(4, LabAgent::query()->with('modelVersion')->get()->filter(
            fn (LabAgent $seat): bool => data_get($seat->modelVersion->metadata, 'base_strategy') === 'mtf_research_control_v1'
        )->count());
        $this->assertTrue(LabAgent::query()->with('modelVersion')->get()->filter(
            fn (LabAgent $seat): bool => data_get($seat->modelVersion->metadata, 'edge_genesis.arm') === 'confirmation_floor_one'
                && (int) data_get($seat->modelVersion->parameters, 'minimum_independent_confirmations') === 1
                && data_get($seat->modelVersion->parameters, 'entry_mode') === 'balanced'
        )->count() === 4);
        Queue::assertPushed(EvaluateLabAgentJob::class, 20);
        $this->artisan('trading:dispatch-mtf-playbook-prior', [
            'symbol' => 'XAUUSD',
            '--confirmation-first' => true,
        ])->expectsOutput('One unique MTF prior job queued behind full validation on lab-frontier.')
            ->assertSuccessful();
        Queue::assertPushed(EvaluateMtfPlaybookPriorJob::class, 1);
        $agent = LabAgent::query()->with('modelVersion')->firstOrFail();
        $this->assertTrue((bool) data_get($agent->modelVersion->metadata, 'edge_genesis.risk_governor_frozen'));
        $this->assertSame('EDGE_DISCOVERY', data_get($agent->modelVersion->metadata, 'edge_genesis.phase'));
        $this->assertSame('M5', data_get($agent->modelVersion->metadata, 'execution_contract.timeframe'));
        $this->assertTrue($foundry->preflight($agent)['allowed']);
        $directAdmission = app(DirectResearchReplayAdmissionService::class)->inspect($agent);
        $this->assertTrue($directAdmission['applicable']);
        $this->assertTrue($directAdmission['allowed'], json_encode($directAdmission));
        $strictPreflight = app(LabAgentPreflightService::class)->inspect($agent, 'full_validation');
        $this->assertNotContains('SEMANTIC_GROUP_NOT_DECLARED', $strictPreflight['errors']);
        $this->assertNotContains('FULL_REPLAY_EXECUTION_HASH_MISSING_OR_INVALID', $strictPreflight['errors']);

        // A historical constructor omission may be recovered only for the
        // exact two known metadata errors; parameters and passport hashes stay
        // immutable and the replay still has to pass the complete preflight.
        $metadata = (array) $agent->modelVersion->metadata;
        unset($metadata['semantic_group'], $metadata['execution_contract']);
        $metadata['preflight_quarantine'] = [
            'errors' => ['SEMANTIC_GROUP_NOT_DECLARED', 'FULL_REPLAY_EXECUTION_HASH_MISSING_OR_INVALID'],
            'promotion_evidence' => false,
        ];
        $agent->modelVersion->update(['metadata' => $metadata, 'evidence_status' => 'stale_quarantine']);
        $agent->update(['lifecycle_status' => 'technical_quarantine']);
        $this->assertSame(1, $foundry->resumePendingTrials('XAUUSD', 'H1', false)['seats']);
        $this->assertSame('queued', $foundry->resumePendingTrials('XAUUSD', 'H1', true)['status']);
        $agent = $agent->fresh(['modelVersion']);
        $this->assertSame('full_queued', $agent->lifecycle_status);
        $this->assertSame('valid', $agent->modelVersion->evidence_status);
        $recoveredPreflight = app(LabAgentPreflightService::class)->inspect($agent, 'full_validation');
        $this->assertNotContains('SEMANTIC_GROUP_NOT_DECLARED', $recoveredPreflight['errors']);
        $this->assertNotContains('FULL_REPLAY_EXECUTION_HASH_MISSING_OR_INVALID', $recoveredPreflight['errors']);

        $missingLedger = $foundry->settleOutcome($agent, ['data_hash' => $data, 'execution_hash' => $execution]);
        $this->assertSame('invalid_edge_observability', $missingLedger['status']);
        $windowPlan = (array) data_get($agent->modelVersion->metadata, 'edge_genesis.frozen_window_plan');
        $discoveryWindows = collect(range(1, 2))->map(fn (int $fold): array => [
            'start' => sprintf('2021-%02d-01', $fold),
            'end' => sprintf('2021-%02d-28', $fold),
            'net_profit_percent' => $fold === 1 ? .25 : -.10,
        ])->all();
        $discoveryResult = [
            'data_hash' => $data, 'execution_hash' => $execution, 'mtf_snapshot_manifest' => ['bundle_hash' => $mtfHash],
            'after_cost_expectancy_r' => .12,
            'net_profit_percent' => 1.5, 'total_trades' => 4,
            'forward_window_protocol' => [
                'observed_windows' => 2, 'powered_windows' => 2, 'positive_windows' => 1,
                'independence_verified' => true, 'overlap_detected' => false,
                'windows' => $discoveryWindows,
            ],
            'edge_genesis_replay' => [
                'window_plan_hash' => $windowPlan['window_plan_hash'],
                'fold_count' => 2, 'fold_offset' => 0, 'fold_universe_count' => 14,
            ],
            'edge_observability' => [
                'opportunity_detected' => ['observed' => true, 'count' => 20],
                'setup_location_valid' => ['observed' => true, 'location_count' => 8, 'setup_count' => 6],
                'context_bias_aligned' => ['observed' => true, 'count' => 20],
                'confirmation' => ['observed' => true, 'count' => 5],
                'entry' => ['observed' => true, 'count' => 4],
                'execution_price' => ['observed' => true, 'closed_trade_count' => 4],
                'invalidation_price' => ['observed' => true, 'count' => 4],
                'mfe_mae' => ['observed' => true, 'observed_trade_count' => 4],
                'exit_outcome' => ['observed' => true, 'closed_trade_count' => 4],
            ],
            'behavior_delta_observed' => true, 'context_declared_before_replay' => true, 'context_occurrences' => 20,
            'edge_context_enforcement' => ['protocol' => 'edge_context_authority_firewall_v1',
                'enforced' => true, 'outside_scope_action' => 'WAIT'],
            'risk_governor_compliant' => true, 'forbidden_risk_bypass' => false,
        ];
        $discovered = $foundry->settleOutcome($agent->fresh('modelVersion'), $discoveryResult);
        $this->assertSame('EDGE_CONFIRMATION', $discovered['phase']);
        $this->assertTrue($discovered['discovery_admission']['passed']);
        $this->assertDatabaseHas('edge_genesis_trials', [
            'lab_agent_id' => $agent->id, 'stage' => 'two_fold_discovery', 'status' => 'edge_progressing',
        ]);

        $agent->modelVersion->marketPerformances()->create([
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => $agent->strategy_family,
            'status' => 'accepted', 'fitness' => .12, 'forward_score' => .12, 'sample_count' => 4,
            'rolling_windows_count' => 2, 'metrics' => $discoveryResult, 'evidence_status' => 'valid',
        ]);
        $control = LabAgent::query()->with('modelVersion')->whereHas('modelVersion', fn ($query) => $query
            ->where('metadata->edge_genesis->arm', 'frozen_control'))->firstOrFail();
        $controlDiscoveryResult = [...$discoveryResult,
            'after_cost_expectancy_r' => .02, 'net_profit_percent' => .25, 'total_trades' => 4];
        $this->assertSame('control_settled', $foundry->settleOutcome($control, $controlDiscoveryResult)['status']);
        $control->modelVersion->marketPerformances()->create([
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => $control->strategy_family,
            'status' => 'accepted', 'fitness' => .02, 'forward_score' => .02, 'sample_count' => 4,
            'rolling_windows_count' => 2, 'metrics' => $controlDiscoveryResult, 'evidence_status' => 'valid',
        ]);

        // Research-only agents are deliberately rejected from the ordinary
        // champion frontier; that lifecycle must not strand their canonical
        // Edge confirmation transition.
        LabAgent::query()->whereIn('id', [$agent->id, $control->id])->update(['lifecycle_status' => 'rejected']);
        $replication = $foundry->materializeIndependentReplication('XAUUSD', 'H1', true);
        $this->assertSame('queued', $replication['status']);
        $this->assertSame(2, $replication['seats']);
        $this->assertSame(3, $replication['folds']);
        $replicationWindows = collect(range(1, 3))->map(fn (int $fold): array => [
            'start' => sprintf('2022-%02d-01', $fold),
            'end' => sprintf('2022-%02d-28', $fold),
            'net_profit_percent' => $fold <= 2 ? .20 : -.05,
        ])->all();
        $replicationResult = [...$discoveryResult,
            'forward_window_protocol' => [
                'observed_windows' => 3, 'powered_windows' => 3, 'positive_windows' => 2,
                'independence_verified' => true, 'overlap_detected' => false,
                'windows' => $replicationWindows,
            ],
            'edge_genesis_replay' => [
                'window_plan_hash' => $windowPlan['window_plan_hash'],
                'fold_count' => 3, 'fold_offset' => 2, 'fold_universe_count' => 14,
            ],
        ];
        $controlReplicationResult = [...$replicationResult, 'after_cost_expectancy_r' => .02, 'net_profit_percent' => .2];
        $controlReplicationResult['forward_window_protocol']['windows'] = collect(range(1, 3))->map(fn (int $fold): array => [
            'start' => sprintf('2022-%02d-01', $fold), 'end' => sprintf('2022-%02d-28', $fold),
            'net_profit_percent' => -.10,
        ])->all();
        $control->modelVersion->marketPerformances()->update([
            'sample_count' => 6, 'rolling_windows_count' => 3,
            'metrics' => $controlReplicationResult, 'evidence_status' => 'valid',
        ]);
        $agent->modelVersion->marketPerformances()->update([
            'sample_count' => 6, 'rolling_windows_count' => 3,
            'metrics' => $replicationResult, 'evidence_status' => 'valid',
        ]);
        $this->assertSame('replication_control_settled', $foundry->settleOutcome(
            $control->fresh('modelVersion'), $controlReplicationResult
        )['status']);
        $replicated = $foundry->settleOutcome($agent->fresh('modelVersion'), $replicationResult);
        $this->assertSame('edge_replication_passed', $replicated['status']);
        LabAgent::query()->whereIn('id', [$agent->id, $control->id])->update(['lifecycle_status' => 'rejected']);
        $confirmation = $foundry->materializeConfirmation('XAUUSD', 'H1', true);
        $this->assertSame('queued', $confirmation['status'], json_encode($confirmation));
        $this->assertSame(2, $confirmation['seats']);
        $this->assertSame(9, $confirmation['folds']);
        $this->assertDatabaseHas('edge_genesis_trials', [
            'lab_agent_id' => $agent->id, 'stage' => 'nine_fold_authority', 'status' => 'queued',
        ]);
        $this->assertSame('EDGE_CONFIRMATION', data_get($agent->fresh('modelVersion')->modelVersion->metadata, 'edge_genesis.phase'));
        $this->assertSame(9, data_get($agent->fresh('modelVersion')->modelVersion->metadata, 'edge_genesis.authority_contract.requested_folds'));
        $this->assertSame('queued', $agent->generation()->value('status'));
        $this->assertNull($agent->generation()->value('completed_at'));

        $authorityResult = [
            ...$discoveryResult,
            'total_trades' => 25,
            'pf_lower_confidence_bound' => 1.08,
            'forward_window_protocol' => [
                'observed_windows' => 9,
                'powered_windows' => 9,
                'positive_windows' => 3,
                'independence_verified' => true,
                'overlap_detected' => false,
                'windows' => collect(range(1, 9))->map(fn (int $fold): array => [
                    'start' => sprintf('2023-%02d-01', $fold),
                    'end' => sprintf('2023-%02d-28', $fold),
                    'net_profit_percent' => $fold <= 5 ? .25 : -.10,
                ])->all(),
            ],
            'edge_genesis_replay' => [
                'window_plan_hash' => $windowPlan['window_plan_hash'],
                'fold_count' => 9, 'fold_offset' => 5, 'fold_universe_count' => 14,
            ],
            'context_occurrences' => 80,
        ];
        $authorityResult['edge_observability']['entry']['count'] = 25;
        $authorityResult['edge_observability']['exit_outcome']['closed_trade_count'] = 25;
        $authorityResult['edge_context_enforcement'] = [
            ...$authorityResult['edge_context_enforcement'],
            'observed_signals' => 40, 'matched_signals' => 25, 'rejected_signals' => 15,
            'fold_telemetry_complete' => true, 'fold_contract_identity_consistent' => true,
            'trade_admission_consistent' => true,
        ];
        $agent->modelVersion->marketPerformances()->update([
            'status' => 'accepted', 'fitness' => .12, 'forward_score' => .12, 'sample_count' => 25,
            'rolling_windows_count' => 9, 'metrics' => $authorityResult, 'evidence_status' => 'valid',
        ]);
        $controlResult = [...$authorityResult, 'total_trades' => 12,
            'net_profit_percent' => .34, 'after_cost_expectancy_r' => .04];
        $controlResult['forward_window_protocol']['windows'] = collect(range(1, 9))->map(fn (int $fold): array => [
            'start' => sprintf('2023-%02d-01', $fold),
            'end' => sprintf('2023-%02d-28', $fold),
            'net_profit_percent' => -.15,
        ])->all();
        foreach (['confirmation', 'entry'] as $field) {
            $controlResult['edge_observability'][$field]['count'] = 0;
        }
        $controlResult['edge_observability']['exit_outcome']['closed_trade_count'] = 12;
        $control->modelVersion->marketPerformances()->update([
            'status' => 'accepted', 'fitness' => .04, 'forward_score' => .04, 'sample_count' => 12,
            'rolling_windows_count' => 9, 'metrics' => $controlResult, 'evidence_status' => 'valid',
        ]);
        $this->assertSame('control_settled', $foundry->settleOutcome($control, $controlResult)['status']);
        $settled = $foundry->settleOutcome($agent->fresh('modelVersion'), $authorityResult);
        $this->assertSame('EDGE_ATTRIBUTION', $settled['phase']);
        $this->assertTrue($settled['edge_admission']['financial']);
        $this->assertTrue($settled['edge_admission']['temporal']);

        $runtimeFailed = LabAgent::query()->with('modelVersion')->whereNotIn('id', [$agent->id, $control->id])->firstOrFail();
        $runtimeFailed->update(['lifecycle_status' => 'evaluation_error']);
        DB::table('lab_evaluation_runs')->insert([
            'run_id' => (string) Str::uuid(), 'lab_generation_id' => $runtimeFailed->lab_generation_id,
            'lab_agent_id' => $runtimeFailed->id, 'model_version_id' => $runtimeFailed->model_version_id,
            'phase' => 'full_validation', 'mode' => 'full', 'status' => 'technical_error',
            'error_message' => 'Rolling walk-forward uchun kamida 3 ta oyna va 2 yillik final holdout kerak.',
            'started_at' => now(), 'finished_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(1, $foundry->resumePendingTrials('XAUUSD', 'H1', false)['seats']);
        $this->assertSame('queued', $foundry->resumePendingTrials('XAUUSD', 'H1', true)['status']);
        $this->assertSame('full_queued', $runtimeFailed->fresh()->lifecycle_status);

        $timeoutFailed = LabAgent::query()->with('modelVersion')->whereNotIn('id', [$agent->id, $control->id, $runtimeFailed->id])->firstOrFail();
        $timeoutFailed->update(['lifecycle_status' => 'evaluation_error']);
        DB::table('lab_evaluation_runs')->insert([
            'run_id' => (string) Str::uuid(), 'lab_generation_id' => $timeoutFailed->lab_generation_id,
            'lab_agent_id' => $timeoutFailed->id, 'model_version_id' => $timeoutFailed->model_version_id,
            'phase' => 'full_validation', 'mode' => 'full', 'status' => 'technical_error',
            'error_message' => '{"detail":"TimeoutError: Causal confirmation fold 2 exceeded its 180s budget; remaining folds were not executed and no learning credit was emitted."}',
            'started_at' => now(), 'finished_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(1, $foundry->resumePendingTrials('XAUUSD', 'H1', false)['seats']);
        $this->assertSame('queued', $foundry->resumePendingTrials('XAUUSD', 'H1', true)['status']);
        $this->assertSame('full_queued', $timeoutFailed->fresh()->lifecycle_status);

        $attribution = $foundry->materializeAttribution($agent->fresh(['modelVersion', 'generation.laboratory']));
        $this->assertSame('queued', $attribution['status']);
        $this->assertSame(40, LabAgent::count());
        $this->assertSame(20, LabAgent::query()->where('lab_generation_id', $attribution['generation_id'])->count());
        $this->assertDatabaseCount('edge_genesis_trials', 25);
        $this->assertDatabaseCount('full_stack_playbook_passports', 25);
        $attributionAgent = LabAgent::query()->with('modelVersion')->where('origin', 'edge_component_attribution')->firstOrFail();
        $this->assertTrue(app(FullStackPlaybookMasteryService::class)->preflight($attributionAgent)['allowed']);
        $this->assertTrue(app(DirectResearchReplayAdmissionService::class)->inspect($attributionAgent)['allowed']);
        $noConfirmation = LabAgent::query()->with('modelVersion')->where('origin', 'edge_component_attribution')->get()
            ->first(fn (LabAgent $seat): bool => data_get($seat->modelVersion->metadata, 'edge_genesis_attribution.arm') === 'no_confirmation');
        $this->assertNotNull($noConfirmation);
        $this->assertTrue((bool) data_get($noConfirmation->modelVersion->parameters, 'attribution_confirmation_bypass'));
        $this->assertTrue($foundry->preflight($noConfirmation)['attribution_ablation_valid']);

        $attributionAgents = LabAgent::query()->with('modelVersion')
            ->where('origin', 'edge_component_attribution')->get();
        foreach ($attributionAgents as $seat) {
            $arm = (string) data_get($seat->modelVersion->metadata, 'edge_genesis_attribution.arm');
            $full = $arm === 'full_composition';
            $armMetrics = [...$authorityResult,
                'after_cost_expectancy_r' => $full ? .12 : .02,
                'net_profit_percent' => $full ? 1.5 : .2,
                'total_trades' => $full ? 25 : 20,
                'trade_ledger_hash' => hash('sha256', 'ledger-'.$arm),
                'signal_decision_hash' => hash('sha256', 'signals-'.$arm),
                'forward_window_protocol' => [
                    'powered_windows' => 9, 'positive_windows' => $full ? 5 : 2,
                    'windows' => collect(range(1, 9))->map(fn (int $fold): array => [
                        'start' => sprintf('2023-%02d-01', $fold),
                        'end' => sprintf('2023-%02d-28', $fold),
                        'net_profit_percent' => $full ? .25 : .10,
                    ])->all(),
                ],
            ];
            $armMetrics['edge_observability']['entry']['count'] = $full ? 25 : 20;
            $armMetrics['edge_observability']['exit_outcome']['closed_trade_count'] = $full ? 25 : 20;
            $seat->modelVersion->marketPerformances()->create([
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => $seat->strategy_family,
                'status' => 'rejected', 'fitness' => 0, 'forward_score' => 0,
                'sample_count' => $full ? 25 : 20, 'rolling_windows_count' => 9,
                'rolling_forward_wins' => $full ? 5 : 2,
                'metrics' => $armMetrics, 'evidence_status' => 'valid',
            ]);
        }
        $attributionSettlement = $foundry->settleOutcome($attributionAgent->fresh('modelVersion'), []);
        $this->assertSame('attribution_settled', $attributionSettlement['status'], json_encode($attributionSettlement));
        $this->assertSame('RISK_SHAPING', $attributionSettlement['phase']);
        $this->assertDatabaseCount('edge_genesis_component_attributions', 4);
        $this->assertTrue(DB::table('edge_genesis_component_attributions')->get()->every(function ($row): bool {
            $evidence = json_decode((string) $row->evidence, true);

            return $row->status === 'supported'
                && data_get($evidence, 'paired_window_effect.positive_windows') === 9
                && data_get($evidence, 'parent_authority') === false;
        }));
        $this->assertSame('already_settled', $foundry->settleOutcome($attributionAgent->fresh('modelVersion'), [])['status']);
    }

    public function test_partial_discovery_cohort_is_rebound_to_one_frozen_mtf_bundle_without_reusing_old_authority(): void
    {
        Queue::fake();
        $lab = AiLaboratory::create(['name' => 'Edge MTF identity', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $coverage = $this->canonicalCoverageFixture();
        $data = $coverage['data_hash'];
        $execution = (string) app(ExecutionContractService::class)->for('XAUUSD', 'M5')['execution_hash'];
        $oldHash = str_repeat('b', 64);
        $oldBundle = ['bundle_hash' => $oldHash, 'manifest' => [
            'bundle_hash' => $oldHash, 'validation_bundle_protocol' => 'agent_owned_mtf_foundation_bundle_v1',
            'data_role' => 'pre_2026_foundation_training_only', 'promotion_evidence' => false,
        ]];
        $foundry = app(DependencyAwareEdgeGenesisFoundryService::class);
        $created = $foundry->materialize($lab, $data, $execution, true, $oldBundle,
            DependencyAwareEdgeGenesisFoundryService::INITIAL_REVISION, $coverage['snapshots']);
        $agent = LabAgent::query()->with('modelVersion')->firstOrFail();
        $agent->update(['lifecycle_status' => 'rejected']);
        $performance = $agent->modelVersion->marketPerformances()->create([
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => $agent->strategy_family,
            'status' => 'rejected', 'fitness' => 0, 'forward_score' => 0, 'sample_count' => 3,
            'metrics' => ['old_per_agent_bundle' => true], 'evidence_status' => 'valid',
        ]);

        $newHash = str_repeat('c', 64);
        $newBundle = ['bundle_hash' => $newHash, 'manifest' => [
            'bundle_hash' => $newHash, 'validation_bundle_protocol' => 'agent_owned_mtf_foundation_bundle_v1',
            'data_role' => 'pre_2026_foundation_training_only', 'promotion_evidence' => false,
        ]];
        $confirmationPassportId = DB::table('edge_genesis_passports')->where('lab_generation_id', $created['generation_id'])->value('id');
        DB::table('edge_genesis_passports')->where('id', $confirmationPassportId)->update(['phase' => 'EDGE_CONFIRMATION']);
        $this->assertSame('would_rebind', $foundry->rebindDiscoveryCohortToFrozenMtfBundle($created['generation_id'], $newBundle, false)['status']);
        $rebound = $foundry->rebindDiscoveryCohortToFrozenMtfBundle($created['generation_id'], $newBundle, true);

        $this->assertSame('rebound', $rebound['status']);
        $this->assertSame(20, $rebound['models']);
        $this->assertSame(1, $rebound['jobs_dispatched']);
        $this->assertSame($newHash, data_get($agent->fresh('modelVersion')->modelVersion->metadata, 'edge_genesis.mtf_bundle_hash'));
        $this->assertSame('stale_quarantine', $performance->fresh()->evidence_status);
        $this->assertDatabaseMissing('edge_genesis_trials', ['status' => 'edge_not_found']);
        $this->assertSame(20, DB::table('edge_genesis_trials')->where('stage', 'two_fold_discovery')->where('status', 'queued')->count());
    }

    public function test_a_legacy_trade_less_cohort_opens_one_causal_confirmation_breadth_repair_wave(): void
    {
        Queue::fake();
        $lab = AiLaboratory::create(['name' => 'Edge confirmation repair', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $fixtureDirectory = storage_path('framework/testing/edge-genesis-'.Str::uuid());
        File::ensureDirectoryExists($fixtureDirectory);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($fixtureDirectory));
        $foundationPath = $fixtureDirectory.'/foundation.csv';
        $pricePath = $fixtureDirectory.'/paper.csv';
        File::put($foundationPath, 'foundation-fixture');
        File::put($pricePath, 'paper-fixture');
        $data = hash_file('sha256', $foundationPath);
        $canonicalSnapshots = [
            'foundation' => ['path' => $foundationPath, 'sha256' => $data, 'manifest' => [
                'source_role' => 'foundation_training_only', 'promotion_evidence' => false,
                'continuity' => ['status' => 'ready', 'unexpected_gap_count' => 0],
            ]],
            'price' => ['path' => $pricePath, 'sha256' => hash_file('sha256', $pricePath), 'manifest' => [
                'data_role' => 'paper_only', 'training_end_exclusive' => '2026-01-01T00:00:00+00:00',
                'promotion_evidence' => false,
            ]],
        ];
        $execution = (string) app(ExecutionContractService::class)->for('XAUUSD', 'M5')['execution_hash'];
        $bundleHash = str_repeat('c', 64);
        $bundle = ['bundle_hash' => $bundleHash, 'manifest' => [
            'bundle_hash' => $bundleHash, 'validation_bundle_protocol' => 'agent_owned_mtf_foundation_bundle_v1',
            'data_role' => 'pre_2026_foundation_training_only', 'promotion_evidence' => false,
        ]];
        $foundry = app(DependencyAwareEdgeGenesisFoundryService::class);
        $source = $foundry->materialize($lab, $data, $execution, true, $bundle,
            DependencyAwareEdgeGenesisFoundryService::INITIAL_REVISION, $canonicalSnapshots);
        $sourceGeneration = LabGeneration::query()->findOrFail($source['generation_id']);
        $sourceGeneration->update(['trigger_context' => [...(array) $sourceGeneration->trigger_context,
            'canonical_dataset_snapshots' => $canonicalSnapshots,
        ]]);

        DB::table('edge_genesis_passports')->where('lab_generation_id', $source['generation_id'])
            ->update(['status' => 'exhausted']);
        foreach (LabAgent::query()->with('modelVersion')->where('lab_generation_id', $source['generation_id'])->get() as $agent) {
            $metadata = (array) $agent->modelVersion->metadata;
            $arm = (string) data_get($metadata, 'edge_genesis.arm');
            // Reproduce the immutable legacy G137 topology, registered before
            // confirmation_floor_one existed.
            if ($arm === 'confirmation_floor_one') {
                $arm = 'confirmation_tactic_change';
                data_set($metadata, 'edge_genesis.arm', $arm);
                $agent->modelVersion->update(['metadata' => $metadata]);
                DB::table('edge_genesis_trials')->where('lab_agent_id', $agent->id)->update(['arm' => $arm]);
            }
            DB::table('edge_genesis_trials')->where('lab_agent_id', $agent->id)->update([
                'status' => $arm === 'frozen_control' ? 'control_settled' : 'edge_not_found',
                'settled_at' => now(),
            ]);
            $agent->update(['lifecycle_status' => 'rejected']);
            $setupCount = $arm === 'frozen_control' ? 0 : 8;
            $agent->modelVersion->marketPerformances()->create([
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => $agent->strategy_family,
                'status' => 'rejected', 'fitness' => 0, 'forward_score' => 0, 'sample_count' => 2,
                'metrics' => ['total_trades' => 0, 'edge_observability' => [
                    'setup_location_valid' => ['observed' => true, 'setup_count' => $setupCount],
                    'confirmation' => ['observed' => true, 'count' => 0],
                    'entry' => ['observed' => true, 'count' => 0],
                ]],
                'evidence_status' => 'valid',
            ]);
        }

        $readiness = $foundry->architectureRepairReadiness('XAUUSD', 'H1');
        $this->assertTrue($readiness['admitted'], json_encode($readiness));
        $this->assertSame('REPLICATED_CONFIRMATION_BREADTH_STARVATION', $readiness['reason']);
        $this->assertSame(4, count($readiness['diagnostic_packets']));
        config()->set('services.edge_director.autonomous_specialized_cohorts_enabled', false);
        $normalMode = app(CanonicalResearchLanePriorityService::class)->edgeGenesisOwnership('XAUUSD', 'H1');
        $this->assertFalse($normalMode['owned']);
        $this->assertSame('normal_twenty_generation_mode', $normalMode['reservation_reason']);
        config()->set('services.edge_director.autonomous_specialized_cohorts_enabled', true);
        $reservation = app(CanonicalResearchLanePriorityService::class)->edgeGenesisOwnership('XAUUSD', 'H1');
        $this->assertTrue($reservation['owned']);
        $this->assertSame('causally_admitted_edge_architecture_repair', $reservation['reservation_reason']);
        $parallelAdmission = app(GenerationAdmissionDecisionService::class)->decide($lab, $sourceGeneration, [
            'trigger' => 'learning_confirmation', 'learning_confirmation' => true,
        ], false);
        $this->assertFalse($parallelAdmission['allowed']);
        $this->assertSame(GenerationAdmissionDecisionService::WAIT_ACTIVE_WORK, $parallelAdmission['decision']);
        $this->assertContains('CANONICAL_EDGE_STATE_MACHINE_OWNS_EVOLUTION_LANE', $parallelAdmission['reason_codes']);
        $this->assertSame('would_queue', $foundry->materializeConfirmationBreadthRepair($lab, false)['status']);

        $repair = $foundry->materializeConfirmationBreadthRepair($lab, true);
        $this->assertSame('queued', $repair['status']);
        $this->assertSame(DependencyAwareEdgeGenesisFoundryService::CONFIRMATION_REPAIR_REVISION, $repair['architecture_revision']);
        $this->assertSame($repair['generation_id'], data_get(LabGeneration::query()->findOrFail($repair['generation_id'])->trigger_context,
            'canonical_dataset_snapshots.foundation.causal_reuse.target_generation_id'));
        $this->assertDatabaseCount('edge_genesis_passports', 8);
        $this->assertDatabaseCount('edge_genesis_trials', 40);
        $this->assertDatabaseCount('lab_agents', 40);
        $repairAgents = LabAgent::query()->with('modelVersion')->where('lab_generation_id', $repair['generation_id'])->get();
        $this->assertSame(4, $repairAgents->filter(fn (LabAgent $agent): bool => data_get($agent->modelVersion->metadata, 'edge_genesis.arm') === 'confirmation_floor_one')->count());
        $this->assertTrue($repairAgents->every(fn (LabAgent $agent): bool => data_get($agent->modelVersion->metadata, 'edge_genesis.architecture_revision')
                === DependencyAwareEdgeGenesisFoundryService::CONFIRMATION_REPAIR_REVISION));

        $packet = $repairAgents->filter(fn (LabAgent $agent): bool => data_get($agent->modelVersion->metadata, 'edge_genesis.packet_key') === 'trend_pullback');
        $reference = (array) $packet->first(fn (LabAgent $agent): bool => data_get($agent->modelVersion->metadata, 'edge_genesis.arm') === 'professional_reference')?->modelVersion->parameters;
        $floor = (array) $packet->first(fn (LabAgent $agent): bool => data_get($agent->modelVersion->metadata, 'edge_genesis.arm') === 'confirmation_floor_one')?->modelVersion->parameters;
        $changed = collect(array_unique([...array_keys($reference), ...array_keys($floor)]))
            ->filter(fn (string $key): bool => ($reference[$key] ?? null) !== ($floor[$key] ?? null))->values()->all();
        $this->assertSame(['minimum_independent_confirmations'], $changed);
        Queue::assertPushed(EvaluateLabAgentJob::class, 40);

        // An interrupted constructor that omitted generation-level coverage
        // passports is recoverable only before any replay/performance exists.
        $repairGeneration = LabGeneration::query()->findOrFail($repair['generation_id']);
        $repairContext = (array) $repairGeneration->trigger_context;
        unset($repairContext['canonical_dataset_snapshots']);
        $repairGeneration->update(['trigger_context' => $repairContext, 'status' => 'technical_quarantine']);
        foreach ($repairAgents as $repairAgent) {
            $metadata = (array) $repairAgent->modelVersion->metadata;
            $metadata['preflight_quarantine'] = ['errors' => ['FULL_REPLAY_DATASET_COVERAGE_INSUFFICIENT'],
                'promotion_evidence' => false];
            $repairAgent->modelVersion->update(['metadata' => $metadata]);
            $repairAgent->update(['lifecycle_status' => 'technical_quarantine']);
        }
        $this->assertSame('would_repair', $foundry->reconcileRepairGenerationCoverage('XAUUSD', 'H1', false)['status']);
        $this->assertSame('repaired', $foundry->reconcileRepairGenerationCoverage('XAUUSD', 'H1', true)['status']);
        $this->assertSame($data, data_get($repairGeneration->fresh()->trigger_context,
            'canonical_dataset_snapshots.foundation.sha256'));
        $this->assertSame(20, $foundry->resumePendingTrials('XAUUSD', 'H1', false)['seats']);
        $this->assertSame('queued', $foundry->resumePendingTrials('XAUUSD', 'H1', true)['status']);
        $this->assertTrue(LabAgent::query()->where('lab_generation_id', $repair['generation_id'])
            ->get()->every(fn (LabAgent $agent): bool => $agent->lifecycle_status === 'full_queued'));

        // The confirmation repair can reveal a later, different dependency:
        // confirmations exist, but the M5 trigger topology never activates.
        DB::table('edge_genesis_passports')->where('lab_generation_id', $repair['generation_id'])
            ->update(['status' => 'exhausted']);
        foreach ($repairAgents as $repairAgent) {
            $arm = (string) data_get($repairAgent->modelVersion->metadata, 'edge_genesis.arm');
            DB::table('edge_genesis_trials')->where('lab_agent_id', $repairAgent->id)->update([
                'status' => $arm === 'frozen_control' ? 'control_settled' : 'edge_not_found',
                'settled_at' => now(),
            ]);
            $repairAgent->update(['lifecycle_status' => 'rejected']);
            $floor = $arm === 'confirmation_floor_one';
            $repairAgent->modelVersion->marketPerformances()->create([
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => $repairAgent->strategy_family,
                'status' => 'rejected', 'fitness' => 0, 'forward_score' => 0, 'sample_count' => 2,
                'metrics' => ['total_trades' => 0, 'edge_observability' => [
                    'setup_location_valid' => ['observed' => true, 'setup_count' => $floor ? 8 : 0],
                    'confirmation' => ['observed' => true, 'count' => $floor ? 8 : 0],
                    'entry' => ['observed' => true, 'count' => 0],
                ], 'entry_contract_funnel' => ['stage_counts' => ['trigger' => 0]]],
                'evidence_status' => 'valid',
            ]);
        }
        $triggerReadiness = $foundry->architectureRepairReadiness('XAUUSD', 'H1');
        $this->assertTrue($triggerReadiness['admitted'], json_encode($triggerReadiness));
        $this->assertSame(DependencyAwareEdgeGenesisFoundryService::TRIGGER_REPAIR_REVISION, $triggerReadiness['repair_revision']);
        $this->assertSame('REPLICATED_ENTRY_TRIGGER_STARVATION_AFTER_CONFIRMATION', $triggerReadiness['reason']);
        $this->assertSame(4, count($triggerReadiness['diagnostic_packets']));
        $this->assertSame('would_queue', $foundry->materializeNextArchitectureRepair($lab, false)['status']);

        $triggerRepair = $foundry->materializeNextArchitectureRepair($lab, true);
        $this->assertSame('queued', $triggerRepair['status']);
        $this->assertSame(DependencyAwareEdgeGenesisFoundryService::TRIGGER_REPAIR_REVISION, $triggerRepair['architecture_revision']);
        $this->assertSame($triggerRepair['generation_id'], data_get(LabGeneration::query()->findOrFail($triggerRepair['generation_id'])->trigger_context,
            'canonical_dataset_snapshots.price.causal_reuse.target_generation_id'));
        $triggerAgents = LabAgent::query()->with('modelVersion')->where('lab_generation_id', $triggerRepair['generation_id'])->get();
        $this->assertCount(20, $triggerAgents);
        $this->assertEqualsCanonicalizing(DependencyAwareEdgeGenesisFoundryService::TRIGGER_REPAIR_ARMS,
            $triggerAgents->map(fn (LabAgent $agent): string => (string) data_get($agent->modelVersion->metadata, 'edge_genesis.arm'))->unique()->all());

        $triggerPacket = $triggerAgents->filter(fn (LabAgent $agent): bool => data_get($agent->modelVersion->metadata, 'edge_genesis.packet_key') === 'trend_pullback');
        $byArm = $triggerPacket->keyBy(fn (LabAgent $agent): string => (string) data_get($agent->modelVersion->metadata, 'edge_genesis.arm'));
        $baseline = (array) $byArm['confirmation_floor_control']->modelVersion->parameters;
        $this->assertSame(1, $baseline['minimum_independent_confirmations']);
        foreach ([
            'internal_structure_trigger' => ['swing_lookback'],
            'aggressive_trigger' => ['entry_mode'],
            'extended_retest_trigger' => ['m5_retest_expiry_minutes'],
        ] as $arm => $expectedDiff) {
            $candidate = (array) $byArm[$arm]->modelVersion->parameters;
            $changed = collect(array_unique([...array_keys($baseline), ...array_keys($candidate)]))
                ->filter(fn (string $key): bool => ($baseline[$key] ?? null) !== ($candidate[$key] ?? null))->values()->all();
            $this->assertSame($expectedDiff, $changed, $arm);
        }
        Queue::assertPushed(EvaluateLabAgentJob::class, 60);

        // A losing composition may still contain a directional entry edge.
        // Only conservative excursion accumulated before the exit candle can
        // admit one bounded management-harvest wave.
        DB::table('edge_genesis_passports')->where('lab_generation_id', $triggerRepair['generation_id'])
            ->update(['status' => 'exhausted']);
        foreach ($triggerAgents as $triggerAgent) {
            $arm = (string) data_get($triggerAgent->modelVersion->metadata, 'edge_genesis.arm');
            $packetKey = (string) data_get($triggerAgent->modelVersion->metadata, 'edge_genesis.packet_key');
            DB::table('edge_genesis_trials')->where('lab_agent_id', $triggerAgent->id)->update([
                'status' => $arm === 'frozen_control' ? 'control_settled' : 'edge_not_found',
                'settled_at' => now(),
            ]);
            $triggerAgent->update(['lifecycle_status' => 'rejected']);
            $latent = $packetKey === 'break_retest'
                && in_array($arm, ['confirmation_floor_control', 'aggressive_trigger'], true);
            $triggerAgent->modelVersion->marketPerformances()->create([
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => $triggerAgent->strategy_family,
                'status' => 'rejected', 'fitness' => 0, 'forward_score' => 0, 'sample_count' => 2,
                'metrics' => ['total_trades' => $latent ? 4 : 0, 'after_cost_expectancy_r' => $latent ? -1.0 : 0,
                    'edge_observability' => ['setup_location_valid' => ['observed' => true, 'setup_count' => $latent ? 8 : 0],
                        'confirmation' => ['observed' => true, 'count' => $latent ? 8 : 0],
                        'entry' => ['observed' => true, 'count' => $latent ? 4 : 0]],
                    'management_evidence' => ['protocol' => 'replay_management_path_evidence_v1',
                        'observed_trades' => $latent ? 4 : 0,
                        'average_mfe_r_before_exit_bar' => $latent ? 1.4 : null,
                        'average_realized_r' => $latent ? -1.0 : null,
                        'path_precision' => 'dual_bound_with_conservative_pre_exit_bar_excursion',
                        'promotion_evidence' => false]],
                'evidence_status' => 'valid',
            ]);
        }
        // A candidate that looked useful in discovery may fail the complete
        // nine-fold authority gate while still exposing a repeatable,
        // conservative pre-exit excursion. That failure is terminal (never
        // edge authority), but it is valid input to one management-only
        // harvest experiment instead of a state-machine dead end.
        $failedAuthority = $triggerAgents->first(fn (LabAgent $agent): bool => data_get($agent->modelVersion->metadata, 'edge_genesis.packet_key') === 'break_retest'
            && data_get($agent->modelVersion->metadata, 'edge_genesis.arm') === 'aggressive_trigger');
        $failedAuthorityTrial = DB::table('edge_genesis_trials')->where('lab_agent_id', $failedAuthority->id)->first();
        DB::table('edge_genesis_trials')->where('id', $failedAuthorityTrial->id)->update([
            'stage' => 'nine_fold_authority', 'status' => 'edge_not_confirmed', 'settled_at' => now(),
        ]);
        DB::table('edge_genesis_passports')->where('id', $failedAuthorityTrial->edge_genesis_passport_id)->update([
            'phase' => 'EDGE_CONFIRMATION', 'status' => 'edge_not_confirmed', 'phase_changed_at' => now(),
        ]);
        $harvestReadiness = $foundry->architectureRepairReadiness('XAUUSD', 'H1');
        $this->assertTrue($harvestReadiness['admitted'], json_encode($harvestReadiness));
        $this->assertSame(DependencyAwareEdgeGenesisFoundryService::LATENT_HARVEST_REVISION, $harvestReadiness['repair_revision']);
        $this->assertSame('break_retest', $harvestReadiness['selected_packet']);
        $this->assertSame(8, $harvestReadiness['selected_packet_observed_trades']);

        $harvest = $foundry->materializeNextArchitectureRepair($lab, true);
        $this->assertSame('queued', $harvest['status']);
        $this->assertSame(20, $harvest['seats']);
        $harvestAgents = LabAgent::query()->with('modelVersion')->where('lab_generation_id', $harvest['generation_id'])
            ->where('origin', 'edge_genesis')->get();
        $this->assertCount(5, $harvestAgents);
        $this->assertTrue($harvestAgents->every(fn (LabAgent $agent): bool => data_get($agent->modelVersion->metadata, 'edge_genesis.packet_key') === 'break_retest'));
        $harvestByArm = $harvestAgents->keyBy(fn (LabAgent $agent): string => (string) data_get($agent->modelVersion->metadata, 'edge_genesis.arm'));
        $harvestControl = (array) $harvestByArm['latent_edge_control']->modelVersion->parameters;
        foreach ([
            'partial_harvest' => ['partial_take_profit_fraction'],
            'trailing_harvest' => ['trailing_atr_multiplier'],
            'time_stop_harvest' => ['time_stop_candles'],
            'target_harvest' => ['atr_target_multiplier'],
        ] as $arm => $expectedDiff) {
            $candidate = (array) $harvestByArm[$arm]->modelVersion->parameters;
            $changed = collect(array_unique([...array_keys($harvestControl), ...array_keys($candidate)]))
                ->filter(fn (string $key): bool => ($harvestControl[$key] ?? null) !== ($candidate[$key] ?? null))->values()->all();
            $this->assertSame($expectedDiff, $changed, $arm);
        }
        $this->assertNotNull(DB::table('edge_genesis_passports')->where('lab_generation_id', $harvest['generation_id'])
            ->value('baseline_model_version_id'));
        Queue::assertPushed(EvaluateLabAgentJob::class, 80);

        $harvestPlan = (array) data_get($harvestByArm['latent_edge_control']->modelVersion->metadata,
            'edge_genesis.frozen_window_plan');
        $harvestDiscoveryWindows = collect(range(1, 2))->map(fn (int $fold): array => [
            'start' => sprintf('2021-%02d-01', $fold), 'end' => sprintf('2021-%02d-20', $fold),
            'net_profit_percent' => .20,
        ])->all();
        $authorityDiscovery = [
            'data_hash' => $data, 'execution_hash' => $execution,
            'mtf_snapshot_manifest' => ['bundle_hash' => $bundleHash],
            'after_cost_expectancy_r' => .2, 'net_profit_percent' => 1.0, 'total_trades' => 6,
            'forward_window_protocol' => ['observed_windows' => 2, 'powered_windows' => 2, 'positive_windows' => 2,
                'independence_verified' => true, 'overlap_detected' => false, 'windows' => $harvestDiscoveryWindows],
            'edge_genesis_replay' => ['window_plan_hash' => $harvestPlan['window_plan_hash'],
                'fold_count' => 2, 'fold_offset' => 0, 'fold_universe_count' => 14],
            'edge_observability' => [
                'opportunity_detected' => ['observed' => true, 'count' => 20],
                'setup_location_valid' => ['observed' => true, 'location_count' => 8, 'setup_count' => 8],
                'context_bias_aligned' => ['observed' => true, 'count' => 20],
                'confirmation' => ['observed' => true, 'count' => 8],
                'entry' => ['observed' => true, 'count' => 6],
                'execution_price' => ['observed' => true, 'closed_trade_count' => 6],
                'invalidation_price' => ['observed' => true, 'count' => 6],
                'mfe_mae' => ['observed' => true, 'observed_trade_count' => 6],
                'exit_outcome' => ['observed' => true, 'closed_trade_count' => 6],
            ],
            'behavior_delta_observed' => true, 'context_declared_before_replay' => true,
            'context_occurrences' => 30,
            'edge_context_enforcement' => ['protocol' => 'edge_context_authority_firewall_v1',
                'enforced' => true, 'outside_scope_action' => 'WAIT'],
            'risk_governor_compliant' => true, 'forbidden_risk_bypass' => false,
        ];
        $partialHarvest = $harvestByArm['partial_harvest'];
        $harvestControlDiscovery = [...$authorityDiscovery,
            'after_cost_expectancy_r' => .10, 'net_profit_percent' => .4];
        $harvestControlDiscovery['forward_window_protocol']['windows'] = collect(range(1, 2))->map(fn (int $fold): array => [
            'start' => sprintf('2021-%02d-01', $fold), 'end' => sprintf('2021-%02d-20', $fold),
            'net_profit_percent' => .05,
        ])->all();
        $this->assertSame('edge_progressing', $foundry->settleOutcome(
            $harvestByArm['latent_edge_control'], $harvestControlDiscovery
        )['status']);
        $this->assertSame('edge_progressing', $foundry->settleOutcome($partialHarvest, $authorityDiscovery)['status']);
        foreach ([[$harvestByArm['latent_edge_control'], $harvestControlDiscovery, .10], [$partialHarvest, $authorityDiscovery, .20]] as [$seat, $metrics, $fitness]) {
            $seat->modelVersion->marketPerformances()->create([
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => $seat->strategy_family,
                'status' => 'screened', 'fitness' => $fitness, 'forward_score' => $fitness,
                'sample_count' => 6, 'rolling_windows_count' => 2, 'metrics' => $metrics, 'evidence_status' => 'valid',
            ]);
        }
        LabAgent::query()->whereIn('id', [$harvestByArm['latent_edge_control']->id, $partialHarvest->id])
            ->update(['lifecycle_status' => 'rejected']);
        $harvestReplication = $foundry->materializeIndependentReplication('XAUUSD', 'H1', true);
        $this->assertSame('queued', $harvestReplication['status']);
        $this->assertSame(2, $harvestReplication['seats']);
        $harvestReplicationWindows = collect(range(1, 3))->map(fn (int $fold): array => [
            'start' => sprintf('2022-%02d-01', $fold), 'end' => sprintf('2022-%02d-20', $fold),
            'net_profit_percent' => .20,
        ])->all();
        $harvestReplicationResult = [...$authorityDiscovery,
            'forward_window_protocol' => ['observed_windows' => 3, 'powered_windows' => 3, 'positive_windows' => 3,
                'independence_verified' => true, 'overlap_detected' => false, 'windows' => $harvestReplicationWindows],
            'edge_genesis_replay' => ['window_plan_hash' => $harvestPlan['window_plan_hash'],
                'fold_count' => 3, 'fold_offset' => 2, 'fold_universe_count' => 14],
        ];
        $harvestControlReplication = [...$harvestReplicationResult, 'after_cost_expectancy_r' => .10];
        $harvestControlReplication['forward_window_protocol']['windows'] = collect(range(1, 3))->map(fn (int $fold): array => [
            'start' => sprintf('2022-%02d-01', $fold), 'end' => sprintf('2022-%02d-20', $fold),
            'net_profit_percent' => .01,
        ])->all();
        $harvestByArm['latent_edge_control']->modelVersion->marketPerformances()->update([
            'rolling_windows_count' => 3, 'metrics' => $harvestControlReplication]);
        $partialHarvest->modelVersion->marketPerformances()->update([
            'rolling_windows_count' => 3, 'metrics' => $harvestReplicationResult]);
        $this->assertSame('replication_control_settled', $foundry->settleOutcome(
            $harvestByArm['latent_edge_control']->fresh('modelVersion'), $harvestControlReplication
        )['status']);
        $this->assertSame('edge_replication_passed', $foundry->settleOutcome(
            $partialHarvest->fresh('modelVersion'), $harvestReplicationResult
        )['status']);
        LabAgent::query()->whereIn('id', [$harvestByArm['latent_edge_control']->id, $partialHarvest->id])
            ->update(['lifecycle_status' => 'rejected']);
        $harvestConfirmation = $foundry->materializeConfirmation('XAUUSD', 'H1', true);
        $this->assertSame('queued', $harvestConfirmation['status']);
        $this->assertSame(2, $harvestConfirmation['seats']);
        $this->assertSame('EDGE_CONFIRMATION', data_get($partialHarvest->fresh('modelVersion')->modelVersion->metadata, 'edge_genesis.phase'));
        $this->assertSame('EDGE_CONFIRMATION', data_get($harvestByArm['latent_edge_control']->fresh('modelVersion')->modelVersion->metadata, 'edge_genesis.phase'));
        $authority = [...$authorityDiscovery, 'total_trades' => 30, 'pf_lower_confidence_bound' => 1.1,
            'forward_window_protocol' => ['observed_windows' => 9, 'powered_windows' => 9, 'positive_windows' => 6,
                'independence_verified' => true, 'overlap_detected' => false,
                'windows' => collect(range(1, 9))->map(fn (int $fold): array => [
                    'start' => sprintf('2023-%02d-01', $fold), 'end' => sprintf('2023-%02d-20', $fold),
                    'net_profit_percent' => .10,
                ])->all()],
            'edge_genesis_replay' => ['window_plan_hash' => $harvestPlan['window_plan_hash'],
                'fold_count' => 9, 'fold_offset' => 5, 'fold_universe_count' => 14],
            'context_occurrences' => 90];
        $authority['edge_observability']['entry']['count'] = 30;
        $authority['edge_observability']['exit_outcome']['closed_trade_count'] = 30;
        $harvestByArm['latent_edge_control']->modelVersion->marketPerformances()->update([
            'rolling_windows_count' => 9, 'metrics' => $authority]);
        $this->assertSame('control_settled', $foundry->settleOutcome(
            $harvestByArm['latent_edge_control']->fresh('modelVersion'), $authority,
        )['status']);

        // Historical cohorts declared a market-state niche but the evaluator
        // did not turn it into an entry gate. Preserve that result as audit,
        // then open a new paired context-firewall experiment around the exact
        // source parameters instead of retroactively filtering its trades.
        foreach ($harvestAgents as $harvestAgent) {
            $arm = (string) data_get($harvestAgent->modelVersion->metadata, 'edge_genesis.arm');
            DB::table('edge_genesis_trials')->where('lab_agent_id', $harvestAgent->id)->update([
                'stage' => 'nine_fold_authority',
                'status' => $arm === 'latent_edge_control' ? 'control_settled' : 'edge_not_confirmed',
                'settled_at' => now(),
            ]);
            $harvestAgent->update(['lifecycle_status' => 'rejected']);
        }
        DB::table('edge_genesis_passports')->where('lab_generation_id', $harvest['generation_id'])->update([
            'phase' => 'EDGE_CONFIRMATION', 'status' => 'edge_not_confirmed', 'phase_changed_at' => now(),
        ]);
        $historicalControl = $harvestByArm['latent_edge_control']->fresh('modelVersion');
        $historicalMetadata = (array) $historicalControl->modelVersion->metadata;
        data_set($historicalMetadata, 'edge_genesis.context', [
            'regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal', 'outside_scope' => 'WAIT',
        ]);
        $historicalControl->modelVersion->update(['metadata' => $historicalMetadata]);
        $historicalControl->modelVersion->marketPerformances()->update([
            'status' => 'rejected', 'fitness' => 0, 'forward_score' => 0, 'sample_count' => 30,
            'metrics' => ['total_trades' => 30, 'regime_performance' => ['trend_down' => ['trades' => 6]],
                'walk_forward' => ['windows' => [['results' => ['forward' => ['regime_performance' => [
                    'trend_up' => ['trades' => 4], 'range' => ['trades' => 3],
                ]]]]]]],
            'evidence_status' => 'valid',
        ]);
        $contextReadiness = $foundry->architectureRepairReadiness('XAUUSD', 'H1');
        $this->assertTrue($contextReadiness['admitted'], json_encode($contextReadiness));
        $this->assertSame(DependencyAwareEdgeGenesisFoundryService::CONTEXT_ROUTER_REPAIR_REVISION,
            $contextReadiness['repair_revision']);
        $this->assertContains('range', $contextReadiness['outside_regimes']);

        $contextRepair = $foundry->materializeNextArchitectureRepair($lab, true);
        $this->assertSame('queued', $contextRepair['status']);
        $this->assertSame(20, $contextRepair['seats']);
        $contextAgents = LabAgent::query()->with('modelVersion')->where('lab_generation_id', $contextRepair['generation_id'])
            ->where('origin', 'edge_genesis')->get();
        $this->assertEqualsCanonicalizing(DependencyAwareEdgeGenesisFoundryService::CONTEXT_ROUTER_REPAIR_ARMS,
            $contextAgents->map(fn (LabAgent $agent): string => (string) data_get($agent->modelVersion->metadata, 'edge_genesis.arm'))->all());
        $this->assertSame(1, $contextAgents->pluck('modelVersion.parameters')->map(fn ($parameters): string => hash('sha256', json_encode($parameters, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)))->unique()->count());
        $contextByArm = $contextAgents->keyBy(fn (LabAgent $agent): string => (string) data_get($agent->modelVersion->metadata, 'edge_genesis.arm'));
        $this->assertSame('telemetry_only_control', data_get($contextByArm['unfiltered_context_control']->modelVersion->metadata,
            'edge_genesis.context.enforcement'));
        $this->assertSame(['regime', 'session', 'volatility'], data_get($contextByArm['strict_context_gate']->modelVersion->metadata,
            'edge_genesis.context.admission_axes'));

        // A context treatment earns an expensive nine-fold replay only when
        // it improves the exact unfiltered control on identical discovery
        // windows. Absolute-positive but opportunity-destroying filters are
        // retained as immutable failure evidence, not authority candidates.
        $windows = [
            ['start' => '2020-06-01', 'end' => '2020-06-30'],
            ['start' => '2020-11-01', 'end' => '2020-11-30'],
        ];
        $expectancies = [
            'unfiltered_context_control' => .40,
            'regime_compatibility_gate' => .62,
            'session_liquidity_gate' => .20,
            'regime_session_gate' => .31,
            'strict_context_gate' => -.10,
        ];
        foreach ($contextByArm as $arm => $contextAgent) {
            $contextAgent->modelVersion->marketPerformances()->create([
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => $contextAgent->strategy_family,
                'status' => 'rejected', 'fitness' => 0, 'forward_score' => 0, 'sample_count' => 8,
                'rolling_windows_count' => 2, 'rolling_forward_wins' => 1,
                'metrics' => ['after_cost_expectancy_r' => $expectancies[$arm],
                    'forward_window_protocol' => ['windows' => $windows],
                    'edge_context_enforcement' => ['protocol' => 'edge_context_authority_firewall_v1',
                        'enforced' => $arm !== 'unfiltered_context_control', 'outside_scope_action' => 'WAIT']],
                'evidence_status' => 'valid',
            ]);
            DB::table('edge_genesis_trials')->where('lab_agent_id', $contextAgent->id)->update([
                'stage' => 'two_fold_discovery',
                'status' => $arm === 'strict_context_gate' ? 'edge_not_found' : 'edge_progressing',
                'updated_at' => now(),
            ]);
            $contextAgent->update(['lifecycle_status' => 'rejected']);
        }
        DB::table('edge_genesis_passports')->where('lab_generation_id', $contextRepair['generation_id'])->update([
            'phase' => 'EDGE_CONFIRMATION', 'status' => 'running', 'phase_changed_at' => now(), 'updated_at' => now(),
        ]);
        $contextReplicationDry = $foundry->materializeIndependentReplication('XAUUSD', 'H1', false);
        $this->assertSame(2, $contextReplicationDry['seats']);
        $this->assertSame(1, $contextReplicationDry['discovery_approved']);
        $this->assertSame(2, $contextReplicationDry['discovery_dominated']);
        $contextReplication = $foundry->materializeIndependentReplication('XAUUSD', 'H1', true);
        $this->assertSame(2, $contextReplication['seats']);
        $contextControl = $contextByArm['unfiltered_context_control'];
        $contextTreatment = $contextByArm['regime_compatibility_gate'];
        $contextPlan = (array) data_get($contextControl->modelVersion->metadata, 'edge_genesis.frozen_window_plan');
        $contextReplicationWindows = collect(range(1, 3))->map(fn (int $fold): array => [
            'start' => sprintf('2022-%02d-01', $fold), 'end' => sprintf('2022-%02d-18', $fold),
            'net_profit_percent' => -.03,
        ])->all();
        $contextTreatmentReplication = [...$authorityDiscovery,
            'after_cost_expectancy_r' => -.03, 'net_profit_percent' => -.1,
            'forward_window_protocol' => ['observed_windows' => 3, 'powered_windows' => 3, 'positive_windows' => 0,
                'independence_verified' => true, 'overlap_detected' => false, 'windows' => $contextReplicationWindows],
            'edge_genesis_replay' => ['window_plan_hash' => $contextPlan['window_plan_hash'],
                'fold_count' => 3, 'fold_offset' => 2, 'fold_universe_count' => 14],
        ];
        $contextControlReplication = [...$contextTreatmentReplication, 'after_cost_expectancy_r' => -.31];
        $contextControlReplication['forward_window_protocol']['windows'] = collect(range(1, 3))->map(fn (int $fold): array => [
            'start' => sprintf('2022-%02d-01', $fold), 'end' => sprintf('2022-%02d-18', $fold),
            'net_profit_percent' => -.30,
        ])->all();
        foreach ([$contextControl, $contextTreatment] as $discoverySeat) {
            $discoveryMetrics = (array) $discoverySeat->modelVersion->marketPerformances()->latest('id')->value('metrics');
            LabEvaluationRun::create([
                'run_id' => (string) Str::uuid(), 'lab_generation_id' => $discoverySeat->lab_generation_id,
                'lab_agent_id' => $discoverySeat->id, 'model_version_id' => $discoverySeat->model_version_id,
                'phase' => 'full_validation', 'mode' => 'full', 'status' => 'completed',
                'metrics' => ['agent_result' => [...$discoveryMetrics, 'rolling_windows_count' => 2]],
            ]);
        }
        $contextControl->modelVersion->marketPerformances()->update([
            'rolling_windows_count' => 3, 'metrics' => $contextControlReplication]);
        $contextTreatment->modelVersion->marketPerformances()->update([
            'rolling_windows_count' => 3, 'metrics' => $contextTreatmentReplication]);
        $this->assertSame('replication_control_settled', $foundry->settleOutcome(
            $contextControl->fresh('modelVersion'), $contextControlReplication
        )['status']);
        $this->assertSame('edge_replication_passed', $foundry->settleOutcome(
            $contextTreatment->fresh('modelVersion'), $contextTreatmentReplication
        )['status']);
        LabAgent::query()->whereIn('id', [$contextControl->id, $contextTreatment->id])
            ->update(['lifecycle_status' => 'rejected']);
        $contextConfirmationDry = $foundry->materializeConfirmation('XAUUSD', 'H1', false);
        $this->assertSame(2, $contextConfirmationDry['seats']);
        $this->assertSame(1, $contextConfirmationDry['replication_approved']);
        $contextConfirmation = $foundry->materializeConfirmation('XAUUSD', 'H1', true);
        $this->assertSame(2, $contextConfirmation['seats']);
        $this->assertSame(1, $contextConfirmation['replication_approved']);
        $regimeSelection = json_decode((string) DB::table('edge_genesis_trials')
            ->where('lab_agent_id', $contextByArm['regime_compatibility_gate']->id)->value('evidence'), true);
        $this->assertSame('discovery_outperformed_control', data_get($regimeSelection, 'authority_selection.decision'));
        foreach (['session_liquidity_gate', 'regime_session_gate'] as $dominatedArm) {
            $dominated = $contextByArm[$dominatedArm];
            $trial = DB::table('edge_genesis_trials')->where('lab_agent_id', $dominated->id)->first();
            $this->assertSame('edge_not_found', $trial->status);
            $this->assertSame('PAIRED_DISCOVERY_DID_NOT_OUTPERFORM_CONTROL',
                data_get(json_decode($trial->evidence, true), 'authority_selection.reason'));
            $this->assertSame('rejected', $dominated->fresh()->lifecycle_status);
        }

        // A known evaluator regression after discovery may replay the queued
        // authority request without deleting or manufacturing its valid
        // two-fold evidence. Unselected arms remain terminal.
        $authorityAgents = collect(['unfiltered_context_control', 'regime_compatibility_gate'])
            ->map(fn (string $arm): LabAgent => $contextByArm[$arm]);
        foreach ($authorityAgents as $index => $authorityAgent) {
            LabAgent::query()->whereKey($authorityAgent->id)->update([
                'lifecycle_status' => $index === 0 ? 'evaluation_error' : 'technical_quarantine',
            ]);
            DB::table('lab_evaluation_runs')->insert([
                'run_id' => (string) Str::uuid(), 'lab_generation_id' => $authorityAgent->lab_generation_id,
                'lab_agent_id' => $authorityAgent->id, 'model_version_id' => $authorityAgent->model_version_id,
                'phase' => 'full_validation', 'mode' => 'full', 'status' => 'technical_error',
                'error_message' => '{"detail":"UnboundLocalError: cannot access local variable \'context_declared\' where it is not associated with a value"}',
                'started_at' => now(), 'finished_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->assertSame(2, $foundry->resumePendingTrials('XAUUSD', 'H1', false)['seats']);
        $this->assertSame(2, $foundry->resumePendingTrials('XAUUSD', 'H1', true)['seats']);
        $this->assertTrue($authorityAgents->every(fn (LabAgent $authorityAgent): bool => $authorityAgent->fresh()->lifecycle_status === 'full_queued'));

        // A regime filter may be causally beneficial without being an edge.
        // Preserve it as the next packet's frozen stepping stone and move the
        // search to one-axis entry quality rather than discarding the gain or
        // granting premature parent authority.
        $authorityWindows = collect(range(1, 9))->map(fn (int $window): array => [
            'start' => sprintf('2020-%02d-01', $window),
            'end' => sprintf('2020-%02d-21', $window),
        ])->all();
        foreach (['unfiltered_context_control' => [-.31, 57, 1], 'regime_compatibility_gate' => [-.03, 35, 2]] as $arm => [$expectancy, $trades, $positive]) {
            $authorityAgent = $contextByArm[$arm];
            $performance = $authorityAgent->modelVersion->marketPerformances()->latest('id')->firstOrFail();
            $performance->update([
                'sample_count' => $trades, 'rolling_windows_count' => 9, 'rolling_forward_wins' => $positive,
                'metrics' => ['after_cost_expectancy_r' => $expectancy, 'total_trades' => $trades,
                    'forward_window_protocol' => ['powered_windows' => 9, 'positive_windows' => $positive,
                        'windows' => $authorityWindows],
                    ...($arm === 'regime_compatibility_gate' ? ['pf_attribution' => [
                        'by_direction' => [
                            'BUY' => ['trades' => 22, 'net_pf' => 1.248, 'net_profit_percent' => 2.091],
                            'SELL' => ['trades' => 13, 'net_pf' => 0.0, 'net_profit_percent' => -5.046],
                        ],
                        'by_volatility' => [
                            'high_volatility' => ['trades' => 27, 'net_pf' => 1.379, 'net_profit_percent' => 2.892],
                            'normal_volatility' => ['trades' => 8, 'net_pf' => 0.0, 'net_profit_percent' => -5.847],
                        ],
                    ]] : []),
                    'edge_context_enforcement' => ['protocol' => 'edge_context_authority_firewall_v1',
                        'status' => $arm === 'unfiltered_context_control' ? 'telemetry_only_control' : 'enforced',
                        'enforced' => $arm !== 'unfiltered_context_control',
                        'admission_axes' => $arm === 'unfiltered_context_control' ? [] : ['regime'],
                        'outside_scope_action' => 'WAIT']],
            ]);
            DB::table('edge_genesis_trials')->where('lab_agent_id', $authorityAgent->id)->update([
                'stage' => 'nine_fold_authority',
                'status' => $arm === 'unfiltered_context_control' ? 'control_settled' : 'edge_not_confirmed',
                'settled_at' => now(), 'updated_at' => now(),
            ]);
            $authorityAgent->update(['lifecycle_status' => 'rejected']);
        }
        DB::table('edge_genesis_passports')->where('lab_generation_id', $contextRepair['generation_id'])->update([
            'phase' => 'EDGE_CONFIRMATION', 'status' => 'edge_not_confirmed', 'phase_changed_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame('would_reconcile', $foundry->reconcileContextAuthorityEffects('XAUUSD', 'H1', false)['status']);
        $this->assertSame('reconciled', $foundry->reconcileContextAuthorityEffects('XAUUSD', 'H1', true)['status']);
        $regimeEffect = json_decode((string) DB::table('edge_genesis_trials')
            ->where('lab_agent_id', $contextByArm['regime_compatibility_gate']->id)->value('evidence'), true);
        $this->assertSame('beneficial_stepping_stone', data_get($regimeEffect, 'causal_context_effect.status'));
        $this->assertSame(.28, data_get($regimeEffect, 'causal_context_effect.expectancy_delta_r'));
        $this->assertFalse((bool) data_get($regimeEffect, 'causal_context_effect.parent_authority'));
        $entrySynthesisReadiness = $foundry->architectureRepairReadiness('XAUUSD', 'H1');
        $this->assertTrue($entrySynthesisReadiness['admitted'], json_encode($entrySynthesisReadiness));
        $this->assertSame(DependencyAwareEdgeGenesisFoundryService::REGIME_ENTRY_SYNTHESIS_REVISION,
            $entrySynthesisReadiness['repair_revision']);
        $this->assertSame(.28, $entrySynthesisReadiness['expectancy_delta_r']);
        $entrySynthesis = $foundry->materializeNextArchitectureRepair($lab, true);
        $this->assertSame('queued', $entrySynthesis['status']);
        $this->assertSame(20, $entrySynthesis['seats']);
        $synthesisAgents = LabAgent::query()->with('modelVersion')->where('lab_generation_id', $entrySynthesis['generation_id'])
            ->where('origin', 'edge_genesis')->get();
        $this->assertEqualsCanonicalizing(DependencyAwareEdgeGenesisFoundryService::REGIME_ENTRY_SYNTHESIS_ARMS,
            $synthesisAgents->map(fn (LabAgent $agent): string => (string) data_get($agent->modelVersion->metadata, 'edge_genesis.arm'))->all());
        $synthesisByArm = $synthesisAgents->keyBy(fn (LabAgent $agent): string => (string) data_get($agent->modelVersion->metadata, 'edge_genesis.arm'));
        $synthesisControl = (array) $synthesisByArm['regime_entry_control']->modelVersion->parameters;
        foreach ([
            'retest_entry_gate' => ['entry_mode'],
            'independent_confirmation_gate' => ['minimum_independent_confirmations'],
            'reward_space_gate' => ['minimum_reward_space_r'],
            'chase_quality_gate' => ['max_chase_atr'],
        ] as $arm => $expectedDiff) {
            $candidate = (array) $synthesisByArm[$arm]->modelVersion->parameters;
            $changed = collect(array_unique([...array_keys($synthesisControl), ...array_keys($candidate)]))
                ->filter(fn (string $key): bool => ($synthesisControl[$key] ?? null) !== ($candidate[$key] ?? null))->values()->all();
            $this->assertSame($expectedDiff, $changed, $arm);
            $this->assertSame(['regime'], data_get($synthesisByArm[$arm]->modelVersion->metadata,
                'edge_genesis.context.admission_axes'));
        }

        // If every one-axis repair loses to (or merely ties) the exact regime
        // control, no arm may consume nine-fold authority compute and the
        // control must become terminal. This closes the former lifecycle
        // deadlock without weakening the economic edge gate.
        $synthesisExpectancies = [
            'regime_entry_control' => .671963,
            'retest_entry_gate' => -1.0,
            'independent_confirmation_gate' => .665351,
            'reward_space_gate' => .548692,
            'chase_quality_gate' => .671963,
        ];
        foreach ($synthesisByArm as $arm => $synthesisAgent) {
            $synthesisAgent->modelVersion->marketPerformances()->create([
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => $synthesisAgent->strategy_family,
                'status' => 'rejected', 'fitness' => 0, 'forward_score' => 0, 'sample_count' => 8,
                'rolling_windows_count' => 2, 'rolling_forward_wins' => $arm === 'retest_entry_gate' ? 0 : 1,
                'metrics' => ['after_cost_expectancy_r' => $synthesisExpectancies[$arm],
                    'forward_window_protocol' => ['powered_windows' => 2, 'positive_windows' => $arm === 'retest_entry_gate' ? 0 : 1,
                        'windows' => $windows],
                    'edge_context_enforcement' => ['protocol' => 'edge_context_authority_firewall_v1',
                        'status' => 'enforced', 'enforced' => true, 'admission_axes' => ['regime'],
                        'outside_scope_action' => 'WAIT']],
                'evidence_status' => 'valid',
            ]);
            DB::table('edge_genesis_trials')->where('lab_agent_id', $synthesisAgent->id)->update([
                'stage' => 'two_fold_discovery',
                'status' => $arm === 'retest_entry_gate' ? 'edge_not_found' : 'edge_progressing',
                'evidence' => json_encode(['protocol' => DependencyAwareEdgeGenesisFoundryService::PROTOCOL,
                    'discovery_verdict_revision' => DependencyAwareEdgeGenesisFoundryService::DISCOVERY_VERDICT_REVISION,
                    'promotion_evidence' => false]),
                'settled_at' => now(), 'updated_at' => now(),
            ]);
            $synthesisAgent->update(['lifecycle_status' => 'rejected']);
        }
        DB::table('edge_genesis_passports')->where('lab_generation_id', $entrySynthesis['generation_id'])->update([
            'phase' => 'EDGE_CONFIRMATION', 'status' => 'running', 'phase_changed_at' => now(), 'updated_at' => now(),
        ]);
        $noWinnerDry = $foundry->materializeIndependentReplication('XAUUSD', 'H1', false);
        $this->assertSame('would_settle_controls', $noWinnerDry['status']);
        $this->assertSame(0, $noWinnerDry['seats']);
        $this->assertSame(3, $noWinnerDry['discovery_dominated']);
        $noWinner = $foundry->materializeIndependentReplication('XAUUSD', 'H1', true);
        $this->assertSame('controls_settled', $noWinner['status']);
        $this->assertSame('control_settled', DB::table('edge_genesis_trials')
            ->where('lab_agent_id', $synthesisByArm['regime_entry_control']->id)->value('status'));
        foreach (['independent_confirmation_gate', 'reward_space_gate', 'chase_quality_gate'] as $dominatedArm) {
            $trial = DB::table('edge_genesis_trials')->where('lab_agent_id', $synthesisByArm[$dominatedArm]->id)->first();
            $this->assertSame('edge_not_found', $trial->status);
            $this->assertSame('discovery_dominated', data_get(json_decode($trial->evidence, true), 'authority_selection.decision'));
        }
        $this->assertTrue(DB::table('edge_genesis_passports')->where('lab_generation_id', $entrySynthesis['generation_id'])
            ->get()->every(fn ($passport): bool => $passport->status === 'edge_not_found'));

        // A later scalar-verdict reconciliation must not erase or reopen a
        // terminal paired authority decision.
        $foundry->reconcileDiscoveryOutcomes('XAUUSD', 'H1', true);
        $persistent = DB::table('edge_genesis_trials')
            ->where('lab_agent_id', $synthesisByArm['independent_confirmation_gate']->id)->first();
        $this->assertSame('edge_not_found', $persistent->status);
        $this->assertSame('discovery_dominated', data_get(json_decode($persistent->evidence, true), 'authority_selection.decision'));

        // Retrospective failure-cell attribution is converted into a new
        // prospective pre-entry experiment; it is never mistaken for a skill
        // or parent merely because BUY/high-volatility subsets looked useful.
        $factorialReadiness = $foundry->architectureRepairReadiness('XAUUSD', 'H1');
        $this->assertTrue($factorialReadiness['admitted'], json_encode($factorialReadiness));
        $this->assertSame(DependencyAwareEdgeGenesisFoundryService::FAILURE_CELL_FACTORIAL_REVISION,
            $factorialReadiness['repair_revision']);
        $this->assertTrue($factorialReadiness['retrospective_only']);
        $this->assertFalse($factorialReadiness['authority_from_subset_analysis']);
        $factorial = $foundry->materializeNextArchitectureRepair($lab, true);
        $this->assertSame('queued', $factorial['status']);
        $this->assertSame(20, $factorial['seats']);
        $factorialAgents = LabAgent::query()->with('modelVersion')->where('lab_generation_id', $factorial['generation_id'])
            ->where('origin', 'edge_genesis')->get();
        $this->assertEqualsCanonicalizing(DependencyAwareEdgeGenesisFoundryService::FAILURE_CELL_FACTORIAL_ARMS,
            $factorialAgents->map(fn (LabAgent $agent): string => (string) data_get($agent->modelVersion->metadata, 'edge_genesis.arm'))->all());
        $factorialByArm = $factorialAgents->keyBy(fn (LabAgent $agent): string => (string) data_get($agent->modelVersion->metadata, 'edge_genesis.arm'));
        $this->assertSame(['regime'], data_get($factorialByArm['failure_cell_control']->modelVersion->metadata,
            'edge_genesis.context.admission_axes'));
        $this->assertSame(['regime', 'direction'], data_get($factorialByArm['buy_direction_gate']->modelVersion->metadata,
            'edge_genesis.context.admission_axes'));
        $this->assertSame(['BUY'], data_get($factorialByArm['buy_direction_gate']->modelVersion->metadata,
            'edge_genesis.context.allowed_directions'));
        $this->assertSame(['regime', 'volatility'], data_get($factorialByArm['high_volatility_gate']->modelVersion->metadata,
            'edge_genesis.context.admission_axes'));
        $this->assertSame(['high_volatility'], data_get($factorialByArm['high_volatility_gate']->modelVersion->metadata,
            'edge_genesis.context.allowed_volatility'));
        $this->assertSame(['regime', 'direction', 'volatility'], data_get($factorialByArm['buy_high_volatility_interaction']->modelVersion->metadata,
            'edge_genesis.context.admission_axes'));
        $this->assertSame(['SELL'], data_get($factorialByArm['sell_direction_negative_control']->modelVersion->metadata,
            'edge_genesis.context.allowed_directions'));
        $this->assertCount(1, $factorialAgents->pluck('modelVersion.parameters')->map(fn ($parameters): string => hash('sha256', json_encode($parameters, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)))->unique());

        // Direction specialists have fewer opportunities by construction.
        // A positive, pre-registered one-powered-fold result may reach the
        // paired budget selector, but it still receives no authority: only
        // candidates that beat the exact control are sent to nine folds.
        $factorialOutcomes = [
            'failure_cell_control' => [.671963, 9, 2, 2],
            'buy_direction_gate' => [1.345409, 4, 1, 1],
            'high_volatility_gate' => [.172353, 8, 2, 1],
            'buy_high_volatility_interaction' => [1.345409, 4, 1, 1],
            'sell_direction_negative_control' => [.133207, 5, 1, 1],
        ];
        foreach ($factorialOutcomes as $arm => [$expectancy, $trades, $powered, $positive]) {
            $factorialAgent = $factorialByArm[$arm];
            $factorialPlan = (array) data_get($factorialAgent->modelVersion->metadata,
                'edge_genesis.frozen_window_plan');
            $contextAxes = (array) data_get($factorialAgent->modelVersion->metadata,
                'edge_genesis.context.admission_axes', []);
            $result = [
                'data_hash' => $data, 'execution_hash' => $execution,
                'mtf_snapshot_manifest' => ['bundle_hash' => $bundleHash],
                'after_cost_expectancy_r' => $expectancy,
                'net_profit_percent' => max(.01, $expectancy), 'total_trades' => $trades,
                'forward_window_protocol' => ['observed_windows' => 2, 'powered_windows' => $powered,
                    'positive_windows' => $positive, 'independence_verified' => true,
                    'overlap_detected' => false, 'windows' => $windows],
                'edge_genesis_replay' => ['window_plan_hash' => $factorialPlan['window_plan_hash'],
                    'fold_count' => 2, 'fold_offset' => 0, 'fold_universe_count' => 14],
                'edge_observability' => [
                    'opportunity_detected' => ['observed' => true, 'count' => 20],
                    'setup_location_valid' => ['observed' => true, 'setup_count' => max(1, $trades)],
                    'context_bias_aligned' => ['observed' => true, 'count' => 20],
                    'confirmation' => ['observed' => true, 'count' => max(1, $trades)],
                    'entry' => ['observed' => true, 'count' => $trades],
                    'execution_price' => ['observed' => true, 'closed_trade_count' => $trades],
                    'invalidation_price' => ['observed' => true, 'count' => $trades],
                    'mfe_mae' => ['observed' => true, 'observed_trade_count' => $trades],
                    'exit_outcome' => ['observed' => true, 'closed_trade_count' => $trades],
                ],
                'behavior_delta_observed' => true, 'context_declared_before_replay' => true,
                'context_occurrences' => 20,
                'edge_context_enforcement' => ['protocol' => 'edge_context_authority_firewall_v1',
                    'status' => 'enforced', 'enforced' => true, 'admission_axes' => $contextAxes,
                    'outside_scope_action' => 'WAIT'],
                'risk_governor_compliant' => true, 'forbidden_risk_bypass' => false,
            ];
            $factorialAgent->modelVersion->marketPerformances()->create([
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => $factorialAgent->strategy_family,
                'status' => 'screened', 'fitness' => $expectancy, 'forward_score' => $expectancy,
                'sample_count' => $trades, 'rolling_windows_count' => 2,
                'rolling_forward_wins' => $positive, 'metrics' => $result, 'evidence_status' => 'valid',
            ]);
            $factorialAgent->update(['lifecycle_status' => 'screened']);
            $verdict = $foundry->settleOutcome($factorialAgent->fresh('modelVersion'), $result);
            if (in_array('direction', $contextAxes, true)) {
                $this->assertTrue($verdict['discovery_admission']['specialist_power_adjustment'], $arm);
                $this->assertSame('edge_progressing', $verdict['status'], $arm);
            }
        }
        $factorialDry = $foundry->materializeIndependentReplication('XAUUSD', 'H1', false);
        $this->assertSame('would_queue', $factorialDry['status']);
        $this->assertSame(3, $factorialDry['seats']);
        $this->assertSame(2, $factorialDry['discovery_approved']);
        $this->assertSame(2, $factorialDry['discovery_dominated']);
        $factorialReplication = $foundry->materializeIndependentReplication('XAUUSD', 'H1', true);
        $this->assertSame('queued', $factorialReplication['status']);
        $this->assertSame(3, $factorialReplication['seats']);
        $factorialReplicationWindows = collect(range(1, 3))->map(fn (int $fold): array => [
            'start' => sprintf('2022-%02d-01', $fold), 'end' => sprintf('2022-%02d-17', $fold),
            'net_profit_percent' => .30,
        ])->all();
        foreach (['failure_cell_control', 'buy_direction_gate', 'buy_high_volatility_interaction'] as $authorityArm) {
            $seat = $factorialByArm[$authorityArm];
            $metrics = (array) $seat->modelVersion->marketPerformances()->latest('id')->value('metrics');
            $plan = (array) data_get($seat->modelVersion->metadata, 'edge_genesis.frozen_window_plan');
            $replicationMetrics = [...$metrics,
                'forward_window_protocol' => ['observed_windows' => 3, 'powered_windows' => 3,
                    'positive_windows' => 3, 'independence_verified' => true, 'overlap_detected' => false,
                    'windows' => $authorityArm === 'failure_cell_control'
                        ? collect($factorialReplicationWindows)->map(fn (array $window): array => [...$window, 'net_profit_percent' => .05])->all()
                        : $factorialReplicationWindows],
                'edge_genesis_replay' => ['window_plan_hash' => $plan['window_plan_hash'],
                    'fold_count' => 3, 'fold_offset' => 2, 'fold_universe_count' => 14],
            ];
            $seat->modelVersion->marketPerformances()->update([
                'rolling_windows_count' => 3, 'metrics' => $replicationMetrics]);
            $factorialReplicationResults[$authorityArm] = $replicationMetrics;
        }
        $this->assertSame('replication_control_settled', $foundry->settleOutcome(
            $factorialByArm['failure_cell_control']->fresh('modelVersion'),
            $factorialReplicationResults['failure_cell_control']
        )['status']);
        foreach (['buy_direction_gate', 'buy_high_volatility_interaction'] as $authorityArm) {
            $foundry->settleOutcome($factorialByArm[$authorityArm]->fresh('modelVersion'),
                $factorialReplicationResults[$authorityArm]);
        }
        LabAgent::query()->whereIn('id', collect(['failure_cell_control', 'buy_direction_gate', 'buy_high_volatility_interaction'])
            ->map(fn (string $arm): int => $factorialByArm[$arm]->id))->update(['lifecycle_status' => 'rejected']);
        $factorialConfirmation = $foundry->materializeConfirmation('XAUUSD', 'H1', true);
        $this->assertSame('queued', $factorialConfirmation['status']);
        $this->assertSame(3, $factorialConfirmation['seats']);
        foreach (['failure_cell_control', 'buy_direction_gate', 'buy_high_volatility_interaction'] as $authorityArm) {
            $this->assertSame('nine_fold_authority', DB::table('edge_genesis_trials')
                ->where('lab_agent_id', $factorialByArm[$authorityArm]->id)->value('stage'), $authorityArm);
        }
        $factorialControlEvidence = json_decode((string) DB::table('edge_genesis_trials')
            ->where('lab_agent_id', $factorialByArm['failure_cell_control']->id)->value('evidence'), true);
        $this->assertSame('exact_control_replayed_for_paired_authority',
            data_get($factorialControlEvidence, 'authority_selection.decision'));
        $this->assertTrue((bool) data_get($factorialControlEvidence,
            'authority_selection.nine_fold_replay_admitted'));
        foreach (['high_volatility_gate', 'sell_direction_negative_control'] as $dominatedArm) {
            $trial = DB::table('edge_genesis_trials')->where('lab_agent_id', $factorialByArm[$dominatedArm]->id)->first();
            $this->assertSame('edge_not_found', $trial->status, $dominatedArm);
            $this->assertFalse((bool) data_get(json_decode($trial->evidence, true),
                'authority_selection.nine_fold_replay_admitted'), $dominatedArm);
        }
        $legacyDominatedTrial = DB::table('edge_genesis_trials')
            ->where('lab_agent_id', $factorialByArm['high_volatility_gate']->id)->first();
        $legacyDominatedEvidence = json_decode((string) $legacyDominatedTrial->evidence, true);
        data_set($legacyDominatedEvidence, 'authority_selection.nine_fold_replay_admitted', true);
        DB::table('edge_genesis_trials')->where('id', $legacyDominatedTrial->id)->update([
            'evidence' => json_encode($legacyDominatedEvidence),
        ]);
        $this->assertSame('would_reconcile', $foundry->reconcileAuthoritySelectionEvidence('XAUUSD', 'H1', false)['status']);
        $this->assertSame('reconciled', $foundry->reconcileAuthoritySelectionEvidence('XAUUSD', 'H1', true)['status']);
        $this->assertFalse((bool) data_get(json_decode((string) DB::table('edge_genesis_trials')
            ->where('id', $legacyDominatedTrial->id)->value('evidence'), true),
            'authority_selection.nine_fold_replay_admitted'));

        $nineFoldWindows = collect(range(1, 9))->map(fn (int $fold): array => [
            'start' => sprintf('2023-%02d-01', $fold),
            'end' => sprintf('2023-%02d-21', $fold),
            'net_profit_percent' => -.10,
        ])->all();
        foreach (['failure_cell_control', 'buy_direction_gate', 'buy_high_volatility_interaction'] as $authorityArm) {
            $authorityAgent = $factorialByArm[$authorityArm];
            $controlArm = $authorityArm === 'failure_cell_control';
            $authorityPlan = (array) data_get($authorityAgent->modelVersion->metadata,
                'edge_genesis.frozen_window_plan');
            $contextAxes = (array) data_get($authorityAgent->modelVersion->metadata,
                'edge_genesis.context.admission_axes', []);
            $armWindows = collect($nineFoldWindows)->map(function (array $window, int $index) use ($controlArm): array {
                $window['net_profit_percent'] = $controlArm ? -.10 : ($index < 6 ? .20 : -.20);

                return $window;
            })->all();
            $authorityMetrics = [
                'data_hash' => $data, 'execution_hash' => $execution,
                'mtf_snapshot_manifest' => ['bundle_hash' => $bundleHash],
                'after_cost_expectancy_r' => $controlArm ? -.03 : .40,
                'profit_factor' => $controlArm ? .70 : 2.0,
                'pf_lower_confidence_bound' => $controlArm ? .70 : 1.10,
                'net_profit_percent' => $controlArm ? -.90 : 2.0,
                'total_trades' => $controlArm ? 35 : 20,
                'forward_window_protocol' => ['observed_windows' => 9, 'powered_windows' => 9,
                    'positive_windows' => $controlArm ? 2 : 5, 'independence_verified' => true,
                    'overlap_detected' => false, 'windows' => $armWindows],
                'edge_genesis_replay' => ['window_plan_hash' => $authorityPlan['window_plan_hash'],
                    'fold_count' => 9, 'fold_offset' => 5, 'fold_universe_count' => 14],
                'edge_observability' => [
                    'opportunity_detected' => ['observed' => true, 'count' => 80],
                    'setup_location_valid' => ['observed' => true, 'setup_count' => 30],
                    'context_bias_aligned' => ['observed' => true, 'count' => 80],
                    'confirmation' => ['observed' => true, 'count' => 25],
                    'entry' => ['observed' => true, 'count' => $controlArm ? 35 : 20],
                    'execution_price' => ['observed' => true, 'closed_trade_count' => $controlArm ? 35 : 20],
                    'invalidation_price' => ['observed' => true, 'count' => $controlArm ? 35 : 20],
                    'mfe_mae' => ['observed' => true, 'observed_trade_count' => $controlArm ? 35 : 20],
                    'exit_outcome' => ['observed' => true, 'closed_trade_count' => $controlArm ? 35 : 20],
                ],
                'behavior_delta_observed' => true, 'context_declared_before_replay' => true,
                'context_occurrences' => 80,
                'edge_context_enforcement' => ['protocol' => 'edge_context_authority_firewall_v1',
                    'status' => 'enforced', 'enforced' => true, 'admission_axes' => $contextAxes,
                    'outside_scope_action' => 'WAIT',
                    'observed_signals' => 80, 'matched_signals' => $controlArm ? 35 : 20,
                    'rejected_signals' => $controlArm ? 45 : 60,
                    'fold_telemetry_complete' => true, 'fold_contract_identity_consistent' => true,
                    'trade_admission_consistent' => true],
                'risk_governor_compliant' => true, 'forbidden_risk_bypass' => false,
            ];
            $authorityAgent->modelVersion->marketPerformances()->where('symbol', 'XAUUSD')
                ->where('timeframe', 'H1')->firstOrFail()->update([
                    'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => $authorityAgent->strategy_family,
                    'status' => 'rejected', 'fitness' => 0, 'forward_score' => 0,
                    'sample_count' => $controlArm ? 35 : 20, 'rolling_windows_count' => 9,
                    'rolling_forward_wins' => $controlArm ? 2 : 5,
                    'metrics' => $authorityMetrics, 'evidence_status' => 'valid',
                ]);
            $foundry->settleOutcome($authorityAgent->fresh('modelVersion'), $authorityMetrics);
        }
        $this->assertSame('edge_progressing', DB::table('edge_genesis_trials')
            ->where('lab_agent_id', $factorialByArm['buy_direction_gate']->id)->value('status'));
        $this->assertSame('edge_not_confirmed', DB::table('edge_genesis_trials')
            ->where('lab_agent_id', $factorialByArm['buy_high_volatility_interaction']->id)->value('status'));
        $selectedAuthority = json_decode((string) DB::table('edge_genesis_trials')
            ->where('lab_agent_id', $factorialByArm['buy_direction_gate']->id)->value('evidence'), true);
        $this->assertSame('causal_edge_selected', data_get($selectedAuthority,
            'nine_fold_causal_authority.decision'));
        $this->assertSame(6, data_get($selectedAuthority,
            'nine_fold_causal_authority.paired_window_effect.positive_windows'));
        $this->assertSame('EDGE_ATTRIBUTION', data_get($factorialByArm['buy_direction_gate']
            ->fresh('modelVersion')->modelVersion->metadata, 'edge_genesis.phase'));
        $factorialPassportEvidence = json_decode((string) DB::table('edge_genesis_passports')
            ->where('lab_generation_id', $factorial['generation_id'])->value('evidence'), true);
        $this->assertSame('nine_fold_differential_authority_v1', data_get($factorialPassportEvidence,
            'nine_fold_causal_authority.protocol'));

        // A strong differential that still lacks absolute temporal authority
        // becomes a professional coverage curriculum, never a parent. This
        // exercises the real G149 branch after validating the winner branch.
        $interactionTrial = DB::table('edge_genesis_trials')
            ->where('lab_agent_id', $factorialByArm['buy_high_volatility_interaction']->id)->first();
        $interactionEvidence = json_decode((string) $interactionTrial->evidence, true);
        data_set($interactionEvidence, 'nine_fold_causal_authority.decision', 'causal_edge_not_confirmed');
        data_set($interactionEvidence, 'nine_fold_causal_authority.absolute_edge_reconfirmed', false);
        data_set($interactionEvidence, 'nine_fold_causal_authority.expectancy_delta_r', 1.1);
        data_set($interactionEvidence, 'nine_fold_causal_authority.paired_window_effect.comparable_windows', 9);
        data_set($interactionEvidence, 'nine_fold_causal_authority.paired_window_effect.positive_windows', 7);
        data_set($interactionEvidence, 'nine_fold_causal_authority.paired_window_effect.negative_windows', 0);
        data_set($interactionEvidence, 'nine_fold_causal_authority.edge_attribution_authority', false);
        DB::table('edge_genesis_trials')->where('id', $interactionTrial->id)->update([
            'status' => 'edge_not_confirmed', 'evidence' => json_encode($interactionEvidence), 'updated_at' => now(),
        ]);
        DB::table('edge_genesis_trials')->where('lab_agent_id', $factorialByArm['buy_direction_gate']->id)
            ->update(['status' => 'edge_not_confirmed', 'updated_at' => now()]);
        foreach (['buy_direction_gate', 'buy_high_volatility_interaction'] as $arm) {
            $model = $factorialByArm[$arm]->modelVersion;
            $metadata = (array) $model->metadata;
            data_set($metadata, 'edge_genesis.phase', 'EDGE_CONFIRMATION');
            $model->update(['metadata' => $metadata]);
        }
        data_set($factorialPassportEvidence, 'nine_fold_causal_authority.selected_treatment_trial_id', null);
        DB::table('edge_genesis_passports')->where('lab_generation_id', $factorial['generation_id'])->update([
            'phase' => 'EDGE_CONFIRMATION', 'status' => 'edge_not_confirmed',
            'evidence' => json_encode($factorialPassportEvidence), 'updated_at' => now(),
        ]);
        $densificationReadiness = $foundry->architectureRepairReadiness('XAUUSD', 'H1');
        $this->assertTrue($densificationReadiness['admitted'], json_encode($densificationReadiness));
        $this->assertSame(DependencyAwareEdgeGenesisFoundryService::SPECIALIST_DENSIFICATION_REVISION,
            $densificationReadiness['repair_revision']);
        $this->assertFalse($densificationReadiness['parent_authority']);
        $densification = $foundry->materializeNextArchitectureRepair($lab, true);
        $this->assertSame('queued', $densification['status']);
        $this->assertSame(20, $densification['seats']);
        $densificationAgents = LabAgent::query()->with('modelVersion')
            ->where('lab_generation_id', $densification['generation_id'])->where('origin', 'edge_genesis')->get();
        $this->assertEqualsCanonicalizing(DependencyAwareEdgeGenesisFoundryService::SPECIALIST_DENSIFICATION_ARMS,
            $densificationAgents->map(fn (LabAgent $seat): string => (string) data_get($seat->modelVersion->metadata,
                'edge_genesis.arm'))->all());
        $densificationByArm = $densificationAgents->keyBy(fn (LabAgent $seat): string => (string) data_get($seat->modelVersion->metadata, 'edge_genesis.arm'));
        $this->assertTrue($densificationAgents->every(fn (LabAgent $seat): bool => data_get($seat->modelVersion->metadata, 'edge_genesis.context.admission_axes')
                === ['regime', 'direction', 'volatility']
            && data_get($seat->modelVersion->metadata, 'edge_genesis.context.allowed_directions') === ['BUY']
            && data_get($seat->modelVersion->metadata, 'edge_genesis.context.allowed_volatility') === ['high_volatility']));
        $this->assertSame('trend_continuation', data_get($densificationByArm['trend_continuation_topology']
            ->modelVersion->parameters, 'entry_model'));
        $this->assertSame('false_break_reversal', data_get($densificationByArm['false_break_reversal_topology']
            ->modelVersion->parameters, 'entry_model'));
        $this->assertSame(40, data_get($densificationByArm['extended_retest_window']
            ->modelVersion->parameters, 'm5_retest_expiry_minutes'));
        $this->assertSame(.35, data_get($densificationByArm['lower_displacement_gate']
            ->modelVersion->parameters, 'm5_minimum_displacement_atr'));

        // Settle the exact production-shaped G151 observation: the control
        // is economically positive but sparse, two alternate tactics produce
        // no trades, and the expiry/displacement mutations are behavioral
        // no-ops under aggressive breakout. This must open a temporal role
        // binder, not risk optimization or another duplicate density tweak.
        $densificationOutcomes = [
            'specialist_interaction_control' => [1.345409, 4, 3.398, 1, 1, 41, 13],
            'trend_continuation_topology' => [0.0, 0, 0.0, 0, 0, 0, 0],
            'false_break_reversal_topology' => [0.0, 0, 0.0, 0, 0, 0, 0],
            'extended_retest_window' => [1.345409, 4, 3.398, 1, 1, 41, 13],
            'lower_displacement_gate' => [1.345409, 4, 3.398, 1, 1, 41, 13],
        ];
        foreach ($densificationOutcomes as $arm => [$expectancy, $trades, $profitFactor, $powered, $positive, $setups, $triggers]) {
            $seat = $densificationByArm[$arm];
            $densificationPlan = (array) data_get($seat->modelVersion->metadata,
                'edge_genesis.frozen_window_plan');
            $result = [
                'data_hash' => $data, 'execution_hash' => $execution,
                'mtf_snapshot_manifest' => ['bundle_hash' => $bundleHash],
                'after_cost_expectancy_r' => $expectancy, 'profit_factor' => $profitFactor,
                'net_profit_percent' => $expectancy, 'total_trades' => $trades,
                'forward_window_protocol' => ['observed_windows' => 2, 'powered_windows' => $powered,
                    'positive_windows' => $positive, 'independence_verified' => true,
                    'overlap_detected' => false, 'windows' => array_slice($windows, 0, 2)],
                'edge_genesis_replay' => ['window_plan_hash' => $densificationPlan['window_plan_hash'],
                    'fold_count' => 2, 'fold_offset' => 0, 'fold_universe_count' => 14],
                'entry_contract_funnel' => ['stage_counts' => [
                    'setup' => $setups, 'trigger' => $triggers, 'entry_ready' => $triggers,
                ]],
                'edge_observability' => [
                    'opportunity_detected' => ['observed' => true, 'count' => 23],
                    'setup_location_valid' => ['observed' => true, 'setup_count' => $setups],
                    'context_bias_aligned' => ['observed' => true, 'count' => 23],
                    'confirmation' => ['observed' => true, 'count' => $setups],
                    'entry' => ['observed' => true, 'count' => $trades],
                    'execution_price' => ['observed' => true, 'closed_trade_count' => $trades],
                    'invalidation_price' => ['observed' => true, 'count' => $trades],
                    'mfe_mae' => ['observed' => true, 'observed_trade_count' => $trades],
                    'exit_outcome' => ['observed' => true, 'closed_trade_count' => $trades],
                ],
                'behavior_delta_observed' => $setups !== $trades,
                'context_declared_before_replay' => true, 'context_occurrences' => 23,
                'edge_context_enforcement' => [
                    'protocol' => 'edge_context_authority_firewall_v1',
                    'status' => 'enforced', 'enforced' => true,
                    'admission_axes' => ['regime', 'direction', 'volatility'],
                    'outside_scope_action' => 'WAIT',
                ],
                'risk_governor_compliant' => true, 'forbidden_risk_bypass' => false,
            ];
            $seat->modelVersion->marketPerformances()->create([
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => $seat->strategy_family,
                'status' => 'screened', 'fitness' => $expectancy, 'forward_score' => $expectancy,
                'sample_count' => $trades, 'rolling_windows_count' => 2,
                'rolling_forward_wins' => $positive, 'metrics' => $result, 'evidence_status' => 'valid',
            ]);
            $seat->update(['lifecycle_status' => 'screened']);
            $foundry->settleOutcome($seat->fresh('modelVersion'), $result);
        }
        $this->assertSame('controls_settled', $foundry
            ->materializeIndependentReplication('XAUUSD', 'H1', true)['status']);

        $temporalReadiness = $foundry->architectureRepairReadiness('XAUUSD', 'H1');
        $this->assertTrue($temporalReadiness['admitted'], json_encode($temporalReadiness));
        $this->assertSame(DependencyAwareEdgeGenesisFoundryService::TEMPORAL_BREAKOUT_BINDING_REVISION,
            $temporalReadiness['repair_revision']);
        $this->assertSame(['bias' => 'H1', 'candidate_setup' => ['H1', 'M15'], 'trigger' => 'M5', 'execution' => 'M5'],
            $temporalReadiness['temporal_roles']);
        $temporal = $foundry->materializeNextArchitectureRepair($lab, true);
        $this->assertSame('queued', $temporal['status']);
        $this->assertSame(20, $temporal['seats']);
        $temporalAgents = LabAgent::query()->with('modelVersion')
            ->where('lab_generation_id', $temporal['generation_id'])->where('origin', 'edge_genesis')->get();
        $this->assertEqualsCanonicalizing(DependencyAwareEdgeGenesisFoundryService::TEMPORAL_BREAKOUT_BINDING_ARMS,
            $temporalAgents->map(fn (LabAgent $seat): string => (string) data_get($seat->modelVersion->metadata,
                'edge_genesis.arm'))->all());
        $temporalByArm = $temporalAgents->keyBy(fn (LabAgent $seat): string => (string) data_get($seat->modelVersion->metadata, 'edge_genesis.arm'));
        $this->assertSame('H1', data_get($temporalByArm['h1_breakout_control']->modelVersion->parameters,
            'breakout_setup_timeframe'));
        $this->assertSame('M15', data_get($temporalByArm['m15_setup_breakout']->modelVersion->parameters,
            'breakout_setup_timeframe'));
        $this->assertSame(20, data_get($temporalByArm['short_structure_horizon']->modelVersion->parameters,
            'swing_lookback'));
        $this->assertSame(60, data_get($temporalByArm['long_structure_horizon']->modelVersion->parameters,
            'swing_lookback'));
        $this->assertSame('balanced', data_get($temporalByArm['balanced_retest_confirmation']->modelVersion->parameters,
            'entry_mode'));
        $this->assertTrue($temporalAgents->every(fn (LabAgent $seat): bool => data_get($seat->modelVersion->metadata, 'edge_genesis.context.temporal_role_experiment') === true));

        // M15 owns more opportunity coverage in the real-shaped observation,
        // but aggressive/minimum-one confirmation turns that activity into a
        // losing cluster. Distill the non-parent M15 result into a causal
        // baseline and open a confirmation-quality curriculum around it.
        $temporalOutcomes = [
            'h1_breakout_control' => [1.345409, 4, 3.398, 1.345409, 1],
            'm15_setup_breakout' => [-.1, 6, .454, -1.0391, 0],
            'short_structure_horizon' => [1.241167, 3, 2.383, 1.241167, 1],
            'long_structure_horizon' => [1.010908, 4, 2.579, 1.010908, 1],
            'balanced_retest_confirmation' => [-1.0, 3, 0.0, -3.0, 0],
        ];
        foreach ($temporalOutcomes as $arm => [$expectancy, $trades, $profitFactor, $net, $positive]) {
            $seat = $temporalByArm[$arm];
            $temporalPlan = (array) data_get($seat->modelVersion->metadata,
                'edge_genesis.frozen_window_plan');
            $result = [
                'data_hash' => $data, 'execution_hash' => $execution,
                'mtf_snapshot_manifest' => ['bundle_hash' => $bundleHash],
                'after_cost_expectancy_r' => $expectancy, 'profit_factor' => $profitFactor,
                'net_profit_percent' => $net, 'total_trades' => $trades,
                'forward_window_protocol' => ['observed_windows' => 2, 'powered_windows' => 1,
                    'positive_windows' => $positive, 'independence_verified' => true,
                    'overlap_detected' => false, 'windows' => array_slice($windows, 0, 2)],
                'edge_genesis_replay' => ['window_plan_hash' => $temporalPlan['window_plan_hash'],
                    'fold_count' => 2, 'fold_offset' => 0, 'fold_universe_count' => 14],
                'entry_contract_funnel' => [
                    'stage_counts' => ['setup' => 79, 'trigger' => 30, 'entry_ready' => 30],
                    'trigger_topology' => ['counterfactual_mode_counts' => [
                        'aggressive' => 30, 'balanced' => 8, 'conservative' => 15,
                    ]],
                ],
                'edge_observability' => [
                    'opportunity_detected' => ['observed' => true, 'count' => 62],
                    'setup_location_valid' => ['observed' => true, 'setup_count' => 79],
                    'context_bias_aligned' => ['observed' => true, 'count' => 62],
                    'confirmation' => ['observed' => true, 'count' => 79],
                    'entry' => ['observed' => true, 'count' => $trades],
                    'execution_price' => ['observed' => true, 'closed_trade_count' => $trades],
                    'invalidation_price' => ['observed' => true, 'count' => $trades],
                    'mfe_mae' => ['observed' => true, 'observed_trade_count' => $trades],
                    'exit_outcome' => ['observed' => true, 'closed_trade_count' => $trades],
                ],
                'behavior_delta_observed' => true,
                'context_declared_before_replay' => true, 'context_occurrences' => 62,
                'edge_context_enforcement' => [
                    'protocol' => 'edge_context_authority_firewall_v1',
                    'status' => 'enforced', 'enforced' => true,
                    'admission_axes' => ['regime', 'direction', 'volatility'],
                    'outside_scope_action' => 'WAIT',
                ],
                'risk_governor_compliant' => true, 'forbidden_risk_bypass' => false,
            ];
            $seat->modelVersion->marketPerformances()->create([
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => $seat->strategy_family,
                'status' => 'screened', 'fitness' => $expectancy, 'forward_score' => $expectancy,
                'sample_count' => $trades, 'rolling_windows_count' => 2,
                'rolling_forward_wins' => $positive, 'metrics' => $result, 'evidence_status' => 'valid',
            ]);
            $seat->update(['lifecycle_status' => 'screened']);
            $foundry->settleOutcome($seat->fresh('modelVersion'), $result);
        }
        $this->assertSame('controls_settled', $foundry
            ->materializeIndependentReplication('XAUUSD', 'H1', true)['status']);

        $qualityReadiness = $foundry->architectureRepairReadiness('XAUUSD', 'H1');
        $this->assertTrue($qualityReadiness['admitted'], json_encode($qualityReadiness));
        $this->assertSame(DependencyAwareEdgeGenesisFoundryService::M15_SETUP_QUALITY_REVISION,
            $qualityReadiness['repair_revision']);
        $this->assertSame(['bias' => 'H1', 'setup' => 'M15', 'trigger' => 'M5', 'execution' => 'M5'],
            $qualityReadiness['temporal_roles']);
        $this->assertFalse($qualityReadiness['parent_authority']);
        $quality = $foundry->materializeNextArchitectureRepair($lab, true);
        $this->assertSame('queued', $quality['status']);
        $qualityAgents = LabAgent::query()->with('modelVersion')
            ->where('lab_generation_id', $quality['generation_id'])->where('origin', 'edge_genesis')->get();
        $this->assertEqualsCanonicalizing(DependencyAwareEdgeGenesisFoundryService::M15_SETUP_QUALITY_ARMS,
            $qualityAgents->map(fn (LabAgent $seat): string => (string) data_get($seat->modelVersion->metadata,
                'edge_genesis.arm'))->all());
        $qualityByArm = $qualityAgents->keyBy(fn (LabAgent $seat): string => (string) data_get($seat->modelVersion->metadata, 'edge_genesis.arm'));
        $this->assertSame('balanced', data_get($qualityByArm['m15_balanced_confirmation']->modelVersion->parameters,
            'entry_mode'));
        $this->assertSame('conservative', data_get($qualityByArm['m15_conservative_confirmation']->modelVersion->parameters,
            'entry_mode'));
        $this->assertSame(2, data_get($qualityByArm['m15_two_family_confirmation']->modelVersion->parameters,
            'minimum_independent_confirmations'));
        $this->assertSame(3, data_get($qualityByArm['m15_three_family_confirmation']->modelVersion->parameters,
            'minimum_independent_confirmations'));
        $this->assertTrue($qualityAgents->every(fn (LabAgent $seat): bool => data_get($seat->modelVersion->parameters, 'breakout_setup_timeframe') === 'M15'
            && data_get($seat->modelVersion->metadata, 'edge_genesis.context.m15_setup_quality_experiment') === true));

        // Three sparse but profitable conservative entries are not edge
        // authority. They are, however, enough information to justify the
        // already-planned paired nine-fold experiment instead of discarding
        // a potentially valuable confirmation policy at the cheap screen.
        $qualityDiscoveryResults = [];
        foreach ([
            'm15_aggressive_control' => [.10, 6, .60],
            'm15_conservative_confirmation' => [1.50, 3, 4.50],
        ] as $arm => [$expectancy, $trades, $net]) {
            $seat = $qualityByArm[$arm];
            $qualityPlan = (array) data_get($seat->modelVersion->metadata,
                'edge_genesis.frozen_window_plan');
            $result = [
                'data_hash' => $data, 'execution_hash' => $execution,
                'mtf_snapshot_manifest' => ['bundle_hash' => $bundleHash],
                'after_cost_expectancy_r' => $expectancy,
                'net_profit_percent' => $net, 'total_trades' => $trades,
                'rolling_windows_count' => 2,
                'forward_window_protocol' => ['observed_windows' => 2, 'powered_windows' => 1,
                    'positive_windows' => 1, 'independence_verified' => true,
                    'overlap_detected' => false, 'windows' => array_slice($windows, 0, 2)],
                'edge_genesis_replay' => ['window_plan_hash' => $qualityPlan['window_plan_hash'],
                    'fold_count' => 2, 'fold_offset' => 0, 'fold_universe_count' => 14],
                'edge_observability' => [
                    'opportunity_detected' => ['observed' => true, 'count' => 20],
                    'setup_location_valid' => ['observed' => true, 'setup_count' => 12],
                    'context_bias_aligned' => ['observed' => true, 'count' => 12],
                    'confirmation' => ['observed' => true, 'count' => 8],
                    'entry' => ['observed' => true, 'count' => $trades],
                    'execution_price' => ['observed' => true, 'closed_trade_count' => $trades],
                    'invalidation_price' => ['observed' => true, 'count' => $trades],
                    'mfe_mae' => ['observed' => true, 'observed_trade_count' => $trades],
                    'exit_outcome' => ['observed' => true, 'closed_trade_count' => $trades],
                ],
                'behavior_delta_observed' => true,
                'context_declared_before_replay' => true, 'context_occurrences' => 20,
                'edge_context_enforcement' => [
                    'protocol' => 'edge_context_authority_firewall_v1',
                    'status' => 'enforced', 'enforced' => true,
                    'admission_axes' => ['regime', 'direction', 'volatility'],
                    'outside_scope_action' => 'WAIT',
                ],
                'risk_governor_compliant' => true, 'forbidden_risk_bypass' => false,
            ];
            $qualityDiscoveryResults[$arm] = $result;
            $seat->modelVersion->marketPerformances()->create([
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => $seat->strategy_family,
                'status' => 'screened', 'fitness' => $expectancy, 'forward_score' => $expectancy,
                'sample_count' => $trades, 'rolling_windows_count' => 2,
                'rolling_forward_wins' => 1, 'metrics' => $result, 'evidence_status' => 'valid',
            ]);
            $seat->update(['lifecycle_status' => 'screened']);
            $verdict = $foundry->settleOutcome($seat->fresh('modelVersion'), $result);
            $this->assertTrue($verdict['discovery_admission']['specialist_power_adjustment'], $arm);
            $this->assertSame(3, $verdict['discovery_admission']['specialist_minimum_trades'], $arm);
        }
        $qualityControl = $qualityByArm['m15_aggressive_control'];
        $qualityCandidate = $qualityByArm['m15_conservative_confirmation'];
        LabEvaluationRun::create([
            'run_id' => (string) Str::uuid(), 'lab_generation_id' => $qualityControl->lab_generation_id,
            'lab_agent_id' => $qualityControl->id, 'model_version_id' => $qualityControl->model_version_id,
            'phase' => 'full_validation', 'mode' => 'full', 'status' => 'completed',
            'metrics' => ['agent_result' => $qualityDiscoveryResults['m15_aggressive_control']],
        ]);
        // Simulate the real projection after the control has already moved to
        // nine folds: its immutable discovery run must remain retrievable.
        $qualityControl->modelVersion->marketPerformances()->update(['rolling_windows_count' => 9]);
        $candidateTrial = DB::table('edge_genesis_trials')->where('lab_agent_id', $qualityCandidate->id)->first();
        $candidateEvidence = json_decode((string) $candidateTrial->evidence, true);
        $candidateEvidence['authority_selection'] = [
            'protocol' => 'paired_discovery_authority_budget_v1',
            'decision' => 'discovery_dominated',
            'reason' => 'PAIRED_DISCOVERY_WINDOW_IDENTITY_MISMATCH',
            'nine_fold_replay_admitted' => false, 'promotion_evidence' => false,
        ];
        DB::table('edge_genesis_trials')->where('id', $candidateTrial->id)->update([
            'status' => 'edge_not_found', 'evidence' => json_encode($candidateEvidence),
        ]);
        $qualityRecovery = $foundry->reconcileAuthoritySelectionEvidence('XAUUSD', 'H1', true);
        $this->assertSame(1, data_get($qualityRecovery,
            'repairs.immutable_discovery_control_recovery'));
        $this->assertSame('edge_progressing', DB::table('edge_genesis_trials')
            ->where('id', $candidateTrial->id)->value('status'));
        $qualityReplication = $foundry->materializeIndependentReplication('XAUUSD', 'H1', true);
        $this->assertSame('queued', $qualityReplication['status']);
        $this->assertSame(2, $qualityReplication['seats']);
        $qualityReplicationWindows = collect(range(1, 3))->map(fn (int $fold): array => [
            'start' => sprintf('2022-%02d-01', $fold), 'end' => sprintf('2022-%02d-16', $fold),
            'net_profit_percent' => .30,
        ])->all();
        foreach (['m15_aggressive_control' => $qualityControl, 'm15_conservative_confirmation' => $qualityCandidate] as $arm => $seat) {
            $plan = (array) data_get($seat->modelVersion->metadata, 'edge_genesis.frozen_window_plan');
            $replicationMetrics = [...$qualityDiscoveryResults[$arm],
                'rolling_windows_count' => 3,
                'forward_window_protocol' => ['observed_windows' => 3, 'powered_windows' => 3,
                    'positive_windows' => 3, 'independence_verified' => true, 'overlap_detected' => false,
                    'windows' => $arm === 'm15_aggressive_control'
                        ? collect($qualityReplicationWindows)->map(fn (array $window): array => [...$window, 'net_profit_percent' => .05])->all()
                        : $qualityReplicationWindows],
                'edge_genesis_replay' => ['window_plan_hash' => $plan['window_plan_hash'],
                    'fold_count' => 3, 'fold_offset' => 2, 'fold_universe_count' => 14],
            ];
            $seat->modelVersion->marketPerformances()->update([
                'rolling_windows_count' => 3, 'metrics' => $replicationMetrics]);
            $qualityReplicationResults[$arm] = $replicationMetrics;
        }
        $this->assertSame('replication_control_settled', $foundry->settleOutcome(
            $qualityControl->fresh('modelVersion'), $qualityReplicationResults['m15_aggressive_control']
        )['status']);
        $this->assertSame('edge_replication_passed', $foundry->settleOutcome(
            $qualityCandidate->fresh('modelVersion'), $qualityReplicationResults['m15_conservative_confirmation']
        )['status']);
        LabAgent::query()->whereIn('id', [$qualityControl->id, $qualityCandidate->id])
            ->update(['lifecycle_status' => 'rejected']);
        $qualityConfirmation = $foundry->materializeConfirmation('XAUUSD', 'H1', true);
        $this->assertSame('queued', $qualityConfirmation['status']);
        $this->assertSame(2, $qualityConfirmation['seats']);
        $this->assertSame(9, $qualityConfirmation['folds']);
    }

    /** @return array{data_hash:string,snapshots:array<string,mixed>} */
    private function canonicalCoverageFixture(): array
    {
        $directory = storage_path('framework/testing/edge-coverage-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));
        $foundationPath = $directory.'/foundation.csv';
        $paperPath = $directory.'/paper.csv';
        File::put($foundationPath, 'pre-2026-foundation');
        File::put($paperPath, '2026-paper-only');
        $dataHash = (string) hash_file('sha256', $foundationPath);

        return ['data_hash' => $dataHash, 'snapshots' => [
            'foundation' => ['path' => $foundationPath, 'sha256' => $dataHash, 'manifest' => [
                'source_role' => 'foundation_training_only', 'promotion_evidence' => false,
                'continuity' => ['status' => 'ready', 'unexpected_gap_count' => 0],
            ]],
            'price' => ['path' => $paperPath, 'sha256' => hash_file('sha256', $paperPath), 'manifest' => [
                'data_role' => 'paper_only', 'training_end_exclusive' => '2026-01-01T00:00:00+00:00',
                'promotion_evidence' => false,
            ]],
        ]];
    }
}
