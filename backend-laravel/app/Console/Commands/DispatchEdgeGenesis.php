<?php

namespace App\Console\Commands;

use App\Models\AiLaboratory;
use App\Services\DependencyAwareEdgeGenesisFoundryService;
use App\Services\LabDatasetExportService;
use App\Services\MultiTimeframeSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class DispatchEdgeGenesis extends Command
{
    protected $signature = 'trading:dispatch-edge-genesis {symbol?} {--timeframe=H1} {--data-hash=} {--execution-hash=} {--pre-2026} {--dry-run}';
    protected $description = 'Materialize the dependency-aware 4 packet x 5 frozen-risk Edge Genesis cohort.';

    public function handle(DependencyAwareEdgeGenesisFoundryService $foundry, MultiTimeframeSnapshotService $snapshots, LabDatasetExportService $datasets): int
    {
        $symbol = strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD')); $timeframe = strtoupper((string) $this->option('timeframe'));
        $lab = AiLaboratory::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->where('is_active', true)->first();
        if (! $lab) { $this->error('Active laboratory not found.'); return self::FAILURE; }
        if ($this->option('dry-run')) { $this->info('Would seal pre-2026 foundation + 2026 paper-only coverage, then create 4 pre-registered packets x 5 research-only arms.'); return self::SUCCESS; }
        $bundle = $snapshots->forAgentOwnedConfirmationValidation($symbol);
        $foundation = $datasets->ensureFoundationDataset($symbol, $timeframe);
        $paperPath = $datasets->exportPaper($symbol, $timeframe, false);
        $paperManifestPath = $paperPath.'.manifest.json';
        $paperSha = is_file($paperPath) ? hash_file('sha256', $paperPath) : false;
        $paperManifest = is_file($paperManifestPath) ? (array) json_decode(File::get($paperManifestPath), true) : [];
        if (! is_string($paperSha) || $paperManifest === []) {
            $this->error('Canonical paper-only coverage snapshot could not be sealed.');
            return self::FAILURE;
        }
        $canonicalSnapshots = [
            'foundation' => ['protocol' => $foundation['protocol'] ?? 'foundation_training_archive_v1',
                'path' => $foundation['path'] ?? '', 'manifest' => $foundation['manifest'] ?? [],
                'sha256' => $foundation['sha256'] ?? '', 'promotion_evidence' => false],
            'price' => ['protocol' => 'lab_generation_pre_dispatch_coverage_v1', 'path' => $paperPath,
                'manifest_path' => $paperManifestPath, 'manifest' => $paperManifest,
                'sha256' => $paperSha, 'promotion_evidence' => false],
        ];
        $result = $foundry->materialize(
            $lab,
            (string) $this->option('data-hash'),
            (string) $this->option('execution-hash'),
            (bool) $this->option('pre-2026'),
            $bundle,
            DependencyAwareEdgeGenesisFoundryService::INITIAL_REVISION,
            $canonicalSnapshots,
        );
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return ($result['status'] ?? null) === 'queued' ? self::SUCCESS : self::FAILURE;
    }
}
