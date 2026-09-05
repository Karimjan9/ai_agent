<?php

namespace Tests\Feature;

use App\Models\MtfAgentValidationRun;
use App\Models\MtfPlaybookFrozenControlRun;
use App\Services\AgentResearchPlaybookToolboxService;
use App\Services\MtfPlaybookFrozenControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentResearchPlaybookToolboxServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_agent_receives_the_full_library_but_studies_only_a_bounded_named_subset(): void
    {
        $toolbox = app(AgentResearchPlaybookToolboxService::class)->forAgent(
            ['id' => 71, 'strategy_id' => 'fibonacci_structure_pullback', 'mastery_stage' => 'validated_specialist', 'innovation_allowed' => true],
            'XAUUSD', 'M5', ['regime' => 'trend_up', 'session' => 'asia'],
        );

        $this->assertSame(17, $toolbox['catalogue_size']);
        $this->assertCount(17, $toolbox['tools']);
        $this->assertCount(3, $toolbox['active_study_set']);
        $this->assertSame('bounded_shadow_window', data_get($toolbox, 'creative_window.status'));
        $this->assertFalse((bool) data_get($toolbox, 'creative_window.catalogue_is_exhaustive'));
        $this->assertTrue(collect($toolbox['tools'])->every(fn (array $tool): bool => $tool['execution_authority'] === 'none'));
        $this->assertTrue(collect($toolbox['tools'])->every(fn (array $tool): bool => data_get($tool, 'frozen_control_prior.prior_only')));
        $this->assertContains('liquidity_trap_mtf', collect($toolbox['active_study_set'])->pluck('id')->all());
    }

    public function test_completed_frozen_replay_is_visible_as_prior_but_not_as_agent_owned_promotion_evidence(): void
    {
        $identity = app(MtfPlaybookFrozenControlService::class)->currentIdentity();
        MtfPlaybookFrozenControlRun::create([
            'run_key' => str_repeat('a', 64), 'protocol' => 'mtf_playbook_frozen_control_v1',
            'research_model_id' => 'liquidity_trap_mtf', 'symbol' => 'XAUUSD', 'entry_timeframe' => 'M5',
            'data_hash' => str_repeat('b', 64), 'execution_hash' => str_repeat('c', 64),
            'control_parameter_hash' => str_repeat('d', 64), 'candidate_parameter_hash' => str_repeat('e', 64),
            'status' => 'completed', 'required_streams' => ['H4', 'H1', 'M15', 'M5'], 'dataset_manifest' => [],
            'comparison' => [
                ...$identity,
                'interpretation' => 'candidate_improved_on_this_frozen_replay',
                'candidate' => ['total_trades' => 12],
                'delta' => ['profit_factor' => .2],
            ],
            'reason_codes' => [], 'promotion_evidence' => false, 'completed_at' => now(),
        ]);

        $toolbox = app(AgentResearchPlaybookToolboxService::class)->forAgent([], 'XAUUSD');
        $tool = collect($toolbox['tools'])->firstWhere('id', 'liquidity_trap_mtf');

        $this->assertSame('completed', data_get($tool, 'frozen_control_prior.status'));
        $this->assertTrue((bool) data_get($tool, 'frozen_control_prior.prior_only'));
        $this->assertFalse((bool) data_get($toolbox, 'experience_contract.promotion_evidence'));
        $this->assertContains('paired_frozen_replay', data_get($toolbox, 'experience_contract.agent_owned_evidence_required'));
    }

    public function test_historical_zero_trade_prior_is_projected_as_insufficient_without_rewriting_it(): void
    {
        $identity = app(MtfPlaybookFrozenControlService::class)->currentIdentity();
        MtfPlaybookFrozenControlRun::create([
            'run_key' => hash('sha256', 'zero-trade-prior'), 'protocol' => 'mtf_playbook_frozen_control_v1',
            'research_model_id' => 'liquidity_trap_mtf', 'symbol' => 'XAUUSD', 'entry_timeframe' => 'M5',
            'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
            'control_parameter_hash' => str_repeat('c', 64), 'candidate_parameter_hash' => str_repeat('d', 64),
            'status' => 'completed', 'required_streams' => ['H4', 'H1', 'M15', 'M5'],
            'dataset_manifest' => [], 'comparison' => [
                ...$identity,
                'interpretation' => 'candidate_not_dominant',
                'control' => ['total_trades' => 31], 'candidate' => ['total_trades' => 0],
            ],
            'reason_codes' => [], 'promotion_evidence' => false, 'completed_at' => now(),
        ]);

        $toolbox = app(AgentResearchPlaybookToolboxService::class)->forAgent([], 'XAUUSD');
        $tool = collect($toolbox['tools'])->firstWhere('id', 'liquidity_trap_mtf');

        $this->assertSame('insufficient_candidate_activity', data_get($tool, 'frozen_control_prior.interpretation'));
        $this->assertSame('candidate_not_dominant', data_get(MtfPlaybookFrozenControlRun::first()->comparison, 'interpretation'));
    }

    public function test_next_trial_rotates_through_missing_priors_without_claiming_learning(): void
    {
        $service = app(AgentResearchPlaybookToolboxService::class);
        $first = $service->nextFrozenPriorTrial([], 'XAUUSD');
        $this->assertSame('ready', $first['status']);
        $this->assertSame('liquidity_trap_mtf', data_get($first, 'tool.id'));
        $this->assertFalse($first['agent_owned_evidence']);

        $identity = app(MtfPlaybookFrozenControlService::class)->currentIdentity();
        MtfPlaybookFrozenControlRun::create([
            'run_key' => hash('sha256', 'toolbox-rotation'),
            'protocol' => 'mtf_playbook_frozen_control_v1',
            'research_model_id' => 'liquidity_trap_mtf',
            'symbol' => 'XAUUSD', 'entry_timeframe' => 'M5',
            'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
            'control_parameter_hash' => str_repeat('c', 64), 'candidate_parameter_hash' => str_repeat('d', 64),
            'status' => 'completed', 'required_streams' => ['H4', 'H1', 'M15', 'M5'],
            'dataset_manifest' => [], 'comparison' => [
                ...$identity,
                'interpretation' => 'candidate_not_dominant', 'delta' => ['profit_factor' => -.1],
            ],
            'reason_codes' => [], 'completed_at' => now(), 'promotion_evidence' => false,
        ]);

        $second = $service->nextFrozenPriorTrial([], 'XAUUSD');
        $this->assertSame('adaptive_timeframe_confirmation', data_get($second, 'tool.id'));
        $this->assertFalse($second['promotion_evidence']);
    }

    public function test_completed_prior_from_an_old_runtime_is_scheduled_again(): void
    {
        MtfPlaybookFrozenControlRun::create([
            'run_key' => hash('sha256', 'stale-runtime'), 'protocol' => 'mtf_playbook_frozen_control_v1',
            'research_model_id' => 'liquidity_trap_mtf', 'symbol' => 'XAUUSD', 'entry_timeframe' => 'M5',
            'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
            'control_parameter_hash' => str_repeat('c', 64), 'candidate_parameter_hash' => str_repeat('d', 64),
            'status' => 'completed', 'required_streams' => ['H4', 'H1', 'M15', 'M5'],
            'dataset_manifest' => [], 'comparison' => [
                'python_runtime_hash' => 'old', 'runner_contract_hash' => 'old',
                'candidate' => ['total_trades' => 12],
                'interpretation' => 'candidate_improved_on_this_frozen_replay',
            ],
            'reason_codes' => [], 'promotion_evidence' => false, 'completed_at' => now(),
        ]);

        $next = app(AgentResearchPlaybookToolboxService::class)->nextFrozenPriorTrial([], 'XAUUSD');

        $this->assertSame('liquidity_trap_mtf', data_get($next, 'tool.id'));
        $this->assertSame('not_yet_replayed', data_get($next, 'tool.frozen_control_prior.status'));
    }

    public function test_toolbox_projects_only_the_latest_current_run_for_each_model(): void
    {
        $identity = app(MtfPlaybookFrozenControlService::class)->currentIdentity();
        foreach ([
            ['suffix' => 'older', 'trades' => 2, 'completed_at' => now()->subMinute()],
            ['suffix' => 'latest', 'trades' => 11, 'completed_at' => now()],
        ] as $row) {
            MtfPlaybookFrozenControlRun::create([
                'run_key' => hash('sha256', 'latest-current-'.$row['suffix']),
                'protocol' => MtfPlaybookFrozenControlService::PROTOCOL,
                'research_model_id' => 'confirmation_trend_continuation',
                'symbol' => 'XAUUSD', 'entry_timeframe' => 'M5',
                'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
                'control_parameter_hash' => str_repeat('c', 64), 'candidate_parameter_hash' => str_repeat('d', 64),
                'status' => 'completed', 'required_streams' => ['H1', 'M15', 'M5'],
                'dataset_manifest' => [], 'candidate_result' => ['total_trades' => $row['trades']],
                'comparison' => [
                    ...$identity,
                    'interpretation' => 'candidate_not_dominant',
                    'power' => ['minimum_trades_per_arm' => 8],
                    'candidate' => ['total_trades' => $row['trades']],
                ],
                'reason_codes' => [], 'promotion_evidence' => false,
                'completed_at' => $row['completed_at'],
            ]);
        }

        $toolbox = app(AgentResearchPlaybookToolboxService::class)->forAgent([], 'XAUUSD');
        $tool = collect($toolbox['tools'])->firstWhere('id', 'confirmation_trend_continuation');

        $this->assertSame(11, data_get($tool, 'frozen_control_prior.learning_value.observed_trades'));
    }

    public function test_confirmation_models_can_receive_bounded_first_research_priority(): void
    {
        $next = app(AgentResearchPlaybookToolboxService::class)->nextFrozenPriorTrial(
            [],
            'XAUUSD',
            [],
            ['confirmation_trend_continuation', 'confirmation_breakout_retest'],
        );

        $this->assertSame('ready', $next['status']);
        $this->assertSame('confirmation_trend_continuation', data_get($next, 'tool.id'));
        $this->assertSame('entry_contract_playbook', data_get($next, 'tool.class'));
        $this->assertFalse($next['agent_owned_evidence']);
        $this->assertFalse($next['promotion_evidence']);
    }

    public function test_promising_but_underpowered_prior_is_preserved_for_agent_owned_independent_windows(): void
    {
        $identity = app(MtfPlaybookFrozenControlService::class)->currentIdentity();
        MtfPlaybookFrozenControlRun::create([
            'run_key' => hash('sha256', 'promising-underpowered-confirmation'),
            'protocol' => MtfPlaybookFrozenControlService::PROTOCOL,
            'research_model_id' => 'confirmation_breakout_retest',
            'symbol' => 'XAUUSD', 'entry_timeframe' => 'M5',
            'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
            'control_parameter_hash' => str_repeat('c', 64), 'candidate_parameter_hash' => str_repeat('d', 64),
            'status' => 'completed', 'required_streams' => ['H4', 'H1', 'M15', 'M5'],
            'dataset_manifest' => [], 'comparison' => [
                ...$identity,
                'interpretation' => 'insufficient_candidate_activity',
                'power' => ['minimum_trades_per_arm' => 8],
                'control' => [
                    'total_trades' => 8, 'profit_factor' => .34,
                    'net_profit_percent' => -2.28, 'max_drawdown_percent' => 2.28,
                ],
                'candidate' => [
                    'total_trades' => 3, 'profit_factor' => 54.78,
                    'net_profit_percent' => 28.14, 'max_drawdown_percent' => .49,
                ],
            ],
            'reason_codes' => [], 'completed_at' => now(), 'promotion_evidence' => false,
        ]);

        $toolbox = app(AgentResearchPlaybookToolboxService::class)->forAgent([], 'XAUUSD');
        $tool = collect($toolbox['tools'])->firstWhere('id', 'confirmation_breakout_retest');

        $this->assertSame(
            'promising_underpowered_observation',
            data_get($tool, 'frozen_control_prior.learning_value.status'),
        );
        $this->assertSame(
            'agent_owned_independent_window_pair',
            data_get($tool, 'frozen_control_prior.learning_value.next_evidence'),
        );
        $this->assertTrue((bool) data_get($tool, 'frozen_control_prior.learning_value.preserve_observation'));
        $this->assertFalse((bool) data_get($tool, 'frozen_control_prior.learning_value.inheritance_authority'));
        $this->assertFalse((bool) data_get($tool, 'frozen_control_prior.learning_value.promotion_evidence'));
    }

    public function test_toolbox_consumes_terminal_agent_owned_portability_learning_instead_of_reusing_paper_success(): void
    {
        $identity = app(MtfPlaybookFrozenControlService::class)->currentIdentity();
        $source = MtfPlaybookFrozenControlRun::create([
            'run_key' => hash('sha256', 'toolbox-portability-source'),
            'protocol' => MtfPlaybookFrozenControlService::PROTOCOL,
            'research_model_id' => 'confirmation_breakout_retest',
            'symbol' => 'XAUUSD', 'entry_timeframe' => 'M5',
            'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
            'control_parameter_hash' => str_repeat('c', 64), 'candidate_parameter_hash' => str_repeat('d', 64),
            'status' => 'completed', 'required_streams' => ['H4', 'H1', 'M15', 'M5'],
            'dataset_manifest' => [], 'candidate_result' => ['total_trades' => 9],
            'comparison' => [
                ...$identity,
                'interpretation' => 'candidate_improved_on_this_frozen_replay',
                'power' => ['minimum_trades_per_arm' => 8],
                'candidate' => ['total_trades' => 9],
            ],
            'reason_codes' => [], 'promotion_evidence' => false, 'completed_at' => now(),
        ]);
        MtfAgentValidationRun::create([
            'run_key' => hash('sha256', 'toolbox-portability-validation'),
            'protocol' => 'mtf_powered_prior_agent_validation_v1',
            'source_run_id' => $source->id, 'model_version_id' => null,
            'symbol' => 'XAUUSD', 'entry_timeframe' => 'M5', 'status' => 'completed', 'attempts' => 1,
            'data_hash' => str_repeat('e', 64), 'execution_hash' => str_repeat('f', 64),
            'candidate_parameter_hash' => str_repeat('1', 64), 'control_parameter_hash' => str_repeat('2', 64),
            'dataset_manifest' => [], 'validation_contract' => [],
            'candidate_result' => ['total_trades' => 0], 'control_result' => ['total_trades' => 4],
            'paired_summary' => [
                'verdict' => 'underpowered',
                'next_evidence' => 'reformulate_as_regime_specialist_or_retire_prior',
                'portability_diagnosis' => [
                    'classification' => 'period_regime_or_data_domain_conditioned_prior',
                    'evolution_directive' => [
                        'protocol' => 'mtf_regime_portability_experiment_v1',
                        'changed_axis' => 'market_state_admission_policy',
                    ],
                ],
            ],
            'causal_accounting' => [],
            'reason_codes' => ['PRIOR_PORTABILITY_FAILURE_REQUIRES_CAUSAL_ROUTER_TRIAL'],
            'promotion_evidence' => false, 'started_at' => now(), 'completed_at' => now(),
        ]);

        $toolbox = app(AgentResearchPlaybookToolboxService::class)->forAgent([], 'XAUUSD');
        $tool = collect($toolbox['tools'])->firstWhere('id', 'confirmation_breakout_retest');

        $this->assertSame(
            'period_conditioned_prior_requires_router_ablation',
            data_get($tool, 'frozen_control_prior.learning_value.status'),
        );
        $this->assertTrue((bool) data_get($tool, 'frozen_control_prior.learning_value.canonical_agent_owned_validation_consumed'));
        $this->assertSame(
            'market_state_admission_policy',
            data_get($tool, 'frozen_control_prior.learning_value.evolution_directive.changed_axis'),
        );
        $this->assertFalse((bool) data_get($tool, 'frozen_control_prior.agent_owned_validation.parent_authority'));
        $this->assertFalse((bool) data_get($tool, 'frozen_control_prior.learning_value.promotion_evidence'));
    }
}
