<?php

namespace Tests\Feature;

use App\Models\AgentPsychologySnapshot;
use App\Services\LabPopulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AgentMindTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_all_dispatches_canonical_lab_without_agent_mind_artifacts(): void
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

        $this->assertDatabaseCount('agent_psychology_snapshots', 0);
        $this->assertDatabaseCount('agent_self_reflections', 0);
        $this->assertDatabaseCount('agent_memories', 0);
        $this->assertDatabaseCount('agent_reputations', 0);
        $this->assertDatabaseCount('internal_debates', 0);
        $this->assertDatabaseCount('evolution_triggers', 0);
    }

    public function test_agent_mind_dashboard_renders(): void
    {
        AgentPsychologySnapshot::create([
            'strategy' => 'ema_rsi_v1',
            'confidence' => 87,
            'stress' => 22,
            'trust' => 91,
            'adaptation_pressure' => 17,
            'stability' => 81,
            'learning_rate' => 0.12,
            'state' => 'stable',
            'metrics' => [],
        ]);

        $response = $this->get('/agent-mind');

        $response->assertOk()
            ->assertSee('Agent Mind')
            ->assertSee('Psychology')
            ->assertSee('Reputation')
            ->assertSee('Self Reflections')
            ->assertSee('Internal Debate')
            ->assertSee('EMA_RSI_V1');
    }
}
