<?php

namespace App\Services;

use App\Models\LabFailureDojoRun;
use App\Models\LabLearningLaneDispatch;
use App\Models\LabLearningLanePair;
use App\Models\LearningRecoveryEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciles learning backlog without deleting evidence or pretending that a
 * failed/legacy row was a successful skill. It creates retry projections and
 * append-only recovery events; promotion and strategy state are untouched.
 */
class LearningRecoveryReconciliationService
{
    public const PROTOCOL = 'learning_reconciliation_recovery_v1';

    public function inspect(string $symbol, string $timeframe, int $limit = 100): array
    {
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        $pendingDojo = Schema::hasTable('lab_failure_dojo_runs')
            ? LabFailureDojoRun::query()->where(compact('symbol', 'timeframe'))->where('status', 'pending')->count()
            : 0;
        $failedJobs = $this->failedLabJobs($symbol, $timeframe);
        $pairs = LabLearningLanePair::query()
            ->where(compact('symbol', 'timeframe'))
            ->whereIn('status', ['screen_paired', 'provisional', 'learning_queued', 'learning_observed'])
            ->limit(max(1, min(500, $limit)))
            ->get();
        $validPairs = $pairs->filter(fn (LabLearningLanePair $pair): bool => $this->verifiedPair($pair))->count();

        return [
            'protocol' => self::PROTOCOL,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'pending_dojo' => $pendingDojo,
            'failed_lab_jobs' => count($failedJobs),
            'candidate_pairs_scanned' => $pairs->count(),
            'verified_pairs' => $validPairs,
            'invalid_pairs' => $pairs->count() - $validPairs,
            'recovery_events' => Schema::hasTable('learning_recovery_events')
                ? LearningRecoveryEvent::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->count()
                : 0,
            'promotion_evidence' => false,
        ];
    }

    public function reconcile(string $symbol, string $timeframe, int $limit = 100, bool $apply = false): array
    {
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        $result = $this->inspect($symbol, $timeframe, $limit) + [
            'apply' => $apply,
            'dojo_recovery_queued' => 0,
            'dojo_diagnostic_only' => 0,
            'failed_jobs_requeued' => 0,
            'rows' => [],
        ];
        if (! $apply || ! Schema::hasTable('learning_recovery_events')) return $result;

        $runs = LabFailureDojoRun::query()
            ->where(compact('symbol', 'timeframe'))
            ->where('status', 'pending')
            ->oldest('id')->limit(max(1, min(500, $limit)))->get();
        foreach ($runs as $run) {
            $pair = $run->pair_id ? LabLearningLanePair::query()->find($run->pair_id) : null;
            $key = 'dojo:'.$run->id;
            if (! $pair || ! $this->verifiedPair($pair)) {
                $this->event($key, 'dojo', (string) $run->id, $symbol, $timeframe, 'diagnostic_only', 'CONTROL_PAIR_INVALID', ['pair_id' => $run->pair_id]);
                $run->update([
                    'status' => 'diagnostic_only',
                    'evidence' => [
                        ...((array) $run->evidence),
                        'recovery' => ['protocol' => self::PROTOCOL, 'status' => 'diagnostic_only', 'reason' => 'CONTROL_PAIR_INVALID'],
                        'promotion_evidence' => false,
                    ],
                ]);
                $result['dojo_diagnostic_only']++;
                continue;
            }

            $active = LabLearningLaneDispatch::query()->where('pair_id', $pair->id)
                ->whereIn('status', ['selected', 'queued', 'running', 'retry_ready'])->exists();
            if ($active) continue;
            $dispatch = LabLearningLaneDispatch::firstOrCreate(
                ['dispatch_key' => 'recovery:dojo:'.$run->id],
                [
                    'pair_id' => $pair->id,
                    'lab_generation_id' => $pair->lab_generation_id,
                    'lab_agent_id' => $pair->candidate_agent_id,
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'strategy_family' => $pair->strategy_family,
                    'target' => $pair->target,
                    'specialist_role' => $pair->specialist_role,
                    'status' => 'retry_ready',
                    'stage' => 'micro',
                    'micro_status' => 'pending',
                    'metadata' => ['protocol' => self::PROTOCOL, 'dojo_run_id' => $run->id, 'promotion_evidence' => false],
                    'selected_at' => now(),
                ],
            );
            $run->update([
                'status' => 'recovery_queued',
                'evidence' => [
                    ...((array) $run->evidence),
                    'recovery' => ['protocol' => self::PROTOCOL, 'status' => 'recovery_queued', 'dispatch_id' => $dispatch->id],
                    'promotion_evidence' => false,
                ],
            ]);
            $this->event('dojo:'.$run->id, 'dojo', (string) $run->id, $symbol, $timeframe, 'recovery_queued', 'MICRO_DISPATCH_RECREATED', ['dispatch_id' => $dispatch->id]);
            $result['dojo_recovery_queued']++;
        }

        foreach ($this->failedLabJobs($symbol, $timeframe) as $job) {
            $key = 'failed_job:'.(string) $job->uuid;
            if (LearningRecoveryEvent::query()->where('event_key', $key)->whereIn('status', ['requeued', 'reconciled'])->exists()) continue;
            $payload = (string) $job->payload;
            $command = $this->commandPayload($payload);
            if (! preg_match('/labAgentId.*?i:(\d+);/s', $command, $match)) {
                $this->event($key, 'failed_job', (string) $job->uuid, $symbol, $timeframe, 'manual_review', 'AGENT_ID_NOT_IDENTIFIABLE', []);
                continue;
            }
            $agentId = (int) $match[1];
            $agent = DB::table('lab_agents')->where('id', $agentId)->first();
            if (! $agent || strtoupper((string) $agent->symbol) !== $symbol || strtoupper((string) $agent->timeframe) !== $timeframe) {
                $this->event($key, 'failed_job', (string) $job->uuid, $symbol, $timeframe, 'manual_review', 'AGENT_SCOPE_UNCONFIRMED', ['agent_id' => $agentId]);
                continue;
            }
            try {
                Queue::connection((string) $job->connection)->pushRaw($payload, (string) $job->queue);
                $this->event($key, 'failed_job', (string) $job->uuid, $symbol, $timeframe, 'requeued', 'FAILED_JOB_REQUEUED_WITHOUT_DELETION', ['agent_id' => $agentId, 'queue' => $job->queue]);
                $result['failed_jobs_requeued']++;
            } catch (\Throwable $exception) {
                $this->event($key, 'failed_job', (string) $job->uuid, $symbol, $timeframe, 'manual_review', 'FAILED_JOB_REQUEUE_ERROR', ['error' => substr($exception->getMessage(), 0, 500)]);
            }
        }

        return $result;
    }

