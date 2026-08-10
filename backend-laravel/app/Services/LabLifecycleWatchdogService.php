<?php

namespace App\Services;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\CandidateGateDecision;
use App\Models\Candle;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\PaperOrder;
use App\Models\PaperSignal;
use App\Models\ShadowVetoObservation;
use App\Models\Symbol;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Detects lifecycle/evidence failures without changing any promotion gate.
 * The only repair is a bounded requeue of a demonstrably abandoned full
 * replay.  All other findings are durable operational warnings.
 */
class LabLifecycleWatchdogService
{
    private const STALE_TRAINING_MINUTES = 30;
    private const FULL_STALL_MINUTES = 60;
    private const DRAFT_STALL_MINUTES = 90;
    private const MAX_SAFE_REQUEUES = 2;

    public function __construct(private readonly SystemLogService $logs) {}

    public function inspect(bool $repair = false): array
    {
        $events = [];
        $events = [...$events, ...$this->watchStaleTraining($repair)];
        $events = [...$events, ...$this->watchFullValidationStalls($repair)];
        $events = [...$events, ...$this->watchOrphanedOpenRuns($repair)];
        $events = [...$events, ...$this->watchDraftDispatchStalls()];
        $events = [...$events, ...$this->watchMissingForwardLedgers()];
        $events = [...$events, ...$this->watchShadowConnection()];
        $events = [...$events, ...$this->watchPaperCapture()];
        $events = [...$events, ...$this->watchPaperIntegrity()];

        return $events;
    }

    /**
     * A worker can die after the immutable run is opened and before the
     * queue middleware gets a chance to close it.  Such a row is not strategy
     * evidence and must not remain an apparent active replay forever.  We
     * only reconcile it after all three operational proofs hold: the owning
     * generation is terminal, no queue job still references the agent, and
     * the single Python replay lane explicitly reports idle.
     */
    private function watchOrphanedOpenRuns(bool $repair): array
    {
        $events = [];
        $runs = LabEvaluationRun::query()
            ->with(['generation', 'agent'])
            ->where('status', 'started')
            ->where('started_at', '<=', now()->subMinutes(self::STALE_TRAINING_MINUTES))
            ->orderBy('id')
            ->limit(100)
            ->get();

        if ($runs->isEmpty()) return $events;

        $laneIdle = $this->replayLaneIsIdle();
        $evidence = $repair && $laneIdle ? app(LabImmutableEvidenceService::class) : null;
        foreach ($runs as $run) {
            $generation = $run->generation;
            $agent = $run->agent;
            $screeningComplete = $generation
                && (string) $generation->status === 'screened'
                && ! $generation->agents()->whereIn('lifecycle_status', ['draft', 'queued', 'screening'])->exists();
            $terminalGeneration = $generation
                && (in_array((string) $generation->status, ['completed', 'technical_quarantine', 'abandoned'], true) || $screeningComplete);
            $hasQueuedJob = $agent ? $this->hasAnyLabJob($agent->id) : true;
            $context = [
                'run_id' => $run->run_id,
                'lab_evaluation_run_id' => $run->id,
                'lab_agent_id' => $run->lab_agent_id,
                'generation_id' => $run->lab_generation_id,
                'phase' => $run->phase,
                'started_at' => $run->started_at?->toIso8601String(),
                'generation_status' => $generation?->status,
                'screening_complete' => $screeningComplete,
                'agent_lifecycle_status' => $agent?->lifecycle_status,
                'queue_job_present' => $hasQueuedJob,
                'replay_lane_idle' => $laneIdle,
                'promotion_evidence' => false,
            ];

            if (! $terminalGeneration || $hasQueuedJob) {
                $events[] = $this->warn(
                    'OPEN_LAB_RUN_REQUIRES_REVIEW',
                    'An old immutable lab run remains open, but its lifecycle/queue proof is not sufficient for automatic reconciliation.',
                    $context,
                    $run->id,
                );
                continue;
            }

            $events[] = $this->warn(
                'ORPHANED_OPEN_LAB_RUN',
                'An old open lab run has no active queue/replay owner; it is operational retry evidence, not a strategy verdict.',
                $context,
                $run->id,
            );

            if ($evidence === null) continue;

            $evidence->finishIfOpen(
                $run,
                'retry_released',
                null,
                [],
                [
                    'reason_code' => 'ORPHANED_OPEN_RUN_RECONCILED',
                    'recovery_protocol' => 'orphaned_open_run_v1',
                    'terminal_generation_proven' => true,
                    'no_queue_job_proven' => true,
                    'replay_lane_idle_proven' => true,
                    'promotion_evidence' => false,
                ],
            );
            $events[] = $this->warn(
                'ORPHANED_OPEN_LAB_RUN_RECONCILED',
                'Closed the orphaned immutable run as retry_released after terminal lifecycle and idle-lane proofs; no strategy gate was changed.',
                $context,
                $run->id,
                'info',
            );
        }

        return $events;
    }

