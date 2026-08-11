<?php

namespace Tests\Feature;

use App\Models\LabCandleDecisionEvent;
use App\Models\LabGateDecisionEvent;
use App\Models\LabLearningConsumptionEvent;
use App\Services\LabHistoricalLearningService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabPopulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LabHistoricalLearningTest extends TestCase
{
    use RefreshDatabase;

    public function test_immutable_failures_become_a_bounded_learning_target(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'history_seed', true);
        $agent = $generation->agents->first();
        $run = app(LabImmutableEvidenceService::class)->beginRun($agent, 'screening', 'incremental');
        app(LabImmutableEvidenceService::class)->finishRun($run, 'completed', []);

        LabGateDecisionEvent::create([
            'current_decision_id' => null,
            'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id,
            'run_id' => $run->run_id,
            'stage' => 'screening',
            'decision' => 'failed',
            'revision' => 1,
            'attribution_status' => 'agent_scoped',
            'reason_codes' => ['FAILED_CALENDAR_MONTH_SURVIVAL'],
            'metrics' => ['profit_factor' => 1.4],
            'payload' => ['test' => true],
            'recorded_at' => now(),
        ]);
        LabCandleDecisionEvent::create([
            'decision_id' => (string) Str::uuid(),
            'run_id' => $run->run_id,
            'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id,
            'candle_time' => '2026-01-01T00:00:00Z',
            'candle_index' => 200,
            'event_type' => 'signal_evaluation',
            'action' => 'BUY',
            'accepted' => false,
            'rejection_code' => 'regime_transition_wait',
            'market_regime' => 'trend_down',
            'volatility_regime' => 'normal_volatility',
            'confidence' => .8,
            'price' => 2000,
            'features' => ['adx' => 20],
            'state' => ['transition_wait' => true],
            'payload_hash' => hash('sha256', 'history-test'),
            'payload' => ['test' => true],
            'recorded_at' => now(),
        ]);

        $learning = app(LabHistoricalLearningService::class);
        $learning->refreshForLab('XAUUSD', 'H1');
        $insight = $learning->latestForFamily('XAUUSD', 'H1', $agent->strategy_family);
        $this->assertNotNull($insight);

        $this->assertSame('monthly_survival', data_get($insight?->recommended_mutations, 'primary_target'));
        // A completed screening row is still a diagnostic snapshot. Exact
        // causal history requires the full request/response/trace/ledger
        // chain from a full, paper or holdout replay.
        $this->assertSame('snapshot_only', $insight?->evidence_quality);
        $this->assertFalse((bool) $insight?->causal_prior_allowed);
        $this->assertContains('transition_firewall_enabled', data_get($insight?->recommended_mutations, 'keys', []));
    }

    public function test_generation_records_which_history_insights_it_consumed(): void
    {
        $first = app(LabPopulationService::class)->build('EURUSD', 'history_consumption_seed', true);
        $first->update(['status' => 'completed', 'completed_at' => now()]);
        $trendAgent = $first->agents->firstWhere('strategy_family', 'trend') ?? $first->agents->first();
        LabGateDecisionEvent::create([
            'lab_generation_id' => $first->id, 'lab_agent_id' => $trendAgent->id, 'stage' => 'screening',
            'decision' => 'failed', 'revision' => 1, 'attribution_status' => 'agent_scoped',
            'reason_codes' => ['FAILED_TEMPORAL_CHUNK_SURVIVAL'], 'metrics' => [], 'payload' => [], 'recorded_at' => now(),
        ]);
        $learning = app(LabHistoricalLearningService::class);
        $learning->refreshForLab('EURUSD', 'H1');
        $insight = $learning->latestForFamily('EURUSD', 'H1', $trendAgent->strategy_family);
        $this->assertNotNull($insight);

        $next = app(LabPopulationService::class)->build('EURUSD', 'history_consumption_followup', true);

        $this->assertSame(20, LabLearningConsumptionEvent::where('lab_generation_id', $next->id)->count());
        $this->assertGreaterThan(0, LabLearningConsumptionEvent::where('lab_generation_id', $next->id)
            ->where('lab_learning_insight_id', $insight?->id)->count());
        $this->assertTrue($next->agents->every(fn ($agent): bool => data_get($agent->modelVersion->metadata, 'historical_learning.protocol') === LabHistoricalLearningService::PROTOCOL));
    }
}
