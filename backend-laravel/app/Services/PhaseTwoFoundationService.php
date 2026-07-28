<?php

namespace App\Services;

use App\Models\AgentMemory;
use App\Models\AgentMemoryMatch;
use App\Models\Candle;
use App\Models\KnowledgeMiningRun;
use App\Models\MarketStateSnapshot;
use App\Models\MarketDataSyncState;
use App\Models\ModelMarketPerformance;
use App\Models\RealityVerificationRun;
use App\Models\ServiceHealthCheck;
use App\Models\SignalMarketSnapshot;
use App\Models\SystemEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
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
                'stale_after' => 1800,
                'status' => fn (): array => $this->freshnessStatus(MarketStateSnapshot::query()->latest('time')->value('time'), 1800, 'Latest market state snapshot'),
            ],
            [
                'key' => 'signal_foundation',
                'label' => 'Signal Snapshot',
                'stale_after' => 3600,
                'status' => fn (): array => $this->signalFoundationStatus(),
            ],
            [
                'key' => 'event_store',
                'label' => 'Event Store',
                'stale_after' => 600,
                'status' => fn (): array => ['status' => 'ok', 'score' => 100, 'message' => 'Event store is writable.', 'last_ok_at' => now(), 'metrics' => ['events' => SystemEvent::count()]],
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
                'status' => fn (): array => ['status' => RealityVerificationRun::count() > 0 ? 'ok' : 'warning', 'score' => RealityVerificationRun::count() > 0 ? 80 : 50, 'message' => RealityVerificationRun::count() > 0 ? 'Reality verification has run.' : 'Reality verification has not run yet.', 'last_ok_at' => RealityVerificationRun::latest()->value('created_at'), 'metrics' => ['runs' => RealityVerificationRun::count()]],
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
        $age = max(0, (int) now()->diffInSeconds($timestamp));
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

        $states = MarketDataSyncState::query()->where('provider', (string) config('services.market_data.provider', 'dukascopy'))->get();
        if ($states->isEmpty()) {
            return ['status' => 'critical', 'score' => 0, 'message' => 'No per-market feed states found.', 'last_ok_at' => null, 'metrics' => []];
        }

        $blocked = $states->where('status', '!=', 'healthy')->values();
        if ($blocked->isNotEmpty()) {
            return [
                'status' => $blocked->contains('status', 'offline') ? 'critical' : 'warning',
                'score' => $blocked->contains('status', 'offline') ? 0 : 60,
                'message' => 'Incomplete feeds: '.$blocked->map(fn (MarketDataSyncState $state) => "{$state->symbol} {$state->timeframe} ({$state->status})")->implode(', ').'.',
                'last_ok_at' => null,
                'metrics' => ['blocked_markets' => $blocked->pluck('symbol')->values()->all()],
            ];
        }

        return ['status' => 'ok', 'score' => 100, 'message' => 'All active per-market feeds are healthy.', 'last_ok_at' => now(), 'metrics' => ['markets' => $states->pluck('symbol')->values()->all()]];
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

    private function schedulerStatus(): array
    {
        $output = '';
        $status = 'warning';
        $score = 60;

        try {
            Artisan::call('schedule:list');
            $output = Artisan::output();
            $status = Str::contains($output, ['market-data:update', 'trading:daily-workflow']) ? 'ok' : 'warning';
            $score = $status === 'ok' ? 90 : 55;
        } catch (\Throwable $exception) {
            $status = 'critical';
            $score = 10;
            $output = $exception->getMessage();
        }

        return [
            'status' => $status,
            'score' => $score,
            'message' => $status === 'ok' ? 'Scheduler commands are registered.' : 'Scheduler needs attention.',
            'last_ok_at' => $status === 'ok' ? now() : null,
            'metrics' => ['contains_schedule' => Str::limit($output, 500)],
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
