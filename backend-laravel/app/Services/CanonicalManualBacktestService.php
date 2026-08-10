<?php

namespace App\Services;

use App\Jobs\RunBacktestJob;
use App\Models\LabEvaluationRun;
use Illuminate\Support\Str;

/**
 * Opens and dispatches a manual replay in the canonical evidence plane.
 *
 * A manual replay is an evaluation run with no laboratory agent attached. It
 * is still immutable, hash-addressed, and processed by the same execution
 * contract as screening/full validation. Legacy BacktestRun, Trade and
 * Mistake rows are deliberately not part of this service.
 */
class CanonicalManualBacktestService
{
    public function __construct(private LabImmutableEvidenceService $evidence) {}

    public function requestHash(array $payload): string
    {
        return $this->evidence->hash($payload);
    }

    public function submit(array $payload, ?string $requestHash = null, array $metadata = []): LabEvaluationRun
    {
        $requestHash ??= $this->requestHash($payload);
        $active = LabEvaluationRun::query()
            ->where('phase', 'manual_backtest')
            ->where('request_hash', $requestHash)
            ->whereIn('status', ['queued', 'started'])
            ->latest('id')
            ->first();

        if ($active) {
            return $active->fresh();
        }

        $run = LabEvaluationRun::create([
            'run_id' => (string) Str::uuid(),
            'phase' => 'manual_backtest',
            'mode' => 'manual',
            'attempt' => 1,
            'queue' => 'backtests',
            'status' => 'queued',
            'request_hash' => $requestHash,
            'request_meta' => [
                'payload' => $payload,
                'payload_hash' => $requestHash,
                'protocol' => 'manual_backtest_evaluation_v2',
            ],
            'metadata' => [
                'protocol' => 'manual_backtest_evaluation_v2',
                'source' => 'manual_backtest',
                ...$metadata,
            ],
        ]);

        RunBacktestJob::dispatch($run->id, $requestHash, $payload);

        return $run->fresh();
    }

    public function findByIdempotencyKey(string $idempotencyKeyHash): ?LabEvaluationRun
    {
        return LabEvaluationRun::query()
            ->where('phase', 'manual_backtest')
            ->where('metadata->idempotency_key_hash', $idempotencyKeyHash)
            ->latest('id')
            ->first();
    }

    public function payloadFor(LabEvaluationRun $run): array
    {
        return (array) data_get($run->request_meta, 'payload', []);
    }

    public function responseFor(LabEvaluationRun $run): array
    {
        return (array) ($this->evidence->latestArtifactPayload($run) ?? []);
    }
}
