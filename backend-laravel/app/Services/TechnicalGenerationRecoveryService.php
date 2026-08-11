<?php

namespace App\Services;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Repairs only operationally stale screening work.  It never emits a quality
 * verdict: a failed probe, queue, or mutex is evidence about infrastructure,
 * not about the strategy that happened to be waiting behind it.
 */
class TechnicalGenerationRecoveryService
{
    public const PROTOCOL = 'technical_generation_recovery_v1';

    public function __construct(
        private LabImmutableEvidenceService $evidence,
        private LabAgentPreflightService $preflight,
        private LabReplayRecoveryService $replayRecovery,
    ) {}

    /** @return array{ready: bool, idle: bool, reason: string} */
    public function readiness(): array
    {
        try {
            $response = Http::connectTimeout(3)->timeout(8)->acceptJson()
                ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])
                ->get(rtrim((string) config('services.ai_service.url'), '/').'/api/replay-status');
            if (! $response->successful()) return ['ready' => false, 'idle' => false, 'reason' => 'AI_READINESS_PROBE_FAILED'];
            $active = $response->json('active_requests');
            if (! is_numeric($active)) return ['ready' => false, 'idle' => false, 'reason' => 'AI_READINESS_PROBE_INVALID'];
            return ['ready' => true, 'idle' => (int) $active === 0, 'reason' => (int) $active === 0 ? 'AI_READY_IDLE' : 'AI_REPLAY_ACTIVE'];
        } catch (\Throwable) {
            return ['ready' => false, 'idle' => false, 'reason' => 'AI_READINESS_PROBE_UNREACHABLE'];
        }
    }

    /**
     * Appends the stale event first.  At most one clean retry is dispatched;
     * the second confirmed stale state becomes technical_quarantine instead
     * of screened/rejected, so selection cannot learn a fictional loss.
     *
     * @return array{retried: int, quarantined: int, skipped: int, reason: string}
     */
    public function recover(array $generationNumbers = [25, 29], int $olderThanMinutes = 90, bool $apply = false): array
    {
        $probe = $this->readiness();
        if (! $probe['ready'] || ! $probe['idle']) return ['retried' => 0, 'quarantined' => 0, 'skipped' => 0, 'reason' => $probe['reason']];

        // A killed worker can leave a database lock row until its explicit
        // expiry. An expired row is historical residue, not a live replay
        // owner, and must not block bounded technical recovery.
        $mutexHeld = DB::table('cache_locks')
            ->where('key', 'like', '%'.(string) config('services.lab_queue.replay_mutex_key', 'neurotrader-ai-heavy-replay').'%')
            ->where('expiration', '>', now()->timestamp)
            ->exists();
        if ($mutexHeld) return ['retried' => 0, 'quarantined' => 0, 'skipped' => 0, 'reason' => 'REPLAY_MUTEX_HELD'];

        $cutoff = now()->subMinutes(max(30, $olderThanMinutes));
        $agents = LabAgent::query()->with(['generation', 'modelVersion'])
            ->whereIn('lifecycle_status', ['queued', 'screening', 'evaluation_error'])
            ->where('updated_at', '<=', $cutoff)
            ->whereHas('generation', fn ($query) => $query->whereIn('generation', $generationNumbers)->whereIn('status', ['screening', 'screened']))
            ->orderBy('id')->get();

        $retried = $quarantined = $skipped = 0;
        foreach ($agents as $agent) {
            $inspection = $this->preflight->inspect($agent, 'screening');
            if (! $inspection['passed']) {
                if ($apply) $this->preflight->quarantine($agent, $inspection, 'technical_recovery_admission');
                $quarantined++;
                continue;
            }
            $queue = (string) config('services.lab_queue.screening_queue', 'lab-screening');
            $queues = array_values(array_unique(array_merge(
                [$queue],
                [(string) config('services.lab_queue.frontier_queue', 'lab-frontier')],
                (array) config('services.lab_queue.legacy_screening_queues', []),
            )));
            $queued = DB::table('jobs')->whereIn('queue', $queues)->where('payload', 'like', '%labAgentId%'.$agent->id.'%')->exists();
            $metadata = (array) ($agent->modelVersion?->metadata ?? []);
            $attempts = (int) data_get($metadata, 'technical_recovery.attempts', 0);
            $this->evidence->recordLifecycle($agent, 'stale_screening_detected', [
                'protocol' => self::PROTOCOL, 'reason_code' => $queued ? 'STALE_SCREENING_QUEUE_PRESENT' : 'STALE_SCREENING_NO_QUEUE',
                'generation' => $agent->generation?->generation, 'queue' => $queue, 'recovery_attempts' => $attempts,
                'ai_readiness' => $probe, 'mutex_held' => false, 'quality_verdict' => 'withheld',
            ], 'screening', null, $attempts, self::class);
            if ($queued) { $skipped++; continue; }

            if ($attempts >= 1) {
                if ($apply) {
                    data_set($metadata, 'technical_recovery', ['protocol' => self::PROTOCOL, 'attempts' => $attempts, 'status' => 'technical_quarantine', 'quarantined_at' => now()->utc()->toIso8601String()]);
                    $agent->modelVersion?->update(['metadata' => $metadata]);
                    $agent->update(['lifecycle_status' => 'technical_quarantine', 'decision_reason' => 'Technical quarantine after one bounded stale-screening retry; no strategy quality verdict.']);
                    $this->evidence->recordLifecycle($agent, 'technical_quarantine', [
                        'protocol' => self::PROTOCOL, 'reason_code' => 'STALE_SCREENING_RETRY_EXHAUSTED', 'quality_verdict' => 'withheld',
                    ], 'screening', null, $attempts, self::class);
                }
                $quarantined++;
                continue;
            }

            try {
                $recoveryContract = $this->replayRecovery->prepare($agent, 'screen');
            } catch (\Throwable $exception) {
                if ($apply) {
                    $agent->update([
                        'lifecycle_status' => 'technical_quarantine',
                        'decision_reason' => 'Technical quarantine: stale-screening recovery dataset/hash contract failed; strategy verdict withheld.',
                    ]);
                    $this->evidence->recordLifecycle($agent, 'technical_quarantine', [
                        'protocol' => self::PROTOCOL,
                        'reason_code' => 'RECOVERY_DATASET_CONTRACT_INVALID',
                        'error_message' => substr($exception->getMessage(), 0, 1000),
                        'quality_verdict' => 'withheld',
                        'promotion_evidence' => false,
                    ], 'screening', null, $attempts, self::class, $exception);
                }
                $quarantined++;
                continue;
            }

            if ($apply) {
                data_set($metadata, 'technical_recovery', ['protocol' => self::PROTOCOL, 'attempts' => 1, 'status' => 'retry_dispatched', 'retried_at' => now()->utc()->toIso8601String()]);
                $agent->modelVersion?->update(['metadata' => $metadata]);
                $agent->update(['lifecycle_status' => 'queued', 'decision_reason' => 'One bounded technical stale-screening retry dispatched; strategy verdict remains withheld.']);
                Bus::dispatch(new EvaluateLabAgentJob($agent->id, $agent->symbol, 'screen', $recoveryContract));
                $this->evidence->recordLifecycle($agent, 'technical_retry_dispatched', [
                    'protocol' => self::PROTOCOL, 'reason_code' => 'STALE_SCREENING_BOUNDED_RETRY', 'quality_verdict' => 'withheld',
                ], 'screening', null, 1, self::class);
            }
            $retried++;
        }

        if ($apply) {
            LabGeneration::query()->whereIn('generation', $generationNumbers)->whereHas('agents', fn ($q) => $q->where('lifecycle_status', 'technical_quarantine'))
                ->each(function (LabGeneration $generation): void {
                    if ($generation->agents()->whereIn('lifecycle_status', ['queued', 'screening', 'evaluation_error'])->doesntExist()) {
                        $generation->update(['status' => 'technical_quarantine', 'completed_at' => now()]);
                    }
                });
        }
        return compact('retried', 'quarantined', 'skipped') + ['reason' => $apply ? 'APPLIED' : 'DRY_RUN'];
    }
}
