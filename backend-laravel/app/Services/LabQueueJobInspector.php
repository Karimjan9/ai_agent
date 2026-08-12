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
    /** @return array{total: int, queues: array<string, int>} */
    public function labQueueBacklog(): array
    {
        if (! Schema::hasTable('jobs')) return ['total' => 0, 'queues' => []];

        $queues = $this->labQueues();
        $counts = DB::table('jobs')
            ->whereIn('queue', $queues)
            ->selectRaw('queue, COUNT(*) as total')
            ->groupBy('queue')
            ->pluck('total', 'queue')
            ->map(fn ($count): int => (int) $count)
            ->all();

        return ['total' => array_sum($counts), 'queues' => $counts];
    }

    public function hasLabJobs(): bool
    {
        return $this->labQueueBacklog()['total'] > 0;
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
     * @return array<int, int>
     */
    public function queuedJobIdsForAgents(array $agentIds, array $queues = []): array
    {
        $agentIds = array_values(array_unique(array_filter(array_map('intval', $agentIds), static fn (int $id): bool => $id > 0)));
        if ($agentIds === [] || ! Schema::hasTable('jobs')) return [];

        $query = DB::table('jobs')->where('payload', 'like', '%labAgentId%');
        if ($queues !== []) $query->whereIn('queue', array_values(array_unique($queues)));

        return $query->get(['id', 'payload'])
            ->filter(function (object $job) use ($agentIds): bool {
                $decoded = json_decode((string) $job->payload, true);
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
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
