<?php

namespace Tests\Feature;

use App\Jobs\ProjectLabCandleDecisionEvents;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabLearningInsight;
use App\Models\ModelVersion;
use App\Services\LabHistoricalLearningService;
use App\Services\LabImmutableEvidenceService;
use App\Services\ResearchPaperEpochContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Synthetic read-model vectors, not native replay or economic qualification. */
class NativeStudyHistoricalProjectionIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_two_study_projection_jobs_are_idempotent_audit_only_and_never_ordinary_advice(): void
    {
        Queue::fake();
        $runs = [];
        foreach (['masked', 'unmasked'] as $arm) {
            $runs[] = $this->projectionRun('purpose', 'minimum_confidence', $arm);
        }
        $this->assertAndExecuteOnlyProjectionJobs($runs);
        $this->assertSame(4, DB::table('lab_candle_decision_events')->count());
        $this->assertSame(4, (int) DB::table('lab_candle_decision_rollups')->sum('event_count'));
        $this->assertSame(2, DB::table('lab_candle_decision_rollups')->count());
        $history = app(LabHistoricalLearningService::class);
        $this->assertSame([], $history->refreshForLab('XAUUSD', 'H1'));
        $this->assertNull($history->latestForFamily('XAUUSD', 'H1', 'hybrid'));
        $this->assertNull($history->confirmedMutationPrior('XAUUSD', 'H1', 'hybrid'));
        $this->assertDatabaseCount('lab_learning_insights', 0);
        $this->assertZeroAuthority();
    }

    #[DataProvider('studyDeclarations')]
    public function test_exact_or_malformed_reserved_study_declarations_cannot_mix_into_ordinary_history(string $declaration): void
    {
        Queue::fake();
        $ordinary = $this->projectionRun('ordinary', 'outside_session');
        $study = $this->projectionRun($declaration, 'minimum_confidence');
        $this->assertAndExecuteOnlyProjectionJobs([$ordinary, $study]);
        $this->assertSame(4, (int) DB::table('lab_candle_decision_rollups')->sum('event_count'));
        $history = app(LabHistoricalLearningService::class);
        $insights = $history->refreshForLab('XAUUSD', 'H1');
        $this->assertCount(1, $insights);
        $insight = $history->latestForFamily('XAUUSD', 'H1', 'hybrid');
        $this->assertNotNull($insight);
        $this->assertSame('volatility_session_stability', data_get($insight->recommended_mutations, 'primary_target'));
        $this->assertSame(2, data_get($insight->metrics, 'candle_event_count'));
        $this->assertSame(1, data_get($insight->metrics, 'agent_count'));
        $this->assertSame([$ordinary->run_id], $insight->source_run_ids);
        $this->assertNotContains($study->run_id, $insight->source_run_ids);
        $this->assertFalse((bool) $insight->causal_prior_allowed);
        $this->assertNull($history->confirmedMutationPrior('XAUUSD', 'H1', 'hybrid'));
        $this->assertZeroAuthority();

        // An older append-only insight is retained, not silently rewritten,
        // but even run-only declarations prevent its future consumption.
        $tainted = LabLearningInsight::create([
            'insight_id' => (string) Str::uuid(), 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'scope_key' => 'global', 'insight_type' => 'failure_profile',
            'evidence_quality' => 'diagnostic_only', 'causal_prior_allowed' => false, 'confidence' => 15,
            'source_hash' => hash('sha256', 'synthetic-tainted-insight-'.$declaration),
            'source_generation_ids' => [], 'source_agent_ids' => [], 'source_run_ids' => [$study->run_id],
            'source_event_ids' => [], 'failure_signature' => [], 'metrics' => [],
            'recommended_mutations' => ['primary_target' => 'trade_frequency'], 'blocked_mutations' => [],
            'conclusion' => 'Synthetic old-source fixture; no economic evidence.', 'generated_at' => now()->addMinute(),
        ]);
        // Generation/carrier declarations need not also set the run marker.
        // Existing canonical source IDs independently fence those old rows.
        $tainted->update(['source_generation_ids' => [$study->lab_generation_id], 'source_agent_ids' => [$study->lab_agent_id]]);
        $before = $tainted->fresh()->getAttributes();
        $this->assertSame($insight->id, $history->latestForFamily('XAUUSD', 'H1', 'hybrid')->id);
        $this->assertSame($before, $tainted->fresh()->getAttributes());
    }

    public static function studyDeclarations(): array
    {
        return array_combine($keys = ['purpose', 'generation_owner', 'malformed_generation_owner',
            'carrier_seed', 'carrier_owner', 'malformed_carrier_owner', 'run_reason', 'request_owner',
            'batch_request_owner', 'response_owner'], array_map(fn ($key): array => [$key], $keys));
    }

    private function assertAndExecuteOnlyProjectionJobs(array $runs): void
    {
        Queue::assertPushed(ProjectLabCandleDecisionEvents::class, count($runs));
        Queue::assertCount(count($runs));
        $jobs = Queue::pushed(ProjectLabCandleDecisionEvents::class);
        $this->assertSame(collect($runs)->pluck('run_id')->sort()->values()->all(), $jobs->pluck('runId')->sort()->values()->all());
        foreach ($jobs as $job) {
            $run = collect($runs)->firstWhere('run_id', $job->runId);
            $this->assertNotNull($run);
            $responseHash = $run->response_hash;
            $job->handle(app(LabImmutableEvidenceService::class));
            $rows = DB::table('lab_candle_decision_events')->where('run_id', $job->runId)->count();
            $rollups = DB::table('lab_candle_decision_rollups')->where('run_id', $job->runId)->count();
            $job->handle(app(LabImmutableEvidenceService::class));
            $this->assertSame($rows, DB::table('lab_candle_decision_events')->where('run_id', $job->runId)->count());
            $this->assertSame($rollups, DB::table('lab_candle_decision_rollups')->where('run_id', $job->runId)->count());
            $this->assertSame($responseHash, $run->fresh()->response_hash);
        }
        Queue::assertCount(count($runs));
    }

    private function assertZeroAuthority(): void
    {
        foreach (['lab_evolution_credit_events', 'lab_mutation_credit_events', 'agent_learning_settlements',
            'research_experiment_work_items', 'candidate_gate_decisions', 'paper_authority_admissions',
            'agent_knowledge_cards', 'agent_memories', 'screening_learning_outbox'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    private function projectionRun(string $declaration, string $rejection, string $arm = 'unmasked'): LabEvaluationRun
    {
        $lab = AiLaboratory::firstOrCreate(['symbol' => 'XAUUSD', 'timeframe' => 'H1'],
            ['name' => 'Synthetic projection boundary', 'strategy_families' => ['hybrid']]);
        $trigger = match ($declaration) {
            'purpose' => ['native_specialist_council_intent' => ['research_purpose' => 'spread_context_study']],
            'generation_owner' => ['native_spread_context_study' => ['protocol' => 'native_spread_context_study_v1']],
            'malformed_generation_owner' => ['native_spread_context_study' => 'UNATTESTED_RESERVED_OWNER'],
            default => [],
        };
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id,
            'generation' => LabGeneration::where('ai_laboratory_id', $lab->id)->count() + 1,
            'trigger_type' => 'synthetic_projection_fixture_only', 'trigger_context' => $trigger, 'status' => 'screened']);
        $metadata = match ($declaration) {
            'carrier_seed' => ['native_specialist_council_seed' => ['research_purpose' => 'spread_context_study']],
            'carrier_owner' => ['native_spread_context_study' => ['protocol' => 'native_spread_context_study_v1', 'arm' => $arm]],
            'malformed_carrier_owner' => ['native_spread_context_study' => 'UNATTESTED_RESERVED_OWNER'],
            default => ['audit_note' => 'The prose native_spread_context_study must not exclude ordinary evidence.'],
        };
        $model = ModelVersion::create(['name' => 'Projection fixture-'.$generation->id, 'strategy' => 'hybrid',
            'version' => 'synthetic_only', 'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => $metadata]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'synthetic_projection_fixture_only', 'lifecycle_status' => 'screened']);
        $evidence = app(LabImmutableEvidenceService::class);
        $run = $evidence->beginRun($agent, 'screening', 'synthetic_projection_fixture_only',
            ['synthetic_fixture_only' => true, 'economic_authority' => false, 'skill_authority' => false, 'promotion_evidence' => false]);
        $request = ['symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'candles' => array_fill(0, 202, ['time' => '2025-10-01T00:00:00Z', 'close' => 2000])];
        if ($declaration === 'request_owner') $request['native_spread_context_study_contract'] = ['protocol' => 'UNATTESTED_RESERVED_OWNER'];
        if ($declaration === 'batch_request_owner') $request['strategies'] = [['native_spread_context_study_contract' => ['protocol' => 'UNATTESTED_RESERVED_OWNER']]];
        $evidence->attachRequest($run, $request);
        $trace = [];
        for ($index = 0; $index < 2; $index++) $trace[] = ['candle_index' => 200 + $index,
            'candle_time' => sprintf('2025-10-01T%02d:00:00Z', $index), 'event_type' => 'signal_evaluation',
            'action' => 'WAIT', 'accepted' => false, 'rejection_code' => $rejection];
        $response = ['total_trades' => 0, 'trade_ledger' => [], 'trades' => [], 'displayed_trade_count' => 0,
            'trade_ledger_hash' => hash('sha256', '[]'), 'decision_trace' => $trace,
            'data_quality' => ['decision_trace' => ['protocol' => 'candle_decision_trace_v1', 'requested' => true,
                'complete' => true, 'event_count' => 2, 'evaluated_candle_count' => 2,
                'trace_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($trace)]]];
        if ($declaration === 'response_owner') $response['native_spread_context_study_receipt'] = ['protocol' => 'UNATTESTED_RESERVED_OWNER'];
        $evidence->finishRun($run, 'completed', $response, $declaration === 'response_owner' ? $response : [], $declaration === 'run_reason'
            ? ['reason_code' => 'NATIVE_SPREAD_CONTEXT_STUDY_RESEARCH_ONLY'] : []);
        return $run->fresh();
    }
}
