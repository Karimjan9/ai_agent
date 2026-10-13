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
use App\Services\ScreeningLearningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
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
        $studyAgent = LabAgent::findOrFail($study->lab_agent_id);
        $this->assertTrue(app(LabHistoricalLearningService::class)->withholdsOrdinaryLearning($studyAgent, $study));
        $this->assertFalse(app(ScreeningLearningService::class)->record($studyAgent, $study->modelVersion,
            ['evidence_run_id' => $study->run_id, 'total_trades' => 100, 'profit_factor' => 3], 90));
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
        $base = ['purpose', 'generation_owner', 'malformed_generation_owner',
            'carrier_seed', 'carrier_owner', 'malformed_carrier_owner', 'run_reason', 'request_owner',
            'batch_request_owner', 'response_owner'];
        $keys = [...$base, ...array_map(fn ($key) => 'depth_'.$key, $base),
            'scoped_generation', 'scoped_generation_malformed', 'scoped_carrier', 'scoped_request',
            'method_generation', 'method_generation_malformed', 'method_carrier', 'method_request', 'method_run',
            'scoped_run', 'scoped_run_scalar_id', 'scoped_run_scalar_purpose'];
        return array_combine($keys, array_map(fn ($key): array => [$key], $keys));
    }

    public function test_one_refresh_scans_each_exact_declaration_plane_once_and_next_call_is_fresh(): void
    {
        Queue::fake();
        $ordinary = $this->projectionRun('ordinary', 'outside_session');
        $second = $this->projectionRun('ordinary', 'minimum_confidence');
        $sameFamily = $this->projectionRun('ordinary', 'minimum_confidence');
        $second->agent->update(['strategy_family' => 'trend']);
        $this->assertAndExecuteOnlyProjectionJobs([$ordinary, $second, $sameFamily]);
        $history = app(LabHistoricalLearningService::class);
        $declarations = [];
        DB::listen(function ($query) use (&$declarations): void {
            if (str_starts_with(strtolower($query->sql), 'select ') && ! str_starts_with(strtolower($query->sql), 'select exists')
                && str_contains($query->sql, 'native_spread_context_study')) {
                $declarations[] = $query->sql;
            }
        });

        $insights = $history->refreshForLab('XAUUSD', 'H1');
        $this->assertCount(2, $insights);
        $this->assertSame(['hybrid', 'trend'], collect($insights)->pluck('strategy_family')->sort()->values()->all());
        foreach (['lab_evaluation_runs', 'lab_generations', 'model_versions'] as $table) {
            $this->assertCount(1, array_filter($declarations, fn ($sql) => str_contains($sql, 'from "'.$table.'"')), $table);
        }
        $this->assertCount(3, $declarations); // Families, sourceRevision and both family compilers reuse those exact IDs.
        foreach ($insights as $insight) {
            $this->assertSame($insight->strategy_family === 'hybrid' ? 4 : 2, data_get($insight->metrics, 'candle_event_count'));
            $this->assertSame($insight->strategy_family === 'hybrid' ? 2 : 1, data_get($insight->metrics, 'agent_count'));
            $this->assertFalse((bool) $insight->causal_prior_allowed);
        }
        $oldTrend = collect($insights)->firstWhere('strategy_family', 'trend');
        $oldAttributes = $oldTrend->fresh()->getAttributes();

        // A deliberately malformed declaration added after the original refresh
        // is withheld by the next call on the very same service instance.
        $second->update(['metadata' => [...(array) $second->metadata, 'instrument_method_study' => 'UNATTESTED_RESERVED_OWNER']]);
        $declarations = [];
        $fresh = $history->refreshForLab('XAUUSD', 'H1');
        $this->assertCount(1, $fresh); $this->assertSame('hybrid', $fresh[0]->strategy_family);
        $this->assertCount(3, $declarations);
        $this->assertEqualsCanonicalizing([$ordinary->run_id, $sameFamily->run_id], $fresh[0]->source_run_ids);
        $this->assertNull($history->latestForFamily('XAUUSD', 'H1', 'trend'));
        $this->assertSame($oldAttributes, $oldTrend->fresh()->getAttributes());
        $sameFamily->update(['metadata' => [...(array) $sameFamily->metadata, 'instrument_method_study' => 'UNATTESTED_RESERVED_OWNER']]);
        $declarations = [];
        $last = $history->refreshForLab('XAUUSD', 'H1');
        $this->assertCount(1, $last); $this->assertSame('hybrid', $last[0]->strategy_family);
        $this->assertCount(3, $declarations);
        $this->assertSame(2, data_get($last[0]->metrics, 'candle_event_count'));
        $this->assertSame([$ordinary->run_id], $last[0]->source_run_ids);
        $this->assertZeroAuthority();
    }

    public function test_roster_overflow_is_operational_fail_closed_and_releases_the_refresh_scope(): void
    {
        $lab = AiLaboratory::firstOrCreate(['symbol' => 'XAUUSD', 'timeframe' => 'H1'],
            ['name' => 'Synthetic bounded roster', 'strategy_families' => ['hybrid']]);
        $rows = [];
        for ($i = 1; $i <= LabHistoricalLearningService::MAX_REFRESH_EXCLUSION_IDS + 1; $i++) {
            $rows[] = ['ai_laboratory_id' => $lab->id, 'generation' => $i, 'status' => 'screened',
                'trigger_type' => 'synthetic_budget_fixture_only',
                'trigger_context' => json_encode(['instrument_method_study_execution' => 'UNATTESTED_RESERVED_OWNER'])];
            if (count($rows) === 500) { DB::table('lab_generations')->insert($rows); $rows = []; }
        }
        if ($rows !== []) DB::table('lab_generations')->insert($rows);
        $history = app(LabHistoricalLearningService::class);
        try { $history->refreshForLab('XAUUSD', 'H1'); $this->fail('A truncated exclusion roster must never create advice.'); }
        catch (\RuntimeException $error) { $this->assertSame('LAB_HISTORY_REFRESH_EXCLUSION_BUDGET_EXCEEDED', $error->getMessage()); }
        $property = new \ReflectionProperty($history, 'refreshExclusionRoster'); $property->setAccessible(true);
        $this->assertNull($property->getValue($history));
        $lock = Cache::lock('lab-history-refresh:XAUUSD:H1', 60);
        $this->assertTrue($lock->get()); $lock->release();
        $this->assertDatabaseCount('lab_learning_insights', 0);
        $this->assertZeroAuthority();
        // Only a synthetic fixture row is removed: the same instance must read
        // the now-exactly-bounded roster again, not retain its failed attempt.
        DB::table('lab_generations')->where('ai_laboratory_id', $lab->id)
            ->where('generation', LabHistoricalLearningService::MAX_REFRESH_EXCLUSION_IDS + 1)->delete();
        $this->assertSame([], $history->refreshForLab('XAUUSD', 'H1'));
        $this->assertNull($property->getValue($history));
    }

    /** Conditional own-connection interleaving, not a claim that SQLite emulates production MVCC. */
    public function test_positive_snapshot_withholds_later_carriers_runs_events_and_gates_until_the_next_refresh(): void
    {
        Queue::fake();
        $ordinary = $this->projectionRun('ordinary', 'outside_session');
        $this->assertAndExecuteOnlyProjectionJobs([$ordinary]);
        $history = app(LabHistoricalLearningService::class); $interleaved = false; $laterStudy = null;
        DB::listen(function ($query) use (&$interleaved, &$laterStudy, $ordinary): void {
            if ($interleaved || ! str_contains(strtolower($query->sql), 'select distinct') || ! str_contains($query->sql, 'strategy_family')) return;
            $interleaved = true;
            $laterStudy = $this->projectionRun('method_generation', 'minimum_confidence');
            (new ProjectLabCandleDecisionEvents($laterStudy->run_id))->handle(app(LabImmutableEvidenceService::class));
            $laterRun = app(LabImmutableEvidenceService::class)->beginRun($ordinary->agent, 'screening', 'conditional_later_owned_writer');
            $laterRun->update(['metadata' => ['instrument_method_study' => 'UNATTESTED_RESERVED_OWNER']]);
            $event = \App\Models\LabCandleDecisionEvent::where('run_id', $ordinary->run_id)->firstOrFail()->replicate();
            $event->decision_id = (string) Str::uuid(); $event->run_id = $laterRun->run_id; $event->rejection_code = 'minimum_confidence'; $event->save();
            \App\Models\LabGateDecisionEvent::create(['lab_generation_id' => $ordinary->lab_generation_id,
                'lab_agent_id' => $ordinary->lab_agent_id, 'run_id' => $laterRun->run_id, 'stage' => 'screening',
                'decision' => 'failed', 'revision' => 1, 'attribution_status' => 'agent_scoped',
                'reason_codes' => ['FAILED_CALENDAR_MONTH_SURVIVAL'], 'metrics' => [], 'payload' => [], 'recorded_at' => now()]);
        });
        $insights = $history->refreshForLab('XAUUSD', 'H1');
        $this->assertTrue($interleaved); $this->assertNotNull($laterStudy);
        $this->assertCount(1, $insights);
        $this->assertSame([$ordinary->lab_agent_id], $insights[0]->source_agent_ids);
        $this->assertSame([$ordinary->run_id], $insights[0]->source_run_ids);
        $this->assertSame(2, data_get($insights[0]->metrics, 'candle_event_count'));
        $this->assertSame(0, data_get($insights[0]->metrics, 'latest_gate_count'));
        $this->assertSame('volatility_session_stability', data_get($insights[0]->recommended_mutations, 'primary_target'));
        $before = $insights[0]->fresh()->getAttributes();
        $this->assertSame([], $history->refreshForLab('XAUUSD', 'H1'));
        $this->assertNull($history->latestForFamily('XAUUSD', 'H1', 'hybrid'));
        $this->assertSame($before, $insights[0]->fresh()->getAttributes());
        $this->assertZeroAuthority();
    }

    public function test_same_original_source_retag_before_publication_refuses_and_rolls_back_insight_and_cache(): void
    {
        Queue::fake();
        $ordinary = $this->projectionRun('ordinary', 'outside_session');
        $this->assertAndExecuteOnlyProjectionJobs([$ordinary]);
        $legacyKey = 'lab-history:ordinary-legacy-candle-evidence:pre2026-v3:'.hash('sha256', 'XAUUSD|H1');
        Cache::forget($legacyKey);
        $history = app(LabHistoricalLearningService::class); $retagged = false;
        DB::listen(function ($query) use (&$retagged, $ordinary): void {
            if ($retagged || ! str_contains(strtolower($query->sql), 'select distinct') || ! str_contains($query->sql, 'strategy_family')) return;
            $retagged = true;
            $ordinary->update(['metadata' => [...(array) $ordinary->metadata, 'instrument_method_study' => 'UNATTESTED_RESERVED_OWNER']]);
        });
        try { $history->refreshForLab('XAUUSD', 'H1'); $this->fail('A changed original purpose cannot publish ordinary advice.'); }
        catch (\RuntimeException $error) { $this->assertSame('LAB_HISTORY_REFRESH_ORIGINAL_SOURCE_PURPOSE_CHANGED', $error->getMessage()); }
        $this->assertTrue($retagged);
        $this->assertDatabaseCount('lab_learning_insights', 0);
        $this->assertFalse(Cache::has($legacyKey));
        $this->assertNull(data_get($ordinary->fresh()->metadata, 'instrument_method_study'));
        foreach (['refreshExclusionRoster', 'refreshSourceSnapshot'] as $name) {
            $property = new \ReflectionProperty($history, $name); $property->setAccessible(true);
            $this->assertNull($property->getValue($history));
        }
        $this->assertZeroAuthority();
    }

    public function test_unsupported_snapshot_isolation_is_an_operational_refusal_not_a_strategy_result(): void
    {
        DB::statement('PRAGMA read_uncommitted = 1');
        try {
            try { app(LabHistoricalLearningService::class)->refreshForLab('XAUUSD', 'H1'); $this->fail('Unsupported isolation must not produce alleged snapshot advice.'); }
            catch (\RuntimeException $error) { $this->assertSame('LAB_HISTORY_REFRESH_SNAPSHOT_ISOLATION_REQUIRED', $error->getMessage()); }
        } finally { DB::statement('PRAGMA read_uncommitted = 0'); }
        $this->assertDatabaseCount('lab_learning_insights', 0);
        $this->assertZeroAuthority();
    }

    public function test_legacy_derived_cache_cannot_reuse_tainted_early_rows_when_latest_event_id_did_not_change(): void
    {
        Queue::fake();
        $early = $this->projectionRun('ordinary', 'minimum_confidence');
        $late = $this->projectionRun('ordinary', 'outside_session');
        $this->assertAndExecuteOnlyProjectionJobs([$early, $late]);
        // Only synthetic read-model rollups are removed so the existing raw-
        // evidence cache is exercised. Original run products are not rewritten.
        DB::table('lab_candle_decision_rollups')->whereIn('run_id', [$early->run_id, $late->run_id])->delete();
        $key = 'lab-history:ordinary-legacy-candle-evidence:pre2026-v3:'.hash('sha256', 'XAUUSD|H1');
        Cache::forget($key);
        $history = app(LabHistoricalLearningService::class);
        $first = $history->refreshForLab('XAUUSD', 'H1');
        $this->assertSame(4, data_get($first[0]->metrics, 'candle_event_count'));
        $originalCache = Cache::get($key);
        $this->assertSame('immutable_ordinary_candle_aggregate_v3', $originalCache['protocol']);
        $early->update(['metadata' => [...(array) $early->metadata, 'instrument_method_study' => 'UNATTESTED_RESERVED_OWNER']]);
        $fresh = $history->refreshForLab('XAUUSD', 'H1');
        $freshCache = Cache::get($key);
        $this->assertSame($originalCache['latest_event_id'], $freshCache['latest_event_id']);
        $this->assertNotSame($originalCache['exclusion_source_hash'], $freshCache['exclusion_source_hash']);
        $this->assertSame(2, data_get($fresh[0]->metrics, 'candle_event_count'));
        $this->assertSame([$late->run_id], $fresh[0]->source_run_ids);
        $this->assertSame('volatility_session_stability', data_get($fresh[0]->recommended_mutations, 'primary_target'));
        $this->assertZeroAuthority();
    }

    public function test_direction_prior_memo_is_bound_to_the_current_eligible_agent_membership(): void
    {
        Queue::fake();
        $early = $this->projectionRun('ordinary', 'minimum_confidence');
        $this->projectionRun('ordinary', 'outside_session');
        $history = app(LabHistoricalLearningService::class);
        $this->assertNull($history->confirmedMutationPrior('XAUUSD', 'H1', 'hybrid'));
        $property = new \ReflectionProperty($history, 'mutationPriorCache'); $property->setAccessible(true);
        $memo = $property->getValue($history); $this->assertCount(1, $memo);
        // Conditional stale-cache witness only: no credit, original market proof
        // or useful mutation is forged in SQL by this software cache regression.
        foreach ($memo as &$value) $value = ['parameter_key' => 'STALE_PRIOR_WITNESS', 'confirmation_count' => 99];
        unset($value); $property->setValue($history, $memo);
        $early->update(['metadata' => [...(array) $early->metadata, 'instrument_method_study' => 'UNATTESTED_RESERVED_OWNER']]);
        $this->assertNull($history->confirmedMutationPrior('XAUUSD', 'H1', 'hybrid'));
        $this->assertCount(2, $property->getValue($history));
        $this->assertZeroAuthority();
    }

    public function test_revision_watermark_recompiles_same_max_different_original_agent_and_run_rosters(): void
    {
        Queue::fake();
        $early = $this->projectionRun('ordinary', 'outside_session');
        $middle = $this->projectionRun('ordinary', 'outside_session');
        $latest = $this->projectionRun('ordinary', 'outside_session');
        $this->assertAndExecuteOnlyProjectionJobs([$early, $middle, $latest]);
        $middleMetadata = (array) $middle->metadata;
        $middle->update(['metadata' => [...$middleMetadata, 'instrument_method_study' => 'UNATTESTED_RESERVED_OWNER']]);
        $key = 'lab-history:source-revision:pre2026-v3:'.hash('sha256', 'XAUUSD|H1'); Cache::forget($key);
        // Exercise the real cache branch while retaining explicit conditional
        // SQLite transaction semantics, not alleged production concurrency.
        $history = new class extends LabHistoricalLearningService {
            protected function sourceRevisionCacheEnabled(): bool { return true; }
        };
        $first = $history->refreshForLab('XAUUSD', 'H1'); $originalRevision = Cache::get($key);
        $this->assertIsString($originalRevision);
        $this->assertEqualsCanonicalizing([$early->lab_agent_id, $latest->lab_agent_id], $first[0]->source_agent_ids);
        $middle->update(['metadata' => $middleMetadata]);
        $early->update(['metadata' => [...(array) $early->metadata, 'instrument_method_study' => 'UNATTESTED_RESERVED_OWNER']]);
        $fresh = $history->refreshForLab('XAUUSD', 'H1');
        $this->assertNotSame($originalRevision, Cache::get($key));
        $this->assertEqualsCanonicalizing([$middle->lab_agent_id, $latest->lab_agent_id], $fresh[0]->source_agent_ids);
        $this->assertEqualsCanonicalizing([$middle->run_id, $latest->run_id], $fresh[0]->source_run_ids);
        $this->assertNotSame($first[0]->id, $fresh[0]->id);
        $this->assertSame(2, data_get($first[0]->metrics, 'agent_count'));
        $this->assertSame(2, data_get($fresh[0]->metrics, 'agent_count'));
        $this->assertZeroAuthority();
    }

    public function test_revision_watermark_reuses_unchanged_source_but_recompiles_same_id_completion_and_response_hash(): void
    {
        Queue::fake();
        $run = $this->projectionRun('ordinary', 'outside_session');
        $this->assertAndExecuteOnlyProjectionJobs([$run]);
        $originalResponse = $run->response_hash; $originalUpdated = $run->getRawOriginal('updated_at');
        // Conditional lifecycle projection only; original response/artifact
        // bytes are never changed or relabelled as independent evidence.
        DB::table('lab_evaluation_runs')->where('id', $run->id)->update(['status' => 'running', 'response_hash' => null]);
        $key = 'lab-history:source-revision:pre2026-v3:'.hash('sha256', 'XAUUSD|H1'); Cache::forget($key);
        $history = new class extends LabHistoricalLearningService {
            protected function sourceRevisionCacheEnabled(): bool { return true; }
        };
        $compilations = 0;
        DB::listen(function ($query) use (&$compilations): void {
            if (str_starts_with($query->sql, 'select "id", "run_id", "lab_generation_id"')) $compilations++;
        });
        $history->refreshForLab('XAUUSD', 'H1'); $firstRevision = Cache::get($key);
        $this->assertSame(1, $compilations); $this->assertIsString($firstRevision);
        $compilations = 0; $history->refreshForLab('XAUUSD', 'H1');
        $this->assertSame(0, $compilations); $this->assertSame($firstRevision, Cache::get($key));
        DB::table('lab_evaluation_runs')->where('id', $run->id)->update([
            'status' => 'completed', 'response_hash' => $originalResponse, 'updated_at' => $originalUpdated]);
        $compilations = 0; $history->refreshForLab('XAUUSD', 'H1');
        $this->assertSame(1, $compilations); $this->assertNotSame($firstRevision, Cache::get($key));
        $this->assertSame($originalUpdated, $run->fresh()->getRawOriginal('updated_at'));
        $this->assertZeroAuthority();
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
        $depth = str_starts_with($declaration, 'depth_');
        if ($depth) $declaration = substr($declaration, 6);
        $purpose = $depth ? \App\Services\NativeReachabilityDepthAuditService::PURPOSE : 'spread_context_study';
        $marker = $depth ? \App\Services\NativeReachabilityDepthAuditService::MARKER : 'native_spread_context_study';
        $field = $marker.'_contract'; $receiptField = $marker.'_receipt';
        $protocol = $depth ? \App\Services\NativeReachabilityDepthAuditService::PROTOCOL : 'native_spread_context_study_v1';
        $lab = AiLaboratory::firstOrCreate(['symbol' => 'XAUUSD', 'timeframe' => 'H1'],
            ['name' => 'Synthetic projection boundary', 'strategy_families' => ['hybrid']]);
        $trigger = match ($declaration) {
            'purpose' => ['native_specialist_council_intent' => ['research_purpose' => $purpose]],
            'generation_owner' => [$marker => ['protocol' => $protocol]],
            'malformed_generation_owner' => [$marker => 'UNATTESTED_RESERVED_OWNER'],
            'scoped_generation' => ['scoped_descendant_execution' => ['purpose' => 'independent_scoped_descendant_research']],
            'scoped_generation_malformed' => ['scoped_descendant_execution' => 'UNATTESTED_RESERVED_OWNER'],
            'method_generation' => ['instrument_method_study_execution' => ['study_hash' => str_repeat('a', 64)]],
            'method_generation_malformed' => ['instrument_method_study_execution' => 'UNATTESTED_RESERVED_OWNER'],
            default => [],
        };
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id,
            'generation' => LabGeneration::where('ai_laboratory_id', $lab->id)->count() + 1,
            'trigger_type' => 'synthetic_projection_fixture_only', 'trigger_context' => $trigger, 'status' => 'screened']);
        $metadata = match ($declaration) {
            'carrier_seed' => ['native_specialist_council_seed' => ['research_purpose' => $purpose]],
            'carrier_owner' => [$marker => ['protocol' => $protocol, 'arm' => $arm]],
            'malformed_carrier_owner' => [$marker => 'UNATTESTED_RESERVED_OWNER'],
            'scoped_carrier' => ['scoped_research_certificate' => 'UNATTESTED_RESERVED_OWNER'],
            'method_carrier' => ['instrument_method_study_execution' => 'UNATTESTED_RESERVED_OWNER'],
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
        if ($declaration === 'request_owner') $request[$field] = ['protocol' => 'UNATTESTED_RESERVED_OWNER'];
        if ($declaration === 'batch_request_owner') $request['strategies'] = [[$field => ['protocol' => 'UNATTESTED_RESERVED_OWNER']]];
        if ($declaration === 'scoped_request') $request['policy_context']['scoped_research_certificate'] = 'UNATTESTED_RESERVED_OWNER';
        if ($declaration === 'method_request') $request['policy_context']['instrument_method_study'] = 'UNATTESTED_RESERVED_OWNER';
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
        if ($declaration === 'response_owner') $response[$receiptField] = ['protocol' => 'UNATTESTED_RESERVED_OWNER'];
        $runMetadata = match ($declaration) {
            'run_reason' => ['reason_code' => $depth ? 'NATIVE_REACHABILITY_DEPTH_AUDIT_RESEARCH_ONLY' : 'NATIVE_SPREAD_CONTEXT_STUDY_RESEARCH_ONLY'],
            'scoped_run' => ['scoped_research' => 'UNATTESTED_RESERVED_OWNER'],
            'method_run' => ['instrument_method_study_execution' => 'UNATTESTED_RESERVED_OWNER'],
            'scoped_run_scalar_id' => ['scoped_certificate_id' => 1],
            'scoped_run_scalar_purpose' => ['scoped_native_purpose' => 'independent_scoped_component_research'],
            default => [],
        };
        $evidence->finishRun($run, 'completed', $response, $declaration === 'response_owner' ? $response : [], $runMetadata);
        return $run->fresh();
    }
}
