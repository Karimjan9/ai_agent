<?php

namespace App\Services;

use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

/** One prospective source/data/cost identity, never retroactive release proof. */
class ResearchReleaseSealService
{
    public const PROTOCOL = 'prospective_research_release_v1';

    public const SOURCE_ARTIFACT_PROTOCOL = 'research_source_artifact_v1';
    private const SOURCE_ARTIFACT_DIRECTORY = '.runtime/research-source-artifacts';
    private const SOURCE_ARTIFACT_MAX_BYTES = 268435456;
    private const SOURCE_ARTIFACT_MAX_FILES = 5000;
    private const SOURCE_ARTIFACT_FILES = [
        'backend-laravel/artisan', 'backend-laravel/bootstrap/app.php', 'backend-laravel/bootstrap/providers.php',
        'backend-laravel/composer.json', 'backend-laravel/composer.lock', 'backend-laravel/package.json',
        'backend-laravel/package-lock.json', 'backend-laravel/phpunit.xml', 'backend-laravel/ecosystem.config.cjs',
        'backend-laravel/config/redis-local.conf', 'ai-service-python/requirements.txt',
        'ai-service-python/pyproject.toml', 'ai-service-python/poetry.lock',
        'backend-laravel/scripts/audit-research-release.php', 'backend-laravel/scripts/pm2-clean-env.mjs',
        'backend-laravel/scripts/pm2-sync-runtime.mjs', 'backend-laravel/scripts/pm2-summary.cjs',
        'backend-laravel/scripts/pm2-summary.test.cjs',
        'backend-laravel/scripts/pm2-sync-runtime.test.cjs',
        'backend-laravel/scripts/supervise-laravel-runtime.ps1', 'backend-laravel/scripts/install-autonomous-runtime-task.ps1',
        'backend-laravel/scripts/run-laravel-workers.cmd', 'backend-laravel/scripts/run-laravel-workers-hidden.ps1',
        'backend-laravel/scripts/run-laravel-workers-hidden.vbs', 'backend-laravel/scripts/run-laravel-server.cmd',
        'backend-laravel/scripts/run-ai-service.cmd', 'backend-laravel/scripts/run-ai-service.py',
        'backend-laravel/scripts/start-redis.ps1', 'ai-service-python/scripts/maintain_replay_cache.py',
        'backend-laravel/scripts/recover-frozen-m5-gap.php', 'backend-laravel/scripts/verify-sparse-reopen-ticks.cjs',
        'backend-laravel/scripts/decode-dukascopy-tick-hour.py',
        'backend-laravel/scripts/decode-dukascopy-tick-hour.test.py',
        'backend-laravel/scripts/recover-secondary-m5-research.php',
        'backend-laravel/scripts/verify-sparse-reopen-ticks.test.cjs',
        'backend-laravel/scripts/freeze-historical-tick-spread.cjs', 'backend-laravel/scripts/freeze-historical-tick-spread.test.cjs',
        'backend-laravel/scripts/audit-frozen-m5-gap-source.py',
        'backend-laravel/tests/Feature/FrozenM5GapRecoveryTest.php',
        'backend-laravel/tests/Feature/ProspectiveCleanDiscoverySnapshotTest.php',
        'backend-laravel/tests/Feature/SecondaryM5ResearchRecoveryTest.php',
        'backend-laravel/tests/Feature/AcademyCleanDiscoveryHandoffTest.php',
        'backend-laravel/tests/Feature/AcademyMtfValidatorReplacementTest.php',
        'backend-laravel/tests/Feature/AcademyColdStartHandoffTest.php',
        'backend-laravel/tests/Feature/ProspectiveM5ContinuityHandoffTest.php',
        'backend-laravel/tests/Feature/HistoricalDataQualityDispositionTest.php',
        'backend-laravel/tests/Unit/TechnicalFailureClassifierServiceTest.php',
        'backend-laravel/tests/TestCase.php', 'backend-laravel/tests/Feature/ResearchSourceArtifactTest.php',
        'backend-laravel/tests/Feature/EvolutionAuditRegressionTest.php', 'backend-laravel/tests/Feature/ReleaseSealTest.php',
        'backend-laravel/tests/Feature/RuntimeReloadPreflightTest.php', 'backend-laravel/tests/Feature/AcademyCanonicalHandoffTest.php',
        'backend-laravel/tests/Feature/NativeSpecialistCouncilConstructorTest.php',
        'backend-laravel/tests/Feature/AuthorizedSpecialistCouncilConstructorTest.php',
        'backend-laravel/tests/Feature/ResearchWorkLeaseBudgetTest.php',
        'backend-laravel/tests/Feature/SpecialistCouncilFollowupExecutionTest.php',
        'backend-laravel/tests/Feature/SpecialistCouncilFollowupReadinessTest.php',
        'backend-laravel/tests/Feature/SpecialistCouncilPanelReservationTest.php',
        'backend-laravel/tests/Feature/NativeDecisionTraceCompletenessTest.php',
        'backend-laravel/tests/Feature/SpecialistCouncilDescendantProducerTest.php',
        'backend-laravel/tests/Feature/SpecialistCouncilHistoricalProofTest.php',
        'backend-laravel/tests/Feature/SpecialistCouncilSupportRoleTest.php',
        'backend-laravel/tests/Feature/QualifiedResearchPolicyConsumptionTest.php',
        'backend-laravel/tests/Feature/NativePolicyPhysicalExposureTest.php',
        'backend-laravel/tests/Feature/ScreeningExecutionDefinitionTest.php',
        'backend-laravel/tests/Feature/SpecialistCouncilPreparationTest.php',
        'backend-laravel/tests/Feature/SpecialistCouncilObservedProbeCompletionTest.php',
        'backend-laravel/tests/Feature/SpecialistCouncilObservedProjectionGuardTest.php',
        'backend-laravel/tests/Feature/ResearchLoopArbiterTest.php',
        'backend-laravel/tests/Feature/LabReplayRecoveryHashDomainTest.php',
        'backend-laravel/tests/Support/ConditionalQualifiedNativePolicyFixture.php',
        'backend-laravel/tests/Feature/AcademyPrimaryInstrumentSurfaceTest.php',
        'backend-laravel/tests/Feature/AcademyPreparationContainmentTest.php',
        'backend-laravel/tests/Feature/ResearchPaperEpochContractTest.php',
        'backend-laravel/tests/Feature/PostPaperConfirmedTraitAdmissionTest.php',
        'backend-laravel/tests/Support/InstrumentValidationFixture.php',
        'backend-laravel/tests/Feature/InstrumentResearchWindowAuthorityTest.php',
        'backend-laravel/tests/Feature/AuthorizedResearchTransportTest.php',
        'backend-laravel/tests/Feature/OriginalModelRuntimeIdentityTest.php',
        'backend-laravel/tests/Feature/ProspectiveRepairExperimentTest.php',
        'backend-laravel/tests/Unit/CausalStageMasteryDirectorServiceTest.php',
        'backend-laravel/tests/Feature/CausalProgressRatchetGovernorServiceTest.php',
        'backend-laravel/tests/Support/academy_confirmation_source.json',
        'ai-service-python/tests/test_research_release.py', 'ai-service-python/tests/test_sealed_dataset_identity.py',
        'ai-service-python/tests/test_clean_discovery_boundary.py',
        'ai-service-python/tests/test_authorized_research_transport.py',
        'ai-service-python/tests/test_authorized_council_arm.py',
        'ai-service-python/tests/test_specialist_council.py',
        'ai-service-python/tests/support/authorized_council_arm_fixture.py',
        'ai-service-python/tests/support/authorized_research_transport_fixture.py',
        'ai-service-python/tests/test_composition_runtime.py', 'ai-service-python/tests/test_parameter_preserving_composition.py',
        'ai-service-python/tests/test_market_sessions.py', 'ai-service-python/tests/test_prospective_probe_window.py',
        'ai-service-python/tests/support/semantic_stage_receipt_fixture.py',
    ];

