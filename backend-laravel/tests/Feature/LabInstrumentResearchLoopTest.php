<?php

namespace Tests\Feature;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AiLaboratory;
use App\Models\InstrumentInvocationLedger;
use App\Models\InstrumentValuePosterior;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\ModelVersion;
use App\Models\PlaybookComposition;
use App\Models\PlaybookValuePosterior;
use App\Models\TradingInstrument;
use App\Services\CausalLearningCohortPlannerService;
use App\Services\InstrumentInvocationLedgerService;
use App\Services\InstrumentResearchWindowService;
use App\Services\LabInstrumentResearchService;
use App\Services\LearningLaneService;
use App\Services\StrategyParameterSchemaService;
use App\Services\TradingInstrumentOperatingSystemService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Support\InstrumentValidationFixture;

class LabInstrumentResearchLoopTest extends TestCase
{
    use RefreshDatabase;
    use InstrumentValidationFixture;

    public function test_ordinary_frozen_control_observes_the_exact_candidate_instrument_surface(): void
    {
        [$candidate, $control] = $this->pairAgents();
        $service = app(LabInstrumentResearchService::class);
        $candidateAssignment = $service->assignment($candidate);
        $controlAssignment = $service->assignment($control);
        $this->assertSame($candidateAssignment['sealed_treatment_gene'], $controlAssignment['sealed_treatment_gene']);
        $this->assertSame('volume_lane', $controlAssignment['sealed_treatment_gene']);
        $this->assertSame($candidateAssignment['selected_keys'], $controlAssignment['selected_keys']);
        $this->assertSame($candidateAssignment['instrument_key_role_hash'], $controlAssignment['instrument_key_role_hash']);
        $this->assertSame($candidateAssignment['activation_context_hash'], $controlAssignment['activation_context_hash']);
        $this->assertSame('frozen_control', $controlAssignment['experiment_role']);
        $this->assertNull($controlAssignment['changed_gene']);
        $this->assertFalse($controlAssignment['promotion_evidence']);
    }

    public function test_transition_wait_control_does_not_silently_switch_to_a_regime_router(): void
    {
        [$candidate, $control] = $this->pairAgents();
        $base = (array) $control->modelVersion->parameters; $base['transition_wait_candles'] = 4;
        $control->modelVersion->update(['parameters' => $base]);
        $candidate->modelVersion->update(['parameters' => [...$base, 'transition_wait_candles' => 5]]);
        $candidate->update(['parameter_diff' => ['transition_wait_candles' => ['old' => 4, 'new' => 5]]]);
        $service = app(LabInstrumentResearchService::class);
        $c = $service->assignment($candidate->fresh('modelVersion'));
        $f = $service->assignment($control->fresh('modelVersion'));
        $this->assertSame('transition_wait_candles', $f['sealed_treatment_gene']);
        $this->assertSame($c['instrument_key_role_hash'], $f['instrument_key_role_hash']);
        $this->assertSame($c['activation_context_hash'], $f['activation_context_hash']);
        $this->assertSame($c['selected_keys'], $f['selected_keys']);
    }

    public function test_missing_control_treatment_owner_blocks_assignment_instead_of_inventing_a_surface(): void
    {
        [$candidate, $control] = $this->pairAgents();
        $candidate->delete();
        $assignment = app(LabInstrumentResearchService::class)->assignment($control->fresh('modelVersion'));
        $this->assertSame('missing', data_get($assignment, 'pair_reservation.status'));
        $this->assertStringStartsWith('blocked_', $assignment['status']);
        $this->assertFalse($assignment['promotion_evidence']);
    }

    public function test_assignment_is_sealed_and_only_runtime_attestation_opens_invocations(): void
    {
        [$candidate] = $this->pairAgents();
        $assignment = app(LabInstrumentResearchService::class)->assignment($candidate);

        $this->assertSame('assigned', $assignment['status']);
        $this->assertContains('volume_confirmation', $assignment['selected_keys']);
        $this->assertTrue((bool) collect($assignment['selected'])->firstWhere('instrument_key', 'volume_confirmation')['causal_candidate']);
        $this->assertSame('reserved', data_get($assignment, 'pair_reservation.status'));
        $this->assertTrue((bool) data_get($assignment, 'pair_reservation.exact_parameter_baseline'));
        $this->assertSame(
            LabInstrumentResearchService::ACTIVATION_PROTOCOL,
            data_get($assignment, 'activation_policy.protocol'),
        );
        $this->assertTrue((bool) data_get($assignment, 'activation_policy.selection_is_not_invocation'));
        $this->assertSame(
            LabInstrumentResearchService::DECISION_DOCTRINE_PROTOCOL,
            data_get($assignment, 'decision_doctrine.protocol'),
        );
        $this->assertSame(1, data_get($assignment, 'decision_doctrine.causal_candidate_limit'));
        $this->assertSame(
            'changed_gene_causal_surface',
            data_get(collect($assignment['selected'])->firstWhere('instrument_key', 'volume_confirmation'), 'selection_reason'),
        );
        $this->assertTrue(collect($assignment['selected'])->every(
            fn (array $selected): bool => data_get($selected, 'activation_contract.protocol') === LabInstrumentResearchService::ACTIVATION_PROTOCOL
                && data_get($selected, 'activation_contract.mode') === 'instrument_specific_runtime_event'
                && data_get($selected, 'activation_contract.aggregate_metric_fallback_allowed') === false
                && (array) data_get($selected, 'activation_contract.required_runtime_events', []) !== [],
        ));
        $this->assertFalse((bool) data_get($assignment, 'bundle_identity.interaction_identified'));
        $this->assertFalse($assignment['promotion_evidence']);
        $this->assertDatabaseCount('instrument_invocation_ledger', 0);

        $result = $this->attestedResult($assignment, 'candidate-screen-run');
        $count = app(InstrumentInvocationLedgerService::class)->recordResearchObservation(
            $candidate->fresh(['modelVersion']),
            $result,
        );

        $this->assertSame(count($assignment['selected']), $count);
        $this->assertDatabaseHas('instrument_invocation_ledger', [
            'lab_agent_id' => $candidate->id,
            'instrument_key' => 'volume_confirmation',
            'used_in_decision' => true,
            'used_in_execution' => false,
            'verdict' => 'awaiting_paired_control',
        ]);
        $this->assertDatabaseCount('instrument_evidence', 0);

        $incomplete = $this->attestedResult($assignment, 'partial-screen-run');
        $incomplete['instrument_research_trace']['status'] = 'incomplete';
        $incomplete['instrument_research_trace']['runtime_bindings_valid'] = false;
        $this->assertSame(0, app(InstrumentInvocationLedgerService::class)->recordResearchObservation(
            $candidate->fresh(['modelVersion']),
            $incomplete,
        ));
        $this->assertSame(count($assignment['selected']), InstrumentInvocationLedger::query()->count());
    }

