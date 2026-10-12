<?php

namespace App\Services;

use App\Models\AgentDiagnosis;
use App\Models\AgentFailureCase;
use App\Models\AgentKnowledgeCard;
use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningMutationIntent;
use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\Candle;
use App\Models\EliteAgentPortfolio;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\MutationMemory;
use App\Models\Symbol;
use App\Services\MarketData\HistoricalDataQualityService;
use App\Services\MarketData\MarketDataContinuityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Internal method group composed exclusively into LabPopulationService. */
trait LabPopulationLineageTrait
{
    private function qualityParents(string $symbol, string $timeframe, string $family, ?string $target = null, ?array $niche = null)
    {
        return $this->parentPerformanceSnapshot($symbol, $timeframe, $family, 'valid')
            ->filter(fn (ModelMarketPerformance $performance) => $this->parentEligible($performance))
            ->filter(fn (ModelMarketPerformance $performance): bool => $performance->modelVersion !== null
                && $this->semanticGroups->parentCompatible($performance->modelVersion, $family, $niche))
            // A raw PF/forward winner is not automatically the best repair
            // parent. Keep the general edge in the score, but let the active
            // target lane prefer the candidate that is already closest to
            // closing that lane's gate.
            ->sortByDesc(fn (ModelMarketPerformance $performance) => $this->parentQualityScore($performance, $target, $niche))
            ->pluck('modelVersion')
            ->filter()
            ->values();
    }

    /** @return array<int, string> */
    private function lineageContinuationFamilies(AiLaboratory $lab): array
    {
        return ModelMarketPerformance::with('modelVersion')
            ->where('symbol', $lab->symbol)
            ->where('timeframe', $lab->timeframe)
            ->where('evidence_status', 'valid')
            ->whereIn('status', ['champion', 'challenger', 'forward_validated', 'paper'])
            ->whereHas('modelVersion', fn ($query) => $query->where('evidence_status', 'valid'))
            ->get()
            ->filter(fn (ModelMarketPerformance $performance): bool => $this->parentEligible($performance))
            ->sortByDesc(fn (ModelMarketPerformance $performance): array => [
                (float) $performance->forward_score,
                (int) $performance->sample_count,
                (int) $performance->id,
            ])
            ->pluck('strategy_family')
            ->filter(fn ($family): bool => filled($family))
            ->unique()->values()->all();
    }

    /**
     * Convert a loose/diagnostic frontier into a genetic frontier.
     *
     * This boundary is deliberately kept at the last moment before parent A
     * is selected.  It protects older reports and selectors that still need
     * to see a legacy hypothesis while making it impossible for that record
     * to leak into `base` or `parent_a_model_version_id`.
     */
    private function strictSemanticParents(
        $parents,
        string $symbol,
        string $timeframe,
        string $family,
        ?array $niche = null,
    ) {
        $eligible = collect($parents)
            ->filter(fn (ModelVersion $parent): bool => $this->semanticGroups->exactParentCompatible(
                $parent,
                $symbol,
                $timeframe,
                $family,
                $niche,
            ))
            ->values();

        // Keep the exact-cell boundary, but retain a bounded same-cell
        // frontier. The local champion anchors convergence; diverse
        // capability parents remain available to robust crossover.
        $frontierCaps = array_values(array_filter([
            (int) config('services.lab_selection.semantic_cell_parent_frontier', 0),
            (int) config('services.lab_selection.parent_candidate_frontier', 0),
        ], static fn (int $limit): bool => $limit > 0));
        // Zero means the complete exact-cell frontier. Positive values are
        // explicit infrastructure caps, never biological/evidence rules.
        $frontierLimit = $frontierCaps === [] ? 0 : max($frontierCaps);

        return app(SemanticEliteArchiveService::class)->frontierPerCell(
            $eligible,
            $frontierLimit,
            fn (ModelVersion $parent): float => (float) ($parent->best_score ?? 0),
        );
    }

