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
            ...$this->semanticAssessment(true),
            'status' => 'controllable', 'target_stage' => 'trigger', 'owner' => ['gene' => 'trigger_topology_policy'],
            'candidate_counts' => ['confirmation' => 4, 'trigger' => 3], 'event_delta' => 2,
        ];

        $first = $governor->recordAssessment($assessment, $context, $control, $candidate);
        $noop = $governor->recordAssessment([
            ...$this->semanticAssessment(false),
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
        $assessment = [...$this->semanticAssessment(false), 'status' => 'non_controlling_axis', 'target_stage' => 'trigger',
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
        $governor->recordAssessment([...$this->semanticAssessment(true), 'status' => 'controllable', 'target_stage' => 'trigger', 'owner' => ['gene' => 'trigger_topology_policy'],
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
        $governor->recordAssessment([...$this->semanticAssessment(true), 'status' => 'controllable', 'target_stage' => 'trigger', 'owner' => ['gene' => 'trigger_topology_policy'],
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

    public function test_missing_or_legacy_identity_never_creates_retirement_or_progress_rows(): void
    {
        $governor = app(CausalProgressRatchetGovernorService::class);
        $context = ['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'composition_key' => 'missing-evidence',
            'baseline_epoch_hash' => str_repeat('a', 64), 'data_hash' => str_repeat('b', 64), 'execution_hash' => str_repeat('c', 64)];
        foreach (['non_controlling_axis', 'controllable'] as $status) {
            $result = $governor->recordAssessment(['status' => $status, 'target_stage' => 'closed_trade',
                'candidate_counts' => ['entry' => 4, 'closed_trade' => 4], 'event_delta' => 0], $context, [], []);
            $this->assertSame('unassessable', $result['status']);
            $this->assertFalse($result['retire']);
            $this->assertSame('none', $result['authority']);
        }
        $this->assertDatabaseCount('causal_axis_retirements', 0);
        $this->assertDatabaseCount('causal_progress_ratchets', 0);
    }

    public function test_management_semantic_change_with_equal_event_counts_is_not_retired(): void
    {
        $assessment = [...$this->semanticAssessment(true), 'status' => 'controllable', 'target_stage' => 'closed_trade',
            'owner' => ['gene' => 'trailing_atr_multiplier'], 'candidate_counts' => ['entry' => 3, 'closed_trade' => 3], 'event_delta' => 0];
        $result = app(CausalProgressRatchetGovernorService::class)->classify($assessment, ['total_trades' => 3], ['total_trades' => 3]);
        $this->assertSame('behavior_changed_economically_underpowered', $result['classification']);
        $this->assertFalse($result['retire']);
        $this->assertSame('increase_economic_power', $result['next_action']);
    }

    public function test_non_controlling_scope_mismatch_is_not_a_zero_effect_retirement(): void
    {
        $assessment = [...$this->semanticAssessment(true), 'status' => 'non_controlling_axis',
            'reason' => 'BEHAVIOR_DELTA_EXCESSIVE', 'target_stage' => 'closed_trade',
            'candidate_counts' => ['entry' => 3], 'event_delta' => 0];
        $result = app(CausalProgressRatchetGovernorService::class)->classify($assessment, [], []);
        $this->assertSame('invariant_saturation', $result['classification']);
        $this->assertFalse($result['retire']);
    }

    public function test_duplicate_callback_does_not_count_twice_or_retire_axis(): void
    {
        $governor = app(CausalProgressRatchetGovernorService::class);
        $assessment = [...$this->semanticAssessment(false), 'status' => 'non_controlling_axis', 'target_stage' => 'closed_trade',
            'owner' => ['gene' => 'trailing_atr_multiplier'], 'candidate_counts' => ['entry' => 3], 'event_delta' => 0];
        $context = ['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'composition_key' => 'exit-noop',
            'baseline_epoch_hash' => str_repeat('a', 64), 'data_hash' => str_repeat('b', 64), 'execution_hash' => str_repeat('c', 64),
            'control_evaluation_run_id' => 21, 'candidate_evaluation_run_id' => 22, 'intervention_hash' => hash('sha256', 'exit-delta-1')];
        $control = ['value' => 2.0]; $candidate = ['value' => 2.5];
        $first = $governor->recordAssessment($assessment, $context, $control, $candidate);
        $retry = $governor->recordAssessment($assessment, $context, $control, $candidate);
        $this->assertSame(1, data_get($first, 'retirement.observations'));
        $this->assertSame(1, data_get($retry, 'retirement.observations'));
        $this->assertTrue(data_get($retry, 'retirement.duplicate_delivery'));
        $this->assertFalse(data_get($retry, 'retirement.retired'));
        $this->assertDatabaseCount('causal_axis_retirements', 1);
        $ratchet = DB::table('causal_progress_ratchets')->first();
        $this->assertCount(1, json_decode($ratchet->evidence, true)['history']);

        $second = $governor->recordAssessment($assessment, [...$context,
            'candidate_evaluation_run_id' => 23, 'intervention_hash' => hash('sha256', 'exit-delta-2')], $control, ['value' => 3.0]);
        $this->assertSame(2, data_get($second, 'retirement.observations'));
        $this->assertTrue(data_get($second, 'retirement.retired'));
    }

    public function test_escalation_excludes_legacy_counters_and_other_frozen_input_scopes(): void
    {
        $context = ['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'composition_key' => 'exit-scope',
            'baseline_epoch_hash' => str_repeat('a', 64), 'data_hash' => str_repeat('b', 64), 'execution_hash' => str_repeat('c', 64)];
        $row = ['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'composition_key' => 'exit-scope',
            'axis' => 'time_stop_candles', 'classification' => 'behavior_changed_no_edge', 'observations' => 20,
            'retired' => false, 'created_at' => now(), 'updated_at' => now()];
        DB::table('causal_axis_retirements')->insert([...$row, 'retirement_key' => 'legacy-axis', 'evidence' => json_encode(['promotion_evidence' => false])]);
        DB::table('causal_axis_retirements')->insert([...$row, 'retirement_key' => 'other-input-axis',
            'evidence' => json_encode(['protocol' => CausalProgressRatchetGovernorService::PROTOCOL,
                'semantic_observation_keys' => ['one', 'two'], 'source_scope' => [...$context, 'data_hash' => str_repeat('d', 64)]])]);
        $governor = app(CausalProgressRatchetGovernorService::class);
        $this->assertSame('L0_scalar_threshold', $governor->escalation($context, 'time_stop_candles')['level']);
        $this->assertSame(1, $governor->kpis('XAUUSD', 'H1')['legacy_axis_rows_diagnostic_only']);
        DB::table('causal_axis_retirements')->insert([...$row, 'retirement_key' => 'matched-input-axis', 'observations' => 2,
            'evidence' => json_encode(['protocol' => CausalProgressRatchetGovernorService::PROTOCOL,
                'semantic_observation_keys' => ['three', 'four'], 'source_scope' => $context])]);
        $this->assertSame('L1_component_topology', $governor->escalation($context, 'time_stop_candles')['level']);
    }

    public function test_no_effect_callback_keeps_settled_ablation_and_admitted_economic_authority(): void
    {
        $governor = app(CausalProgressRatchetGovernorService::class);
        $context = ['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'composition_key' => 'monotonic-scope',
            'baseline_epoch_hash' => str_repeat('a', 64), 'data_hash' => str_repeat('b', 64), 'execution_hash' => str_repeat('c', 64)];
        $changed = [...$this->semanticAssessment(true), 'status' => 'controllable', 'target_stage' => 'trigger',
            'owner' => ['gene' => 'trigger_topology_policy'], 'candidate_counts' => ['confirmation' => 4], 'event_delta' => 1];
        $noop = [...$this->semanticAssessment(false), 'status' => 'non_controlling_axis', 'target_stage' => 'closed_trade',
            'owner' => ['gene' => 'time_stop_candles'], 'candidate_counts' => ['entry' => 4], 'event_delta' => 0];
        $governor->recordAssessment($changed, $context, ['value' => 'balanced'], ['value' => 'aggressive']);
        $governor->recordAblation($context, ['semantic_output' => 'with'], ['semantic_output' => 'without']);
        $afterAblation = $governor->recordAssessment($noop, $context, ['value' => 3], ['value' => 4]);
        $this->assertSame('ablation_preserved', $afterAblation['scaffold_ablation']['status']);
        $governor->recordPositiveAfterCostEdge($context, ['total_trades' => 12, 'after_cost_expectancy_r' => .2]);
        $afterEconomic = $governor->recordAssessment($noop, $context, ['value' => 3], ['value' => 4]);
        $this->assertSame('positive_after_cost_edge', $afterEconomic['deepest_stage']);
        $this->assertSame('economic_edge_confirmed', $afterEconomic['authority']);
        $this->assertDatabaseHas('causal_progress_ratchets', ['composition_key' => 'monotonic-scope', 'authority' => 'economic_edge_confirmed']);
    }

    public function test_positive_discovery_cannot_bypass_separately_admitted_economic_replay(): void
    {
        $governor = app(CausalProgressRatchetGovernorService::class);
        $context = ['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'composition_key' => 'discovery-only',
            'baseline_epoch_hash' => str_repeat('a', 64), 'data_hash' => str_repeat('b', 64), 'execution_hash' => str_repeat('c', 64)];
        $result = $governor->recordAssessment([...$this->semanticAssessment(true), 'status' => 'controllable',
            'target_stage' => 'closed_trade', 'owner' => ['gene' => 'time_stop_candles'], 'candidate_counts' => ['entry' => 12], 'event_delta' => 0],
            $context, ['value' => 3], ['value' => 4, 'total_trades' => 12, 'after_cost_expectancy_r' => .2]);
        $this->assertSame('positive_after_cost_edge', $result['classification']['classification']);
        $this->assertSame('closed_trade', $result['deepest_stage']);
        $this->assertFalse($governor->mutationAuthority($result['deepest_stage'])['risk_allowed']);
        $this->assertNotSame('economic_edge_confirmed', $result['authority']);
    }

    private function semanticAssessment(bool $changed): array
    {
        return ['protocol' => \App\Services\CausalStageMasteryDirectorService::PROTOCOL,
            'evidence_assessable' => true, 'semantic_effect_observed' => $changed,
            'reason' => $changed ? 'SEMANTIC_EFFECT_OBSERVED' : 'NO_OBSERVED_SEMANTIC_EFFECT',
            'checks' => ['decision_identity_valid' => true, 'upstream_identity_preserved' => true]];
    }
}
