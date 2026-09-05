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

    /**
     * Work that can consume a replay worker now.
     *
     * Delayed toolbox/research jobs are intentionally reported separately:
     * they already yield to an active evolution generation and therefore must
     * not starve the Edge director while waiting for their next retry window.
     * Pending and reserved work remain a strict fail-closed boundary.
     *
     * @return array{total: int|null, queues: array<string, int>, delayed: int}
     */
    public function runnableLabQueueBacklog(): array
    {
        $snapshot = $this->state->snapshot($this->labQueues());
        if (($snapshot['available'] ?? true) === false) {
            return ['total' => null, 'queues' => [], 'delayed' => 0];
        }

        $queues = [];
        $delayed = 0;
        foreach ($this->labQueues() as $queue) {
            $stats = (array) data_get($snapshot, "stats.{$queue}", []);
            $runnable = (int) data_get($stats, 'pending', 0)
                + (int) data_get($stats, 'reserved', 0);
            $queues[$queue] = $runnable;
            $delayed += (int) data_get($stats, 'delayed', 0);
        }

        return ['total' => array_sum($queues), 'queues' => $queues, 'delayed' => $delayed];
    }

    /**
     * Reserved jobs whose owning PHP worker would be killed by a PM2 reload.
     * Pending/delayed payloads remain durable in Redis and are reported for
     * observability, but only a reservation is an interruption hazard.
     *
     * @param array<int,string> $queues
     * @return array{total:int|null,queues:array<string,int>,pending:int,delayed:int}
     */
    public function reservedQueueBacklog(array $queues): array
    {
        $queues = array_values(array_unique(array_filter(array_map('strval', $queues))));
        $snapshot = $this->state->snapshot($queues);
        if (($snapshot['available'] ?? true) === false) {
            return ['total' => null, 'queues' => [], 'pending' => 0, 'delayed' => 0];
        }

        $reservedByQueue = [];
        $pending = 0;
        $delayed = 0;
        foreach ($queues as $queue) {
            $stats = (array) data_get($snapshot, "stats.{$queue}", []);
            $reservedByQueue[$queue] = (int) data_get($stats, 'reserved', 0);
            $pending += (int) data_get($stats, 'pending', 0);
            $delayed += (int) data_get($stats, 'delayed', 0);
        }

        return [
            'total' => array_sum($reservedByQueue),
            'queues' => $reservedByQueue,
            'pending' => $pending,
            'delayed' => $delayed,
        ];
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

    /**
     * Return only queued/reserved lab jobs owned by the supplied generation's
     * agents. Global queue work from another generation or the research-only
     * learning lane must not keep this generation's report in progress.
     *
     * Unknown queue state remains fail-closed (total=null). A row without an
     * identifiable lab agent is also treated as unknown rather than silently
     * attributed to a different generation.
     *
     * @param array<int, int> $agentIds
     * @param array<int, string>|null $queues
     * @return array<string, mixed>
     */
    public function generationQueueBacklog(array $agentIds, ?array $queues = null): array
    {
        $agentIds = array_values(array_unique(array_filter(
            array_map('intval', $agentIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($agentIds === []) {
            return ['backend' => $this->state->backend(), 'total' => 0, 'queues' => [], 'rows' => [], 'scope' => 'generation_agents'];
        }

        $snapshot = $this->state->snapshot($queues ?? $this->labQueues());
        if (($snapshot['available'] ?? true) === false) {
            return [
                'backend' => $this->backend(), 'available' => false, 'total' => null,
                'queues' => [], 'rows' => [], 'scope' => 'generation_agents',
            ];
        }

        $rows = collect((array) ($snapshot['rows'] ?? []))
            ->filter(fn (array $row): bool => $this->payloadBelongsToAgents((string) ($row['payload'] ?? ''), $agentIds))
            ->values();

        return [
            'backend' => $snapshot['backend'] ?? $this->backend(),
            'available' => true,
            'total' => $rows->count(),
            'queues' => $rows->groupBy('queue')->map->count()->all(),
            'rows' => $rows->all(),
            'scope' => 'generation_agents',
        ];
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
            ->filter(fn (array $job): bool => $this->payloadBelongsToAgents((string) ($job['payload'] ?? ''), $agentIds))
            ->pluck('id')
            ->map(fn (mixed $id): int|string => is_numeric($id) && ! str_contains((string) $id, '-') ? (int) $id : (string) $id)
            ->values()
            ->all();
    }

    /** @param array<int, int> $agentIds */
    private function payloadBelongsToAgents(string $payload, array $agentIds): bool
    {
        $decoded = json_decode($payload, true);
        $command = (string) data_get($decoded, 'data.command', '');
        if ($command === '') $command = (string) data_get($decoded, 'command', '');
        if ($command === '') return false;

        foreach ($agentIds as $agentId) {
            // Laravel's queued command is serialized as
            // s:10:"labAgentId";i:123;. The compact marker preserves
            // compatibility with older payloads without matching 1234.
            if (str_contains($command, 's:10:"labAgentId";i:'.$agentId.';')
                || preg_match('/labAgentId;'.preg_quote((string) $agentId, '/').'(?=\D|$)/', $command) === 1) {
                return true;
            }

            // Screening batches carry an integer array instead of the
            // singular labAgentId property. Keep the match inside the
            // serialized labAgentIds field so a batch UUID or another
            // serialized integer cannot accidentally claim ownership.
            if (preg_match('/s:\d+:"labAgentIds";a:\d+:\{(.*?)\}/s', $command, $matches) === 1
                && preg_match('/(?<!\d)i:'.preg_quote((string) $agentId, '/').';/', $matches[1]) === 1) {
                return true;
            }
        }

        return false;
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

    /**
     * Whether an agent-owned evolution replay is ready or already reserved.
     *
     * Research-only toolbox jobs use this as a strict priority boundary: a
     * pending/delayed research prior must never take the Python lane ahead of
     * canonical screening or full validation. Delayed evolution jobs are not
     * considered runnable yet and therefore do not starve research forever.
     */
    public function evolutionReplayIsWaiting(array $ignoredJobClasses = []): bool
    {
        // Population construction can take long enough for a scheduled
        // research job to observe an empty queue between generation creation
        // and screening dispatch. Treat the durable active generation as the
        // priority intent, otherwise research can seize the single Python
        // replay lane during that gap and delay/429 the causal cohort.
        if (Schema::hasTable('lab_generations')
            && DB::table('lab_generations')->whereIn('status', [
                'draft', 'queued', 'training', 'screening',
                'full_queued', 'full_validation',
            ])->exists()) {
            return true;
        }

        // Screening can become terminal just after a five-minute selector
        // tick. Reserve the lane through that short screened -> full_queued
        // hand-off as well. The freshness bound prevents an old, permanently
        // ineligible screened projection from starving research forever; the
        // ordinary funnel reconciler remains responsible for such stale rows.
        if (Schema::hasTable('lab_generations')
            && Schema::hasTable('lab_agents')
            && DB::table('lab_generations')
                ->whereIn('status', ['screened', 'completed'])
                ->where('updated_at', '>=', now()->subHour())
                // Reports may touch an old screened generation long after a
                // newer cohort has become terminal. Only the laboratory's
                // latest generation can own the screened -> full handoff;
                // otherwise stale history starves toolbox research forever.
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('lab_generations as newer_generation')
                        ->whereColumn('newer_generation.ai_laboratory_id', 'lab_generations.ai_laboratory_id')
                        ->whereColumn('newer_generation.generation', '>', 'lab_generations.generation');
                })
                ->whereExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('lab_agents')
                        ->whereColumn('lab_agents.lab_generation_id', 'lab_generations.id')
                        ->where('lab_agents.lifecycle_status', 'screened');
                })
                ->exists()) {
            return true;
        }

        $queues = array_values(array_unique(array_filter([
            (string) config('services.lab_queue.screening_queue', 'lab-screening'),
            (string) config('services.lab_queue.full_validation_queue', 'lab-full-validation'),
            ...((array) config('services.lab_queue.legacy_screening_queues', [])),
        ])));
        $snapshot = $this->state->snapshot($queues);
        if (($snapshot['available'] ?? true) === false) return true;

        $ignoredJobClasses = array_values(array_unique(array_filter(array_map('strval', $ignoredJobClasses))));
        foreach ($queues as $queue) {
            if ($ignoredJobClasses !== []) {
                $runnable = collect((array) ($snapshot['rows'] ?? []))
                    ->filter(fn (array $row): bool => (string) ($row['queue'] ?? '') === $queue)
                    ->filter(fn (array $row): bool => in_array((string) ($row['redis_state'] ?? ''), ['pending', 'reserved'], true)
                        || (($row['redis_state'] ?? null) === null && data_get($row, 'reserved_at') !== null)
                        || (($row['redis_state'] ?? null) === null
                            && data_get($row, 'reserved_at') === null
                            && (data_get($row, 'available_at') === null || (int) data_get($row, 'available_at') <= now()->timestamp)))
                    ->reject(fn (array $row): bool => $this->payloadHasAnyJobClass(
                        (string) ($row['payload'] ?? ''),
                        $ignoredJobClasses,
                    ));
                if ($runnable->isNotEmpty()) {
                    return true;
                }
                continue;
            }
            $stats = (array) data_get($snapshot, "stats.{$queue}", []);
            if ((int) data_get($stats, 'pending', 0) > 0
                || (int) data_get($stats, 'reserved', 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int,string> $jobClasses */
    private function payloadHasAnyJobClass(string $payload, array $jobClasses): bool
    {
        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            return false;
        }
        $displayName = ltrim((string) ($decoded['displayName'] ?? ''), '\\');
        $commandName = ltrim((string) data_get($decoded, 'data.commandName', ''), '\\');

        return collect($jobClasses)->contains(function (string $class) use ($displayName, $commandName): bool {
            $class = ltrim($class, '\\');

            return $displayName === $class || $commandName === $class;
        });
    }
}
