<?php

namespace App\Console\Commands;

use App\Services\LearningLaneService;
use App\Services\LabQueueJobInspector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Single-seat learning-lane pump. It never competes with an active replay. */
class PumpLearningLane extends Command
{
    protected $signature = 'trading:pump-learning-lane {symbol?} {--timeframe=H1} {--limit=1} {--dry-run}';

    protected $description = 'Pump one micro-confirmed learning-lane replay only when the heavy evaluator is idle';

    public function handle(LearningLaneService $learning, LabQueueJobInspector $queueState): int
    {
        $symbol = strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $limit = max(1, min(2, (int) $this->option('limit')));
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
        if ($this->option('dry-run')) {
            $this->line(json_encode([...$payload, 'status' => 'would_dispatch'], JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $lock = Cache::lock('learning-lane-pump:'.$symbol.':'.$timeframe, 120);
        if (! $lock->get()) {
            $this->line(json_encode([...$payload, 'status' => 'pump_lock_busy'], JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        try {
            $exit = Artisan::call('trading:dispatch-learning-lane', [
                'symbol' => $symbol, '--timeframe' => $timeframe, '--limit' => $limit, '--retry-queued' => true,
            ]);
            $this->line(json_encode([...$payload, 'status' => 'dispatch_called', 'exit_code' => $exit], JSON_UNESCAPED_SLASHES));
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
