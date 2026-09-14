<?php

namespace App\Jobs;

use App\Exceptions\ReplayLaneBusyException;
use App\Jobs\Middleware\PreferFullValidationQueue;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Services\FrozenControlScreeningAdmissionService;
use App\Services\LabAgentEvaluationService;
use App\Services\LearningTechnicalCircuitBreakerService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sends a bounded 4–6-agent cohort through one shared snapshot request.
 * Each agent still receives its own immutable evidence run/gate decision;
 * only dataset/feature construction is shared in the Python evaluator.
 */
class EvaluateLabScreeningBatchJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 2400;

    public int $uniqueFor = 21600;

    /** @var array<int, int> */
    public array $labAgentIds;

    public function __construct(
        array $labAgentIds,
        public string $symbol,
        public ?int $screeningSlot = null,
        public ?int $labGenerationId = null,
        public string $timeframe = 'H1',
        public string $jobSchemaVersion = 'lab_screening_batch_v2',
    ) {
        $this->labAgentIds = array_values(array_unique(array_map('intval', $labAgentIds)));
        if (count($this->labAgentIds) < 1 || count($this->labAgentIds) > 6) {
            throw new \InvalidArgumentException('Screening batch 1–6 agent oralig‘ida bo‘lishi kerak.');
        }
        sort($this->labAgentIds);
        if ($this->labGenerationId === null) {
            $scope = LabAgent::query()->whereKey($this->labAgentIds[0])->first(['lab_generation_id', 'timeframe']);
            $this->labGenerationId = $scope?->lab_generation_id;
            $this->timeframe = strtoupper((string) ($scope?->timeframe ?: $this->timeframe));
        }
        $this->onConnection((string) config('queue.default', 'redis'));
        $this->onQueue((string) config('services.lab_queue.screening_queue', 'lab-screening'));
    }

    public function uniqueId(): string
    {
        return 'lab-screening-batch:'.implode(',', $this->labAgentIds);
    }

    public function middleware(): array
    {
        return [
            // A cancelled cohort must be consumed before fairness or replay
            // mutex middleware can release it. Otherwise it may loop without
            // ever reaching the handle()-level cancellation guard.
            new SkipIfBatchCancelled,
            new PreferFullValidationQueue('screen'),
            (new WithoutOverlapping($this->screeningMutexKey()))
                ->shared()
                ->releaseAfter(max(15, (int) config('services.lab_queue.mutex_release_seconds', 60)))
                ->expireAfter((int) config('services.lab_queue.screening_batch_timeout_seconds', 1800) + 120),
        ];
    }

    public function backoff(): array|int
    {
        return [30, 60, 120, 300];
    }

    public function retryUntil(): \DateTimeInterface
    {
        // Frozen-control replay plus its asynchronous learning projection is
        // normally minutes, but a controlled Redis/worker restart can span a
        // maintenance window. Keep waiting evidence alive for one day; the
        // replay itself remains bounded by the job/Python hard timeouts.
        return now()->addHours(24);
    }

    public function screeningMutexKey(): string
    {
        $slot = isset($this->screeningSlot) && $this->screeningSlot !== null
            ? abs((int) $this->screeningSlot) % 2
            : abs((int) ($this->labAgentIds[0] ?? 0)) % 2;

        return (string) config('services.lab_queue.screening_mutex_key', 'neurotrader-ai-screening-replay').":slot{$slot}";
    }

    public function handle(
        LabAgentEvaluationService $service,
        FrozenControlScreeningAdmissionService $controlAdmission,
        LearningTechnicalCircuitBreakerService $technicalBreaker,
    ): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $admission = $controlAdmission->batchAdmission($this->labAgentIds);
        if ($admission['status'] === 'waiting') {
            // Control batches are scheduled ahead of candidates. A second
            // screening slot can still dequeue a candidate first, so release
            // it without creating a strategy/technical verdict.
            $this->release(30);

            return;
        }
        if ($admission['status'] === 'blocked') {
            $reasons = collect($admission['blocked'])->pluck('reason')->unique()->implode(', ');
            LabAgent::query()->whereIn('id', $this->labAgentIds)
                ->where('lifecycle_status', 'queued')
                ->update([
                    'lifecycle_status' => 'technical_quarantine',
                    'decision_reason' => 'Frozen control admission failed before screening; strategy verdict withheld: '.$reasons.'.',
                ]);

            return;
        }

        // screenBatch intentionally converts transport/projection exceptions
        // into immutable per-agent technical runs instead of throwing them
        // back to the queue. Track this invocation by monotonically increasing
        // run id so old completed evidence cannot accidentally close a new
        // half-open breaker probe.
        $runFloor = (int) (LabEvaluationRun::query()->max('id') ?? 0);
        try {
            $service->screenBatch($this->labAgentIds, $this->symbol);
        } catch (ReplayLaneBusyException) {
            // No quality/circuit-breaker failure occurred. The service either
            // deferred before opening evidence or sealed a retry_released run
            // for the narrow post-preflight race.
            $this->release(max(30, (int) config('services.lab_queue.screening_busy_release_seconds', 60)));

            return;
        }

        $runs = LabEvaluationRun::query()
            ->where('id', '>', $runFloor)
            ->whereIn('lab_agent_id', $this->labAgentIds)
            ->where('phase', 'screening')
            ->orderBy('id')
            ->get();
        $completed = $runs->firstWhere('status', 'completed');
        if ($completed) {
            $technicalBreaker->recordSuccess($this->symbol, $this->timeframe, [
                'evidence_run_id' => (string) $completed->run_id,
                'batch_protocol' => 'bounded_screening_batch_v1',
                'lab_generation_id' => $this->labGenerationId,
            ]);

            return;
        }

        // One failed evaluator request can create several agent runs. Count it
        // once at breaker scope; agent count must not masquerade as three
        // independent infrastructure incidents.
        $technical = $runs->firstWhere('status', 'technical_error');
        if ($technical) {
            $technicalBreaker->record(
                $this->symbol,
                $this->timeframe,
                (string) ($technical->error_message ?: $technical->error_class ?: 'bounded screening batch technical failure'),
                [
                    'evidence_run_id' => (string) $technical->run_id,
                    'batch_protocol' => 'bounded_screening_batch_v1',
                    'lab_generation_id' => $this->labGenerationId,
                    'failed_agent_count' => $runs->where('status', 'technical_error')->count(),
                ],
            );
        }
    }

    public function failed(Throwable $exception): void
    {
        LabAgent::query()->whereIn('id', $this->labAgentIds)
            ->whereIn('lifecycle_status', ['queued', 'screening'])
            ->update([
                'lifecycle_status' => 'evaluation_error',
                'decision_reason' => 'Bounded screening batch exhausted operational retries; strategy verdict withheld.',
            ]);
        report($exception);
    }
}