    public function test_cached_assignment_rebinds_to_current_frozen_component_identity(): void
    {
        [$candidate] = $this->pairAgents();
        $model = $candidate->modelVersion;
        $metadata = (array) $model->metadata;
        $metadata['smart_composition'] = [
            'strategy_library_id' => 'stale-strategy',
            'tactic_library_key' => 'stale-tactic',
            'risk_library_id' => 'stale-risk',
            'composition_passport' => [
                'composition_id' => 'frozen-composition-test',
                'components' => [
                    'strategy_id' => 'str_001_ema_adx_pullback',
                    'tactic_id' => 'trend_pullback',
                    'risk_id' => 'atr_risk_envelope',
                    'management_id' => 'balanced_professional',
                ],
            ],
        ];
        $model->update(['metadata' => $metadata]);
        $service = app(LabInstrumentResearchService::class);
        $first = $service->assignment($candidate->fresh(['modelVersion', 'generation']));
        $this->assertSame('str_001_ema_adx_pullback', data_get($first, 'source_components.strategy_library_id'));
        $this->assertSame('trend_pullback', data_get($first, 'source_components.tactic_library_key'));

        $metadata = (array) $model->fresh()->metadata;
        $metadata['smart_composition']['composition_passport']['components']['tactic_id'] = 'trend_breakout_retest';
        $model->update(['metadata' => $metadata]);
        $second = $service->assignment($candidate->fresh(['modelVersion', 'generation']));

        $this->assertSame('trend_breakout_retest', data_get($second, 'source_components.tactic_library_key'));
        $this->assertNotSame($first['assignment_hash'], $second['assignment_hash']);
    }

    public function test_historical_transition_homework_is_not_a_live_instrument_scope(): void
    {
        [, $control] = $this->pairAgents();
        $model = $control->modelVersion;
        $model->update(['metadata' => [
            ...((array) $model->metadata),
            'semantic_group' => [
                'regime' => 'trend_up', 'volatility' => 'normal_volatility',
            ],
            'portfolio_council_lane' => [
                'regime' => 'trend_up', 'volatility' => 'normal_volatility',
                'transition_state' => 'transition_observed',
            ],
        ]]);

        $assignment = app(LabInstrumentResearchService::class)
            ->assignment($control->fresh(['modelVersion', 'generation']));

        $this->assertSame('assigned', $assignment['status']);
        $this->assertNotEmpty($assignment['selected']);
        foreach ($assignment['selected'] as $instrument) {
            $declared = (array) data_get($instrument, 'activation_contract.context.declared_context', []);
            $this->assertSame('trend_up', $declared['regime'] ?? null);
            $this->assertSame('normal', $declared['volatility'] ?? null);
            $this->assertArrayNotHasKey('transition_state', $declared);
        }
    }

    public function test_one_gene_candidate_without_exact_pair_is_blocked_before_replay(): void
    {
        [$candidate] = $this->pairAgents();
        $model = $candidate->modelVersion;
        $metadata = (array) $model->metadata;
        unset($metadata['control_pair_contract']);
        $model->update(['metadata' => $metadata]);

        $assignment = app(LabInstrumentResearchService::class)->assignment($candidate->fresh(['modelVersion', 'generation']));

        $this->assertSame('blocked_exact_pair_reservation_missing', $assignment['status']);
        $this->assertSame('EXACT_CONTROL_PAIR_CONTRACT_MISSING', data_get($assignment, 'pair_reservation.reason_code'));
        $this->assertSame([], $assignment['selected']);
        $this->assertNull($assignment['playbook_key']);
        $this->assertSame(0, app(InstrumentInvocationLedgerService::class)->recordResearchObservation(
            $candidate->fresh(['modelVersion']),
            $this->attestedResult($assignment, 'must-not-run'),
        ));
        $this->assertDatabaseCount('instrument_invocation_ledger', 0);
    }

    public function test_effectful_runtime_veto_is_recorded_as_research_decision_not_paper_execution(): void
    {
        [$candidate] = $this->pairAgents();
        $assignment = app(LabInstrumentResearchService::class)->assignment($candidate);
        $result = $this->attestedResult($assignment, 'veto-screen-run');
        $result['instrument_research_trace']['status'] = 'decision_observed';
        $result['instrument_research_trace']['bundle_fully_activated'] = false;
        $result['instrument_research_trace']['bundle_activation_context_keys'] = [];
        foreach ($result['instrument_research_trace']['instruments'] as &$runtime) {
            $runtime['status'] = 'evaluated_veto';
            $runtime['decision_path_activated'] = false;
            $runtime['used_in_decision'] = true;
            $runtime['runtime_disposition'] = 'evaluated_veto';
            $runtime['decision_effect_counts'] = ['VETO' => 1];
            $runtime['evaluation_count'] = 1;
            $runtime['veto_count'] = 1;
            $runtime['abstain_count'] = 0;
            $runtime['activated_context_keys'] = [];
            $runtime['out_of_scope_context_keys'] = ['trend_up|normal_volatility|asia|BUY'];
        }
        unset($runtime);

        $count = app(InstrumentInvocationLedgerService::class)->recordResearchObservation(
            $candidate->fresh(['modelVersion']),
            $result,
        );

        $this->assertSame(count($assignment['selected']), $count);
        $this->assertDatabaseHas('instrument_invocation_ledger', [
            'lab_agent_id' => $candidate->id,
            'instrument_key' => 'volume_confirmation',
            'used_in_decision' => true,
            'used_in_execution' => false,
            'verdict' => 'evaluated_veto_research_only',
        ]);
        $row = InstrumentInvocationLedger::query()
            ->where('lab_agent_id', $candidate->id)
            ->where('instrument_key', 'volume_confirmation')
            ->firstOrFail();
        $this->assertSame('VETO', data_get($row->metadata, 'decision_effect'));
        $this->assertFalse((bool) data_get($row->metadata, 'paper_execution_authority'));
        $this->assertFalse((bool) data_get($row->metadata, 'promotion_evidence'));
    }

