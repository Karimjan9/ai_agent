<?php

namespace App\Services;

use App\Models\AgentLearningLesson;
use App\Models\AgentLearningRetrieval;
use App\Models\AgentLearningSettlement;
use App\Models\LabAgent;
use App\Models\LabLearningLanePair;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LearningRetrievalService
{
    public function __construct(private ContextContractV2Service $contexts) {}

    /**
     * Retrieve the single canonical lesson pre-registered by a causal cohort.
     *
     * A bounded top-N contextual packet is correct for ordinary exploration,
     * but it can evict the cohort's source lesson and silently turn the guided
     * arm into another blind mutation.  Counterfactual experiments therefore
     * use an exact, fail-closed retrieval with its own durable retrieval row.
     *
     * @return array<string,mixed>
     */
    public function retrieveCanonicalLesson(
        int $lessonId,
        string $symbol,
        string $timeframe,
        string $family,
        array $context = [],
        ?LabAgent $agent = null,
        ?int $episodeId = null,
    ): array {
        $packetId = (string) Str::uuid();
        if (! Schema::hasTable('agent_learning_lessons')
            || ! Schema::hasTable('agent_learning_retrievals')
            || ! Schema::hasTable('agent_learning_settlements')
            || ! Schema::hasTable('lab_learning_lane_pairs')) {
            return $this->missingCanonicalPacket($packetId, $lessonId, 'CAUSAL_RETRIEVAL_TABLES_UNAVAILABLE');
        }
        $lesson = AgentLearningLesson::query()
            ->whereKey($lessonId)
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->where('strategy_family', $family)
            ->where('lesson_type', 'skill_lesson')
            ->whereIn('status', ['provisional', 'confirmed'])
            ->where('outcome', 'beneficial')
            ->whereNotNull('parameter_key')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
        if (! $lesson) {
            return $this->missingCanonicalPacket($packetId, $lessonId, 'PRE_REGISTERED_LESSON_UNAVAILABLE');
        }
        $pairId = (int) data_get($lesson->evidence, 'pair_id', 0);
        $pair = $pairId > 0
            ? LabLearningLanePair::query()->with('controlResponseMap')->find($pairId)
            : null;
        $settled = $pair && $pair->isVerifiedControlPair()
            && AgentLearningSettlement::query()
                ->where('source_type', LabLearningLanePair::class)
                ->where('source_id', $pairId)
                ->where('evidence_state', 'positive')
                ->where('hard_failure', false)
                ->exists();
        if (! $settled) {
            return $this->missingCanonicalPacket($packetId, $lessonId, 'PRE_REGISTERED_LESSON_NOT_CANONICAL_SETTLED');
        }
        $retrieval = AgentLearningRetrieval::query()->create([
            'retrieval_id' => (string) Str::uuid(),
            'packet_id' => $packetId,
            'episode_id' => $episodeId,
            'agent_learning_lesson_id' => $lesson->id,
            'lab_agent_id' => $agent?->id,
            'symbol' => strtoupper($symbol),
            'timeframe' => strtoupper($timeframe),
            'strategy_family' => $family,
            'retrieval_state' => 'retrieved',
            'match_level' => 'pre_registered_causal_source',
            'rank_score' => 100,
            'context' => [...$context, 'required_source_lesson_id' => (int) $lesson->id],
            'metadata' => [
                'parameter_key' => $lesson->parameter_key,
                'provenance' => 'canonical_settled',
                'exact_source_required' => true,
                'retrieval_decision' => ['considered' => true, 'compatible' => true, 'accepted' => false,
                    'reason' => 'PRE_REGISTERED_CAUSAL_SOURCE', 'expected_uplift' => null, 'uncertainty' => null,
                    'outcome_settlement_id' => null],
                'promotion_evidence' => false,
            ],
        ]);
        $payload = [
            'lesson_id' => (int) $lesson->id,
            'retrieval_id' => (string) $retrieval->retrieval_id,
            'parameter_key' => $lesson->parameter_key,
            'failure_class' => $lesson->failure_class,
            'match_level' => 'pre_registered_causal_source',
            'provenance' => 'canonical_settled',
            'score' => 100,
        ];

        return [
            'packet_id' => $packetId,
            'status' => 'ok',
            'retrieval_mode' => 'pre_registered_exact_causal_source',
            'required_source_lesson_id' => (int) $lesson->id,
            'positive_lessons' => [$payload],
            'harmful_lessons' => [],
            'uncertainty_lessons' => [],
            'blocked_mutations' => [],
            'recommended_genes' => [(string) $lesson->parameter_key],
            'retrieval_count' => 1,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function retrieve(string $symbol, string $timeframe, ?string $family, array $context = [], ?LabAgent $agent = null, ?int $episodeId = null, int $limit = 5): array
    {
        $packetId = (string) Str::uuid();
        if (! Schema::hasTable('agent_learning_lessons') || ! Schema::hasTable('agent_learning_retrievals')) {
            return ['packet_id' => $packetId, 'status' => 'unavailable', 'positive_lessons' => [], 'harmful_lessons' => [], 'uncertainty_lessons' => [], 'blocked_mutations' => []];
        }
        $canonicalContext = array_filter(
            $this->contexts->canonicalAxes($context),
            static fn ($value): bool => $value !== null && $value !== '',
        );
        $rows = AgentLearningLesson::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->where(function ($q) use ($family): void {
            if ($family) {
                $q->where('strategy_family', $family);
            }
        })->whereIn('status', ['provisional', 'confirmed'])->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->get();
        $lessonPairIds = $rows->map(fn (AgentLearningLesson $lesson): int => (int) data_get($lesson->evidence, 'pair_id', 0))->filter()->unique()->values();
        $settlements = $lessonPairIds->isEmpty()
            || ! Schema::hasTable('agent_learning_settlements')
            || ! Schema::hasTable('lab_learning_lane_pairs')
            ? collect()
            : AgentLearningSettlement::query()->where('source_type', LabLearningLanePair::class)->whereIn('source_id', $lessonPairIds)->get();
        $settledPairIds = $settlements->pluck('source_id')->unique()->values();
        $verifiedPairIds = $settledPairIds->isEmpty()
            ? collect()
            : LabLearningLanePair::query()->with('controlResponseMap')->whereIn('id', $settledPairIds)
                ->whereIn('status', ['canonical_episode_settled', 'lesson_compiled', 'skill_confirmed'])->get()
                ->filter(fn (LabLearningLanePair $pair): bool => $pair->isVerifiedControlPair())
                ->pluck('id');
        $positivePairIds = $settlements->filter(fn (AgentLearningSettlement $settlement): bool => $verifiedPairIds->contains((int) $settlement->source_id)
            && $settlement->evidence_state === 'positive'
            && ! $settlement->hard_failure
        )->pluck('source_id')->unique()->values();
        $negativePairIds = $settlements->filter(fn (AgentLearningSettlement $settlement): bool => $verifiedPairIds->contains((int) $settlement->source_id)
            && ($settlement->evidence_state === 'negative' || $settlement->hard_failure)
        )->pluck('source_id')->unique()->values();
        $ranked = $rows->map(function (AgentLearningLesson $lesson) use ($canonicalContext, $verifiedPairIds, $positivePairIds, $negativePairIds): array {
            $fields = ['regime', 'volatility', 'session', 'transition_state', 'spread_liquidity_state', 'volume_state', 'direction', 'state_cluster_id'];
            $lessonContext = $this->contexts->canonicalAxes([
                'regime' => $lesson->regime,
                'volatility' => $lesson->volatility,
                'session' => data_get($lesson->evidence, 'failure_signature.state.session', data_get($lesson->evidence, 'context.session')),
                'transition_state' => $lesson->transition_state,
                'spread_liquidity_state' => $lesson->spread_liquidity_state,
                'volume_state' => data_get($lesson->evidence, 'failure_signature.state.volume_state', data_get($lesson->evidence, 'context.volume_state')),
                'direction' => data_get($lesson->evidence, 'failure_signature.state.direction', data_get($lesson->evidence, 'context.direction')),
                'state_cluster_id' => $lesson->state_cluster_id,
            ]);
            $exact = 0;
            $conflict = false;
            $specified = 0;
            foreach ($fields as $field) {
                $stored = $lessonContext[$field] ?? null;
                $requested = $canonicalContext[$field] ?? null;
                if ($stored !== null && $stored !== '') {
                    $specified++;
                    if ($requested !== null && $stored === $requested) {
                        $exact++;
                    } elseif ($requested !== null) {
                        $conflict = true;
                    }
                }
            }
            $pairId = (int) data_get($lesson->evidence, 'pair_id', 0);
            $canonical = match ((string) $lesson->outcome) {
                'beneficial' => $positivePairIds->contains($pairId),
                'harmful' => $negativePairIds->contains($pairId),
                default => $verifiedPairIds->contains($pairId),
            };

            return ['lesson' => $lesson, 'match_level' => $conflict ? 'incompatible' : ($specified > 0 && $exact === $specified ? 'exact_context' : ($specified > 0 ? 'family_prior' : 'broad_prior')), 'provenance' => $canonical ? 'canonical_settled' : 'legacy_prior', 'score' => ($canonical ? 4 : 0) + ($lesson->status === 'confirmed' ? 2 : 1) + $exact + ($specified > 0 && $exact === $specified ? 2 : 0) + (float) ($lesson->lower_confidence_bound ?? 0)];
        })->reject(fn (array $row) => $row['match_level'] === 'incompatible')->sortByDesc('score')->values();
        // Retrieval is a bounded decision aid, not an unbounded prompt that
        // can drown a single-gene experiment in historical advice. Keep at
        // most five records in each semantic bucket: positive, harmful and
        // uncertainty evidence remain independently visible.
        $bucketLimit = max(1, min(5, $limit));
        $groups = ['positive_lessons' => [], 'harmful_lessons' => [], 'uncertainty_lessons' => []];
        $ids = [];
        foreach ($ranked as $row) {
            /** @var AgentLearningLesson $lesson */ $lesson = $row['lesson'];
            $bucket = $lesson->lesson_type === 'harmful_lesson' ? 'harmful_lessons' : ($lesson->outcome === 'beneficial' ? 'positive_lessons' : 'uncertainty_lessons');
            if (count($groups[$bucket]) >= $bucketLimit) {
                continue;
            }
            $record = AgentLearningRetrieval::query()->create(['retrieval_id' => (string) Str::uuid(), 'packet_id' => $packetId, 'episode_id' => $episodeId, 'agent_learning_lesson_id' => $lesson->id, 'lab_agent_id' => $agent?->id, 'symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe), 'strategy_family' => $family, 'retrieval_state' => 'retrieved', 'match_level' => $row['match_level'], 'rank_score' => $row['score'], 'context' => $canonicalContext, 'metadata' => ['parameter_key' => $lesson->parameter_key, 'provenance' => $row['provenance'], 'raw_requested_context' => $context, 'retrieval_decision' => ['considered' => true, 'compatible' => true, 'accepted' => false, 'reason' => 'CONTEXT_COMPATIBLE', 'expected_uplift' => null, 'uncertainty' => null, 'outcome_settlement_id' => null], 'promotion_evidence' => false]]);
            $payload = ['lesson_id' => $lesson->id, 'retrieval_id' => $record->retrieval_id, 'parameter_key' => $lesson->parameter_key, 'failure_class' => $lesson->failure_class, 'match_level' => $row['match_level'], 'provenance' => $row['provenance'], 'score' => $row['score']];
            $groups[$bucket][] = $payload;
            $ids[] = $lesson->parameter_key;
        }

        return ['packet_id' => $packetId, 'status' => 'ok', ...$groups, 'blocked_mutations' => array_values(array_unique(array_filter(array_column($groups['harmful_lessons'], 'parameter_key')))), 'recommended_genes' => array_values(array_unique(array_filter(array_column($groups['positive_lessons'], 'parameter_key')))), 'retrieval_count' => count($groups['positive_lessons']) + count($groups['harmful_lessons']) + count($groups['uncertainty_lessons']), 'promotion_evidence' => false];
    }

    /** @return array<string, mixed> */
    private function missingCanonicalPacket(string $packetId, int $lessonId, string $reason): array
    {
        return [
            'packet_id' => $packetId,
            'status' => 'required_source_unavailable',
            'retrieval_mode' => 'pre_registered_exact_causal_source',
            'required_source_lesson_id' => $lessonId,
            'reason_code' => $reason,
            'positive_lessons' => [],
            'harmful_lessons' => [],
            'uncertainty_lessons' => [],
            'blocked_mutations' => [],
            'recommended_genes' => [],
            'retrieval_count' => 0,
            'promotion_evidence' => false,
        ];
    }
}
