<?php

namespace Tests\Feature;

use App\Models\AgentLearningLesson;
use App\Models\AgentLearningRetrieval;
use App\Models\AgentLearningSettlement;
use App\Services\LearningKernelService;
use App\Services\LearningPolicyRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningKernelServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_episode_settlement_applies_hard_safety_veto_and_keeps_promotion_separate(): void
    {
        $kernel = app(LearningKernelService::class);
        $episode = $kernel->openEpisode(null, [
            'decision_key' => 'kernel-veto-1', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'differential_router', 'decision' => 'BUY',
            'context' => ['regime' => 'trend_down', 'volatility' => 'high'],
        ]);
        $result = $kernel->settleOutcome($episode, [
            'source_key' => 'kernel-veto-settlement-1', 'outcome_status' => 'settled',
            'metrics' => ['edge_quality' => .95, 'cost_adjusted_return' => .9, 'drawdown_percent' => 16, 'risk_of_ruin_percent' => 2, 'stress_profit_factor' => 1.4],
        ]);

        $settlement = $result['settlement'];
        $this->assertInstanceOf(AgentLearningSettlement::class, $settlement);
        $this->assertTrue($settlement->hard_failure);
        $this->assertSame('drawdown_risk', $settlement->failure_class);
        $this->assertLessThan(0, $settlement->selection_reward);
        $this->assertFalse($result['promotion_evidence']);
        $this->assertSame('technical_quarantine', $episode->fresh()->status);
    }

    public function test_contextual_retrieval_is_consumed_and_linked_to_the_settled_episode(): void
    {
        $lesson = AgentLearningLesson::create([
            'lesson_id' => '00000000-0000-0000-0000-000000000111', 'lesson_hash' => str_repeat('a', 128),
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
            'lesson_type' => 'skill_lesson', 'status' => 'confirmed', 'failure_class' => 'drawdown_risk',
            'parameter_key' => 'high_volatility_risk_multiplier', 'regime' => 'trend_down', 'volatility' => 'high',
            'outcome' => 'beneficial', 'independent_window_count' => 3, 'confirmation_count' => 2,
            'lower_confidence_bound' => .55, 'source_run_ids' => ['run-1'], 'evidence' => ['promotion_evidence' => false], 'observed_at' => now(),
        ]);
        AgentLearningLesson::create([
            'lesson_id' => '00000000-0000-0000-0000-000000000112', 'lesson_hash' => str_repeat('b', 128),
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
            'lesson_type' => 'skill_lesson', 'status' => 'confirmed', 'failure_class' => 'drawdown_risk',
            'parameter_key' => 'wrong_regime_gene', 'regime' => 'range', 'outcome' => 'beneficial',
            'source_run_ids' => ['run-2'], 'evidence' => [], 'observed_at' => now(),
        ]);
        $kernel = app(LearningKernelService::class);
        $episode = $kernel->openEpisode(null, ['decision_key' => 'kernel-consumption-1', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router', 'context' => ['regime' => 'trend_down', 'volatility' => 'high']]);
        $packet = $kernel->retrieveForGeneration('XAUUSD', 'H1', 'differential_router', ['regime' => 'trend_down', 'volatility' => 'high'], null, $episode->id);
        $this->assertCount(1, $packet['positive_lessons']);
        $this->assertSame($lesson->id, $packet['positive_lessons'][0]['lesson_id']);
        $experiment = $kernel->proposeExperiment($packet, 'drawdown_risk', ['high_volatility_risk_multiplier']);
        $this->assertSame('planned', $experiment['status']);
        $kernel->recordConsumption($packet, $experiment, null, $episode);
        $kernel->settleOutcome($episode, ['source_key' => 'kernel-consumption-settlement-1', 'outcome_status' => 'settled', 'parameter_key' => 'high_volatility_risk_multiplier', 'metrics' => ['edge_quality' => .7, 'cost_adjusted_return' => .7, 'drawdown_safety' => .8]]);

        $retrieval = AgentLearningRetrieval::firstOrFail();
        $this->assertSame('consumed', $retrieval->retrieval_state);
        $this->assertNotNull($retrieval->outcome_linked_at);
        $pulse = $kernel->pulse('XAUUSD', 'H1', 'differential_router');
        $this->assertSame(1, $pulse['lessons_consumed']);
        $this->assertSame(1.0, $pulse['retrieval_to_outcome_rate']);
    }

    public function test_contextual_retrieval_handles_structured_runtime_context_without_casting_error(): void
    {
        AgentLearningLesson::create([
            'lesson_id' => '00000000-0000-0000-0000-000000000113', 'lesson_hash' => str_repeat('c', 128),
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'lesson_type' => 'uncertainty_lesson', 'status' => 'provisional', 'failure_class' => 'transition',
            'parameter_key' => 'transition_firewall', 'transition_state' => 'transition_wait',
            'outcome' => 'uncertain', 'source_run_ids' => ['run-3'], 'evidence' => [], 'observed_at' => now(),
        ]);

        $packet = app(LearningKernelService::class)->retrieveForGeneration(
            'XAUUSD', 'H1', 'hybrid', ['transition_state' => ['state' => 'transition_wait']],
        );

        $this->assertSame('ok', $packet['status']);
        $this->assertSame(0, $packet['retrieval_count']);
    }

    public function test_policy_versions_are_immutable_and_activation_requires_external_approval(): void
    {
        $registry = app(LearningPolicyRegistryService::class);
        $policy = $registry->register('risk-router', ['risk_multiplier' => .7], ['symbol' => 'XAUUSD', 'timeframe' => 'H1']);
        $this->assertSame('draft', $policy->state);
        $shadow = $registry->transition($policy, 'shadow');
        $this->assertSame('shadow', $shadow->state);
        $canary = $registry->transition($shadow, 'canary');
        $this->assertSame('canary', $canary->state);
        $this->assertSame('approval_required', $registry->transition($canary, 'active')['status']);
        $active = $registry->transition($canary->fresh(), 'active', ['immutable_gate_passed' => true, 'operator_approved' => true]);
        $this->assertSame('active', $active->state);
    }
}
