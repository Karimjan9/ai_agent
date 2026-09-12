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
        $retrievalRows = (clone $retrievals)->get();
        $retrieved = $retrievalRows->count();
        $consumed = $retrievalRows->where('retrieval_state', 'consumed')->count();
        $causallyApplied = $retrievalRows->filter(fn (AgentLearningRetrieval $row): bool => data_get($row->metadata, 'causal_application') === true)->count();
        $outcomeLinked = $retrievalRows->whereNotNull('outcome_linked_at')->count();
        // A packet is one mutation decision opportunity. Counting every
        // lesson row inflated hit-rate above 100% and made a five-row prompt
        // look five times more useful than a one-row packet.
        $packets = $retrievalRows->pluck('packet_id')->filter()->unique()->values();
        $retrievalPackets = $packets->count();
        $episodesWithRetrieval = $retrievalRows->pluck('episode_id')->filter()->unique()->count();
        $consumedPackets = $retrievalRows->where('retrieval_state', 'consumed')->pluck('packet_id')->filter()->unique()->count();
        $causalPackets = $retrievalRows->filter(fn (AgentLearningRetrieval $row): bool => data_get($row->metadata, 'causal_application') === true)
            ->pluck('packet_id')->filter()->unique()->count();
        $outcomeLinkedPackets = $retrievalRows->whereNotNull('outcome_linked_at')->pluck('packet_id')->filter()->unique()->count();
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
        $confirmed = $canonicalLessons->filter(function (AgentLearningLesson $lesson): bool {
            if ($lesson->status !== 'confirmed' || $lesson->lesson_type !== 'skill_lesson' || $lesson->outcome !== 'beneficial') {
                return false;
            }
            $evidence = (array) $lesson->evidence;
            $counterfactualRequired = data_get($evidence, 'counterfactual_required') === true;

            return data_get($evidence, 'canonical_source') === true
                && data_get($evidence, 'control_present') === true
                && (int) $lesson->independent_window_count >= 3
                && (int) $lesson->confirmation_count >= 2
                && (! $counterfactualRequired || data_get($evidence, 'counterfactual_eligible') === true);
        })->count();
        $legacyLessons = max(0, $lessonRows->count() - $canonicalLessons->count());
        $legacyConfirmed = $lessonRows->where('status', 'confirmed')->count() - $confirmed;
        // Legacy lessons are useful retrieval priors but are not proof that
        // the current kernel completed a retrieve → consume → outcome loop.
        // Reporting 1,036 "velocity" with zero episodes/settlements was a
        // false green that hid the exact starvation this monitor must expose.
        $positiveSettlements = (clone $canonicalSettlements)->where('evidence_state', 'positive')->count();
        $canonicalSettlementRows = (clone $canonicalSettlements)->get();
        $negativeRows = $canonicalSettlementRows->filter(fn (AgentLearningSettlement $settlement): bool => $settlement->evidence_state === 'negative' || $settlement->hard_failure
        );
        $negativeSettlements = $negativeRows->count();
        $repeatedFailures = $negativeRows->groupBy(fn (AgentLearningSettlement $settlement): string => trim((string) ($settlement->failure_class ?: data_get($settlement->reflection, 'failure', 'unclassified')))
        )->sum(fn ($group): int => max(0, $group->count() - 1));
        $settlementSuccessRate = $settledCount > 0
            ? round(($positiveSettlements * 100) / $settledCount, 4)
            : 0.0;
        $causalLearningVelocity = round(($confirmed * 100) / max(1, $opened), 4);

        return ['available' => true, 'episodes_opened' => $opened, 'episodes_settled' => $allSettledCount, 'canonical_settlements' => $settledCount, 'settlement_lag' => max(0, $opened - $allSettledCount), 'lessons_created' => $lessonRows->count(), 'canonical_lessons_created' => $canonicalLessons->count(), 'lessons_confirmed' => $confirmed, 'legacy_lessons' => $legacyLessons, 'legacy_confirmed_lessons' => max(0, $legacyConfirmed), 'retrieval_rows' => $retrieved, 'retrieval_packets' => $retrievalPackets, 'retrieval_episode_hits' => $episodesWithRetrieval, 'lessons_consumed' => $consumed, 'consumed_packets' => $consumedPackets, 'causally_applied_lessons' => $causallyApplied, 'causally_applied_packets' => $causalPackets, 'retrieval_hit_rate' => round(min(1, $episodesWithRetrieval / max(1, $opened)), 4), 'memory_utilization' => round($consumedPackets / max(1, $retrievalPackets), 4), 'causal_memory_utilization' => round($causalPackets / max(1, $retrievalPackets), 4), 'retrieval_to_outcome_rate' => round($outcomeLinkedPackets / max(1, $consumedPackets), 4), 'negative_settlement_rate' => round($negativeSettlements / max(1, $settledCount), 4), 'repeated_failure_events' => $repeatedFailures, 'repeated_failure_rate' => round($repeatedFailures / max(1, $negativeSettlements), 4), 'policy_versions' => AgentLearningPolicy::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->when($family, fn ($q) => $q->where('strategy_family', $family))->count(), 'positive_settlements' => $positiveSettlements, 'settlement_success_rate' => $settlementSuccessRate, 'learning_velocity' => $causalLearningVelocity, 'learning_velocity_status' => $confirmed > 0 ? 'causally_confirmed_skills_only' : ($settledCount > 0 ? 'settlement_throughput_not_learning_progress' : 'no_canonical_settlements'), 'metric_contract' => ['retrieval_unit' => 'decision_packet', 'repeated_failure_unit' => 'repeat_after_first_failure_in_same_canonical_class', 'confirmed_skill_requires' => ['verified_control_pair', 'canonical_source', 'control_present', 'independent_windows>=3', 'positive_confirmations>=2', 'counterfactual_when_required']], 'memory_opportunities' => app(MemoryOpportunityService::class)->metrics($symbol, $timeframe, $family), 'parent_foundry' => app(ParentFoundryService::class)->summary($symbol, $timeframe)];
    }
}
