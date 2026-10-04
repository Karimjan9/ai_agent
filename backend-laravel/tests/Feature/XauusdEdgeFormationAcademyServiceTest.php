<?php

namespace Tests\Feature;

use App\Services\XauusdEdgeFormationAcademyService;
use App\Services\AcademyExperimentContractCompilerService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
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
        $negative = $service->assessOracleGap($passport['passport_id'], $this->oracle(-.1), ['data_hash' => 'd', 'execution_hash' => 'e']);
        $entry = $service->assessOracleGap($passport['passport_id'], $this->oracle(.6), ['data_hash' => 'd2', 'execution_hash' => 'e2', 'real_entry_after_cost_r' => -.1, 'false_entry_cost_r' => .2]);
        $management = $service->assessOracleGap($passport['passport_id'], $this->oracle(.6), ['data_hash' => 'd3', 'execution_hash' => 'e3', 'real_entry_after_cost_r' => .2, 'realized_after_cost_r' => -.1, 'management_capture_loss_r' => .3]);

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
        $this->assertSame('blocked', $planned['status']);
        $this->assertSame('ACADEMY_CATEGORICAL_RUNTIME_ADAPTER_REQUIRED', $planned['reason']);
        $this->assertFalse($planned['executable']);
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
        $this->assertSame('ACADEMY_PROSPECTIVE_CONTEXT_ADAPTER_REQUIRED', $regime['reason']);
        $this->assertFalse($regime['executable']);
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

    public function test_real_confirmation_and_trigger_plans_compile_without_fake_runtime_aliases(): void
    {
        $academy = app(XauusdEdgeFormationAcademyService::class);
        $compiler = app(AcademyExperimentContractCompilerService::class);
        $parameters = app(StrategyParameterSchemaService::class)->defaults('confirmation_entry_mtf');
        foreach (['confirmation_specialist', 'trigger_entry_specialist'] as $stage) {
            $passport = $academy->passport('XAUUSD', 'H1', ['composition_key' => $stage, 'deepest_stage' => $stage]);
            $plan = $stage === 'confirmation_specialist' ? $academy->planConfirmationMarginalValue($passport['passport_id']) : $academy->planTriggerTopologyTournament($passport['passport_id']);
            $compiled = $compiler->compile($plan, $parameters, ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5']);
            $this->assertSame('compiled', $compiled['status']);
            $this->assertSame($parameters, $compiled['arms'][0]['runtime_parameters']);
            $this->assertCount(5, $compiled['arms']);
        }
    }

    public function test_cold_start_uses_bounded_legal_runtime_axes_and_blocked_oracle_keeps_its_reason(): void
    {
        $academy = app(XauusdEdgeFormationAcademyService::class);
        $parameters = app(StrategyParameterSchemaService::class)->defaults('confirmation_entry_mtf');
        foreach (['market_cartographer', 'setup_apprentice'] as $stage) {
            $passport = $academy->passport('XAUUSD', 'H1', ['composition_key' => $stage, 'deepest_stage' => $stage, 'baseline_parameters' => $parameters]);
            $plan = $academy->nextExperiment($passport['passport_id'], ['status' => 'entry_mastery_required']);
            $compiled = app(AcademyExperimentContractCompilerService::class)->compile($plan, $parameters,
                ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5']);
            $this->assertSame('planned', $plan['status']);
            $this->assertSame('compiled', $compiled['status']);
            $this->assertLessThanOrEqual(4, count($plan['arms']));
            $blocked = $academy->nextExperiment($passport['passport_id'], ['status' => 'blocked', 'reason' => 'ORACLE_DIAGNOSTIC_CONTRACT_REQUIRED']);
            $this->assertSame('blocked', $blocked['status']);
            $this->assertSame('ORACLE_DIAGNOSTIC_CONTRACT_REQUIRED', $blocked['reason']);
        }
    }

    public function test_legacy_or_runtime_shaped_oracle_cannot_be_relabelled_as_valid_diagnostic(): void
    {
        $academy = app(XauusdEdgeFormationAcademyService::class);
        $passport = $this->passport();
        $this->assertSame('blocked', $academy->assessOracleGap($passport['passport_id'], ['diagnostic_only' => true, 'opportunity_edge_r' => .6], [])['status']);
        $this->assertSame('blocked', $academy->assessOracleGap($passport['passport_id'], [...$this->oracle(.6), 'runtime_signal' => true], [])['status']);
        $this->assertSame(0, \DB::table('edge_academy_oracle_gaps')->count());
    }

    public function test_oracle_requires_explicit_boolean_safety_flags_and_finite_json_numbers(): void
    {
        $academy = app(XauusdEdgeFormationAcademyService::class);
        $passport = $this->passport();
        foreach (['diagnostic_only', 'runtime_signal', 'promotion_evidence', 'full_oracle_gap_available'] as $field) {
            $missing = $this->oracle(.6);
            unset($missing[$field]);
            $this->assertSame('ORACLE_DIAGNOSTIC_CONTRACT_REQUIRED', $academy->assessOracleGap($passport['passport_id'], $missing, [])['reason']);
            foreach (['false', 'true', 0, 1, null, []] as $value) {
                $malformed = [...$this->oracle(.6), $field => $value];
                $this->assertSame('blocked', $academy->assessOracleGap($passport['passport_id'], $malformed, [])['status']);
            }
        }
        foreach ([null, NAN, INF, -INF, '0.6', true, []] as $edge) {
            $this->assertSame('blocked', $academy->assessOracleGap($passport['passport_id'], [...$this->oracle(.6), 'oracle_opportunity_edge_r' => $edge], [])['status']);
        }
        $this->assertSame(0, \DB::table('edge_academy_oracle_gaps')->count());
    }

    public function test_missing_realized_outcome_is_not_inferred_from_the_oracle_upper_bound(): void
    {
        $academy = app(XauusdEdgeFormationAcademyService::class);
        $passport = $this->passport();
        $result = $academy->assessOracleGap($passport['passport_id'], $this->oracle(.6), ['realized_after_cost_r' => null]);

        $this->assertSame('entry_mastery_required', $result['status']);
        $this->assertNull($result['edge_gap']['realized_after_cost_r']);
        $this->assertNull($result['edge_gap']['unexplained_gap_r']);
        $this->assertFalse($result['edge_gap']['realized_edge_observed']);
        $this->assertSame('ORACLE_REALIZED_EVIDENCE_MALFORMED', $academy->assessOracleGap(
            $passport['passport_id'], $this->oracle(.6), ['realized_after_cost_r' => INF],
        )['reason']);
    }

    public function test_location_probe_at_both_runtime_bounds_has_distinct_finite_legal_interventions(): void
    {
        $academy = app(XauusdEdgeFormationAcademyService::class);
        $compiler = app(AcademyExperimentContractCompilerService::class);
        foreach ([.05, .051, .15, 1.9, 1.99, 2.] as $baseline) {
            $parameters = [...app(StrategyParameterSchemaService::class)->defaults('confirmation_entry_mtf'),
                'setup_topology_policy' => 'liquidity_sweep_reclaim', 'location_tolerance_atr' => $baseline];
            $passport = $academy->passport('XAUUSD', 'H1', ['composition_key' => 'location-'.$baseline,
                'deepest_stage' => 'market_cartographer', 'baseline_parameters' => $parameters]);
            $plan = $academy->nextExperiment($passport['passport_id'], ['status' => 'entry_mastery_required']);
            $compiled = $compiler->compile($plan, $parameters, ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5']);
            $this->assertSame('compiled', $compiled['status']);
            $values = array_column($compiled['arms'], 'runtime_value');
            $this->assertCount(3, array_unique($values, SORT_REGULAR));
            foreach ($values as $value) {
                $this->assertGreaterThanOrEqual(.05, $value);
                $this->assertLessThanOrEqual(2., $value);
            }
        }
        foreach ([.049, 2.01, '0.35'] as $baseline) {
            $passport = $academy->passport('XAUUSD', 'H1', ['composition_key' => 'invalid-location-'.$baseline,
                'deepest_stage' => 'market_cartographer', 'baseline_parameters' => ['setup_topology_policy' => 'liquidity_sweep_reclaim', 'location_tolerance_atr' => $baseline]]);
            $this->assertSame('ACADEMY_RUNTIME_LOCATION_BASELINE_REQUIRED', $academy->planContextLocationProbe($passport['passport_id'])['reason']);
        }
    }

    public function test_cold_start_preview_is_read_only_stage_zero_and_matches_the_sealed_plan(): void
    {
        $academy = app(XauusdEdgeFormationAcademyService::class);
        $compiler = app(AcademyExperimentContractCompilerService::class);
        $scope = ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5'];
        foreach (['liquidity_sweep_reclaim' => 'location_tolerance_atr', 'pullback_rejection' => 'setup_topology_policy'] as $model => $axis) {
            $parameters = [...app(StrategyParameterSchemaService::class)->defaults('confirmation_entry_mtf'),
                'setup_topology_policy' => $model];
            $beforePassports = \DB::table('edge_academy_passports')->count();
            $beforeTrials = \DB::table('edge_academy_trials')->count();
            $preview = $academy->previewColdStartExperiment($parameters);
            $this->assertSame('previewed', $preview['status']);
            $this->assertSame($axis, $preview['axis']);
            $this->assertSame(0, $preview['stage_depth']);
            $this->assertTrue($preview['source_is_hypothesis_only']);
            foreach (['dispatch_allowed', 'credit_authority', 'paper_authority', 'parent_authority', 'promotion_evidence'] as $flag) {
                $this->assertFalse($preview[$flag]);
            }
            $this->assertSame($beforePassports, \DB::table('edge_academy_passports')->count());
            $this->assertSame($beforeTrials, \DB::table('edge_academy_trials')->count());
            $this->assertSame($preview, $academy->previewColdStartExperiment($parameters));
            $passport = $academy->passport('XAUUSD', 'H1', ['composition_key' => 'cold-start-'.$model,
                'deepest_stage' => 'market_cartographer', 'baseline_parameters' => $parameters,
                'baseline_parameter_hash' => $compiler->parameterHash($parameters)]);
            $planned = $academy->planColdStartExperiment($passport['passport_id']);
            $this->assertSame('planned', $planned['status']);
            $this->assertGreaterThan(0, $planned['trial_id']);
            $this->assertSame($preview['compiled_contract'], $compiler->compile($planned, $parameters, $scope));
            $this->assertSame($preview['event_density_contract'], $planned['event_density_contract']);
            $this->assertSame(0, \DB::table('edge_academy_passports')->find($passport['passport_id'])->stage_depth);
        }
        $this->assertSame(0, \DB::table('edge_academy_oracle_gaps')->count());
        $this->assertSame(0, \DB::table('lab_evolution_credit_events')->count());
    }

    public function test_cold_start_preview_rejects_malformed_parameters_without_diagnostic_or_trial_writes(): void
    {
        $academy = app(XauusdEdgeFormationAcademyService::class);
        $parameters = [...app(StrategyParameterSchemaService::class)->defaults('confirmation_entry_mtf'),
            'setup_topology_policy' => 'liquidity_sweep_reclaim'];
        $this->assertSame('ACADEMY_RUNTIME_LOCATION_BASELINE_REQUIRED', $academy->previewColdStartExperiment(
            [...$parameters, 'location_tolerance_atr' => INF],
        )['reason']);
        $this->assertStringStartsWith('ACADEMY_RUNTIME_BASELINE_SCHEMA_REJECTED:', $academy->previewColdStartExperiment(
            [...$parameters, 'unsupported_runtime_gene' => 1],
        )['reason']);
        $this->assertSame(0, \DB::table('edge_academy_passports')->count());
        $this->assertSame(0, \DB::table('edge_academy_trials')->count());
        $this->assertSame(0, \DB::table('edge_academy_oracle_gaps')->count());
    }

    public function test_prospective_passport_identity_includes_exact_baseline_and_dependency_source(): void
    {
        $academy = app(XauusdEdgeFormationAcademyService::class);
        $parameters = app(StrategyParameterSchemaService::class)->defaults('confirmation_entry_mtf');
        $identity = ['composition_key' => 'same-composition', 'deepest_stage' => 'market_cartographer',
            'baseline_parameters' => $parameters,
            'baseline_parameter_hash' => app(AcademyExperimentContractCompilerService::class)->parameterHash($parameters),
            'prospective_source_identity' => ['data_hash' => str_repeat('a', 64), 'source_evaluator_hash' => str_repeat('b', 64)]];
        $first = $academy->passport('XAUUSD', 'H1', $identity);
        $original = \DB::table('edge_academy_passports')->find($first['passport_id'])->frozen_upstream_contract;
        $changedParameters = [...$parameters, 'location_tolerance_atr' => .45];
        $changed = $academy->passport('XAUUSD', 'H1', [...$identity,
            'baseline_parameters' => $changedParameters,
            'baseline_parameter_hash' => app(AcademyExperimentContractCompilerService::class)->parameterHash($changedParameters)]);
        $changedSource = $academy->passport('XAUUSD', 'H1', [...$identity,
            'prospective_source_identity' => ['data_hash' => str_repeat('a', 64), 'source_evaluator_hash' => str_repeat('c', 64)]]);

        $this->assertNotSame($first['passport_id'], $changed['passport_id']);
        $this->assertNotSame($first['passport_id'], $changedSource['passport_id']);
        $this->assertSame($original, \DB::table('edge_academy_passports')->find($first['passport_id'])->frozen_upstream_contract);
        $this->assertSame(3, \DB::table('edge_academy_passports')->count());
    }

    public function test_versioned_observation_coexists_with_the_archived_v1_composition_cell(): void
    {
        $academy = app(XauusdEdgeFormationAcademyService::class);
        $context = hash('sha256', 'same-context');
        $temporal = hash('sha256', 'same-temporal');
        $legacyId = \DB::table('edge_academy_passports')->insertGetId([
            'passport_key' => hash('sha256', 'archived-v1-passport'), 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'composition_key' => 'archived-composition', 'strategy_key' => 'confirmation_entry_mtf',
            'context_key' => $context, 'temporal_binding_hash' => $temporal,
            'deepest_stage' => 'market_cartographer', 'stage_depth' => 0, 'status' => 'apprentice',
            'frozen_upstream_contract' => json_encode(['protocol' => 'xauusd_edge_formation_academy_v1', 'original' => true]),
            'curriculum' => json_encode(['protocol' => 'xauusd_edge_formation_academy_v1', 'permitted_axes' => ['context']]),
            'evidence' => json_encode(['legacy_observation' => true]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $original = (array) \DB::table('edge_academy_passports')->find($legacyId);
        $identity = ['composition_key' => 'archived-composition', 'strategy_family' => 'confirmation_entry_mtf',
            'context_key' => $context, 'temporal_binding_hash' => $temporal, 'deepest_stage' => 'market_cartographer'];
        $current = $academy->passport('XAUUSD', 'H1', $identity, ['source_admission' => ['eligible' => false]]);
        $again = $academy->passport('XAUUSD', 'H1', $identity, ['source_admission' => ['eligible' => false]]);

        $this->assertNotSame($legacyId, $current['passport_id']);
        $this->assertSame($current['passport_id'], $again['passport_id']);
        $this->assertSame($original, (array) \DB::table('edge_academy_passports')->find($legacyId));
        $frozen = json_decode(\DB::table('edge_academy_passports')->find($current['passport_id'])->frozen_upstream_contract, true);
        $this->assertSame('archived-composition', $frozen['declared_composition_key']);
        $this->assertSame(2, \DB::table('edge_academy_passports')->count());
        $this->assertSame(0, \DB::table('edge_academy_trials')->count());
    }

    public function test_density_and_claimed_mastery_never_upgrade_the_frozen_source_passport(): void
    {
        $academy = app(XauusdEdgeFormationAcademyService::class);
        $parameters = [...app(StrategyParameterSchemaService::class)->defaults('confirmation_entry_mtf'), 'setup_topology_policy' => 'liquidity_sweep_reclaim'];
        $passport = $academy->passport('XAUUSD', 'H1', ['composition_key' => 'unattested-progress',
            'deepest_stage' => 'market_cartographer', 'baseline_parameters' => $parameters]);
        $trial = $academy->planContextLocationProbe($passport['passport_id']);
        $settled = $academy->settleTrial($trial['trial_id'], ['setup' => 100, 'trigger' => 100, 'closed_trade' => 100], [
            'primary_arm_evidence_complete' => true, 'expected_arm_count' => 3, 'complete_arm_count' => 3,
            'stage_comparisons' => [['role' => 'candidate', 'gene' => 'location_tolerance_atr',
                'assessment' => ['status' => 'controllable', 'target_stage' => 'location', 'checks' => ['decision_identity_valid' => true]]]],
        ]);
        $this->assertSame('no_proven_stage_progress', $settled['stage_progress']['status']);
        $this->assertSame(0, \DB::table('edge_academy_passports')->find($passport['passport_id'])->stage_depth);
        $this->assertSame(1, \DB::table('edge_academy_passports')->count());
        $this->assertSame(0, \DB::table('lab_evolution_credit_events')->count());
        $prior = \DB::table('edge_academy_trials')->find($trial['trial_id'])->outcome;
        $academy->settleTrial($trial['trial_id'], ['setup' => 0], ['new_claim' => true]);
        $this->assertSame($prior, \DB::table('edge_academy_trials')->find($trial['trial_id'])->outcome);
    }

    public function test_entry_and_management_curricula_use_bounded_executable_numeric_axes(): void
    {
        $academy = app(XauusdEdgeFormationAcademyService::class);
        $compiler = app(AcademyExperimentContractCompilerService::class);
        $parameters = app(StrategyParameterSchemaService::class)->defaults('confirmation_entry_mtf');
        foreach (['execution_specialist' => 'max_chase_atr', 'management_specialist' => 'time_stop_candles'] as $stage => $axis) {
            $passport = $academy->passport('XAUUSD', 'H1', ['composition_key' => 'late-stage-'.$stage,
                'deepest_stage' => $stage, 'baseline_parameters' => $parameters]);
            $plan = $academy->nextExperiment($passport['passport_id'], ['status' => $stage === 'execution_specialist' ? 'entry_mastery_required' : 'management_or_cost_mastery_required']);
            $compiled = $compiler->compile($plan, $parameters, ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5']);
            $this->assertSame($axis, $plan['axis']);
            $this->assertSame('compiled', $compiled['status']);
            $this->assertCount(3, $compiled['arms']);
            $this->assertNotSame($compiled['arms'][0]['runtime_value'], $compiled['arms'][1]['runtime_value']);
            $this->assertNotSame($compiled['arms'][1]['runtime_value'], $compiled['arms'][2]['runtime_value']);
            $this->assertFalse($compiled['promotion_evidence']);
        }
    }

    public function test_actual_python_diagnostic_and_runtime_policies_cross_the_laravel_boundary(): void
    {
        $academy = app(XauusdEdgeFormationAcademyService::class);
        $compiler = app(AcademyExperimentContractCompilerService::class);
        $parameters = app(StrategyParameterSchemaService::class)->defaults('confirmation_entry_mtf');
        $arms = [];
        foreach (['market_cartographer', 'setup_apprentice', 'confirmation_specialist', 'trigger_entry_specialist'] as $stage) {
            $passport = $academy->passport('XAUUSD', 'H1', ['composition_key' => 'producer-'.$stage,
                'deepest_stage' => $stage, 'baseline_parameters' => $parameters]);
            $plan = $academy->nextExperiment($passport['passport_id'], ['status' => 'entry_mastery_required']);
            $compiled = $compiler->compile($plan, $parameters, ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5']);
            $this->assertSame('compiled', $compiled['status']);
            $arms = [...$arms, ...$compiled['arms']];
        }
        $python = PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
        $process = new Process([$python, '-c', <<<'PYTHON'
import json
import sys
import pandas as pd
from app.schemas import SimpleBacktestRequest
from app.services.backtester import _edge_formation_academy_diagnostic
from app.strategies.confirmation_entry import compile_confirmation_entry_contract

arms = json.load(sys.stdin)
times = pd.date_range("2025-01-01T00:00:00Z", periods=10, freq="5min")
frame = pd.DataFrame({
    "time": times, "decision_at": times + pd.Timedelta(minutes=5),
    "open": 100., "high": 104., "low": 99., "close": 102.,
    "entry_setup_detected": [True] + [False] * 9,
    "entry_contract_direction": "BUY", "entry_invalidation_reference_price": 98.,
    "mtf_stack_status": "ready", "h4_structure_direction": "bullish",
    "h1_structure_direction": "bullish", "h1_structure_regime": "trend_up",
    "h1_dynamic_fib_zone_low": 99., "h1_dynamic_fib_zone_high": 102.,
    "h1_confirmed_swing_high": 110., "h1_confirmed_swing_low": 95.,
    "m15_trap_direction": "bullish", "m15_trap_available_at": times[0],
    "m15_trap_extreme": 98., "structure_atr": 1.,
    "choch_event": "bullish", "bos_event": "bullish", "break_displacement_atr": .8,
    "confirmed_swing_high": 100.5, "confirmed_swing_low": 99., "market_regime": "trend_up",
})
frame.attrs["execution_timeframe"] = "M5"
payload = SimpleBacktestRequest(symbol="XAUUSD", timeframe="M5", parameters={
    "academy_oracle_horizon_bars": 2, "academy_oracle_minimum_setup_events": 1,
})
diagnostic = _edge_formation_academy_diagnostic(frame, {"stage_counts": {"setup": 1}}, {}, [], payload)
policies = []
for arm in arms:
    result = compile_confirmation_entry_contract(frame, arm["runtime_parameters"])
    policies.append({
        "model": str(result["entry_contract_model"].iloc[0]),
        "status": result["entry_contract_status"].unique().tolist(),
    })
reachability_frame = frame.copy()
reachability_frame["h1_confirmed_swing_low"] = 98.5
location_cases = {}
for policy in ("pullback_rejection", "liquidity_sweep_reclaim"):
    location_cases[policy] = []
    for tolerance in (.35, .75):
        parameters = {**arms[0]["runtime_parameters"], "setup_topology_policy": policy, "location_tolerance_atr": tolerance}
        result = compile_confirmation_entry_contract(reachability_frame, parameters)
        location_cases[policy].append(int(result["entry_location_valid"].sum()))
print(json.dumps({"diagnostic": diagnostic, "policies": policies, "location_cases": location_cases}, allow_nan=False))
PYTHON], base_path('../ai-service-python'), ['PYTHONDONTWRITEBYTECODE' => '1']);
        $process->setInput(json_encode($arms, JSON_THROW_ON_ERROR));
        $process->setTimeout(60);
        $process->mustRun();
        $producer = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $diagnostic = $producer['diagnostic'];
        $this->assertSame('full_oracle_gap_observed', $diagnostic['status']);
        $this->assertArrayNotHasKey('opportunity_edge_r', $diagnostic);
        $this->assertGreaterThan(0., $diagnostic['oracle_opportunity_edge_r']);
        $this->assertNull($diagnostic['realized_after_cost_r']);
        $settlement = $academy->assessOracleGap($passport['passport_id'], $diagnostic, [
            'realized_after_cost_r' => $diagnostic['realized_after_cost_r'],
            'real_entry_after_cost_r' => $diagnostic['entry_envelope_after_cost_r'],
        ]);
        $this->assertSame('entry_mastery_required', $settlement['status']);
        $this->assertFalse($settlement['edge_gap']['realized_edge_observed']);
        $this->assertCount(count($arms), $producer['policies']);
        foreach ($producer['policies'] as $policy) {
            foreach ($policy['status'] as $status) $this->assertStringNotContainsString('unsupported', $status);
            $this->assertNotSame('missing_closed_mtf_context', $policy['status'][0]);
        }
        $this->assertSame([10, 10], $producer['location_cases']['pullback_rejection']);
        $this->assertSame([0, 10], $producer['location_cases']['liquidity_sweep_reclaim']);
        $this->assertSame(0, \DB::table('lab_evolution_credit_events')->count());
    }

    private function oracle(float $edge): array
    {
        return ['protocol' => 'edge_formation_academy_diagnostic_v2', 'status' => 'full_oracle_gap_observed',
            'diagnostic_only' => true, 'runtime_signal' => false, 'promotion_evidence' => false,
            'full_oracle_gap_available' => true, 'oracle_opportunity_edge_r' => $edge];
    }
}
