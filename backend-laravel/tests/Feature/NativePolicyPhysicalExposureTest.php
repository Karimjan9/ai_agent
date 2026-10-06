<?php

namespace Tests\Feature;

use App\Models\LabEvaluationRun;
use App\Models\ModelVersion;
use App\Models\SpecialistCouncilVersion;
use App\Services\LabImmutableEvidenceService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\SpecialistCouncilContractService;
use App\Services\SpecialistCouncilIndependentPanelService;
use App\Services\SpecialistCouncilLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Conditional original-observation owner boundary; no worker/market/qualification proof is invented. */
class NativePolicyPhysicalExposureTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;
    private array $observations = [];
    private int $observationCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = \Mockery::mock(SpecialistCouncilLifecycleService::class)->makePartial();
        $owner->__construct(app(SpecialistCouncilContractService::class), app(ResearchPaperEpochContractService::class), app(LabImmutableEvidenceService::class));
        $owner->shouldReceive('originalIndependentRunObservation')->andReturnUsing(function ($version, LabEvaluationRun $run): ?array {
            $this->observationCalls++;
            return $this->observations[$run->run_id] ?? null;
        });
        $this->app->instance(SpecialistCouncilLifecycleService::class, $owner);
    }

    public function test_seen_window_is_not_fresh_after_model_program_dataset_or_path_relabelling(): void
    {
        [$prior, $plan] = $this->program();
        $this->observationRun($plan, observed: true);
        foreach ([false, true] as $differentProgram) {
            [$fresh, $freshPlan] = $this->program(fast: $differentProgram ? 6 : 4);
            $freshPlan['windows']['a']['dataset_sha256'] = hash('sha256', 'relocated CSV bundle and renamed registry');
            $freshPlan['windows']['a']['authorization_id'] = 'different-server-label';
            $freshPlan['windows']['a']['path'] = '/different-original-source-location.csv';
            $this->savePlan($fresh, $freshPlan);
            $this->assertNotSame($prior->id, $fresh->id);
            $this->assertNotSame($plan['arms']['candidate']['model_version_id'], $freshPlan['arms']['candidate']['model_version_id']);
            try {
                $this->guard($fresh, $freshPlan);
                $this->fail('New labels/program/IDs restored untouched window authority.');
            } catch (\LogicException $error) {
                $this->assertSame('OBSERVED_NATIVE_POLICY_WINDOW_CANNOT_BE_PREREGISTERED', $error->getMessage());
            }
        }
        $this->assertSame(2, $this->observationCalls);
        $this->assertDatabaseCount('paper_authority_admissions', 0);
    }

    public function test_plan_preparation_and_request_only_are_not_actual_observation(): void
    {
        [, $priorPlan] = $this->program();
        $this->observationRun($priorPlan, observed: false, status: 'started');
        $this->observationRun($priorPlan, observed: false, response: false);
        [$fresh, $plan] = $this->program();
        $this->guard($fresh, $plan);
        $this->assertSame(0, $this->observationCalls);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_disjoint_utc_or_market_data_remains_fresh_and_discovery_is_not_an_independent_exposure(): void
    {
        [, $priorPlan] = $this->program();
        $this->observationRun($priorPlan, observed: true);
        foreach ([['EURUSD', '2027-01-01'], ['XAUUSD', '2028-01-01']] as [$symbol, $start]) {
            [$fresh, $plan] = $this->program($symbol, $start);
            $this->guard($fresh, $plan);
        }
        [$discovery, $discoveryPlan] = $this->program('GBPUSD');
        $discoveryPlan['purpose'] = 'research';
        $this->savePlan($discovery, $discoveryPlan);
        $this->observationRun($discoveryPlan, observed: true);
        [$fresh, $plan] = $this->program('GBPUSD');
        $this->guard($fresh, $plan);
        $this->assertSame(0, $this->observationCalls);
    }

    public function test_true_utc_overlap_is_rechecked_from_authenticated_owner_not_a_mutable_projection(): void
    {
        [, $priorPlan] = $this->program();
        $run = $this->observationRun($priorPlan, observed: true);
        [$fresh, $plan] = $this->program();
        $run->update(['request_meta' => [], 'response_meta' => []]);
        $this->expectExceptionMessage('OBSERVED_NATIVE_POLICY_WINDOW_CANNOT_BE_PREREGISTERED');
        $this->guard($fresh, $plan);
    }

    public function test_missing_original_observation_proof_is_dependency_not_untouched_or_qualified(): void
    {
        [, $priorPlan] = $this->program();
        $this->observationRun($priorPlan, observed: false);
        [$fresh, $plan] = $this->program();
        $this->expectExceptionMessage('ORIGINAL_NATIVE_POLICY_EXPOSURE_PROOF_UNAVAILABLE');
        $this->guard($fresh, $plan);
    }

    public function test_unobserved_other_window_in_an_existing_plan_is_not_a_returned_outcome(): void
    {
        [, $priorPlan] = $this->program();
        $this->observationRun($priorPlan, observed: true); // January only.
        [$fresh, $plan] = $this->program(start: '2027-02-01');
        $this->guard($fresh, $plan);
        $this->assertSame(1, $this->observationCalls, 'The prior reserved February window is not an observed January outcome.');
    }

    public function test_original_run_lookup_overflow_is_dependency_not_a_sampled_no_exposure_result(): void
    {
        [, $priorPlan] = $this->program();
        for ($index = 0; $index < 37; $index++) $this->observationRun($priorPlan, observed: true);
        [$fresh, $plan] = $this->program();
        $this->expectExceptionMessage('ORIGINAL_NATIVE_POLICY_EXPOSURE_RUN_BUDGET_EXCEEDED');
        $this->guard($fresh, $plan);
    }

    public function test_bounded_owner_overflow_refuses_without_sampling_or_claiming_observations(): void
    {
        for ($index = 0; $index < 257; $index++) {
            [, $priorPlan] = $this->program();
            $this->observationRun($priorPlan, observed: true);
        }
        [$fresh, $plan] = $this->program();
        try {
            $this->guard($fresh, $plan);
            $this->fail('Only a tail of original owners was checked.');
        } catch (\LogicException $error) {
            $this->assertSame('ORIGINAL_NATIVE_POLICY_EXPOSURE_LOOKUP_BUDGET_EXCEEDED', $error->getMessage());
        }
        $this->assertSame(0, $this->observationCalls);
    }

    private function guard(SpecialistCouncilVersion $version, array $plan): void
    {
        app(SpecialistCouncilIndependentPanelService::class)->assertUnobservedOriginalPhysicalQuestion($version, $plan, 'a');
    }

    private function program(string $symbol = 'XAUUSD', string $start = '2027-01-01', int $fast = 4): array
    {
        $tag = 'exposure-fixture-'.++$this->sequence;
        $model = ModelVersion::create(['name' => $tag, 'strategy' => 'ema_rsi_v1', 'version' => 'v1', 'status' => 'testing',
            'parameters' => ['ema_fast' => $fast, 'ema_slow' => 10], 'metadata' => ['base_strategy' => 'ema_rsi']]);
        $manifest = ['council_id' => $tag, 'version' => 'v1', 'members' => [[
            'specialist_id' => 'hour', 'role' => 'hour', 'version' => 'v1', 'as_of' => '2025-01-01T00:00:00Z',
            'inputs' => ['as_of_closed_candles'], 'scope' => ['symbols' => [$symbol], 'contexts' => ['trend']],
            'known_limits' => ['conditional_guard_only_not_market_proof'],
            'resources' => ['max_compute_ms' => 100, 'max_memory_mb' => 32, 'max_lookback_bars' => 512],
            'horizon' => ['kind' => 'hour', 'decision_interval_seconds' => 300, 'reevaluation_interval_seconds' => 300, 'max_holding_seconds' => 3600],
            'data_requirements' => ['sessions', 'costs'], 'model_version_id' => $model->id,
            'strategy_version' => 'v1', 'tactic_version' => 'v1', 'management_version' => 'v1',
            'capital_weight' => .4, 'risk_per_trade_percent' => .5, 'sensor_timeframes' => ['H4', 'H1', 'M15', 'M5']]],
            'components' => [], 'routing' => ['id' => 'router', 'version' => '1'], 'allocation' => ['id' => 'allocation', 'version' => '1'],
            'risk' => ['id' => 'risk', 'version' => '1'], 'execution' => ['id' => 'native', 'version' => '1',
                'broker_position_mode' => 'hedging', 'opposite_position_policy' => 'hedge', 'max_open_positions' => 8,
                'max_reserved_capital_percent' => 100, 'max_gross_exposure_percent' => 100, 'max_total_risk_percent' => 2,
                'max_drawdown_percent' => 10, 'max_daily_loss_percent' => 3, 'max_expected_cost_percent' => 1],
            'evaluation_policy' => ['objective' => 'net_return_at_equal_risk', 'champion_model_version_id' => $model->id, 'solo_model_version_id' => $model->id]];
        $version = app(SpecialistCouncilLifecycleService::class)->registerDraft($manifest, $tag.'-creator');
        $windows = [];
        foreach (['a', 'b', 'c'] as $index => $key) {
            $from = CarbonImmutable::parse($start, 'UTC')->addMonths($index);
            $windows[$key] = ['window_key' => $key, 'dataset_sha256' => hash('sha256', $tag.$key),
                'start_inclusive' => $from->toIso8601String(), 'end_exclusive' => $from->addDay()->toIso8601String()];
        }
        $plan = ['purpose' => 'independent', 'version_id' => $version->id, 'manifest_hash' => $version->manifest_hash,
            'windows' => $windows, 'arms' => ['candidate' => ['kind' => 'candidate', 'window_key' => 'a', 'model_version_id' => $model->id]]];
        $this->savePlan($version, $plan);
        return [$version, $plan];
    }

    private function savePlan(SpecialistCouncilVersion $version, array $plan): void
    {
        DB::table('specialist_council_evaluation_plans')->updateOrInsert(['specialist_council_version_id' => $version->id],
            ['evaluator_id' => 'independent-conditional-owner', 'plan' => json_encode($plan, JSON_THROW_ON_ERROR),
                'plan_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($plan),
                'sealed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function observationRun(array $plan, bool $observed, string $status = 'completed', bool $response = true): LabEvaluationRun
    {
        $run = LabEvaluationRun::create(['run_id' => (string) Str::uuid(), 'model_version_id' => $plan['arms']['candidate']['model_version_id'],
            'phase' => 'full_validation', 'mode' => 'full', 'status' => $status, 'started_at' => now(),
            'finished_at' => $status === 'completed' ? now() : null, 'request_hash' => hash('sha256', 'conditional original request'),
            'response_hash' => $response ? hash('sha256', 'conditional original response') : null]);
        if ($observed) $this->observations[$run->run_id] = ['symbol' => SpecialistCouncilVersion::findOrFail($plan['version_id'])->manifest['members'][0]['scope']['symbols'][0],
            'start_inclusive' => $plan['windows']['a']['start_inclusive'], 'end_exclusive' => $plan['windows']['a']['end_exclusive'],
            'run_id' => $run->run_id, 'request_hash' => $run->request_hash, 'response_hash' => $run->response_hash];
        return $run;
    }
}
