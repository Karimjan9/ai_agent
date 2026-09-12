<?php

namespace App\Console\Commands;

use App\Services\AutonomousModeService;
use App\Services\LabQueueJobInspector;
use App\Services\LearningLaneService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Single-seat learning-lane pump. It never competes with an active replay. */
class PumpLearningLane extends Command
{
    protected $signature = 'trading:pump-learning-lane {symbol?} {--timeframe=H1} {--limit=1} {--pair-id= : Dispatch this exact scheduler-selected learning pair} {--dry-run} {--autonomous : Use the bounded lighthouse scheduler contract}';

    protected $description = 'Pump one micro-confirmed learning-lane replay only when the heavy evaluator is idle';

    public function handle(LearningLaneService $learning, LabQueueJobInspector $queueState, AutonomousModeService $autonomy): int
    {
        $symbol = strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $limit = max(1, min(2, (int) $this->option('limit')));
        $pairId = max(0, (int) $this->option('pair-id'));
        if ((bool) $this->option('autonomous') && ! $autonomy->enabled($symbol, $timeframe)) {
            $this->line((string) json_encode([
                'protocol' => 'learning_lane_pump_v1',
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'status' => 'autonomous_mode_stopped',
                'next_action' => 'monitor_only_until_ai_start',
                'promotion_evidence' => false,
            ], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $queue = (string) config('services.lab_queue.full_validation_queue', 'lab-full-validation');
        $mutexKey = Cache::getStore()->getPrefix().'laravel-queue-overlap:'.(string) config('services.lab_queue.replay_mutex_key', 'neurotrader-ai-heavy-replay');
        $queueSnapshot = $queueState->queueSnapshot([$queue, 'lab-full-hold']);
        $queueAvailable = ($queueSnapshot['available'] ?? true) !== false;
        $queueJobs = $queueAvailable ? (int) ($queueSnapshot['total'] ?? 0) : null;
        $mutex = DB::table('cache_locks')->where('key', $mutexKey)->exists();
        $ai = $this->aiReplayStatus();
        // An unavailable replay-status endpoint is not equivalent to idle.
        // The pump must fail closed; otherwise a transient AI outage could
        // race a still-running child replay.
        $statusKnown = is_array($ai);
        $aiBusy = $ai !== null && ((int) data_get($ai, 'active_requests', data_get($ai, 'active', 0)) > 0);

        $ready = $queueAvailable && $queueJobs === 0 && ! $mutex && $statusKnown && ! $aiBusy;
        $payload = [
            'protocol' => 'learning_lane_pump_v1', 'symbol' => $symbol, 'timeframe' => $timeframe,
            'ready' => $ready, 'queue_jobs' => $queueJobs, 'queue_backend' => $queueSnapshot['backend'] ?? null,
            'queue_state_known' => $queueAvailable, 'mutex' => $mutex, 'ai_status_known' => $statusKnown, 'ai_busy' => $aiBusy,
            'promotion_evidence' => false,
        ];
        if (! $ready) {
            $this->line(json_encode([...$payload, 'status' => 'deferred'], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        if ($pairId > 0) {
            $exactPair = $learning->actionablePairById($pairId, $symbol, $timeframe);
            $actualPlan = $exactPair ? collect([$exactPair]) : collect();
        } else {
            $priorityPair = $learning->priorityResearchPair($symbol, $timeframe);
            $pendingMicro = $learning->pendingMicroPairs($symbol, $timeframe, null, $limit);
            $existingFrontier = $learning->frontier($symbol, $timeframe, null, $limit, false);
            $actualPlan = collect([$priorityPair])->filter()
                ->concat($pendingMicro)
                ->concat($existingFrontier)
                ->unique('id')
                ->take($limit)
                ->values();
        }
        $payload['actual_plan'] = [
            'pair_ids' => $actualPlan->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'agent_ids' => $actualPlan->pluck('candidate_agent_id')->filter()->map(fn ($id): int => (int) $id)->all(),
            'existing_frontier_count' => $actualPlan->count(),
            'fresh_materialization_required' => $actualPlan->isEmpty(),
        ];
        if ($this->option('dry-run')) {
            $this->line(json_encode([
                ...$payload,
                'status' => $actualPlan->isNotEmpty() ? 'would_dispatch' : 'no_actionable_learning_work',
            ], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $lock = Cache::lock('learning-lane-pump:'.$symbol.':'.$timeframe, 120);
        if (! $lock->get()) {
            $this->line(json_encode([...$payload, 'status' => 'pump_lock_busy'], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        try {
            $arguments = [
                'symbol' => $symbol,
                '--timeframe' => $timeframe,
                '--limit' => $limit,
                '--autonomous' => (bool) $this->option('autonomous'),
            ];
            if ($pairId > 0) {
                $arguments['--pair-id'] = $pairId;
            }
            // Retry the durable frontier first. If it is empty, allow the
            // dispatcher to materialize one fresh verified frontier. This is
            // the only path that omits --retry-queued.
            if ($actualPlan->isNotEmpty()) {
                $arguments['--retry-queued'] = true;
            }
            $exit = Artisan::call('trading:dispatch-learning-lane', $arguments);
            $dispatchOutput = Artisan::output();
            $noWork = str_contains($dispatchOutput, "frontier hozircha bo'sh");
            $this->line(json_encode([
                ...$payload,
                'status' => $noWork ? 'no_actionable_learning_work' : 'dispatch_called',
                'mode' => $actualPlan->isNotEmpty() ? 'retry_existing_frontier' : 'materialize_fresh_verified_frontier',
                'exit_code' => $exit,
            ], JSON_UNESCAPED_SLASHES));
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    /** @return array<string, mixed>|null */
    private function aiReplayStatus(): ?array
    {
        $base = rtrim((string) config('services.ai_service.url', 'http://127.0.0.1:9000'), '/');
        try {
            $response = Http::timeout(4)->acceptJson()
                ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])
                ->get($base.'/api/replay-status');

            return $response->successful() ? (array) $response->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
