<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Services\LabGenerationContextService;
use App\Services\LabQueueJobInspector;
use App\Services\LabQueueStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EvidenceLifecycleContractTest extends TestCase
{
    use RefreshDatabase;

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
