<?php
namespace App\Jobs;
use App\Models\LabAgent;
use App\Services\LabAgentEvaluationService;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
class EvaluateLabAgentJob implements ShouldQueue
{
    use Batchable,Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public int $timeout=2400; public int $tries=2;
    public function __construct(public int $labAgentId,public string $symbol,public string $mode='full')
    {
        $this->onConnection('database');
        // Screening is safe to run per market in parallel. Full validation is
        // deliberately serialized through one shared queue because it is CPU
        // and memory intensive.
        $this->onQueue($mode === 'screen' ? 'lab-'.strtolower($symbol) : 'lab-full-validation');
    }
    public function handle(LabAgentEvaluationService $service):void
    {
        $agent=LabAgent::findOrFail($this->labAgentId);
        $agent->update(['lifecycle_status'=>$this->mode === 'screen' ? 'screening' : 'training']);
        $this->mode === 'screen' ? $service->screen($agent) : $service->evaluate($agent);
    }
    public function failed(\Throwable $e):void{LabAgent::whereKey($this->labAgentId)->update(['lifecycle_status'=>'rejected','decision_reason'=>ucfirst($this->mode).' queue evaluation failed: '.$e->getMessage()]);}
}
