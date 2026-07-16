<?php

namespace Tests\Feature;

use App\Models\AgentBelief;
use App\Models\BeliefDecayEvent;
use App\Models\BlindSpot;
use App\Models\KnowledgeAudit;
use App\Models\KnowledgeClaim;
use App\Models\KnowledgeContradiction;
use App\Models\KnowledgeHealthScore;
use App\Models\MarketGenome;
use App\Models\MarketSpecies;
use App\Models\MarketStateSnapshot;
use App\Models\MetaAuditRun;
use App\Models\SelfCritique;
use App\Models\UnknownZone;
use App\Services\MetaIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class MetaIntelligenceEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_audit_creates_health_audits_decay_contradictions_unknowns_and_self_critique(): void
    {
        $this->createMetaEvidence();

        $run = app(MetaIntelligenceService::class)->runAudit();

        $this->assertNotNull($run);
        $this->assertDatabaseHas('meta_audit_runs', [
            'id' => $run->id,
            'status' => 'success',
        ]);
        $this->assertGreaterThan(0, KnowledgeAudit::count());
        $this->assertGreaterThan(0, BeliefDecayEvent::count());
        $this->assertGreaterThan(0, KnowledgeContradiction::count());
        $this->assertGreaterThan(0, UnknownZone::count());
        $this->assertGreaterThan(0, BlindSpot::count());
        $this->assertGreaterThan(0, KnowledgeHealthScore::count());
        $this->assertGreaterThan(0, SelfCritique::count());
    }

    public function test_meta_intelligence_dashboard_and_manual_audit_work(): void
    {
        $this->createMetaEvidence();

        $this->post(route('meta-intelligence.audit'))
            ->assertRedirect(route('meta-intelligence.index'));

        $this->get(route('meta-intelligence.index'))
            ->assertOk()
            ->assertSee('Meta Intelligence')
            ->assertSee('Knowledge Health Score')
            ->assertSee('Contradictions')
            ->assertSee('Unknown Zones')
            ->assertSee('Self Critiques');
    }

    public function test_meta_audit_command_runs(): void
    {
        $this->createMetaEvidence();

        Artisan::call('meta:audit');

        $this->assertGreaterThan(0, MetaAuditRun::count());
        $this->assertStringContainsString('Meta audit', Artisan::output());
    }

    private function createMetaEvidence(): void
    {
        KnowledgeClaim::create([
            'title' => 'ema_rsi_v12 performs better in Fear Expansion',
            'claim' => 'EMA RSI V12 performs better in Fear Expansion with profitable follow-through.',
            'claim_type' => 'strategy_species_performance',
            'confidence_score' => 92,
            'evidence_count' => 20,
            'status' => 'validated',
            'scope' => ['strategy' => 'ema_rsi_v12', 'market_species' => 'Fear Expansion'],
            'metadata' => ['direction' => 'positive'],
            'last_seen_at' => now()->subDays(12),
        ]);

        KnowledgeClaim::create([
            'title' => 'ema_rsi_v12 struggles in Fear Expansion',
            'claim' => 'EMA RSI V12 struggles in Fear Expansion when liquidity collapses.',
            'claim_type' => 'strategy_species_performance',
            'confidence_score' => 80,
            'evidence_count' => 12,
            'status' => 'provisional',
            'scope' => ['strategy' => 'ema_rsi_v12', 'market_species' => 'Fear Expansion'],
            'metadata' => ['direction' => 'negative'],
            'last_seen_at' => now()->subDays(10),
        ]);

        AgentBelief::create([
            'strategy' => 'ema_rsi_v12',
            'belief_key' => 'trend_continuation',
            'belief_label' => 'Trend continuation',
            'score' => 91,
            'sample_size' => 30,
            'confirmed_count' => 16,
            'failed_count' => 14,
            'confidence_interval_low' => 72,
            'confidence_interval_high' => 96,
            'regime' => 'trend_up',
            'last_evidence_at' => now()->subDays(180),
            'evidence_summary' => 'Old evidence with recent failure pressure.',
            'metadata' => [],
        ]);

        $species = MarketSpecies::create([
            'code' => 'SPC_META41',
            'name' => 'Fear Expansion',
            'dominant_state' => 'panic',
            'description' => 'Meta audit test species.',
            'danger_score' => 80,
            'opportunity_score' => 45,
            'signature' => ['market_state' => 'panic'],
        ]);

        $snapshot = MarketStateSnapshot::create([
            'market_species_id' => $species->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'time' => now(),
            'market_state' => 'panic',
            'liquidity_state' => 'low_proxy',
            'momentum_state' => 'strong',
            'structure_state' => 'breakout',
            'confidence_score' => 74,
            'trend_score' => 64,
            'panic_score' => 88,
            'compression_score' => 36,
            'expansion_score' => 79,
            'momentum_score' => 81,
            'liquidity_proxy_score' => 24,
            'features' => ['panic_score' => 88],
            'explanation' => 'Low-similarity test market.',
        ]);

        MarketGenome::create([
            'market_state_snapshot_id' => $snapshot->id,
            'market_species_id' => $species->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'time' => now(),
            'genome_hash' => 'meta-test-genome',
            'vector' => [
                'trend' => 64,
                'panic' => 88,
                'compression' => 36,
                'momentum' => 81,
                'liquidity_proxy' => 24,
            ],
            'trend' => 64,
            'panic' => 88,
            'compression' => 36,
            'momentum' => 81,
            'liquidity_proxy' => 24,
        ]);
    }
}
