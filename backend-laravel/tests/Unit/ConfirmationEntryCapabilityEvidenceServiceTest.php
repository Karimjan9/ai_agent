<?php

namespace Tests\Unit;

use App\Services\CausalEdgeAccountingService;
use App\Services\ConfirmationEntryCapabilityEvidenceService;
use Tests\TestCase;

class ConfirmationEntryCapabilityEvidenceServiceTest extends TestCase
{
    public function test_observed_funnel_is_process_evidence_not_rule_authority(): void
    {
        $result = $this->replayResult();
        $evidence = app(ConfirmationEntryCapabilityEvidenceService::class)->project($result);

        $this->assertSame('process_observed_outcome_unpowered', $evidence['status']);
        $this->assertSame(174, data_get($evidence, 'stage_counts.setup'));
        $this->assertSame(.75, data_get($evidence, 'process_scores.confirmation_quality'));
        $this->assertTrue((bool) data_get($evidence, 'process_power.confirmation_quality'));
        $this->assertFalse((bool) data_get($evidence, 'dimension_power.confirmation_quality'));
        $this->assertTrue((bool) data_get($evidence, 'causal_outcome_power.conversion_rate_is_not_quality'));
        $this->assertFalse($evidence['promotion_evidence']);

        $projection = app(CausalEdgeAccountingService::class)->project($result);
        $this->assertSame(
            ConfirmationEntryCapabilityEvidenceService::PROTOCOL,
            data_get($projection, 'confirmation_entry_capability_evidence.protocol'),
        );
        $this->assertSame(.75, data_get($projection, 'evolving_trader_fitness.dimensions.confirmation_quality.score'));
        $this->assertSame('underpowered', data_get($projection, 'evolving_trader_fitness.dimensions.confirmation_quality.status'));
    }

    public function test_paired_oos_ablation_powers_dimensions_without_granting_promotion(): void
    {
        $result = $this->replayResult();
        $result['confirmation_entry_ablation'] = [
            'status' => 'complete_powered',
            'paired_control' => true,
            'powered_windows' => 9,
        ];

        $evidence = app(ConfirmationEntryCapabilityEvidenceService::class)->project($result);

        $this->assertSame('outcome_powered', $evidence['status']);
        $this->assertTrue((bool) data_get($evidence, 'dimension_power.setup_selection'));
        $this->assertTrue((bool) data_get($evidence, 'dimension_power.confirmation_quality'));
        $this->assertTrue((bool) data_get($evidence, 'dimension_power.entry_precision'));
        $this->assertFalse($evidence['promotion_evidence']);
    }

    /** @return array<string,mixed> */
    private function replayResult(): array
    {
        return [
            'total_trades' => 20,
            'entry_contract_funnel' => [
                'protocol' => 'entry_contract_funnel_v2',
                'count_semantics' => 'ordered_cumulative_pipeline',
                'status' => 'observed',
                'stage_counts' => [
                    'setup' => 174,
                    'confirmation' => 16,
                    'trigger' => 12,
                    'entry_ready' => 9,
                ],
                'grade_distribution' => ['A+' => 20, 'A' => 40, 'B' => 80, 'SKIP' => 34],
                'confirmation_cost' => [
                    'average_independent_count' => 3,
                    'average_raw_count' => 4,
                    'average_chase_distance_atr_after_trigger' => .25,
                ],
            ],
            'parameters' => ['max_chase_atr' => 1.25],
        ];
    }
}
