<?php

namespace Tests\Unit;

use App\Services\CausalStageMasteryDirectorService;
use Tests\TestCase;

class CausalStageMasteryDirectorServiceTest extends TestCase
{
    public function test_declared_topology_must_change_its_owned_transition_without_disturbing_upstream(): void
    {
        $service = app(CausalStageMasteryDirectorService::class);
        $control = ['value' => 'balanced_retest_reaction', 'entry_contract_funnel' => ['stage_counts' => [
            'opportunity' => 10, 'location' => 8, 'setup' => 6, 'confirmation' => 4, 'trigger' => 1, 'entry_ready' => 1,
        ]], 'total_trades' => 1];
        $candidate = ['value' => 'aggressive_structure_close', 'entry_contract_funnel' => ['stage_counts' => [
            'opportunity' => 10, 'location' => 8, 'setup' => 6, 'confirmation' => 4, 'trigger' => 3, 'entry_ready' => 2,
        ]], 'total_trades' => 2];

        $assessment = $service->assess('trigger_topology_policy', $control, $candidate);

        $this->assertSame('controllable', $assessment['status']);
        $this->assertSame('CONFIRMATION_TO_TRIGGER', data_get($assessment, 'owner.transition'));
        $this->assertTrue(data_get($assessment, 'checks.upstream_identity_preserved'));
        $this->assertSame(7, data_get($assessment, 'lexicographic_fitness.funnel_depth'));
        $this->assertFalse($assessment['promotion_evidence']);
    }

    public function test_noop_or_multi_axis_intervention_cannot_receive_causal_credit(): void
    {
        $service = app(CausalStageMasteryDirectorService::class);
        $axis = $service->inferAxis(['entry_mode' => 'balanced'], ['entry_mode' => 'aggressive', 'entry_model' => 'breakout_retest']);
        $noop = $service->assess('trigger_topology_policy', ['value' => 'balanced'], ['value' => 'aggressive']);

        $this->assertSame('not_assessable', $axis['status']);
        $this->assertSame('MULTI_AXIS_INTERVENTION', $axis['reason']);
        $this->assertSame('non_controlling_axis', $noop['status']);
        $this->assertFalse(data_get($noop, 'checks.target_transition_changed'));
    }
}
