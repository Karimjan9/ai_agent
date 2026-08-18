<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabEvolutionArchiveEntry;
use App\Models\LabEvolutionIsland;
use App\Models\LabGeneration;
use App\Models\LabParentSelectionDecision;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use Illuminate\Support\Collection;

/**
 * Persistent research memory for convergence, diversity, failure and young
 * lineages. Failure entries are explanatory evidence only and are never
 * returned by augmentFrontier().
 */
class EvolutionArchiveService
{
    public const PROTOCOL = 'adaptive_parent_archive_v1';

    public function __construct(
        private StrategySemanticGroupService $semanticGroups,
        private EvolutionGovernorService $governor,
    ) {}

    /**
     * Re-introduce archived candidates only when they still belong to the
     * exact requested semantic cell. Failure archive entries are excluded by
     * construction.
     *
     * @param iterable<ModelVersion> $diagnostic
     */
    public function augmentFrontier(
        iterable $diagnostic,
        string $symbol,
        string $timeframe,
        string $family,
        string $origin,
        ?string $target,
        ?array $niche,
    ): Collection {
        $frontier = collect($diagnostic)
            ->filter(fn ($model): bool => $model instanceof ModelVersion)
            ->values();

        if (! (bool) config('services.lab_selection.adaptive_archive_enabled', true)) return $frontier;

        $allowedTypes = ['convergence', 'diversity'];
        if (in_array($origin, ['architecture', 'curiosity_probe', 'robust_crossover', 'crossover'], true)
            || $target === 'unknown_state_curiosity') {
            $allowedTypes[] = 'young';
        }

        $islandKey = data_get($this->semanticGroups->descriptor($symbol, $timeframe, $family, $niche), 'key');
        $archiveLimit = (int) config('services.lab_selection.archive_max_per_island', 0);
        $entryQuery = LabEvolutionArchiveEntry::with('modelVersion')
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->where('strategy_family', $family)
            ->where('island_key', $islandKey)
            ->whereIn('archive_type', $allowedTypes)
            ->whereIn('status', ['active', 'retained'])
            ->latest('novelty_score');
        if ($archiveLimit > 0) $entryQuery->limit($archiveLimit);
        $entries = $entryQuery->get();

        foreach ($entries as $entry) {
            $model = $entry->modelVersion;
            if (! $model instanceof ModelVersion) continue;
            if (! $this->semanticGroups->exactParentCompatible($model, $symbol, $timeframe, $family, $niche)) continue;
            // Keep the archive projection attached to the in-memory model so
            // the downstream selector can distinguish a validated frontier
            // entry from a young research seed. This attribute is never saved
            // back to model_versions and therefore cannot rewrite evidence.
            $model->setAttribute('_adaptive_archive_type', (string) $entry->archive_type);
            $model->setAttribute('_adaptive_archive_entry_id', (int) $entry->id);
            $frontier->push($model);
        }

        $this->refreshFailureArchive($symbol, $timeframe, $family, $islandKey);

        return $frontier->filter(fn ($model): bool => $model instanceof ModelVersion)->unique('id')->values();
    }

