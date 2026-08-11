<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\AgentMemory;
use App\Models\AgentMemoryMatch;
use App\Models\Candle;
use App\Models\LabGeneration;
use App\Models\KnowledgeMiningRun;
use App\Models\MarketStateSnapshot;
use App\Models\MarketDataSyncState;
use App\Models\ModelMarketPerformance;
use App\Models\RealityVerificationRun;
use App\Models\ServiceHealthCheck;
use App\Models\SignalMarketSnapshot;
use App\Models\SystemEvent;
use App\Models\User;
use App\Services\MarketData\HistoricalDataQualityService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PhaseTwoFoundationService
{
    public function recordEvent(array $data): ?SystemEvent
    {
        if (! Schema::hasTable('system_events')) {
            return null;
        }

        return SystemEvent::create([
            'event_type' => $data['event_type'],
            'event_key' => $data['event_key'] ?? null,
            'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'agent' => $data['agent'] ?? null,
            'symbol' => $data['symbol'] ?? null,
            'timeframe' => $data['timeframe'] ?? null,
            'market_state_snapshot_id' => $data['market_state_snapshot_id'] ?? null,
            'severity' => $data['severity'] ?? 'info',
            'summary' => $data['summary'],
            'payload' => $data['payload'] ?? [],
            'occurred_at' => $data['occurred_at'] ?? now(),
        ]);
    }

    public function runHealthCheck(): Collection
    {
        if (! Schema::hasTable('service_health_checks')) {
            return collect();
        }

        return collect($this->healthDefinitions())
            ->map(fn (array $definition): ServiceHealthCheck => $this->upsertHealth($definition));
    }

    public function captureSignalMarketSnapshot(array $data): ?SignalMarketSnapshot
    {
        if (! Schema::hasTable('signal_market_snapshots')) {
            return null;
        }

        $marketSnapshot = $data['market_state_snapshot'] ?? $this->latestMarketSnapshot($data['symbol'], $data['timeframe']);
        $marketSpecies = $marketSnapshot?->marketSpecies?->name ?? $marketSnapshot?->market_state;
        $snapshot = [
            'market_state' => $marketSnapshot?->market_state,
            'liquidity_state' => $marketSnapshot?->liquidity_state,
            'momentum_state' => $marketSnapshot?->momentum_state,
            'structure_state' => $marketSnapshot?->structure_state,
            'trend_score' => (float) ($marketSnapshot?->trend_score ?? 0),
            'volatility_score' => (float) ($marketSnapshot?->expansion_score ?? $data['volatility_score'] ?? 0),
            'liquidity_score' => (float) ($marketSnapshot?->liquidity_proxy_score ?? $data['liquidity_score'] ?? 0),
            'momentum_score' => (float) ($marketSnapshot?->momentum_score ?? $data['momentum_score'] ?? 0),
            'features' => $marketSnapshot?->features ?? [],
        ];

        $signal = SignalMarketSnapshot::create([
            'signal_type' => $data['signal_type'] ?? 'paper_candidate',
            'signal_key' => $data['signal_key'] ?? 'signal-snapshot:'.Str::uuid()->toString(),
            'strategy' => $data['strategy'],
            'symbol' => $data['symbol'],
            'timeframe' => $data['timeframe'],
            'signal' => $data['signal'] ?? 'WAIT',
            'confidence' => $data['confidence'] ?? 50,
            'market_state_snapshot_id' => $marketSnapshot?->id,
            'market_species' => $data['market_species'] ?? $marketSpecies,
            'trend_score' => $snapshot['trend_score'],
            'volatility_score' => $snapshot['volatility_score'],
            'liquidity_score' => $snapshot['liquidity_score'],
            'momentum_score' => $snapshot['momentum_score'],
            'memory_match_score' => 0,
            'snapshot' => $snapshot,
            'hypothesis' => $data['hypothesis'] ?? null,
        ]);

        $matches = $this->matchMemories($signal);
        $signal->update(['memory_match_score' => round((float) $matches->max('similarity_score'), 2)]);

        $this->recordEvent([
            'event_type' => 'signal_market_snapshot_captured',
            'source_type' => SignalMarketSnapshot::class,
            'source_id' => $signal->id,
            'agent' => $signal->strategy,
            'symbol' => $signal->symbol,
            'timeframe' => $signal->timeframe,
            'market_state_snapshot_id' => $signal->market_state_snapshot_id,
            'summary' => "Captured market snapshot for {$signal->strategy} {$signal->signal} signal.",
            'payload' => [
                'confidence' => $signal->confidence,
                'market_species' => $signal->market_species,
                'memory_match_score' => $signal->memory_match_score,
            ],
        ]);

        return $signal->fresh('memoryMatches');
    }

    public function writeExperienceMemory(array $data): AgentMemory
    {
        $memory = AgentMemory::create([
            'strategy' => $data['strategy'],
            'memory_type' => $data['memory_type'] ?? 'paper_experience',
            'market_regime' => $data['market_regime'] ?? null,
            'volatility_regime' => $data['volatility_regime'] ?? null,
            'market_species' => $data['market_species'] ?? null,
            'outcome' => $data['outcome'] ?? null,
            'training_session_id' => $data['training_session_id'] ?? null,
            'summary' => $data['summary'],
            'lesson' => $data['lesson'],
            'strength' => $data['strength'] ?? 60,
            'confidence_score' => $data['confidence_score'] ?? 60,
            'occurrences' => $data['occurrences'] ?? 1,
            'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'metadata' => $data['metadata'] ?? [],
        ]);

        $this->recordEvent([
            'event_type' => 'agent_memory_created',
            'source_type' => AgentMemory::class,
            'source_id' => $memory->id,
            'agent' => $memory->strategy,
            'summary' => "Agent memory created for {$memory->strategy}: {$memory->summary}",
            'payload' => [
                'market_species' => $memory->market_species,
                'outcome' => $memory->outcome,
                'strength' => $memory->strength,
            ],
        ]);

        return $memory;
    }

    public function matchMemories(SignalMarketSnapshot $signal): Collection
    {
        if (! Schema::hasTable('agent_memory_matches')) {
            return collect();
        }

        return AgentMemory::query()
            ->where('strategy', $signal->strategy)
            ->latest()
            ->take(50)
            ->get()
            ->map(function (AgentMemory $memory) use ($signal): ?AgentMemoryMatch {
                $similarity = $this->memorySimilarity($memory, $signal);

                if ($similarity < 35) {
                    return null;
                }

                $memory->update([
                    'last_matched_at' => now(),
                    'occurrences' => (int) $memory->occurrences + 1,
                ]);

                return AgentMemoryMatch::create([
                    'agent_memory_id' => $memory->id,
                    'signal_market_snapshot_id' => $signal->id,
                    'strategy' => $signal->strategy,
                    'symbol' => $signal->symbol,
                    'timeframe' => $signal->timeframe,
                    'similarity_score' => round($similarity, 2),
                    'lesson' => $memory->lesson,
                    'match_context' => [
                        'signal_market_species' => $signal->market_species,
                        'memory_market_species' => $memory->market_species,
                        'signal_snapshot' => $signal->snapshot,
                    ],
                ]);
            })
            ->filter()
            ->sortByDesc('similarity_score')
            ->values();
    }

    private function healthDefinitions(): array
    {
        return [
            [
                'key' => 'market_feed',
                'label' => 'Market Feed',
                'stale_after' => 900,
                // A global green status is meaningful only when every active
                // instrument is complete. The latest candle from one symbol
                // must never hide an outage in another market.
                'status' => fn (): array => $this->marketFeedStatus(),
            ],
            [
                'key' => 'market_reality',
                'label' => 'Market Snapshot',
                'stale_after' => (int) config('services.market_reality.stale_after_seconds', 7200),
                'status' => fn (): array => $this->freshnessStatus(
                    MarketStateSnapshot::query()->latest('time')->value('time'),
                    (int) config('services.market_reality.stale_after_seconds', 7200),
                    'Latest market state snapshot',
                ),
            ],
            [
                'key' => 'signal_foundation',
                'label' => 'Signal Snapshot',
                'stale_after' => 3600,
                'status' => fn (): array => $this->signalFoundationStatus(),
            ],
            [
                'key' => 'lab_pipeline',
                'label' => 'Lab Pipeline',
                'stale_after' => 5400,
                'status' => fn (): array => $this->labPipelineStatus(),
            ],
            [
                'key' => 'event_store',
                'label' => 'Event Store',
                'stale_after' => 600,
                'status' => fn (): array => ['status' => 'ok', 'score' => 100, 'message' => 'Event store is writable.', 'last_ok_at' => now(), 'metrics' => ['events' => SystemEvent::count()]],
            ],
            [
                'key' => 'access_control',
                'label' => 'Access Control',
                'stale_after' => 86400,
                'status' => fn (): array => $this->accessControlStatus(),
            ],
            [
                'key' => 'database_backup',
                'label' => 'Database Backup',
                'stale_after' => (int) config('database.backup.stale_after_seconds', 172800),
                'status' => fn (): array => $this->databaseBackupStatus(),
            ],
            [
                'key' => 'scientist_memory',
                'label' => 'Agent Memory',
                'stale_after' => 86400,
                'status' => fn (): array => ['status' => AgentMemory::count() > 0 ? 'ok' : 'warning', 'score' => AgentMemory::count() > 0 ? 85 : 55, 'message' => AgentMemory::count() > 0 ? 'Agent memory has observations.' : 'No agent memories yet.', 'last_ok_at' => AgentMemory::latest()->value('created_at'), 'metrics' => ['memories' => AgentMemory::count()]],
            ],
            [
                'key' => 'reality_loop',
                'label' => 'Reality Loop',
                'stale_after' => 604800,
                'status' => fn (): array => $this->realityLoopStatus(),
            ],
            [
                'key' => 'scheduler',
                'label' => 'Scheduler',
                'stale_after' => 300,
                'status' => fn (): array => $this->schedulerStatus(),
            ],
        ];
    }

    private function upsertHealth(array $definition): ServiceHealthCheck
    {
        $status = $definition['status']();
        $isOk = $status['status'] === 'ok';

        return ServiceHealthCheck::updateOrCreate(
            ['service_key' => $definition['key']],
            [
                'service_label' => $definition['label'],
                'status' => $status['status'],
                'health_score' => round((float) $status['score'], 2),
                'last_ok_at' => $isOk ? now() : ($status['last_ok_at'] ?? null),
                'last_checked_at' => now(),
                'stale_after_seconds' => $definition['stale_after'],
                'message' => $status['message'],
                'metrics' => $status['metrics'] ?? [],
            ],
        );
    }

    private function freshnessStatus($timestamp, int $staleAfterSeconds, string $label): array
    {
        if (! $timestamp) {
            return ['status' => 'critical', 'score' => 0, 'message' => "{$label} not found.", 'last_ok_at' => null, 'metrics' => []];
        }

        // Carbon returns a signed difference. A future timestamp must never
        // produce a negative freshness age and mask a broken data pipeline.
        $age = max(0, (int) Carbon::parse((string) $timestamp)->diffInSeconds(now()));
        $status = $age <= $staleAfterSeconds ? 'ok' : ($age <= $staleAfterSeconds * 3 ? 'warning' : 'critical');
        $score = $status === 'ok' ? 100 : ($status === 'warning' ? 60 : 20);

        return [
            'status' => $status,
            'score' => $score,
            'message' => "{$label} age: {$age}s.",
            'last_ok_at' => $status === 'ok' ? now() : null,
            'metrics' => ['age_seconds' => $age, 'stale_after_seconds' => $staleAfterSeconds],
        ];
    }

    private function marketFeedStatus(): array
    {
        if (! Schema::hasTable('market_data_sync_states')) {
            return $this->freshnessStatus(Candle::query()->latest('time')->value('time'), 900, 'Latest candle');
        }

        $states = MarketDataSyncState::query()
            ->where('provider', $this->activeMarketProvider())
            ->whereIn('symbol', $this->configuredMarketSymbols())
            ->whereIn('timeframe', $this->configuredMarketTimeframes())
            ->get();
        if ($states->isEmpty()) {
            return ['status' => 'critical', 'score' => 0, 'message' => 'No per-market feed states found.', 'last_ok_at' => null, 'metrics' => []];
        }

        $staleAfter = (int) config('services.mt5.feed_stale_after_seconds', 900);
        $lostAfter = (int) config('services.mt5.feed_lost_after_seconds', 1200);
        $assessed = $states->map(function (MarketDataSyncState $state) use ($staleAfter, $lostAfter): array {
            $lastConfirmed = $state->last_confirmed_candle_at;
            $age = $lastConfirmed
                ? max(0, (int) $lastConfirmed->diffInSeconds(now()))
                : PHP_INT_MAX;
            $status = (string) $state->status;
            $severity = 'ok';
            $reason = 'healthy';

            if ($status !== 'healthy') {
                $severity = in_array($status, ['offline', 'failed'], true) ? 'critical' : 'warning';
                $reason = "sync status {$status}";
            } elseif ($age > $lostAfter) {
                $severity = 'critical';
                $reason = "candle age {$age}s";
            } elseif ($age > $staleAfter || $age === PHP_INT_MAX) {
                $severity = 'warning';
                $reason = $age === PHP_INT_MAX ? 'no confirmed candle' : "candle age {$age}s";
            }

            return [
                'symbol' => $state->symbol,
                'timeframe' => $state->timeframe,
                'severity' => $severity,
                'reason' => $reason,
                'age_seconds' => $age === PHP_INT_MAX ? null : $age,
            ];
        });
        $blocked = $assessed->where('severity', '!=', 'ok')->values();
        if ($blocked->isNotEmpty()) {
            return [
                'status' => $blocked->contains('severity', 'critical') ? 'critical' : 'warning',
                'score' => $blocked->contains('severity', 'critical') ? 0 : 60,
                'message' => 'Incomplete feeds: '.$blocked->map(fn (array $feed) => "{$feed['symbol']} {$feed['timeframe']} ({$feed['reason']})")->implode(', ').'.',
                'last_ok_at' => null,
                'metrics' => ['blocked_markets' => $blocked->map(fn (array $feed) => "{$feed['symbol']}/{$feed['timeframe']}")->values()->all()],
            ];
        }

        return [
            'status' => 'ok',
            'score' => 100,
            'message' => 'All active per-market feeds are healthy and fresh.',
            'last_ok_at' => now(),
            'metrics' => ['markets' => $assessed->map(fn (array $feed) => "{$feed['symbol']}/{$feed['timeframe']}")->values()->all()],
        ];
    }

    private function signalFoundationStatus(): array
    {
        $latestSignal = SignalMarketSnapshot::query()->latest()->value('created_at');
        if ($latestSignal) {
            return $this->freshnessStatus($latestSignal, 3600, 'Latest signal market snapshot');
        }

        $eligible = ModelMarketPerformance::query()
            ->where('evidence_status', 'valid')
            ->whereIn('status', ['forward_validated', 'paper'])
            ->where('paper_status', '!=', 'failed')
            ->exists();
        if (! $eligible) {
            return [
                'status' => 'warning', 'score' => 50,
                'message' => 'No valid paper-eligible candidate yet; signal evidence is intentionally blocked by the forward gate.',
                'last_ok_at' => null, 'metrics' => ['paper_eligible_candidates' => 0],
            ];
        }

        return ['status' => 'critical', 'score' => 0, 'message' => 'Paper-eligible candidate exists but no signal snapshot was captured.', 'last_ok_at' => null, 'metrics' => ['paper_eligible_candidates' => 1]];
    }

    private function labPipelineStatus(): array
    {
        if (! Schema::hasTable('lab_generations') || ! Schema::hasTable('lab_agents')) {
            return [
                'status' => 'critical',
                'score' => 0,
                'message' => 'Lab pipeline schema is incomplete; lifecycle monitoring is unavailable.',
                'last_ok_at' => null,
                'metrics' => ['promotion_evidence' => false],
            ];
        }

        $active = LabGeneration::query()
            ->whereIn('status', LabPopulationService::ACTIVE_GENERATION_STATUSES)
            ->with([
                'laboratory:id,symbol,timeframe',
                'agents:id,lab_generation_id,lifecycle_status,updated_at',
            ])
            ->get();
        $latest = LabGeneration::query()->latest('updated_at')->first(['id', 'status', 'updated_at']);
        $activeAgents = $active->flatMap(fn (LabGeneration $generation) => $generation->agents);
        $openAgentStatuses = ['draft', 'queued', 'screening', 'evaluation_error', 'full_queued', 'training', 'full_validation'];
        $queuedAgentStatuses = ['queued', 'screening', 'full_queued'];
        $queueBackend = (string) config('queue.default');
        $queueRows = collect();

        // Only the database queue exposes durable job rows through this
        // connection. Redis/SQS workers remain observable through the agent
        // lifecycle and watchdog; absence of database rows must not be called
        // an outage on a different queue backend.
        if ($queueBackend === 'database' && Schema::hasTable('jobs')) {
            $queueRows = collect(DB::table('jobs')
                ->whereIn('queue', array_values(array_unique(array_merge(
                    [(string) config('services.lab_queue.screening_queue', 'lab-screening')],
                    [(string) config('services.lab_queue.frontier_queue', 'lab-frontier')],
                    (array) config('services.lab_queue.legacy_screening_queues', []),
                    ['lab-full-validation'],
                ))))
                ->get(['queue', 'attempts', 'created_at', 'payload']));
        }

        $activeAgentIds = $activeAgents->pluck('id')->map(fn (mixed $id): int => (int) $id)->values();
        $linkedQueueRows = $queueRows->filter(function (object $job) use ($activeAgentIds): bool {
            $payload = (string) ($job->payload ?? '');
            foreach ($activeAgentIds as $agentId) {
                if (preg_match('/labAgentId[^0-9]{1,24}'.preg_quote((string) $agentId, '/').'(?:[^0-9]|$)/', $payload) === 1) {
                    return true;
                }
            }

            return false;
        })->values();
        $oldestQueueAge = $linkedQueueRows->isEmpty()
            ? null
            : $linkedQueueRows->map(fn (object $job): int => max(0, now()->timestamp - (int) ($job->created_at ?? now()->timestamp)))->max();
        $maxQueueAttempts = $linkedQueueRows->isEmpty()
            ? 0
            : (int) $linkedQueueRows->max(fn (object $job): int => (int) ($job->attempts ?? 0));

        $generationSummary = $active->map(function (LabGeneration $generation) use ($openAgentStatuses): array {
            $age = $generation->updated_at
                ? max(0, (int) Carbon::parse($generation->updated_at)->diffInSeconds(now()))
                : null;

            return [
                'id' => $generation->id,
                'generation' => $generation->generation,
                'symbol' => $generation->laboratory?->symbol,
                'timeframe' => $generation->laboratory?->timeframe,
                'status' => $generation->status,
                'age_seconds' => $age,
                'completed_at_present' => $generation->completed_at !== null,
                'open_agents' => $generation->agents->whereIn('lifecycle_status', $openAgentStatuses)->count(),
                'agents' => $generation->agents->count(),
            ];
        })->values();
        $metrics = [
            'active_generations' => $active->count(),
            'active_agents' => $activeAgents->count(),
            'open_agents' => $activeAgents->whereIn('lifecycle_status', $openAgentStatuses)->count(),
            'queued_or_screening_agents' => $activeAgents->whereIn('lifecycle_status', $queuedAgentStatuses)->count(),
            'queue_backend' => $queueBackend,
            'queue_inspection' => $queueBackend === 'database' ? 'database_rows' : 'lifecycle_only',
            'linked_pending_jobs' => $linkedQueueRows->count(),
            'oldest_linked_job_age_seconds' => $oldestQueueAge,
            'max_linked_job_attempts' => $maxQueueAttempts,
            'generations' => $generationSummary->all(),
            'promotion_evidence' => false,
        ];
        $fullReplayCoverage = $active
            ->filter(fn (LabGeneration $generation): bool => (string) $generation->status === 'full_validation')
            ->mapWithKeys(function (LabGeneration $generation): array {
                $rollingManifest = data_get($generation->trigger_context, 'canonical_dataset_snapshots.price.manifest');
                $foundationManifest = data_get($generation->trigger_context, 'canonical_dataset_snapshots.foundation.manifest');
                return [(string) $generation->id => app(HistoricalDataQualityService::class)->fullReplayCoverage(
                    (string) $generation->laboratory?->symbol,
                    (string) $generation->laboratory?->timeframe,
                    is_array($rollingManifest) ? $rollingManifest : null,
                    is_array($foundationManifest) ? $foundationManifest : null,
                )];
            });
        $metrics['full_replay_coverage'] = $fullReplayCoverage->all();

        if ($active->isEmpty()) {
            if (! $latest) {
                return [
                    'status' => 'warning',
                    'score' => 55,
                    'message' => 'Lab pipeline has not created a generation yet.',
                    'last_ok_at' => null,
                    'metrics' => $metrics + ['latest_generation' => null],
                ];
            }

            return [
                'status' => 'ok',
                'score' => 90,
                'message' => "Lab pipeline is idle; latest generation is terminal ({$latest->status}).",
                'last_ok_at' => now(),
                'metrics' => $metrics + ['latest_generation' => ['id' => $latest->id, 'status' => $latest->status]],
            ];
        }

        $inconsistent = $active->filter(fn (LabGeneration $generation): bool => $generation->completed_at !== null);
        if ($inconsistent->isNotEmpty()) {
            return [
                'status' => 'critical',
                'score' => 0,
                'message' => 'Active lab generation has a terminal completed_at marker; lifecycle boundary is inconsistent.',
                'last_ok_at' => null,
                'metrics' => $metrics + ['inconsistent_generation_ids' => $inconsistent->pluck('id')->values()->all()],
            ];
        }

        $blockedCoverage = $fullReplayCoverage->filter(fn (array $coverage): bool => ($coverage['status'] ?? 'blocked') !== 'ready');
        if ($blockedCoverage->isNotEmpty()) {
            return [
                'status' => 'critical',
                'score' => 0,
                'message' => 'Full-validation work is blocked by insufficient foundation/rolling dataset coverage; no quality verdict is valid.',
                'last_ok_at' => null,
                'metrics' => $metrics + ['coverage_blocked_generation_ids' => $blockedCoverage->keys()->map(fn (mixed $id): int => (int) $id)->values()->all()],
            ];
        }

        $terminalBoundary = $active->filter(function (LabGeneration $generation): bool {
            if (! in_array((string) $generation->status, ['screening', 'full_validation'], true)) {
                return false;
            }

            $age = $generation->updated_at
                ? max(0, (int) Carbon::parse($generation->updated_at)->diffInSeconds(now()))
                : PHP_INT_MAX;

            $boundaryOpenStatuses = (string) $generation->status === 'full_validation'
                ? ['full_queued', 'training']
                : ['draft', 'queued', 'screening', 'evaluation_error'];

            return $age >= 600 && $generation->agents->whereIn('lifecycle_status', $boundaryOpenStatuses)->isEmpty();
        });
        if ($terminalBoundary->isNotEmpty()) {
            return [
                'status' => 'critical',
                'score' => 0,
                'message' => 'Active lab generation has no open agents but has not closed its lifecycle status.',
                'last_ok_at' => null,
                'metrics' => $metrics + ['terminal_boundary_generation_ids' => $terminalBoundary->pluck('id')->values()->all()],
            ];
        }

        $databaseQueueOrphans = collect();
        if ($queueBackend === 'database') {
            $databaseQueueOrphans = $active->filter(function (LabGeneration $generation) use ($queuedAgentStatuses, $linkedQueueRows): bool {
                $age = $generation->updated_at
                    ? max(0, (int) Carbon::parse($generation->updated_at)->diffInSeconds(now()))
                    : PHP_INT_MAX;
                if ($age < 900) return false;

                $queuedIds = $generation->agents->whereIn('lifecycle_status', $queuedAgentStatuses)->pluck('id');
                if ($queuedIds->isEmpty()) return false;

                return ! $linkedQueueRows->contains(function (object $job) use ($queuedIds): bool {
                    $payload = (string) ($job->payload ?? '');
                    return $queuedIds->contains(fn (mixed $id): bool => preg_match('/labAgentId[^0-9]{1,24}'.preg_quote((string) $id, '/').'(?:[^0-9]|$)/', $payload) === 1);
                });
            });
        }
        if ($databaseQueueOrphans->isNotEmpty()) {
            return [
                'status' => 'critical',
                'score' => 0,
                'message' => 'Active lab agents are queued without a durable database queue owner.',
                'last_ok_at' => null,
                'metrics' => $metrics + ['orphaned_queue_generation_ids' => $databaseQueueOrphans->pluck('id')->values()->all()],
            ];
        }

        $oldestActiveAge = $generationSummary->max('age_seconds') ?? 0;
        if ($oldestActiveAge >= 5400) {
            return [
                'status' => 'warning',
                'score' => 60,
                'message' => 'Lab pipeline has an active generation with no recent lifecycle update.',
                'last_ok_at' => null,
                'metrics' => $metrics + ['oldest_active_generation_age_seconds' => $oldestActiveAge],
            ];
        }

        if ($maxQueueAttempts >= 4 && ($oldestQueueAge ?? 0) >= 900) {
            return [
                'status' => 'warning',
                'score' => 70,
                'message' => 'Lab queue is progressing under repeated operational retries; strategy evidence remains unchanged.',
                'last_ok_at' => null,
                'metrics' => $metrics + ['oldest_active_generation_age_seconds' => $oldestActiveAge],
            ];
        }

        return [
            'status' => 'ok',
            'score' => 100,
            'message' => 'Lab pipeline has active lifecycle work with no detected stall or boundary inconsistency.',
            'last_ok_at' => now(),
            'metrics' => $metrics + ['oldest_active_generation_age_seconds' => $oldestActiveAge],
        ];
    }

    private function accessControlStatus(): array
    {
        $activeUsers = User::query()->where('is_active', true)->count();
        $activeAdmins = User::query()->where('is_active', true)->where('role', 'admin')->count();

        if ($activeUsers === 0) {
            return [
                'status' => 'critical',
                'score' => 0,
                'message' => 'No active application user exists; dashboard access is unavailable.',
                'last_ok_at' => null,
                'metrics' => ['active_users' => 0, 'active_admins' => 0],
            ];
        }

        if ($activeAdmins === 0) {
            return [
                'status' => 'warning',
                'score' => 60,
                'message' => 'Active users exist, but no active administrator is configured.',
                'last_ok_at' => null,
                'metrics' => ['active_users' => $activeUsers, 'active_admins' => 0],
            ];
        }

        return [
            'status' => 'ok',
            'score' => 100,
            'message' => 'Active user and administrator access is configured.',
            'last_ok_at' => now(),
            'metrics' => ['active_users' => $activeUsers, 'active_admins' => $activeAdmins],
        ];
    }

    private function realityLoopStatus(): array
    {
        $runs = RealityVerificationRun::query()->count();

        // Reality Verification consumes the frozen secondary-intelligence
        // layer. Its absence is an intentional P0 policy state while the
        // canonical Market Reality snapshot remains independently active.
        if (! (bool) config('services.secondary_intelligence.enabled', false)) {
            return [
                'status' => 'ok',
                'score' => 85,
                'message' => 'Reality verification is intentionally frozen by the P0 secondary-intelligence policy.',
                'last_ok_at' => now(),
                'metrics' => ['runs' => $runs, 'enabled' => false, 'policy_state' => 'frozen_by_p0'],
            ];
        }

        $latest = RealityVerificationRun::query()->latest('created_at')->first();
        if (! $latest) {
            return [
                'status' => 'warning',
                'score' => 50,
                'message' => 'Reality verification has not run yet.',
                'last_ok_at' => null,
                'metrics' => ['runs' => 0, 'enabled' => true],
            ];
        }

        if ((string) $latest->status !== 'success') {
            return [
                'status' => 'critical',
                'score' => 20,
                'message' => "Latest reality verification ended with status: {$latest->status}.",
                'last_ok_at' => null,
                'metrics' => ['runs' => $runs, 'enabled' => true, 'latest_status' => $latest->status],
            ];
        }

        $freshness = $this->freshnessStatus(
            $latest->finished_at ?? $latest->created_at,
            604800,
            'Latest reality verification',
        );
        $freshness['metrics']['runs'] = $runs;
        $freshness['metrics']['enabled'] = true;

        return $freshness;
    }

    private function databaseBackupStatus(): array
    {
        $directory = storage_path('app/backups');
        $staleAfter = (int) config('database.backup.stale_after_seconds', 172800);
        $files = collect(File::glob($directory.'/neurotrader_*.sql'))
            ->filter(fn (string $path): bool => File::isFile($path))
            ->sortByDesc(fn (string $path): int => File::lastModified($path))
            ->values();

        foreach ($files as $path) {
            $manifestPath = $path.'.manifest.json';
            if (! File::isFile($manifestPath)) {
                continue;
            }

            $manifest = json_decode((string) File::get($manifestPath), true);
            $expectedHash = is_array($manifest) ? (string) ($manifest['sha256'] ?? '') : '';
            $actualHash = hash_file('sha256', $path);
            if ($expectedHash === '' || ! is_string($actualHash) || ! hash_equals($expectedHash, $actualHash)) {
                continue;
            }

            try {
                $createdAt = is_array($manifest) && ! empty($manifest['created_at_utc'])
                    ? Carbon::parse((string) $manifest['created_at_utc'], 'UTC')
                    : Carbon::createFromTimestampUTC(File::lastModified($path));
            } catch (\Throwable) {
                // A malformed manifest is not usable backup evidence.
                continue;
            }
            $age = max(0, (int) $createdAt->diffInSeconds(now('UTC')));
            $status = $age <= $staleAfter ? 'ok' : ($age <= $staleAfter * 2 ? 'warning' : 'critical');
            $score = $status === 'ok' ? 100 : ($status === 'warning' ? 60 : 0);

            return [
                'status' => $status,
                'score' => $score,
                'message' => "Latest verified database backup age: {$age}s.",
                'last_ok_at' => $status === 'ok' ? $createdAt : null,
                'metrics' => [
                    'file' => basename($path),
                    'age_seconds' => $age,
                    'stale_after_seconds' => $staleAfter,
                    'bytes' => File::size($path),
                    'sha256_verified' => true,
                ],
            ];
        }

        return [
            'status' => 'critical',
            'score' => 0,
            'message' => 'No verified database backup with a matching SHA-256 manifest was found.',
            'last_ok_at' => null,
            'metrics' => ['directory' => $directory, 'sha256_verified' => false],
        ];
    }

    private function schedulerStatus(): array
    {
        $output = '';
        $status = 'warning';
        $score = 60;

        try {
            Artisan::call('schedule:list');
            $output = Artisan::output();
            $registered = Str::contains($output, ['market-data:update', 'trading:daily-workflow']);
            $heartbeat = Cache::get('system:scheduler-heartbeat');
            $heartbeatAge = $heartbeat
                ? max(0, (int) Carbon::parse((string) $heartbeat)->diffInSeconds(now()))
                : null;

            if (! $registered || $heartbeatAge === null) {
                $status = 'warning';
                $score = 55;
            } elseif ($heartbeatAge <= 300) {
                $status = 'ok';
                $score = 100;
            } elseif ($heartbeatAge <= 900) {
                $status = 'warning';
                $score = 60;
            } else {
                $status = 'critical';
                $score = 0;
            }
        } catch (\Throwable $exception) {
            $status = 'critical';
            $score = 10;
            $output = $exception->getMessage();
            $heartbeat = null;
            $heartbeatAge = null;
        }

        return [
            'status' => $status,
            'score' => $score,
            'message' => match ($status) {
                'ok' => 'Scheduler commands are registered and the runtime heartbeat is fresh.',
                'critical' => 'Scheduler heartbeat is stale; scheduled work may be stopped.',
                default => 'Scheduler registration or runtime heartbeat needs attention.',
            },
            'last_ok_at' => $status === 'ok' ? now() : null,
            'metrics' => [
                'contains_schedule' => Str::limit($output, 500),
                'heartbeat' => $heartbeat ?? null,
                'heartbeat_age_seconds' => $heartbeatAge ?? null,
            ],
        ];
    }

    private function latestMarketSnapshot(string $symbol, string $timeframe): ?MarketStateSnapshot
    {
        if (! Schema::hasTable('market_state_snapshots')) {
            return null;
        }

        return MarketStateSnapshot::query()
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->with('marketSpecies')
            ->latest('time')
            ->first();
    }

    private function activeMarketProvider(): string
    {
        return (string) config(
            'services.mt5.provider',
            config('services.market_data.provider', 'dukascopy'),
        );
    }

    private function configuredMarketSymbols(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('services.mt5.symbols', 'XAUUSD,EURUSD,GBPUSD')),
        )));
    }

    private function configuredMarketTimeframes(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('services.mt5.timeframes', 'M15,H1')),
        )));
    }

    private function memorySimilarity(AgentMemory $memory, SignalMarketSnapshot $signal): float
    {
        $score = 20;
        $snapshot = $signal->snapshot ?? [];

        if ($memory->market_species && $signal->market_species && Str::lower($memory->market_species) === Str::lower($signal->market_species)) {
            $score += 35;
        }

        if ($memory->market_regime && data_get($snapshot, 'market_state') && Str::contains(Str::lower(data_get($snapshot, 'market_state')), Str::lower($memory->market_regime))) {
            $score += 20;
        }

        if ($memory->volatility_regime && data_get($snapshot, 'structure_state') && Str::contains(Str::lower(data_get($snapshot, 'structure_state')), Str::lower($memory->volatility_regime))) {
            $score += 10;
        }

        $score += min(20, (float) $memory->strength * 0.2);

        return min(100, $score);
    }
}
