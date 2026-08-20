<?php

namespace App\Services;

use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningLesson;
use App\Models\AgentLearningPolicy;
use App\Models\AgentLearningRetrieval;
use App\Models\AgentLearningSettlement;
use Illuminate\Support\Facades\Schema;

class LearningPulseService
{
    /** @return array<string,mixed> */
    public function pulse(string $symbol, string $timeframe, ?string $family = null): array
    {
        if (! Schema::hasTable('agent_learning_episodes')) return ['available' => false];
        $episodes = AgentLearningEpisode::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->when($family, fn ($q) => $q->where('strategy_family', $family));
        $episodeIds = (clone $episodes)->pluck('id');
        $settled = AgentLearningSettlement::query()->whereIn('episode_id', $episodeIds);
        $retrievals = AgentLearningRetrieval::query()->whereIn('episode_id', $episodeIds);
        $lessons = AgentLearningLesson::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->when($family, fn ($q) => $q->where('strategy_family', $family));
        $opened = (clone $episodes)->count(); $settledCount = (clone $settled)->count(); $retrieved = (clone $retrievals)->count(); $consumed = (clone $retrievals)->where('retrieval_state', 'consumed')->count();
        $outcomeLinked = (clone $retrievals)->whereNotNull('outcome_linked_at')->count(); $confirmed = (clone $lessons)->where('status', 'confirmed')->count();
        return ['available' => true, 'episodes_opened' => $opened, 'episodes_settled' => $settledCount, 'settlement_lag' => max(0, $opened - $settledCount), 'lessons_created' => (clone $lessons)->count(), 'lessons_confirmed' => $confirmed, 'lessons_consumed' => $consumed, 'retrieval_hit_rate' => round($retrieved / max(1, $opened), 4), 'memory_utilization' => round($consumed / max(1, $retrieved), 4), 'retrieval_to_outcome_rate' => round($outcomeLinked / max(1, $consumed), 4), 'repeated_failure_rate' => round((clone $settled)->where('evidence_state', 'negative')->count() / max(1, $settledCount), 4), 'policy_versions' => AgentLearningPolicy::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->when($family, fn ($q) => $q->where('strategy_family', $family))->count(), 'learning_velocity' => round(($confirmed * 100) / max(1, $settledCount), 4)];
    }
}
