<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Services\LabImmutableEvidenceService;
use App\Services\ResearchReleaseSealService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class FixtureResearchSourceArtifactOwner extends ResearchReleaseSealService
{
    public $afterCapture;
    public int $fullArchiveVerifications = 0;
    public int $archiveByteFences = 0;
    public function __construct(public string $root) {}
    protected function artifactProjectRoot(): string { return $this->root; }
    protected function artifactGitProvenance(): array
    {
        return ['head' => str_repeat('1', 40), 'dirty' => true, 'porcelain_sha256' => hash('sha256', 'dirty fixture'), 'commit_created_by_build' => false];
    }
    protected function artifactToolVersions(): array
    {
        if ($this->afterCapture) ($this->afterCapture)();
        return ['php' => PHP_VERSION, 'python' => 'fixture-only-test-tool', 'git' => 'fixture-only-test-tool'];
    }
    protected function artifactCurrentIdentity(): array
    {
        $files = [];
        foreach (parent::artifactSourcePaths() as $path) $files[$path] = ['sha256' => hash_file('sha256', $this->root.'/'.$path)];
        return $this->artifactIdentityFromFiles($files, null);
    }
    public function identities(): array { return $this->artifactCurrentIdentity(); }
    public function pythonHash(): string { return $this->identities()['python_source_hash']; }
    public function verifySourceArtifact(array $reference, bool $requireCurrent = false): array
    {
        $this->fullArchiveVerifications++;
        return parent::verifySourceArtifact($reference, $requireCurrent);
    }
    public function sourceArtifactByteFence(array $reference): array
    {
        $this->archiveByteFences++;
        return parent::sourceArtifactByteFence($reference);
    }
}

