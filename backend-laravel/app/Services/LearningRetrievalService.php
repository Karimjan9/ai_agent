<?php

namespace App\Services;

use App\Models\AgentLearningLesson;
use App\Models\AgentLearningRetrieval;
use App\Models\LabAgent;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LearningRetrievalService
{
    /** @return array<string,mixed> */
    public function retrieve(string $symbol, string $timeframe, ?string $family, array $context = [], ?LabAgent $agent = null, ?int $episodeId = null): array
    {
        $packetId = (string) Str::uuid();
        if (! Schema::hasTable('agent_learning_lessons') || ! Schema::hasTable('agent_learning_retrievals')) return ['packet_id' => $packetId, 'status' => 'unavailable', 'positive_lessons' => [], 'harmful_lessons' => [], 'uncertainty_lessons' => [], 'blocked_mutations' => []];
        $rows = AgentLearningLesson::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->where(function ($q) use ($family): void { if ($family) $q->where('strategy_family', $family); })->whereIn('status', ['provisional', 'confirmed'])->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->get();
        $ranked = $rows->map(function (AgentLearningLesson $lesson) use ($context): array {
            $fields = ['regime', 'volatility', 'transition_state', 'spread_liquidity_state', 'state_cluster_id'];
            $exact = 0; $conflict = false; $specified = 0;
            foreach ($fields as $field) { $stored = $lesson->{$field}; $requested = $context[$field] ?? null; if ($stored !== null && $stored !== '') { $specified++; if ($requested !== null && (string) $stored === (string) $requested) $exact++; elseif ($requested !== null) $conflict = true; } }
            return ['lesson' => $lesson, 'match_level' => $conflict ? 'incompatible' : ($specified > 0 && $exact === $specified ? 'exact_context' : ($specified > 0 ? 'family_prior' : 'broad_prior')), 'score' => ($lesson->status === 'confirmed' ? 2 : 1) + $exact + (float) ($lesson->lower_confidence_bound ?? 0)];
        })->reject(fn (array $row) => $row['match_level'] === 'incompatible')->sortByDesc('score')->values();
        $groups = ['positive_lessons' => [], 'harmful_lessons' => [], 'uncertainty_lessons' => []]; $ids = [];
        foreach ($ranked as $row) {
            /** @var AgentLearningLesson $lesson */ $lesson = $row['lesson'];
            $bucket = $lesson->lesson_type === 'harmful_lesson' ? 'harmful_lessons' : ($lesson->outcome === 'beneficial' ? 'positive_lessons' : 'uncertainty_lessons');
            $record = AgentLearningRetrieval::query()->create(['retrieval_id' => (string) Str::uuid(), 'packet_id' => $packetId, 'episode_id' => $episodeId, 'agent_learning_lesson_id' => $lesson->id, 'lab_agent_id' => $agent?->id, 'symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe), 'strategy_family' => $family, 'retrieval_state' => 'retrieved', 'match_level' => $row['match_level'], 'rank_score' => $row['score'], 'context' => $context, 'metadata' => ['parameter_key' => $lesson->parameter_key, 'promotion_evidence' => false]]);
            $payload = ['lesson_id' => $lesson->id, 'retrieval_id' => $record->retrieval_id, 'parameter_key' => $lesson->parameter_key, 'failure_class' => $lesson->failure_class, 'match_level' => $row['match_level'], 'score' => $row['score']];
            $groups[$bucket][] = $payload; $ids[] = $lesson->parameter_key;
        }
        return ['packet_id' => $packetId, 'status' => 'ok', ...$groups, 'blocked_mutations' => array_values(array_unique(array_filter(array_column($groups['harmful_lessons'], 'parameter_key')))), 'recommended_genes' => array_values(array_unique(array_filter(array_column($groups['positive_lessons'], 'parameter_key')))), 'retrieval_count' => $ranked->count(), 'promotion_evidence' => false];
    }
}