    /**
     * Screening-only seed frontier. These models have enough reproducible
     * edge to be useful as mutation anchors, but intentionally do not satisfy
     * the stricter parentEligible() passport. The metadata on every child
     * makes this distinction auditable and the normal gate still decides all
     * later promotion stages.
     */
    private function screeningSeedParents(string $symbol, string $timeframe, string $family, ?string $target = null, ?array $niche = null)
    {
        $valid = $this->parentPerformanceSnapshot($symbol, $timeframe, $family, 'valid')
            ->filter(function (ModelMarketPerformance $performance): bool {
                $metrics = (array) ($performance->metrics ?? []);

                return (float) data_get($metrics, 'profit_factor', 0) >= 1.25
                    && (int) $performance->sample_count >= 25
                    && (float) data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown', 100)) <= 15
                    && (float) data_get($metrics, 'monte_carlo.risk_of_ruin_percent', 100) <= 10
                    && ! (bool) data_get($metrics, 'is_overfit', true);
            })
            ->filter(fn (ModelMarketPerformance $performance): bool => $performance->modelVersion !== null
                && $this->semanticGroups->parentCompatible($performance->modelVersion, $family, $niche))
            ->sortByDesc(fn (ModelMarketPerformance $performance) => $this->parentQualityScore($performance, $target, $niche))
            ->pluck('modelVersion')
            ->filter()
            ->values();

        if ($valid->isNotEmpty()) {
            return $valid;
        }

        // Some early runs used a non-canonical historical slice and are
        // explicitly marked legacy_invalid. They may re-enter only as a
        // hypothesis seed when no canonical valid frontier exists; their old
        // score is never copied into a gate, forward ledger, or paper state.
        return $this->parentPerformanceSnapshot($symbol, $timeframe, $family, 'legacy_invalid')
            ->filter(function (ModelMarketPerformance $performance): bool {
                $metrics = (array) ($performance->metrics ?? []);

                return (float) data_get($metrics, 'profit_factor', 0) >= 1.25
                    && (int) $performance->sample_count >= 25
                    && (float) data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown', 100)) <= 15
                    && (float) data_get($metrics, 'monte_carlo.risk_of_ruin_percent', 100) <= 10
                    && ! (bool) data_get($metrics, 'is_overfit', true);
            })
            ->filter(fn (ModelMarketPerformance $performance): bool => $performance->modelVersion !== null
                && $this->semanticGroups->parentCompatible($performance->modelVersion, $family, $niche))
            ->sortByDesc(fn (ModelMarketPerformance $performance) => $this->parentQualityScore($performance, $target, $niche))
            ->pluck('modelVersion')
            ->filter()
            ->values();
    }

    /**
     * The same parent frontier is consulted several times for every one of the
     * twenty seats. Model metadata contains immutable replay provenance and can
     * be hundreds of MB per family, so hydrate it once for this build rather
     * than once per candidate and replacement attempt.
     */
    private function parentPerformanceSnapshot(
        string $symbol,
        string $timeframe,
        string $family,
        string $evidenceStatus,
    ) {
        $cacheKey = implode('|', [
            strtoupper($symbol),
            strtoupper($timeframe),
            $family,
            $evidenceStatus,
        ]);

        // Do not eager-load multi-megabyte model metadata for every coarse
        // performance candidate. parentEligible() rejects on cheap immutable
        // performance facts first and lazy-loads a model only for survivors.
        return $this->parentPerformanceSnapshots[$cacheKey] ??= ModelMarketPerformance::query()
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->where('evidence_status', $evidenceStatus)
            ->whereHas('modelVersion', fn ($query) => $query->where('evidence_status', $evidenceStatus))
            ->where('strategy_family', $family)
            ->whereIn('status', $evidenceStatus === 'valid'
                ? ['champion', 'challenger', 'forward_validated', 'paper']
                : ['challenger', 'paper', 'archived'])
            ->get();
    }

    /**
     * Resolve a parameter baseline only when it is a prior-generation root in
     * the exact semantic cell.  This is intentionally independent of PF and
     * lifecycle promotion thresholds: it supplies a frozen research vector,
     * never a genetic parent, champion, or paper shortcut.
     */
    private function frozenResearchSeed(LabGeneration $generation, string $family, ?array $niche): ?LabAgent
    {
        $lab = $generation->laboratory;

        return LabAgent::query()
            ->with(['modelVersion', 'generation'])
            ->where('symbol', $lab->symbol)
            ->where('timeframe', $lab->timeframe)
            ->where('strategy_family', $family)
            ->whereHas('generation', fn ($query) => $query->where('generation', '<', $generation->generation))
            ->latest('id')
            ->take(500)
            ->get()
            ->first(function (LabAgent $seed) use ($lab, $family, $niche): bool {
                $model = $seed->modelVersion;
                if (! $model || (array) $model->parameters === []) {
                    return false;
                }
                if (in_array((string) $seed->lifecycle_status, ['technical_quarantine', 'quarantined', 'evaluation_error'], true)) {
                    return false;
                }
                if (in_array((string) $model->evidence_status, ['legacy_invalid', 'stale_quarantine'], true) || $model->invalidated_at !== null) {
                    return false;
                }
                if ((string) ($seed->generation?->data_fingerprint ?? '') === '') {
                    return false;
                }

                // A research checkpoint need not be parentless. Restricting
                // this lookup to roots made a healthy exact-cell lineage
                // disappear after its first child and pushed later cohorts
                // back to schema defaults. This remains parameter-baseline
                // context only: no genetic link, score, promotion status or
                // gate result is inherited from the seed.
                return $this->semanticGroups->exactParentCompatible(
                    $model,
                    $lab->symbol,
                    $lab->timeframe,
                    $family,
                    $niche,
                );
            });
    }

