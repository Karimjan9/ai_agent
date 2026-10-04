<?php

namespace Tests\Feature;

use App\Models\ResearchExperimentWorkItem;
use App\Services\ResearchClosureInvariantService;
use App\Services\ResearchExperimentConversionKernelService;
use App\Services\ActivationValidationPlanService;
use App\Services\ResearchLoopArbiterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
