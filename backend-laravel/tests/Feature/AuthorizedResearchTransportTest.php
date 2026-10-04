<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Services\ExecutionContractService;
use App\Services\InstrumentResearchWindowService;
use App\Services\LabImmutableEvidenceService;
use App\Services\ResearchReleaseSealService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AuthorizedResearchTransportTest extends TestCase
{
    use RefreshDatabase;
    private string $root;
    private string $originalStorage;
    private const KEY = 'fixture-only-internal-auth-key-32-characters';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2028-01-01T00:00:00Z'));
        $this->root = sys_get_temp_dir().'/authorized-research-transport-test-'.bin2hex(random_bytes(8));
        $this->originalStorage = storage_path();
        File::ensureDirectoryExists($this->root.'/app/lab-datasets');
        $this->app->useStoragePath($this->root);
        config(['services.internal_api.token' => self::KEY,
            'services.instrument_policy.authorized_research_windows' => [],
            'services.research_paper_epochs.authorized_paper_epochs' => []]);
    }

    protected function tearDown(): void
    {
        if (isset($this->originalStorage)) $this->app->useStoragePath($this->originalStorage);
        if (isset($this->root)) {
            $resolved = realpath($this->root);
            $prefix = str_replace('\\', '/', (string) realpath(sys_get_temp_dir())).'/authorized-research-transport-test-';
            if ($resolved && str_starts_with(str_replace('\\', '/', $resolved), $prefix)) File::deleteDirectory($resolved);
        }
        parent::tearDown();
    }

    public function test_real_server_issuer_sealed_release_and_actual_csv_pass_the_python_boundary_with_retry_stable_identity(): void
    {
        [$generation, $request] = $this->fixture();
        $owner = app(InstrumentResearchWindowService::class);
        $first = app(ResearchReleaseSealService::class)->bindGenerationRequest($generation, $request, []);
        $second = $owner->bindReplayRequest($generation->fresh(), $request);
        $signed = $first['policy_context']['authorized_research_transport'];
        $this->assertSame($signed, $second['policy_context']['authorized_research_transport']);
        $this->assertFalse($signed['independent_evidence']);
        $this->assertFalse($signed['promotion_evidence']);
        $this->assertCount(7, $signed['window']);
        $this->assertArrayNotHasKey('expires_at', $signed);
        $fixture = base_path('../ai-service-python/tests/support/authorized_research_transport_fixture.py');
        if (getenv('AUTHORIZED_RESEARCH_TRANSPORT_STAGING') === '1') $fixture = base_path('../.runtime/authorized-research-transport-staging-2026-10-03/authorized_research_transport_fixture.py');
        $process = new Process(['python', $fixture, '--test-data-root', $this->root, '--fixture-clock', '2028-01-01T00:00:00Z'],
            base_path('../ai-service-python'), ['INTERNAL_API_TOKEN' => self::KEY, 'INTERNAL_API_TOKEN_FILE' => ''],
            json_encode($first, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), timeout: 30);
        $process->mustRun();
        $actual = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($actual['admission_verified']);
        $this->assertSame(2, $actual['source_rows']);
        $this->assertSame($signed['contract_hash'], $actual['contract_hash']);
        $this->assertSame($signed['window']['window_key'], $actual['window_key']);
        $this->assertFalse($actual['independent_evidence']);
    }

    public function test_default_registry_removes_caller_token_without_creating_an_authorization(): void
    {
        [$generation, $request] = $this->fixture();
        config(['services.instrument_policy.authorized_research_windows' => []]);
        $request['policy_context']['authorized_research_transport'] = ['approved' => true, 'hmac_sha256' => 'fake'];
        $actual = app(InstrumentResearchWindowService::class)->bindReplayRequest($generation, $request);
        $this->assertArrayNotHasKey('authorized_research_transport', $actual['policy_context']);
    }

    public function test_forged_generation_object_cannot_borrow_an_unpersisted_release(): void
    {
        [$generation, $request] = $this->fixture();
        $generation->update(['trigger_context' => ['canonical_dataset_snapshots' => ['price' => ['sha256' => $request['replay_dataset_hash']]]]]);
        $this->expectExceptionMessage('RESEARCH_TRANSPORT_PERSISTED_RELEASE_REQUIRED');
        app(InstrumentResearchWindowService::class)->bindReplayRequest($generation, $request);
    }

    public function test_signed_source_requires_original_bytes_not_a_copied_dataset_label(): void
    {
        [$generation, $request] = $this->fixture();
        File::put($request['dataset_path'], "time,open\n2027-02-01T00:00:00Z,1\n2027-02-02T00:00:00Z,1\n");
        $this->expectExceptionMessage('RESEARCH_TRANSPORT_SOURCE_HASH_MISMATCH');
        app(InstrumentResearchWindowService::class)->bindReplayRequest($generation, $request);
    }

    public function test_full_file_warmup_may_not_cross_an_authorized_window_boundary(): void
    {
        [$generation, $request] = $this->fixture('2027-01-31T23:55:00Z');
        $this->expectExceptionMessage('RESEARCH_TRANSPORT_TIME_SCOPE_INVALID');
        app(InstrumentResearchWindowService::class)->bindReplayRequest($generation, $request);
    }

    public function test_paper_2026_and_explicit_future_paper_overlap_never_obtain_a_transport_signature(): void
    {
        [$generation, $request] = $this->fixture();
        $config = config('services.instrument_policy.authorized_research_windows');
        $config[0]['start_inclusive'] = '2026-02-01T00:00:00Z';
        config(['services.instrument_policy.authorized_research_windows' => $config]);
        $this->assertArrayNotHasKey('authorized_research_transport', app(InstrumentResearchWindowService::class)->bindReplayRequest($generation, $request)['policy_context'] ?? []);
        $config[0]['start_inclusive'] = '2027-02-01T00:00:00Z';
        config(['services.instrument_policy.authorized_research_windows' => $config,
            'services.research_paper_epochs.authorized_paper_epochs' => [[
                'protocol' => 'authorized_prospective_paper_epoch_v1', 'purpose' => 'prospective_paper_forward',
                'approved' => true, 'candidate_must_be_frozen_before_observation' => true, 'research_uses_forbidden' => true,
                'authorization_id' => 'paper-approved', 'window_key' => 'paper-2027', 'authorized_at' => '2026-10-01T00:00:00Z',
                'start_inclusive' => '2027-02-15T00:00:00Z', 'end_exclusive' => '2027-04-01T00:00:00Z',
            ]]]);
        $this->assertArrayNotHasKey('authorized_research_transport', app(InstrumentResearchWindowService::class)->bindReplayRequest($generation, $request)['policy_context'] ?? []);
    }

    public function test_copied_bundle_label_cannot_authorize_changed_frozen_stream_records(): void
    {
        [$generation, $request] = $this->fixture();
        $manifest = ['bundle_hash' => $request['replay_dataset_hash'], 'streams' => [
            'M5' => ['path' => $request['dataset_path'], 'sha256' => $request['replay_dataset_hash']],
        ]];
        $context = $generation->trigger_context; $context['mtf_bundle_manifest'] = $manifest;
        $generation->update(['trigger_context' => $context]);
        $request['mtf_snapshot_manifest'] = $manifest;
        $request['mtf_snapshot_manifest']['streams']['H4'] = $manifest['streams']['M5'];
        $this->expectExceptionMessage('RESEARCH_TRANSPORT_PERSISTED_STREAM_MANIFEST_MISMATCH');
        app(InstrumentResearchWindowService::class)->bindReplayRequest($generation, $request);
    }

    public function test_primary_source_cannot_be_hidden_by_duplicate_mtf_key(): void
    {
        [$generation, $request] = $this->fixture();
        $other = $this->root.'/app/lab-datasets/other.csv';
        File::copy($request['dataset_path'], $other);
        $request['mtf_dataset_paths']['M5'] = $other;
        $this->expectExceptionMessage('RESEARCH_TRANSPORT_DUPLICATE_STREAM_PATH_MISMATCH');
        app(InstrumentResearchWindowService::class)->bindReplayRequest($generation, $request);
    }

    public function test_path_escape_cannot_receive_server_signature_even_if_bytes_match(): void
    {
        [$generation, $request] = $this->fixture();
        $request['dataset_path'] = $this->root.'/app/lab-datasets/../lab-datasets/future.csv';
        $this->expectExceptionMessage('RESEARCH_TRANSPORT_SOURCE_PATH_INVALID');
        app(InstrumentResearchWindowService::class)->bindReplayRequest($generation, $request);
    }

    private function fixture(string $firstTime = '2027-02-01T00:00:00Z'): array
    {
        $path = $this->root.'/app/lab-datasets/future.csv';
        File::put($path, "time,open,high,low,close,volume\n{$firstTime},100,101,99,100,10\n2027-02-01T00:05:00Z,100,101,99,100,10\n");
        $hash = hash_file('sha256', $path);
        config(['services.instrument_policy.authorized_research_windows' => [[
            'authorization_id' => 'original-server-window', 'research_epoch_id' => 'post-paper-research',
            'purpose' => 'instrument_independent_validation', 'dataset_sha256' => $hash,
            'start_inclusive' => '2027-02-01T00:00:00Z', 'end_exclusive' => '2027-03-01T00:00:00Z',
        ]]]);
        $identity = ['protocol' => 'prospective_research_release_v1',
            'source_hash' => app(LabImmutableEvidenceService::class)->codeHash(),
            'python_source_hash' => app(ResearchReleaseSealService::class)->pythonHash(),
            'php_version' => PHP_VERSION, 'dataset_hash' => $hash, 'agent_execution_hashes' => ['1' => str_repeat('e', 64)]];
        $seal = [...$identity, 'release_hash' => app(ExecutionContractService::class)->hashParameters($identity),
            'promotion_evidence' => false, 'sealed_at' => now()->toIso8601String()];
        $lab = AiLaboratory::create(['name' => 'transport fixture', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'population_size' => 0, 'status' => 'draft', 'trigger_type' => 'learning_confirmation',
            'trigger_context' => ['research_release' => $seal, 'canonical_dataset_snapshots' => ['price' => ['path' => $path, 'sha256' => $hash]]]]);
        return [$generation, ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'evaluation_mode' => 'full',
            'dataset_path' => $path, 'replay_dataset_hash' => $hash, 'research_release' => $seal,
            'execution_contract' => ['execution_hash' => str_repeat('e', 64)], 'policy_context' => []]];
    }
}
