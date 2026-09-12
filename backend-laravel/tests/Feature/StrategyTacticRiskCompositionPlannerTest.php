<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\CompositionComponentPosterior;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\CanonicalSkillCartridgeService;
use App\Services\CompositionAuthorityKernelService;
use App\Services\CompositionLibrarySettlementService;
use App\Services\CompositionSettlementFanoutService;
use App\Services\StrategyTacticRiskCompositionPlannerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyTacticRiskCompositionPlannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_cohort_receives_four_auditable_causal_packets_with_frozen_passports(): void
    {
        $plan = collect(range(1, 20))->map(fn (int $slot): array => [
            'family' => $slot % 2 ? 'hybrid' : 'differential_router',
            'target' => 'portfolio_router',
            'niche' => $slot <= 3 ? ['structural_research' => true] : [],
        ])->all();

        $result = app(StrategyTacticRiskCompositionPlannerService::class)->materialize($plan);

        $this->assertSame('admitted', $result['contract']['status']);
        $this->assertSame(['hypothesis_packets' => 4, 'arms_per_packet' => 5, 'seats' => 20], $result['contract']['generation_structure']);
        $this->assertSame(.40, $result['contract']['prior_budget_ceiling']);
        $lanes = collect($result['plan'])->countBy(fn (array $seat): string => (string) data_get($seat, 'niche.composition_lane'));
        $this->assertSame(6, $lanes['strategy_composition']);
        $this->assertSame(6, $lanes['tactic_mutation']);
        $this->assertSame(5, $lanes['risk_management_mutation']);
        $this->assertSame(3, $lanes['structural_topology_experiment']);
        $this->assertCount(5, collect($result['plan'])->filter(fn (array $seat): bool => data_get($seat, 'niche.risk_library_contract.paired_control_required') === true));
        $strategySeat = collect($result['plan'])->firstWhere('niche.composition_lane', 'strategy_composition');
        $tacticSeat = collect($result['plan'])->firstWhere('niche.composition_lane', 'tactic_mutation');
        $this->assertContains($strategySeat['family'], ['trend', 'breakout', 'volatility', 'mean_reversion', 'session', 'hybrid']);
        $this->assertNotEmpty(data_get($strategySeat, 'niche.composition_architecture'));
        $this->assertSame('trend', $tacticSeat['family']);
        $this->assertSame('trend_pullback', data_get($tacticSeat, 'niche.composition_architecture'));
        $this->assertCount(4, collect($result['plan'])->pluck('niche.causal_packet.packet_id')->unique());
        $this->assertTrue(collect($result['plan'])->every(fn (array $seat): bool => data_get($seat, 'niche.composition_passport.protocol') === 'xauusd_composition_authority_kernel_v1'));
        $this->assertTrue(collect($result['plan'])->every(fn (array $seat): bool => data_get($seat, 'niche.composition_passport.validation.m1_false_precision_blocked') === true));
    }

    public function test_recovery_cohort_is_not_silently_rewritten_as_a_full_composition_cohort(): void
    {
        $result = app(StrategyTacticRiskCompositionPlannerService::class)->materialize(array_fill(0, 4, ['niche' => []]));

        $this->assertSame('not_applicable_bounded_cohort', $result['contract']['status']);
    }

    public function test_composition_settlement_fails_closed_without_explicit_independent_control_proof(): void
    {
        $lab = AiLaboratory::create(['name' => 'Composition', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_families' => ['hybrid'], 'lifecycle_mode' => 'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test', 'status' => 'completed']);
        $passport = ['protocol' => CompositionAuthorityKernelService::PROTOCOL, 'composition_id' => 'xau-comp-test', 'components' => ['strategy_id' => 'mix_001_trend_beast', 'tactic_id' => 'trend_pullback', 'risk_id' => 'atr_risk_envelope', 'management_id' => 'balanced_professional']];
        $model = ModelVersion::create(['name' => 'composition-child', 'strategy' => 'composition-child', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => ['smart_composition' => ['protocol' => StrategyTacticRiskCompositionPlannerService::PROTOCOL, 'strategy_library_id' => 'mix_001_trend_beast', 'tactic_library_key' => 'trend_pullback', 'risk_library_id' => 'atr_risk_envelope', 'composition_passport' => $passport]]]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'challenger', 'parameter_diff' => []])->fresh('modelVersion');
        $service = app(CompositionLibrarySettlementService::class);

        $blocked = $service->consolidateConfirmed($agent, ['pair_id' => 1, 'lesson_id' => 1]);
        $this->assertSame('not_independently_confirmed', $blocked['status']);
        $this->assertDatabaseCount('composition_settlements', 0);
        $this->assertDatabaseCount('lab_skill_zoo_entries', 0);

        $confirmed = $service->consolidateConfirmed($agent, ['pair_id' => 1, 'lesson_id' => 1, 'paired_control' => true, 'independent_confirmation' => true]);
        $this->assertSame('confirmed_composition_consolidated', $confirmed['status']);
        $this->assertDatabaseCount('composition_settlements', 1);
        $this->assertDatabaseCount('lab_skill_zoo_entries', 1);
    }

    public function test_component_fanout_credits_only_explicit_paired_ablation_and_is_idempotent(): void
    {
        $passport = ['protocol' => CompositionAuthorityKernelService::PROTOCOL, 'composition_id' => 'causal-fanout', 'components' => [
            'strategy_id' => 'hybrid', 'tactic_id' => 'trend_pullback', 'risk_id' => 'atr_risk_envelope', 'management_id' => 'balanced_professional',
        ]];
        $service = app(CompositionSettlementFanoutService::class);
        $blocked = $service->settle([
            'source_key' => 'fanout-1', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'state_key' => 'trend_up|london',
            'after_cost_r' => .4, 'composition_passport' => $passport,
        ]);
        $this->assertSame('settled_packet_only_awaiting_component_ablation', $blocked['status']);
        $this->assertDatabaseCount('composition_component_posteriors', 0);

        $packet = [
            'source_key' => 'fanout-2', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'state_key' => 'trend_up|london',
            'after_cost_r' => .4, 'composition_passport' => $passport,
            'component_effects' => ['tactic' => [
                'component_id' => 'trend_pullback', 'incremental_after_cost_r' => .12,
                'paired_control' => true, 'same_data_hash' => true, 'same_execution_hash' => true, 'non_target_safe' => true,
            ]],
        ];
        $settled = $service->settle($packet);
        $service->settle($packet);

        $this->assertSame('settled_causal_component_effects', $settled['status']);
        $this->assertSame(['tactic'], array_keys($settled['component_posteriors']));
        $posterior = CompositionComponentPosterior::firstOrFail();
        $this->assertSame(1, $posterior->observations);
        $this->assertSame(.12, (float) $posterior->after_cost_value);
        $this->assertFalse($settled['whole_packet_credit_fanned_out']);
    }

    public function test_combination_synergy_uses_factorial_interaction_not_ab_beating_one_arm(): void
    {
        $method = new \ReflectionMethod(CanonicalSkillCartridgeService::class, 'factorialInteraction');
        $service = app(CanonicalSkillCartridgeService::class);

        $subAdditive = $method->invoke($service, 1.0, 1.2, 1.2, 1.3);
        $superAdditive = $method->invoke($service, 1.0, 1.1, 1.1, 1.35);

        $this->assertSame('antagonistic', $subAdditive['status']);
        $this->assertEqualsWithDelta(-.1, $subAdditive['interaction_delta'], .000001);
        $this->assertSame('synergistic', $superAdditive['status']);
        $this->assertEqualsWithDelta(.15, $superAdditive['interaction_delta'], .000001);
    }
}