    /**
     * Store current frontier placement and refresh the local island summary.
     * This method records hypotheses, not validation evidence.
     *
     * @param iterable<ModelVersion> $diagnostic
     * @param iterable<ModelVersion> $selected
     */
    public function sync(
        LabGeneration $generation,
        iterable $diagnostic,
        iterable $selected,
        string $symbol,
        string $timeframe,
        string $family,
        string $origin,
        ?string $target,
        ?array $niche,
        array $selection = [],
    ): array {
        if (! (bool) config('services.lab_selection.adaptive_archive_enabled', true)) return [];

        $selectedModels = collect($selected)->filter(fn ($model): bool => $model instanceof ModelVersion)->unique('id')->values();
        $diagnosticModels = collect($diagnostic)->filter(fn ($model): bool => $model instanceof ModelVersion)->unique('id')->values();
        $models = $diagnosticModels->merge($selectedModels)->unique('id')->values();
        $descriptor = $this->semanticGroups->descriptor($symbol, $timeframe, $family, $niche);
        $islandKey = (string) data_get($descriptor, 'key');
        $selectedIds = $selectedModels->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $written = [];

        foreach ($models as $index => $model) {
            $performance = $this->latestPerformance($model, $symbol, $timeframe, $family);
            $quality = $this->convergenceQuality($performance);
            $isSelected = in_array((int) $model->id, $selectedIds, true);
            $archiveType = $quality
                ? 'convergence'
                : ($isSelected && $this->isYoungLane($origin, $target, $performance) ? 'young' : 'diversity');
            $fitness = $this->fitnessSnapshot($performance, $model);
            $signature = $this->behaviorSignature($model, $performance);
            $novelty = $this->noveltyScore($model, $selectedModels);
            $entry = LabEvolutionArchiveEntry::updateOrCreate(
                [
                    'archive_type' => $archiveType,
                    'island_key' => $islandKey,
                    'model_version_id' => $model->id,
                ],
                [
                    'symbol' => strtoupper($symbol),
                    'timeframe' => strtoupper($timeframe),
                    'strategy_family' => $family,
                    'lab_generation_id' => $generation->id,
                    'rank' => $index + 1,
                    'novelty_score' => $novelty,
                    'behavior_signature' => $signature,
                    'fitness_snapshot' => $fitness,
                    'metadata' => [
                        'protocol' => self::PROTOCOL,
                        'origin' => $origin,
                        'target' => $target,
                        'selected_parent' => $isSelected,
                        'semantic_group_key' => $islandKey,
                        'evidence_status' => $model->evidence_status,
                        'parent_eligibility' => [
                            'convergence_quality' => $quality,
                            'archive_type' => $archiveType,
                            'research_seed_only' => $archiveType === 'young',
                            'independent_replay_required' => true,
                        ],
                        'promotion_evidence' => false,
                    ],
                    'status' => 'active',
                ],
            );
            $written[] = $entry->id;
        }

        $this->refreshFailureArchive($symbol, $timeframe, $family, $islandKey);
        $this->updateIsland($generation, $symbol, $timeframe, $family, $islandKey, $selectedModels, $selection);

        return $written;
    }

    public function recordParentSelectionDecision(
        LabGeneration $generation,
        LabAgent $agent,
        string $symbol,
        string $timeframe,
        string $family,
        string $origin,
        ?string $target,
        array $selection,
    ): LabParentSelectionDecision {
        $contract = $this->normalizeParentSelectionContract(
            (array) ($selection['contract'] ?? []),
            (array) ($selection['selected_parent_ids'] ?? []),
        );

        return LabParentSelectionDecision::create([
            'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id,
            'symbol' => strtoupper($symbol),
            'timeframe' => strtoupper($timeframe),
            'strategy_family' => $family,
            'origin' => $origin,
            'target' => $target,
            'island_key' => (string) data_get($contract, 'island_key', 'undeclared'),
            'mode' => (string) data_get($contract, 'mode', 'legacy_frontier_projection'),
            'candidate_count' => (int) data_get($contract, 'candidate_count', 0),
            'selected_count' => (int) data_get($contract, 'selected_count', 0),
            'selected_parent_model_version_ids' => (array) ($selection['selected_parent_ids'] ?? []),
            'candidate_scores' => (array) data_get($contract, 'candidate_scores', []),
            'policy' => $contract,
            'diversity_score' => (float) data_get($contract, 'diversity_score', 0),
            'progress_score' => (float) data_get($contract, 'progress_score', 0),
            'exploration_ratio' => (float) data_get($contract, 'exploration_ratio', 0),
            'promotion_evidence' => false,
        ]);
    }

    /**
     * Keep the parent firewall explainable even when a repair lane bypasses
     * the ordinary frontier selector.  Older repair contracts only stored
     * `repair_anchor_only`, which made the policy correct but left the
     * reason ledger blank.
     *
     * @param array<int, mixed> $selectedParentIds
     * @return array<string, mixed>
     */
    public function normalizeParentSelectionContract(array $contract, array $selectedParentIds = []): array
    {
        $reasons = collect((array) ($contract['parent_selection_reasons'] ?? []))
            ->map(fn (mixed $reason): string => trim((string) $reason))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $status = (string) data_get($contract, 'status', '');
        $selectedCount = max(
            (int) data_get($contract, 'selected_count', 0),
            count(array_filter($selectedParentIds)),
            count((array) data_get($contract, 'selected_parent_model_version_ids', [])),
        );
        $candidateCount = (int) data_get($contract, 'candidate_count', 0);
        $repairOnly = $status === 'repair_anchor_only'
            || (bool) data_get($contract, 'genetic_parent_forbidden', false);

        if ($reasons === []) {
            $reasons = $repairOnly
                ? ['rejected_pending_paired_replay', 'rejected_no_independent_forward']
                : ($selectedCount > 0
                    ? ['eligible']
                    : ($candidateCount <= 0
                        ? ['rejected_no_independent_forward']
                        : ['rejected_parent_passport']));
        }
        if ($repairOnly && ! in_array('rejected_pending_paired_replay', $reasons, true)) {
            $reasons[] = 'rejected_pending_paired_replay';
        }
        if ($repairOnly && ! in_array('rejected_no_independent_forward', $reasons, true)) {
            $reasons[] = 'rejected_no_independent_forward';
        }

        $contract['parent_selection_reason'] = (string) ($contract['parent_selection_reason'] ?? $reasons[0]);
        $contract['parent_selection_reasons'] = array_values(array_unique($reasons));
        $contract['selected_count'] = $selectedCount;
        $contract['candidate_count'] = max($candidateCount, (int) data_get($contract, 'candidate_count', 0));
        $contract['reason_ledger_protocol'] = 'parent_selection_reason_ledger_v1';
        $contract['promotion_evidence'] = false;

        return $contract;
    }

