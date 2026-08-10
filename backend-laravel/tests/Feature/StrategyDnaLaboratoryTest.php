<?php

namespace Tests\Feature;

use App\Models\TrainingSession;
use App\Services\AgentEvolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StrategyDnaLaboratoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_strategy_dna_profiles_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('strategy_dna_profiles'));
        $this->assertTrue(Schema::hasColumns('strategy_dna_profiles', [
            'strategy_score_id',
            'aggression_score',
            'trend_dependency',
            'range_dependency',
            'volatility_sensitivity',
            'adaptability_score',
            'recovery_score',
            'survival_score',
            'dna_summary',
        ]));
    }

    public function test_dna_laboratory_page_redirects_to_canonical_laboratory(): void
    {
        $this->get(route('strategy-lab.dna-laboratory'))
            ->assertRedirect(route('ai-laboratory.show', ['symbol' => 'XAUUSD']));
    }

    public function test_legacy_evolution_service_is_read_only(): void
    {
        $session = TrainingSession::create([
            'title' => 'Historical DNA Session',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'agents_count' => 1,
            'worst_strategy' => 'ema_rsi_v8',
            'worst_score' => 35,
            'status' => 'completed',
        ]);

        $this->assertNull(app(AgentEvolutionService::class)->createProposalFromSession($session));
        $this->assertDatabaseCount('evolution_proposals', 0);
        $this->assertDatabaseCount('strategy_genomes', 0);
    }
}
