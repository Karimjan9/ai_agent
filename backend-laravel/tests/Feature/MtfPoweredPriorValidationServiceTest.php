<?php

namespace Tests\Feature;

use App\Models\CompositionSettlement;
use App\Models\MtfAgentValidationRun;
use App\Models\MtfPlaybookFrozenControlRun;
use App\Services\CompositionAuthorityKernelService;
use App\Services\ExecutionContractService;
use App\Services\MtfPlaybookFrozenControlService;
use App\Services\MtfPoweredPriorValidationService;
use App\Services\MultiTimeframeSnapshotService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery as m;
use ReflectionMethod;
use Tests\TestCase;

class MtfPoweredPriorValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_powered_prior_becomes_model_owned_nine_fold_settlement_without_promotion(): void
    {
        $identity = ['python_runtime_hash' => str_repeat('a', 64), 'runner_contract_hash' => str_repeat('b', 64)];
        $runner = m::mock(MtfPlaybookFrozenControlService::class);
        $runner->shouldReceive('currentIdentity')->andReturn($identity);
        $schemas = app(StrategyParameterSchemaService::class);
        $candidateParameters = $schemas->validate('confirmation_entry_mtf_v1', [
            ...$schemas->defaults('confirmation_entry_mtf_v1'), 'entry_model' => 'breakout_retest',
        ]);
        $controlParameters = $schemas->validate('mtf_research_control_v1', $schemas->defaults('mtf_research_control_v1'));
        $candidateHash = $this->parameterHash($schemas, 'confirmation_entry_mtf_v1', $candidateParameters);
        $controlHash = $this->parameterHash($schemas, 'mtf_research_control_v1', $controlParameters);
        $source = MtfPlaybookFrozenControlRun::create([
            'run_key' => hash('sha256', 'powered-prior'),
            'protocol' => MtfPlaybookFrozenControlService::PROTOCOL,
            'research_model_id' => 'confirmation_breakout_retest',
            'symbol' => 'XAUUSD', 'entry_timeframe' => 'M5',
            'data_hash' => str_repeat('c', 64), 'execution_hash' => str_repeat('d', 64),
            'control_parameter_hash' => $controlHash, 'candidate_parameter_hash' => $candidateHash,
            'status' => 'completed', 'required_streams' => ['H4', 'H1', 'M15', 'M5'],
            'dataset_manifest' => ['data_role' => 'paper_shadow_prior_only', 'closed_cutoff' => '2026-08-29T00:00:00Z'],
            'control_result' => ['total_trades' => 26], 'candidate_result' => ['total_trades' => 9],
            'comparison' => [
                ...$identity,
                'agent_owned_evidence' => false,
                'power' => ['status' => 'powered'],
                'interpretation' => 'candidate_improved_on_this_frozen_replay',
                'candidate_parameter_diff' => [],
            ],
            'reason_codes' => [], 'promotion_evidence' => false, 'completed_at' => now(),
        ]);
        $manifest = [
            'protocol' => MultiTimeframeSnapshotService::PROTOCOL,
            'bundle_hash' => str_repeat('e', 64),
            'data_role' => 'foundation_training_only',
            'streams' => ['M5' => ['path' => 'C:/sealed/training-m5.csv']],
        ];
        $bundle = [
            'bundle_hash' => str_repeat('e', 64),
            'entry_dataset_path' => 'C:/sealed/training-m5.csv',
            'context_dataset_paths' => [
                'H4' => 'C:/sealed/training-h4.csv', 'H1' => 'C:/sealed/training-h1.csv',
                'M15' => 'C:/sealed/training-m15.csv',
            ],
            'related_context_dataset_paths' => [], 'manifest' => $manifest,
        ];
        $snapshots = m::mock(MultiTimeframeSnapshotService::class);
        $snapshots->shouldReceive('agentValidationReadiness')->twice()
            ->with('XAUUSD', MultiTimeframeSnapshotService::AGENT_VALIDATION_MAX_M5_ROWS)->andReturn([
                'ready' => true,
                'reason' => 'SEALED_FOUNDATION_READY',
            ]);
        $snapshots->shouldReceive('forAgentOwnedConfirmationValidation')->once()
            ->with('XAUUSD', MultiTimeframeSnapshotService::AGENT_VALIDATION_MAX_M5_ROWS)->andReturn($bundle);
        $contract = [
            'protocol' => ExecutionContractService::PROTOCOL,
            'version' => ExecutionContractService::VERSION,
            'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'parameters' => [],
            'execution_hash' => str_repeat('f', 64),
        ];
        $execution = m::mock(ExecutionContractService::class);
        $execution->shouldReceive('for')->once()->with('XAUUSD', 'M5')->andReturn($contract);
        $execution->shouldReceive('matches')->times(4)->andReturnTrue();
        $passport = [
            'protocol' => CompositionAuthorityKernelService::PROTOCOL,
            'composition_id' => 'xau-comp-test',
            'components' => [
                'strategy_id' => 'str_031_bos_retest', 'tactic_id' => 'breakout_retest',
                'risk_id' => 'atr_risk_envelope', 'management_id' => 'breakout_measured_move',
            ],
        ];
        $kernel = m::mock(CompositionAuthorityKernelService::class);
        $kernel->shouldReceive('freeze')->once()->andReturn($passport);
        $candidateName = 'xauusd_confirmation_breakout_retest_owner_'.$source->id;
        $controlName = $candidateName.'_frozen_control';
        Http::fake(['*' => Http::response(['leaderboard' => [
            ['strategy' => $candidateName, 'result' => $this->foldResult($contract, true)],
            ['strategy' => $controlName, 'result' => $this->foldResult($contract, false)],
        ]])]);
        $service = new MtfPoweredPriorValidationService($runner, $snapshots, $schemas, $execution, $kernel);

        $this->assertSame($source->id, $service->nextEligible()?->id);
        $result = $service->run($source->id);

        $this->assertSame('completed', $result['status']);
        $this->assertSame('local_e2_candidate_supported', $result['verdict']);
        $this->assertSame(9, data_get($result, 'paired_summary.observed_windows'));
        $this->assertSame(9, data_get($result, 'paired_summary.powered_paired_windows'));
        $this->assertSame(9, data_get($result, 'paired_summary.positive_paired_windows'));
        $this->assertFalse($result['runtime_trade_authority']);
        $this->assertFalse($result['parent_authority']);
        $this->assertFalse($result['promotion_evidence']);
        $run = MtfAgentValidationRun::query()->sole();
        $this->assertSame($source->id, $run->source_run_id);
        $this->assertSame(1, $run->attempts);
        $this->assertDatabaseHas('composition_settlements', [
            'model_version_id' => $run->model_version_id,
            'status' => 'local_e2_candidate_supported',
        ]);
        $settlement = CompositionSettlement::query()->sole();
        $this->assertSame('E2_candidate', data_get($settlement->evidence, 'evidence_tier'));
        $this->assertSame(data_get($run->validation_contract, 'composition_id'), $settlement->composition_id);
        $this->assertSame(
            data_get($run->validation_contract, 'composition_id'),
            data_get($run->validation_contract, 'composition_passport.composition_id'),
        );
        $this->assertFalse((bool) data_get($settlement->evidence, 'promotion_evidence'));
        $this->assertSame('research_validated', $run->modelVersion->status);
        $this->assertFalse((bool) data_get($run->modelVersion->metadata, 'mtf_agent_validation.parent_authority'));
        $this->assertNull($service->nextEligible());
        $this->assertTrue($service->run($source->id)['reused']);
        $this->assertTrue($service->reprojectCompleted($run->id)['reprojected']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => count((array) $request['strategies']) === 2
            && data_get($request['policy_context'], "learning_confirmation_contracts.{$candidateName}.fold_count") === 9
            && data_get($request['policy_context'], 'data_boundary.source_discovery_paper_reuse_for_final_paper') === false
            && data_get($run->validation_contract, 'post_selection_historical_confirmation') === true
            && data_get($result, 'paired_summary.evidence_scope.hypothesis_selection_out_of_sample') === false);
    }

    public function test_underpowered_history_expands_once_then_stops_at_the_sealed_maximum(): void
    {
        $source = MtfPlaybookFrozenControlRun::create([
            'run_key' => hash('sha256', 'history-ladder-source'),
            'protocol' => MtfPlaybookFrozenControlService::PROTOCOL,
            'research_model_id' => 'confirmation_breakout_retest',
            'symbol' => 'XAUUSD', 'entry_timeframe' => 'M5',
            'data_hash' => str_repeat('1', 64), 'execution_hash' => str_repeat('2', 64),
            'control_parameter_hash' => str_repeat('3', 64), 'candidate_parameter_hash' => str_repeat('4', 64),
            'status' => 'completed', 'required_streams' => ['H4', 'H1', 'M15', 'M5'],
            'dataset_manifest' => [], 'control_result' => [], 'candidate_result' => [],
            'comparison' => [], 'reason_codes' => [], 'promotion_evidence' => false, 'completed_at' => now(),
        ]);
        $snapshots = m::mock(MultiTimeframeSnapshotService::class);
        $snapshots->shouldReceive('agentValidationReadiness')->once()
            ->with('XAUUSD', 350000)->andReturn([
                'ready' => true,
                'bounded_m5_rows' => 329705,
            ]);
        $service = new MtfPoweredPriorValidationService(
            m::mock(MtfPlaybookFrozenControlService::class),
            $snapshots,
            app(StrategyParameterSchemaService::class),
            m::mock(ExecutionContractService::class),
            m::mock(CompositionAuthorityKernelService::class),
        );
        $method = new ReflectionMethod(MtfPoweredPriorValidationService::class, 'nextEvidenceBudget');
        $method->setAccessible(true);
        $base = [
            'protocol' => MtfPoweredPriorValidationService::PROTOCOL,
            'source_run_id' => $source->id,
            'model_version_id' => null,
            'symbol' => 'XAUUSD', 'entry_timeframe' => 'M5', 'status' => 'completed', 'attempts' => 1,
            'data_hash' => str_repeat('5', 64), 'execution_hash' => str_repeat('6', 64),
            'candidate_parameter_hash' => str_repeat('7', 64), 'control_parameter_hash' => str_repeat('8', 64),
            'dataset_manifest' => [], 'candidate_result' => [], 'control_result' => [],
            'causal_accounting' => [], 'reason_codes' => [], 'promotion_evidence' => false,
            'started_at' => now(), 'completed_at' => now(),
        ];
        MtfAgentValidationRun::create([
            ...$base,
            'run_key' => hash('sha256', 'history-ladder-200k'),
            // Legacy completed rows predate explicit ladder fields. Their
            // immutable manifest remains the safe compatibility fallback.
            'dataset_manifest' => ['streams' => ['M5' => ['row_count' => 200000]]],
            'validation_contract' => [],
            'paired_summary' => ['verdict' => 'underpowered'],
        ]);

        $this->assertSame(350000, $method->invoke($service, $source));

        MtfAgentValidationRun::create([
            ...$base,
            'run_key' => hash('sha256', 'history-ladder-350k'),
            'validation_contract' => ['historical_evidence_budget_rows' => 350000, 'historical_entry_rows' => 329705],
            'paired_summary' => ['verdict' => 'underpowered', 'historical_evidence_budget_rows' => 350000],
        ]);

        $this->assertNull($method->invoke($service, $source));
    }

    public function test_retry_selects_the_same_sealed_budget_manifest_instead_of_fresh_archive_bytes(): void
    {
        $source = MtfPlaybookFrozenControlRun::create([
            'run_key' => hash('sha256', 'resume-source'),
            'protocol' => MtfPlaybookFrozenControlService::PROTOCOL,
            'research_model_id' => 'confirmation_breakout_retest',
            'symbol' => 'XAUUSD', 'entry_timeframe' => 'M5',
            'data_hash' => str_repeat('1', 64), 'execution_hash' => str_repeat('2', 64),
            'control_parameter_hash' => str_repeat('3', 64), 'candidate_parameter_hash' => str_repeat('4', 64),
            'status' => 'completed', 'required_streams' => ['H4', 'H1', 'M15', 'M5'],
            'dataset_manifest' => [], 'control_result' => [], 'candidate_result' => [],
            'comparison' => [], 'reason_codes' => [], 'promotion_evidence' => false, 'completed_at' => now(),
        ]);
        $run = MtfAgentValidationRun::create([
            'run_key' => hash('sha256', 'resume-validation'),
            'protocol' => MtfPoweredPriorValidationService::PROTOCOL,
            'source_run_id' => $source->id, 'model_version_id' => null,
            'symbol' => 'XAUUSD', 'entry_timeframe' => 'M5', 'status' => 'technical_error', 'attempts' => 1,
            'data_hash' => str_repeat('5', 64), 'execution_hash' => str_repeat('6', 64),
            'candidate_parameter_hash' => str_repeat('7', 64), 'control_parameter_hash' => str_repeat('8', 64),
            'dataset_manifest' => [
                'bundle_hash' => str_repeat('5', 64),
                'bounded_cost_contract' => ['requested_m5_rows' => 350000],
            ],
            'validation_contract' => ['historical_evidence_budget_rows' => 350000],
            'candidate_result' => null, 'control_result' => null, 'paired_summary' => null,
            'causal_accounting' => null, 'reason_codes' => ['MTF_AGENT_VALIDATION_TECHNICAL_ERROR'],
            'last_error' => 'bounded timeout', 'promotion_evidence' => false,
            'started_at' => now(), 'completed_at' => now(),
        ]);
        $service = new MtfPoweredPriorValidationService(
            m::mock(MtfPlaybookFrozenControlService::class),
            m::mock(MultiTimeframeSnapshotService::class),
            app(StrategyParameterSchemaService::class),
            m::mock(ExecutionContractService::class),
            m::mock(CompositionAuthorityKernelService::class),
        );
        $method = new ReflectionMethod(MtfPoweredPriorValidationService::class, 'resumableRun');
        $method->setAccessible(true);

        $this->assertSame($run->id, $method->invoke($service, $source->id, 350000)?->id);
        $this->assertNull($method->invoke($service, $source->id, 200000));
    }

    public function test_max_breadth_activity_collapse_becomes_a_bounded_portability_directive(): void
    {
        $source = MtfPlaybookFrozenControlRun::create([
            'run_key' => hash('sha256', 'period-conditioned-prior'),
            'protocol' => MtfPlaybookFrozenControlService::PROTOCOL,
            'research_model_id' => 'confirmation_breakout_retest',
            'symbol' => 'XAUUSD', 'entry_timeframe' => 'M5',
            'data_hash' => str_repeat('1', 64), 'execution_hash' => str_repeat('2', 64),
            'control_parameter_hash' => str_repeat('3', 64), 'candidate_parameter_hash' => str_repeat('4', 64),
            'status' => 'completed', 'required_streams' => ['H4', 'H1', 'M15', 'M5'],
            'dataset_manifest' => ['closed_cutoff' => '2026-08-29T00:00:00Z'],
            'control_result' => ['total_trades' => 20],
            'candidate_result' => ['total_trades' => 9],
            'comparison' => ['candidate' => ['total_trades' => 9]],
            'reason_codes' => [], 'promotion_evidence' => false, 'completed_at' => now(),
        ]);
        $service = new MtfPoweredPriorValidationService(
            m::mock(MtfPlaybookFrozenControlService::class),
            m::mock(MultiTimeframeSnapshotService::class),
            app(StrategyParameterSchemaService::class),
            m::mock(ExecutionContractService::class),
            m::mock(CompositionAuthorityKernelService::class),
        );
        $candidate = $this->foldResult([], false);
        $control = $this->foldResult([], false);
        $candidate['total_trades'] = 0;
        foreach ($candidate['walk_forward']['forward_window_protocol']['windows'] as &$window) {
            $window['trades'] = 0;
        }
        unset($window);
        $method = new ReflectionMethod(MtfPoweredPriorValidationService::class, 'pairedSummary');
        $method->setAccessible(true);

        $summary = $method->invoke($service, $source, $candidate, $control, 350000);

        $this->assertSame('underpowered', $summary['verdict']);
        $this->assertSame(
            'period_regime_or_data_domain_conditioned_prior',
            data_get($summary, 'portability_diagnosis.classification'),
        );
        $this->assertSame(0.0, data_get($summary, 'portability_diagnosis.activity_transfer_ratio'));
        $this->assertSame(
            'market_state_admission_policy',
            data_get($summary, 'portability_diagnosis.evolution_directive.changed_axis'),
        );
        $this->assertTrue((bool) data_get($summary, 'portability_diagnosis.evolution_directive.fresh_paper_after_discovery_cutoff_required'));
        $this->assertContains('PRIOR_PORTABILITY_FAILURE_REQUIRES_CAUSAL_ROUTER_TRIAL', $summary['reason_codes']);
        $this->assertFalse((bool) data_get($summary, 'portability_diagnosis.causal_claim_authority'));
        $this->assertFalse((bool) data_get($summary, 'portability_diagnosis.evolution_directive.parent_authority'));
    }

    /** @return array<string,mixed> */
    private function foldResult(array $contract, bool $candidate): array
    {
        $windows = [];
        for ($index = 1; $index <= 9; $index++) {
            $windows[] = [
                'id' => "2025-01-{$index}__2025-01-{$index}",
                'start' => "2025-01-{$index}", 'end' => "2025-01-{$index}",
                'score' => $candidate ? 70 : 30,
                'profit_factor' => $candidate ? 2.0 : 1.0,
                'net_profit_percent' => $candidate ? 1.0 : 0.0,
                'trades' => $candidate ? 2 : 3,
            ];
        }

        return [
            'execution_contract' => $contract,
            'total_trades' => $candidate ? 18 : 27,
            'profit_factor' => $candidate ? 2.0 : 1.0,
            'net_profit_percent' => $candidate ? 9.0 : 0.0,
            'max_drawdown_percent' => $candidate ? 2.0 : 3.0,
            'winrate' => $candidate ? 60.0 : 45.0,
            'learning_confirmation' => ['status' => 'completed'],
            'walk_forward' => ['forward_window_protocol' => [
                'protocol' => 'disjoint_forward_folds_v1',
                'observed_windows' => 9, 'windows' => $windows,
                'powered_windows' => 9, 'minimum_powered_windows' => 6,
                'power_quorum_passed' => true,
                'independence_verified' => true, 'overlap_detected' => false,
                'purge_embargo_applied' => true,
                'promotion_evidence' => false,
            ]],
        ];
    }

    private function parameterHash(StrategyParameterSchemaService $schemas, string $strategy, array $parameters): string
    {
        $canonical = $schemas->canonicalizeForIdentity($strategy, $parameters);
        $sort = function (array &$value) use (&$sort): void {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $sort($item);
                }
            }
        };
        $sort($canonical);

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }
}
