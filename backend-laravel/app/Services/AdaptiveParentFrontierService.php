<?php

namespace App\Services;

use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use Illuminate\Support\Collection;

/**
 * Selects contributors from the exact semantic frontier.
 *
 * The service is intentionally downstream of strict semantic filtering. It
 * may choose several parents inside one legal cell, but it can never create a
 * cross-family or cross-regime genetic edge.
 */
class AdaptiveParentFrontierService
{
    private const MODULE_KEYS = [
        'entry' => [
            'lookback', 'confirmation_candles',
            'trend_strength_min', 'pullback_atr_fraction', 'roc_threshold', 'deviation',
        ],
        'exit' => [
            'atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier',
            'time_stop_candles', 'partial_take_profit_fraction', 'partial_target_atr_multiplier',
        ],
        'risk' => [
            'high_volatility_risk_multiplier', 'max_loss_streak_before_wait',
            'loss_cooldown_candles', 'avoid_high_volatility',
        ],
        'execution_cost' => [
            'spread_to_atr_max', 'max_spread_points', 'slippage_points',
            'commission_percent', 'risk_per_trade',
        ],
        'router' => [
            'differential_target_regime', 'differential_router_version',
            'trend_down_strength_min', 'trend_down_pullback_atr_fraction',
            'high_volatility_wait', 'range_signal_mode',
        ],
        'confidence_calibration' => [
            'minimum_signal_confidence', 'confidence_calibration_enabled',
            'confidence_calibration_min_samples', 'confidence_ev_lower_bound_enabled',
            'meta_label_enabled', 'meta_label_min_history', 'meta_label_min_pf',
            'meta_label_risk_multiplier',
        ],
    ];

    public function __construct(
        private EvolutionGovernorService $governor,
        private StrategySemanticGroupService $semanticGroups,
        private ParentContextTrustService $parentTrust,
    ) {}

