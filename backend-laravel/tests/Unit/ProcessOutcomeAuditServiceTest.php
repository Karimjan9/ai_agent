<?php

namespace Tests\Unit;

use App\Services\LearningReflectionService;
use App\Services\LearningRewardService;
use App\Services\ProcessOutcomeAuditService;
use Tests\TestCase;

class ProcessOutcomeAuditServiceTest extends TestCase
{
    public function test_good_loss_preserves_process_and_routes_the_slow_loop_to_edge_research(): void
    {
        $audit = $this->project(-1.0, 1.0);

        $this->assertSame('GOOD_LOSS', $audit['quadrant']);
        $this->assertSame('preserve_process_and_test_edge_or_context', data_get($audit, 'learning_contract.action'));
        $this->assertFalse((bool) data_get($audit, 'learning_contract.process_repair_required'));
        $this->assertNull($audit['hard_veto']);

        $reflection = app(LearningReflectionService::class)->reflect([
            'metrics' => ['process_outcome_audit' => $audit],
        ], ['vetoes' => [], 'selection_reward' => -.5]);

        $this->assertSame('good_process_negative_outcome', $reflection['failure']);
        $this->assertSame('mutate_edge_quality', $reflection['next_action']);
        $this->assertSame('one_gene_paired_replay', $reflection['test']);
    }

    public function test_bad_win_is_a_hard_learning_veto_even_when_financial_result_is_positive(): void
    {
        $audit = $this->project(4.0, .35);
        $this->assertSame('BAD_WIN', $audit['quadrant']);
        $this->assertSame('quarantine_profit_and_repair_process_first', data_get($audit, 'learning_contract.action'));
        $this->assertSame('BAD_PROCESS_OUTCOME', $audit['hard_veto']);

        $reward = app(LearningRewardService::class)->score([
            'total_trades' => 20,
            'edge_quality' => 1, 'cost_adjusted_return' => 1,
            'drawdown_safety' => 1, 'risk_of_ruin' => 1,
            'temporal_stability' => 1, 'regime_coverage' => 1,
            'calibration' => 1, 'abstention_quality' => 1,
            'process_outcome_audit' => $audit,
        ]);

        $this->assertTrue($reward['hard_failure']);
        $this->assertContains('BAD_PROCESS_OUTCOME', $reward['vetoes']);
        $this->assertLessThan(0, $reward['selection_reward']);
        $this->assertSame('process_integrity_error', app(LearningReflectionService::class)->failureClass(
            ['metrics' => ['process_outcome_audit' => $audit]],
            $reward,
        ));
    }

    public function test_hard_discipline_violation_overrides_a_perfect_reported_process_score(): void
    {
        $service = app(ProcessOutcomeAuditService::class);
        $audit = $service->project([
            'net_profit_percent' => 2,
            'smart_discipline' => [
                'average_process_adherence_score' => 100,
                'rule_violation_count' => 1,
                'violations' => ['fomo_entry'],
            ],
        ], ['blocks' => ['process' => ['score' => 1, 'powered' => true]]], [
            'dimensions' => ['decision_discipline' => ['score' => 1, 'powered' => true]],
        ]);

        $this->assertSame('BAD_WIN', $audit['quadrant']);
        $this->assertContains('SMART_DISCIPLINE_RULE_VIOLATION_COUNT', data_get($audit, 'process.hard_violations'));
        $this->assertSame('M12', data_get($audit, 'mistake_attribution.0.code'));
    }

    public function test_missing_process_or_financial_evidence_stays_unresolved(): void
    {
        $audit = app(ProcessOutcomeAuditService::class)->project([], ['blocks' => []], ['dimensions' => []]);

        $this->assertSame('insufficient_evidence', $audit['status']);
        $this->assertSame('UNRESOLVED', $audit['quadrant']);
        $this->assertFalse((bool) data_get($audit, 'learning_contract.financial_result_learning_eligible'));
        $this->assertFalse($audit['promotion_evidence']);
    }

    private function project(float $netProfitPercent, float $processScore): array
    {
        return app(ProcessOutcomeAuditService::class)->project([
            'net_profit_percent' => $netProfitPercent,
        ], ['blocks' => ['process' => ['score' => $processScore, 'powered' => true]]], [
            'dimensions' => ['decision_discipline' => ['score' => $processScore, 'powered' => true]],
        ]);
    }
}
