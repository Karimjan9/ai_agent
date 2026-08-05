<?php
namespace App\Jobs;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Jobs\Middleware\PreferFullValidationQueue;
use App\Services\LabAgentEvaluationService;
use App\Services\CandidateHandoffService;
use App\Services\LabImmutableEvidenceService;
use App\Jobs\Middleware\LabQueueAttemptEvidenceMiddleware;
use App\Jobs\Middleware\LabMutexEvidenceMiddleware;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Throwable;
class EvaluateLabAgentJob implements ShouldQueue, ShouldBeUnique
{
    use Batchable,Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public int $timeout = 360;
    // Contention with the single AI replay lane releases a job instead of
    // running a duplicate request. A small bounded retry budget prevents a
    // dead AI request from turning one screening candidate into an all-night
    // queue storm. Transport failures remain operational evidence and are
    // recovered explicitly after the service is healthy.
    // The wall-clock retryUntil() deadline remains the real safety bound.
    // A shared AI lane can be occupied by another market for several minutes;
    // eight lock-release attempts could discard healthy work before that
    // deadline. Keep attempts high enough to absorb contention, while the
    // serialized retryUntil() timestamp prevents an unbounded retry storm.
    // 32 attempts is too small when the single AI lane is shared by three
    // screening workers and a serialized full-validation worker: middleware
    // releases consume attempts even before handle() reaches the evaluator.
    // Keep the real bound in retryUntil(); this high ceiling only prevents a
    // healthy job from being discarded because of queue contention.
    public int $tries = 240;
    public int $uniqueFor = 7200;
    /** Runtime-only marker used by queue evidence middleware. */
    public bool $labMutexAcquired = false;
    /** Set by the outer evidence middleware so deferred attempts have a run. */
    public ?string $evidenceRunId = null;
    public \DateTimeInterface $retryDeadline;
    public function __construct(public int $labAgentId,public string $symbol,public string $mode='full')
    {
        $this->onConnection('database');
        // Screening is safe to run per market in parallel. Full validation is
        // deliberately serialized through one shared queue because it is CPU
        // and memory intensive.
        $this->onQueue($mode === 'screen' ? 'lab-'.strtolower($symbol) : 'lab-full-validation');
        // WithoutOverlapping releases contenders while another replay owns
        // the single AI CPU lane. Those releases count as queue attempts, so
        // a two-attempt policy would incorrectly discard healthy candidates
        // before their first evaluator call. Keep the retry budget bounded by
        // a wall-clock retry window and use a small backoff for provider/boot
        // failures.
        // Differential screening performs four paired replays and has a
        // separate bounded HTTP budget. Ordinary screens keep the shorter
        // transport timeout in LabAgentEvaluationService.
        $this->timeout = $mode === 'screen' ? 1200 : 2400;
        // Screening is serialized through one AI lane per process. A 20
        // minute deadline can starve the tail of a 20-agent generation while
        // the first candidates are being replayed, turning queue fairness
        // into false evaluation_error records. Keep the lane bounded, but
        // give a full generation enough wall-clock time to drain.
        $this->retryDeadline = now()->addMinutes($mode === 'screen' ? 90 : 90);
    }
    /**
     * The Python evaluator is a bounded single-process CPU service.  Multiple
     * symbol workers may remain online for queue isolation, but only one heavy
     * replay may enter the service at a time.  Contending jobs are released
     * instead of waiting inside a 300s HTTP request and being misclassified as
     * strategy failures.
     */
    public function middleware(): array
    {
        return [
            new LabQueueAttemptEvidenceMiddleware(),
            new PreferFullValidationQueue($this->mode),
            (new WithoutOverlapping('neurotrader-ai-heavy-replay'))
                // Sparse releases avoid a database retry storm across market
                // workers while another replay owns the single AI lane.
                ->releaseAfter(45)
                ->expireAfter(2400),
            new LabMutexEvidenceMiddleware(),
        ];
    }
    public function backoff(): array|int
    {
        return $this->mode === 'screen' ? [30, 60, 120, 300] : 30;
    }
    public function retryUntil(): \DateTimeInterface
    {
        return $this->retryDeadline;
    }
    public function uniqueId(): string
    {
        $agent = LabAgent::find($this->labAgentId);
        $contract = (string) data_get($agent?->modelVersion?->metadata, 'execution_contract', 'legacy');
        return implode(':', ['lab-evaluation', $this->labAgentId, $this->mode, $contract]);
    }
    public function handle(LabAgentEvaluationService $service, CandidateHandoffService $handoffs, LabImmutableEvidenceService $evidence):void
    {
        // A cancelled batch must not continue mutating agent lifecycle state
        // after an operator or recovery command has stopped it.
        if ($this->batch()?->cancelled()) {
            if ($run = $evidence->findRun($this->evidenceRunId)) {
                $evidence->finishIfOpen($run, 'skipped', null, ['skip_reason' => 'BATCH_CANCELLED'], [
                    'reason_code' => 'BATCH_CANCELLED', 'mode' => $this->mode,
                ]);
            }
            return;
        }
        $agent=LabAgent::findOrFail($this->labAgentId);
        $jobUuid = $this->job && method_exists($this->job, 'uuid') ? $this->job->uuid() : null;
        $run = $evidence->findRun($this->evidenceRunId);
        if (! $run) {
            $run = $evidence->beginRun($agent, $this->mode === 'screen' ? 'screening' : 'full_validation', $this->mode, [
                'attempt' => max(1, (int) $this->attempts()), 'queue' => $this->queue,
                'job_uuid' => $jobUuid, 'source' => 'EvaluateLabAgentJob',
            ]);
            $this->evidenceRunId = $run->run_id;
        }
        // The first full-validation job evaluates and caches the selected
        // cohort.  A peer that has already been resolved must not reopen a
        // completed lifecycle state when its queued job is reached.
        if($this->mode === 'full' && $agent->lifecycle_status !== 'full_queued') {
            // A worker can die after setting `training` and before the
            // response/evidence transaction closes. When the exact reserved
            // job is later recovered, leaving this state untouched would
            // strand the sealed candidate forever. Reopen only this transient
            // lifecycle state and release the same job; completed/error
            // states remain immutable and are still skipped.
            if ($agent->lifecycle_status === 'training') {
                $agent->update([
                    'lifecycle_status' => 'full_queued',
                    'decision_reason' => 'Recovered stale training lifecycle after worker interruption; full replay remains sealed and verdict withheld.',
                ]);
                $evidence->finishRun($run, 'retry_released', null, [], [
                    'reason_code' => 'STALE_TRAINING_LIFECYCLE_RECOVERED',
                    'retry_after_seconds' => 60,
                ]);
                $this->release(60);
                return;
            }
            $evidence->markSkipped($run, 'FULL_AGENT_NOT_QUEUED', ['lifecycle_status' => $agent->lifecycle_status]);
            return;
        }
        $agent->update(['lifecycle_status'=>$this->mode === 'screen' ? 'screening' : 'training']);
        if ($this->mode === 'full') $handoffs->record($agent->generation, $agent, 'running', 'completed', null, ['attempt' => $this->attempts(), 'queue' => $this->queue]);
        try {
            $this->mode === 'screen' ? $service->screen($agent, $run) : $service->evaluate($agent, $run);
        } catch (Throwable $error) {
            // A direct portfolio command and a queued full replay share one
            // serialized AI lane. Contention is transient operational state,
            // not an evaluator failure and never a strategy verdict. Release
            // the job so the same sealed candidate can retry after the lane
            // owner finishes.
            if ($this->isReplayLaneContention($error)) {
                $agent->update([
                    'lifecycle_status' => $this->mode === 'screen' ? 'queued' : 'full_queued',
                    'decision_reason' => 'AI replay lane contention; job released for bounded retry. Strategy verdict remains withheld.',
                ]);
                $evidence->finishRun($run, 'retry_released', null, [], [
                    'reason_code' => 'REPLAY_LANE_CONTENTION', 'retry_after_seconds' => 60,
                ]);
                $this->release(60);
                return;
            }
            // Transport and evaluator faults are terminal for this queue run.
            // They are operational evidence only; no retry storm or gate
            // decision may be produced from an incomplete replay.
            $this->markEvaluationError($agent, $error, $run, $evidence);
        }
    }
    public function failed(\Throwable $e):void
    {
        $agent=LabAgent::find($this->labAgentId);
        if(!$agent) return;

        // A transport/provider/runtime failure is not evidence that the
        // strategy failed.  Keep it out of rejection statistics and make the
        // recovery path explicit so it can be safely requeued after repair.
        $evidence = app(LabImmutableEvidenceService::class);
        $run = $evidence->findRun($this->evidenceRunId);
        if (! $run) {
            $run = $evidence->beginRun($agent, $this->mode === 'screen' ? 'screening' : 'full_validation', $this->mode, [
                'attempt' => max(1, (int) $this->attempts()), 'queue' => $this->queue,
                'source' => 'queue_failed_callback',
            ]);
            $this->evidenceRunId = $run->run_id;
        }
        $this->markEvaluationError($agent, $e, $run, $evidence);
    }
    private function markEvaluationError(LabAgent $agent, Throwable $e, ?LabEvaluationRun $run = null, ?LabImmutableEvidenceService $evidence = null): void
    {
        $evidence ??= app(LabImmutableEvidenceService::class);
        if ($run) {
            $evidence->finishRun($run, 'technical_error', null, [], [
                'reason_code' => 'EVALUATION_ERROR', 'mode' => $this->mode,
            ], $e);
        }
        $reason = ucfirst($this->mode).' queue evaluation error; strategy verdict withheld: '.substr($e->getMessage(), 0, 500);
        $agent->update(['lifecycle_status'=>'evaluation_error','decision_reason'=>$reason]);
        if ($this->mode === 'screen') {
            $generation = $agent->generation()->with('agents.modelVersion')->first();
            $generation?->update(['status' => 'screening', 'completed_at' => null]);

            // One bounded recovery is allowed. If that recovery also fails,
            // the transport problem must not hold the entire laboratory open
            // forever. Quarantine the incomplete agent as operational
            // evidence only, close the generation for screening handoff, and
            // leave every quality/promotion gate unchanged.
            $recoveryAttempts = (int) data_get($agent->modelVersion?->metadata, 'evaluator_recovery_attempts', 0);
            $openPeers = $generation?->agents->contains(fn (LabAgent $peer): bool =>
                in_array($peer->lifecycle_status, ['draft', 'queued', 'screening'], true)
            ) ?? true;
            $unrecoveredErrors = $generation?->agents->contains(fn (LabAgent $peer): bool =>
                $peer->lifecycle_status === 'evaluation_error'
                && (int) data_get($peer->modelVersion?->metadata, 'evaluator_recovery_attempts', 0) < 1
            ) ?? true;
            if ($generation && $recoveryAttempts >= 1 && ! $openPeers && ! $unrecoveredErrors) {
                $agent->update([
                    'lifecycle_status' => 'technical_quarantine',
                    'decision_reason' => 'Technical quarantine after bounded evaluator recovery; strategy verdict remains withheld.',
                ]);
                $context = (array) ($generation->trigger_context ?? []);
                $context['evaluation_error_quarantine'] = [
                    'protocol' => 'bounded_evaluator_recovery_v1',
                    'recorded_at' => now()->utc()->toIso8601String(),
                    'rule' => 'transport failure is quarantined; no strategy verdict, full replay, or paper evidence is created',
                    'agent_ids' => $generation->agents()->where('lifecycle_status', 'technical_quarantine')->pluck('id')->values()->all(),
                ];
                $generation->update([
                    // An exhausted evaluator retry is terminal technical
                    // evidence, never a synthetic screening completion.
                    'status' => 'technical_quarantine',
                    'completed_at' => now(),
                    'trigger_context' => $context,
                ]);
                app(CandidateHandoffService::class)->record(
                    $generation,
                    $agent,
                    'evaluation_error_quarantined',
                    'completed',
                    'EVALUATOR_RECOVERY_EXHAUSTED',
                    ['recovery_attempts' => $recoveryAttempts, 'promotion_evidence' => false]
                );
                $evidence->recordLifecycle($agent, 'technical_quarantine', [
                    'reason_code' => 'EVALUATOR_RECOVERY_EXHAUSTED', 'quality_verdict' => 'withheld',
                    'recovery_attempts' => $recoveryAttempts,
                ], 'screening', $run?->run_id, $run?->attempt, self::class);
                app(\App\Services\LabGenerationReportService::class)->record($generation->fresh(), 'screening_technical_quarantine');
            }
        }
        if($this->mode==='full') {
            app(CandidateHandoffService::class)->record($agent->generation,$agent,'completed','failed','QUEUE_JOB_FAILED',['attempt'=>$this->attempts(),'failure_reason'=>$e->getMessage(),'next_action'=>'retry_after_evaluator_health_check']);
            app(\App\Services\LabGenerationReportService::class)->record($agent->generation()->with('agents')->first(), 'full_technical_error');
        }
    }

    private function isReplayLaneContention(Throwable $error): bool
    {
        $message = strtolower($error->getMessage());
        return str_contains($message, 'ai replay lane is busy')
            || str_contains($message, 'ai replay lane band')
            || str_contains($message, 'http 429');
    }
}