    /** Explicit local filesystem action. Never called automatically by seal(). */
    public function buildSourceArtifact(): array
    {
        if (! class_exists(ZipArchive::class)) throw new RuntimeException('SOURCE_ARTIFACT_ZIP_UNAVAILABLE');
        $identity = $this->artifactCurrentIdentity();
        $git = $this->artifactGitProvenance();
        $settings = $this->artifactEffectiveSettings();
        $paths = $this->artifactSourcePaths();
        $contents = $files = [];
        $total = 0;
        foreach ($paths as $path) {
            $sourcePath = $this->artifactSourcePath($path);
            if (filesize($sourcePath) > self::SOURCE_ARTIFACT_MAX_BYTES - $total) throw new RuntimeException('SOURCE_ARTIFACT_BYTE_LIMIT');
            $bytes = file_get_contents($sourcePath);
            if ($bytes === false) throw new RuntimeException('SOURCE_ARTIFACT_SOURCE_UNREADABLE');
            $total += strlen($bytes);
            if ($total > self::SOURCE_ARTIFACT_MAX_BYTES) throw new RuntimeException('SOURCE_ARTIFACT_BYTE_LIMIT');
            $contents[$path] = $bytes;
            $files[$path] = ['sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)];
        }
        if ($identity !== $this->artifactIdentityFromFiles($files, $identity['declared_commit'])) {
            throw new RuntimeException('SOURCE_ARTIFACT_RUNTIME_IDENTITY_MISMATCH');
        }
        $manifest = ['protocol' => self::SOURCE_ARTIFACT_PROTOCOL, 'source_identity' => $identity,
            'files' => $files, 'git' => $git, 'tools' => $this->artifactToolVersions(),
            'effective_non_secret_settings' => $settings, 'archive_format' => 'zip_stored_fixed_time_v1',
            'file_count' => count($files), 'source_bytes' => $total,
            'worker_loaded_code_attested' => false, 'scientific_evidence' => false, 'promotion_evidence' => false];
        $this->assertArtifactSourceUnchanged($paths, $files, $identity, $git, $settings);
        $manifestBytes = $this->artifactJson($manifest);
        $address = hash('sha256', $manifestBytes);
        $directory = $this->artifactDirectory();
        File::ensureDirectoryExists($directory);
        $temporary = tempnam($directory, 'building-');
        if ($temporary === false) throw new RuntimeException('SOURCE_ARTIFACT_TEMPORARY_FAILED');
        try {
            $zip = new ZipArchive;
            if ($zip->open($temporary, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('SOURCE_ARTIFACT_ZIP_CREATE_FAILED');
            foreach (['manifest.json' => $manifestBytes, ...$contents] as $path => $bytes) {
                if (! $zip->addFromString($path, $bytes)
                    || ! $zip->setCompressionName($path, ZipArchive::CM_STORE)
                    || ! $zip->setMtimeName($path, 315532800)
                    || ! $zip->setExternalAttributesName($path, ZipArchive::OPSYS_UNIX, 0100644 << 16)) {
                    throw new RuntimeException('SOURCE_ARTIFACT_ZIP_ENTRY_FAILED');
                }
            }
            if (! $zip->close()) throw new RuntimeException('SOURCE_ARTIFACT_ZIP_WRITE_FAILED');
            $reference = ['protocol' => 'research_source_artifact_reference_v1', 'artifact_hash' => $address,
                'archive_sha256' => hash_file('sha256', $temporary),
                'archive_path' => self::SOURCE_ARTIFACT_DIRECTORY.'/'.$address.'.zip',
                'source_hash' => $identity['source_hash'], 'python_source_hash' => $identity['python_source_hash']];
            ksort($reference);
            $this->assertArtifactSourceUnchanged($paths, $files, $identity, $git, $settings);
            $target = $directory.'/'.$address.'.zip';
            if (is_file($target)) {
                if (! hash_equals($reference['archive_sha256'], (string) hash_file('sha256', $target))) {
                    throw new RuntimeException('SOURCE_ARTIFACT_ADDRESS_ALREADY_CORRUPT');
                }
            } elseif (! rename($temporary, $target)) {
                throw new RuntimeException('SOURCE_ARTIFACT_PUBLISH_FAILED');
            }
            $verified = $this->verifySourceArtifact($reference, true);
            $this->publishArtifactReference($directory.'/'.$address.'.json', $reference, false);
            $this->publishArtifactReference($directory.'/current.json', $reference, true);
            return ['status' => 'verified', 'reference' => $reference, 'manifest' => $verified['manifest'],
                'git_commit_sealed' => ! $git['dirty'], 'worker_loaded_code_attested' => false,
                'scientific_evidence' => false, 'promotion_evidence' => false];
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }

    /** Verify archive bytes, every entry, content address and both identity domains. No extraction. */
    public function verifySourceArtifact(array $reference, bool $requireCurrent = false): array
    {
        $address = (string) ($reference['artifact_hash'] ?? '');
        if (($reference['protocol'] ?? null) !== 'research_source_artifact_reference_v1'
            || ! preg_match('/^[a-f0-9]{64}$/D', $address)
            || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($reference['archive_sha256'] ?? ''))
            || ($reference['archive_path'] ?? null) !== self::SOURCE_ARTIFACT_DIRECTORY.'/'.$address.'.zip') {
            throw new RuntimeException('SOURCE_ARTIFACT_REFERENCE_INVALID');
        }
        $path = $this->artifactDirectory().'/'.$address.'.zip';
        if (! is_file($path)) throw new RuntimeException('SOURCE_ARTIFACT_ARCHIVE_MISSING');
        if (filesize($path) > self::SOURCE_ARTIFACT_MAX_BYTES + 8388608) throw new RuntimeException('SOURCE_ARTIFACT_BYTE_LIMIT');
        if (is_link($path) || ! hash_equals($reference['archive_sha256'], (string) hash_file('sha256', $path))) {
            throw new RuntimeException('SOURCE_ARTIFACT_ARCHIVE_HASH_MISMATCH');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) throw new RuntimeException('SOURCE_ARTIFACT_ARCHIVE_INVALID');
        try {
            if ($zip->numFiles > self::SOURCE_ARTIFACT_MAX_FILES + 1) throw new RuntimeException('SOURCE_ARTIFACT_FILE_LIMIT');
            $seen = [];
            foreach (range(0, $zip->numFiles - 1) as $index) {
                $name = $zip->getNameIndex($index);
                if (! is_string($name) || isset($seen[$name]) || ($name !== 'manifest.json' && ! $this->artifactAllowedPath($name))) {
                    throw new RuntimeException('SOURCE_ARTIFACT_ENTRY_PATH_INVALID');
                }
                $stat = $zip->statIndex($index);
                if (! is_array($stat) || $stat['size'] > self::SOURCE_ARTIFACT_MAX_BYTES
                    || ($name === 'manifest.json' && $stat['size'] > 4194304)
                    || $stat['comp_method'] !== ZipArchive::CM_STORE
                    || ! $zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)
                    || $operatingSystem !== ZipArchive::OPSYS_UNIX || (($attributes >> 16) & 0170000) !== 0100000) {
                    throw new RuntimeException('SOURCE_ARTIFACT_ENTRY_TYPE_INVALID');
                }
                $seen[$name] = true;
            }
            $rawManifest = $zip->getFromName('manifest.json');
            if (! is_string($rawManifest) || strlen($rawManifest) > 4194304) throw new RuntimeException('SOURCE_ARTIFACT_MANIFEST_MISSING');
            $manifest = json_decode($rawManifest, true, 512, JSON_THROW_ON_ERROR);
            if (($manifest['protocol'] ?? null) !== self::SOURCE_ARTIFACT_PROTOCOL
                || ! hash_equals($address, hash('sha256', $this->artifactJson($manifest)))
                || $rawManifest !== $this->artifactJson($manifest)
                || ($manifest['scientific_evidence'] ?? null) !== false
                || ($manifest['worker_loaded_code_attested'] ?? null) !== false
                || ($manifest['promotion_evidence'] ?? null) !== false) {
                throw new RuntimeException('SOURCE_ARTIFACT_MANIFEST_INVALID');
            }
            $files = $manifest['files'] ?? [];
            if (! is_array($files) || $files === [] || count($files) + 1 !== $zip->numFiles
                || count($files) !== ($manifest['file_count'] ?? null)) throw new RuntimeException('SOURCE_ARTIFACT_FILE_SET_MISMATCH');
            $total = 0;
            foreach ($files as $name => $expected) {
                if (! $this->artifactAllowedPath($name) || ! isset($seen[$name])) throw new RuntimeException('SOURCE_ARTIFACT_SOURCE_FILE_MISSING');
                $stat = $zip->statName($name);
                if (! is_array($expected) || ! is_int($expected['bytes'] ?? null) || $expected['bytes'] < 0
                    || $expected['bytes'] > self::SOURCE_ARTIFACT_MAX_BYTES
                    || ! is_array($stat) || $stat['size'] !== $expected['bytes']) throw new RuntimeException('SOURCE_ARTIFACT_SOURCE_SIZE_MISMATCH');
                $total += $expected['bytes'];
                if ($total > self::SOURCE_ARTIFACT_MAX_BYTES) throw new RuntimeException('SOURCE_ARTIFACT_BYTE_LIMIT');
                $bytes = $zip->getFromName($name);
                if (! is_string($bytes) || ! hash_equals((string) ($expected['sha256'] ?? ''), hash('sha256', $bytes))) {
                    throw new RuntimeException('SOURCE_ARTIFACT_SOURCE_HASH_MISMATCH');
                }
            }
            $identity = $manifest['source_identity'] ?? [];
            if (! is_array($identity) || ! is_string($identity['php_version'] ?? null)
                || $this->artifactJson($identity) !== $this->artifactJson($this->artifactIdentityFromFiles($files, $identity['declared_commit'] ?? null, $identity['php_version']))
                || ($identity['source_hash'] ?? null) !== ($reference['source_hash'] ?? null)
                || ($identity['python_source_hash'] ?? null) !== ($reference['python_source_hash'] ?? null)
                || $total !== ($manifest['source_bytes'] ?? null)) throw new RuntimeException('SOURCE_ARTIFACT_RUNTIME_IDENTITY_MISMATCH');
            if ($requireCurrent && ($this->artifactJson($identity) !== $this->artifactJson($this->artifactCurrentIdentity())
                || $manifest['effective_non_secret_settings'] !== $this->artifactEffectiveSettings()
                || array_keys($files) !== $this->artifactSourcePaths())) {
                throw new RuntimeException('SOURCE_ARTIFACT_SOURCE_DRIFT');
            }
            if ($requireCurrent) foreach ($files as $name => $expected) {
                if (! hash_equals($expected['sha256'], (string) hash_file('sha256', $this->artifactSourcePath($name)))) {
                    throw new RuntimeException('SOURCE_ARTIFACT_SOURCE_DRIFT');
                }
            }
            return ['status' => 'verified', 'artifact_hash' => $address, 'manifest' => $manifest,
                'worker_loaded_code_attested' => false, 'scientific_evidence' => false, 'promotion_evidence' => false];
        } finally { $zip->close(); }
    }

    /**
     * Live byte fence for an already fully verified archive. This is not an
     * archive verification and cannot establish trust on its own: it only
     * permits one held invocation to retain its original full proof while
     * both original reference bytes and actual ZIP bytes remain unchanged.
     */
    public function sourceArtifactByteFence(array $reference): array
    {
        $address = (string) ($reference['artifact_hash'] ?? '');
        if (($reference['protocol'] ?? null) !== 'research_source_artifact_reference_v1'
            || ! preg_match('/^[a-f0-9]{64}$/D', $address)
            || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($reference['archive_sha256'] ?? ''))
            || ($reference['archive_path'] ?? null) !== self::SOURCE_ARTIFACT_DIRECTORY.'/'.$address.'.zip') {
            throw new RuntimeException('SOURCE_ARTIFACT_REFERENCE_INVALID');
        }
        $directory = $this->artifactDirectory();
        $referencePath = $directory.'/'.$address.'.json';
        $archivePath = $directory.'/'.$address.'.zip';
        clearstatcache(true, $referencePath);
        clearstatcache(true, $archivePath);
        if (! is_file($referencePath) || is_link($referencePath)) throw new RuntimeException('SOURCE_ARTIFACT_REFERENCE_MISSING');
        if (filesize($referencePath) > 4194304) throw new RuntimeException('SOURCE_ARTIFACT_REFERENCE_INVALID');
        $referenceBytes = file_get_contents($referencePath);
        if (! is_string($referenceBytes)
            || $this->artifactJson(json_decode($referenceBytes, true, 512, JSON_THROW_ON_ERROR)) !== $this->artifactJson($reference)) {
            throw new RuntimeException('SOURCE_ARTIFACT_REFERENCE_INVALID');
        }
        if (! is_file($archivePath)) throw new RuntimeException('SOURCE_ARTIFACT_ARCHIVE_MISSING');
        if (filesize($archivePath) > self::SOURCE_ARTIFACT_MAX_BYTES + 8388608) throw new RuntimeException('SOURCE_ARTIFACT_BYTE_LIMIT');
        if (is_link($archivePath) || ! hash_equals($reference['archive_sha256'], (string) hash_file('sha256', $archivePath))) {
            throw new RuntimeException('SOURCE_ARTIFACT_ARCHIVE_HASH_MISMATCH');
        }
        return ['artifact_hash' => $address, 'reference_sha256' => hash('sha256', $referenceBytes),
            'archive_sha256' => $reference['archive_sha256']];
    }

    public function sourceArtifactReference(string $address): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $address)) throw new RuntimeException('SOURCE_ARTIFACT_ADDRESS_INVALID');
        $path = $this->artifactDirectory().'/'.$address.'.json';
        if (! is_file($path) || is_link($path)) throw new RuntimeException('SOURCE_ARTIFACT_REFERENCE_MISSING');
        $reference = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($reference['artifact_hash'] ?? null) !== $address) throw new RuntimeException('SOURCE_ARTIFACT_REFERENCE_INVALID');
        $this->verifySourceArtifact($reference);
        return $reference;
    }

    /** Bounded discovery only; addresses are not proof until sourceArtifactReference verifies them. */
    public function retainedSourceArtifactAddresses(int $limit = 128): array
    {
        if ($limit < 1 || $limit > 128) throw new RuntimeException('SOURCE_ARTIFACT_REFERENCE_LOOKUP_LIMIT_INVALID');
        $directory = $this->artifactDirectory();
        if (! is_dir($directory)) return [];
        $addresses = [];
        foreach (new \DirectoryIterator($directory) as $entry) {
            if (! preg_match('/^([a-f0-9]{64})\.json$/D', $entry->getFilename(), $match)) continue;
            if ($entry->isLink() || ! $entry->isFile()) throw new RuntimeException('SOURCE_ARTIFACT_REFERENCE_INVALID');
            $addresses[] = $match[1];
            if (count($addresses) > $limit) throw new RuntimeException('SOURCE_ARTIFACT_REFERENCE_LOOKUP_BUDGET_EXCEEDED');
        }
        sort($addresses, SORT_STRING);
        return $addresses;
    }

    public function currentSourceArtifact(): ?array
    {
        $path = $this->artifactDirectory().'/current.json';
        if (! is_file($path)) return null;
        if (is_link($path)) throw new RuntimeException('SOURCE_ARTIFACT_REFERENCE_INVALID');
        $reference = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->verifySourceArtifact($reference);
        try { $this->verifySourceArtifact($reference, true); }
        catch (RuntimeException $error) {
            if ($error->getMessage() === 'SOURCE_ARTIFACT_SOURCE_DRIFT') return null;
            throw $error;
        }
        return $reference;
    }

    protected function artifactProjectRoot(): string { return dirname(base_path()); }

    private function artifactDirectory(): string
    {
        $root = realpath($this->artifactProjectRoot());
        if ($root === false) throw new RuntimeException('SOURCE_ARTIFACT_ROOT_MISSING');
        foreach (['.runtime', self::SOURCE_ARTIFACT_DIRECTORY] as $relative) {
            $directory = $root.'/'.$relative;
            if (is_link($directory) || (is_dir($directory) && str_replace('\\', '/', (string) realpath($directory)) !== str_replace('\\', '/', $directory))) {
                throw new RuntimeException('SOURCE_ARTIFACT_DIRECTORY_ESCAPE');
            }
        }
        return $root.'/'.self::SOURCE_ARTIFACT_DIRECTORY;
    }

    private function artifactAllowedPath(string $path): bool
    {
        if (str_contains($path, '\\') || str_contains($path, ':') || str_contains($path, "\0") || str_starts_with($path, '/')
            || preg_match('~(^|/)(\.{1,2}|\.env[^/]*|vendor|node_modules|storage|\.runtime|data|datasets|tokens|credentials)(/|$)~i', $path)) return false;
        return in_array($path, self::SOURCE_ARTIFACT_FILES, true)
            || preg_match('~^(backend-laravel/(app|config|routes|database/migrations)/.*\.php|ai-service-python/app/.*\.py)$~D', $path) === 1;
    }

    private function artifactSourcePath(string $relative): string
    {
        if (! $this->artifactAllowedPath($relative)) throw new RuntimeException('SOURCE_ARTIFACT_ENTRY_PATH_INVALID');
        $root = str_replace('\\', '/', (string) realpath($this->artifactProjectRoot()));
        $requested = $root.'/'.$relative;
        $actual = realpath($requested);
        if (is_link($requested) || $actual === false || ! is_file($actual)
            || str_replace('\\', '/', $actual) !== $requested) throw new RuntimeException('SOURCE_ARTIFACT_SOURCE_PATH_ESCAPE');
        return $actual;
    }

    protected function artifactSourcePaths(): array
    {
        $root = $this->artifactProjectRoot();
        $paths = [];
        foreach (['backend-laravel/app', 'backend-laravel/config', 'backend-laravel/routes', 'backend-laravel/database/migrations', 'ai-service-python/app'] as $directory) {
            if (! is_dir($root.'/'.$directory)) continue;
            foreach (File::allFiles($root.'/'.$directory) as $file) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if ($this->artifactAllowedPath($relative)) $paths[] = $relative;
            }
        }
        foreach (self::SOURCE_ARTIFACT_FILES as $relative) if (is_file($root.'/'.$relative)) $paths[] = $relative;
        $paths = array_values(array_unique($paths));
        sort($paths);
        if ($paths === [] || count($paths) > self::SOURCE_ARTIFACT_MAX_FILES) throw new RuntimeException('SOURCE_ARTIFACT_FILE_LIMIT');
        return $paths;
    }

    protected function artifactCurrentIdentity(): array
    {
        $commit = env('APP_COMMIT_SHA');
        if ($commit !== null && $commit !== '' && (! is_string($commit) || ! preg_match('/^[a-f0-9]{40,64}$/iD', $commit))) {
            throw new RuntimeException('SOURCE_ARTIFACT_DECLARED_COMMIT_INVALID');
        }
        return ['source_hash' => app(LabImmutableEvidenceService::class)->codeHash(),
            'python_source_hash' => $this->pythonHash(), 'php_version' => PHP_VERSION, 'declared_commit' => $commit];
    }

    protected function artifactIdentityFromFiles(array $files, ?string $commit, ?string $phpVersion = null): array
    {
        $phpVersion ??= PHP_VERSION;
        $runtime = $python = [];
        foreach ($files as $path => $record) {
            if (preg_match('~^(backend-laravel/(app|config)/.*\.php|ai-service-python/app/.*\.py)$~D', $path)
                || in_array($path, ['backend-laravel/composer.lock', 'backend-laravel/package-lock.json', 'ai-service-python/requirements.txt', 'ai-service-python/pyproject.toml'], true)) {
                $runtime[$path] = $record['sha256'];
            }
            if (str_starts_with($path, 'ai-service-python/app/') || in_array($path,
                ['ai-service-python/requirements.txt', 'ai-service-python/pyproject.toml', 'ai-service-python/poetry.lock'], true)) {
                $python[substr($path, strlen('ai-service-python/'))] = $record['sha256'];
            }
        }
        ksort($runtime); ksort($python);
        return ['source_hash' => app(LabImmutableEvidenceService::class)->hash([
            'protocol' => 'full_runtime_dependency_fingerprint_v3', 'files' => $runtime, 'php' => $phpVersion, 'commit' => $commit]),
            'python_source_hash' => hash('sha256', json_encode($python, JSON_UNESCAPED_SLASHES)),
            'php_version' => $phpVersion, 'declared_commit' => $commit];
    }

    protected function artifactGitProvenance(): array
    {
        $head = new Process(['git', 'rev-parse', 'HEAD'], $this->artifactProjectRoot());
        $status = new Process(['git', 'status', '--porcelain=v1', '--untracked-files=all', '-z'], $this->artifactProjectRoot());
        $head->setEnv(['GIT_OPTIONAL_LOCKS' => '0']); $status->setEnv(['GIT_OPTIONAL_LOCKS' => '0']);
        $head->setTimeout(10); $status->setTimeout(10); $head->run(); $status->run();
        $commit = trim($head->getOutput());
        if (! $head->isSuccessful() || ! $status->isSuccessful() || ! preg_match('/^[a-f0-9]{40,64}$/iD', $commit)) {
            throw new RuntimeException('SOURCE_ARTIFACT_GIT_PROVENANCE_UNAVAILABLE');
        }
        return ['head' => $commit, 'dirty' => $status->getOutput() !== '',
            'porcelain_sha256' => hash('sha256', $status->getOutput()), 'commit_created_by_build' => false];
    }

    protected function artifactEffectiveSettings(): array
    {
        $keys = ['queue.default', 'services.lab_queue.screening_queue', 'services.lab_queue.full_validation_queue',
            'services.lab_queue.frontier_queue', 'services.lab_queue.screening_batch_size',
            'services.lab_selection.full_replay_timeout_seconds', 'services.lab_selection.causal_replay_timeout_seconds',
            'services.lab_selection.screen_timeout_seconds', 'services.lab_selection.training_end_exclusive',
            'services.lab_selection.minimum_screening_trades', 'services.instrument_policy.minimum_independent_windows',
            'services.xauusd_organism.historical_research_until_champion', 'services.xauusd_organism.research_m5_dataset'];
        $values = [];
        foreach ($keys as $key) { $value = config($key); if (is_scalar($value) || $value === null) $values[$key] = $value; }
        // Bind the non-secret authority-policy registries as digests too:
        // source bytes alone cannot attest a later environment policy change.
        foreach (['services.research_paper_epochs.authorized_paper_epochs',
            'services.instrument_policy.authorized_research_windows'] as $key) {
            $values[$key.'_sha256'] = hash('sha256', $this->artifactJson((array) config($key, [])));
        }
        ksort($values);
        return $values;
    }

    protected function artifactToolVersions(): array
    {
        $versions = ['php' => PHP_VERSION, 'zip' => defined('ZipArchive::LIBZIP_VERSION') ? ZipArchive::LIBZIP_VERSION : 'available'];
        foreach (['python_cli' => ['python', '--version'], 'node_cli' => ['node', '--version'], 'git_cli' => ['git', '--version']] as $name => $command) {
            try { $process = new Process($command, $this->artifactProjectRoot()); $process->setTimeout(5); $process->run();
                $versions[$name] = $process->isSuccessful() ? trim($process->getOutput().$process->getErrorOutput()) : 'unavailable';
            } catch (\Throwable) { $versions[$name] = 'unavailable'; }
        }
        ksort($versions);
        return $versions;
    }

    private function assertArtifactSourceUnchanged(array $paths, array $files, array $identity, array $git, array $settings): void
    {
        if ($paths !== $this->artifactSourcePaths() || $identity !== $this->artifactCurrentIdentity()
            || $git !== $this->artifactGitProvenance() || $settings !== $this->artifactEffectiveSettings()) {
            throw new RuntimeException('SOURCE_ARTIFACT_SOURCE_CHANGED_DURING_COLLECTION');
        }
        foreach ($files as $path => $record) if (! hash_equals($record['sha256'], (string) hash_file('sha256', $this->artifactSourcePath($path)))) {
            throw new RuntimeException('SOURCE_ARTIFACT_SOURCE_CHANGED_DURING_COLLECTION');
        }
    }

    private function artifactJson(array $value): string
    {
        $sort = function ($item) use (&$sort) { if (! is_array($item)) return $item;
            if (! array_is_list($item)) ksort($item); return array_map($sort, $item); };
        return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function publishArtifactReference(string $path, array $reference, bool $replace): void
    {
        $bytes = $this->artifactJson($reference);
        if (is_file($path) && ! $replace) {
            if (is_link($path) || (string) file_get_contents($path) !== $bytes) throw new RuntimeException('SOURCE_ARTIFACT_REFERENCE_ALREADY_CORRUPT');
            return;
        }
        if (is_link($path)) throw new RuntimeException('SOURCE_ARTIFACT_REFERENCE_INVALID');
        $temporary = tempnam(dirname($path), 'reference-');
        try {
            if ($temporary === false || file_put_contents($temporary, $bytes) === false || ! rename($temporary, $path)) {
                throw new RuntimeException('SOURCE_ARTIFACT_REFERENCE_PUBLISH_FAILED');
            }
        } finally { if (is_string($temporary) && is_file($temporary)) unlink($temporary); }
    }

    public const TERMINAL_DRIFT_CODES = [
        'RESEARCH_RELEASE_SOURCE_DRIFT',
        'RESEARCH_RELEASE_EXECUTION_DRIFT',
        'RESEARCH_RELEASE_DATASET_DRIFT',
    ];

    public static function isTerminalDrift(\Throwable $error): bool
    {
        return $error instanceof RuntimeException
            && in_array($error->getMessage(), self::TERMINAL_DRIFT_CODES, true);
    }

    public function pythonHash(): string
    {
        $root = dirname(base_path()).'/ai-service-python';
        $parts = [];
        foreach (File::allFiles($root.'/app') as $file) {
            if ($file->getExtension() === 'py') {
                $parts[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = hash_file('sha256', $file->getPathname());
            }
        }
        foreach (['requirements.txt', 'pyproject.toml', 'poetry.lock'] as $name) {
            if (is_file($root.'/'.$name)) $parts[$name] = hash_file('sha256', $root.'/'.$name);
        }
        ksort($parts);

        return hash('sha256', json_encode($parts, JSON_UNESCAPED_SLASHES));
    }

    public function seal(LabGeneration $generation): LabGeneration
    {
        return DB::transaction(function () use ($generation): LabGeneration {
            $generation = LabGeneration::query()->lockForUpdate()->findOrFail($generation->id);
            if (data_get($generation->trigger_context, 'research_release') !== null) {
                $this->assertCurrent($generation);
                return $generation;
            }
            // Existing attempts belong to historical evidence. A retry cannot
            // pin today's source onto yesterday's experiment.
            if (LabEvaluationRun::where('lab_generation_id', $generation->id)->exists()) return $generation;
            $generation->load('agents.modelVersion');
            $costs = [];
            foreach ($generation->agents as $agent) {
                $costs[(string) $agent->id] = $this->executionHash(
                    (array) data_get($agent->modelVersion?->metadata, 'execution_contract', []));
            }
            ksort($costs);
            $identity = ['protocol' => self::PROTOCOL,
                'source_hash' => app(LabImmutableEvidenceService::class)->codeHash(),
                'python_source_hash' => $this->pythonHash(),
                'php_version' => PHP_VERSION,
                'dataset_hash' => data_get($generation->trigger_context, 'mtf_bundle_hash',
                    data_get($generation->trigger_context, 'canonical_dataset_snapshots.price.sha256')),
                'agent_execution_hashes' => $costs];
            // An explicitly built matching archive may accompany this new seal.
            // Existing generations are never backfilled, and absence is not proof.
            if ($artifact = $this->currentSourceArtifact()) $identity['source_artifact'] = $artifact;
            $context = (array) $generation->trigger_context;
            $context['research_release'] = [...$identity, 'release_hash' => app(ExecutionContractService::class)->hashParameters($identity),
                'sealed_at' => now()->utc()->toIso8601String(), 'promotion_evidence' => false];
            $generation->update(['trigger_context' => $context]);

            return $generation;
        });
    }

    public function assertCurrent(LabGeneration $generation): void
    {
        $seal = (array) data_get($generation->trigger_context, 'research_release', []);
        if ($seal === []) return; // Explicitly unsealed legacy generation.
        $identity = $seal;
        unset($identity['release_hash'], $identity['sealed_at'], $identity['promotion_evidence']);
        $current = app(LabImmutableEvidenceService::class)->codeHash();
        if (($seal['protocol'] ?? null) !== self::PROTOCOL
            || ! hash_equals((string) ($seal['release_hash'] ?? ''), app(ExecutionContractService::class)->hashParameters($identity))
            || ! hash_equals((string) ($seal['source_hash'] ?? ''), $current)
            || ! hash_equals((string) ($seal['python_source_hash'] ?? ''), $this->pythonHash())) {
            throw new RuntimeException('RESEARCH_RELEASE_SOURCE_DRIFT');
        }
        if (app()->bound('research.worker_boot_source_hash')
            && ! hash_equals((string) app('research.worker_boot_source_hash'), $current)) {
            throw new RuntimeException('RESEARCH_WORKER_LOADED_RELEASE_MISMATCH');
        }
        $generation->loadMissing('agents.modelVersion');
        foreach ($generation->agents as $agent) {
            $actual = $this->executionHash((array) data_get($agent->modelVersion?->metadata, 'execution_contract', []));
            if (! hash_equals((string) data_get($seal, 'agent_execution_hashes.'.$agent->id, ''), $actual)) {
                throw new RuntimeException('RESEARCH_RELEASE_EXECUTION_DRIFT');
            }
        }
        if (! hash_equals((string) ($seal['dataset_hash'] ?? ''), (string) data_get($generation->trigger_context,
            'mtf_bundle_hash', data_get($generation->trigger_context, 'canonical_dataset_snapshots.price.sha256', '')))) {
            throw new RuntimeException('RESEARCH_RELEASE_DATASET_DRIFT');
        }
    }

    public function bindRequest(LabEvaluationRun $run, array $request): array
    {
        $generation = $run->generation;
        if (! $generation) return $request;

        return $this->bindGenerationRequest($generation, $request, [(int) $run->lab_agent_id]);
    }

    public function bindGenerationRequest(LabGeneration $generation, array $request, array $agentIds): array
    {
        $this->assertCurrent($generation);
        $seal = (array) data_get($generation->trigger_context, 'research_release', []);
        if ($seal !== []) {
            if (! hash_equals((string) ($seal['dataset_hash'] ?? ''),
                (string) data_get($request, 'replay_dataset_hash', ''))) {
                throw new RuntimeException('RESEARCH_RELEASE_REQUEST_DATASET_DRIFT');
            }
            foreach ($agentIds as $agentId) {
                if (! hash_equals((string) data_get($seal, 'agent_execution_hashes.'.$agentId, ''),
                    $this->executionHash((array) data_get($request, 'execution_contract', [])))) {
                    throw new RuntimeException('RESEARCH_RELEASE_REQUEST_EXECUTION_DRIFT');
                }
            }
            $request['research_release'] = $seal;
            $request = app(InstrumentResearchWindowService::class)->bindReplayRequest($generation, $request);
        }
        return $request;
    }

    /** Only cost/risk parameters are economic identity; H1/M5 labels are descriptive. */
    private function executionHash(array $contract): string
    {
        // DB JSON may reorder object keys or store 1.0 as 1. This is numerical
        // projection equality, not permission to change any cost parameter.
        $parameters = json_decode(json_encode((array) ($contract['parameters'] ?? $contract)), true);
        return app(ExecutionContractService::class)->hashParameters($parameters);
    }

    /** Legacy evidence stays unsealed; prospective evidence requires the actual worker receipt. */
    public function responseValid(array $seal, array $receipt): bool
    {
        if ($seal === []) return true;

        return ($receipt['protocol'] ?? null) === 'research_worker_release_receipt_v1'
            && ($receipt['loaded_code_attested'] ?? null) === true
            && filled($seal['release_hash'] ?? null)
            && filled($seal['python_source_hash'] ?? null)
            && hash_equals((string) $seal['release_hash'], (string) ($receipt['release_hash'] ?? ''))
            && hash_equals((string) $seal['python_source_hash'], (string) ($receipt['source_hash'] ?? ''))
            && hash_equals((string) $seal['python_source_hash'], (string) ($receipt['boot_source_hash'] ?? ''));
    }
}
