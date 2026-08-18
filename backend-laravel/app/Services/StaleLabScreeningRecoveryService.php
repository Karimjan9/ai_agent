<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use Illuminate\Support\Facades\DB;

/**
 * Reclaims a screening run which has lost its worker without manufacturing a
 * strategy result.  This is deliberately narrower than generic batch
 * cancellation: the immutable run is closed as technical_error, the agent is
 * marked evaluation_error, and every promotion/learning consumer remains
 * fail-closed.
 */
class StaleLabScreeningRecoveryService
{
    public const PROTOCOL = 'stale_screening_batch_recovery_v1';

    public function __construct(
        private readonly LabImmutableEvidenceService $evidence,
        private readonly LabQueueJobInspector $queueJobs,
        private readonly LabQueueStateService $queueState,
        private readonly LabGenerationContextService $generationContext,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function inspect(LabGeneration $generation, int $olderThanMinutes = 30): array
    {
        $cutoff = now()->subMinutes(max(30, $olderThanMinutes));
        $runs = $this->staleRuns($generation, $cutoff);
        $agentIds = $generation->agents()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $backlog = $this->queueJobs->generationQueueBacklog($agentIds);

        return [
            'protocol' => self::PROTOCOL,
            'generation_id' => (int) $generation->id,
            'generation' => (int) $generation->generation,
            'cutoff' => $cutoff->toIso8601String(),
            'stale_runs' => $runs->map(fn (LabEvaluationRun $run): array => $this->runPayload($run, $backlog))->values()->all(),
            'queue' => [
                'backend' => $backlog['backend'] ?? null,
                'available' => $backlog['available'] ?? true,
                'total' => $backlog['total'] ?? null,
                'queues' => $backlog['queues'] ?? [],
                'rows' => $backlog['rows'] ?? [],
            ],
            'ai_replay_required' => true,
            'promotion_evidence' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function recover(LabGeneration $generation, int $olderThanMinutes = 30): array
    {
        $cutoff = now()->subMinutes(max(30, $olderThanMinutes));
        $runs = $this->staleRuns($generation, $cutoff);
        $agentIds = $generation->agents()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $backlog = $this->queueJobs->generationQueueBacklog($agentIds);
        if (($backlog['available'] ?? true) === false || $backlog['total'] === null) {
            return [
                'protocol' => self::PROTOCOL,
                'status' => 'blocked',
                'reason_code' => 'QUEUE_STATE_UNKNOWN',
                'reclaimed_runs' => 0,
                'reclaimed_agents' => 0,
                'removed_reserved_payloads' => 0,
                'promotion_evidence' => false,
            ];
        }

        $rows = collect((array) ($backlog['rows'] ?? []));
        $blockedAgents = $rows
            ->filter(fn (array $row): bool => in_array((string) ($row['redis_state'] ?? ''), ['pending', 'delayed'], true))
            ->flatMap(fn (array $row): array => $this->agentIdsFromPayload((string) ($row['payload'] ?? '')))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $reclaimedRuns = [];
        $reclaimedAgents = [];
        $removedReserved = 0;

        foreach ($runs as $candidate) {
            $agentId = (int) $candidate->lab_agent_id;
            if (in_array($agentId, $blockedAgents, true)) {
                continue;
            }

            $reclaimed = DB::transaction(function () use ($candidate, $cutoff, &$reclaimedAgents): ?LabEvaluationRun {
                /** @var LabEvaluationRun|null $run */
                $run = LabEvaluationRun::query()->whereKey($candidate->id)->lockForUpdate()->first();
                if (! $run || ! in_array((string) $run->status, ['started', 'running', 'processing'], true)) {
                    return null;
                }
                if (! $run->started_at || $run->started_at->gt($cutoff)) {
                    return null;
                }

                $agent = $run->agent()->first();
                if (! $agent || ! in_array((string) $agent->lifecycle_status, ['queued', 'screening'], true)) {
                    return null;
                }

                $fromStatus = (string) $agent->lifecycle_status;
                $this->evidence->finishIfOpen($run, 'technical_error', null, [], [
                    'protocol' => self::PROTOCOL,
                    'reason_code' => 'STALE_SCREENING_RUN_RECLAIMED',
                    'quality_verdict' => 'withheld',
                    'strategy_verdict' => 'withheld',
                    'promotion_evidence' => false,
                    'recovery_cutoff' => $cutoff->toIso8601String(),
                ]);
                $agent->update([
                    'lifecycle_status' => 'evaluation_error',
                    'decision_reason' => 'Stale screening run reclaimed after bounded timeout; strategy verdict withheld.',
                ]);
                $this->evidence->recordLifecycle($agent, 'stale_screening_run_reclaimed', [
                    'protocol' => self::PROTOCOL,
                    'reason_code' => 'STALE_SCREENING_RUN_RECLAIMED',
                    'run_id' => $run->run_id,
                    'quality_verdict' => 'withheld',
                    'promotion_evidence' => false,
                ], 'screening', $run->run_id, (int) $run->attempt, self::class, null, $fromStatus, 'evaluation_error');
                $reclaimedAgents[] = (int) $agent->id;

                return $run->fresh();
            }, 1);

            if (! $reclaimed) {
                continue;
            }

            $reclaimedRuns[] = [
                'run_id' => $reclaimed->run_id,
                'run_db_id' => (int) $reclaimed->id,
                'agent_id' => (int) $reclaimed->lab_agent_id,
                'status' => $reclaimed->status,
            ];

        }

        // A reserved batch payload is removed only after every immutable run
        // it owns is terminal. Pending/delayed payloads are never deleted
        // here; they require a separate queue-owner decision. Doing this as a
        // second pass is important when one batch payload owns two agents.
        $terminalAgentIds = LabAgent::query()
            ->where('lab_generation_id', $generation->id)
            ->whereNotIn('lifecycle_status', ['draft', 'queued', 'screening', 'full_queued', 'full_validation', 'training'])
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $cleanupAgentIds = array_values(array_unique([...$reclaimedAgents, ...$terminalAgentIds]));
        if ($cleanupAgentIds !== []) {
            $freshBacklog = $this->queueJobs->generationQueueBacklog($agentIds);
            foreach ((array) ($freshBacklog['rows'] ?? []) as $row) {
                if (($row['redis_state'] ?? null) !== 'reserved') {
                    continue;
                }
                $payload = (string) ($row['payload'] ?? '');
                $payloadAgents = $this->agentIdsFromPayload($payload);
                if (array_intersect($payloadAgents, $cleanupAgentIds) === []) {
                    continue;
                }
                if ($payloadAgents === [] || array_diff($payloadAgents, $agentIds) !== []) {
                    continue;
                }
                $openOwnedRun = LabEvaluationRun::query()
                    ->where('lab_generation_id', $generation->id)
                    ->whereIn('lab_agent_id', $payloadAgents)
                    ->whereIn('status', ['started', 'running', 'processing'])
                    ->exists();
                $openOwnedAgent = LabAgent::query()
                    ->where('lab_generation_id', $generation->id)
                    ->whereIn('id', $payloadAgents)
                    ->whereIn('lifecycle_status', ['queued', 'screening'])
                    ->exists();
                if ($openOwnedRun || $openOwnedAgent) {
                    continue;
                }
                if ($this->queueState->removeCompletedReservedPayload((string) $row['queue'], $payload)) {
                    $removedReserved++;
                }
            }
        }

        if ($reclaimedRuns !== []) {
            $this->generationContext->update($generation, function (array $context) use ($reclaimedRuns, $reclaimedAgents, $removedReserved): array {
                $history = (array) data_get($context, 'integrity_repair.stale_screening_batch_recovery.history', []);
                $history[] = [
                    'protocol' => self::PROTOCOL,
                    'reclaimed_runs' => $reclaimedRuns,
                    'reclaimed_agents' => array_values(array_unique($reclaimedAgents)),
                    'removed_reserved_payloads' => $removedReserved,
                    'quality_verdict' => 'withheld',
                    'promotion_evidence' => false,
                    'recovered_at' => now()->utc()->toIso8601String(),
                ];
                data_set($context, 'integrity_repair.stale_screening_batch_recovery.protocol', self::PROTOCOL);
                data_set($context, 'integrity_repair.stale_screening_batch_recovery.history', array_slice($history, -20));
                data_set($context, 'integrity_repair.stale_screening_batch_recovery.promotion_evidence', false);
                data_set($context, 'integrity_repair.stale_screening_batch_recovery.quality_verdict', 'withheld');

                return $context;
            });
        }

        return [
            'protocol' => self::PROTOCOL,
            'status' => 'applied',
            'reclaimed_runs' => count($reclaimedRuns),
            'reclaimed_agents' => count(array_unique($reclaimedAgents)),
            'removed_reserved_payloads' => $removedReserved,
            'blocked_pending_agents' => $blockedAgents,
            'runs' => $reclaimedRuns,
            'promotion_evidence' => false,
        ];
    }

    private function staleRuns(LabGeneration $generation, \DateTimeInterface $cutoff)
    {
        return LabEvaluationRun::query()
            ->with('agent')
            ->where('lab_generation_id', $generation->id)
            ->where('phase', 'screening')
            ->whereIn('status', ['started', 'running', 'processing'])
            ->where('started_at', '<=', $cutoff)
            ->orderBy('started_at')
            ->get();
    }

    /** @return array<string, mixed> */
    private function runPayload(LabEvaluationRun $run, array $backlog): array
    {
        $rows = collect((array) ($backlog['rows'] ?? []))
            ->filter(fn (array $row): bool => in_array((int) $run->lab_agent_id, $this->agentIdsFromPayload((string) ($row['payload'] ?? '')), true))
            ->map(fn (array $row): array => [
                'queue' => $row['queue'] ?? null,
                'id' => $row['id'] ?? null,
                'state' => $row['redis_state'] ?? null,
                'attempts' => $row['attempts'] ?? null,
            ])->values()->all();

        return [
            'run_id' => $run->run_id,
            'run_db_id' => (int) $run->id,
            'agent_id' => (int) $run->lab_agent_id,
            'status' => $run->status,
            'started_at' => $run->started_at?->toIso8601String(),
            'queue_rows' => $rows,
        ];
    }

    /** @return array<int, int> */
    private function agentIdsFromPayload(string $payload): array
    {
        $decoded = json_decode($payload, true);
        $command = (string) data_get($decoded, 'data.command', data_get($decoded, 'command', ''));
        if ($command === '') return [];

        $ids = [];
        if (preg_match('/s:10:"labAgentId";i:(\d+);/', $command, $match) === 1) {
            $ids[] = (int) $match[1];
        }
        if (preg_match('/s:\d+:"labAgentIds";a:\d+:\{(.*?)\}/s', $command, $match) === 1
            && preg_match_all('/i:(\d+);/', $match[1], $matches) !== false) {
            $ids = [...$ids, ...array_map('intval', $matches[1])];
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }
}
