<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AiLaboratory;
use App\Models\LabLearningLaneDispatch;
use App\Models\LabLearningLanePair;
use App\Models\SystemEvent;
use App\Services\CandidateGateDecisionService;
use App\Services\CandidateHandoffService;
use App\Services\LabQueueJobInspector;
use App\Services\LearningEvidenceGate;
use App\Services\LearningLaneService;
use App\Services\LearningMemoryService;
use App\Services\LearningProtocolSafetyService;
use App\Services\MicroReplayService;
use App\Services\OperatorApprovalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Opens only the research-only learning lane.  The ordinary full-validation
 * selector remains unchanged and this command never creates paper evidence.
 */
class DispatchLearningLane extends Command
{
    protected $signature = 'trading:dispatch-learning-lane {symbol?} {--timeframe=H1} {--family=} {--limit=4} {--pair-id= : Consume this exact scheduler-selected learning pair} {--dry-run} {--autonomous : Bounded lighthouse-only scheduler dispatch} {--force : Allow dispatch when the serialized full lane already has work} {--retry-queued : Reopen only terminal learning batches after worker recovery} {--bounded-recovery : Admit only verified 2-of-3 micro near-passes to research replay} {--generation-from=} {--generation-to=} {--approved-by=} {--approval-reason=}';

    protected $description = 'Queue paired near-miss full replays for research-only learning, never promotion';