    private function parentEligible(ModelMarketPerformance $performance): bool
    {
        $metrics = $performance->metrics ?? [];
        $bootstrap = data_get($metrics, 'statistical_evidence.edge_quality.bootstrap_pf', []);
        $bootstrapPasses = data_get($bootstrap, 'status') !== 'assessed'
            || (float) data_get($bootstrap, 'pf_5_percentile_lower_bound', 0) >= 1.1;
        $worstRegime = data_get($metrics, 'statistical_evidence.edge_quality');
        $regimePasses = ! data_get($worstRegime, 'worst_regime_sampled', false)
            || (float) data_get($worstRegime, 'worst_regime_pf', 0) >= 1.0;

        // Reject on compact performance facts before hydrating a 10MB+
        // model metadata document. This is the same eligibility predicate in
        // a cheaper order; no parent, gate or threshold receives new rights.
        $performanceEligible = in_array((string) $performance->status, ['champion', 'challenger', 'forward_validated', 'paper'], true)
            && (float) data_get($metrics, 'profit_factor', 0) >= 1.3
            && (float) data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown', 100)) <= 15
            && (float) data_get($metrics, 'monte_carlo.risk_of_ruin_percent', 100) <= 10
            && ! (bool) data_get($metrics, 'is_overfit', true)
            && (int) $performance->sample_count >= 30
            && (int) $performance->rolling_windows_count >= 3
            && (int) $performance->rolling_forward_wins >= 3
            && $bootstrapPasses && $regimePasses
            && data_get($metrics, 'behavioral_diversity.status') !== 'near_duplicate';
        if (! $performanceEligible) {
            return false;
        }

        $performance->loadMissing('modelVersion');
        if ((data_get($performance->modelVersion?->metadata, 'shadow_research_lane.shadow_only', false) === true
            || data_get($performance->modelVersion?->metadata, 'shadow_research_lane.protocol') === ShadowResearchGovernorService::PROTOCOL)
            && data_get($performance->modelVersion?->metadata, 'shadow_research_lane.requalified', false) !== true) {
            return false;
        }
        $repairAnchor = (array) data_get($performance->modelVersion?->metadata, 'repair_anchor', []);
        if ($repairAnchor !== [] && data_get($repairAnchor, 'parent_eligible_after_confirmation') !== true) {
            return false;
        }
        $evolutionStage = (string) data_get($performance->modelVersion?->metadata, 'evolution_stage.stage', '');
        $mentorDeclared = in_array($evolutionStage, ['screen_validated_seed', 'skill_mentor', 'screen_validated_control', 'repair_anchor', 'repair_anchor_control'], true)
            || data_get($performance->modelVersion?->metadata, 'skill_mentor.status') === 'confirmed';
        $authorityEligible = data_get(
            app(EvolutionaryAuthorityFoundryService::class)->authorityFor($performance->modelVersion),
            'stage',
        ) === 'eligible_parent';
        if ($mentorDeclared && ! $authorityEligible) {
            return false;
        }

        // This method feeds the diagnostic/quality frontier. The final
        // genetic-parent passport, including independent-forward and paired
        // replay evidence, is enforced by AdaptiveParentFrontierService
        // immediately before contributors are selected. Keeping those
        // lifecycle gates out of this coarse frontier preserves valid
        // challenger evidence for lane ranking and audit reports.
        return true;
    }

    private function parentQualityScore(ModelMarketPerformance $performance, ?string $target = null, ?array $niche = null): float
    {
        $metrics = $performance->metrics ?? [];
        $progress = $this->parentProgressSnapshot($performance, $target, $niche);
        $statusBonus = match ((string) $performance->status) {
            'champion' => 8.0,
            'forward_validated' => 6.0,
            'challenger' => 4.0,
            'paper' => 3.0,
            default => 0.0,
        };

        return ((float) $performance->forward_score * 2)
            + ((float) data_get($metrics, 'profit_factor', 0) * 25)
            - ((float) data_get($metrics, 'monte_carlo.risk_of_ruin_percent', 100) * 2)
            - (float) data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown', 100))
            + (float) data_get($progress, 'selection_score', 0)
            + $statusBonus;
    }

