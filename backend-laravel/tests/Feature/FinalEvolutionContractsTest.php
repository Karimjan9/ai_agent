<?php

namespace Tests\Feature;

use App\Services\ContextualMutationBanditService;
use App\Services\GateContractService;
use App\Services\ProgressLadderService;
use App\Services\ResearchAllocationPolicyService;
use App\Services\ShadowResearchGovernorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinalEvolutionContractsTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_and_train_forward_failures_have_distinct_canonical_gates(): void
    {
        $contracts = app(GateContractService::class)->contracts([
            'FAILED_CALENDAR_MONTH_SURVIVAL',
            'FAILED_TRAIN_FORWARD_GAP',
        ]);

        $this->assertSame('calendar_stability', data_get($contracts, '0.gate'));
        $this->assertSame('train_forward_robustness', data_get($contracts, '1.gate'));
        $this->assertNotSame(data_get($contracts, '0.lane'), data_get($contracts, '1.lane'));
        $this->assertContains('window_survival.positive_windows', (array) data_get($contracts, '0.observed_metric'));
        $this->assertSame(3.0, data_get($contracts, '0.screening_contract.threshold'));
        $this->assertContains('screening_survival.train_forward_gap', (array) data_get($contracts, '1.observed_metric'));
        $this->assertSame(25.0, data_get($contracts, '1.screening_contract.threshold'));
    }

    public function test_gate_contract_self_check_is_healthy_before_full_dispatch(): void
    {
        $health = app(GateContractService::class)->health();

        $this->assertTrue($health['healthy']);
        $this->assertSame([], $health['issues']);
        $this->assertFalse($health['promotion_evidence']);
    }

    public function test_progress_ladder_stops_at_the_first_unproven_stage(): void
    {
        $ladder = app(ProgressLadderService::class)->assess([
            'parameter_changed' => true,
            'signal_decisions' => ['changed' => true],
            'trade_ledger' => ['changed' => true],
            'event_ledger' => ['available' => true, 'changed' => true],
            'gate_margin' => ['target_gate_improved' => true],
        ], [
            'screen_decision' => 'failed',
        ], [
            'control_relative_improved' => false,
        ]);

        $this->assertSame('target_deficit_reduced', $ladder['stage']);
        $this->assertSame('control_parity_improved', $ladder['next_stage']);
        $this->assertFalse($ladder['flags']['screening_pass']);
        $this->assertFalse($ladder['promotion_evidence']);
    }

    public function test_contextual_bandit_keys_keep_regime_and_volume_cells_separate(): void
    {
        $service = app(ContextualMutationBanditService::class);
        $base = [
            'target' => 'temporal_stability', 'gene' => 'loss_cooldown_candles',
            'direction' => 'increase', 'regime' => 'trend_up', 'session' => 'london',
            'volume_state' => 'normal', 'temporal_window' => '2026-08', 'side' => 'BUY',
        ];
        $this->assertNotSame(
            $service->key($base),
            $service->key([...$base, 'volume_state' => 'high']),
        );
        $this->assertNotSame(
            $service->key($base),
            $service->key([...$base, 'regime' => 'range']),
        );
    }

    public function test_shadow_escape_allocation_has_one_authoritative_source(): void
    {
        $governor = app(ShadowResearchGovernorService::class);
        $policy = app(ResearchAllocationPolicyService::class);
        $governorAllocation = $governor->allocation(20, true);
        $policyAllocation = $policy->shadowAllocation(20, true);
        $contract = $policy->shadowContract(true, 20);

        $this->assertSame($policyAllocation['counts'], $governorAllocation['counts']);
        $this->assertSame($policyAllocation['shares'], $contract['effective_shares']);
        $this->assertSame(1, $contract['counts']['frozen_control']);
        $this->assertArrayNotHasKey('targeted_repair', $contract['counts']);
        $this->assertFalse($contract['promotion_evidence']);
    }
}