    /**
     * Backfill missing reason fields on historical policy projections. This
     * changes only audit metadata; it never changes selected parents or gate
     * outcomes.
     */
    public function backfillParentSelectionReasons(?int $generationId = null): int
    {
        $query = LabParentSelectionDecision::query();
        if ($generationId !== null) $query->where('lab_generation_id', $generationId);

        $updated = 0;
        $query->orderBy('id')->each(function (LabParentSelectionDecision $decision) use (&$updated): void {
            $policy = $this->normalizeParentSelectionContract(
                (array) $decision->policy,
                (array) $decision->selected_parent_model_version_ids,
            );
            $before = json_encode((array) $decision->policy, JSON_UNESCAPED_SLASHES);
            $after = json_encode($policy, JSON_UNESCAPED_SLASHES);
            if ($before === $after) return;
            $decision->update(['policy' => $policy]);
            $updated++;
        });

        return $updated;
    }

    /**
     * Describe cross-island migration without silently turning a compatible
     * diagnostic into genetic material. Exact-cell candidates may be used by
     * the selector; broader compatible cells are returned as knowledge only.
     * This keeps islands connected while preserving the project's strict
     * semantic-parent invariant.
     *
     * @return array<string, mixed>
     */
    public function migrationPlan(
        string $symbol,
        string $timeframe,
        string $family,
        ?array $niche,
        int $limit = 0,
    ): array {
        $limit = max(0, $limit);
        $target = $this->semanticGroups->descriptor($symbol, $timeframe, $family, $niche);
        $targetKey = (string) data_get($target, 'key');
        $rowQuery = LabEvolutionArchiveEntry::with('modelVersion')
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->where('strategy_family', $family)
            ->where('island_key', '!=', $targetKey)
            ->whereIn('archive_type', ['convergence', 'diversity'])
            ->whereIn('status', ['active', 'retained'])
            ->latest('novelty_score');
        if ($limit > 0) $rowQuery->limit($limit * 4);
        $rows = $rowQuery->get();

        $candidates = [];
        foreach ($rows as $entry) {
            $model = $entry->modelVersion;
            if (! $model instanceof ModelVersion) continue;
            $sourceGroup = $this->semanticGroups->fromModel($model, $family);
            $exact = $this->semanticGroups->exactParentCompatible($model, $symbol, $timeframe, $family, $niche);
            $compatible = $this->semanticGroups->parentCompatible($model, $family, $niche);
            if (! $compatible) continue;
            $candidates[] = [
                'model_version_id' => (int) $model->id,
                'source_island_key' => (string) $entry->island_key,
                'target_island_key' => $targetKey,
                'source_semantic_group_key' => data_get($sourceGroup, 'key'),
                'archive_type' => $entry->archive_type,
                'migration_status' => $exact ? 'genetic_eligible_after_frontier_selection' : 'diagnostic_only',
                'genetic_parent_eligible' => $exact,
                'evidence_status' => data_get($entry->fitness_snapshot, 'evidence_status', $model->evidence_status),
                'novelty_score' => (float) $entry->novelty_score,
                'rule' => $exact
                    ? 'exact semantic cell migration may enter the adaptive frontier'
                    : 'compatible cell is knowledge-only; cross-cell genetic edge is forbidden',
                'promotion_evidence' => false,
            ];
            if ($limit > 0 && count($candidates) >= $limit) break;
        }

        return [
            'protocol' => 'island_migration_contract_v1',
            'source_islands' => collect($candidates)->pluck('source_island_key')->unique()->values()->all(),
            'target_island_key' => $targetKey,
            'candidate_count' => count($candidates),
            'candidates' => $candidates,
            'cross_cell_genetic_edge_forbidden' => true,
            'compatible_cells_are_diagnostic_only' => true,
            'promotion_evidence' => false,
        ];
    }

