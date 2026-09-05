<?php

namespace Tests\Feature;

use App\Models\OpportunityFunnelEntry;
use App\Services\CompositionAuthorityKernelService;
use App\Services\EvidenceOrthogonalityService;
use App\Services\InvalidationTargetModelLibraryService;
use App\Services\OpportunityFunnelLedgerService;
use App\Services\RiskHysteresisControllerService;
use App\Services\SessionNewsStateMachineService;
use App\Services\TradePathLaboratoryService;
use App\Services\TradingCognitiveStackService;
use App\Services\WinnerOnlyPyramidingLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CausalEdgeAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejected_opportunities_are_retained_and_filter_quality_is_measurable(): void
    {
        $ledger = app(OpportunityFunnelLedgerService::class);
        $ledger->record(['opportunity_key' => 'harmful-reject', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'checks' => ['setup_detected' => true, 'regime_allowed' => ['passed' => false, 'rejected_reason' => 'REGIME_REJECTED']], 'shadow_outcome' => ['net_r' => -1.1]]);
        $ledger->record(['opportunity_key' => 'beneficial-reject', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'checks' => ['setup_detected' => true, 'regime_allowed' => ['passed' => false, 'rejected_reason' => 'REGIME_REJECTED']], 'shadow_outcome' => ['net_r' => .8]]);
        $ledger->record(['opportunity_key' => 'executed-winner', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'checks' => array_fill_keys(OpportunityFunnelLedgerService::STAGES, true), 'shadow_outcome' => ['net_r' => 1.4]]);

        $metrics = $ledger->metrics('XAUUSD', 'H1');
        $this->assertSame(3, OpportunityFunnelEntry::query()->count());
        $this->assertSame(2, $metrics['rejected']);
        $this->assertSame(.5, $metrics['filter_precision']);
        $this->assertSame(.5, $metrics['filter_regret']);
        $this->assertSame(.5, $metrics['capture_rate']);
    }

    public function test_only_independent_information_families_count_as_confluence(): void
    {
        $contract = app(EvidenceOrthogonalityService::class)->assess(['rsi_14', 'macd', 'stochastic', 'h1_ema_direction', 'm15_bos', 'm5_liquidity_sweep', 'm1_engulfing_entry']);

        $this->assertSame(7, $contract['raw_confirmations']);
        $this->assertSame(5, $contract['effective_confluence']);
        $this->assertSame(2, $contract['redundancy_penalty']);
    }

    public function test_passport_freezes_causal_ownership_models_and_fail_closed_location_contract(): void
    {
        $passport = app(CompositionAuthorityKernelService::class)->freeze([
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_id' => 'str_001_ema_adx_pullback',
            'data_contract' => ['m5_canonical' => true, 'm1_execution' => true, 'spread_history' => true, 'slippage_model' => true, 'latency_model' => true, 'deterministic_aggregation' => true, 'gap_audit' => true],
            'location_context' => ['location_available' => false], 'horizon_mode' => 'day_structure',
        ]);

        $this->assertSame('day_structure', $passport['horizon_mode']);
        $this->assertSame('H1', $passport['temporal_owners']['owners']['direction_owner']);
        $this->assertFalse($passport['location_thesis']['trigger_admissible']);
        $this->assertSame('structure_stop', $passport['invalidation_target_contract']['invalidation_model']);
        $this->assertSame('H1_liquidity_target', $passport['invalidation_target_contract']['target_model']);
        $this->assertSame('normal', $passport['news_state']);
        $this->assertSame('typed_program_compiled', $passport['typed_program']['status']);
        $this->assertSame('RegimeEvidence', $passport['typed_program']['nodes'][0]['provides']);
        $this->assertSame('ExitPolicy', $passport['typed_program']['nodes'][7]['provides']);
    }

    public function test_news_reentry_risk_hysteresis_and_winner_only_adds_are_constrained(): void
    {
        $news = app(SessionNewsStateMachineService::class)->compile(['news_state' => 'reentry_allowed', 'spread_normal' => true, 'cooldown_elapsed' => true, 'm5_structure_stabilized' => true, 'execution_trigger' => true, 'slippage_estimate' => .1, 'slippage_limit' => .2]);
        $risk = app(RiskHysteresisControllerService::class)->transition('NORMAL', ['consecutive_losses' => 4]);
        $add = app(WinnerOnlyPyramidingLedgerService::class)->authorize(['position_key' => 'p1', 'add_number' => 1, 'initial_risk_limit' => 1, 'open_risk_before' => .7, 'open_risk_after' => .9, 'unrealized_r' => 1.2, 'new_protected_structure' => true, 'funded_by_protected_risk' => true]);

        $this->assertTrue($news['reentry_allowed']);
        $this->assertSame('DEFENSE', $risk['state']);
        $this->assertSame(.25, $risk['risk_multiplier']);
        $this->assertTrue($add['allowed']);
        $this->assertFalse($add['independent_learning_sample']);
    }

    public function test_trade_path_lab_separates_management_value_from_entry_baseline(): void
    {
        $settlement = app(TradePathLaboratoryService::class)->settle(['entry_price' => 2300, 'direction' => 'long'], ['fixed_target' => ['net_r' => 1.0], 'm5_trailing' => ['net_r' => 2.4]], ['symbol' => 'XAUUSD', 'timeframe' => 'M5']);

        $this->assertSame('m5_trailing', $settlement['attribution']['best_path']);
        $this->assertSame(1.4, $settlement['attribution']['management_value']);
        $this->assertTrue($settlement['attribution']['requires_independent_replication']);
    }

    public function test_unknown_invalidation_or_target_is_never_silently_substituted(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(InvalidationTargetModelLibraryService::class)->compile('not_a_stop', 'H1_liquidity_target');
    }

    public function test_typed_program_rejects_strategy_risk_override_before_a_replay_exists(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TYPED_PROGRAM_STRATEGY_RISK_OVERRIDE_FORBIDDEN');
        app(CompositionAuthorityKernelService::class)->freeze([
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_id' => 'str_001_ema_adx_pullback',
            'strategy_override_allowed' => true,
        ]);
    }

    public function test_foundry_composition_cannot_trigger_without_a_location_thesis(): void
    {
        $plan = app(TradingCognitiveStackService::class)->plan('XAUUSD', 'M15', [
            'regime' => 'trend_up', 'm15_regime' => 'trend_up', 'volatility' => 'normal', 'spread_atr_ratio' => .1, 'feed_healthy' => true,
        ], ['strategy_id' => 'fibonacci_structure_pullback', 'composition_passport' => ['protocol' => CompositionAuthorityKernelService::PROTOCOL, 'composition_id' => 'test-foundry']]);

        $this->assertSame('WAIT', $plan['decision']);
        $this->assertContains('LOCATION_UNRESOLVED', $plan['reason_codes']);
        $this->assertTrue($plan['causal_edge_accounting']['opportunity_funnel']['shadow_required']);
    }
}
