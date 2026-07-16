<?php
namespace App\Console\Commands;
use App\Services\LabPopulationService;
use Illuminate\Console\Command;
class BuildLabGeneration extends Command
{
    protected $signature = 'trading:lab-generation {symbol?} {--trigger=new_data} {--force}';
    protected $description = 'Create a bounded 20-agent AI Laboratory generation after 24 new H1 candles, drift, or degradation';
    public function handle(LabPopulationService $service): int
    {
        $symbols = $this->argument('symbol') ? [strtoupper($this->argument('symbol'))] : ['XAUUSD', 'EURUSD', 'GBPUSD'];
        foreach ($symbols as $symbol) {
            $generation = $service->build($symbol, (string) $this->option('trigger'), (bool) $this->option('force'));
            $this->info($generation ? "{$symbol}: generation {$generation->generation}, 20 agents." : "{$symbol}: yangi evidence yo'q, generation yaratilmadi.");
        }
        return self::SUCCESS;
    }
}