    public function test_causal_triplet_arm_reserves_its_experiment_control_and_settles_against_the_later_pair(): void
    {
        [$candidate, $control, $generation] = $this->pairAgents();
        $experimentKey = hash('sha256', 'causal-instrument-triplet');
        foreach ([$candidate, $control] as $agent) {
            $model = $agent->modelVersion;
            $metadata = (array) $model->metadata;
            unset($metadata['control_pair_contract']);
            $metadata['causal_learning_cohort'] = [
                'protocol' => CausalLearningCohortPlannerService::PROTOCOL,
                'experiment_key' => $experimentKey,
                'role' => (int) $agent->id === (int) $candidate->id ? 'hypothesis_guided' : 'frozen_control',
                'promotion_evidence' => false,
            ];
            $model->update(['metadata' => $metadata]);
        }
        $experiment = AgentLearningCausalExperiment::create([
            'experiment_key' => $experimentKey,
            'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'instrument_triplet', 'gene_key' => 'volume_lane',
            'guided_agent_id' => $candidate->id, 'control_agent_id' => $control->id,
            'status' => 'ready_for_replay', 'evidence' => [],
        ]);

        $assignment = app(LabInstrumentResearchService::class)->assignment(
            $candidate->fresh(['modelVersion', 'generation'])
        );

        $this->assertSame('assigned', $assignment['status']);
        $this->assertSame('reserved', data_get($assignment, 'pair_reservation.status'));
        $this->assertSame('causal_triplet_instrument_reservation_v1', data_get($assignment, 'pair_reservation.protocol'));
        $this->assertSame($experiment->id, data_get($assignment, 'pair_reservation.causal_experiment_id'));
        $controlAssignment = app(LabInstrumentResearchService::class)->assignment(
            $control->fresh(['modelVersion', 'generation'])
        );
        $this->assertSame('assigned', $controlAssignment['status']);
        $this->assertSame($assignment['selected_keys'], $controlAssignment['selected_keys']);
        $this->assertSame($assignment['instrument_key_role_hash'], $controlAssignment['instrument_key_role_hash']);
        $this->assertSame($assignment['activation_context_hash'], $controlAssignment['activation_context_hash']);
        $this->assertSame('volume_lane', $controlAssignment['sealed_treatment_gene']);
        $this->assertSame('frozen_treatment_surface', data_get(
            collect($controlAssignment['selected'])->firstWhere('instrument_key', 'volume_confirmation'),
            'selection_reason',
        ));

        $ledger = app(InstrumentInvocationLedgerService::class);
        $ledger->recordResearchObservation(
            $candidate->fresh(['modelVersion']),
            $this->attestedResult($assignment, 'causal-candidate-screen-run'),
        );
        $dataHash = str_repeat('a', 64);
        $executionHash = str_repeat('b', 64);
        $controlMap = LabMutationResponseMap::create([
            'response_key' => hash('sha256', 'causal-triplet-control-map'),
            'stage' => 'screening', 'status' => 'control',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'lab_agent_id' => $control->id, 'model_version_id' => $control->model_version_id,
            'evidence_run_id' => 'causal-control-screen-run',
            'observed_metrics' => [],
            'metadata' => ['control_contract' => [
                'protocol' => 'frozen_control_v2', 'control_only' => true, 'role' => 'control',
                'generation_id' => $generation->id, 'data_hash' => $dataHash,
                'execution_hash' => $executionHash,
            ]],
        ]);
        $pair = LabLearningLanePair::create([
            'pair_key' => hash('sha256', 'causal-triplet-later-pair'),
            'lab_generation_id' => $generation->id,
            'candidate_agent_id' => $candidate->id, 'control_agent_id' => $control->id,
            'control_response_map_id' => $controlMap->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'baseline_source' => 'control', 'status' => 'screen_paired',
            'candidate_evidence_run_id' => 'causal-candidate-screen-run',
            'control_evidence_run_id' => 'causal-control-screen-run',
            'candidate_data_hash' => $dataHash, 'control_data_hash' => $dataHash,
            'candidate_execution_hash' => $executionHash, 'control_execution_hash' => $executionHash,
            'pair_integrity_status' => 'verified', 'same_generation' => true,
            'candidate_metrics' => [
                'net_profit_percent' => 3.0, 'profit_factor' => 1.2,
                'max_drawdown_percent' => 5.0, 'total_trades' => 42,
                'instrument_research_trace' => $this->contextTrace(1.2, 4.0, 5.0, 21),
            ],
            'control_metrics' => [
                'net_profit_percent' => 1.0, 'profit_factor' => 1.05,
                'max_drawdown_percent' => 6.0, 'total_trades' => 40,
                'instrument_research_trace' => $this->contextTrace(1.05, 1.0, 6.0, 20),
            ],
            'metadata' => ['promotion_evidence' => false],
        ]);

        $this->assertTrue($pair->isVerifiedControlPair());
        $this->assertSame(1, $ledger->settleResearchPair($pair->fresh()));
        $this->assertDatabaseHas('instrument_invocation_ledger', [
            'lab_agent_id' => $candidate->id,
            'instrument_key' => 'volume_confirmation',
            'verdict' => 'helped',
        ]);
    }

    public function test_venue_phase_attribution_requires_exact_matched_trade_slices(): void
    {
        $service = app(InstrumentInvocationLedgerService::class);
        $pair = new LabLearningLanePair([
            'strategy_family' => 'hybrid', 'independent_window_key' => 'sealed-research-window',
        ]);
        $slice = fn (string $phase): array => [
            'context_key' => "trend_up|normal_volatility|london|{$phase}|BUY",
            'context' => [
                'regime' => 'trend_up', 'volatility' => 'normal_volatility',
                'session' => 'london', 'venue_phase' => $phase, 'direction' => 'BUY',
            ],
            'metrics' => [
                'trades' => 4, 'net_pf' => 1.2, 'net_profit_percent' => 1.0,
                'max_drawdown_percent' => 2.0, 'execution_cost_percent' => .1,
            ],
            'powered' => true,
        ];
        $candidate = ['instrument_research_trace' => [
            'context_source' => 'decision_time_trade_ledger',
            'context_slice_protocol' => 'venue_phase_v1',
            'exact_context_slices' => [$slice('london_am_fix'), $slice('london_interfix')],
        ]];
        $control = $candidate;
        $method = new \ReflectionMethod(InstrumentInvocationLedgerService::class, 'pairedContextOutcomes');
        $outcomes = $method->invoke($service, $pair, $candidate, $control);
        $this->assertCount(2, $outcomes);

        $row = new InstrumentInvocationLedger(['metadata' => [
            'declaration' => ['activation_contract' => ['context' => [
                'declared_context' => ['venue_phase' => 'london_am_fix'],
            ]]],
            'runtime_trace' => ['activated_exact_context_keys' => [
                'trend_up|normal_volatility|london|london_am_fix|BUY',
            ]],
        ]]);
        $active = (new \ReflectionMethod(InstrumentInvocationLedgerService::class, 'activatedOutcomes'))
            ->invoke($service, $row, $outcomes);
        $this->assertCount(1, $active);
        $this->assertSame('london_am_fix', data_get($active[0], 'context.venue_phase'));
        $mismatchedControl = $control;
        $mismatchedControl['instrument_research_trace']['exact_context_slices'][0]['context']['venue_phase'] = 'london_interfix';
        $this->assertCount(1, $method->invoke($service, $pair, $candidate, $mismatchedControl));

        $legacy = ['instrument_research_trace' => [
            'context_source' => 'decision_time_trade_ledger',
            'context_slices' => [[...$slice('london_am_fix'),
                'context_key' => 'trend_up|normal_volatility|london|BUY']],
        ]];
        $legacyOutcomes = $method->invoke($service, $pair, $legacy, $legacy);
        $this->assertSame([], (new \ReflectionMethod(InstrumentInvocationLedgerService::class, 'activatedOutcomes'))
            ->invoke($service, $row, $legacyOutcomes));
        $this->assertSame([], $method->invoke($service, $pair, $candidate, $legacy));
    }

