<?php

namespace Tests\Feature;

use App\Services\ContextualSpecialistAuthorityService;
use Tests\TestCase;

class ContextualSpecialistAuthorityTest extends TestCase
{
    public function test_only_complete_local_causal_evidence_confirms_a_specialist(): void
    {
        $service = app(ContextualSpecialistAuthorityService::class);
        $evidence = $this->evidence();
        $assessment = $service->assess($evidence);

        $this->assertSame('contextually_confirmed_specialist', $assessment['status']);
        $this->assertTrue($assessment['promotion_evidence']);
        $this->assertFalse($assessment['local_evidence_grants_global_inheritance']);

        data_set($evidence, 'outside_scope_activation_count', 1);
        $rejected = $service->assess($evidence);
        $this->assertSame('research_specialist_only', $rejected['status']);
        $this->assertContains('outside_scope_abstention', $rejected['failed_checks']);
    }

    public function test_router_uses_exact_context_lcb_then_baseline_or_wait(): void
    {
        $service = app(ContextualSpecialistAuthorityService::class);
        $london = $this->evidence();
        $asia = $this->evidence();
        data_set($asia, 'identity.venue_phase', 'asia_sge_day');
        data_set($asia, 'qualified_dst_offset_states', []);

        $context = [
            'regime' => 'trend_up', 'venue_phase' => 'london_interfix', 'volatility' => 'normal',
            'spread_liquidity' => 'liquid', 'transition_state' => 'stable', 'direction' => 'BUY',
        ];
        $route = $service->route($context, [
            ['id' => 'asia', 'lower_confidence_bound' => .9, 'evidence' => $asia],
            ['id' => 'london', 'lower_confidence_bound' => .2, 'evidence' => $london],
        ]);
        $this->assertSame('SPECIALIST', $route['action']);
        $this->assertSame('london', $route['specialist_id']);

        $outside = [...$context, 'venue_phase' => 'asia_sge_night'];
        $this->assertSame('BASELINE', $service->route($outside, [['id' => 'london', 'evidence' => $london]], ['eligible' => true])['action']);
        $this->assertSame('WAIT', $service->route($outside, [['id' => 'london', 'evidence' => $london]])['action']);
    }

    /** @return array<string, mixed> */
    private function evidence(): array
    {
        return [
            'identity' => [
                'identity_hash' => hash('sha256', 'london'),
                'regime' => 'trend_up', 'venue_phase' => 'london_interfix', 'volatility' => 'normal',
                'spread_liquidity' => 'liquid', 'transition_state' => 'stable', 'direction' => 'BUY',
            ],
            'screening_status' => 'passed', 'full_replay_status' => 'passed',
            'exact_frozen_control' => true, 'frozen_control_superiority' => true,
            'absolute_settlement' => 4.2,
            'candidate_session_instance_ids' => ['i1', 'i2'],
            'control_session_instance_ids' => ['i1', 'i2'],
            'chronological_windows' => [
                ['candidate_better_than_control' => true, 'absolute_settlement' => 1.1],
                ['candidate_better_than_control' => true, 'absolute_settlement' => 1.4],
            ],
            'qualified_dst_offset_states' => ['+0000', '+0100'],
            'spread_cost_stress_status' => 'passed', 'local_positive_posterior_status' => 'passed',
            'multiple_testing_validation_status' => 'passed', 'other_session_regression_status' => 'passed',
            'outside_scope_activation_count' => 0,
        ];
    }
}
