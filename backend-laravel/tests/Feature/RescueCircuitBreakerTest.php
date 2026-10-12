<?php

namespace Tests\Feature;

use App\Console\Commands\BuildTemporalFoundationWindows;
use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\SystemEvent;
use App\Services\AdaptiveParentFrontierService;
use App\Services\LabPopulationService;
use App\Services\LearningProtocolSafetyService;
use App\Services\RescueCircuitBreakerService;
use App\Services\StrategyParameterSchemaService;
use App\Services\StructuralResearchCohortService;
use App\Services\TemporalAblationProtocolService;
use App\Services\TemporalAblationRunnerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RescueCircuitBreakerTest extends TestCase
{
    use RefreshDatabase;

    private string $originalStorage;
    private string $fixtureStorage;
    private string $holdoutHash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStorage = storage_path();
        $this->fixtureStorage = sys_get_temp_dir().'/rescue-contract-fixture-'.bin2hex(random_bytes(8));
        \Illuminate\Support\Facades\File::ensureDirectoryExists($this->fixtureStorage.'/app/temporal-ablation');
        $this->app->useStoragePath($this->fixtureStorage);
        $this->writeSyntheticHoldout();
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        $resolved = realpath($this->fixtureStorage);
        $prefix = str_replace('\\', '/', (string) realpath(sys_get_temp_dir())).'/rescue-contract-fixture-';
        if ($resolved && str_starts_with(str_replace('\\', '/', $resolved), $prefix)) {
            \Illuminate\Support\Facades\File::deleteDirectory($resolved);
        }
        parent::tearDown();
    }

    /** Synthetic contract mechanics; these bytes never prove unused market history. */
    private function writeSyntheticHoldout(string $first = '2014-01-01T00:00:00Z'): void
    {
        $file = storage_path('app/temporal-ablation/fixture.csv');
        $csv = "time,open,high,low,close,volume\n";
        $origin = \Carbon\CarbonImmutable::parse($first, 'UTC');
        foreach (range(0, 23) as $index) $csv .= $origin->addHours($index)->toIso8601String().",100,101,99,100,10\n";
        file_put_contents($file, $csv);
        $this->holdoutHash = hash_file('sha256', $file);
        file_put_contents(storage_path('app/temporal-ablation/XAUUSD_H1_manifest.json'), json_encode([
            'protocol' => 'temporal_clean_ablation_manifest_v2', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'independent_attestation' => true, 'independent_holdout' => true,
            'data_identity_protocol' => 'content_and_timestamp_disjoint_v1', 'synthetic_test_fixture' => true,
            'windows' => [['role' => 'sealed_holdout', 'window_id' => 'synthetic-disjoint-input',
                'dataset_path' => $file, 'data_hash' => $this->holdoutHash, 'sealed' => true,
                'independent_from_rescue' => true, 'candle_count' => 24,
                'attestation' => 'derived_from_independent_source_non_overlap_v1']],
        ], JSON_THROW_ON_ERROR));
    }

    public function test_paper_clocks_cannot_reopen_rescue_even_with_a_real_disjoint_historical_file(): void
    {
        $lab = $this->lab();
        $profile = [...$this->profile(), 'cohort_mode' => StructuralResearchCohortService::COHORT_MODE];
        $service = app(RescueCircuitBreakerService::class);
        $generation = $this->rescueGeneration($lab, 1, 'old-source', 100, $profile);
        foreach ([null, 'invalid', '2026-01-01T00:00:00Z', '2027-01-01T00:00:00Z',
            '2028-01-01T00:00:00Z', '2035-01-01T00:00:00Z', '2098-01-01T00:00:00Z'] as $clock) {
            $snapshot = ['data_fingerprint' => 'new-source', 'data_count' => 124, 'latest_candle' => $clock];
            $decision = $service->admission($lab, $profile, $generation, $snapshot);
            $this->assertFalse($decision['allowed'], (string) $clock);
            $this->assertFalse(data_get($decision, 'history.independent_new_evidence'));
            $this->assertFalse($service->independentEvidenceAdmission($lab, $generation, $profile, $snapshot)['allowed']);
        }
    }

    public function test_copied_holdout_labels_cannot_replace_original_bytes(): void
    {
        \Illuminate\Support\Facades\File::delete(storage_path('app/temporal-ablation/XAUUSD_H1_manifest.json'));
        $profile = [...$this->profile(), 'cohort_mode' => StructuralResearchCohortService::COHORT_MODE,
            'evidence_admission' => ['independent_holdout' => ['status' => 'ready']],
            'sealed_holdout' => ['status' => 'sealed']];
        $decision = app(RescueCircuitBreakerService::class)->independentEvidenceAdmission($this->lab(), null, $profile,
            ['data_count' => 200, 'latest_candle' => '2025-01-01T00:00:00Z', 'independent_data_hash' => str_repeat('a', 64)]);
        $this->assertFalse($decision['allowed']);
        $this->assertFalse(data_get($decision, 'sealed_holdout_evidence.allowed'));
        $this->assertSame('SEALED_HOLDOUT_MANIFEST_MISSING', data_get($decision, 'sealed_holdout_evidence.reason'));
    }

    public function test_hash_matching_paper_file_cannot_use_a_historical_manifest_label(): void
    {
        $this->writeSyntheticHoldout('2027-01-01T00:00:00Z');
        $profile = [...$this->profile(), 'cohort_mode' => StructuralResearchCohortService::COHORT_MODE];
        $decision = app(RescueCircuitBreakerService::class)->independentEvidenceAdmission($this->lab(), null, $profile,
            ['data_count' => 200, 'latest_candle' => '2025-01-01T00:00:00Z']);
        $this->assertFalse($decision['allowed']);
        $this->assertTrue(data_get($decision, 'sealed_holdout_evidence.file_hash_matches_manifest'));
        $this->assertFalse(data_get($decision, 'sealed_holdout_evidence.file_events_pre_paper'));
    }

    public function test_snapshot_counts_only_historical_candles(): void
    {
        $symbol = \App\Models\Symbol::create(['code' => 'XAUUSD', 'display_name' => 'Synthetic gold']);
        foreach (['2025-01-01T00:00:00Z', '2026-01-01T00:00:00Z', '2027-01-01T00:00:00Z'] as $time) {
            \App\Models\Candle::create(['symbol_id' => $symbol->id, 'timeframe' => 'H1', 'time' => $time,
                'open' => 100, 'high' => 101, 'low' => 99, 'close' => 100, 'volume' => 10]);
        }
        $snapshot = app(RescueCircuitBreakerService::class)->currentDataSnapshot($this->lab());
        $this->assertSame(1, $snapshot['data_count']);
        $this->assertStringStartsWith('2025-01-01', $snapshot['latest_candle']);
    }

    public function test_three_same_temporal_cohorts_and_twelve_siblings_are_blocked(): void
    {
        $lab = $this->lab();
        $profile = $this->profile();
        $service = app(RescueCircuitBreakerService::class);
        $profile['hypothesis_hash'] = $service->hypothesisHash($profile);

        for ($generation = 1; $generation <= 3; $generation++) {
            $this->rescueGeneration($lab, $generation, 'same-dataset', 100, $profile);
        }

        $latest = $lab->generations()->latest('generation')->firstOrFail();
        $decision = $service->admission($lab, $profile, $latest, [
            'data_fingerprint' => 'same-dataset',
            'data_count' => 100,
            'latest_candle' => '2025-08-01 00:00:00',
        ]);

        $this->assertFalse($decision['allowed']);
        $this->assertSame(RescueCircuitBreakerService::BLOCKED_NEED_NEW_EVIDENCE, $decision['decision']);
        $this->assertSame(3, data_get($decision, 'history.exact_cohort_count'));
        $this->assertSame(15, data_get($decision, 'history.exact_sibling_count'));
        $this->assertFalse(data_get($decision, 'history.independent_new_evidence'));
        $this->assertTrue(data_get($decision, 'history.family_reused_dataset_history'));
        $this->assertSame(0.0, data_get($decision, 'research_allocation.effective_shares.targeted_rescue'));

        $service->recordBlocked($lab, $decision, $latest);
        $this->assertDatabaseHas('system_events', ['summary' => RescueCircuitBreakerService::BLOCKED_NEED_NEW_EVIDENCE]);
    }

    public function test_one_rescue_cannot_reopen_on_the_same_dataset_but_a_closed_day_tail_can(): void
    {
        $lab = $this->lab();
        $profile = $this->profile();
        $service = app(RescueCircuitBreakerService::class);
        $profile['hypothesis_hash'] = $service->hypothesisHash($profile);
        $generation = $this->rescueGeneration($lab, 1, 'dataset-a', 100, $profile);

        $same = $service->admission($lab, $profile, $generation, [
            'data_fingerprint' => 'dataset-a',
            'data_count' => 100,
            'latest_candle' => '2025-08-01 00:00:00',
        ]);
        $this->assertFalse($same['allowed']);

        $newWindow = $service->admission($lab, $profile, $generation, [
            'data_fingerprint' => 'dataset-b',
            'data_count' => 124,
            'latest_candle' => '2025-08-02 00:00:00',
        ]);
        $this->assertTrue($newWindow['allowed']);
        $this->assertTrue(data_get($newWindow, 'history.independent_new_evidence'));
        $this->assertSame(24, data_get($newWindow, 'history.fresh_candles'));
    }

    public function test_temporal_window_builder_uses_the_actual_screening_period_not_the_full_foundation_manifest(): void
    {
        $lab = $this->lab();
        $this->rescueGeneration($lab, 1, 'dataset-a', 100, $this->profile());

        $method = new \ReflectionMethod(BuildTemporalFoundationWindows::class, 'priorGenerationRanges');
        $method->setAccessible(true);
        $ranges = $method->invoke(app(BuildTemporalFoundationWindows::class), 'XAUUSD', 'H1');

        $this->assertCount(1, $ranges);
        $this->assertSame('2025-02-27 00:00:00', $ranges[0]['first']->format('Y-m-d H:i:s'));
        $this->assertSame('2025-12-31 23:59:59', $ranges[0]['last']->format('Y-m-d H:i:s'));
    }

    public function test_valid_sealed_temporal_holdout_manifest_admits_structural_evidence_without_fresh_tail(): void
    {
        $lab = $this->lab();
        $source = new LabGeneration([
            'data_fingerprint' => 'rolling-source',
            'trigger_context' => ['data_count' => 6173],
        ]);
        $profile = [
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'protocol' => LabPopulationService::TARGETED_RESCUE_PROFILE_PROTOCOL,
            'cohort_mode' => StructuralResearchCohortService::COHORT_MODE,
        ];

        $decision = app(RescueCircuitBreakerService::class)->independentEvidenceAdmission(
            $lab,
            $source,
            $profile,
            [
                'data_fingerprint' => 'rolling-source-new',
                'data_count' => 6180,
                'latest_candle' => '2025-08-18 00:00:00',
            ],
        );

        $this->assertTrue($decision['allowed']);
        $this->assertSame('SEALED_INDEPENDENT_HOLDOUT_READY', data_get($decision, 'sealed_holdout_evidence.reason'));
        $this->assertTrue(data_get($decision, 'sealed_holdout_evidence.file_hash_matches_manifest'));
        $this->assertSame(7, $decision['fresh_candles']);
        $this->assertFalse($decision['promotion_evidence']);
        $this->assertFalse($decision['candidate_unused_demonstrated']);
        $this->assertFalse($decision['scientific_independent_validation_authority']);
    }

    public function test_a_sealed_holdout_hash_cannot_reopen_the_same_rescue_family(): void
    {
        $lab = $this->lab();
        $profile = [
            ...$this->profile(),
            'cohort_mode' => StructuralResearchCohortService::COHORT_MODE,
        ];
        $service = app(RescueCircuitBreakerService::class);
        $profile['hypothesis_hash'] = $service->hypothesisHash($profile);
        $generation = $this->rescueGeneration($lab, 1, 'rolling-source', 6173, $profile);
        $holdoutHash = $this->holdoutHash;
        $generation->update(['trigger_context' => [
            ...$generation->trigger_context,
            'independent_evidence_admission' => [
                'sealed_holdout_evidence' => ['data_hash' => $holdoutHash],
            ],
        ]]);

        $decision = $service->admission($lab, $profile, $generation->fresh(), [
            'data_fingerprint' => 'rolling-source',
            'data_count' => 6173,
            'latest_candle' => '2025-08-18 00:00:00',
        ]);

        $this->assertFalse($decision['allowed']);
        $this->assertSame(RescueCircuitBreakerService::BLOCKED_NEED_NEW_EVIDENCE, $decision['decision']);
        $this->assertTrue(data_get($decision, 'history.sealed_holdout_consumed_by_family'));
    }

    public function test_fresh_chronological_window_can_reopen_after_holdout_was_consumed(): void
    {
        $lab = $this->lab();
        $profile = [
            ...$this->profile(),
            'cohort_mode' => StructuralResearchCohortService::COHORT_MODE,
        ];
        $service = app(RescueCircuitBreakerService::class);
        $profile['hypothesis_hash'] = $service->hypothesisHash($profile);
        $generation = $this->rescueGeneration($lab, 1, 'rolling-source', 6173, $profile);
        $holdoutHash = $this->holdoutHash;
        $generation->update(['trigger_context' => [
            ...$generation->trigger_context,
            'independent_evidence_admission' => [
                'sealed_holdout_evidence' => ['data_hash' => $holdoutHash],
            ],
        ]]);

        $decision = $service->admission($lab, $profile, $generation->fresh(), [
            'data_fingerprint' => 'rolling-source-new-window',
            'data_count' => 6197,
            'latest_candle' => '2025-08-19 00:00:00',
        ]);

        $this->assertTrue($decision['allowed']);
        $this->assertSame(RescueCircuitBreakerService::ADMITTED, $decision['decision']);
        $this->assertTrue(data_get($decision, 'history.sealed_holdout_consumed_by_family'));
        $this->assertTrue(data_get($decision, 'history.chronological_new_evidence'));
        $this->assertTrue(data_get($decision, 'history.independent_new_evidence'));
        $this->assertSame(24, data_get($decision, 'history.fresh_candles'));
    }

    public function test_temporal_ablation_requires_paired_four_variants_and_three_windows(): void
    {
        $service = app(TemporalAblationProtocolService::class);
        $windows = [];
        foreach (['w1', 'w2', 'w3'] as $order => $windowId) {
            $windows[] = [
                'window_id' => $windowId,
                'chronological_order' => $order + 1,
                'data_hash' => 'data-'.$windowId,
                'execution_hash' => 'execution-v1',
                'variants' => [
                    'control' => ['temporal_margin' => .90],
                    'state_only' => ['temporal_margin' => 1.05],
                    'calibration_only' => ['temporal_margin' => $order === 2 ? .95 : 1.02],
                    'interaction' => ['temporal_margin' => 1.01],
                ],
            ];
        }

        $result = $service->evaluate($windows);

        $this->assertSame('qualified', $result['status']);
        $this->assertContains('state_only', $result['qualified_variants']);
        $this->assertContains('interaction', $result['qualified_variants']);
        $this->assertNotContains('control', $result['qualified_variants']);
        $this->assertSame(3, $result['observed_window_count']);
    }

    public function test_clean_temporal_ablation_without_sealed_windows_is_persistently_blocked(): void
    {
        $lab = $this->lab();
        $result = app(TemporalAblationRunnerService::class)->plan($lab, null, null, []);

        $this->assertFalse($result['allowed']);
        $this->assertSame(RescueCircuitBreakerService::BLOCKED_NEED_NEW_EVIDENCE, $result['decision']);
        $this->assertContains('TEMPORAL_ABLATION_WINDOWS_INCOMPLETE', $result['reason_codes']);
        $this->assertDatabaseHas('lab_temporal_ablation_runs', [
            'symbol' => 'XAUUSD',
            'status' => 'blocked',
            'promotion_evidence' => false,
        ]);
    }

    public function test_same_temporal_ablation_identity_cannot_be_replanned_after_block(): void
    {
        $lab = $this->lab();
        $runner = app(TemporalAblationRunnerService::class);

        $first = $runner->plan($lab, null, null, []);
        $second = $runner->plan($lab, null, null, []);

        $this->assertSame($first['run']->id, $second['run']->id);
        $this->assertFalse($second['allowed']);
        $this->assertContains('TEMPORAL_ABLATION_IDENTITY_ALREADY_EVALUATED', $second['reason_codes']);
        $this->assertSame('blocked', $second['run']->status);
    }

    public function test_temporal_ablation_audit_projection_refreshes_stale_reason_codes(): void
    {
        $lab = $this->lab();
        $result = app(TemporalAblationRunnerService::class)->plan($lab, null, null, []);
        $run = $result['run'];
        $event = SystemEvent::query()->where('event_key', 'temporal_ablation:'.$run->run_key)->firstOrFail();
        $event->update(['payload' => ['reason_codes' => ['STALE_REASON'], 'promotion_evidence' => false]]);

        app(TemporalAblationRunnerService::class)->syncAuditEvent($run->fresh(), $lab);

        $event = $event->fresh();
        $this->assertSame($run->reason_codes, data_get($event->payload, 'reason_codes'));
        $this->assertNotSame('STALE_REASON', data_get($event->payload, 'reason_codes.0'));
        $this->assertNotEmpty(data_get($event->payload, 'payload_hash'));
    }

    public function test_challenger_reason_codes_keep_low_pf_and_pending_forward_out_of_parent_frontier(): void
    {
        $model = ModelVersion::create([
            'name' => 'pending-challenger',
            'strategy' => 'xauusd_hybrid_pending_challenger',
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'parameters' => app(StrategyParameterSchemaService::class)->defaults('hybrid'),
            'metadata' => [],
            'evidence_status' => 'valid',
        ]);
        $performance = ModelMarketPerformance::create([
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'status' => 'challenger',
            'evidence_status' => 'valid',
            'sample_count' => 80,
            'rolling_windows_count' => 3,
            'rolling_forward_wins' => 3,
            'metrics' => [
                'profit_factor' => 1.18,
                'max_drawdown_percent' => 8,
                'is_overfit' => false,
                'monte_carlo' => ['risk_of_ruin_percent' => 5],
                'paired_replay' => ['status' => 'pending'],
            ],
        ]);

        $method = new \ReflectionMethod(AdaptiveParentFrontierService::class, 'parentEligibilityProfile');
        $method->setAccessible(true);
        $profile = $method->invoke(app(AdaptiveParentFrontierService::class), $model, $performance);

        $this->assertFalse($profile['parent_eligible']);
        $this->assertContains('rejected_low_pf', $profile['parent_selection_reasons']);
        $this->assertContains('rejected_no_independent_forward', $profile['parent_selection_reasons']);
        $this->assertContains('rejected_pending_paired_replay', $profile['parent_selection_reasons']);
    }

    private function lab(): AiLaboratory
    {
        return AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'XAUUSD Circuit Breaker Test',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid'],
            'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
    }

    /** @return array<string, mixed> */
    private function profile(): array
    {
        return [
            'protocol' => LabPopulationService::TARGETED_RESCUE_PROFILE_PROTOCOL,
            'rescue_protocol' => LearningProtocolSafetyService::CONTROLLED_RESCUE_PROTOCOL,
            'temporary' => true,
            'promotion_evidence' => false,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'dominant_target' => 'temporal_stability',
            'failure_specific_lane' => 'temporal_stability',
            'repair_anchors' => [[
                'id' => 77,
                'failure_target' => 'temporal_stability',
                'parameter_fingerprint' => 'anchor-fingerprint',
            ]],
            'temporal_mutation_hypothesis' => [
                'hypothesis_protocol' => LabPopulationService::TEMPORAL_STATE_PERSISTENCE_HYPOTHESIS,
                'genes' => ['loss_cooldown_candles'],
                'direction_rule' => ['loss_cooldown_candles' => 'increase'],
            ],
            'cohort_contract' => ['protocol' => 'four_siblings_plus_control_v1'],
        ];
    }

    private function rescueGeneration(AiLaboratory $lab, int $number, string $dataFingerprint, int $dataCount, array $profile): LabGeneration
    {
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => $number,
            'trigger_type' => 'candidate_handoff',
            'population_size' => 5,
            'status' => 'screened',
            'data_fingerprint' => $dataFingerprint,
            'trigger_context' => [
                'data_count' => $dataCount,
                'new_candles' => 0,
                'targeted_failure_profile' => $profile,
            ],
        ]);
        for ($slot = 1; $slot <= 5; $slot++) {
            $model = ModelVersion::create([
                'name' => 'circuit-'.$number.'-'.$slot,
                'strategy' => 'circuit-'.$number.'-'.$slot,
                'version' => 'v1',
                'generation' => $number,
                'status' => 'testing',
                'parameters' => ['loss_cooldown_candles' => 4],
                'metadata' => [],
                'evidence_status' => 'valid',
            ]);
            $agent = LabAgent::create([
                'lab_generation_id' => $generation->id,
                'model_version_id' => $model->id,
                'symbol' => 'XAUUSD',
                'timeframe' => 'H1',
                'strategy_family' => 'hybrid',
                'origin' => 'targeted_failure_profile',
                'lifecycle_status' => 'screened',
                'parameter_diff' => $slot === 5 ? [] : ['loss_cooldown_candles' => ['old' => 4, 'new' => 7]],
            ]);
            CandidateGateDecision::create([
                'lab_agent_id' => $agent->id,
                'stage' => 'screening',
                'decision' => 'failed',
                'reason_codes' => ['FAILED_CALENDAR_MONTH_SURVIVAL'],
                'metrics' => [
                    'period' => '2025-02-27 - 2025-12-31',
                    'gate_margin' => ['target_margin' => -.72],
                ],
                'evaluated_at' => now(),
            ]);
        }

        return $generation->fresh(['agents']);
    }
}
