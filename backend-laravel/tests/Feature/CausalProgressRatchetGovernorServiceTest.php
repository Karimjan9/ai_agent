<?php

namespace Tests\Feature;

use App\Services\CausalProgressRatchetGovernorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CausalProgressRatchetGovernorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_trigger_scaffold_advances_once_and_a_later_noop_cannot_reduce_depth(): void
    {
        $governor = app(CausalProgressRatchetGovernorService::class);
        $context = [
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'composition_key' => 'trend_pullback',
            'causal_baseline_id' => 17, 'baseline_epoch_hash' => str_repeat('a', 64),
            'data_hash' => str_repeat('b', 64), 'execution_hash' => str_repeat('c', 64), 'axis' => 'trigger_topology_policy',
        ];
        $control = ['value' => 'balanced', 'entry_contract_funnel' => ['stage_counts' => [
            'opportunity' => 10, 'location' => 8, 'setup' => 6, 'confirmation' => 4, 'trigger' => 1,
        ]]];
        $candidate = ['value' => 'aggressive', 'entry_contract_funnel' => ['stage_counts' => [
            'opportunity' => 10, 'location' => 8, 'setup' => 6, 'confirmation' => 4, 'trigger' => 3,
        ]], 'total_trades' => 2];
        $assessment = [
            'status' => 'controllable', 'target_stage' => 'trigger', 'owner' => ['gene' => 'trigger_topology_policy'],
            'candidate_counts' => ['confirmation' => 4, 'trigger' => 3], 'event_delta' => 2,
        ];

        $first = $governor->recordAssessment($assessment, $context, $control, $candidate);
        $noop = $governor->recordAssessment([
            'status' => 'non_controlling_axis', 'target_stage' => 'trigger', 'owner' => ['gene' => 'trigger_topology_policy'],
            'candidate_counts' => ['confirmation' => 4, 'trigger' => 3], 'event_delta' => 0,
        ], $context, $control, $candidate);

        $this->assertSame('trigger', $first['deepest_stage']);
        $this->assertSame('path_activating_scaffold', $first['authority']);
        $this->assertSame('trigger', $noop['deepest_stage']);
        $this->assertSame('non_controlling_axis', data_get($noop, 'classification.classification'));
        $this->assertSame('pending_scaffold_removal_ablation', data_get($first, 'scaffold_ablation.status'));
        $this->assertDatabaseCount('causal_progress_ratchets', 1);
    }

    public function test_unreachable_axis_pivots_upstream_instead_of_being_retired_and_progress_is_exactly_once(): void
    {
        $governor = app(CausalProgressRatchetGovernorService::class);
        $assessment = ['status' => 'non_controlling_axis', 'target_stage' => 'trigger',
            'candidate_counts' => ['confirmation' => 0], 'event_delta' => 0];
        $classification = $governor->classify($assessment, [], []);
        $context = ['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'composition_key' => 'range_reentry',
            'causal_baseline_id' => 18, 'baseline_epoch_hash' => str_repeat('d', 64),
            'data_hash' => str_repeat('e', 64), 'execution_hash' => str_repeat('f', 64),
            'window_plan_hash' => str_repeat('1', 64), 'intervention_hash' => str_repeat('2', 64)];

        $one = $governor->progress($context, 'CONFIRMATION_QUEUED', 'queued');
        $two = $governor->progress($context, 'CONFIRMATION_QUEUED', 'queued');

        $this->assertSame('upstream_unreachable', $classification['classification']);
        $this->assertFalse($classification['retire']);
        $this->assertSame('repair_upstream_stage', $classification['next_action']);
        $this->assertSame($one['progress_key'], $two['progress_key']);
        $this->assertDatabaseCount('causal_progress_states', 1);
    }

    public function test_all_debt_closed_keeps_topology_and_adversarial_lanes_nonzero(): void
    {
        $allocation = app(CausalProgressRatchetGovernorService::class)->allocate('XAUUSD', 'H1', true);

        $this->assertSame('all_debt_closed', $allocation['mode']);
        $this->assertGreaterThan(0, $allocation['allocation_percent']['topology_pivot']);
        $this->assertGreaterThan(0, $allocation['allocation_percent']['adversarial_falsification']);
        $this->assertSame(['edge_exploration', 'edge_exploration', 'edge_exploration', 'skill_consolidation'], $allocation['cadence']);
        $this->assertFalse($allocation['invariants']['scalar_exploration_blocked']);
    }

    public function test_high_value_debt_blocks_new_scalar_search_but_keeps_a_topology_pivot_admissible(): void
    {
        foreach (range(1, 8) as $id) {
            DB::table('causal_progress_ratchets')->insert([
                'ratchet_key' => hash('sha256', 'ratchet-'.$id), 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
                'composition_key' => 'composition-'.$id, 'causal_baseline_id' => $id,
                'baseline_epoch_hash' => hash('sha256', 'baseline-'.$id), 'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
                'deepest_stage' => 'trigger', 'stage_depth' => 5, 'authority' => 'path_activating_scaffold', 'status' => 'active',
                'evidence' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $governor = app(CausalProgressRatchetGovernorService::class);
        $scalar = $governor->admitExpensivePacket(['structural_axis' => 'max_chase_atr'], 'XAUUSD', 'H1');
        $pivot = $governor->admitExpensivePacket(['structural_axis' => 'trigger_topology_policy'], 'XAUUSD', 'H1');

        $this->assertFalse($scalar['allowed']);
        $this->assertSame('HIGH_VALUE_PROMOTION_DEBT_CONSOLIDATION_REQUIRED', $scalar['reason']);
        $this->assertTrue($pivot['allowed']);
    }

    public function test_consolidation_plan_is_idempotent_settlement_work_not_new_cohort_creation(): void
    {
        $plan = app(CausalProgressRatchetGovernorService::class)->consolidationPlan('XAUUSD', 'H1');

        $this->assertSame('planned', $plan['status']);
        $this->assertFalse($plan['new_cohort_creation_allowed']);
        $this->assertArrayHasKey('edge_terminal_settlement', $plan['actions']);
        $this->assertArrayHasKey('legacy_cartridge_terminalization', $plan['actions']);
        $this->assertDatabaseCount('lab_generations', 0);
    }

    public function test_only_a_powered_safe_positive_after_cost_replay_opens_risk_shaping(): void
    {
        $governor = app(CausalProgressRatchetGovernorService::class);
        $context = ['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'composition_key' => 'break_retest', 'causal_baseline_id' => 9,
            'baseline_epoch_hash' => str_repeat('a', 64), 'data_hash' => str_repeat('b', 64), 'execution_hash' => str_repeat('c', 64)];
        $governor->recordAssessment(['status' => 'controllable', 'target_stage' => 'trigger', 'owner' => ['gene' => 'trigger_topology_policy'],
            'candidate_counts' => ['confirmation' => 2, 'trigger' => 4], 'event_delta' => 2], $context, [], ['total_trades' => 2]);
        $rejected = $governor->recordPositiveAfterCostEdge($context, ['total_trades' => 1, 'after_cost_expectancy_r' => .2]);
        $accepted = $governor->recordPositiveAfterCostEdge($context, ['total_trades' => 12, 'after_cost_expectancy_r' => .2]);
        $matrix = $governor->mutationAuthority('positive_after_cost_edge');

        $this->assertSame('economic_edge_not_admitted', $rejected['status']);
        $this->assertSame('economic_edge_recorded', $accepted['status']);
        $this->assertTrue($matrix['risk_allowed']);
        $this->assertFalse($matrix['paper_allowed']);
        $this->assertFalse($matrix['parent_allowed']);
    }

    public function test_scaffold_ablation_revokes_a_scaffold_that_has_no_causal_effect(): void
    {
        $governor = app(CausalProgressRatchetGovernorService::class);
        $context = ['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'composition_key' => 'scaffold-ablation', 'causal_baseline_id' => 3,
            'baseline_epoch_hash' => str_repeat('a', 64), 'data_hash' => str_repeat('b', 64), 'execution_hash' => str_repeat('c', 64)];
        $governor->recordAssessment(['status' => 'controllable', 'target_stage' => 'trigger', 'owner' => ['gene' => 'trigger_topology_policy'],
            'candidate_counts' => ['confirmation' => 1, 'trigger' => 2], 'event_delta' => 1], $context, [], []);
        $result = $governor->recordAblation($context, ['trigger' => 2], ['trigger' => 2]);

        $this->assertSame('scaffold_authority_revoked', $result['status']);
        $this->assertDatabaseHas('causal_progress_ratchets', ['composition_key' => 'scaffold-ablation', 'authority' => 'revoked']);
    }

    public function test_governor_command_is_read_only_unless_a_write_option_is_explicit(): void
    {
        $this->artisan('trading:causal-progress-governor XAUUSD --timeframe=H1 --json')
            ->assertExitCode(0);

        $this->assertDatabaseCount('causal_governor_allocations', 0);
        $this->assertDatabaseCount('causal_governor_debt_ledgers', 0);

        $this->artisan('trading:causal-progress-governor XAUUSD --timeframe=H1 --persist-telemetry --json')
            ->assertExitCode(0);

        $this->assertDatabaseCount('causal_governor_allocations', 1);
        $this->assertDatabaseCount('causal_governor_debt_ledgers', 1);
    }
}
