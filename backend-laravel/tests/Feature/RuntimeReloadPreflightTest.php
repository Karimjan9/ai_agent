<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabCandleDecisionEvent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use App\Models\LabLifecycleEvent;
use App\Models\LabMutationCreditEvent;
use App\Models\MarketTrainingArchive;
use App\Models\ModelVersion;
use App\Services\LabQueueJobInspector;
use App\Services\AutonomousModeService;
use App\Services\ObservedCouncilEpisodeDispositionService;
use App\Services\RuntimeReloadPreflightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RuntimeReloadPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_delayed_research_is_visible_but_does_not_block_an_idle_reload(): void
    {
        $queues = Mockery::mock(LabQueueJobInspector::class);
        $queues->shouldReceive('reservedQueueBacklog')->once()->andReturn([
            'total' => 0, 'queues' => ['lab-frontier' => 0], 'pending' => 0, 'delayed' => 1,
        ]);

        $result = (new RuntimeReloadPreflightService($queues))->inspect();

        $this->assertTrue($result['safe']);
        $this->assertSame('IDLE', $result['reason']);
        $this->assertSame(1, data_get($result, 'reserved_queue.delayed'));
    }

    public function test_runnable_job_or_active_generation_refuses_reload(): void
    {
        $queues = Mockery::mock(LabQueueJobInspector::class);
        $queues->shouldReceive('reservedQueueBacklog')->twice()->andReturn(
            ['total' => 1, 'queues' => ['lab-frontier' => 1], 'pending' => 0, 'delayed' => 0],
            ['total' => 0, 'queues' => [], 'pending' => 0, 'delayed' => 0],
        );
        $service = new RuntimeReloadPreflightService($queues);
        $this->assertSame('RESERVED_WORKER_JOB_EXISTS', $service->inspect()['reason']);

        $lab = AiLaboratory::create([
            'name' => 'Reload safety lab', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1,
            'status' => 'queued', 'population_size' => 1, 'trigger_type' => 'edge_genesis',
        ]);

        $result = $service->inspect();
        $this->assertFalse($result['safe']);
        $this->assertSame('ACTIVE_GENERATION_EXISTS', $result['reason']);
    }

    public function test_explicit_cold_maintenance_requires_exact_proof_but_default_stays_strict(): void
    {
        $generation = $this->activeProjection();
        $service = $this->emptyQueueService(2);
        $mode = Mockery::mock(AutonomousModeService::class);
        $mode->shouldReceive('status')->once()->andReturn(['enabled' => false, 'state' => 'draining']);
        $this->app->instance(AutonomousModeService::class, $mode);
        $disposition = Mockery::mock(ObservedCouncilEpisodeDispositionService::class);
        $disposition->shouldReceive('inspectGeneration')->once()->andReturn([
            'allowed' => true, 'proof' => ['generation_id' => $generation->id, 'proof_hash' => str_repeat('a', 64)],
            'promotion_evidence' => false,
        ]);
        $this->app->instance(ObservedCouncilEpisodeDispositionService::class, $disposition);

        $this->assertFalse($service->inspect()['safe']);
        $result = $service->inspect($generation->id);
        $this->assertTrue($result['safe']);
        $this->assertSame('TERMINAL_PROJECTION_COLD_MAINTENANCE_READY', $result['reason']);
        $this->assertFalse($result['rolling_reload_allowed']);
        $this->assertTrue($result['replay_idle_probe_required']);
        $this->assertFalse($result['scientific_evidence']);
    }

    public function test_cold_maintenance_refuses_unknown_pending_delayed_or_reserved_queue(): void
    {
        foreach ([['pending' => null], ['total' => 1], ['pending' => 1], ['delayed' => 1], ['total' => '0']] as $delta) {
            $queues = Mockery::mock(LabQueueJobInspector::class);
            $queues->shouldReceive('reservedQueueBacklog')->once()->andReturn([
                'total' => 0, 'pending' => 0, 'delayed' => 0, 'queues' => [], ...$delta,
            ]);
            $result = (new RuntimeReloadPreflightService($queues))->inspect(355);
            $this->assertFalse($result['safe']);
            $this->assertContains($result['reason'], ['QUEUE_STATE_UNKNOWN', 'QUEUE_WORK_REMAINS']);
        }
    }

    public function test_cold_maintenance_refuses_other_active_generation_or_missing_target(): void
    {
        $generation = $this->activeProjection();
        $service = $this->emptyQueueService(2);
        $this->assertFalse($service->inspect($generation->id + 1)['safe']);
        LabGeneration::create(['ai_laboratory_id' => $generation->ai_laboratory_id, 'generation' => 2,
            'status' => 'queued', 'population_size' => 1, 'trigger_type' => 'edge_genesis']);
        $this->assertFalse($service->inspect($generation->id)['safe']);
    }

    public function test_cold_maintenance_never_overrides_running_pause_or_safety_halt(): void
    {
        $generation = $this->activeProjection();
        $service = $this->emptyQueueService(3);
        $mode = Mockery::mock(AutonomousModeService::class);
        $mode->shouldReceive('status')->times(3)->andReturn(
            ['enabled' => true, 'state' => 'running'], ['enabled' => false, 'state' => 'paused'],
            ['enabled' => false, 'state' => 'safety_halt']);
        $this->app->instance(AutonomousModeService::class, $mode);
        foreach (range(1, 3) as $_) {
            $this->assertSame('DRAIN_FIRST_STOP_REQUIRED', $service->inspect($generation->id)['reason']);
        }
    }

    public function test_cold_maintenance_refuses_missing_or_authority_granting_proof(): void
    {
        $generation = $this->activeProjection();
        $service = $this->emptyQueueService(3);
        $mode = Mockery::mock(AutonomousModeService::class);
        $mode->shouldReceive('status')->times(3)->andReturn(['enabled' => false, 'state' => 'stopped']);
        $this->app->instance(AutonomousModeService::class, $mode);
        $disposition = Mockery::mock(ObservedCouncilEpisodeDispositionService::class);
        $disposition->shouldReceive('inspectGeneration')->times(3)->andReturn(
            ['allowed' => false, 'promotion_evidence' => false, 'proof' => ['id' => 1]],
            ['allowed' => true, 'promotion_evidence' => false, 'proof' => []],
            ['allowed' => true, 'promotion_evidence' => true, 'proof' => ['id' => 1]]);
        $this->app->instance(ObservedCouncilEpisodeDispositionService::class, $disposition);
        foreach (range(1, 3) as $_) {
            $this->assertSame('TERMINAL_PROJECTION_RECOVERY_PROOF_REFUSED', $service->inspect($generation->id)['reason']);
        }
    }

    public function test_cli_refuses_invalid_explicit_target(): void
    {
        $this->artisan('system:runtime-reload-preflight', ['--json' => true, '--terminal-projection-recovery' => '0'])
            ->assertExitCode(1);
    }

    public function test_named_unused_draft_allows_only_read_only_cold_maintenance_and_retains_data_dependency(): void
    {
        $generation = $this->unusedDraft();
        $service = $this->emptyQueueService(2);
        Queue::fake();
        $before = $generation->fresh(['agents.modelVersion'])->toArray();
        $locksBefore = Cache::store()->getStore()->locks;
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^(?:insert|update|delete|replace|alter|create|drop)\b/i', ltrim($query->sql))) $writes[] = $query->sql;
        });

        $this->assertSame('ACTIVE_GENERATION_EXISTS', $service->inspect()['reason']);
        $result = $service->inspect(null, $generation->id);

        $this->assertTrue($result['safe'], json_encode($result));
        $this->assertSame('unsealed_draft_cold_maintenance_v1', $result['mode']);
        $this->assertSame('UNSEALED_DRAFT_COLD_MAINTENANCE_READY', $result['reason']);
        $this->assertFalse($result['rolling_reload_allowed']);
        $this->assertTrue($result['replay_idle_probe_required']);
        $this->assertFalse($result['scientific_evidence']);
        $this->assertFalse(data_get($result, 'unsealed_draft_proof.replay_admission_granted'));
        $this->assertSame(range(1, 20), data_get($result, 'unsealed_draft_proof.constructor_slots'));
        $this->assertSame($before, $generation->fresh(['agents.modelVersion'])->toArray());
        $this->assertSame(17, data_get(MarketTrainingArchive::first()->metrics, 'frozen_m5_gap_recovery_receipt.calendar_scope.full_source_unexpected_after'));
        $this->assertSame($locksBefore, Cache::store()->getStore()->locks);
        $this->assertSame([], $writes);
        Queue::assertNothingPushed();
    }

    #[DataProvider('unusedDraftConstructionFailures')]
    public function test_unused_draft_requires_every_original_slot_model_and_draft_agent(string $fault, string $reason): void
    {
        $generation = $this->unusedDraft();
        $agents = $generation->agents()->with('modelVersion')->orderBy('id')->get();
        $context = (array) $generation->trigger_context;
        if ($fault === 'partial_population') $agents->last()->delete();
        if ($fault === 'audit_mismatch') { $context['constructor_audit']['created_agents'] = 19; $generation->update(['trigger_context' => $context]); }
        if ($fault === 'plan_missing') { unset($context['generation_plan']); $generation->update(['trigger_context' => $context]); }
        if ($fault === 'agent_queued') LabAgent::withoutEvents(fn () => $agents->first()->update(['lifecycle_status' => 'queued']));
        if ($fault === 'model_missing') { $agents->first()->modelVersion->delete(); }
        if ($fault === 'slot_duplicate') $agents->last()->modelVersion->update(['strategy' => $agents->first()->modelVersion->strategy]);
        if ($fault === 'wrong_slot_family') { $context['generation_plan'][0]['family'] = 'trend'; $generation->update(['trigger_context' => $context]); }
        if ($fault === 'foreign_ownership') {
            $other = LabGeneration::create(['ai_laboratory_id' => $generation->ai_laboratory_id, 'generation' => 2,
                'status' => 'completed', 'population_size' => 1, 'trigger_type' => 'historical_research']);
            LabAgent::withoutEvents(fn () => LabAgent::create(['lab_generation_id' => $other->id, 'model_version_id' => $agents->first()->model_version_id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'g98_council', 'lifecycle_status' => 'draft']));
        }

        $result = $this->emptyQueueService(1)->inspect(null, $generation->id);
        $this->assertFalse($result['safe']);
        $this->assertSame($reason, $result['reason']);
    }

    public static function unusedDraftConstructionFailures(): array
    {
        return [
            ['partial_population', 'UNSEALED_DRAFT_CONSTRUCTION_INCOMPLETE'],
            ['audit_mismatch', 'UNSEALED_DRAFT_CONSTRUCTION_INCOMPLETE'],
            ['plan_missing', 'UNSEALED_DRAFT_CONSTRUCTION_INCOMPLETE'],
            ['agent_queued', 'UNSEALED_DRAFT_AGENT_OR_MODEL_NOT_UNUSED'],
            ['model_missing', 'UNSEALED_DRAFT_CONSTRUCTION_INCOMPLETE'],
            ['slot_duplicate', 'UNSEALED_DRAFT_CONSTRUCTOR_SLOT_INVALID'],
            ['wrong_slot_family', 'UNSEALED_DRAFT_CONSTRUCTOR_SLOT_INVALID'],
            ['foreign_ownership', 'UNSEALED_DRAFT_MODEL_OWNERSHIP_INVALID'],
        ];
    }

    #[DataProvider('unusedDraftForbiddenMarkers')]
    public function test_unused_draft_refuses_original_seals_and_separate_owners(string $scope, string $path, array $marker, string $reason): void
    {
        $generation = $this->unusedDraft();
        if ($scope === 'generation') {
            $context = (array) $generation->trigger_context;
            data_set($context, $path, $marker);
            $generation->update(['trigger_context' => $context]);
        } else {
            $model = $generation->agents()->first()->modelVersion;
            $metadata = (array) $model->metadata;
            data_set($metadata, $path, $marker);
            $model->update(['metadata' => $metadata]);
        }
        $result = $this->emptyQueueService(1)->inspect(null, $generation->id);
        $this->assertFalse($result['safe']);
        $this->assertSame($reason, $result['reason']);
    }

    public static function unusedDraftForbiddenMarkers(): array
    {
        $admission = 'UNSEALED_DRAFT_ADMISSION_OR_EXECUTION_EVIDENCE';
        $owner = 'UNSEALED_DRAFT_SEPARATE_EXPERIMENT_OWNER';
        return [
            ['generation', 'research_release', ['source_hash' => str_repeat('a', 64)], $admission],
            ['generation', 'mtf_bundle_manifest', ['bundle_hash' => str_repeat('b', 64)], $admission],
            ['generation', 'queue_batches', ['screening' => ['original-batch']], $admission],
            ['generation', 'prequeue_receipt', ['protocol' => 'generation_snapshot_admission_v1', 'allowed' => true], $admission],
            ['generation', 'native_specialist_council_intent', ['followup_work_item_id' => 7], $owner],
            ['generation', 'authorized_specialist_council_panel_intent', ['work_item_id' => 8], $owner],
            ['generation', 'specialist_council_preparation', ['version_id' => 9], $owner],
            ['generation', 'native_spread_context_study', ['study_id' => 'prospective-study'], $owner],
            ['generation', 'academy_experiment', ['trial_id' => 10], $owner],
            ['model', 'last_screen_result', ['status' => 'completed'], $admission],
            ['model', 'last_result', ['status' => 'technical_error'], $admission],
            ['model', 'release_receipt', ['protocol' => 'prospective_research_release_v1'], $admission],
            ['model', 'native_specialist_council_seed', ['slot_role' => 'candidate_carrier'], $owner],
            ['model', 'authorized_specialist_council_panel_seed', ['work_item_id' => 8], $owner],
            ['model', 'specialist_council', ['version_id' => 9], $owner],
            ['model', 'native_spread_context_study', ['study_id' => 'prospective-study'], $owner],
            ['model', 'academy_control_admission', ['trial_id' => 10], $owner],
        ];
    }

    #[DataProvider('unusedDraftHistoricalEvidence')]
    public function test_unused_draft_cannot_hide_original_attempts_or_queue_history(string $evidence): void
    {
        $generation = $this->unusedDraft();
        $agent = $generation->agents()->first();
        if ($evidence === 'run') {
            LabEvaluationRun::create(['run_id' => (string) Str::uuid(), 'lab_generation_id' => $generation->id,
                'lab_agent_id' => $agent->id, 'model_version_id' => $agent->model_version_id,
                'phase' => 'screening', 'attempt' => 1, 'status' => 'technical_error']);
        } elseif ($evidence === 'artifact') {
            LabEvidenceArtifact::create(['artifact_id' => (string) Str::uuid(), 'lab_generation_id' => $generation->id,
                'lab_agent_id' => $agent->id, 'artifact_type' => 'request_snapshot', 'sha256' => str_repeat('a', 64),
                'recorded_at' => now()]);
        } elseif ($evidence === 'candle') {
            LabCandleDecisionEvent::create(['decision_id' => (string) Str::uuid(), 'lab_generation_id' => $generation->id,
                'lab_agent_id' => $agent->id, 'event_type' => 'signal_evaluation', 'recorded_at' => now()]);
        } elseif ($evidence === 'credit') {
            LabMutationCreditEvent::create(['lab_generation_id' => $generation->id, 'lab_agent_id' => $agent->id,
                'model_version_id' => $agent->model_version_id, 'parameter_key' => 'entry_threshold',
                'outcome' => 'withheld', 'recorded_at' => now()]);
        } elseif ($evidence === 'scalar') {
            LabAgent::withoutEvents(fn () => $agent->update(['train_score' => 0]));
        } else {
            LabLifecycleEvent::create(['event_id' => (string) Str::uuid(), 'lab_generation_id' => $generation->id,
                'lab_agent_id' => $agent->id, 'phase' => 'screening', 'event_type' => 'status_changed',
                'from_status' => 'draft', 'to_status' => 'queued', 'occurred_at' => now()]);
        }
        $result = $this->emptyQueueService(1)->inspect(null, $generation->id);
        $this->assertFalse($result['safe']);
        $this->assertSame('UNSEALED_DRAFT_ADMISSION_OR_EXECUTION_EVIDENCE', $result['reason']);
    }

    public static function unusedDraftHistoricalEvidence(): array
    {
        return [['run'], ['artifact'], ['candle'], ['credit'], ['scalar'], ['queue_history']];
    }

    public function test_unused_draft_refuses_unknown_or_nonempty_queues(): void
    {
        foreach ([['total' => null], ['pending' => null], ['delayed' => null], ['total' => '0'], ['total' => -1],
            ['total' => 1], ['pending' => 1], ['delayed' => 1]] as $delta) {
            $queues = Mockery::mock(LabQueueJobInspector::class);
            $queues->shouldReceive('reservedQueueBacklog')->once()->andReturn([
                'total' => 0, 'pending' => 0, 'delayed' => 0, 'queues' => [], ...$delta]);
            $result = (new RuntimeReloadPreflightService($queues))->inspect(null, 357);
            $this->assertFalse($result['safe']);
            $this->assertSame(in_array(1, $delta, true) ? 'QUEUE_WORK_REMAINS' : 'QUEUE_STATE_UNKNOWN', $result['reason']);
        }
    }

    public function test_unused_draft_refuses_running_pause_safety_halt_and_unknown_control(): void
    {
        $generation = $this->unusedDraft();
        $service = $this->emptyQueueService(5);
        $mode = Mockery::mock(AutonomousModeService::class);
        $mode->shouldReceive('status')->times(5)->andReturn(
            ['enabled' => true, 'state' => 'running'], ['enabled' => false, 'state' => 'paused'],
            ['enabled' => false, 'state' => 'pausing'], ['enabled' => false, 'state' => 'safety_halt'], []);
        $this->app->instance(AutonomousModeService::class, $mode);
        foreach (range(1, 5) as $_) $this->assertSame('DRAIN_FIRST_STOP_REQUIRED', $service->inspect(null, $generation->id)['reason']);
    }

    public function test_unused_draft_refuses_constructor_owner_and_lease_without_changing_them(): void
    {
        $generation = $this->unusedDraft();
        $service = $this->emptyQueueService(3);
        $key = 'lab-population-constructor:XAUUSD:H1:v1';
        Cache::put($key.':owner', ['generation_id' => $generation->id, 'pid' => 42], 60);
        $this->assertSame('CONSTRUCTOR_OWNER_OR_LEASE_EXISTS', $service->inspect(null, $generation->id)['reason']);
        $this->assertSame(42, data_get(Cache::get($key.':owner'), 'pid'));
        Cache::forget($key.':owner');
        $lock = Cache::lock($key, 60, 'original-constructor-owner');
        $this->assertTrue($lock->get());
        $this->assertSame('CONSTRUCTOR_OWNER_OR_LEASE_EXISTS', $service->inspect(null, $generation->id)['reason']);
        $this->assertTrue($lock->isOwnedByCurrentProcess());
        $lock->release();
        $this->assertTrue($service->inspect(null, $generation->id)['safe']);
    }

    public function test_unused_draft_refuses_missing_competing_target_or_ambiguous_mode(): void
    {
        $generation = $this->unusedDraft();
        $service = $this->emptyQueueService(3);
        $this->assertSame('UNSEALED_DRAFT_TARGET_NOT_SOLE_ACTIVE', $service->inspect(null, $generation->id + 1)['reason']);
        $this->assertSame('COLD_MAINTENANCE_MODE_AMBIGUOUS', $service->inspect($generation->id, $generation->id)['reason']);
        LabGeneration::create(['ai_laboratory_id' => $generation->ai_laboratory_id, 'generation' => 2,
            'status' => 'queued', 'population_size' => 1, 'trigger_type' => 'historical_research']);
        $this->assertSame('UNSEALED_DRAFT_TARGET_NOT_SOLE_ACTIVE', $service->inspect(null, $generation->id)['reason']);
    }

    public function test_unused_draft_refuses_cache_stores_whose_reads_can_mutate_expired_owner_records(): void
    {
        $generation = $this->unusedDraft();
        config(['cache.default' => 'database']);
        $result = $this->emptyQueueService(1)->inspect(null, $generation->id);
        $this->assertFalse($result['safe']);
        $this->assertSame('CONSTRUCTOR_STATE_UNKNOWN', $result['reason']);
    }

    public function test_unused_draft_cli_requires_explicit_single_valid_target(): void
    {
        $generation = $this->unusedDraft();
        $queues = Mockery::mock(LabQueueJobInspector::class);
        $queues->shouldReceive('reservedQueueBacklog')->times(3)->andReturn(['total' => 0, 'pending' => 0, 'delayed' => 0, 'queues' => []]);
        $this->app->instance(LabQueueJobInspector::class, $queues);
        $this->artisan('system:runtime-reload-preflight', ['--json' => true])->assertExitCode(1);
        $this->artisan('system:runtime-reload-preflight', ['--json' => true, '--unsealed-draft-maintenance' => (string) $generation->id])->assertExitCode(0);
        $this->artisan('system:runtime-reload-preflight', ['--json' => true, '--unsealed-draft-maintenance' => '0'])->assertExitCode(1);
        $this->artisan('system:runtime-reload-preflight', ['--json' => true, '--unsealed-draft-maintenance' => (string) $generation->id,
            '--terminal-projection-recovery' => (string) $generation->id])->assertExitCode(1);
    }

    private function unusedDraft(): LabGeneration
    {
        config(['services.autonomous_mode.default_enabled' => false]);
        $lab = AiLaboratory::create(['name' => 'Unused complete draft cold maintenance', 'symbol' => 'XAUUSD',
            'timeframe' => 'H1', 'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $plan = array_fill(0, 20, ['family' => 'hybrid', 'origin' => 'g98_council', 'target' => 'novelty_pair']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'status' => 'draft', 'population_size' => 20, 'trigger_type' => 'historical_research', 'started_at' => now(),
            'trigger_context' => ['generation_plan' => $plan, 'constructor_audit' => ['protocol' => 'agent_constructor_invariant_v1',
                'planned_slots' => 20, 'created_agents' => 20, 'skipped_zero_diff_slots' => []],
                'native_specialist_council_intent' => null, 'authorized_specialist_council_panel_intent' => null,
                'canonical_dataset_snapshots' => ['foundation' => ['sha256' => str_repeat('a', 64)], 'price' => ['sha256' => str_repeat('b', 64)]],
                'specialist_council_contract' => ['protocol' => 'ordinary_population_constructor_descriptor', 'promotion_evidence' => false]]]);
        MarketTrainingArchive::create(['dataset_key' => 'foundation_intraday_gapfix_original', 'provider' => 'dukascopy',
            'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'status' => 'complete', 'row_count' => 200237,
            'target_from' => '2022-01-01 00:00:00', 'target_to' => '2025-12-31 23:59:59',
            'metrics' => ['frozen_m5_gap_recovery_receipt' => ['calendar_scope' => ['protocol' => 'frozen_source_calendar_scope_audit_v2',
                'full_source_unexpected_after' => 17, 'screening_unexpected_after' => 0, 'whole_archive_continuity_proven' => false]]]]);
        foreach (range(1, 20) as $slot) {
            $strategy = 'xauusd_hybrid_g1_a'.str_pad((string) $slot, 2, '0', STR_PAD_LEFT);
            $model = ModelVersion::create(['name' => $strategy, 'strategy' => $strategy, 'version' => 'v1',
                'generation' => 1, 'status' => 'testing', 'parameters' => ['entry_threshold' => 1],
                'metadata' => ['lab_symbol' => 'XAUUSD', 'lab_timeframe' => 'H1', 'origin' => 'g98_council',
                    'generation_target' => 'novelty_pair', 'execution_contract' => ['protocol' => 'constructor_execution_declaration']]]);
            $agent = LabAgent::withoutEvents(fn () => LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'g98_council', 'lifecycle_status' => 'draft']));
            LabLifecycleEvent::create(['event_id' => (string) Str::uuid(), 'lab_generation_id' => $generation->id,
                'lab_agent_id' => $agent->id, 'phase' => 'creation', 'event_type' => 'agent_created', 'to_status' => 'draft',
                'source' => 'LabAgentObserver', 'occurred_at' => now()]);
        }
        return $generation;
    }

    private function emptyQueueService(int $calls): RuntimeReloadPreflightService
    {
        $queues = Mockery::mock(LabQueueJobInspector::class);
        $queues->shouldReceive('reservedQueueBacklog')->times($calls)->andReturn([
            'total' => 0, 'queues' => [], 'pending' => 0, 'delayed' => 0,
        ]);
        return new RuntimeReloadPreflightService($queues);
    }

    private function activeProjection(): LabGeneration
    {
        $lab = AiLaboratory::create(['name' => 'Projection-only cold maintenance', 'symbol' => 'XAUUSD',
            'timeframe' => 'H1', 'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        return LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'status' => 'screening', 'population_size' => 6, 'trigger_type' => 'edge_genesis']);
    }
}
