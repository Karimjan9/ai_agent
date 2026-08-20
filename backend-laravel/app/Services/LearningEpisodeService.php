<?php

namespace App\Services;

use App\Models\AgentLearningEpisode;
use App\Models\LabAgent;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LearningEpisodeService
{
    /** @return AgentLearningEpisode|array<string,mixed> */
    public function open(?LabAgent $agent, array $context): AgentLearningEpisode|array
    {
        if (! Schema::hasTable('agent_learning_episodes')) return ['status' => 'unavailable'];
        $normalized = $this->context($context, $agent);
        $decisionKey = (string) ($context['decision_key'] ?? hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)));
        return AgentLearningEpisode::query()->firstOrCreate(['decision_key' => $decisionKey], [
            'episode_id' => (string) Str::uuid(), 'lab_agent_id' => $agent?->id, 'model_version_id' => $agent?->model_version_id,
            'symbol' => $normalized['symbol'], 'timeframe' => $normalized['timeframe'], 'strategy_family' => $normalized['strategy_family'],
            'stage' => (string) ($context['stage'] ?? 'decision'), 'status' => 'open', 'decision' => $context['decision'] ?? null,
            'confidence' => is_numeric($context['confidence'] ?? null) ? (float) $context['confidence'] : null, 'risk_veto' => $context['risk_veto'] ?? null,
            'context_hash' => hash('sha256', json_encode($normalized['learning_context'], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
            'data_hash' => $context['data_hash'] ?? $context['data_manifest_hash'] ?? null, 'code_hash' => $context['code_hash'] ?? null,
            'parameter_hash' => $context['parameter_hash'] ?? null, 'execution_hash' => $context['execution_hash'] ?? null,
            'decision_context' => $normalized['learning_context'], 'observations' => [], 'opened_at' => now(),
        ]);
    }

    /** @return AgentLearningEpisode|array<string,mixed> */
    public function recordObservation(AgentLearningEpisode|array $episode, array $observation): AgentLearningEpisode|array
    {
        if (! $episode instanceof AgentLearningEpisode) return $episode;
        $entries = (array) $episode->observations;
        $key = (string) ($observation['observation_key'] ?? hash('sha256', json_encode($observation)));
        if (! array_key_exists($key, $entries)) $entries[$key] = [...$observation, 'recorded_at' => now()->toIso8601String()];
        $episode->update(['observations' => $entries]);
        return $episode->fresh();
    }

    /** @return array<string,mixed> */
    private function context(array $context, ?LabAgent $agent): array
    {
        $learning = (array) ($context['context'] ?? $context['learning_context'] ?? []);
        foreach (['regime', 'volatility', 'transition_state', 'session', 'spread_liquidity_state', 'volume_state', 'direction', 'state_cluster_id', 'execution_contract', 'data_manifest'] as $key) {
            if (array_key_exists($key, $context)) $learning[$key] = $context[$key];
        }
        return ['symbol' => strtoupper((string) ($context['symbol'] ?? $agent?->symbol ?? 'UNKNOWN')), 'timeframe' => strtoupper((string) ($context['timeframe'] ?? $agent?->timeframe ?? 'UNKNOWN')), 'strategy_family' => $context['strategy_family'] ?? $agent?->strategy_family, 'learning_context' => $learning];
    }
}
