<?php
namespace App\Console\Commands;
use App\Services\LabPopulationService;
use App\Services\MarketDriftDetectionService;
use App\Models\MarketDriftSnapshot;
use Illuminate\Console\Command;
class DetectMarketDrift extends Command
{
    protected $signature='trading:detect-drift'; protected $description='Detect statistical market drift and trigger isolated lab generations';
    public function handle(MarketDriftDetectionService $drift,LabPopulationService $labs):int
    { foreach(['XAUUSD','EURUSD','GBPUSD'] as $symbol){$snapshot=$drift->detect($symbol); if(!$snapshot){$this->warn("{$symbol}: insufficient data");continue;}
        $confirmation=$drift->confirmation($symbol,'H1');
        $this->info("{$symbol}: PSI {$snapshot->psi_score}, {$snapshot->status} (".$confirmation['observed_confirmations'].'/'. $confirmation['required_confirmations'].' canonical checks)'.($confirmation['status']==='confirmed'?' (confirmed)':''));
        if($confirmation['status']==='confirmed')$labs->build($symbol,'market_drift');} return self::SUCCESS; }
}
