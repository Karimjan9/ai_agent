<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\ExecutionTacticPosterior;
use App\Models\LabAgent;
use App\Models\ModelVersion;
use App\Services\DependencyAwareEdgeGenesisFoundryService;
use App\Services\ExecutionContractService;
use App\Services\FullStackPlaybookMasteryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class FullStackPlaybookMasteryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_evidence_compiled_causal_roles_are_explicit_mastery_arms(): void
    {
        $this->assertSame([], array_values(array_diff(
            DependencyAwareEdgeGenesisFoundryService::COMPILED_HYPOTHESIS_ARMS,
            FullStackPlaybookMasteryService::ARMS,
        )));
    }

    public function test_full_stack_passport_requires_observed_procedure_and_keeps_mastery_separate_from_edge(): void
    {
        Queue::fake();
        $lab = AiLaboratory::create(['name' => 'Mastery XAUUSD H1', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $fixtureDirectory = storage_path('framework/testing/full-stack-'.Str::uuid());
        File::ensureDirectoryExists($fixtureDirectory);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($fixtureDirectory));
        $foundationPath = $fixtureDirectory.'/foundation.csv';
        $paperPath = $fixtureDirectory.'/paper.csv';
        File::put($foundationPath, 'pre-2026-foundation');
        File::put($paperPath, '2026-paper-only');
        $data = (string) hash_file('sha256', $foundationPath);
        $snapshots = [
            'foundation' => ['path' => $foundationPath, 'sha256' => $data, 'manifest' => [
                'source_role' => 'foundation_training_only', 'promotion_evidence' => false,
                'continuity' => ['status' => 'ready', 'unexpected_gap_count' => 0],
            ]],
            'price' => ['path' => $paperPath, 'sha256' => hash_file('sha256', $paperPath), 'manifest' => [
                'data_role' => 'paper_only', 'training_end_exclusive' => '2026-01-01T00:00:00+00:00',
                'promotion_evidence' => false,
            ]],
        ];
        $execution = (string) app(ExecutionContractService::class)->for('XAUUSD', DependencyAwareEdgeGenesisFoundryService::EXECUTION_TIMEFRAME)['execution_hash'];
        $mtfHash = str_repeat('d', 64);
        $bundle = ['bundle_hash' => $mtfHash, 'manifest' => [
            'bundle_hash' => $mtfHash, 'validation_bundle_protocol' => 'agent_owned_mtf_foundation_bundle_v1',
            'data_role' => 'pre_2026_foundation_training_only', 'promotion_evidence' => false,
        ]];
        app(DependencyAwareEdgeGenesisFoundryService::class)->materialize($lab, $data, $execution, true, $bundle,
            DependencyAwareEdgeGenesisFoundryService::INITIAL_REVISION, $snapshots);
        $agent = LabAgent::query()->with('modelVersion')->firstOrFail();
        $mastery = app(FullStackPlaybookMasteryService::class);

        $this->assertDatabaseCount('full_stack_playbook_passports', 20);
        $this->assertDatabaseCount('playbook_mastery_ledger_entries', 0);
        $this->assertTrue($mastery->preflight($agent)['allowed']);
        $this->assertContains(data_get($agent->modelVersion->metadata, 'full_stack_playbook.arm'), FullStackPlaybookMasteryService::ARMS);

        $unobserved = $mastery->settleOutcome($agent, ['data_hash' => $data, 'execution_hash' => $execution]);
        $this->assertSame('in_progress', $unobserved['status']);
        $this->assertFalse($unobserved['procedural_mastery']);

        $observed = $mastery->settleOutcome($agent->fresh('modelVersion'), [
            'data_hash' => $data, 'execution_hash' => $execution, 'behavior_delta_observed' => true,
            'risk_governor_compliant' => true, 'forbidden_risk_bypass' => false, 'abstention_quality' => .8, 'exit_management_quality' => .8,
            'entry_contract_funnel' => ['protocol' => 'entry_contract_funnel_v2', 'count_semantics' => 'ordered_cumulative_pipeline', 'status' => 'observed',
                'stage_counts' => ['setup' => 12, 'confirmation' => 10, 'trigger' => 10, 'entry_ready' => 8], 'grade_distribution' => ['A' => 10],
                'confirmation_cost' => ['average_independent_count' => 2, 'average_raw_count' => 2, 'average_chase_distance_atr_after_trigger' => .1]],
            'forward_window_protocol' => ['powered_windows' => 9, 'positive_windows' => 3], 'after_cost_expectancy_r' => .15,
            'pf_lower_confidence_bound' => 1.12, 'net_profit_percent' => 2.1, 'temporal_leakage' => false,
        ]);
        $this->assertSame('master_candidate', $observed['status']);
        $this->assertTrue($observed['procedural_mastery']);
        $this->assertTrue($observed['economic_edge']);
        $this->assertDatabaseCount('playbook_mastery_ledger_entries', 2);
        $this->assertDatabaseHas('strategy_master_passports', ['model_version_id' => $agent->model_version_id, 'mastery_stage' => 'strategy_master_candidate']);
        $this->assertSame(1, ExecutionTacticPosterior::count());
        $this->assertTrue($mastery->parentAdmission($agent->fresh('modelVersion')->modelVersion)['allowed']);

        $child = ModelVersion::create(['name' => 'inherited procedure child', 'strategy' => 'hybrid', 'version' => 'v1', 'generation' => 2, 'status' => 'testing', 'parameters' => [], 'metadata' => [], 'evidence_status' => 'valid']);
        $transfer = $mastery->recordDescendantTransfer($agent->fresh('modelVersion')->modelVersion, $child, [
            'protocol' => FullStackPlaybookMasteryService::PROTOCOL,
            'parent_passport_key' => data_get($agent->fresh('modelVersion')->modelVersion->metadata, 'full_stack_playbook.passport_key'),
            'descendant_model_version_id' => $child->id, 'transfer_fidelity_observed' => true,
            'risk_governor_compliant' => true, 'forbidden_risk_bypass' => false, 'independent_windows' => 9, 'after_cost_expectancy_r' => .1,
        ]);
        $this->assertSame('master', $transfer['status']);
        $this->assertTrue((bool) data_get($agent->fresh('modelVersion')->modelVersion->metadata, 'full_stack_playbook.innovation_allowed'));
    }
}