    public function test_inventory_and_parameter_binding_without_runtime_activation_open_no_invocation(): void
    {
        [$candidate] = $this->pairAgents();
        $assignment = app(LabInstrumentResearchService::class)->assignment($candidate);
        $result = $this->attestedResult($assignment, 'inventory-only-run');
        $result['instrument_research_trace']['bundle_fully_activated'] = false;
        $result['instrument_research_trace']['bundle_activation_context_keys'] = [];
        foreach ($result['instrument_research_trace']['instruments'] as &$runtime) {
            $runtime['status'] = 'not_activated';
            $runtime['decision_path_activated'] = false;
            $runtime['activated_context_keys'] = [];
        }
        unset($runtime);

        $count = app(InstrumentInvocationLedgerService::class)->recordResearchObservation(
            $candidate->fresh(['modelVersion']),
            $result,
        );

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('instrument_invocation_ledger', 0);
    }

    public function test_verified_frozen_pair_moves_only_changed_instrument_into_value_block(): void
    {
        $this->travelTo(CarbonImmutable::parse('2028-01-01 00:00:00', 'UTC'));
        config()->set('services.instrument_policy.authorized_research_windows', [[
            'authorization_id' => 'sealed-paired-window',
            'research_epoch_id' => 'test-post-paper-research',
            'start_inclusive' => '2027-01-01T00:00:00+00:00',
            'end_exclusive' => '2027-02-01T00:00:00+00:00',
            'dataset_sha256' => str_repeat('a', 64),
            'purpose' => 'instrument_independent_validation',
        ]]);
        [$candidate, $control, $generation] = $this->pairAgents();
        $assignment = app(LabInstrumentResearchService::class)->assignment($candidate);
        $ledger = app(InstrumentInvocationLedgerService::class);
        foreach ([[$candidate, 'candidate-screen-run'], [$control, 'control-screen-run']] as [$agent, $runKey]) {
            LabEvaluationRun::create(['run_id' => $runKey, 'lab_generation_id' => $generation->id,
                'lab_agent_id' => $agent->id, 'model_version_id' => $agent->model_version_id,
                'phase' => 'screening', 'mode' => 'test', 'attempt' => 1, 'status' => 'completed',
                'code_hash' => hash('sha256', 'instrument-window-evaluator'),
                'request_hash' => hash('sha256', $runKey.'-request'), 'response_hash' => hash('sha256', $runKey.'-response'),
                'parameter_hash' => app(\App\Services\ResearchPaperEpochContractService::class)->parameterHash((array) $agent->modelVersion->parameters),
                'data_hash' => str_repeat('a', 64)]);
        }
        $ledger->recordResearchObservation(
            $candidate->fresh(['modelVersion']),
            $this->attestedResult($assignment, 'candidate-screen-run', true),
        );
        $dataHash = str_repeat('a', 64);
        $executionHash = str_repeat('b', 64);
        $controlMap = LabMutationResponseMap::create([
            'response_key' => str_repeat('c', 64),
            'stage' => 'screening',
            'status' => 'control',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'lab_agent_id' => $control->id,
            'model_version_id' => $control->model_version_id,
            'evidence_run_id' => 'control-screen-run',
            'observed_metrics' => [
                'net_profit_percent' => 1.0,
                'profit_factor' => 1.05,
                'max_drawdown_percent' => 6.0,
                'total_trades' => 40,
            ],
            'metadata' => ['control_contract' => [
                'protocol' => 'frozen_control_v2',
                'control_only' => true,
                'role' => 'control',
                'generation_id' => $generation->id,
                'data_hash' => $dataHash,
                'execution_hash' => $executionHash,
            ]],
        ]);
        $candidateMap = LabMutationResponseMap::create([
            'response_key' => hash('sha256', 'instrument-candidate-screen-map'),
            'stage' => 'screening', 'status' => 'screen_observed',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'target' => 'profit_factor', 'parameter_key' => 'volume_lane',
            'lab_agent_id' => $candidate->id,
            'model_version_id' => $candidate->model_version_id,
            'evidence_run_id' => 'candidate-screen-run',
            'old_value' => ['value' => 'none'],
            'new_value' => ['value' => 'breakout_volume_confirmation'],
            'observed_metrics' => [
                'net_profit_percent' => 3.0,
                'profit_factor' => 1.20,
                'max_drawdown_percent' => 5.0,
                'total_trades' => 42,
                'instrument_research_trace' => $this->contextTrace(1.2, 4.0, 5.0, 21, true),
            ],
            'metadata' => [
                'screening_decision' => 'failed',
                'data_manifest_hash' => $dataHash,
                'execution_hash' => $executionHash,
            ],
        ]);
        $controlMap->update(['observed_metrics' => [
            ...(array) $controlMap->observed_metrics,
            'instrument_research_trace' => $this->contextTrace(1.05, 1.0, 6.0, 20, true),
        ]]);
        $createdPair = app(LearningLaneService::class)->pairScreeningObservation(
            $candidate->fresh(['modelVersion', 'generation']),
            ['evidence_run_id' => 'candidate-screen-run', 'data_manifest' => [
                'data_partition' => ['screening_source' => 'authorized_post_paper_research_validation'],
                'instrument_research_window' => ['authorization_id' => 'sealed-paired-window',
                    'research_epoch_id' => 'test-post-paper-research'],
                'first_candle_at' => '2027-01-01T00:00:00Z',
                'last_candle_at' => '2027-01-31T23:55:00Z',
            ]],
            $candidateMap->toArray(),
        );
        $this->assertNotNull($createdPair);
        $pair = LabLearningLanePair::query()->findOrFail($createdPair['id']);
        $this->assertTrue($pair->isVerifiedControlPair());
        $this->assertSame(
            $pair->independent_window_key,
            data_get($pair->metadata, 'instrument_research_window_receipt.window_key'),
        );
        $this->assertNotSame(
            data_get($assignment, 'pair_reservation.pair_key'),
            $pair->pair_key,
            'A learning observation has a distinct key from the constructor reservation.',
        );

        // A real learning-lane pair must still belong to the exact frozen
        // candidate and to the assignment attested by the replay receipt.
        $candidateMap->update(['lab_agent_id' => $control->id]);
        $this->assertSame(0, $ledger->settleResearchPair($pair->fresh()));
        $candidateMap->update(['lab_agent_id' => $candidate->id]);
        $invocation = InstrumentInvocationLedger::query()
            ->where('lab_agent_id', $candidate->id)
            ->where('instrument_key', 'volume_confirmation')
            ->firstOrFail();
        $originalMetadata = (array) $invocation->metadata;
        $invocation->update(['metadata' => [...$originalMetadata, 'assignment_hash' => str_repeat('f', 64)]]);
        $this->assertSame(0, $ledger->settleResearchPair($pair->fresh()));
        $this->assertFalse((bool) data_get($invocation->fresh()->metadata, 'paired_control_rejection.attested_assignment_valid'));
        $this->assertDatabaseCount('instrument_value_posteriors', 0);
        $invocation->update(['metadata' => $originalMetadata]);

        $candidateMetrics = (array) $pair->candidate_metrics;
        $controlMetrics = (array) $pair->control_metrics;
        $pair->update([
            'candidate_metrics' => array_diff_key($candidateMetrics, ['instrument_research_trace' => true]),
            'control_metrics' => array_diff_key($controlMetrics, ['instrument_research_trace' => true]),
        ]);
        $this->assertSame(0, $ledger->settleResearchPair($pair->fresh()));
        $this->assertDatabaseCount('instrument_evidence', 0);
        $this->assertDatabaseCount('instrument_value_posteriors', 0);
        $pair->update([
            'candidate_metrics' => $candidateMetrics,
            'control_metrics' => $controlMetrics,
        ]);

        $this->assertSame(1, $ledger->settleResearchPair($pair->fresh()));
        $this->assertDatabaseHas('instrument_invocation_ledger', [
            'lab_agent_id' => $candidate->id,
            'instrument_key' => 'volume_confirmation',
            'verdict' => 'helped',
        ]);
        $this->assertSame(
            'activated_contexts_only',
            data_get(InstrumentInvocationLedger::query()->where('instrument_key', 'volume_confirmation')->firstOrFail()->control_delta, 'scope'),
        );
        $this->assertSame(2, InstrumentInvocationLedger::query()->where('verdict', 'support_consumed')->count());
        $this->assertDatabaseCount('instrument_evidence', 1);
        $this->assertSame(1, InstrumentValuePosterior::query()->count());
        $this->assertCount(1, (array) data_get(InstrumentValuePosterior::query()->firstOrFail()->value_vector, 'window_evidence'));
        $epochs = (array) data_get(InstrumentValuePosterior::query()->firstOrFail()->value_vector, 'validation_epochs');
        $this->assertCount(1, $epochs);
        $epoch = array_values($epochs)[0];
        $this->assertSame('volume_lane', $epoch['tested_intervention']['gene']);
        $this->assertSame('none', $epoch['tested_intervention']['old']);
        $this->assertSame('breakout_volume_confirmation', $epoch['tested_intervention']['new']);
        $this->assertSame($pair->pair_key, data_get($epoch, 'window_evidence.0.source_receipt.pair_key'));
        $this->assertSame('provisional', InstrumentValuePosterior::query()->value('decay_state'));
        $this->assertSame(
            'trend_up|london|normal|unknown|stable|0|buy|hybrid|london_am_fix',
            InstrumentValuePosterior::query()->value('state_key'),
        );
        $this->assertSame(1, PlaybookValuePosterior::query()->count());
        $bundle = PlaybookValuePosterior::query()->with('playbook')->firstOrFail();
        $this->assertSame('research_only', $bundle->playbook->promotion_state);
        $this->assertFalse((bool) data_get($bundle->value_vector, 'interaction_identified'));
        $this->assertSame('joint_bundle_value_not_component_synergy', data_get($bundle->value_vector, 'interpretation'));

        // Settlement and posterior projection are idempotent under a retry.
        $this->assertSame(1, $ledger->settleResearchPair($pair->fresh()));
        $this->assertDatabaseCount('instrument_evidence', 1);
        $this->assertSame(1, (int) InstrumentValuePosterior::query()->value('observations'));
        $this->assertSame(1, (int) PlaybookValuePosterior::query()->value('observations'));
    }

