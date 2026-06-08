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

    public function test_evolution_proposals_index_lists_proposals(): void
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

        $response = $this->get('/evolution-proposals');

        $response->assertOk()
            ->assertSee('Evolution Proposals')
            ->assertSee('BREAKOUT_V1')
            ->assertSee('false_breakout')
            ->assertSee('pending');
    }

    public function test_evolution_proposal_show_can_approve_apply_and_create_next_model_version(): void
    {
        $trainingSession = TrainingSession::create([
            'title' => 'Training Session Test',
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

        $this->post(route('evolution-proposals.approve', $proposal))
            ->assertRedirect();

        $this->assertDatabaseHas('evolution_proposals', [
            'id' => $proposal->id,
            'status' => 'approved',
        ]);

        $this->post(route('evolution-proposals.apply', $proposal->fresh()))
            ->assertRedirect(route('model-versions.index'));

        $this->assertDatabaseHas('model_versions', [
            'name' => 'breakout_v2',
            'strategy' => 'breakout_v2',
            'version' => 'v2',
            'generation' => 2,
            'status' => 'testing',
        ]);

        $this->assertDatabaseHas('evolution_proposals', [
            'id' => $proposal->id,
            'status' => 'applied',
        ]);
    }

    public function test_evolution_proposal_can_be_rejected(): void
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
            ->assertRedirect();

        $this->assertDatabaseHas('evolution_proposals', [
            'id' => $proposal->id,
            'status' => 'rejected',
        ]);
    }
}
