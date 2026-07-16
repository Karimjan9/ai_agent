<?php

namespace App\Console\Commands;

use App\Services\CotMarketIntelligenceService;
use Illuminate\Console\Command;
use Throwable;

class SyncCotMarketIntelligence extends Command
{
    protected $signature = 'market-intelligence:sync-cot {--limit=260 : Number of official CFTC Gold reports to request}';

    protected $description = 'Import official CFTC Gold COT reports and create read-only positioning features.';

    public function handle(CotMarketIntelligenceService $service): int
    {
        try {
            $stats = $service->syncGoldReports((int) $this->option('limit'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("COT synced: {$stats['received']} received, {$stats['created']} reports created, {$stats['features']} feature snapshots created.");

        return self::SUCCESS;
    }
}
