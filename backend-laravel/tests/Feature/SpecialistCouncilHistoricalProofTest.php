<?php

namespace Tests\Feature;

use App\Models\LabEvaluationRun;
use App\Models\ModelVersion;
use App\Services\ExecutionContractService;
use App\Services\LabImmutableEvidenceService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\ResearchReleaseSealService;
use App\Services\SpecialistCouncilLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

require_once __DIR__.'/ResearchSourceArtifactTest.php';

/** Actual file/archive validators in isolation; no worker/exam/market qualification is asserted. */
class SpecialistCouncilHistoricalProofTest extends TestCase
{
    use RefreshDatabase;
    private string $fixtureRoot;
    private string $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = sys_get_temp_dir().'/council-history-test-'.bin2hex(random_bytes(8));
        $this->originalStorage = storage_path(); app()->useStoragePath($this->fixtureRoot.'/storage');
        foreach (['backend-laravel/app/Example.php' => '<?php return "original archived source";',
            'backend-laravel/config/example.php' => '<?php return [];', 'backend-laravel/composer.json' => '{}',
            'backend-laravel/composer.lock' => '{}', 'backend-laravel/package-lock.json' => '{}',
            'ai-service-python/requirements.txt' => 'pandas==2.2.3', 'ai-service-python/app/main.py' => '# original archived python'] as $path => $bytes) {
            File::ensureDirectoryExists(dirname($this->fixtureRoot.'/'.$path)); File::put($this->fixtureRoot.'/'.$path, $bytes);
        }
    }

    protected function tearDown(): void
    {
        app()->useStoragePath($this->originalStorage);
        $resolved = realpath($this->fixtureRoot); $prefix = str_replace('\\', '/', realpath(sys_get_temp_dir())).'/council-history-test-';
        if ($resolved && str_starts_with(str_replace('\\', '/', $resolved), $prefix)) File::deleteDirectory($resolved);
        parent::tearDown();
    }

    public function test_original_archived_release_and_request_survive_later_source_bytes_without_live_admission(): void
    {
        [$owner, $run, $request, $response, $plan] = $this->original();
        File::put($this->fixtureRoot.'/backend-laravel/app/Example.php', '<?php return "later source release";');
        $this->assertNotSame($run->code_hash, $owner->identities()['source_hash']);
        $service = app(SpecialistCouncilLifecycleService::class);
        (new \ReflectionMethod($service, 'assertArchivedOriginalRelease'))->invoke($service, $run, $request, $response, $plan);
        $this->assertSame($request, (new \ReflectionMethod($service, 'originalArtifact'))->invoke($service, $run, 'evaluation_request'));
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_missing_or_tampered_archive_and_original_request_are_refused(): void
    {
        [, $run, $request, $response, $plan] = $this->original();
        $service = app(SpecialistCouncilLifecycleService::class);
        $artifact = \App\Models\LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_request')->sole();
        $metadata = $artifact->metadata; $artifact->update(['metadata' => [...$metadata, 'request_hash' => str_repeat('0', 64)]]);
        try { (new \ReflectionMethod($service, 'originalArtifact'))->invoke($service, $run, 'evaluation_request'); $this->fail('Tampered original request ownership was accepted.'); }
        catch (\LogicException $error) { $this->assertSame('ORIGINAL_EVIDENCE_HASH_INVALID', $error->getMessage()); }
        $artifact->update(['metadata' => $metadata]);
        $archive = $this->fixtureRoot.'/'.$request['research_release']['source_artifact']['archive_path'];
        $originalBytes = File::get($archive); File::append($archive, 'tampered');
        try { (new \ReflectionMethod($service, 'assertArchivedOriginalRelease'))->invoke($service, $run, $request, $response, $plan); $this->fail('Tampered archived source was accepted.'); }
        catch (\RuntimeException $error) { $this->assertSame('SOURCE_ARTIFACT_ARCHIVE_HASH_MISMATCH', $error->getMessage()); }
        File::put($archive, $originalBytes); unlink($archive);
        try { (new \ReflectionMethod($service, 'assertArchivedOriginalRelease'))->invoke($service, $run, $request, $response, $plan); $this->fail('Missing archived source was accepted.'); }
        catch (\RuntimeException $error) { $this->assertSame('SOURCE_ARTIFACT_ARCHIVE_MISSING', $error->getMessage()); }
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    /** Conditional consumed-proof shape isolates authorization; actual public replay is tested separately. */
    public function test_signed_original_authorization_survives_registry_removal_but_unsigned_and_mismatched_proofs_refuse(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2028-01-01T00:00:00Z'));
        [$source, $run, $request, $response, $plan] = $this->original(true);
        $owner = app(SpecialistCouncilLifecycleService::class);
        $archive = (new \ReflectionMethod($owner, 'assertArchivedOriginalRelease'))->invoke($owner, $run, $request, $response, $plan);
        $check = new \ReflectionMethod($owner, 'authorizedOriginalPlanWindow'); $window = array_values($plan['windows'])[0];
        $registry = config('services.instrument_policy.authorized_research_windows');
        foreach ([[], [[...$registry[0], 'dataset_sha256' => hash('sha256', 'later changed authorization')]]] as $later) {
            config(['services.instrument_policy.authorized_research_windows' => $later]);
            $this->assertFalse(app(\App\Services\InstrumentResearchWindowService::class)->authorized($window, $run->data_hash));
            $this->assertTrue($check->invoke($owner, $window, $run, $request, $response, $plan, $archive));
        }
        $bad = $request; unset($bad['policy_context']['authorized_research_transport']['hmac_sha256']);
        try { $check->invoke($owner, $window, $run, $bad, $response, $plan, $archive); $this->fail('Unsigned history used the archived registry digest as authority.'); }
        catch (\LogicException $error) { $this->assertSame('COUNCIL_ORIGINAL_WINDOW_ISSUER_SIGNATURE_INVALID', $error->getMessage()); }
        $bad = $response; $bad['data_quality']['context_source_attestations']['H4']['actual_source_sha256'] = hash('sha256', 'counterfeit consumed file');
        try { $check->invoke($owner, $window, $run, $request, $bad, $plan, $archive); $this->fail('Wrong consumed bytes were accepted.'); }
        catch (\LogicException $error) { $this->assertSame('COUNCIL_ORIGINAL_ATTESTED_FILE_CONSUMPTION_MISMATCH:H4', $error->getMessage()); }
        $bad = $request; $bad['mtf_snapshot_manifest']['streams']['H1']['path'] .= '.not-the-original-signed-path';
        try { $check->invoke($owner, $window, $run, $bad, $response, $plan, $archive); $this->fail('Original signed file path mismatch was accepted.'); }
        catch (\LogicException $error) { $this->assertSame('COUNCIL_ORIGINAL_ATTESTED_FILE_CONSUMPTION_MISMATCH:H1', $error->getMessage()); }
        $bad = $request; unset($bad['research_release']['source_artifact']);
        try { (new \ReflectionMethod($owner, 'assertArchivedOriginalRelease'))->invoke($owner, $run, $bad, $response, $plan); $this->fail('Missing original archive was accepted.'); }
        catch (\LogicException $error) { $this->assertSame('COUNCIL_ORIGINAL_ARCHIVED_WORKER_RELEASE_REQUIRED', $error->getMessage()); }
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    /** Pure post-attestation witness guard; this conditional shape grants no native/exam authority. */
    public function test_native_context_witness_requires_every_member_to_match_the_original_signed_source(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2028-01-01T00:00:00Z'));
        [, , $request, $response] = $this->original(true);
        $owner = app(SpecialistCouncilLifecycleService::class);
        $check = new \ReflectionMethod(SpecialistCouncilLifecycleService::class, 'originalNativeContextWitness');
        $file = $request['policy_context']['authorized_research_transport']['files']['H1'];
        $proof = [...$response['data_quality']['context_source_attestations']['H1'], 'stream' => 'H1',
            'first_candle_utc' => $file['start_inclusive'], 'last_candle_utc' => $file['last_candle_at']];
        $members = array_map(fn ($role) => ['role' => $role, 'context_source_attestations' => ['H1' => $proof]], ['hour', 'swing', 'scalp', 'day']);
        $receipt = ['members' => $members];
        $this->assertSame($proof, $check->invoke($owner, $receipt, 'H1', $file));
        $variants = [];
        $bad = $receipt; unset($bad['members'][2]['context_source_attestations']['H1']); $variants[] = $bad;
        foreach (['actual_source_sha256' => hash('sha256', 'wrong native context bytes'), 'consumed_rows' => 1,
            'consumed_data_hash' => hash('sha256', 'different actual member consumption'), 'status' => 'unsealed', 'stream' => 'H4',
            'first_candle_utc' => '2027-02-01T00:01:00Z', 'last_candle_utc' => '2027-02-01T02:00:00Z'] as $field => $value) {
            $bad = $receipt; $bad['members'][2]['context_source_attestations']['H1'][$field] = $value; $variants[] = $bad;
        }
        foreach ($variants as $bad) {
            try { $check->invoke($owner, $bad, 'H1', $file); $this->fail('Conflicting or missing native member context was accepted.'); }
            catch (\LogicException $error) { $this->assertSame('COUNCIL_ORIGINAL_ATTESTED_FILE_CONSUMPTION_MISMATCH:H1', $error->getMessage()); }
        }
        foreach (['sha256' => hash('sha256', 'different signed source'), 'rows' => 3] as $field => $value) {
            try { $check->invoke($owner, $receipt, 'H1', [...$file, $field => $value]); $this->fail('Witness did not match original signed source.'); }
            catch (\LogicException $error) { $this->assertSame('COUNCIL_ORIGINAL_ATTESTED_FILE_CONSUMPTION_MISMATCH:H1', $error->getMessage()); }
        }
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    private function original(bool $signedWindow = false): array
    {
        $dataHash = hash('sha256', 'conditional historical archive-validator data'); $records = []; $files = []; $window = null;
        if ($signedWindow) {
            config(['services.internal_api.token' => str_repeat('historical-test-issuer-key-', 3)]);
            foreach (['M5' => 300, 'H4' => 14400, 'H1' => 3600, 'M15' => 900] as $stream => $step) {
                $path = $this->fixtureRoot.'/datasets/'.$stream.'.csv'; File::ensureDirectoryExists(dirname($path));
                $first = '2027-02-01T00:00:00Z'; $last = \Carbon\CarbonImmutable::parse($first)->addSeconds($step)->format('Y-m-d\TH:i:s\Z');
                File::put($path, "time,open,high,low,close,volume\n{$first},100,101,99,100,10\n{$last},100,102,99,101,11\n");
                $records[$stream] = ['path' => $path, 'sha256' => hash_file('sha256', $path), 'rows' => 2, 'first_candle_at' => $first, 'last_candle_at' => $last];
                $files[$stream] = ['path' => $path, 'sha256' => $records[$stream]['sha256'], 'rows' => 2, 'start_inclusive' => $first, 'last_candle_at' => $last];
            }
            $dataHash = app(ResearchPaperEpochContractService::class)->parameterHash($records);
            config(['services.instrument_policy.authorized_research_windows' => [['authorization_id' => 'original-history-unit',
                'research_epoch_id' => 'original-history', 'purpose' => 'instrument_independent_validation', 'dataset_sha256' => $dataHash,
                'start_inclusive' => '2027-02-01T00:00:00Z', 'end_exclusive' => '2027-03-01T00:00:00Z']]]);
            $window = app(\App\Services\InstrumentResearchWindowService::class)->seal('original-history-unit', $dataHash);
            $this->assertNotNull($window);
        }
        $owner = new FixtureResearchSourceArtifactOwner($this->fixtureRoot);
        $reference = $owner->buildSourceArtifact()['reference']; app()->instance(ResearchReleaseSealService::class, $owner);
        $identity = ['protocol' => ResearchReleaseSealService::PROTOCOL, 'source_hash' => $reference['source_hash'],
            'python_source_hash' => $reference['python_source_hash'], 'php_version' => PHP_VERSION, 'dataset_hash' => $dataHash,
            'agent_execution_hashes' => [], 'source_artifact' => $reference];
        $seal = [...$identity, 'release_hash' => app(ExecutionContractService::class)->hashParameters($identity),
            'sealed_at' => now()->toIso8601String(), 'promotion_evidence' => false];
        $request = ['research_release' => $seal, 'replay_dataset_hash' => $dataHash];
        // Conditional worker shape isolates the original archive verifier,
        // never learning eligibility, full qualification, or a real worker proof.
        $response = ['data_quality' => ['research_release_receipt' => ['protocol' => 'research_worker_release_receipt_v1',
            'loaded_code_attested' => true, 'release_hash' => $seal['release_hash'],
            'source_hash' => $seal['python_source_hash'], 'boot_source_hash' => $seal['python_source_hash']]]];
        $model = ModelVersion::create(['name' => 'unqualified historical archive validator', 'strategy' => 'ema_rsi_v1',
            'version' => 'v1', 'status' => 'testing', 'parameters' => [], 'metadata' => []]);
        $plan = ['preparation_source_hash' => $reference['source_hash']]; $generationId = null; $agentId = null;
        if ($signedWindow) {
            $lab = \App\Models\AiLaboratory::create(['name' => 'isolated signed history validator', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_families' => ['ema_rsi']]);
            $generation = \App\Models\LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'status' => 'completed']);
            $agent = \App\Models\LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'ema_rsi', 'origin' => 'archive_guard_fixture', 'lifecycle_status' => 'completed']);
            $generationId = (int) $generation->id; $agentId = (int) $agent->id;
            $runtimePolicy = ['protocol' => 'specialist_council_original_full_source_v1', 'maximum_source_rows' => 200000, 'warmup_rows' => 0];
            $scope = ['start_inclusive' => $files['M5']['start_inclusive'], 'end_exclusive' => '2027-02-01T00:10:00Z', 'rows' => 2, 'decision_rows' => 1, 'warmup_rows' => 0,
                'policy_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($runtimePolicy)];
            $binding = ['version_id' => 41, 'manifest_hash' => hash('sha256', 'isolated manifest'), 'plan_hash' => hash('sha256', 'isolated plan'), 'arm_key' => 'original-solo'];
            $strategy = ['lab_agent_id' => $agentId, 'strategy' => $model->strategy, 'version' => $model->version, 'parameters' => [], 'specialist_council_evaluation' => $binding];
            $shared = ['initial_balance' => 10000, 'risk_per_trade' => .5, 'execution' => [], 'execution_contract' => [], 'volume_context' => [], 'emit_decision_trace' => true];
            $request = [...$request, ...$shared, 'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'evaluation_mode' => 'full',
                'strategies' => [$strategy], 'specialist_council_evaluation' => $binding, 'mtf_snapshot_manifest' => ['streams' => $records]];
            $modelHash = app(\App\Services\SpecialistCouncilContractService::class)->modelHash($model);
            $plan = [...$plan, 'version_id' => 41, 'manifest_hash' => $binding['manifest_hash'], 'execution_timeframe' => 'M5',
                'panel_work_item_id' => 7, 'panel_reservation_hash' => hash('sha256', 'isolated reservation'), 'windows' => [$window['window_key'] => $window],
                'full_replay_runtime_policy' => $runtimePolicy, 'arms' => ['original-solo' => ['kind' => 'solo', 'model_hash' => $modelHash, 'evaluation_scope' => $scope]]];
            $json = static fn ($value) => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            $originalArm = ['protocol' => 'authorized_original_council_arm_v1', 'purpose' => 'independent', 'generation_id' => $generationId,
                'work_item_id' => 7, 'reservation_hash' => $plan['panel_reservation_hash'], ...$binding, 'kind' => 'solo', 'window_key' => $window['window_key'],
                'model_version_id' => (int) $model->id, 'model_hash' => $modelHash, 'strategy_payload_json' => $json($strategy),
                'runtime_policy_json' => $json($runtimePolicy), 'evaluation_scope_json' => $json($scope), 'shared_runtime_json' => $json($shared),
                'independent_evidence' => false, 'promotion_evidence' => false];
            $identity = ['protocol' => \App\Services\InstrumentResearchWindowService::TRANSPORT_PROTOCOL, 'purpose' => 'server_authorized_research_execution',
                'generation_id' => $generationId, 'release_hash' => $seal['release_hash'], 'dataset_hash' => $dataHash, 'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'evaluation_mode' => 'full',
                'window' => $window, 'files' => $files, 'paper_exclusions' => [['start_inclusive' => '2026-01-01T00:00:00Z', 'end_exclusive' => '2027-01-01T00:00:00Z']],
                'original_council_arm' => $originalArm, 'independent_evidence' => false, 'promotion_evidence' => false];
            $ordered = function ($value) use (&$ordered) { if (! is_array($value)) return $value; if (! array_is_list($value)) ksort($value, SORT_STRING); return array_map($ordered, $value); };
            $canonical = json_encode($ordered($identity), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $request['policy_context']['authorized_research_transport'] = [...$identity, 'contract_hash' => hash('sha256', $canonical),
                'hmac_sha256' => hash_hmac('sha256', \App\Services\InstrumentResearchWindowService::TRANSPORT_PROTOCOL."\n".$canonical, config('services.internal_api.token'))];
            foreach ($files as $stream => $file) {
                $consumed = ['status' => 'verified', 'actual_source_sha256' => $file['sha256'], 'consumed_rows' => 2, 'consumed_data_hash' => hash('sha256', File::get($file['path']))];
                if ($stream === 'M5') $response['data_quality']['dataset_attestation'] = $consumed;
                else $response['data_quality']['context_source_attestations'][$stream] = $consumed;
            }
        }
        $evidence = app(LabImmutableEvidenceService::class);
        $run = LabEvaluationRun::create(['run_id' => 'history-'.bin2hex(random_bytes(8)), 'model_version_id' => $model->id,
            'lab_generation_id' => $generationId, 'lab_agent_id' => $agentId,
            'phase' => 'full_validation', 'mode' => 'full', 'status' => 'running', 'started_at' => now(),
            'request_hash' => $evidence->hash($request), 'data_hash' => $dataHash, 'code_hash' => $reference['source_hash']]);
        $evidence->recordArtifact($run, 'evaluation_request', $request, ['request_hash' => $run->request_hash]);
        $responseArtifact = $evidence->recordArtifact($run, 'evaluation_response', $response);
        $run->update(['status' => 'completed', 'finished_at' => now()->addSecond(), 'response_hash' => $responseArtifact->sha256]);
        return [$owner, $run->fresh(), $request, $response, $plan];
    }
}