    public function test_authorized_window_stays_frozen_when_control_finishes_after_candidate(): void
    {
        $this->travelTo(CarbonImmutable::parse('2028-01-01 00:00:00', 'UTC'));
        $dataHash = str_repeat('a', 64);
        $executionHash = str_repeat('b', 64);
        config()->set('services.instrument_policy.authorized_research_windows', [[
            'authorization_id' => 'late-control-window',
            'research_epoch_id' => 'test-post-paper-research',
            'start_inclusive' => '2027-01-01T00:00:00Z',
            'end_exclusive' => '2027-02-01T00:00:00Z',
            'dataset_sha256' => $dataHash,
            'purpose' => 'instrument_independent_validation',
        ]]);
        [$candidate, $control, $generation] = $this->pairAgents();
        $candidateMap = LabMutationResponseMap::create([
            'response_key' => hash('sha256', 'late-window-candidate'),
            'stage' => 'screening', 'status' => 'screen_observed',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'profit_factor', 'parameter_key' => 'volume_lane',
            'lab_agent_id' => $candidate->id, 'model_version_id' => $candidate->model_version_id,
            'evidence_run_id' => 'late-window-run',
            'old_value' => ['value' => 'none'], 'new_value' => ['value' => 'breakout_volume_confirmation'],
            'observed_metrics' => ['profit_factor' => 1.2],
            'metadata' => ['screening_decision' => 'failed',
                'data_manifest_hash' => $dataHash, 'execution_hash' => $executionHash],
        ]);
        $service = app(LearningLaneService::class);
        $result = ['evidence_run_id' => 'late-window-run', 'data_manifest' => [
            'data_partition' => ['screening_source' => 'authorized_post_paper_research_validation'],
            'instrument_research_window' => ['authorization_id' => 'late-control-window',
                'research_epoch_id' => 'test-post-paper-research'],
            'first_candle_at' => '2027-01-01T00:00:00Z',
            'last_candle_at' => '2027-01-31T23:55:00Z',
        ]];
        $initial = $service->pairScreeningObservation($candidate->fresh(['modelVersion', 'generation']),
            $result, $candidateMap->toArray());
        $pair = LabLearningLanePair::findOrFail($initial['id']);
        $receipt = (array) data_get($pair->metadata, 'instrument_research_window_receipt');
        $this->assertSame('missing_control', $pair->status);
        $this->assertSame($pair->independent_window_key, $receipt['window_key']);

        LabMutationResponseMap::create([
            'response_key' => hash('sha256', 'late-window-control'),
            'stage' => 'screening', 'status' => 'control',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'lab_agent_id' => $control->id, 'model_version_id' => $control->model_version_id,
            'evidence_run_id' => 'late-control-run', 'observed_metrics' => ['profit_factor' => 1.0],
            'metadata' => ['control_contract' => [
                'protocol' => 'frozen_control_v2', 'control_only' => true, 'role' => 'control',
                'generation_id' => $generation->id, 'data_hash' => $dataHash,
                'execution_hash' => $executionHash,
            ]],
        ]);
        $updated = $service->pairScreeningObservation($candidate->fresh(['modelVersion', 'generation']),
            $result, $candidateMap->toArray());
        $pair = LabLearningLanePair::findOrFail($updated['id']);
        $this->assertSame($initial['id'], $updated['id']);
        $this->assertSame($receipt, data_get($pair->metadata, 'instrument_research_window_receipt'));
        $this->assertSame($receipt['window_key'], $pair->independent_window_key);
        $this->assertTrue($pair->isVerifiedControlPair());
    }

