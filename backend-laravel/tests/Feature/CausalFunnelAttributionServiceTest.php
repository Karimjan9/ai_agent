<?php

namespace Tests\Feature;

use App\Services\CausalFunnelAttributionService;
use Tests\TestCase;

class CausalFunnelAttributionServiceTest extends TestCase
{
    public function test_confidence_bottleneck_routes_to_a_confidence_ablation(): void
    {
        $assessment = app(CausalFunnelAttributionService::class)->assess([
            'total_trades' => 2,
            'entry_funnel' => [
                'raw_strategy_signals' => 100,
                'accepted_entries' => 2,
                'dominant_rejection' => 'minimum_confidence',
                'rejected' => ['minimum_confidence' => 72, 'ev_lower_bound' => 12, 'regime_transition_wait' => 4],
            ],
        ]);

        $this->assertSame('confidence', $assessment['bottleneck']);
        $this->assertSame('confidence_funnel_ablation', $assessment['recommended_experiment_lane']);
        $this->assertSame(0.02, $assessment['acceptance_rate']);
        $this->assertSame('entry_funnel_veto', data_get($assessment, 'failure_decomposition.primary_failure_mode'));
    }

    public function test_stress_failure_routes_to_exit_cost_instead_of_a_generic_repair(): void
    {
        $assessment = app(CausalFunnelAttributionService::class)->assess([
            'profit_factor' => 1.25,
            'stress_test' => ['profit_factor' => .82],
            'reason_codes' => ['FAILED_COST_EXIT_STRESS', 'FAILED_CALENDAR_MONTH_SURVIVAL'],
            'entry_funnel' => ['raw_strategy_signals' => 120, 'accepted_entries' => 32, 'rejected' => []],
        ]);

        $this->assertSame('exit_cost_execution', data_get($assessment, 'failure_decomposition.primary_failure_mode'));
        $this->assertSame('stress_exit_ablation', $assessment['recommended_experiment_lane']);
    }
}
