<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\InstrumentValuePosterior;
use App\Models\LabAgent;
use App\Models\LabEvolutionCreditEvent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\TradingInstrument;
use App\Services\ContextualCouncilAllocatorService;
use App\Services\ResearchAllocationPolicyService;
use App\Services\TradingInstrumentOperatingSystemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContextualCouncilAllocatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_twenty_seats_are_dynamic_contextual_exact_pair_hosts(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'XAUUSD Unified MTF Organism',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid', 'trend', 'breakout'],
            'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
        $plan = $this->plan();

        $allocation = app(ContextualCouncilAllocatorService::class)->allocate($plan, $lab, ['exploration_ratio' => .6]);
        $allocated = $allocation['plan'];
        $contract = $allocation['contract'];

        $this->assertCount(20, $allocated);
        $this->assertSame(10, array_sum($contract['pair_quotas']));
        $this->assertTrue($contract['dynamic']);
        $this->assertTrue($contract['pair_integrity']);
        $this->assertFalse(collect($contract['seat_counts'])->every(fn (int $count): bool => $count === 4));
        $this->assertGreaterThanOrEqual(3, collect($contract['cells'])->pluck('session')->unique()->count());
        $this->assertGreaterThanOrEqual(6, collect($contract['cells'])->pluck('venue_phase')->unique()->count());
        $authorityAllocation = (array) $contract['evolutionary_authority_allocation'];
        $this->assertSame('cold_start', $authorityAllocation['phase']);
        $this->assertSame(8, data_get($authorityAllocation, 'seat_counts.repair_pair'));
        $this->assertSame(4, data_get($authorityAllocation, 'seat_counts.novelty_pair'));
        $this->assertSame(4, data_get($authorityAllocation, 'seat_counts.factorial'));
        $this->assertSame(4, data_get($authorityAllocation, 'seat_counts.coverage_guard')
            + data_get($authorityAllocation, 'seat_counts.adversarial_guard'));
        $learningPortfolio = (array) $contract['multi_modal_learning_portfolio'];
        $this->assertGreaterThanOrEqual(4, $learningPortfolio['method_diversity']);
        $this->assertContains('failure_directed_repair', $learningPortfolio['active_methods']);
        $this->assertContains('bayesian_active_learning', $learningPortfolio['active_methods']);
        $this->assertContains('quality_diversity_novelty', $learningPortfolio['active_methods']);
        $this->assertContains('adversarial_robustness', $learningPortfolio['active_methods']);
        $this->assertGreaterThan(0, data_get($learningPortfolio, 'signals.posterior_entropy_mean'));
        $this->assertGreaterThan(0, data_get($learningPortfolio, 'signals.expected_information_gain_proxy'));

        foreach (array_chunk($allocated, 2) as $pair) {
            $this->assertCount(2, $pair);
            $this->assertSame($pair[0]['research_group'], $pair[1]['research_group']);
            $this->assertSame(
                data_get($pair[0], 'niche.contextual_specialist_cell.cell_hash'),
                data_get($pair[1], 'niche.contextual_specialist_cell.cell_hash'),
            );
            $this->assertSame(
                data_get($pair[0], 'niche.contextual_specialist_cell.session_ownership.session_instance_id'),
                data_get($pair[1], 'niche.contextual_specialist_cell.session_ownership.session_instance_id'),
            );
            $this->assertSame('WAIT', data_get($pair[0], 'niche.contextual_specialist_cell.outside_scope_action'));
            $this->assertSame(
                data_get($pair[0], 'niche.cooperative_experiment_block.block_index'),
                data_get($pair[1], 'niche.cooperative_experiment_block.block_index'),
            );
            $this->assertSame(
                data_get($pair[0], 'niche.learning_method_contract.learning_method'),
                data_get($pair[1], 'niche.learning_method_contract.learning_method'),
            );
            $this->assertSame(
                data_get($pair[0], 'niche.learning_method_contract.selection_receipt.receipt_hash'),
                data_get($pair[1], 'niche.learning_method_contract.selection_receipt.receipt_hash'),
            );
            $this->assertTrue((bool) data_get($pair[0], 'niche.learning_method_contract.selection_receipt.selected_before_mutation'));
        }

        $paired = app(ResearchAllocationPolicyService::class)
            ->materializeNormalControlPairing($allocated, 'XAUUSD', 'H1', 1)['plan'];
        foreach (array_chunk($paired, 2) as $pair) {
            $this->assertSame('exact_frozen_control', data_get($pair[0], 'niche.learning_method_contract.pair_role'));
            $this->assertSame('candidate', data_get($pair[1], 'niche.learning_method_contract.pair_role'));
            $this->assertSame(
                data_get($pair[0], 'niche.learning_method_contract.control_pair_key'),
                data_get($pair[1], 'niche.learning_method_contract.control_pair_key'),
            );
        }
    }

    public function test_session_budget_exploits_local_instrument_value_without_losing_coverage_floor(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'XAUUSD Unified MTF Organism',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid'],
            'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
        app(TradingInstrumentOperatingSystemService::class)->seedDefaults();
        $instrument = TradingInstrument::query()->where('is_abstention', false)->firstOrFail();
        foreach (['asia' => -.9, 'london' => .9, 'new_york' => -.9, 'overlap' => -.9] as $session => $utility) {
            InstrumentValuePosterior::create([
                'trading_instrument_id' => $instrument->id,
                'symbol' => 'XAUUSD',
                'timeframe' => 'M15',
                'state_key' => "trend_up|{$session}|normal|normal|stable|0|both|hybrid",
                'observations' => 20,
                'net_value' => $utility,
                'uncertainty' => .05,
                'decay_state' => 'active',
                'value_vector' => ['conditional_net_utility' => $utility],
                'last_observed_at' => now(),
            ]);
        }

        $contract = app(ContextualCouncilAllocatorService::class)
            ->allocate($this->plan(), $lab, ['exploration_ratio' => .35])['contract'];

        $sessionPairs = (array) $contract['session_pair_counts'];
        $venuePhasePairs = (array) $contract['venue_phase_pair_counts'];
        $this->assertSame(10, array_sum($sessionPairs));
        $this->assertSame(10, array_sum($venuePhasePairs));
        $this->assertArrayHasKey('comex_maintenance', $venuePhasePairs);
        $this->assertArrayHasKey('london_comex_overlap', $venuePhasePairs);
        $this->assertCount(4, array_filter($sessionPairs, fn (int $pairs): bool => $pairs > 0));
        $this->assertGreaterThan($sessionPairs['asia'], $sessionPairs['london']);
        $this->assertGreaterThan($sessionPairs['new_york'], $sessionPairs['london']);
        $this->assertSame(
            'venue_phase_coverage_rotation_then_contextual_ucb_with_local_success_failure_and_instrument_posterior',
            $contract['session_selection_policy'],
        );
    }

    public function test_early_credits_route_the_next_generation_and_causal_credit_changes_phase(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Credit-routed council', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'trigger_context' => [], 'population_size' => 1, 'status' => 'completed',
        ]);
        $model = ModelVersion::create([
            'name' => 'credit-routing-model', 'strategy' => 'hybrid', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => [
                'generation_target' => 'portfolio_router',
            ], 'evidence_status' => 'valid',
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'rejected',
            'parameter_diff' => ['lookback' => ['old' => 20, 'new' => 24]],
        ]);
        $allocator = app(ContextualCouncilAllocatorService::class);
        $before = $allocator->allocate($this->plan(), $lab)['contract'];

        foreach (['information_credit', 'repair_credit', 'learning'] as $index => $type) {
            LabEvolutionCreditEvent::create([
                'lab_agent_id' => $agent->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
                'event_type' => $type, 'context_key' => hash('sha256', 'credit-route-context'),
                'amount' => 1.0, 'status' => 'observed',
                'evidence_fingerprint' => hash('sha256', "credit-route-{$type}-{$index}"),
                'payload' => ['failure_signature' => ['failure_target' => 'portfolio_router']],
                'recorded_at' => now(),
            ]);
        }
        $routed = $allocator->allocate($this->plan(), $lab)['contract'];

        $this->assertGreaterThan(
            collect($before['priority_ledger'])->where('block_type', 'repair_pair')->max('learning_credit_routing_boost'),
            collect($routed['priority_ledger'])->where('block_type', 'repair_pair')->max('learning_credit_routing_boost'),
        );
        $this->assertSame(2, data_get($routed, 'evidence_snapshot.credits.information_credit'));
        $this->assertSame(1, data_get($routed, 'evidence_snapshot.credits.repair_credit'));
        $this->assertSame('cold_start', data_get($routed, 'evolutionary_authority_allocation.phase'));

        LabEvolutionCreditEvent::create([
            'lab_agent_id' => $agent->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'event_type' => 'causal_skill_credit', 'context_key' => hash('sha256', 'credit-route-context'),
            'amount' => 1.0, 'status' => 'independently_replicated_causal_skill',
            'evidence_fingerprint' => hash('sha256', 'credit-route-causal-skill'),
            'payload' => ['context' => [
                'regime' => 'trend_up', 'session' => 'london',
                'volume_state' => 'normal', 'cost_stress' => 'normal',
            ]], 'recorded_at' => now(),
        ]);
        $compoundingAllocation = $allocator->allocate($this->plan(), $lab);
        $compounding = $compoundingAllocation['contract'];

        $this->assertSame('causal_compounding', data_get($compounding, 'evolutionary_authority_allocation.phase'));
        $this->assertSame(2, data_get($compounding, 'evolutionary_authority_allocation.seat_counts.replication'));
        $this->assertSame(4, data_get($compounding, 'evolutionary_authority_allocation.seat_counts.factorial'));
        $this->assertSame(4, data_get($compounding, 'evolutionary_authority_allocation.seat_counts.transfer'));
        $this->assertSame(4, data_get($compounding, 'evolutionary_authority_allocation.seat_counts.descendant'));
        $this->assertContains('positive_skill_replication', data_get($compounding, 'multi_modal_learning_portfolio.active_methods'));
        $this->assertContains('counterfactual_factorial', data_get($compounding, 'multi_modal_learning_portfolio.active_methods'));
        $this->assertContains('context_transfer_validation', data_get($compounding, 'multi_modal_learning_portfolio.active_methods'));
        $factorial = collect($compounding['experiment_blocks'])->firstWhere('block_type', 'factorial');
        $transfer = collect($compounding['experiment_blocks'])->firstWhere('block_type', 'transfer');
        $this->assertSame(['control', 'a_only', 'b_only', 'a_plus_b'], $factorial['arms']);
        $this->assertSame($factorial['source_context_cell_key'], $factorial['target_context_cell_key']);
        $this->assertSame(['source_control', 'source_candidate', 'target_control', 'target_candidate'], $transfer['arms']);
        $this->assertNotSame($transfer['source_context_cell_key'], $transfer['target_context_cell_key']);
        $pairedCompounding = app(ResearchAllocationPolicyService::class)
            ->materializeNormalControlPairing($compoundingAllocation['plan'], 'XAUUSD', 'H1', 2)['plan'];
        $positivePair = collect(array_chunk($pairedCompounding, 2))->first(
            fn (array $pair): bool => data_get($pair[1], 'niche.learning_method_contract.learning_method') === 'positive_skill_replication',
        );
        $this->assertIsArray($positivePair);
        $this->assertNull(data_get($positivePair[0], 'niche.learning_evolution'));
        $this->assertSame('lookback', data_get($positivePair[1], 'niche.learning_evolution.required_gene'));
        $this->assertSame(20, data_get($positivePair[1], 'niche.learning_evolution.mutation_from'));
        $this->assertSame(24, data_get($positivePair[1], 'niche.learning_evolution.mutation_to'));
        $this->assertSame(
            'same_source_context_bound',
            data_get($positivePair[1], 'niche.learning_method_contract.context_binding.status'),
        );
        $this->assertTrue($factorial['settlement_required']);
        $this->assertTrue($factorial['requires_exact_frozen_controls']);
        $this->assertTrue($transfer['settlement_required']);
        $this->assertTrue($transfer['requires_exact_frozen_controls']);
    }

    public function test_bayesian_acquisition_reads_global_session_evidence_instead_of_permanent_zero_counts(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Posterior acquisition council', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'trigger_context' => [], 'population_size' => 1, 'status' => 'completed',
        ]);
        $model = ModelVersion::create([
            'name' => 'posterior-session-source', 'strategy' => 'hybrid', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => [], 'evidence_status' => 'valid',
            'metadata' => ['specialist_council_membership' => [
                'group_key' => 'regime_coverage',
                'contextual_cell' => ['session' => 'london'],
            ]],
        ]);
        LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'rejected', 'parameter_diff' => [],
        ]);

        $contract = app(ContextualCouncilAllocatorService::class)->allocate($this->plan(), $lab)['contract'];

        $londonObservations = collect(data_get($contract, 'evidence_snapshot.venue_phases'))
            ->filter(fn (array $_row, string $phase): bool => str_starts_with($phase, 'london_'))
            ->sum('observations');
        $londonFailures = collect(data_get($contract, 'evidence_snapshot.venue_phases'))
            ->filter(fn (array $_row, string $phase): bool => str_starts_with($phase, 'london_'))
            ->sum('failures');
        $this->assertSame(1, $londonObservations);
        $this->assertSame(1, $londonFailures);
        $this->assertGreaterThan(0, data_get(
            $contract,
            'multi_modal_learning_portfolio.signals.expected_information_gain_proxy',
        ));
    }

    /** @return array<int, array<string, mixed>> */
    private function plan(): array
    {
        $groups = ['monthly_survival', 'regime_coverage', 'volatility_session_stability', 'exit_topology', 'portfolio_router'];
        $genes = ['lookback', 'minimum_signal_confidence', 'atr_stop_multiplier', 'time_stop_candles'];
        $plan = [];
        foreach ($groups as $group) {
            foreach ($genes as $index => $gene) {
                $plan[] = [
                    'origin' => 'g98_council',
                    'family' => 'hybrid',
                    'target' => $group,
                    'research_group' => $group,
                    'niche' => [
                        'protocol' => 'portfolio_council_v1',
                        'declared_gene' => $gene,
                        'declared_value' => $index + 1,
                        'regime' => $index % 2 === 0 ? 'trend_up' : 'range',
                        'volatility' => 'normal_volatility',
                    ],
                ];
            }
        }

        return $plan;
    }
}
