<?php

namespace Tests\Feature;

use App\Models\EvolutionProposal;
use App\Models\ModelVersion;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvolutionProposalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_evolution_proposals_index_lists_historical_proposals(): void
    {
        $modelVersion = ModelVersion::create([
            'name' => 'breakout_v1',
            'strategy' => 'breakout_v1',
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'parameters' => ['lookback' => 20],
            'metadata' => [],
        ]);

        EvolutionProposal::create([
            'model_version_id' => $modelVersion->id,
            'strategy' => 'breakout_v1',
            'current_version' => 'v1',
            'proposed_version' => 'v2',
            'current_score' => 28,
            'main_problem' => 'false_breakout',
            'reason' => 'Breakout signal too noisy.',
            'proposal' => 'Increase ATR filter.',
            'old_parameters' => ['lookback' => 20],
            'new_parameters' => ['lookback' => 30],
            'status' => 'pending',
        ]);

        $this->get('/evolution-proposals')
            ->assertOk()
            ->assertSee('Evolution Proposals')
            ->assertSee('BREAKOUT_V1')
            ->assertSee('false_breakout')
            ->assertSee('pending');
    }

    public function test_historical_proposal_show_page_is_read_only(): void
    {
        $trainingSession = TrainingSession::create([
            'title' => 'Historical Training Session',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'agents_count' => 1,
            'worst_strategy' => 'breakout_v1',
            'worst_score' => 28,
            'status' => 'completed',
        ]);

        $modelVersion = ModelVersion::create([
            'name' => 'breakout_v1',
            'strategy' => 'breakout_v1',
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'parameters' => ['lookback' => 20, 'atr_multiplier' => 0.2],
            'metadata' => [],
        ]);

        $proposal = EvolutionProposal::create([
            'training_session_id' => $trainingSession->id,
            'model_version_id' => $modelVersion->id,
            'strategy' => 'breakout_v1',
            'current_version' => 'v1',
            'proposed_version' => 'v2',
            'current_score' => 28,
            'main_problem' => 'false_breakout',
            'reason' => 'Breakout agent needs a stronger filter.',
            'proposal' => 'Create breakout v2 with stronger confirmation.',
            'old_parameters' => ['lookback' => 20, 'atr_multiplier' => 0.2],
            'new_parameters' => ['lookback' => 30, 'atr_multiplier' => 0.4],
            'status' => 'pending',
        ]);

        $this->get(route('evolution-proposals.show', $proposal))
            ->assertOk()
            ->assertSee('Evolution Proposal #'.$proposal->id)
            ->assertSee('false_breakout')
            ->assertSee('Create breakout v2');

        $this->post(route('evolution-proposals.approve', $proposal))->assertStatus(410);
        $this->post(route('evolution-proposals.apply', $proposal))->assertStatus(410);
        $this->post(route('evolution-proposals.reject', $proposal))->assertStatus(410);

        $this->assertDatabaseHas('evolution_proposals', [
            'id' => $proposal->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('model_versions', ['name' => 'breakout_v2']);
    }

    public function test_evolution_proposal_mutations_are_gone(): void
    {
        $proposal = EvolutionProposal::create([
            'strategy' => 'ema_rsi_v1',
            'current_version' => 'v1',
            'proposed_version' => 'v2',
            'current_score' => 35,
            'main_problem' => 'late_entry',
            'old_parameters' => [],
            'new_parameters' => ['atr_filter' => true],
            'status' => 'pending',
        ]);

        $this->post(route('evolution-proposals.reject', $proposal))
            ->assertStatus(410);

        $this->assertDatabaseHas('evolution_proposals', [
            'id' => $proposal->id,
            'status' => 'pending',
        ]);
    }
}
