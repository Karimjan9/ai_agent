<?php

namespace Tests\Unit;

use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Services\CouncilCompatibilityService;
use App\Services\CouncilCurriculumService;
use App\Services\ChampionCouncilTransitionService;
use App\Services\ChampionCouncilCanaryRouterService;
use App\Services\SpecialistPassportService;
use PHPUnit\Framework\TestCase;

class ChampionCouncilArchitectureTest extends TestCase
{
    public function test_compatibility_requires_two_regimes_and_a_router(): void
    {
        $service = new CouncilCompatibilityService();

        $blocked = $service->assess([
            ['role' => 'trend_up_specialist', 'target_regime' => 'trend_up'],
            ['role' => 'range_specialist', 'target_regime' => 'range'],
        ]);
        $this->assertSame('incompatible', $blocked['status']);
        $this->assertContains('COUNCIL_NEEDS_TRANSITION_RISK_ROUTER', $blocked['reason_codes']);

        $ready = $service->assess([
            ['role' => 'trend_up_specialist', 'target_regime' => 'trend_up'],
            ['role' => 'range_specialist', 'target_regime' => 'range'],
            ['role' => 'transition_risk_router', 'target_regime' => 'transition'],
        ]);
        $this->assertSame('compatible', $ready['status']);
    }

    public function test_compatibility_rejects_hidden_behavioral_clones(): void
    {
        $result = (new CouncilCompatibilityService())->assess([
            ['role' => 'trend_up_specialist', 'target_regime' => 'trend_up', 'behavior_fingerprint' => 'same-replay'],
            ['role' => 'range_specialist', 'target_regime' => 'range', 'behavior_fingerprint' => 'same-replay'],
            ['role' => 'transition_risk_router', 'target_regime' => 'transition', 'behavior_fingerprint' => 'router-replay'],
        ]);

        $this->assertSame('incompatible', $result['status']);
        $this->assertContains('COUNCIL_HAS_BEHAVIORAL_CLONE', $result['reason_codes']);
    }

    public function test_curriculum_turns_repeat_failure_into_architecture_escape(): void
    {
        $lesson = (new CouncilCurriculumService())->next(
            ['role' => 'trend_up_specialist', 'stage' => 'specialist_candidate'],
            ['repeat_count' => 2],
        );

        $this->assertSame('adversarial', $lesson['stage']);
        $this->assertSame('architecture_escape_for_repeat_failure', $lesson['objective']);
        $this->assertTrue($lesson['rules']['research_only']);
        $this->assertFalse($lesson['rules']['promotion_evidence']);
    }

    public function test_passport_is_evidence_projection_and_not_a_promotion_shortcut(): void
    {
        $model = new ModelVersion([
            'metadata' => [
                'council_specialist_contract' => [
                    'role' => 'trend_up_specialist',
                    'owner_regime' => 'trend_up',
                ],
            ],
        ]);
        $candidate = new ModelMarketPerformance([
            'model_version_id' => 7,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'status' => 'forward_validated',
            'evidence_status' => 'valid',
            'metrics' => [
                'elite_agent_passport' => ['status' => 'passed'],
                'pf_attribution' => [
                    'breakdown' => [
                        'by_regime' => [
                            'trend_up' => ['trades' => 12, 'net_pf' => 1.4],
                        ],
                    ],
                ],
            ],
        ]);
        $candidate->setRelation('modelVersion', $model);

        $passport = (new SpecialistPassportService())->build($candidate);

        $this->assertSame('passed', $passport['status']);
        $this->assertFalse($passport['promotion_evidence']);
        $this->assertSame('trend_up_regime_ownership', $passport['skill']['capability']);
    }

    public function test_transition_protects_incumbent_until_council_proves_parity_and_anchor_independence(): void
    {
        $service = new ChampionCouncilTransitionService();
        $base = [
            'council_compatibility_status' => 'compatible',
            'all_council_members_passed' => true,
            'incumbent_score' => 1.0,
            'council_score' => 1.0,
            'hybrid_score' => 1.0,
            'shadow_windows' => 3,
            'hybrid_windows' => 3,
            'council_windows' => 3,
            'worst_window_regression' => .01,
            'router_switch_rate' => .10,
            'council_synergy_delta' => .02,
            'leave_one_out_passed' => true,
        ];

        $beforeAblation = $service->evaluate([], [], $base);
        $this->assertSame('anchor_ablation', $beforeAblation['stage']);
        $this->assertSame('KEEP_INCUMBENT', $beforeAblation['decision']);

        $active = $service->evaluate([], [], [
            ...$base,
            'anchor_ablation_passed' => true,
            'anchor_ablation_windows' => 2,
            'anchor_dependency' => .10,
        ]);
        $this->assertSame('council_active', $active['stage']);
        $this->assertSame('PROMOTE_COUNCIL', $active['decision']);
        $this->assertSame(1.0, $active['council_canary_share']);
        $this->assertFalse($active['promotion_evidence']);
    }

    public function test_transition_rolls_back_when_drift_or_catastrophic_regression_appears(): void
    {
        $result = (new ChampionCouncilTransitionService())->evaluate([], [], [
            'council_compatibility_status' => 'compatible',
            'all_council_members_passed' => true,
            'incumbent_score' => 1.0,
            'council_score' => .7,
            'rollback_requested' => true,
        ]);

        $this->assertSame('rollback', $result['stage']);
        $this->assertSame('ROLLBACK_TO_INCUMBENT', $result['decision']);
        $this->assertSame('incumbent_champion', $result['fallback']);
    }

    public function test_canary_assignment_is_deterministic_and_fail_closed(): void
    {
        $router = new ChampionCouncilCanaryRouterService();
        $transition = ['decision' => 'HYBRID_CANARY', 'council_canary_share' => .25];
        $first = $router->decide($transition, 'XAUUSD|H1|candle-100');
        $second = $router->decide($transition, 'XAUUSD|H1|candle-100');
        $this->assertSame($first, $second);
        $this->assertSame('incumbent', $router->decide(['decision' => 'KEEP_INCUMBENT', 'council_canary_share' => 1], 'same')['route']);
    }
}
