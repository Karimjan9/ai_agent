<?php

namespace Tests\Feature;

use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\PaperOrder;
use App\Models\PaperSignal;
use App\Models\Candle;
use App\Models\Symbol;
use App\Services\ExecutionContractService;
use App\Services\PaperAuthorityAdmissionService;
use App\Services\SpecialistCouncilLifecycleService;
use App\Services\SpecialistPaperAccountService;
use App\Services\PaperTradingExecutionService;
use App\Services\MarketData\CandlePayloadService;
use App\Services\MarketData\MarketReadinessService;
use App\Services\EconomicCalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SpecialistPaperAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-05T01:00:00Z'));
        config()->set(['services.paper.specialist_council_enabled' => true,
            'services.paper.specialist_initial_balance_cents' => 100000,
            'services.paper.specialist_max_gross_exposure_cents' => 100000,
            'services.paper.specialist_max_account_risk_cents' => 10000,
            'services.risk.max_open_positions' => 4, 'services.risk.max_positions_per_group' => 4]);
    }

    public function test_two_specialists_keep_opposite_positions_and_exact_shared_costs(): void
    {
        [$swing, $scalp] = $this->owners();
        $accounts = app(SpecialistPaperAccountService::class);
        $first = $this->reserve($swing, 'BUY', 10000);
        $second = $this->reserve($scalp, 'SELL', 10000);
        $this->assertTrue($first['allowed']); $this->assertTrue($second['allowed']);
        $one = $this->order($swing, $first); $two = $this->order($scalp, $second);
        $this->assertTrue($accounts->fill($one, 'entry', 'entry', 10000, 100000000, 3));
        $this->assertTrue($accounts->fill($two, 'entry', 'entry', 10000, 100000000, 3));
        $this->assertSame(2, PaperOrder::where('status', 'open')->count());
        $account = $accounts->reconciliation('specialist-paper');
        $this->assertTrue($account['reconciled']);
        $this->assertSame(20000, (int) $account['account']['gross_exposure_cents']);
        $this->assertSame(99994, (int) $account['account']['balance_cents']);
        $accounts->fill($one, 'exit', 'exit', 10000, 101000000, 7);
        $accounts->fill($two, 'exit', 'exit', 10000, 99000000, 7);
        $account = $accounts->reconciliation('specialist-paper');
        $this->assertTrue($account['reconciled']);
        $this->assertSame(100180, (int) $account['account']['balance_cents']);
        $this->assertSame(0, (int) $account['account']['allocated_cents']);
        $this->assertFalse($account['broker_reconciled']);
        $this->assertSame('swing', $one->fresh()->owner_id);
        $this->assertSame('scalp', $two->fresh()->owner_id);
    }

    public function test_native_pending_signal_path_opens_two_members_with_real_risk_and_discipline_gates(): void
    {
        [$swing, $scalp] = $this->owners();
        $this->signal($swing, 'BUY'); $this->signal($scalp, 'SELL');
        $symbol = Symbol::create(['code' => 'XAUUSD', 'display_name' => 'Synthetic test gold', 'asset_class' => 'commodity', 'is_active' => true]);
        Candle::create(['symbol_id' => $symbol->id, 'timeframe' => 'H1', 'time' => now()->addHour(), 'open' => 100, 'high' => 101, 'low' => 99, 'close' => 100, 'volume' => 100, 'provider' => 'synthetic_test']);
        $this->travelTo(CarbonImmutable::parse('2026-10-05T02:00:00Z'));
        $this->mock(CandlePayloadService::class)->shouldReceive('candlesForBacktest')->andReturn([]);
        $this->mock(MarketReadinessService::class)->shouldReceive('ready')->andReturn(true);
        $this->mock(EconomicCalendarService::class)->shouldReceive('veto')->andReturn(['active' => false]);
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'H1');
        Http::fake(['*/api/paper/execution-contract' => function ($request) use ($execution) {
            $buy = $request['request']['strategy'] === 'swing';
            return Http::response(['decision' => $buy ? 'BUY' : 'SELL', 'entry_price' => 100,
                'stop_loss' => $buy ? 99.5 : 100.5, 'take_profit' => $buy ? 102 : 98, 'position_size_multiple' => .5,
                'execution_hash' => $execution['execution_hash'], 'execution_contract' => $execution,
                'management_contract' => ['management_hash' => str_repeat('1', 64), 'parameters' => ['time_stop_candles' => 12]]]);
        }]);
        $service = app(PaperTradingExecutionService::class);
        $execute = new \ReflectionMethod($service, 'executePendingSignal');
        $this->assertSame(1, $execute->invoke($service, $swing), json_encode(DB::table('paper_execution_events')->pluck('reason')));
        $this->assertSame(1, $execute->invoke($service, $scalp), json_encode(DB::table('paper_execution_events')->pluck('reason')));
        $this->assertSame(0, $execute->invoke($service, $swing));
        $this->assertSame(2, PaperOrder::where('status', 'open')->count());
        $this->assertDatabaseCount('paper_fills', 2);
        $this->assertSame(2, DB::table('smart_discipline_decisions')->where('phase', 'pre_trade')->where('decision', '!=', 'VETO')->count());
        $this->assertTrue(app(SpecialistPaperAccountService::class)->reconciliation('specialist-paper')['reconciled']);
    }

    public function test_reservation_retries_and_partial_cancel_do_not_duplicate_or_release_filled_capital(): void
    {
        [$owner] = $this->owners();
        $accounts = app(SpecialistPaperAccountService::class);
        $reservation = $this->reserve($owner, 'BUY', 30000);
        $repeat = $accounts->reserve($owner, $reservation['signal'], $reservation['binding'], 30000, 100000000, 99500000, 2);
        $this->assertTrue($repeat['idempotent']);
        $this->assertSame($reservation['reservation']['id'], $repeat['reservation']['id']);
        $order = $this->order($owner, $reservation);
        $this->assertTrue($accounts->fill($order, 'first-partial', 'entry', 10000, 100000000, 1));
        $this->assertFalse($accounts->fill($order, 'first-partial', 'entry', 10000, 100000000, 1));
        $accounts->release($reservation['reservation']['id']);
        $accounts->release($reservation['reservation']['id']);
        $account = $accounts->reconciliation('specialist-paper');
        $this->assertTrue($account['reconciled']);
        $this->assertSame(0, (int) $account['account']['reserved_cents']);
        $this->assertSame(10000, (int) $account['account']['allocated_cents']);
        $this->assertSame(10000, (int) $order->fresh()->remaining_units_micros);
        $this->assertSame('open', $order->fresh()->status);
        $this->assertDatabaseCount('paper_fills', 1);
        $accounts->fill($order, 'remaining-exit', 'exit', 10000, 100000000, 1);
        $this->assertTrue($accounts->reconciliation('specialist-paper')['reconciled']);
        $this->assertSame(0, (int) DB::table('paper_capital_accounts')->value('allocated_cents'));
    }

    public function test_reserved_capital_is_visible_to_another_owner_before_order_publication(): void
    {
        [$first, $second] = $this->owners();
        $one = $this->reserve($first, 'BUY', 50000);
        $two = $this->reserve($second, 'SELL', 50000);
        $this->assertTrue($one['allowed']);
        $this->assertFalse($two['allowed']);
        $this->assertSame('PAPER_SHARED_CAPITAL_EXHAUSTED', $two['reason_code']);
        $this->assertDatabaseCount('paper_capital_reservations', 1);
        app(SpecialistPaperAccountService::class)->release($one['reservation']['id'], 'rejected');
        $retry = app(SpecialistPaperAccountService::class)->reserve($second, $two['signal'], $two['binding'], 50000, 100000000, 99990000, 2);
        $this->assertTrue($retry['allowed']);
        $this->assertSame(50000, (int) DB::table('paper_capital_accounts')->value('reserved_cents'));
    }

    public function test_member_score_or_existing_other_owner_authority_cannot_admit_drift(): void
    {
        [$owner, $other] = $this->owners();
        $other->modelVersion->update(['parameters' => ['entry_threshold' => 999, 'risk_multiplier' => .5]]);
        $blocked = $this->reserve($other, 'BUY', 10000);
        $this->assertFalse($blocked['allowed']);
        $this->assertContains($blocked['reason_code'], ['PAPER_FROZEN_CANDIDATE_DRIFT', 'E3_ADMISSION_MISSING']);
        $this->assertDatabaseCount('paper_capital_reservations', 0);
        config()->set('services.paper.specialist_broker_account_mode', 'netting');
        $blocked = $this->reserve($owner, 'BUY', 10000);
        $this->assertSame('BROKER_NETTING_RECONCILIATION_UNSUPPORTED', $blocked['reason_code']);
    }

    public function test_unaccounted_legacy_position_must_drain_before_shared_capital_can_be_reserved(): void
    {
        [$owner] = $this->owners();
        $legacy = PaperOrder::create(['model_market_performance_id' => $owner->id, 'broker' => 'simulated',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'direction' => 'BUY', 'units' => 1,
            'entry_price' => 100, 'stop_loss' => 99, 'take_profit' => 102, 'status' => 'open', 'opened_at' => now()]);
        $blocked = $this->reserve($owner, 'BUY', 10000);
        $this->assertFalse($blocked['allowed']);
        $this->assertSame('LEGACY_PAPER_ACCOUNT_RECONCILIATION_REQUIRED', $blocked['reason_code']);
        $this->assertDatabaseCount('paper_capital_reservations', 0);
        $legacy->update(['status' => 'closed', 'closed_at' => now()]);
        $retry = app(SpecialistPaperAccountService::class)->reserve($owner, $blocked['signal'], $blocked['binding'], 10000, 100000000, 99990000, 2);
        $this->assertTrue($retry['allowed']);
    }

    public function test_expired_unpublished_intent_releases_capital_and_records_a_terminal_receipt(): void
    {
        [$owner] = $this->owners();
        $held = $this->reserve($owner, 'BUY', 10000);
        $this->assertTrue($held['allowed']);
        $this->travelTo(CarbonImmutable::parse('2026-10-05T04:00:00Z'));
        $expired = app(SpecialistPaperAccountService::class)->reserve($owner, $held['signal'], $held['binding'], 10000, 100000000, 99990000, 2);
        $this->assertSame('SPECIALIST_INTENT_EXPIRED', $expired['reason_code']);
        $this->assertSame(0, (int) DB::table('paper_capital_accounts')->value('reserved_cents'));
        $this->assertSame('cancelled', $held['signal']->order()->firstOrFail()->status);
        $this->assertTrue($this->reserve($owner, 'BUY', 10000)['allowed']);
    }

    public function test_two_native_processes_cannot_spend_the_same_pending_account_capital(): void
    {
        [$first, $second] = $this->owners();
        $one = $this->signal($first, 'BUY'); $two = $this->signal($second, 'SELL');
        $database = tempnam(sys_get_temp_dir(), 'specialist-paper-race-');
        $sqlite = new \PDO('sqlite:'.$database);
        $sqlite->exec('PRAGMA journal_mode=WAL');
        // Export the isolated in-memory fixture, including original E3 rows,
        // into a disposable SQLite file shared by two real native processes.
        foreach (DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY rowid") as $table) {
            $sqlite->exec($table->sql);
            $quoted = '"'.str_replace('"', '""', $table->name).'"';
            foreach (DB::select('SELECT * FROM '.$quoted) as $row) {
                $values = (array) $row;
                $insert = $sqlite->prepare('INSERT INTO '.$quoted.' VALUES ('.implode(',', array_fill(0, count($values), '?')).')');
                $insert->execute(array_values($values));
            }
        }
        foreach (DB::select("SELECT sql FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL") as $index) $sqlite->exec($index->sql);
        $settings = json_encode(['paper' => config('services.paper'), 'risk' => config('services.risk'),
            'execution' => config('services.execution_contract'), 'now' => now()->toIso8601String()]);
        $workers = [];
        try {
            foreach ([[$first, $one], [$second, $two]] as [$candidate, $signal]) {
                $autoload = dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2).'/autoload.php';
                $worker = new Process([PHP_BINARY, base_path('tests/Support/SpecialistPaperReservationWorker.php'),
                    $autoload, $database, (string) $candidate->id, (string) $signal->id, '50000', $settings], base_path());
                $worker->setTimeout(45); $worker->start(); $workers[] = $worker;
            }
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
                $results[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }
            $this->assertSame(1, count(array_filter($results, static fn ($row) => $row['allowed'])), json_encode($results));
            $this->assertSame(1, (int) $sqlite->query('SELECT COUNT(*) FROM paper_capital_reservations')->fetchColumn());
            $this->assertSame(50000, (int) $sqlite->query('SELECT reserved_cents FROM paper_capital_accounts')->fetchColumn());
        } finally {
            foreach ($workers as $worker) if ($worker->isRunning()) $worker->stop();
            $insert = null;
            $sqlite->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            $sqlite = null;
            foreach ([$database, $database.'-wal', $database.'-shm'] as $temporary) if (is_file($temporary)) unlink($temporary);
        }
    }

    public function test_position_pin_is_immutable_and_retired_version_stops_new_entries(): void
    {
        [$owner, $other, $version] = $this->owners();
        $reservation = $this->reserve($owner, 'BUY', 10000);
        $order = $this->order($owner, $reservation);
        app(SpecialistPaperAccountService::class)->fill($order, 'entry', 'entry', 10000, 100000000, 1);
        $version->update(['state' => 'retired', 'retired_at' => now()]);
        $allowed = app(SpecialistCouncilLifecycleService::class)->paperBinding($owner->modelVersion, 'XAUUSD', 'H1', [...$reservation['binding'], 'management_only' => true]);
        $this->assertTrue($allowed['allowed']);
        $this->assertFalse($this->reserve($other, 'SELL', 10000)['allowed']);
        app(SpecialistPaperAccountService::class)->fill($order, 'exit', 'exit', 10000, 100500000, 1);
        $this->assertTrue(app(SpecialistPaperAccountService::class)->reconciliation('specialist-paper')['reconciled']);
        $this->expectExceptionMessage('PAPER_POSITION_OWNER_PIN_IMMUTABLE');
        $order->update(['owner_id' => 'scalp']);
    }

    public function test_native_management_records_a_partial_once_and_uses_the_pinned_request_after_swap(): void
    {
        [$owner, $other, $version] = $this->owners();
        $reservation = $this->reserve($owner, 'BUY', 10000);
        $order = $this->order($owner, $reservation);
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'H1')['execution_hash'];
        $management = str_repeat('1', 64);
        $order->update(['signal_context' => ['specialist_council_binding' => $reservation['binding'],
            'management_request' => ['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy' => 'swing', 'parameters' => ['entry_threshold' => 1.25], 'mtf_streams' => []],
            'execution_contract' => ['execution_hash' => $execution, 'management_contract' => ['management_hash' => $management]],
            'paper_cost_policy' => ['commission_percent' => .01, 'swap_per_day_percent' => .002]]]);
        app(SpecialistPaperAccountService::class)->fill($order, 'entry', 'entry', 10000, 100000000, 1);
        $version->update(['state' => 'retired', 'retired_at' => now()]);
        $owner->modelVersion->update(['parameters' => ['entry_threshold' => 9, 'risk_multiplier' => .5]]);
        $this->travelTo(CarbonImmutable::parse('2026-10-05T03:00:00Z'));
        $this->mock(CandlePayloadService::class)->shouldReceive('candlesForBacktest')->with('XAUUSD', 'H1', 1000)->andReturn([]);
        Http::fake(['*/api/paper/advance-contract' => Http::response(['closed' => false, 'paper_accounting' => [
            'protocol' => 'specialist_paper_accounting_v1', 'entry_price' => 100, 'exit_price' => null, 'exit_time' => null,
            'observed_at' => '2026-10-05T03:00:00+00:00', 'holding_days' => 2 / 24,
            'partial' => ['fraction' => .5, 'exit_price' => 101, 'exit_time' => '2026-10-05T02:00:00+00:00'],
            'commission_percent_round_trip' => .01, 'carry_percent_total' => .002 * 2 / 24,
            'carry_scope' => 'initial_notional_canonical_contract', 'costs_embedded_in_prices' => ['spread' => true, 'slippage' => true],
            'execution_hash' => $execution, 'management_hash' => $management, 'execution_attested' => true, 'management_attested' => true]])]);
        $service = app(PaperTradingExecutionService::class);
        $advance = new \ReflectionMethod($service, 'simulatedExit');
        $this->assertNull($advance->invoke($service, $order));
        $this->assertNull($advance->invoke($service, $order->fresh()));
        $this->assertDatabaseCount('paper_fills', 2);
        $this->assertSame(5000, (int) $order->fresh()->remaining_units_micros);
        $this->assertSame('open', $order->fresh()->status);
        $this->assertTrue(app(SpecialistPaperAccountService::class)->reconciliation('specialist-paper')['reconciled']);
        Http::assertSent(fn ($request) => $request['request']['parameters']['entry_threshold'] === 1.25);
    }

    private function reserve(ModelMarketPerformance $owner, string $direction, int $units): array
    {
        $binding = $owner->modelVersion->metadata['specialist_council_binding'];
        $signal = $this->signal($owner, $direction);
        return [...app(SpecialistPaperAccountService::class)->reserve($owner, $signal, $binding, $units, 100000000, 99990000, 2), 'signal' => $signal, 'binding' => $binding];
    }

    private function signal(ModelMarketPerformance $owner, string $direction): PaperSignal
    {
        $binding = $owner->modelVersion->metadata['specialist_council_binding'];
        $guard = app(PaperAuthorityAdmissionService::class)->verifyFrozenCandidate($owner->modelVersion, 'XAUUSD', 'H1');
        $payload = ['paper_admission' => $guard, 'specialist_council_binding' => $binding, 'specialist_trade_intent' => [
            'protocol' => 'specialist_paper_trade_intent_v1', 'owner_id' => $binding['specialist_id'], 'symbol' => 'XAUUSD',
            'direction' => $direction, 'expires_at' => now()->addHours(2)->toIso8601String()]];
        $signal = PaperSignal::create(['model_market_performance_id' => $owner->id, 'model_version_id' => $owner->model_version_id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'candle_time' => now(), 'decision' => $direction,
            'price' => 100, 'stop_loss' => 99.5, 'take_profit' => 102, 'confidence' => 90,
            'payload' => $payload, 'payload_hash' => hash('sha256', json_encode($payload))]);
        return $signal;
    }

    private function order(ModelMarketPerformance $owner, array $reservation): PaperOrder
    {
        $order = PaperOrder::create(['model_market_performance_id' => $owner->id, 'paper_signal_id' => $reservation['signal']->id,
            'broker' => 'simulated', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'direction' => $reservation['signal']->decision,
            'units' => $reservation['reservation']['requested_units_micros'] / 10000, 'entry_price' => 100, 'stop_loss' => 99.5,
            'take_profit' => 102, 'status' => 'submitted', 'opened_at' => now()]);
        app(SpecialistPaperAccountService::class)->attach($reservation['reservation']['id'], $order);
        return $order;
    }

    private function owners(): array
    {
        $owners = [];
        foreach (['swing', 'scalp'] as $role) {
            $hashes = ['passport_hash' => str_repeat('c', 64), 'execution_hash' => app(ExecutionContractService::class)->for('XAUUSD', 'H1')['execution_hash'],
                'confirmation_entry_hash' => str_repeat('e', 64), 'risk_governor_hash' => str_repeat('f', 64),
                'trade_management_hash' => str_repeat('1', 64), 'training_pre_2026' => true];
            $model = ModelVersion::create(['name' => $role, 'strategy' => $role, 'version' => 'v1', 'generation' => 1, 'status' => 'testing',
                'parameters' => ['entry_threshold' => 1.25, 'risk_multiplier' => .5], 'metadata' => ['elite_agent_passport' => ['passport_hash' => $hashes['passport_hash']],
                    'confirmation_entry' => ['contract_hash' => $hashes['confirmation_entry_hash']], 'risk_governor' => ['hash' => $hashes['risk_governor_hash']],
                    'trade_management' => ['hash' => $hashes['trade_management_hash']]], 'evidence_status' => 'valid']);
            DB::table('evolutionary_authority_ledgers')->insert(['authority_key' => hash('sha256', 'pre-paper-'.$model->id), 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'authority_stage' => 'breeder_candidate', 'status' => 'research_mentor_granted',
                'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64), 'evidence' => json_encode(['incubation_passed' => true,
                    'passport' => ['passed' => true], 'research_mentor_authority' => ['eligible' => true],
                    'economic_parent_authority' => ['eligible' => false, 'checks' => ['screening_passed' => true, 'full_replay_passed' => true, 'positive_absolute_settlement' => true]]]),
                'evaluated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $this->assertSame('e3_paper_candidate', app(PaperAuthorityAdmissionService::class)->admit($model, 'XAUUSD', 'H1', $hashes)['status']);
            $owners[] = ModelMarketPerformance::create(['model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
                'strategy_family' => 'hybrid', 'status' => 'forward_validated', 'paper_status' => 'pending', 'evidence_status' => 'valid',
                'metrics' => ['training_boundary' => ['used_for_training' => false], 'gold_holdout' => ['used_for_training' => false]]])->load('modelVersion');
        }
        $members = [];
        foreach ($owners as $owner) {
            $role = $owner->modelVersion->strategy;
            $members[] = ['specialist_id' => $role, 'role' => $role, 'version' => 'v1', 'as_of' => '2025-12-01T00:00:00Z', 'inputs' => ['as_of_quotes'],
                'scope' => ['symbols' => ['XAUUSD'], 'contexts' => ['trend']], 'known_limits' => ['fixture_only'],
                'resources' => ['max_compute_ms' => 100, 'max_memory_mb' => 32, 'max_lookback_bars' => 512],
                'horizon' => ['kind' => $role, 'decision_interval_seconds' => 3600, 'reevaluation_interval_seconds' => 3600, 'max_holding_seconds' => 86400, 'execution_precision' => 'candle'],
                'data_requirements' => $role === 'scalp' ? ['bid_ask', 'spread', 'slippage', 'quote_age', 'intrabar_ambiguity'] : ['gap', 'carry', 'rollover', 'mature_holding_outcomes'],
                'model_version_id' => $owner->model_version_id, 'strategy_version' => 'v1', 'tactic_version' => 'v1', 'management_version' => 'manage-v1', 'capital_weight' => .5, 'risk_per_trade_percent' => .5];
        }
        $manifest = ['council_id' => 'paper-fixture', 'version' => 'v1', 'members' => $members, 'components' => [],
            'routing' => ['id' => 'route', 'version' => '1'], 'allocation' => ['id' => 'allocate', 'version' => '1'], 'risk' => ['id' => 'risk', 'version' => '1'],
            'execution' => ['id' => 'execution', 'version' => '1', 'broker_position_mode' => 'hedging', 'opposite_position_policy' => 'hedge',
                'max_open_positions' => 4, 'max_reserved_capital_percent' => 100, 'max_gross_exposure_percent' => 100, 'max_total_risk_percent' => 2,
                'max_drawdown_percent' => 10, 'max_daily_loss_percent' => 3, 'max_expected_cost_percent' => 1],
            'evaluation_policy' => ['objective' => 'net_return_at_equal_risk', 'champion_model_version_id' => $owners[0]->model_version_id, 'solo_model_version_id' => $owners[0]->model_version_id]];
        $version = app(SpecialistCouncilLifecycleService::class)->registerDraft($manifest, 'fixture-researcher');
        // A prequalified active-version fixture only; native E3 was independently
        // created above for EACH owner. No score or claim is execution authority.
        $version->update(['state' => 'active', 'activated_at' => now()]);
        foreach ($owners as $owner) {
            $owner->modelVersion->update(['metadata' => [...$owner->modelVersion->metadata, 'specialist_council_binding' => ['protocol' => 'specialist_council_binding_v1',
                'council_id' => 'paper-fixture', 'council_version' => 'v1', 'specialist_id' => $owner->modelVersion->strategy, 'management_version' => 'manage-v1']]]);
        }
        return [...$owners, $version];
    }
}