    private function hasAnyLabJob(int $agentId): bool
    {
        if (! DB::getSchemaBuilder()->hasTable('jobs')) return false;

        return DB::table('jobs')
            ->whereIn('queue', ['lab-xauusd', 'lab-eurusd', 'lab-gbpusd', 'lab-full-validation'])
            ->where('payload', 'like', '%labAgentId%'.$agentId.'%')
            ->exists();
    }

    private function replayLaneIsIdle(): bool
    {
        $url = rtrim((string) config('services.ai_service.url'), '/').'/api/replay-status';
        $token = (string) config('services.internal_api.token');
        if ($url === '/api/replay-status' || $token === '') return false;

        try {
            $response = Http::connectTimeout(2)->timeout(4)
                ->withHeaders(['X-Internal-Token' => $token])->get($url);
            if ($response->failed()) return false;
            $body = $response->json();
            return (string) data_get($body, 'protocol', '') !== ''
                && (int) data_get($body, 'active_requests', -1) === 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Draft is normal for one scheduler tick; beyond 90 minutes it is a
     * lifecycle finding, never an excuse to create a duplicate generation. */
    private function watchDraftDispatchStalls(): array
    {
        $events = [];
        LabGeneration::query()->with(['laboratory', 'agents'])
            ->whereIn('status', ['draft', 'queued'])
            ->where('updated_at', '<=', now()->subMinutes(self::DRAFT_STALL_MINUTES))
            ->each(function (LabGeneration $generation) use (&$events): void {
                $queued = $generation->agents->whereIn('lifecycle_status', ['queued', 'screening'])->count();
                $events[] = $this->warn('LAB_GENERATION_DISPATCH_STALLED', 'Generation remained draft/queued for over 90 minutes; no duplicate dispatch was created.', [
                    'generation_id' => $generation->id, 'generation' => $generation->generation,
                    'symbol' => $generation->laboratory?->symbol, 'timeframe' => $generation->laboratory?->timeframe,
                    'status' => $generation->status, 'queued_or_screening_agents' => $queued,
                    'next_action' => 'inspect scheduler, feed health and existing queue job before manual dispatch',
                ], $generation->id);
            });
        return $events;
    }

    private function watchStaleTraining(bool $repair): array
    {
        $events = [];
        $cutoff = now()->subMinutes(self::STALE_TRAINING_MINUTES);
        LabAgent::query()->with('modelVersion')->where('lifecycle_status', 'training')
            // A generation that already completed belongs to historical audit;
            // it must not be resurrected by the operational watchdog.
            ->whereHas('generation', fn ($query) => $query->where('status', 'full_validation'))
            ->where('updated_at', '<=', $cutoff)->each(function (LabAgent $agent) use (&$events, $repair): void {
                $retryCount = (int) data_get($agent->modelVersion?->metadata, 'full_validation_watchdog.retry_count', 0);
                $context = ['lab_agent_id' => $agent->id, 'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe,
                    'stale_since' => $agent->updated_at?->toIso8601String(), 'retry_count' => $retryCount];
                $events[] = $this->warn('STALE_TRAINING_AGENT', 'Full-validation agent remains in training without a completed lifecycle.', $context, $agent->id);

                if ($retryCount >= self::MAX_SAFE_REQUEUES) {
                    $events[] = $this->warn('FULL_VALIDATION_RETRY_EXHAUSTED', 'Stale full validation exhausted its two safe retries and now requires diagnosis; no gate status changed.', $context, $agent->id, 'error');
                    return;
                }
                if (! $repair || ! $this->safeToRequeue($agent)) {
                    return;
                }

                DB::transaction(function () use ($agent, $retryCount): void {
                    $locked = LabAgent::query()->lockForUpdate()->find($agent->id);
                    if (! $locked || $locked->lifecycle_status !== 'training' || ! $this->safeToRequeue($locked)) return;
                    $model = $locked->modelVersion()->lockForUpdate()->first();
                    if (! $model) return;
                    $metadata = $model->metadata ?? [];
                    data_set($metadata, 'full_validation_watchdog', [
                        'retry_count' => $retryCount + 1,
                        'last_requeued_at' => now()->toIso8601String(),
                        'reason' => 'STALE_TRAINING_AGENT',
                    ]);
                    $model->update(['metadata' => $metadata]);
                    $locked->update([
                        'lifecycle_status' => 'full_queued',
                        'decision_reason' => 'Watchdog safely requeued an abandoned full-validation job; promotion evidence unchanged.',
                    ]);
                    EvaluateLabAgentJob::dispatch($locked->id, $locked->symbol, 'full');
                });
                $events[] = $this->warn('STALE_TRAINING_AGENT_REQUEUED', 'Safely requeued stale full validation after verifying no job or replay evidence exists.', $context + ['retry_count' => $retryCount + 1], $agent->id, 'info');
            });
        return $events;
    }

    private function safeToRequeue(LabAgent $agent): bool
    {
        if ($this->hasQueuedFullJob($agent->id)) return false;
        if (ModelMarketPerformance::query()->where('model_version_id', $agent->model_version_id)
            ->where('symbol', $agent->symbol)->where('timeframe', $agent->timeframe)->exists()) return false;
        return ! is_array(data_get($agent->modelVersion?->metadata, 'full_validation_batch.item'));
    }

    private function hasQueuedFullJob(int $agentId): bool
    {
        if (! DB::getSchemaBuilder()->hasTable('jobs')) return false;
        return DB::table('jobs')->where('queue', 'lab-full-validation')
            ->where('payload', 'like', '%labAgentId%'.$agentId.'%')->exists();
    }

    private function watchFullValidationStalls(bool $repair = false): array
    {
        $events = [];
        LabGeneration::query()->where('status', 'full_validation')->with('agents')
            ->where('updated_at', '<=', now()->subMinutes(self::FULL_STALL_MINUTES))->each(function (LabGeneration $generation) use (&$events, $repair): void {
                // Long replays are expected.  "stalled" specifically means
                // that no queued or reserved full-validation job remains.
                if ($generation->agents->contains(fn (LabAgent $agent) => $this->hasQueuedFullJob($agent->id))) return;
                $context = [
                    'generation_id' => $generation->id, 'generation' => $generation->generation,
                    'symbol' => $generation->laboratory?->symbol, 'updated_at' => $generation->updated_at?->toIso8601String(),
                ];
                if (! $repair || ! $this->safeToFinalizeStalledGeneration($generation)) {
                    $events[] = $this->warn('FULL_VALIDATION_STALLED', 'Generation has remained in full validation for over 60 minutes; no lifecycle state was changed.', $context, $generation->id);
                    return;
                }

                DB::transaction(function () use ($generation): void {
                    $locked = LabGeneration::query()->with('agents')->lockForUpdate()->find($generation->id);
                    if (! $locked || $locked->status !== 'full_validation') return;
                    if ($locked->agents->contains(fn (LabAgent $agent) => in_array($agent->lifecycle_status, ['queued', 'screening', 'training', 'full_queued'], true))) return;
                    $triggerContext = (array) $locked->trigger_context;
                    $triggerContext['stale_full_validation_recovery'] = [
                        'protocol' => 'safe_terminal_lifecycle_finalize_v1',
                        'finalized_at' => now()->toIso8601String(),
                        'reason' => 'no_active_job_or_replay_and_all_agents_terminal',
                        'gate_status_unchanged' => true,
                    ];
                    $locked->update([
                        'status' => 'completed',
                        'completed_at' => now(),
                        'trigger_context' => $triggerContext,
                    ]);
                });
                $events[] = $this->warn('FULL_VALIDATION_STALE_FINALIZED', 'Safely finalized a stale full-validation generation after proving that no job/replay remained and every agent was terminal.', $context, $generation->id, 'info');
            });
        return $events;
    }

    private function safeToFinalizeStalledGeneration(LabGeneration $generation): bool
    {
        if ($generation->agents->isEmpty()) return false;
        if ($generation->agents->contains(fn (LabAgent $agent): bool => in_array($agent->lifecycle_status, ['queued', 'screening', 'training', 'full_queued'], true))) return false;
        if ($generation->agents->contains(fn (LabAgent $agent): bool => $this->hasQueuedFullJob($agent->id))) return false;

        $url = rtrim((string) config('services.ai_service.url'), '/').'/api/replay-status';
        $token = (string) config('services.internal_api.token');
        if ($url === '/api/replay-status' || $token === '') return false;

        try {
            $response = Http::connectTimeout(2)->timeout(4)
                ->withHeaders(['X-Internal-Token' => $token])->get($url);
            if ($response->failed()) return false;
            $body = $response->json();
            return (string) data_get($body, 'protocol', '') !== ''
                && (int) data_get($body, 'active_requests', -1) === 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function watchMissingForwardLedgers(): array
    {
        $events = [];
        LabGeneration::query()->where('status', 'completed')->with('agents.modelVersion')->each(function (LabGeneration $generation) use (&$events): void {
            // Legacy generations are intentionally handled by the manual,
            // immutable backfill command.  This watchdog covers only a full
            // replay that advertises the post-change observability protocol.
            $evaluated = $generation->agents->filter(fn (LabAgent $agent) =>
                (int) data_get($agent->modelVersion?->metadata, 'last_result.observability_protocol_version', 0) >= 1
                && ModelMarketPerformance::query()->where('model_version_id', $agent->model_version_id)->exists()
            );
            if ($evaluated->isEmpty()) return;
            $hasLedger = CandidateGateDecision::query()->where('stage', 'statistical_forward_gate')
                ->whereIn('lab_agent_id', $evaluated->pluck('id'))->exists();
            if (! $hasLedger) {
                $events[] = $this->warn('FORWARD_LEDGER_NOT_WRITTEN', 'Completed generation has full replay evidence but no statistical forward-gate decision.', [
                    'generation_id' => $generation->id, 'generation' => $generation->generation,
                    'evaluated_agents' => $evaluated->pluck('id')->all(),
                ], $generation->id);
            }
        });
        return $events;
    }

    private function watchShadowConnection(): array
    {
        $events = [];
        LabAgent::query()->with('modelVersion')->each(function (LabAgent $agent) use (&$events): void {
            $result = (array) data_get($agent->modelVersion?->metadata, 'last_result', []);
            if ((int) data_get($result, 'observability_protocol_version', 0) < 1) return;
            $rejected = (int) data_get($result, 'entry_funnel.entry_rejection_count', 0);
            $shadows = (int) data_get($result, 'veto_regret.shadow_trade_count', 0);
            if ($rejected > 0 && $shadows === 0 && ! ShadowVetoObservation::query()->where('lab_agent_id', $agent->id)->exists()) {
                $events[] = $this->warn('SHADOW_ENGINE_NOT_CONNECTED', 'Replay reported veto rejections but no shadow counterfactuals were persisted.', [
                    'lab_agent_id' => $agent->id, 'rejected_signals' => $rejected, 'shadow_trade_count' => $shadows,
                ], $agent->id);
            }
        });
        return $events;
    }

    private function watchPaperCapture(): array
    {
        $events = [];
        ModelMarketPerformance::query()->where('status', 'forward_validated')->each(function (ModelMarketPerformance $candidate) use (&$events): void {
            $symbolId = Symbol::query()->where('code', $candidate->symbol)->value('id');
            if (! $symbolId) return;
            $since = $candidate->updated_at ?: $candidate->created_at;
            $candles = Candle::query()->where('symbol_id', $symbolId)->where('timeframe', $candidate->timeframe)
                ->where('time', '>', $since)->count();
            if ($candles < 3 || PaperSignal::query()->where('model_market_performance_id', $candidate->id)->exists()) return;
            $events[] = $this->warn('PAPER_CAPTURE_BLOCKED', 'Forward-valid candidate has seen three candles without a recorded paper signal; no paper status was changed.', [
                'performance_id' => $candidate->id, 'symbol' => $candidate->symbol, 'timeframe' => $candidate->timeframe,
                'candles_since_forward_validation' => $candles,
                'reason' => 'NO_SIGNAL_OPPORTUNITY_OR_CAPTURE_PATH_REQUIRES_INSPECTION',
            ], $candidate->id);
        });
        return $events;
    }

    private function watchPaperIntegrity(): array
    {
        $events = [];
        PaperOrder::query()->where('evidence_status', 'valid')->whereNull('paper_signal_id')->each(function (PaperOrder $order) use (&$events): void {
            $events[] = $this->warn('PAPER_INTEGRITY_ERROR', 'Paper order has no immutable paper_signal_id; it cannot count as valid paper evidence.', [
                'paper_order_id' => $order->id, 'performance_id' => $order->model_market_performance_id,
            ], $order->id, 'critical');
        });
        return $events;
    }

    private function warn(string $code, string $message, array $context, int $sourceId, string $level = 'warning'): array
    {
        $key = 'lab-watchdog:'.sha1($code.':'.$sourceId);
        $isNew = Cache::add($key, true, now()->addMinutes(30));
        if ($isNew) {
            $this->logs->write($code, $message, $context, $level, 'lab_lifecycle_watchdog', 'inspect', 'open', null, $sourceId);
        }
        return ['code' => $code, 'source_id' => $sourceId, 'new' => $isNew, 'context' => $context];
    }
}
