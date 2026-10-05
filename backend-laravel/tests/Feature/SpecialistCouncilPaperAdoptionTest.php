<?php

namespace Tests\Feature;

use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\PaperOrder;
use App\Models\PaperSignal;
use App\Models\SpecialistCouncilVersion;
use App\Services\AutonomousModeService;
use App\Services\ExecutionContractService;
use App\Services\PaperTradingExecutionService;
use App\Services\SpecialistCouncilLifecycleService;
use App\Services\MarketData\CandlePayloadService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Paper-clock integration fixtures, never independent market or promotion evidence. */
class SpecialistCouncilPaperAdoptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-05T03:00:00Z'));
        config()->set('services.paper.specialist_council_enabled', true);
    }

    public function test_existing_paper_cycle_delivers_due_adoption_to_the_native_locked_owner(): void
    {
        $version = $this->scheduled('due-council');
        $future = $this->scheduled('future-council', now()->addHour());
        $this->running();
        $this->mock(SpecialistCouncilLifecycleService::class)->shouldReceive('activateDue')->once()->with('due-council')
            ->andReturnUsing(function () use ($version): array {
                // The mocked native owner stands for its already-tested original
                // assessment/E3/E4 gates; the paper cycle has no approval shortcut.
                $version->update(['state' => 'active', 'activated_at' => now()]);
                return ['allowed' => true, 'state' => 'active', 'version_id' => $version->id, 'promotion_evidence' => false];
            });
        $stats = app(PaperTradingExecutionService::class)->run();
        $this->assertSame('active', $version->fresh()->state);
        $this->assertSame('scheduled', $future->fresh()->state);
        $this->assertSame('checked', $stats['specialist_adoption']['status']);
        $this->assertSame('due-council', $stats['specialist_adoption']['adoptions'][0]['council_id']);
        $this->assertTrue($stats['specialist_adoption']['adoptions'][0]['result']['allowed']);
        $this->assertFalse($stats['specialist_adoption']['promotion_evidence']);
        $this->assertDatabaseCount('paper_orders', 0);
    }

    #[DataProvider('fencedStates')]
    public function test_pause_stop_and_safety_fences_never_adopt_a_due_version(string $state, bool $enabled): void
    {
        $version = $this->scheduled('fenced-council');
        $this->mock(AutonomousModeService::class)->shouldReceive('status')->once()->with('XAUUSD', 'H1')
            ->andReturn(['state' => $state, 'enabled' => $enabled]);
        $this->mock(SpecialistCouncilLifecycleService::class)->shouldNotReceive('activateDue');
        $stats = app(PaperTradingExecutionService::class)->run();
        $this->assertFalse($stats['specialist_adoption']['intake_allowed']);
        $this->assertSame('SPECIALIST_ADOPTION_AUTONOMY_FENCE', $stats['specialist_adoption']['reason_code']);
        $this->assertSame($state, $stats['specialist_adoption']['controller_state']);
        $this->assertSame('scheduled', $version->fresh()->state);
        $this->assertSame(0, $stats['opened']);
        $this->assertDatabaseCount('paper_orders', 0);
    }

    public static function fencedStates(): array
    {
        return [['paused', false], ['pausing', false], ['stopped', false], ['draining', false], ['safety_halt', false]];
    }

    public function test_opt_in_false_leaves_scheduled_versions_untouched(): void
    {
        config()->set('services.paper.specialist_council_enabled', false);
        $version = $this->scheduled('disabled-council');
        $this->mock(AutonomousModeService::class)->shouldNotReceive('status');
        $this->mock(SpecialistCouncilLifecycleService::class)->shouldNotReceive('activateDue');
        $stats = app(PaperTradingExecutionService::class)->run();
        $this->assertSame('disabled', $stats['specialist_adoption']['status']);
        $this->assertSame('SPECIALIST_PAPER_DISABLED', $stats['specialist_adoption']['reason_code']);
        $this->assertSame('scheduled', $version->fresh()->state);
    }

    public function test_rejected_or_corrupt_version_does_not_shadow_another_due_council(): void
    {
        $this->scheduled('a-blocked');
        $this->scheduled('b-corrupt');
        $other = $this->scheduled('c-ready');
        $this->running();
        $owner = $this->mock(SpecialistCouncilLifecycleService::class);
        $owner->shouldReceive('activateDue')->once()->with('a-blocked')
            ->andReturn(['allowed' => false, 'reason_code' => 'COUNCIL_ORIGINAL_EVIDENCE_DRIFT']);
        $owner->shouldReceive('activateDue')->once()->with('b-corrupt')->andThrow(new \LogicException('fixture seal drift'));
        $owner->shouldReceive('activateDue')->once()->with('c-ready')->andReturnUsing(function () use ($other): array {
            $other->update(['state' => 'active', 'activated_at' => now()]);
            return ['allowed' => true, 'version_id' => $other->id];
        });
        $stats = app(PaperTradingExecutionService::class)->run();
        $rows = $stats['specialist_adoption']['adoptions'];
        $this->assertCount(3, $rows);
        $this->assertSame('COUNCIL_ORIGINAL_EVIDENCE_DRIFT', $rows[0]['result']['reason_code']);
        $this->assertSame('COUNCIL_ADOPTION_REVALIDATION_FAILED', $rows[1]['result']['reason_code']);
        $this->assertSame('active', $other->fresh()->state);
        $this->assertSame(0, $stats['opened']);
    }

    public function test_each_cycle_is_bounded_and_deduplicates_due_council_ids(): void
    {
        $this->running();
        for ($index = 0; $index < 10; $index++) $this->scheduled('council-'.$index);
        $this->scheduled('council-0', null, 'v2');
        $this->mock(SpecialistCouncilLifecycleService::class)->shouldReceive('activateDue')
            ->andReturn(['allowed' => false, 'reason_code' => 'ORIGINAL_EVIDENCE_NOT_READY']);
        $stats = app(PaperTradingExecutionService::class)->run();
        $count = count($stats['specialist_adoption']['adoptions']);
        $this->assertGreaterThan(0, $count);
        $this->assertLessThanOrEqual(8, $count);
        $this->assertSame(8, $stats['specialist_adoption']['maximum_councils_per_cycle']);
        $this->assertCount($count, array_unique(array_column($stats['specialist_adoption']['adoptions'], 'council_id')));
        $this->assertSame(10, $stats['specialist_adoption']['due_councils']);
    }

    public function test_corrupt_due_backlog_does_not_starve_councils_outside_the_first_bounded_page(): void
    {
        for ($index = 0; $index < 9; $index++) $this->scheduled('council-'.$index);
        $this->mock(AutonomousModeService::class)->shouldReceive('status')->twice()->with('XAUUSD', 'H1')
            ->andReturn(['state' => 'running', 'enabled' => true]);
        $seen = [];
        $this->mock(SpecialistCouncilLifecycleService::class)->shouldReceive('activateDue')->times(9)
            ->andReturnUsing(function (string $id) use (&$seen): array {
                $seen[] = $id;
                return ['allowed' => false, 'reason_code' => 'ORIGINAL_EVIDENCE_NOT_READY'];
            });
        $first = app(PaperTradingExecutionService::class)->run();
        $this->travelTo(now()->addMinute());
        $second = app(PaperTradingExecutionService::class)->run();
        $this->assertLessThanOrEqual(8, count($first['specialist_adoption']['adoptions']));
        $this->assertLessThanOrEqual(8, count($second['specialist_adoption']['adoptions']));
        $this->assertCount(9, array_unique($seen));
        $this->assertContains('council-8', $seen);
        $this->assertDatabaseCount('paper_orders', 0);
    }

    public function test_forged_scheduled_flag_cannot_bypass_the_actual_native_assessment_owner(): void
    {
        $version = $this->scheduled('unqualified-fixture');
        $this->running();
        // No lifecycle mock: this forged scheduled row has no original sealed
        // manifest, evaluation plan, arm receipts or member paper authority.
        $stats = app(PaperTradingExecutionService::class)->run();
        $this->assertFalse($stats['specialist_adoption']['adoptions'][0]['result']['allowed']);
        $this->assertSame('COUNCIL_ADOPTION_REVALIDATION_FAILED', $stats['specialist_adoption']['adoptions'][0]['result']['reason_code']);
        $this->assertSame('scheduled', $version->fresh()->state);
        $this->assertDatabaseCount('paper_orders', 0);
        $this->assertDatabaseCount('specialist_council_evaluations', 0);
    }

    #[DataProvider('managementFences')]
    public function test_fenced_adoption_still_advances_an_original_owned_management_pin(bool $optIn, ?string $state): void
    {
        config()->set('services.paper.specialist_council_enabled', $optIn);
        if ($state !== null) {
            $this->mock(AutonomousModeService::class)->shouldReceive('status')->once()->with('XAUUSD', 'H1')
                ->andReturn(['state' => $state, 'enabled' => false]);
        }
        $model = ModelVersion::create(['name' => 'owner-fixture', 'strategy' => 'current-unrelated', 'version' => 'v2',
            'generation' => 1, 'status' => 'testing', 'parameters' => ['changed' => true], 'metadata' => [], 'evidence_status' => 'valid']);
        $candidate = ModelMarketPerformance::create(['model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'status' => 'rejected', 'paper_status' => 'failed', 'evidence_status' => 'valid']);
        $signal = PaperSignal::create(['model_market_performance_id' => $candidate->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'candle_time' => now()->subHours(2), 'decision' => 'BUY',
            'price' => 100, 'stop_loss' => 99, 'take_profit' => 102, 'confidence' => 90, 'payload' => [], 'payload_hash' => str_repeat('a', 64)]);
        $binding = ['protocol' => 'specialist_council_binding_v1', 'council_id' => 'old', 'council_version' => 'v1',
            'specialist_id' => 'hour-owner', 'management_version' => 'manage-v1'];
        $account = DB::table('paper_capital_accounts')->insertGetId(['account_key' => 'pin-fixture', 'initial_balance_cents' => 100000,
            'balance_cents' => 100000, 'created_at' => now(), 'updated_at' => now()]);
        $reservation = DB::table('paper_capital_reservations')->insertGetId(['paper_capital_account_id' => $account, 'paper_signal_id' => $signal->id,
            'intent_key' => str_repeat('b', 64), 'owner_id' => 'hour-owner', 'council_id' => 'old', 'council_version' => 'v1',
            'management_version' => 'manage-v1', 'symbol' => 'XAUUSD', 'direction' => 'BUY', 'requested_units_micros' => 10000,
            'capital_cents' => 10000, 'risk_cents' => 100, 'pending_capital_cents' => 0, 'pending_risk_cents' => 0,
            'status' => 'filled', 'binding' => json_encode($binding), 'created_at' => now(), 'updated_at' => now()]);
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'H1')['execution_hash'];
        $management = str_repeat('1', 64);
        $context = ['specialist_council_binding' => $binding,
            'management_request' => ['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy' => 'original-owner', 'parameters' => ['original' => 1.25]],
            'execution_contract' => ['execution_hash' => $execution, 'management_contract' => ['management_hash' => $management]],
            'paper_cost_policy' => ['commission_percent' => .01, 'swap_per_day_percent' => .002]];
        $order = PaperOrder::create(['model_market_performance_id' => $candidate->id, 'paper_signal_id' => $signal->id,
            'broker' => 'simulated', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'direction' => 'BUY', 'units' => 1,
            'entry_price' => 100, 'stop_loss' => 99, 'take_profit' => 102, 'status' => 'open', 'opened_at' => now()->subHours(2),
            'paper_capital_reservation_id' => $reservation, 'owner_id' => 'hour-owner', 'council_id' => 'old', 'council_version' => 'v1',
            'management_version' => 'manage-v1', 'filled_units_micros' => 10000, 'remaining_units_micros' => 10000, 'signal_context' => $context]);
        $this->mock(SpecialistCouncilLifecycleService::class)->shouldReceive('paperBinding')->once()
            ->withArgs(fn ($actual, $symbol, $timeframe, $pin): bool => $actual->id === $model->id && $pin['management_only'] === true
                && $pin['council_version'] === 'v1')->andReturn(['allowed' => true]);
        $this->mock(CandlePayloadService::class)->shouldReceive('candlesForBacktest')->once()->with('XAUUSD', 'H1', 1000)->andReturn([]);
        Http::fake(['*/api/paper/advance-contract' => Http::response(['closed' => false, 'paper_accounting' => [
            'protocol' => 'specialist_paper_accounting_v1', 'entry_price' => 100, 'exit_price' => null, 'exit_time' => null,
            'observed_at' => now()->toIso8601String(), 'holding_days' => 2 / 24, 'partial' => null,
            'commission_percent_round_trip' => .01, 'carry_percent_total' => .002 * 2 / 24,
            'carry_scope' => 'initial_notional_canonical_contract', 'costs_embedded_in_prices' => ['spread' => true, 'slippage' => true],
            'execution_hash' => $execution, 'management_hash' => $management, 'execution_attested' => true, 'management_attested' => true]])]);
        $stats = app(PaperTradingExecutionService::class)->run();
        Http::assertSent(fn ($request): bool => $request['request']['strategy'] === 'original-owner'
            && $request['request']['parameters']['original'] === 1.25);
        $this->assertSame('open', $order->fresh()->status);
        $this->assertSame($context, $order->fresh()->signal_context);
        $this->assertSame('manage-v1', $order->fresh()->management_version);
        $this->assertSame(0, $stats['opened']);
        $this->assertDatabaseCount('paper_orders', 1);
        $this->assertDatabaseCount('paper_fills', 0);
    }

    public static function managementFences(): array
    {
        return [[false, null], [true, 'paused'], [true, 'stopped'], [true, 'safety_halt']];
    }

    private function scheduled(string $councilId, mixed $time = null, string $version = 'v1'): SpecialistCouncilVersion
    {
        return SpecialistCouncilVersion::create(['council_id' => $councilId, 'version' => $version,
            'creator_id' => 'fixture-creator', 'state' => 'scheduled', 'manifest' => ['fixture_only' => true],
            'manifest_hash' => str_repeat('c', 64), 'sealed_at' => now()->subHour(), 'effective_at' => $time ?? now()->subMinute()]);
    }

    private function running(): void
    {
        $this->mock(AutonomousModeService::class)->shouldReceive('status')->once()->with('XAUUSD', 'H1')
            ->andReturn(['state' => 'running', 'enabled' => true]);
    }
}
