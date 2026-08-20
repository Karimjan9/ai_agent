<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\LabPopulationService;
use App\Services\MutationObservabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $decision = app(\App\Services\CandidateGateDecisionService::class)->recordScreening($child, $candidate);

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
        $decision = \App\Models\CandidateGateDecision::create([
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
