<?php

namespace App\Jobs;

use App\Services\CausalFoldExecutionService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class RunCausalExperimentFoldJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels {
        SerializesModels::__unserialize as private restoreSerializedModels;
    }

    public int $tries;

    public int $timeout;

    public int $uniqueFor = 172800;

    public bool $failOnTimeout = true;

    public function __construct(public int $experimentId, public int $foldIndex)
    {
        $this->tries = max(1, min(5, (int) config('services.learning_lane.causal_fold_job_attempts', 3)));
        $this->timeout = $this->operationalTimeout();
        $this->onConnection((string) config('queue.default', 'redis'));
        $this->onQueue((string) config('services.lab_queue.full_validation_queue', 'lab-full-validation'));
    }

    /** Refresh only the operational timeout of already queued fold jobs. */
    public function __unserialize(array $values): void
    {
        $this->restoreSerializedModels($values);
        $this->timeout = $this->operationalTimeout();
    }

    private function operationalTimeout(): int
    {
        return max(1020, min(1260, (int) config('services.lab_selection.causal_fold_transport_timeout_seconds', 960) + 60));
    }

    public function uniqueId(): string
    {
        return "causal-experiment:{$this->experimentId}:fold:{$this->foldIndex}";
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(48);
    }

    public function middleware(): array
    {
        return [
            new SkipIfBatchCancelled,
            (new WithoutOverlapping((string) config('services.lab_queue.replay_mutex_key', 'neurotrader-ai-heavy-replay')))
                ->shared()->releaseAfter(60)->expireAfter($this->timeout + 120),
        ];
    }

    public function backoff(): array
    {
        return [60, 180, 600];
    }

    public function handle(CausalFoldExecutionService $folds): void
    {
        $folds->run($this->experimentId, $this->foldIndex);
    }

    public function failed(\Throwable $exception): void
    {
        app(CausalFoldExecutionService::class)->terminalFailure(
            $this->experimentId,
            $this->foldIndex,
            $exception,
        );
    }
}
