<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\CandidateGateDecisionService;
use App\Services\CouncilDisagreementService;
use App\Services\ExecutionContractService;
use App\Services\LabAgentEvaluationService;
use App\Services\LabDatasetExportService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabInstrumentResearchService;
use App\Services\LabTrialLedgerService;
use App\Services\MultiTimeframePilotService;
use App\Services\SpecialistCouncilContractService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Storage/identity regression only: snapshot, replay completeness and quality
 * gate boundaries below are conditional fixtures, not measured market proof.
 * Both real screening writers, run/artifact persistence and council identity
 * validation execute against the isolated test database.
 */
class ScreeningExecutionDefinitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_screen_observation_preserves_original_council_definition_and_remaining_runtime_guards(): void
    {
        [$agent, $model, $definition, $manifest, $originalRuntime, $originalHash, $evidence] = $this->fixture();
        Http::fake();
        $run = $evidence->beginRun($agent, 'screening', 'incremental', ['source' => 'conditional_storage_regression']);
        $evidence->attachRequest($run, $this->request($model, $definition));
        $item = $this->replayItem($definition);

        (new \ReflectionMethod(LabAgentEvaluationService::class, 'persistScreenBatchItem'))->invoke(
            app(LabAgentEvaluationService::class), $agent, $run, $item,
            ['data_hash' => str_repeat('a', 64), 'candle_count' => 500],
        );

        $this->assertPersistedObservation($agent, $model, $run, $item, $definition, $manifest, $originalRuntime, $originalHash, $evidence);
        Http::assertNothingSent();
    }

    public function test_public_single_screen_keeps_original_definition_and_persists_actual_response_separately(): void
    {
        [$agent, $model, $definition, $manifest, $originalRuntime, $originalHash, $evidence] = $this->fixture();
        $pilot = \Mockery::mock(MultiTimeframePilotService::class)->makePartial();
        $pilot->shouldReceive('replayTimeframe')->with('XAUUSD', 'H1')->andReturn('H1');
        $this->app->instance(MultiTimeframePilotService::class, $pilot);
        $rows = [];
        for ($i = 0; $i < 500; $i++) {
            $rows[] = ['time' => CarbonImmutable::parse('2025-01-01T00:00:00Z')->addHours($i)->toIso8601String(),
                'open' => 2000, 'high' => 2001, 'low' => 1999, 'close' => 2000, 'volume' => 100];
        }
        $snapshot = ['protocol' => 'conditional_snapshot_storage_fixture',
            'path' => '/conditional-test/no-provider-read.csv', 'sha256' => str_repeat('a', 64),
            'manifest' => ['protocol' => 'conditional_snapshot_storage_fixture', 'row_count' => count($rows),
                'first_candle_at' => $rows[0]['time'], 'last_candle_at' => $rows[499]['time']]];
        $this->mock(LabDatasetExportService::class, function ($mock) use ($snapshot, $rows): void {
            $mock->shouldReceive('ensureGenerationSnapshot')->once()->andReturn($snapshot);
            $mock->shouldReceive('ensureGenerationFoundationSnapshot')->once()->andReturn($snapshot);
            $mock->shouldReceive('rowsFromSnapshot')->once()->with($snapshot['path'], 5000)->andReturn($rows);
        });
        $item = $this->replayItem($definition);
        Http::fake([
            '*/api/replay-status' => Http::response(['protocol' => 'replay_liveness_v2_bounded_worker', 'active_requests' => 0]),
            '*/api/backtest/run-all' => Http::response(['leaderboard' => [$item]]),
        ]);

        app(LabAgentEvaluationService::class)->screen($agent);

        $run = LabEvaluationRun::where('lab_agent_id', $agent->id)->sole();
        $this->assertPersistedObservation($agent, $model, $run, $item, $definition, $manifest, $originalRuntime, $originalHash, $evidence);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/backtest/run-all')
            && app(ExecutionContractService::class)->matches($request['execution_contract'], 'XAUUSD', 'H1')
            && $request['strategies'][0]['parameters'] === $model->parameters);
        $this->assertNotNull($run->request_hash);
    }

    public function test_genuine_parameter_execution_definition_and_assignment_changes_still_refuse_the_original_council(): void
    {
        [$agent, $model, $definition, $manifest, , $originalHash] = $this->fixture();
        $originalMetadata = $model->metadata;
        $originalParameters = $model->parameters;
        foreach (['parameters', 'execution_contract', 'instrument_research_assignment'] as $change) {
            $model->forceFill(['parameters' => $originalParameters, 'metadata' => $originalMetadata])->save();
            if ($change === 'parameters') {
                $model->forceFill(['parameters' => [...$originalParameters, 'ema_fast' => 5]])->save();
            } else {
                $metadata = $originalMetadata;
                $metadata[$change] = $change === 'execution_contract'
                    ? [...$definition, 'parameters' => [...$definition['parameters'], 'spread_points' => 999]]
                    : ['protocol' => 'instrument_research_assignment_v1', 'assignment_id' => 'different-original-assignment'];
                $model->forceFill(['metadata' => $metadata])->save();
            }
            $this->assertNotSame($originalHash, app(SpecialistCouncilContractService::class)->modelHash($model->fresh()), $change);
            try {
                $this->runtime($manifest, $definition);
                $this->fail('Original council accepted genuine '.$change.' drift.');
            } catch (\InvalidArgumentException $error) {
                $this->assertSame('Council member native model changed after sealing.', $error->getMessage(), $change);
            }
        }
    }

    private function fixture(): array
    {
        Storage::fake('local');
        Queue::fake();
        Http::preventStrayRequests();
        $definition = app(ExecutionContractService::class)->for('XAUUSD', 'H1');
        $lab = AiLaboratory::create(['name' => 'conditional screening definition regression', 'symbol' => 'XAUUSD',
            'timeframe' => 'H1', 'strategy_families' => ['ema_rsi'], 'is_active' => false]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'test', 'status' => 'screening', 'population_size' => 2, 'trigger_context' => []]);
        $models = [];
        $agents = [];
        foreach (['hour', 'day'] as $role) {
            $models[] = $model = ModelVersion::create(['name' => 'screen-definition-'.$role, 'strategy' => 'ema_rsi_v1',
                'version' => 'v1-'.$role, 'generation' => 1, 'status' => 'testing',
                'parameters' => ['ema_fast' => 4, 'ema_slow' => 10],
                'metadata' => ['base_strategy' => 'ema_rsi', 'execution_contract' => $definition]]);
            $agents[] = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'ema_rsi', 'origin' => 'test',
                'lifecycle_status' => $role === 'hour' ? 'screening' : 'draft', 'parameter_diff' => []]);
        }
        // The production compiler owns a real persisted instrument assignment.
        // Establish that original definition before sealing; first-time
        // assignment creation is not a screening-observation mutation.
        foreach ($agents as $agent) app(LabInstrumentResearchService::class)->assignment($agent);
        // Freeze the actual persisted JSON representation, not a pre-save
        // PHP object whose whole-number floats may be normalized by Eloquent.
        foreach ($models as $model) $model->refresh();
        $definition = $models[0]->metadata['execution_contract'];
        $evidence = \Mockery::mock(LabImmutableEvidenceService::class)->makePartial();
        $evidence->shouldReceive('replayEvidenceCompleteness')->andReturn([
            'complete' => true, 'reason_codes' => [], 'fixture_scope' => 'conditional_storage_only_not_market_proof',
        ]);
        $this->app->instance(LabImmutableEvidenceService::class, $evidence);
        $this->mock(LabTrialLedgerService::class, function ($mock): void {
            $mock->shouldReceive('record')->andReturn(['fixture_scope' => 'conditional_storage_only']);
            $mock->shouldReceive('selectionContext')->andReturn([]);
        });
        $this->mock(CouncilDisagreementService::class, fn ($mock) => $mock->shouldReceive('recordResult')->andReturn([]));
        $this->mock(CandidateGateDecisionService::class, function ($mock): void {
            $mock->shouldReceive('recordScreening')->andReturnUsing(function (LabAgent $agent): CandidateGateDecision {
                // The gate owns a fresh DB object. This also proves the later
                // writer refresh does not erase a concurrently sealed cache.
                $fresh = ModelVersion::findOrFail($agent->model_version_id);
                $fresh->update(['metadata' => [...$fresh->metadata, 'full_validation_batch' => [
                    'protocol' => 'conditional_cache_storage_fixture', 'original_identity_retained' => true]]]);
                return CandidateGateDecision::create(['lab_agent_id' => $agent->id, 'stage' => 'screening',
                    'decision' => 'failed', 'reason_codes' => ['CONDITIONAL_STORAGE_TEST_NOT_MARKET_QUALIFICATION'],
                    'metrics' => ['promotion_evidence' => false], 'evaluated_at' => now()]);
            });
        });
        $manifest = app(SpecialistCouncilContractService::class)->sealManifest([
            'council_id' => 'screen-definition-regression', 'version' => '1',
            'members' => [$this->passport('hour', $models[0]), $this->passport('day', $models[1])], 'components' => [],
            'routing' => ['id' => 'scope-router', 'version' => '1'], 'allocation' => ['id' => 'shared-capital', 'version' => '1'],
            'risk' => ['id' => 'external-hard-risk', 'version' => '1'],
            'execution' => ['id' => 'canonical-execution', 'version' => '1', 'broker_position_mode' => 'hedging',
                'opposite_position_policy' => 'hedge', 'max_open_positions' => 8, 'max_reserved_capital_percent' => 100,
                'max_gross_exposure_percent' => 100, 'max_total_risk_percent' => 2, 'max_drawdown_percent' => 10,
                'max_daily_loss_percent' => 3, 'max_expected_cost_percent' => 1],
            'evaluation_policy' => ['objective' => 'net_return_at_equal_risk',
                'champion_model_version_id' => $models[0]->id, 'solo_model_version_id' => $models[0]->id],
        ]);
        return [$agents[0], $models[0], $definition, $manifest, $this->runtime($manifest, $definition),
            app(SpecialistCouncilContractService::class)->modelHash($models[0]), $evidence];
    }

    private function assertPersistedObservation(LabAgent $agent, ModelVersion $model, LabEvaluationRun $run, array $item,
        array $definition, array $manifest, array $originalRuntime, string $originalHash, LabImmutableEvidenceService $evidence): void
    {
        $fresh = $model->fresh();
        $this->assertNotSame($definition, $item['result']['execution_contract']);
        $this->assertSame($definition, $fresh->metadata['execution_contract']);
        $this->assertSame($item['result']['execution_contract'], $fresh->metadata['execution_observation']);
        $this->assertSame($item['result']['execution_contract'], data_get($fresh->metadata, 'last_screen_result.execution_contract'));
        $this->assertTrue(data_get($fresh->metadata, 'full_validation_batch.original_identity_retained'));
        $this->assertSame($originalHash, app(SpecialistCouncilContractService::class)->modelHash($fresh));
        $this->assertSame($originalRuntime, $this->runtime($manifest, $definition));
        $this->assertSame('screened', $agent->fresh()->lifecycle_status);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame($model->id, $run->model_version_id);
        $this->assertSame('failed', data_get($run->fresh()->metrics, 'screen_decision'));
        $originalIdentity = $evidence->verifiedModelRuntimeIdentity($run->fresh());
        $this->assertNotNull($originalIdentity);
        $this->assertSame($definition, data_get($originalIdentity, 'runtime_basis.components.execution_contract'));
        $responseArtifact = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_response')->sole();
        $response = $evidence->readArtifactPayload($responseArtifact);
        $this->assertSame($item['result']['execution_contract'], $response['execution_contract']);
        $this->assertSame($item['result']['conditional_fixture_marker'], $response['conditional_fixture_marker']);
        $this->assertSame($run->run_id, $response['evidence_run_id']);
        $this->assertDatabaseHas('candidate_handoff_events', ['lab_agent_id' => $agent->id, 'stage' => 'screened']);
        $this->assertDatabaseCount('paper_authority_admissions', 0);
    }

    private function request(ModelVersion $model, array $definition): array
    {
        return ['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy' => $model->strategy,
            'parameters' => $model->parameters, 'execution_contract' => $definition,
            'replay_dataset_hash' => str_repeat('a', 64), 'emit_decision_trace' => true];
    }

    private function replayItem(array $definition): array
    {
        return ['score' => -1, 'result' => ['total_trades' => 0, 'profit_factor' => 0, 'max_drawdown_percent' => 0,
            'conditional_fixture_marker' => 'storage_only_no_market_or_promotion_proof',
            'execution_contract' => [...$definition, 'observed_costs' => ['commission_cash' => 0, 'source' => 'conditional_fixture']]]];
    }

    private function runtime(array $manifest, array $definition): array
    {
        return app(SpecialistCouncilContractService::class)->runtimeContract($manifest, 'M5', str_repeat('a', 64),
            $definition['execution_hash'], [], 'XAUUSD');
    }

    private function passport(string $role, ModelVersion $model): array
    {
        return ['specialist_id' => $role, 'role' => $role, 'version' => '1', 'as_of' => '2025-01-06T02:00:00Z',
            'inputs' => ['as_of_closed_candles'], 'scope' => ['symbols' => ['XAUUSD'], 'contexts' => ['trend']],
            'known_limits' => ['research_unqualified'], 'resources' => ['max_compute_ms' => 100, 'max_memory_mb' => 32, 'max_lookback_bars' => 512],
            'horizon' => ['kind' => $role, 'decision_interval_seconds' => 300, 'reevaluation_interval_seconds' => 300,
                'max_holding_seconds' => 3600, 'execution_precision' => 'candle'], 'data_requirements' => ['sessions', 'costs'],
            'model_version_id' => $model->id, 'strategy_version' => 'strategy-v1', 'tactic_version' => 'tactic-v1',
            'management_version' => 'management-v1', 'capital_weight' => .5, 'risk_per_trade_percent' => .5,
            'sensor_timeframes' => ['H4', 'H1', 'M15', 'M5']];
    }
}
