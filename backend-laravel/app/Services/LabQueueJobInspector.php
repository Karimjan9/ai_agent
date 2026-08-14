<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;


/**
 * Match queued lab jobs by the serialized job field, not by an unbounded
 * substring search. A loose LIKE on the numeric agent id also matches batch
 * UUIDs and timestamps, which can make a live queue look owned by the wrong
 * agent and block safe recovery/quarantine.
 */
class LabQueueJobInspector
{
    public function __construct(private readonly LabQueueStateService $state)
    {
    }

    /** @return array{total: int, queues: array<string, int>} */
    public function labQueueBacklog(): array
    {
        $snapshot = $this->state->snapshot($this->labQueues());
        if (($snapshot['available'] ?? true) === false) return ['total' => null, 'queues' => []];

        return ['total' => (int) ($snapshot['total'] ?? 0), 'queues' => (array) ($snapshot['queues'] ?? [])];
    }

    /** @return array<string, mixed> */
    public function queueSnapshot(?array $queues = null): array
    {
        return $this->state->snapshot($queues ?? $this->labQueues());
    }

    public function hasLabJobs(): bool
    {
        $total = $this->labQueueBacklog()['total'];

        return $total === null || $total > 0;
    }

    /** @return array<int, string> */
    public function labQueues(): array
    {
        return array_values(array_unique(array_filter([
            (string) config('services.lab_queue.screening_queue', 'lab-screening'),
            (string) config('services.lab_queue.frontier_queue', 'lab-frontier'),
            (string) config('services.lab_queue.full_validation_queue', 'lab-full-validation'),
            ...((array) config('services.lab_queue.legacy_screening_queues', [])),
        ])));
    }

    /** @param array<int, string> $queues */
    public function hasAgentJob(int $agentId, array $queues = []): bool
    {
        return $this->queuedJobIdsForAgents([$agentId], $queues) !== [];
    }

    /**
     * @param array<int, int> $agentIds
     * @param array<int, string> $queues
     * @return array<int, int|string>
     */
    public function queuedJobIdsForAgents(array $agentIds, array $queues = []): array
    {
        $agentIds = array_values(array_unique(array_filter(array_map('intval', $agentIds), static fn (int $id): bool => $id > 0)));
        if ($agentIds === []) return [];

        $snapshot = $this->state->snapshot($queues !== [] ? array_values(array_unique($queues)) : $this->labQueues());
        if (($snapshot['available'] ?? true) === false) return [];

        return collect((array) ($snapshot['rows'] ?? []))
            ->filter(function (array $job) use ($agentIds): bool {
                $decoded = json_decode((string) ($job['payload'] ?? ''), true);
                $command = (string) data_get($decoded, 'data.command', '');
                if ($command === '') $command = (string) data_get($decoded, 'command', '');

                foreach ($agentIds as $agentId) {
                    // Laravel's queued command is a serialized object after
                    // JSON decoding: s:10:"labAgentId";i:123;. The second
                    // marker keeps compatibility with compact test/legacy
                    // payloads such as labAgentId;123 while preserving the
                    // numeric boundary (123 must not match 1234).
                    if (str_contains($command, 's:10:"labAgentId";i:'.$agentId.';')
                        || preg_match('/labAgentId;'.preg_quote((string) $agentId, '/').'(?=\D|$)/', $command) === 1) {
                        return true;
                    }
                }

                return false;
            })
            ->pluck('id')
            ->map(fn (mixed $id): int|string => is_numeric($id) && ! str_contains((string) $id, '-') ? (int) $id : (string) $id)
            ->values()
            ->all();
    }

    public function fullValidationIsWaiting(): bool
    {
        $queue = (string) config('services.lab_queue.full_validation_queue', 'lab-full-validation');
        $snapshot = $this->state->snapshot([$queue]);
        if (($snapshot['available'] ?? true) === false) return true;

        $stats = (array) data_get($snapshot, "stats.{$queue}", []);
        if ((int) data_get($stats, 'pending', 0) > 0 || (int) data_get($stats, 'reserved', 0) > 0) return true;

        return Schema::hasTable('job_batches')
            && DB::table('job_batches')
                ->whereIn('name', ['Portfolio member full validation', 'Global full validation'])
                ->whereNull('finished_at')->where('pending_jobs', '>', 0)->exists();
    }
}
