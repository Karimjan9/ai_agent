<?php

namespace Tests\Feature;

use App\Models\CandidateGateDecision;
use App\Models\ModelMarketPerformance;
use App\Services\AgentProgressCardService;
use App\Services\LabPopulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentProgressCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_moves_through_bounded_progress_stages_and_keeps_failure_context(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'progress_card_test', true);
        $agent = $generation->agents->first();
        $agent->update(['lifecycle_status' => 'screened']);

        $decision = CandidateGateDecision::create([
            'lab_agent_id' => $agent->id,
            'stage' => 'screening',
            'decision' => 'failed',
            'reason_codes' => ['FAILED_CALENDAR_MONTH_SURVIVAL'],
            'metrics' => ['promotion_evidence' => false],
            'evaluated_at' => now(),
            'attribution_status' => 'agent_scoped',
        ]);
        $card = app(AgentProgressCardService::class)->sync(
            $agent->fresh(['modelVersion', 'generation']),
            null,
            [
                'monthly_passport' => ['failed_months' => 1, 'rolling_forward_wins' => 1],
                'evidence_run_id' => 'progress-screen',
            ],
            $decision,
        );

        $this->assertSame('diagnosed', $card->stage);
        $this->assertSame('blocked', $card->status);
        $this->assertSame('monthly_survival', $card->primary_failure);
        $this->assertSame('run_one_gene_monthly_repair', $card->next_action);
        $this->assertNotNull($card->changed_gene);

        $agent->update(['lifecycle_status' => 'full_queued']);
        $repaired = app(AgentProgressCardService::class)->sync(
            $agent->fresh(['modelVersion', 'generation']),
            null,
            [
                'paired_replay' => ['status' => 'confirmed'],
                'no_regression_contract' => ['status' => 'passed'],
                'evidence_run_id' => 'progress-repaired',
            ],
        );
        $this->assertSame('specialist', $repaired->stage);
        $this->assertContains('paired_replay', $repaired->gates_passed);
        $this->assertContains('no_regression', $repaired->gates_passed);

        $elite = app(AgentProgressCardService::class)->sync(
            $agent->fresh(['modelVersion', 'generation']),
            null,
            [
                'elite_agent_passport' => ['status' => 'passed'],
                'evidence_run_id' => 'progress-elite',
            ],
        );
        $this->assertSame('elite_candidate', $elite->stage);

        $agent->update(['lifecycle_status' => 'forward_validated']);
        $performance = ModelMarketPerformance::create([
            'model_version_id' => $agent->model_version_id,
            'symbol' => $agent->symbol,
            'timeframe' => $agent->timeframe,
            'strategy_family' => $agent->strategy_family,
            'status' => 'forward_validated',
            'evidence_status' => 'valid',
            'forward_score' => 70,
            'sample_count' => 40,
            'rolling_windows_count' => 4,
            'rolling_forward_wins' => 4,
            'metrics' => [
                'elite_agent_passport' => [
                    'status' => 'passed',
                    'elite_quorum' => ['status' => 'passed'],
                ],
                'challenger_protocol' => [
                    'observed_forward_windows' => 4,
                    'positive_forward_windows' => 4,
                ],
                'gold_holdout' => [
                    'protocol' => 'gold_holdout_v1',
                    'used_for_training' => false,
                    'used_for_evolution' => false,
                ],
                'evidence_run_id' => 'progress-forward',
            ],
        ]);
        $forwardDecision = CandidateGateDecision::create([
            'model_market_performance_id' => $performance->id,
            'lab_agent_id' => $agent->id,
            'stage' => 'statistical_forward_gate',
            'decision' => 'passed',
            'reason_codes' => [],
            'metrics' => $performance->metrics,
            'evaluated_at' => now(),
            'attribution_status' => 'deterministic',
        ]);
        $paperReady = app(AgentProgressCardService::class)->sync(
            $agent->fresh(['modelVersion', 'generation']),
            $performance,
            $performance->metrics,
            $forwardDecision,
        );
        $this->assertSame('paper_ready', $paperReady->stage);
        $this->assertSame('capture_immutable_paper_evidence', $paperReady->next_action);

        $agent->update(['lifecycle_status' => 'rejected']);
        $stable = app(AgentProgressCardService::class)->sync($agent->fresh(['modelVersion', 'generation']), $performance, [
            'is_overfit' => true,
            'evidence_run_id' => 'progress-failed-later',
        ]);
        $this->assertSame('paper_ready', $stable->stage);
        $this->assertSame('quarantined', $stable->status);
        $this->assertCount(4, $stable->stage_history);
    }
}