    private function updateIsland(
        LabGeneration $generation,
        string $symbol,
        string $timeframe,
        string $family,
        string $islandKey,
        Collection $selected,
        array $selection,
    ): void {
        $entries = LabEvolutionArchiveEntry::query()
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->where('strategy_family', $family)
            ->where('island_key', $islandKey)
            ->whereIn('status', ['active', 'retained'])
            ->get();
        $counts = $entries->countBy('archive_type')->all();
        $champion = $entries->where('archive_type', 'convergence')->sortBy('rank')->first();
        $signatures = $entries->pluck('behavior_signature')->filter()->unique()->count();
        $diversity = $entries->isEmpty() ? 1.0 : min(1.0, $signatures / max(1, $entries->count()));
        $snapshot = $this->governor->scopeSnapshot($symbol, $timeframe);

        LabEvolutionIsland::updateOrCreate(
            [
                'symbol' => strtoupper($symbol),
                'timeframe' => strtoupper($timeframe),
                'strategy_family' => $family,
                'island_key' => $islandKey,
            ],
            [
                'local_champion_model_version_id' => $champion?->model_version_id,
                'archive_counts' => $counts,
                'diversity_score' => round($diversity, 4),
                'progress_score' => (float) data_get($selection, 'contract.progress_score', data_get($snapshot, 'progress_score', .5)),
                'stagnation_generations' => (int) data_get($selection, 'contract.stagnation_generations', data_get($snapshot, 'stagnation_generations', 0)),
                'status' => 'active',
                'metadata' => [
                    'protocol' => self::PROTOCOL,
                    'last_generation_id' => $generation->id,
                    'selected_parent_model_version_ids' => $selected->pluck('id')->values()->all(),
                    'last_migration_plan' => data_get($selection, 'contract.island_migration', []),
                    'promotion_evidence' => false,
                ],
            ],
        );
    }

    private function refreshFailureArchive(string $symbol, string $timeframe, string $family, string $islandKey): void
    {
        $limit = max(0, (int) config('services.lab_selection.archive_failure_limit', 0));
        $agentQuery = LabAgent::with('modelVersion')
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->where('strategy_family', $family)
            ->whereIn('lifecycle_status', [
                'rejected', 'failed', 'overfit', 'archived', 'stagnated', 'technical_quarantine', 'abandoned',
            ])
            ->latest('id');
        // Failure evidence is a safety memory: a failed lineage must not
        // remain active in a convergence/diversity archive merely because it
        // fell outside an arbitrary "latest N" window. A positive value is
        // available only as an explicit operational backfill budget.
        if ($limit > 0) $agentQuery->limit($limit);
        $agents = $agentQuery->get();

        foreach ($agents as $agent) {
            if (! $agent->modelVersion) continue;
            // Once a lineage has a durable failure outcome, any earlier
            // convergence/diversity/young projection is retired so the same
            // model cannot sneak back through a non-failure archive type.
            LabEvolutionArchiveEntry::query()
                ->where('island_key', $islandKey)
                ->where('model_version_id', $agent->model_version_id)
                ->where('archive_type', '!=', 'failure')
                ->whereIn('status', ['active', 'retained'])
                ->update(['status' => 'retired']);
            LabEvolutionArchiveEntry::updateOrCreate(
                [
                    'archive_type' => 'failure',
                    'island_key' => $islandKey,
                    'model_version_id' => $agent->model_version_id,
                ],
                [
                    'symbol' => strtoupper($symbol),
                    'timeframe' => strtoupper($timeframe),
                    'strategy_family' => $family,
                    'lab_agent_id' => $agent->id,
                    'lab_generation_id' => $agent->lab_generation_id,
                    'rank' => 0,
                    'novelty_score' => 0,
                    'behavior_signature' => $this->behaviorSignature($agent->modelVersion, null),
                    'fitness_snapshot' => [
                        'train_score' => $agent->train_score,
                        'validation_score' => $agent->validation_score,
                        'forward_score' => $agent->forward_score,
                        'profit_factor' => $agent->profit_factor,
                        'max_drawdown' => $agent->max_drawdown,
                        'risk_of_ruin' => $agent->risk_of_ruin,
                    ],
                    'metadata' => [
                        'protocol' => self::PROTOCOL,
                        'failure_status' => $agent->lifecycle_status,
                        'decision_reason' => $agent->decision_reason,
                        'failure_evidence_only' => true,
                        'promotion_evidence' => false,
                    ],
                    'status' => 'retained',
                ],
            );
        }
    }

