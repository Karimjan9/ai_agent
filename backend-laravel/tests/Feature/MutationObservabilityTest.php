<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\CandidateGateDecisionService;
use App\Services\LabPopulationService;
use App\Services\MutationObservabilityService;
use App\Services\ResearchAllocationPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MutationObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_exhausted_gene_gets_a_same_target_replacement_before_architecture_escape(): void
    {
        $service = app(LabPopulationService::class);
        $method = new \ReflectionMethod($service, 'zeroDiffReplacementSpecs');
        $method->setAccessible(true);
        $spec = [
            'origin' => 'targeted_failure_profile',
            'family' => 'hybrid',
            'target' => 'temporal_stability',
            'niche' => [
                'declared_gene' => 'transition_firewall_enabled',
                'repair_anchor_id' => 42,
                'control_only' => false,
                'temporal_mutation_hypothesis' => [
                    'declared_genes' => [
                        'max_loss_streak_before_wait',
                        'loss_cooldown_candles',
                        'loss_streak_wait_candles',
                        'weak_regime_wait_candles',
                    ],
                    'historically_exhausted_controls' => ['transition_firewall_enabled'],
                ],
            ],
        ];
        $plan = [
            $spec,
            ['niche' => ['declared_gene' => 'max_loss_streak_before_wait']],
            ['niche' => ['declared_gene' => 'loss_cooldown_candles']],
            ['niche' => ['declared_gene' => 'weak_regime_wait_candles']],
            ['niche' => ['control_only' => true]],
        ];

        $replacements = $method->invoke($service, $plan, 0, $spec);

        $this->assertNotEmpty($replacements);
        $this->assertSame('loss_streak_wait_candles', data_get($replacements[0], 'niche.declared_gene'));
        $this->assertSame('zero_diff_replacement_compiler_v1', data_get($replacements[0], 'niche.replacement_contract.protocol'));
        $this->assertSame('transition_firewall_enabled', data_get($replacements[0], 'niche.replacement_contract.replaced_gene'));
    }

    public function test_dependency_blocked_targeted_seat_is_replaced_by_an_edge_prerequisite(): void
    {
        $service = app(LabPopulationService::class);
        $replaceable = new \ReflectionMethod($service, 'canReplaceMutationConstruction');
        $replaceable->setAccessible(true);
        $replacements = new \ReflectionMethod($service, 'zeroDiffReplacementSpecs');
        $replacements->setAccessible(true);
        $spec = [
            'origin' => 'targeted_failure_profile',
            'family' => 'hybrid',
            'target' => 'drawdown_risk',
            'niche' => [
                'declared_gene' => 'time_stop_candles',
                'control_only' => false,
                'failure_target' => 'drawdown_risk',
                'mutation_target' => 'drawdown_risk',
            ],
        ];

        $reason = 'MANAGEMENT_MUTATION_LOCKED_UNTIL_RISK_SHAPING';
        $this->assertTrue($replaceable->invoke($service, $spec, $reason));
        data_set($spec, 'niche.protocol', LabPopulationService::TARGETED_RESCUE_PROFILE_PROTOCOL);
        $this->assertTrue($replaceable->invoke($service, $spec, 'CONSTRUCTOR_MUTATION_INVARIANT_FAILED'));

        $compiled = $replacements->invoke($service, [$spec], 0, $spec, $reason);

        $this->assertNotEmpty($compiled);
        $this->assertSame('minimum_signal_confidence', data_get($compiled[0], 'niche.declared_gene'));
        $this->assertSame('profit_factor', data_get($compiled[0], 'target'));
        $this->assertSame('drawdown_risk', data_get($compiled[0], 'niche.deferred_failure_target'));
        $this->assertSame($reason, data_get($compiled[0], 'niche.replacement_contract.original_failure_reason'));

        $repeated = $replacements->invoke($service, [$spec], 0, $spec, $reason, true);
        $this->assertCount(1, $repeated);
        $this->assertTrue((bool) data_get($repeated[0], 'niche.control_only'));
        $this->assertNull(data_get($repeated[0], 'niche.declared_gene'));
        $this->assertSame('dependency_control', data_get($repeated[0], 'origin'));
        $this->assertSame('frozen_control_replication', data_get($repeated[0], 'allocation_lane'));
        $this->assertSame(
            'dependency_prerequisites_exhausted_frozen_control',
            data_get($repeated[0], 'niche.replacement_contract.reason'),
        );

        $preemptive = new \ReflectionMethod($service, 'repeatedFailureReplacementSpec');
        $preemptive->setAccessible(true);
        $retrySpec = $preemptive->invoke($service, [$spec], 0, $spec, [
            ['slot' => 1, 'reason' => $reason],
        ]);
        $this->assertIsArray($retrySpec);
        $this->assertSame('dependency_control', data_get($retrySpec, 'origin'));
        $this->assertSame(
            'frozen_dependency_control',
            data_get($retrySpec, 'niche.replacement_contract.replacement_mode'),
        );
        $this->assertSame('frozen_control', data_get($retrySpec, 'evolution_mode'));

        $learningSpec = [
            ...$spec,
            'origin' => 'causal_confirm',
        ];
        $this->assertTrue($replaceable->invoke($service, $learningSpec, $reason));
        $learningControl = $preemptive->invoke($service, [$learningSpec], 0, $learningSpec, [
            ['slot' => 1, 'reason' => $reason],
        ]);
        $this->assertSame('dependency_control', data_get($learningControl, 'origin'));
        $this->assertTrue((bool) data_get($learningControl, 'niche.control_only'));

        $blindedControl = $preemptive->invoke($service, [$learningSpec], 0, $learningSpec, [
            ['slot' => 1, 'reason' => 'CAUSAL_BLINDED_SELECTOR_NOT_EXACT_SINGLE_GENE'],
        ]);
        $this->assertSame('frozen_dependency_control', data_get(
            $blindedControl,
            'niche.replacement_contract.replacement_mode',
        ));
        $this->assertFalse((bool) data_get($blindedControl, 'promotion_evidence', false));

        $frozenCausalControl = [
            ...$learningSpec,
            'niche' => [
                ...$learningSpec['niche'],
                'control_only' => true,
                'causal_learning_cohort' => [
                    'role' => 'frozen_control',
                    'source_lesson_id' => 2365,
                    'source_control_agent_id' => 1731,
                    'baseline_model_version_id' => 1767,
                ],
            ],
        ];
        $baselineMissing = 'CAUSAL_LEARNING_SOURCE_BASELINE_MISSING';
        $this->assertTrue($replaceable->invoke($service, $frozenCausalControl, $baselineMissing));
        $safeControl = $preemptive->invoke($service, [$frozenCausalControl], 0, $frozenCausalControl, [
            ['slot' => 1, 'reason' => $baselineMissing],
        ]);
        $this->assertSame('dependency_control', data_get($safeControl, 'origin'));
        $this->assertNull(data_get($safeControl, 'niche.causal_learning_cohort'));
        $this->assertTrue((bool) data_get($safeControl, 'niche.replacement_contract.causal_experiment_removed'));
        $this->assertFalse((bool) data_get($safeControl, 'niche.replacement_contract.promotion_evidence'));
    }

    public function test_failed_shadow_experiment_becomes_an_explicit_frozen_control(): void
    {
        $service = app(LabPopulationService::class);
        $replaceable = new \ReflectionMethod($service, 'canReplaceMutationConstruction');
        $replaceable->setAccessible(true);
        $replacements = new \ReflectionMethod($service, 'zeroDiffReplacementSpecs');
        $replacements->setAccessible(true);
        $preemptive = new \ReflectionMethod($service, 'repeatedFailureReplacementSpec');
        $preemptive->setAccessible(true);
        $spec = [
            'origin' => 'architecture',
            'family' => 'differential_router',
            'target' => 'architecture',
            'allocation_lane' => 'architecture_explorer',
            'niche' => [
                'shadow_only' => true,
                'control_only' => false,
                'architecture_experiment' => true,
                'entry_topology_variant' => 'volatility_persistence_v1',
                'shadow_mutation_gene' => 'entry_topology_variant',
                'shadow_mutation_contract' => ['gene' => 'entry_topology_variant'],
                'shadow_research_lane' => ['role' => 'architecture_explorer'],
            ],
        ];

        $reason = 'SHADOW_MUTATION_CONTRACT_FAILED';
        $this->assertTrue($replaceable->invoke($service, $spec, $reason));
        $compiled = $replacements->invoke($service, [$spec], 0, $spec, $reason, true);

        $this->assertCount(1, $compiled);
        $this->assertSame('shadow_control', data_get($compiled[0], 'origin'));
        $this->assertSame('frozen_control_replication', data_get($compiled[0], 'allocation_lane'));
        $this->assertTrue((bool) data_get($compiled[0], 'niche.control_only'));
        $this->assertNull(data_get($compiled[0], 'niche.declared_gene'));
        $this->assertNull(data_get($compiled[0], 'niche.shadow_mutation_gene'));
        $this->assertSame('frozen_control', data_get($compiled[0], 'niche.shadow_research_lane.role'));
        $this->assertSame('architecture_explorer', data_get($compiled[0], 'niche.shadow_research_lane.replaced_role'));
        $this->assertSame('frozen_shadow_control', data_get($compiled[0], 'niche.replacement_contract.replacement_mode'));
        $this->assertFalse((bool) data_get($compiled[0], 'niche.replacement_contract.mutation_credit'));
        $this->assertFalse((bool) data_get($compiled[0], 'niche.replacement_contract.promotion_evidence'));

        $retrySpec = $preemptive->invoke($service, [$spec], 0, $spec, [
            ['slot' => 1, 'reason' => $reason],
        ]);
        $this->assertSame('shadow_control', data_get($retrySpec, 'origin'));
        $this->assertSame('frozen_shadow_control', data_get($retrySpec, 'niche.replacement_contract.replacement_mode'));
    }

    public function test_dependency_blocked_shadow_seat_uses_the_same_non_promoting_control_fallback(): void
    {
        $service = app(LabPopulationService::class);
        $replaceable = new \ReflectionMethod($service, 'canReplaceMutationConstruction');
        $replaceable->setAccessible(true);
        $replacements = new \ReflectionMethod($service, 'zeroDiffReplacementSpecs');
        $replacements->setAccessible(true);
        $spec = [
            'origin' => 'robust_crossover',
            'family' => 'hybrid',
            'target' => 'robustness',
            'niche' => [
                'shadow_only' => true,
                'shadow_mutation_gene' => 'cooldown_shadow_min_samples',
                'shadow_mutation_contract' => ['gene' => 'cooldown_shadow_min_samples'],
            ],
        ];
        $reason = 'RISK_MUTATION_BEFORE_EDGE_CONFIRMATION';

        $this->assertTrue($replaceable->invoke($service, $spec, $reason));
        $compiled = $replacements->invoke($service, [$spec], 0, $spec, $reason, true);

        $this->assertCount(1, $compiled);
        $this->assertSame('shadow_control', data_get($compiled[0], 'origin'));
        $this->assertSame($reason, data_get($compiled[0], 'niche.replacement_contract.original_failure_reason'));
        $this->assertTrue((bool) data_get($compiled[0], 'niche.control_only'));
    }

    public function test_blocked_targeted_allocation_is_executable_and_reports_zero_targeted_seats(): void
    {
        $service = app(LabPopulationService::class);
        $method = new \ReflectionMethod($service, 'reallocateBlockedTargetedPrerequisites');
        $method->setAccessible(true);
        $base = [
            'origin' => 'targeted_failure_profile',
            'family' => 'hybrid',
            'target' => 'drawdown_risk',
            'allocation_lane' => 'targeted_rescue',
            'niche' => ['protocol' => LabPopulationService::TARGETED_RESCUE_PROFILE_PROTOCOL],
        ];
        $plan = [
            [...$base, 'niche' => [...$base['niche'], 'declared_gene' => 'time_stop_candles']],
            [...$base, 'target' => 'regime_coverage', 'niche' => [...$base['niche'], 'declared_gene' => 'trend_roc_threshold']],
            [...$base, 'niche' => [...$base['niche'], 'control_only' => true]],
            [...$base, 'target' => 'architecture', 'niche' => [...$base['niche'], 'architecture_experiment' => true]],
        ];

        $reallocated = $method->invoke($service, $plan, true);
        $audit = app(ResearchAllocationPolicyService::class)->audit($reallocated, true);

        $this->assertNotSame('time_stop_candles', data_get($reallocated[0], 'niche.declared_gene'));
        $this->assertSame('profit_factor', data_get($reallocated[0], 'target'));
        $this->assertSame('architecture_signal', data_get($reallocated[0], 'allocation_lane'));
        $this->assertSame(0, data_get($audit, 'targeted_rescue_observed'));
        $this->assertSame('audited', data_get($audit, 'allocation_status'));
    }

    public function test_population_build_defers_while_another_entry_point_owns_the_constructor(): void
    {
        $lockKey = 'lab-population-constructor:XAUUSD:H1:v1';
        $ownerKey = $lockKey.':owner';
        $lock = Cache::lock($lockKey, LabPopulationService::CONSTRUCTOR_LOCK_TTL_SECONDS);
        $this->assertTrue($lock->get());
        $owner = [
            'protocol' => 'lab_population_constructor_owner_v1',
            'operation' => 'build',
            'trigger' => 'learning_confirmation',
            'command' => 'artisan trading:dispatch-lab XAUUSD',
        ];
        Cache::put($ownerKey, $owner, 60);

        try {
            $service = app(LabPopulationService::class);
            $this->assertNull($service->build('XAUUSD', 'new_data', false, 'M15'));
            $this->assertSame('GENERATION_CONSTRUCTOR_ACTIVE', data_get($service->lastBuildOutcome(), 'reason_code'));
            $this->assertTrue((bool) data_get($service->lastBuildOutcome(), 'retryable'));
            $this->assertSame($owner, data_get($service->lastBuildOutcome(), 'context.lock_owner'));
        } finally {
            $lock->release();
            Cache::forget($ownerKey);
        }
    }

    public function test_interrupted_constructor_cannot_modify_an_older_generation(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'XAUUSD Unified MTF Organism',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid'],
            'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
        $older = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 1,
            'trigger_type' => 'candidate_handoff',
            'population_size' => 1,
            'status' => 'technical_quarantine',
            'trigger_context' => ['generation_plan' => [['family' => 'hybrid']]],
        ]);
        $newer = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 2,
            'trigger_type' => 'candidate_handoff',
            'population_size' => 20,
            'status' => 'draft',
            'trigger_context' => ['generation_plan' => array_fill(0, 20, ['family' => 'hybrid'])],
        ]);

        $result = app(LabPopulationService::class)->continueInterruptedConstruction($older->id, 1);

        $this->assertSame('superseded_by_newer_generation', $result['status']);
        $this->assertSame(0, $older->agents()->count());
        $this->assertSame('draft', $newer->fresh()->status);
    }

    public function test_parameter_change_without_signal_or_ledger_change_is_not_observable(): void
    {
        [$child, $candidate] = $this->childAndCandidate(false);

        $observability = app(MutationObservabilityService::class)->assess($child, $candidate);

        $this->assertSame('mutation_no_observable_effect', $observability['classification']);
        $this->assertFalse(data_get($observability, 'gate_margin.target_gate_improved'));

        app(MutationObservabilityService::class)->record($child, $observability);
        $this->assertSame(
            'mutation_no_observable_effect',
            data_get($child->modelVersion->fresh()->metadata, 'mutation_observability.classification'),
        );
    }

    public function test_expected_invariants_do_not_count_as_a_behavioral_effect(): void
    {
        $service = app(MutationObservabilityService::class);
        $method = new \ReflectionMethod($service, 'expectedBehavioralAssessment');
        $method->setAccessible(true);

        $assessment = $method->invoke($service, [
            'raw_signal' => 'unchanged',
            'veto_channel' => 'confidence',
            'veto_direction' => 'decrease',
            'accepted_entries' => 'increase_or_same',
        ], [
            'entry_funnel' => ['raw_strategy_signals' => 100, 'accepted_entries' => 12, 'rejected' => ['confidence' => 0]],
        ], [
            'entry_funnel' => ['raw_strategy_signals' => 100, 'accepted_entries' => 12, 'rejected' => ['confidence' => 0]],
        ], false);

        $this->assertSame('no_expected_effect', data_get($assessment, 'status'));
        $this->assertSame('not_applicable_no_baseline_veto', data_get($assessment, 'checks.veto_channel.status'));
    }

    public function test_exit_mutation_is_checked_against_its_declared_exit_plane(): void
    {
        $service = app(MutationObservabilityService::class);
        $method = new \ReflectionMethod($service, 'expectedBehavioralAssessment');
        $method->setAccessible(true);

        $assessment = $method->invoke($service, ['exit_state' => 'change'], [
            'exit_distribution' => ['atr_stop' => 8, 'target' => 4],
        ], [
            'exit_distribution' => ['atr_stop' => 5, 'target' => 7],
        ], true);

        $this->assertSame('matched', data_get($assessment, 'status'));
        $this->assertSame('matched', data_get($assessment, 'checks.exit_state.status'));
    }

    public function test_structural_claim_without_its_declared_plane_is_evidence_incomplete_not_generic_success(): void
    {
        $service = app(MutationObservabilityService::class);
        $method = new \ReflectionMethod($service, 'expectedBehavioralAssessment');
        $method->setAccessible(true);

        $assessment = $method->invoke($service, [
            'entry_topology' => 'change',
            'regime_coverage' => 'change',
            'accepted_trade_set' => 'change',
        ], ['signal_decision_hash' => 'candidate-signal'], ['signal_decision_hash' => 'baseline-signal'], true);

        $this->assertSame('evidence_incomplete', data_get($assessment, 'status'));
        $this->assertSame('evidence_incomplete', data_get($assessment, 'checks.entry_topology.status'));
        $this->assertSame('evidence_incomplete', data_get($assessment, 'checks.regime_coverage.status'));
    }

    public function test_exit_claim_is_contradicted_when_it_materially_changes_entry_count(): void
    {
        $service = app(MutationObservabilityService::class);
        $method = new \ReflectionMethod($service, 'expectedBehavioralAssessment');
        $method->setAccessible(true);

        $assessment = $method->invoke($service, ['accepted_entries' => 'unchanged_or_near'], [
            'entry_funnel' => ['accepted_entries' => 140],
        ], [
            'entry_funnel' => ['accepted_entries' => 100],
        ], true);

        $this->assertSame('contradicted', data_get($assessment, 'status'));
        $this->assertSame('contradicted', data_get($assessment, 'checks.accepted_entries.status'));
        $this->assertSame(10, data_get($assessment, 'checks.accepted_entries.tolerance'));
    }

    public function test_non_directional_veto_claim_matches_when_the_veto_opens(): void
    {
        $service = app(MutationObservabilityService::class);
        $method = new \ReflectionMethod($service, 'expectedBehavioralAssessment');
        $method->setAccessible(true);

        $assessment = $method->invoke($service, ['veto_channel' => 'regime'], [
            'entry_funnel' => ['rejected' => ['regime' => 312]],
        ], [
            'entry_funnel' => ['rejected' => ['regime' => 0]],
        ], true);

        $this->assertSame('matched', data_get($assessment, 'status'));
        $this->assertSame('matched', data_get($assessment, 'checks.veto_channel.status'));
    }

    public function test_changed_signal_and_ledger_are_learning_observable_but_not_promotion_evidence(): void
    {
        [$child, $candidate] = $this->childAndCandidate(true);

        $observability = app(MutationObservabilityService::class)->assess($child, $candidate);

        $this->assertSame('observable_effect', $observability['classification']);
        $this->assertSame('profit_factor', $observability['declared_target']);
        $this->assertSame('profit_factor', $observability['target']);
        $this->assertTrue(data_get($observability, 'signal_decisions.changed'));
        $this->assertTrue(data_get($observability, 'trade_ledger.changed'));
        $this->assertFalse($observability['promotion_evidence']);
    }

    public function test_event_digest_is_required_for_final_observability_classification(): void
    {
        [$child, $candidate] = $this->childAndCandidate(true);
        unset($candidate['event_ledger_hash']);

        $observability = app(MutationObservabilityService::class)->assess($child, $candidate);

        $this->assertSame('observability_incomplete', $observability['classification']);
        $this->assertFalse(data_get($observability, 'observable_effect'));
        $this->assertSame('control_missing', data_get($observability, 'control_relative.status'));
    }

    public function test_shadow_mutation_contract_blocks_a_parameter_only_probe(): void
    {
        [$child, $candidate] = $this->childAndCandidate(false);
        $metadata = (array) $child->modelVersion->metadata;
        $metadata['portfolio_council_lane'] = [
            'shadow_research_lane' => ['shadow_only' => true],
            'shadow_mutation_contract' => [
                'protocol' => 'shadow_structural_mutation_v1',
                'gene' => 'entry_topology_variant',
                'behavioral_change_required' => true,
                'trade_ledger_delta_required' => true,
                'control_pair_required' => true,
            ],
        ];
        $child->modelVersion->update(['metadata' => $metadata]);
        $child->refresh()->load('modelVersion', 'parentA');

        $observability = app(MutationObservabilityService::class)->assess($child, $candidate);

        $this->assertTrue(data_get($observability, 'mutation_contract.required'));
        $this->assertSame('failed_evidence_incomplete', data_get($observability, 'mutation_contract.status'));
        $this->assertSame('mutation_no_observable_effect', $observability['classification']);

        $decision = app(CandidateGateDecisionService::class)->recordScreening($child, $candidate);

        $this->assertContains('FAILED_BEHAVIORAL_MUTATION_EVIDENCE', (array) $decision->reason_codes);
        $this->assertSame('failed', $decision->decision);
    }

    public function test_data_edge_root_cohort_is_not_failed_for_missing_parent_control_pair(): void
    {
        [$child, $candidate] = $this->childAndCandidate(false);
        $child->generation->update([
            'trigger_type' => 'data_edge_audit',
            'trigger_context' => ['research_allocation_budget' => ['mode' => 'normal_research']],
        ]);
        $child->refresh()->load('modelVersion', 'parentA', 'generation');

        $observability = app(MutationObservabilityService::class)->assess($child, $candidate);

        $this->assertFalse(data_get($observability, 'mutation_contract.required'));
        $this->assertSame('not_required', data_get($observability, 'mutation_contract.status'));
        $this->assertTrue(data_get($observability, 'mutation_contract.root_data_edge_audit_exempt'));
        $this->assertSame('root_seed_observability', data_get($observability, 'classification'));
    }

    public function test_root_portfolio_intervention_requires_its_declared_control_pair(): void
    {
        [$child, $candidate] = $this->childAndCandidate(true);
        $child->generation->update(['trigger_type' => 'data_edge_audit']);
        $metadata = (array) $child->modelVersion->metadata;
        $metadata['root_experiment_portfolio'] = true;
        $metadata['control_pair_contract'] = ['required_for_candidate' => true, 'pair_key' => 'root-pair'];
        $child->modelVersion->update(['metadata' => $metadata]);
        $child->refresh()->load('modelVersion', 'parentA', 'generation');

        $observability = app(MutationObservabilityService::class)->assess($child, $candidate);

        $this->assertTrue(data_get($observability, 'mutation_contract.required'));
        $this->assertTrue(data_get($observability, 'mutation_contract.root_portfolio_intervention'));
        $this->assertFalse(data_get($observability, 'mutation_contract.root_data_edge_audit_exempt'));
    }

    public function test_reconciliation_never_upgrades_a_failed_gate_projection(): void
    {
        [$child, $candidate] = $this->childAndCandidate(false);
        $decision = CandidateGateDecision::create([
            'lab_agent_id' => $child->id,
            'stage' => 'screening',
            'decision' => 'failed',
            'reason_codes' => ['FAILED_PROFIT_FACTOR'],
            'metrics' => ['promotion_evidence' => false],
            'evaluated_at' => now(),
        ]);

        $this->artisan('trading:reconcile-mutation-contracts', [
            'symbol' => 'XAUUSD',
            '--timeframe' => 'H1',
            '--limit' => 10,
            '--apply' => true,
            '--json' => true,
        ])->assertExitCode(0);

        $this->assertSame('failed', $decision->fresh()->decision);
        $this->assertFalse((bool) data_get($decision->fresh()->metrics, 'promotion_evidence'));
    }

    /** @return array{0: LabAgent, 1: array<string, mixed>} */
    private function childAndCandidate(bool $changed): array
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Mutation observability test', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'population_size' => 2, 'status' => 'screened', 'trigger_context' => [],
        ]);
        $baselineResult = [
            'signal_decision_hash' => 'signal-old',
            'trade_ledger_hash' => 'ledger-old',
            'event_ledger_hash' => 'event-old',
            'total_trades' => 10,
            'profit_factor' => 1.0,
            'data_manifest' => ['sha256' => str_repeat('a', 64)],
            'execution_contract' => ['execution_hash' => str_repeat('b', 64)],
        ];
        $parent = ModelVersion::create([
            'name' => 'observability-parent', 'strategy' => 'hybrid', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => ['minimum_signal_confidence' => .5],
            'metadata' => ['last_screen_result' => $baselineResult, 'generation_target' => 'profit_factor'],
            'evidence_status' => 'valid',
        ]);
        $childModel = ModelVersion::create([
            'name' => 'observability-child', 'strategy' => 'hybrid', 'version' => 'v2',
            'generation' => 1, 'status' => 'testing', 'parameters' => ['minimum_signal_confidence' => .55],
            'metadata' => [
                'last_screen_result' => $baselineResult,
                'generation_target' => 'profit_factor',
                'parameter_fingerprint' => str_repeat('c', 64),
            ],
            'evidence_status' => 'valid',
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $childModel->id,
            'parent_a_model_version_id' => $parent->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'origin' => 'targeted_failure_profile',
            'lifecycle_status' => 'screened',
            'parameter_diff' => ['minimum_signal_confidence' => ['old' => .5, 'new' => .55]],
            'sample_count' => 10, 'profit_factor' => 1.0,
        ]);
        $candidate = $baselineResult;
        if ($changed) {
            $candidate['signal_decision_hash'] = 'signal-new';
            $candidate['trade_ledger_hash'] = 'ledger-new';
            $candidate['event_ledger_hash'] = 'event-new';
            $candidate['total_trades'] = 12;
            $candidate['profit_factor'] = 1.15;
        }

        return [$agent->fresh(['modelVersion', 'parentA']), $candidate];
    }
}
