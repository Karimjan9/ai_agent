<?php

namespace Tests\Feature;

use App\Models\CandidateGateDecision;
use App\Models\LabEvaluationRun;
use App\Models\LabGateDecisionEvent;
use App\Models\LabLifecycleEvent;
use App\Models\LabCandleDecisionEvent;
use App\Models\LabMutationCreditEvent;
use App\Models\ModelMarketPerformance;
use App\Models\MutationMemory;
use App\Services\CandidateGateDecisionService;
use App\Services\CandidateHandoffService;
use App\Services\AgentConstitutionService;
use App\Services\LabAgentEvaluationService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabPopulationService;
use App\Services\IncompleteLabEvidenceRecoveryService;
use App\Models\ModelVersion;
use App\Jobs\EvaluateLabAgentJob;
use App\Jobs\Middleware\LabMutexEvidenceMiddleware;
use App\Jobs\Middleware\LabQueueAttemptEvidenceMiddleware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\MaxAttemptsExceededException;
use Tests\TestCase;

class ImmutableLabEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_bounded_screen_failure_quarantines_only_the_current_agent_and_closes_generation(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'bounded_timeout_quarantine', true, 'H1');
        $generation->update(['status' => 'screening', 'completed_at' => null]);
        $agent = $generation->agents->first();
        $generation->agents()->where('id', '<>', $agent->id)->update(['lifecycle_status' => 'screened']);
        $agent->update([
            'lifecycle_status' => 'screening',
            'decision_reason' => 'replay running',
        ]);
        $agent->modelVersion()->update(['metadata' => ['evaluator_recovery_attempts' => 1]]);

        $job = new EvaluateLabAgentJob($agent->id, $agent->symbol, 'screen');
        $method = new \ReflectionMethod($job, 'markEvaluationError');
        $method->setAccessible(true);
        $method->invoke($job, $agent->fresh(['modelVersion']), new MaxAttemptsExceededException('bounded replay exhausted'));

        $this->assertSame('technical_quarantine', $agent->fresh()->lifecycle_status);
        $this->assertSame('technical_quarantine', $generation->fresh()->status);
        $this->assertDatabaseHas('candidate_handoff_events', [
            'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id,
            'stage' => 'evaluation_error_quarantined',
        ]);
    }

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
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'immutable_handoff', true, 'H1');
        $agent = $generation->agents->first();
        $handoffs = app(CandidateHandoffService::class);

        $handoffs->record($generation, $agent, 'screened', 'completed', null, ['attempt' => 1]);
        $handoffs->record($generation, $agent, 'screened', 'completed', null, ['attempt' => 2]);

        $this->assertDatabaseCount('candidate_handoff_events', 1);
        $this->assertSame(2, LabLifecycleEvent::where('lab_agent_id', $agent->id)->where('event_type', 'handoff_screened')->count());
    }

    public function test_stable_waiting_handoff_poll_is_deduplicated_but_keeps_projection(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'immutable_waiting_handoff', true);
        $handoffs = app(CandidateHandoffService::class);

        $first = $handoffs->noEligibleCandidate($generation);
        $second = $handoffs->noEligibleCandidate($generation);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('candidate_handoff_events', 1);
        $this->assertSame(1, LabLifecycleEvent::where('lab_generation_id', $generation->id)
            ->where('event_type', 'handoff_waiting_for_targeted_generation')->count());
        $this->assertNotEmpty(data_get($first->fresh()->payload, 'handoff_profile_hash'));
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
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'immutable_run', true, 'H1');
        $agent = $generation->agents->first();
        $ledger = app(LabImmutableEvidenceService::class);
        $run = $ledger->beginRun($agent, 'screening', 'incremental', ['attempt' => 3, 'queue' => 'lab-gbpusd']);
        $ledger->attachRequest($run, ['symbol' => 'XAUUSD', 'candles' => [['time' => '2026-01-01', 'close' => 1.0]]], ['request_id' => 'test-run-1']);
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

    public function test_compact_projection_keeps_high_value_rows_and_rolls_up_wait_noise(): void
    {
        config()->set('services.lab_evidence.compact_decision_projection', true);
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'compact_projection', true);
        $agent = $generation->agents->first();
        $ledger = app(LabImmutableEvidenceService::class);
        $run = $ledger->beginRun($agent, 'screening', 'incremental');

        $ledger->finishRun($run, 'completed', [
            'total_trades' => 1,
            'decision_trace' => [
                ['time' => '2026-01-01T00:00:00Z', 'action' => 'WAIT', 'accepted' => false, 'reason' => 'no_signal'],
                ['time' => '2026-01-01T00:15:00Z', 'action' => 'WAIT', 'accepted' => false, 'reason' => 'no_signal'],
                ['time' => '2026-01-01T00:30:00Z', 'action' => 'BUY', 'accepted' => true],
                ['time' => '2026-01-01T00:45:00Z', 'event_type' => 'trade_exit', 'action' => 'BUY', 'accepted' => true],
            ],
        ]);

        $this->assertSame(2, LabCandleDecisionEvent::where('run_id', $run->run_id)->count());
        $this->assertDatabaseHas('lab_candle_decision_rollups', [
            'run_id' => $run->run_id,
            'rejection_code' => 'no_signal',
            'event_count' => 2,
        ]);
        $this->assertSame(4, (int) DB::table('lab_candle_decision_rollups')->where('run_id', $run->run_id)->sum('event_count'));
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

    public function test_terminal_replay_requires_dataset_hash_and_error_envelope_stays_ineligible(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'terminal_evidence_contract', true);
        $agent = $generation->agents->first();
        $evidence = app(LabImmutableEvidenceService::class);

        $run = $evidence->beginRun($agent, 'full_validation', 'full');
        $evidence->attachRequest($run, ['symbol' => $agent->symbol, 'timeframe' => $agent->timeframe], ['request_id' => 'missing-dataset-hash']);
        $evidence->finishRun($run, 'technical_error', null, [], ['reason_code' => 'EVALUATION_ERROR']);

        $this->assertDatabaseHas('lab_evidence_artifacts', [
            'run_id' => $run->run_id,
            'artifact_type' => 'evaluation_response',
        ]);
        $this->assertDatabaseHas('lab_evidence_artifacts', [
            'run_id' => $run->run_id,
            'artifact_type' => 'decision_trace_manifest',
        ]);
        $eligibility = $evidence->learningEligibility($run->fresh());
        $this->assertFalse($eligibility['complete']);
        $this->assertContains('MISSING_DATASET_HASH', $eligibility['reason_codes']);
        $this->assertContains('EVIDENCE_RUN_NOT_COMPLETED', $eligibility['reason_codes']);
    }

    public function test_failed_screen_projection_with_incomplete_run_is_recovery_candidate(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'incomplete_failed_projection', true);
        $generation->update(['status' => 'screening', 'completed_at' => null]);
        $agent = $generation->agents->first();
        $agent->update(['lifecycle_status' => 'screened']);
        $agent->modelVersion()->update(['metadata' => []]);
        CandidateGateDecision::create([
            'lab_agent_id' => $agent->id,
            'stage' => 'screening',
            'decision' => 'failed',
            'reason_codes' => ['FAILED_PROFIT_FACTOR'],
            'metrics' => ['evidence_run_id' => null],
            'attribution_status' => 'agent_scoped',
            'evaluated_at' => now(),
        ]);
        app(LabImmutableEvidenceService::class)->beginRun($agent, 'screening', 'screen');

        $result = app(IncompleteLabEvidenceRecoveryService::class)->recover(
            'XAUUSD', $agent->timeframe, $generation->generation, 5, false,
        );
        $this->assertSame(1, $result['selected']);
        $this->assertSame($agent->id, $result['rows'][0]['agent_id']);
        $this->assertNotSame('skipped', $result['rows'][0]['action']);
    }

    public function test_queue_inspector_respects_serialized_agent_id_boundary(): void
    {
        $matchingId = (int) DB::table('jobs')->insertGetId([
            'queue' => 'lab-frontier',
            'payload' => json_encode(['data' => ['command' => 's:10:"labAgentId";i:123;']], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
        $nearMissId = (int) DB::table('jobs')->insertGetId([
            'queue' => 'lab-frontier',
            'payload' => json_encode(['data' => ['command' => 'batch_uuid_labAgentId;1234;']], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $inspector = app(\App\Services\LabQueueJobInspector::class);
        $ids = $inspector->queuedJobIdsForAgents([123], ['lab-frontier']);

        $this->assertContains($matchingId, $ids);
        $this->assertNotContains($nearMissId, $ids);
        $this->assertTrue($inspector->hasAgentJob(1234, ['lab-frontier']));
    }

    public function test_full_admission_block_closes_the_middleware_run_as_skipped(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'full_admission_gate', true);
        $agent = $generation->agents->first();
        $agent->update(['lifecycle_status' => 'full_queued']);
        $generation->update(['status' => 'full_validation', 'completed_at' => null]);

        $job = new EvaluateLabAgentJob($agent->id, $agent->symbol, 'full');
        $attemptEvidence = new LabQueueAttemptEvidenceMiddleware;
        $mutexEvidence = new LabMutexEvidenceMiddleware;

        $attemptEvidence->handle($job, function (EvaluateLabAgentJob $wrappedJob) use ($mutexEvidence): mixed {
            return $mutexEvidence->handle($wrappedJob, fn (EvaluateLabAgentJob $innerJob): mixed => app()->call([$innerJob, 'handle']));
        });

        $run = LabEvaluationRun::query()->where('lab_agent_id', $agent->id)->latest('id')->firstOrFail();
        $this->assertSame('skipped', $run->status);
        $this->assertSame('SCREENING_EVIDENCE_GATE', data_get($run->metadata, 'reason_code'));
        $this->assertSame('screened', $agent->fresh()->lifecycle_status);
    }

    public function test_full_replay_recovery_waits_for_laravel_post_processing_grace(): void
    {
        Http::fake([
            '*' => Http::response([
                'active_requests' => 0,
                'protocol' => 'replay_liveness_v2_bounded_worker',
            ], 200),
        ]);

        $reservedAt = now()->timestamp - 901;
        $jobId = (int) DB::table('jobs')->insertGetId([
            'queue' => 'lab-full-validation',
            'payload' => '{"displayName":"App\\\\Jobs\\\\EvaluateLabAgentJob","data":{"command":"labAgentId;259"}}',
            'attempts' => 1,
            'reserved_at' => $reservedAt,
            'available_at' => $reservedAt,
            'created_at' => $reservedAt,
        ]);

        $this->artisan('trading:recover-lab-replay-mutex', [
            '--force-stale' => true,
            '--stale-after' => 120,
        ])->assertExitCode(1);

        $this->assertDatabaseHas('jobs', [
            'id' => $jobId,
            'reserved_at' => $reservedAt,
        ]);
    }

    public function test_stale_mutex_owner_is_requeued_even_when_a_recent_contender_is_reserved(): void
    {
        Http::fake([
            '*' => Http::response([
                'active_requests' => 0,
                'protocol' => 'replay_liveness_v2_bounded_worker',
            ], 200),
        ]);

        $generation = app(LabPopulationService::class)->build('XAUUSD', 'stale_owner_with_contender', true);
        $owner = $generation->agents->first();
        $evidence = app(LabImmutableEvidenceService::class);
        $run = $evidence->beginRun($owner, 'screening', 'screen');
        $staleAt = now()->timestamp - 1501;
        $recentAt = now()->timestamp;
        $staleJobId = (int) DB::table('jobs')->insertGetId([
            'queue' => 'lab-screening',
            'payload' => json_encode(['data' => ['command' => 'labAgentId;'.$owner->id]], JSON_THROW_ON_ERROR),
            'attempts' => 1,
            'reserved_at' => $staleAt,
            'available_at' => $staleAt,
            'created_at' => $staleAt,
        ]);
        $recentJobId = (int) DB::table('jobs')->insertGetId([
            'queue' => 'lab-screening',
            'payload' => json_encode(['data' => ['command' => 'labAgentId;'.$generation->agents->skip(1)->first()->id]], JSON_THROW_ON_ERROR),
            'attempts' => 1,
            'reserved_at' => $recentAt,
            'available_at' => $recentAt,
            'created_at' => $recentAt,
        ]);
        $lockKey = Cache::getStore()->getPrefix().'laravel-queue-overlap:'.config('services.lab_queue.replay_mutex_key');
        DB::table('cache_locks')->insert([
            'key' => $lockKey,
            'owner' => 'stale-owner-test',
            'expiration' => now()->timestamp + 600,
        ]);

        $this->artisan('trading:recover-lab-replay-mutex', [
            '--force-stale' => true,
            '--stale-after' => 120,
            '--apply' => true,
            '--approved-by' => 'test-operator',
            '--approval-reason' => 'Verify stale owner recovery contract.',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('jobs', ['id' => $staleJobId, 'reserved_at' => null]);
        $this->assertDatabaseHas('jobs', ['id' => $recentJobId, 'reserved_at' => $recentAt]);
        $this->assertDatabaseMissing('cache_locks', ['key' => $lockKey]);
        $this->assertSame('retry_released', $run->fresh()->status);
    }

    public function test_mutation_credit_reconciliation_is_idempotent_and_requires_distinct_replay_runs(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'credit_idempotency', true);
        $agent = $generation->agents->first();
        $parameterKey = (string) array_key_first((array) $agent->parameter_diff);
        $change = (array) data_get($agent->parameter_diff, $parameterKey, []);
        $memory = MutationMemory::create([
            'lab_agent_id' => $agent->id,
            'symbol' => $agent->symbol,
            'timeframe' => $agent->timeframe,
            'strategy_family' => $agent->strategy_family,
            'parameter_key' => $parameterKey,
            'old_value' => ['value' => $change['old'] ?? 1],
            'new_value' => ['value' => $change['new'] ?? 2],
            'forward_delta' => 8,
            'outcome' => 'beneficial',
            'confidence' => 90,
            'decision' => 'independently confirmed',
            'independent_confirmation_count' => 2,
            'behavioral_effect' => ['causal_credit' => ['status' => 'independently_confirmed']],
        ]);
        $evidence = app(LabImmutableEvidenceService::class);
        $runOne = $this->completeExactRun($agent, $evidence, 'credit-run-one');
        $runTwo = $this->completeExactRun($agent, $evidence, 'credit-run-two');
        $payload = [
            'source' => 'verified_mutation_skill_reconciliation',
            'temporal_window_ids' => ['window-2026-01', 'window-2026-02'],
        ];

        $evidence->recordMutationCredit($memory, $payload, $runOne->run_id);
        $evidence->recordMutationCredit($memory, $payload, $runOne->run_id);

        $this->assertSame(1, LabMutationCreditEvent::where('mutation_memory_id', $memory->id)->count());

        $evidence->recordMutationCredit($memory, [
            ...$payload,
            'temporal_window_ids' => ['window-2026-03', 'window-2026-04'],
        ], $runTwo->run_id);
        $this->assertSame(2, LabMutationCreditEvent::where('mutation_memory_id', $memory->id)->count());
        $events = LabMutationCreditEvent::where('mutation_memory_id', $memory->id)->get();
        $this->assertTrue($events->every(fn (LabMutationCreditEvent $event): bool => $event->temporal_window_key !== 'missing' && ! str_starts_with((string) $event->temporal_window_key, 'legacy:')));
        $this->assertTrue($events->every(fn (LabMutationCreditEvent $event): bool => data_get($event->payload, 'behavioral_effect.causal_credit.status') === 'independently_confirmed'));
        $this->assertTrue($events->every(fn (LabMutationCreditEvent $event): bool => collect((array) $event->evidence_run_ids)->intersect([$runOne->run_id, $runTwo->run_id])->isNotEmpty()));
        $this->assertSame(2, LabEvaluationRun::whereIn('run_id', [$runOne->run_id, $runTwo->run_id])->where('status', 'completed')->count());
        $this->assertTrue($evidence->learningEligibility($runOne)['complete']);
        $this->assertTrue($evidence->learningEligibility($runTwo)['complete']);
        $exactRuns = LabEvaluationRun::whereIn('run_id', [$runOne->run_id, $runTwo->run_id])
            ->whereIn('phase', ['full_validation', 'paper', 'holdout'])
            ->whereJsonDoesntContain('metadata->historical', true)
            ->pluck('run_id')->all();
        $this->assertEqualsCanonicalizing([$runOne->run_id, $runTwo->run_id], $exactRuns);
        $this->assertTrue($events->every(fn (LabMutationCreditEvent $event): bool => $event->outcome === 'beneficial'));
        $this->assertTrue($events->every(fn (LabMutationCreditEvent $event): bool => $event->parameter_key === $parameterKey));

        $prior = app(\App\Services\LabHistoricalLearningService::class)
            ->confirmedMutationPrior($agent->symbol, $agent->timeframe, $agent->strategy_family);
        $this->assertNotNull($prior);
        $this->assertSame(2, $prior['confirmation_count']);
        $this->assertEqualsCanonicalizing([$runOne->run_id, $runTwo->run_id], $prior['evidence_run_ids']);
        $this->assertNotNull(LabMutationCreditEvent::query()->first()->reconciliation_key);
    }

    public function test_repeated_reconcile_generation_is_idempotent_and_requires_new_run(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'reconcile_idempotency', true);
        $agent = $generation->agents->first();
        $parameterKey = (string) array_key_first((array) $agent->parameter_diff);
        $change = (array) data_get($agent->parameter_diff, $parameterKey, []);
        $memory = MutationMemory::create([
            'lab_agent_id' => $agent->id,
            'symbol' => $agent->symbol,
            'timeframe' => $agent->timeframe,
            'strategy_family' => $agent->strategy_family,
            'parameter_key' => $parameterKey,
            'old_value' => ['value' => $change['old'] ?? 1],
            'new_value' => ['value' => $change['new'] ?? 2],
            'forward_delta' => 8,
            'outcome' => 'neutral',
            'confidence' => 90,
            'decision' => 'awaiting reconciliation',
            'behavioral_effect' => [
                'causal_credit' => ['status' => 'awaiting_paired_confirmation'],
                'verified_mutation_skill' => [
                    'status' => 'confirmed',
                    'target_gate' => ['improved' => true],
                    'independent_forward_windows' => [
                        'confirmed_windows' => 2,
                        'window_ids' => ['window-2026-03', 'window-2026-04'],
                    ],
                ],
            ],
        ]);
        $evidence = app(LabImmutableEvidenceService::class);
        $runOne = $this->completeExactRun($agent, $evidence, 'reconcile-run-one');
        $runTwo = $this->completeExactRun($agent, $evidence, 'reconcile-run-two');
        $performance = ModelMarketPerformance::create([
            'model_version_id' => $agent->model_version_id,
            'symbol' => $agent->symbol,
            'timeframe' => $agent->timeframe,
            'strategy_family' => $agent->strategy_family,
            'status' => 'challenger',
            'evidence_status' => 'valid',
            'forward_score' => 10,
            'sample_count' => 30,
            'rolling_windows_count' => 2,
            'rolling_forward_wins' => 2,
            'metrics' => ['evidence_run_id' => $runOne->run_id],
        ]);

        $reconciler = app(\App\Services\CausalMutationCreditService::class);
        $reconciler->reconcileGeneration($generation->id);
        $reconciler->reconcileGeneration($generation->id);
        $this->assertSame(1, LabMutationCreditEvent::where('mutation_memory_id', $memory->id)->count());

        $effect = (array) $memory->fresh()->behavioral_effect;
        data_set($effect, 'verified_mutation_skill.independent_forward_windows.window_ids', ['window-2026-05', 'window-2026-06']);
        $memory->update(['behavioral_effect' => $effect]);
        $performance->update(['metrics' => ['evidence_run_id' => $runTwo->run_id]]);
        $reconciler->reconcileGeneration($generation->id);
        $this->assertSame(2, LabMutationCreditEvent::where('mutation_memory_id', $memory->id)->count());
        $this->assertSame(2, LabMutationCreditEvent::where('mutation_memory_id', $memory->id)
            ->distinct('reconciliation_key')->count('reconciliation_key'));
    }

    private function completeExactRun($agent, LabImmutableEvidenceService $evidence, string $requestId): LabEvaluationRun
    {
        $run = $evidence->beginRun($agent, 'full_validation', 'full', ['source' => 'feature_test']);
        $evidence->attachRequest($run, [
            'symbol' => $agent->symbol,
            'timeframe' => $agent->timeframe,
            'candles' => [['time' => '2026-01-01T00:00:00Z', 'close' => 2000]],
        ], ['request_id' => $requestId]);
        $evidence->finishRun($run, 'completed', [
            'total_trades' => 0,
            'trade_ledger_hash' => hash('sha256', $requestId.'-ledger'),
            'trade_ledger' => [],
            'trades' => [],
            'displayed_trade_count' => 0,
            'decision_trace' => [[
                'candle_time' => '2026-01-01T00:00:00Z',
                'event_type' => 'signal_evaluation',
                'action' => 'WAIT',
                'accepted' => false,
            ]],
            'data_quality' => [
                'decision_trace' => [
                    'requested' => true,
                    'complete' => true,
                    'evaluated_candle_count' => 1,
                ],
            ],
        ]);

        return $run->fresh();
    }
}
