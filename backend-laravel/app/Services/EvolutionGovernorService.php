<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabAgentParentLink;
use App\Models\LabEvolutionIsland;
use App\Models\LabGeneration;
use App\Models\MarketDriftSnapshot;
use Illuminate\Support\Collection;

/**
 * Chooses the search posture for the next experiment without ever becoming a
 * promotion gate.  The governor changes how much of the already eligible
 * frontier is explored; it never turns weak evidence into a parent.
 */
class EvolutionGovernorService
{
    public const PROTOCOL = 'adaptive_evolution_governor_v1';

    public const CAUSAL_ORIGINS = [
        'gate_targeted', 'risk_exit', 'causal_isolation', 'g98_council',
        'coverage_rescue', 'lineage_root_rebuild',
    ];

    public const CAUSAL_TARGETS = [
        'monthly_survival', 'regime_coverage', 'volatility_session_stability',
        'exit_topology', 'transition_firewall', 'portfolio_router',
        'opportunity_recall', 'unknown_state_curiosity',
    ];

    /**
     * Snapshot the recent research state for a laboratory. This is deliberately
     * descriptive: no field returned here is evidence of promotion.
     */
    public function generationSnapshot(AiLaboratory $lab, array $plan = []): array
    {
        $lookback = max(1, (int) config('services.lab_selection.governor_lookback_generations', 3));
        $generations = $lab->generations()
            ->whereIn('status', ['screened', 'completed', 'technical_quarantine', 'abandoned', 'failed'])
            ->latest('generation')
            ->limit($lookback)
            ->get();

        $agents = $generations->isEmpty()
            ? collect()
            : LabAgent::with('modelVersion')
                ->whereIn('lab_generation_id', $generations->pluck('id'))
                ->get();

        $generationScores = $generations->map(function (LabGeneration $generation) use ($agents): ?float {
            $values = $agents
                ->where('lab_generation_id', $generation->id)
                ->map(fn (LabAgent $agent) => $agent->forward_score ?? $agent->validation_score ?? $agent->train_score)
                ->filter(fn ($value): bool => is_numeric($value))
                ->map(fn ($value): float => (float) $value)
                ->values();

            return $values->isEmpty() ? null : round((float) $values->avg(), 4);
        })->filter(fn ($value): bool => $value !== null)->values();

        $diversity = $this->diversityScore($agents);
        $stagnation = $this->stagnationGenerations($generationScores);
        $progress = $this->progressScore($generationScores);
        $parentTelemetry = $this->parentTelemetry($generations, $agents);
        $archiveTelemetry = $this->archiveTelemetry($lab);
        $driftTelemetry = $this->driftTelemetry($lab);
        $failedMutationTelemetry = $this->failedMutationTelemetry($agents);
        $collapseThreshold = (float) config('services.lab_selection.governor_diversity_collapse_threshold', .35);
        $stagnationThreshold = max(1, (int) config('services.lab_selection.governor_stagnation_generations', 3));
        $exploration = .20;
        if ($generations->isEmpty()) $exploration += .15;
        if ($diversity <= $collapseThreshold) $exploration += .30;
        if ($stagnation >= $stagnationThreshold) $exploration += .25;
        if ((float) data_get($parentTelemetry, 'parent_concentration', 0) >= .50) $exploration += .15;
        if ((string) data_get($driftTelemetry, 'status') === 'recheck_required') $exploration += .15;
        if ((int) data_get($failedMutationTelemetry, 'repeated_failure_count', 0) > 0) $exploration += .10;
        $exploration = $this->clamp($exploration, .15, .80);

        $originCounts = collect($plan)
            ->filter(fn ($slot): bool => is_array($slot))
            ->countBy(fn (array $slot): string => (string) ($slot['origin'] ?? 'unknown'))
            ->all();

        return [
            'protocol' => self::PROTOCOL,
            'scope' => ['symbol' => $lab->symbol, 'timeframe' => $lab->timeframe],
            'lookback_generations' => $lookback,
            'observed_generations' => $generations->pluck('generation')->values()->all(),
            'progress_score' => round($progress, 4),
            'diversity_score' => round($diversity, 4),
            'stagnation_generations' => $stagnation,
            'exploration_ratio' => round($exploration, 4),
            'exploitation_ratio' => round(1 - $exploration, 4),
            'diversity_collapse' => $diversity <= $collapseThreshold,
            'stagnation_triggered' => $stagnation >= $stagnationThreshold,
            'parent_concentration' => round((float) data_get($parentTelemetry, 'parent_concentration', 0), 4),
            'parent_lineage_entropy' => round((float) data_get($parentTelemetry, 'lineage_entropy', 1), 4),
            'parent_lineage_count' => (int) data_get($parentTelemetry, 'lineage_count', 0),
            'archive_coverage' => $archiveTelemetry,
            'market_drift' => $driftTelemetry,
            'repeated_failed_mutations' => $failedMutationTelemetry,
            'validation_budget' => [
                'planned_slots' => count($plan),
                'observed_agents' => $agents->count(),
                'lookback_agent_count' => $agents->count(),
                'promotion_evidence' => false,
            ],
            'lineage_cap' => (float) config('services.lab_selection.parent_lineage_cap', .50),
            'planned_origin_counts' => $originCounts,
            'promotion_evidence' => false,
            'rule' => 'Governor changes search allocation only; all children still require independent replay and unchanged gates.',
        ];
    }