    /**
     * Rank a parent by progress toward the current repair lane, not just by
     * its headline profit. This is the evolutionary frontier used before the
     * child compiler chooses a one-gene mutation.
     *
     * The score is deliberately a tie-breaker/quality bonus on top of the
     * hard parentEligible() passport. It can never make a rejected, overfit,
     * low-sample or high-ruin model a parent.
     */
    private function parentProgressSnapshot(ModelMarketPerformance $performance, ?string $target = null, ?array $niche = null): array
    {
        $metrics = (array) ($performance->metrics ?? []);
        $monthly = $this->monthlyProgressScore($metrics);
        $regime = $this->regimeProgressScore($metrics);
        $recall = $this->recallProgressScore($metrics);
        $stress = $this->stressProgressScore($metrics);
        $drawdown = $this->drawdownProgressScore($metrics);
        $forward = $this->forwardQuorumProgressScore($performance);
        $elite = $this->eliteProgressScore($metrics);
        $architecture = $this->architectureProgressScore($metrics);
        $gateScores = [
            'monthly_survival' => $monthly,
            'regime_coverage' => $regime,
            'opportunity_recall' => $recall,
            'stress_cost' => $stress,
            'drawdown' => $drawdown,
            'forward_quorum' => $forward,
            'elite_passport' => $elite,
            'architecture' => $architecture,
        ];
        $lane = $this->progressLaneForTarget($target);
        $targetScore = (float) ($gateScores[$lane] ?? $this->genericProgressScore($gateScores));
        $contextScore = $this->contextProgressScore($metrics, $niche);
        $genericScore = $this->genericProgressScore($gateScores);
        $observed = collect($gateScores)->filter(fn (float $score): bool => $score > 0)->keys()->values()->all();
        $passed = collect($gateScores)->filter(fn (float $score): bool => $score >= 70)->keys()->values()->all();
        $selectionScore = min(60.0, round(
            ($targetScore * .55) + ($contextScore * .20) + ($genericScore * .15) + ($forward * .10),
            2,
        ));

        return [
            'protocol' => 'progress_frontier_snapshot_v1',
            'target' => $target,
            'lane' => $lane,
            'target_progress_score' => round($targetScore, 2),
            'context_progress_score' => round($contextScore, 2),
            'generic_gate_score' => round($genericScore, 2),
            'forward_quorum_score' => round($forward, 2),
            'selection_score' => $selectionScore,
            'gate_scores' => array_map(fn (float $score): float => round($score, 2), $gateScores),
            'observed_gates' => $observed,
            'passed_gates' => $passed,
            'promotion_evidence' => false,
        ];
    }

    private function progressLaneForTarget(?string $target): string
    {
        return match ($target) {
            'monthly_survival', 'temporal_stability' => 'monthly_survival',
            'regime_coverage', 'rolling_regime', 'portfolio_router' => 'regime_coverage',
            'opportunity_recall', 'trade_frequency' => 'opportunity_recall',
            'volatility_session_stability', 'exit_topology', 'stress_cost', 'profit_factor', 'risk_exit', 'transition_firewall' => 'stress_cost',
            'drawdown_risk', 'shadow_veto_loss_cooldown', 'shadow_veto_volatility' => 'drawdown',
            'architecture', 'robustness' => 'architecture',
            default => 'forward_quorum',
        };
    }

    private function monthlyProgressScore(array $metrics): float
    {
        $passport = (array) data_get($metrics, 'monthly_passport', []);
        if ($passport === []) {
            return 0.0;
        }
        $wins = (int) data_get($passport, 'rolling_forward_wins', 0);
        $failedMonths = (int) data_get($passport, 'failed_months', 0);
        $winsScore = min(60.0, ($wins / 3) * 60);
        $stabilityScore = $failedMonths === 0 ? 40.0 : max(0.0, 40.0 - ($failedMonths * 20.0));
        $worstMonthPf = data_get($passport, 'worst_month_pf', data_get($passport, 'worst_month.profit_factor'));
        if (is_numeric($worstMonthPf)) {
            $stabilityScore = min($stabilityScore, max(0.0, (float) $worstMonthPf / 1.05 * 40));
        }

        return min(100.0, $winsScore + $stabilityScore);
    }

    private function regimeProgressScore(array $metrics): float
    {
        $edge = (array) data_get($metrics, 'statistical_evidence.edge_quality', []);
        $rows = (array) data_get($metrics, 'regime_performance', []);
        $sampled = collect($rows)->filter(fn ($row): bool => (int) data_get($row, 'trades', 0) > 0)->count();
        $coverageScore = min(40.0, ($sampled / 3) * 40);
        $worstPf = data_get($edge, 'worst_regime_pf');
        if (! is_numeric($worstPf)) {
            $pfValues = collect($rows)->map(fn ($row) => data_get($row, 'net_pf', data_get($row, 'profit_factor')))
                ->filter(fn ($pf): bool => is_numeric($pf))->map(fn ($pf): float => (float) $pf);
            $worstPf = $pfValues->isNotEmpty() ? $pfValues->min() : null;
        }
        $pfScore = is_numeric($worstPf) ? min(60.0, max(0.0, ((float) $worstPf / 1.05) * 60)) : 0.0;

        return min(100.0, $coverageScore + $pfScore);
    }

    private function recallProgressScore(array $metrics): float
    {
        $recall = (array) data_get($metrics, 'opportunity_recall', []);
        if ($recall === []) {
            return 0.0;
        }
        $recallScore = min(70.0, max(0.0, ((float) data_get($recall, 'opportunity_recall', 0) / .20) * 70));
        $precisionScore = min(30.0, max(0.0, ((float) data_get($recall, 'abstention_precision', 0) / .50) * 30));

        return min(100.0, $recallScore + $precisionScore);
    }

    private function stressProgressScore(array $metrics): float
    {
        $pf = data_get($metrics, 'pf_attribution.stress_cost.profit_factor', data_get($metrics, 'stress_test.profit_factor'));
        if (! is_numeric($pf)) {
            return 0.0;
        }
        $score = min(80.0, max(0.0, ((float) $pf / 1.05) * 80));
        $digitalTwin = data_get($metrics, 'execution_digital_twin');
        if (is_array($digitalTwin) && data_get($digitalTwin, 'status') === 'assessed') {
            $score += data_get($digitalTwin, 'pass') ? 20.0 : 0.0;
        } elseif ((array) $digitalTwin === []) {
            $score += 20.0;
        }

        return min(100.0, $score);
    }

