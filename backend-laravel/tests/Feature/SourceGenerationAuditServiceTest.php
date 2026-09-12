<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Services\SourceGenerationAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_edge_progressing_is_a_stage_result_and_not_a_terminal_trial(): void
    {
        $lab = AiLaboratory::create(['name' => 'progressing source audit', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_families' => ['fixture']]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 74,
            'trigger_type' => 'edge_genesis', 'status' => 'completed']);
        $model = ModelVersion::create(['name' => 'progressing source model', 'strategy' => 'fixture-progressing',
            'version' => 'v1', 'generation' => 74, 'status' => 'testing', 'parameters' => [],
            'metadata' => ['edge_genesis' => ['protocol' => 'dependency_aware_edge_genesis_foundry_v1',
                'arm' => 'compiled_primary', 'intervention_attestation' => [
                    'protocol' => 'edge_genesis_intervention_attestation_v1', 'control_identity' => false,
                    'consumed_parameter_hash' => hash('sha256', 'parameters'),
                ]]]]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'fixture', 'origin' => 'edge_genesis',
            'lifecycle_status' => 'rejected', 'parameter_diff' => ['gene' => ['old' => 1, 'new' => 2]]]);
        ModelMarketPerformance::create(['model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'fixture', 'metrics' => ['edge' => 'progressing']]);
        $passportId = DB::table('edge_genesis_passports')->insertGetId(['genesis_key' => hash('sha256', 'progressing-passport'),
            'lab_generation_id' => $generation->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'fixture', 'phase' => 'EDGE_CONFIRMATION', 'status' => 'running',
            'data_hash' => 'data', 'execution_hash' => 'execution', 'context' => '{}', 'evidence' => '{}',
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('edge_genesis_trials')->insert(['trial_key' => hash('sha256', 'progressing-trial'),
            'edge_genesis_passport_id' => $passportId, 'lab_agent_id' => $agent->id, 'model_version_id' => $model->id,
            'packet_key' => 'fixture', 'emitter' => 'fixture', 'arm' => 'compiled_primary',
            'stage' => 'two_fold_discovery', 'status' => 'edge_progressing', 'evidence' => '{}',
            'settled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $report = app(SourceGenerationAuditService::class)->audit($generation->id);

        $this->assertFalse(data_get($report, 'edge_projection.all_trials_terminal'));
        $this->assertContains('EDGE_TRIAL_SETTLEMENT_INCOMPLETE', $report['provenance_blockers']);
        $this->assertSame('reconcile_or_quarantine_unsettled_edge_trials', $report['next_action']);
    }
}
