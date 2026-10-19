<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentWorkItem;
use App\Services\AutonomousModeService;
use App\Services\FiniteOriginalComponentPreparationService;
use App\Services\GenerationAdmissionDecisionService;
use App\Services\InstrumentResearchWindowService;
use App\Services\LabPopulationService;
use App\Services\ResearchExperimentConversionKernelService;
use App\Services\ResearchExperimentWorkConsumerService;
use App\Services\ResearchLoopArbiterService;
use App\Services\ScopedResearchCertificateService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

/** Conditional data/admission/release seams; actual constructor, birth, origin, certificate and capture execute. */
class FiniteOriginalComponentPreparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T13:00:00Z'));
        config(['services.internal_api.token' => str_repeat('finite-source-test-', 3),
            'services.instrument_policy.authorized_research_windows' => [],
            'services.xauusd_organism.historical_research_until_champion' => true]);
        AiLaboratory::create(['name' => 'Conditional finite source', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'lifecycle_mode' => 'lighthouse', 'is_active' => true]);
        $this->partialMock(\App\Services\LabImmutableEvidenceService::class,
            fn ($mock) => $mock->shouldReceive('codeHash')->andReturn(hash('sha256', 'conditional-frozen-release-owner')));
        Http::preventStrayRequests(); Bus::fake();
    }

    public function test_registration_is_idempotent_and_pause_cannot_claim_or_birth_sources(): void
    {
        app(AutonomousModeService::class)->pause('XAUUSD', 'H1', 'test', 'conditional operator pause');
        $owner = app(FiniteOriginalComponentPreparationService::class);
        $first = $owner->register('cold-original-v1', $this->design());
        $second = $owner->register('cold-original-v1', $this->design());
        $this->assertSame('recorded', $first['status']);
        $this->assertSame($first['work_id'], $second['work_id']);
        $this->assertSame('already_registered', $second['status']);
        $work = ResearchExperimentWorkItem::findOrFail($first['work_id']);
        $this->assertSame('AUTONOMOUS_MODE_STOPPED', $owner->inspectWork($work)['reason_code']);
        $this->assertSame([], app(ResearchExperimentConversionKernelService::class)->claimForOwner(ResearchLoopArbiterService::class));
        $this->assertDatabaseCount('lab_generations', 0);
        $this->assertDatabaseCount('model_versions', 0);
        $this->assertDatabaseCount('research_experiment_work_items', 1);
        Bus::assertNothingDispatched(); Http::assertNothingSent();
    }

    public function test_missing_server_data_stays_a_dependency_and_immutable_question_cannot_change(): void
    {
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'conditional finite source');
        $owner = app(FiniteOriginalComponentPreparationService::class);
        $first = $owner->register('cold-missing-data', $this->design());
        $work = ResearchExperimentWorkItem::findOrFail($first['work_id']);
        $this->assertSame('FINITE_COMPONENT_ORIGINAL_SERVER_DATA_REQUIRED', $owner->inspectWork($work)['reason_code']);
        $changed = $this->design(); $changed['metric'] = 'net_profit';
        try { $owner->register('cold-missing-data', $changed); $this->fail('Question drift must be refused'); }
        catch (LogicException $error) { $this->assertSame('FINITE_COMPONENT_QUESTION_IMMUTABLE', $error->getMessage()); }
        $this->assertDatabaseCount('lab_generations', 0);
        $this->assertDatabaseCount('model_versions', 0);
        Bus::assertNothingDispatched(); Http::assertNothingSent();
    }

    public function test_canonical_constructor_ignores_copied_caller_proof_body_and_uses_original_sealed_design(): void
    {
        $this->conditionalReadyOwners();
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'conditional finite source');
        $owner = app(FiniteOriginalComponentPreparationService::class);
        $owner->register('cold-caller-body-substitution', $this->design());
        $work = app(ResearchExperimentConversionKernelService::class)->claimForOwner(ResearchLoopArbiterService::class, 1)[0];
        $proof = $owner->inspectWork($work->fresh('receipt'));
        $proof['spec']['lab_id'] = 987654321;
        $proof['spec']['design']['metric'] = 'net_profit';
        $proof['spec']['design']['native_execution']['risk_policy']['risk_per_trade_percent'] = 50;
        // A copied genuine hash must not make a modified duplicate body genuine.
        $generation = app(LabPopulationService::class)->buildFiniteOriginalComponent($work, $proof);
        $this->assertSame((int) AiLaboratory::sole()->id, (int) $generation->ai_laboratory_id);
        $certificate = \App\Models\ScopedResearchCertificate::where('record_type', 'preregistration')->sole();
        $this->assertSame('drawdown', data_get($certificate->payload, 'design.metric'));
        $this->assertSame(.5, (float) data_get($certificate->payload, 'design.native_execution.risk_policy.risk_per_trade_percent'));
        $source = \App\Models\AgentLearningCausalExperiment::findOrFail($certificate->source_id);
        $this->assertSame('scoped_source_reference', $source->status);
        $this->assertTrue($owner->isOriginalSourceReference($source));
        $this->assertSame('settled', $work->fresh()->status);
        $this->assertDatabaseCount('model_versions', 3);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        Bus::assertNothingDispatched(); Http::assertNothingSent();
    }

    public function test_complete_birth_rejects_unowned_generation_before_any_source_is_created(): void
    {
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'conditional finite source');
        $owner = app(FiniteOriginalComponentPreparationService::class);
        $registered = $owner->register('cold-foreign-birth', $this->design());
        $work = ResearchExperimentWorkItem::findOrFail($registered['work_id']);
        $work->update(['status' => 'leased', 'lease_token' => 'conditional-only', 'fence_version' => 1,
            'lease_expires_at' => now()->addMinute()]);
        $generation = LabGeneration::create(['ai_laboratory_id' => AiLaboratory::sole()->id, 'generation' => 1,
            'trigger_type' => FiniteOriginalComponentPreparationService::WORK_TYPE, 'status' => 'draft', 'population_size' => 3]);
        try {
            $owner->completeBirth($work, $generation, ['spec_hash' => data_get($work->payload, 'spec_hash')]);
            $this->fail('A leased question is not permission to fill an unrelated draft');
        } catch (LogicException $error) {
            $this->assertSame('FINITE_COMPONENT_CANONICAL_BIRTH_PURPOSE_REQUIRED', $error->getMessage());
        }
        foreach (['model_versions', 'lab_agents', 'research_original_model_origins', 'scoped_research_certificates'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Bus::assertNothingDispatched(); Http::assertNothingSent();
    }

    public function test_existing_claim_consumer_birth_and_capture_close_neutrally_once_without_replay(): void
    {
        $this->conditionalReadyOwners();
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'conditional finite source');
        $owner = app(FiniteOriginalComponentPreparationService::class);
        $registered = $owner->register('cold-conditional-execution', $this->design());
        $claimed = app(ResearchExperimentConversionKernelService::class)->claimForOwner(ResearchLoopArbiterService::class, 1);
        $this->assertCount(1, $claimed);
        $work = $claimed[0];
        $result = app(ResearchExperimentWorkConsumerService::class)->execute($work->id, $work->lease_token, $work->fence_version);
        $this->assertSame('completed', $result['status'], json_encode($result));
        $this->assertDatabaseCount('lab_generations', 1);
        $this->assertDatabaseCount('lab_agents', 3);
        $this->assertDatabaseCount('model_versions', 3);
        $this->assertDatabaseCount('research_original_model_origins', 1);
        $this->assertDatabaseCount('agent_learning_causal_experiments', 1);
        $this->assertSame(1, DB::table('research_exposure_capture_records')->where('record_type', 'capture_start')->count());
        $generation = LabGeneration::findOrFail($result['source_generation_id']);
        $this->assertSame('abandoned', $generation->status);
        $this->assertNull($generation->started_at);
        $this->assertNull($generation->data_fingerprint);
        $this->assertFalse(data_get($generation->trigger_context, 'source_only_disposition.scientific_outcome'));
        $this->assertSame(['abandoned'], $generation->agents->pluck('lifecycle_status')->unique()->values()->all());
        $this->assertFalse($generation->laboratory->generations()->whereIn('status', \App\Services\LabPopulationService::ACTIVE_GENERATION_STATUSES)->exists());
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->assertSame(2, ResearchExperimentWorkItem::count());
        $this->assertSame(1, ResearchExperimentWorkItem::where('work_type', \App\Services\DescendantScopedExecutionService::COMPONENT_WORK_TYPE)->count());
        $this->assertSame('settled', $work->fresh()->status);
        $this->assertSame($registered['work_id'], $owner->register('cold-conditional-execution', $this->design())['work_id']);
        $again = app(ResearchExperimentWorkConsumerService::class)->execute($work->id, $work->lease_token, $work->fence_version);
        $this->assertSame('blocked', $again['status']);
        $this->assertDatabaseCount('lab_generations', 1);
        foreach (ModelVersion::all() as $model) $this->assertNotEmpty(data_get($model->metadata, 'execution_contract.parameters'));
        Bus::assertNothingDispatched(); Http::assertNothingSent();
    }

    public function test_commit_pause_during_birth_rolls_back_models_origin_and_certificate(): void
    {
        $this->conditionalReadyOwners();
        $this->mock(AutonomousModeService::class, function ($mock): void {
            $mock->shouldReceive('enabled')->andReturnTrue();
            $mock->shouldReceive('enabledForCommit')->andReturnUsing(fn (): bool => ModelVersion::count() === 0);
        });
        $owner = app(FiniteOriginalComponentPreparationService::class);
        $registered = $owner->register('cold-pause-during-birth', $this->design());
        $work = app(ResearchExperimentConversionKernelService::class)->claimForOwner(ResearchLoopArbiterService::class, 1)[0];
        $result = app(ResearchExperimentWorkConsumerService::class)->execute($work->id, $work->lease_token, $work->fence_version);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('ORIGINAL_COMPONENT_AUTONOMY_COMMIT_DENIED', $result['reason']);
        foreach (['lab_generations', 'lab_agents', 'model_versions', 'research_original_model_origins', 'scoped_research_certificates'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        Bus::assertNothingDispatched(); Http::assertNothingSent();
    }

    public function test_source_only_birth_cannot_enter_generic_folds_or_targeted_full_dispatch_even_after_seal_damage(): void
    {
        $this->conditionalReadyOwners();
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'conditional source purpose fence');
        $owner = app(FiniteOriginalComponentPreparationService::class);
        $registered = $owner->register('cold-source-ordinary-replay-fence', $this->design());
        $work = app(ResearchExperimentConversionKernelService::class)->claimForOwner(ResearchLoopArbiterService::class, 1)[0];
        $built = app(ResearchExperimentWorkConsumerService::class)->execute($work->id, $work->lease_token, $work->fence_version);
        $this->assertSame('completed', $built['status'], json_encode($built));
        $experiment = \App\Models\AgentLearningCausalExperiment::sole();
        $this->assertSame('scoped_source_reference', $experiment->status);
        $evidence = $experiment->evidence;
        $models = ModelVersion::orderBy('id')->get()->map(fn ($model): array => [
            'id' => $model->id, 'parameters' => $model->parameters, 'metadata' => $model->metadata])->all();
        $this->mock(\App\Services\GateContractService::class,
            fn ($mock) => $mock->shouldReceive('health')->twice()->andReturn(['healthy' => true]));
        foreach ([false, true] as $damageOriginSeal) {
            if ($damageOriginSeal) {
                DB::table('research_original_model_origins')->update(['server_seal' => str_repeat('0', 64)]);
            }
            $reason = $damageOriginSeal ? 'FINITE_COMPONENT_SOURCE_REFERENCE_PROOF_INVALID'
                : 'FINITE_COMPONENT_SOURCE_REFERENCE_REPLAY_WITHHELD';
            $fold = app(\App\Services\CausalFoldExecutionService::class)->run((int) $experiment->id, 1);
            $this->assertSame('terminal_without_replay', $fold['status']);
            $this->assertSame($reason, $fold['reason_code']);
            $this->artisan('trading:dispatch-full-validation', ['symbol' => 'XAUUSD', '--timeframe' => 'H1',
                '--causal-experiment-id' => (string) $experiment->id])->expectsOutputToContain($reason)->assertSuccessful();
            $this->assertSame('scoped_source_reference', $experiment->fresh()->status);
            $this->assertSame($evidence, $experiment->fresh()->evidence);
            $this->assertSame($models, ModelVersion::orderBy('id')->get()->map(fn ($model): array => [
                'id' => $model->id, 'parameters' => $model->parameters, 'metadata' => $model->metadata])->all());
            foreach (['causal_fold_receipts', 'lab_evaluation_runs', 'lab_evolution_credit_events'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }
            $this->assertDatabaseCount('model_versions', 3);
            $this->assertDatabaseCount('lab_generations', 1);
        }
        Bus::assertNothingDispatched(); Http::assertNothingSent();
    }

    private function conditionalReadyOwners(): void
    {
        $records = [];
        foreach ($this->design()['validation_windows'] as $index => $period) $records[] = [
            ...$period, 'authorization_id' => 'finite-'.$index, 'research_epoch_id' => 'conditional-'.$index,
            'dataset_sha256' => hash('sha256', 'conditional-source-'.$index), 'purpose' => 'instrument_independent_validation',
            'mtf_bundle_manifest' => ['conditional_fixture' => true]];
        config(['services.instrument_policy.authorized_research_windows' => $records]);
        $this->partialMock(InstrumentResearchWindowService::class, function ($mock): void {
            $mock->shouldReceive('verifySealedReplayWindow')->andReturn(['files' => []]);
        });
        // The genuinely unexposed authorized input inventory is an external seam
        // in this software test, not an assertion that such market data exists.
        $certificates = \Mockery::mock(ScopedResearchCertificateService::class,
            [app(\App\Services\ResearchPaperEpochContractService::class)])->makePartial();
        $certificates->shouldReceive('independentWindowReadiness')
            ->andReturn(['ready' => true, 'windows' => [], 'reason_codes' => []]);
        $this->app->instance(ScopedResearchCertificateService::class, $certificates);
        $this->mock(GenerationAdmissionDecisionService::class, fn ($mock) => $mock->shouldReceive('decide')->once()
            ->withArgs(fn ($lab, $latest, $input): bool => $lab->symbol === 'XAUUSD' && $input['force'] === false
                && $input['trigger'] === GenerationAdmissionDecisionService::HISTORICAL_TRIGGER)
            ->andReturn(['allowed' => true, 'conditional_software_admission' => true]));
    }

    private function design(): array
    {
        $periods = [];
        foreach (range(1, 6) as $month) {
            $start = CarbonImmutable::create(2012, $month, 1, 0, 0, 0, 'UTC');
            $periods[] = ['start_inclusive' => $start->toIso8601String(), 'end_exclusive' => $start->addMonth()->toIso8601String()];
        }
        return ['validation_start' => $periods[0]['start_inclusive'], 'validation_end' => $periods[5]['end_exclusive'],
            'validation_windows' => $periods, 'metric' => 'drawdown', 'data_manifest_hash' => null,
            'stopping_rule' => ['minimum_trades_per_arm' => 8, 'minimum_effect' => .05, 'minimum_positive_windows' => 4],
            'subject' => ['context' => ['regime' => 'trend_up', 'volatility' => 'normal_volatility',
                'session' => 'london', 'venue_phase' => 'london_interfix', 'direction' => 'BUY']],
            'exposure_policy' => ['protocol' => 'prospective_scoped_exposure_policy_v1', 'holding_fence_seconds' => 172800,
                'execution_timeframe' => 'M5', 'context_timeframes' => ['H4', 'H1', 'M15'],
                'warmup_policy' => 'all_original_closed_source_rows_inside_registered_window', 'selection_policy' => 'frozen_before_first_exposure'],
            'native_execution' => ['authorization_ids' => array_map(fn ($i): string => 'finite-'.$i, range(0, 5)),
                'execution_timeframe' => 'M5', 'initial_capital' => 10000, 'risk_policy' => ['risk_per_trade_percent' => .5],
                'full_replay_runtime_policy' => ['protocol' => 'scoped_original_full_source_v1', 'evaluation_mode' => 'full',
                    'selection' => 'entire_authorized_source', 'maximum_source_rows' => 4096, 'maximum_runtime_seconds' => 300,
                    'warmup_rows' => 0, 'no_walk_forward_selection' => true, 'promotion_evidence' => false]]];
    }
}
