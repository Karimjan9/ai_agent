<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\ExecutionContractService;
use App\Services\GenerationConstructionAdmissionService;
use App\Services\ImmutableGenerationContractService;
use App\Services\ResearchLoopArbiterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImmutableGenerationContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_sealed_complete_plan_is_admitted_and_nested_post_seal_rewrite_is_rejected(): void
    {
        $lab = AiLaboratory::create(['name' => 'sealed generation', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $plan = [
            ['family' => 'hybrid', 'origin' => 'instrument_research', 'target' => 'profit_factor',
                'research_group' => 'pair-1', 'group_seat' => 1,
                'niche' => ['instrument_pair' => ['role' => 'control', 'instrument_ids' => [1, 2]]]],
            ['family' => 'hybrid', 'origin' => 'instrument_research', 'target' => 'profit_factor',
                'research_group' => 'pair-1', 'group_seat' => 2,
                'niche' => ['instrument_pair' => ['role' => 'candidate', 'instrument_ids' => [1, 2]]]],
        ];
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 9,
            'trigger_type' => 'new_data', 'trigger_context' => [], 'data_fingerprint' => str_repeat('a', 64),
            'population_size' => 2, 'status' => 'draft', 'started_at' => now()]);
        foreach ([1, 2] as $seat) {
            $model = ModelVersion::create(['name' => 'sealed-seat-'.$seat, 'strategy' => sprintf('xauusd_hybrid_g9_a%02d', $seat),
                'version' => 'v9-'.$seat, 'generation' => 9, 'status' => 'testing', 'parameters' => [], 'metadata' => []]);
            LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
                'origin' => 'instrument_research', 'lifecycle_status' => 'draft', 'parameter_diff' => []]);
        }
        $generation->update(['trigger_context' => [
            'generation_plan' => $plan,
            'immutable_generation_contract' => null,
            'constructor_audit' => ['planned_slots' => 2, 'created_agents' => 2],
            'lineage_continuation_contract' => ['allowed' => true],
        ]]);
        $unsealed = app(GenerationConstructionAdmissionService::class)->inspect($generation);
        $this->assertFalse($unsealed['allowed']);
        $this->assertContains('IMMUTABLE_GENERATION_CONTRACT_NOT_SEALED', $unsealed['reason_codes']);

        $contract = app(ImmutableGenerationContractService::class)->compile($generation->fresh('laboratory'), $plan, [
            'data_hash' => str_repeat('a', 64),
            'execution_hash' => data_get(app(ExecutionContractService::class)->for('XAUUSD', 'M5'), 'execution_hash'),
            'normal_population' => 20,
            'control_pairing_required' => true,
            'new_work_owner' => ResearchLoopArbiterService::class,
        ]);
        $generation->update(['trigger_context' => [
            'generation_plan' => $plan,
            'immutable_generation_contract' => $contract,
            'constructor_audit' => ['planned_slots' => 2, 'created_agents' => 2],
            'lineage_continuation_contract' => ['allowed' => true],
        ]]);

        $admitted = app(GenerationConstructionAdmissionService::class)->inspect($generation);
        $this->assertTrue($admitted['allowed']);
        $this->assertSame(ResearchLoopArbiterService::class, data_get($contract, 'requirements.new_work_owner'));

        $tampered = (array) $generation->fresh()->trigger_context;
        data_set($tampered, 'generation_plan.1.niche.instrument_pair.instrument_ids.1', 99);
        $generation->update(['trigger_context' => $tampered]);
        $rejected = app(GenerationConstructionAdmissionService::class)->inspect($generation);

        $this->assertFalse($rejected['allowed']);
        $this->assertContains('IMMUTABLE_GENERATION_PLAN_HASH_MISMATCH', $rejected['reason_codes']);
        $this->assertContains('IMMUTABLE_GENERATION_CONTRACT_HASH_MISMATCH', $rejected['reason_codes']);

        $contractOnlyTamper = (array) $generation->fresh()->trigger_context;
        $contractOnlyTamper['generation_plan'] = $plan;
        $contractOnlyTamper['immutable_generation_contract']['state'] = 'rewritten_after_seal';
        $generation->update(['trigger_context' => $contractOnlyTamper]);
        $contractRejected = app(GenerationConstructionAdmissionService::class)->inspect($generation);
        $this->assertFalse($contractRejected['allowed']);
        $this->assertContains('IMMUTABLE_GENERATION_CONTRACT_TAMPERED', $contractRejected['reason_codes']);
    }
}
