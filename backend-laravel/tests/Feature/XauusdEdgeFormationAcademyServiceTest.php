<?php

namespace Tests\Feature;

use App\Services\XauusdEdgeFormationAcademyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class XauusdEdgeFormationAcademyServiceTest extends TestCase
{
    use RefreshDatabase;

    private function passport(string $stage = 'confirmation_specialist'): array
    {
        return app(XauusdEdgeFormationAcademyService::class)->passport('XAUUSD', 'H1', [
            'composition_key' => 'hybrid|core|trend-up|h1-m15-m5', 'strategy_family' => 'hybrid',
            'context' => ['regime' => 'trend_up', 'session' => 'london'],
            'temporal_roles' => ['H1' => 'context', 'M15' => 'setup', 'M5' => 'trigger'],
            'deepest_stage' => $stage, 'risk_contract' => ['risk_per_trade' => .5], 'management_contract' => ['mode' => 'trail'],
        ]);
    }

    public function test_oracle_is_diagnostic_only_and_routes_the_dominant_mastery_problem(): void
    {
        $service = app(XauusdEdgeFormationAcademyService::class);
        $passport = $this->passport();
        $negative = $service->assessOracleGap($passport['passport_id'], ['diagnostic_only' => true, 'opportunity_edge_r' => -.1], ['data_hash' => 'd', 'execution_hash' => 'e']);
        $entry = $service->assessOracleGap($passport['passport_id'], ['diagnostic_only' => true, 'opportunity_edge_r' => .6], ['data_hash' => 'd2', 'execution_hash' => 'e2', 'real_entry_after_cost_r' => -.1, 'false_entry_cost_r' => .2]);
        $management = $service->assessOracleGap($passport['passport_id'], ['diagnostic_only' => true, 'opportunity_edge_r' => .6], ['data_hash' => 'd3', 'execution_hash' => 'e3', 'real_entry_after_cost_r' => .2, 'realized_after_cost_r' => -.1, 'management_capture_loss_r' => .3]);

        $this->assertSame('oracle_negative_retire_context_cell', $negative['status']);
        $this->assertSame('entry_mastery_required', $entry['status']);
        $this->assertSame('management_or_cost_mastery_required', $management['status']);
        $this->assertFalse($management['oracle_is_runtime_signal']);
        $this->assertFalse($management['promotion_evidence']);
    }

    public function test_curriculum_freezes_unowned_axes_and_registers_exact_five_arm_experiments(): void
    {
        $service = app(XauusdEdgeFormationAcademyService::class);
        $passport = $this->passport('confirmation_specialist');
        $confirmation = $service->planConfirmationMarginalValue($passport['passport_id']);
        $trigger = $service->planTriggerTopologyTournament($passport['passport_id']);

        $this->assertSame('planned', $confirmation['status']);
        $this->assertCount(5, $confirmation['arms']);
        $this->assertSame('confirmation_family_policy', $confirmation['axis']);
        $this->assertSame('frozen_control', $confirmation['arms'][0]['role']);
        $this->assertSame('blinded_control', $confirmation['arms'][4]['role']);
        $this->assertSame('blocked', $trigger['status']);
        $this->assertSame('CURRICULUM_FORBIDS_MUTATION_AXIS', $trigger['reason']);
    }

    public function test_replanning_a_settled_trial_preserves_its_immutable_terminal_state(): void
    {
        $service = app(XauusdEdgeFormationAcademyService::class);
        $passport = $this->passport('confirmation_specialist');
        $planned = $service->planConfirmationMarginalValue($passport['passport_id']);
        $trialId = (int) \DB::table('edge_academy_trials')->where('trial_type', $planned['trial_type'])->value('id');
        $service->settleTrial($trialId, ['setup' => 20, 'trigger' => 12, 'closed_trade' => 8], [
            'avoided_loss_r' => .3, 'missed_opportunity_r' => .1, 'late_entry_cost_r' => .1,
        ]);

        $replanned = $service->planConfirmationMarginalValue($passport['passport_id']);

        $this->assertSame('settled_powered', $replanned['status']);
        $this->assertTrue($replanned['terminal']);
        $this->assertSame('settled_powered', \DB::table('edge_academy_trials')->find($trialId)->status);
    }

    public function test_beam_archive_retains_three_per_stage_and_density_has_distinct_no_power_outcomes(): void
    {
        $service = app(XauusdEdgeFormationAcademyService::class);
        $passport = $this->passport('trigger_entry_specialist');
        foreach ([.1, .4, .2, .9] as $index => $score) $service->archiveBeam($passport['passport_id'], 'trigger_entry_specialist', ['composition_key' => 'candidate-'.$index, 'score' => $score]);
        $rows = \DB::table('edge_academy_beams')->where('edge_academy_passport_id', $passport['passport_id'])->where('status', 'beam_retained')->get();
        $trial = $service->planTriggerTopologyTournament($passport['passport_id']);
        $topology = $service->eventDensityVerdict(['setup' => 2, 'trigger' => 0], $trial['event_density_contract']);
        $noPower = $service->eventDensityVerdict(['setup' => 20, 'trigger' => 12, 'closed_trade' => 1], $trial['event_density_contract']);

        $this->assertCount(3, $rows);
        $this->assertSame('topology_failure_insufficient_events', $topology['status']);
        $this->assertSame('path_activated_no_power', $noPower['status']);
    }

    public function test_recomposition_needs_powered_negative_entry_evidence_and_one_categorical_axis(): void
    {
        $service = app(XauusdEdgeFormationAcademyService::class);
        $passport = $this->passport('full_composition_master');
        $blocked = $service->planLocationRecomposition($passport['passport_id'], 'risk', ['density_status' => 'powered_for_economic_settlement', 'after_cost_expectancy_r' => -.1]);
        $planned = $service->planLocationRecomposition($passport['passport_id'], 'tactic', ['density_status' => 'powered_for_economic_settlement', 'after_cost_expectancy_r' => -.1]);

        $this->assertSame('CATEGORICAL_RECOMPOSITION_AXIS_REQUIRED', $blocked['reason']);
        $this->assertSame('planned', $planned['status']);
        $this->assertSame('tactic', $planned['axis']);
    }

    public function test_roles_rewards_and_regime_specialism_are_research_only(): void
    {
        $service = app(XauusdEdgeFormationAcademyService::class);
        $passport = $this->passport('market_cartographer');
        $role = $service->roleBrief('adversarial_falsifier', ['claim' => 'candidate']);
        $reward = $service->researchReward(['causal_depth_gain' => 1, 'decisive_information' => 1, 'independent_replication' => 1]);
        $regime = $service->planRegimeSpecialist($passport['passport_id'], ['regime' => 'trend_up', 'volatility' => 'normal', 'session' => 'london']);

        $this->assertFalse($role['can_grant_authority']);
        $this->assertTrue($reward['excludes_raw_profit_as_reward']);
        $this->assertSame('planned', $regime['status']);
        $this->assertFalse($regime['promotion_evidence']);
    }

    public function test_oracle_routes_one_legal_next_experiment_and_confirmation_uses_marginal_inequality(): void
    {
        $service = app(XauusdEdgeFormationAcademyService::class);
        $passport = $this->passport('trigger_entry_specialist');
        $next = $service->nextExperiment($passport['passport_id'], ['status' => 'entry_mastery_required']);
        $trialId = (int) \DB::table('edge_academy_trials')->where('trial_type', 'trigger_topology_tournament')->value('id');
        $settled = $service->settleTrial($trialId, ['setup' => 20, 'trigger' => 12, 'closed_trade' => 8], ['after_cost_expectancy_r' => -.1]);
        $confirmationPassport = app(XauusdEdgeFormationAcademyService::class)->passport('XAUUSD', 'H1', [
            'composition_key' => 'confirmation-only-composition', 'strategy_family' => 'hybrid',
            'context' => ['regime' => 'trend_up', 'session' => 'london'],
            'temporal_roles' => ['H1' => 'context', 'M15' => 'setup', 'M5' => 'trigger'],
            'deepest_stage' => 'confirmation_specialist',
        ]);
        $confirmation = $service->planConfirmationMarginalValue($confirmationPassport['passport_id']);
        $confirmationId = (int) \DB::table('edge_academy_trials')->where('trial_type', 'confirmation_marginal_value')->value('id');
        $marginal = $service->settleTrial($confirmationId, ['setup' => 20, 'trigger' => 12, 'closed_trade' => 8], ['avoided_loss_r' => .3, 'missed_opportunity_r' => .1, 'late_entry_cost_r' => .1]);

        $this->assertSame('planned', $next['status']);
        $this->assertSame('settled_powered', $settled['status']);
        $this->assertTrue($marginal['marginal_value']['passed']);
        $this->assertCount(5, $confirmation['arms']);
    }
}
