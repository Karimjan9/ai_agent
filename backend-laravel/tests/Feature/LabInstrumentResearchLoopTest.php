<?php

namespace Tests\Feature;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AiLaboratory;
use App\Models\InstrumentInvocationLedger;
use App\Models\InstrumentValuePosterior;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\ModelVersion;
use App\Models\PlaybookComposition;
use App\Models\PlaybookValuePosterior;
use App\Models\TradingInstrument;
use App\Services\CausalLearningCohortPlannerService;
use App\Services\InstrumentInvocationLedgerService;
use App\Services\LabInstrumentResearchService;
use App\Services\LearningLaneService;
use App\Services\StrategyParameterSchemaService;
use App\Services\TradingInstrumentOperatingSystemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabInstrumentResearchLoopTest extends TestCase
{
    use RefreshDatabase;

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
        [$candidate, $control, $generation] = $this->pairAgents();
        $assignment = app(LabInstrumentResearchService::class)->assignment($candidate);
        $ledger = app(InstrumentInvocationLedgerService::class);
        $ledger->recordResearchObservation(
            $candidate->fresh(['modelVersion']),
            $this->attestedResult($assignment, 'candidate-screen-run'),
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
                'instrument_research_trace' => $this->contextTrace(1.2, 4.0, 5.0, 21),
            ],
            'metadata' => [
                'screening_decision' => 'failed',
                'data_manifest_hash' => $dataHash,
                'execution_hash' => $executionHash,
            ],
        ]);
        $controlMap->update(['observed_metrics' => [
            ...(array) $controlMap->observed_metrics,
            'instrument_research_trace' => $this->contextTrace(1.05, 1.0, 6.0, 20),
        ]]);
        $createdPair = app(LearningLaneService::class)->pairScreeningObservation(
            $candidate->fresh(['modelVersion', 'generation']),
            ['evidence_run_id' => 'candidate-screen-run'],
            $candidateMap->toArray(),
        );
        $this->assertNotNull($createdPair);
        $pair = LabLearningLanePair::query()->findOrFail($createdPair['id']);
        $this->assertTrue($pair->isVerifiedControlPair());
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
        $this->assertSame(
            'trend_up|london|normal|unknown|stable|0|buy|hybrid',
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
        app(TradingInstrumentOperatingSystemService::class)->seedDefaults();
        $instrument = TradingInstrument::query()->where('instrument_key', 'volume_confirmation')->firstOrFail();
        InstrumentValuePosterior::create([
            'trading_instrument_id' => $instrument->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M15',
            'state_key' => 'trend_up|london|normal|normal|stable|0|both|hybrid', 'observations' => 8,
            'net_value' => .3, 'uncertainty' => .1, 'decay_state' => 'confirmed',
            'value_vector' => $this->posteriorVector('trend_up|london|normal|normal|stable|0|both|hybrid', 8, true),
        ]);
        InstrumentValuePosterior::create([
            'trading_instrument_id' => $instrument->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M15',
            'state_key' => 'trend_up|asia|normal|normal|stable|0|both|hybrid', 'observations' => 8,
            'net_value' => -.3, 'uncertainty' => .1, 'decay_state' => 'forbidden',
            'value_vector' => $this->posteriorVector('trend_up|asia|normal|normal|stable|0|both|hybrid', 8, false),
        ]);
        $bundle = $this->researchBundle('volume-context-bundle', ['volume_confirmation', 'atr_risk_envelope', 'cost_aware_exit'], 'volume_confirmation');
        PlaybookValuePosterior::create([
            'playbook_composition_id' => $bundle->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M15',
            'state_key' => 'trend_up|london|normal|normal|stable|0|both|hybrid', 'observations' => 8,
            'net_value' => .25, 'uncertainty' => .1, 'decay_state' => 'confirmed',
            'value_vector' => $this->posteriorVector('trend_up|london|normal|normal|stable|0|both|hybrid', 8, true),
        ]);

        $service = app(LabInstrumentResearchService::class);
        $london = $service->mutationPolicy('XAUUSD', 'hybrid', ['regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal']);
        $asia = $service->mutationPolicy('XAUUSD', 'hybrid', ['regime' => 'trend_up', 'session' => 'asian', 'volatility' => 'normal']);
        $global = $service->mutationPolicy('XAUUSD', 'hybrid');
        $otherFamily = $service->mutationPolicy('XAUUSD', 'trend', ['regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal']);

        $this->assertContains('volume_lane', $london['preferred_genes']);
        $this->assertNotContains('volume_lane', $london['blocked_genes']);
        $this->assertContains('volume_lane', $asia['blocked_genes']);
        $this->assertNotContains('volume_lane', $global['preferred_genes']);
        $this->assertNotContains('volume_lane', $global['blocked_genes']);
        $this->assertNotContains('volume_lane', $otherFamily['preferred_genes']);
        $this->assertNotContains('volume_lane', $otherFamily['blocked_genes']);
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

    private function attestedResult(array $assignment, string $runId): array
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
                    'promotion_evidence' => false,
                ], $assignment['selected']),
                'promotion_evidence' => false,
            ],
        ];
    }

    private function contextTrace(float $pf, float $net, float $drawdown, int $trades): array
    {
        return [
            'context_source' => 'decision_time_trade_ledger',
            'context_slices' => [[
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
            ]],
        ];
    }

    private function posteriorVector(string $stateKey, int $observations, bool $positive): array
    {
        [$regime, $session, $volatility, $spread, $transition, $loss, $direction, $family] = explode('|', $stateKey);

        return [
            'context' => [
                'regime' => $regime, 'session' => $session, 'volatility' => $volatility,
                'spread_state' => $spread, 'transition' => $transition,
                'loss_streak' => (int) $loss, 'direction' => $direction,
                'strategy_family' => $family, 'state_key' => $stateKey,
            ],
            'strategy_family' => $family,
            'independent_window_keys' => ['window-a', 'window-b', 'window-c'],
            'evidence_keys' => array_map(fn (int $i): string => "evidence-{$stateKey}-{$i}", range(1, $observations)),
            'positive_observations' => $positive ? $observations : 0,
            'negative_observations' => $positive ? 0 : $observations,
            'non_target_regression_count' => 0,
        ];
    }
}
