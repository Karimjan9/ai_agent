<?php

namespace Tests\Feature;

use App\Jobs\ProcessLabScreeningLearningProjection;
use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabMutationResponseMap;
use App\Models\ModelVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScreeningLearningProjectionReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_run_with_lost_projection_is_redispatched_without_replay(): void
    {
        Queue::fake();
        [$agent, $run, $decision] = $this->screenedAgent();

        $this->artisan('trading:reconcile-screening-learning-projections', [
            '--generation' => 127,
            '--limit' => 2,
            '--apply' => true,
            '--json' => true,
        ])->assertSuccessful();

        Queue::assertPushed(ProcessLabScreeningLearningProjection::class, function ($job) use ($agent, $run, $decision): bool {
            return $job->labAgentId === $agent->id
                && $job->runId === $run->run_id
                && $job->decisionId === $decision->id
                && data_get($job->screenProjection, 'evidence_run_id') === $run->run_id;
        });
        $this->assertDatabaseCount('lab_evaluation_runs', 1);
    }

    public function test_existing_response_map_makes_reconciliation_idempotent(): void
    {
        Queue::fake();
        [$agent, $run] = $this->screenedAgent();
        LabMutationResponseMap::create([
            'response_key' => 'already-projected',
            'stage' => 'screening',
            'status' => 'control',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'lab_agent_id' => $agent->id,
            'model_version_id' => $agent->model_version_id,
            'evidence_run_id' => $run->run_id,
            'observed_metrics' => ['profit_factor' => 1.0],
        ]);

        $this->artisan('trading:reconcile-screening-learning-projections', [
            '--generation' => 127,
            '--apply' => true,
        ])->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /** @return array{0: LabAgent, 1: LabEvaluationRun, 2: CandidateGateDecision} */
    private function screenedAgent(): array
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'Projection reconciliation',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid'],
            'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 127,
            'trigger_type' => 'learning_confirmation',
            'population_size' => 1,
            'status' => 'screening',
            'trigger_context' => [],
        ]);
        $model = ModelVersion::create([
            'name' => 'frozen-control',
            'strategy' => 'hybrid',
            'version' => 'v1',
            'generation' => 127,
            'status' => 'testing',
            'parameters' => ['entry_threshold' => 1],
            'metadata' => ['last_screen_result' => [
                'profit_factor' => 1.0,
                'total_trades' => 20,
                'promotion_evidence' => false,
            ]],
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id,
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'origin' => 'test',
            'lifecycle_status' => 'screened',
            'parameter_diff' => [],
        ]);
        $decision = CandidateGateDecision::create([
            'lab_agent_id' => $agent->id,
            'stage' => 'screening',
            'decision' => 'failed',
            'reason_codes' => ['TEST'],
            'metrics' => ['profit_factor' => 1.0],
            'evaluated_at' => now(),
        ]);
        $run = LabEvaluationRun::create([
            'run_id' => 'projection-run-127',
            'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id,
            'model_version_id' => $model->id,
            'phase' => 'screening',
            'mode' => 'screen',
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'metadata' => ['screen_decision_id' => $decision->id, 'terminal' => true],
        ]);

        return [$agent->fresh(['modelVersion']), $run, $decision];
    }
}
