<?php

namespace App\Services;

use App\Models\AgentLearningPolicy;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Immutable policy versions: draft -> shadow -> canary -> active -> retired. */
class LearningPolicyRegistryService
{
    private const TRANSITIONS = ['draft' => ['shadow'], 'shadow' => ['canary', 'retired'], 'canary' => ['active', 'retired'], 'active' => ['retired'], 'retired' => []];

    /** @return AgentLearningPolicy|array<string,mixed> */
    public function register(string $key, array $definition, array $scope = []): AgentLearningPolicy|array
    {
        if (! Schema::hasTable('agent_learning_policies')) return ['status' => 'unavailable'];
        $latest = AgentLearningPolicy::query()->where('policy_key', $key)->latest('version')->first();
        return AgentLearningPolicy::query()->create(['policy_id' => (string) Str::uuid(), 'policy_key' => $key, 'version' => ((int) ($latest?->version ?? 0)) + 1, 'symbol' => $scope['symbol'] ?? null, 'timeframe' => $scope['timeframe'] ?? null, 'strategy_family' => $scope['strategy_family'] ?? null, 'state' => 'draft', 'parent_policy_id' => $latest?->policy_id, 'definition' => $definition, 'evidence' => ['promotion_evidence' => false]]);
    }

    /** @return AgentLearningPolicy|array<string,mixed> */
    public function transition(AgentLearningPolicy $policy, string $state, array $evidence = []): AgentLearningPolicy|array
    {
        if (! in_array($state, self::TRANSITIONS[$policy->state] ?? [], true)) return ['status' => 'invalid_transition', 'from' => $policy->state, 'to' => $state];
        if ($state === 'active' && (($evidence['immutable_gate_passed'] ?? false) !== true || ($evidence['operator_approved'] ?? false) !== true)) return ['status' => 'approval_required'];
        $policy->update(['state' => $state, 'evidence' => [...((array) $policy->evidence), ...$evidence, 'promotion_evidence' => false], 'activated_at' => $state === 'active' ? now() : $policy->activated_at, 'retired_at' => $state === 'retired' ? now() : $policy->retired_at]);
        return $policy->fresh();
    }
}
