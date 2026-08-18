<?php

namespace App\Services;

use App\Models\LabGeneration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Serializes append-only generation context updates without changing any
 * strategy or promotion decision.  Several scheduler/worker paths write the
 * JSON context concurrently; a short row lock plus bounded retry prevents a
 * transient MySQL lock timeout from being reported as a pipeline failure.
 */
class LabGenerationContextService
{
    public const PROTOCOL = 'lab_generation_context_write_v1';

    /**
     * @param callable(array<string, mixed>, LabGeneration): array<string, mixed> $mutator
     */
    public function update(LabGeneration $generation, callable $mutator, int $attempts = 4): LabGeneration
    {
        return $this->updateWithAttributes($generation, [], $mutator, $attempts);
    }

    /**
     * Update generation context together with small lifecycle attributes
     * under the same short row lock.  This keeps terminal projections
     * atomic without allowing a large JSON write to sit in a long-running
     * caller transaction.
     *
     * @param array<string, mixed> $attributes
     * @param callable(array<string, mixed>, LabGeneration): array<string, mixed> $mutator
     */
    public function updateWithAttributes(
        LabGeneration $generation,
        array $attributes,
        callable $mutator,
        int $attempts = 4,
    ): LabGeneration
    {
        $attempts = max(1, $attempts);
        $last = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $fresh = DB::transaction(function () use ($generation, $attributes, $mutator): LabGeneration {
                    $locked = LabGeneration::query()
                        ->whereKey($generation->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();

                    $context = (array) ($locked->trigger_context ?? []);
                    $next = $mutator($context, $locked);
                    if (! is_array($next)) {
                        throw new \UnexpectedValueException('Generation context mutator must return an array.');
                    }

                    $locked->updateQuietly([
                        ...$attributes,
                        'trigger_context' => $next,
                    ]);

                    return $locked;
                }, 1);

                $generation->setRawAttributes($fresh->getAttributes(), true);

                return $generation;
            } catch (Throwable $exception) {
                $last = $exception;
                if (! $this->retryableLockFailure($exception) || $attempt === $attempts) {
                    throw $exception;
                }

                // Keep the retry bounded and deterministic.  This does not
                // turn a failed write into evidence; it only gives a competing
                // short metadata write time to release its row lock.
                usleep(150000 * $attempt);
            }
        }

        throw $last ?? new \RuntimeException('Generation context update failed.');
    }

    private function retryableLockFailure(Throwable $exception): bool
    {
        if (! $exception instanceof QueryException) {
            return false;
        }

        $code = (string) ($exception->errorInfo[1] ?? $exception->getCode());
        $message = strtolower($exception->getMessage());

        return in_array($code, ['1205', '1213'], true)
            || str_contains($message, 'lock wait timeout')
            || str_contains($message, 'deadlock found');
    }
}
