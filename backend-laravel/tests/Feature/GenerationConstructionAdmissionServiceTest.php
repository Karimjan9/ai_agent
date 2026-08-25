<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabEvaluationRun;
use App\Models\LabLearningLanePair;
use App\Models\ModelVersion;
use App\Models\SystemEvent;
use App\Services\GenerationConstructionAdmissionService;
use App\Services\GenerationConstructionReconciliationService;
use App\Services\LabPopulationService;
use App\Services\LearningVelocityGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerationConstructionAdmissionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_incomplete_quarantined_population_cannot_enter_screening(): void
    {
        $generation = $this->generation('technical_quarantine', 2, [
            'constructor_audit' => ['planned_slots' => 2, 'created_agents' => 1],
            'shadow_research_constructor_abort' => [
                'reason_code' => 'INCOMPLETE_SHADOW_RESEARCH_POPULATION',
            ],
            'lineage_continuation_contract' => ['allowed' => true],
        ]);
        $this->agent($generation, 1);

        $result = app(GenerationConstructionAdmissionService::class)->inspect($generation);

        $this->assertFalse($result['allowed']);
        $this->assertContains('POPULATION_COUNT_MISMATCH', $result['reason_codes']);
        $this->assertContains('CONSTRUCTOR_ABORT_ACTIVE', $result['reason_codes']);
        $this->assertContains('GENERATION_TECHNICAL_QUARANTINE', $result['reason_codes']);
    }

    public function test_complete_population_with_lineage_contract_is_dispatchable(): void
    {
        $generation = $this->generation('draft', 2, [
            'constructor_audit' => ['planned_slots' => 2, 'created_agents' => 2],
            'lineage_continuation_contract' => ['allowed' => true],
        ]);
        $this->agent($generation, 1);
        $this->agent($generation, 2);

        $result = app(GenerationConstructionAdmissionService::class)->inspect($generation);

        $this->assertTrue($result['allowed']);
        $this->assertSame([], $result['reason_codes']);
        $this->assertSame([1, 2], $result['completed_slots']);
    }

    public function test_completed_shadow_continuation_clears_abort_before_reopening_generation(): void
    {
        $generation = $this->generation('technical_quarantine', 1, [
            'shadow_research_constructor_abort' => [
                'reason_code' => 'INCOMPLETE_SHADOW_RESEARCH_POPULATION',
            ],
        ]);
        $this->agent($generation, 1);

        $result = app(LabPopulationService::class)->continueInterruptedConstruction($generation->id, 1);
        $fresh = $result['generation'];

        $this->assertSame('complete', $result['status']);
        $this->assertSame('draft', $fresh->status);
        $this->assertNull(data_get($fresh->trigger_context, 'shadow_research_constructor_abort'));
        $this->assertTrue((bool) data_get($fresh->trigger_context, 'lineage_continuation_contract.allowed'));
        $this->assertTrue(app(GenerationConstructionAdmissionService::class)->inspect($fresh)['allowed']);
    }

    public function test_clean_incomplete_population_remains_resumable(): void
    {
        $generation = $this->generation('technical_quarantine', 2, [
            'constructor_audit' => ['planned_slots' => 2, 'created_agents' => 1],
            'lineage_continuation_contract' => ['allowed' => true],
        ]);
        $this->agent($generation, 1);

        $result = app(GenerationConstructionReconciliationService::class)->reconcile($generation);

        $this->assertFalse($result['closed']);
        $this->assertSame('resumable_clean_construction', $result['status']);
        $this->assertSame('technical_quarantine', $generation->fresh()->status);
    }

    public function test_screened_incomplete_population_is_abandoned_and_learning_is_diagnostic_only(): void
    {
        $generation = $this->generation('screening', 2, [
            'constructor_audit' => ['planned_slots' => 2, 'created_agents' => 1],
            'shadow_research_constructor_abort' => [
                'reason_code' => 'INCOMPLETE_SHADOW_RESEARCH_POPULATION',
            ],
            'lineage_continuation_contract' => ['allowed' => true],
        ]);
        $agent = $this->agent($generation, 1);
        LabEvaluationRun::create([
            'run_id' => (string) \Illuminate\Support\Str::uuid(),
            'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id,
            'model_version_id' => $agent->model_version_id,
            'phase' => 'screening',
            'mode' => 'screen',
            'status' => 'started',
            'started_at' => now()->subMinute(),
        ]);
        $pair = LabLearningLanePair::create([
            'pair_key' => 'contaminated-generation-pair',
            'lab_generation_id' => $generation->id,
            'candidate_agent_id' => $agent->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'target' => 'regime_coverage',
            'status' => 'verified',
            'pair_integrity_status' => 'verified',
            'same_generation' => true,
            'metadata' => [],
        ]);

        $result = app(GenerationConstructionReconciliationService::class)->reconcile($generation);

        $this->assertTrue($result['closed']);
        $this->assertSame('abandoned_diagnostic_only', $result['status']);
        $this->assertSame('abandoned', $generation->fresh()->status);
        $this->assertSame('technical_quarantine', $agent->fresh()->lifecycle_status);
        $this->assertSame('diagnostic_only', $pair->fresh()->status);
        $this->assertSame('invalid_generation_construction', $pair->fresh()->pair_integrity_status);
        $run = LabEvaluationRun::where('lab_generation_id', $generation->id)->firstOrFail();
        $this->assertSame('technical_error', $run->status);
        $this->assertSame('GenerationConstructionContamination', $run->error_class);
        $this->assertNotNull($run->finished_at);
        $this->assertTrue(SystemEvent::where('event_key', 'generation-construction-contamination:'.$generation->id)->exists());

        $repeat = app(GenerationConstructionReconciliationService::class)->reconcile($generation->fresh());
        $this->assertFalse($repeat['closed']);
        $this->assertSame('already_abandoned_diagnostic_only', $repeat['status']);
        $this->assertSame(0, app(LearningVelocityGateService::class)->inspect($generation->laboratory->fresh())['technical_recovery_agents']);
    }

    /** @param array<string, mixed> $extraContext */
    private function generation(string $status, int $plannedSlots, array $extraContext): LabGeneration
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Construction admission lab', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);

        return LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 2,
            'trigger_type' => 'shadow_research',
            'trigger_context' => [
                'generation_plan' => array_fill(0, $plannedSlots, ['family' => 'hybrid']),
                ...$extraContext,
            ],
            'data_fingerprint' => 'construction-admission-test',
            'population_size' => $plannedSlots,
            'status' => $status,
            'started_at' => now(),
        ]);
    }

    private function agent(LabGeneration $generation, int $slot): LabAgent
    {
        $model = ModelVersion::create([
            'name' => 'construction-seat-'.$slot,
            'strategy' => sprintf('xauusd_hybrid_g%s_a%02d', $generation->generation, $slot),
            'version' => 'v'.$generation->generation,
            'generation' => $generation->generation,
            'status' => 'testing',
            'parameters' => [],
            'metadata' => [],
            'evidence_status' => 'valid',
        ]);

        return LabAgent::create([
            'lab_generation_id' => $generation->id,
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'origin' => 'shadow_research',
            'lifecycle_status' => 'draft',
            'parameter_diff' => [],
        ]);
    }
}
