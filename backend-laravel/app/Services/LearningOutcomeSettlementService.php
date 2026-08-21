<?php

namespace App\Services;

use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningSettlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LearningOutcomeSettlementService
{
    public function __construct(private LearningRewardService $rewards, private LearningReflectionService $reflections) {}

    /** @return AgentLearningSettlement|array<string,mixed> */
    public function settle(AgentLearningEpisode|array $episode, array $outcome): AgentLearningSettlement|array
    {
        if (! $episode instanceof AgentLearningEpisode || ! Schema::hasTable('agent_learning_settlements')) return ['status' => 'unavailable'];
        return DB::transaction(function () use ($episode, $outcome): AgentLearningSettlement {
            $reward = $this->rewards->score($outcome);
            $reflection = $this->reflections->reflect($outcome, $reward);
            $sourceKey = (string) ($outcome['source_key'] ?? hash('sha256', $episode->episode_id.'|'.json_encode($outcome, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)));
            $settlement = AgentLearningSettlement::query()->firstOrCreate(['episode_id' => $episode->id], [
                'settlement_id' => (string) Str::uuid(), 'source_key' => $sourceKey, 'source_type' => $outcome['source_type'] ?? null,
                'source_id' => $outcome['source_id'] ?? null, 'outcome_status' => (string) ($outcome['outcome_status'] ?? 'settled'),
                'failure_class' => $reflection['failure'], 'evidence_state' => $reward['hard_failure'] ? 'negative' : (($outcome['evidence_state'] ?? null) ?: $reward['evidence_state']),
                'selection_reward' => $reward['selection_reward'], 'hard_failure' => $reward['hard_failure'], 'outcome' => $outcome,
                'reward_components' => [...$reward['components'], 'vetoes' => $reward['vetoes'], 'insufficient_reasons' => $reward['insufficient_reasons'], 'promotion_evidence' => false], 'reflection' => $reflection, 'settled_at' => now(),
            ]);
            $episode->update(['status' => $reward['hard_failure'] ? 'technical_quarantine' : 'settled', 'settled_at' => now()]);
            return $settlement->fresh();
        });
    }
}
