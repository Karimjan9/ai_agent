<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\EliteAgentPortfolio;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Services\AgentLifecycleAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentLifecycleAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_parentless_root_is_not_reported_as_a_lineage_failure(): void
    {
        $this->fakeReplayStatus();
        [$lab, $generation] = $this->generation('EURUSD', 'H1', 1, [
            'constructor_audit' => [
                'planned_slots' => 1,
                'created_agents' => 1,
                'skipped_zero_diff_slots' => [],
            ],
        ]);
        $this->agent($generation, 'monthly_survival', [
            'semantic_lineage' => [
                'protocol' => 'strict_semantic_lineage_v2',
                'mode' => 'semantic_group_root_default_seed',
                'genetic_parent_model_version_id' => null,
            ],
            'parent_inheritance_protocol' => [
                'protocol' => 'exact_semantic_parent_or_group_root_v1',
                'parent_selection' => 'exact_group_root_default',
                'legacy_parent_genetic_material' => false,
            ],
        ]);

        $report = app(AgentLifecycleAuditService::class)->audit($lab->symbol, $lab->timeframe, false, false);
        $lineage = $this->check($report, 'LINEAGE_AND_PREFLIGHT');

        $this->assertSame('passed', $lineage['status']);
        $this->assertSame(1, $lineage['metrics']['parentless_count']);
        $this->assertSame([], $lineage['metrics']['parentless_protocol_missing_ids']);
    }

    public function test_active_population_contract_drift_is_blocked_without_rewriting_agents(): void
    {
        $this->fakeReplayStatus();
        [$lab, $generation] = $this->generation('GBPUSD', 'H1', 20, [
            'population_group_contract' => [
                'protocol' => 'population_group_checkpoint_v1',
                'planned_population' => 20,
                'balanced_core' => true,
                'groups' => [
                    'monthly_survival' => ['planned_seats' => 4],
                    'regime_coverage' => ['planned_seats' => 4],
                    'volatility_session_stability' => ['planned_seats' => 4],
                    'exit_topology' => ['planned_seats' => 4],
                    'portfolio_router' => ['planned_seats' => 4],
                ],
            ],
            'constructor_audit' => [
                'planned_slots' => 20,
                'created_agents' => 16,
                'skipped_zero_diff_slots' => [['slot' => 17]],
            ],
        ]);
        foreach (['monthly_survival', 'regime_coverage', 'volatility_session_stability', 'exit_topology'] as $group) {
            for ($seat = 0; $seat < 4; $seat++) {
                $this->agent($generation, $group);
            }
        }

        $report = app(AgentLifecycleAuditService::class)->audit($lab->symbol, $lab->timeframe, false, false);
        $population = $this->check($report, 'POPULATION_GROUP_CONTRACT');
        $constructor = $this->check($report, 'CONSTRUCTOR_INVARIANT');

        $this->assertSame('blocked', $population['status']);
        $this->assertSame('blocked', $constructor['status']);
        $this->assertSame(16, $generation->fresh()->agents()->count());
        $this->assertSame('screening', $generation->fresh()->status);
    }

    public function test_architecture_only_zero_parameter_diff_is_not_a_constructor_failure(): void
    {
        $this->fakeReplayStatus();
        [$lab, $generation] = $this->generation('XAUUSD', 'H1', 1, [
            'constructor_audit' => [
                'planned_slots' => 1,
                'created_agents' => 1,
                'skipped_zero_diff_slots' => [],
            ],
        ]);
        $agent = $this->agent($generation, 'portfolio_router');
        $agent->update(['parameter_diff' => []]);
        $agent->modelVersion->update(['metadata' => [
            ...$agent->modelVersion->metadata,
            'mutation_constructor_invariant' => [
                'status' => 'passed',
                'control_only' => false,
                'architecture_changed' => true,
                'architecture_variant' => 'regime_consensus',
            ],
            'portfolio_council_lane' => [
                'architecture_experiment' => true,
            ],
        ]]);

        $report = app(AgentLifecycleAuditService::class)->audit($lab->symbol, $lab->timeframe, false, false);
        $constructor = $this->check($report, 'CONSTRUCTOR_INVARIANT');

        $this->assertSame('passed', $constructor['status']);
        $this->assertSame([], $constructor['metrics']['zero_diff_agent_ids']);
        $this->assertSame(1, $constructor['metrics']['architecture_only_count']);
    }

    public function test_technical_zero_diff_quarantine_does_not_block_the_active_laboratory(): void
    {
        $this->fakeReplayStatus();
        $auditService = app(AgentLifecycleAuditService::class);
        $zeroDiff = new \ReflectionMethod($auditService, 'isZeroDiff');
        $zeroDiff->setAccessible(true);
        $this->assertTrue($zeroDiff->invoke($auditService, [
            'partial_take_profit_fraction' => ['old' => 0, 'new' => 0.0],
        ]));

        [$lab, $generation] = $this->generation('XAUUSD', 'H1', 1, [
            'constructor_audit' => [
                'planned_slots' => 1,
                'created_agents' => 1,
                'skipped_zero_diff_slots' => [],
            ],
        ]);
        $agent = $this->agent($generation, 'volatility_session_stability', [
            'preflight_quarantine' => [
                'protocol' => \App\Services\LabAgentPreflightService::PROTOCOL,
                'errors' => ['ZERO_DIFF_INVARIANT_FAILED'],
            ],
            'mutation_constructor_invariant' => [
                'status' => 'passed',
                'control_only' => false,
            ],
        ]);
        $agent->update([
            'lifecycle_status' => 'technical_quarantine',
            'parameter_diff' => [
                'partial_take_profit_fraction' => ['old' => 0, 'new' => 0.0],
            ],
        ]);

        $report = app(AgentLifecycleAuditService::class)->audit($lab->symbol, $lab->timeframe, false, false);
        $constructor = $this->check($report, 'CONSTRUCTOR_INVARIANT');

        $this->assertSame('attention', $constructor['status']);
        $this->assertSame([], $constructor['metrics']['zero_diff_agent_ids']);
        $this->assertSame([$agent->id], $constructor['metrics']['technical_quarantine_zero_diff_agent_ids']);
    }

    public function test_m15_requires_a_frozen_closed_h1_regime_snapshot(): void
    {
        $this->fakeReplayStatus();
        [$lab, $generation] = $this->generation('XAUUSD', 'M15', 1, [
            'constructor_audit' => [
                'planned_slots' => 1,
                'created_agents' => 1,
                'skipped_zero_diff_slots' => [],
            ],
        ]);
        $this->agent($generation, 'monthly_survival');

        $report = app(AgentLifecycleAuditService::class)->audit($lab->symbol, $lab->timeframe, false, false);
        $regime = $this->check($report, 'M15_CLOSED_H1_REGIME');

        $this->assertSame('blocked', $regime['status']);
        $this->assertFalse($regime['metrics']['snapshot_hash_valid']);
    }

    public function test_forward_and_elite_monitor_waits_without_manufacturing_promotion(): void
    {
        $this->fakeReplayStatus();
        [$lab, $generation] = $this->generation('EURUSD', 'M15', 1, [
            'constructor_audit' => [
                'planned_slots' => 1,
                'created_agents' => 1,
                'skipped_zero_diff_slots' => [],
            ],
            'canonical_dataset_snapshots' => [
                'price' => ['path' => 'missing.csv', 'sha256' => 'missing'],
                'foundation' => ['path' => 'missing-foundation.csv', 'sha256' => 'missing'],
            ],
            'regime_snapshot' => ['path' => 'missing-regime.csv', 'sha256' => 'missing'],
        ]);
        $this->agent($generation, 'monthly_survival');

        $report = app(AgentLifecycleAuditService::class)->audit($lab->symbol, $lab->timeframe, false, false);
        $forwardElite = $this->check($report, 'FORWARD_ELITE_LIFECYCLE');

        $this->assertSame('in_progress', $forwardElite['status']);
        $this->assertSame('complete_screening_then_full_validation', $forwardElite['metrics']['next_stage']);
        $this->assertSame(0, $forwardElite['metrics']['forward_candidate_count']);
        $this->assertSame(0, $forwardElite['metrics']['elite_portfolio_passed_count']);
        $this->assertFalse($forwardElite['metrics']['promotion_evidence']);
    }

    public function test_forward_candidate_without_auditable_gate_is_blocked(): void
    {
        $this->fakeReplayStatus();
        [$lab, $generation] = $this->generation('GBPUSD', 'H1', 1, [
            'constructor_audit' => [
                'planned_slots' => 1,
                'created_agents' => 1,
                'skipped_zero_diff_slots' => [],
            ],
        ]);
        $agent = $this->agent($generation, 'monthly_survival');
        $agent->update(['lifecycle_status' => 'forward_validated']);
        ModelMarketPerformance::create([
            'model_version_id' => $agent->model_version_id,
            'symbol' => 'GBPUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'trend',
            'status' => 'forward_validated',
            'paper_status' => 'pending',
            'evidence_status' => 'valid',
            'metrics' => [],
        ]);

        $report = app(AgentLifecycleAuditService::class)->audit($lab->symbol, $lab->timeframe, false, false);
        $forwardElite = $this->check($report, 'FORWARD_ELITE_LIFECYCLE');

        $this->assertSame('blocked', $forwardElite['status']);
        $this->assertContains('FORWARD_GATE_MISSING', $forwardElite['metrics']['issues']);
        $this->assertSame('repair_or_replay_failed_boundary', $forwardElite['metrics']['next_stage']);
        $this->assertFalse($forwardElite['metrics']['promotion_evidence']);
        $this->assertDatabaseCount('candidate_gate_decisions', 0);
    }

    public function test_failed_elite_portfolio_is_reported_without_being_repaired(): void
    {
        $this->fakeReplayStatus();
        [$lab, $generation] = $this->generation('XAUUSD', 'H1', 1, [
            'constructor_audit' => [
                'planned_slots' => 1,
                'created_agents' => 1,
                'skipped_zero_diff_slots' => [],
            ],
        ]);
        $this->agent($generation, 'monthly_survival');
        $generation->update(['status' => 'screened']);
        $portfolio = EliteAgentPortfolio::create([
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'portfolio_key' => 'audit-elite',
            'status' => 'blocked',
            'gate_status' => 'failed',
            'member_count' => 0,
            'gate_reasons' => ['FAILED_PORTFOLIO_MEMBER_PASSPORT'],
            'evidence' => ['gate' => ['status' => 'failed']],
        ]);

        $report = app(AgentLifecycleAuditService::class)->audit($lab->symbol, $lab->timeframe, false, false);
        $forwardElite = $this->check($report, 'FORWARD_ELITE_LIFECYCLE');

        $this->assertSame('attention', $forwardElite['status']);
        $this->assertContains('ELITE_PORTFOLIO_GATE_FAILED', $forwardElite['metrics']['issues']);
        $this->assertSame(['status' => 'blocked', 'gate_status' => 'failed', 'reasons' => ['FAILED_PORTFOLIO_MEMBER_PASSPORT']], $forwardElite['metrics']['elite_portfolio_gate_failures'][$portfolio->id]);
        $this->assertSame('repair_or_replay_failed_boundary', $forwardElite['metrics']['next_stage']);
        $this->assertSame('blocked', $portfolio->fresh()->status);
    }

    private function fakeReplayStatus(): void
    {
        config([
            'services.ai_service.url' => 'http://ai.test',
            'services.internal_api.token' => 'test-token',
        ]);
        Http::fake(['http://ai.test/api/replay-status' => Http::response([
            'active_requests' => 0,
            'protocol' => 'replay_liveness_v2_bounded_worker',
        ])]);
    }

    /** @param array<string, mixed> $context */
    private function generation(string $symbol, string $timeframe, int $population, array $context): array
    {
        $lab = AiLaboratory::create([
            'symbol' => $symbol,
            'name' => $symbol.' '.$timeframe.' test lab',
            'timeframe' => $timeframe,
            'strategy_families' => ['trend'],
            'is_active' => true,
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 1,
            'trigger_type' => 'test',
            'trigger_context' => $context,
            'population_size' => $population,
            'status' => 'screening',
            'started_at' => now(),
        ]);

        return [$lab, $generation];
    }

    /** @param array<string, mixed> $metadata */
    private function agent(LabGeneration $generation, string $group, array $metadata = []): LabAgent
    {
        $model = ModelVersion::create([
            'name' => 'audit-'.$generation->id.'-'.uniqid(),
            'strategy' => 'audit_strategy',
            'version' => 'v1',
            'generation' => $generation->generation,
            'status' => 'testing',
            'parameters' => ['period' => 10],
            'metadata' => array_merge([
                'population_group' => ['key' => $group],
                'semantic_lineage' => [
                    'mode' => 'no_parent_available',
                    'genetic_parent_model_version_id' => null,
                ],
                'parent_inheritance_protocol' => [
                    'parent_selection' => 'no_parent_available',
                    'legacy_parent_genetic_material' => false,
                ],
                'mutation_constructor_invariant' => [
                    'status' => 'passed',
                    'control_only' => false,
                ],
            ], $metadata),
        ]);

        return LabAgent::create([
            'lab_generation_id' => $generation->id,
            'model_version_id' => $model->id,
            'symbol' => $generation->laboratory->symbol,
            'timeframe' => $generation->laboratory->timeframe,
            'strategy_family' => 'trend',
            'origin' => 'g98_council',
            'lifecycle_status' => 'queued',
            'parameter_diff' => ['period' => ['old' => 10, 'new' => 11]],
        ]);
    }

    /** @return array<string, mixed> */
    private function check(array $report, string $code): array
    {
        return collect($report['laboratories'][0]['checks'])->firstWhere('code', $code);
    }
}
