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
    public const PROTOCOL = 'learning_kernel_directional_v2';

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
        $identity = $this->identity($settlement);
        $same = AgentLearningSettlement::query()->with('episode')->where('failure_class', $failure)->whereHas('episode', function ($query) use ($episode): void {
            $query->where('symbol', $episode->symbol)->where('timeframe', $episode->timeframe)->where('strategy_family', $episode->strategy_family)->where('execution_hash', $episode->execution_hash);
        })->get()->filter(fn (AgentLearningSettlement $row): bool => (string) data_get($row->outcome, 'parameter_key', data_get($row->outcome, 'gene', '')) === $gene && $this->identity($row) === $identity);
        $observations = $same->map(function (AgentLearningSettlement $row): array {
            $pair = $this->pair($row);
            $receipt = (array) data_get($pair?->metadata, 'instrument_research_window_receipt', []);
            $authorized = $pair?->isVerifiedControlPair() === true
                && app(InstrumentResearchWindowService::class)->authorized($receipt, (string) $pair->candidate_data_hash);

            return ['evidence_key' => 'settlement:'.$row->id,
                'window' => $authorized ? $receipt : [],
                'outcome' => $row->hard_failure || $row->evidence_state === 'negative' ? 'negative'
                    : ($row->evidence_state === 'positive' ? 'positive' : 'neutral')];
        })->values()->all();
        $independence = app(InstrumentResearchWindowService::class)->analyze($observations);
        $windows = collect($observations)->pluck('window.window_key')->filter()->unique()->values();
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
            'memory_guided', 'hypothesis_guided', 'causal_repair_guided' => AgentLearningCausalExperiment::query()
                ->where('guided_agent_id', $episode->lab_agent_id)
                ->where('status', 'confirmed')
                ->exists(),
            'blinded_counterfactual', 'frozen_control' => false,
            default => $canonicalSource,
        };
        $confirmed = $currentCanonical && $canonicalSource && $controlPresent
            && $identity !== null && $independence['valid'] && $independence['windows'] >= 3
            && $independence['positive_windows'] >= 2 && $negative === 0
            && $counterfactualEligible;
        $harmful = $currentCanonical && $canonicalSource && $controlPresent && $identity !== null
            && $independence['valid'] && $independence['negative_windows'] >= 3 && $positive === 0;
        $type = $harmful ? 'harmful_lesson' : ($confirmed ? 'skill_lesson' : 'uncertainty_lesson');
        $status = ($confirmed || $harmful) ? 'confirmed' : 'provisional';
        $hash = hash('sha512', json_encode([self::PROTOCOL, $episode->symbol, $episode->timeframe,
            $episode->strategy_family, $failure, $gene, $episode->execution_hash, $identity, $status], JSON_UNESCAPED_SLASHES));
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
                'protocol' => self::PROTOCOL,
                'mutation_identity' => $identity,
                'context' => $this->context($context),
                'window_observations' => $observations,
                'independence' => $independence,
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
                'counterfactual_required' => in_array($intent?->influence_type, ['memory_guided', 'hypothesis_guided', 'causal_repair_guided'], true),
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

    /** Legacy negative observations remain hypotheses, not whole-gene bans. */
    public function harmfulConstraint(AgentLearningLesson $lesson, array $requested): ?array
    {
        $evidence = (array) $lesson->evidence;
        $identity = data_get($evidence, 'mutation_identity');
        if (is_array($identity)) $identity = $this->canonicalize($identity);
        if ($lesson->status !== 'confirmed' || $lesson->lesson_type !== 'harmful_lesson'
            || data_get($evidence, 'protocol') !== self::PROTOCOL || ! is_array($identity)
            || ! array_key_exists('old_value', $identity) || ! array_key_exists('new_value', $identity)
            || ! array_key_exists('direction', $identity)
            || ($identity['context'] ?? null) !== $this->context($requested)) return null;
        $observations = (array) data_get($evidence, 'window_observations', []);
        foreach ($observations as $row) {
            $window = (array) ($row['window'] ?? []);
            if (! app(InstrumentResearchWindowService::class)->authorized($window, (string) ($window['dataset_sha256'] ?? ''))) return null;
            if (! preg_match('/^settlement:(\d+)$/', (string) ($row['evidence_key'] ?? ''), $matches)) return null;
            $source = AgentLearningSettlement::with('episode')->find((int) $matches[1]);
            $pair = $source ? $this->pair($source) : null;
            if (! $source || $pair?->isVerifiedControlPair() !== true || $this->identity($source) !== $identity
                || $this->canonicalize((array) data_get($pair->metadata, 'instrument_research_window_receipt', [])) !== $this->canonicalize($window)
                || ($row['outcome'] ?? null) !== ($source->hard_failure || $source->evidence_state === 'negative' ? 'negative'
                    : ($source->evidence_state === 'positive' ? 'positive' : 'neutral'))) return null;
        }
        $proof = app(InstrumentResearchWindowService::class)->analyze($observations);
        if (! $proof['valid'] || $proof['negative_windows'] < 3 || $proof['positive_windows'] > 0) return null;

        return ['signature' => hash('sha256', json_encode($identity)), 'parameter_key' => $lesson->parameter_key,
            'old_value' => $identity['old_value'], 'new_value' => $identity['new_value'],
            'direction' => $identity['direction'], 'context' => $identity['context'],
            'source_lesson_id' => (int) $lesson->id, 'global_scope' => false];
    }

    private function pair(AgentLearningSettlement $row): ?LabLearningLanePair
    {
        return $row->source_type === LabLearningLanePair::class && (int) $row->source_id > 0
            ? LabLearningLanePair::query()->with('candidateResponseMap')->find($row->source_id) : null;
    }

    private function identity(AgentLearningSettlement $row): ?array
    {
        $map = $this->pair($row)?->candidateResponseMap;
        if (! $map || ! is_array($map->old_value) || ! is_array($map->new_value)
            || ! array_key_exists('value', $map->old_value) || ! array_key_exists('value', $map->new_value)
            || $map->old_value['value'] === $map->new_value['value']) return null;

        return $this->canonicalize(['parameter_key' => $map->parameter_key, 'old_value' => $map->old_value['value'],
            'new_value' => $map->new_value['value'], 'direction' => $map->direction,
            'context' => $this->context((array) $row->episode?->decision_context),
            'code_hash' => $row->episode?->code_hash]);
    }

    private function context(array $context): array
    {
        return $this->canonicalize(array_intersect_key(app(ContextContractV2Service::class)->canonicalAxes($context), array_flip([
            'regime', 'volatility', 'session', 'venue_phase', 'transition_state',
            'spread_liquidity_state', 'volume_state', 'direction', 'state_cluster_id',
        ])));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        if (! array_is_list($value)) ksort($value);
        return $value;
    }
}
