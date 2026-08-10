<?php

namespace Tests\Feature;

use App\Models\CandidateGateDecision;
use App\Models\LabEvaluationRun;
use App\Models\LabGateDecisionEvent;
use App\Models\LabLifecycleEvent;
use App\Models\LabCandleDecisionEvent;
use App\Services\CandidateGateDecisionService;
use App\Services\CandidateHandoffService;
use App\Services\AgentConstitutionService;
use App\Services\LabAgentEvaluationService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabPopulationService;
use App\Models\ModelVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImmutableLabEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_constitution_hash_survives_json_numeric_round_trip_and_separates_falsification(): void
    {
        $service = app(AgentConstitutionService::class);
        $constitution = $service->draft('XAUUSD', 'H1', 'hybrid', 'regime_router', [
            'high_volatility_risk_multiplier' => .856,
            'trend_down_risk_multiplier' => 1.0,
        ]);
        $model = ModelVersion::create([
            'name' => 'constitution-round-trip', 'strategy' => 'constitution-round-trip',
            'version' => 'test', 'generation' => 1, 'status' => 'testing', 'parameters' => [],
            'metadata' => [
                'strategy_architecture' => 'regime_router',
                'agent_constitution' => $constitution,
            ],
        ])->fresh();

        $healthy = $service->verify($model, ['pf_attribution' => ['stress_cost' => ['profit_factor' => 1.2]]]);
        $falsified = $service->verify($model, ['pf_attribution' => ['stress_cost' => ['profit_factor' => .4]]]);

        $this->assertTrue($healthy['integrity']);
        $this->assertSame('verified', $healthy['status']);
        $this->assertSame('canonical_v2', $healthy['hash_version']);
        $this->assertTrue($falsified['integrity']);
        $this->assertSame('falsified', $falsified['status']);
        $this->assertTrue($falsified['falsified_by_evidence']);
    }

    public function test_agent_creation_is_recorded_without_replacing_the_projection(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'immutable_creation', true);

        $this->assertSame(20, LabLifecycleEvent::where('lab_generation_id', $generation->id)->where('event_type', 'agent_created')->count());
        $this->assertSame(20, $generation->fresh()->agents->count());
    }

    public function test_repeated_gate_checks_create_revisions_while_projection_remains_one_row(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'immutable_gate', true);
        $agent = $generation->agents->first();
        $result = ['total_trades' => 12, 'profit_factor' => 0.8, 'screening_survival' => ['status' => 'rescue_case', 'reason_codes' => ['FAILED_PROFIT_FACTOR']]];

        app(CandidateGateDecisionService::class)->recordScreening($agent, $result);
        app(CandidateGateDecisionService::class)->recordScreening($agent, [...$result, 'profit_factor' => 0.9]);

        $this->assertSame(1, CandidateGateDecision::where('lab_agent_id', $agent->id)->where('stage', 'screening')->count());
        $this->assertSame(2, LabGateDecisionEvent::where('lab_agent_id', $agent->id)->where('stage', 'screening')->count());
        $this->assertSame([1, 2], LabGateDecisionEvent::where('lab_agent_id', $agent->id)->where('stage', 'screening')->orderBy('revision')->pluck('revision')->all());
    }

    public function test_handoff_retries_are_not_collapsed_in_the_evidence_plane(): void
    {
        $generation = app(LabPopulationService::class)->build('EURUSD', 'immutable_handoff', true);
        $agent = $generation->agents->first();
        $handoffs = app(CandidateHandoffService::class);

        $handoffs->record($generation, $agent, 'screened', 'completed', null, ['attempt' => 1]);
        $handoffs->record($generation, $agent, 'screened', 'completed', null, ['attempt' => 2]);

        $this->assertDatabaseCount('candidate_handoff_events', 1);
        $this->assertSame(2, LabLifecycleEvent::where('lab_agent_id', $agent->id)->where('event_type', 'handoff_screened')->count());
    }

    public function test_selection_handoff_projection_refreshes_after_a_retry(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'selection_handoff_retry', true);
        $agent = $generation->agents->first();
        $handoffs = app(CandidateHandoffService::class);

        $handoffs->record($generation, $agent, 'selection_passed', 'not_selected', 'NO_ELIGIBLE_CANDIDATE', [
            'selection_lane' => 'none',
        ]);
        $handoffs->record($generation, $agent, 'selection_passed', 'completed', null, [
            'selection_lane' => 'volume_context',
        ]);

        $projection = \App\Models\CandidateHandoffEvent::where('lab_generation_id', $generation->id)
            ->where('lab_agent_id', $agent->id)->where('stage', 'selection_passed')->first();
        $this->assertSame('completed', $projection->status);
        $this->assertSame('volume_context', data_get($projection->payload, 'selection_lane'));
        $this->assertSame(2, LabLifecycleEvent::where('lab_agent_id', $agent->id)->where('event_type', 'handoff_selection_passed')->count());
    }

    public function test_evaluation_run_keeps_terminal_artifact_and_candle_trace(): void
    {
        $generation = app(LabPopulationService::class)->build('GBPUSD', 'immutable_run', true);
        $agent = $generation->agents->first();
        $ledger = app(LabImmutableEvidenceService::class);
        $run = $ledger->beginRun($agent, 'screening', 'incremental', ['attempt' => 3, 'queue' => 'lab-gbpusd']);
        $ledger->attachRequest($run, ['symbol' => 'GBPUSD', 'candles' => [['time' => '2026-01-01', 'close' => 1.0]]], ['request_id' => 'test-run-1']);
        $ledger->finishRun($run, 'completed', [
            'total_trades' => 1, 'profit_factor' => 1.2, 'trade_ledger_hash' => 'ledger-hash',
            'displayed_trade_count' => 1, 'trades' => [['profit_percent' => 1]],
            'decision_trace' => [[
                'time' => '2026-01-01T00:00:00Z', 'signal' => 'BUY', 'accepted' => false,
                'reason' => 'minimum_confidence', 'market_regime' => 'range',
                'volatility_regime' => 'normal_volatility', 'signal_confidence' => .42,
                'features' => ['adx' => 12], 'state' => ['loss_streak' => 0],
            ]],
        ]);

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseHas('lab_evidence_artifacts', ['run_id' => $run->run_id, 'artifact_type' => 'evaluation_request']);
        $this->assertDatabaseHas('lab_evidence_artifacts', ['run_id' => $run->run_id, 'artifact_type' => 'evaluation_response']);
        $this->assertSame(1, LabCandleDecisionEvent::where('run_id', $run->run_id)->count());
    }

    public function test_projection_is_bounded_and_terminal_run_cannot_be_rewritten(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'immutable_projection', true);
        $agent = $generation->agents->first();
        $ledger = app(LabImmutableEvidenceService::class);
        $full = [
            'total_trades' => 2,
            'trade_ledger_hash' => 'ledger-hash',
            'trade_ledger' => [['profit_percent' => 1], ['profit_percent' => -0.2]],
            'decision_trace' => [['candle_index' => 200, 'action' => 'WAIT', 'features' => ['adx' => 12]]],
        ];

        $projection = $ledger->projectionPayload($full);
        $this->assertArrayNotHasKey('trade_ledger', $projection);
        $this->assertArrayNotHasKey('decision_trace', $projection);
        $this->assertSame(2, data_get($projection, 'observability_manifest.trade_ledger_count'));
        $this->assertSame(1, data_get($projection, 'observability_manifest.decision_trace_count'));

        $run = $ledger->beginRun($agent, 'screening', 'incremental');
        $ledger->finishRun($run, 'completed', $full);
        $originalHash = $run->fresh()->response_hash;
        $ledger->finishRun($run, 'technical_error', ['different' => true]);

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame($originalHash, $run->fresh()->response_hash);
        $this->assertSame(1, LabLifecycleEvent::where('run_id', $run->run_id)->where('event_type', 'evaluation_terminal_duplicate')->count());
        $this->assertDatabaseHas('lab_evidence_artifacts', ['run_id' => $run->run_id, 'artifact_type' => 'trade_ledger']);
    }
}
