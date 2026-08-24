<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabFailureDojoRun;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\ModelVersion;
use App\Services\FailureDojoService;
use App\Services\FrozenControlScreeningAdmissionService;
use App\Services\GenerationSnapshotAdmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningDeadlockPreventionTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_pair_creates_diagnostic_dojo_not_actionable_backlog(): void
    {
        [, $candidate] = $this->agents();
        $pair = LabLearningLanePair::create([
            'pair_key' => 'invalid-dojo-pair', 'lab_generation_id' => $candidate->lab_generation_id,
            'candidate_agent_id' => $candidate->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'target' => 'profit_factor', 'status' => 'missing_control',
            'failure_signature' => ['signature' => 'invalid-control'], 'candidate_metrics' => ['profit_factor' => .9],
        ]);

        app(FailureDojoService::class)->recordPair($pair);

        $this->assertDatabaseHas('lab_failure_dojo_runs', ['pair_id' => $pair->id, 'status' => 'diagnostic_only']);
        $summary = app(FailureDojoService::class)->summary('XAUUSD', 'H1');
        $this->assertSame(0, $summary['actionable_pending']);
        $this->assertSame(0, LabFailureDojoRun::query()->where('status', 'pending')->count());
    }

    public function test_candidate_waits_for_completed_frozen_control_then_becomes_admissible(): void
    {
        [$control, $candidate] = $this->agents();
        $admission = app(FrozenControlScreeningAdmissionService::class);

        $this->assertSame('waiting', $admission->admission($candidate)['status']);

        $dataHash = str_repeat('a', 64);
        $executionHash = str_repeat('b', 64);
        LabEvaluationRun::create([
            'run_id' => 'control-screen-run', 'lab_generation_id' => $control->lab_generation_id,
            'lab_agent_id' => $control->id, 'model_version_id' => $control->model_version_id,
            'phase' => 'screening', 'mode' => 'screen', 'status' => 'completed',
        ]);
        LabMutationResponseMap::create([
            'response_key' => 'control-screen-map', 'stage' => 'screening', 'status' => 'control',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'lab_agent_id' => $control->id, 'observed_metrics' => ['profit_factor' => 1],
            'metadata' => ['control_contract' => [
                'protocol' => 'frozen_control_v2', 'control_only' => true, 'role' => 'control',
                'generation_id' => $control->lab_generation_id, 'data_hash' => $dataHash,
                'execution_hash' => $executionHash,
            ]],
        ]);

        $this->assertSame('ready', $admission->admission($candidate)['status']);
    }

    public function test_generation_without_price_snapshot_is_fail_closed_at_admission(): void
    {
        [$control] = $this->agents();

        $result = app(GenerationSnapshotAdmissionService::class)->inspect($control->generation);

        $this->assertFalse($result['allowed']);
        $this->assertContains('GENERATION_PRICE_SNAPSHOT_PATH_MISSING', $result['reasons']);
        $this->assertContains('GENERATION_PRICE_SNAPSHOT_HASH_MISSING', $result['reasons']);
    }

    /** @return array{0:LabAgent,1:LabAgent} */
    private function agents(): array
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Deadlock prevention', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'population_size' => 2, 'status' => 'draft', 'trigger_context' => [],
        ]);
        $execution = ['protocol' => 'canonical_market_execution_v1'];
        $controlModel = ModelVersion::create([
            'name' => 'control', 'strategy' => 'hybrid', 'version' => 'v1', 'generation' => 1,
            'status' => 'testing', 'parameters' => [], 'metadata' => [
                'execution_contract' => $execution,
                'control_contract' => ['protocol' => 'frozen_control_v2', 'control_only' => true, 'role' => 'control', 'generation_id' => $generation->id],
            ],
        ]);
        $candidateModel = ModelVersion::create([
            'name' => 'candidate', 'strategy' => 'hybrid', 'version' => 'v1', 'generation' => 1,
            'status' => 'testing', 'parameters' => [], 'metadata' => ['execution_contract' => $execution],
        ]);
        $control = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $controlModel->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'queued', 'parameter_diff' => [],
        ]);
        $candidate = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $candidateModel->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'queued', 'parameter_diff' => ['entry' => ['old' => 1, 'new' => 2]],
        ]);

        return [$control->fresh(['modelVersion', 'generation']), $candidate->fresh(['modelVersion', 'generation'])];
    }
}
