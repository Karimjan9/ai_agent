<?php

namespace App\Services;

use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningLesson;
use App\Models\AgentLearningPolicy;
use App\Models\AgentLearningRetrieval;
use App\Models\AgentLearningSettlement;
use App\Models\LabLearningLanePair;
use Illuminate\Support\Facades\Schema;

class LearningPulseService
{
    /** @return array<string,mixed> */
    public function pulse(string $symbol, string $timeframe, ?string $family = null): array
    {
        if (! Schema::hasTable('agent_learning_episodes')) {
            return ['available' => false];
        }
        $episodes = AgentLearningEpisode::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->when($family, fn ($q) => $q->where('strategy_family', $family));
        $episodeIds = (clone $episodes)->pluck('id');
        $settled = AgentLearningSettlement::query()->whereIn('episode_id', $episodeIds);
        $retrievals = AgentLearningRetrieval::query()->whereIn('episode_id', $episodeIds);
        $lessons = AgentLearningLesson::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->when($family, fn ($q) => $q->where('strategy_family', $family));
        $opened = (clone $episodes)->count();
        $allSettledCount = (clone $settled)->count();
        $retrieved = (clone $retrievals)->count();
        $consumed = (clone $retrievals)->where('retrieval_state', 'consumed')->count();
        $causallyApplied = (clone $retrievals)->where('metadata->causal_application', true)->count();
        $outcomeLinked = (clone $retrievals)->whereNotNull('outcome_linked_at')->count();
        $lessonRows = (clone $lessons)->get();
        $settledPairIds = (clone $settled)->where('source_type', LabLearningLanePair::class)->pluck('source_id');
        $verifiedPairIds = $settledPairIds->isEmpty()
            ? collect()
            : LabLearningLanePair::query()->with('controlResponseMap')->whereIn('id', $settledPairIds)
                // Pair status is an operational projection and older valid
                // settlements can still be screen_paired. Canonical truth is
                // the verified control contract plus its durable settlement.
                ->get()
                ->filter(fn (LabLearningLanePair $pair): bool => $pair->isVerifiedControlPair())
                ->pluck('id');
        $canonicalSettlements = (clone $settled)
            ->where('source_type', LabLearningLanePair::class)
            ->whereIn('source_id', $verifiedPairIds);
        $settledCount = (clone $canonicalSettlements)->count();
        $canonicalLessons = $lessonRows->filter(fn (AgentLearningLesson $lesson): bool => $verifiedPairIds->contains(
            (int) data_get($lesson->evidence, 'pair_id', 0),
        ));
        $confirmed = $canonicalLessons->where('status', 'confirmed')->count();
        $legacyLessons = max(0, $lessonRows->count() - $canonicalLessons->count());
        $legacyConfirmed = $lessonRows->where('status', 'confirmed')->count() - $confirmed;
        // Legacy lessons are useful retrieval priors but are not proof that
        // the current kernel completed a retrieve → consume → outcome loop.
        // Reporting 1,036 "velocity" with zero episodes/settlements was a
        // false green that hid the exact starvation this monitor must expose.
        $positiveSettlements = (clone $canonicalSettlements)->where('evidence_state', 'positive')->count();
        $settlementSuccessRate = $settledCount > 0
            ? round(($positiveSettlements * 100) / $settledCount, 4)
            : 0.0;
        $causalLearningVelocity = round(($confirmed * 100) / max(1, $opened), 4);

        return ['available' => true, 'episodes_opened' => $opened, 'episodes_settled' => $allSettledCount, 'canonical_settlements' => $settledCount, 'settlement_lag' => max(0, $opened - $allSettledCount), 'lessons_created' => $lessonRows->count(), 'canonical_lessons_created' => $canonicalLessons->count(), 'lessons_confirmed' => $confirmed, 'legacy_lessons' => $legacyLessons, 'legacy_confirmed_lessons' => max(0, $legacyConfirmed), 'lessons_consumed' => $consumed, 'causally_applied_lessons' => $causallyApplied, 'retrieval_hit_rate' => round($retrieved / max(1, $opened), 4), 'memory_utilization' => round($consumed / max(1, $retrieved), 4), 'causal_memory_utilization' => round($causallyApplied / max(1, $retrieved), 4), 'retrieval_to_outcome_rate' => round($outcomeLinked / max(1, $consumed), 4), 'repeated_failure_rate' => round((clone $canonicalSettlements)->where('evidence_state', 'negative')->count() / max(1, $settledCount), 4), 'policy_versions' => AgentLearningPolicy::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->when($family, fn ($q) => $q->where('strategy_family', $family))->count(), 'positive_settlements' => $positiveSettlements, 'settlement_success_rate' => $settlementSuccessRate, 'learning_velocity' => $causalLearningVelocity, 'learning_velocity_status' => $confirmed > 0 ? 'causally_confirmed_skills_only' : ($settledCount > 0 ? 'settlement_throughput_not_learning_progress' : 'no_canonical_settlements'), 'parent_foundry' => app(ParentFoundryService::class)->summary($symbol, $timeframe)];
    }
}
