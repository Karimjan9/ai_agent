<?php

namespace Tests\Feature;

use App\Models\EvolutionProposal;
use App\Services\LabPopulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class EvolutionGenomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_strategy_lab_run_all_does_not_create_legacy_genome_records(): void
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
        ])->assertRedirect(route('ai-laboratory.show', ['symbol' => 'XAUUSD']));

        $this->assertDatabaseCount('strategy_genomes', 0);
        $this->assertDatabaseCount('fitness_evaluations', 0);
        $this->assertDatabaseCount('genome_crossovers', 0);
        $this->assertDatabaseCount('genome_discoveries', 0);
        $this->assertDatabaseCount('genome_mutations', 0);
        $this->assertDatabaseCount('genome_lineages', 0);
    }

    public function test_legacy_proposal_apply_is_gone_and_does_not_create_a_child_genome(): void
    {
        $proposal = EvolutionProposal::create([
            'strategy' => 'ema_rsi_v1',
            'current_version' => 'v1',
            'proposed_version' => 'v2',
            'current_score' => 40,
            'main_problem' => 'late_entry',
            'reason' => 'Historical proposal.',
            'proposal' => 'Historical proposal.',
            'old_parameters' => ['ema_fast' => 50],
            'new_parameters' => ['ema_fast' => 45],
            'status' => 'pending',
        ]);

        $this->post(route('evolution-proposals.apply', $proposal))
            ->assertStatus(410);

        $this->assertDatabaseHas('evolution_proposals', [
            'id' => $proposal->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseCount('strategy_genomes', 0);
        $this->assertDatabaseCount('genome_mutations', 0);
        $this->assertDatabaseCount('genome_lineages', 0);
    }

    public function test_evolution_lab_is_a_canonical_laboratory_redirect(): void
    {
        $this->get('/evolution-lab')
            ->assertRedirect(route('ai-laboratory.show', ['symbol' => 'XAUUSD']));
    }
}
