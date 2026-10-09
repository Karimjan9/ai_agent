<?php

namespace Tests\Feature;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\ScopedResearchCertificate;
use App\Models\SpecialistCouncilVersion;
use App\Services\ResearchPaperEpochContractService;
use App\Services\InstrumentResearchWindowService;
use App\Services\ScopedResearchCertificateService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use LogicException;
use Tests\TestCase;

class ScopedResearchCertificateTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureRoot;
    private string $previousStorage;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        config()->set('services.instrument_policy.authorized_research_windows', []);
        config()->set('services.research_paper_epochs.authorized_paper_epochs', []);
        $this->travelTo(CarbonImmutable::parse('2026-10-09T00:00:00Z'));
        $this->fixtureRoot = sys_get_temp_dir().'/scoped-certificate-input-'.bin2hex(random_bytes(8));
        $this->previousStorage = storage_path();
        app()->useStoragePath($this->fixtureRoot.'/storage');
    }

    protected function tearDown(): void
    {
        app()->useStoragePath($this->previousStorage);
        $resolved = realpath($this->fixtureRoot);
        $prefix = str_replace('\\', '/', realpath(sys_get_temp_dir())).'/scoped-certificate-input-';
        if ($resolved && str_starts_with(str_replace('\\', '/', $resolved), $prefix)) File::deleteDirectory($resolved);
        parent::tearDown();
    }

    public function test_future_draft_is_sealed_but_cannot_grant_any_authority(): void
    {
        [$source, $design] = $this->question();
        $result = app(ScopedResearchCertificateService::class)->register('component', $source, $design);

        $this->assertTrue($result['valid']);
        $this->assertSame('preregistered_diagnostic', $result['status']);
        $this->assertNull($result['design']['data_manifest_hash']);
        $this->assertSame(app(ResearchPaperEpochContractService::class)->parameterHash($design), $result['design_hash']);
        foreach (['authority', 'executable', 'scope_authority_confirmed', 'component_credit',
            'selector_credit', 'inheritance_credit', 'parent_eligible', 'paper_authority',
            'economic_authority', 'independent_market_evidence', 'promotion_evidence'] as $flag) {
            $this->assertFalse($result[$flag], $flag);
        }
    }

    public function test_exact_registration_is_idempotent_after_validation_starts(): void
    {
        [$source, $design] = $this->question();
        $owner = app(ScopedResearchCertificateService::class);
        $first = $owner->register('component', $source, $design);
        $this->travelTo(CarbonImmutable::parse('2027-02-01T00:00:00Z'));
        $repeat = $owner->register('component', $source, array_reverse($design, true));

        $this->assertTrue($repeat['valid']);
        $this->assertSame($first['certificate_id'], $repeat['certificate_id']);
        $this->assertDatabaseCount('scoped_research_certificates', 1);
    }

    public function test_component_and_selector_are_distinct_sealed_questions(): void
    {
        [$source, $design] = $this->question();
        $owner = app(ScopedResearchCertificateService::class);
        $component = $owner->register('component', $source, $design);
        $selector = $owner->register('selector', $source, $design);

        $this->assertNotSame($component['certificate_id'], $selector['certificate_id']);
        $this->assertSame(['component', 'selector'], array_column($owner->sourceRegistrations($source), 'scope'));
        $this->assertTrue($owner->hasProspectiveScope($source));
        $this->assertFalse($selector['confirmed_component']);
        $this->assertFalse($component['confirmed_selector']);
    }

    public function test_design_cannot_be_replaced_after_preregistration(): void
    {
        [$source, $design] = $this->question();
        $owner = app(ScopedResearchCertificateService::class);
        $owner->register('component', $source, $design);
        $design['metric'] = 'net_profit';

        $this->expectExceptionMessage('SCOPED_CERTIFICATE_IMMUTABLE_DESIGN_OR_SOURCE_CHANGED');
        $owner->register('component', $source, $design);
    }

    public function test_source_outcomes_and_status_do_not_change_immutable_question_identity(): void
    {
        [$source, $design] = $this->question();
        $owner = app(ScopedResearchCertificateService::class);
        $receipt = $owner->register('component', $source, $design);
        $source->update(['status' => 'outcomes_pending', 'independent_window_count' => 4,
            'evidence' => [...$source->evidence, 'outcomes' => ['guided' => ['score' => 2]]]]);

        $this->assertTrue($owner->inspect($receipt['certificate_id'])['valid']);
        $this->assertSame($receipt['certificate_id'], $owner->register('component', $source->fresh(), $design)['certificate_id']);
    }

    public function test_changed_original_model_parameters_invalidate_inspection_and_assessment(): void
    {
        [$source, $design, $model] = $this->question();
        $owner = app(ScopedResearchCertificateService::class);
        $receipt = $owner->register('component', $source, $design);
        $model->update(['parameters' => ['threshold' => 9]]);

        $this->assertFalse($owner->inspect($receipt['certificate_id'])['valid']);
        $this->expectExceptionMessage('SCOPED_CERTIFICATE_ORIGINAL_MODEL_PARAMETER_DRIFT');
        $owner->recordAssessment($receipt['certificate_id'], ['score' => 100, 'authority' => true]);
    }

    public function test_public_assessment_is_append_only_diagnostic_even_with_caller_success_flags(): void
    {
        [$source, $design] = $this->question();
        $owner = app(ScopedResearchCertificateService::class);
        $receipt = $owner->register('component', $source, $design);
        $input = ['effect' => 2, 'interaction' => 0.0, 'authority' => true, 'confirmed_component' => true,
            'selector_credit' => true, 'inheritance_credit' => true, 'parent_eligible' => true,
            'nested' => ['confirmed_selector' => true, 'authority' => 'confirmed']];
        $assessed = $owner->recordAssessment($receipt['certificate_id'], $input);

        $this->assertSame('assessed_diagnostic', $assessed['status']);
        $this->assertSame(2, $assessed['diagnostic_assessment']['effect']);
        $this->assertSame(0.0, $assessed['diagnostic_assessment']['interaction']);
        $this->assertFalse($assessed['diagnostic_assessment']['authority']);
        $this->assertFalse($assessed['diagnostic_assessment']['confirmed_component']);
        $this->assertFalse($assessed['diagnostic_assessment']['parent_eligible']);
        $this->assertFalse($assessed['diagnostic_assessment']['nested']['confirmed_selector']);
        $this->assertFalse($assessed['diagnostic_assessment']['nested']['authority']);
        $this->assertSame($assessed['assessment_record_id'],
            $owner->recordAssessment($receipt['certificate_id'], array_reverse($input, true))['assessment_record_id']);
        $this->assertDatabaseCount('scoped_research_certificates', 2);
        $this->expectExceptionMessage('SCOPED_CERTIFICATE_COMPLETED_ASSESSMENT_IMMUTABLE');
        $owner->recordAssessment($receipt['certificate_id'], [...$input, 'effect' => 3]);
    }

    public function test_model_update_and_direct_database_relabel_cannot_rewrite_a_certificate(): void
    {
        [$source, $design] = $this->question();
        $owner = app(ScopedResearchCertificateService::class);
        $receipt = $owner->register('component', $source, $design);
        $row = ScopedResearchCertificate::findOrFail($receipt['certificate_id']);
        try {
            $row->update(['scope' => 'selector']);
            $this->fail('Certificate model accepted an update.');
        } catch (LogicException $error) {
            $this->assertSame('SCOPED_CERTIFICATE_APPEND_ONLY', $error->getMessage());
        }
        DB::table('scoped_research_certificates')->where('id', $row->id)->update(['scope' => 'selector']);
        $this->assertSame('SCOPED_CERTIFICATE_SEAL_OR_PAYLOAD_DRIFT', $owner->inspect((int) $row->id)['reason_code']);
    }

    public function test_retrospective_and_paper_year_designs_are_refused(): void
    {
        [$source, $design] = $this->question();
        $owner = app(ScopedResearchCertificateService::class);
        $this->travelTo(CarbonImmutable::parse('2027-02-01T00:00:00Z'));
        try {
            $owner->register('component', $source, $design);
            $this->fail('Past validation received a new preregistration.');
        } catch (LogicException $error) {
            $this->assertSame('SCOPED_CERTIFICATE_RETROSPECTIVE_REGISTRATION_FORBIDDEN', $error->getMessage());
        }
        $design['validation_start'] = '2026-11-01T00:00:00+00:00';
        $design['validation_end'] = '2026-12-01T00:00:00+00:00';
        $this->expectExceptionMessage('SCOPED_CERTIFICATE_POST_PAPER_FUTURE_INTERVAL_REQUIRED');
        $owner->register('component', $source, $design);
    }

    public function test_observed_source_needs_explicit_hypothesis_role_and_never_prospective_scope(): void
    {
        [$source, $design] = $this->question();
        $owner = app(ScopedResearchCertificateService::class);
        $source->update(['evidence' => [...$source->evidence, 'outcomes' => ['guided' => ['score' => 2]]]]);
        try {
            $owner->register('component', $source, $design);
            $this->fail('Observed owner was prospectively registered.');
        } catch (LogicException $error) {
            $this->assertSame('SCOPED_CERTIFICATE_VALIDATION_OUTCOMES_ALREADY_OBSERVED', $error->getMessage());
        }
        $hypothesis = $owner->register('component', $source, [...$design, 'source_hypothesis_only' => true]);
        $this->assertTrue($hypothesis['valid']);
        $this->assertFalse($owner->hasProspectiveScope($source));
        $this->assertFalse($hypothesis['authority']);
    }

    public function test_arbitrary_model_cannot_become_an_original_scope_owner(): void
    {
        [, $design, $model] = $this->question();
        $this->expectExceptionMessage('SCOPED_CERTIFICATE_NAMED_SCOPE_OWNER_REQUIRED');
        app(ScopedResearchCertificateService::class)->register('component', $model, $design);
    }

    public function test_caller_design_cannot_preregister_an_asserted_confirmed_scope(): void
    {
        [$source, $design] = $this->question();
        $this->expectExceptionMessage('SCOPED_CERTIFICATE_CALLER_AUTHORITY_FORBIDDEN');
        app(ScopedResearchCertificateService::class)->register('component', $source,
            [...$design, 'confirmed_component' => true]);
    }

    public function test_council_scope_seals_its_actual_manifest_programs_and_original_plan(): void
    {
        [, $design, $model] = $this->question();
        $epochs = app(ResearchPaperEpochContractService::class);
        $manifest = [
            'council_id' => 'scoped-council-fixture', 'version' => 'one',
            'members' => [['model_version_id' => (int) $model->id, 'program' => 'synthetic_guard_only']],
            'account' => ['capital' => 1000, 'risk_percent' => 1],
            'clock' => ['decision_seconds' => 300],
        ];
        $manifest['manifest_hash'] = $epochs->parameterHash($manifest);
        $source = SpecialistCouncilVersion::create([
            'council_id' => $manifest['council_id'], 'version' => 'one',
            'creator_id' => 'constructor-owner', 'manifest' => $manifest,
            'manifest_hash' => $manifest['manifest_hash'], 'sealed_at' => now(), 'state' => 'draft',
        ]);
        $plan = ['manifest_hash' => $source->manifest_hash, 'purpose' => 'independent',
            'arms' => ['candidate' => ['model_version_id' => (int) $model->id]],
            'windows' => [['start_inclusive' => $design['validation_start'], 'end_exclusive' => $design['validation_end']]],
            'account' => $manifest['account'], 'clock' => $manifest['clock']];
        $planHash = $epochs->parameterHash($plan);
        DB::table('specialist_council_evaluation_plans')->insert([
            'specialist_council_version_id' => $source->id, 'evaluator_id' => 'separate-evaluator',
            'plan' => json_encode($plan), 'plan_hash' => $planHash, 'sealed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $design['subject']['manifest_hash'] = $source->manifest_hash;
        $design['subject']['plan_hash'] = $planHash;
        $owner = app(ScopedResearchCertificateService::class);
        $receipt = $owner->register('council', $source, $design);

        $this->assertTrue($receipt['valid']);
        $this->assertSame('council', $receipt['scope']);
        $this->assertFalse($receipt['confirmed_council']);
        $plan['clock']['decision_seconds'] = 600;
        DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $source->id)
            ->update(['plan' => json_encode($plan), 'plan_hash' => $epochs->parameterHash($plan)]);
        $this->assertFalse($owner->inspect($receipt['certificate_id'])['valid']);
    }

    public function test_original_four_stream_binding_is_immutable_and_remains_inventory_blocked(): void
    {
        [$source, $design] = $this->question();
        $owner = app(ScopedResearchCertificateService::class);
        $question = $owner->register('component', $source, $design);
        [$window, $manifest] = $this->syntheticInputs();
        $bound = $owner->bindOriginalData($question['certificate_id'], $window, $manifest);

        $this->assertSame('data_bound_diagnostic', $bound['status']);
        $this->assertTrue($bound['data_binding']['valid']);
        $this->assertTrue($bound['original_readiness']['complete_input_proof']);
        $this->assertFalse($bound['original_readiness']['candidate_unused_demonstrated']);
        $this->assertSame('ORIGINAL_TRAINING_AND_SELECTION_EXPOSURE_INVENTORY_NOT_ATTESTED',
            $bound['original_readiness']['reason_code']);
        $this->assertFalse($bound['executable']);
        $this->assertFalse($bound['authority']);
        $this->assertSame($bound['data_binding']['record_id'],
            $owner->bindOriginalData($question['certificate_id'], $window, array_reverse($manifest, true))['data_binding']['record_id']);
        $this->assertDatabaseCount('scoped_research_certificates', 2);
        File::append($manifest['streams']['M15']['path'], 'changed bytes');
        $drifted = $owner->inspect($question['certificate_id']);
        $this->assertTrue($drifted['valid']); // Question identity and physical data readiness are separate.
        $this->assertFalse($drifted['data_binding']['valid']);
        $this->assertFalse($drifted['scope_authority_confirmed']);
    }

    public function test_first_actual_binding_cannot_follow_validation_outcomes(): void
    {
        [$source, $design] = $this->question();
        $owner = app(ScopedResearchCertificateService::class);
        $question = $owner->register('component', $source, $design);
        [$window, $manifest] = $this->syntheticInputs();
        $source->update(['evidence' => [...$source->evidence, 'outcomes' => ['guided' => ['value' => 3]]]]);

        $this->expectExceptionMessage('SCOPED_CERTIFICATE_DATA_BINDING_MUST_PRECEDE_OUTCOMES');
        $owner->bindOriginalData($question['certificate_id'], $window, $manifest);
    }

    public function test_hypothesis_certificate_cannot_bind_new_validation_data(): void
    {
        [$source, $design] = $this->question();
        $owner = app(ScopedResearchCertificateService::class);
        $question = $owner->register('component', $source, [...$design, 'source_hypothesis_only' => true]);

        $this->expectExceptionMessage('SCOPED_CERTIFICATE_HYPOTHESIS_CANNOT_BIND_VALIDATION');
        $owner->bindOriginalData($question['certificate_id'], [], []);
    }

    public function test_future_hash_and_caller_complete_flags_cannot_bind_unavailable_bytes(): void
    {
        [$source, $design] = $this->question();
        $owner = app(ScopedResearchCertificateService::class);
        $question = $owner->register('component', $source, $design);
        $window = ['start_inclusive' => $design['validation_start'], 'end_exclusive' => $design['validation_end'],
            'dataset_sha256' => hash('sha256', 'future-unavailable'), 'authorization_id' => 'not-authorized'];

        $this->expectExceptionMessage('SCOPED_CERTIFICATE_ORIGINAL_FOUR_STREAM_PROOF_REQUIRED');
        $owner->bindOriginalData($question['certificate_id'], $window,
            ['bundle_hash' => $window['dataset_sha256'], 'complete_input_proof' => true, 'unused' => true]);
    }

    /** Synthetic byte/clock fixtures test guards only; no real future market data exists here. */
    private function syntheticInputs(): array
    {
        $this->travelTo(CarbonImmutable::parse('2028-01-01T00:00:00Z'));
        $streams = [];
        foreach (['M5' => 300, 'H4' => 14400, 'H1' => 3600, 'M15' => 900] as $stream => $seconds) {
            $from = '2027-01-01T00:00:00Z';
            $until = CarbonImmutable::parse($from)->addSeconds($seconds)->format('Y-m-d\TH:i:s\Z');
            $path = storage_path('app/lab-datasets/'.$stream.'.csv');
            File::ensureDirectoryExists(dirname($path));
            File::put($path, "time,open,high,low,close,volume\n{$from},100,101,99,100,10\n{$until},100,102,99,101,11\n");
            $streams[$stream] = ['path' => $path, 'sha256' => hash_file('sha256', $path),
                'first_candle_at' => $from, 'last_candle_at' => $until, 'rows' => 2];
        }
        $manifest = ['streams' => $streams,
            'bundle_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($streams)];
        config()->set('services.instrument_policy.authorized_research_windows', [[
            'authorization_id' => 'scoped-synthetic-input', 'research_epoch_id' => 'synthetic-input-only',
            'purpose' => 'instrument_independent_validation', 'dataset_sha256' => $manifest['bundle_hash'],
            'start_inclusive' => '2027-01-01T00:00:00Z', 'end_exclusive' => '2027-02-01T00:00:00Z',
            'mtf_bundle_manifest' => $manifest,
        ]]);

        return [app(InstrumentResearchWindowService::class)->seal('scoped-synthetic-input', $manifest['bundle_hash']), $manifest];
    }

    private function question(): array
    {
        $lab = AiLaboratory::create(['name' => 'Certificate fixture', 'symbol' => 'XAUUSD',
            'timeframe' => 'M15', 'strategy_families' => ['hybrid'], 'is_active' => true]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'test', 'population_size' => 3, 'status' => 'queued']);
        $agents = [];
        foreach (['guided', 'blinded', 'control'] as $index => $role) {
            $model = ModelVersion::create(['name' => $role, 'strategy' => 'hybrid', 'version' => 'certificate-'.$role,
                'status' => 'testing', 'parameters' => ['threshold' => $index + 1]]);
            $agents[$role] = LabAgent::withoutEvents(fn () => LabAgent::create([
                'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'M15', 'strategy_family' => 'hybrid',
                'origin' => 'test', 'lifecycle_status' => 'screening_queued', 'parameter_diff' => [],
            ]));
        }
        $source = AgentLearningCausalExperiment::create([
            'experiment_key' => 'certificate-source-'.$generation->id, 'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'M15', 'strategy_family' => 'hybrid',
            'target' => 'profit_factor', 'gene_key' => 'threshold', 'status' => 'ready_for_replay',
            'guided_agent_id' => $agents['guided']->id, 'blinded_agent_id' => $agents['blinded']->id,
            'control_agent_id' => $agents['control']->id, 'evidence' => ['experiment_kind' => 'memory_confirmation'],
        ]);
        $model = ModelVersion::findOrFail($agents['guided']->model_version_id);
        $design = [
            'validation_start' => '2027-01-01T00:00:00+00:00', 'validation_end' => '2027-02-01T00:00:00+00:00',
            'evaluator_hash' => hash('sha256', 'evaluator'), 'context_hash' => hash('sha256', 'context'),
            'execution_hash' => hash('sha256', 'execution'), 'owner_source_hash' => hash('sha256', 'owner'),
            'data_manifest_hash' => null, 'metric' => 'profit_factor', 'stopping_rule' => 'complete_sealed_window',
            'subject' => ['arm_models' => ['guided' => ['model_version_id' => (int) $model->id,
                'parameter_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($model->parameters)]]],
        ];

        return [$source, $design, $model];
    }
}
