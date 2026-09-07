<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Services\SourceGenerationAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceGenerationAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_uses_primary_key_and_reports_arms_controls_and_replay_identity_read_only(): void
    {
        $lab = AiLaboratory::create(['name' => 'source audit', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_families' => ['fixture']]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 73, 'trigger_type' => 'fixture', 'status' => 'completed']);
        $model = ModelVersion::create(['name' => 'source audit model', 'strategy' => 'fixture', 'version' => 'v1', 'generation' => 73, 'status' => 'testing', 'parameters' => [], 'metadata' => [
            'academy_experiment' => ['academy_trial_id' => 91, 'arm_role' => 'frozen_control', 'control_identity' => true],
        ]]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'fixture', 'origin' => 'fixture', 'lifecycle_status' => 'completed', 'parameter_diff' => ['risk' => 'frozen']]);
        ModelMarketPerformance::create(['model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'fixture', 'metrics' => ['data_hash' => 'data', 'execution_hash' => 'execution']]);

        $report = app(SourceGenerationAuditService::class)->audit($generation->id);

        $this->assertSame('audited', $report['status']);
        $this->assertSame($generation->id, $report['source_generation_id']);
        $this->assertSame(73, $report['generation_label']);
        $this->assertSame(1, $report['arm_count']);
        $this->assertSame(1, $report['control_arm_count']);
        $this->assertTrue($report['all_arms_terminal']);
        $this->assertFalse($report['provenance_complete']);
        $this->assertContains('SETTLEMENT_WATERMARK_MISSING', $report['provenance_blockers']);
        $this->assertSame($agent->id, $report['arms'][0]['agent_id']);
        $this->artisan('trading:audit-source-generation', ['source_generation_id' => $generation->id, '--json' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('"source_generation_id": '.$generation->id);
    }
}