    private function drawdownProgressScore(array $metrics): float
    {
        $drawdown = data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown'));
        $ruin = data_get($metrics, 'monte_carlo.risk_of_ruin_percent');
        if (! is_numeric($drawdown) && ! is_numeric($ruin)) {
            return 0.0;
        }
        $ddScore = is_numeric($drawdown) ? max(0.0, min(60.0, (1 - ((float) $drawdown / 15)) * 60)) : 0.0;
        $ruinScore = is_numeric($ruin) ? max(0.0, min(40.0, (1 - ((float) $ruin / 10)) * 40)) : 0.0;

        return min(100.0, $ddScore + $ruinScore);
    }

    private function forwardQuorumProgressScore(ModelMarketPerformance $performance): float
    {
        $windows = (int) $performance->rolling_windows_count;
        $wins = (int) $performance->rolling_forward_wins;
        if ($windows === 0 && $wins === 0) {
            return 0.0;
        }

        return min(100.0, min(($windows / 3) * 100, ($wins / 3) * 100));
    }

    private function eliteProgressScore(array $metrics): float
    {
        if (data_get($metrics, 'elite_agent_passport.status') === 'passed') {
            return 100.0;
        }
        if (data_get($metrics, 'elite_agent_passport.elite_quorum.status') === 'passed') {
            return 85.0;
        }
        $reasons = (array) data_get($metrics, 'elite_agent_passport.reason_codes', []);

        return $reasons === [] ? 0.0 : max(0.0, 100.0 - (count($reasons) * 12.5));
    }

    private function architectureProgressScore(array $metrics): float
    {
        $scores = [];
        if (data_get($metrics, 'parameter_plateau.status') === 'assessed') {
            $scores[] = data_get($metrics, 'parameter_plateau.pass') ? 100.0 : 0.0;
        }
        if (data_get($metrics, 'paired_replay.status', data_get($metrics, 'paired_experiment.status')) !== null) {
            $scores[] = data_get($metrics, 'paired_replay.status', data_get($metrics, 'paired_experiment.status')) === 'confirmed' ? 100.0 : 0.0;
        }
        if (data_get($metrics, 'no_regression_contract.status') !== null) {
            $scores[] = data_get($metrics, 'no_regression_contract.status') === 'passed' ? 100.0 : 0.0;
        }

        return $scores === [] ? 0.0 : (float) collect($scores)->avg();
    }

    private function genericProgressScore(array $gateScores): float
    {
        $observed = array_values(array_filter($gateScores, fn (float $score): bool => $score > 0));

        return $observed === [] ? 0.0 : (float) collect($observed)->avg();
    }

    private function contextProgressScore(array $metrics, ?array $niche): float
    {
        $contexts = [];
        foreach ([
            ['key' => 'regime_performance', 'value' => data_get($niche, 'regime')],
            ['key' => 'volatility_performance', 'value' => data_get($niche, 'volatility')],
        ] as $context) {
            if (! is_string($context['value']) || $context['value'] === '') {
                continue;
            }
            $row = (array) data_get($metrics, $context['key'].'.'.$context['value'], []);
            if ($row === []) {
                continue;
            }
            $pf = data_get($row, 'net_pf', data_get($row, 'profit_factor'));
            $trades = (int) data_get($row, 'trades', data_get($row, 'total_trades', 0));
            if (! is_numeric($pf)) {
                continue;
            }
            $contexts[] = min(100.0, max(0.0, ((float) $pf / 1.05) * 75) + min(25.0, ($trades / 20) * 25));
        }

        return $contexts === [] ? 0.0 : (float) collect($contexts)->avg();
    }

