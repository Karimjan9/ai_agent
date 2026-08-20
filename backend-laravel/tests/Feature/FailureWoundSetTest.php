<?php

namespace Tests\Feature;

use App\Models\AgentFailureCase;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\FailureWoundSetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FailureWoundSetTest extends TestCase
{
    use RefreshDatabase;

    public function test_g44_wound_is_sealed_and_same_target_is_required_to_improve(): void
    {
        [$first, $second] = $this->agents();
        $failed = $this->woundResult(.82);

        $sealed = app(FailureWoundSetService::class)->sealFromScreening(
            $first,
            $failed,
            ['FAILED_TEMPORAL_CHUNK_SURVIVAL'],
        );

        $this->assertCount(1, $sealed);
        $this->assertSame('temporal_chunk', $sealed[0]['target_key']);
        $this->assertSame(1, AgentFailureCase::count());
        $this->assertFalse((bool) data_get(AgentFailureCase::first()->evidence, 'promotion_evidence'));
        $this->assertLessThanOrEqual(64, strlen((string) AgentFailureCase::first()->expected_safe_behavior));
        $this->assertSame(
            'Improve sealed temporal_chunk evidence without non-target regression.',
            data_get(AgentFailureCase::first()->evidence, 'expected_safe_behavior_description'),
        );

        $notImproved = app(FailureWoundSetService::class)->evaluateForScreening($second, $failed);
        $this->assertSame('failed', $notImproved['status']);
        $this->assertSame(1, $notImproved['blocking_failure_count']);

        $improved = app(FailureWoundSetService::class)->evaluateForScreening($second, $this->woundResult(1.04));
        $this->assertSame('passed', $improved['status']);
        $this->assertSame('improved', $improved['cases'][0]['status']);
    }

    public function test_wound_does_not_block_a_different_window_protocol(): void
    {
        [$first, $second] = $this->agents();
        $tail = $this->woundResult(.82);
        $tail['screening_survival']['protocol'] = 'screening_survival_v2';
        app(FailureWoundSetService::class)->sealFromScreening($first, $tail, ['FAILED_TEMPORAL_CHUNK_SURVIVAL']);

        $stratified = $this->woundResult(.82);
        $stratified['screening_survival'] = [
            'protocol' => 'screening_survival_v2',
            'worst_temporal_chunk_pf' => .82,
            'stratified_historical_windows' => ['protocol' => 'historical_stratified_windows_v1'],
        ];
        $assessment = app(FailureWoundSetService::class)->evaluateForScreening($second, $stratified);

        $this->assertSame('passed', $assessment['status']);
        $this->assertSame('not_assessed', data_get($assessment, 'cases.0.status'));
        $this->assertContains('window_protocol', (array) data_get($assessment, 'cases.0.compatibility.mismatches'));
    }

    /** @return array{0: LabAgent, 1: LabAgent} */
    private function agents(): array
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Wound set test', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 44, 'trigger_type' => 'test',
            'population_size' => 2, 'status' => 'screened', 'trigger_context' => [],
        ]);
        $models = collect([1, 2])->map(fn (int $version) => ModelVersion::create([
            'name' => 'wound-agent-'.$version, 'strategy' => 'hybrid', 'version' => 'v'.$version,
            'generation' => 44, 'status' => 'testing', 'parameters' => ['gene' => $version],
            'metadata' => [], 'evidence_status' => 'valid',
        ]));

        return $models->map(fn (ModelVersion $model) => LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'parent_a_model_version_id' => null, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'origin' => 'shadow_research',
            'lifecycle_status' => 'screened', 'parameter_diff' => ['gene' => ['old' => 0, 'new' => 1]],
            'sample_count' => 10, 'profit_factor' => .82,
        ]))->values()->all();
    }

    /** @return array<string, mixed> */
    private function woundResult(float $worstChunkPf): array
    {
        return [
            'profit_factor' => 1.02,
            'total_trades' => 20,
            'data_manifest' => ['snapshot_sha256' => str_repeat('a', 64)],
            'execution_contract' => ['execution_hash' => str_repeat('b', 64)],
            'screening_survival' => ['worst_temporal_chunk_pf' => $worstChunkPf],
        ];
    }
}
