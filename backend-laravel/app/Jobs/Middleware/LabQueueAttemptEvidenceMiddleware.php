<?php

namespace App\Jobs\Middleware;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\LabAgent;
use App\Services\LabImmutableEvidenceService;
use Closure;
use Throwable;

/** Records queue attempts even when a later middleware releases for fairness. */
class LabQueueAttemptEvidenceMiddleware
{
    public function handle(object $job, Closure $next): mixed
    {
        if (! $job instanceof EvaluateLabAgentJob) return $next($job);
        $agent = LabAgent::find($job->labAgentId);
        $ledger = app(LabImmutableEvidenceService::class);
        if (! $agent) return $next($job);

        $job->labMutexAcquired = false;
        // Open the attempt before fairness/mutex middleware. A release before
        // handle() is still a real evaluation attempt and must have its own
        // run_id; the job reuses this run if it reaches the evaluator.
        $run = $ledger->beginRun($agent, $job->mode === 'screen' ? 'screening' : 'full_validation', $job->mode, [
            'attempt' => max(1, (int) $job->attempts()), 'queue' => $job->queue,
            'source' => self::class,
        ]);
        $job->evidenceRunId = $run->run_id;
        $ledger->recordLifecycle($agent, 'queue_attempt_started', [
            'run_id' => $run->run_id, 'attempt' => $job->attempts(), 'queue' => $job->queue,
            'mode' => $job->mode,
        ], $job->mode === 'screen' ? 'screening' : 'full_validation', $run->run_id, $job->attempts(), self::class);

        try {
            $result = $next($job);
            $run->refresh();
            // PreferFullValidationQueue and WithoutOverlapping can release
            // before handle(). Close that run here; it is not a strategy
            // result and cannot be mistaken for one by the audit.
            if (! $ledger->isTerminalRun($run) && ! $job->labMutexAcquired) {
                $ledger->finishRun($run, 'retry_released', null, [], [
                    'reason_code' => 'QUEUE_MIDDLEWARE_RELEASE',
                    'attempt' => $job->attempts(), 'queue' => $job->queue,
                ]);
            }
            $ledger->recordLifecycle($agent->fresh(), $job->labMutexAcquired ? 'queue_attempt_completed' : 'queue_attempt_deferred', [
                'run_id' => $run->run_id, 'attempt' => $job->attempts(), 'queue' => $job->queue,
                'mutex_acquired' => $job->labMutexAcquired,
                'rule' => $job->labMutexAcquired ? 'worker_entered_evaluator' : 'later_middleware_released_job',
            ], $job->mode === 'screen' ? 'screening' : 'full_validation', $run->run_id, $job->attempts(), self::class);
            return $result;
        } catch (Throwable $error) {
            $ledger->finishIfOpen($run, 'technical_error', null, [], [
                'reason_code' => 'QUEUE_MIDDLEWARE_ERROR', 'attempt' => $job->attempts(),
                'error_file' => $error->getFile(), 'error_line' => $error->getLine(),
            ], $error);
            report($error);
            $ledger->recordLifecycle($agent->fresh(), 'queue_attempt_error', [
                'run_id' => $run->run_id, 'attempt' => $job->attempts(), 'queue' => $job->queue,
                'mutex_acquired' => $job->labMutexAcquired,
            ], $job->mode === 'screen' ? 'screening' : 'full_validation', $run->run_id, $job->attempts(), self::class, $error);
            throw $error;
        }
    }
}
