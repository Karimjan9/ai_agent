<?php

namespace App\Services;

use App\Models\AgentLearningLesson;
use App\Models\AgentLearningSettlement;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Consolidates repeated, contract-compatible settlements into one lesson. */
class LearningConsolidationService
{
    /** @return array<string,mixed> */
    public function consolidate(AgentLearningSettlement|array $settlement): array
    {
        if (! $settlement instanceof AgentLearningSettlement || ! Schema::hasTable('agent_learning_lessons')) return ['status' => 'unavailable', 'lessons' => []];
        $episode = $settlement->episode;
        if (! $episode) return ['status' => 'episode_missing', 'lessons' => []];
        $context = (array) $episode->decision_context;
        $gene = (string) data_get($settlement->outcome, 'parameter_key', data_get($settlement->outcome, 'gene', ''));
        $failure = (string) ($settlement->failure_class ?: 'uncertain');
        $same = AgentLearningSettlement::query()->with('episode')->where('failure_class', $failure)->whereHas('episode', function ($query) use ($episode): void {
            $query->where('symbol', $episode->symbol)->where('timeframe', $episode->timeframe)->where('strategy_family', $episode->strategy_family)->where('execution_hash', $episode->execution_hash);
        })->get()->filter(fn (AgentLearningSettlement $row): bool => (string) data_get($row->outcome, 'parameter_key', data_get($row->outcome, 'gene', '')) === $gene);
        $windows = $same->map(fn (AgentLearningSettlement $row) => data_get($row->outcome, 'independent_window_key', data_get($row->outcome, 'window_key')))->filter()->unique()->values();
        $positive = $same->filter(fn (AgentLearningSettlement $row): bool => ! $row->hard_failure && $row->evidence_state === 'positive')->count();
        $negative = $same->filter(fn (AgentLearningSettlement $row): bool => $row->hard_failure || $row->evidence_state === 'negative')->count();
        $controlPresent = $same->isNotEmpty() && $same->every(fn (AgentLearningSettlement $row): bool => data_get($row->outcome, 'control_present') === true);
        $confirmed = $controlPresent && $windows->count() >= 3 && $positive >= 2 && $negative === 0;
        $harmful = $negative >= 3;
        $type = $harmful ? 'harmful_lesson' : ($confirmed ? 'skill_lesson' : 'uncertainty_lesson');
        $status = ($confirmed || $harmful) ? 'confirmed' : 'provisional';
        $hash = hash('sha512', implode('|', ['learning_kernel_v1', $episode->symbol, $episode->timeframe, $episode->strategy_family, $failure, $gene, $episode->execution_hash, $status]));
        $lesson = AgentLearningLesson::query()->firstOrCreate(['lesson_hash' => $hash], [
            'lesson_id' => (string) Str::uuid(), 'lab_agent_id' => $episode->lab_agent_id, 'model_version_id' => $episode->model_version_id,
            'symbol' => $episode->symbol, 'timeframe' => $episode->timeframe, 'strategy_family' => $episode->strategy_family,
            'lesson_type' => $type, 'status' => $status, 'failure_class' => $failure, 'parameter_key' => $gene ?: null,
            'state_cluster_id' => $context['state_cluster_id'] ?? null, 'regime' => $context['regime'] ?? null, 'volatility' => $context['volatility'] ?? null,
            'transition_state' => $context['transition_state'] ?? null, 'spread_liquidity_state' => $context['spread_liquidity_state'] ?? null,
            'outcome' => $harmful ? 'harmful' : ($confirmed ? 'beneficial' : 'uncertain'), 'independent_window_count' => $windows->count(), 'confirmation_count' => $positive,
            'lower_confidence_bound' => $this->lowerBound($positive, max(1, $positive + $negative)), 'source_run_ids' => $same->pluck('id')->map(fn ($id) => 'settlement:'.$id)->all(),
            'evidence' => ['protocol' => 'learning_kernel_v1', 'execution_hash' => $episode->execution_hash, 'control_required' => true, 'control_present' => $controlPresent, 'window_keys' => $windows->all(), 'promotion_evidence' => false], 'observed_at' => now(),
        ]);
        return ['status' => $status, 'lessons' => [$lesson], 'promotion_evidence' => false];
    }

    private function lowerBound(int $successes, int $total): float
    {
        $p = $successes / max(1, $total);
        return round(max(0, $p - 1.96 * sqrt(($p * (1 - $p)) / max(1, $total))), 4);
    }
}
