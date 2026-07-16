<?php

namespace Tests\Feature;

use App\Models\AgentReputation;
use App\Models\BlindSpot;
use App\Models\CivilizationAgent;
use App\Models\CivilizationGoal;
use App\Models\CivilizationMemory;
use App\Models\CouncilDecision;
use App\Models\CouncilVote;
use App\Models\InstitutionalKnowledge;
use App\Models\KnowledgeClaim;
use App\Models\MetaAuditRun;
use App\Models\UnknownZone;
use App\Services\QuantCivilizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class QuantCivilizationEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_civilization_sync_creates_agents_credits_council_memory_knowledge_and_goals(): void
    {
        $this->createCivilizationEvidence();

        $decision = app(QuantCivilizationService::class)->synchronize();

        $this->assertNotNull($decision);
        $this->assertDatabaseHas('civilization_agents', [
            'agent_key' => 'role:research',
            'role_key' => 'research',
        ]);
        $this->assertDatabaseHas('civilization_agents', [
            'agent_key' => 'strategy:ema_rsi_v12',
            'role_key' => 'strategy_member',
        ]);
        $this->assertGreaterThan(0, CivilizationAgent::sum('credits_balance'));
        $this->assertGreaterThan(0, CouncilDecision::count());
        $this->assertGreaterThan(0, CouncilVote::count());
        $this->assertGreaterThan(0, CivilizationGoal::count());
        $this->assertGreaterThan(0, CivilizationMemory::count());
        $this->assertGreaterThan(0, InstitutionalKnowledge::count());
    }

    public function test_ai_civilization_dashboard_and_manual_sync_work(): void
    {
        $this->createCivilizationEvidence();

        $this->post(route('ai-civilization.sync'))
            ->assertRedirect(route('ai-civilization.index'));

        $this->get(route('ai-civilization.index'))
            ->assertOk()
            ->assertSee('AI Civilization')
            ->assertSee('Agent Society')
            ->assertSee('Council Decisions')
            ->assertSee('Internal Economy')
            ->assertSee('Institutional Knowledge');
    }

    public function test_civilization_sync_command_runs(): void
    {
        $this->createCivilizationEvidence();

        Artisan::call('civilization:sync');

        $this->assertGreaterThan(0, CouncilDecision::count());
        $this->assertStringContainsString('AI Civilization sync completed', Artisan::output());
    }

    private function createCivilizationEvidence(): void
    {
        $audit = MetaAuditRun::create([
            'status' => 'success',
            'started_at' => now()->subMinutes(3),
            'finished_at' => now()->subMinute(),
            'knowledge_health_score' => 72,
            'audited_claims' => 12,
            'decayed_beliefs' => 3,
            'contradictions_found' => 1,
            'unknown_zones_found' => 1,
            'blind_spots_found' => 1,
            'summary' => 'Meta audit found one uncertainty zone.',
            'metrics' => ['test' => true],
        ]);

        KnowledgeClaim::create([
            'title' => 'EMA RSI V12 survives trend markets',
            'claim' => 'EMA RSI V12 performs better in trend markets with low volatility.',
            'claim_type' => 'strategy_species_performance',
            'confidence_score' => 88,
            'evidence_count' => 18,
            'status' => 'validated',
            'scope' => ['strategy' => 'ema_rsi_v12', 'market_state' => 'trend_up'],
            'metadata' => [],
            'last_seen_at' => now(),
        ]);

        AgentReputation::create([
            'strategy' => 'ema_rsi_v12',
            'reputation_score' => 91,
            'stability_score' => 84,
            'trust_score' => 88,
            'calibration_score' => 82,
            'survival_score' => 86,
            'sessions_count' => 14,
            'reasons' => ['Strong survival history.'],
        ]);

        UnknownZone::create([
            'meta_audit_run_id' => $audit->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'market_state' => 'panic',
            'market_species' => 'Fear Expansion',
            'similarity_score' => 18,
            'uncertainty_score' => 86,
            'status' => 'open',
            'reason' => 'Low historical similarity.',
            'evidence' => [],
        ]);

        BlindSpot::create([
            'meta_audit_run_id' => $audit->id,
            'spot_key' => 'low_panic_breakout',
            'label' => 'Low liquidity panic breakout',
            'priority_score' => 91,
            'status' => 'open',
            'reason' => 'Under-sampled condition.',
            'coverage' => ['samples_found' => 1],
            'suggested_research' => ['action' => 'queue_research_ticket'],
        ]);
    }
}