    public function test_unscoped_family_prior_opens_research_but_cannot_directly_mutate(): void
    {
        app(TradingInstrumentOperatingSystemService::class)->seedDefaults();
        $volume = TradingInstrument::query()->where('instrument_key', 'volume_confirmation')->firstOrFail();
        $entry = TradingInstrument::query()->where('instrument_key', 'adaptive_entry_topology')->firstOrFail();
        InstrumentValuePosterior::create([
            'trading_instrument_id' => $volume->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'M15',
            'state_key' => 'historical_mixed|stratified_replay|mixed|unknown|stable|0|both|hybrid',
            'observations' => 5,
            'net_value' => .2,
            'uncertainty' => .1,
            'decay_state' => 'confirmed',
            'value_vector' => [],
        ]);
        InstrumentValuePosterior::create([
            'trading_instrument_id' => $entry->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'M15',
            'state_key' => 'historical_mixed|stratified_replay|mixed|unknown|stable|0|both|hybrid',
            'observations' => 5,
            'net_value' => -.2,
            'uncertainty' => .1,
            'decay_state' => 'forbidden',
            'value_vector' => [],
        ]);
        $bundle = $this->researchBundle('volume-confirmed-bundle', ['volume_confirmation', 'atr_risk_envelope', 'cost_aware_exit'], 'volume_confirmation');
        PlaybookValuePosterior::create([
            'playbook_composition_id' => $bundle->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M15',
            'state_key' => 'historical_mixed|stratified_replay|mixed|unknown|stable|0|both|hybrid', 'observations' => 5,
            'net_value' => .18, 'uncertainty' => .1, 'decay_state' => 'confirmed', 'value_vector' => [],
        ]);

        $policy = app(LabInstrumentResearchService::class)->mutationPolicy('XAU/USD', 'hybrid');

        $this->assertSame([], $policy['preferred_genes']);
        $this->assertSame([], $policy['blocked_genes']);
        $this->assertContains('volume_confirmation', collect($policy['research_inbox'])->pluck('instrument_key')->all());
        $this->assertTrue(collect($policy['research_inbox'])->every(
            fn (array $source): bool => $source['authority'] === 'controlled_experiment_proposal_only',
        ));
        $this->assertFalse($policy['paper_execution_authority']);
        $this->assertFalse($policy['promotion_evidence']);
    }

    public function test_instrument_policy_keeps_london_value_out_of_asia_and_global_scope(): void
    {
        $this->travelTo(CarbonImmutable::parse('2028-01-01 00:00:00', 'UTC'));
        app(TradingInstrumentOperatingSystemService::class)->seedDefaults();
        $instrument = TradingInstrument::query()->where('instrument_key', 'volume_confirmation')->firstOrFail();
        InstrumentValuePosterior::create([
            'trading_instrument_id' => $instrument->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M15',
            'state_key' => 'trend_up|london|normal|normal|stable|0|buy|hybrid|london_am_fix', 'observations' => 8,
            'net_value' => .3, 'uncertainty' => .1, 'decay_state' => 'confirmed',
            'value_vector' => $this->posteriorVector('trend_up|london|normal|normal|stable|0|buy|hybrid|london_am_fix', 8, true),
        ]);
        InstrumentValuePosterior::create([
            'trading_instrument_id' => $instrument->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M15',
            'state_key' => 'trend_up|asia|normal|normal|stable|0|buy|hybrid|asia_sge_day', 'observations' => 8,
            'net_value' => -.3, 'uncertainty' => .1, 'decay_state' => 'forbidden',
            'value_vector' => $this->posteriorVector('trend_up|asia|normal|normal|stable|0|buy|hybrid|asia_sge_day', 8, false),
        ]);
        $bundle = $this->researchBundle('volume-context-bundle', ['volume_confirmation', 'atr_risk_envelope', 'cost_aware_exit'], 'volume_confirmation');
        PlaybookValuePosterior::create([
            'playbook_composition_id' => $bundle->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M15',
            'state_key' => 'trend_up|london|normal|normal|stable|0|buy|hybrid|london_am_fix', 'observations' => 8,
            'net_value' => .25, 'uncertainty' => .1, 'decay_state' => 'confirmed',
            'value_vector' => $this->posteriorVector('trend_up|london|normal|normal|stable|0|buy|hybrid|london_am_fix', 8, true),
        ]);

        $service = app(LabInstrumentResearchService::class);
        $full = ['direction' => 'buy', 'transition_state' => 'stable', 'spread_liquidity_state' => 'normal'];
        $london = $service->mutationPolicy('XAUUSD', 'hybrid', [...$full, 'regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal', 'venue_phase' => 'london_am_fix']);
        $asia = $service->mutationPolicy('XAUUSD', 'hybrid', [...$full, 'regime' => 'trend_up', 'session' => 'asian', 'volatility' => 'normal', 'venue_phase' => 'asia_sge_day']);
        $global = $service->mutationPolicy('XAUUSD', 'hybrid');
        $otherFamily = $service->mutationPolicy('XAUUSD', 'trend', ['regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal']);

        $this->assertContains('volume_lane', $london['preferred_genes']);
        $this->assertNotContains('volume_lane', $london['blocked_genes']);
        $this->assertSame([], $asia['blocked_genes']);
        $this->assertContains('volume_lane', array_column($asia['blocked_deltas'], 'gene'));
        $this->assertNotContains('volume_lane', $global['preferred_genes']);
        $this->assertNotContains('volume_lane', $global['blocked_genes']);
        $this->assertNotContains('volume_lane', $otherFamily['preferred_genes']);
        $this->assertNotContains('volume_lane', $otherFamily['blocked_genes']);
    }

    public function test_phase_local_posterior_cannot_mutate_a_session_wide_successor(): void
    {
        $this->travelTo(CarbonImmutable::parse('2028-01-01 00:00:00', 'UTC'));
        app(TradingInstrumentOperatingSystemService::class)->seedDefaults();
        $instrument = TradingInstrument::query()->where('instrument_key', 'volume_confirmation')->firstOrFail();
        $key = 'trend_up|london|normal|normal|stable|0|buy|hybrid|london_am_fix';
        InstrumentValuePosterior::create([
            'trading_instrument_id' => $instrument->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M15',
            'state_key' => $key, 'observations' => 8, 'net_value' => .3,
            'uncertainty' => .1, 'decay_state' => 'confirmed',
            'value_vector' => $this->posteriorVector($key, 8, true),
        ]);
        $bundle = $this->researchBundle('exact-fix-bundle', [
            'volume_confirmation', 'atr_risk_envelope', 'cost_aware_exit',
        ], 'volume_confirmation');
        PlaybookValuePosterior::create([
            'playbook_composition_id' => $bundle->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M15',
            'state_key' => $key, 'observations' => 8, 'net_value' => .25,
            'uncertainty' => .1, 'decay_state' => 'confirmed',
            'value_vector' => $this->posteriorVector($key, 8, true),
        ]);

        $service = app(LabInstrumentResearchService::class);
        $base = ['regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal',
            'direction' => 'buy', 'transition_state' => 'stable', 'spread_liquidity_state' => 'normal'];
        $exact = $service->mutationPolicy('XAUUSD', 'hybrid', [
            ...$base, 'venue_phase' => 'london_am_fix',
        ]);
        $other = $service->mutationPolicy('XAUUSD', 'hybrid', [
            ...$base, 'venue_phase' => 'london_interfix',
        ]);
        $broad = $service->mutationPolicy('XAUUSD', 'hybrid', $base);

        $this->assertContains('volume_lane', $exact['preferred_genes']);
        $this->assertNotContains('volume_lane', $other['preferred_genes']);
        $this->assertNotContains('volume_lane', $broad['preferred_genes']);
        $this->assertSame([], $broad['bundle_sources']);

        // A bundle from a different spread cell cannot support this exact
        // instrument posterior merely because both satisfy a broad request.
        $differentCell = 'trend_up|london|normal|high|stable|0|buy|hybrid|london_am_fix';
        PlaybookValuePosterior::query()->where('playbook_composition_id', $bundle->id)->firstOrFail()->update([
            'state_key' => $differentCell,
            'value_vector' => $this->posteriorVector($differentCell, 8, true),
        ]);
        $mismatched = $service->mutationPolicy('XAUUSD', 'hybrid', [
            ...$base, 'venue_phase' => 'london_am_fix',
        ]);
        $this->assertNotContains('volume_lane', $mismatched['preferred_genes']);
        InstrumentValuePosterior::create([
            'trading_instrument_id' => $instrument->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M15',
            'state_key' => $differentCell, 'observations' => 3, 'net_value' => -.1,
            'uncertainty' => .1, 'decay_state' => 'forbidden',
            'value_vector' => $this->posteriorVector($differentCell, 3, false),
        ]);
        $negativeCellBundle = $service->mutationPolicy('XAUUSD', 'hybrid', [
            ...$base, 'venue_phase' => 'london_am_fix',
        ]);
        $this->assertNotContains('volume_lane', $negativeCellBundle['preferred_genes']);
    }

