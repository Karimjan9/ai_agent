<?php

namespace Tests\Feature;

use App\Console\Commands\DispatchLabGeneration;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\PaperMtfShadowObservation;
use App\Models\PaperSignal;
use App\Models\PaperSignalPassport;
use App\Services\LabAgentEvaluationService;
use App\Services\LabGenerationContextService;
use App\Services\MarketData\MarketVolumeService;
use App\Services\MultiTimeframePilotService;
use App\Services\MultiTimeframeSnapshotService;
use App\Services\PaperMtfLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Mockery;
use Tests\TestCase;

class MtfPilotContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_ordinary_replay_envelope_uses_m5_and_all_closed_context_streams(): void
    {
        $bundle = [
            'bundle_hash' => str_repeat('c', 64),
            'entry_dataset_path' => 'C:/sealed/m5.csv',
            'context_dataset_paths' => [
                'H4' => 'C:/sealed/h4.csv',
                'H1' => 'C:/sealed/h1.csv',
                'M15' => 'C:/sealed/m15.csv',
            ],
            'manifest_path' => 'C:/sealed/manifest.json',
            'manifest' => [
                'protocol' => MultiTimeframeSnapshotService::PROTOCOL,
                'bundle_hash' => str_repeat('c', 64),
                'streams' => [
                    'M5' => ['sha256' => str_repeat('5', 64)],
                ],
            ],
        ];
        $method = new \ReflectionMethod(LabAgentEvaluationService::class, 'applyMtfReplayBundle');
        $method->setAccessible(true);
        $request = $method->invoke(app(LabAgentEvaluationService::class), [
            'symbol' => 'XAUUSD',
            'timeframe' => 'M5',
            'dataset_path' => 'C:/legacy/h1.csv',
            'policy_context' => ['snapshot_transport' => []],
        ], $bundle);

        $this->assertSame('C:/sealed/m5.csv', $request['dataset_path']);
        $this->assertSame(['H4', 'H1', 'M15'], array_keys($request['mtf_dataset_paths']));
        $this->assertSame(str_repeat('c', 64), data_get($request, 'mtf_snapshot_manifest.bundle_hash'));
        $this->assertSame('M5', data_get($request, 'policy_context.snapshot_transport.execution_timeframe'));
        $this->assertSame(str_repeat('5', 64), data_get($request, 'policy_context.snapshot_transport.training_dataset_sha256'));
        $this->assertSame(str_repeat('c', 64), data_get($request, 'policy_context.snapshot_transport.mtf_bundle_hash'));
    }

    public function test_ordinary_generation_dispatch_seals_the_bundle_consumed_by_the_m5_replay_request(): void
    {
        $laboratory = AiLaboratory::create([
            'name' => 'Ordinary autonomous MTF lifecycle',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid'],
            'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $laboratory->id,
            'generation' => 1,
            'trigger_type' => 'new_data',
            'population_size' => 20,
            'status' => 'draft',
            'trigger_context' => [],
        ]);
        $bundleHash = str_repeat('c', 64);
        $manifest = [
            'protocol' => MultiTimeframeSnapshotService::PROTOCOL,
            'validation_bundle_protocol' => 'agent_owned_mtf_foundation_bundle_v1',
            'bundle_hash' => $bundleHash,
            'streams' => ['M5' => ['sha256' => str_repeat('5', 64)]],
        ];
        $bundle = [
            'bundle_hash' => $bundleHash,
            'entry_dataset_path' => 'C:/sealed/m5.csv',
            'context_dataset_paths' => [
                'H4' => 'C:/sealed/h4.csv',
                'H1' => 'C:/sealed/h1.csv',
                'M15' => 'C:/sealed/m15.csv',
            ],
            'manifest_path' => 'C:/sealed/manifest.json',
            'manifest' => $manifest,
        ];
        $snapshots = Mockery::mock(MultiTimeframeSnapshotService::class);
        $snapshots->shouldReceive('forAgentOwnedConfirmationValidation')
            ->once()->with('XAUUSD')->andReturn($bundle);
        $snapshots->shouldReceive('restoreAgentOwnedConfirmationValidationBundle')
            ->once()->with($manifest)->andReturn([...$bundle, 'restored_from_sealed_retry' => true]);
        $this->app->instance(MultiTimeframeSnapshotService::class, $snapshots);

        $seal = new \ReflectionMethod(DispatchLabGeneration::class, 'sealAutonomousMtfRuntime');
        $seal->setAccessible(true);
        $sealed = $seal->invoke(
            app(DispatchLabGeneration::class),
            $generation,
            'XAUUSD',
            $snapshots,
            app(LabGenerationContextService::class),
        );

        $this->assertSame($bundleHash, data_get($sealed->trigger_context, 'mtf_bundle_hash'));
        $this->assertSame('sealed', data_get($sealed->trigger_context, 'mtf_runtime_contract.status'));
        $this->assertSame('M5', data_get($sealed->trigger_context, 'mtf_runtime_contract.execution_timeframe'));
        $this->assertSame(['H4', 'H1', 'M15'], data_get($sealed->trigger_context, 'mtf_runtime_contract.context_timeframes'));

        $agent = new LabAgent(['symbol' => 'XAUUSD', 'timeframe' => 'H1']);
        $agent->setRelation('generation', $sealed);
        $agent->setRelation('modelVersion', new ModelVersion([
            'strategy' => 'hybrid', 'parameters' => [], 'metadata' => [],
        ]));
        $evaluation = app(LabAgentEvaluationService::class);
        $runtimeMethod = new \ReflectionMethod(LabAgentEvaluationService::class, 'replayTimeframe');
        $runtimeMethod->setAccessible(true);
        $runtimeTimeframe = $runtimeMethod->invoke($evaluation, $agent);
        $bundleMethod = new \ReflectionMethod(LabAgentEvaluationService::class, 'replayMtfBundle');
        $bundleMethod->setAccessible(true);
        $restored = $bundleMethod->invoke($evaluation, $agent);
        $apply = new \ReflectionMethod(LabAgentEvaluationService::class, 'applyMtfReplayBundle');
        $apply->setAccessible(true);
        $request = $apply->invoke($evaluation, [
            'symbol' => 'XAUUSD',
            'timeframe' => $runtimeTimeframe,
            'mtf_pilot' => app(MultiTimeframePilotService::class)
                ->requestPayload('XAUUSD', $runtimeTimeframe, 'hybrid', $bundleHash),
            'policy_context' => ['snapshot_transport' => []],
        ], $restored);

        $this->assertSame('M5', $request['timeframe']);
        $this->assertTrue(data_get($request, 'mtf_pilot.enabled'));
        $this->assertSame('execution_stream_bound', data_get($request, 'mtf_pilot.activation_status'));
        $this->assertSame('C:/sealed/m5.csv', $request['dataset_path']);
        $this->assertSame(['H4', 'H1', 'M15'], array_keys($request['mtf_dataset_paths']));
        $this->assertSame($bundleHash, data_get($request, 'mtf_snapshot_manifest.bundle_hash'));
    }

    public function test_m5_replay_uses_frozen_bundle_volume_provenance_instead_of_live_coverage(): void
    {
        $volume = Mockery::mock(MarketVolumeService::class);
        $volume->shouldReceive('contract')->once()->andReturn(['normalization' => ['global_lookback' => 168]]);
        $volume->shouldNotReceive('mtfContext');
        $volume->shouldNotReceive('inspect');
        $this->app->instance(MarketVolumeService::class, $volume);

        $bundleHash = str_repeat('c', 64);
        $snapshot = [
            'bundle_hash' => $bundleHash,
            'manifest' => [
                'volume_provenance' => [
                    'protocol' => 'historical_volume_snapshot_provenance_v1',
                    'status' => 'passed',
                    'source_contract' => MarketVolumeService::HISTORICAL_SOURCE_CONTRACT,
                    'streams' => ['M5' => ['status' => 'passed', 'coverage' => 1.0]],
                    'live_coverage_inherited' => false,
                ],
            ],
        ];

        $method = new \ReflectionMethod(LabAgentEvaluationService::class, 'volumeContextOrFail');
        $method->setAccessible(true);
        $context = $method->invoke(app(LabAgentEvaluationService::class), 'XAUUSD', 'M5', $snapshot);

        $this->assertSame('passed', $context['status']);
        $this->assertSame($bundleHash, $context['snapshot_sha256']);
        $this->assertSame('frozen_historical_replay_snapshot', $context['coverage_scope']);
        $this->assertFalse($context['live_coverage_inherited']);
        $this->assertSame('passed', data_get($context, 'snapshot_quality.status'));
    }

    public function test_h1_storage_identity_binds_to_m15_entry_without_becoming_a_second_population(): void
    {
        $service = app(MultiTimeframePilotService::class);
        $contract = $service->requestPayload('XAUUSD', 'M15', 'pullback_entry_v1', 'b'.str_repeat('1', 63));

        $this->assertTrue($contract['enabled']);
        $this->assertSame('H1', $contract['regime_timeframe']);
        $this->assertSame('M15', $contract['entry_timeframe']);
        $this->assertFalse($contract['genetic_parent_transfer']);
        $this->assertSame('xauusd_h1_m15_v1', $contract['pilot_id']);
        $this->assertSame('H1', $contract['laboratory_storage_timeframe']);
        $this->assertSame('M5', $contract['execution_timeframe']);
        $this->assertSame('entry_stream_bound', $contract['activation_status']);
        $this->assertNotSame('', $contract['contract_hash']);
        $storage = app(MultiTimeframePilotService::class)->requestPayload('XAUUSD', 'H1');
        $this->assertFalse($storage['enabled']);
        $this->assertTrue($storage['storage_candidate_eligible']);
        $this->assertSame('storage_identity_requires_entry_stream', $storage['activation_status']);
        $execution = app(MultiTimeframePilotService::class)->requestPayload('XAUUSD', 'M5');
        $this->assertTrue($execution['enabled']);
        $this->assertSame('execution_stream_bound', $execution['activation_status']);
        $this->assertFalse(app(MultiTimeframePilotService::class)->requestPayload('EURUSD', 'M15')['enabled']);

        $model = ModelVersion::create([
            'name' => 'stored-h1-mtf', 'strategy' => 'pullback_entry_v1', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => [],
        ]);
        $candidate = ModelMarketPerformance::create([
            'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'entry', 'status' => 'forward_validated', 'paper_status' => 'pending',
        ]);
        $this->assertTrue($service->isPilotCandidate($candidate));
        $this->assertSame('M15', $service->decisionTimeframe($candidate));
        $this->assertSame('M5', $service->replayTimeframe('XAUUSD', 'H1'));
        $this->assertSame('H1', $service->replayTimeframe('EURUSD', 'H1'));
    }

    public function test_missing_python_mtf_context_is_blocked_at_laravel_paper_boundary(): void
    {
        $model = ModelVersion::create([
            'name' => 'mtf_guard', 'strategy' => 'pullback_entry_v1', 'version' => 'v1', 'generation' => 1,
            'status' => 'testing', 'parameters' => [], 'metadata' => [],
        ]);
        $candidate = ModelMarketPerformance::create([
            'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M15',
            'strategy_family' => 'entry', 'status' => 'forward_validated', 'paper_status' => 'pending',
        ]);

        $guarded = app(MultiTimeframePilotService::class)->enforcePaperResponse($candidate, [
            'signal' => 'BUY', 'confidence' => .8,
        ]);

        $this->assertSame('WAIT', $guarded['signal']);
        $this->assertSame('LARAVEL_MTF_CONTEXT_GUARD', $guarded['mtf_pilot']['reason']);
    }

    public function test_passport_and_shadow_twin_are_idempotent_and_immutable(): void
    {
        $model = ModelVersion::create([
            'name' => 'mtf_ledger', 'strategy' => 'pullback_entry_v1', 'version' => 'v1', 'generation' => 1,
            'status' => 'testing', 'parameters' => [], 'metadata' => [],
        ]);
        $candidate = ModelMarketPerformance::create([
            'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M15',
            'strategy_family' => 'entry', 'status' => 'forward_validated', 'paper_status' => 'pending',
        ]);
        $payload = [
            'signal' => 'BUY', 'signal_time' => '2026-08-11T10:15:00+00:00', 'price' => 3400,
            'confidence' => .72,
            'mtf_pilot' => [
                'protocol' => MultiTimeframePilotService::PROTOCOL, 'pilot_id' => 'xauusd_h1_m15_v1',
                'decision' => 'BUY', 'risk_multiplier' => 1.0,
                'context' => [
                    'status' => 'ready', 'permission' => 'ALLOW', 'h1_direction' => 'BUY',
                    'h1_regime' => 'trend_up', 'h1_closed_at' => '2026-08-11T10:00:00+00:00',
                    'h1_context_hash' => str_repeat('a', 64),
                ],
            ],
            'meta_agent' => ['decision' => 'BUY', 'reason' => 'H1_PERMISSION_GRANTED'],
            'counterfactuals' => [
                'm15_only' => ['decision' => 'BUY'],
                'h1_m15_official' => ['decision' => 'BUY'],
                'm15_without_h1_veto' => ['decision' => 'BUY'],
                'h1_only_context' => ['decision' => 'BUY'],
            ],
            'execution_contract_preview' => ['execution_hash' => str_repeat('b', 64)],
        ];
        $signal = PaperSignal::create([
            'model_market_performance_id' => $candidate->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'M15', 'candle_time' => '2026-08-11 10:15:00',
            'decision' => 'BUY', 'price' => 3400, 'confidence' => 72,
            'payload' => $payload, 'payload_hash' => hash('sha256', json_encode($payload)),
        ]);

        $ledger = app(PaperMtfLedgerService::class);
        $passport = $ledger->recordOfficial($signal, $payload);
        $ledger->recordShadow($candidate, $signal, $payload);
        $ledger->recordShadow($candidate, $signal, $payload);

        $this->assertInstanceOf(PaperSignalPassport::class, $passport);
        $this->assertDatabaseCount('paper_signal_passports', 1);
        $this->assertSame(2, PaperMtfShadowObservation::count());
        $this->assertDatabaseHas('paper_mtf_shadow_observations', ['promotion_evidence' => false, 'scenario_key' => 'm15_only']);

        $this->expectException(LogicException::class);
        $passport->update(['entry_reason' => 'tampered']);
    }
}