    private function latestPerformance(ModelVersion $model, string $symbol, string $timeframe, string $family): ?ModelMarketPerformance
    {
        return $model->marketPerformances()
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->where('strategy_family', $family)
            ->latest('id')
            ->first();
    }

    private function convergenceQuality(?ModelMarketPerformance $performance): bool
    {
        if (! $performance || $performance->evidence_status !== 'valid') return false;
        $metrics = (array) ($performance->metrics ?? []);
        $edge = (array) data_get($metrics, 'statistical_evidence.edge_quality', []);
        $bootstrap = (array) data_get($edge, 'bootstrap_pf', []);
        $pbo = data_get($metrics, 'selection_validation.probability_of_backtest_overfitting',
            data_get($metrics, 'statistical_evidence.selection_validation.probability_of_backtest_overfitting'));
        $dsr = data_get($metrics, 'statistical_evidence.deflated_sharpe.deflated_sharpe_probability');
        $bootstrapPasses = data_get($bootstrap, 'status') !== 'assessed'
            || (float) data_get($bootstrap, 'pf_5_percentile_lower_bound', 0) >= 1.1;
        $pboPasses = $pbo === null || (float) $pbo <= .50;
        $dsrPasses = $dsr === null || (float) $dsr >= .95;
        $regimePasses = ! (bool) data_get($edge, 'worst_regime_sampled', false)
            || (float) data_get($edge, 'worst_regime_pf', 0) >= 1.0;
        $behaviorPasses = data_get($metrics, 'behavioral_diversity.status') !== 'near_duplicate';
        return in_array((string) $performance->status, ['champion', 'challenger', 'forward_validated', 'paper'], true)
            && (float) data_get($metrics, 'profit_factor', 0) >= 1.3
            && (float) data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown', 100)) <= 15
            && (float) data_get($metrics, 'monte_carlo.risk_of_ruin_percent', 100) <= 10
            && ! (bool) data_get($metrics, 'is_overfit', true)
            && (int) $performance->sample_count >= 30
            && (int) $performance->rolling_windows_count >= 3
            && (int) $performance->rolling_forward_wins >= 3
            && $bootstrapPasses && $pboPasses && $dsrPasses && $regimePasses && $behaviorPasses;
    }

    private function isYoungLane(string $origin, ?string $target, ?ModelMarketPerformance $performance): bool
    {
        return $performance === null
            && (in_array($origin, ['architecture', 'curiosity_probe', 'robust_crossover', 'crossover'], true)
                || $target === 'unknown_state_curiosity');
    }

    private function fitnessSnapshot(?ModelMarketPerformance $performance, ModelVersion $model): array
    {
        $metrics = (array) ($performance?->metrics ?? []);
        return [
            'model_best_score' => $model->best_score,
            'forward_score' => $performance?->forward_score,
            'profit_factor' => data_get($metrics, 'profit_factor'),
            'max_drawdown_percent' => data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown')),
            'risk_of_ruin_percent' => data_get($metrics, 'monte_carlo.risk_of_ruin_percent'),
            'sample_count' => $performance?->sample_count,
            'evidence_status' => $performance?->evidence_status ?: $model->evidence_status,
            'promotion_evidence' => false,
        ];
    }

    private function behaviorSignature(ModelVersion $model, ?ModelMarketPerformance $performance): string
    {
        $stored = data_get($model->metadata, 'behavior_signature', data_get($performance?->metrics, 'behavior_signature'));
        if (filled($stored)) return substr((string) $stored, 0, 128);
        $parameters = (array) ($model->parameters ?? []);
        ksort($parameters);
        return hash('sha256', json_encode($parameters, JSON_PRESERVE_ZERO_FRACTION));
    }

    private function noveltyScore(ModelVersion $model, Collection $selected): float
    {
        if ($selected->isEmpty()) return 1.0;
        $parameters = (array) ($model->parameters ?? []);
        $distances = $selected->reject(fn (ModelVersion $candidate): bool => (int) $candidate->id === (int) $model->id)
            ->map(fn (ModelVersion $candidate): float => $this->parameterDistance($parameters, (array) $candidate->parameters));
        return round($distances->isEmpty() ? 0.0 : (float) $distances->max(), 4);
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
