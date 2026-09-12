<?php

namespace Tests\Feature;

use App\Models\InstrumentValuePosterior;
use App\Models\PlaybookComposition;
use App\Models\PlaybookValuePosterior;
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

    public function test_xauusd_storage_timeframe_routes_into_one_m15_instrument_decision_layer(): void
    {
        $registry = app(TradingInstrumentOperatingSystemService::class);

        $this->assertTrue($registry->supports('XAUUSD', 'H1'));
        $result = $registry->route('XAUUSD', 'H1', [
            'decision_key' => 'xauusd-one-organism', 'regime' => 'trend_up',
            'session' => 'london', 'volatility' => 'normal', 'spread_atr_ratio' => .08,
        ]);

        $this->assertSame('M15', $result['timeframe']);
        $this->assertSame('H1', data_get($result, 'router_decision.metadata.requested_timeframe'));
        $this->assertSame('xauusd_single_organism', data_get($result, 'router_decision.metadata.organism_scope'));
    }

    public function test_router_selects_a_conditional_playbook_and_learning_updates_its_posterior(): void
    {
        $registry = app(TradingInstrumentOperatingSystemService::class);
        $context = ['regime' => 'trend_up', 'm15_regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal', 'spread_atr_ratio' => .08, 'transition' => false, 'direction' => 'BUY', 'strategy_family' => 'hybrid'];
        $route = $registry->route('XAUUSD', 'M15', [...$context, 'decision_key' => 'instrument-router-trend']);

        $this->assertSame('TRADE', $route['decision']);
        $this->assertSame('xauusd_trend_pullback_v1', $route['playbook']->playbook_key);

        foreach (range(1, 5) as $i) {
            $posterior = $registry->recordEvidence('trend_pullback', 'XAUUSD', 'M15', $context, [
                'evidence_key' => "trend-pullback-evidence-{$i}", 'source_type' => 'paired_control', 'source_key' => "control-{$i}",
                'independent_window_key' => "window-{$i}",
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

    public function test_paper_router_requires_an_exact_confirmed_bundle_and_emits_audit_receipt(): void
    {
        $registry = app(TradingInstrumentOperatingSystemService::class);
        $context = [
            'regime' => 'trend_up', 'm15_regime' => 'trend_up', 'session' => 'london',
            'volatility' => 'normal', 'spread_atr_ratio' => .08, 'transition' => false,
            'strategy_family' => 'hybrid', 'direction' => 'BUY',
        ];
        $cold = $registry->route('XAUUSD', 'M15', [...$context, 'routing_mode' => 'paper', 'decision_key' => 'paper-cold']);
        $this->assertSame('ABSTAIN', $cold['decision']);
        $this->assertSame('NO_CONFIRMED_CONTEXTUAL_PLAYBOOK', $cold['reason_code']);

        foreach (range(1, 3) as $i) {
            $bundle = $registry->recordPlaybookEvidence('xauusd_trend_pullback_v1', 'XAUUSD', 'M15', $context, [
                'evidence_key' => "paper-bundle-{$i}", 'source_key' => "window-{$i}",
                'independent_window_key' => "window-{$i}",
                'metrics' => ['net_edge' => .04, 'drawdown_penalty' => 0],
                'control_metrics' => ['net_edge' => 0],
                'control_contract' => ['paired_isolated' => true],
            ]);
        }
        $this->assertSame('confirmed', $bundle->decay_state);

        $route = $registry->route('XAUUSD', 'M15', [...$context, 'routing_mode' => 'paper', 'decision_key' => 'paper-confirmed']);
        $this->assertSame('TRADE', $route['decision']);
        $this->assertSame('xauusd_trend_pullback_v1', $route['playbook']->playbook_key);
        $this->assertSame('exact', data_get($route, 'candidate.bundle_posterior.scope'));
        $this->assertTrue(data_get($route, 'candidate.selection_eligible'));
        $this->assertNotEmpty($route['alternatives']);
        $this->assertSame('contextual_lower_bound_with_explicit_abstention', data_get($route, 'router_decision.metadata.selection_rule'));
    }

    public function test_hierarchical_context_is_a_research_prior_not_paper_authority(): void
    {
        $registry = app(TradingInstrumentOperatingSystemService::class);
        $registry->seedDefaults();
        $london = [
            'regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal',
            'spread_atr_ratio' => .08, 'strategy_family' => 'hybrid', 'direction' => 'BUY',
        ];
        foreach (range(1, 3) as $i) {
            $registry->recordPlaybookEvidence('xauusd_trend_pullback_v1', 'XAUUSD', 'M15', $london, [
                'evidence_key' => "london-only-{$i}", 'independent_window_key' => "london-window-{$i}",
                'metrics' => ['net_edge' => .05], 'control_metrics' => ['net_edge' => 0],
                'control_contract' => ['paired_isolated' => true],
            ]);
        }

        $asia = $registry->route('XAUUSD', 'M15', [
            ...$london, 'session' => 'asia', 'routing_mode' => 'paper', 'decision_key' => 'asia-prior-only',
        ]);

        $this->assertSame('ABSTAIN', $asia['decision']);
        $trend = collect($asia['candidates'])->firstWhere('playbook_key', 'xauusd_trend_pullback_v1');
        $this->assertTrue((bool) data_get($trend, 'bundle_posterior.backoff_used'));
        $this->assertContains('HIERARCHICAL_PRIOR_NOT_EXECUTION_AUTHORITY', $trend['rejected_reasons']);
    }

    public function test_status_only_confirmed_bundle_is_quarantined_from_paper_selection(): void
    {
        $registry = app(TradingInstrumentOperatingSystemService::class);
        $registry->seedDefaults();
        $playbook = PlaybookComposition::query()->where('playbook_key', 'xauusd_trend_pullback_v1')->firstOrFail();
        $stateKey = 'trend_up|london|normal|normal|stable|0|buy|hybrid';
        PlaybookValuePosterior::create([
            'playbook_composition_id' => $playbook->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M15',
            'state_key' => $stateKey, 'observations' => 10, 'net_value' => .5,
            'uncertainty' => .01, 'decay_state' => 'confirmed', 'value_vector' => [],
        ]);

        $route = $registry->route('XAUUSD', 'M15', [
            'regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal',
            'spread_atr_ratio' => .08, 'strategy_family' => 'hybrid', 'direction' => 'BUY',
            'routing_mode' => 'paper', 'decision_key' => 'status-only-confirmed',
        ]);

        $this->assertSame('ABSTAIN', $route['decision']);
        $trend = collect($route['candidates'])->firstWhere('playbook_key', 'xauusd_trend_pullback_v1');
        $this->assertSame('status_only_quarantined', data_get($trend, 'bundle_posterior.decay_state'));
        $this->assertContains('BUNDLE_NOT_CONFIRMED', $trend['rejected_reasons']);
    }
}
