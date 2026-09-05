<?php

namespace Tests\Unit;

use App\Services\ConfirmationEntryContractService;
use Tests\TestCase;

class ConfirmationEntryContractServiceTest extends TestCase
{
    public function test_ready_contract_keeps_independent_evidence_and_redundancy_separate(): void
    {
        $contract = app(ConfirmationEntryContractService::class)->compile([
            'protocol' => ConfirmationEntryContractService::PROTOCOL,
            'model' => 'trend_continuation', 'mode' => 'balanced',
            'direction' => 'BUY', 'status' => 'entry_ready', 'stage' => 'entry',
            'order_type' => 'market_after_retest_close',
            'checks' => array_fill_keys([
                'context', 'location', 'setup', 'confirmation', 'trigger',
                'invalidation', 'reward_space', 'chase', 'event',
            ], true),
            'confirmation' => [
                'families' => ['price_reaction', 'market_structure', 'volatility_participation'],
                'independent_count' => 3, 'raw_count' => 6, 'redundancy_penalty' => 3,
            ],
            'reference_price' => 100.0, 'invalidation_price' => 98.0,
            'target_reference_price' => 104.8, 'trigger_anchor_price' => 99.0,
            'structure_atr' => 1.0, 'reward_space_r' => 2.4,
            'chase_distance_atr' => 1.0, 'grade' => 'A+',
        ]);

        $this->assertTrue($contract['executable']);
        $this->assertSame(3, data_get($contract, 'confirmation.independent_count'));
        $this->assertSame(6, data_get($contract, 'confirmation.raw_count'));
        $this->assertSame(3, data_get($contract, 'confirmation.redundancy_penalty'));
        $this->assertSame([], $contract['reason_codes']);
        $this->assertFalse($contract['promotion_evidence']);
        $this->assertTrue($contract['geometry_valid']);
    }

    public function test_setup_cannot_execute_when_exact_trigger_or_reward_space_is_missing(): void
    {
        $checks = array_fill_keys([
            'context', 'location', 'setup', 'confirmation', 'trigger',
            'invalidation', 'reward_space', 'chase', 'event',
        ], true);
        $checks['trigger'] = false;
        $checks['reward_space'] = false;
        $contract = app(ConfirmationEntryContractService::class)->compile([
            'protocol' => ConfirmationEntryContractService::PROTOCOL,
            'status' => 'trigger_missing', 'checks' => $checks,
        ]);

        $this->assertFalse($contract['executable']);
        $this->assertContains('ENTRY_TRIGGER_MISSING', $contract['reason_codes']);
        $this->assertContains('ENTRY_REWARD_SPACE_INSUFFICIENT', $contract['reason_codes']);
    }

    public function test_missing_contract_is_explicit_legacy_projection_not_new_evidence(): void
    {
        $contract = app(ConfirmationEntryContractService::class)->compile([], true);

        $this->assertTrue($contract['executable']);
        $this->assertSame('legacy_route_entry_projection_v1', $contract['protocol']);
        $this->assertFalse($contract['promotion_evidence']);
    }

    public function test_required_contract_never_falls_back_to_legacy_route_execution(): void
    {
        $contract = app(ConfirmationEntryContractService::class)->compile([], true, true, false);

        $this->assertFalse($contract['executable']);
        $this->assertFalse($contract['attested']);
        $this->assertSame('confirmation_entry_contract_missing_v1', $contract['protocol']);
        $this->assertContains('ENTRY_CONTRACT_REQUIRED', $contract['reason_codes']);
    }

    public function test_tampered_geometry_or_confirmation_counts_fail_closed(): void
    {
        $checks = array_fill_keys([
            'context', 'location', 'setup', 'confirmation', 'trigger',
            'invalidation', 'reward_space', 'chase', 'event',
        ], true);
        $contract = app(ConfirmationEntryContractService::class)->compile([
            'protocol' => ConfirmationEntryContractService::PROTOCOL,
            'model' => 'trend_continuation', 'mode' => 'balanced',
            'direction' => 'BUY', 'status' => 'entry_ready', 'stage' => 'entry',
            'order_type' => 'market_after_retest_close', 'checks' => $checks,
            'confirmation' => [
                'families' => ['price_reaction', 'market_structure'],
                'independent_count' => 3, 'raw_count' => 4, 'redundancy_penalty' => 0,
            ],
            'reference_price' => 100, 'invalidation_price' => 101,
            'target_reference_price' => 104, 'trigger_anchor_price' => 99,
            'structure_atr' => 1, 'reward_space_r' => 2, 'chase_distance_atr' => 1,
        ], true, true, true);

        $this->assertFalse($contract['executable']);
        $this->assertContains('ENTRY_CONFIRMATION_EVIDENCE_INCONSISTENT', $contract['reason_codes']);
        $this->assertContains('ENTRY_CONTRACT_GEOMETRY_INCONSISTENT', $contract['reason_codes']);
    }

    public function test_unattested_ready_contract_fails_closed(): void
    {
        $contract = app(ConfirmationEntryContractService::class)->compile([
            'protocol' => ConfirmationEntryContractService::PROTOCOL,
            'model' => 'trend_continuation', 'mode' => 'balanced',
            'status' => 'entry_ready',
        ], true, true, false);

        $this->assertFalse($contract['executable']);
        $this->assertContains('ENTRY_CONTRACT_ATTESTATION_FAILED', $contract['reason_codes']);
    }
}
