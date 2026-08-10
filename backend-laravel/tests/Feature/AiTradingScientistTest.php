<?php

namespace Tests\Feature;

use App\Models\AgentBelief;
use App\Services\LabPopulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AiTradingScientistTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_all_dispatches_canonical_lab_without_scientist_artifacts(): void
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

        $this->assertDatabaseCount('agent_hypotheses', 0);
        $this->assertDatabaseCount('agent_beliefs', 0);
        $this->assertDatabaseCount('scientist_journals', 0);
        $this->assertDatabaseCount('knowledge_facts', 0);
        $this->assertDatabaseCount('counterfactual_runs', 0);
    }

    public function test_ai_scientist_dashboard_renders_scientific_memory(): void
    {
        AgentBelief::create([
            'strategy' => 'ema_rsi_v1',
            'belief_key' => 'trend_following',
            'belief_label' => 'Trend following edge',
            'score' => 82,
            'sample_size' => 20,
        ]);

        $response = $this->get('/ai-scientist');

        $response->assertOk()
            ->assertSee('AI Scientist')
            ->assertSee('Hypotheses')
            ->assertSee('Beliefs')
            ->assertSee('Scientist Journals')
            ->assertSee('Knowledge Base')
            ->assertSee('Counterfactuals')
            ->assertSee('Trend following edge');
    }
}
