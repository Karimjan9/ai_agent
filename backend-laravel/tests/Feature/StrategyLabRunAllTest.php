<?php

namespace Tests\Feature;

use App\Services\LabPopulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class StrategyLabRunAllTest extends TestCase
{
    use RefreshDatabase;

    public function test_strategy_and_evolution_lab_urls_redirect_to_canonical_laboratory(): void
    {
        $this->get('/strategy-lab')
            ->assertRedirect(route('ai-laboratory.show', ['symbol' => 'XAUUSD']));

        $this->get('/evolution-lab')
            ->assertRedirect(route('ai-laboratory.show', ['symbol' => 'XAUUSD']));
    }

    public function test_strategy_lab_run_all_dispatches_only_the_canonical_lab_command(): void
    {
        $this->mock(LabPopulationService::class, function ($mock): void {
            $mock->shouldReceive('ensureLaboratories')->once();
        });
        Artisan::shouldReceive('call')
            ->once()
            ->with('trading:dispatch-lab', [
                'XAUUSD',
                '--timeframe' => 'H1',
                '--force-generation' => true,
            ])
            ->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('canonical dispatch');

        $this->post('/strategy-lab/run-all', [
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'initial_balance' => 10000,
            'risk_per_trade' => 1,
        ])
            ->assertRedirect(route('ai-laboratory.show', ['symbol' => 'XAUUSD']))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('training_sessions', 0);
        $this->assertDatabaseCount('strategy_scores', 0);
        $this->assertDatabaseCount('evolution_proposals', 0);
        $this->assertDatabaseCount('strategy_genomes', 0);
    }

    public function test_evolve_alias_is_a_safe_noop(): void
    {
        $this->artisan('trading:evolve')->assertOk();

        $this->assertDatabaseCount('evolution_proposals', 0);
        $this->assertDatabaseCount('strategy_genomes', 0);
    }
}
