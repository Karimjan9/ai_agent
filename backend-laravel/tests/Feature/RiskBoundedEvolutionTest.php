<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\EvolutionGovernorService;
use App\Services\LearningVelocityGateService;
use App\Services\MtfShadowCouncilSandboxService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiskBoundedEvolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_governor_emits_controlled_exploration_modes_after_history_exists(): void
    {
        $plan = collect(range(1, 20))->map(fn (int $slot): array => [
            'origin' => 'g98_council',
            'target' => 'monthly_survival',
            'niche' => ['role' => 'general'],
            'slot' => $slot,
        ])->all();
        $snapshot = [
            'observed_generations' => [1, 2],
            'exploration_ratio' => .75,
            'diversity_collapse' => true,
            'parent_concentration' => .80,
            'stagnation_generations' => 3,
            'market_drift' => ['status' => 'recheck_required'],
            'learning_telemetry' => [
                'provisional_skill_count' => 2,
                'confirmed_skill_count' => 1,
            ],
        ];

        $adapted = app(EvolutionGovernorService::class)->adaptPlan($plan, $snapshot);
        $tail = array_slice($adapted, -8);
        $modes = array_values(array_map(
            static fn (array $slot): string => (string) data_get($slot, 'niche.evolution_mode'),
            $tail,
        ));

        $this->assertSame([
            'frozen_control', 'screen_pass', 'targeted_repair', 'targeted_repair',
            'proven_gene_refinement', 'bold_explorer', 'regime_volume_explorer',
            'adversarial_red_team',
        ], $modes);
        $this->assertTrue((bool) data_get($tail[0], 'niche.control_only'));
        $this->assertTrue((bool) data_get($tail[6], 'niche.volume_shadow'));
        $this->assertTrue((bool) data_get($tail[6], 'niche.shadow_only'));
        $this->assertSame('volume_m15_specialist', data_get($tail[6], 'niche.specialist_role'));
        $this->assertSame('volume_lane', data_get($tail[6], 'niche.shadow_mutation_gene'));
        $this->assertSame('volume_lane', data_get($tail[6], 'niche.shadow_mutation_contract.gene'));
        $this->assertTrue((bool) data_get($tail[7], 'niche.adversarial_red_team'));
        $this->assertFalse((bool) data_get($tail[5], 'adaptive_governor.promotion_evidence'));
    }

    public function test_learning_velocity_blocks_screen_pass_without_replay(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Velocity test', 'timeframe' => 'H1',
            'strategy_families' => ['trend'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'population_size' => 1, 'status' => 'screened', 'trigger_context' => [],
        ]);
        $model = ModelVersion::create([
            'name' => 'velocity-test', 'strategy' => 'velocity-test', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing',
            'parameters' => app(StrategyParameterSchemaService::class)->defaults('trend'),
            'metadata' => [], 'evidence_status' => 'valid',
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend',
            'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => [],
        ]);
        CandidateGateDecision::create([
            'lab_agent_id' => $agent->id, 'stage' => 'screening', 'decision' => 'passed',
            'reason_codes' => [], 'metrics' => ['sample_count' => 20], 'evaluated_at' => now(),
        ]);

        $result = app(LearningVelocityGateService::class)->inspect($lab);

        $this->assertFalse($result['allowed']);
        $this->assertSame('blocked_learning_backlog', $result['status']);
        $this->assertContains('screen_pass_without_full_replay', $result['reason_codes']);
    }

    public function test_zero_diff_constructor_quarantine_is_reconciled_not_recovery_blocking(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Zero-diff reconciliation test', 'timeframe' => 'H1',
            'strategy_families' => ['trend'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'population_size' => 1, 'status' => 'technical_quarantine', 'trigger_context' => [],
        ]);
        $model = ModelVersion::create([
            'name' => 'zero-diff-test', 'strategy' => 'zero-diff-test', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing',
            'parameters' => app(StrategyParameterSchemaService::class)->defaults('trend'),
            'metadata' => [
                'preflight_quarantine' => [
                    'errors' => ['ZERO_DIFF_INVARIANT_FAILED'],
                    'classification' => 'integrity',
                ],
            ],
            'evidence_status' => 'stale_quarantine',
            'invalidation_reason' => 'strict_lab_agent_preflight_failed',
        ]);
        LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend',
            'origin' => 'test', 'lifecycle_status' => 'technical_quarantine',
            'parameter_diff' => ['partial_take_profit_fraction' => ['old' => 0, 'new' => 0.0]],
            'decision_reason' => 'Technical quarantine: strict lab preflight failed (ZERO_DIFF_INVARIANT_FAILED).',
        ]);

        $result = app(LearningVelocityGateService::class)->inspect($lab);

        $this->assertTrue($result['allowed']);
        $this->assertSame('healthy', $result['status']);
        $this->assertSame(0, $result['technical_recovery_agents']);
    }

    public function test_exhausted_retry_budget_quarantine_is_terminal_history_not_a_generation_deadlock(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Retry budget terminal test', 'timeframe' => 'H1',
            'strategy_families' => ['trend'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'population_size' => 1, 'status' => 'screened', 'trigger_context' => [],
        ]);
        $model = ModelVersion::create([
            'name' => 'retry-budget-terminal', 'strategy' => 'retry-budget-terminal', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing',
            'parameters' => app(StrategyParameterSchemaService::class)->defaults('trend'),
            'metadata' => [], 'evidence_status' => 'valid',
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend',
            'origin' => 'test', 'lifecycle_status' => 'technical_quarantine', 'parameter_diff' => [],
            'decision_reason' => 'Technical quarantine after bounded learning-lane transport failures; strategy verdict withheld.',
        ]);
        LabEvaluationRun::create([
            'run_id' => 'retry-budget-terminal-run', 'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id, 'model_version_id' => $model->id,
            'phase' => 'screening', 'mode' => 'screen', 'status' => 'technical_error',
            'error_class' => 'Illuminate\\Queue\\MaxAttemptsExceededException',
            'error_message' => 'EvaluateLabAgentJob has been attempted too many times.',
            'started_at' => now()->subMinute(), 'finished_at' => now(),
        ]);

        $pending = app(LearningVelocityGateService::class)->inspect($lab);
        $this->assertFalse($pending['allowed']);
        $this->assertSame(1, $pending['technical_recovery_agents']);

        $metadata = $model->fresh()->metadata;
        data_set($metadata, 'retry_budget_repair_recovery_attempts', 1);
        $model->update(['metadata' => $metadata]);
        $terminal = app(LearningVelocityGateService::class)->inspect($lab);

        $this->assertTrue($terminal['allowed']);
        $this->assertSame(0, $terminal['technical_recovery_agents']);
        $this->assertSame('healthy', $terminal['status']);
    }

    public function test_closed_population_contract_quarantine_is_excluded_without_quality_credit(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Contract drift reconciliation test', 'timeframe' => 'H1',
            'strategy_families' => ['trend'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'shadow_research',
            'population_size' => 20, 'status' => 'technical_quarantine',
            'trigger_context' => [
                'integrity_repair' => [
                    'contract_drift' => [
                        'issues' => ['POPULATION_COUNT_MISMATCH'],
                        'evidence_preserved' => true,
                    ],
                ],
                'shadow_research_constructor_abort' => [
                    'reason_code' => 'INCOMPLETE_SHADOW_RESEARCH_POPULATION',
                    'planned_slots' => 20,
                    'created_agents' => 16,
                    'promotion_evidence' => false,
                ],
            ],
        ]);
        $model = ModelVersion::create([
            'name' => 'contract-drift-test', 'strategy' => 'contract-drift-test', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing',
            'parameters' => app(StrategyParameterSchemaService::class)->defaults('trend'),
            'metadata' => [
                'preflight_quarantine' => ['errors' => ['POPULATION_CONTRACT_DRIFT']],
            ],
            'evidence_status' => 'stale_quarantine',
        ]);
        LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend',
            'origin' => 'shadow_research', 'lifecycle_status' => 'technical_quarantine',
            'parameter_diff' => ['entry_topology_variant' => ['old' => 'frozen', 'new' => 'regime_consensus_v1']],
        ]);

        $result = app(LearningVelocityGateService::class)->inspect($lab);

        $this->assertTrue($result['allowed']);
        $this->assertSame(0, $result['technical_recovery_agents']);
        $this->assertSame('healthy', $result['status']);
    }

    public function test_closed_audited_generation_contract_quarantine_is_excluded_without_reopening_learning(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Audited contract drift test', 'timeframe' => 'H1',
            'strategy_families' => ['trend'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'learning_trigger',
            'population_size' => 20, 'status' => 'technical_quarantine',
            'trigger_context' => [
                'integrity_repair' => ['contract_drift' => ['issues' => ['POPULATION_COUNT_MISMATCH']]],
                'constructor_contract_abort' => [
                    'reason_code' => 'INCOMPLETE_GENERATION_POPULATION',
                    'planned_slots' => 20, 'created_agents' => 19,
                ],
            ],
        ]);
        $model = ModelVersion::create([
            'name' => 'audited-contract-drift-test', 'strategy' => 'audited-contract-drift-test', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing',
            'parameters' => app(StrategyParameterSchemaService::class)->defaults('trend'),
            'metadata' => ['preflight_quarantine' => ['errors' => ['POPULATION_CONTRACT_DRIFT']]],
            'evidence_status' => 'stale_quarantine',
        ]);
        LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend',
            'origin' => 'learning_trigger', 'lifecycle_status' => 'technical_quarantine',
            'parameter_diff' => ['volume_lane' => ['old' => 'none', 'new' => 'low_volume_risk_firewall']],
        ]);

        $result = app(LearningVelocityGateService::class)->inspect($lab);

        $this->assertTrue($result['allowed']);
        $this->assertSame(0, $result['technical_recovery_agents']);
        $this->assertSame('healthy', $result['status']);
    }

    public function test_closed_constructor_abort_is_excluded_without_an_integrity_repair_projection(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Constructor abort exclusion test', 'timeframe' => 'H1',
            'strategy_families' => ['trend'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'quality_evolution_synthesis',
            'population_size' => 3, 'status' => 'technical_quarantine',
            'trigger_context' => [
                'constructor_contract_abort' => [
                    'reason_code' => 'INCOMPLETE_GENERATION_POPULATION',
                    'planned_slots' => 20, 'created_agents' => 3,
                ],
            ],
        ]);
        $model = ModelVersion::create([
            'name' => 'constructor-abort-exclusion-test', 'strategy' => 'constructor-abort-exclusion-test', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing',
            'parameters' => app(StrategyParameterSchemaService::class)->defaults('trend'),
            'metadata' => [], 'evidence_status' => 'stale_quarantine',
        ]);
        LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend',
            'origin' => 'quality_evolution_synthesis', 'lifecycle_status' => 'technical_quarantine',
            'parameter_diff' => ['entry_topology_variant' => ['old' => 'frozen', 'new' => 'regime_consensus_v1']],
            'decision_reason' => 'Generation construction incomplete; candidate quarantined before replay and strategy verdict withheld.',
        ]);

        $result = app(LearningVelocityGateService::class)->inspect($lab);

        $this->assertTrue($result['allowed']);
        $this->assertSame(0, $result['technical_recovery_agents']);
        $this->assertSame('healthy', $result['status']);
    }

    public function test_superseded_causal_selector_quarantine_is_terminal_history_not_recovery_work(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Superseded causal selector test', 'timeframe' => 'H1',
            'strategy_families' => ['trend'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 214, 'trigger_type' => 'learning_confirmation',
            'population_size' => 20, 'status' => 'technical_quarantine',
            'trigger_context' => [
                'constructor_contract_abort' => [
                    'protocol' => 'superseded_causal_selector_construction_v1',
                    'reason_codes' => ['CAUSAL_SELECTOR_PROTOCOL_SUPERSEDED'],
                    'stored_selector_protocol' => 'causal_blinded_single_gene_selector_v5',
                    'required_selector_protocol' => 'causal_blinded_single_gene_selector_v6',
                    'learning_evidence' => false,
                    'promotion_evidence' => false,
                ],
            ],
        ]);
        $model = ModelVersion::create([
            'name' => 'superseded-selector-test', 'strategy' => 'superseded-selector-test', 'version' => 'v1',
            'generation' => 214, 'status' => 'testing',
            'parameters' => app(StrategyParameterSchemaService::class)->defaults('trend'),
            'metadata' => [], 'evidence_status' => 'stale_quarantine',
        ]);
        LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend',
            'origin' => 'causal_learning_confirmation', 'lifecycle_status' => 'technical_quarantine',
            'parameter_diff' => ['trend_down_roc_threshold' => ['old' => -0.001, 'new' => -0.0015]],
            'decision_reason' => 'Causal constructor selector protocol was superseded before screening; strategy verdict withheld.',
        ]);

        $result = app(LearningVelocityGateService::class)->inspect($lab);

        $this->assertTrue($result['allowed']);
        $this->assertSame(0, $result['technical_recovery_agents']);
        $this->assertSame('healthy', $result['status']);
    }

    public function test_shadow_council_is_explicitly_research_only(): void
    {
        $contract = app(MtfShadowCouncilSandboxService::class)->contract([
            ['role' => 'pf_entry'],
            ['role' => 'cost_exit'],
            ['role' => 'regime'],
        ], ['data_hash' => str_repeat('a', 64)]);

        $this->assertSame(MtfShadowCouncilSandboxService::PROTOCOL, $contract['protocol']);
        $this->assertSame('research_only', $contract['status']);
        $this->assertFalse($contract['combined_proxy_eligible']);
        $this->assertFalse($contract['official_paper_eligible']);
        $this->assertContains('temporal_volume', $contract['missing_skill_roles']);
        $this->assertFalse($contract['promotion_evidence']);
    }

    public function test_outcome_policy_separates_recovery_failure_and_confirmed_exploration(): void
    {
        $governor = app(EvolutionGovernorService::class);

        $technical = $governor->evolutionModePolicy('technical_error');
        $failure = $governor->evolutionModePolicy('strategy_failure');
        $confirmed = $governor->evolutionModePolicy('independent_pass');
        $repeated = $governor->evolutionModePolicy('repeated_failure');

        $this->assertFalse($technical['mutation_allowed']);
        $this->assertSame('one_failure_targeted_gene_mutation', $failure['action']);
        $this->assertSame(1, $failure['max_changed_genes']);
        $this->assertGreaterThan(1, $confirmed['step_multiplier']);
        $this->assertTrue($repeated['gene_direction_closed']);
        $this->assertFalse($technical['promotion_evidence']);
    }
}