    /** Carry confirmed traits forward without turning them into promotion evidence. */
    private function progressiveInheritanceContract(
        ?ModelVersion $parent,
        ?ModelMarketPerformance $parentPerformance,
        string $family,
        string $target,
        ?array $niche,
        array $base,
        array $parameters,
        string $parentTier,
        string $parentSelection,
        array $semanticGroup,
        array $contributors = [],
    ): array {
        $schema = $this->schemas->schema($family);
        $parentModels = collect([$parent, ...$contributors])
            ->filter(fn ($model): bool => $model instanceof ModelVersion)
            ->unique('id')
            ->values();
        $parentParameters = (array) ($parent?->parameters ?? []);
        $inheritedKeys = $parentModels
            ->flatMap(fn (ModelVersion $model): array => array_keys((array) $model->parameters))
            ->filter(fn (string $key): bool => array_key_exists($key, $schema))
            ->unique()->values()->all();
        $changedKeys = array_keys($this->diff($base, $parameters));
        $parentLineage = (array) data_get($parent?->metadata, 'progressive_inheritance', []);
        $repairLineage = (array) data_get($parent?->metadata, 'repair_lineage', []);
        $rootId = $parent
            ? (int) data_get($parentLineage, 'root_model_version_id', data_get($repairLineage, 'root_model_version_id', $parent->id))
            : null;
        $traitsByParent = $parentModels->mapWithKeys(fn (ModelVersion $model): array => [
            (string) $model->id => $this->confirmedParentTraits($model),
        ])->all();
        $traits = collect([
            ...(array) data_get($parentLineage, 'confirmed_beneficial_traits', []),
            ...collect($traitsByParent)->flatMap(fn (array $parentTraits, string $parentId) => collect($parentTraits)
                ->map(fn (array $trait): array => [
                    ...$trait,
                    // The source id is retained beside the legacy trait
                    // shape so a multi-parent child can audit which parent
                    // supplied a beneficial prior.
                    'source_parent_model_version_id' => (int) $parentId,
                ]))->all(),
        ])->filter(fn ($trait): bool => is_array($trait) && filled(data_get($trait, 'parameter_key')))
            ->unique(fn (array $trait): string => (string) data_get($trait, 'parameter_key').'|'.json_encode(data_get($trait, 'new_value')));
        $traitLimit = (int) config('services.lab_selection.confirmed_parent_traits_limit', 0);
        if ($traitLimit > 0) {
            $traits = $traits->take($traitLimit);
        }
        $traits = $traits->values()->all();
        $progress = $parentPerformance
            ? $this->parentProgressSnapshot($parentPerformance, $target, $niche)
            : [
                'protocol' => 'progress_frontier_snapshot_v1', 'target' => $target,
                'lane' => $this->progressLaneForTarget($target), 'selection_score' => 0,
                'target_progress_score' => 0, 'context_progress_score' => 0,
                'generic_gate_score' => 0, 'forward_quorum_score' => 0,
                'gate_scores' => [], 'observed_gates' => [], 'passed_gates' => [],
                'promotion_evidence' => false,
            ];
        $parentGroup = $parent
            ? $this->semanticGroups->fromModel($parent, (string) data_get($parentPerformance, 'strategy_family', $family))
            : null;

        return [
            'protocol' => 'progressive_frontier_inheritance_v1',
            'status' => $parent ? 'inherited_from_frontier' : 'first_generation_seed',
            'parent_model_version_id' => $parent?->id,
            'parent_model_version_ids' => $parentModels->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'parent_performance_id' => $parentPerformance?->id,
            'root_model_version_id' => $rootId,
            'lineage_depth' => $parent ? ((int) data_get($parentLineage, 'lineage_depth', 0) + 1) : 0,
            'target' => $target,
            'target_context' => $niche ? [
                'regime' => data_get($niche, 'regime'),
                'volatility' => data_get($niche, 'volatility'),
                'direction' => data_get($niche, 'direction'),
                'state_cluster' => data_get($niche, 'state_cluster'),
            ] : null,
            'semantic_group' => $semanticGroup,
            'parent_semantic_group' => $parentGroup,
            'same_semantic_group' => $parent
                ? $this->semanticGroups->exactParentCompatible(
                    $parent,
                    (string) data_get($semanticGroup, 'symbol', '*'),
                    (string) data_get($semanticGroup, 'timeframe', '*'),
                    $family,
                    $niche,
                )
                : false,
            'parent_tier' => $parentTier,
            'parent_selection' => $parentSelection,
            'inherited_parameter_keys' => $inheritedKeys,
            'inherited_parameter_count' => count($inheritedKeys),
            'changed_parameter_keys' => $changedKeys,
            'preserved_parameter_count' => count(array_diff($inheritedKeys, $changedKeys)),
            'confirmed_beneficial_traits' => $traits,
            'confirmed_beneficial_traits_by_parent' => $traitsByParent,
            'parent_progress' => $progress,
            'reset_reason' => $parent ? null : 'no_parent_available',
            'promotion_evidence' => false,
            'rule' => 'Carry only the exact semantic parent parameters and independently confirmed traits; otherwise start from the group root/default seed. Change only the declared bounded experiment and re-earn every gate.',
        ];
    }

    private function confirmedParentTraits(?ModelVersion $parent): array
    {
        if (! $parent) {
            return [];
        }
        $agentIds = LabAgent::query()->where('model_version_id', $parent->id)->pluck('id')->all();
        if ($agentIds === []) {
            return [];
        }
        $query = MutationMemory::query()
            ->whereIn('lab_agent_id', $agentIds)
            ->where('outcome', 'beneficial')
            ->where('independent_confirmation_count', '>=', 2)
            ->where('non_target_regression_status', 'passed')
            ->latest('updated_at');
        $traitLimit = (int) config('services.lab_selection.confirmed_parent_traits_limit', 0);
        if ($traitLimit > 0) {
            $query->take($traitLimit);
        }

        return $query->get()
            ->map(fn (MutationMemory $memory): array => [
                'parameter_key' => $memory->parameter_key,
                'old_value' => data_get($memory->old_value, 'value'),
                'new_value' => data_get($memory->new_value, 'value'),
                'forward_delta' => (float) $memory->forward_delta,
                'market_regime' => $memory->market_regime,
                'confidence' => (float) $memory->confidence,
                'independent_confirmation_count' => (int) $memory->independent_confirmation_count,
                'promotion_evidence' => false,
            ])->values()->all();
    }

