<?php

namespace Tests\Feature;

use App\Services\InstrumentPolicyConsumptionService;
use Tests\TestCase;

class InstrumentPolicyConsumptionTest extends TestCase
{
    public function test_legacy_gene_only_policy_and_hash_labels_are_not_delta_consumption(): void
    {
        $policy = $this->policy();
        $service = app(InstrumentPolicyConsumptionService::class);
        $receipt = $service->receipt($policy, ['volume_lane' => ['old' => 'none', 'new' => 'confirmed']], [
            'volume_lane' => 'confirmed',
        ]);
        $this->assertSame('not_applied', $receipt['status']);
        $this->assertSame([], $receipt['applied_genes']);
        $this->assertSame([], $receipt['isolated_sources']);
        $this->assertSame([], $receipt['bundle_sources']);
        $this->assertFalse($receipt['paper_execution_authority']);
        $this->assertSame($receipt['receipt_hash'], $service->receipt($policy, [
            'volume_lane' => ['old' => 'none', 'new' => 'confirmed'],
        ], ['volume_lane' => 'confirmed'])['receipt_hash']);
    }

    public function test_retrieval_without_matching_application_or_phase_is_not_consumption(): void
    {
        $service = app(InstrumentPolicyConsumptionService::class);
        $policy = $this->policy();
        $this->assertSame('not_applied', $service->receipt($policy, [
            'risk_pct' => ['old' => .5, 'new' => .4],
        ], ['risk_pct' => .4])['status']);
        unset($policy['context']['venue_phase']);
        $this->assertSame('not_applied', $service->receipt($policy, [
            'volume_lane' => ['old' => 'none', 'new' => 'confirmed'],
        ], ['volume_lane' => 'confirmed'])['status']);
        $policy = $this->policy();
        $policy['bundle_sources'] = [];
        $this->assertSame('not_applied', $service->receipt($policy, [
            'volume_lane' => ['old' => 'none', 'new' => 'confirmed'],
        ], ['volume_lane' => 'confirmed'])['status']);
        $policy = $this->policy();
        $this->assertSame('not_applied', $service->receipt($policy, [
            'volume_lane' => ['old' => 'none', 'new' => 'confirmed'],
        ], ['volume_lane' => 'different'])['status']);
    }

    private function policy(): array
    {
        $context = [
            'regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal',
            'venue_phase' => 'london_am_fix',
        ];
        $stateKey = 'trend_up|london|normal|normal|stable|0|both|hybrid|london_am_fix';

        return [
            'strategy_family' => 'hybrid', 'context' => $context,
            'preferred_genes' => ['volume_lane'],
            'sources' => [[
                'source_type' => 'isolated_instrument', 'instrument_key' => 'volume_confirmation',
                'posterior_id' => 11, 'window_evidence_digest' => str_repeat('a', 64),
                'state' => 'confirmed', 'genes' => ['volume_lane'],
                'state_key' => $stateKey,
                'context' => [...$context, 'strategy_family' => 'hybrid'],
            ]],
            'bundle_sources' => [[
                'source_type' => 'exact_instrument_bundle',
                'primary_instrument_key' => 'volume_confirmation',
                'posterior_id' => 12, 'state' => 'confirmed',
                'window_evidence_digest' => str_repeat('b', 64),
                'state_key' => $stateKey,
                'context' => [...$context, 'strategy_family' => 'hybrid'],
            ]],
        ];
    }
}
