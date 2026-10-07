<?php

namespace Tests\Feature;

use App\Models\ResearchExperimentWorkItem;
use App\Services\ResearchClosureInvariantService;
use App\Services\ResearchExperimentConversionKernelService;
use App\Services\ActivationValidationPlanService;
use App\Services\ResearchLoopArbiterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ResearchExperimentConversionKernelServiceTest extends TestCase
{
    use RefreshDatabase;

    private function contract(): array
    {
        return ['contract_version' => ResearchExperimentConversionKernelService::CONTRACT_VERSION,
            'source' => ['type' => 'fixture', 'id' => 7],
            'scope' => ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5'],
            'identity' => ['baseline_epoch_hash' => 'baseline', 'data_and_mtf_hash' => 'data', 'runtime_and_contract_hash' => 'runtime', 'intervention_hash' => 'intervention', 'window_plan_hash' => 'window', 'evaluator_version' => 'v1'],
            'arms' => [['role' => 'frozen_control'], ['role' => 'candidate']], 'revisions' => ['subject' => 2, 'evidence' => 3]];
    }

    public function test_terminal_receipt_and_next_work_are_idempotent_and_stale_completion_is_fenced(): void
    {
        $kernel = app(ResearchExperimentConversionKernelService::class);
        $one = $kernel->record($this->contract(), ['settlement_id' => 9], 'INCONCLUSIVE', ['type' => 'academy_repair', 'identity' => 'fixture']);
        $two = $kernel->record($this->contract(), ['settlement_id' => 9], 'INCONCLUSIVE', ['type' => 'academy_repair', 'identity' => 'fixture']);

        $this->assertSame($one['receipt_id'], $two['receipt_id']);
        $this->assertSame($one['work_id'], $two['work_id']);
        $first = $kernel->claim(1)[0];
        $first->update(['lease_expires_at' => now()->subSecond()]);
        $second = $kernel->claim(1)[0];

        $this->assertFalse($kernel->complete($first, ['status' => 'stale']));
        $this->assertTrue($kernel->complete($second, ['status' => 'done']));
        $this->assertSame('settled', ResearchExperimentWorkItem::query()->find($second->id)->status);
    }

    public function test_terminal_closure_requires_exactly_one_next_work_or_reason(): void
    {
        $kernel = app(ResearchExperimentConversionKernelService::class);

        $neither = $kernel->record($this->contract(), ['settlement_id' => 10], 'INCONCLUSIVE');
        $both = $kernel->record($this->contract(), ['settlement_id' => 11], 'INCONCLUSIVE',
            ['type' => 'replication'], ['code' => 'TERMINAL']);

        $this->assertSame('RESEARCH_CLOSURE_EXACTLY_ONE_OUTCOME_REQUIRED', $neither['reason']);
        $this->assertSame('RESEARCH_CLOSURE_EXACTLY_ONE_OUTCOME_REQUIRED', $both['reason']);
        $this->assertDatabaseCount('research_experiment_receipts', 0);
    }

    public function test_canonical_closure_recovers_expired_ownership_before_selection_and_fences_old_delivery(): void
    {
        $kernel = app(ResearchExperimentConversionKernelService::class);
        $kernel->record($this->contract(), ['settlement_id' => 19], 'INCONCLUSIVE',
            ['type' => 'academy_repair', 'identity' => 'expired-closure']);
        $old = $kernel->claimForOwner(ResearchLoopArbiterService::class, 1)[0];
        $originalResult = ['dependency_hold' => ['reason' => 'ORIGINAL_OPERATIONAL_HOLD',
            'prerequisite_hash' => str_repeat('a', 64), 'promotion_evidence' => false]];
        $old->update(['lease_expires_at' => now()->subSecond(), 'result' => $originalResult]);
        $originalPayload = $old->payload;
        $closure = app(ResearchClosureInvariantService::class);

        $dry = $closure->inspect('XAUUSD', 'H1', false);
        $this->assertFalse($dry['healthy']);
        $this->assertSame([$old->id], $dry['expired_lease_work_ids']);
        $this->assertSame('leased', $old->fresh()->status);

        $repaired = $closure->inspect('XAUUSD', 'H1', true);
        $current = $old->fresh();
        $this->assertTrue($repaired['healthy']);
        $this->assertSame([], $repaired['expired_lease_work_ids']);
        $this->assertSame(1, data_get($repaired, 'metadata_reconciliation.expired_leases_recovered'));
        $this->assertSame('ready', $current->status);
        $this->assertSame($old->attempts, $current->attempts);
        $this->assertSame($old->fence_version, $current->fence_version);
        $this->assertSame($originalPayload, $current->payload);
        $this->assertSame($originalResult, $current->result);
        $this->assertNull($current->lease_token);
        $this->assertNull($current->lease_expires_at);
        $this->assertNull($current->heartbeat_at);
        $this->assertSame('LEASE_EXPIRED', $current->last_error);
        $this->assertSame(0, $kernel->reconcileOwnershipAndDependencies()['expired_leases_recovered']);

        $consumer = app(\App\Services\ResearchExperimentWorkConsumerService::class);
        $stale = $consumer->execute($old->id, $old->lease_token, $old->fence_version);
        $this->assertSame('WORK_LEASE_NOT_CURRENT', $stale['reason']);
        $fresh = $kernel->claimForOwner(ResearchLoopArbiterService::class, 1)[0];
        $this->assertSame($old->id, $fresh->id);
        $this->assertSame($old->attempts + 1, $fresh->attempts);
        $this->assertSame($old->fence_version + 1, $fresh->fence_version);
        $this->assertNotSame($old->lease_token, $fresh->lease_token);
        $this->assertSame($originalResult, $fresh->result);
        $this->assertSame('WORK_LEASE_NOT_CURRENT', $consumer->execute($old->id, $old->lease_token, $old->fence_version)['reason']);
        $this->assertFalse($kernel->complete($old, ['forged_stale_completion' => true]));
        $this->assertDatabaseCount('research_experiment_receipts', 1);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_expired_recovery_does_not_change_live_settled_or_undated_lease_rows(): void
    {
        $kernel = app(ResearchExperimentConversionKernelService::class);
        foreach (['live', 'settled', 'undated'] as $index => $kind) {
            $contract = $this->contract(); $contract['source']['id'] = 801 + $index;
            $row = $kernel->record($contract, ['kind' => $kind], 'INCONCLUSIVE',
                ['type' => 'academy_repair', 'identity' => 'untouched-'.$kind]);
            $work = ResearchExperimentWorkItem::findOrFail($row['work_id']);
            $work->update(['status' => $kind === 'settled' ? 'settled' : 'leased',
                'attempts' => 4, 'fence_version' => 7, 'lease_token' => 'existing-'.$kind,
                'lease_expires_at' => $kind === 'undated' ? null : ($kind === 'live' ? now()->addHour() : now()->subHour()),
                'heartbeat_at' => now(), 'result' => ['original_checkpoint' => $kind],
                'completed_at' => $kind === 'settled' ? now() : null]);
            $before = $work->fresh()->getRawOriginal();
            $this->assertSame(0, $kernel->reconcileOwnershipAndDependencies()['expired_leases_recovered']);
            $this->assertSame($before, $work->fresh()->getRawOriginal());
        }
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_expired_recovery_rechecks_a_concurrently_renewed_lease(): void
    {
        $kernel = app(ResearchExperimentConversionKernelService::class);
        $row = $kernel->record($this->contract(), ['settlement_id' => 29], 'INCONCLUSIVE',
            ['type' => 'academy_repair', 'identity' => 'renewed-lease']);
        $work = ResearchExperimentWorkItem::findOrFail($row['work_id']);
        $work->update(['status' => 'leased', 'attempts' => 2, 'fence_version' => 2,
            'lease_token' => 'old-token', 'lease_expires_at' => now()->subMinute(),
            'result' => ['untouched' => true]]);
        $renewedAt = now()->addHour()->startOfSecond();
        $injected = false;
        ResearchExperimentWorkItem::retrieved(function ($snapshot) use ($work, $renewedAt, &$injected): void {
            if ($injected || $snapshot->id !== $work->id) return;
            $injected = true;
            // Test-only interleaving after discovery/locked hydration: the
            // conditional update must still refuse a newer ownership fence.
            DB::table('research_experiment_work_items')->where('id', $work->id)->update([
                'lease_expires_at' => $renewedAt, 'lease_token' => 'renewed-token', 'fence_version' => 3,
            ]);
        });
        try { $result = $kernel->reconcileOwnershipAndDependencies(); }
        finally { Event::forget('eloquent.retrieved: '.ResearchExperimentWorkItem::class); }
        $current = $work->fresh();
        $this->assertTrue($injected);
        $this->assertSame(0, $result['expired_leases_recovered']);
        $this->assertSame('leased', $current->status);
        $this->assertSame('renewed-token', $current->lease_token);
        $this->assertSame(3, $current->fence_version);
        $this->assertTrue($current->lease_expires_at->equalTo($renewedAt));
        $this->assertSame(2, $current->attempts);
        $this->assertSame(['untouched' => true], $current->result);
    }

    public function test_expired_recovery_is_bounded_and_keeps_attempt_and_scientific_budget_history(): void
    {
        $kernel = app(ResearchExperimentConversionKernelService::class);
        $row = $kernel->record($this->contract(), ['settlement_id' => 39], 'INCONCLUSIVE',
            ['type' => 'academy_repair', 'identity' => 'bounded-expiry']);
        $original = ResearchExperimentWorkItem::findOrFail($row['work_id']);
        $original->update(['status' => 'leased', 'attempts' => 8, 'fence_version' => 12,
            'lease_token' => 'original-token', 'lease_expires_at' => now()->subMinute(),
            'result' => ['scientific_attempts_already_used' => 1]]);
        for ($i = 1; $i <= 100; ++$i) {
            $copy = $original->replicate();
            $copy->work_key = hash('sha256', 'bounded-expired-work-'.$i);
            $copy->save();
        }
        $result = $kernel->reconcileOwnershipAndDependencies();
        $this->assertSame(100, $result['expired_leases_recovered']);
        $this->assertSame(1, ResearchExperimentWorkItem::where('status', 'leased')->count());
        $this->assertSame(101, ResearchExperimentWorkItem::where('attempts', 8)->where('fence_version', 12)->count());
        $this->assertSame(101, ResearchExperimentWorkItem::where('result->scientific_attempts_already_used', 1)->count());
        $this->assertSame(1, $kernel->reconcileOwnershipAndDependencies()['expired_leases_recovered']);
        $this->assertSame(0, $kernel->reconcileOwnershipAndDependencies()['expired_leases_recovered']);
        $this->assertDatabaseCount('research_experiment_receipts', 1);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_activation_continuation_requires_the_preregistered_plan_hash(): void
    {
        $kernel = app(ResearchExperimentConversionKernelService::class);
        $proposal = [
            'hypothesis_key' => str_repeat('a', 64),
            'source_data_hash' => str_repeat('b', 64),
            'source_response_hash' => str_repeat('c', 64),
            'source_execution_hash' => str_repeat('d', 64),
            'source_mtf_bundle_hash' => str_repeat('e', 64),
        ];
        $plan = app(ActivationValidationPlanService::class)->reserve($proposal);
        $contract = $this->contract();
        $invalid = $kernel->record($contract, ['settlement_id' => 13], 'BEHAVIORAL_ACTIVATION_HYPOTHESIS', [
            'type' => 'activation_independent_validation', 'validation_plan' => $plan,
        ]);
        $this->assertSame('ACTIVATION_VALIDATION_PLAN_INVALID', $invalid['reason']);
        $this->assertDatabaseCount('research_experiment_receipts', 0);

        $contract['identity']['window_plan_hash'] = $plan['plan_hash'];
        $valid = $kernel->record($contract, ['settlement_id' => 13], 'BEHAVIORAL_ACTIVATION_HYPOTHESIS', [
            'type' => 'activation_independent_validation', 'validation_plan' => $plan,
        ]);
        $this->assertSame('recorded', $valid['status']);
        $this->assertSame('blocked', ResearchExperimentWorkItem::query()->sole()->status);
        $this->assertFalse((bool) data_get(ResearchExperimentWorkItem::query()->sole()->payload, 'executable'));
        $this->assertSame([], $kernel->claimForOwner(ResearchLoopArbiterService::class));
    }

    public function test_next_work_is_owned_retry_bounded_and_visible_to_closure_truth(): void
    {
        $kernel = app(ResearchExperimentConversionKernelService::class);
        $kernel->record($this->contract(), ['settlement_id' => 12], 'UNDERPOWERED',
            ['type' => 'academy_power_extension', 'identity' => 'power']);
        $work = ResearchExperimentWorkItem::query()->sole();

        $this->assertSame('blocked', $work->status);
        $this->assertSame(\App\Services\ResearchLoopArbiterService::class, data_get($work->payload, 'owner'));
        $this->assertSame('NEW_INDEPENDENT_POWERED_WINDOW_REQUIRED', data_get($work->payload, 'retry_condition.code'));
        $closure = app(ResearchClosureInvariantService::class)->inspect('XAUUSD', 'H1');
        $this->assertTrue($closure['healthy']);
        $this->assertSame(1, data_get($closure, 'work.blocked_with_explicit_retry'));
    }

    public function test_unadmitted_instrument_transfer_cannot_be_made_executable_by_the_caller(): void
    {
        $kernel = app(ResearchExperimentConversionKernelService::class);
        $result = $kernel->record($this->contract(), ['hypothesis_only' => true], 'INCONCLUSIVE', [
            'type' => 'instrument_exact_delta_transfer', 'identity' => 'unadmitted-transfer', 'executable' => true,
            'retry_condition' => ['code' => 'CALLER_ADMITTED', 'max_experiments' => 99, 'same_evidence_replay_forbidden' => false],
        ]);
        $work = ResearchExperimentWorkItem::findOrFail($result['work_id']);

        $this->assertSame('blocked', $work->status);
        $this->assertFalse(data_get($work->payload, 'executable'));
        $this->assertSame('CANONICAL_TRANSFER_ADMISSION_AND_AUTHORIZED_UNUSED_WINDOW_REQUIRED', data_get($work->payload, 'retry_condition.code'));
        $this->assertSame(1, data_get($work->payload, 'retry_condition.max_experiments'));
        $this->assertTrue(data_get($work->payload, 'retry_condition.same_evidence_replay_forbidden'));
        $this->assertSame([], $kernel->claimForOwner(ResearchLoopArbiterService::class));
    }

    public function test_owner_claim_cannot_be_starved_by_another_owners_priority_rows(): void
    {
        $kernel = app(ResearchExperimentConversionKernelService::class);
        for ($id = 1; $id <= 21; $id++) {
            $contract = $this->contract();
            $contract['source']['id'] = 100 + $id;
            $kernel->record($contract, ['settlement_id' => 100 + $id], 'INCONCLUSIVE', [
                'type' => 'foreign_work',
                'identity' => 'foreign-'.$id,
                'priority' => 9,
                'owner' => 'ExternalOwner',
            ]);
        }
        $contract = $this->contract();
        $contract['source']['id'] = 999;
        $kernel->record($contract, ['settlement_id' => 999], 'INCONCLUSIVE', [
            'type' => 'arbiter_work',
            'identity' => 'arbiter',
            'priority' => 1,
        ]);

        $claimed = $kernel->claimForOwner(ResearchLoopArbiterService::OWNER, 1);

        $this->assertCount(1, $claimed);
        $this->assertSame('arbiter_work', $claimed[0]->work_type);
        $this->assertSame('leased', $claimed[0]->status);
        $this->assertSame(21, ResearchExperimentWorkItem::query()
            ->where('status', 'ready')->where('payload->owner', 'ExternalOwner')->count());
    }

    public function test_chunk_identity_is_reread_before_preserving_a_concurrent_council_registration(): void
    {
        $kernel = app(ResearchExperimentConversionKernelService::class);
        $row = $kernel->record($this->contract(), ['technical' => true], 'TECHNICAL_QUARANTINE', [
            'type' => 'specialist_council_technical_repair', 'identity' => 'concurrent-registration',
        ]);
        $work = ResearchExperimentWorkItem::findOrFail($row['work_id']);
        $legacy = $work->payload; unset($legacy['executor']);
        $work->update(['payload' => $legacy]);
        $concurrent = [...$legacy, 'executor' => \App\Services\ResearchExperimentWorkConsumerService::class,
            'followup_resolution' => ['test_projection_only' => true, 'resolution_hash' => str_repeat('a', 64)]];
        $this->mock(\App\Services\SpecialistCouncilResearchFeedbackService::class, fn ($mock) => $mock
            ->shouldReceive('inspectFollowupReadiness')->andReturn(['executable' => false, 'reason' => 'TEST_PROOF_REMAINS_BLOCKED']));
        $injected = false;
        ResearchExperimentWorkItem::retrieved(function ($snapshot) use ($work, $concurrent, &$injected): void {
            if ($injected || $snapshot->id !== $work->id) return;
            $injected = true;
            // Deterministic test-only interleaving: another original owner
            // commits after the chunk captured its stale row.
            DB::table('research_experiment_work_items')->where('id', $work->id)
                ->update(['payload' => json_encode($concurrent, JSON_THROW_ON_ERROR)]);
        });
        try { $kernel->reconcileOwnershipAndDependencies(); }
        finally { Event::forget('eloquent.retrieved: '.ResearchExperimentWorkItem::class); }
        $current = $work->fresh();
        $this->assertTrue($injected);
        $this->assertSame($concurrent['followup_resolution'], $current->payload['followup_resolution']);
        $this->assertFalse($current->payload['executable']);
        $this->assertSame('blocked', $current->status);
        $this->assertSame(0, $current->attempts);
        $this->assertNull($current->lease_token);
    }

    public function test_chunk_reconciliation_does_not_reopen_a_newly_leased_or_settled_council_checkpoint(): void
    {
        $kernel = app(ResearchExperimentConversionKernelService::class);
        $this->mock(\App\Services\SpecialistCouncilResearchFeedbackService::class, fn ($mock) => $mock
            ->shouldNotReceive('inspectFollowupReadiness'));
        foreach (['leased', 'settled'] as $status) {
            $contract = $this->contract(); $contract['source']['id'] = $status === 'leased' ? 701 : 702;
            $row = $kernel->record($contract, ['technical' => true], 'TECHNICAL_QUARANTINE', [
                'type' => 'specialist_council_technical_repair', 'identity' => 'concurrent-'.$status,
            ]);
            $work = ResearchExperimentWorkItem::findOrFail($row['work_id']);
            $payload = [...$work->payload, 'followup_resolution' => ['test_projection_only' => true, 'resolution_hash' => str_repeat('b', 64)]];
            $result = ['generation_id' => 891, 'original_checkpoint' => true];
            $token = $status === 'leased' ? 'original-worker-token' : null;
            $injected = false;
            ResearchExperimentWorkItem::retrieved(function ($snapshot) use ($work, $payload, $result, $status, $token, &$injected): void {
                if ($injected || $snapshot->id !== $work->id) return;
                $injected = true;
                DB::table('research_experiment_work_items')->where('id', $work->id)->update([
                    'status' => $status, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'result' => json_encode($result, JSON_THROW_ON_ERROR), 'attempts' => 3, 'fence_version' => 5,
                    'lease_token' => $token, 'completed_at' => $status === 'settled' ? now() : null,
                ]);
            });
            try { $kernel->reconcileOwnershipAndDependencies(); }
            finally { Event::forget('eloquent.retrieved: '.ResearchExperimentWorkItem::class); }
            $current = $work->fresh();
            $this->assertTrue($injected);
            $this->assertSame($status, $current->status);
            $this->assertSame($payload, $current->payload);
            $this->assertSame($result, $current->result);
            $this->assertSame(3, $current->attempts);
            $this->assertSame(5, $current->fence_version);
            $this->assertSame($token, $current->lease_token);
        }
    }
}