    private function mutationScope(string $symbol, string $timeframe, string $family, int $seed): string
    {
        $scopes = [];
        $performanceQuery = ModelMarketPerformance::query()
            ->where(compact('symbol', 'timeframe'))
            ->where('strategy_family', $family)
            ->where('evidence_status', 'valid')
            ->latest('updated_at');
        $sourceLimit = (int) config('services.lab_selection.mutation_scope_source_limit', 0);
        if ($sourceLimit > 0) {
            $performanceQuery->take($sourceLimit);
        }
        $performanceQuery->get()
            ->each(function (ModelMarketPerformance $performance) use (&$scopes): void {
                foreach (['market' => 'regime_performance', 'volatility' => 'volatility_performance'] as $prefix => $key) {
                    foreach (data_get($performance->metrics, $key, []) as $name => $metric) {
                        if ((int) data_get($metric, 'trades', 0) < 20) {
                            continue;
                        }
                        $scope = "{$prefix}:{$name}";
                        $score = (float) data_get($metric, 'profit_percent', 0) / max(1, (int) data_get($metric, 'trades', 1));
                        $scopes[$scope] = min($scopes[$scope] ?? INF, $score);
                    }
                }
            });

        if ($scopes !== []) {
            asort($scopes);

            return (string) array_key_first($scopes);
        }

        $fallbacks = ['market:trend_up', 'market:trend_down', 'market:range', 'volatility:high_volatility', 'volatility:normal_volatility'];

        return $fallbacks[$seed % count($fallbacks)];
    }

    private function crossover(string $family, array $a, array $b): array
    {
        $child = [];
        foreach (array_keys($this->schemas->schema($family)) as $index => $key) {
            $child[$key] = $index % 2 === 0 ? ($a[$key] ?? $b[$key]) : ($b[$key] ?? $a[$key]);
        }

        return $child;
    }

