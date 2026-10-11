<?php

namespace App\Jobs;

use App\Services\DescendantScopedExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Existing full replay worker; never the scheduler's 900-second child. */
class ExecuteScopedResearchArmJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    public int $uniqueFor = DescendantScopedExecutionService::WORK_LEASE_SECONDS;

    public int $timeout;

    public function __construct(public int $workItemId, public string $leaseToken, public int $fenceVersion,
        public string $unitKey, public string $requestHash, public int $producerBudgetSeconds)
    {
        $this->timeout = DescendantScopedExecutionService::nativeJobTimeout($producerBudgetSeconds);
        $this->onConnection((string) config('queue.default', 'redis'));
        $this->onQueue('lab-full-validation');
    }

    public function uniqueId(): string
    {
        return 'scoped-original-arm:'.$this->workItemId.':'.$this->fenceVersion.':'.$this->unitKey;
    }

    public function handle(DescendantScopedExecutionService $executor): void
    {
        $executor->executeQueuedArm($this->workItemId, $this->leaseToken, $this->fenceVersion,
            $this->unitKey, $this->requestHash, $this->producerBudgetSeconds);
    }
}
