<?php

namespace App\Jobs;

use App\Services\LabImmutableEvidenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Build the query-friendly candle projection after immutable trace storage.
 * The compressed decision_trace artifact is the canonical evidence; this job
 * is a retryable learning/read-model projection only.
 */
class ProjectLabCandleDecisionEvents implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;
    public int $timeout = 600;
    public int $uniqueFor = 86400;

    public function __construct(public string $runId)
    {
        $this->onConnection((string) config('queue.default', 'redis'));
        $this->onQueue((string) config('services.lab_queue.learning_queue', 'lab-learning'));
    }

    public function uniqueId(): string
    {
        return 'lab-candle-projection:'.$this->runId;
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(24);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))->shared()->releaseAfter(15)->expireAfter(900),
        ];
    }

    public function handle(LabImmutableEvidenceService $evidence): void
    {
        $run = $evidence->findRun($this->runId);
        if (! $run || ! $evidence->isTerminalRun($run)) {
            $this->release(15);

            return;
        }

        $manifest = $evidence->projectDecisionTrace($run);
        if (($manifest['complete'] ?? false) === true) {
            $evidence->recordLifecycle($run->agent, 'decision_trace_projected', [
                'run_id' => $run->run_id,
                'event_count' => $manifest['event_count'] ?? 0,
                'stored_event_count' => $manifest['stored_event_count'] ?? $manifest['event_count'] ?? 0,
                'compacted_event_count' => $manifest['compacted_event_count'] ?? 0,
                'rollup_count' => $manifest['rollup_count'] ?? 0,
                'projection_mode' => $manifest['projection_mode'] ?? 'full_rows_v1',
                'projection_protocol' => 'candle_decision_projection_v1',
            ], $run->phase, $run->run_id, $run->attempt, self::class);
        }
    }
}
