<?php

namespace App\Console\Commands;

use App\Services\PriorKnowledgeVaultService;
use Illuminate\Console\Command;

class SeedPriorKnowledgeVault extends Command
{
    protected $signature = 'trading:seed-prior-knowledge-vault';
    protected $description = 'Register XAUUSD research priors; they remain proposal-only and have no runtime or promotion authority';

    public function handle(PriorKnowledgeVaultService $vault): int
    {
        $result = $vault->seed();
        $this->info("Prior vault: {$result['status']} ({$result['seeded']} blueprints).");

        return self::SUCCESS;
    }
}
