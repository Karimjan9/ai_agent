<?php

namespace App\Jobs;

use App\Jobs\Middleware\PreferFullValidationQueue;
use App\Models\LabAgent;
use App\Services\LabAgentEvaluationService;
use App\Services\FrozenControlScreeningAdmissionService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
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
    )
    {
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
        return now()->addHours(6);
    }

    public function screeningMutexKey(): string
    {
        $slot = isset($this->screeningSlot) && $this->screeningSlot !== null
            ? abs((int) $this->screeningSlot) % 2
            : abs((int) ($this->labAgentIds[0] ?? 0)) % 2;

        return (string) config('services.lab_queue.screening_mutex_key', 'neurotrader-ai-screening-replay').":slot{$slot}";
    }

    public function handle(LabAgentEvaluationService $service, FrozenControlScreeningAdmissionService $controlAdmission): void
    {
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

        $service->screenBatch($this->labAgentIds, $this->symbol);
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
