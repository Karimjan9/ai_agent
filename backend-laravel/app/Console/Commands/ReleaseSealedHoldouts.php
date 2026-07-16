<?php
namespace App\Console\Commands;
use App\Models\ModelMarketPerformance;
use App\Services\SealedHoldoutService;
use Illuminate\Console\Command;
class ReleaseSealedHoldouts extends Command
{
    protected $signature='trading:release-holdouts'; protected $description='Open each finalist sealed holdout exactly once after paper validation';
    public function handle(SealedHoldoutService $service):int
    {foreach(ModelMarketPerformance::where('paper_status','passed')->where('holdout_status','sealed')->get() as $candidate){
        try{$release=$service->release($candidate);$this->info("{$candidate->symbol} {$candidate->strategy_family}: holdout {$release->status}, score {$release->score}");}
        catch(\Throwable $e){$this->error($e->getMessage());}}
        return self::SUCCESS;}
}
