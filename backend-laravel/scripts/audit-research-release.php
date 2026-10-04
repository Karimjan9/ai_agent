<?php

/**
 * Read-only provenance diagnostic, except explicit --build-source-artifact.
 * The build archives allowlisted source locally; it never seals Git or DB rows.
 * This script intentionally lives outside
 * app/: adding an audit must not change an in-flight replay code fingerprint.
 */

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$options = getopt('', ['symbol::', 'timeframe::', 'generation::', 'strict', 'build-source-artifact', 'verify-source-artifact:']);
$releaseOwner = $app->make(\App\Services\ResearchReleaseSealService::class);
if (isset($options['build-source-artifact']) || isset($options['verify-source-artifact'])) {
    try {
        $report = isset($options['build-source-artifact']) ? $releaseOwner->buildSourceArtifact()
            : $releaseOwner->verifySourceArtifact($releaseOwner->sourceArtifactReference((string) $options['verify-source-artifact']));
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
        exit(0);
    } catch (\Throwable $error) {
        echo json_encode(['status' => 'invalid', 'reason_code' => $error->getMessage(),
            'worker_loaded_code_attested' => false, 'scientific_evidence' => false, 'promotion_evidence' => false],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
        exit(1);
    }
}
$symbol = strtoupper((string) ($options['symbol'] ?? 'XAUUSD'));
$timeframe = strtoupper((string) ($options['timeframe'] ?? 'H1'));
$query = \App\Models\LabGeneration::query()->whereHas('laboratory', fn ($q) => $q
    ->where('symbol', $symbol)->where('timeframe', $timeframe));
if (isset($options['generation'])) {
    $query->where('generation', (int) $options['generation']);
}
$generation = $query->orderByDesc('generation')->orderByDesc('id')->first();
if (! $generation) {
    fwrite(STDERR, "Generation not found.\n");
    exit(2);
}

$root = dirname(dirname(__DIR__));
$git = static function (array $args) use ($root): string {
    $process = new \Symfony\Component\Process\Process(['git', ...$args], $root);
    $process->run();

    return $process->isSuccessful() ? trim($process->getOutput()) : '';
};
$head = $git(['rev-parse', 'HEAD']);
$dirty = $git(['status', '--porcelain=v1', '--untracked-files=all']) !== '';
$declaredCommit = trim((string) env('APP_COMMIT_SHA', ''));
$currentHash = $app->make(\App\Services\LabImmutableEvidenceService::class)->codeHash();
$runs = \App\Models\LabEvaluationRun::query()->where('lab_generation_id', $generation->id)
    ->whereIn('phase', ['screening', 'full_validation'])
    ->get(['run_id', 'code_hash', 'worker_name', 'worker_pid', 'metadata', 'response_meta']);
$hashCounts = $runs->groupBy(fn ($run): string => (string) ($run->code_hash ?: 'missing'))
    ->map(fn ($group): int => $group->count())->all();
$observed = array_values(array_filter(array_keys($hashCounts), fn (string $hash): bool => $hash !== 'missing'));
$reasons = [];
if ($runs->isEmpty()) {
    $reasons[] = 'GENERATION_RUN_EVIDENCE_MISSING';
}
if (! preg_match('/^[a-f0-9]{40}$/i', $head)) {
    $reasons[] = 'GIT_HEAD_UNAVAILABLE';
}
if ($dirty) {
    $reasons[] = 'WORKTREE_NOT_SEALED';
}
if ($declaredCommit === '' || ! hash_equals($head, $declaredCommit)) {
    $reasons[] = 'APP_COMMIT_SHA_NOT_AT_HEAD';
}
if (array_key_exists('missing', $hashCounts)) {
    $reasons[] = 'RUN_SOURCE_HASH_MISSING';
}
if (count($observed) > 1) {
    $reasons[] = 'GENERATION_MIXED_SOURCE_HASHES';
}
if ($observed !== [] && ! in_array($currentHash, $observed, true)) {
    $reasons[] = 'CURRENT_SOURCE_DIFFERS_FROM_GENERATION';
}
$seal = (array) data_get($generation->trigger_context, 'research_release', []);
$attestedRuns = $seal === [] ? 0 : $runs->filter(fn ($run): bool =>
    hash_equals((string) ($seal['source_hash'] ?? ''), (string) data_get($run->metadata, 'worker_boot_source_hash', ''))
    && hash_equals((string) ($seal['source_hash'] ?? ''), (string) $run->code_hash)
    && $app->make(\App\Services\ResearchReleaseSealService::class)->responseValid($seal,
        (array) data_get($run->response_meta, 'research_release_receipt', [])))->count();
$workersAttested = $runs->isNotEmpty() && $attestedRuns === $runs->count();
if (! $workersAttested) $reasons[] = 'WORKER_LOADED_RELEASE_NOT_FULLY_ATTESTED';

$sourceArtifact = ['status' => 'missing', 'reason_code' => 'SOURCE_ARTIFACT_REFERENCE_MISSING'];
$reference = (array) ($seal['source_artifact'] ?? []);
if ($reference !== []) {
    try {
        $verified = $releaseOwner->verifySourceArtifact($reference);
        if (($reference['source_hash'] ?? null) !== ($seal['source_hash'] ?? null)
            || ($reference['python_source_hash'] ?? null) !== ($seal['python_source_hash'] ?? null)) {
            throw new \RuntimeException('SOURCE_ARTIFACT_RELEASE_IDENTITY_MISMATCH');
        }
        $sourceArtifact = ['status' => 'verified', 'artifact_hash' => $reference['artifact_hash'],
            'archive_sha256' => $reference['archive_sha256'], 'git_at_build' => $verified['manifest']['git'],
            'worker_loaded_code_attested' => false, 'scientific_evidence' => false];
    } catch (\Throwable $error) {
        $sourceArtifact = ['status' => 'invalid', 'reason_code' => $error->getMessage()];
    }
}
if ($sourceArtifact['status'] !== 'verified') $reasons[] = $sourceArtifact['reason_code'];

$report = [
    'protocol' => 'research_release_provenance_audit_v1',
    'generation_id' => (int) $generation->id,
    'generation' => (int) $generation->generation,
    'state' => $reasons === [] ? 'source_sealed_worker_attested' : 'release_incomplete',
    'reason_codes' => $reasons,
    'git_head' => $head,
    'declared_commit' => $declaredCommit !== '' ? $declaredCommit : null,
    'worktree_clean' => ! $dirty,
    'current_source_hash' => $currentHash,
    'generation_run_hash_counts' => $hashCounts,
    'run_count' => $runs->count(),
    'worker_count' => $runs->map(fn ($run): string =>
        (string) $run->worker_name.'|'.(string) $run->worker_pid)->unique()->count(),
    'worker_loaded_code_attested' => $workersAttested,
    'prospective_release_hash' => $seal['release_hash'] ?? null,
    'release_attested_run_count' => $attestedRuns,
    'all_observed_workers_attested' => $workersAttested,
    'source_artifact' => $sourceArtifact,
    'source_artifact_reproducible' => $sourceArtifact['status'] === 'verified',
    'git_commit_sealed' => ! $dirty && $declaredCommit !== '' && hash_equals($head, $declaredCommit),
    'independent_research_data' => $app->make(\App\Services\InstrumentResearchWindowService::class)->readiness(),
    'reproducible_release_proven' => $reasons === [],
    'promotion_evidence' => false,
];
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit(isset($options['strict']) && $reasons !== [] ? 1 : 0);