    /**
     * @param iterable<ModelVersion> $parents Already exact-cell filtered.
     * @return array{parents: Collection, selected_parent_ids: array, candidate_parent_ids: array, contract: array, capability_genome: array, runtime_ensemble_policy: ?array}
     */
    public function select(
        iterable $parents,
        string $symbol,
        string $timeframe,
        string $family,
        string $origin,
        ?string $target,
        ?array $niche,
        int $slot = 1,
        ?LabGeneration $generation = null,
    ): array {
        $candidates = collect($parents)
            ->filter(fn ($model): bool => $model instanceof ModelVersion && (int) $model->id > 0)
            ->filter(fn (ModelVersion $model): bool => $this->semanticGroups->exactParentCompatible(
                $model,
                $symbol,
                $timeframe,
                $family,
                $niche,
            ))
            ->unique('id')
            ->values();

        $snapshot = $generation
            ? (array) data_get($generation->trigger_context, 'adaptive_evolution_policy', [])
            : [];
        if ($snapshot === []) $snapshot = $this->governor->scopeSnapshot($symbol, $timeframe);

        if (! (bool) config('services.lab_selection.adaptive_parent_enabled', true)) {
            // Disabling the adaptive scorer must not disable the evolutionary
            // contract. Causal lanes still need one isolated parent; only
            // robust/discovery lanes may receive the full legacy frontier.
            $allProfiles = $this->profiles($candidates, $symbol, $timeframe, $family, $target, $niche);
            $eligibleProfiles = $allProfiles
                ->filter(fn (array $profile): bool => (bool) data_get($profile, 'parent_eligible', false))
                ->values();
            $causal = in_array($origin, EvolutionGovernorService::CAUSAL_ORIGINS, true);
            $policy = $this->governor->selectionPolicy(
                $family,
                $origin,
                $target,
                $snapshot,
            );
            $selectedCandidates = $causal
                ? $eligibleProfiles->take(1)->pluck('model')->values()
                : $eligibleProfiles->take((int) $policy['max_parents'] > 0 ? (int) $policy['max_parents'] : $eligibleProfiles->count())->pluck('model')->values();
            $candidateIds = $allProfiles->pluck('model.id')->map(fn ($id): int => (int) $id)->values()->all();
            $ids = $selectedCandidates->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
            $candidateScores = $allProfiles->mapWithKeys(fn (array $profile): array => [(string) $profile['model']->id => [
                'parent_eligible' => (bool) data_get($profile, 'parent_eligible', false),
                'parent_selection_reason' => data_get($profile, 'parent_selection_reason', 'rejected_parent_passport'),
                'parent_selection_reasons' => (array) data_get($profile, 'parent_selection_reasons', []),
            ]])->all();
            $contract = [
                'protocol' => 'adaptive_parent_frontier_v1',
                'status' => 'disabled',
                'mode' => 'legacy_frontier_projection',
                'candidate_count' => $allProfiles->count(),
                'selected_count' => $selectedCandidates->count(),
                'candidate_parent_model_version_ids' => $candidateIds,
                'selected_parent_model_version_ids' => $ids,
                'eligible_parent_model_version_ids' => $eligibleProfiles->pluck('model.id')->map(fn ($id): int => (int) $id)->values()->all(),
                'candidate_scores' => $candidateScores,
                'parent_firewall' => 'parent_eligible_true_only',
                'causal_lane' => $causal,
                'min_parents' => $policy['min_parents'],
                'max_parents' => $policy['max_parents'],
                'promotion_evidence' => false,
            ];
            return [
                'parents' => $selectedCandidates,
                'selected_parent_ids' => $ids,
                'candidate_parent_ids' => $candidateIds,
                'contract' => $contract,
                'capability_genome' => $this->capabilityGenome($selectedCandidates, []),
                'runtime_ensemble_policy' => $this->governor->runtimePolicy($family, $ids),
            ];
        }

        $policy = $this->governor->selectionPolicy($family, $origin, $target, $snapshot);
        $island = $this->semanticGroups->descriptor($symbol, $timeframe, $family, $niche);
        $allProfiles = $this->profiles($candidates, $symbol, $timeframe, $family, $target, $niche);
        // An archive is a memory system, not a passport. A young/research
        // entry may guide a hypothesis, but it has no proven benefit and may
        // not become a genetic parent or capability contributor. Only an
        // exact-cell model with a valid parent passport can influence the
        // child's inherited parameters. Control-root seeds are the explicit
        // exception: they are reproducible starting baselines, not claimed
        // performance parents.
        $profiles = $allProfiles
            ->filter(fn (array $profile): bool => (bool) data_get($profile, 'parent_eligible', false))
            ->values();
        $desired = $this->desiredParentCount($policy, $profiles->count());
        $selectedProfiles = $this->selectProfiles($profiles, $desired, (int) $slot, $policy);
        $selected = $selectedProfiles->map(fn (array $profile): ModelVersion => $profile['model'])->values();
        $selectedIds = $selected->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        $candidateIds = $allProfiles->pluck('model.id')->map(fn ($id): int => (int) $id)->values()->all();
        $eligibleCandidateIds = $profiles->pluck('model.id')->map(fn ($id): int => (int) $id)->values()->all();
        $capability = $this->capabilityGenome($selected, $selectedProfiles->all());
        $runtime = $this->governor->runtimePolicy($family, $selectedIds);

        $candidateScores = [];
        foreach ($allProfiles as $profile) {
            $candidateScores[(string) $profile['model']->id] = [
                'score' => round((float) $profile['score'], 4),
                'lineage_id' => $profile['lineage_id'],
                'parameter_signature' => $profile['signature'],
                'novelty_to_anchor' => round((float) $profile['novelty_to_anchor'], 4),
                'parent_eligible' => (bool) data_get($profile, 'parent_eligible', false),
                'research_seed_eligible' => (bool) data_get($profile, 'research_seed_eligible', false),
                'context_trust' => data_get($profile, 'context_trust', ['trust_score' => .50, 'status' => 'no_context_evidence']),
                'exclusion_reason' => data_get($profile, 'parent_exclusion_reason'),
                'parent_selection_reason' => data_get($profile, 'parent_selection_reason', 'rejected_parent_passport'),
                'parent_selection_reasons' => (array) data_get($profile, 'parent_selection_reasons', []),
                'archive_type' => data_get($profile, 'archive_type'),
            ];
        }

        $contract = [
            'protocol' => 'adaptive_parent_frontier_v1',
            'status' => 'active',
            'mode' => $policy['mode'],
            'island_key' => data_get($island, 'key'),
            'semantic_group_protocol' => StrategySemanticGroupService::PROTOCOL,
            'candidate_count' => $allProfiles->count(),
            'eligible_candidate_count' => $profiles->count(),
            'selected_count' => $selected->count(),
            'candidate_parent_model_version_ids' => $candidateIds,
            'eligible_parent_model_version_ids' => $eligibleCandidateIds,
            'selected_parent_model_version_ids' => $selectedIds,
            'dynamic_k' => $selected->count(),
            'min_parents' => $policy['min_parents'],
            'max_parents' => $policy['max_parents'],
            'candidate_scores' => $candidateScores,
            'exploration_ratio' => $policy['exploration_ratio'],
            'diversity_score' => $policy['diversity_score'],
            'progress_score' => $policy['progress_score'],
            'stagnation_generations' => $policy['stagnation_generations'],
            'lineage_cap' => $policy['lineage_cap'],
            'selection_seed' => $slot,
            'anchor_model_version_id' => $selected->first()?->id,
            'anchor_selection_rule' => 'slot-aware exploitation/exploration rotation; champion is a contributor, not a mandatory parent for every child',
            'parent_firewall' => 'parent_eligible_true_only',
            'selected_parent_reasons' => $selectedProfiles->mapWithKeys(fn (array $profile): array => [
                (string) $profile['model']->id => data_get($profile, 'parent_selection_reason', 'eligible'),
            ])->all(),
            'capability_modules' => array_keys((array) data_get($capability, 'modules', [])),
            'research_seed_only' => false,
            'research_seed_candidate_count' => $allProfiles->filter(
                fn (array $profile): bool => (bool) data_get($profile, 'research_seed_eligible', false)
                    && ! (bool) data_get($profile, 'parent_eligible', false),
            )->count(),
            'parent_passport_rule' => 'valid evidence, sample/rolling/stress/PBO-DSR/bootstrap checks; exploratory young seeds are research-only',
            'causal_parent_rule' => $policy['causal_lane']
                ? 'exactly one parent; mutation attribution remains isolated'
                : null,
            'cross_cell_crossover' => false,
            'promotion_evidence' => false,
        ];

        return [
            'parents' => $selected,
            'selected_parent_ids' => $selectedIds,
            'candidate_parent_ids' => $candidateIds,
            'contract' => $contract,
            'capability_genome' => $capability,
            'runtime_ensemble_policy' => $runtime,
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function profiles(
        Collection $candidates,
        string $symbol,
        string $timeframe,
        string $family,
        ?string $target,
        ?array $niche,
    ): Collection {
        // A full exact-cell frontier is allowed. Resolve the latest evidence
        // in one query so removing the old parent-count ceiling does not turn
        // every contributor into an N+1 database round trip.
        $performanceByModel = ModelMarketPerformance::query()
            ->whereIn('model_version_id', $candidates->pluck('id')->all())
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->where('strategy_family', $family)
            ->latest('id')
            ->get()
            ->unique('model_version_id')
            ->keyBy('model_version_id');

        return $candidates->map(function (ModelVersion $model) use ($symbol, $timeframe, $family, $target, $niche, $performanceByModel): array {
            $performance = $performanceByModel->get($model->id);
            $metrics = (array) ($performance?->metrics ?? []);
            $contextTrust = $this->parentTrust->score(
                $model,
                $symbol,
                $timeframe,
                $family,
                (string) ($target ?: 'general_skill'),
                (array) $niche,
            );
            $statusBonus = match ((string) ($performance?->status ?? '')) {
                'champion' => 8, 'forward_validated' => 6, 'challenger' => 4, 'paper' => 3,
                default => 0,
            };
            $score = ((float) ($performance?->forward_score ?? $model->best_score ?? 0) * 2)
                + ((float) data_get($metrics, 'profit_factor', 0) * 25)
                - (float) data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown', 0))
                - ((float) data_get($metrics, 'monte_carlo.risk_of_ruin_percent', 0) * 2)
                + $statusBonus
                + (float) data_get($model->metadata, 'target_progress.'.(string) $target.'.selection_score', 0)
                // Context trust is only a bounded ranking adjustment. It
                // cannot admit an ineligible parent or bypass any gate.
                + (((float) data_get($contextTrust, 'trust_score', .50) - .50) * 5);

            $parameters = (array) ($model->parameters ?? []);
            ksort($parameters);
            $signature = hash('sha256', json_encode($parameters, JSON_PRESERVE_ZERO_FRACTION));
            $lineage = data_get($model->metadata, 'repair_lineage.root_model_version_id')
                ?: data_get($model->metadata, 'control_root_seed.control_root_model_version_id')
                ?: data_get($model->metadata, 'control_root_seed_model_version_id')
                ?: $model->id;

            return [
                'model' => $model,
                'score' => $score,
                'lineage_id' => (string) $lineage,
                'signature' => $signature,
                'novelty_to_anchor' => 0.0,
                'performance_id' => $performance?->id,
                'archive_type' => $model->getAttribute('_adaptive_archive_type'),
                'context_trust' => $contextTrust,
                ...$this->parentEligibilityProfile($model, $performance),
                'semantic_group_key' => data_get($this->semanticGroups->fromModel($model, $family), 'key'),
                'niche' => $niche,
            ];
        })->sortByDesc('score')->values()->tap(function (Collection $profiles): void {
            $anchor = $profiles->first();
            if (! $anchor) return;
            $profiles->transform(function (array $profile) use ($anchor): array {
                $profile['novelty_to_anchor'] = $this->parameterDistance(
                    (array) $profile['model']->parameters,
                    (array) $anchor['model']->parameters,
                );
                return $profile;
            });
        });
    }

    private function desiredParentCount(array $policy, int $candidateCount): int
    {
        if ($candidateCount === 0) return 0;
        if ((bool) $policy['causal_lane']) return 1;

        $configuredMax = (int) ($policy['max_parents'] ?? 0);
        // A zero policy max is deliberately resolved here, after exact-cell
        // eligibility is known. This keeps K dynamic instead of replacing a
        // hidden parent ceiling with a different hard-coded ceiling.
        $max = $configuredMax > 0 ? min($configuredMax, $candidateCount) : $candidateCount;
        $exploration = (float) $policy['exploration_ratio'];
        $minimum = min((int) ($policy['min_parents'] ?? 1), $max);
        $desired = match ($policy['mode']) {
            'robust_capability_crossover' => max($minimum, 2 + (int) round($exploration * max(0, $max - 2))),
            'runtime_ensemble' => max($minimum, 3 + (int) round($exploration * max(0, $max - 3))),
            // Architecture discovery is not a causal one-gene repair. It
            // needs at least a small capability frontier even under normal
            // exploration, otherwise the old champion remains the sole
            // source until a collapse alarm fires. K still grows with the
            // governor and is bounded only by the configured eligible pool.
            'architecture_discovery' => max($minimum, 1 + (int) round($exploration * max(0, $max - 1))),
            'curiosity_exploration' => 1 + (int) round($exploration * max(0, $max - 1)),
            default => 1,
        };
        if ((int) $policy['stagnation_generations'] >= (int) config('services.lab_selection.governor_stagnation_generations', 3)
            || (float) $policy['diversity_score'] <= (float) config('services.lab_selection.governor_diversity_collapse_threshold', .35)) {
            $desired = $max;
        }

        return max(1, min($max, $desired));
    }

    /** @param Collection<int, array<string, mixed>> $profiles */
    private function selectProfiles(Collection $profiles, int $desired, int $slot, array $policy): Collection
    {
        if ($profiles->isEmpty() || $desired <= 0) return collect();
        // The old selector always started at profiles[0]. That made every
        // child inherit the same champion even after the governor detected
        // concentration. Keep the score-ranked champion in the pool, but
        // rotate the exploitation anchor through a small quality frontier as
        // exploration pressure rises. `slot` is deterministic, so replays
        // remain reproducible while siblings no longer collapse to one
        // lineage.
        $exploration = max(.15, min(.80, (float) data_get($policy, 'exploration_ratio', .35)));
        $anchorPool = max(1, min(
            $profiles->count(),
            1 + (int) ceil(max(0, $profiles->count() - 1) * $exploration),
        ));
        $anchorIndex = $anchorPool > 1
            ? (max(0, $slot - 1) % $anchorPool)
            : 0;
        $anchor = $profiles->get($anchorIndex) ?: $profiles->first();
        $selected = collect([$anchor]);
        if ($desired === 1) return $selected;

        $remaining = $profiles->reject(fn (array $profile): bool =>
            (int) data_get($profile, 'model.id') === (int) data_get($anchor, 'model.id')
        )->values();
        $lineageCounts = [$anchor['lineage_id'] => 1];
        $lineageCap = max(1, (int) ceil($desired * max(.25, (float) $policy['lineage_cap'])));
        $diversityWeight = (float) config('services.lab_selection.parent_diversity_weight', 20);

        while ($selected->count() < $desired && $remaining->isNotEmpty()) {
            $ranked = $remaining->map(function (array $candidate) use ($selected, $lineageCounts, $lineageCap, $diversityWeight): array {
                $distances = $selected->map(fn (array $chosen): float => $this->parameterDistance(
                    (array) $candidate['model']->parameters,
                    (array) $chosen['model']->parameters,
                ));
                $novelty = $distances->isEmpty() ? 0 : (float) $distances->max();
                $newLineage = ! isset($lineageCounts[$candidate['lineage_id']]);
                $blocked = ($lineageCounts[$candidate['lineage_id']] ?? 0) >= $lineageCap
                    && $remaining->contains(fn (array $other): bool => ! isset($lineageCounts[$other['lineage_id']]));
                $candidate['selection_utility'] = (float) $candidate['score']
                    + ($novelty * $diversityWeight)
                    + ($newLineage ? $diversityWeight : 0)
                    - ($blocked ? 1000000 : 0);
                $candidate['marginal_novelty'] = $novelty;
                return $candidate;
            })->sortByDesc('selection_utility')->first();
            if (! $ranked) break;
            $selected->push($ranked);
            $lineageCounts[$ranked['lineage_id']] = ($lineageCounts[$ranked['lineage_id']] ?? 0) + 1;
            $remaining = $remaining->reject(fn (array $candidate): bool => (int) $candidate['model']->id === (int) $ranked['model']->id)->values();
        }

        return $selected->values();
    }

    /**
     * Capability-level provenance. A child may copy a module from a different
     * parent, but every source remains explicit and the child is re-tested.
     */
    private function capabilityGenome(Collection $parents, array $profiles): array
    {
        $profileMap = collect($profiles)->mapWithKeys(fn (array $profile): array => [(string) $profile['model']->id => $profile]);
        $modules = [];
        $parameterSources = [];
        $moduleMap = self::MODULE_KEYS;
        $knownKeys = collect($moduleMap)->flatten()->map(fn ($key): string => (string) $key)->all();
        $extensionKeys = $parents
            ->flatMap(fn (ModelVersion $parent): array => array_keys((array) $parent->parameters))
            ->unique()
            ->reject(fn ($key): bool => in_array((string) $key, $knownKeys, true))
            ->values();
        foreach ($extensionKeys as $key) {
            $moduleMap['extension:'.(string) $key] = [(string) $key];
        }

        foreach ($moduleMap as $module => $keys) {
            $contributors = [];
            foreach ($parents as $parent) {
                $present = array_values(array_intersect($keys, array_keys((array) $parent->parameters)));
                if ($present === []) continue;
                $profile = (array) ($profileMap[(string) $parent->id] ?? []);
                $contributors[] = [
                    'parent_model_version_id' => (int) $parent->id,
                    'parameter_keys' => $present,
                    'quality_score' => round((float) data_get($profile, 'score', $parent->best_score ?? 0), 4),
                    'source_evidence_id' => data_get($profile, 'performance_id'),
                    'evidence_confidence' => round((float) data_get($profile, 'evidence_confidence', 0), 4),
                    'scope' => data_get($profile, 'niche'),
                ];
            }
            if ($contributors === []) continue;
            usort($contributors, fn (array $left, array $right): int => $right['quality_score'] <=> $left['quality_score']);
            $positiveScores = collect($contributors)->map(fn (array $entry): float => max(0.0, (float) $entry['quality_score']));
            $scoreTotal = max(0.0001, (float) $positiveScores->sum());
            $contributors = array_map(function (array $entry) use ($scoreTotal): array {
                $entry['contribution_weight'] = round(max(0.0, (float) $entry['quality_score']) / $scoreTotal, 6);
                return $entry;
            }, $contributors);
            $modules[$module] = [
                'source_parent_ids' => array_values(array_map(fn (array $entry): int => $entry['parent_model_version_id'], $contributors)),
                'contributors' => $contributors,
                'rule' => 'module-level inheritance with explicit gene provenance and independent child replay',
            ];
            foreach ($keys as $key) {
                $sourceEntry = collect($contributors)->first(fn (array $entry): bool => in_array($key, (array) ($entry['parameter_keys'] ?? []), true));
                $source = data_get($sourceEntry, 'parent_model_version_id');
                if ($source) {
                    $sourceModel = $parents->firstWhere('id', (int) $source);
                    $sourceParameters = (array) ($sourceModel?->parameters ?? []);
                    $parameterSources[$key] = [
                        'source_parent_id' => $source,
                        'source_module' => $module,
                        'source_evidence_id' => data_get($sourceEntry, 'source_evidence_id'),
                        'source_confidence' => (float) data_get($sourceEntry, 'evidence_confidence', 0),
                        'contribution_weight' => (float) data_get($sourceEntry, 'contribution_weight', 0),
                        'scope' => data_get($sourceEntry, 'scope'),
                        'parameter_hash' => hash('sha256', json_encode([$key => $sourceParameters[$key] ?? null], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)),
                        'provenance_confidence' => data_get($sourceEntry, 'source_evidence_id') ? 'evidence_backed' : 'research_seed',
                    ];
                }
            }
        }

        return [
            'protocol' => 'capability_genome_provenance_v1',
            'parent_model_version_ids' => $parents->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'modules' => $modules,
            'parameter_sources' => $parameterSources,
            'dynamic_extension_modules' => array_values(array_keys(array_filter(
                $moduleMap,
                static fn ($keys, $module): bool => str_starts_with((string) $module, 'extension:'),
                ARRAY_FILTER_USE_BOTH,
            ))),
            'all_sources_require_child_replay' => true,
            'blind_scalar_blending' => false,
            'gene_provenance_required' => true,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function parentEligibilityProfile(ModelVersion $model, ?ModelMarketPerformance $performance): array
    {
        $shadowOnly = (
            (bool) data_get($model->metadata, 'shadow_research_lane.shadow_only', false)
            || data_get($model->metadata, 'shadow_research_lane.protocol') === ShadowResearchGovernorService::PROTOCOL
        ) && data_get($model->metadata, 'shadow_research_lane.requalified', false) !== true;
        $metrics = (array) ($performance?->metrics ?? []);
        $bootstrap = (array) data_get($metrics, 'statistical_evidence.edge_quality.bootstrap_pf', []);
        $edge = (array) data_get($metrics, 'statistical_evidence.edge_quality', []);
        $bootstrapPasses = data_get($bootstrap, 'status') !== 'assessed'
            || (float) data_get($bootstrap, 'pf_5_percentile_lower_bound', 0) >= 1.1;
        $regimePasses = ! (bool) data_get($edge, 'worst_regime_sampled', false)
            || (float) data_get($edge, 'worst_regime_pf', 0) >= 1.0;
        $parentEligible = ! $shadowOnly && $performance !== null
            && $performance->evidence_status === 'valid'
            && $model->evidence_status === 'valid'
            && in_array((string) $performance->status, ['champion', 'challenger', 'forward_validated', 'paper'], true)
            && (float) data_get($metrics, 'profit_factor', 0) >= 1.3
            && (float) data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown', 100)) <= 15
            && (float) data_get($metrics, 'monte_carlo.risk_of_ruin_percent', 100) <= 10
            && ! (bool) data_get($metrics, 'is_overfit', true)
            && (int) $performance->sample_count >= 30
            && (int) $performance->rolling_windows_count >= 3
            && (int) $performance->rolling_forward_wins >= 3
            && $bootstrapPasses
            && $regimePasses
            && data_get($metrics, 'behavioral_diversity.status') !== 'near_duplicate';
        $rootSeed = ! $shadowOnly && data_get($model->metadata, 'control_root_seed.protocol') === 'control_root_specialist_inheritance_v1'
            && data_get($model->metadata, 'control_root_seed.status') !== 'revoked';
        $archiveType = (string) $model->getAttribute('_adaptive_archive_type');
        $researchSeed = $archiveType === 'young'
            || $performance === null
            || (bool) data_get($model->metadata, 'screening_seed_only', false);
        $evolutionStage = (string) data_get($model->metadata, 'evolution_stage.stage', '');
        $mentorOnly = in_array($evolutionStage, ['screen_validated_seed', 'skill_mentor', 'screen_validated_control', 'repair_anchor', 'repair_anchor_control'], true)
            || data_get($model->metadata, 'skill_mentor.status') === 'confirmed';
        $passportParentEligible = ($parentEligible || $rootSeed) && ! $mentorOnly;

        // Challenger status is a lifecycle label, not independent forward
        // evidence. Persist explicit rejection codes with every candidate
        // score so a future selector cannot mistake a challenger row for a
        // legal parent merely because its status string looks advanced.
        $selectionReasons = [];
        if ($shadowOnly) $selectionReasons[] = 'rejected_shadow_only';
        if ($mentorOnly) $selectionReasons[] = 'rejected_mentor_only';
        if ($performance !== null && (float) data_get($metrics, 'profit_factor', 0) < 1.3) {
            $selectionReasons[] = 'rejected_low_pf';
        }
        $forwardStatuses = ['forward_validated', 'paper', 'champion'];
        $independentForwardWindows = max(
            (int) data_get($metrics, 'forward_protocol.independent_windows', 0),
            (int) data_get($metrics, 'forward_validation.independent_windows', 0),
            (int) data_get($metrics, 'independent_forward_windows', 0),
        );
        $independentForward = $performance !== null
            && in_array((string) $performance->status, $forwardStatuses, true)
            && ($independentForwardWindows >= 1
                || ((int) $performance->rolling_windows_count >= 3
                    && (int) $performance->rolling_forward_wins >= 3
                    && data_get($metrics, 'forward_protocol.status') === 'confirmed'));
        if (! $independentForward) $selectionReasons[] = 'rejected_no_independent_forward';
        $pairedReplayStatus = strtolower((string) (
            data_get($metrics, 'paired_replay.status')
            ?: data_get($metrics, 'paired_replay_status', '')
        ));
        if (in_array($pairedReplayStatus, ['pending', 'queued', 'started', 'in_progress'], true)) {
            $selectionReasons[] = 'rejected_pending_paired_replay';
        }
        if ($mentorOnly && $selectionReasons === []) $selectionReasons[] = 'rejected_mentor_only';
        if (! $passportParentEligible && $selectionReasons === []) $selectionReasons[] = 'rejected_parent_passport';
        if ($passportParentEligible) $selectionReasons = ['eligible'];

        $confidence = $performance === null ? 0.0 : min(1.0, max(0.0,
            .20
            + min(0.25, ((int) $performance->sample_count / 1000))
            + min(0.20, ((int) $performance->rolling_forward_wins / 20))
            + ($bootstrapPasses ? .15 : 0)
            + ($regimePasses ? .10 : 0)
            + ($performance->evidence_status === 'valid' ? .10 : 0),
        ));

        return [
            'parent_eligible' => $passportParentEligible,
            'parent_selection_reason' => $selectionReasons[0] ?? 'rejected_parent_passport',
            'parent_selection_reasons' => array_values(array_unique($selectionReasons)),
            'root_seed_eligible' => $rootSeed,
            'research_seed_eligible' => $researchSeed,
            'parent_exclusion_reason' => $passportParentEligible
                ? null
                : ($shadowOnly
                    ? 'shadow_research_only_until_control_requalification'
                    : ($mentorOnly ? 'skill_tier_not_full_parent' : ($performance === null ? 'no_independent_evidence' : 'parent_passport_incomplete'))),
            'evidence_confidence' => round($confidence, 4),
        ];
    }

    private function parameterDistance(array $left, array $right): float
    {
        $keys = array_values(array_unique(array_merge(array_keys($left), array_keys($right))));
        if ($keys === []) return 0.0;
        $different = 0;
        foreach ($keys as $key) {
            if (json_encode($left[$key] ?? null, JSON_PRESERVE_ZERO_FRACTION)
                !== json_encode($right[$key] ?? null, JSON_PRESERVE_ZERO_FRACTION)) {
                $different++;
            }
        }
        return $different / count($keys);
    }
}
