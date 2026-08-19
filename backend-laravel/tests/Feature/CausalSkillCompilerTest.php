<?php

namespace Tests\Feature;

use App\Models\LabFailureDojoRun;
use App\Services\CausalSkillCompilerService;
use App\Services\StrategicResearchDirectorService;
use Tests\TestCase;

class CausalSkillCompilerTest extends TestCase
{
    public function test_compiler_requires_causal_artifacts_and_never_grants_promotion(): void
    {
        $compiler = app(CausalSkillCompilerService::class);
        $result = [
            'control_pair_available' => true,
            'same_snapshot' => true,
            'same_execution_contract' => true,
            'mutation_observability' => [
                'observable_effect' => true,
                'behavioral_delta' => ['trade_ledger_changed' => true],
            ],
            'counterfactual_replay' => ['branches' => [
                ['branch' => 'veto_on'], ['branch' => 'veto_off'], ['branch' => 'delayed_entry'],
                ['branch' => 'half_risk'], ['branch' => 'alternate_exit'],
            ]],
            'market_adaptive_replay' => ['checkpoint_windows' => [
                ['trades' => 20, 'profit_factor' => 1.4, 'net_profit_percent' => 2],
                ['trades' => 20, 'profit_factor' => 1.35, 'net_profit_percent' => 1],
                ['trades' => 20, 'profit_factor' => .9, 'net_profit_percent' => -1],
            ]],
        ];

        $compiled = $compiler->compile(null, [
            'signature' => 'temporal-signature',
            'failure_target' => 'temporal_stability',
            'changed_gene' => 'state_machine_variant',
        ], $result, ['delta' => .12]);

        $this->assertSame(CausalSkillCompilerService::PROTOCOL, $compiled['protocol']);
        $this->assertSame('regime_transition', $compiled['decision_stage']);
        $this->assertSame('research_incomplete', $compiled['status']);
        $this->assertSame('diagnostic_only', data_get($compiled, 'reusable_lesson.status'));
        $this->assertFalse((bool) data_get($compiled, 'promotion_evidence'));
        $this->assertFalse((bool) data_get($compiled, 'reusable_lesson.mutation_credit_allowed'));
    }

    public function test_structural_escape_freezes_repeated_scalar_search_and_router_waits(): void
    {
        $compiler = app(CausalSkillCompilerService::class);

        $escape = $compiler->structuralEscapeContract([
            ['changed_gene' => 'transition_wait_candles'],
            ['changed_gene' => 'cooldown_candles'],
        ]);
        $router = $compiler->routerContract([
            ['decision' => 'ALLOW'], ['decision' => 'WAIT'],
        ]);

        $this->assertTrue($escape['freeze_scalar_search']);
        $this->assertSame('two_or_more_independent_scalar_failures', $escape['reason']);
        $this->assertSame('wait_on_disagreement', $router['status']);
        $this->assertSame('WAIT', $router['disagreement_action']);
    }

    public function test_mentor_and_interaction_contracts_are_strict(): void
    {
        $compiler = app(CausalSkillCompilerService::class);
        $provisional = $compiler->mentorContract(['independent_windows' => 2, 'positive_windows' => 2]);
        $confirmed = $compiler->mentorContract(['independent_windows' => 3, 'positive_windows' => 2]);
        $interaction = $compiler->interactionContract(['entry_topology_variant', 'regime_classifier_variant'], [
            ['status' => 'confirmed_shadow_mentor', 'independent_windows' => 3, 'positive_windows' => 2],
            ['status' => 'confirmed_shadow_mentor', 'independent_windows' => 3, 'positive_windows' => 2],
        ]);

        $this->assertSame('provisional_shadow_only', $provisional['status']);
        $this->assertSame('confirmed_shadow_mentor', $confirmed['status']);
        $this->assertSame('eligible_research_only', $interaction['status']);
        $this->assertFalse((bool) $interaction['mutation_credit']);
        $this->assertContains('gene_a_plus_b', $interaction['required_arms']);
    }

