<?php

namespace Tests\Feature;

use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\PaperOrder;
use App\Models\PaperSignal;
use App\Models\SmartDisciplineDecision;
use App\Services\RiskHysteresisControllerService;
use App\Services\SmartDisciplineEngineService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmartDisciplineEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.discipline.enabled', true);
        config()->set('services.discipline.minimum_reward_risk', 1.0);
        config()->set('services.discipline.late_entry_max_stop_units', .5);
        config()->set('services.discipline.weekly_loss_limit_percent', 99);
        config()->set('services.discipline.max_trades_per_session', 99);
        config()->set('services.discipline.max_trades_per_day', 99);
        config()->set('services.discipline.max_consecutive_losses', 4);
        config()->set('services.discipline.loss_cooldown_minutes', 60);
        config()->set('services.risk.daily_loss_limit_percent', 99);
        config()->set('services.paper.units', 1);
    }

    public function test_clean_entry_is_approved_and_written_to_an_immutable_ledger(): void
    {
        [$candidate, $signal] = $this->candidateAndSignal();

        $plan = app(SmartDisciplineEngineService::class)->assessEntry(
            $candidate,
            $signal,
            $this->executionSignal(),
            $this->contract(),
            CarbonImmutable::now(),
        );

        $this->assertTrue($plan['approved']);
        $this->assertSame('APPROVE', $plan['decision']);
        $this->assertSame('GREEN', $plan['state']);
        $this->assertSame(100.0, $plan['process_adherence_score']);
        $this->assertSame(1.0, $plan['risk_multiplier']);
        $this->assertDatabaseHas('smart_discipline_decisions', [
            'decision_key' => 'pre_trade:'.$signal->id,
            'phase' => 'pre_trade',
            'decision' => 'APPROVE',
        ]);

        $decision = SmartDisciplineDecision::firstOrFail();
        $this->expectException(\LogicException::class);
        $decision->update(['state' => 'RED']);
    }

    public function test_recent_four_loss_streak_triggers_a_temporary_hard_lock(): void
    {
        [$candidate, $signal] = $this->candidateAndSignal();
        $at = CarbonImmutable::parse('2026-08-27 13:00:00', 'UTC');
        foreach (range(1, 4) as $index) {
            $closedAt = $at->subMinutes(9 - $index);
            $this->closedOrder($candidate, -.5, $closedAt);
        }

        $plan = app(SmartDisciplineEngineService::class)->assessEntry(
            $candidate,
            $signal,
            $this->executionSignal(),
            $this->contract(),
            $at,
        );

        $this->assertFalse($plan['approved']);
        $this->assertSame('VETO', $plan['decision']);
        $this->assertSame('LOCKED', $plan['state']);
        $this->assertContains('NO_TRADE_LOSS_STREAK_COOLDOWN', $plan['reason_codes']);
        $this->assertSame(0.0, $plan['risk_multiplier']);
    }

    public function test_directional_late_chase_is_vetoed_in_stop_distance_units(): void
    {
        [$candidate, $signal] = $this->candidateAndSignal();
        $contract = $this->contract(entry: 100.6, stop: 99.0, target: 103.0);
        $executionSignal = $this->executionSignal(entry: 100.6, stop: 99.0, target: 103.0);

        $plan = app(SmartDisciplineEngineService::class)->assessEntry(
            $candidate,
            $signal,
            $executionSignal,
            $contract,
            CarbonImmutable::now(),
        );

        $this->assertSame('VETO', $plan['decision']);
        $this->assertContains('NO_TRADE_LATE_ENTRY_CHASE', $plan['reason_codes']);
        $this->assertEqualsWithDelta(.6, $plan['metrics']['late_entry_stop_units'], .000001);
    }

    public function test_account_loss_and_trade_frequency_limits_are_hard_gates(): void
    {
        [$candidate, $signal] = $this->candidateAndSignal();
        $at = CarbonImmutable::parse('2026-08-27 13:00:00', 'UTC');
        config()->set('services.risk.daily_loss_limit_percent', 1);
        config()->set('services.discipline.max_trades_per_session', 1);
        $this->closedOrder($candidate, -1, $at->subMinute());

        $plan = app(SmartDisciplineEngineService::class)->assessEntry(
            $candidate,
            $signal,
            $this->executionSignal(),
            $this->contract(),
            $at,
        );

        $this->assertSame('VETO', $plan['decision']);
        $this->assertContains('NO_TRADE_DAILY_LOSS_LOCK', $plan['reason_codes']);
        $this->assertContains('NO_TRADE_SESSION_TRADE_LIMIT', $plan['reason_codes']);
    }

    public function test_external_risk_veto_is_part_of_the_same_no_trade_receipt_without_becoming_a_process_violation(): void
    {
        [$candidate, $signal] = $this->candidateAndSignal();

        $plan = app(SmartDisciplineEngineService::class)->assessEntry(
            $candidate,
            $signal,
            $this->executionSignal(),
            $this->contract(),
            CarbonImmutable::now(),
            [
                'risk_sentinel' => ['approved' => true, 'reason_code' => 'CAPPED_FRACTIONAL_RISK_APPROVED'],
                'account_risk' => ['allowed' => false, 'reason_code' => 'NO_TRADE_CORRELATED_RISK'],
            ],
        );

        $this->assertSame('VETO', $plan['decision']);
        $this->assertContains('NO_TRADE_CORRELATED_RISK', $plan['reason_codes']);
        $this->assertFalse($plan['gate_results']['account_risk_authority']['passed']);
        $this->assertSame(100.0, $plan['process_adherence_score']);
        $this->assertEquals(100.0, $plan['metrics']['gate_pass_score']);
        $this->assertSame(64, strlen($plan['metrics']['policy_hash']));

        $report = app(SmartDisciplineEngineService::class)->summary();
        $this->assertSame(1, $report['policy_hashes'][$plan['metrics']['policy_hash']]);
    }

    public function test_disabling_discipline_rules_never_disables_the_higher_risk_authority(): void
    {
        config()->set('services.discipline.enabled', false);
        [$candidate, $signal] = $this->candidateAndSignal();

        $plan = app(SmartDisciplineEngineService::class)->assessEntry(
            $candidate,
            $signal,
            $this->executionSignal(),
            $this->contract(),
            CarbonImmutable::now(),
            ['risk_sentinel' => ['approved' => false, 'reason_code' => 'MAX_DRAWDOWN_REACHED']],
        );

        $this->assertSame('VETO', $plan['decision']);
        $this->assertSame('LOCKED', $plan['state']);
        $this->assertSame(0.0, $plan['risk_multiplier']);
        $this->assertContains('NO_TRADE_RISK_SENTINEL_MAX_DRAWDOWN_REACHED', $plan['reason_codes']);
    }

    public function test_authorized_contract_seals_the_actual_risk_reduced_size_used_by_paper_pnl(): void
    {
        [$candidate, $signal] = $this->candidateAndSignal();
        $engine = app(SmartDisciplineEngineService::class);
        $plan = $engine->assessEntry($candidate, $signal, $this->executionSignal(), $this->contract());
        $plan['risk_multiplier'] = .5;

        $authorized = $engine->authorizeExecutionContract(
            [...$this->contract(), 'position_size_multiple' => .8],
            ['approved' => true, 'position_size_multiple' => .6],
            $plan,
        );

        $this->assertEqualsWithDelta(.3, $authorized['position_size_multiple'], .00000001);
        $this->assertEqualsWithDelta(.3, $authorized['contract']['position_size_multiple'], .00000001);
        $this->assertSame('risk_authorized_execution_contract_v1', data_get($authorized, 'contract.risk_authorization.protocol'));
        $this->assertTrue(data_get($authorized, 'authorization.invariants.sentinel_cap_preserved'));
        $this->assertTrue(data_get($authorized, 'authorization.invariants.strategy_cap_preserved'));
        $this->assertSame(64, strlen((string) data_get($authorized, 'authorization.authorization_hash')));
    }

    public function test_risk_hysteresis_requires_new_settled_evidence_and_can_recover_from_caution(): void
    {
        $controller = app(RiskHysteresisControllerService::class);
        $caution = $controller->persist('XAUUSD', 'H1', [
            'consecutive_losses' => 2,
            'drawdown_velocity' => .01,
            'settled_trades' => 3,
            'after_cost_expectancy' => -.2,
            'regime_aligned' => true,
            'execution_quality_normal' => true,
            'evidence_fingerprint' => hash('sha256', 'loss-window'),
        ]);
        $sameEvidence = $controller->persist('XAUUSD', 'H1', [
            'consecutive_losses' => 0,
            'drawdown_velocity' => 0,
            'settled_trades' => 3,
            'after_cost_expectancy' => .2,
            'regime_aligned' => true,
            'execution_quality_normal' => true,
            'evidence_fingerprint' => hash('sha256', 'loss-window'),
        ]);
        $recovery = $controller->persist('XAUUSD', 'H1', [
            'consecutive_losses' => 0,
            'drawdown_velocity' => 0,
            'settled_trades' => 4,
            'after_cost_expectancy' => .2,
            'regime_aligned' => true,
            'execution_quality_normal' => true,
            'evidence_fingerprint' => hash('sha256', 'first-recovery-win'),
        ]);
        $repeatedRecovery = $controller->persist('XAUUSD', 'H1', [
            'consecutive_losses' => 0,
            'drawdown_velocity' => 0,
            'settled_trades' => 4,
            'after_cost_expectancy' => .2,
            'regime_aligned' => true,
            'execution_quality_normal' => true,
            'evidence_fingerprint' => hash('sha256', 'first-recovery-win'),
        ]);
        $normal = $controller->persist('XAUUSD', 'H1', [
            'consecutive_losses' => 0,
            'drawdown_velocity' => 0,
            'settled_trades' => 5,
            'after_cost_expectancy' => .25,
            'regime_aligned' => true,
            'execution_quality_normal' => true,
            'evidence_fingerprint' => hash('sha256', 'second-recovery-win'),
        ]);

        $this->assertSame('CAUTION', $caution['state']);
        $this->assertSame('CAUTION', $sameEvidence['state']);
        $this->assertSame('RECOVERY', $recovery['state']);
        $this->assertSame('RECOVERY', $repeatedRecovery['state']);
        $this->assertSame('NORMAL', $normal['state']);
    }

    public function test_post_trade_review_separates_good_loss_from_bad_win(): void
    {
        [$candidate, $goodSignal] = $this->candidateAndSignal();
        $engine = app(SmartDisciplineEngineService::class);
        $goodPlan = $engine->assessEntry($candidate, $goodSignal, $this->executionSignal(), $this->contract());
        $goodOrder = $this->reviewableOrder($candidate, $goodSignal, $goodPlan, 'simulated');

        $goodLoss = $engine->reviewOutcome($candidate, $goodOrder, -.5, 'stop_loss', null, $this->managementAudit([
            'realized_r_multiple' => -.5,
            'mfe_r' => .4,
            'mae_r' => 1.0,
            'exit_reason' => 'stop_loss',
        ]));

        $this->assertSame('GOOD_LOSS', $goodLoss['classification']);
        $this->assertTrue($goodLoss['learning_eligible']);
        $this->assertSame(100.0, $goodLoss['process_adherence_score']);

        $badSignal = $this->signal($candidate, CarbonImmutable::now()->subMinute());
        $badPlan = $engine->assessEntry($candidate, $badSignal, $this->executionSignal(), $this->contract());
        $badOrder = $this->reviewableOrder($candidate, $badSignal, $badPlan, 'manual_override');

        $badWin = $engine->reviewOutcome($candidate, $badOrder, .8, 'take_profit', null, $this->managementAudit([
            'realized_r_multiple' => .8,
            'mfe_r' => 1.2,
            'mae_r' => .2,
            'exit_reason' => 'take_profit',
        ]));
        $report = $engine->summary('XAUUSD', 'H1', CarbonImmutable::now()->subDay());

        $this->assertSame('BAD_WIN', $badWin['classification']);
        $this->assertFalse($badWin['learning_eligible']);
        $this->assertContains('MANUAL_OR_UNKNOWN_EXECUTION_OVERRIDE', $badWin['reason_codes']);
        $this->assertContains('execution_error', data_get($badWin, 'metrics.error_taxonomy'));
        $this->assertSame(2, $report['settled_reviews']);
        $this->assertSame(50.0, $report['invalid_trade_rate_percent']);
        $this->assertSame(1, $report['classifications']['GOOD_LOSS']);
        $this->assertSame(1, $report['classifications']['BAD_WIN']);
        $this->assertSame(100.0, $report['management_attestation_rate_percent']);
        $this->assertSame(0.0, $report['stop_widening_rate_percent']);
        $this->assertSame(1, $report['process_error_taxonomy']['execution_error']);
        $this->assertEqualsWithDelta(.15, $report['average_realized_r_multiple'], .0001);
    }

    public function test_stop_widening_turns_a_profitable_outcome_into_a_quarantined_bad_win(): void
    {
        [$candidate, $signal] = $this->candidateAndSignal();
        $engine = app(SmartDisciplineEngineService::class);
        $plan = $engine->assessEntry($candidate, $signal, $this->executionSignal(), $this->contract());
        $order = $this->reviewableOrder($candidate, $signal, $plan, 'simulated');

        $review = $engine->reviewOutcome($candidate, $order, 2.0, 'take_profit', null, $this->managementAudit([
            'contract_followed' => false,
            'final_stop_loss' => 98.0,
            'stop_widened' => true,
            'realized_r_multiple' => 2.0,
        ]));

        $this->assertSame('BAD_WIN', $review['classification']);
        $this->assertFalse($review['learning_eligible']);
        $this->assertContains('STOP_WIDENING_VIOLATION', $review['reason_codes']);
        $this->assertContains('risk_error', data_get($review, 'metrics.error_taxonomy'));
        $this->assertNotContains('UNATTESTED_TRADE_MANAGEMENT', $review['reason_codes']);
        $this->assertSame(90.0, $review['process_adherence_score']);
    }

    public function test_operator_report_command_supports_json_output(): void
    {
        $this->artisan('trading:discipline-report', ['--days' => 30, '--json' => true])
            ->assertSuccessful();
    }

    /** @return array{0:ModelMarketPerformance,1:PaperSignal} */
    private function candidateAndSignal(): array
    {
        $model = ModelVersion::create([
            'name' => 'discipline-'.str()->uuid(),
            'strategy' => 'differential_router',
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'parameters' => [],
            'metadata' => [],
            'evidence_status' => 'valid',
        ]);
        $candidate = ModelMarketPerformance::create([
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'differential_router',
            'status' => 'forward_validated',
            'paper_status' => 'pending',
            'evidence_status' => 'valid',
        ]);

        return [$candidate, $this->signal($candidate, CarbonImmutable::now()->subMinutes(2))];
    }

    private function signal(ModelMarketPerformance $candidate, CarbonImmutable $candleTime): PaperSignal
    {
        $payload = [
            'market_regime' => 'trend_up',
            'mtf_pilot' => ['aligned' => true],
            'trading_cognitive_stack' => [
                'location_thesis' => ['location' => 'discount'],
                'tactic_executor' => ['trigger' => 'breakout_retest'],
            ],
        ];

        return PaperSignal::create([
            'model_market_performance_id' => $candidate->id,
            'model_version_id' => $candidate->model_version_id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'candle_time' => $candleTime,
            'decision' => 'BUY',
            'price' => 100,
            'stop_loss' => 99,
            'take_profit' => 102,
            'confidence' => 75,
            'market_regime' => 'trend_up',
            'volatility_regime' => 'normal_volatility',
            'payload' => $payload,
            'payload_hash' => hash('sha256', json_encode($payload).$candleTime->toIso8601String()),
        ]);
    }

    /** @return array<string, mixed> */
    private function contract(float $entry = 100, float $stop = 99, float $target = 102): array
    {
        return [
            'decision' => 'BUY',
            'entry_price' => $entry,
            'stop_loss' => $stop,
            'take_profit' => $target,
            'position_size_multiple' => .5,
            'management_contract' => ['management_hash' => 'test-management-hash'],
        ];
    }

    /** @return array<string, mixed> */
    private function executionSignal(float $entry = 100, float $stop = 99, float $target = 102): array
    {
        return [
            'signal' => 'BUY',
            'price' => $entry,
            'stop_loss' => $stop,
            'take_profit' => $target,
            'confidence' => 75,
            'market_regime' => 'trend_up',
            'volatility_regime' => 'normal_volatility',
        ];
    }

    private function closedOrder(ModelMarketPerformance $candidate, float $profit, CarbonImmutable $closedAt): PaperOrder
    {
        return PaperOrder::create([
            'model_market_performance_id' => $candidate->id,
            'broker' => 'simulated',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'direction' => 'BUY',
            'units' => .5,
            'entry_price' => 100,
            'stop_loss' => 99,
            'take_profit' => 102,
            'exit_price' => 99,
            'profit_percent' => $profit,
            'status' => 'closed',
            'opened_at' => $closedAt->subMinute(),
            'closed_at' => $closedAt,
            'signal_context' => [],
            'evidence_status' => 'valid',
        ]);
    }

    private function reviewableOrder(ModelMarketPerformance $candidate, PaperSignal $signal, array $plan, string $broker): PaperOrder
    {
        $plan['final_position_size_multiple'] = .5;

        return PaperOrder::create([
            'model_market_performance_id' => $candidate->id,
            'paper_signal_id' => $signal->id,
            'broker' => $broker,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'direction' => 'BUY',
            'units' => .5,
            'entry_price' => 100,
            'stop_loss' => 99,
            'take_profit' => 102,
            'status' => 'open',
            'opened_at' => now(),
            'signal_context' => [
                'signal' => $this->executionSignal(),
                'smart_discipline' => $plan,
                'position_size_multiple' => .5,
                'execution_contract' => $this->contract(),
            ],
            'evidence_status' => 'valid',
        ]);
    }

    /** @return array<string, mixed> */
    private function managementAudit(array $overrides = []): array
    {
        return [
            'protocol' => 'paper_management_audit_v1',
            'management_hash' => 'test-management-hash',
            'management_attested' => true,
            'execution_attested' => true,
            'strategy_attested' => true,
            'contract_followed' => true,
            'initial_stop_loss' => 99.0,
            'final_stop_loss' => 99.0,
            'stop_widened' => false,
            'partial_closed' => false,
            'realized_r_multiple' => 0.0,
            'mfe_r' => 0.0,
            'mae_r' => 0.0,
            ...$overrides,
        ];
    }
}
