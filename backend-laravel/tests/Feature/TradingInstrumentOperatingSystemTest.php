<?php

namespace Tests\Feature;

use App\Models\InstrumentValuePosterior;
use App\Services\TradingInstrumentOperatingSystemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TradingInstrumentOperatingSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_creates_executable_xauusd_playbooks_and_contracts(): void
    {
        $registry = app(TradingInstrumentOperatingSystemService::class);
        $result = $registry->seedDefaults();

        $this->assertGreaterThanOrEqual(20, $result['instruments']->count());
        $this->assertGreaterThanOrEqual(13, $result['playbooks']->count());
        $this->assertDatabaseHas('trading_instruments', ['instrument_key' => 'trend_pullback', 'role' => 'tactic']);
        $this->assertDatabaseHas('trading_instruments', ['instrument_key' => 'high_volatility_firewall', 'is_abstention' => true]);
        $this->assertDatabaseHas('playbook_compositions', ['playbook_key' => 'xauusd_transition_wait_v1']);

        $this->artisan('instruments:seed')
            ->expectsOutputToContain('Trading instrument registry ready:')
            ->assertExitCode(0);
    }

    public function test_router_prefers_a_risk_abstention_in_high_volatility(): void
    {
        $result = app(TradingInstrumentOperatingSystemService::class)->route('XAUUSD', 'M15', [
            'decision_key' => 'instrument-router-high-volatility', 'regime' => 'trend_up', 'm15_regime' => 'trend_up',
            'session' => 'london', 'volatility' => 'high', 'spread_atr_ratio' => .12, 'transition' => false,
        ]);

        $this->assertSame('ABSTAIN', $result['decision']);
        $this->assertSame('HIGH_VOLATILITY_FIREWALL', $result['reason_code']);
        $this->assertSame('xauusd_high_volatility_wait_v1', $result['playbook']->playbook_key);
        $this->assertDatabaseHas('router_decisions', ['decision_key' => 'instrument-router-high-volatility', 'decision' => 'ABSTAIN']);
    }

    public function test_router_fails_closed_when_cost_context_is_missing(): void
    {
        $result = app(TradingInstrumentOperatingSystemService::class)->route('XAUUSD', 'M15', [
            'decision_key' => 'instrument-router-missing-cost', 'regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal',
        ]);

        $this->assertSame('ABSTAIN', $result['decision']);
        $this->assertSame('NO_ELIGIBLE_PLAYBOOK', $result['reason_code']);
    }

    public function test_router_selects_a_conditional_playbook_and_learning_updates_its_posterior(): void
    {
        $registry = app(TradingInstrumentOperatingSystemService::class);
        $context = ['regime' => 'trend_up', 'm15_regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal', 'spread_atr_ratio' => .08, 'transition' => false];
        $route = $registry->route('XAUUSD', 'M15', [...$context, 'decision_key' => 'instrument-router-trend']);

        $this->assertSame('TRADE', $route['decision']);
        $this->assertSame('xauusd_trend_pullback_v1', $route['playbook']->playbook_key);

        foreach (range(1, 5) as $i) {
            $posterior = $registry->recordEvidence('trend_pullback', 'XAUUSD', 'M15', $context, [
                'evidence_key' => "trend-pullback-evidence-{$i}", 'source_type' => 'paired_control', 'source_key' => "control-{$i}",
                'metrics' => ['net_edge' => .60, 'cost_penalty' => .10, 'drawdown_penalty' => .05, 'survival_value' => .10, 'regime_coverage_value' => .05, 'incremental_lift' => .05],
                'control_metrics' => ['net_edge' => .1], 'control_contract' => ['paired_isolated' => true],
            ]);
        }

        $this->assertSame(5, $posterior->observations);
        $this->assertSame('confirmed', $posterior->decay_state);
        $this->assertGreaterThan(.5, (float) $posterior->net_value);
        $this->assertSame(1, InstrumentValuePosterior::query()->count());

        $replayed = $registry->recordEvidence('trend_pullback', 'XAUUSD', 'M15', $context, [
            'evidence_key' => 'trend-pullback-evidence-5', 'metrics' => ['net_edge' => -10], 'control_metrics' => ['net_edge' => .1], 'control_contract' => ['paired_isolated' => true],
        ]);
        $this->assertSame(5, $replayed->observations);

        $playbook = $registry->recordPlaybookEvidence('xauusd_trend_pullback_v1', 'XAUUSD', 'M15', $context, [
            'metrics' => ['net_edge' => .4], 'control_metrics' => ['net_edge' => .1], 'control_contract' => ['paired_isolated' => true],
        ]);
        $this->assertSame(1, $playbook->observations);
    }
}
