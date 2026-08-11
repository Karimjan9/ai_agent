<?php

namespace App\Jobs\Middleware;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\LabAgent;
use App\Services\LabImmutableEvidenceService;
use Closure;
use Throwable;

/** Records acquisition/release of the single heavy-replay lane. */
class LabMutexEvidenceMiddleware
{
    public function handle(object $job, Closure $next): mixed
    {
        if (! $job instanceof EvaluateLabAgentJob) return $next($job);
        $agent = LabAgent::find($job->labAgentId);
        if (! $agent) return $next($job);
        $ledger = app(LabImmutableEvidenceService::class);
        $job->labMutexAcquired = true;
        $phase = $job->mode === 'screen' ? 'screening' : 'full_validation';
        $ledger->recordLifecycle($agent, 'replay_mutex_acquired', [
            'run_id' => $job->evidenceRunId, 'attempt' => $job->attempts(), 'queue' => $job->effectiveQueue(),
            'mutex' => (string) config('services.lab_queue.replay_mutex_key', 'neurotrader-ai-heavy-replay'),
        ], $phase, $job->evidenceRunId, $job->attempts(), self::class);
        try {
            return $next($job);
        } catch (Throwable $error) {
            $ledger->recordLifecycle($agent->fresh(), 'replay_mutex_error', [
                'run_id' => $job->evidenceRunId, 'mutex' => (string) config('services.lab_queue.replay_mutex_key', 'neurotrader-ai-heavy-replay'), 'attempt' => $job->attempts(),
            ], $phase, $job->evidenceRunId, $job->attempts(), self::class, $error);
            throw $error;
        } finally {
            $ledger->recordLifecycle($agent->fresh(), 'replay_mutex_released', [
                'run_id' => $job->evidenceRunId, 'mutex' => (string) config('services.lab_queue.replay_mutex_key', 'neurotrader-ai-heavy-replay'), 'attempt' => $job->attempts(),
            ], $phase, $job->evidenceRunId, $job->attempts(), self::class);
        }
    }
}
