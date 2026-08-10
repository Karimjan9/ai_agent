<?php

namespace App\Services;

use App\Models\ModelVersion;
use Illuminate\Support\Collection;

/**
 * Lightweight MAP-Elites archive over the already sealed semantic groups.
 * Champion views keep one elite per cell; the evolutionary frontier keeps a
 * configurable set of same-cell capability/novelty contributors. A cell
 * never borrows genetic material from another cell.
 */
class SemanticEliteArchiveService
{
    public const PROTOCOL = 'semantic_map_elites_archive_v1';

    public function cellKey(ModelVersion $model, ?string $family = null): string
    {
        $group = app(StrategySemanticGroupService::class)->fromModel($model, $family);
        return (string) data_get($group, 'key', 'undeclared');
    }

    /** @param iterable<ModelVersion> $parents */
    public function oneElitePerCell(iterable $parents, ?callable $score = null): Collection
    {
        $score ??= static fn (ModelVersion $model): float => (float) ($model->best_score ?? 0);

        return collect($parents)
            ->filter(fn ($model): bool => $model instanceof ModelVersion)
            ->groupBy(fn (ModelVersion $model): string => $this->cellKey($model))
            ->map(fn (Collection $cell) => $cell->sortByDesc($score)->first())
            ->filter()
            ->values();
    }

    /**
     * Keep a configurable frontier inside each exact cell. The historical
     * oneElitePerCell() API remains available for production champion views;
     * parent evolution needs additional same-cell capability/novelty sources
     * so that a cell champion is not mistaken for the only possible genome.
     * No cross-cell material is admitted here.
     */
    public function frontierPerCell(iterable $parents, int $limit = 0, ?callable $score = null): Collection
    {
        $score ??= static fn (ModelVersion $model): float => (float) ($model->best_score ?? 0);
        // Zero is an explicit unlimited frontier. An operator may still set a
        // positive infrastructure cap, but the default must not erase valid
        // same-cell lineages before the adaptive selector can score them.
        $limit = max(0, $limit);

        return collect($parents)
            ->filter(fn ($model): bool => $model instanceof ModelVersion)
            ->groupBy(fn (ModelVersion $model): string => $this->cellKey($model))
            ->flatMap(function (Collection $cell) use ($score, $limit): Collection {
                $ranked = $cell->sortByDesc($score)->values();
                if ($limit === 0 || $ranked->count() <= $limit) return $ranked;

                $selected = collect([$ranked->first()]);
                $remaining = $ranked->slice(1)->values();
                while ($selected->count() < $limit && $remaining->isNotEmpty()) {
                    $next = $remaining->sortByDesc(function (ModelVersion $candidate) use ($selected, $score): float {
                        $novelty = $selected->map(fn (ModelVersion $chosen): float => $this->parameterDistance($candidate, $chosen))->max() ?? 0;
                        return ((float) $score($candidate)) + ($novelty * 20);
                    })->first();
                    if (! $next) break;
                    $selected->push($next);
                    $remaining = $remaining->reject(fn (ModelVersion $candidate): bool => (int) $candidate->id === (int) $next->id)->values();
                }
                return $selected;
            })
            ->values();
    }

    public function contract(ModelVersion $model, ?string $family = null): array
    {
        $cell = $this->cellKey($model, $family);
        return [
            'protocol' => self::PROTOCOL,
            'cell_key' => $cell,
            'archive_role' => 'local_champion_plus_adaptive_same_cell_frontier',
            'genetic_parent_rule' => 'exact_cell_only; adaptive_frontier; root_default_when_empty',
            'cross_cell_crossover' => false,
            'portfolio_mix_rule' => 'elite_quorum_only_after_independent_validation',
            'promotion_evidence' => false,
        ];
    }

    private function parameterDistance(ModelVersion $left, ModelVersion $right): float
    {
        $leftParameters = (array) ($left->parameters ?? []);
        $rightParameters = (array) ($right->parameters ?? []);
        $keys = array_values(array_unique(array_merge(array_keys($leftParameters), array_keys($rightParameters))));
        if ($keys === []) return 0.0;
        $different = 0;
        foreach ($keys as $key) {
            if (json_encode($leftParameters[$key] ?? null, JSON_PRESERVE_ZERO_FRACTION)
                !== json_encode($rightParameters[$key] ?? null, JSON_PRESERVE_ZERO_FRACTION)) {
                $different++;
            }
        }
        return $different / count($keys);
    }
}
