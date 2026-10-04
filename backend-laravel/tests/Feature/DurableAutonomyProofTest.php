<?php

namespace Tests\Feature;

use App\Jobs\RunCausalExperimentFoldJob;
use App\Models\AgentLearningCausalExperiment;
use App\Models\AiLaboratory;
use App\Models\CausalFoldReceipt;
use App\Models\GenerationAutonomyReceipt;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\ResearchLoopDecision;
use App\Services\CausalFoldExecutionService;
use App\Services\CausalLearningCohortService;
use App\Services\GenerationAutonomyAuditService;
use App\Services\GenerationAutonomyReceiptService;
use App\Services\GenerationSnapshotAdmissionService;
use App\Services\LabGenerationContextService;
use App\Services\ResearchLoopArbiterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class DurableAutonomyProofTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminal_screening_triplet_is_invalidated_instead_of_remaining_ready_forever(): void
    {
        [$generation, $experiment, $agents] = $this->causalTriplet(
            'technical_quarantine',
            'ready_for_replay',
        );
        LabAgent::query()->whereIn('id', $agents->pluck('id'))->update([
            'lifecycle_status' => 'technical_quarantine',
        ]);

        $result = app(CausalLearningCohortService::class)
            ->terminalScreeningDisposition($experiment, true);

        $this->assertSame('invalid_counterfactual_contract', $result['status']);
        $this->assertContains(
            'CAUSAL_COHORT_SCREENING_EVIDENCE_INCOMPLETE',
            $result['reason_codes'],
        );
        $this->assertSame('invalid_counterfactual_contract', $experiment->fresh()->status);
        $this->assertSame('technical_quarantine', $generation->fresh()->status);
        $this->assertFalse((bool) data_get($experiment->fresh()->evidence, 'promotion_evidence', true));
    }

    public function test_causal_fold_job_delegates_exactly_one_durable_fold(): void
    {
        config()->set('services.learning_lane.causal_fold_job_attempts', 3);
        config()->set('services.lab_selection.causal_fold_transport_timeout_seconds', 960);
        $folds = \Mockery::mock(CausalFoldExecutionService::class);
        $folds->shouldReceive('run')->once()->with(75, 4)->andReturn(['status' => 'completed']);

        $job = new RunCausalExperimentFoldJob(75, 4);

        $this->assertSame('causal-experiment:75:fold:4', $job->uniqueId());
        $this->assertSame(3, $job->tries);
        $this->assertSame(1020, $job->timeout);
        $this->assertSame('lab-full-validation', $job->queue);
        $job->handle($folds);

        // A queued job may have been serialized before the operational budget
        // was raised. Deserialization must not retain its obsolete 360s cap.
        $job->timeout = 360;
        $restored = unserialize(serialize($job));
        $this->assertSame(1020, $restored->timeout);
    }

    public function test_fold_receipts_are_idempotent_and_terminal_exhaustion_grants_no_partial_credit(): void
    {
        config()->set('services.learning_lane.causal_fold_count', 9);
        [$generation, $experiment, $agents] = $this->causalTriplet();
        $folds = app(CausalFoldExecutionService::class);

        foreach (range(1, 9) as $foldIndex) {
            $receipt = $folds->ensureReceipt($experiment, $foldIndex);
            $this->assertSame($foldIndex, (int) $receipt->fold_index);
            $this->assertSame('planned', $receipt->status);
        }
        $original = $folds->ensureReceipt($experiment, 4);
        $duplicate = $folds->ensureReceipt($experiment, 4);
        $this->assertSame($original->id, $duplicate->id);
        $this->assertDatabaseCount('causal_fold_receipts', 9);

        $folds->terminalFailure($experiment->id, 4, new RuntimeException('fold timed out'));

        $this->assertSame('technical_error', CausalFoldReceipt::query()
            ->where('agent_learning_causal_experiment_id', $experiment->id)
            ->where('fold_index', 4)->value('status'));
        $this->assertSame('technical_quarantine', $experiment->fresh()->status);
        $this->assertSame('technical_quarantine', $generation->fresh()->status);
        $this->assertSame(3, LabAgent::query()->whereIn('id', $agents->pluck('id'))
            ->where('lifecycle_status', 'technical_quarantine')->count());
        $this->assertFalse((bool) data_get(
            $experiment->fresh()->evidence,
            'fold_execution.terminal_failure.partial_fold_credit_allowed',
            true,
        ));
        $skipped = $folds->run($experiment->id, 5);
        $this->assertSame('terminal_without_replay', $skipped['status']);
        $this->assertDatabaseMissing('causal_fold_receipts', [
            'agent_learning_causal_experiment_id' => $experiment->id,
            'fold_index' => 5,
            'status' => 'running',
        ]);
    }

    public function test_autonomy_audit_requires_every_durable_fold_and_atomic_settlement(): void
    {
        config()->set('services.learning_lane.causal_fold_count', 9);
        [$generation, $experiment] = $this->causalTriplet('completed', 'confirmed');
        $experiment->update(['evidence' => [
            'construction_validation' => ['status' => 'ready_for_replay'],
            'fold_execution' => [
                'protocol' => CausalFoldExecutionService::PROTOCOL,
                'required_fold_count' => 9,
                'promotion_evidence' => false,
            ],
        ]]);
        $receipt = app(CausalFoldExecutionService::class)->ensureReceipt($experiment, 1);
        $receipt->update($this->completedReceiptAttributes(1));

        $incomplete = app(GenerationAutonomyAuditService::class)->audit($generation->fresh());
        $closure = collect($incomplete['checks'])->firstWhere('name', 'causal_learning_closure');
        $this->assertContains('CAUSAL_DURABLE_FOLD_RECEIPTS_INCOMPLETE', $closure['reason_codes']);
        $this->assertSame([1], data_get($closure, "metrics.durable_fold_experiments.{$experiment->id}.completed_indexes"));

        foreach (range(2, 9) as $foldIndex) {
            app(CausalFoldExecutionService::class)->ensureReceipt($experiment, $foldIndex)
                ->update($this->completedReceiptAttributes($foldIndex));
        }
        $evidence = (array) $experiment->fresh()->evidence;
        data_set($evidence, 'fold_execution.settlement.status', 'completed');
        $experiment->update(['evidence' => $evidence]);

        $complete = app(GenerationAutonomyAuditService::class)->audit($generation->fresh());
        $closure = collect($complete['checks'])->firstWhere('name', 'causal_learning_closure');
        $this->assertNotContains('CAUSAL_DURABLE_FOLD_RECEIPTS_INCOMPLETE', $closure['reason_codes']);
        $this->assertTrue((bool) data_get(
            $closure,
            "metrics.durable_fold_experiments.{$experiment->id}.atomic_settlement_complete",
        ));
    }

    public function test_successful_fold_retry_does_not_hide_historical_technical_timeout_from_clean_audit(): void
    {
        [$generation, $experiment] = $this->causalTriplet('completed', 'confirmed');
        $fold = app(CausalFoldExecutionService::class)->ensureReceipt($experiment, 1);
        $fold->update([
            ...$this->completedReceiptAttributes(1),
            'attempt_count' => 2,
        ]);

        $audit = app(GenerationAutonomyAuditService::class)->audit($generation->fresh());
        $technical = collect($audit['checks'])->firstWhere('name', 'technical_integrity');

        $this->assertSame('failed', $technical['status']);
        $this->assertContains('CAUSAL_FOLD_TECHNICAL_ATTEMPT_RECORDED', $technical['reason_codes']);
        $this->assertSame([$fold->id], $technical['metrics']['technical_fold_receipt_ids']);
        $this->assertSame(2, $technical['metrics']['technical_fold_attempts'][1]);
    }

    public function test_two_linked_clean_receipts_form_the_required_autonomy_streak(): void
    {
        $lab = $this->lab();
        $first = $this->generation($lab, 229, 'completed');
        $second = $this->generation($lab, 230, 'completed');
        $third = $this->generation($lab, 231, 'screening');
        $firstDecision = $this->decision('first-successor');
        $secondDecision = $this->decision('second-successor');
        $this->autonomyReceipt($first, $second, $firstDecision, 'passed');
        $this->autonomyReceipt($second, $third, $secondDecision, 'passed_with_scientific_abstention');

        $proof = app(GenerationAutonomyReceiptService::class)
            ->consecutiveProof('XAUUSD', 'H1', 2);

        $this->assertTrue($proof['passed']);
        $this->assertSame('passed', $proof['status']);
        $this->assertSame([229, 230], $proof['generation_numbers']);
        $this->assertSame([], $proof['reason_codes']);
    }

    public function test_autonomy_streak_rejects_a_tampered_receipt_payload(): void
    {
        $lab = $this->lab();
        $first = $this->generation($lab, 229, 'completed');
        $second = $this->generation($lab, 230, 'completed');
        $third = $this->generation($lab, 231, 'screening');
        $firstReceipt = $this->autonomyReceipt($first, $second, $this->decision('tamper-first'), 'passed');
        $this->autonomyReceipt($second, $third, $this->decision('tamper-second'), 'passed');
        DB::table('generation_autonomy_receipts')->where('id', $firstReceipt->id)->update([
            'payload' => json_encode(['generation_id' => 999999], JSON_UNESCAPED_SLASHES),
        ]);

        $proof = app(GenerationAutonomyReceiptService::class)
            ->consecutiveProof('XAUUSD', 'H1', 2);

        $this->assertFalse($proof['passed']);
        $this->assertContains('AUTONOMY_RECEIPT_INTEGRITY_INVALID', $proof['reason_codes']);
    }

    public function test_completed_fold_and_autonomy_receipts_are_immutable(): void
    {
        [$generation, $experiment] = $this->causalTriplet();
        $fold = app(CausalFoldExecutionService::class)->ensureReceipt($experiment, 1);
        $fold->update($this->completedReceiptAttributes(1));

        try {
            $fold->update(['response_hash' => hash('sha256', 'tampered')]);
            $this->fail('Completed fold receipt accepted a mutation.');
        } catch (LogicException $exception) {
            $this->assertSame('Completed causal fold receipts are immutable.', $exception->getMessage());
        }

        $successor = $this->generation($generation->laboratory, 2, 'screening');
        $receipt = $this->autonomyReceipt(
            $generation,
            $successor,
            $this->decision('immutable-autonomy'),
            'passed',
        );
        $this->expectException(LogicException::class);
        $receipt->update(['state' => 'failed']);
    }

    public function test_successor_decision_seals_the_clean_predecessor_and_writes_provenance(): void
    {
        $lab = $this->lab();
        $predecessor = $this->generation($lab, 228, 'completed');
        $source = $this->generation($lab, 229, 'completed');
        $creation = $this->seedCreationProvenance($source, $predecessor);
        $decision = $this->decision('seal-successor', $source->id);
        $successor = $this->generation($lab, 230, 'screening');
        $audit = \Mockery::mock(GenerationAutonomyAuditService::class);
        $audit->shouldReceive('audit')->once()->andReturn([
            'protocol' => GenerationAutonomyAuditService::PROTOCOL,
            'observed_at' => now()->utc()->toIso8601String(),
            'state' => 'passed_with_scientific_abstention',
            'checks' => [
                ['name' => 'population_terminal', 'status' => 'passed', 'metrics' => [
                    'planned' => 20, 'actual' => 20, 'terminal_agents' => 20,
                ]],
                ['name' => 'technical_integrity', 'status' => 'passed', 'metrics' => [
                    'technical_run_ids' => [],
                ]],
                ['name' => 'immutable_evidence', 'status' => 'passed', 'metrics' => []],
                ['name' => 'terminal_learning_order', 'status' => 'passed', 'metrics' => []],
            ],
        ]);
        $snapshots = \Mockery::mock(GenerationSnapshotAdmissionService::class);
        $snapshots->shouldReceive('inspect')->once()->andReturn([
            'allowed' => true, 'reasons' => [], 'promotion_evidence' => false,
        ]);
        $service = new GenerationAutonomyReceiptService(
            $audit,
            $snapshots,
            app(LabGenerationContextService::class),
        );

        $sealed = $service->recordSuccessorDecision($decision->fresh());

        $this->assertSame('recorded', $sealed['status']);
        $this->assertSame($source->id, $sealed['generation_id']);
        $this->assertSame($creation->id, data_get(
            GenerationAutonomyReceipt::where('lab_generation_id', $source->id)->first()?->payload,
            'creation_arbiter_decision_id',
        ));
        $this->assertSame($successor->id, $sealed['successor_generation_id']);
        $this->assertDatabaseHas('generation_autonomy_receipts', [
            'lab_generation_id' => $source->id,
            'arbiter_decision_id' => $decision->id,
            'successor_generation_id' => $successor->id,
            'state' => 'passed_with_scientific_abstention',
        ]);
        $this->assertSame(
            $decision->id,
            data_get($successor->fresh()->trigger_context, 'arbiter_provenance.decision_id'),
        );
        $this->assertFalse((bool) data_get(
            $successor->fresh()->trigger_context,
            'arbiter_provenance.manual_generation_writer',
            true,
        ));
    }

    public function test_failed_predecessor_audit_does_not_erase_successor_creation_provenance(): void
    {
        $lab = $this->lab();
        $source = $this->generation($lab, 232, 'completed');
        $decision = $this->decision('failed-predecessor-successor', $source->id);
        $successor = $this->generation($lab, 233, 'screening');
        $audit = \Mockery::mock(GenerationAutonomyAuditService::class);
        $audit->shouldReceive('audit')->once()->andReturn([
            'state' => 'failed',
            'failed_checks' => ['technical_integrity'],
        ]);
        $snapshots = \Mockery::mock(GenerationSnapshotAdmissionService::class);
        $service = new GenerationAutonomyReceiptService(
            $audit,
            $snapshots,
            app(LabGenerationContextService::class),
        );

        $result = $service->recordSuccessorDecision($decision->fresh());

        $this->assertSame('audit_not_clean', $result['status']);
        $this->assertDatabaseMissing('generation_autonomy_receipts', ['lab_generation_id' => $source->id]);
        $provenance = (array) data_get($successor->fresh()->trigger_context, 'arbiter_provenance');
        $this->assertSame($decision->id, $provenance['decision_id']);
        $this->assertSame($source->id, $provenance['predecessor_generation_id']);
        $this->assertNull($provenance['predecessor_autonomy_receipt_id']);
        $this->assertFalse($provenance['manual_generation_writer']);
    }

    public function test_technical_predecessor_retains_new_successor_provenance_without_clean_receipt(): void
    {
        $lab = $this->lab();
        $source = $this->generation($lab, 250, 'technical_quarantine');
        $decision = $this->decision('technical-predecessor-successor', $source->id);
        $successor = $this->generation($lab, 251, 'draft');
        $audit = \Mockery::mock(GenerationAutonomyAuditService::class);
        $audit->shouldReceive('audit')->once()->andReturn([
            'state' => 'failed', 'failed_checks' => ['technical_integrity', 'population_complete'],
        ]);
        $snapshots = \Mockery::mock(GenerationSnapshotAdmissionService::class);
        $snapshots->shouldReceive('inspect')->never();
        $service = new GenerationAutonomyReceiptService($audit, $snapshots, app(LabGenerationContextService::class));

        $this->assertSame('audit_not_clean', $service->recordSuccessorDecision($decision->fresh())['status']);
        $this->assertDatabaseMissing('generation_autonomy_receipts', ['lab_generation_id' => $source->id]);
        $this->assertSame($decision->id, data_get($successor->fresh()->trigger_context, 'arbiter_provenance.decision_id'));
        $this->assertSame($source->id, data_get($successor->fresh()->trigger_context, 'arbiter_provenance.predecessor_generation_id'));
        $this->assertSame('technical_quarantine', $source->fresh()->status);
    }

    public function test_clean_receipt_rejects_a_source_without_arbiter_creation_provenance(): void
    {
        $lab = $this->lab();
        $source = $this->generation($lab, 229, 'completed');
        $decision = $this->decision('unproven-source-successor', $source->id);
        $successor = $this->generation($lab, 230, 'screening');
        $audit = \Mockery::mock(GenerationAutonomyAuditService::class);
        $audit->shouldReceive('audit')->once()->andReturn([
            'state' => 'passed_with_scientific_abstention',
            'checks' => [],
        ]);
        $snapshots = \Mockery::mock(GenerationSnapshotAdmissionService::class);
        $snapshots->shouldReceive('inspect')->once()->andReturn([
            'allowed' => true, 'reasons' => [],
        ]);
        $service = new GenerationAutonomyReceiptService(
            $audit,
            $snapshots,
            app(LabGenerationContextService::class),
        );

        $result = $service->recordSuccessorDecision($decision->fresh());

        $this->assertSame('source_creation_not_arbiter_verified', $result['status']);
        $this->assertDatabaseMissing('generation_autonomy_receipts', ['lab_generation_id' => $source->id]);
        $this->assertSame($decision->id, data_get($successor->fresh()->trigger_context, 'arbiter_provenance.decision_id'));
    }

    /** @return array{0:LabGeneration,1:AgentLearningCausalExperiment,2:Collection<int,LabAgent>} */
    private function causalTriplet(
        string $generationStatus = 'full_validation',
        string $experimentStatus = 'outcomes_pending',
    ): array {
        $lab = $this->lab();
        $generation = $this->generation($lab, 1, $generationStatus, 3);
        $roles = ['memory_guided', 'blinded', 'frozen_control'];
        $agents = collect($roles)->map(function (string $role, int $index) use ($generation): LabAgent {
            $model = ModelVersion::create([
                'name' => 'durable-arm-'.$index,
                'strategy' => 'shared-runtime-strategy',
                'version' => 'v1-'.$index,
                'generation' => 1,
                'status' => 'testing',
                'parameters' => [],
                'metadata' => [
                    'causal_learning_cohort' => [
                        'protocol' => 'causal_learning_counterfactual_cohort_v1',
                        'role' => $role,
                    ],
                ],
            ]);

            return LabAgent::create([
                'lab_generation_id' => $generation->id,
                'model_version_id' => $model->id,
                'symbol' => 'XAUUSD',
                'timeframe' => 'H1',
                'strategy_family' => 'hybrid',
                'origin' => 'test',
                'lifecycle_status' => $generation->status === 'completed' ? 'rejected' : 'full_queued',
                'parameter_diff' => [],
            ]);
        })->values();
        $experiment = AgentLearningCausalExperiment::create([
            'experiment_key' => hash('sha512', 'durable-fold-test-'.$generation->id),
            'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'target' => 'profit_factor',
            'gene_key' => 'entry_threshold',
            'guided_agent_id' => $agents[0]->id,
            'blinded_agent_id' => $agents[1]->id,
            'control_agent_id' => $agents[2]->id,
            'status' => $experimentStatus,
            'evidence' => ['construction_validation' => ['status' => 'ready_for_replay']],
        ]);

        return [$generation->fresh('agents.modelVersion'), $experiment, $agents];
    }

    /** @return array<string,mixed> */
    private function completedReceiptAttributes(int $foldIndex): array
    {
        return [
            'status' => 'completed',
            'request_hash' => hash('sha256', 'request-'.$foldIndex),
            'response_hash' => hash('sha256', 'response-'.$foldIndex),
            'dataset_hash' => str_repeat('d', 64),
            'execution_hash' => str_repeat('e', 64),
            'completed_at' => now(),
            'observed_at' => now(),
        ];
    }

    private function lab(): AiLaboratory
    {
        return AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'Durable autonomy proof lab',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid'],
            'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
    }

    private function generation(
        AiLaboratory $lab,
        int $number,
        string $status,
        int $population = 20,
    ): LabGeneration {
        return LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => $number,
            'trigger_type' => 'learning_confirmation',
            'status' => $status,
            'population_size' => $population,
            'trigger_context' => [],
            'started_at' => now()->subMinute(),
            'completed_at' => in_array($status, ['completed', 'screened'], true) ? now() : null,
        ]);
    }

    private function decision(string $suffix, ?int $generationId = null): ResearchLoopDecision
    {
        return ResearchLoopDecision::create([
            'decision_key' => hash('sha256', $suffix),
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'action' => 'RUN_NORMAL_TWENTY_SEAT_LIFECYCLE',
            'status' => 'completed',
            'priority' => 60,
            'evidence_hash' => hash('sha256', $suffix.'-evidence'),
            'command' => 'trading:run-lifecycle-cycle',
            'queue' => 'scheduler-constructor',
            'arguments' => ['--symbol' => 'XAUUSD'],
            'reason_codes' => ['TEST'],
            'evidence_snapshot' => $generationId ? ['generation' => ['id' => $generationId]] : [],
            'contract' => [
                'protocol' => ResearchLoopArbiterService::PROTOCOL,
                'owner' => ResearchLoopArbiterService::OWNER,
                'selection_cardinality' => 1,
            ],
            'dispatched_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
    }

    private function autonomyReceipt(
        LabGeneration $generation,
        LabGeneration $successor,
        ResearchLoopDecision $decision,
        string $state,
    ): GenerationAutonomyReceipt {
        $creation = null;
        if ((int) $generation->generation > 1) {
            $predecessor = LabGeneration::query()
                ->where('ai_laboratory_id', $generation->ai_laboratory_id)
                ->where('generation', (int) $generation->generation - 1)
                ->first() ?: $this->generation(
                    $generation->laboratory,
                    (int) $generation->generation - 1,
                    'completed',
                );
            $creation = $this->seedCreationProvenance($generation, $predecessor);
        }
        $payload = [
            'protocol' => GenerationAutonomyReceiptService::PROTOCOL,
            'arbiter_decision_id' => $decision->id,
            'creation_arbiter_decision_id' => $creation?->id,
            'generation_id' => $generation->id,
            'successor_generation_id' => $successor->id,
            'state' => $state,
        ];
        ksort($payload);

        return GenerationAutonomyReceipt::create([
            'receipt_key' => hash('sha256', 'receipt-'.$generation->id),
            'lab_generation_id' => $generation->id,
            'arbiter_decision_id' => $decision->id,
            'successor_generation_id' => $successor->id,
            'state' => $state,
            'receipt_hash' => hash('sha256', (string) json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            )),
            'payload' => $payload,
            'observed_at' => now(),
        ]);
    }

    private function seedCreationProvenance(
        LabGeneration $generation,
        LabGeneration $predecessor,
    ): ResearchLoopDecision {
        $decision = $this->decision('create-'.$generation->id, $predecessor->id);
        app(LabGenerationContextService::class)->update($generation, function (array $context) use ($decision, $predecessor): array {
            $context['arbiter_provenance'] = [
                'protocol' => GenerationAutonomyReceiptService::PROTOCOL,
                'decision_id' => $decision->id,
                'decision_key' => $decision->decision_key,
                'predecessor_generation_id' => $predecessor->id,
                'predecessor_autonomy_receipt_id' => null,
                'manual_generation_writer' => false,
            ];

            return $context;
        });

        return $decision;
    }
}
