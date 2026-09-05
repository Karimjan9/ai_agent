<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\AgentLearningEpisode;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\LabGenerationContextService;
use App\Services\LabQueueJobInspector;
use App\Services\LabQueueStateService;
use App\Services\SettlementWatermarkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EvidenceLifecycleContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminal_agent_quarantine_is_an_explicit_terminal_watermark_not_missing_control_debt(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Technical watermark', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'learning_confirmation',
            'population_size' => 1, 'status' => 'screening', 'trigger_context' => [],
        ]);
        $model = ModelVersion::create([
            'name' => 'technical-watermark', 'strategy' => 'hybrid', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => [],
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'learning_trigger', 'lifecycle_status' => 'technical_quarantine',
            'parameter_diff' => [], 'decision_reason' => 'Terminal evaluator failure; no economic verdict.',
        ]);
        AgentLearningEpisode::create([
            'episode_id' => (string) \Illuminate\Support\Str::uuid(),
            'decision_key' => 'technical-watermark-episode', 'lab_agent_id' => $agent->id,
            'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'stage' => 'screening', 'status' => 'open',
            'decision' => 'research', 'context_hash' => str_repeat('a', 64),
            'decision_context' => [], 'observations' => [],
            'opened_at' => now()->subMinutes(10),
        ]);

        $watermark = app(SettlementWatermarkService::class)
            ->reconcile('XAUUSD', 'H1', $generation->fresh('agents'));

        $this->assertTrue($watermark['generation_close_allowed']);
        $this->assertSame(1, $watermark['terminal']);
        $this->assertSame(1, data_get($watermark, 'lag_taxonomy.technical_quarantine'));
        $this->assertDatabaseHas('settlement_watermarks', [
            'lab_generation_id' => $generation->id,
            'disposition' => 'technical_quarantine', 'terminal' => true,
        ]);
    }

    public function test_generation_queue_backlog_ignores_jobs_owned_by_another_generation(): void
    {
        $owned = json_encode([
            'data' => ['command' => 's:10:"labAgentId";i:41;'],
        ], JSON_THROW_ON_ERROR);
        $other = json_encode([
            'data' => ['command' => 's:10:"labAgentId";i:42;'],
        ], JSON_THROW_ON_ERROR);

        $state = Mockery::mock(LabQueueStateService::class);
        $state->shouldReceive('backend')->andReturn('redis');
        $state->shouldReceive('snapshot')->once()->andReturn([
            'backend' => 'redis',
            'available' => true,
            'total' => 2,
            'queues' => ['lab-full-validation' => 2],
            'rows' => [
                ['id' => 'owned', 'queue' => 'lab-full-validation', 'payload' => $owned],
                ['id' => 'other', 'queue' => 'lab-full-validation', 'payload' => $other],
            ],
        ]);
        $this->app->instance(LabQueueStateService::class, $state);

        $backlog = app(LabQueueJobInspector::class)->generationQueueBacklog([41]);

        $this->assertSame(1, $backlog['total']);
        $this->assertSame(['owned'], collect($backlog['rows'])->pluck('id')->all());
    }

    public function test_generation_queue_backlog_recognizes_screening_batch_agent_arrays(): void
    {
        $owned = json_encode([
            'data' => ['command' => 's:11:"labAgentIds";a:2:{i:0;i:41;i:1;i:43;}'],
        ], JSON_THROW_ON_ERROR);

        $state = Mockery::mock(LabQueueStateService::class);
        $state->shouldReceive('backend')->andReturn('redis');
        $state->shouldReceive('snapshot')->once()->andReturn([
            'backend' => 'redis',
            'available' => true,
            'total' => 1,
            'queues' => ['lab-screening' => 1],
            'rows' => [
                ['id' => 'screen-batch', 'queue' => 'lab-screening', 'payload' => $owned],
            ],
        ]);
        $this->app->instance(LabQueueStateService::class, $state);

        $backlog = app(LabQueueJobInspector::class)->generationQueueBacklog([43]);

        $this->assertSame(1, $backlog['total']);
        $this->assertSame(['screen-batch'], collect($backlog['rows'])->pluck('id')->all());
    }

    public function test_research_lane_yields_only_to_runnable_evolution_replays(): void
    {
        $state = Mockery::mock(LabQueueStateService::class);
        $state->shouldReceive('snapshot')->once()->andReturn([
            'backend' => 'redis',
            'available' => true,
            'stats' => [
                'lab-screening' => ['pending' => 1, 'reserved' => 0, 'delayed' => 0],
                'lab-full-validation' => ['pending' => 0, 'reserved' => 0, 'delayed' => 0],
            ],
        ]);

        $this->assertTrue((new LabQueueJobInspector($state))->evolutionReplayIsWaiting());
    }

    public function test_delayed_evolution_replay_does_not_starve_research_lane(): void
    {
        $state = Mockery::mock(LabQueueStateService::class);
        $state->shouldReceive('snapshot')->once()->andReturn([
            'backend' => 'redis',
            'available' => true,
            'stats' => [
                'lab-screening' => ['pending' => 0, 'reserved' => 0, 'delayed' => 1],
                'lab-full-validation' => ['pending' => 0, 'reserved' => 0, 'delayed' => 0],
            ],
        ]);

        $this->assertFalse((new LabQueueJobInspector($state))->evolutionReplayIsWaiting());
    }

    public function test_delayed_research_work_does_not_starve_edge_director_but_runnable_work_does(): void
    {
        $state = Mockery::mock(LabQueueStateService::class);
        $state->shouldReceive('snapshot')->twice()->andReturn(
            [
                'backend' => 'redis', 'available' => true,
                'stats' => [
                    'lab-screening' => ['pending' => 0, 'reserved' => 0, 'delayed' => 0],
                    'lab-frontier' => ['pending' => 0, 'reserved' => 0, 'delayed' => 1],
                    'lab-full-validation' => ['pending' => 0, 'reserved' => 0, 'delayed' => 0],
                ],
            ],
            [
                'backend' => 'redis', 'available' => true,
                'stats' => [
                    'lab-screening' => ['pending' => 1, 'reserved' => 0, 'delayed' => 0],
                    'lab-frontier' => ['pending' => 0, 'reserved' => 1, 'delayed' => 1],
                    'lab-full-validation' => ['pending' => 0, 'reserved' => 0, 'delayed' => 0],
                ],
            ],
        );
        $inspector = new LabQueueJobInspector($state);

        $delayedOnly = $inspector->runnableLabQueueBacklog();
        $this->assertSame(0, $delayedOnly['total']);
        $this->assertSame(1, $delayedOnly['delayed']);

        $runnable = $inspector->runnableLabQueueBacklog();
        $this->assertSame(2, $runnable['total']);
        $this->assertSame(1, $runnable['delayed']);
    }

    public function test_reload_preflight_counts_only_reserved_worker_ownership_as_interruptible(): void
    {
        $state = Mockery::mock(LabQueueStateService::class);
        $state->shouldReceive('snapshot')->once()->andReturn([
            'backend' => 'redis', 'available' => true,
            'stats' => [
                'lab-frontier' => ['pending' => 2, 'reserved' => 0, 'delayed' => 1],
                'market-maintenance' => ['pending' => 0, 'reserved' => 1, 'delayed' => 0],
            ],
        ]);

        $backlog = (new LabQueueJobInspector($state))->reservedQueueBacklog([
            'lab-frontier', 'market-maintenance',
        ]);

        $this->assertSame(1, $backlog['total']);
        $this->assertSame(['lab-frontier' => 0, 'market-maintenance' => 1], $backlog['queues']);
        $this->assertSame(2, $backlog['pending']);
        $this->assertSame(1, $backlog['delayed']);
    }

    public function test_priority_validation_does_not_deadlock_on_its_own_full_queue_payload(): void
    {
        $payload = json_encode([
            'displayName' => \App\Jobs\ValidateMtfPoweredPriorJob::class,
            'data' => ['commandName' => \App\Jobs\ValidateMtfPoweredPriorJob::class],
        ], JSON_THROW_ON_ERROR);
        $state = Mockery::mock(LabQueueStateService::class);
        $state->shouldReceive('snapshot')->once()->andReturn([
            'backend' => 'redis',
            'available' => true,
            'stats' => [
                'lab-screening' => ['pending' => 0, 'reserved' => 0, 'delayed' => 0],
                'lab-full-validation' => ['pending' => 0, 'reserved' => 1, 'delayed' => 0],
            ],
            'rows' => [[
                'queue' => 'lab-full-validation',
                'redis_state' => 'reserved',
                'payload' => $payload,
            ]],
        ]);

        $this->assertFalse((new LabQueueJobInspector($state))->evolutionReplayIsWaiting([
            \App\Jobs\ValidateMtfPoweredPriorJob::class,
        ]));
    }

    public function test_priority_validation_still_yields_to_a_canonical_full_replay(): void
    {
        $self = json_encode(['displayName' => \App\Jobs\ValidateMtfPoweredPriorJob::class], JSON_THROW_ON_ERROR);
        $canonical = json_encode(['displayName' => \App\Jobs\EvaluateLabAgentJob::class], JSON_THROW_ON_ERROR);
        $state = Mockery::mock(LabQueueStateService::class);
        $state->shouldReceive('snapshot')->once()->andReturn([
            'backend' => 'redis',
            'available' => true,
            'stats' => [
                'lab-screening' => ['pending' => 0, 'reserved' => 0, 'delayed' => 0],
                'lab-full-validation' => ['pending' => 1, 'reserved' => 1, 'delayed' => 0],
            ],
            'rows' => [
                ['queue' => 'lab-full-validation', 'redis_state' => 'reserved', 'payload' => $self],
                ['queue' => 'lab-full-validation', 'redis_state' => 'pending', 'payload' => $canonical],
            ],
        ]);

        $this->assertTrue((new LabQueueJobInspector($state))->evolutionReplayIsWaiting([
            \App\Jobs\ValidateMtfPoweredPriorJob::class,
        ]));
    }

    public function test_active_generation_reserves_replay_priority_before_its_queue_jobs_exist(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'Priority intent lab',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid'],
            'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
        LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 1,
            'trigger_type' => 'learning_confirmation',
            'population_size' => 3,
            'status' => 'draft',
            'data_fingerprint' => str_repeat('a', 40),
            'trigger_context' => [],
        ]);
        $state = Mockery::mock(LabQueueStateService::class);
        $state->shouldNotReceive('snapshot');

        $this->assertTrue((new LabQueueJobInspector($state))->evolutionReplayIsWaiting());
    }

    public function test_fresh_screened_generation_reserves_priority_until_full_dispatch(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'Screened handoff priority lab',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid'],
            'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 1,
            'trigger_type' => 'learning_confirmation',
            'population_size' => 1,
            'status' => 'screened',
            'data_fingerprint' => str_repeat('b', 40),
            'trigger_context' => [],
        ]);
        $model = ModelVersion::create([
            'name' => 'screened-handoff',
            'strategy' => 'xauusd_handoff_test',
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'parameters' => [],
            'metadata' => [],
        ]);
        LabAgent::withoutEvents(fn () => LabAgent::create([
            'lab_generation_id' => $generation->id,
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'origin' => 'test',
            'lifecycle_status' => 'screened',
        ]));

        $state = Mockery::mock(LabQueueStateService::class);
        $state->shouldNotReceive('snapshot');

        $this->assertTrue((new LabQueueJobInspector($state))->evolutionReplayIsWaiting());
    }

    public function test_old_screened_generation_cannot_reclaim_priority_after_a_newer_terminal_generation(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'Latest generation priority lab',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid'],
            'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
        $old = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 1,
            'trigger_type' => 'learning_confirmation',
            'population_size' => 1,
            'status' => 'screened',
            'data_fingerprint' => str_repeat('c', 40),
            'trigger_context' => [],
        ]);
        $model = ModelVersion::create([
            'name' => 'old-screened-handoff',
            'strategy' => 'xauusd_handoff_test',
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'parameters' => [],
            'metadata' => [],
        ]);
        LabAgent::withoutEvents(fn () => LabAgent::create([
            'lab_generation_id' => $old->id,
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'origin' => 'test',
            'lifecycle_status' => 'screened',
        ]));
        LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 2,
            'trigger_type' => 'learning_confirmation',
            'population_size' => 1,
            'status' => 'technical_quarantine',
            'data_fingerprint' => str_repeat('d', 40),
            'trigger_context' => [],
        ]);

        $state = Mockery::mock(LabQueueStateService::class);
        $state->shouldReceive('snapshot')->once()->andReturn([
            'backend' => 'redis',
            'available' => true,
            'stats' => [
                'lab-screening' => ['pending' => 0, 'reserved' => 0, 'delayed' => 0],
                'lab-full-validation' => ['pending' => 0, 'reserved' => 0, 'delayed' => 0],
            ],
        ]);

        $this->assertFalse((new LabQueueJobInspector($state))->evolutionReplayIsWaiting());
    }

    public function test_generation_context_update_preserves_concurrent_context_keys(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'Lifecycle test',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid'],
            'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 1,
            'trigger_type' => 'test',
            'trigger_context' => ['existing' => ['value' => 1]],
            'population_size' => 0,
            'status' => 'screened',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        app(LabGenerationContextService::class)->update($generation, function (array $context): array {
            $context['new_projection'] = ['promotion_evidence' => false];

            return $context;
        });

        $context = (array) $generation->fresh()->trigger_context;
        $this->assertSame(1, data_get($context, 'existing.value'));
        $this->assertFalse((bool) data_get($context, 'new_projection.promotion_evidence'));

        app(LabGenerationContextService::class)->updateWithAttributes(
            $generation->fresh(),
            ['status' => 'completed', 'completed_at' => now()],
            function (array $context): array {
                $context['terminal_projection'] = ['promotion_evidence' => false];

                return $context;
            },
        );

        $terminal = $generation->fresh();
        $this->assertSame('completed', $terminal->status);
        $this->assertFalse((bool) data_get($terminal->trigger_context, 'terminal_projection.promotion_evidence'));
    }
}
