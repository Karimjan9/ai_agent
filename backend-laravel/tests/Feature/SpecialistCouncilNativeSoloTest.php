<?php

namespace Tests\Feature;

use App\Models\ModelVersion;
use App\Models\SpecialistCouncilVersion;
use App\Services\ExecutionContractService;
use App\Services\ProspectiveRepairProbeWindowService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\SpecialistCouncilContractService;
use App\Services\SpecialistCouncilLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SpecialistCouncilNativeSoloTest extends TestCase
{
    use RefreshDatabase;

    public function test_original_declared_solo_crosses_real_python_account_with_same_clock_and_full_metrics(): void
    {
        [$version, $carrier, $solo, $plan, $base, $rows] = $this->fixture();
        $owner = app(SpecialistCouncilLifecycleService::class);
        $before = $solo->parameters;
        $modelHash = app(SpecialistCouncilContractService::class)->modelHash($solo);
        $candidate = $owner->runtimeContractForModel($carrier, 'M5', $base['replay_dataset_hash'], $base['execution_hash'], null, 'XAUUSD');
        $nativeSolo = $owner->runtimeContractForModel($solo, 'M5', $base['replay_dataset_hash'], $base['execution_hash'], null, 'XAUUSD');
        $this->assertSame([$candidate['members'][0]], $nativeSolo['members']);
        $this->assertSame($candidate['policy'], $nativeSolo['policy']);
        $this->assertSame(.25, $nativeSolo['members'][0]['capital_weight']);
        $this->assertSame($version->manifest['members'][0]['passport_hash'], $nativeSolo['solo_comparison']['passport_hash']);
        $this->assertFalse($nativeSolo['solo_comparison']['best_solo_full_budget_proven']);
        $path = tempnam(sys_get_temp_dir(), 'native-solo-');
        try {
            $stream = fopen($path, 'wb');
            fputcsv($stream, ['time', 'open', 'high', 'low', 'close', 'volume', 'volume_available'], ',', '"', '');
            foreach ($rows as $row) fputcsv($stream, array_values($row), ',', '"', '');
            fclose($stream);
            // The fixture plan is sealed to the actual CSV before any replay.
            $this->assertSame($base['replay_dataset_hash'], hash_file('sha256', $path));
            $results = []; $requests = [];
            foreach (['candidate' => $candidate, 'solo' => $nativeSolo] as $key => $runtime) {
                $request = $owner->bindEvaluationRequest($version, $key, [...$base,
                    'dataset_path' => $path, 'specialist_council_contract' => $runtime]);
                $request = json_decode(json_encode($request, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
                $process = $this->python($request); $process->mustRun();
                $results[$key] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $requests[$key] = $request;
            }
            $receipt = $owner->attestReplayResult($solo->fresh(), $requests['solo'], $results['solo']);
            $this->assertSame('computed', $receipt['status']);
            $this->assertCount(1, $receipt['members']);
            $this->assertNotEmpty($receipt['position_ledger']);
            $this->assertNotEmpty($receipt['account_ledger']);
            foreach (['candidate', 'solo'] as $key) $owner->assertOriginalArmScope($plan['arms'][$key], $requests[$key], $results[$key], 'M5');
            $physical = new \ReflectionMethod(SpecialistCouncilLifecycleService::class, 'assertPairedExecutedClocks');
            $physical->invoke($owner, array_map(fn (array $result): array => ['executed_clock' => $result['data_quality']['replay_executed_clock']], $results));
            $this->assertSame(287, $receipt['replay_executed_clock']['decision_rows']);
            $this->assertSame(32, $receipt['evaluated_scope']['warmup_rows']);
            $metrics = $receipt['metrics'];
            foreach (['net_profit', 'total_costs', 'matured_trades', 'censored_trades', 'max_drawdown_percent',
                'max_daily_loss_percent', 'max_gross_exposure_percent', 'max_total_risk_percent'] as $key) {
                $this->assertIsNumeric($metrics[$key]);
            }
            $this->assertGreaterThan(0, $metrics['total_costs']);
            $this->assertGreaterThan(0, $metrics['max_gross_exposure_percent']);
            $this->assertGreaterThan(0, $metrics['max_total_risk_percent']);
            $this->assertEqualsWithDelta(array_sum(array_column($receipt['position_ledger'], 'net_pnl')), $metrics['net_profit'], .000001);
            $this->assertEqualsWithDelta($metrics['net_profit'], $receipt['account']['final_balance'] - $base['initial_balance'], .000001);
            foreach ($receipt['position_ledger'] as $position) {
                $this->assertSame('scalp', $position['management_owner']);
                $this->assertSame('management-scalp', $position['management_version']);
            }
            unset($results['solo']['specialist_council_receipt'], $results['solo']['data_quality']['specialist_council_receipt']);
            try { $owner->attestReplayResult($solo, $requests['solo'], $results['solo']); $this->fail('Native SOLO receipt was omitted.'); }
            catch (\LogicException $error) { $this->assertSame('SPECIALIST_COUNCIL_RECEIPT_OR_BINDING_MISSING', $error->getMessage()); }
        } finally { unlink($path); }
        $this->assertSame($before, $solo->fresh()->parameters);
        $this->assertSame($modelHash, app(SpecialistCouncilContractService::class)->modelHash($solo->fresh()));
        $this->assertNull(data_get($solo->fresh()->metadata, 'specialist_council'));
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_missing_or_changed_native_account_is_refused_before_dispatch(): void
    {
        [$version, , $solo, , $base] = $this->fixture();
        $owner = app(SpecialistCouncilLifecycleService::class);
        $runtime = $owner->runtimeContractForModel($solo, 'M5', $base['replay_dataset_hash'], $base['execution_hash'], null, 'XAUUSD');
        foreach (['missing', 'weight', 'risk', 'management', 'policy', 'passport'] as $mutation) {
            $request = [...$base, 'specialist_council_contract' => $runtime];
            if ($mutation === 'missing') unset($request['specialist_council_contract']);
            if ($mutation === 'weight') $request['specialist_council_contract']['members'][0]['capital_weight'] = 1;
            if ($mutation === 'risk') $request['specialist_council_contract']['members'][0]['risk_per_trade_percent'] = 1;
            if ($mutation === 'management') $request['specialist_council_contract']['members'][0]['management_version'] = 'other';
            if ($mutation === 'policy') $request['specialist_council_contract']['policy']['max_total_risk_percent'] = 3;
            if ($mutation === 'passport') $request['specialist_council_contract']['solo_comparison']['passport_hash'] = str_repeat('f', 64);
            try { $owner->bindEvaluationRequest($version, 'solo', $request); $this->fail('Changed native SOLO was dispatched: '.$mutation); }
            catch (\LogicException $error) { $this->assertStringStartsWith('COUNCIL_NATIVE_SOLO_', $error->getMessage()); }
        }
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_fresh_chosen_full_account_solo_changes_only_allocation_and_crosses_actual_python_receipt(): void
    {
        [$version, $carrier, $solo, $plan, $base, $rows] = $this->fixture(true, true, 'hour');
        $owner = app(SpecialistCouncilLifecycleService::class);
        $before = $solo->parameters;
        $modelHash = app(SpecialistCouncilContractService::class)->modelHash($solo);
        $candidate = $owner->runtimeContractForModel($carrier, 'M5', $base['replay_dataset_hash'], $base['execution_hash'], null, 'XAUUSD');
        $runtime = $owner->runtimeContractForModel($solo, 'M5', $base['replay_dataset_hash'], $base['execution_hash'], null, 'XAUUSD');
        $this->assertSame($candidate['members'][1], $runtime['solo_source_member']);
        $restored = $runtime['members'][0]; $restored['capital_weight'] = .25;
        $this->assertSame($runtime['solo_source_member'], $restored);
        $this->assertSame(1.0, $runtime['members'][0]['capital_weight']);
        $this->assertSame('hour', $runtime['members'][0]['role']);
        $this->assertSame(10800, $runtime['members'][0]['horizon']['max_holding_seconds']);
        $this->assertSame(.25, $version->manifest['members'][1]['capital_weight']);
        $this->assertSame($candidate['policy'], $runtime['policy']);
        $this->assertSame('chosen_source_unqualified', $runtime['solo_comparison']['selection_status']);
        $this->assertFalse($runtime['solo_comparison']['best_solo_full_budget_proven']);
        $this->assertFalse($runtime['solo_comparison']['promotion_evidence']);
        $path = tempnam(sys_get_temp_dir(), 'native-chosen-solo-');
        try {
            $stream = fopen($path, 'wb');
            fputcsv($stream, ['time', 'open', 'high', 'low', 'close', 'volume', 'volume_available'], ',', '"', '');
            foreach ($rows as $row) fputcsv($stream, array_values($row), ',', '"', '');
            fclose($stream);
            $this->assertSame($base['replay_dataset_hash'], hash_file('sha256', $path));
            $results = []; $requests = [];
            foreach (['candidate' => $candidate, 'solo' => $runtime] as $key => $contract) {
                $requests[$key] = $owner->bindEvaluationRequest($version, $key, [...$base,
                    'dataset_path' => $path, 'specialist_council_contract' => $contract]);
                $this->assertTrue($requests[$key]['emit_decision_trace']);
                $process = $this->python($requests[$key]); $process->mustRun();
                $results[$key] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }
            $receipt = $owner->attestReplayResult($solo->fresh(), $requests['solo'], $results['solo']);
            $this->assertSame('computed', $receipt['status']);
            $this->assertCount(1, $receipt['members']);
            $this->assertNotEmpty($receipt['position_ledger']);
            $this->assertTrue(app(\App\Services\LabImmutableEvidenceService::class)->nativeDecisionTraceHashValid($results['solo'], $receipt));
            $this->assertSame($runtime['contract_hash'], $receipt['contract_hash']);
            foreach (['net_profit', 'total_costs', 'matured_trades', 'censored_trades', 'max_drawdown_percent',
                'max_daily_loss_percent', 'max_gross_exposure_percent', 'max_total_risk_percent'] as $key) $this->assertIsNumeric($receipt['metrics'][$key]);
            $this->assertGreaterThan(0, $receipt['metrics']['total_costs']);
            foreach (['candidate', 'solo'] as $key) $owner->assertOriginalArmScope($plan['arms'][$key], $requests[$key], $results[$key], 'M5');
            $physical = new \ReflectionMethod(SpecialistCouncilLifecycleService::class, 'assertPairedExecutedClocks');
            $physical->invoke($owner, array_map(fn (array $result): array => ['executed_clock' => $result['data_quality']['replay_executed_clock']], $results));
            $this->assertSame(287, $receipt['replay_executed_clock']['decision_rows']);
            $changed = $results['solo']; $changed['decision_trace'][0]['price'] += .1;
            try { $owner->attestReplayResult($solo, $requests['solo'], $changed); $this->fail('Changed actual trace accepted.'); }
            catch (\LogicException $error) { $this->assertSame('COUNCIL_NATIVE_DECISION_TRACE_HASH_MISMATCH', $error->getMessage()); }
        } finally { unlink($path); }
        $this->assertSame($before, $solo->fresh()->parameters);
        $this->assertSame($modelHash, app(SpecialistCouncilContractService::class)->modelHash($solo->fresh()));
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_chosen_full_account_preflight_refuses_programme_drift_or_best_claim_and_followup_reuse(): void
    {
        [$version, , $solo, $plan, $base] = $this->fixture(true, true);
        $owner = app(SpecialistCouncilLifecycleService::class);
        $runtime = $owner->runtimeContractForModel($solo, 'M5', $base['replay_dataset_hash'], $base['execution_hash'], null, 'XAUUSD');
        foreach (['source', 'weight', 'risk', 'management', 'parameters', 'policy', 'passport', 'best', 'selection'] as $mutation) {
            $changed = $runtime;
            if ($mutation === 'source') $changed['solo_source_member']['parameters']['ema_fast'] = 5;
            if ($mutation === 'weight') $changed['members'][0]['capital_weight'] = .25;
            if ($mutation === 'risk') $changed['members'][0]['risk_per_trade_percent'] = .5;
            if ($mutation === 'management') $changed['members'][0]['management_version'] = 'other';
            if ($mutation === 'parameters') $changed['members'][0]['parameters']['ema_fast'] = 5;
            if ($mutation === 'policy') $changed['policy']['max_total_risk_percent'] = 3;
            if ($mutation === 'passport') $changed['members'][0]['passport_hash'] = str_repeat('f', 64);
            if ($mutation === 'best') $changed['solo_comparison']['best_solo_full_budget_proven'] = true;
            if ($mutation === 'selection') $changed['solo_comparison']['selection_status'] = 'best_on_preregistered_candidate_panel';
            try { $owner->bindEvaluationRequest($version, 'solo', [...$base, 'specialist_council_contract' => $changed]); $this->fail('Changed original chosen SOLO accepted: '.$mutation); }
            catch (\LogicException $error) { $this->assertStringStartsWith('COUNCIL_NATIVE_SOLO_', $error->getMessage()); }
        }
        $originalRow = DB::table('specialist_council_evaluation_plans')->sole();
        $proof = ['source_version_id' => (int) $version->id, 'source_manifest_hash' => $version->manifest_hash,
            'source_plan_hash' => $originalRow->plan_hash, 'evaluation_plan' => $plan];
        try { app(\App\Services\SpecialistCouncilFollowupExecutionService::class)->preparationRequest(new \App\Models\LabGeneration, $proof); $this->fail('An original chosen question renewed itself as followup.'); }
        catch (\LogicException $error) { $this->assertSame('COUNCIL_FOLLOWUP_NATIVE_SOLO_FULL_ACCOUNT_REQUIRES_FRESH_ORIGINAL_REQUEST', $error->getMessage()); }
        $this->assertSame($originalRow->plan, DB::table('specialist_council_evaluation_plans')->sole()->plan);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_chosen_full_account_seal_refuses_previously_bound_source_and_missing_choice_scope(): void
    {
        [$version, , , $plan] = $this->fixture();
        $contracts = app(SpecialistCouncilContractService::class);
        $selector = $this->chosenSelector();
        foreach (['selection_status', 'selection_timing', 'comparison_kind'] as $key) {
            $wrong = $selector; unset($wrong[$key]);
            try { $contracts->sealNativeSoloComparison($version->manifest, [...$plan, 'solo_comparison' => $wrong]); $this->fail('Incomplete original chosen scope accepted.'); }
            catch (\InvalidArgumentException $error) { $this->assertStringStartsWith('COUNCIL_NATIVE_SOLO_', $error->getMessage()); }
        }
        $owner = app(SpecialistCouncilLifecycleService::class);
        $manifest = $version->manifest; unset($manifest['manifest_hash']); $manifest['version'] = 'fresh-label';
        $later = $owner->registerDraft($manifest, 'creator');
        try { $owner->sealEvaluationPlan($later, 'evaluator', [...$plan, 'solo_comparison' => $selector]); $this->fail('New label upgraded bound original sources.'); }
        catch (\LogicException $error) { $this->assertSame('COUNCIL_NATIVE_SOLO_FULL_ACCOUNT_REQUIRES_FRESH_ORIGINAL_MODELS', $error->getMessage()); }
        $this->assertDatabaseCount('specialist_council_evaluation_plans', 1);
        $this->assertSame('draft', $later->fresh()->state);
    }

    public function test_wrong_declaration_or_full_budget_claim_cannot_be_sealed(): void
    {
        [$version, , , $plan] = $this->fixture();
        $contracts = app(SpecialistCouncilContractService::class);
        foreach (['protocol', 'comparison_kind', 'specialist_id', 'best_solo_full_budget_proven', 'passport_hash'] as $field) {
            $wrong = $plan;
            $wrong['solo_comparison'][$field] = $field === 'best_solo_full_budget_proven' ? true : 'wrong';
            try { $contracts->sealNativeSoloComparison($version->manifest, $wrong); $this->fail('Wrong SOLO scope accepted: '.$field); }
            catch (\InvalidArgumentException $error) { $this->assertStringStartsWith('COUNCIL_NATIVE_SOLO_', $error->getMessage()); }
        }
    }

    public function test_legacy_plan_is_not_upgraded_and_original_stored_plan_is_unchanged(): void
    {
        [, , $solo, , $base] = $this->fixture(false);
        $before = DB::table('specialist_council_evaluation_plans')->sole()->plan;
        $this->assertNull(app(SpecialistCouncilLifecycleService::class)->runtimeContractForModel(
            $solo, 'M5', $base['replay_dataset_hash'], $base['execution_hash'], null, 'XAUUSD'));
        $this->assertSame($before, DB::table('specialist_council_evaluation_plans')->sole()->plan);
        $this->assertArrayNotHasKey('solo_comparison', json_decode($before, true));
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_later_signed_declaration_cannot_grandfather_a_legacy_original_plan(): void
    {
        [$version, , , $plan] = $this->fixture(false);
        config(['services.internal_api.token' => 'isolated-native-solo-test-only-hmac-key']);
        $row = DB::table('specialist_council_evaluation_plans')->sole();
        $plan['solo_comparison'] = app(SpecialistCouncilContractService::class)->sealNativeSoloComparison($version->manifest,
            [...$plan, 'solo_comparison' => ['protocol' => SpecialistCouncilContractService::NATIVE_SOLO_PROTOCOL,
                'comparison_kind' => 'matched_member_allocation', 'specialist_id' => 'scalp', 'best_solo_full_budget_proven' => false]]);
        $proof = ['source_version_id' => (int) $version->id, 'source_manifest_hash' => $version->manifest_hash,
            'source_plan_hash' => $row->plan_hash, 'evaluation_plan' => $plan];
        $seal = new \ReflectionMethod(\App\Services\SpecialistCouncilResearchFeedbackService::class, 'followupServerSeal');
        $proof['server_seal'] = $seal->invoke(app(\App\Services\SpecialistCouncilResearchFeedbackService::class), $proof);
        $originalProof = $proof;
        try {
            app(\App\Services\SpecialistCouncilFollowupExecutionService::class)->preparationRequest(new \App\Models\LabGeneration, $proof);
            $this->fail('A later signed declaration upgraded the original legacy plan.');
        } catch (\LogicException $error) {
            $this->assertSame('COUNCIL_FOLLOWUP_FUTURE_NATIVE_SOLO_ORIGINAL_DECLARATION_REQUIRED', $error->getMessage());
        }
        $this->assertSame($originalProof, $proof);
        $this->assertSame($row->plan, DB::table('specialist_council_evaluation_plans')->sole()->plan);
        $this->assertDatabaseCount('lab_generations', 0);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    private function fixture(bool $declared = true, bool $fullChosen = false, string $sourceRole = 'scalp'): array
    {
        $this->assertSame(realpath(dirname(__DIR__, 2)), realpath(base_path()));
        $this->assertSame('testing', config('app.env'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        config(['services.execution_contract.allowed_sessions_utc' => [], 'services.mtf_pilot.enabled' => false,
            'services.xauusd_organism.enabled' => false, 'services.execution_contract.stop_loss_percent' => .15,
            'services.execution_contract.take_profit_percent' => .2]);
        $members = []; $models = [];
        foreach (['scalp' => 3600, 'hour' => 10800, 'day' => 43200, 'swing' => 259200] as $role => $holding) {
            $model = $models[] = ModelVersion::create(['name' => 'solo-'.$role, 'strategy' => 'ema_rsi_v1', 'version' => 'solo-'.$role.'-1',
                'generation' => 1, 'status' => 'testing', 'parameters' => ['ema_fast' => 4, 'ema_slow' => 10,
                    'rsi_period' => 4, 'rsi_buy_min' => 1, 'rsi_buy_max' => 99, 'rsi_sell_min' => 1, 'rsi_sell_max' => 99],
                'metadata' => ['base_strategy' => 'ema_rsi']]);
            $members[] = ['specialist_id' => $role, 'role' => $role, 'version' => '1', 'as_of' => '2024-12-31T00:00:00Z',
                'inputs' => ['as_of_closed_candles'], 'scope' => ['symbols' => ['XAUUSD'], 'contexts' => ['any']],
                'known_limits' => ['synthetic_research_unqualified'], 'resources' => ['max_compute_ms' => 100, 'max_memory_mb' => 32, 'max_lookback_bars' => 512],
                'horizon' => ['kind' => $role, 'decision_interval_seconds' => 300, 'reevaluation_interval_seconds' => 300,
                    'max_holding_seconds' => $holding, 'execution_precision' => 'candle'],
                'data_requirements' => match ($role) {
                    'scalp' => ['bid_ask', 'spread', 'slippage', 'quote_age', 'intrabar_ambiguity'],
                    'swing' => ['gap', 'carry', 'rollover', 'mature_holding_outcomes'], default => ['sessions', 'costs'],
                },
                'model_version_id' => $model->id, 'strategy_version' => 'strategy-'.$role, 'tactic_version' => 'tactic-'.$role,
                'management_version' => 'management-'.$role, 'capital_weight' => .25, 'risk_per_trade_percent' => .1,
                'sensor_timeframes' => ['H4', 'H1', 'M15', 'M5']];
        }
        $owner = app(SpecialistCouncilLifecycleService::class);
        $soloIndex = array_search($sourceRole, ['scalp', 'hour', 'day', 'swing'], true);
        $this->assertIsInt($soloIndex);
        $policy = ['id' => 'native-account', 'version' => '1', 'broker_position_mode' => 'hedging', 'opposite_position_policy' => 'hedge',
            'max_open_positions' => 8, 'max_reserved_capital_percent' => 100, 'max_gross_exposure_percent' => 100,
            'max_total_risk_percent' => 2, 'max_drawdown_percent' => 10, 'max_daily_loss_percent' => 3, 'max_expected_cost_percent' => 1];
        $version = $owner->registerDraft(['council_id' => 'native-solo-test', 'version' => '1', 'members' => $members, 'components' => [],
            'routing' => ['id' => 'routing', 'version' => '1'], 'allocation' => ['id' => 'allocation', 'version' => '1'],
            'risk' => ['id' => 'risk', 'version' => '1'], 'execution' => $policy, 'evaluation_policy' => [
                'objective' => 'net_return_at_equal_risk', 'champion_model_version_id' => $models[0]->id, 'solo_model_version_id' => $models[$soloIndex]->id]], 'creator');
        $carrier = $owner->attachResearchModel($version, ModelVersion::create(['name' => 'carrier', 'strategy' => 'ema_rsi_v1',
            'version' => 'carrier-1', 'generation' => 1, 'status' => 'testing', 'parameters' => $models[0]->parameters, 'metadata' => ['base_strategy' => 'ema_rsi']]));
        $rows = []; $start = new \DateTimeImmutable('2025-01-06T02:00:00Z');
        $csv = fopen('php://temp', 'w+');
        fputcsv($csv, ['time', 'open', 'high', 'low', 'close', 'volume', 'volume_available'], ',', '"', '');
        for ($index = 0; $index < 320; $index++) {
            $price = 2000.0 + 10 * sin($index / 12) + $index * .025;
            $rows[] = $row = ['time' => $start->modify('+'.($index * 5).' minutes')->format('Y-m-d\TH:i:s\Z'),
                'open' => $price, 'high' => $price + .5, 'low' => $price - .5, 'close' => $price, 'volume' => 100, 'volume_available' => 1];
            fputcsv($csv, array_values($row), ',', '"', '');
        }
        rewind($csv); $hash = hash('sha256', stream_get_contents($csv)); fclose($csv);
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        $probe = app(ProspectiveRepairProbeWindowService::class)->seal($rows, $hash, $execution['execution_hash'], 'declared-solo-fixture', 288, 32);
        $scope = ['start_inclusive' => $probe['evaluated_start'], 'end_exclusive' => $start->modify('+1600 minutes')->format(DATE_ATOM),
            'rows' => 288, 'decision_rows' => 287, 'warmup_rows' => 32, 'policy_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($probe)];
        $plan = ['purpose' => 'research', 'execution_hash' => $execution['execution_hash'], 'execution_timeframe' => 'M5',
            'initial_capital' => 10000, 'cost_model' => $execution['parameters'],
            'risk_policy' => [...array_diff_key($policy, ['id' => true, 'version' => true]), 'risk_per_trade_percent' => .5],
            'windows' => [['window_key' => 'original', 'start_inclusive' => $probe['loaded_start'], 'end_exclusive' => $scope['end_exclusive'],
                'dataset_sha256' => $hash, 'prospective_probe_window' => $probe, 'evaluation_scope' => $scope]],
            'arms' => [['arm_key' => 'candidate', 'kind' => 'candidate', 'window_key' => 'original', 'model_version_id' => $carrier->id],
                ['arm_key' => 'solo', 'kind' => 'solo', 'window_key' => 'original', 'model_version_id' => $models[$soloIndex]->id]]];
        if ($declared) $plan['solo_comparison'] = ['protocol' => SpecialistCouncilContractService::NATIVE_SOLO_PROTOCOL,
            'comparison_kind' => 'matched_member_allocation', 'specialist_id' => $sourceRole, 'best_solo_full_budget_proven' => false];
        if ($fullChosen) $plan['solo_comparison'] = $this->chosenSelector($sourceRole);
        $sealed = $owner->sealEvaluationPlan($version, 'evaluator', $plan);
        $carrier = $owner->attachEvaluationArm($version, 'candidate', $carrier);
        $solo = $owner->attachEvaluationArm($version, 'solo', $models[$soloIndex]);
        $base = ['strategy' => 'ema_rsi_v1', 'base_strategy' => 'ema_rsi', 'symbol' => 'XAUUSD', 'timeframe' => 'M5',
            'evaluation_mode' => 'incremental', 'initial_balance' => 10000, 'execution_hash' => $execution['execution_hash'],
            'execution' => $execution['parameters'], 'execution_contract' => $execution, 'replay_dataset_hash' => $hash];
        return [$version->fresh(), $carrier, $solo, $sealed, $base, $rows];
    }

    private function chosenSelector(string $sourceRole = 'scalp'): array
    {
        return ['protocol' => SpecialistCouncilContractService::NATIVE_CHOSEN_SOLO_PROTOCOL,
            'comparison_kind' => 'chosen_source_full_account_allocation', 'specialist_id' => $sourceRole,
            'selection_status' => 'chosen_source_unqualified', 'selection_timing' => 'preregistered_before_outcomes',
            'best_solo_full_budget_proven' => false];
    }

    private function python(array $request): Process
    {
        $source = <<<'PY'
import json, sys
from app.schemas import SimpleBacktestRequest
from app.services.backtester import run_simple_ema_rsi_backtest
print(run_simple_ema_rsi_backtest(SimpleBacktestRequest.model_validate(json.load(sys.stdin))).model_dump_json())
PY;
        $process = new Process(['python', '-c', $source], dirname(base_path()).'/ai-service-python');
        $process->setTimeout(45); $process->setInput(json_encode($request, JSON_THROW_ON_ERROR));
        return $process;
    }
}
