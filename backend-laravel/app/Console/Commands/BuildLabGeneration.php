<?php
namespace App\Console\Commands;
use App\Services\LabPopulationService;
use Illuminate\Console\Command;
class BuildLabGeneration extends Command
{
    protected $signature = 'trading:lab-generation {symbol?} {--trigger=new_data} {--timeframe=H1} {--force}';
    protected $description = 'Create a configurable AI Laboratory generation after new candles, drift, or degradation';
    public function handle(LabPopulationService $service): int
    {
        $symbols = $this->argument('symbol') ? [strtoupper($this->argument('symbol'))] : ['XAUUSD', 'EURUSD', 'GBPUSD'];
        $timeframe = strtoupper((string) $this->option('timeframe'));
        foreach ($symbols as $symbol) {
            $generation = $service->build($symbol, (string) $this->option('trigger'), (bool) $this->option('force'), $timeframe);
            $this->info($generation ? "{$symbol} {$timeframe}: generation {$generation->generation}, {$generation->agents->count()} agents." : "{$symbol} {$timeframe}: yangi evidence yo'q, generation yaratilmadi.");
        }
        return self::SUCCESS;
    }
}
