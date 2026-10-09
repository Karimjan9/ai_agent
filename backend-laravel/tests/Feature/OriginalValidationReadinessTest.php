<?php

namespace Tests\Feature;

use App\Services\InstrumentResearchWindowService;
use App\Services\ResearchPaperEpochContractService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Actual local input bytes only; these fixtures never assert an untouched market window. */
class OriginalValidationReadinessTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureRoot;
    private string $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2028-01-01T00:00:00Z'));
        config()->set('services.instrument_policy.authorized_research_windows', []);
        config()->set('services.research_paper_epochs.authorized_paper_epochs', []);
        $this->fixtureRoot = sys_get_temp_dir().'/original-validation-input-'.bin2hex(random_bytes(8));
        $this->originalStorage = storage_path();
        app()->useStoragePath($this->fixtureRoot.'/storage');
    }

    protected function tearDown(): void
    {
        app()->useStoragePath($this->originalStorage);
        $resolved = realpath($this->fixtureRoot);
        $prefix = str_replace('\\', '/', realpath(sys_get_temp_dir())).'/original-validation-input-';
        if ($resolved && str_starts_with(str_replace('\\', '/', $resolved), $prefix)) File::deleteDirectory($resolved);
        parent::tearDown();
    }

    public function test_actual_four_stream_files_do_not_turn_an_absent_exposure_ledger_into_unused_authority(): void
    {
        [$window, $manifest] = $this->inputs();
        $before = \Illuminate\Support\Facades\DB::table('lab_evaluation_runs')->count();
        $readiness = app(InstrumentResearchWindowService::class)->originalValidationReadiness($window, $manifest, []);

        $this->assertTrue($readiness['complete_input_proof']);
        $this->assertCount(4, $readiness['actual_input_proof']['files']);
        $this->assertSame('ORIGINAL_TRAINING_AND_SELECTION_EXPOSURE_INVENTORY_NOT_ATTESTED', $readiness['reason_code']);
        $this->assertSame('BLOCKED_DEPENDENCY', $readiness['status']);
        foreach (['ready', 'candidate_unused_demonstrated', 'absence_of_recorded_use_proves_unused',
            'original_training_selection_inventory_attested', 'independent_evidence', 'promotion_evidence',
            'server_authorization_created', 'data_writes'] as $key) $this->assertFalse($readiness[$key]);
        $this->assertSame($before, \Illuminate\Support\Facades\DB::table('lab_evaluation_runs')->count());
    }

    public function test_caller_inventory_flags_and_hashes_never_replace_a_canonical_original_owner(): void
    {
        [$window, $manifest] = $this->inputs();
        $readiness = app(InstrumentResearchWindowService::class)->originalValidationReadiness($window, $manifest,
            ['complete' => true, 'unused' => true, 'inventory_hash' => hash('sha256', 'unrecorded-caller-claim')]);
        $this->assertContains('ORIGINAL_EXPOSURE_CANONICAL_OWNER_REFERENCES_REQUIRED', $readiness['unresolved_provenance']);
        $this->assertTrue($readiness['complete_input_proof']);
        $this->assertFalse($readiness['ready']);
        $this->assertSame([], $readiness['original_native_exposure_references']);
    }

    public function test_changed_context_bytes_and_paper_warmup_are_refused_before_any_unused_claim(): void
    {
        [$window, $manifest] = $this->inputs();
        File::append($manifest['streams']['H4']['path'], 'changed original context bytes');
        $readiness = app(InstrumentResearchWindowService::class)->originalValidationReadiness($window, $manifest, []);
        $this->assertContains('RESEARCH_TRANSPORT_SOURCE_HASH_MISMATCH:H4', $readiness['unresolved_provenance']);
        $this->assertFalse($readiness['complete_input_proof']);
        [$window, $manifest] = $this->inputs(['H4' => ['2026-12-31T20:00:00Z', '2027-01-01T00:00:00Z']]);
        $readiness = app(InstrumentResearchWindowService::class)->originalValidationReadiness($window, $manifest, []);
        $this->assertContains('RESEARCH_TRANSPORT_TIME_SCOPE_INVALID:H4', $readiness['unresolved_provenance']);
        $this->assertFalse($readiness['ready']);
    }

    public function test_unclosed_context_at_the_end_and_unverified_auxiliary_streams_stay_blocked(): void
    {
        [$window, $manifest] = $this->inputs(['H4' => ['2027-01-31T22:00:00Z', '2027-01-31T23:00:00Z']]);
        $readiness = app(InstrumentResearchWindowService::class)->originalValidationReadiness($window, $manifest, []);
        $this->assertContains('ORIGINAL_VALIDATION_CLOSED_CONTEXT_OR_WARMUP_OUTSIDE_WINDOW', $readiness['unresolved_provenance']);
        $this->assertFalse($readiness['complete_input_proof']);
        [$window, $manifest] = $this->inputs([], true);
        $readiness = app(InstrumentResearchWindowService::class)->originalValidationReadiness($window, $manifest, []);
        $this->assertContains('ORIGINAL_VALIDATION_AUXILIARY_EXPOSURE_UNVERIFIED', $readiness['unresolved_provenance']);
        $this->assertFalse($readiness['complete_input_proof']);
        $this->assertFalse($readiness['candidate_unused_demonstrated']);
    }

    public function test_future_drafts_and_configured_hashes_without_original_bytes_are_not_data_readiness(): void
    {
        [$window, $manifest] = $this->inputs();
        File::delete($manifest['streams']['M15']['path']);
        $readiness = app(InstrumentResearchWindowService::class)->originalValidationReadiness($window, $manifest, []);
        $this->assertContains('RESEARCH_TRANSPORT_SOURCE_PATH_INVALID', $readiness['unresolved_provenance']);
        $this->assertFalse($readiness['complete_input_proof']);
        $this->travelTo(CarbonImmutable::parse('2026-10-09T00:00:00Z'));
        $readiness = app(InstrumentResearchWindowService::class)->originalValidationReadiness($window, $manifest, []);
        $this->assertContains('NO_COMPLETED_AUTHORIZED_INDEPENDENT_WINDOW', $readiness['unresolved_provenance']);
        $this->assertFalse($readiness['ready']);
    }

    private function inputs(array $dates = [], bool $auxiliary = false): array
    {
        $records = [];
        foreach (['M5' => 300, 'H4' => 14400, 'H1' => 3600, 'M15' => 900] as $stream => $seconds) {
            $times = $dates[$stream] ?? ['2027-01-01T00:00:00Z', CarbonImmutable::parse('2027-01-01T00:00:00Z')
                ->addSeconds($seconds)->format('Y-m-d\TH:i:s\Z')];
            $path = storage_path('app/lab-datasets/'.$stream.'.csv'); File::ensureDirectoryExists(dirname($path));
            File::put($path, "time,open,high,low,close,volume\n{$times[0]},100,101,99,100,10\n{$times[1]},100,102,99,101,11\n");
            $records[$stream] = ['path' => $path, 'sha256' => hash_file('sha256', $path),
                'first_candle_at' => $times[0], 'last_candle_at' => $times[1], 'rows' => 2];
        }
        if ($auxiliary) $records['RELATED_M15'] = [...$records['M15'], 'symbol' => 'EURUSD'];
        $manifest = ['streams' => $records, 'bundle_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($records)];
        config()->set('services.instrument_policy.authorized_research_windows', [[
            'authorization_id' => 'actual-input-fixture', 'research_epoch_id' => 'input-only-fixture',
            'purpose' => 'instrument_independent_validation', 'dataset_sha256' => $manifest['bundle_hash'],
            'start_inclusive' => '2027-01-01T00:00:00Z', 'end_exclusive' => '2027-02-01T00:00:00Z',
            'mtf_bundle_manifest' => $manifest,
        ]]);
        return [app(InstrumentResearchWindowService::class)->seal('actual-input-fixture', $manifest['bundle_hash']), $manifest];
    }
}
