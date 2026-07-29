<?php

namespace App\Services;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\CandidateGateDecision;
use App\Models\Candle;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\PaperOrder;
use App\Models\PaperSignal;
use App\Models\ShadowVetoObservation;
use App\Models\Symbol;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Detects lifecycle/evidence failures without changing any promotion gate.
 * The only repair is a bounded requeue of a demonstrably abandoned full
 * replay.  All other findings are durable operational warnings.
 */
class LabLifecycleWatchdogService
{
    private const STALE_TRAINING_MINUTES = 30;
    private const FULL_STALL_MINUTES = 60;
    private const MAX_SAFE_REQUEUES = 2;

    public function __construct(private readonly SystemLogService $logs) {}

    public function inspect(bool $repair = false): array
    {
        $events = [];
        $events = [...$events, ...$this->watchStaleTraining($repair)];
        $events = [...$events, ...$this->watchFullValidationStalls()];
        $events = [...$events, ...$this->watchMissingForwardLedgers()];
        $events = [...$events, ...$this->watchShadowConnection()];
        $events = [...$events, ...$this->watchPaperCapture()];
        $events = [...$events, ...$this->watchPaperIntegrity()];

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

    private function watchFullValidationStalls(): array
    {
        $events = [];
        LabGeneration::query()->where('status', 'full_validation')->with('agents')
            ->where('updated_at', '<=', now()->subMinutes(self::FULL_STALL_MINUTES))->each(function (LabGeneration $generation) use (&$events): void {
                // Long replays are expected.  "stalled" specifically means
                // that no queued or reserved full-validation job remains.
                if ($generation->agents->contains(fn (LabAgent $agent) => $this->hasQueuedFullJob($agent->id))) return;
                $events[] = $this->warn('FULL_VALIDATION_STALLED', 'Generation has remained in full validation for over 60 minutes; no lifecycle state was changed.', [
                    'generation_id' => $generation->id, 'generation' => $generation->generation,
                    'symbol' => $generation->laboratory?->symbol, 'updated_at' => $generation->updated_at?->toIso8601String(),
                ], $generation->id);
            });
        return $events;
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