    /** Return a safe default when a selector is used outside a generation build. */
    public function scopeSnapshot(string $symbol, string $timeframe): array
    {
        $lab = AiLaboratory::query()
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->first();

        return $lab ? $this->generationSnapshot($lab) : [
            'protocol' => self::PROTOCOL,
            'scope' => ['symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe)],
            'lookback_generations' => 0,
            'observed_generations' => [],
            'progress_score' => .5,
            'diversity_score' => 1.0,
            'stagnation_generations' => 0,
            'exploration_ratio' => .35,
            'exploitation_ratio' => .65,
            'diversity_collapse' => false,
            'stagnation_triggered' => false,
            'parent_concentration' => 0,
            'parent_lineage_entropy' => 1,
            'parent_lineage_count' => 0,
            'archive_coverage' => ['island_count' => 0, 'active_entry_count' => 0, 'coverage_ratio' => 0],
            'market_drift' => ['status' => 'unknown', 'psi_score' => null, 'volatility_ratio' => null],
            'repeated_failed_mutations' => ['repeated_failure_count' => 0, 'fingerprints' => []],
            'validation_budget' => ['planned_slots' => 0, 'observed_agents' => 0, 'lookback_agent_count' => 0, 'promotion_evidence' => false],
            'lineage_cap' => (float) config('services.lab_selection.parent_lineage_cap', .50),
            'planned_origin_counts' => [],
            'promotion_evidence' => false,
            'rule' => 'No prior generation exists; use bounded exploration and collect evidence first.',
        ];
    }

    /**
     * Dynamic parent cardinality policy. Causal lanes remain one-parent by
     * design; robust lanes can use several contributors when evidence and
     * diversity justify it.
     */
    public function selectionPolicy(
        string $family,
        string $origin,
        ?string $target,
        array $snapshot,
    ): array {
        $causal = $this->isCausalLane($family, $origin, $target);
        $runtime = in_array($family, ['regime_ensemble', 'differential_router'], true);
        $mode = match (true) {
            $runtime && $family === 'regime_ensemble' => 'runtime_ensemble',
            $causal => 'causal_single_parent',
            in_array($origin, ['robust_crossover', 'crossover'], true) => 'robust_capability_crossover',
            $origin === 'architecture' => 'architecture_discovery',
            $origin === 'curiosity_probe' => 'curiosity_exploration',
            default => 'champion_guided_frontier',
        };

        $max = match ($mode) {
            'causal_single_parent' => 1,
            'robust_capability_crossover' => max(2, (int) config('services.lab_selection.parent_max_robust', 5)),
            'architecture_discovery' => max(1, (int) config('services.lab_selection.parent_max_architecture', 4)),
            'curiosity_exploration' => max(1, (int) config('services.lab_selection.parent_max_curiosity', 3)),
            'runtime_ensemble' => max(1, (int) config('services.lab_selection.parent_max_runtime', 8)),
            default => 1,
        };

        return [
            'protocol' => 'adaptive_parent_frontier_v1',
            'mode' => $mode,
            'min_parents' => $mode === 'robust_capability_crossover' ? 2 : 1,
            'max_parents' => $max,
            'causal_lane' => $causal,
            'exploration_ratio' => (float) data_get($snapshot, 'exploration_ratio', .35),
            'diversity_score' => (float) data_get($snapshot, 'diversity_score', 1),
            'progress_score' => (float) data_get($snapshot, 'progress_score', .5),
            'stagnation_generations' => (int) data_get($snapshot, 'stagnation_generations', 0),
            'lineage_cap' => (float) data_get($snapshot, 'lineage_cap', .50),
            'adaptive_k' => true,
            'promotion_evidence' => false,
        ];
    }

    /**
     * Turn the fixed safety budget into an adaptive search budget after the
     * first generation. The first generation remains the historical causal
     * baseline; later generations keep a protected causal floor and use the
     * remaining seats for robust crossover, architecture discovery and
     * curiosity. No gate or evidence threshold is changed here.
     *
     * @param array<int, array<string, mixed>> $plan
     * @return array<int, array<string, mixed>>
     */
    public function adaptPlan(array $plan, array $snapshot): array
    {
        if ($plan === [] || ! (bool) config('services.lab_selection.adaptive_budget_enabled', true)) return $plan;
        // lookback_generations is the configured window, not proof that a
        // previous generation exists. The first generation must remain the
        // stable causal baseline even when the default lookback is three.
        if (count((array) data_get($snapshot, 'observed_generations', [])) < 1) return $plan;

        $protectedFloor = min(count($plan), max(4, (int) config('services.lab_selection.adaptive_causal_seat_floor', 8)));
        $mutable = [];
        foreach ($plan as $index => $slot) {
            if ($index < $protectedFloor) continue;
            if (! is_array($slot)) continue;
            if (in_array((string) ($slot['origin'] ?? ''), ['coverage_rescue', 'council_role_complete', 'lineage_root_rebuild'], true)) continue;
            $mutable[] = $index;
        }
        if ($mutable === []) return $plan;

        $exploration = $this->clamp((float) data_get($snapshot, 'exploration_ratio', .35), .15, .80);
        $pressure = max(
            (float) data_get($snapshot, 'parent_concentration', 0),
            (bool) data_get($snapshot, 'diversity_collapse', false) ? 1.0 : 0.0,
            (string) data_get($snapshot, 'market_drift.status') === 'recheck_required' ? .9 : 0.0,
        );
        $seatCount = max(2, (int) round(count($mutable) * max(.20, min(.60, $exploration + ($pressure * .20)))));
        $seatCount = min(count($mutable), $seatCount);
        $origins = $pressure >= .50 || (int) data_get($snapshot, 'stagnation_generations', 0) >= 1
            ? ['robust_crossover', 'architecture', 'curiosity_probe', 'robust_crossover']
            : ['robust_crossover', 'architecture'];

        foreach (array_slice($mutable, -$seatCount) as $offset => $index) {
            $origin = $origins[$offset % count($origins)];
            $slot = (array) $plan[$index];
            $slot['origin'] = $origin;
            $slot['target'] = match ($origin) {
                'robust_crossover' => 'robustness',
                'architecture' => 'architecture',
                default => 'architecture',
            };
            // A dynamic experiment is deliberately detached from a previous
            // council niche. Otherwise the target itself would reclassify the
            // child as a causal lane and collapse K back to one parent.
            $slot['niche'] = [
                'protocol' => 'adaptive_exploration_lane_v1',
                'role' => 'adaptive_explorer',
                'regime' => data_get($slot, 'niche.regime'),
                'volatility' => data_get($slot, 'niche.volatility'),
                'direction' => data_get($slot, 'niche.direction'),
                'adaptive_origin' => $origin,
                'research_only_until_independent_replay' => true,
                'promotion_evidence' => false,
            ];
            $slot['adaptive_governor'] = [
                'protocol' => self::PROTOCOL,
                'reason' => $pressure >= .50 ? 'diversity_or_drift_pressure' : 'bounded_exploration_reserve',
                'exploration_ratio' => round($exploration, 4),
                'protected_causal_floor' => $protectedFloor,
                'promotion_evidence' => false,
            ];
            $plan[$index] = $slot;
        }

        return array_values($plan);
    }

    /** @return array<string, mixed> */
    private function parentTelemetry(Collection $generations, Collection $agents): array
    {
        try {
            $links = LabAgentParentLink::query()
                ->whereIn('lab_agent_id', $agents->pluck('id'))
                ->pluck('parent_model_version_id')
                ->filter()->map(fn ($id): int => (int) $id)->values();
            $total = max(1, $links->count());
            $counts = $links->countBy();
            $probabilities = $counts->map(fn ($count): float => (float) $count / $total);
            $entropy = $probabilities->isEmpty()
                ? 1.0
                : (float) (-$probabilities->sum(fn (float $p): float => $p > 0 ? $p * log($p) : 0) / log(max(2, $probabilities->count())));
            $lineages = $agents->map(function (LabAgent $agent): string {
                $model = $agent->modelVersion;
                return (string) (
                    data_get($model?->metadata, 'repair_lineage.root_model_version_id')
                    ?: data_get($model?->metadata, 'control_root_seed.root_model_version_id')
                    ?: $agent->parent_a_model_version_id
                    ?: $agent->model_version_id
                );
            })->filter()->countBy();
            $lineageTotal = max(1, $lineages->sum());
            $lineageProbabilities = $lineages->map(fn ($count): float => (float) $count / $lineageTotal);
            $lineageEntropy = $lineageProbabilities->count() <= 1
                ? ($lineageProbabilities->count() === 0 ? 1.0 : 0.0)
                : (float) (-$lineageProbabilities->sum(fn (float $p): float => $p > 0 ? $p * log($p) : 0) / log($lineageProbabilities->count()));

            return [
                'parent_count' => $links->unique()->count(),
                'parent_concentration' => $counts->isEmpty() ? 0.0 : round((float) $counts->max() / $total, 4),
                'lineage_count' => $lineages->count(),
                'lineage_entropy' => $this->clamp($lineageEntropy, 0, 1),
            ];
        } catch (\Throwable) {
            return ['parent_count' => 0, 'parent_concentration' => 0.0, 'lineage_count' => 0, 'lineage_entropy' => 1.0];
        }
    }

    /** @return array<string, mixed> */
    private function archiveTelemetry(AiLaboratory $lab): array
    {
        try {
            $islands = LabEvolutionIsland::query()->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)->get();
            $entries = $islands->sum(fn (LabEvolutionIsland $island): int => array_sum((array) $island->archive_counts));
            $expected = max(1, count((array) $lab->strategy_families) * 4);
            return [
                'island_count' => $islands->count(),
                'active_entry_count' => $entries,
                'coverage_ratio' => round($this->clamp($islands->count() / $expected, 0, 1), 4),
            ];
        } catch (\Throwable) {
            return ['island_count' => 0, 'active_entry_count' => 0, 'coverage_ratio' => 0];
        }
    }

