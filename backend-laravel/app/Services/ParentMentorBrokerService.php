<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use Illuminate\Support\Collection;

/**
 * Converts a validated parent into a bounded, auditable suggestion.
 *
 * The broker never returns a parameter vector. It returns at most one gene,
 * its context, and the evidence needed to test it. The child remains free to
 * reject the suggestion and always keeps an autonomous lane beside it.
 */
class ParentMentorBrokerService
{
    public const PROTOCOL = 'parent_mentor_broker_v1';

    public function __construct(private ParentContextTrustService $trust)
    {
    }

    /** @return array<string, mixed> */
    public function propose(
        iterable $parents,
        string $symbol,
        string $timeframe,
        string $family,
        string $target,
        ?array $niche,
        string $lane,
        array $schemaKeys = [],
    ): array {
        $context = $this->trust->context([
            ...((array) $niche),
            'cost_stress' => data_get($niche, 'cost_stress', 'normal'),
        ]);
        $parents = collect($parents)
            ->filter(fn ($parent): bool => $parent instanceof ModelVersion && (int) $parent->id > 0)
            ->unique('id')
            ->values();

        $firewall = [
            'protocol' => 'parent_inheritance_firewall_v1',
            'direct_parameter_vector_copy' => false,
            'max_parent_derived_genes' => 1,
            'child_specific_change_required' => true,
            'autonomous_branch_required' => true,
            'parent_credit_requires_counterfactual' => (bool) config('services.lab_selection.parent_counterfactual_required', true),
            'cross_cell_parent_forbidden' => true,
            'promotion_evidence' => false,
        ];
        $base = [
            'protocol' => self::PROTOCOL,
            'lane' => in_array($lane, ['mentor_assisted', 'cross_skill_composition'], true) ? $lane : 'autonomous',
            'status' => 'autonomous_no_parent_suggestion',
            'parent_available' => $parents->isNotEmpty(),
            'candidate_parent_ids' => $parents->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'context' => $context,
            'inheritance_firewall' => $firewall,
            'promotion_evidence' => false,
        ];
        if ($parents->isEmpty() || ! in_array($lane, ['mentor_assisted', 'cross_skill_composition'], true)) {
            return $base;
        }

        $proposals = [];
        foreach ($parents as $parent) {
            $gene = $this->geneFor($parent, $target, $family, $schemaKeys);
            if ($gene === null) continue;
            $trust = $this->trust->score($parent, $symbol, $timeframe, $family, $gene, $context);
            $performance = ModelMarketPerformance::query()
                ->where('model_version_id', $parent->id)
                ->where('symbol', strtoupper($symbol))
                ->where('timeframe', strtoupper($timeframe))
                ->where('strategy_family', $family)
                ->latest('id')
                ->first();
            $evidenceHash = (string) data_get($parent->metadata, 'elite_agent_passport.passport_hash', '');
            if ($evidenceHash === '') {
                $evidenceHash = hash('sha256', json_encode([
                    'parent_model_version_id' => $parent->id,
                    'parameter_fingerprint' => data_get($parent->metadata, 'parameter_fingerprint'),
                    'performance_id' => $performance?->id,
                    'context_key' => $context['context_key'],
                ], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
            }
            $parentAgent = LabAgent::query()->where('model_version_id', $parent->id)->latest('id')->first();
            $direction = $this->directionFor($parent, $parentAgent, $gene);
            $quality = (float) ($performance?->forward_score ?? $parent->best_score ?? 0);
            $proposals[] = [
                'parent_model_version_id' => (int) $parent->id,
                'parent_agent_id' => $parentAgent?->id,
                'skill_key' => $gene,
                'changed_gene' => $gene,
                'direction' => $direction,
                'expected_effect' => data_get($parent->metadata, 'skill_mentor.target_delta', data_get($parent->metadata, 'hypothesis_contract.target_gate')),
                'parent_quality_score' => round($quality, 6),
                'context_trust' => $trust,
                'evidence_hash' => $evidenceHash,
                'source_context' => $context,
                'provenance' => 'parent_skill_suggestion_only',
            ];
        }
        if ($proposals === []) {
            return [
                ...$base,
                'status' => 'no_compatible_parent_skill',
                'parent_suggestion' => null,
            ];
        }

        usort($proposals, static function (array $left, array $right): int {
            $leftScore = ((float) data_get($left, 'context_trust.trust_score', .5) * .75)
                + (min(1.0, max(0.0, (float) data_get($left, 'parent_quality_score', 0) / 100)) * .25);
            $rightScore = ((float) data_get($right, 'context_trust.trust_score', .5) * .75)
                + (min(1.0, max(0.0, (float) data_get($right, 'parent_quality_score', 0) / 100)) * .25);
            return $rightScore <=> $leftScore;
        });
        $selected = $proposals[0];

        return [
            ...$base,
            'status' => 'proposal_available',
            'parent_suggestion' => $selected,
            'candidate_proposals' => array_slice($proposals, 0, 8),
            'expected_parent_incremental_value' => null,
            'requires_autonomous_counterfactual' => true,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function autonomousContract(string $symbol, string $timeframe, string $family, string $target, ?array $niche): array
    {
        $context = $this->trust->context((array) $niche);
        return [
            'protocol' => self::PROTOCOL,
            'lane' => 'autonomous',
            'status' => 'independent_hypothesis',
            'parent_used' => false,
            'context' => $context,
            'target' => $target,
            'independence_rule' => 'Child owns its hypothesis and failure lesson; parent suggestions are not applied.',
            'promotion_evidence' => false,
        ];
    }

    /** @return list<string> */
    private function targetGenes(string $target): array
    {
        return match ($target) {
            'stress_cost', 'stress_drawdown', 'risk_and_exit_topology' => [
                'atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier',
                'time_stop_candles', 'risk_per_trade', 'spread_to_atr_max',
            ],
            'regime_coverage', 'trend_up_stability', 'directional_specialist' => [
                'trend_strength_min', 'pullback_atr_fraction', 'roc_threshold',
                'differential_target_regime', 'trend_down_strength_min',
            ],
            'volume', 'volume_entry_quality', 'volume_transition_routing' => [
                'minimum_signal_confidence', 'lookback', 'confirmation_candles',
            ],
            'temporal_stability', 'monthly_survival', 'temporal_session_filter' => [
                'lookback', 'confirmation_candles', 'time_stop_candles',
            ],
            default => [
                'minimum_signal_confidence', 'trend_strength_min', 'lookback',
                'pullback_atr_fraction', 'confirmation_candles',
            ],
        };
    }

    private function geneFor(ModelVersion $parent, string $target, string $family, array $schemaKeys): ?string
    {
        $metadata = (array) $parent->metadata;
        $parentAgent = LabAgent::query()->where('model_version_id', $parent->id)->latest('id')->first();
        $sources = array_keys((array) data_get($metadata, 'capability_gene_provenance', []));
        $declared = [
            (string) data_get($metadata, 'skill_mentor.parameter_key', ''),
            (string) data_get($metadata, 'hypothesis_contract.changed_gene', ''),
            ...array_keys((array) ($parentAgent?->parameter_diff ?? [])),
            ...$sources,
            ...$this->targetGenes($target),
        ];
        $allowed = $schemaKeys !== [] ? array_fill_keys(array_map('strval', $schemaKeys), true) : null;
        foreach ($declared as $key) {
            $key = trim((string) $key);
            if ($key === '' || ($allowed !== null && ! isset($allowed[$key]))) continue;
            if ($key === '__architecture') continue;
            return $key;
        }
        return null;
    }

    private function directionFor(ModelVersion $parent, ?LabAgent $agent, string $gene): ?string
    {
        $direction = data_get($parent->metadata, 'skill_mentor.direction');
        if (is_string($direction) && $direction !== '') return $direction;
        $diff = (array) ($agent?->parameter_diff ?? []);
        $change = (array) ($diff[$gene] ?? []);
        if (is_numeric($change['old'] ?? null) && is_numeric($change['new'] ?? null)) {
            return (float) $change['new'] > (float) $change['old'] ? 'increase' : 'decrease';
        }
        if (array_key_exists('new', $change) && is_bool($change['new'])) return $change['new'] ? 'enable' : 'disable';
        return null;
    }
}
