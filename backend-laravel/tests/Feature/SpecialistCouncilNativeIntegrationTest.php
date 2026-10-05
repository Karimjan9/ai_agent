<?php

namespace Tests\Feature;

use App\Models\ModelVersion;
use App\Services\ExecutionContractService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\SpecialistCouncilLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real persisted Laravel contracts cross the JSON boundary into the native replay. */
class SpecialistCouncilNativeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_persisted_native_members_execute_on_one_attested_account_after_numeric_transport(): void
    {
        [$carrier, $request, $result] = $this->replay();
        $contract = $request['specialist_council_contract'];
        $receipt = app(SpecialistCouncilLifecycleService::class)->attestReplayResult($carrier, $request, $result);

        $this->assertSame('computed', $receipt['status']);
        $this->assertSame($contract['contract_hash'], $receipt['contract_hash']);
        $this->assertSame($request['replay_dataset_hash'], $receipt['dataset_hash']);
        $this->assertSame('verified', $receipt['source_attestation']['status']);
        $this->assertSame('previous_closed_candle_next_open', $receipt['asof_policy']);
        $this->assertCount(4, $receipt['members']);
        $this->assertNotEmpty($receipt['position_ledger']);
        $this->assertFalse($receipt['scientific_evidence']);
        $this->assertFalse($receipt['promotion_evidence']);

        // The ordinary HTTP JSON encoder drops 10.0 -> 10, while contract_json
        // preserves the sealed numeric copy. This request intentionally uses it.
        $this->assertIsInt($contract['policy']['max_drawdown_percent']);
        $preserved = json_decode($contract['contract_json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsFloat($preserved['policy']['max_drawdown_percent']);
        $this->assertSame(10.0, $preserved['policy']['max_drawdown_percent']);

        $members = collect($receipt['members'])->keyBy('specialist_id');
        foreach ($preserved['members'] as $member) {
            $observed = $members[$member['specialist_id']];
            $this->assertSame($member['model_version_id'], $observed['model_version_id']);
            $this->assertSame(['symbols' => ['XAUUSD'], 'contexts' => ['any']], $observed['scope']);
            $this->assertSame('XAUUSD', $observed['symbol']);
            $this->assertGreaterThan(0, $observed['stages']['decision:observed']);
            $this->assertGreaterThan(0, $observed['stages']['execution:filled'] ?? 0);
            $this->assertSame(app(ResearchPaperEpochContractService::class)->parameterHash([
                'council_version' => $preserved['council_version'], 'member' => $member,
            ]), $observed['member_version_hash']);
        }

        $this->assertGreaterThanOrEqual(4, max(array_column($receipt['account_ledger'], 'open_positions')));
        $ledgerNet = array_sum(array_column($receipt['position_ledger'], 'net_pnl'));
        $this->assertEqualsWithDelta($ledgerNet,
            $receipt['account']['final_balance'] - $request['initial_balance'], 0.000001);
        $this->assertEqualsWithDelta(array_sum(array_column($receipt['position_ledger'], 'fees')),
            $receipt['account']['fees'], 0.000001);
        $this->assertGreaterThan(0, $receipt['account']['fees']);
        $this->assertGreaterThan(0, $receipt['account']['carry']);
        $censoredSwing = array_filter($receipt['position_ledger'], fn (array $position): bool =>
            $position['role'] === 'swing' && $position['outcome_matured'] === false);
        $this->assertNotEmpty($censoredSwing);
        foreach ($censoredSwing as $position) {
            $this->assertSame('end_of_data', $position['exit_reason']);
            $this->assertGreaterThan(0, $position['carry']);
        }
        $this->assertGreaterThan(0, $receipt['metrics']['censored_trades']);
        $this->assertEqualsWithDelta(0, $receipt['account']['reconciliation_error'], 0.000001);
        foreach ($receipt['account_ledger'] as $point) {
            $this->assertEqualsWithDelta($point['equity'] - $point['reserved_capital'],
                $point['free_capital'], 0.000001);
            $this->assertLessThanOrEqual($point['equity'] + 0.000001, $point['reserved_capital']);
        }
        foreach ($receipt['position_ledger'] as $position) {
            $owner = $members[$position['specialist_id']];
            $this->assertSame($owner['member_version_hash'], $position['member_version_hash']);
            $this->assertSame($owner['specialist_id'], $position['management_owner']);
            $this->assertSame($owner['management_version'], $position['management_version']);
            $this->assertEqualsWithDelta($position['gross_pnl_after_embedded_cost'] - $position['fees'] - $position['carry'],
                $position['net_pnl'], 0.000001);
        }
    }

    public function test_declared_model_refuses_an_actual_result_with_both_receipts_removed(): void
    {
        [$carrier, $request, $result] = $this->replay();
        unset($result['specialist_council_receipt'], $result['data_quality']['specialist_council_receipt']);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('SPECIALIST_COUNCIL_RECEIPT_OR_BINDING_MISSING');
        app(SpecialistCouncilLifecycleService::class)->attestReplayResult($carrier, $request, $result);
    }

    public function test_even_resealed_native_receipt_cannot_transfer_a_position_management_owner(): void
    {
        [$carrier, $request, $result] = $this->replay();
        $receipt = $result['specialist_council_receipt'];
        $this->assertNotEmpty($receipt['position_ledger']);
        $receipt['position_ledger'][0]['management_owner'] = 'unsealed-owner';
        unset($receipt['receipt_hash'], $receipt['receipt_json']);
        $receipt['receipt_hash'] = app(ResearchPaperEpochContractService::class)->parameterHash($receipt);
        $receipt['receipt_json'] = json_encode($this->canonicalize(array_diff_key($receipt, ['receipt_hash' => true])),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $result['specialist_council_receipt'] = $receipt;
        $result['data_quality']['specialist_council_receipt'] = $receipt;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('COUNCIL_PINNED_POSITION_OWNERSHIP_MISMATCH');
        app(SpecialistCouncilLifecycleService::class)->attestReplayResult($carrier, $request, $result);
    }

    public function test_original_sealed_members_cannot_be_dispatched_on_a_different_carrier_symbol(): void
    {
        [, , , $request] = $this->replay();
        $request['symbol'] = 'EURUSD';
        $process = $this->python($request);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('SPECIALIST_COUNCIL_MEMBER_SYMBOL_IDENTITY_MISMATCH', $process->getErrorOutput());
    }

    public function test_tick_precision_is_reported_as_a_native_dependency_without_fake_fills_or_evidence(): void
    {
        [$carrier, $request, $result] = $this->replay(true);
        $receipt = app(SpecialistCouncilLifecycleService::class)->attestReplayResult($carrier, $request, $result);
        $this->assertSame('dependency', $receipt['status']);
        $this->assertContains('TICK_EXECUTION_REQUIRES_ORDER_FILL_AND_LATENCY', $receipt['dependency_reasons']);
        $this->assertSame([], $receipt['position_ledger']);
        $this->assertSame(0, $result['total_trades']);
        $this->assertEqualsWithDelta($request['initial_balance'], $receipt['account']['final_balance'], 0.000001);
        foreach ($receipt['members'] as $member) $this->assertSame(0, $member['stages']['decision:observed']);
        $this->assertFalse($receipt['scientific_evidence']);
        $this->assertFalse($receipt['promotion_evidence']);
        $this->assertFalse($result['risk_governor_compliant']);
    }

    /** No providers, database connections or mocked strategy/receipt inside Python. */
    private function replay(bool $tickPrecision = false): array
    {
        // Composer's inferred APP_BASE_PATH may otherwise point at the original
        // checkout when vendor is shared. Refuse that before building contracts.
        $this->assertSame(realpath(dirname(__DIR__, 2)), realpath(base_path()));
        $this->assertSame('testing', config('app.env'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        config(['services.execution_contract.allowed_sessions_utc' => [],
            'services.execution_contract.stop_loss_percent' => 1.0,
            'services.execution_contract.take_profit_percent' => 2.0]);

        $members = [];
        foreach (['scalp' => 3600, 'hour' => 10800, 'day' => 43200, 'swing' => 259200] as $role => $holding) {
            $model = $this->model($role);
            $members[] = ['specialist_id' => $role, 'role' => $role, 'version' => '1',
                'as_of' => '2024-12-31T00:00:00Z', 'inputs' => ['as_of_closed_candles'],
                'scope' => ['symbols' => ['XAUUSD'], 'contexts' => ['any']],
                'known_limits' => ['synthetic_research_unqualified'],
                'resources' => ['max_compute_ms' => 100.0, 'max_memory_mb' => 32, 'max_lookback_bars' => 512],
                'horizon' => ['kind' => $role, 'decision_interval_seconds' => 3600,
                    'reevaluation_interval_seconds' => 3600, 'max_holding_seconds' => $holding,
                    'execution_precision' => $tickPrecision && $role === 'hour' ? 'tick' : 'candle'],
                'data_requirements' => match ($role) {
                    'scalp' => ['bid_ask', 'spread', 'slippage', 'quote_age', 'intrabar_ambiguity'],
                    'swing' => ['gap', 'carry', 'rollover', 'mature_holding_outcomes'],
                    default => ['sessions', 'costs'],
                },
                'model_version_id' => $model->id, 'strategy_version' => 'strategy-'.$role.'-1',
                'tactic_version' => 'tactic-'.$role.'-1', 'management_version' => 'management-'.$role.'-1',
                'capital_weight' => 0.25, 'risk_per_trade_percent' => 0.1,
                'sensor_timeframes' => ['H4', 'H1', 'M15', 'M5']];
        }
        $service = app(SpecialistCouncilLifecycleService::class);
        $version = $service->registerDraft(['council_id' => 'native-integration', 'version' => '1',
            'members' => $members, 'components' => [],
            'routing' => ['id' => 'scope-router', 'version' => '1'],
            'allocation' => ['id' => 'shared-capital', 'version' => '1'],
            'risk' => ['id' => 'external-hard-risk', 'version' => '1'],
            'execution' => ['id' => 'canonical-execution', 'version' => '1', 'broker_position_mode' => 'hedging',
                'opposite_position_policy' => 'hedge', 'max_open_positions' => 8,
                'max_reserved_capital_percent' => 100.0, 'max_gross_exposure_percent' => 100.0,
                'max_total_risk_percent' => 2.0, 'max_drawdown_percent' => 10.0,
                'max_daily_loss_percent' => 3.0, 'max_expected_cost_percent' => 1.0],
            'evaluation_policy' => ['objective' => 'net_return_at_equal_risk',
                'champion_model_version_id' => $members[0]['model_version_id'],
                'solo_model_version_id' => $members[0]['model_version_id']],
        ], 'integration-owner');
        $carrier = $service->attachResearchModel($version, $this->model('carrier'));
        $path = tempnam(sys_get_temp_dir(), 'native-council-');
        $this->assertNotFalse($path);
        try {
            $file = fopen($path, 'wb');
            fputcsv($file, ['time', 'open', 'high', 'low', 'close', 'volume', 'volume_available'], ',', '"', '');
            $start = new \DateTimeImmutable('2025-01-06T02:00:00Z');
            for ($index = 0; $index < 110; $index++) {
                $price = 2000.0 + 10 * sin($index / 12) + $index * .025;
                fputcsv($file, [$start->modify('+'.$index.' hours')->format('Y-m-d\TH:i:s\Z'),
                    $price, $price + .5, $price - .5, $price, 100, 1], ',', '"', '');
            }
            fclose($file);
            $datasetHash = hash_file('sha256', $path);
            $execution = app(ExecutionContractService::class)->for('XAUUSD', 'H1');
            $runtime = $service->runtimeContractForModel($carrier, 'H1', $datasetHash, $execution['execution_hash'], null, 'XAUUSD');
            $request = ['strategy' => $carrier->strategy, 'base_strategy' => 'ema_rsi', 'version' => $carrier->version,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'initial_balance' => 10000.0,
                'dataset_path' => $path, 'replay_dataset_hash' => $datasetHash,
                'execution' => $execution['parameters'], 'execution_contract' => $execution,
                'specialist_council_contract' => $runtime];
            $request = json_decode(json_encode($request, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            $process = $this->python($request);
            $process->mustRun();
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            // Retain the actual data for a second wrong-symbol dispatch after
            // deleting the temporary CSV; there is never mixed transport.
            $requestForWrongSymbol = $request;
            $requestForWrongSymbol['candles'] = $this->candles($start);
            unset($requestForWrongSymbol['dataset_path']);
            return [$carrier, $request, $result, $requestForWrongSymbol];
        } finally {
            unlink($path);
        }
    }

    private function model(string $name): ModelVersion
    {
        return ModelVersion::create(['name' => 'native-'.$name, 'strategy' => 'ema_rsi_v1', 'version' => 'native-'.$name.'-1',
            'generation' => 1, 'status' => 'testing', 'parameters' => ['ema_fast' => 4, 'ema_slow' => 10,
                'rsi_period' => 4, 'rsi_buy_min' => 1, 'rsi_buy_max' => 99, 'rsi_sell_min' => 1, 'rsi_sell_max' => 99],
            'metadata' => ['base_strategy' => 'ema_rsi']]);
    }

    private function candles(\DateTimeImmutable $start): array
    {
        $candles = [];
        for ($index = 0; $index < 110; $index++) {
            $price = 2000.0 + 10 * sin($index / 12) + $index * .025;
            $candles[] = ['time' => $start->modify('+'.$index.' hours')->format('Y-m-d\TH:i:s\Z'),
                'open' => $price, 'high' => $price + .5, 'low' => $price - .5, 'close' => $price,
                'volume' => 100, 'volume_available' => true];
        }
        return $candles;
    }

    private function python(array $request): Process
    {
        $source = <<<'PY'
import json, sys
from app.schemas import SimpleBacktestRequest
from app.services.backtester import run_simple_ema_rsi_backtest
payload = SimpleBacktestRequest.model_validate(json.load(sys.stdin))
print(run_simple_ema_rsi_backtest(payload).model_dump_json())
PY;
        $process = new Process(['python', '-c', $source], dirname(base_path()).'/ai-service-python');
        $process->setTimeout(45);
        $process->setInput(json_encode($request, JSON_THROW_ON_ERROR));
        return $process;
    }

    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
