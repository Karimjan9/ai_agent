<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\InstrumentValuePosterior;
use App\Models\TradingInstrument;
use App\Services\ContextualCouncilAllocatorService;
use App\Services\TradingInstrumentOperatingSystemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContextualCouncilAllocatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_twenty_seats_are_dynamic_contextual_exact_pair_hosts(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'XAUUSD Unified MTF Organism',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid', 'trend', 'breakout'],
            'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
        $plan = $this->plan();

        $allocation = app(ContextualCouncilAllocatorService::class)->allocate($plan, $lab, ['exploration_ratio' => .6]);
        $allocated = $allocation['plan'];
        $contract = $allocation['contract'];

        $this->assertCount(20, $allocated);
        $this->assertSame(10, array_sum($contract['pair_quotas']));
        $this->assertTrue($contract['dynamic']);
        $this->assertTrue($contract['pair_integrity']);
        $this->assertFalse(collect($contract['seat_counts'])->every(fn (int $count): bool => $count === 4));
        $this->assertGreaterThanOrEqual(3, collect($contract['cells'])->pluck('session')->unique()->count());

        foreach (array_chunk($allocated, 2) as $pair) {
            $this->assertCount(2, $pair);
            $this->assertSame($pair[0]['research_group'], $pair[1]['research_group']);
            $this->assertSame(
                data_get($pair[0], 'niche.contextual_specialist_cell.cell_hash'),
                data_get($pair[1], 'niche.contextual_specialist_cell.cell_hash'),
            );
            $this->assertSame('WAIT', data_get($pair[0], 'niche.contextual_specialist_cell.outside_scope_action'));
        }
    }

    public function test_session_budget_exploits_local_instrument_value_without_losing_coverage_floor(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'XAUUSD Unified MTF Organism',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid'],
            'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
        app(TradingInstrumentOperatingSystemService::class)->seedDefaults();
        $instrument = TradingInstrument::query()->where('is_abstention', false)->firstOrFail();
        foreach (['asia' => -.9, 'london' => .9, 'new_york' => -.9, 'overlap' => -.9] as $session => $utility) {
            InstrumentValuePosterior::create([
                'trading_instrument_id' => $instrument->id,
                'symbol' => 'XAUUSD',
                'timeframe' => 'M15',
                'state_key' => "trend_up|{$session}|normal|normal|stable|0|both|hybrid",
                'observations' => 20,
                'net_value' => $utility,
                'uncertainty' => .05,
                'decay_state' => 'active',
                'value_vector' => ['conditional_net_utility' => $utility],
                'last_observed_at' => now(),
            ]);
        }

        $contract = app(ContextualCouncilAllocatorService::class)
            ->allocate($this->plan(), $lab, ['exploration_ratio' => .35])['contract'];

        $sessionPairs = (array) $contract['session_pair_counts'];
        $this->assertSame(10, array_sum($sessionPairs));
        $this->assertCount(4, array_filter($sessionPairs, fn (int $pairs): bool => $pairs > 0));
        $this->assertGreaterThan($sessionPairs['asia'], $sessionPairs['london']);
        $this->assertGreaterThan($sessionPairs['new_york'], $sessionPairs['london']);
        $this->assertSame(
            'coverage_floor_then_contextual_ucb_with_local_success_failure_and_instrument_posterior',
            $contract['session_selection_policy'],
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function plan(): array
    {
        $groups = ['monthly_survival', 'regime_coverage', 'volatility_session_stability', 'exit_topology', 'portfolio_router'];
        $genes = ['lookback', 'minimum_signal_confidence', 'atr_stop_multiplier', 'time_stop_candles'];
        $plan = [];
        foreach ($groups as $group) {
            foreach ($genes as $index => $gene) {
                $plan[] = [
                    'origin' => 'g98_council',
                    'family' => 'hybrid',
                    'target' => $group,
                    'research_group' => $group,
                    'niche' => [
                        'protocol' => 'portfolio_council_v1',
                        'declared_gene' => $gene,
                        'declared_value' => $index + 1,
                        'regime' => $index % 2 === 0 ? 'trend_up' : 'range',
                        'volatility' => 'normal_volatility',
                    ],
                ];
            }
        }

        return $plan;
    }
}
