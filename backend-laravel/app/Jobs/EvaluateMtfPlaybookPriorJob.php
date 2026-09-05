<?php

namespace App\Jobs;

use App\Exceptions\ReplayLaneBusyException;
use App\Models\SystemEvent;
use App\Services\CanonicalResearchLanePriorityService;
use App\Services\LabQueueJobInspector;
use App\Services\MtfPlaybookFrozenControlService;
use App\Services\MtfPlaybookLearningDirectorService;
use App\Services\ReplayLivenessProbeService;
use App\Services\StrategyResearchCatalogueService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Advances one immutable toolbox prior without blocking the scheduler.
 *
 * Full-validation has queue priority over lab-frontier, and uniqueness keeps
 * repeated scheduler ticks from accumulating the same expensive research.
 */
class EvaluateMtfPlaybookPriorJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    // Queue releases are expected admission decisions, not failed attempts.
    // A fixed wall-clock deadline bounds retries without discarding a healthy
    // immutable trial while evolution owns the Python lane.
    public int $tries = 60;

    // Admission releases do not count as exceptions. Unexpected technical
    // failures are bounded independently and can never spin for six hours.
    public int $maxExceptions = 3;

    public int $uniqueFor = 21600;

    public int $replayLaneDeferrals = 0;

    public \DateTimeInterface $retryDeadline;

    /** @param array<int,string> $preferredModelIds */
    public function __construct(
        public string $symbol = 'XAUUSD',
        public array $preferredModelIds = [],
        public ?string $relatedSymbol = null,
    ) {
        $this->symbol = strtoupper(str_replace(['/', '_', '-'], '', trim($symbol)));
        $this->preferredModelIds = array_values(array_unique(array_filter(array_map('strval', $preferredModelIds))));
        $this->onConnection((string) config('queue.default', 'redis'));
        $this->onQueue((string) config('services.lab_queue.frontier_queue', 'lab-frontier'));
        $this->retryDeadline = now()->addHours(6);
    }

    public function uniqueId(): string
    {
        return 'mtf-playbook-prior:'.$this->symbol;
    }

    public function retryUntil(): \DateTimeInterface
    {
        return $this->retryDeadline;
    }

    public function handle(
        MtfPlaybookLearningDirectorService $director,
        MtfPlaybookFrozenControlService $runner,
        LabQueueJobInspector $queues,
        ReplayLivenessProbeService $liveness,
        StrategyResearchCatalogueService $catalogue,
        CanonicalResearchLanePriorityService $priority,
    ): void {
        $ownership = $priority->edgeGenesisOwnership($this->symbol, 'H1');
        if (($ownership['owned'] ?? false) === true) {
            $this->recordCanonicalDeferral($ownership);
            return;
        }
        // Agent-owned evolution always has priority over research-only priors.
        // This check happens before snapshot construction or a DB run exists.
        if ($queues->evolutionReplayIsWaiting()) {
            // Do not release the same scheduler-owned job once per minute.
            // That turns a healthy priority wait into an ever-growing attempt
            // counter. The half-hour scheduler tick is the retry authority;
            // this job exits successfully without creating evidence.
            Log::info('MTF research prior yielded to pending canonical evolution.', [
                'symbol' => $this->symbol,
                'reason' => 'canonical_evolution_waiting',
                'retry_policy' => 'next_scheduler_tick',
                'same_job_released' => false,
                'promotion_evidence' => false,
            ]);
            return;
        }
        $probe = $liveness->probe();
        if ((string) data_get($probe, 'status') !== 'ok') {
            $this->defer('replay_lane_'.(string) data_get($probe, 'status', 'unknown'));
            return;
        }

        $next = $director->nextTrial($this->symbol, $this->preferredModelIds, $this->relatedSymbol);
        $modelId = (string) data_get($next, 'model_id', '');
        if ($modelId === '') {
            Log::info('MTF playbook frozen-prior scheduler has no executable dependency-complete trial.', [
                'symbol' => $this->symbol,
                'protocol' => MtfPlaybookLearningDirectorService::PROTOCOL,
                'status' => data_get($next, 'status'),
                'dependency_blocked_model_ids' => data_get($next, 'dependency_blocked_model_ids', []),
                'promotion_evidence' => false,
            ]);

            return;
        }

        $model = $catalogue->model($modelId);
        if (in_array('related_market', (array) ($model['required_streams'] ?? []), true)
            && blank($this->relatedSymbol)) {
            SystemEvent::updateOrCreate([
                'event_key' => 'mtf-research-dependency:'.$this->symbol.':'.$modelId,
            ], [
                'event_type' => 'mtf_research_dependency_blocked',
                'source_type' => self::class,
                'agent' => 'mtf_playbook_prior_scheduler',
                'symbol' => $this->symbol,
                'timeframe' => 'M15',
                'severity' => 'info',
                'summary' => 'Research prior withheld until its declared related-market stream exists.',
                'payload' => [
                    'protocol' => MtfPlaybookLearningDirectorService::PROTOCOL,
                    'status' => 'dependency_blocked',
                    'research_model_id' => $modelId,
                    'missing_dependency' => 'related_market',
                    'retryable_after_dependency_change' => true,
                    'promotion_evidence' => false,
                ],
                'occurred_at' => now(),
            ]);
            return;
        }

        try {
            $result = $runner->run(
                $this->symbol,
                $modelId,
                $this->relatedSymbol,
                (array) data_get($next, 'candidate_overrides', []),
                (array) data_get($next, 'trial_context', []),
            );
        } catch (ReplayLaneBusyException) {
            // A screen can win the race after the preflight. The runner seals
            // any completed control leg and marks the row retry_deferred;
            // release this exact unique job to resume the candidate leg.
            $this->defer('replay_lane_race_lost');
            return;
        }
        Log::info('One bounded MTF playbook frozen-prior trial completed.', [
            'symbol' => $this->symbol,
            'research_model_id' => $modelId,
            'trial_type' => data_get($next, 'trial_type'),
            'status' => data_get($result, 'status'),
            'run_id' => data_get($result, 'run_id'),
            'interpretation' => data_get($result, 'comparison.interpretation'),
            'agent_owned_evidence' => false,
            'promotion_evidence' => false,
        ]);
        if ((string) data_get($result, 'status') === 'completed'
            && (string) data_get($result, 'comparison.power.status') === 'powered'
            && (string) data_get($result, 'comparison.interpretation') === 'candidate_improved_on_this_frozen_replay') {
            ValidateMtfPoweredPriorJob::dispatch((int) data_get($result, 'run_id'));
        }
    }

    private function defer(string $reason): void
    {
        $delay = max(30, (int) config('services.lab_queue.frontier_release_seconds', 60));
        $this->replayLaneDeferrals++;
        Log::info('MTF research prior yielded to the canonical evolution replay lane.', [
            'symbol' => $this->symbol,
            'reason' => $reason,
            'deferrals' => $this->replayLaneDeferrals,
            'retry_after_seconds' => $delay,
            'promotion_evidence' => false,
        ]);
        $this->release($delay);
    }

    /** @param array<string,mixed> $ownership */
    private function recordCanonicalDeferral(array $ownership): void
    {
        SystemEvent::updateOrCreate([
            'event_key' => 'mtf-prior-canonical-deferral:'.$this->symbol,
        ], [
            'event_type' => 'mtf_research_deferred_to_canonical_evolution',
            'source_type' => self::class,
            'agent' => 'mtf_playbook_prior_scheduler',
            'symbol' => $this->symbol,
            'timeframe' => 'H1',
            'severity' => 'info',
            'summary' => 'MTF research prior yielded without retry spin while Edge Genesis owns replay authority.',
            'payload' => [
                ...$ownership,
                'retry_policy' => 'next_scheduler_tick_after_canonical_owner_settles',
                'research_evidence_preserved' => true,
                'promotion_evidence' => false,
            ],
            'occurred_at' => now(),
        ]);
    }
}