    public function test_strategic_director_selects_a_research_action_and_keeps_all_gates_closed(): void
    {
        $director = app(StrategicResearchDirectorService::class);
        $run = new LabFailureDojoRun([
            'id' => 42,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'target' => 'temporal_stability',
            'failure_signature' => [
                'signature' => 'temporal-transition-failure',
                'failure_reason' => 'FAILED_TEMPORAL_CHUNK_SURVIVAL',
                'state' => ['regime' => 'transition', 'signal_age_candles' => 3],
            ],
            'evidence' => [
                'information_gain_priority' => [
                    'components' => [
                        'novelty' => .8,
                        'causal_leverage' => .7,
                        'replay_readiness' => .6,
                    ],
                ],
                'causal_skill_compiler' => [
                    'decision_stage' => 'regime_transition',
                    'hypothesis' => [
                        'falsifiable_statement' => 'The transition classifier changes decisions and reduces false entries.',
                        'expected_behavioral_delta' => ['decision_ledger_changed' => true],
                    ],
                    'exact_control' => ['status' => 'missing_or_unverified'],
                    'counterfactual_replay' => ['observed_branches' => ['control']],
                    'prediction_contract' => ['status' => 'declared'],
                    'independent_windows' => ['observed_windows' => 0, 'positive_windows' => 0],
                    'behavioral_delta' => ['status' => 'not_observed'],
                    'reusable_lesson' => ['status' => 'diagnostic_only'],
                ],
            ],
        ]);

        $plan = $director->planFor($run);

        $this->assertSame(StrategicResearchDirectorService::PROTOCOL, $plan['protocol']);
        $this->assertSame('regime_classifier', $plan['decision_action']);
        $this->assertSame('REQUEST_CONTROL', $plan['next_action']);
        $this->assertSame('under_observed', data_get($plan, 'belief_state.state'));
        $this->assertSame(StrategicResearchDirectorService::CAUSAL_GRAPH_PROTOCOL, data_get($plan, 'causal_decision_graph.protocol'));
        $this->assertSame('pre_replay_commitment_required', data_get($plan, 'prediction_market.status'));
        $this->assertSame('active_research', data_get($plan, 'hypothesis_retirement.status'));
        $this->assertCount(5, data_get($plan, 'research_tree.branches'));
        $this->assertSame('incomplete', data_get($plan, 'counterfactual_lab.status'));
        $this->assertSame('not_eligible', data_get($plan, 'strategic_credit.status'));
        $this->assertFalse((bool) data_get($plan, 'promotion_evidence'));
        $this->assertFalse((bool) data_get($plan, 'strategic_credit.mutation_credit_allowed'));
    }

    public function test_strategic_prediction_score_is_diagnostic_only(): void
    {
        $director = app(StrategicResearchDirectorService::class);
        $score = $director->scorePrediction([
            'decision_layer' => 'regime_transition',
            'proposal' => [
                'expected_behavioral_delta' => [
                    'decision_ledger_changed' => true,
                    'trade_ledger_changed' => false,
                ],
            ],
        ], [
            'mutation_observability' => [
                'behavioral_delta' => [
                    'decision_ledger_changed' => true,
                    'trade_ledger_changed' => true,
                ],
            ],
            'decision_stage' => 'regime_transition',
        ]);

        $this->assertSame('scored', $score['status']);
        $this->assertSame(.5, $score['accuracy']);
        $this->assertTrue(data_get($score, 'cause_prediction.matched'));
        $this->assertFalse((bool) $score['promotion_evidence']);
    }

    public function test_repeated_unobserved_hypothesis_is_frozen_until_new_evidence(): void
    {
        $director = app(StrategicResearchDirectorService::class);
        $run = new LabFailureDojoRun([
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'family' => 'hybrid',
            'target' => 'temporal_stability',
            'failure_signature' => [
                'failure_type' => 'FAILED_TEMPORAL_CHUNK_SURVIVAL',
                'repeat_count' => 2,
            ],
            'evidence' => [
                'causal_skill_compiler' => [
                    'decision_stage' => 'regime_transition',
                    'behavioral_delta' => ['status' => 'not_observed'],
                    'exact_control' => ['status' => 'missing_or_unverified'],
                    'independent_windows' => ['observed_windows' => 0],
                    'counterfactual_replay' => ['status' => 'incomplete'],
                ],
            ],
        ]);

        $plan = $director->planFor($run);

        $this->assertSame('freeze_until_new_evidence', data_get($plan, 'hypothesis_retirement.status'));
        $this->assertTrue((bool) data_get($plan, 'hypothesis_retirement.scalar_rescue_reentry_forbidden'));
        $this->assertTrue((bool) data_get($plan, 'executable_lesson.validity.apply_outside_scope') === false);
        $this->assertSame('WAIT', data_get($plan, 'specialist_router.disagreement_action'));
    }
}