    /** Blend modules using the adaptive capability provenance contract. */
    private function skillCrossover(string $family, $parents, array $fallback, int $seed, array $capabilityGenome = []): array
    {
        $parents = collect($parents)->filter()->values();
        if ($parents->isEmpty()) {
            return [$this->randomParameters($family, $seed), [], []];
        }
        $child = $fallback;
        $sources = [];
        $geneProvenance = [];
        foreach (array_keys($this->schemas->schema($family)) as $key) {
            $skill = match (true) {
                in_array($key, ['differential_target_regime', 'differential_router_version', 'trend_down_strength_min', 'trend_down_pullback_atr_fraction', 'high_volatility_wait', 'range_signal_mode'], true) => 'router_skill',
                in_array($key, ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles', 'partial_take_profit_fraction', 'partial_target_atr_multiplier'], true) => 'exit_skill',
                in_array($key, ['high_volatility_risk_multiplier', 'max_loss_streak_before_wait', 'loss_cooldown_candles', 'avoid_high_volatility'], true) => 'risk_skill',
                in_array($key, ['lookback', 'confirmation_candles', 'trend_strength_min', 'pullback_atr_fraction', 'roc_threshold', 'deviation'], true) => 'entry_timing_skill',
                in_array($key, ['minimum_signal_confidence', 'confidence_calibration_enabled', 'confidence_calibration_min_samples', 'confidence_ev_lower_bound_enabled', 'meta_label_enabled', 'meta_label_min_history', 'meta_label_min_pf', 'meta_label_risk_multiplier'], true) => 'confidence_calibration_skill',
                default => 'cost_robustness_skill',
            };
            $module = match ($skill) {
                'router_skill' => 'router',
                'exit_skill' => 'exit',
                'risk_skill' => 'risk',
                'entry_timing_skill' => 'entry',
                'confidence_calibration_skill' => 'confidence_calibration',
                default => 'execution_cost',
            };
            $parameterSource = (array) data_get($capabilityGenome, "parameter_sources.{$key}", []);
            $provenanceParentId = data_get($parameterSource, 'source_parent_id');
            $parent = $provenanceParentId
                ? $parents->firstWhere('id', (int) $provenanceParentId)
                : null;
            if (! $parent) {
                $moduleContributor = collect((array) data_get($capabilityGenome, "modules.{$module}.contributors", []))
                    ->first(fn (array $contributor): bool => in_array($key, (array) data_get($contributor, 'parameter_keys', []), true));
                $parent = $parents->firstWhere('id', (int) data_get($moduleContributor, 'parent_model_version_id', 0));
                if ($parent) {
                    $parameterSource = [
                        ...$parameterSource,
                        'source_parent_id' => $parent->id,
                        'source_module' => $module,
                        'source_evidence_id' => data_get($moduleContributor, 'source_evidence_id'),
                        'source_confidence' => data_get($moduleContributor, 'evidence_confidence', 0),
                        'contribution_weight' => data_get($moduleContributor, 'contribution_weight', 0),
                    ];
                }
            }
            $parent ??= $parents->sortByDesc(fn (ModelVersion $model) => (float) data_get($model->metadata, "skill_tree.{$skill}", 0))->first();
            if ($parent && array_key_exists($key, $parent->parameters ?? [])) {
                $child[$key] = $parent->parameters[$key];
                $sources[$skill] = $parent->id;
                $geneProvenance[$key] = [
                    'source_parent_id' => (int) data_get($parameterSource, 'source_parent_id', $parent->id),
                    'source_module' => (string) data_get($parameterSource, 'source_module', $module),
                    'source_evidence_id' => data_get($parameterSource, 'source_evidence_id'),
                    'source_confidence' => (float) data_get($parameterSource, 'source_confidence', 0),
                    'contribution_weight' => (float) data_get($parameterSource, 'contribution_weight', 0),
                    'scope' => data_get($parameterSource, 'scope'),
                    'parameter_hash' => hash('sha256', json_encode([$key => $parent->parameters[$key]], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)),
                    'inheritance_rule' => 'module_bundle_copy_no_blind_scalar_blend',
                    'child_replay_required' => true,
                ];
            }
        }

        return [$child, $sources, $geneProvenance];
    }

    private function randomParameters(string $family, int $seed): array
    {
        $values = [];
        $index = 0;
        foreach ($this->schemas->schema($family) as $key => $rule) {
            [$type, $min, $max] = array_pad($rule, 3, null);
            if ($type === 'boolean') {
                $values[$key] = ($seed + $index) % 2 === 0;
                $index++;

                continue;
            }
            if ($type === 'string') {
                $choices = (array) $min;
                $values[$key] = $choices[($seed + $index) % max(1, count($choices))] ?? '';
                $index++;

                continue;
            }
            $ratio = (($seed * 37 + $index * 17) % 101) / 100;
            $value = $min + ($max - $min) * $ratio;
            $values[$key] = $type === 'integer' ? (int) round($value) : round($value, 4);
            $index++;
        }

        return $values;
    }

    private function dataSnapshot(AiLaboratory $lab): array
    {
        $symbolId = Symbol::where('code', $lab->symbol)->value('id');
        if (! $symbolId) {
            return ['fingerprint' => 'no-data', 'count' => 0, 'latest' => null];
        }
        $query = Candle::where('symbol_id', $symbolId)->where('timeframe', $lab->timeframe);
        $count = $query->count();
        $latest = $query->max('time');

        return ['fingerprint' => sha1($count.'|'.($latest ?? 'none')), 'count' => $count, 'latest' => $latest];
    }

    private function sealParameterIntegrity(ModelVersion $model, string $family): void
    {
        $parameters = (array) $model->parameters;
        $expectedFingerprint = $this->parameterFingerprint($family, $parameters);
        $expectedUniversalHash = hash('sha256', json_encode(
            $this->canonicalFingerprintParameters($family, $parameters),
            JSON_PRESERVE_ZERO_FRACTION,
        ));
        $metadata = (array) $model->metadata;
        $repairs = [];

        if (data_get($metadata, 'parameter_fingerprint') !== $expectedFingerprint) {
            $repairs['parameter_fingerprint'] = [
                'old' => data_get($metadata, 'parameter_fingerprint'),
                'new' => $expectedFingerprint,
            ];
            $metadata['parameter_fingerprint'] = $expectedFingerprint;
        }
        if (data_get($metadata, 'universal_genome.local_adapter.parameters_hash') !== $expectedUniversalHash) {
            $repairs['universal_genome.local_adapter.parameters_hash'] = [
                'old' => data_get($metadata, 'universal_genome.local_adapter.parameters_hash'),
                'new' => $expectedUniversalHash,
            ];
            data_set($metadata, 'universal_genome.local_adapter.parameters_hash', $expectedUniversalHash);
        }

        if ($repairs === []) {
            return;
        }

        $metadata['parameter_integrity_repair'] = [
            'protocol' => 'model_identity_reconciliation_v1',
            'checks' => $repairs,
            'parameters_unchanged' => true,
            'promotion_evidence' => false,
            'recorded_at' => now()->toIso8601String(),
        ];
        $model->metadata = $metadata;
        $model->save();
    }

    private function diff(array $old, array $new): array
    {
        return collect($new)->filter(fn ($value, $key) => ! array_key_exists($key, $old) || $this->valuesDiffer($old[$key], $value))
            ->map(fn ($value, $key) => ['old' => $old[$key] ?? null, 'new' => $value])->all();
    }

    private function valuesDiffer(mixed $old, mixed $new): bool
    {
        // JSON/database casts may represent the same schema number as "0",
        // 0 or 0.0. A type-only distinction is not a mutation and must not
        // satisfy a one-gene constructor contract.
        if (! is_bool($old) && ! is_bool($new) && is_numeric($old) && is_numeric($new)) {
            return (float) $old !== (float) $new;
        }

        return $old !== $new;
    }
}
