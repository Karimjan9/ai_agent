<?php

namespace Tests\Feature;

use App\Jobs\EvaluateLabAgentJob;
use App\Jobs\Middleware\LabMutexEvidenceMiddleware;
use App\Jobs\Middleware\LabQueueAttemptEvidenceMiddleware;
use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningSettlement;
use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\CandidateHandoffEvent;
use App\Models\InstrumentInvocationLedger;
use App\Models\LabAgent;
use App\Models\LabCandleDecisionEvent;
use App\Models\LabEvaluationRun;
use App\Models\LabGateDecisionEvent;
use App\Models\LabGeneration;
use App\Models\LabLifecycleCycle;
use App\Models\LabLifecycleEvent;
use App\Models\LabMutationCreditEvent;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\MutationMemory;
use App\Services\AgentConstitutionService;
use App\Services\CandidateGateDecisionService;
use App\Services\CandidateHandoffService;
use App\Services\CausalMutationCreditService;
use App\Services\CooperativeContextualEvolutionCouncilService;
use App\Services\GenerationAutonomyAuditService;
use App\Services\IncompleteLabEvidenceRecoveryService;
use App\Services\LabAgentEvaluationService;
use App\Services\LabHistoricalLearningService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabPopulationService;
use App\Services\LabQueueJobInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImmutableLabEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_autonomy_audit_treats_scientific_rejection_as_a_clean_terminal_disposition(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Scientific rejection terminal test',
            'timeframe' => 'H1', 'strategy_families' => ['hybrid'],
            'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'test', 'status' => 'completed',
            'population_size' => 1, 'trigger_context' => [],
            'completed_at' => now(),
        ]);
        $model = ModelVersion::create([
            'name' => 'scientifically-rejected', 'strategy' => 'hybrid',
            'version' => 'v1-rejected', 'generation' => 1,
            'status' => 'testing', 'parameters' => [], 'metadata' => [],
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id,
            'model_version_id' => $model->id, 'symbol' => 'XAUUSD',
            'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'rejected',
            'parameter_diff' => [],
        ]);

        $audit = app(GenerationAutonomyAuditService::class)->audit($generation->fresh());
        $population = collect($audit['checks'])->firstWhere('name', 'population_terminal');

        $this->assertSame('passed', $population['status']);
        $this->assertSame(1, $population['metrics']['terminal_agents']);
        $this->assertSame([], $population['metrics']['non_terminal_or_technical_agent_ids']);
    }

    public function test_autonomy_audit_reports_a_technical_quarantine_as_terminal_but_not_technically_clean(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Technical terminal audit test',
            'timeframe' => 'H1', 'strategy_families' => ['hybrid'],
            'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'test', 'status' => 'technical_quarantine',
            'population_size' => 1, 'trigger_context' => [],
            'completed_at' => now(),
        ]);
        $model = ModelVersion::create([
            'name' => 'technically-quarantined', 'strategy' => 'hybrid',
            'version' => 'v1-technical', 'generation' => 1,
            'status' => 'testing', 'parameters' => [], 'metadata' => [],
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id,
            'model_version_id' => $model->id, 'symbol' => 'XAUUSD',
            'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'technical_quarantine',
            'parameter_diff' => [],
        ]);

        $audit = app(GenerationAutonomyAuditService::class)->audit($generation->fresh());
        $checks = collect($audit['checks']);
        $population = $checks->firstWhere('name', 'population_terminal');
        $technical = $checks->firstWhere('name', 'technical_integrity');
        $immutable = $checks->firstWhere('name', 'immutable_evidence');
        $order = $checks->firstWhere('name', 'terminal_learning_order');

        $this->assertSame('failed', $audit['state']);
        $this->assertSame([], $audit['running_checks']);
        $this->assertSame('passed', $population['status']);
        $this->assertSame(1, $population['metrics']['terminal_agents']);
        $this->assertSame([], $population['metrics']['non_terminal_or_technical_agent_ids']);
        $this->assertSame('failed', $technical['status']);
        $this->assertSame([$agent->id], $technical['metrics']['technical_agent_ids']);
        $this->assertSame('failed', $immutable['status']);
        $this->assertSame('passed', $order['status']);
    }

    public function test_uncertainty_abstain_is_a_local_wait_receipt_and_never_calls_replay(): void
    {
        Http::fake();
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Abstain guard test', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'status' => 'screening', 'population_size' => 1, 'trigger_context' => [],
        ]);
        $model = ModelVersion::create([
            'name' => 'uncertainty-abstain', 'strategy' => 'hybrid', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => [],
            'metadata' => [
                'generation_target' => 'uncertainty_abstain',
                'mutation_constructor_invariant' => ['control_only' => true],
                'uncertainty_abstain_contract' => [
                    'protocol' => CooperativeContextualEvolutionCouncilService::UNCERTAINTY_ABSTAIN_PROTOCOL,
                    'status' => 'sealed', 'action' => 'WAIT', 'replay_required' => false,
                    'causal_credit_allowed' => false, 'economic_credit_allowed' => false,
                    'promotion_evidence' => false,
                ],
            ],
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'screening', 'parameter_diff' => [],
        ]);
        $episode = AgentLearningEpisode::create([
            'episode_id' => (string) Str::uuid(),
            'decision_key' => 'uncertainty-abstain-learning-episode',
            'lab_agent_id' => $agent->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'stage' => 'screening', 'status' => 'open', 'decision' => 'WAIT',
            'context_hash' => str_repeat('u', 64),
            'decision_context' => ['action' => 'WAIT'], 'observations' => [],
            'opened_at' => now(),
        ]);
        $metadata = (array) $model->metadata;
        $metadata['learning_decision'] = ['episode_id' => $episode->id, 'decision' => 'WAIT'];
        $model->update(['metadata' => $metadata]);

        app(LabAgentEvaluationService::class)->screen($agent);

        Http::assertNothingSent();
        $this->assertSame('screened', $agent->fresh()->lifecycle_status);
        $this->assertSame('screened', $generation->fresh()->status);
        $run = LabEvaluationRun::query()->where('lab_agent_id', $agent->id)->firstOrFail();
        $this->assertSame('completed', $run->status);
        $this->assertSame('WAIT', data_get($run->metrics, 'decision'));
        $this->assertFalse((bool) data_get($run->metadata, 'replay_performed', true));
        $this->assertTrue((bool) data_get($run->metadata, 'correctly_abstained'));
        $settlement = AgentLearningSettlement::query()->where('episode_id', $episode->id)->firstOrFail();
        $this->assertSame('settled', $episode->fresh()->status);
        $this->assertSame('abstained', $settlement->outcome_status);
        $this->assertSame('neutral', $settlement->evidence_state);
        $this->assertSame(0.0, $settlement->selection_reward);
        $this->assertFalse($settlement->hard_failure);
        $this->assertFalse((bool) data_get($settlement->outcome, 'promotion_evidence', true));
        $this->assertSame(
            'deliberate_abstain_settled',
            data_get($model->fresh()->metadata, 'learning_decision.outcome_status'),
        );
        $this->assertDatabaseCount('candidate_gate_decisions', 0);
        $this->assertSame(0, InstrumentInvocationLedger::query()->where('lab_agent_id', $agent->id)->count());
        $this->assertDatabaseHas('candidate_handoff_events', [
            'lab_agent_id' => $agent->id,
            'terminal_reason' => 'UNCERTAINTY_GUARD_WAIT',
        ]);
        // The generation may close only after its learning settlement. Keep
        // this recovered-cycle fixture chronologically valid so the audit is
        // testing recovery semantics rather than an impossible terminal
        // projection that predates its own settlement.
        $blockedAt = $settlement->fresh()->updated_at->copy()->addSecond();
        LabLifecycleCycle::query()->create([
            'cycle_id' => 'audit-recovered-block', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'status' => 'blocked', 'stage' => 'preflight', 'summary' => 'runtime_unhealthy',
            'context' => [], 'started_at' => $blockedAt, 'heartbeat_at' => $blockedAt, 'finished_at' => $blockedAt,
        ]);
        LabLifecycleCycle::query()->create([
            'cycle_id' => 'audit-recovery-receipt', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'status' => 'completed', 'stage' => 'forward', 'summary' => 'recovered',
            'context' => [], 'started_at' => $blockedAt->copy()->addSecond(),
            'heartbeat_at' => $blockedAt->copy()->addSecond(), 'finished_at' => $blockedAt->copy()->addSecond(),
        ]);
        $generation->update(['completed_at' => $blockedAt->copy()->addSeconds(2)]);
        $audit = app(GenerationAutonomyAuditService::class)->audit($generation->fresh());
        $this->assertSame('passed', $audit['state']);
        $this->assertTrue($audit['unattended_ready']);
        $technical = collect($audit['checks'])->firstWhere('name', 'technical_integrity');
        $this->assertSame(['audit-recovered-block'], $technical['metrics']['recovered_blocked_cycle_ids']);
        $this->assertSame([], $technical['metrics']['unrecovered_blocked_cycle_ids']);
        $this->assertSame('passed', data_get(collect($audit['checks'])->firstWhere('name', 'immutable_evidence'), 'status'));
        $this->assertSame('passed', data_get(collect($audit['checks'])->firstWhere('name', 'market_session_calendar'), 'status'));
        Artisan::call('trading:lab-evidence-audit', [
            'symbol' => 'XAUUSD', '--timeframe' => 'H1', '--generation' => 1, '--json' => true,
        ]);
        $historyAudit = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, data_get($historyAudit, 'rows.0.local_policy_guard_run_count'));
        $this->assertSame(0, data_get($historyAudit, 'rows.0.response_runs'));

        $unrecoveredAt = $generation->fresh()->completed_at->copy()->addSecond();
        LabLifecycleCycle::query()->create([
            'cycle_id' => 'audit-unrecovered-block', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'status' => 'blocked', 'stage' => 'preflight', 'summary' => 'runtime_unhealthy',
            'context' => [], 'started_at' => $unrecoveredAt, 'heartbeat_at' => $unrecoveredAt,
            'finished_at' => $unrecoveredAt,
        ]);
        $generation->update(['completed_at' => $unrecoveredAt->copy()->addSecond()]);
        $failedAudit = app(GenerationAutonomyAuditService::class)->audit($generation->fresh());
        $failedTechnical = collect($failedAudit['checks'])->firstWhere('name', 'technical_integrity');
        $this->assertSame('failed', $failedTechnical['status']);
        $this->assertSame(['audit-unrecovered-block'], $failedTechnical['metrics']['unrecovered_blocked_cycle_ids']);
    }

    public function test_last_wait_guard_cannot_mask_a_terminal_technical_peer(): void
    {
        Http::fake();
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Technical peer guard', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 2, 'trigger_type' => 'test',
            'status' => 'screening', 'population_size' => 2, 'trigger_context' => [],
        ]);
        $technicalModel = ModelVersion::create([
            'name' => 'technical-peer', 'strategy' => 'hybrid', 'version' => 'v2-technical',
            'generation' => 2, 'status' => 'testing', 'parameters' => [], 'metadata' => [],
        ]);
        $technical = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $technicalModel->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'technical_quarantine', 'parameter_diff' => [],
        ]);
        $guardModel = ModelVersion::create([
            'name' => 'last-wait-guard', 'strategy' => 'hybrid', 'version' => 'v2-guard',
            'generation' => 2, 'status' => 'testing', 'parameters' => [],
            'metadata' => [
                'generation_target' => 'uncertainty_abstain',
                'mutation_constructor_invariant' => ['control_only' => true],
                'uncertainty_abstain_contract' => [
                    'protocol' => CooperativeContextualEvolutionCouncilService::UNCERTAINTY_ABSTAIN_PROTOCOL,
                    'status' => 'sealed', 'action' => 'WAIT', 'replay_required' => false,
                    'promotion_evidence' => false,
                ],
            ],
        ]);
        $guard = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $guardModel->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'screening', 'parameter_diff' => [],
        ]);

        app(LabAgentEvaluationService::class)->screen($guard);

        $this->assertSame('screened', $guard->fresh()->lifecycle_status);
        $this->assertSame('technical_quarantine', $technical->fresh()->lifecycle_status);
        $this->assertSame('technical_quarantine', $generation->fresh()->status);
        $this->assertSame([$technical->id], data_get($generation->fresh()->trigger_context, 'screening_terminal.technical_agent_ids'));
    }

    public function test_autonomy_audit_rejects_generation_closed_before_learning_settlement(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Terminal ordering audit', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 3, 'trigger_type' => 'test',
            'status' => 'screened', 'population_size' => 1, 'trigger_context' => [],
            'completed_at' => now()->subMinute(),
        ]);
        $model = ModelVersion::create([
            'name' => 'terminal-ordering', 'strategy' => 'hybrid', 'version' => 'v3',
            'generation' => 3, 'status' => 'testing', 'parameters' => [], 'metadata' => [],
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => [],
        ]);
        $episode = AgentLearningEpisode::create([
            'episode_id' => (string) Str::uuid(),
            'decision_key' => 'terminal-ordering-episode', 'lab_agent_id' => $agent->id,
            'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'stage' => 'screening', 'status' => 'settled',
            'decision' => 'CONTROL', 'context_hash' => str_repeat('c', 64),
            'decision_context' => [], 'observations' => [],
            'opened_at' => now()->subMinutes(2), 'settled_at' => now(),
        ]);
        AgentLearningSettlement::create([
            'settlement_id' => (string) Str::uuid(),
            'episode_id' => $episode->id, 'source_key' => 'terminal-ordering-source',
            'outcome_status' => 'settled', 'evidence_state' => 'negative',
            'hard_failure' => false, 'outcome' => [], 'settled_at' => now(),
        ]);

        $audit = app(GenerationAutonomyAuditService::class)->audit($generation->fresh());
        $check = collect($audit['checks'])->firstWhere('name', 'terminal_learning_order');

        $this->assertSame('failed', $check['status']);
        $this->assertContains('GENERATION_CLOSED_BEFORE_LEARNING_SETTLEMENT', $check['reason_codes']);
        $this->assertSame(['learning'], $check['metrics']['settlements_after_generation_close']);
    }

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

    public function test_host_suspend_timeout_is_retryable_queue_telemetry_not_a_technical_verdict(): void
    {
        $job = new EvaluateLabAgentJob(1, 'XAUUSD', 'screen');
        $run = new LabEvaluationRun(['started_at' => now()->subHour()]);
        $error = new ConnectionException(
            'cURL error 28: Operation timed out after 3600000 milliseconds with 0 bytes received'
        );
        $method = new \ReflectionMethod($job, 'isHostSuspendOrLongStall');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($job, $error, $run));

        $run->started_at = now()->subMinutes(10);
        $this->assertFalse($method->invoke($job, $error, $run));
        $this->assertFalse($method->invoke($job, new \RuntimeException('ordinary error'), $run));
    }

    public function test_batched_screening_detects_host_suspend_before_writing_technical_evidence(): void
    {
        $service = app(LabAgentEvaluationService::class);
        $method = new \ReflectionMethod($service, 'batchInterruptedByHostSuspend');
        $method->setAccessible(true);
        $error = new ConnectionException(
            'cURL error 28: Operation timed out after 33001518 milliseconds with 0 bytes received'
        );

        $this->assertTrue($method->invoke(
            $service,
            $error,
            collect([new LabEvaluationRun(['started_at' => now()->subHours(9)])]),
            1800,
        ));
        $this->assertFalse($method->invoke(
            $service,
            $error,
            collect([new LabEvaluationRun(['started_at' => now()->subMinutes(31)])]),
            1800,
        ));
        $this->assertFalse($method->invoke(
            $service,
            new \RuntimeException('ordinary error'),
            collect([new LabEvaluationRun(['started_at' => now()->subHours(9)])]),
            1800,
        ));
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
        $this->assertSame('blocked', data_get($healthy, 'strategic_evidence.status'));
        $this->assertFalse((bool) data_get($healthy, 'strategic_evidence.promotion_evidence'));
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

        $projection = CandidateHandoffEvent::where('lab_generation_id', $generation->id)
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

        $inspector = app(LabQueueJobInspector::class);
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
                // A test environment without an internal token reads the
                // official nested /health liveness projection instead.
                'replay_liveness' => [
                    'active_requests' => 0,
                    'protocol' => 'replay_liveness_v2_bounded_worker',
                ],
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

        $prior = app(LabHistoricalLearningService::class)
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

        $reconciler = app(CausalMutationCreditService::class);
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
            'candles' => array_fill(0, 201, ['time' => '2026-01-01T00:00:00Z', 'close' => 2000]),
        ], ['request_id' => $requestId]);
        $evidence->finishRun($run, 'completed', [
            'total_trades' => 0,
            'trade_ledger_hash' => hash('sha256', $requestId.'-ledger'),
            'trade_ledger' => [],
            'trades' => [],
            'displayed_trade_count' => 0,
            'decision_trace' => [[
                'candle_index' => 200,
                'candle_time' => '2026-01-01T00:00:00Z',
                'event_type' => 'signal_evaluation',
                'action' => 'WAIT',
                'accepted' => false,
            ]],
            'data_quality' => [
                'decision_trace' => [
                    'protocol' => 'candle_decision_trace_v1',
                    'requested' => true,
                    'complete' => true,
                    'event_count' => 1,
                    'evaluated_candle_count' => 1,
                ],
            ],
        ]);

        return $run->fresh();
    }
}
