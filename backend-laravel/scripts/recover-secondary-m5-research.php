<?php

/** Explicit offline import; --apply and --freeze-discovery are separate writes. */
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $options = getopt('', ['native-dataset:', 'legacy-receipt:', 'legacy-receipt-sha256:',
        'evidence-receipt:', 'evidence-receipt-sha256:', 'original-budget-bundle:', 'apply', 'freeze-discovery']);
    $references = [];
    if (isset($options['legacy-receipt'])) {
        $path = realpath($options['legacy-receipt']);
        $expected = (string) ($options['legacy-receipt-sha256'] ?? '');
        if ($path === false || ! preg_match('/^[a-f0-9]{64}$/D', $expected) || hash_file('sha256', $path) !== $expected) throw new RuntimeException('SECONDARY_LEGACY_RECEIPT_HASH_INVALID');
        $old = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($old['protocol'] ?? null) !== 'secondary_m5_gap_fork_v1' || ($old['provider'] ?? null) !== 'mixed') throw new RuntimeException('SECONDARY_LEGACY_RECEIPT_IDENTITY_INVALID');
        // The assembled CSV is deliberately ignored. Original request receipts
        // lead back to actual source responses and their UTC request contracts.
        foreach ((array) ($old['input_hashes'] ?? []) as $input => $hash) {
            if (str_ends_with(basename($input), 'receipt.json')) $references[] = ['path' => $input, 'sha256' => $hash];
        }
    }
    $paths = (array) ($options['evidence-receipt'] ?? []);
    $hashes = (array) ($options['evidence-receipt-sha256'] ?? []);
    if (count($paths) !== count($hashes)) throw new RuntimeException('SECONDARY_EVIDENCE_ARGUMENT_COUNT_INVALID');
    foreach ($paths as $index => $path) $references[] = ['path' => $path, 'sha256' => $hashes[$index]];
    $result = app(App\Services\MarketData\SecondaryM5ResearchRecoveryService::class)->build(
        (string) ($options['native-dataset'] ?? ''), $references,
        (string) ($options['original-budget-bundle'] ?? ''), isset($options['apply']));
    $output = ['mode' => isset($options['apply']) ? 'applied_separate_mixed_research_archive' : 'dry_run_no_writes',
        'receipt' => $result['receipt'], 'price_path' => $result['price_path'], 'native_archive_changed' => false];
    if (isset($options['freeze-discovery'])) {
        if (! isset($options['apply'])) throw new RuntimeException('SECONDARY_DISCOVERY_FREEZE_REQUIRES_APPLIED_ARCHIVE');
        $bundle = app(App\Services\MultiTimeframeSnapshotService::class)->forProspectiveCleanDiscovery('XAUUSD', $result['receipt']['dataset_key']);
        $output['discovery_bundle_hash'] = $bundle['bundle_hash']; $output['discovery_manifest_path'] = $bundle['manifest_path'];
        $output['discovery_readiness'] = app(App\Services\MultiTimeframeSnapshotService::class)->discoveryBundleReadiness($bundle['manifest']);
    }
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL); exit(2);
}
