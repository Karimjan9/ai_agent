<?php

namespace Tests\Unit;

use App\Models\LabEvaluationRun;
use App\Services\BacktestExecutionService;
use App\Services\ExecutionContractService;
use App\Services\LabDatasetExportService;
use App\Services\LabImmutableEvidenceService;
use App\Services\MultiTimeframeSnapshotService;
use Illuminate\Support\Facades\Http;
use Mockery as m;
use Tests\TestCase;

class LiquidityTrapBacktestExecutionTest extends TestCase
{
    public function test_shadow_liquidity_trap_replay_uses_one_m5_execution_bundle_with_h4_h1_m15_context(): void
    {
        $manifest = [
            'protocol' => MultiTimeframeSnapshotService::PROTOCOL,
            'bundle_hash' => 'sealed-mtf-bundle',
            'streams' => [
                'M5' => ['path' => 'C:/sealed/m5.csv', 'sha256' => 'm5'],
                'M15' => ['path' => 'C:/sealed/m15.csv', 'sha256' => 'm15'],
                'H1' => ['path' => 'C:/sealed/h1.csv', 'sha256' => 'h1'],
                'H4' => ['path' => 'C:/sealed/h4.csv', 'sha256' => 'h4'],
            ],
        ];
        $snapshots = m::mock(MultiTimeframeSnapshotService::class);
        $snapshots->shouldReceive('forLiquidityTrapReplay')->once()->with('XAUUSD')->andReturn([
            'entry_dataset_path' => 'C:/sealed/m5.csv',
            'context_dataset_paths' => [
                'M15' => 'C:/sealed/m15.csv',
                'H1' => 'C:/sealed/h1.csv',
                'H4' => 'C:/sealed/h4.csv',
            ],
            'manifest' => $manifest,
        ]);
        $datasets = m::mock(LabDatasetExportService::class);
        $evidence = m::mock(LabImmutableEvidenceService::class);
        $evidence->shouldReceive('codeHash')->once()->andReturn('code-hash');
        $evidence->shouldReceive('attachRequest')->once()->withArgs(
            function (LabEvaluationRun $run, array $payload, array $metadata) use ($manifest): bool {
                return $payload['timeframe'] === 'M5'
                    && $payload['dataset_path'] === 'C:/sealed/m5.csv'
                    && $payload['mtf_dataset_paths']['H4'] === 'C:/sealed/h4.csv'
                    && $metadata['data_hash'] === 'sealed-mtf-bundle'
                    && $metadata['dataset_manifest'] === $manifest;
            },
        );
        $evidence->shouldReceive('finishRun')->once();

        $contract = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'parameters' => ['spread_pips' => 1.0]];
        $contracts = m::mock(ExecutionContractService::class);
        $contracts->shouldReceive('for')->once()->with('XAUUSD', 'M5')->andReturn($contract);
        $contracts->shouldReceive('matches')->once()->with($contract, 'XAUUSD', 'M5')->andReturnTrue();
        $this->app->instance(ExecutionContractService::class, $contracts);

        Http::fake([
            '*' => Http::response([
                'strategy' => 'liquidity_trap_mtf_v1',
                'timeframe' => 'M5',
                'execution_contract' => $contract,
                'metrics' => ['total_trades' => 1],
            ]),
        ]);
        $run = m::mock(LabEvaluationRun::class)->makePartial();
        $run->request_hash = 'request-hash';
        $run->run_id = 'run-1';
        $run->shouldReceive('update')->once()->with(m::on(
            fn (array $values): bool => $values['data_hash'] === 'sealed-mtf-bundle' && $values['code_hash'] === 'code-hash',
        ));

        $result = (new BacktestExecutionService($datasets, $evidence, $snapshots))->execute($run, [
            'symbol' => 'XAU/USD',
            'timeframe' => 'H1',
            'strategy' => 'liquidity_trap_mtf_v1',
        ]);

        $this->assertSame('liquidity_trap_mtf_v1', $result['strategy']);
        Http::assertSent(fn ($request): bool => $request['timeframe'] === 'M5'
            && $request['dataset_path'] === 'C:/sealed/m5.csv'
            && $request['mtf_dataset_paths']['M15'] === 'C:/sealed/m15.csv'
            && $request['mtf_snapshot_manifest'] === $manifest);
    }
}