    /** @return array<string, mixed> */
    private function driftTelemetry(AiLaboratory $lab): array
    {
        try {
            $snapshot = MarketDriftSnapshot::query()->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)->latest('detected_at')->first();
            return [
                'status' => (string) ($snapshot?->status ?: 'unknown'),
                'psi_score' => $snapshot?->psi_score,
                'volatility_ratio' => $snapshot?->volatility_ratio,
                'detected_at' => $snapshot?->detected_at?->toIso8601String(),
            ];
        } catch (\Throwable) {
            return ['status' => 'unknown', 'psi_score' => null, 'volatility_ratio' => null];
        }
    }

    /** @return array<string, mixed> */
    private function failedMutationTelemetry(Collection $agents): array
    {
        $fingerprints = $agents->filter(fn (LabAgent $agent): bool => in_array((string) $agent->lifecycle_status, [
            'rejected', 'failed', 'overfit', 'stagnated', 'technical_quarantine',
        ], true))->map(fn (LabAgent $agent): ?string => data_get($agent->modelVersion?->metadata, 'parameter_fingerprint'))
            ->filter()->countBy();
        $repeated = $fingerprints->filter(fn ($count): bool => (int) $count > 1);
        return [
            'repeated_failure_count' => $repeated->count(),
            'fingerprints' => $repeated->map(fn ($count, $fingerprint): array => ['fingerprint' => $fingerprint, 'count' => (int) $count])->values()->all(),
        ];
    }

    /** Runtime policy is a router contract, never a shortcut around replay. */
    public function runtimePolicy(string $family, array $parentIds = []): ?array
    {
        if (! in_array($family, ['regime_ensemble', 'differential_router'], true)) return null;

        return [
            'protocol' => 'runtime_regime_ensemble_router_v1',
            'family' => $family,
            'member_model_version_ids' => array_values(array_map('intval', array_filter($parentIds))),
            'selection_rule' => $family === 'regime_ensemble'
                ? 'one sealed specialist owns each observed regime; no duplicate signal aggregation'
                : 'frozen parent owns non-target lanes; target lane is the only allowed repair surface',
            'unknown_regime_action' => 'WAIT',
            'specialist_disagreement_action' => 'WAIT',
            'missing_member_action' => 'WAIT',
            'minimum_independent_members' => $family === 'regime_ensemble' ? 2 : 1,
            'max_members' => (int) config('services.lab_selection.parent_max_runtime', 8),
            // Genetic contributors are hypotheses. They are not deployable
            // runtime members until an independent specialist passport and a
            // combined portfolio passport have been sealed.
            'independent_members_validated' => false,
            'genetic_parent_ids_are_runtime_members' => false,
            'runtime_activation_source' => 'EliteAgentPortfolioGateService',
            'runtime_activation_rule' => 'only a passed portfolio/holdout/paper contract may populate portfolio_members',
            'paper_and_holdout_required' => true,
            'promotion_evidence' => false,
        ];
    }

    public function isCausalLane(string $family, string $origin, ?string $target): bool
    {
        return $family === 'differential_router'
            || in_array($origin, self::CAUSAL_ORIGINS, true)
            || in_array((string) $target, self::CAUSAL_TARGETS, true);
    }

    private function diversityScore(Collection $agents): float
    {
        if ($agents->isEmpty()) return 1.0;

        $signatures = $agents->map(function (LabAgent $agent): string {
            $model = $agent->modelVersion;
            $stored = data_get($model?->metadata, 'parameter_fingerprint');
            if (filled($stored)) return (string) $stored;
            $parameters = (array) ($model?->parameters ?? []);
            ksort($parameters);
            return hash('sha256', json_encode($parameters, JSON_PRESERVE_ZERO_FRACTION));
        })->filter()->unique()->count();

        return $this->clamp($signatures / max(1, $agents->count()), 0, 1);
    }

    private function stagnationGenerations(Collection $scores): int
    {
        if ($scores->count() < 2) return 0;
        $values = $scores->values()->all();
        $stagnation = 0;
        for ($index = 0; $index < count($values) - 1; $index++) {
            if ((float) $values[$index] <= (float) $values[$index + 1] + .25) $stagnation++;
            else break;
        }
        return $stagnation;
    }

    private function progressScore(Collection $scores): float
    {
        if ($scores->isEmpty()) return .5;
        if ($scores->count() === 1) return .5;
        $newest = (float) $scores->first();
        $older = (float) $scores->slice(1)->avg();
        return $this->clamp(.5 + (($newest - $older) / 20), 0, 1);
    }

    private function clamp(float $value, float $minimum, float $maximum): float
    {
        return max($minimum, min($maximum, $value));
    }
}