    public function handle(
        LearningLaneService $learning,
        LearningEvidenceGate $evidenceGate,
        LearningMemoryService $memory,
        MicroReplayService $microReplay,
        CandidateGateDecisionService $decisions,
        CandidateHandoffService $handoffs,
        LabQueueJobInspector $queueState,
        OperatorApprovalService $approvals,
    ): int {
        $retryQueued = (bool) $this->option('retry-queued');
        $symbol = strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $family = (string) $this->option('family') ?: null;
        $limit = max(1, min(12, (int) $this->option('limit')));
        $pairId = max(0, (int) $this->option('pair-id'));
        $autonomous = (bool) $this->option('autonomous');
        $boundedRecovery = (bool) $this->option('bounded-recovery');
        if (! (bool) config('services.learning_lane.enabled', true)) {
            $this->info('Learning lane disabled by configuration.');

            return self::SUCCESS;
        }
        $lab = AiLaboratory::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->first();

        if (! $lab || (string) $lab->lifecycle_mode !== 'lighthouse') {
            $this->info("{$symbol} {$timeframe}: shadow lab; learning lane dispatch skipped.");

            return self::SUCCESS;
        }
        if ($autonomous) {
            if (! (bool) config('services.learning_lane.autonomous_dispatch_enabled', false)
                || $symbol !== LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL
                || $timeframe !== LearningProtocolSafetyService::LIGHTHOUSE_TIMEFRAME
                || (bool) $this->option('force')) {
                $this->error('Autonomous learning dispatch is disabled or outside the bounded XAUUSD H1 contract.');

                return self::FAILURE;
            }
            $limit = min($limit, max(1, (int) config('services.learning_lane.autonomous_max_dispatch', 1)));
        }
        if ($boundedRecovery) {
            if ($autonomous || (bool) $this->option('force')) {
                $this->error('Bounded recovery is an operator-only serialized research action.');

                return self::FAILURE;
            }
            $generationFrom = max(1, (int) ($this->option('generation-from') ?: 1));
            $generationTo = max($generationFrom, (int) ($this->option('generation-to') ?: $generationFrom));
            $approval = $this->option('dry-run')
                ? ['event_id' => null]
                : $approvals->requireForApply(
                    'dispatch-learning-lane-bounded-recovery',
                    $this->option('approved-by'),
                    $this->option('approval-reason'),
                    compact('symbol', 'timeframe', 'generationFrom', 'generationTo', 'limit'),
                );
            $limit = 1;
        }
        if ($this->option('retry-queued') && ! $this->option('dry-run') && ! $autonomous) {
            $approvals->requireForApply('dispatch-learning-lane-retry', $this->option('approved-by'), $this->option('approval-reason'), [
                'symbol' => $symbol, 'timeframe' => $timeframe, 'family' => $family, 'limit' => $limit,
            ]);
            $recovered = $learning->recoverQueuedDispatches($symbol, $timeframe, $family);
            if ($recovered > 0) {
                $this->info("{$symbol} {$timeframe}: {$recovered} terminal learning dispatch(es) reopened for retry.");
            }
        }
        if (! $this->option('dry-run')) {
            $staleMicro = $learning->reconcileStaleMicroDispatches($symbol, $timeframe, $family);
            if ($staleMicro > 0) {
                $this->info("{$symbol} {$timeframe}: {$staleMicro} stale micro dispatch(es) reconciled; no duplicate replay created.");
            }
        }
        // Recovery only reuses the already-paired frontier. Recompiling every
        // historical screening observation here would make an operator retry
        // compete with the active lab queue and can exceed the CLI timeout.
        if ($pairId > 0) {
            $exactPair = $learning->actionablePairById($pairId, $symbol, $timeframe, $family);
            $pairs = $exactPair ? collect([$exactPair]) : collect();
        } elseif ($boundedRecovery) {
            $pairs = $learning->boundedRecoveryPairs($symbol, $timeframe, $generationFrom, $generationTo, 1);
        } else {
            $priorityPair = $learning->priorityResearchPair($symbol, $timeframe, $family);
            $pendingMicroPairs = $learning->pendingMicroPairs($symbol, $timeframe, $family, $limit);
            $pairs = $learning->frontier(
                $symbol,
                $timeframe,
                $family,
                $limit,
                ! $this->option('dry-run') && ! $retryQueued,
            );
            // Recovered micro seats have priority over fresh candidates. They
            // already consumed a bounded learning allocation and must not be
            // starved by newer screen pairs.
            $pairs = collect([$priorityPair])->filter()
                ->concat($pendingMicroPairs)
                ->concat($pairs)
                ->unique('id')
                ->take($limit)
                ->values();
        }
        if ($pairs->isEmpty()) {
            $this->info("{$symbol} {$timeframe}: paired learning frontier hozircha bo'sh.");

            return self::SUCCESS;
        }

        $queue = (string) config('services.lab_queue.full_validation_queue', 'lab-full-validation');
        $queueSnapshot = $queueState->queueSnapshot([$queue, 'lab-full-hold']);
        if (($queueSnapshot['available'] ?? true) === false) {
            $this->warn('Redis/database queue state unavailable; learning lane deferred fail-closed.');

            return self::SUCCESS;
        }
        $activeQueueWork = (int) ($queueSnapshot['total'] ?? 0)
            + (int) DB::table('job_batches')
                ->where('name', 'Learning lane research replay')
                ->whereNull('finished_at')
                ->whereNull('cancelled_at')
                ->count();
        if ($activeQueueWork > 0 && ! $this->option('force') && ! $this->option('dry-run')) {
            $this->warn("{$queue}: mavjud heavy replay tugamaguncha learning lane dispatch qilinmadi.");
            $this->line('Pairing davom etadi; queue bo‘shagach command yana near-miss frontier’ni oladi.');

            return self::SUCCESS;
        }

        $mutexKey = 'laravel-queue-overlap:'
            .(string) config('services.lab_queue.replay_mutex_key', 'neurotrader-ai-heavy-replay');
        $mutexProbe = Cache::lock($mutexKey, 1);
        $mutexBusy = ! $mutexProbe->get();
        if (! $mutexBusy) {
            $mutexProbe->release();
        }
        if ($mutexBusy && ! $this->option('dry-run')) {
            $this->warn('Shared evaluator mutex band: learning lane queuega yangi heavy replay qo\'shilmadi.');

            return self::SUCCESS;
        }

        // The bounded autonomous pump uses --retry-queued and consumes only
        // the already-paired frontier. Re-loading every historical response
        // map to deduplicate the whole research plane on every minute tick is
        // both redundant and memory-unbounded. Fresh materialization retains
        // the full deduplication pass before it may queue any replay.
        if (! $this->option('dry-run') && ! $retryQueued && ! $boundedRecovery && $pairId <= 0) {
            $superseded = $learning->deduplicatePairs($symbol, $timeframe, $family);
            if ($superseded > 0) {
                $this->info("{$symbol} {$timeframe}: {$superseded} duplicate learning pair(s) superseded; immutable maps preserved.");
                $staleMicro = $learning->reconcileStaleMicroDispatches($symbol, $timeframe, $family);
                if ($staleMicro > 0) {
                    $this->info("{$symbol} {$timeframe}: {$staleMicro} post-dedup micro dispatch(es) reconciled.");
                }
                $pendingMicroPairs = $learning->pendingMicroPairs($symbol, $timeframe, $family, $limit);
                $freshPairs = $learning->frontier($symbol, $timeframe, $family, $limit, false);
                $pairs = $pendingMicroPairs
                    ->concat($freshPairs->reject(fn (LabLearningLanePair $pair): bool => $pendingMicroPairs->contains('id', $pair->id)))
                    ->take($limit)
                    ->values();
            }
        }

        $jobs = [];
        $dispatches = [];
        foreach ($pairs as $pair) {
            // A micro pass is diagnostic evidence only. It cannot turn a
            // legacy/missing-control row into a full replay candidate.
            if (! $pair->loadMissing('controlResponseMap')->isVerifiedControlPair()) {
                if (! $this->option('dry-run')) {
                    $pair->update([
                        'status' => 'diagnostic_only',
                        'metadata' => [...((array) $pair->metadata), 'diagnostic_reason' => 'CONTROL_PAIR_INVALID', 'promotion_evidence' => false],
                    ]);
                    LabLearningLaneDispatch::query()->where('pair_id', $pair->id)
                        ->whereIn('status', ['selected', 'queued', 'running', 'retry_ready'])
                        ->update(['status' => 'diagnostic_only', 'completed_at' => null]);
                }

                continue;
            }
            $gate = $evidenceGate->allow($pair, null, 'micro_passed');
            if (! $gate['allowed']) {
                if (! $this->option('dry-run')) {
                    $pair->update(['status' => $gate['status'], 'metadata' => [...((array) $pair->metadata), 'evidence_gate' => $gate, 'promotion_evidence' => false]]);
                }

                continue;
            }
            $agent = $pair->candidateAgent?->fresh(['modelVersion', 'generation']);
            if (! $agent || ! in_array((string) $agent->lifecycle_status, ['screened', 'challenger', 'rejected', 'stagnated'], true)) {
                continue;
            }
            $dispatchKey = hash('sha256', implode('|', [
                LearningLaneService::PROTOCOL,
                $pair->id,
                $pair->candidate_response_map_id,
                (string) data_get($pair->target_delta, 'delta'),
            ]));
            $existing = LabLearningLaneDispatch::query()->where('dispatch_key', $dispatchKey)->first();
            // Recovery-era rows may carry a key generated before the pair's
            // immutable delta was reconciled. Pair identity is the stronger
            // idempotency key for a pending micro seat; reuse that row rather
            // than leaving an orphan retry_ready dispatch behind.
            if (! $existing) {
                $existing = LabLearningLaneDispatch::query()
                    ->where('pair_id', $pair->id)
                    ->where('stage', 'micro')
                    ->where('micro_status', 'pending')
                    ->whereIn('status', ['retry_ready', 'selected'])
                    ->latest('id')
                    ->first();
            }
            $pendingMicroSeat = $existing
                && (string) $existing->stage === 'micro'
                && (string) $existing->micro_status === 'pending'
                && in_array((string) $existing->status, ['retry_ready', 'selected'], true);
            if ($existing
                && ! $pendingMicroSeat
                && in_array((string) $existing->status, ['selected', 'queued', 'running', 'completed'], true)) {
                continue;
            }
            $micro = $microReplay->assessPair($pair, ! $this->option('dry-run'));
            $contextualNearPass = $microReplay->isContextualNearPass($micro);
            $researchAdmission = ($boundedRecovery && $contextualNearPass)
                || ($autonomous
                    && (bool) config('services.learning_lane.autonomous_contextual_near_pass_enabled', true)
                    && $contextualNearPass);
            if (($micro['status'] ?? 'deferred') !== 'passed' && ! $researchAdmission) {
                if (! $this->option('dry-run')) {
                    $microStatus = (string) ($micro['status'] ?? 'deferred');
                    $pair->update([
                        'status' => $microStatus === 'failed' ? 'micro_failed' : 'micro_deferred',
                        'metadata' => [...((array) $pair->metadata), 'micro_replay' => $micro, 'promotion_evidence' => false],
                    ]);
                    $existing?->update([
                        'status' => $microStatus === 'failed' ? 'micro_failed' : 'retry_ready',
                        'stage' => 'micro',
                        'micro_status' => $microStatus,
                        'micro_attempts' => (int) ($existing->micro_attempts ?? 0) + 1,
                        'micro_completed_at' => now(),
                        'micro_metadata' => $micro,
                        'metadata' => [...((array) $existing->metadata), 'promotion_evidence' => false],
                    ]);
                    $memory->recordPair($pair->fresh(), 'micro', $micro);
                }
                if ($this->option('dry-run')) {
                    $this->line(json_encode([
                        'agent_id' => $agent->id, 'pair_id' => $pair->id, 'micro' => $micro,
                        'promotion_evidence' => false,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }

                continue;
            }
            if ($researchAdmission) {
                $micro = [
                    ...$micro,
                    'status' => 'research_admitted',
                    'ordinary_micro_status' => 'failed',
                    'admission_protocol' => $autonomous
                        ? 'autonomous_contextual_micro_near_pass_v1'
                        : 'operator_bounded_micro_near_pass_v1',
                    'approval_event_id' => $autonomous ? null : $approval['event_id'],
                    'context_decomposition_required' => true,
                    'global_skill_authority' => false,
                    'promotion_evidence' => false,
                ];
            }
            if (! $this->option('dry-run')) {
                // Micro confirmation is search evidence only. It may guide
                // the mutation bandit but cannot create skill/promotion
                // credit by itself.
                $memory->recordPair($pair->fresh(), 'micro', $micro);
            }
            $delta = (float) data_get($pair->target_delta, 'delta', 0);
            $utility = in_array(strtolower((string) $pair->target), ['drawdown', 'drawdown_risk', 'max_drawdown', 'risk'], true) ? -$delta : $delta;
            $score = (bool) data_get($pair->target_delta, 'improved', false) ? 1.0 : 0.0;
            $score += $utility;
            if ($this->option('dry-run')) {
                $this->line(json_encode([
                    'agent_id' => $agent->id,
                    'pair_id' => $pair->id,
                    'target' => $pair->target,
                    'role' => $pair->specialist_role,
                    'target_delta' => $pair->target_delta,
                    'baseline_source' => $pair->baseline_source,
                    'existing_dispatch_status' => $existing?->status,
                    'micro' => $micro,
                    'promotion_evidence' => false,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                continue;
            }
            $dispatch = DB::transaction(function () use ($existing, $dispatchKey, $pair, $agent, $symbol, $timeframe, $micro, $score, $decisions, $handoffs): LabLearningLaneDispatch {
                $dispatch = $existing ?: LabLearningLaneDispatch::create([
                    'dispatch_key' => $dispatchKey,
                    'pair_id' => $pair->id,
                    'lab_generation_id' => $agent->lab_generation_id,
                    'lab_agent_id' => $agent->id,
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'strategy_family' => $agent->strategy_family,
                    'target' => $pair->target,
                    'specialist_role' => $pair->specialist_role,
                    'status' => 'selected',
                    'stage' => 'full_replay',
                    'micro_status' => $micro['status'] === 'research_admitted' ? 'research_admitted' : 'passed',
                    'micro_attempts' => 1,
                    'micro_completed_at' => now(),
                    'micro_metadata' => $micro,
                    'selection_score' => $score,
                    'metadata' => [
                        'protocol' => LearningLaneService::PROTOCOL,
                        'pair_key' => $pair->pair_key,
                        'target_delta' => $pair->target_delta,
                        'promotion_evidence' => false,
                    ],
                    'selected_at' => now(),
                ]);
                if ($existing) {
                    $dispatch->update([
                        'status' => 'selected',
                        'stage' => 'full_replay',
                        'micro_status' => $micro['status'] === 'research_admitted' ? 'research_admitted' : 'passed',
                        'micro_attempts' => (int) ($dispatch->micro_attempts ?? 0) + 1,
                        'micro_completed_at' => now(),
                        'micro_metadata' => $micro,
                        'selection_score' => $score,
                        'queue_batch_id' => null,
                        'completed_at' => null,
                        'selected_at' => now(),
                        'metadata' => [
                            ...((array) $dispatch->metadata),
                            'protocol' => LearningLaneService::PROTOCOL,
                            'pair_key' => $pair->pair_key,
                            'target_delta' => $pair->target_delta,
                            'promotion_evidence' => false,
                        ],
                    ]);
                }

                $metadata = (array) $agent->modelVersion?->metadata;
                unset($metadata['full_validation_batch']);
                data_set($metadata, 'learning_lane', [
                    'protocol' => LearningLaneService::PROTOCOL,
                    'dispatch_id' => $dispatch->id,
                    'dispatch_key' => $dispatchKey,
                    'pair_id' => $pair->id,
                    // The pair transition is committed in this same database
                    // transaction. Persist the post-transition state so a
                    // recovered micro_failed row is not rejected by the
                    // worker as if it were still outside the learning lane.
                    'pair_status' => 'learning_queued',
                    'target' => $pair->target,
                    'specialist_role' => $pair->specialist_role,
                    'screening_passed' => false,
                    'promotion_evidence' => false,
                ]);
                $agent->modelVersion?->update(['metadata' => $metadata]);
                $decision = $decisions->recordFullReplaySelection($agent, true, 'LEARNING_LANE_NEAR_MISS');
                $agent->update([
                    'lifecycle_status' => 'full_queued',
                    'decision_reason' => 'Paired near-miss learning replay; research-only, promotion remains blocked.',
                ]);
                $pair->update(['status' => 'learning_queued', 'metadata' => [
                    ...((array) $pair->metadata), 'micro_replay' => $micro, 'promotion_evidence' => false,
                ]]);
                $handoffs->record($agent->generation, $agent, 'learning_lane_queued', 'completed', null, [
                    'dispatch_id' => $dispatch->id,
                    'pair_id' => $pair->id,
                    'selection_decision_id' => $decision->id,
                    'promotion_evidence' => false,
                ]);

                return $dispatch;
            });
            $jobs[] = new EvaluateLabAgentJob($agent->id, $symbol, 'full');
            $dispatches[] = $dispatch;
        }

        if ($this->option('dry-run')) {
            $this->info('Learning frontier dry-run: '.$pairs->count().' pair(s) inspected; no replay queued.');

            return self::SUCCESS;
        }
        if ($jobs === []) {
            $this->info("{$symbol} {$timeframe}: yangi learning replay dispatch qilinmadi.");

            return self::SUCCESS;
        }

        $batch = Bus::batch($jobs)
            ->name('Learning lane research replay')
            ->onConnection((string) config('queue.default', 'redis'))
            ->onQueue($queue)
            ->dispatch();
        foreach ($dispatches as $dispatch) {
            $dispatch->update([
                'status' => 'queued',
                'stage' => 'full_replay',
                'micro_status' => $dispatch->micro_status,
                'queue_batch_id' => $batch->id,
                'queued_at' => now(),
            ]);
        }
        if ($autonomous) {
            SystemEvent::create([
                'event_type' => 'autonomous_learning_dispatch',
                'event_key' => 'learning_lane:autonomous:'.$batch->id,
                'agent' => 'learning-lane-scheduler',
                'severity' => 'info',
                'summary' => 'Bounded autonomous research-only learning replay dispatched.',
                'payload' => [
                    'protocol' => 'bounded_autonomous_learning_dispatch_v1',
                    'batch_id' => $batch->id,
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'dispatch_count' => count($jobs),
                    'limit' => $limit,
                    'promotion_evidence' => false,
                ],
                'occurred_at' => now(),
            ]);
        }
        $this->info("Learning lane batch {$batch->id}: ".count($jobs).' research replay queued; promotion evidence=false.');

        return self::SUCCESS;
    }
}
