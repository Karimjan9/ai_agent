<?php

namespace Tests\Feature;

use App\Jobs\RunScheduledArtisanCommandJob;
use App\Models\LabGeneration;
use App\Models\ResearchExperimentWorkItem;
use App\Services\AutonomousModeService;
use App\Services\LabPopulationService;
use App\Services\ResearchExperimentConversionKernelService;
use App\Services\ResearchExperimentWorkConsumerService;
use App\Services\ResearchLoopArbiterService;
use App\Services\SpecialistCouncilFollowupExecutionService;
use App\Services\SpecialistCouncilResearchFeedbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/** Operational lease fixtures are not independent market or economic evidence. */
class ResearchWorkLeaseBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function work(string $type = 'ordinary_work', string $owner = ResearchLoopArbiterService::class): ResearchExperimentWorkItem
    {
        $result = app(ResearchExperimentConversionKernelService::class)->record([
            'contract_version' => ResearchExperimentConversionKernelService::CONTRACT_VERSION,
            'source' => ['type' => 'lease-budget-fixture', 'id' => 1],
            'scope' => ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5'],
            'identity' => ['baseline_epoch_hash' => 'base', 'data_and_mtf_hash' => 'data', 'runtime_and_contract_hash' => 'runtime',
                'intervention_hash' => 'delta', 'window_plan_hash' => 'window', 'evaluator_version' => 'fixture'],
            'arms' => [['role' => 'candidate'], ['role' => 'control']],
        ], ['fixture_not_market_proof' => true], 'INCONCLUSIVE', ['type' => $type, 'owner' => $owner]);
        $work = ResearchExperimentWorkItem::findOrFail($result['work_id']);
        $work->update(['status' => 'ready', 'payload' => [...$work->payload,
            'owner' => $owner, 'executor' => ResearchExperimentWorkConsumerService::class,
            'executable' => true, 'followup_resolution' => ['resolution_hash' => str_repeat('a', 64)],
            'lease_seconds' => 999999, 'authority' => 'economic_parent']]);
        return $work->fresh();
    }

    private function proof(ResearchExperimentWorkItem $work): array
    {
        return ['protocol' => SpecialistCouncilResearchFeedbackService::FOLLOWUP_PROTOCOL, 'executable' => true,
            'authority' => 'research_only', 'work_item_id' => $work->id, 'work_key' => $work->work_key,
            'source_receipt_id' => $work->research_experiment_receipt_id, 'resolution_hash' => str_repeat('a', 64),
            'max_experiments' => 1, 'promotion_evidence' => false];
    }

    public function test_ordinary_initial_lease_remains_900_seconds_regardless_of_caller_budget(): void
    {
        $this->freezeTime();
        $this->work();
        $this->mock(SpecialistCouncilResearchFeedbackService::class)->shouldNotReceive('inspectFollowupReadiness');
        $work = app(ResearchExperimentConversionKernelService::class)->claim(1)[0];
        $this->assertSame(900, $work->lease_expires_at->timestamp - now()->timestamp);
        $this->assertSame(1, $work->attempts);
        $this->assertSame(1, $work->fence_version);
    }

    public function test_server_attested_discovery_initial_lease_covers_scheduled_child_but_not_constructor_mutex(): void
    {
        $this->freezeTime();
        $work = $this->work('specialist_council_technical_repair');
        $this->mock(SpecialistCouncilResearchFeedbackService::class)->shouldReceive('inspectFollowupReadiness')->once()
            ->withArgs(fn ($actual) => $actual->id === $work->id && $actual->status === 'ready')->andReturn($this->proof($work));
        $work = app(ResearchExperimentConversionKernelService::class)->claim(1)[0];
        $seconds = $work->lease_expires_at->timestamp - now()->timestamp;
        $job = new RunScheduledArtisanCommandJob('trading:consume-research-work', [], 'scheduler-constructor');
        $this->assertSame(2700, $seconds);
        $this->assertGreaterThan($job->timeout - 30, $seconds);
        $this->assertLessThan(LabPopulationService::CONSTRUCTOR_LOCK_TTL_SECONDS, $seconds);
        $this->assertFalse(data_get($work->payload, 'promotion_evidence'));
    }

    public function test_expensive_claim_proof_finishes_before_initial_lease_clock_starts(): void
    {
        $this->freezeTime();
        $work = $this->work('specialist_council_power_extension');
        $this->mock(SpecialistCouncilResearchFeedbackService::class)->shouldReceive('inspectFollowupReadiness')->once()
            ->andReturnUsing(function () use ($work): array { $this->travel(360)->seconds(); return $this->proof($work); });
        $lease = app(ResearchExperimentConversionKernelService::class)->claim(1)[0];
        $this->assertSame(2700, $lease->lease_expires_at->timestamp - now()->timestamp);
        $this->assertSame(now()->timestamp, $lease->heartbeat_at->timestamp);
    }

    public function test_forged_executable_payload_cannot_claim_or_extend_a_council_lease(): void
    {
        $work = $this->work('specialist_council_data_repair');
        $this->mock(SpecialistCouncilResearchFeedbackService::class)->shouldReceive('inspectFollowupReadiness')->once()
            ->andReturn(['executable' => false, 'reason' => 'ORIGINAL_SERVER_SEAL_REQUIRED']);
        $this->assertSame([], app(ResearchExperimentConversionKernelService::class)->claim(1));
        $this->assertSame('blocked', $work->fresh()->status);
        $this->assertSame(0, $work->fresh()->attempts);
        $this->assertNull($work->fresh()->lease_token);
    }

    public function test_reconcile_uses_one_original_proof_per_locked_work_and_claim_takes_a_fresh_proof(): void
    {
        $this->freezeTime();
        $work = $this->work('specialist_council_technical_repair');
        $this->mock(SpecialistCouncilResearchFeedbackService::class)->shouldReceive('inspectFollowupReadiness')->twice()
            ->andReturn($this->proof($work), ['executable' => false, 'reason' => 'ORIGINAL_PROOF_CHANGED_AFTER_RECONCILE']);
        $kernel = app(ResearchExperimentConversionKernelService::class);
        $kernel->reconcileOwnershipAndDependencies();
        $this->assertSame('ready', $work->fresh()->status);
        $this->assertSame([], $kernel->claim(1));
        $this->assertSame('blocked', $work->fresh()->status);
        $this->assertSame('ORIGINAL_PROOF_CHANGED_AFTER_RECONCILE', $work->fresh()->last_error);
        $this->assertSame(0, $work->fresh()->attempts);
    }

    public function test_wrong_owner_or_resolution_and_independent_panel_do_not_receive_discovery_budget(): void
    {
        $this->freezeTime();
        foreach (['wrong-owner', 'wrong-resolution', 'independent-panel'] as $case) {
            $type = $case === 'independent-panel' ? 'specialist_council_independent_validation' : 'specialist_council_technical_repair';
            $work = $this->work($type, $case === 'wrong-owner' ? 'ForeignOwner' : ResearchLoopArbiterService::class);
            $proof = $this->proof($work);
            if ($case === 'wrong-resolution') $proof['resolution_hash'] = str_repeat('b', 64);
            $this->mock(SpecialistCouncilResearchFeedbackService::class)->shouldReceive('inspectFollowupReadiness')->once()->andReturn($proof);
            $claimed = app(ResearchExperimentConversionKernelService::class)->claim(1)[0];
            $this->assertSame(900, $claimed->lease_expires_at->timestamp - now()->timestamp);
            $this->assertTrue(app(ResearchExperimentConversionKernelService::class)->complete($claimed, ['status' => 'fixture_complete']));
        }
    }

    public function test_expired_token_and_fence_cannot_write_completion_or_defer_before_reclaim(): void
    {
        $this->freezeTime();
        $this->work(); $kernel = app(ResearchExperimentConversionKernelService::class);
        $work = $kernel->claim(1)[0];
        $forgedToken = clone $work; $forgedToken->lease_token = 'wrong-token';
        $forgedFence = clone $work; $forgedFence->fence_version++;
        foreach ([$forgedToken, $forgedFence] as $forged) {
            $this->assertFalse($kernel->complete($forged, ['forged' => true]));
            $this->assertFalse($kernel->defer($forged, 'FORGED', false));
        }
        $this->travel(900)->seconds();
        $this->assertFalse($kernel->complete($work, ['expired' => true]));
        $this->assertFalse($kernel->defer($work, 'EXPIRED', false));
        $this->assertSame('leased', $work->fresh()->status);
        $this->assertNull($work->fresh()->result);
        $fresh = $kernel->claim(1)[0];
        $this->assertSame(2, $fresh->attempts);
        $this->assertGreaterThan($work->fence_version, $fresh->fence_version);
        $this->assertTrue($kernel->complete($fresh, ['status' => 'done']));
    }

    public function test_discovery_consumer_delegates_one_fresh_proof_to_the_lock_owning_executor(): void
    {
        $this->work('specialist_council_technical_repair');
        $work = ResearchExperimentWorkItem::sole();
        $work->update(['status' => 'leased', 'lease_token' => 'fixture-token', 'fence_version' => 1, 'lease_expires_at' => now()->addSeconds(2700)]);
        $this->mock(AutonomousModeService::class)->shouldReceive('enabled')->andReturnTrue();
        $this->mock(SpecialistCouncilResearchFeedbackService::class)->shouldReceive('inspectFollowupReadiness')->once()
            ->andReturn(['executable' => false, 'reason' => 'ORIGINAL_COUNCIL_PROOF_CHANGED']);
        $result = app(ResearchExperimentWorkConsumerService::class)->execute($work->id, 'fixture-token', 1);
        $this->assertSame('ORIGINAL_COUNCIL_PROOF_CHANGED', $result['reason']);
        $this->assertSame('blocked', $work->fresh()->status);
        $this->assertDatabaseCount('lab_generations', 0);
    }

    public function test_checkpoint_preserves_bounded_deadline_and_expired_consumer_never_executes(): void
    {
        $this->freezeTime();
        $this->work('specialist_council_technical_repair'); $work = ResearchExperimentWorkItem::sole();
        $work->update(['status' => 'leased', 'lease_token' => 'fixture-token', 'fence_version' => 1, 'lease_expires_at' => now()->addSeconds(2700)]);
        $this->mock(AutonomousModeService::class)->shouldReceive('enabled')->andReturnTrue();
        $this->mock(SpecialistCouncilResearchFeedbackService::class)->shouldNotReceive('inspectFollowupReadiness');
        $deadline = $work->fresh()->lease_expires_at->toIso8601String();
        $this->travel(100)->seconds();
        $generation = new LabGeneration; $generation->id = 17;
        (new ReflectionMethod(SpecialistCouncilFollowupExecutionService::class, 'checkpoint'))->invoke(
            app(SpecialistCouncilFollowupExecutionService::class), $work, $generation, ['resolution_hash' => str_repeat('a', 64)], 'constructed');
        $this->assertSame($deadline, $work->fresh()->lease_expires_at->toIso8601String());
        $this->assertSame('constructed', data_get($work->fresh()->result, 'stage'));
        $this->travel(2601)->seconds();
        $result = app(ResearchExperimentWorkConsumerService::class)->execute($work->id, 'fixture-token', 1);
        $this->assertSame('WORK_LEASE_NOT_CURRENT', $result['reason']);
        $this->assertSame('constructed', data_get($work->fresh()->result, 'stage'));
    }
}