    public function test_laravel_and_python_share_the_same_assignment_protocol(): void
    {
        $python = file_get_contents(base_path('../ai-service-python/app/services/instrument_research.py'));

        $this->assertIsString($python);
        $this->assertStringContainsString(
            'ASSIGNMENT_PROTOCOL = "'.LabInstrumentResearchService::PROTOCOL.'"',
            $python,
        );
    }

    public function test_pending_exact_pair_is_reconciled_once_without_relabeling_terminal_history(): void
    {
        [$candidate, $control, $generation] = $this->pairAgents();
        $assignment = app(LabInstrumentResearchService::class)->assignment($candidate);
        $ledger = app(InstrumentInvocationLedgerService::class);
        $ledger->recordResearchObservation($candidate->fresh('modelVersion'),
            $this->attestedResult($assignment, 'pending-candidate-run', true));
        $controlAssignment = app(LabInstrumentResearchService::class)->assignment($control);
        $ledger->recordResearchObservation($control->fresh('modelVersion'),
            $this->attestedResult($controlAssignment, 'pending-control-run', true));
        $data = str_repeat('a', 64); $execution = str_repeat('b', 64);
        $candidateMap = LabMutationResponseMap::create([
            'response_key' => hash('sha256', 'pending-candidate-map'), 'stage' => 'screening', 'status' => 'screen_observed',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'target' => 'profit_factor',
            'parameter_key' => 'volume_lane', 'lab_agent_id' => $candidate->id, 'model_version_id' => $candidate->model_version_id,
            'evidence_run_id' => 'pending-candidate-run', 'old_value' => ['value' => 'none'],
            'new_value' => ['value' => 'breakout_volume_confirmation'],
            'metadata' => ['data_manifest_hash' => $data, 'execution_hash' => $execution]]);
        $controlMap = LabMutationResponseMap::create([
            'response_key' => hash('sha256', 'pending-control-map'), 'stage' => 'screening', 'status' => 'control',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'lab_agent_id' => $control->id, 'model_version_id' => $control->model_version_id,
            'evidence_run_id' => 'pending-control-run', 'metadata' => ['control_contract' => [
                'protocol' => 'frozen_control_v2', 'control_only' => true, 'role' => 'control',
                'generation_id' => $generation->id, 'data_hash' => $data, 'execution_hash' => $execution]]]);
        $pair = LabLearningLanePair::create([
            'pair_key' => hash('sha256', 'pending-learning-observation'), 'lab_generation_id' => $generation->id,
            'candidate_agent_id' => $candidate->id, 'control_agent_id' => $control->id,
            'candidate_response_map_id' => $candidateMap->id, 'control_response_map_id' => $controlMap->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'target' => 'profit_factor',
            'baseline_source' => 'control', 'status' => 'screen_paired', 'pair_integrity_status' => 'verified',
            'same_generation' => true, 'candidate_evidence_run_id' => 'pending-candidate-run',
            'control_evidence_run_id' => 'pending-control-run', 'candidate_data_hash' => $data, 'control_data_hash' => $data,
            'candidate_execution_hash' => $execution, 'control_execution_hash' => $execution,
            'candidate_metrics' => ['instrument_research_trace' => $this->contextTrace(1.2, 4, 5, 21, true)],
            'control_metrics' => ['instrument_research_trace' => $this->contextTrace(1.05, 1, 6, 20, true)]]);
        $this->assertTrue($pair->isVerifiedControlPair());
        $plan = $ledger->pendingResearchPairs('XAUUSD');
        $this->assertSame($pair->id, $plan[0]['pair_id']);
        $this->assertSame(1, $plan[0]['powered_exact_contexts']);
        $this->assertSame(count($controlAssignment['selected']), $plan[0]['pending_control_references']);
        $this->artisan('trading:reconcile-instrument-pairs', ['--dry-run' => true, '--json' => true])->assertExitCode(0);
        $this->assertDatabaseCount('instrument_evidence', 0);
        $result = $ledger->reconcileResearchPair($pair->id);
        $this->assertSame(0, $result['pending_after']);
        $this->assertSame(1, $result['causal_projections']);
        $this->assertSame(count($controlAssignment['selected']), $result['control_references_closed']);
        $this->assertSame(count($controlAssignment['selected']), InstrumentInvocationLedger::where('verdict', 'control_reference_consumed')->count());
        $this->assertSame([], $ledger->pendingResearchPairs('XAUUSD'));
        $this->assertSame('provisional', InstrumentValuePosterior::query()->value('decay_state'));
        $terminal = InstrumentInvocationLedger::query()->get()->map(fn ($row) => $row->toArray())->all();
        $this->assertSame(0, $ledger->reconcileResearchPair($pair->id)['pending_before']);
        $this->assertSame($terminal, InstrumentInvocationLedger::query()->get()->map(fn ($row) => $row->toArray())->all());
        $this->assertDatabaseCount('instrument_evidence', 1);
        $this->assertSame(1, (int) InstrumentValuePosterior::query()->value('observations'));
    }

