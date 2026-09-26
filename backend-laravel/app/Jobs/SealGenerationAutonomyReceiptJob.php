<?php

namespace App\Jobs;

use App\Models\ResearchLoopDecision;
use App\Services\GenerationAutonomyReceiptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Retries only the operational receipt seal; it never re-runs research. */
class SealGenerationAutonomyReceiptJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    public int $uniqueFor = 86400;

    public function __construct(public int $researchLoopDecisionId)
    {
        $this->onConnection((string) config('queue.default', 'redis'));
        $this->onQueue('scheduler-critical');
    }

    public function uniqueId(): string
    {
        return 'generation-autonomy-receipt:'.$this->researchLoopDecisionId;
    }

    /** @return array<int,int> */
    public function backoff(): array
    {
        return [15, 60, 180, 600];
    }

    public function handle(GenerationAutonomyReceiptService $receipts): void
    {
        $decision = ResearchLoopDecision::query()->find($this->researchLoopDecisionId);
        if (! $decision) {
            return;
        }

        $receipts->recordSuccessorDecision($decision);
    }
}
