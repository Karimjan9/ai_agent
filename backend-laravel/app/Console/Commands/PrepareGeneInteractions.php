<?php

namespace App\Console\Commands;

use App\Services\GeneInteractionLabService;
use Illuminate\Console\Command;

class PrepareGeneInteractions extends Command
{
    protected $signature = 'trading:prepare-gene-interactions {symbol?} {--timeframe=H1} {--family=} {--limit=100} {--json}';

    protected $description = 'Prepare research-only gene interaction probes after independent single-gene mentors exist';

    public function handle(GeneInteractionLabService $lab): int
    {
        $result = $lab->prepare(
            strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD')),
            strtoupper((string) $this->option('timeframe')),
            (string) $this->option('family') ?: null,
            max(1, (int) $this->option('limit')),
        );
        if ($this->option('json')) $this->line(json_encode([...$result, 'promotion_evidence' => false], JSON_UNESCAPED_SLASHES));
        else $this->info("Gene interaction frontier prepared: {$result['created']} research probe(s); no promotion mutation was made.");
        return self::SUCCESS;
    }
}
