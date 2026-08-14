<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabLearningLanePair;
use App\Models\LabLearningMemory;
use App\Models\LabMutationResponseMap;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Research-only tri-memory and mutation-bandit ledger.
 *
 * A memory is deliberately narrower than an AgentMemory narrative.  It is a
 * machine-actionable observation keyed by market, family, target, state and
 * gene.  Negative memories down-rank or quarantine a mutation; uncertainty
 * memories prevent the system from treating missing evidence as failure.
 */
class LearningMemoryService
{
    public const PROTOCOL = 'tri_memory_bandit_v1';

    public function available(): bool
    {
        return Schema::hasTable('lab_learning_memories');
    }

    /** @return array<string, mixed>|null */
    public function recordPair(
        LabLearningLanePair $pair,
        string $stage = 'screening',
        array $observation = [],
    ): ?array
    {
        if (! $this->available()) return null;

        $map = $pair->candidateResponseMap ?: LabMutationResponseMap::query()->find($pair->candidate_response_map_id);
        $agent = $pair->candidateAgent ?: LabAgent::query()->find($pair->candidate_agent_id);
        $gene = $this->causalGene($map, $agent);
        $target = (string) ($pair->target ?: 'unspecified');
        $delta = data_get($pair->target_delta, 'delta');
        $improved = data_get($pair->target_delta, 'improved');
        $observationStatus = strtolower((string) data_get($observation, 'status', ''));
        $microPassed = $stage === 'micro' && $observationStatus === 'passed';
        $microFailed = $stage === 'micro' && $observationStatus === 'failed';
        $hasObservation = ($delta !== null && $improved !== null && (array) $pair->control_metrics !== [])
            || $microPassed || $microFailed;
        // A screen-positive candidate that collapses in micro confirmation is
        // negative learning evidence. Preserve that result instead of letting
        // the earlier screen delta teach the bandit to repeat the mutation.
        $memoryType = $microFailed
            ? 'negative'
            : ($microPassed ? 'positive' : (! $hasObservation ? 'uncertainty' : ((bool) $improved ? 'positive' : 'negative')));
        $state = (string) (data_get($pair->failure_signature, 'signature')
            ?: data_get($pair->failure_signature, 'failure_type')
            ?: 'unclassified');
        $context = $this->contextFromFailureSignature((array) $pair->failure_signature);
        $direction = $gene ? (string) data_get($map?->metadata, 'mutation_direction', '') : null;
        $scope = strtoupper((string) $pair->symbol).':'.strtoupper((string) $pair->timeframe);
        $memoryKey = hash('sha256', json_encode([
            self::PROTOCOL, $scope, $pair->strategy_family, $pair->specialist_role,
            $target, $state, $gene, $direction, $context,
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $observationKey = $stage.':pair:'.$pair->id;

        $memory = LabLearningMemory::query()->firstOrCreate(
            ['memory_key' => $memoryKey],
            [
                'symbol' => strtoupper((string) $pair->symbol),
                'timeframe' => strtoupper((string) $pair->timeframe),
                'family' => $pair->strategy_family,
                'specialist_role' => $pair->specialist_role,
                'target' => $target,
                'state_signature' => $state,
                'gene' => $gene,
                'direction' => $direction,
                'memory_type' => $memoryType,
                'status' => 'active',
                'metadata' => [
                    'protocol' => self::PROTOCOL,
                    'observation_keys' => [],
                    'context' => $context,
                    'context_key' => $this->contextKey($context),
                ],
            ],
        );

        $metadata = (array) $memory->metadata;
        $seen = array_values(array_unique(array_map('strval', (array) ($metadata['observation_keys'] ?? []))));
        if (in_array($observationKey, $seen, true)) {
            return $memory->fresh()->toArray();
        }
        $seen[] = $observationKey;

        $trialCount = (int) $memory->trial_count + 1;
        $successCount = (int) $memory->success_count + ($memoryType === 'positive' ? 1 : 0);
        $failureCount = (int) $memory->failure_count + ($memoryType === 'negative' ? 1 : 0);
        $rawDelta = is_numeric($delta) ? (float) $delta : 0.0;
        $normalizedDelta = $this->normalizedDelta($target, $rawDelta);
        if ($microFailed) $normalizedDelta = -abs($normalizedDelta);
        if ($microPassed) $normalizedDelta = abs($normalizedDelta);
        $score = $this->runningScore((float) $memory->score, $trialCount, $normalizedDelta);
        $confidence = $this->confidence($trialCount, $successCount, $failureCount, $memoryType);
        $status = $this->statusFor($gene, $failureCount, $trialCount);

        $memory->update([
            'memory_type' => $memoryType,
            'status' => $status,
            'trial_count' => $trialCount,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'score' => $score,
            'confidence' => $confidence,
            'blocked_until' => $status === 'quarantined' ? now()->addDays(30) : null,
            'metadata' => [
                ...$metadata,
                'protocol' => self::PROTOCOL,
                'causal_credit_eligible' => $gene !== null,
                'observation_keys' => array_slice($seen, -100),
                'last_stage' => $stage,
                'last_delta' => $rawDelta,
                'last_improved' => $improved,
                'last_observation_status' => $observationStatus !== '' ? $observationStatus : null,
                'micro_score' => data_get($observation, 'score'),
                'context' => $context,
                'context_key' => $this->contextKey($context),
            ],
            'last_observed_at' => now(),
        ]);

        return $memory->fresh()->toArray();
    }

    /** @return array{parameter_key:?string, score:float, status:string, memory_id:?int}|null */
    public function recommend(
        string $symbol,
        string $timeframe,
        ?string $family,
        array $candidateGenes,
        ?string $target = null,
        ?string $specialistRole = null,
        ?array $context = null,
    ): ?array {
        if (! $this->available() || $candidateGenes === []) return null;

        $rows = LabLearningMemory::query()
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->whereIn('gene', array_values(array_filter(array_map('strval', $candidateGenes))))
            ->whereNotIn('status', ['quarantined'])
            ->when($family, fn ($query) => $query->where(function ($q) use ($family) {
                $q->where('family', $family)->orWhereNull('family');
            }))
            ->when($target, fn ($query) => $query->where(function ($q) use ($target) {
                $q->where('target', $target)->orWhereNull('target');
            }))
            ->when($specialistRole, fn ($query) => $query->where(function ($q) use ($specialistRole) {
                $q->where('specialist_role', $specialistRole)->orWhereNull('specialist_role');
            }))
            ->get();

        if ($context !== null) {
            $rows = $rows->filter(fn (LabLearningMemory $row): bool => $this->contextCompatible(
                (array) data_get($row->metadata, 'context', []),
                $context,
            ))->values();
        }

        if ($rows->isEmpty()) return null;

        $ranked = $rows->map(function (LabLearningMemory $row): array {
            $trials = max(0, (int) $row->trial_count);
            $posterior = ((int) $row->success_count + 1) / ($trials + 2);
            $uncertainty = 1 / sqrt($trials + 1);
            $statusPenalty = $row->status === 'downranked' ? 0.35 : 1.0;
            $failureRate = (int) $row->failure_count / max(1, $trials);
            $negativePenalty = $row->memory_type === 'negative'
                ? min(0.65, 0.20 + ($failureRate * 0.45))
                : 0.0;
            $score = ($row->score * 0.65) + ($posterior * 0.25) + ($uncertainty * 0.10) - $negativePenalty;
            return ['row' => $row, 'score' => $score * $statusPenalty];
        })->sortByDesc('score')->values();
        $best = $ranked->first();
        if (! $best) return null;

        /** @var LabLearningMemory $row */
        $row = $best['row'];
        return [
            'parameter_key' => $row->gene,
            'score' => (float) $best['score'],
            'status' => (string) $row->status,
            'memory_id' => $row->id,
        ];
    }

    /** @return list<string> */
    public function blockedMutationKeys(string $symbol, string $timeframe, ?string $family, array $keys, ?array $context = null): array
    {
        if (! $this->available() || $keys === []) return [];

        $rows = LabLearningMemory::query()
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->whereIn('gene', array_values(array_filter(array_map('strval', $keys))))
            ->where('status', 'quarantined')
            ->when($family, fn ($query) => $query->where('family', $family))
            ->get(['gene', 'metadata']);
        if ($context !== null) {
            $rows = $rows->filter(fn (LabLearningMemory $row): bool => $this->contextCompatible(
                (array) data_get($row->metadata, 'context', []),
                $context,
            ));
        }
        return $rows->pluck('gene')
            ->map(fn ($gene) => (string) $gene)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function progress(string $symbol, string $timeframe): array
    {
        if (! $this->available()) return ['available' => false];

        $query = LabLearningMemory::query()
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe));
        return [
            'available' => true,
            'total' => (clone $query)->count(),
            'positive' => (clone $query)->where('memory_type', 'positive')->count(),
            'negative' => (clone $query)->where('memory_type', 'negative')->count(),
            'uncertainty' => (clone $query)->where('memory_type', 'uncertainty')->count(),
            'downranked' => (clone $query)->where('status', 'downranked')->count(),
            'quarantined' => (clone $query)->where('status', 'quarantined')->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function contextFromFailureSignature(array $signature): array
    {
        $state = (array) data_get($signature, 'state', []);
        $keys = [
            'cluster_id', 'regime', 'volatility', 'transition_state',
            'spread_liquidity_state', 'session', 'volume_state',
            'volume_quality', 'volume_available',
        ];
        $context = [];
        foreach ($keys as $key) {
            $value = data_get($state, $key);
            if ($value !== null && $value !== '') {
                $context[$key] = is_scalar($value) ? $value : json_encode($value);
            }
        }

        return $context;
    }

    private function contextKey(array $context): string
    {
        ksort($context);

        return hash('sha256', json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function contextCompatible(array $stored, array $requested): bool
    {
        foreach ($requested as $key => $value) {
            if ($value === null || $value === '') continue;
            // Legacy memories without context remain broad priors. New
            // contextual memories are isolated when their coordinate differs.
            if (! array_key_exists($key, $stored) || $stored[$key] === null || $stored[$key] === '') continue;
            if ((string) $stored[$key] !== (string) $value) return false;
        }

        return true;
    }

    private function causalGene(?LabMutationResponseMap $map, ?LabAgent $agent): ?string
    {
        if (! $map || data_get($map->metadata, 'causal_credit_eligible') === false) return null;
        $diff = (array) ($agent?->parameter_diff ?? []);
        if (count($diff) !== 1) return null;
        if (data_get($map->metadata, 'single_gene') === false) return null;
        return (string) array_key_first($diff);
    }

    private function normalizedDelta(string $target, float $delta): float
    {
        return in_array(strtolower($target), ['drawdown', 'drawdown_risk', 'max_drawdown', 'risk'], true)
            ? -$delta
            : $delta;
    }

    private function runningScore(float $old, int $trials, float $delta): float
    {
        return (($old * max(0, $trials - 1)) + $delta) / max(1, $trials);
    }

    private function confidence(int $trials, int $successes, int $failures, string $type): float
    {
        if ($type === 'uncertainty') return min(0.35, 1 / sqrt($trials + 1));
        $observed = $successes + $failures;
        return min(0.99, ($observed / max(1, $trials)) * (1 - (1 / sqrt($trials + 1))));
    }

    private function statusFor(?string $gene, int $failures, int $trials): string
    {
        if ($gene !== null && $failures >= (int) config('services.learning_lane.negative_quarantine_after', 5)) return 'quarantined';
        if ($gene !== null && $failures >= (int) config('services.learning_lane.negative_downrank_after', 3) && $trials >= 3) return 'downranked';
        return 'active';
    }
}