class ResearchSourceArtifactTest extends TestCase
{
    use RefreshDatabase;
    private const REPLAY_IDLE_SOURCE_FILES = [
        'backend-laravel/app/Services/ReplayLivenessProbeService.php',
        'backend-laravel/tests/Unit/ReplayLivenessProbeTest.php',
    ];
    private const NATIVE_META_SOURCE_FILES = [
        'backend-laravel/app/Services/InstrumentMethodNativeMetaModelTrait.php',
        'backend-laravel/app/Services/InstrumentMethodNativeMetaBindingTrait.php',
        'backend-laravel/tests/Feature/InstrumentMethodNativeMetaTest.php',
        'ai-service-python/app/services/instrument_method_native_meta_model.py',
        'ai-service-python/tests/test_instrument_method_native_meta_runtime.py',
        'ai-service-python/tests/support/native_instrument_method_meta_fixture.py',
    ];
    private const METHOD_SUPPLEMENT_DECLARATIONS = [
        'closed-band-reentry' => ['source', 'method', 'recipe', 'probe-spec'],
        'observed-quote-firewall' => ['quote-source', 'atr-source', 'method', 'recipe'],
        'closed-ema-pullback' => ['source', 'method', 'recipe'],
        'closed-donchian-retest' => ['source', 'method', 'recipe'],
        'closed-macd-cross' => ['source', 'method', 'recipe', 'hybrid'],
        'closed-rsi-recovery' => ['source', 'method', 'recipe'],
        'closed-chandelier-exit' => ['source', 'method', 'recipe'],
        'native-single-partial-runner' => ['source', 'method', 'recipe'],
    ];
    private const METHOD_SUPPLEMENT_SOFTWARE_FILES = [
        'backend-laravel/tests/Feature/AgentMethodVerificationScopeTest.php',
        'backend-laravel/tests/Feature/ResearchExperimentConversionKernelServiceTest.php',
        'backend-laravel/tests/Feature/ClosedBandMethodDeclarationTest.php',
        'backend-laravel/tests/Feature/ObservedQuoteMethodDeclarationTest.php',
        'backend-laravel/tests/Feature/ClosedTrendMethodDeclarationTest.php',
        'backend-laravel/tests/Feature/SourceGroundedMethodDeclarationTest.php',
        'backend-laravel/tests/Feature/NativeSinglePartialMethodDeclarationTest.php',
        'ai-service-python/tests/test_source_grounded_method_features.py',
        'ai-service-python/tests/test_source_grounded_method_runtime.py',
        'ai-service-python/tests/test_instrument_method_session_runtime.py',
        'ai-service-python/tests/test_instrument_method_band_reentry.py',
        'ai-service-python/tests/test_observed_quote_method.py',
        'ai-service-python/tests/test_instrument_method_quote_alias.py',
        'ai-service-python/tests/test_instrument_method_pullback_retest.py',
        'ai-service-python/tests/test_volume_none_confidence_compatibility.py',
        'ai-service-python/tests/fixtures/observed_quote_canonical_synthetic.csv',
        'ai-service-python/tests/fixtures/observed_quote_canonical_synthetic.provenance.json',
    ];
    private const EXCLUDED_METHOD_SOURCE_FILES = [
        'backend-laravel/resources/research/instrument-methods/unlisted.json',
        'backend-laravel/resources/research/instrument-methods/closed-band-reentry/unlisted.json',
        'ai-service-python/tests/fixtures/provider-quotes.csv',
        'ai-service-python/tests/fixtures/observed_quote_canonical.csv',
        'ai-service-python/tests/fixtures/unlisted.provenance.json',
        'backend-laravel/storage/app/lab-datasets/provider-quotes.csv',
        '.runtime/private-method-panel.py',
        'docs/task-final-notes.md',
    ];
    private const SOURCE_PRACTICE_FILES = [
        'backend-laravel/resources/research/instrument-operating-skills/curator-archive-observations-v2.json',
        'backend-laravel/resources/research/instrument-methods/closed-ema-slope-pullback/source.json',
        'backend-laravel/resources/research/instrument-methods/closed-ema-slope-pullback/method.json',
        'backend-laravel/resources/research/instrument-methods/closed-ema-slope-pullback/recipe.json',
        'backend-laravel/resources/research/instrument-methods/closed-atr-percent/source.json',
        'backend-laravel/resources/research/instrument-methods/closed-atr-percent/method.json',
        'backend-laravel/resources/research/instrument-methods/closed-atr-percent/recipe.json',
        'backend-laravel/resources/research/instrument-operating-skills/source-practice-v2.json',
        'backend-laravel/tests/Feature/ClosedAtrPercentMethodDeclarationTest.php',
        'backend-laravel/tests/Feature/InstrumentSourcePracticeTeachingTest.php',
        'ai-service-python/tests/test_closed_atr_percent_method.py',
        'ai-service-python/tests/test_instrument_method_ema_pretrend.py',
        'backend-laravel/tests/Feature/SourceEmaPretrendEntryTest.php',
        'ai-service-python/tests/test_source_ema_pretrend_entry.py',
        'backend-laravel/tests/Feature/InstrumentMethodPhaseSelectionTest.php',
        'ai-service-python/tests/test_instrument_method_phase_selection.py',
    ];
    private const OPERATING_CURRICULUM_SOURCE_FILES = [
        'backend-laravel/resources/research/instrument-operating-skills/price-structure-v1.json',
        'backend-laravel/resources/research/instrument-operating-skills/risk-execution-v1.json',
        'backend-laravel/resources/research/instrument-operating-skills/model-v1.json',
        // App source is recursively collected; it needs no explicit allowlist entry.
        'backend-laravel/app/Services/InstrumentOperatingSkillCurriculumTrait.php',
        'backend-laravel/tests/Feature/InstrumentOperatingSkillCurriculumTest.php',
        'backend-laravel/tests/Feature/AgentOperatingSkillTeachingTest.php',
        'backend-laravel/tests/Feature/AgentMethodStudyTeachingTest.php',
        'ai-service-python/tests/test_instrument_operating_skill_exercises.py',
        'ai-service-python/tests/test_temporal_closed_observation.py',
    ];
    private string $root;
    private FixtureResearchSourceArtifactOwner $owner;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(ZipArchive::class)) $this->markTestSkipped('ZIP runtime required for source artifact.');
        $this->root = sys_get_temp_dir().'/research-source-artifact-test-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->root);
        foreach ([
            'backend-laravel/app/Example.php' => '<?php return "actual source bytes";',
            'backend-laravel/config/example.php' => '<?php return ["safe" => true];',
            'backend-laravel/routes/console.php' => '<?php // scheduler source',
            'backend-laravel/scripts/run-ai-service.py' => '# actual runtime script',
            'backend-laravel/composer.json' => '{}', 'backend-laravel/composer.lock' => '{}',
            'backend-laravel/package-lock.json' => '{}', 'ai-service-python/requirements.txt' => 'pandas==2.2.3',
            'ai-service-python/app/main.py' => '# actual python source',
            'ai-service-python/tests/test_research_release.py' => '# focused regression',
            'backend-laravel/resources/research/instrument-methods/curated-v1.json' => '{"catalog":"pinned research-only fixture"}',
            'backend-laravel/resources/research/instrument-methods/curated-v2.json' => '{"catalog":"append-only alternative methods fixture"}',
            'backend-laravel/resources/research/instrument-methods/calibrated-meta-score/source.json' => '{"reference":"synthetic source fixture"}',
            'backend-laravel/resources/research/instrument-methods/calibrated-meta-score/method.json' => '{"method":"synthetic score fixture"}',
            'backend-laravel/resources/research/instrument-methods/calibrated-meta-score/recipe.json' => '{"recipe":"synthetic immutable fixture"}',
            'backend-laravel/.env' => 'SECRET_DO_NOT_ARCHIVE=fake-test-secret',
            'backend-laravel/storage/secret.php' => '<?php // never source',
            'backend-laravel/vendor/secret.php' => '<?php // never source',
            'backend-laravel/node_modules/secret.js' => '// never source',
            'datasets/secret.csv' => 'never archive data',
        ] as $path => $bytes) {
            File::ensureDirectoryExists(dirname($this->root.'/'.$path));
            File::put($this->root.'/'.$path, $bytes);
        }
        foreach ([...$this->methodSupplementPaths(), ...self::EXCLUDED_METHOD_SOURCE_FILES] as $path) {
            File::ensureDirectoryExists(dirname($this->root.'/'.$path));
            File::put($this->root.'/'.$path, 'synthetic source-artifact test bytes: '.$path);
        }
        $projectRoot = dirname(base_path());
        foreach ([...self::OPERATING_CURRICULUM_SOURCE_FILES, ...self::SOURCE_PRACTICE_FILES, ...self::REPLAY_IDLE_SOURCE_FILES, ...self::NATIVE_META_SOURCE_FILES] as $path) {
            if (! is_file($projectRoot.'/'.$path)) throw new RuntimeException('OPERATING_CURRICULUM_TEST_FIXTURE_MISSING');
            File::ensureDirectoryExists(dirname($this->root.'/'.$path));
            if (! File::copy($projectRoot.'/'.$path, $this->root.'/'.$path)) throw new RuntimeException('OPERATING_CURRICULUM_TEST_FIXTURE_COPY_FAILED');
        }
        $this->owner = new FixtureResearchSourceArtifactOwner($this->root);
    }

    public function test_exact_replay_idle_probe_source_and_regression_are_archived(): void
    {
        $artifact = $this->owner->buildSourceArtifact();
        $this->assertSame('verified', $artifact['status'], json_encode($artifact));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->zipPath($artifact['reference'])));
        try {
            foreach (self::REPLAY_IDLE_SOURCE_FILES as $path) {
                $bytes = $zip->getFromName($path);
                $this->assertIsString($bytes, $path);
                $this->assertSame(hash_file('sha256', $this->root.'/'.$path), hash('sha256', $bytes));
            }
            $this->assertFalse($zip->getFromName('backend-laravel/.env'));
            $this->assertFalse($zip->getFromName('datasets/secret.csv'));
        } finally { $zip->close(); }
    }

    public function test_native_meta_original_issuer_target_and_php_python_regressions_are_exact_archive_bytes(): void
    {
        $artifact = $this->owner->buildSourceArtifact(); $this->assertSame('verified', $artifact['status'], json_encode($artifact));
        $zip = new ZipArchive; $this->assertTrue($zip->open($this->zipPath($artifact['reference'])));
        try {
            foreach (self::NATIVE_META_SOURCE_FILES as $path) {
                $bytes = $zip->getFromName($path); $this->assertIsString($bytes, $path);
                $this->assertSame(hash_file('sha256', $this->root.'/'.$path), hash('sha256', $bytes), $path);
            }
            $this->assertFalse($zip->getFromName('backend-laravel/.env')); $this->assertFalse($zip->getFromName('datasets/secret.csv'));
        } finally { $zip->close(); }
    }

    public function test_source_practice_additions_are_exact_bytes_without_private_market_products_or_unlisted_sources(): void
    {
        $unlisted = 'backend-laravel/resources/research/instrument-operating-skills/unlisted-practice.json';
        File::put($this->root.'/'.$unlisted, '{"not":"a reviewed source"}');
        $artifact = $this->owner->buildSourceArtifact(); $this->assertSame('verified', $artifact['status']);
        $zip = new ZipArchive; $this->assertTrue($zip->open($this->zipPath($artifact['reference'])));
        try {
            foreach (self::SOURCE_PRACTICE_FILES as $path) {
                $bytes = $zip->getFromName($path); $this->assertIsString($bytes, $path);
                $this->assertSame(hash_file('sha256', $this->root.'/'.$path), hash('sha256', $bytes), $path);
            }
            foreach ([$unlisted, 'backend-laravel/.env', 'datasets/secret.csv', '.runtime/private-method-panel.py'] as $path) {
                $this->assertFalse($zip->getFromName($path));
            }
        } finally { $zip->close(); }
        $reference = $artifact['reference']; $path = self::SOURCE_PRACTICE_FILES[0];
        File::append($this->root.'/'.$path, "\n changed fixture only");
        try {
            $this->owner->verifySourceArtifact($reference, true);
            $this->fail('Current-source verification must refuse changed instruction bytes.');
        } catch (RuntimeException $exception) {
            $this->assertSame('SOURCE_ARTIFACT_SOURCE_DRIFT', $exception->getMessage());
        }
        $this->assertSame('verified', $this->owner->verifySourceArtifact($reference, false)['status']);
        $this->assertFalse($artifact['scientific_evidence']); $this->assertFalse($artifact['promotion_evidence']);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            $resolved = realpath($this->root);
            $allowed = str_replace('\\', '/', (string) realpath(sys_get_temp_dir())).'/research-source-artifact-test-';
            if ($resolved && str_starts_with(str_replace('\\', '/', $resolved), $allowed)) File::deleteDirectory($resolved);
        }
        parent::tearDown();
    }

    public function test_archive_byte_fence_rehashes_actual_bytes_and_original_reference_without_claiming_full_verification(): void
    {
        $reference = $this->owner->buildSourceArtifact()['reference'];
        $calls = $this->owner->fullArchiveVerifications;
        $first = $this->owner->sourceArtifactByteFence($reference);
        $this->assertSame($first, $this->owner->sourceArtifactByteFence($reference));
        $this->assertSame($calls, $this->owner->fullArchiveVerifications);
        $path = $this->root.'/'.$reference['archive_path'];
        $bytes = File::get($path);
        $bytes[100] = chr(ord($bytes[100]) ^ 1);
        File::put($path, $bytes);
        $this->expectExceptionMessage('SOURCE_ARTIFACT_ARCHIVE_HASH_MISMATCH');
        $this->owner->sourceArtifactByteFence($reference);
    }

    public function test_archive_byte_fence_refuses_changed_reference_bytes_even_with_original_zip(): void
    {
        $reference = $this->owner->buildSourceArtifact()['reference'];
        $this->owner->sourceArtifactByteFence($reference);
        $path = dirname($this->root.'/'.$reference['archive_path']).'/'.$reference['artifact_hash'].'.json';
        $changed = [...$reference, 'extra_projection' => true];
        File::put($path, json_encode($changed, JSON_THROW_ON_ERROR));
        $this->expectExceptionMessage('SOURCE_ARTIFACT_REFERENCE_INVALID');
        $this->owner->sourceArtifactByteFence($reference);
    }

    public function test_retained_address_lookup_is_read_only_bounded_and_excludes_non_address_projections(): void
    {
        $directory = $this->root.'/.runtime/research-source-artifacts';
        $this->assertSame([], $this->owner->retainedSourceArtifactAddresses());
        $this->assertDirectoryDoesNotExist($directory);
        $reference = $this->owner->buildSourceArtifact()['reference'];
        File::put($directory.'/ignored.json', '{}');
        $before = File::get($directory.'/current.json');
        $this->assertSame([$reference['artifact_hash']], $this->owner->retainedSourceArtifactAddresses());
        $this->assertSame($before, File::get($directory.'/current.json'));
        $this->assertSame($reference, $this->owner->sourceArtifactReference($reference['artifact_hash']));
    }

    public function test_retained_address_lookup_overflow_is_not_a_sampled_source_proof(): void
    {
        $this->owner->buildSourceArtifact();
        File::put($this->root.'/backend-laravel/app/Example.php', '<?php return "second source";');
        $this->owner->buildSourceArtifact();
        $this->assertCount(2, $this->owner->retainedSourceArtifactAddresses());
        $this->expectExceptionMessage('SOURCE_ARTIFACT_REFERENCE_LOOKUP_BUDGET_EXCEEDED');
        $this->owner->retainedSourceArtifactAddresses(1);
    }

    public function test_retained_address_is_not_trusted_without_original_archive_validation(): void
    {
        $directory = $this->root.'/.runtime/research-source-artifacts';
        File::ensureDirectoryExists($directory);
        $address = str_repeat('b', 64);
        File::put($directory.'/'.$address.'.json', '{}');
        $this->assertSame([$address], $this->owner->retainedSourceArtifactAddresses());
        $this->expectExceptionMessage('SOURCE_ARTIFACT_REFERENCE_INVALID');
        $this->owner->sourceArtifactReference($address);
    }

    public function test_retained_lookup_cannot_raise_its_code_owned_budget(): void
    {
        $this->expectExceptionMessage('SOURCE_ARTIFACT_REFERENCE_LOOKUP_LIMIT_INVALID');
        $this->owner->retainedSourceArtifactAddresses(129);
    }

    public function test_actual_source_bytes_have_a_reproducible_idempotent_content_address_not_fake_git_or_worker_proof(): void
    {
        $first = $this->owner->buildSourceArtifact();
        $second = $this->owner->buildSourceArtifact();
        $this->assertSame($first, $second);
        $this->assertFalse($first['git_commit_sealed']);
        $this->assertFalse($first['worker_loaded_code_attested']);
        $this->assertFalse($first['scientific_evidence']);
        $this->assertFalse($first['promotion_evidence']);
        $this->assertSame($this->owner->identities()['source_hash'], $first['reference']['source_hash']);
        $this->assertSame($this->owner->pythonHash(), $first['reference']['python_source_hash']);
        $this->assertSame($first['reference'], $this->owner->currentSourceArtifact());
        $this->assertSame($first['reference'], $this->owner->sourceArtifactReference($first['reference']['artifact_hash']));
        foreach (array_keys($first['manifest']['files']) as $path) {
            $this->assertStringNotContainsString('.env', $path);
            $this->assertStringNotContainsString('/storage/', $path);
            $this->assertStringNotContainsString('/vendor/', $path);
            $this->assertStringNotContainsString('/node_modules/', $path);
            $this->assertStringNotContainsString('datasets/', $path);
        }
        $this->assertArrayHasKey('backend-laravel/scripts/run-ai-service.py', $first['manifest']['files']);
        $this->assertArrayHasKey('ai-service-python/tests/test_research_release.py', $first['manifest']['files']);
        $this->assertArrayHasKey('backend-laravel/resources/research/instrument-methods/curated-v1.json', $first['manifest']['files']);
        $this->assertArrayHasKey('backend-laravel/resources/research/instrument-methods/curated-v2.json', $first['manifest']['files']);
        foreach (['source', 'method', 'recipe'] as $kind) {
            $this->assertArrayHasKey('backend-laravel/resources/research/instrument-methods/calibrated-meta-score/'.$kind.'.json', $first['manifest']['files']);
        }
    }

    public function test_curated_method_bytes_are_sealed_and_drift_does_not_rewrite_old_artifact(): void
    {
        $reference = $this->owner->buildSourceArtifact()['reference'];
        File::put($this->root.'/backend-laravel/resources/research/instrument-methods/curated-v1.json', '{"catalog":"changed fixture"}');
        $this->assertNull($this->owner->currentSourceArtifact());
        $this->assertSame('verified', $this->owner->verifySourceArtifact($reference)['status']);
        $this->expectExceptionMessage('SOURCE_ARTIFACT_SOURCE_DRIFT');
        $this->owner->verifySourceArtifact($reference, true);
    }

    public function test_exact_method_supplements_and_synthetic_software_fixtures_are_archived_but_provider_and_private_files_are_not(): void
    {
        $paths = $this->methodSupplementPaths();
        $this->assertCount(44, $paths); // Twenty-seven declarations, fifteen tests, two synthetic fixtures.
        $projectRoot = dirname(base_path());
        $archive = $this->owner->buildSourceArtifact();
        foreach ($paths as $path) {
            $this->assertFileExists($projectRoot.'/'.$path, 'The explicitly allowlisted real repository path must exist.');
            $this->assertArrayHasKey($path, $archive['manifest']['files']);
            $this->assertSame(hash_file('sha256', $this->root.'/'.$path), $archive['manifest']['files'][$path]['sha256']);
        }
        foreach (self::EXCLUDED_METHOD_SOURCE_FILES as $path) {
            $this->assertFileExists($this->root.'/'.$path);
            $this->assertArrayNotHasKey($path, $archive['manifest']['files']);
        }
        $provenance = json_decode(File::get($projectRoot.'/ai-service-python/tests/fixtures/observed_quote_canonical_synthetic.provenance.json'), true, 32, JSON_THROW_ON_ERROR);
        $this->assertTrue($provenance['synthetic']);
        $this->assertFalse($provenance['provider_data']);
        $this->assertTrue($provenance['software_fixture_only']);
        $this->assertFalse($archive['scientific_evidence']);
        $this->assertFalse($archive['promotion_evidence']);
    }

    public function test_exact_operating_curriculum_bytes_and_proofs_are_archived_without_private_or_provider_files(): void
    {
        $this->assertCount(9, self::OPERATING_CURRICULUM_SOURCE_FILES);
        $projectRoot = dirname(base_path());
        $unlisted = 'backend-laravel/resources/research/instrument-operating-skills/unlisted.json';
        File::put($this->root.'/'.$unlisted, '{"fixture":"not an allowlisted lesson"}');
        $archive = $this->owner->buildSourceArtifact();
        $verified = $this->owner->verifySourceArtifact($archive['reference'], true);
        $this->assertSame('verified', $verified['status']);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->zipPath($archive['reference'])));
        try {
            foreach (self::OPERATING_CURRICULUM_SOURCE_FILES as $path) {
                $sha = hash_file('sha256', $projectRoot.'/'.$path);
                $this->assertSame($sha, hash_file('sha256', $this->root.'/'.$path));
                $this->assertArrayHasKey($path, $archive['manifest']['files']);
                $this->assertSame($sha, $archive['manifest']['files'][$path]['sha256']);
                $bytes = $zip->getFromName($path);
                $this->assertIsString($bytes);
                $this->assertSame($sha, hash('sha256', $bytes));
            }
            foreach ([...self::EXCLUDED_METHOD_SOURCE_FILES, $unlisted] as $path) {
                $this->assertArrayNotHasKey($path, $archive['manifest']['files']);
                $this->assertFalse($zip->getFromName($path));
            }
        } finally { $zip->close(); }
        $this->assertFalse($archive['scientific_evidence']);
        $this->assertFalse($archive['promotion_evidence']);
    }

    public function test_each_pinned_operating_lesson_drift_refuses_current_source_but_keeps_original_archive_immutable(): void
    {
        $archive = $this->owner->buildSourceArtifact();
        $reference = $archive['reference'];
        $zipHash = hash_file('sha256', $this->zipPath($reference));
        $pointerPath = $this->root.'/.runtime/research-source-artifacts/current.json';
        $pointer = File::get($pointerPath);
        foreach (array_slice(self::OPERATING_CURRICULUM_SOURCE_FILES, 0, 3) as $path) {
            $original = File::get($this->root.'/'.$path);
            try {
                File::put($this->root.'/'.$path, $original."\n");
                $this->assertNull($this->owner->currentSourceArtifact(), $path);
                $verified = $this->owner->verifySourceArtifact($reference);
                $this->assertSame('verified', $verified['status']);
                $this->assertSame($archive['manifest'], $verified['manifest']);
                $this->assertSame($zipHash, hash_file('sha256', $this->zipPath($reference)));
                $this->assertSame($pointer, File::get($pointerPath));
                try {
                    $this->owner->verifySourceArtifact($reference, true);
                    $this->fail('Changed '.$path.' must not certify current curriculum bytes.');
                } catch (RuntimeException $error) {
                    $this->assertSame('SOURCE_ARTIFACT_SOURCE_DRIFT', $error->getMessage());
                }
                $this->assertSame(hash('sha256', $original), $verified['manifest']['files'][$path]['sha256']);
            } finally { File::put($this->root.'/'.$path, $original); }
            $this->assertSame($reference, $this->owner->currentSourceArtifact());
        }
    }

    public function test_each_new_method_declaration_drift_retires_current_pointer_without_rewriting_the_verified_old_archive(): void
    {
        $archive = $this->owner->buildSourceArtifact();
        $reference = $archive['reference'];
        $zipHash = hash_file('sha256', $this->zipPath($reference));
        $pointer = File::get($this->root.'/.runtime/research-source-artifacts/current.json');
        foreach (array_keys(self::METHOD_SUPPLEMENT_DECLARATIONS) as $supplement) {
            $path = 'backend-laravel/resources/research/instrument-methods/'.$supplement.'/method.json';
            $original = File::get($this->root.'/'.$path);
            File::put($this->root.'/'.$path, '{"fixture":"changed new method declaration"}');
            $this->assertNull($this->owner->currentSourceArtifact(), $supplement);
            $verified = $this->owner->verifySourceArtifact($reference);
            $this->assertSame('verified', $verified['status']);
            $this->assertSame($archive['manifest'], $verified['manifest']);
            $this->assertSame($zipHash, hash_file('sha256', $this->zipPath($reference)));
            $this->assertSame($pointer, File::get($this->root.'/.runtime/research-source-artifacts/current.json'));
            try {
                $this->owner->verifySourceArtifact($reference, true);
                $this->fail('Changed '.$supplement.' declaration must not certify the current release.');
            } catch (RuntimeException $error) {
                $this->assertSame('SOURCE_ARTIFACT_SOURCE_DRIFT', $error->getMessage());
            } finally {
                File::put($this->root.'/'.$path, $original);
            }
            $this->assertSame($reference, $this->owner->currentSourceArtifact());
        }
    }

    public function test_alternative_catalog_drift_is_a_new_release_not_a_rewrite_of_v1(): void
    {
        $reference = $this->owner->buildSourceArtifact()['reference'];
        $originalV1 = hash_file('sha256', $this->root.'/backend-laravel/resources/research/instrument-methods/curated-v1.json');
        File::put($this->root.'/backend-laravel/resources/research/instrument-methods/curated-v2.json', '{"catalog":"changed alternative"}');
        $this->assertSame($originalV1, hash_file('sha256', $this->root.'/backend-laravel/resources/research/instrument-methods/curated-v1.json'));
        $this->assertNull($this->owner->currentSourceArtifact());
        $this->assertSame('verified', $this->owner->verifySourceArtifact($reference)['status']);
        $this->expectExceptionMessage('SOURCE_ARTIFACT_SOURCE_DRIFT');
        $this->owner->verifySourceArtifact($reference, true);
    }

    public function test_append_only_meta_recipe_drift_retires_current_artifact_not_the_original_bytes(): void
    {
        $reference = $this->owner->buildSourceArtifact()['reference'];
        $v2 = hash_file('sha256', $this->root.'/backend-laravel/resources/research/instrument-methods/curated-v2.json');
        File::put($this->root.'/backend-laravel/resources/research/instrument-methods/calibrated-meta-score/recipe.json', '{"recipe":"changed fixture"}');
        $this->assertSame($v2, hash_file('sha256', $this->root.'/backend-laravel/resources/research/instrument-methods/curated-v2.json'));
        $this->assertNull($this->owner->currentSourceArtifact());
        $this->assertSame('verified', $this->owner->verifySourceArtifact($reference)['status']);
        $this->expectExceptionMessage('SOURCE_ARTIFACT_SOURCE_DRIFT');
        $this->owner->verifySourceArtifact($reference, true);
    }

    public function test_prospective_method_generation_settings_are_sealed_without_exposing_secrets(): void
    {
        config()->set('services.instrument_methods', ['generation_learning_enabled' => false, 'generation_recipe_hashes' => []]);
        $original = $this->owner->buildSourceArtifact();
        $settings = $original['manifest']['effective_non_secret_settings'];
        $this->assertArrayHasKey('services.instrument_methods_sha256', $settings);
        $this->assertArrayNotHasKey('services.instrument_methods', $settings);
        config()->set('services.instrument_methods', ['generation_learning_enabled' => true,
            'generation_recipe_hashes' => [str_repeat('c', 64)]]);
        $this->assertNull($this->owner->currentSourceArtifact());
        $this->assertSame('verified', $this->owner->verifySourceArtifact($original['reference'])['status']);
        $this->expectExceptionMessage('SOURCE_ARTIFACT_SOURCE_DRIFT');
        $this->owner->verifySourceArtifact($original['reference'], true);
    }

    public function test_archive_hash_rejects_changed_bytes_even_when_the_address_and_labels_match(): void
    {
        $reference = $this->owner->buildSourceArtifact()['reference'];
        file_put_contents($this->zipPath($reference), 'tampered archive');
        $this->expectExceptionMessage('SOURCE_ARTIFACT_ARCHIVE_HASH_MISMATCH');
        $this->owner->verifySourceArtifact($reference);
    }

    public function test_changed_source_entry_rejects_even_with_a_rehashed_transport_label(): void
    {
        $reference = $this->owner->buildSourceArtifact()['reference'];
        $poison = str_repeat('x', strlen(File::get($this->root.'/backend-laravel/app/Example.php')));
        $reference = $this->mutateZip($reference, fn ($zip) => $zip->addFromString('backend-laravel/app/Example.php', $poison));
        $this->expectExceptionMessage('SOURCE_ARTIFACT_SOURCE_HASH_MISMATCH');
        $this->owner->verifySourceArtifact($reference);
    }

    public function test_missing_source_entry_rejects_with_rehashed_archive_and_original_manifest(): void
    {
        $reference = $this->owner->buildSourceArtifact()['reference'];
        $reference = $this->mutateZip($reference, fn ($zip) => $zip->deleteName('backend-laravel/app/Example.php'));
        $this->expectExceptionMessage('SOURCE_ARTIFACT_FILE_SET_MISMATCH');
        $this->owner->verifySourceArtifact($reference);
    }

    public function test_path_escape_archive_entry_rejects_before_any_extraction_or_content_claim(): void
    {
        $reference = $this->owner->buildSourceArtifact()['reference'];
        $reference = $this->mutateZip($reference, fn ($zip) => $zip->addFromString('../.env', 'never extract'));
        $this->expectExceptionMessage('SOURCE_ARTIFACT_ENTRY_PATH_INVALID');
        $this->owner->verifySourceArtifact($reference);
    }

    public function test_path_escape_reference_is_not_resolved_or_used(): void
    {
        $reference = $this->owner->buildSourceArtifact()['reference'];
        $reference['archive_path'] = '../secret.zip';
        $this->expectExceptionMessage('SOURCE_ARTIFACT_REFERENCE_INVALID');
        $this->owner->verifySourceArtifact($reference);
    }

    public function test_archive_symlink_entry_is_not_a_safe_regular_source_even_with_same_bytes(): void
    {
        $reference = $this->owner->buildSourceArtifact()['reference'];
        $reference = $this->mutateZip($reference, fn ($zip) => $zip->setExternalAttributesName(
            'backend-laravel/app/Example.php', ZipArchive::OPSYS_UNIX, 0120777 << 16), false);
        $this->expectExceptionMessage('SOURCE_ARTIFACT_ENTRY_TYPE_INVALID');
        $this->owner->verifySourceArtifact($reference);
    }

    public function test_missing_archive_is_not_reported_as_a_reproducible_source_artifact(): void
    {
        $reference = $this->owner->buildSourceArtifact()['reference'];
        unlink($this->zipPath($reference));
        $this->expectExceptionMessage('SOURCE_ARTIFACT_ARCHIVE_MISSING');
        $this->owner->verifySourceArtifact($reference);
    }

    public function test_source_change_during_capture_refuses_publication_and_no_current_pointer_exists(): void
    {
        $this->owner->afterCapture = function () { File::put($this->root.'/backend-laravel/scripts/run-ai-service.py', '# changed runtime source after capture'); };
        try { $this->owner->buildSourceArtifact(); $this->fail('Captured drift was published.'); }
        catch (RuntimeException $error) { $this->assertSame('SOURCE_ARTIFACT_SOURCE_CHANGED_DURING_COLLECTION', $error->getMessage()); }
        $this->assertFileDoesNotExist($this->root.'/.runtime/research-source-artifacts/current.json');
    }

    public function test_non_codehash_runtime_script_drift_retires_only_current_pointer_not_old_archive_bytes(): void
    {
        $reference = $this->owner->buildSourceArtifact()['reference'];
        File::put($this->root.'/backend-laravel/scripts/run-ai-service.py', '# new runtime script');
        $this->assertNull($this->owner->currentSourceArtifact());
        $this->assertSame('verified', $this->owner->verifySourceArtifact($reference)['status']);
        $this->expectExceptionMessage('SOURCE_ARTIFACT_SOURCE_DRIFT');
        $this->owner->verifySourceArtifact($reference, true);
    }

    public function test_manifest_cannot_substitute_a_fabricated_full_or_python_source_identity(): void
    {
        $reference = $this->owner->buildSourceArtifact()['reference'];
        $reference['python_source_hash'] = str_repeat('a', 64);
        $this->expectExceptionMessage('SOURCE_ARTIFACT_RUNTIME_IDENTITY_MISMATCH');
        $this->owner->verifySourceArtifact($reference);
    }

    public function test_authority_registry_change_retires_current_pointer_without_rewriting_the_original_archive(): void
    {
        $reference = $this->owner->buildSourceArtifact()['reference'];
        config()->set('services.research_paper_epochs.authorized_paper_epochs', [['authorization_id' => 'different-policy']]);
        $this->assertNull($this->owner->currentSourceArtifact());
        $this->assertSame('verified', $this->owner->verifySourceArtifact($reference)['status']);
        $this->expectExceptionMessage('SOURCE_ARTIFACT_SOURCE_DRIFT');
        $this->owner->verifySourceArtifact($reference, true);
    }

    public function test_only_a_new_prospective_seal_can_reference_the_verified_archive_and_old_seal_is_not_backfilled(): void
    {
        $reference = $this->owner->buildSourceArtifact()['reference'];
        $evidence = Mockery::mock(LabImmutableEvidenceService::class)->makePartial();
        $evidence->shouldReceive('codeHash')->andReturn($reference['source_hash']);
        app()->instance(LabImmutableEvidenceService::class, $evidence);
        $laboratory = AiLaboratory::create(['name' => 'Source artifact fixture', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $fresh = LabGeneration::create(['ai_laboratory_id' => $laboratory->id, 'generation' => 1, 'status' => 'draft',
            'trigger_type' => 'test', 'population_size' => 0, 'trigger_context' => ['mtf_bundle_hash' => str_repeat('b', 64)]]);
        $seal = $this->owner->seal($fresh)->trigger_context['research_release'];
        $this->assertSame($reference, $seal['source_artifact']);
        $this->assertSame($reference['source_hash'], $seal['source_hash']);
        $legacy = $seal;
        unset($legacy['source_artifact']);
        $identity = $legacy;
        unset($identity['release_hash'], $identity['sealed_at'], $identity['promotion_evidence']);
        $legacy['release_hash'] = app(\App\Services\ExecutionContractService::class)->hashParameters($identity);
        $fresh->update(['trigger_context' => ['research_release' => $legacy, 'mtf_bundle_hash' => str_repeat('b', 64)]]);
        $this->assertSame($legacy, $this->owner->seal($fresh->fresh())->trigger_context['research_release']);
    }

    private function methodSupplementPaths(): array
    {
        $paths = self::METHOD_SUPPLEMENT_SOFTWARE_FILES;
        foreach (self::METHOD_SUPPLEMENT_DECLARATIONS as $directory => $kinds) {
            foreach ($kinds as $kind) $paths[] = 'backend-laravel/resources/research/instrument-methods/'.$directory.'/'.$kind.'.json';
        }
        return $paths;
    }

    private function zipPath(array $reference): string { return $this->root.'/'.$reference['archive_path']; }
    private function mutateZip(array $reference, callable $mutation, bool $regularEntries = true): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->zipPath($reference)));
        $mutation($zip);
        if ($regularEntries) for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (is_string($name)) { $zip->setCompressionName($name, ZipArchive::CM_STORE);
                $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16); }
        }
        $zip->close();
        $reference['archive_sha256'] = hash_file('sha256', $this->zipPath($reference));
        return $reference;
    }
}
