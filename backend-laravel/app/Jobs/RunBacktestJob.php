<?php

namespace App\Jobs;

use App\Models\LabEvaluationRun;
use App\Services\BacktestExecutionService;
use App\Services\LabImmutableEvidenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Execute one canonical manual LabEvaluationRun. */
class RunBacktestJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 2;

    public int $uniqueFor = 1800;

    /**
     * Property name is retained for already-queued legacy payloads. Its value
     * is now the LabEvaluationRun primary key, never a BacktestRun id.
     */
    public function __construct(public int $backtestRunId, public string $requestHash, public array $payload)
    {
        $this->onQueue('backtests');
    }

    public function uniqueId(): string
    {
        return 'canonical-manual-backtest:'.$this->requestHash;
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(BacktestExecutionService $service): void
    {
        $run = LabEvaluationRun::find($this->backtestRunId);
        if (! $run) {
            // Jobs serialized before the canonical migration may still carry
            // a BacktestRun id. Consume them without recreating the legacy
            // projection or retrying forever against a non-existent run.
            report(new \RuntimeException(
                "Stale manual backtest job {$this->backtestRunId} skipped: canonical LabEvaluationRun not found."
            ));

            return;
        }
        if (app(LabImmutableEvidenceService::class)->isTerminalRun($run)) {
            return;
        }

        $service->execute($run, $this->payload);
    }

    public function failed(Throwable $exception): void
    {
        $run = LabEvaluationRun::find($this->backtestRunId);
        if (! $run) {
            return;
        }

        app(LabImmutableEvidenceService::class)->finishIfOpen(
            $run,
            'technical_error',
            null,
            [],
            [
                'reason_code' => 'MANUAL_BACKTEST_QUEUE_ERROR',
                'error_file' => $exception->getFile(),
                'error_line' => $exception->getLine(),
            ],
            $exception,
        );
    }
}
