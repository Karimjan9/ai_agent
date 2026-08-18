<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabMutationResponseMap;
use Illuminate\Support\Facades\Schema;

/**
 * Contextual, bounded mutation bandit. It ranks previously observed genes in
 * the same context, but never widens schema bounds or bypasses a gate.
 */
class ContextualMutationBanditService
{
    public const PROTOCOL = 'contextual_mutation_bandit_v1';

    /** @return array<string, mixed> */
    public function context(LabAgent $agent, array $result, string $target, ?string $gene = null, ?string $direction = null): array
    {
        $metadata = (array) ($agent->modelVersion?->metadata ?? []);
        $session = data_get($result, 'session_utc_hour', data_get($result, 'context.session_utc_hour', data_get($metadata, 'mutation_scope.session_utc_hour', 'unknown')));
        $context = [
            'target' => $target !== '' ? $target : 'profit_factor',
            'gene' => $gene ?: data_get($metadata, 'declared_gene', data_get($metadata, 'repair_anchor_sibling.declared_gene', 'unknown')),
            'direction' => $direction ?: data_get($metadata, 'repair_anchor_sibling.direction', data_get($metadata, 'causal_experiment_lane.direction', 'unknown')),
            'regime' => data_get($result, 'dominant_regime', data_get($result, 'regime_context.dominant_regime', data_get($metadata, 'mutation_scope.market_regime', 'unknown'))),
            'session' => (string) $session,
            'volume_state' => data_get($result, 'volume_context.state', data_get($result, 'volume_state', data_get($metadata, 'volume_context.state', 'unknown'))),
            'temporal_window' => data_get($result, 'temporal_window_key', data_get($result, 'forward_window_protocol.window_key', data_get($metadata, 'temporal_window_key', 'unknown'))),
            'side' => data_get($result, 'side', data_get($result, 'dominant_side', data_get($metadata, 'mutation_scope.side', 'both'))),
        ];

        return [
            ...$context,
            'context_key' => $this->key($context),
            'cell_key' => $this->cellKey($context),
            'protocol' => self::PROTOCOL,
        ];
    }

    /** @return array<string, mixed> */
    public function key(array $context): string
    {
        $keys = ['target', 'gene', 'direction', 'regime', 'session', 'volume_state', 'temporal_window', 'side'];
        $normalized = [];
        foreach ($keys as $key) $normalized[$key] = strtolower(trim((string) ($context[$key] ?? 'unknown')));
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** Context used before a gene/direction has been selected. */
    public function cellKey(array $context): string
    {
        $cell = $context;
        unset($cell['gene'], $cell['direction']);
        return $this->key($cell);
    }

    /**
     * Rank legal genes from the same contextual cell. Exploration is bounded
     * by the caller's candidate list and is only a search prior.
     *
     * @param array<int, string> $legalGenes
     * @return array<string, mixed>|null
     */
    public function recommend(string $symbol, string $timeframe, string $family, array $legalGenes, string $target, array $context = []): ?array
    {
        try { if (! Schema::hasTable('lab_mutation_response_maps')) return null; } catch (\Throwable) { return null; }
        $context = [...$context, 'target' => $target];
        $key = $this->cellKey($context);
        $rows = LabMutationResponseMap::query()
            ->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))
            ->where('strategy_family', $family)->whereIn('parameter_key', array_values(array_filter($legalGenes)))
            ->get()->filter(fn (LabMutationResponseMap $row): bool => data_get($row->metadata, 'contextual_bandit.cell_key', data_get($row->metadata, 'contextual_bandit.context_key')) === $key);
        if ($rows->isEmpty()) return null;
        $total = max(1, $rows->count());
        $ranked = $rows->groupBy('parameter_key')->map(function ($items) use ($total): array {
            $trials = $items->count();
            $positive = $items->filter(fn (LabMutationResponseMap $row): bool => (bool) data_get($row->metadata, 'contextual_bandit.reward_positive', false))->count();
            $mean = ($positive + 1) / ($trials + 2);
            $uncertainty = sqrt(log($total + 2) / $trials);
            return ['parameter_key' => (string) $items->first()->parameter_key, 'trials' => $trials, 'positive' => $positive, 'score' => round($mean + $uncertainty, 6)];
        })->sortByDesc('score')->first();
        return $ranked ? [...$ranked, 'cell_key' => $key, 'protocol' => self::PROTOCOL, 'promotion_evidence' => false] : null;
    }

    public function reward(array $observability, array $controlRelative): array
    {
        $observable = in_array((string) data_get($observability, 'classification'), ['observable_effect'], true);
        $anchor = (float) data_get($observability, 'gate_margin.normalized_delta', 0.0);
        $control = (float) data_get($controlRelative, 'control_delta', 0.0);
        $safe = (bool) data_get($controlRelative, 'non_target_regression.safe', true);
        $hasControl = data_get($controlRelative, 'control_agent_id') !== null;
        $positive = $observable && $safe && $anchor > 0
            && (! $hasControl || (bool) data_get($controlRelative, 'control_relative_improved', false));
        return ['reward' => round(($positive ? 1.0 : 0.0) + max(0.0, $anchor) + max(0.0, $control), 6), 'reward_positive' => $positive, 'promotion_evidence' => false];
    }
}
