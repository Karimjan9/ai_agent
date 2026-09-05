<?php

namespace Tests\Feature;

use App\Exceptions\ReplayLaneBusyException;
use App\Models\MtfPlaybookFrozenControlRun;
use App\Services\ExecutionContractService;
use App\Services\LabQueueJobInspector;
use App\Services\MtfPlaybookFrozenControlService;
use App\Services\MultiTimeframeSnapshotService;
use App\Services\StrategyParameterSchemaService;
use App\Services\StrategyResearchCatalogueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery as m;
use RuntimeException;
use Tests\TestCase;

class MtfPlaybookFrozenControlServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_runner_identity_uses_an_explicit_semantic_seal_instead_of_php_file_bytes(): void
    {
        $identity = app(MtfPlaybookFrozenControlService::class)->currentIdentity();

        $this->assertSame(MtfPlaybookFrozenControlService::RUNNER_CONTRACT_HASH, $identity['runner_contract_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $identity['runner_contract_hash']);
    }

    public function test_candidate_and_control_share_the_same_immutable_m5_bundle_and_execution_contract(): void
    {
        $manifest = [
            'protocol' => MultiTimeframeSnapshotService::PROTOCOL,
            'bundle_hash' => str_repeat('a', 64),
            'streams' => [
                'M5' => ['path' => 'C:/sealed/m5.csv', 'sha256' => 'm5'],
                'M15' => ['path' => 'C:/sealed/m15.csv', 'sha256' => 'm15'],
                'H1' => ['path' => 'C:/sealed/h1.csv', 'sha256' => 'h1'],
                'H4' => ['path' => 'C:/sealed/h4.csv', 'sha256' => 'h4'],
            ],
        ];
        $snapshots = m::mock(MultiTimeframeSnapshotService::class);
        $snapshots->shouldReceive('forResearchPlaybookReplay')->once()
            ->with('XAUUSD', m::type('array'), null)
            ->andReturn([
                'bundle_hash' => str_repeat('a', 64),
                'entry_dataset_path' => 'C:/sealed/m5.csv',
                'context_dataset_paths' => ['H4' => 'C:/sealed/h4.csv', 'H1' => 'C:/sealed/h1.csv', 'M15' => 'C:/sealed/m15.csv'],
                'related_context_dataset_paths' => [],
                'manifest' => $manifest,
            ]);
        $contract = [
            'protocol' => ExecutionContractService::PROTOCOL,
            'version' => ExecutionContractService::VERSION,
            'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'parameters' => ['spread_points' => 1.0],
            'execution_hash' => str_repeat('b', 64),
        ];
        $execution = m::mock(ExecutionContractService::class);
        $execution->shouldReceive('for')->once()->with('XAUUSD', 'M5')->andReturn($contract);
        $execution->shouldReceive('matches')->twice()->with($contract, 'XAUUSD', 'M5')->andReturnTrue();
        $schemas = app(StrategyParameterSchemaService::class);
        $runner = new MtfPlaybookFrozenControlService(
            app(StrategyResearchCatalogueService::class), $snapshots, $schemas, $execution, $this->idleQueues(),
        );

        Http::fake([
            '*' => Http::response([
                'execution_contract' => $contract,
                'total_trades' => 8, 'profit_factor' => 1.25,
                'net_profit_percent' => 2.4, 'max_drawdown_percent' => 3.5, 'winrate' => 55,
            ]),
        ]);

        $result = $runner->run('XAUUSD', 'po3_amd_session');

        $this->assertSame('completed', $result['status']);
        $this->assertSame('paper_shadow_prior_only', data_get($result, 'comparison.data_role'));
        $this->assertFalse($result['agent_owned_evidence']);
        $this->assertTrue(data_get($result, 'comparison.same_data_hash'));
        $this->assertTrue(data_get($result, 'comparison.same_execution_hash'));
        $this->assertDatabaseHas('mtf_playbook_frozen_control_runs', [
            'research_model_id' => 'po3_amd_session', 'symbol' => 'XAUUSD', 'status' => 'completed',
        ]);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request['timeframe'] === 'M5'
            && $request['dataset_path'] === 'C:/sealed/m5.csv'
            && $request['emit_decision_trace'] === false
            && $request['emit_trade_ledger'] === false
            && is_object($request['related_mtf_dataset_paths'])
            && (array) $request['related_mtf_dataset_paths'] === []
            && $request['mtf_snapshot_manifest'] === $manifest
            && $request['execution_contract'] === $contract);
        $this->assertSame(1, MtfPlaybookFrozenControlRun::query()->count());
    }

    public function test_smt_without_an_explicit_related_market_is_recorded_as_blocked_not_replayed_as_primary_market(): void
    {
        $snapshots = m::mock(MultiTimeframeSnapshotService::class);
        $snapshots->shouldReceive('forResearchPlaybookReplay')->once()
            ->andThrow(new RuntimeException('SMT research uchun related symbol majburiy.'));
        $execution = m::mock(ExecutionContractService::class);
        $catalogue = new class extends StrategyResearchCatalogueService
        {
            public function catalogue(): array
            {
                return ['models' => [$this->model('smt_sweep_mss')]];
            }
        };
        $runner = new MtfPlaybookFrozenControlService(
            $catalogue, $snapshots,
            app(StrategyParameterSchemaService::class), $execution, $this->idleQueues(),
        );

        $rows = $runner->runAll('XAUUSD');
        $smt = collect($rows)->firstWhere('research_model_id', 'smt_sweep_mss');

        $this->assertSame('blocked', $smt['status']);
        $this->assertSame(['RELATED_MARKET_REQUIRED'], $smt['reason_codes']);
        Http::assertNothingSent();
    }

    public function test_liquidity_trap_catalogue_model_uses_its_dedicated_mtf_candidate(): void
    {
        $manifest = [
            'protocol' => MultiTimeframeSnapshotService::PROTOCOL,
            'bundle_hash' => str_repeat('a', 64),
            'streams' => [
                'M5' => ['path' => 'C:/sealed/m5.csv', 'sha256' => 'm5'],
                'M15' => ['path' => 'C:/sealed/m15.csv', 'sha256' => 'm15'],
                'H1' => ['path' => 'C:/sealed/h1.csv', 'sha256' => 'h1'],
                'H4' => ['path' => 'C:/sealed/h4.csv', 'sha256' => 'h4'],
            ],
        ];
        $snapshots = m::mock(MultiTimeframeSnapshotService::class);
        $snapshots->shouldReceive('forResearchPlaybookReplay')->once()->andReturn([
            'bundle_hash' => str_repeat('a', 64),
            'entry_dataset_path' => 'C:/sealed/m5.csv',
            'context_dataset_paths' => ['H4' => 'C:/sealed/h4.csv', 'H1' => 'C:/sealed/h1.csv', 'M15' => 'C:/sealed/m15.csv'],
            'related_context_dataset_paths' => [],
            'manifest' => $manifest,
        ]);
        $contract = [
            'protocol' => ExecutionContractService::PROTOCOL,
            'version' => ExecutionContractService::VERSION,
            'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'parameters' => ['spread_points' => 1.0],
            'execution_hash' => str_repeat('b', 64),
        ];
        $execution = m::mock(ExecutionContractService::class);
        $execution->shouldReceive('for')->once()->with('XAUUSD', 'M5')->andReturn($contract);
        $execution->shouldReceive('matches')->twice()->with($contract, 'XAUUSD', 'M5')->andReturnTrue();
        $runner = new MtfPlaybookFrozenControlService(
            app(StrategyResearchCatalogueService::class), $snapshots,
            app(StrategyParameterSchemaService::class), $execution, $this->idleQueues(),
        );
        Http::fake(['*' => Http::response(['execution_contract' => $contract])]);

        $result = $runner->run('XAUUSD', 'liquidity_trap_mtf');

        $this->assertSame('completed', $result['status']);
        Http::assertSent(fn ($request): bool => $request['strategy'] === 'liquidity_trap_mtf_v1'
            && ! array_key_exists('research_model_id', (array) $request['parameters']));
    }

    public function test_all_seventeen_models_run_as_separate_frozen_control_pairs(): void
    {
        $manifest = [
            'protocol' => MultiTimeframeSnapshotService::PROTOCOL,
            'bundle_hash' => str_repeat('c', 64),
            'streams' => [
                'M5' => ['path' => 'C:/sealed/m5.csv', 'sha256' => 'm5'],
                'M15' => ['path' => 'C:/sealed/m15.csv', 'sha256' => 'm15'],
                'H1' => ['path' => 'C:/sealed/h1.csv', 'sha256' => 'h1'],
                'H4' => ['path' => 'C:/sealed/h4.csv', 'sha256' => 'h4'],
                'D1' => ['path' => 'C:/sealed/d1.csv', 'sha256' => 'd1'],
                'RELATED_M15' => ['path' => 'C:/sealed/related_m15.csv', 'sha256' => 'related'],
            ],
        ];
        $bundle = [
            'bundle_hash' => str_repeat('c', 64),
            'entry_dataset_path' => 'C:/sealed/m5.csv',
            'context_dataset_paths' => [
                'H4' => 'C:/sealed/h4.csv', 'H1' => 'C:/sealed/h1.csv',
                'M15' => 'C:/sealed/m15.csv', 'D1' => 'C:/sealed/d1.csv',
            ],
            'related_context_dataset_paths' => ['M15' => 'C:/sealed/related_m15.csv'],
            'manifest' => $manifest,
        ];
        $snapshots = m::mock(MultiTimeframeSnapshotService::class);
        $snapshots->shouldReceive('forResearchPlaybookReplay')->times(17)->andReturn($bundle);
        $contract = [
            'protocol' => ExecutionContractService::PROTOCOL,
            'version' => ExecutionContractService::VERSION,
            'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'parameters' => ['spread_points' => 1.0],
            'execution_hash' => str_repeat('d', 64),
        ];
        $execution = m::mock(ExecutionContractService::class);
        $execution->shouldReceive('for')->times(17)->with('XAUUSD', 'M5')->andReturn($contract);
        $execution->shouldReceive('matches')->times(34)->with($contract, 'XAUUSD', 'M5')->andReturnTrue();
        $runner = new MtfPlaybookFrozenControlService(
            app(StrategyResearchCatalogueService::class), $snapshots,
            app(StrategyParameterSchemaService::class), $execution, $this->idleQueues(),
        );
        Http::fake(['*' => Http::response(['execution_contract' => $contract])]);

        $rows = $runner->runAll('XAUUSD', 'XAGUSD');

        $this->assertCount(17, $rows);
        $this->assertCount(17, MtfPlaybookFrozenControlRun::query()->get());
        $this->assertTrue(collect($rows)->every(fn (array $row): bool => $row['status'] === 'completed'));
        Http::assertSentCount(34);
        foreach ([
            'ict_2022_raid_mss_fvg', 'po3_amd_session', 'london_judas_swing',
            'turtle_soup_mtf', 'silver_bullet_window', 'smt_sweep_mss',
            'wyckoff_spring_utad', 'elder_triple_screen_liquidity', 'orb_htf_bias',
            'orb_vwap_reclaim', 'adaptive_timeframe_confirmation',
        ] as $modelId) {
            Http::assertSent(fn ($request): bool => $request['strategy'] === 'mtf_research_playbook_v1'
                && data_get($request['parameters'], 'research_model_id') === $modelId);
        }
        foreach ([
            'trend_continuation', 'breakout_retest', 'false_break_reversal',
            'range_sweep', 'htf_reversal',
        ] as $entryModel) {
            Http::assertSent(fn ($request): bool => $request['strategy'] === 'confirmation_entry_mtf_v1'
                && data_get($request['parameters'], 'entry_model') === $entryModel
                && data_get($request['parameters'], 'entry_mode') === 'balanced');
        }
    }

    public function test_technical_error_is_retried_but_completed_run_is_reused(): void
    {
        $manifest = [
            'protocol' => MultiTimeframeSnapshotService::PROTOCOL,
            'bundle_hash' => str_repeat('a', 64),
            'streams' => ['M5' => ['path' => 'C:/sealed/m5.csv', 'sha256' => 'm5']],
        ];
        $bundle = [
            'bundle_hash' => str_repeat('a', 64),
            'entry_dataset_path' => 'C:/sealed/m5.csv',
            'context_dataset_paths' => [], 'related_context_dataset_paths' => [],
            'manifest' => $manifest,
        ];
        $snapshots = m::mock(MultiTimeframeSnapshotService::class);
        $snapshots->shouldReceive('forResearchPlaybookReplay')->times(3)->andReturn($bundle);
        $contract = [
            'protocol' => ExecutionContractService::PROTOCOL,
            'version' => ExecutionContractService::VERSION,
            'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'parameters' => [],
            'execution_hash' => str_repeat('b', 64),
        ];
        $execution = m::mock(ExecutionContractService::class);
        $execution->shouldReceive('for')->times(3)->andReturn($contract);
        $execution->shouldReceive('matches')->twice()->andReturnTrue();
        $runner = new MtfPlaybookFrozenControlService(
            app(StrategyResearchCatalogueService::class), $snapshots,
            app(StrategyParameterSchemaService::class), $execution, $this->idleQueues(),
        );
        Http::fakeSequence()
            ->push(['message' => 'busy'], 503)
            ->push(['execution_contract' => $contract, 'profit_factor' => 1.0])
            ->push(['execution_contract' => $contract, 'profit_factor' => 1.2]);

        $failed = $runner->run('XAUUSD', 'liquidity_trap_mtf');
        $this->assertSame('technical_error', $failed['status']);
        $retried = $runner->run('XAUUSD', 'liquidity_trap_mtf');
        $this->assertSame('completed', $retried['status']);
        $this->assertFalse($retried['reused']);
        $reused = $runner->run('XAUUSD', 'liquidity_trap_mtf');
        $this->assertTrue($reused['reused']);
        $this->assertSame(1, MtfPlaybookFrozenControlRun::query()->count());
        Http::assertSentCount(3);
    }

    public function test_busy_candidate_is_deferred_and_resumes_without_replaying_completed_control(): void
    {
        $manifest = [
            'protocol' => MultiTimeframeSnapshotService::PROTOCOL,
            'bundle_hash' => str_repeat('a', 64),
            'streams' => ['M5' => ['path' => 'C:/sealed/m5.csv', 'sha256' => 'm5']],
        ];
        $bundle = [
            'bundle_hash' => str_repeat('a', 64),
            'entry_dataset_path' => 'C:/sealed/m5.csv',
            'context_dataset_paths' => [], 'related_context_dataset_paths' => [],
            'manifest' => $manifest,
        ];
        $snapshots = m::mock(MultiTimeframeSnapshotService::class);
        $snapshots->shouldReceive('forResearchPlaybookReplay')->twice()->andReturn($bundle);
        $contract = [
            'protocol' => ExecutionContractService::PROTOCOL,
            'version' => ExecutionContractService::VERSION,
            'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'parameters' => [],
            'execution_hash' => str_repeat('b', 64),
        ];
        $execution = m::mock(ExecutionContractService::class);
        $execution->shouldReceive('for')->twice()->andReturn($contract);
        $execution->shouldReceive('matches')->times(3)->andReturnTrue();
        $runner = new MtfPlaybookFrozenControlService(
            app(StrategyResearchCatalogueService::class), $snapshots,
            app(StrategyParameterSchemaService::class), $execution, $this->idleQueues(),
        );
        $control = ['execution_contract' => $contract, 'total_trades' => 8, 'profit_factor' => 1.0];
        $candidate = ['execution_contract' => $contract, 'total_trades' => 9, 'profit_factor' => 1.2];
        Http::fakeSequence()
            ->push($control)
            ->push(['detail' => 'AI replay lane is busy'], 429)
            ->push($candidate);

        try {
            $runner->run('XAUUSD', 'liquidity_trap_mtf');
            $this->fail('Busy replay must release the caller.');
        } catch (ReplayLaneBusyException) {
            $this->assertDatabaseHas('mtf_playbook_frozen_control_runs', [
                'research_model_id' => 'liquidity_trap_mtf',
                'status' => 'retry_deferred',
            ]);
        }

        $retried = $runner->run('XAUUSD', 'liquidity_trap_mtf');

        $this->assertSame('completed', $retried['status']);
        $this->assertEquals($control, MtfPlaybookFrozenControlRun::query()->sole()->control_result);
        $this->assertSame([], MtfPlaybookFrozenControlRun::query()->sole()->reason_codes);
        Http::assertSentCount(3);
    }

    public function test_evolution_that_appears_during_preflight_preempts_the_candidate_leg(): void
    {
        $manifest = [
            'protocol' => MultiTimeframeSnapshotService::PROTOCOL,
            'bundle_hash' => str_repeat('a', 64),
            'streams' => ['M5' => ['path' => 'C:/sealed/m5.csv', 'sha256' => 'm5']],
        ];
        $bundle = [
            'bundle_hash' => str_repeat('a', 64),
            'entry_dataset_path' => 'C:/sealed/m5.csv',
            'context_dataset_paths' => [], 'related_context_dataset_paths' => [],
            'manifest' => $manifest,
        ];
        $snapshots = m::mock(MultiTimeframeSnapshotService::class);
        $snapshots->shouldReceive('forResearchPlaybookReplay')->once()->andReturn($bundle);
        $contract = [
            'protocol' => ExecutionContractService::PROTOCOL,
            'version' => ExecutionContractService::VERSION,
            'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'parameters' => [],
            'execution_hash' => str_repeat('b', 64),
        ];
        $execution = m::mock(ExecutionContractService::class);
        $execution->shouldReceive('for')->once()->andReturn($contract);
        $execution->shouldReceive('matches')->once()->andReturnTrue();
        $queues = m::mock(LabQueueJobInspector::class);
        $queues->shouldReceive('evolutionReplayIsWaiting')->twice()->andReturn(false, true);
        $runner = new MtfPlaybookFrozenControlService(
            app(StrategyResearchCatalogueService::class), $snapshots,
            app(StrategyParameterSchemaService::class), $execution, $queues,
        );
        $control = ['execution_contract' => $contract, 'total_trades' => 8, 'profit_factor' => 1.0];
        Http::fake(['*' => Http::response($control)]);

        try {
            $runner->run('XAUUSD', 'liquidity_trap_mtf');
            $this->fail('Evolution priority must preempt MTF before its candidate request.');
        } catch (ReplayLaneBusyException) {
            $run = MtfPlaybookFrozenControlRun::query()->sole();
            $this->assertSame('retry_deferred', $run->status);
            $this->assertEquals($control, $run->control_result);
            $this->assertSame(['REPLAY_LANE_BUSY_DEFERRED'], $run->reason_codes);
            $this->assertTrue((bool) data_get($run->comparison, 'control_result_preserved'));
        }

        Http::assertSentCount(1);
    }

    public function test_confirmation_funnel_creates_one_audited_single_gene_repair_variant(): void
    {
        $manifest = [
            'protocol' => MultiTimeframeSnapshotService::PROTOCOL,
            'data_role' => 'paper_shadow_prior_only',
            'streams' => ['M5' => ['path' => 'C:/sealed/m5.csv', 'sha256' => 'm5']],
        ];
        $bundle = [
            'bundle_hash' => str_repeat('a', 64),
            'entry_dataset_path' => 'C:/sealed/m5.csv',
            'context_dataset_paths' => [], 'related_context_dataset_paths' => [],
            'manifest' => $manifest,
        ];
        $snapshots = m::mock(MultiTimeframeSnapshotService::class);
        $snapshots->shouldReceive('forResearchPlaybookReplay')->once()->andReturn($bundle);
        $snapshots->shouldReceive('restoreResearchPlaybookBundle')->once()->with($manifest)->andReturn($bundle);
        $contract = [
            'protocol' => ExecutionContractService::PROTOCOL,
            'version' => ExecutionContractService::VERSION,
            'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'parameters' => [],
            'execution_hash' => str_repeat('b', 64),
        ];
        $execution = m::mock(ExecutionContractService::class);
        $execution->shouldReceive('for')->twice()->andReturn($contract);
        $execution->shouldReceive('matches')->times(3)->andReturnTrue();
        $runner = new MtfPlaybookFrozenControlService(
            app(StrategyResearchCatalogueService::class), $snapshots,
            app(StrategyParameterSchemaService::class), $execution, $this->idleQueues(),
        );
        $control = [
            'execution_contract' => $contract, 'total_trades' => 8,
            'profit_factor' => .34, 'net_profit_percent' => -2.28, 'max_drawdown_percent' => 2.28,
        ];
        $defaultCandidate = [
            'execution_contract' => $contract, 'total_trades' => 0,
            'entry_contract_funnel' => ['stage_counts' => ['setup' => 174, 'confirmation' => 1, 'entry_ready' => 0]],
        ];
        $repairCandidate = [
            'execution_contract' => $contract, 'total_trades' => 9,
            'profit_factor' => 1.1, 'net_profit_percent' => .5, 'max_drawdown_percent' => 1.2,
            'entry_contract_funnel' => ['stage_counts' => ['setup' => 174, 'confirmation' => 16, 'entry_ready' => 9]],
        ];
        Http::fakeSequence()->push($control)->push($defaultCandidate)->push($repairCandidate);

        $base = $runner->run('XAUUSD', 'confirmation_trend_continuation');
        $repair = $runner->run(
            'XAUUSD', 'confirmation_trend_continuation', null,
            ['minimum_independent_confirmations' => 2],
            ['source_run_id' => $base['run_id']],
        );

        $this->assertSame('ready', data_get($base, 'comparison.learning_directive.status'));
        $this->assertSame('minimum_independent_confirmations', data_get($base, 'comparison.learning_directive.changed_axis'));
        $this->assertSame('min_independent_confirmations_2', data_get($repair, 'comparison.candidate_variant.id'));
        $this->assertSame($base['run_id'], data_get($repair, 'comparison.candidate_variant.source_run_id'));
        $this->assertSame($base['data_hash'], data_get($repair, 'comparison.candidate_variant.source_data_hash'));
        $this->assertTrue(data_get($repair, 'comparison.candidate_variant.same_source_data_hash'));
        $this->assertSame($base['run_id'], data_get($repair, 'comparison.candidate_variant.control_source_run_id'));
        $this->assertTrue(data_get($repair, 'comparison.candidate_variant.control_result_reused'));
        $this->assertSame(64, strlen((string) data_get($repair, 'comparison.candidate_variant.source_control_result_hash')));
        $this->assertSame(['minimum_independent_confirmations' => 2], data_get($repair, 'comparison.candidate_parameter_diff'));
        $this->assertSame('bounded_repair_terminal', data_get($repair, 'comparison.learning_directive.status'));
        $this->assertFalse((bool) data_get($repair, 'comparison.learning_directive.further_relaxation_allowed'));
        $this->assertFalse($repair['promotion_evidence']);

        $candidateRequests = collect(Http::recorded())
            ->map(fn (array $entry) => $entry[0])
            ->filter(fn ($request): bool => $request['strategy'] === 'confirmation_entry_mtf_v1')
            ->values();
        $this->assertCount(2, $candidateRequests);
        $this->assertSame(3, data_get($candidateRequests[0], 'parameters.minimum_independent_confirmations'));
        $this->assertSame(2, data_get($candidateRequests[1], 'parameters.minimum_independent_confirmations'));
        $defaultParameters = (array) $candidateRequests[0]['parameters'];
        $repairParameters = (array) $candidateRequests[1]['parameters'];
        unset($defaultParameters['minimum_independent_confirmations'], $repairParameters['minimum_independent_confirmations']);
        $this->assertSame($defaultParameters, $repairParameters);
        $this->assertSame(2, MtfPlaybookFrozenControlRun::query()->count());
        Http::assertSentCount(3);
    }

    public function test_promising_underpowered_prior_expands_data_without_reusing_control_or_relaxing_parameters(): void
    {
        $manifest10 = [
            'protocol' => MultiTimeframeSnapshotService::PROTOCOL,
            'data_role' => 'paper_shadow_prior_only',
            'bounded_entry_rows' => 10000,
            'streams' => ['M5' => ['path' => 'C:/sealed/m5-10k.csv', 'sha256' => 'm5-10k']],
        ];
        $manifest20 = [
            'protocol' => MultiTimeframeSnapshotService::PROTOCOL,
            'data_role' => 'paper_shadow_prior_only',
            'evidence_budget_protocol' => 'mtf_evidence_budget_ladder_v1',
            'bounded_entry_rows' => 20000,
            'streams' => ['M5' => ['path' => 'C:/sealed/m5-20k.csv', 'sha256' => 'm5-20k']],
        ];
        $bundle10 = [
            'bundle_hash' => str_repeat('a', 64),
            'entry_dataset_path' => 'C:/sealed/m5-10k.csv',
            'context_dataset_paths' => [], 'related_context_dataset_paths' => [],
            'manifest' => $manifest10,
        ];
        $bundle20 = [
            'bundle_hash' => str_repeat('c', 64),
            'entry_dataset_path' => 'C:/sealed/m5-20k.csv',
            'context_dataset_paths' => [], 'related_context_dataset_paths' => [],
            'manifest' => $manifest20,
        ];
        $snapshots = m::mock(MultiTimeframeSnapshotService::class);
        $snapshots->shouldReceive('forResearchPlaybookReplay')->once()
            ->with('XAUUSD', m::type('array'), null)->andReturn($bundle10);
        $snapshots->shouldReceive('forResearchPlaybookReplay')->once()
            ->with('XAUUSD', m::type('array'), null, 20000)->andReturn($bundle20);
        $contract = [
            'protocol' => ExecutionContractService::PROTOCOL,
            'version' => ExecutionContractService::VERSION,
            'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'parameters' => [],
            'execution_hash' => str_repeat('b', 64),
        ];
        $execution = m::mock(ExecutionContractService::class);
        $execution->shouldReceive('for')->twice()->andReturn($contract);
        $execution->shouldReceive('matches')->times(4)->andReturnTrue();
        $runner = new MtfPlaybookFrozenControlService(
            app(StrategyResearchCatalogueService::class), $snapshots,
            app(StrategyParameterSchemaService::class), $execution, $this->idleQueues(),
        );
        $control10 = [
            'execution_contract' => $contract, 'total_trades' => 10,
            'profit_factor' => .61, 'net_profit_percent' => -1.38, 'max_drawdown_percent' => 2.28,
        ];
        $candidate10 = [
            'execution_contract' => $contract, 'total_trades' => 3,
            'profit_factor' => 4.2, 'net_profit_percent' => 2.5, 'max_drawdown_percent' => .8,
        ];
        $control20 = [
            'execution_contract' => $contract, 'total_trades' => 18,
            'profit_factor' => .7, 'net_profit_percent' => -2.0, 'max_drawdown_percent' => 3.1,
        ];
        $candidate20 = [
            'execution_contract' => $contract, 'total_trades' => 9,
            'profit_factor' => 1.4, 'net_profit_percent' => 2.1, 'max_drawdown_percent' => 1.4,
        ];
        Http::fakeSequence()->push($control10)->push($candidate10)->push($control20)->push($candidate20);

        $base = $runner->run('XAUUSD', 'confirmation_breakout_retest');
        $expanded = $runner->run(
            'XAUUSD', 'confirmation_breakout_retest', null, [],
            ['source_run_id' => $base['run_id'], 'evidence_budget_rows' => 20000],
        );

        $this->assertSame('evidence_budget_20000', data_get($expanded, 'comparison.candidate_variant.id'));
        $this->assertSame('evidence_budget_expansion', data_get($expanded, 'comparison.candidate_variant.variant_class'));
        $this->assertSame(10000, data_get($expanded, 'comparison.candidate_variant.old_value'));
        $this->assertSame(20000, data_get($expanded, 'comparison.candidate_variant.new_value'));
        $this->assertFalse((bool) data_get($expanded, 'comparison.candidate_variant.same_source_data_hash'));
        $this->assertFalse((bool) data_get($expanded, 'comparison.candidate_variant.control_result_reused'));
        $this->assertSame([], data_get($expanded, 'comparison.candidate_parameter_diff'));
        $this->assertSame(20000, data_get($expanded, 'comparison.evidence_budget.entry_rows'));
        $this->assertFalse((bool) data_get($expanded, 'comparison.evidence_budget.gate_relaxed'));
        $this->assertSame('evidence_budget_power_reached', data_get($expanded, 'comparison.learning_directive.status'));
        $this->assertEquals($control20, MtfPlaybookFrozenControlRun::query()->find($expanded['run_id'])->control_result);
        $this->assertSame(2, MtfPlaybookFrozenControlRun::query()->count());
        Http::assertSentCount(4);
        $candidateRequests = collect(Http::recorded())
            ->map(fn (array $entry) => $entry[0])
            ->filter(fn ($request): bool => $request['strategy'] === 'confirmation_entry_mtf_v1')
            ->values();
        $this->assertSame(
            (array) $candidateRequests[0]['parameters'],
            (array) $candidateRequests[1]['parameters'],
        );
    }

    private function idleQueues(): LabQueueJobInspector
    {
        $queues = m::mock(LabQueueJobInspector::class);
        $queues->shouldReceive('evolutionReplayIsWaiting')->andReturnFalse()->byDefault();

        return $queues;
    }
}