    /** @return array{LabAgent, LabAgent, LabGeneration} */
    private function pairAgents(): array
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'Instrument loop test',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid'],
            'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 1,
            'trigger_type' => 'test',
            'population_size' => 2,
            'status' => 'screening',
        ]);
        $base = app(StrategyParameterSchemaService::class)->defaults('hybrid');
        $candidateParameters = [...$base, 'volume_lane' => 'breakout_volume_confirmation'];
        $pairKey = str_repeat('d', 64);
        $candidateModel = $this->model('instrument-candidate', $candidateParameters, [
            'control_pair_contract' => [
                'protocol' => 'exact_frozen_control_pair_v2',
                'pair_key' => $pairKey,
                'role' => 'candidate',
                'required_for_candidate' => true,
            ],
        ]);
        $controlModel = $this->model('instrument-control', $base, [
            'control_pair_contract' => [
                'protocol' => 'exact_frozen_control_pair_v2',
                'pair_key' => $pairKey,
                'role' => 'control',
                'required_for_candidate' => false,
            ],
            'control_contract' => [
                'protocol' => 'frozen_control_v2',
                'control_only' => true,
                'role' => 'control',
            ],
        ]);
        $candidate = LabAgent::create([
            'lab_generation_id' => $generation->id,
            'model_version_id' => $candidateModel->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'origin' => 'test',
            'lifecycle_status' => 'screening',
            'parameter_diff' => ['volume_lane' => ['old' => 'none', 'new' => 'breakout_volume_confirmation']],
        ]);
        $control = LabAgent::create([
            'lab_generation_id' => $generation->id,
            'model_version_id' => $controlModel->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'origin' => 'test',
            'lifecycle_status' => 'screening',
            'parameter_diff' => [],
        ]);

        return [$candidate->fresh(['modelVersion']), $control->fresh(['modelVersion']), $generation];
    }

    /** @param list<string> $keys */
    private function researchBundle(string $key, array $keys, string $primary): PlaybookComposition
    {
        return PlaybookComposition::create([
            'playbook_key' => $key, 'label' => $key, 'symbol' => 'XAUUSD', 'timeframe' => 'M15',
            'promotion_state' => 'research_only', 'instrument_keys' => $keys, 'preconditions' => [],
            'metadata' => [
                'protocol' => 'exact_instrument_research_bundle_v1',
                'bundle_hash' => hash('sha256', $key),
                'primary_instrument_key' => $primary,
                'router_eligible' => false,
                'interaction_identified' => false,
            ],
        ]);
    }

    private function model(string $name, array $parameters, array $metadata = []): ModelVersion
    {
        return ModelVersion::create([
            'name' => $name,
            'strategy' => $name,
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'parameters' => $parameters,
            'metadata' => ['base_strategy' => 'regime_router', ...$metadata],
            'evidence_status' => 'valid',
        ]);
    }

    private function attestedResult(array $assignment, string $runId, bool $exactPhase = false): array
    {
        return [
            'evidence_run_id' => $runId,
            'net_profit_percent' => 3.0,
            'profit_factor' => 1.2,
            'max_drawdown_percent' => 5.0,
            'total_trades' => 42,
            'instrument_research_trace' => [
                'protocol' => LabInstrumentResearchService::RUNTIME_TRACE_PROTOCOL,
                'status' => 'consumed',
                'assignment_hash' => $assignment['assignment_hash'],
                'assignment_hash_valid' => true,
                'parameter_hash_valid' => true,
                'runtime_bindings_valid' => true,
                'activation_contracts_valid' => true,
                'runtime_observations_valid' => true,
                'runtime_observed' => true,
                'bundle_fully_activated' => true,
                'bundle_activation_context_keys' => ['trend_up|normal_volatility|london|BUY'],
                ...($exactPhase ? ['bundle_activation_exact_context_keys' => [
                    'trend_up|normal_volatility|london|london_am_fix|BUY',
                ]] : []),
                'instruments' => array_map(fn (array $selected): array => [
                    'instrument_key' => $selected['instrument_key'],
                    'status' => 'consumed',
                    'causal_candidate' => $selected['causal_candidate'],
                    'parameter_bindings' => $selected['parameter_bindings'],
                    'activation_contract_protocol' => LabInstrumentResearchService::ACTIVATION_PROTOCOL,
                    'runtime_observation_valid' => true,
                    'runtime_receipt_consistent' => true,
                    'decision_path_activated' => true,
                    'activated_context_keys' => ['trend_up|normal_volatility|london|BUY'],
                    ...($exactPhase ? ['activated_exact_context_keys' => [
                        'trend_up|normal_volatility|london|london_am_fix|BUY',
                    ]] : []),
                    'promotion_evidence' => false,
                ], $assignment['selected']),
                'promotion_evidence' => false,
            ],
        ];
    }

    private function contextTrace(float $pf, float $net, float $drawdown, int $trades, bool $exactPhase = false): array
    {
        $slice = [
            'context_key' => 'trend_up|normal_volatility|london|BUY',
            'context' => [
                'regime' => 'trend_up', 'volatility' => 'normal_volatility',
                'session' => 'london', 'session_utc_hour' => 8, 'direction' => 'BUY',
            ],
            'metrics' => [
                'trades' => $trades, 'net_pf' => $pf,
                'net_profit_percent' => $net, 'max_drawdown_percent' => $drawdown,
                'execution_cost_percent' => .1,
            ],
            'powered' => true, 'promotion_evidence' => false,
        ];

        return [
            'context_source' => 'decision_time_trade_ledger',
            'context_slices' => [$slice],
            ...($exactPhase ? [
                'context_slice_protocol' => 'venue_phase_v1',
                'exact_context_slices' => [[
                    ...$slice,
                    'context_key' => 'trend_up|normal_volatility|london|london_am_fix|BUY',
                    'context' => [...$slice['context'], 'venue_phase' => 'london_am_fix'],
                ]],
            ] : []),
        ];
    }

    private function posteriorVector(string $stateKey, int $observations, bool $positive): array
    {
        [$regime, $session, $volatility, $spread, $transition, $loss, $direction, $family, $venuePhase] = array_pad(explode('|', $stateKey), 9, null);
        $manifests = [];
        foreach ([1, 2, 3] as $month) {
            $manifests[] = [
                'authorization_id' => 'test-instrument-window-'.$month,
                'research_epoch_id' => 'test-post-paper-research',
                'start_inclusive' => sprintf('2027-%02d-01T00:00:00+00:00', $month),
                'end_exclusive' => sprintf('2027-%02d-01T00:00:00+00:00', $month + 1),
                'dataset_sha256' => hash('sha256', 'test-window-'.$month),
                'purpose' => 'instrument_independent_validation',
            ];
        }
        config()->set('services.instrument_policy.authorized_research_windows', $manifests);
        $windows = array_map(fn (array $manifest): array => app(InstrumentResearchWindowService::class)->seal(
            $manifest['authorization_id'], $manifest['dataset_sha256'],
        ), $manifests);
        $evidenceKeys = array_map(fn (int $i): string => "evidence-{$stateKey}-{$i}", range(1, $observations));

        return $this->exactValidationVector($stateKey, array_map(fn (string $key, int $index): array => [
            'window' => $windows[$index % 3], 'evidence_key' => $key,
            'outcome' => $positive ? 'positive' : 'negative',
        ], $evidenceKeys, array_keys($evidenceKeys)));
    }
}
