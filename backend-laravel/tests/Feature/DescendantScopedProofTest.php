<?php

namespace Tests\Feature;

use App\Models\LabEvaluationRun;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabSkillZooEntry;
use App\Models\ModelVersion;
use App\Services\CanonicalSkillCartridgeService;
use App\Services\ContextualCausalTraitCapsuleService;
use App\Services\LabImmutableEvidenceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\TestCase;

/** Synthetic registration tests; no market replay or independent sample exists. */
class DescendantScopedProofTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-09T08:00:00Z');
        $this->mock(ContextualCausalTraitCapsuleService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('assess')->andReturn(['valid' => true]);
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_future_four_arm_scope_is_registered_without_legacy_inheritance_authority(): void
    {
        [$cartridge, $models, $scope] = $this->fixture();
        $owner = app(CanonicalSkillCartridgeService::class);
        $result = $owner->preregisterDescendantProof($cartridge, $models, $scope);

        $this->assertSame('scoped_preregistered', $result['status']);
        $this->assertFalse($result['inheritance_credit']);
        $this->assertSame('preregistered_diagnostic', $result['certificate']['status']);
        $this->assertSame('inheritance', $result['certificate']['scope']);
        $this->assertFalse($result['certificate']['authority']);
        $this->assertFalse($result['certificate']['executable']);
        $this->assertDatabaseCount('descendant_value_trials', 1);
        $this->assertSame(0, DB::table('descendant_value_trials')->where('status', 'settled')->count());
        $inspection = $owner->inspectDescendantProof($result['trial_id'], $result['certificate']['certificate_id']);
        $this->assertSame('scoped_proof_inspected', $inspection['status']);
    }

    public function test_source_or_parameter_drift_cannot_keep_the_original_scope_valid(): void
    {
        [$cartridge, $models, $scope] = $this->fixture();
        $owner = app(CanonicalSkillCartridgeService::class);
        $result = $owner->preregisterDescendantProof($cartridge, $models, $scope);
        $models['P+U']->update(['parameters' => [...$models['P+U']->parameters, 'minimum_confidence' => 1.1]]);

        $inspection = $owner->inspectDescendantProof($result['trial_id'], $result['certificate']['certificate_id']);
        $this->assertSame('DESCENDANT_FROZEN_ARM_DRIFT', $inspection['reason_code']);
        $this->assertFalse($inspection['inheritance_credit']);
    }

    public function test_any_observed_arm_blocks_retrospective_registration(): void
    {
        [$cartridge, $models, $scope] = $this->fixture();
        LabEvaluationRun::create(['run_id' => 'synthetic-observed-before-seal', 'model_version_id' => $models['P']->id,
            'phase' => 'full_validation', 'mode' => 'synthetic_test', 'attempt' => 1, 'status' => 'technical_error']);

        $result = app(CanonicalSkillCartridgeService::class)->preregisterDescendantProof($cartridge, $models, $scope);
        $this->assertSame('DESCENDANT_OBSERVED_ARM_CANNOT_BE_PREREGISTERED', $result['reason_code']);
        $this->assertDatabaseCount('descendant_value_trials', 0);
    }

    public function test_parameter_ablations_cannot_hide_a_different_runtime_program(): void
    {
        [$cartridge, $models, $scope] = $this->fixture();
        $models['P+T+U']->update(['metadata' => ['base_strategy' => 'hybrid', 'risk_governor' => ['new_policy' => true]]]);

        $result = app(CanonicalSkillCartridgeService::class)->preregisterDescendantProof($cartridge, $models, $scope);
        $this->assertSame('DESCENDANT_SHARED_RUNTIME_PROGRAM_REQUIRED', $result['reason_code']);
        $this->assertDatabaseCount('descendant_value_trials', 0);
    }

    public function test_paper_2026_interval_and_missing_original_revision_are_refused(): void
    {
        [$cartridge, $models, $scope] = $this->fixture();
        $owner = app(CanonicalSkillCartridgeService::class);
        $paper = [...$scope, 'validation_start' => '2026-11-01T00:00:00Z', 'validation_end' => '2026-12-01T00:00:00Z'];
        $this->assertSame('DESCENDANT_2027_PROSPECTIVE_PAPER_DISJOINT_INTERVAL_REQUIRED',
            $owner->preregisterDescendantProof($cartridge, $models, $paper)['reason_code']);
        DB::table('skill_cartridge_revisions')->delete();
        $this->assertSame('DESCENDANT_IMMUTABLE_SOURCE_REVISION_REQUIRED',
            $owner->preregisterDescendantProof($cartridge, $models, $scope)['reason_code']);
        $this->assertDatabaseCount('descendant_value_trials', 0);
    }

    public function test_original_run_identities_and_interval_maturity_precede_diagnostic_settlement(): void
    {
        [$cartridge, $models, $scope] = $this->fixture();
        $owner = app(CanonicalSkillCartridgeService::class);
        $result = $owner->preregisterDescendantProof($cartridge, $models, $scope);
        $certificateId = $result['certificate']['certificate_id'];
        $missing = $owner->settleDescendantProof($result['trial_id'], $certificateId, ['P' => 1]);
        $this->assertSame('DESCENDANT_FOUR_ORIGINAL_RUN_IDENTITIES_REQUIRED', $missing['reason_code']);
        $immature = $owner->settleDescendantProof($result['trial_id'], $certificateId,
            ['P' => 1, 'P+T' => 2, 'P+T+U' => 3, 'P+U' => 4]);
        $this->assertSame('DESCENDANT_VALIDATION_INTERVAL_NOT_MATURE', $immature['reason_code']);
        $this->assertFalse($immature['inheritance_credit']);
    }

    public function test_original_four_products_measure_bundle_without_granting_inheritance_credit(): void
    {
        [$cartridge, $models, $scope] = $this->fixture();
        $owner = app(CanonicalSkillCartridgeService::class);
        $result = $owner->preregisterDescendantProof($cartridge, $models, $scope);
        $certificateId = $result['certificate']['certificate_id'];
        $runs = $this->originalProducts($models, $scope, ['P' => 1, 'P+T' => 2, 'P+T+U' => 3, 'P+U' => 3]);
        $settled = $owner->settleDescendantProof($result['trial_id'], $certificateId, $runs);

        $this->assertSame('scoped_diagnostic', $settled['status'], json_encode($settled));
        $this->assertSame('bundle_only', $settled['assessment']['attribution']);
        $this->assertFalse($settled['assessment']['trait_retained']);
        $this->assertFalse($settled['certificate']['authority']);
        $this->assertFalse($settled['inheritance_credit']);
        $this->assertSame(0, DB::table('lab_evolution_credit_events')->where('event_type', 'inheritance_credit')->count());
        $again = $owner->settleDescendantProof($result['trial_id'], $certificateId, $runs);
        $this->assertSame('scoped_diagnostic', $again['status']);
        $this->assertSame(2, DB::table('scoped_research_certificates')->count());
        $this->assertSame(0, DB::table('descendant_value_trials')->where('status', 'settled')->count());
    }

    private function originalProducts(array $models, array $scope, array $values): array
    {
        CarbonImmutable::setTestNow('2027-03-01T00:00:00Z');
        $dataset = hash('sha256', 'synthetic-scoped-authorized-dataset');
        config(['services.instrument_policy.authorized_research_windows' => [[
            'authorization_id' => 'synthetic-scoped-auth', 'research_epoch_id' => 'synthetic-scoped-epoch',
            'dataset_sha256' => $dataset, 'purpose' => 'instrument_independent_validation',
            'start_inclusive' => $scope['validation_start'], 'end_exclusive' => $scope['validation_end'],
        ]]]);
        $lab = AiLaboratory::create(['name' => 'Synthetic scoped descendant products', 'symbol' => 'XAUUSD',
            'timeframe' => 'H1', 'strategy_families' => ['hybrid'], 'is_active' => false]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'synthetic_test', 'status' => 'screened', 'population_size' => 4]);
        $immutable = app(LabImmutableEvidenceService::class);
        $runs = [];
        foreach ($models as $arm => $model) {
            $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
                'origin' => 'synthetic_test', 'lifecycle_status' => 'screened']);
            $run = $immutable->beginRun($agent, 'full_validation', 'synthetic_test', ['code_hash' => $scope['evaluator_hash']]);
            $candles = array_map(fn (int $index): array => ['time' => CarbonImmutable::parse('2027-01-10T00:00:00Z')
                ->addMinutes($index * 5)->toIso8601ZuluString(), 'close' => 2000], range(0, 202));
            $request = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'candles' => $candles,
                'strategies' => [['lab_agent_id' => $agent->id, 'strategy' => $model->strategy, 'parameters' => $model->parameters]],
                'replay_dataset_hash' => $dataset, 'execution_contract' => ['execution_hash' => $scope['execution_hash']]];
            $immutable->attachRequest($run, $request, ['data_hash' => $dataset]);
            $trace = array_map(fn (int $index): array => ['candle_index' => $index, 'candle_time' => $candles[$index]['time'],
                'event_type' => 'signal_evaluation', 'action' => 'WAIT', 'accepted' => false], range(200, 202));
            $response = ['total_trades' => 3, 'trade_ledger' => [['id' => 1], ['id' => 2], ['id' => 3]],
                'trade_ledger_hash' => hash('sha256', 'synthetic-three-ledger'), 'decision_trace' => $trace,
                'data_quality' => ['decision_trace' => ['protocol' => 'candle_decision_trace_v1', 'requested' => true,
                    'complete' => true, 'event_count' => 3, 'evaluated_candle_count' => 3]],
                'replay_manifest' => ['first_candle_at' => $candles[0]['time'], 'last_candle_at' => $candles[202]['time'],
                    'data_partition' => ['screening_source' => 'authorized_post_paper_research_validation'],
                    'instrument_research_window' => ['authorization_id' => 'synthetic-scoped-auth', 'research_epoch_id' => 'synthetic-scoped-epoch']],
                'instrument_research_trace' => ['context_source' => 'decision_time_trade_ledger', 'context_slice_protocol' => 'venue_phase_v1',
                    'exact_context_slices' => [['context' => $scope['context'], 'metrics' => ['trades' => 3, 'profit_factor' => $values[$arm]]]]]];
            $immutable->finishRun($run, 'completed', $response);
            $runs[$arm] = (int) $run->id;
        }

        return $runs;
    }

    private function fixture(): array
    {
        $vectors = [
            'P' => ['minimum_confidence' => 1.0, 'rsi_threshold' => 30, 'risk' => 1],
            'P+T' => ['minimum_confidence' => 1.1, 'rsi_threshold' => 30, 'risk' => 1],
            'P+T+U' => ['minimum_confidence' => 1.1, 'rsi_threshold' => 32, 'risk' => 1],
            'P+U' => ['minimum_confidence' => 1.0, 'rsi_threshold' => 32, 'risk' => 1],
        ];
        $models = [];
        foreach ($vectors as $arm => $parameters) {
            $models[$arm] = ModelVersion::create(['name' => 'synthetic scoped '.$arm, 'strategy' => 'hybrid',
                'version' => 'synthetic-scoped-v1', 'generation' => 1, 'status' => 'testing', 'parameters' => $parameters,
                'metadata' => ['base_strategy' => 'hybrid']]);
        }
        $evidence = ['intervention' => ['old_value' => 1.0, 'tested_value' => 1.1], 'trait_capsule' => []];
        $cartridge = LabSkillZooEntry::create(['skill_key' => 'synthetic-scoped-trait', 'cartridge_key' => 'synthetic-scoped-trait',
            'model_version_id' => $models['P+T']->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'module_key' => 'entry', 'niche_key' => 'trend_up|normal|london', 'gene_key' => 'minimum_confidence', 'status' => 'confirmed',
            'component_status' => 'component_confirmed', 'evidence' => $evidence, 'revision' => 1]);
        DB::table('skill_cartridge_revisions')->insert(['lab_skill_zoo_entry_id' => $cartridge->id, 'revision' => 1,
            'revision_key' => hash('sha256', 'synthetic-scoped-revision'), 'payload' => json_encode($evidence),
            'sealed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $scope = ['validation_start' => '2027-01-01T00:00:00Z', 'validation_end' => '2027-02-01T00:00:00Z',
            'context' => ['regime' => 'trend_up', 'volatility' => 'normal', 'session' => 'london', 'direction' => 'BUY'],
            'evaluator_hash' => hash('sha256', 'synthetic-evaluator'), 'execution_hash' => hash('sha256', 'synthetic-execution'),
            'metric' => 'profit_factor', 'stopping_rule' => ['minimum_trades_per_arm' => 3, 'minimum_effect' => 0.01]];

        return [$cartridge, $models, $scope];
    }
}
