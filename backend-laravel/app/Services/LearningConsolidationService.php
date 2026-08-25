<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningLesson;
use App\Models\AgentLearningMutationIntent;
use App\Models\AgentLearningSettlement;
use App\Models\LabLearningLanePair;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Consolidates repeated, contract-compatible settlements into one lesson. */
class LearningConsolidationService
{
    /** @return array<string,mixed> */
    public function consolidate(AgentLearningSettlement|array $settlement): array
    {
        if (! $settlement instanceof AgentLearningSettlement || ! Schema::hasTable('agent_learning_lessons')) {
            return ['status' => 'unavailable', 'lessons' => []];
        }
        $episode = $settlement->episode;
        if (! $episode) {
            return ['status' => 'episode_missing', 'lessons' => []];
        }
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
        $intent = $episode->lab_agent_id
            ? AgentLearningMutationIntent::query()->where('lab_agent_id', $episode->lab_agent_id)->first()
            : null;
        $canonicalPairs = $same->map(function (AgentLearningSettlement $row): ?LabLearningLanePair {
            if ((string) $row->source_type !== LabLearningLanePair::class || (int) $row->source_id <= 0) {
                return null;
            }

            return LabLearningLanePair::query()->with('controlResponseMap')->find((int) $row->source_id);
        });
        $canonicalSource = $canonicalPairs->isNotEmpty()
            && $canonicalPairs->every(fn (?LabLearningLanePair $pair): bool => $pair?->isVerifiedControlPair() === true);
        $pair = (string) $settlement->source_type === LabLearningLanePair::class
            ? LabLearningLanePair::query()->with(['candidateResponseMap', 'controlResponseMap'])->find((int) $settlement->source_id)
            : null;
        $currentCanonical = $pair?->isVerifiedControlPair() === true;
        $counterfactualEligible = match ((string) ($intent?->influence_type ?? 'independent_exploration')) {
            'memory_guided' => AgentLearningCausalExperiment::query()
                ->where('guided_agent_id', $episode->lab_agent_id)
                ->where('status', 'confirmed')
                ->exists(),
            'blinded_counterfactual', 'frozen_control' => false,
            default => $canonicalSource,
        };
        $confirmed = $currentCanonical && $canonicalSource && $controlPresent
            && $windows->count() >= 3 && $positive >= 2 && $negative === 0
            && $counterfactualEligible;
        $harmful = $currentCanonical && $canonicalSource && $negative >= 3;
        $type = $harmful ? 'harmful_lesson' : ($confirmed ? 'skill_lesson' : 'uncertainty_lesson');
        $status = ($confirmed || $harmful) ? 'confirmed' : 'provisional';
        $hash = hash('sha512', implode('|', ['learning_kernel_v1', $episode->symbol, $episode->timeframe, $episode->strategy_family, $failure, $gene, $episode->execution_hash, $status]));
        $map = $pair?->candidateResponseMap;
        $pairIds = $canonicalPairs->filter()->pluck('id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $settlementIds = $same->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        $lesson = AgentLearningLesson::query()->firstOrNew(['lesson_hash' => $hash]);
        if (! $lesson->exists) {
            $lesson->lesson_id = (string) Str::uuid();
        }
        $lesson->fill([
            'lab_agent_id' => $episode->lab_agent_id, 'model_version_id' => $episode->model_version_id,
            'symbol' => $episode->symbol, 'timeframe' => $episode->timeframe, 'strategy_family' => $episode->strategy_family,
            'lesson_type' => $type, 'status' => $status, 'failure_class' => $failure, 'parameter_key' => $gene ?: null,
            'state_cluster_id' => $context['state_cluster_id'] ?? null, 'regime' => $context['regime'] ?? null, 'volatility' => $context['volatility'] ?? null,
            'transition_state' => $context['transition_state'] ?? null, 'spread_liquidity_state' => $context['spread_liquidity_state'] ?? null,
            'outcome' => $harmful ? 'harmful' : ($confirmed ? 'beneficial' : 'uncertain'), 'independent_window_count' => $windows->count(), 'confirmation_count' => $positive,
            'lower_confidence_bound' => $this->lowerBound($positive, max(1, $positive + $negative)), 'source_run_ids' => $same->pluck('id')->map(fn ($id) => 'settlement:'.$id)->all(),
            'evidence' => [
                'protocol' => 'learning_kernel_v1',
                'canonical_source' => $currentCanonical,
                'pair_id' => $currentCanonical ? (int) $pair->id : null,
                'pair_ids' => $pairIds,
                'settlement_id' => (int) $settlement->id,
                'settlement_ids' => $settlementIds,
                'execution_hash' => $episode->execution_hash,
                'control_required' => true,
                'control_present' => $controlPresent,
                'window_keys' => $windows->all(),
                'direction' => $map?->direction,
                'old_value' => $map?->old_value,
                'new_value' => $map?->new_value,
                'failure_signature' => $pair?->failure_signature,
                'counterfactual_required' => $intent?->influence_type === 'memory_guided',
                'counterfactual_eligible' => $counterfactualEligible,
                'promotion_evidence' => false,
            ], 'observed_at' => now(),
        ])->save();

        return ['status' => $status, 'lessons' => [$lesson], 'promotion_evidence' => false];
    }

    private function lowerBound(int $successes, int $total): float
    {
        $p = $successes / max(1, $total);

        return round(max(0, $p - 1.96 * sqrt(($p * (1 - $p)) / max(1, $total))), 4);
    }
}
