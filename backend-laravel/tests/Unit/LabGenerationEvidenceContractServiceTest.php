<?php

namespace Tests\Unit;

use App\Models\LabGeneration;
use App\Services\LabGenerationEvidenceContractService;
use Tests\TestCase;

class LabGenerationEvidenceContractServiceTest extends TestCase
{
    public function test_direct_replay_generations_require_terminal_evaluations_not_screening_rows(): void
    {
        $generation = new LabGeneration(['id' => 11, 'trigger_type' => 'edge_genesis']);

        $contract = app(LabGenerationEvidenceContractService::class)->for($generation);

        $this->assertSame('direct_replay', $contract['mode']);
        $this->assertFalse($contract['screening_evidence_required']);
        $this->assertTrue($contract['terminal_evaluation_required']);
        $this->assertFalse($contract['promotion_evidence']);
    }

    public function test_ordinary_generations_still_require_screening_evidence(): void
    {
        $generation = new LabGeneration(['id' => 12, 'trigger_type' => 'new_data']);

        $contract = app(LabGenerationEvidenceContractService::class)->for($generation);

        $this->assertSame('screening_pipeline', $contract['mode']);
        $this->assertTrue($contract['screening_evidence_required']);
        $this->assertFalse($contract['terminal_evaluation_required']);
    }
}
