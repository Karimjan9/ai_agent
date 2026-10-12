<?php

// Explicit local read-only history benchmark. No research admission or history rewrite.
$root = realpath($argv[1] ?? dirname(__DIR__, 2));
$output = $argv[2] ?? null;
if (! $root || ! is_file($root.'/backend-laravel/bootstrap/app.php') || ! $output) exit(2);
require $root.'/backend-laravel/vendor/autoload.php';
$app = require $root.'/backend-laravel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once dirname(__DIR__).'/app/Services/ResearchRequestReferenceInventoryService.php';
$db = Illuminate\Support\Facades\DB::connection();
$db->statement('SET SESSION max_statement_time=10');
$db->beforeExecuting(static function (string $sql): void {
    if (! preg_match('/^SELECT\b/i', trim($sql))) throw new RuntimeException('READ_ONLY_REQUIRED');
});
$baseline = realpath($argv[3] ?? $root.'/backend-laravel/app/Services/ResearchWindowProvenanceAuditService.php');
$legacy = $baseline ? file_get_contents($baseline) : '';
$legacyAvailable = $legacy !== '' && ! str_contains($legacy, 'ResearchRequestReferenceInventoryService');
$legacy = str_replace('class ResearchWindowProvenanceAuditService', 'class LegacyResearchWindowProvenanceBenchmark', $legacy);
$legacy = str_replace("\$highWater = (int) DB::table('lab_evaluation_runs')->max('id');", '$highWater = 100;', $legacy);
if ($legacyAvailable) eval(substr($legacy, 5));
$canonical = function ($value) use (&$canonical) {
    if (! is_array($value)) return is_string($value) && ctype_digit($value) ? (int) $value : $value;
    if (! array_is_list($value)) ksort($value);
    return array_map($canonical, $value);
};
$results = ['protocol' => 'read_only_representative_history_benchmark_v1', 'observed_at' => gmdate('c'),
    'fixed_comparison_high_water_id' => 100, 'statement_timeout_seconds' => 10, 'production_writes' => false,
    'original_baseline_available' => $legacyAvailable];
$candidate = ['start' => Carbon\CarbonImmutable::parse('2010-01-01T00:00:00Z'), 'end' => Carbon\CarbonImmutable::parse('2010-02-01T00:00:00Z')];
$before = hrtime(true);
if ($legacyAvailable) try {
    $old = (new App\Services\LegacyResearchWindowProvenanceBenchmark)->audit('2010-01-01T00:00:00Z', '2010-02-01T00:00:00Z');
    $results['legacy_representative'] = ['completed' => true, 'seconds' => (hrtime(true)-$before)/1e9];
} catch (Throwable $error) {
    $results['legacy_representative'] = ['completed' => false, 'seconds' => (hrtime(true)-$before)/1e9, 'error_class' => get_class($error)];
}
$owner = new App\Services\ResearchRequestReferenceInventoryService;
$before = hrtime(true); $new = $owner->inventory(100, $candidate);
$results['single_pass_representative'] = ['completed' => true, 'seconds' => (hrtime(true)-$before)/1e9];
if (isset($old)) {
    foreach (['phases' => 'run_phase_summary', 'ranges' => 'research_request_reference_ranges',
        'input_ranges' => 'research_input_reference_ranges', 'input_summary' => 'research_input_reference_summary'] as $key => $oldKey) {
        $a = $canonical($old[$oldKey]); $b = $canonical($new[$key]);
        $results['semantic_comparison'][$key] = ['equal' => $a === $b,
            'legacy_sha256' => hash('sha256', json_encode($a)), 'new_sha256' => hash('sha256', json_encode($b))];
    }
}
$highWater = (int) Illuminate\Support\Facades\DB::table('lab_evaluation_runs')->max('id');
$before = hrtime(true); $full = $owner->inventory($highWater, $candidate);
$results['single_pass_full_history'] = ['completed' => true, 'seconds' => (hrtime(true)-$before)/1e9,
    'high_water_id' => $highWater, 'runs' => array_sum(array_column($full['phases'], 'total')),
    'peak_memory_bytes' => memory_get_peak_usage(true), 'unresolved_provenance' => $full['missing'],
    'candidate_unused_demonstrated' => false];
file_put_contents($output, json_encode($results, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo json_encode($results, JSON_UNESCAPED_SLASHES)."\n";
