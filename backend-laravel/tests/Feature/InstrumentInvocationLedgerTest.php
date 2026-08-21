<?php

namespace Tests\Feature;

use App\Models\InstrumentInvocationLedger;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\PaperOrder;
use App\Models\PaperSignal;
use App\Models\PaperSignalOutcome;
use App\Services\InstrumentInvocationLedgerService;
use App\Services\InstrumentPolicyRouterService;
use App\Services\TradingInstrumentOperatingSystemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstrumentInvocationLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_exposes_a_sparse_bundle_and_complete_tool_cards(): void
    {
        $route = app(InstrumentPolicyRouterService::class)->route('XAUUSD', 'M15', [
            'decision_key' => 'sparse-instrument-bundle', 'regime' => 'trend_up', 'm15_regime' => 'trend_up',
            'session' => 'london', 'volatility' => 'normal', 'spread_atr_ratio' => .08,
        ]);

        $this->assertSame('TRADE', $route['decision']);
        $this->assertTrue($route['instrument_bundle']['sparse_activation']);
        $this->assertGreaterThanOrEqual(3, $route['instrument_bundle']['activation_count']);
        $this->assertLessThanOrEqual(6, $route['instrument_bundle']['activation_count']);
        $this->assertContains($route['instrument_bundle']['selected_lane'], ['exploit', 'repair', 'discovery']);
        $this->assertSame(['exploit' => .70, 'repair' => .20, 'discovery' => .10], $route['instrument_bundle']['allocation']);
        $card = app(TradingInstrumentOperatingSystemService::class)->seedDefaults()['instruments']->firstWhere('instrument_key', 'trend_pullback')->definition;
        foreach (['preconditions', 'outputs', 'usable_by', 'failure_conditions', 'mutation_surface', 'learning_question'] as $field) {
            $this->assertArrayHasKey($field, $card);
        }
    }

    public function test_selected_instruments_are_immutably_ledgered_and_settled_against_control(): void
    {
        app(TradingInstrumentOperatingSystemService::class)->seedDefaults();
        $model = ModelVersion::create(['name' => 'instrument-ledger', 'strategy' => 'trend', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => []]);
        $performance = ModelMarketPerformance::create(['model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M15', 'strategy_family' => 'trend', 'status' => 'forward_validated', 'paper_status' => 'pending']);
        $payload = [
            'instrument_bundle' => ['keys' => ['trend_pullback', 'atr_risk_envelope', 'cost_aware_exit']],
            'trading_instrument_router' => ['state' => ['state_key' => 'trend_up|london|normal|normal|stable|0']],
            'instrument_control' => ['control_contract' => ['paired_isolated' => true], 'metrics' => ['profit_percent' => .20]],
        ];
        $signal = PaperSignal::create(['model_market_performance_id' => $performance->id, 'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M15', 'candle_time' => now()->startOfMinute(), 'decision' => 'BUY', 'price' => 3300, 'confidence' => 70, 'payload' => $payload, 'payload_hash' => hash('sha256', json_encode($payload))]);
        $ledger = app(InstrumentInvocationLedgerService::class);

        $this->assertSame(3, $ledger->recordDecision($signal));
        $this->assertDatabaseCount('instrument_invocation_ledger', 3);
        $order = PaperOrder::create(['model_market_performance_id' => $performance->id, 'paper_signal_id' => $signal->id, 'broker' => 'simulated', 'symbol' => 'XAUUSD', 'timeframe' => 'M15', 'direction' => 'BUY', 'units' => 1, 'entry_price' => 3300, 'stop_loss' => 3290, 'take_profit' => 3320, 'exit_price' => 3310, 'profit_percent' => .50, 'status' => 'closed', 'opened_at' => now()->subMinute(), 'closed_at' => now(), 'signal_context' => []]);
        $outcome = PaperSignalOutcome::create(['paper_signal_id' => $signal->id, 'paper_order_id' => $order->id, 'outcome' => 'WIN', 'exit_price' => 3310, 'profit_percent' => .50, 'exit_reason' => 'take_profit', 'payload' => []]);

        $this->assertSame(3, $ledger->settle($order, $outcome));
        $this->assertSame(3, InstrumentInvocationLedger::query()->where('verdict', 'helped')->where('used_in_execution', true)->count());
        $this->assertSame(.3, round((float) InstrumentInvocationLedger::query()->value('causal_contribution'), 1));
    }
}
