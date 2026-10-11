<?php

namespace Tests\Feature;

use App\Models\LabCandleDecisionEvent;
use App\Models\LabGateDecisionEvent;
use App\Models\LabLearningConsumptionEvent;
use App\Services\LabHistoricalLearningService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabPopulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            'candle_time' => '2025-01-01T00:00:00Z',
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
        // chain from a pre-2026 full research replay.
        $this->assertSame('diagnostic_only', $insight?->evidence_quality);
        $this->assertFalse((bool) $insight?->causal_prior_allowed);
        $this->assertContains('transition_firewall_enabled', data_get($insight?->recommended_mutations, 'keys', []));
    }

    public function test_generation_records_which_history_insights_it_consumed(): void
    {
        $first = app(LabPopulationService::class)->build('XAUUSD', 'history_consumption_seed', true, 'H1');
        $first->update(['status' => 'completed', 'completed_at' => now()]);
        $trendAgent = $first->agents->firstWhere('strategy_family', 'trend') ?? $first->agents->first();
        LabGateDecisionEvent::create([
            'lab_generation_id' => $first->id, 'lab_agent_id' => $trendAgent->id, 'stage' => 'screening',
            'decision' => 'failed', 'revision' => 1, 'attribution_status' => 'agent_scoped',
            'reason_codes' => ['FAILED_TEMPORAL_CHUNK_SURVIVAL'], 'metrics' => [], 'payload' => [], 'recorded_at' => now(),
        ]);
        $learning = app(LabHistoricalLearningService::class);
        $learning->refreshForLab('XAUUSD', 'H1');
        $insight = $learning->latestForFamily('XAUUSD', 'H1', $trendAgent->strategy_family);
        $this->assertNotNull($insight);

        $next = app(LabPopulationService::class)->build('XAUUSD', 'history_consumption_followup', true, 'H1');

        $this->assertSame(20, LabLearningConsumptionEvent::where('lab_generation_id', $next->id)->count());
        $this->assertGreaterThan(0, LabLearningConsumptionEvent::where('lab_generation_id', $next->id)
            ->where('lab_learning_insight_id', $insight?->id)->count());
        $this->assertTrue($next->agents->every(fn ($agent): bool => data_get($agent->modelVersion->metadata, 'historical_learning.protocol') === LabHistoricalLearningService::PROTOCOL));
    }

    public function test_compact_rollup_replaces_its_same_run_raw_subset_without_double_counting(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'rollup_history_seed', true);
        $agent = $generation->agents->first();
        $run = app(LabImmutableEvidenceService::class)->beginRun($agent, 'screening', 'incremental');
        app(LabImmutableEvidenceService::class)->finishRun($run, 'completed', []);

        LabCandleDecisionEvent::create([
            'decision_id' => (string) Str::uuid(),
            'run_id' => $run->run_id,
            'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id,
            'candle_time' => '2025-01-01T00:00:00Z',
            'candle_index' => 1,
            'event_type' => 'signal_evaluation',
            'action' => 'WAIT',
            'accepted' => false,
            'rejection_code' => 'regime_transition_wait',
            'market_regime' => 'transition',
            'volatility_regime' => 'normal_volatility',
            'payload_hash' => hash('sha256', 'rollup-raw-subset'),
            'recorded_at' => now(),
        ]);
        DB::table('lab_candle_decision_rollups')->insert([
            'rollup_key' => hash('sha256', 'rollup-history-test'),
            'run_id' => $run->run_id,
            'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id,
            'bucket_date' => '2025-01-01',
            'first_candle_time' => '2025-01-01 00:00:00',
            'last_candle_time' => '2025-01-01 00:15:00',
            'event_type' => 'signal_evaluation',
            'action' => 'WAIT',
            'accepted' => false,
            'rejection_code' => 'regime_transition_wait',
            'market_regime' => 'transition',
            'volatility_regime' => 'normal_volatility',
            'event_count' => 4,
            'accepted_count' => 0,
            'recorded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $learning = app(LabHistoricalLearningService::class);
        $learning->refreshForLab('XAUUSD', 'H1');
        $insight = $learning->latestForFamily('XAUUSD', 'H1', $agent->strategy_family);

        $this->assertSame(4, data_get($insight?->metrics, 'candle_event_count'));
        $this->assertSame(4, data_get($insight?->metrics, 'rejected_candle_events'));
        $this->assertSame(4, data_get($insight?->failure_signature, 'dominant_rejections.0.occurrences'));

        // Paper observations must not change this research mutation direction,
        // even when their count greatly exceeds the original research sample.
        $paper = (array) DB::table('lab_candle_decision_rollups')->first();
        unset($paper['id']);
        $paper['rollup_key'] = hash('sha256', 'paper-rollup-excluded');
        $paper['run_id'] = 'paper-only-source';
        $paper['bucket_date'] = '2027-01-01';
        $paper['first_candle_time'] = '2027-01-01 00:00:00';
        $paper['last_candle_time'] = '2027-01-01 00:15:00';
        $paper['event_count'] = 1000;
        DB::table('lab_candle_decision_rollups')->insert($paper);
        $event = LabCandleDecisionEvent::firstOrFail()->replicate();
        $event->decision_id = (string) Str::uuid();
        $event->run_id = 'paper-only-source';
        $event->candle_time = '2027-01-01T00:00:00Z';
        $event->save();
        $fresh = app(LabHistoricalLearningService::class);
        $fresh->refreshForLab('XAUUSD', 'H1');
        $insight = $fresh->latestForFamily('XAUUSD', 'H1', $agent->strategy_family);
        $this->assertSame(4, data_get($insight?->metrics, 'candle_event_count'));
        $this->assertSame(4, data_get($insight?->failure_signature, 'dominant_rejections.0.occurrences'));
    }
}
