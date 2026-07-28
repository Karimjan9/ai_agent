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
        $confirmed=MarketDriftSnapshot::where('symbol',$symbol)->where('timeframe','H1')->latest('detected_at')->take(3)->get();
        $isConfirmed=$confirmed->count()===3&&$confirmed->every(fn(MarketDriftSnapshot $item)=>$item->status==='drift');
        $this->info("{$symbol}: PSI {$snapshot->psi_score}, {$snapshot->status}".($isConfirmed?' (confirmed)':''));
        if($isConfirmed)$labs->build($symbol,'market_drift');} return self::SUCCESS; }
}