    private function verifiedPair(LabLearningLanePair $pair): bool
    {
        return (string) $pair->pair_integrity_status === 'verified'
            && (bool) $pair->same_generation
            && filled($pair->candidate_data_hash)
            && filled($pair->control_data_hash)
            && hash_equals((string) $pair->candidate_data_hash, (string) $pair->control_data_hash)
            && filled($pair->candidate_execution_hash)
            && filled($pair->control_execution_hash)
            && hash_equals((string) $pair->candidate_execution_hash, (string) $pair->control_execution_hash);
    }

    private function event(string $key, string $type, string $source, string $symbol, string $timeframe, string $status, string $reason, array $metadata): void
    {
        LearningRecoveryEvent::updateOrCreate(
            ['event_key' => $key],
            [
                'source_type' => $type,
                'source_key' => $source,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'status' => $status,
                'action' => $status,
                'reason' => $reason,
                'metadata' => ['protocol' => self::PROTOCOL, ...$metadata, 'promotion_evidence' => false],
                'reconciled_at' => now(),
            ],
        );
    }

    /** @return array<int, object> */
    private function failedLabJobs(string $symbol, string $timeframe): array
    {
        if (! Schema::hasTable('failed_jobs')) return [];
        $queues = app(LabQueueJobInspector::class)->labQueues();
        return DB::table('failed_jobs')->whereIn('queue', $queues)->get(['uuid', 'connection', 'queue', 'payload'])->filter(function ($job) use ($symbol, $timeframe): bool {
            if (! preg_match('/labAgentId.*?i:(\d+);/s', $this->commandPayload((string) $job->payload), $match)) return true;
            $agent = DB::table('lab_agents')->where('id', (int) $match[1])->first();
            return $agent && strtoupper((string) $agent->symbol) === $symbol && strtoupper((string) $agent->timeframe) === $timeframe;
        })->values()->all();
    }

    private function commandPayload(string $payload): string
    {
        $decoded = json_decode($payload, true);

        return (string) data_get($decoded, 'data.command', $payload);
    }
}
