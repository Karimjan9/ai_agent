<?php

namespace Tests\Feature;

use App\Jobs\RunScheduledArtisanCommandJob;
use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\MtfPlaybookFrozenControlRun;
use App\Services\AutonomousLearningProgressDirectorService;
use App\Services\AutonomousModeService;
use App\Services\GenerationAdmissionDecisionService;
use App\Services\GenerationSnapshotAdmissionService;
use App\Services\LabDatasetExportService;
use App\Services\LabPopulationService;
use App\Services\LearningProtocolSafetyService;
use App\Services\LearningVelocityGateService;
use App\Services\MarketDataContinuityService;
use App\Services\MarketDriftDetectionService;
use App\Services\MtfResearchCohortService;
use App\Services\MtfPoweredPriorValidationService;
use App\Services\ResearchLoopArbiterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class HistoricalResearchAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private function lab(): AiLaboratory
    {
        config()->set('services.xauusd_organism.historical_research_until_champion', true);
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'archive-first');

        return AiLaboratory::create(['name' => 'archive-first', 'symbol' => 'XAUUSD',
            'timeframe' => 'H1', 'strategy_families' => ['hybrid'],
            'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
    }

    private function terminal(AiLaboratory $lab, bool $zeroPass = false): LabGeneration
    {
        $generation = $lab->generations()->create(['generation' => 1, 'trigger_type' => 'new_data',
            'status' => 'completed', 'population_size' => 1,
            'trigger_context' => ['data_count' => 0], 'completed_at' => now()]);
        if ($zeroPass) {
            $model = ModelVersion::create(['name' => 'null-screen', 'strategy' => 'hybrid',
                'version' => 'v1', 'generation' => 1, 'status' => 'rejected', 'parameters' => [], 'metadata' => []]);
            $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
                'origin' => 'test', 'lifecycle_status' => 'rejected', 'parameter_diff' => []]);
            CandidateGateDecision::create(['lab_agent_id' => $agent->id, 'stage' => 'screening',
                'decision' => 'failed', 'reason_codes' => ['PROFIT_FACTOR'], 'metrics' => [], 'evaluated_at' => now()]);
        }

        return $generation;
    }

    private function velocity(string $status = 'healthy', bool $allowed = true): void
    {
        $this->mock(LearningVelocityGateService::class, function ($mock) use ($status, $allowed): void {
            $mock->shouldReceive('inspect')->andReturn(['status' => $status, 'allowed' => $allowed]);
        });
    }

    public function test_arbiter_selects_archive_without_reading_live_drift_and_deduplicates(): void
    {
        Queue::fake();
        $this->terminal($this->lab(), true);
        $this->mock(MarketDriftDetectionService::class, fn ($mock) => $mock->shouldReceive('confirmation')->never());
        $this->mock(AutonomousLearningProgressDirectorService::class,
            fn ($mock) => $mock->shouldReceive('advance')->andReturn(['action' => 'WAIT']));
        $this->mock(MtfResearchCohortService::class,
            fn ($mock) => $mock->shouldReceive('candidate')->never());
        $this->mock(MtfPoweredPriorValidationService::class,
            fn ($mock) => $mock->shouldReceive('nextEligible')->with('XAUUSD')->andReturn(null));

        $result = app(ResearchLoopArbiterService::class)->tick();
        $this->assertSame('OPEN_HISTORICAL_RESEARCH_GENERATION', $result['action']);
        $this->assertSame('historical_research', $result['arguments']['--trigger']);
        $this->assertArrayNotHasKey('--force', $result['arguments']);
        $this->assertSame('duplicate_suppressed', app(ResearchLoopArbiterService::class)->tick()['status']);
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
    }

    public function test_only_actionable_powered_prior_precedes_archive_and_its_identity_changes_the_work_key(): void
    {
        $this->terminal($this->lab());
        $this->mock(MarketDriftDetectionService::class, fn ($mock) => $mock->shouldReceive('confirmation')->never());
        $this->mock(AutonomousLearningProgressDirectorService::class,
            fn ($mock) => $mock->shouldReceive('advance')->andReturn(['action' => 'WAIT']));
        $this->mock(MtfResearchCohortService::class, fn ($mock) => $mock->shouldReceive('candidate')->never());
        $first = new MtfPlaybookFrozenControlRun(['symbol' => 'XAUUSD', 'status' => 'completed']);
        $first->id = 101;
        $next = clone $first;
        $next->id = 102;
        $this->mock(MtfPoweredPriorValidationService::class, fn ($mock) => $mock
            ->shouldReceive('nextEligible')->with('XAUUSD')->andReturn($first, $first, $next, null));
        $arbiter = app(ResearchLoopArbiterService::class);
        $selected = $arbiter->tick('XAUUSD', 'H1', true);
        $same = $arbiter->tick('XAUUSD', 'H1', true);
        $changed = $arbiter->tick('XAUUSD', 'H1', true);
        $archive = $arbiter->tick('XAUUSD', 'H1', true);
        $this->assertSame('SETTLE_MTF_POWERED_PRIOR', $selected['action']);
        $this->assertSame($selected['decision_key'], $same['decision_key']);
        $this->assertNotSame($selected['decision_key'], $changed['decision_key']);
        $this->assertSame('OPEN_HISTORICAL_RESEARCH_GENERATION', $archive['action']);
        $this->assertDatabaseCount('research_loop_decisions', 0);
    }

    public function test_archive_admission_after_zero_pass_does_not_claim_independence(): void
    {
        $lab = $this->lab();
        $generation = $this->terminal($lab, true);
        $this->velocity();
        $result = app(GenerationAdmissionDecisionService::class)->decide($lab, $generation,
            ['trigger' => 'historical_research']);
        $this->assertTrue($result['allowed']);
        $this->assertContains('HISTORICAL_ZERO_PASS_CONTINUES_RESEARCH_NOT_INDEPENDENT_CONFIRMATION', $result['reason_codes']);
        $this->assertFalse($result['historical_research_policy']['same_archive_is_independent_evidence']);
        $this->assertFalse($result['promotion_evidence']);
    }

    public function test_archive_dependency_change_reselects_but_unchanged_ticks_do_not(): void
    {
        $this->terminal($this->lab());
        // This case owns historical admission's archive dependency, not the
        // separate Academy preview, which also reads the same provider. Keep
        // that unrelated lane unavailable without weakening key assertions.
        $this->mock(\App\Services\AcademyExperimentMaterializerService::class,
            fn ($mock) => $mock->shouldReceive('proposal')->with('XAUUSD', 'H1')
                ->andReturn(['status' => 'blocked', 'reason' => 'NO_ACADEMY_SOURCE_IN_HISTORICAL_FIXTURE']));
        $this->mock(MarketDriftDetectionService::class, fn ($mock) => $mock->shouldReceive('confirmation')->never());
        $this->mock(AutonomousLearningProgressDirectorService::class,
            fn ($mock) => $mock->shouldReceive('advance')->andReturn(['action' => 'WAIT']));
        $this->mock(MtfResearchCohortService::class,
            fn ($mock) => $mock->shouldReceive('candidate')->never());
        $this->mock(MtfPoweredPriorValidationService::class,
            fn ($mock) => $mock->shouldReceive('nextEligible')->with('XAUUSD')->andReturn(null));
        $this->mock(LabDatasetExportService::class, fn ($mock) => $mock->shouldReceive('foundationDependencyWatermark')
            ->with('XAUUSD', 'H1')->andReturn(
                ['archive_present' => false, 'manifest_hash' => null],
                ['archive_present' => false, 'manifest_hash' => null],
                ['archive_present' => true, 'manifest_hash' => str_repeat('a', 64)],
            ));
        $arbiter = app(ResearchLoopArbiterService::class);
        $first = $arbiter->tick('XAUUSD', 'H1', true);
        $same = $arbiter->tick('XAUUSD', 'H1', true);
        $repaired = $arbiter->tick('XAUUSD', 'H1', true);
        $this->assertSame('OPEN_HISTORICAL_RESEARCH_GENERATION', $first['action']);
        $this->assertSame($first['decision_key'], $same['decision_key']);
        $this->assertNotSame($first['decision_key'], $repaired['decision_key']);
        $this->assertDatabaseCount('research_loop_decisions', 0);
    }

    public function test_active_generation_and_technical_debt_keep_priority(): void
    {
        $lab = $this->lab();
        $generation = $this->terminal($lab);
        $generation->update(['status' => 'screening']);
        $this->velocity('blocked_technical_recovery', false);
        $service = app(GenerationAdmissionDecisionService::class);
        $active = $service->decide($lab, $generation, ['trigger' => 'historical_research']);
        $this->assertFalse($active['allowed']);
        $this->assertSame($service::WAIT_ACTIVE_WORK, $active['decision']);
        $generation->update(['status' => 'completed']);
        $technical = $service->decide($lab, $generation, ['trigger' => 'historical_research']);
        $this->assertFalse($technical['allowed']);
        $this->assertSame($service::RECOVER_TECHNICAL, $technical['decision']);
    }

    public function test_safety_pause_and_operator_pause_cannot_be_overridden_by_force(): void
    {
        $lab = $this->lab();
        $this->velocity('strategy_deadlock', false);
        $this->mock(LearningProtocolSafetyService::class,
            fn ($mock) => $mock->shouldReceive('generationCreationPaused')->andReturn(true));
        $service = app(GenerationAdmissionDecisionService::class);
        $result = $service->decide($lab, null, ['trigger' => 'historical_research', 'force' => true]);
        $this->assertFalse($result['allowed']);
        $this->assertContains('GENERATION_CREATION_SAFETY_PAUSED', $result['reason_codes']);
        app(AutonomousModeService::class)->pause('XAUUSD', 'H1', 'test', 'operator');
        $this->assertFalse($service->decide($lab, null, ['trigger' => 'historical_research', 'force' => true])['allowed']);
    }

    public function test_policy_stops_at_valid_champion_but_not_provisional_or_invalidated_labels(): void
    {
        $this->lab();
        $service = app(GenerationAdmissionDecisionService::class);
        $model = ModelVersion::create(['name' => 'champion-label', 'strategy' => 'hybrid',
            'version' => 'v1', 'status' => 'testing', 'parameters' => [], 'metadata' => []]);
        $row = ModelMarketPerformance::create(['model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'status' => 'champion', 'evidence_status' => 'provisional']);
        $this->assertTrue($service->historicalResearchPolicy('XAUUSD', 'H1')['eligible']);
        $row->update(['evidence_status' => 'valid']);
        $this->assertFalse($service->historicalResearchPolicy('XAUUSD', 'H1')['eligible']);
        $row->update(['invalidated_at' => now()]);
        $this->assertTrue($service->historicalResearchPolicy('XAUUSD', 'H1')['eligible']);
        $this->assertFalse($service->historicalResearchPolicy('EURUSD', 'H1')['eligible']);
        config()->set('services.xauusd_organism.historical_research_until_champion', false);
        $this->assertFalse($service->historicalResearchPolicy('XAUUSD', 'H1')['eligible']);
    }

    public function test_constructor_builds_successor_with_zero_new_live_candles_and_no_live_feed(): void
    {
        $lab = $this->lab();
        $this->terminal($lab);
        $this->velocity();
        config()->set('services.market_data.provider', 'dukascopy');
        $this->mock(MarketDataContinuityService::class, fn ($mock) => $mock->shouldReceive('isReady')->never());
        $this->mock(LabDatasetExportService::class, fn ($mock) => $mock->shouldReceive('ensureFoundationDataset')
            ->with('XAUUSD', 'H1')->once()->andReturn(['sha256' => str_repeat('a', 64),
                'path' => 'validated-archive.csv', 'manifest' => ['row_count' => 123000,
                    'first_candle_at' => '2005-01-03T00:00:00Z', 'last_candle_at' => '2025-12-31T23:00:00Z']]));
        $service = app(LabPopulationService::class);
        $generation = $service->build('XAUUSD', 'historical_research', false, 'H1', [], false, false, 2);
        $this->assertNotNull($generation, json_encode($service->lastBuildOutcome()));
        $this->assertSame(2, (int) $generation->generation);
        $this->assertSame('historical_research', $generation->trigger_type);
        $this->assertSame(0, data_get($generation->trigger_context, 'new_candles'));
        $this->assertSame(0, data_get($generation->trigger_context, 'data_count'));
        $this->assertTrue(app(GenerationAdmissionDecisionService::class)->isHistoricalGeneration($generation));
        $this->assertSame(str_repeat('a', 64), data_get($generation->trigger_context, 'historical_research_admission.archive_sha256'));
    }

    public function test_missing_archive_remains_a_dependency_not_permission_to_use_live_data(): void
    {
        $lab = $this->lab();
        $this->terminal($lab);
        $this->mock(LabDatasetExportService::class, fn ($mock) => $mock->shouldReceive('ensureFoundationDataset')
            ->andThrow(new \RuntimeException('archive missing')));
        $service = app(LabPopulationService::class);
        $this->assertNull($service->build('XAUUSD', 'historical_research', false, 'H1', [], false, false));
        $this->assertSame('HISTORICAL_RESEARCH_ARCHIVE_NOT_READY', $service->lastBuildOutcome()['reason_code']);
        $this->assertDatabaseCount('lab_generations', 1);
    }

    public function test_snapshot_rejects_hash_switch_and_2026_context_even_when_live_snapshot_is_paper_only(): void
    {
        $lab = $this->lab();
        $generation = $this->terminal($lab);
        $path = tempnam(sys_get_temp_dir(), 'historical-admission-');
        try {
            $hash = hash_file('sha256', $path);
            $manifest = ['first_candle_at' => '2025-01-01T00:00:00Z', 'last_candle_at' => '2025-12-31T20:00:00Z'];
            $context = ['historical_research_admission' => [
                ...app(GenerationAdmissionDecisionService::class)->historicalResearchPolicy('XAUUSD', 'H1'),
                'archive_sha256' => $hash,
            ], 'canonical_dataset_snapshots' => ['foundation' => ['path' => $path, 'sha256' => $hash, 'manifest' => $manifest],
                'price' => ['manifest' => ['data_role' => 'paper_only', 'last_candle_at' => '2026-10-01T00:00:00Z']]],
                'mtf_bundle_manifest' => ['streams' => array_fill_keys(['M5', 'H4', 'H1', 'M15'], $manifest)]];
            $generation->trigger_context = $context;
            $service = app(GenerationSnapshotAdmissionService::class);
            $this->assertSame([], $service->historicalResearchReasons($generation));
            $context['historical_research_admission']['archive_sha256'] = str_repeat('b', 64);
            $generation->trigger_context = $context;
            $this->assertContains('HISTORICAL_RESEARCH_ARCHIVE_IDENTITY_MISMATCH', $service->historicalResearchReasons($generation));
            $context['historical_research_admission']['archive_sha256'] = $hash;
            $context['mtf_bundle_manifest']['streams']['M5']['last_candle_at'] = '2026-01-01T00:00:00Z';
            $generation->trigger_context = $context;
            $this->assertContains('HISTORICAL_RESEARCH_M5_PERIOD_INVALID', $service->historicalResearchReasons($generation));
            $context['mtf_bundle_manifest']['streams']['M5']['last_candle_at'] = '2026-01-01 00:00:00';
            $generation->trigger_context = $context;
            $this->assertContains('HISTORICAL_RESEARCH_M5_PERIOD_INVALID', $service->historicalResearchReasons($generation));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $generation->trigger_type = 'historical_research';
        $generation->trigger_context = [];
        $this->assertSame(['HISTORICAL_RESEARCH_ADMISSION_INVALID'], $service->historicalResearchReasons($generation));
    }
}
