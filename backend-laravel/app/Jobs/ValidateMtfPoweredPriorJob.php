<?php

namespace App\Jobs;

use App\Exceptions\ReplayLaneBusyException;
use App\Models\SystemEvent;
use App\Services\CanonicalResearchLanePriorityService;
use App\Services\LabQueueJobInspector;
use App\Services\MtfPoweredPriorValidationService;
use App\Services\ReplayLivenessProbeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Executes the nine-fold owner handoff without blocking the scheduler. */
class ValidateMtfPoweredPriorJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    // Admission releases are expected and remain bounded by retryUntil().
    // Unexpected exceptions are failed explicitly in handle(), never spun.
    public int $tries = 0;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 21600;

    public int $replayLaneDeferrals = 0;

    public \DateTimeInterface $retryDeadline;

    public function __construct(public int $sourceRunId)
    {
        $this->onConnection((string) config('queue.default', 'redis'));
        // A powered prior has passed discovery and belongs ahead of new
        // frontier proposals in the explicit full-validation lane.
        $this->onQueue((string) config('services.lab_queue.full_validation_queue', 'lab-full-validation'));
        $this->retryDeadline = now()->addHours(6);
    }

    public function uniqueId(): string
    {
        return 'mtf-powered-prior-validation:'.$this->sourceRunId;
    }

    public function retryUntil(): \DateTimeInterface
    {
        return $this->retryDeadline;
    }

    /** @return array<int,int> */
    public function backoff(): array
    {
        return [60, 120, 300, 600];
    }

    public function handle(
        MtfPoweredPriorValidationService $validation,
        LabQueueJobInspector $queues,
        ReplayLivenessProbeService $liveness,
        CanonicalResearchLanePriorityService $priority,
    ): void {
        $ownership = $priority->edgeGenesisOwnership('XAUUSD', 'H1');
        if (($ownership['owned'] ?? false) === true) {
            SystemEvent::updateOrCreate([
                'event_key' => 'mtf-powered-canonical-deferral:'.$this->sourceRunId,
            ], [
                'event_type' => 'mtf_powered_validation_deferred_to_canonical_evolution',
                'source_type' => self::class,
                'agent' => 'mtf_powered_prior_scheduler',
                'symbol' => 'XAUUSD',
                'timeframe' => 'H1',
                'severity' => 'info',
                'summary' => 'Powered MTF validation yielded without retry spin while Edge Genesis owns replay authority.',
                'payload' => [
                    ...$ownership,
                    'source_run_id' => $this->sourceRunId,
                    'retry_policy' => 'next_scheduler_tick_after_canonical_owner_settles',
                    'research_evidence_preserved' => true,
                    'promotion_evidence' => false,
                ],
                'occurred_at' => now(),
            ]);
            return;
        }
        // This job itself lives in the priority full-validation queue. Ignore
        // only its own class while still yielding to every canonical agent
        // screening/full-validation job and active generation intent.
        if ($queues->evolutionReplayIsWaiting([self::class])) {
            $this->defer('canonical_evolution_waiting');
            return;
        }
        if ((string) data_get($liveness->probe(), 'status') !== 'ok') {
            $this->defer('replay_lane_not_idle');
            return;
        }
        try {
            $result = $validation->run($this->sourceRunId);
        } catch (ReplayLaneBusyException) {
            $this->defer('replay_lane_race_lost');
            return;
        } catch (Throwable $exception) {
            Log::error('MTF model-owned validation failed closed before canonical settlement.', [
                'source_run_id' => $this->sourceRunId,
                'exception' => $exception,
                'promotion_evidence' => false,
            ]);
            $this->fail($exception);

            return;
        }
        Log::info('Powered MTF prior completed its model-owned paired fold handoff.', [
            'source_run_id' => $this->sourceRunId,
            'validation_run_id' => data_get($result, 'run_id'),
            'status' => data_get($result, 'status'),
            'verdict' => data_get($result, 'verdict'),
            'runtime_trade_authority' => false,
            'parent_authority' => false,
            'promotion_evidence' => false,
        ]);
    }

    private function defer(string $reason): void
    {
        $delay = max(30, (int) config('services.lab_queue.frontier_release_seconds', 60));
        $this->replayLaneDeferrals++;
        Log::info('MTF model-owned validation yielded to canonical evolution.', [
            'source_run_id' => $this->sourceRunId,
            'reason' => $reason,
            'deferrals' => $this->replayLaneDeferrals,
            'queue_attempt' => $this->attempts(),
            'retry_after_seconds' => $delay,
            'promotion_evidence' => false,
        ]);
        $this->release($delay);
    }
}
