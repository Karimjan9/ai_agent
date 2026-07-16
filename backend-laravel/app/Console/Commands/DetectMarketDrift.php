<?php
namespace App\Console\Commands;
use App\Services\LabPopulationService;
use App\Services\MarketDriftDetectionService;
use Illuminate\Console\Command;
class DetectMarketDrift extends Command
{
    protected $signature='trading:detect-drift'; protected $description='Detect statistical market drift and trigger isolated lab generations';
    public function handle(MarketDriftDetectionService $drift,LabPopulationService $labs):int
    { foreach(['XAUUSD','EURUSD','GBPUSD'] as $symbol){$snapshot=$drift->detect($symbol); if(!$snapshot){$this->warn("{$symbol}: insufficient data");continue;}
        $this->info("{$symbol}: PSI {$snapshot->psi_score}, {$snapshot->status}"); if($snapshot->status==='drift')$labs->build($symbol,'market_drift');} return self::SUCCESS; }
}
