<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\ModelVersion;

/**
 * Turns a promising mutation into a reusable skill only after the complete
 * parent/child evidence contract is present.
 *
 * This is deliberately separate from the economic promotion gates. A child
 * may be a good candidate and still fail to teach the lab anything reusable
 * when its parent was replayed on another manifest, the execution contract
 * changed, or the target lane did not improve.
 */
class MutationSkillVerificationService
{
    public const PROTOCOL = 'verified_mutation_skill_v1';

    /** @return array<string, mixed> */
    public function verify(
        LabAgent $agent,
        ?ModelVersion $parent,
        ?array $parentResult,
        array $childResult,
        array $parameterDiff,
        ?array $noRegression = null,
    ): array {
        $agent->loadMissing('modelVersion');
        $child = $agent->modelVersion;
        $family = (string) $agent->strategy_family;
        $changedGenes = array_keys($parameterDiff);
        $parentArchitecture = $parent ? data_get($parent->metadata, 'strategy_architecture') : null;
        $childArchitecture = data_get($child?->metadata, 'strategy_architecture');
        if ($parent && $parentArchitecture !== $childArchitecture) $changedGenes[] = '__architecture';
        $changedGenes = array_values(array_unique(array_filter($changedGenes, static fn ($key): bool => filled($key))));

        $exactParent = $parent !== null
            && $child !== null
            && app(StrategySemanticGroupService::class)->sameGroup($child, $family, $parent, $family);
        $sameData = $this->sameHash($parentResult, $childResult, 'data_manifest.sha256');
        $sameExecution = $this->sameHash($parentResult, $childResult, 'execution_contract.execution_hash');
        $target = (string) data_get($child?->metadata, 'generation_target', '');
        $parentTarget = $this->targetScore($target, (array) $parentResult);
        $childTarget = $this->targetScore($target, $childResult);
        $targetImproved = $parentTarget !== null && $childTarget !== null
            && $childTarget > ($parentTarget + 0.0001);

        $regressionStatus = (string) data_get(
            $noRegression,
            'status',
            data_get($childResult, 'no_regression_contract.status', 'baseline_unavailable'),
        );
        $differentialStatus = data_get($childResult, 'differential_no_regression.status');
        $nonTargetPassed = $regressionStatus === 'passed'
            && ($differentialStatus === null || $differentialStatus === 'passed');

        $windows = $this->independentForwardWindows($childResult);
        $purgedValidation = $this->purgedValidation($childResult);
        $singleGene = count($changedGenes) === 1;
        $requirements = [
            'exact_semantic_parent' => $exactParent,
            'same_data_manifest' => $sameData,
            'same_execution_contract' => $sameExecution,
            'single_gene' => $singleGene,
            'target_gate_improved' => $targetImproved,
            'non_target_gates_preserved' => $nonTargetPassed,
            'three_independent_forward_windows' => (int) data_get($windows, 'independent_windows', 0) >= 3
                && data_get($windows, 'independence_verified') === true,
            'minimum_positive_forward_windows' => (int) data_get($windows, 'positive_windows', 0) >= 2,
            'purged_embargoed_validation' => (bool) data_get($purgedValidation, 'promotion_evidence', false),
        ];
        $confirmed = ! in_array(false, $requirements, true);

        return [
            'protocol' => self::PROTOCOL,
            'status' => $confirmed ? 'confirmed' : 'not_confirmed',
            'evidence_run_ids' => array_values(array_unique(array_filter([
                data_get($parentResult, 'evidence_run_id'),
                data_get($childResult, 'evidence_run_id'),
            ]))),
            'target' => $target !== '' ? $target : null,
            'changed_genes' => $changedGenes,
            'changed_parameter_keys' => array_keys($parameterDiff),
            'requirements' => $requirements,
            'exact_parent' => [
                'model_version_id' => $parent?->id,
                'semantic_group_match' => $exactParent,
            ],
            'same_data_manifest' => $sameData,
            'same_execution_contract' => $sameExecution,
            'target_gate' => [
                'parent_score' => $parentTarget,
                'child_score' => $childTarget,
                'delta' => $parentTarget !== null && $childTarget !== null
                    ? round($childTarget - $parentTarget, 6) : null,
                'improved' => $targetImproved,
            ],
            'non_target' => [
                'status' => $nonTargetPassed ? 'passed' : 'failed',
                'evolution_quality_status' => $regressionStatus,
                'differential_status' => $differentialStatus,
            ],
            'independent_forward_windows' => $windows,
            'purged_embargoed_validation' => $purgedValidation,
            'promotion_evidence' => false,
            'rule' => 'A reusable skill requires an exact semantic parent, identical data/execution contracts, one changed gene, target-lane improvement, no non-target regression, three independent non-overlapping chronological windows with at least two positive windows, and purged/embargoed validation evidence.',
        ];
    }

    /**
     * Extract independent chronological windows without counting overlapping
     * train slices twice. Checkpoint windows are preferred because they are
     * disjoint replay chunks; monthly walk-forward windows are a fallback and
     * use their test month, not their overlapping expanding train interval.
     *
     * @return array<string, mixed>
     */
    public function independentForwardWindows(array $result): array
    {
        $walkForward = array_values(array_filter(
            (array) data_get($result, 'walk_forward.windows', []),
            'is_array',
        ));
        $checkpoint = array_values(array_filter(
            (array) data_get($result, 'market_adaptive_replay.checkpoint_windows', []),
            'is_array',
        ));
        $monthly = array_values(array_filter(
            (array) data_get($result, 'market_adaptive_replay.monthly_walk_forward.windows', []),
            'is_array',
        ));

        if ($walkForward !== []) {
            $source = 'walk_forward_windows';
            $rows = $walkForward;
        } elseif ($checkpoint !== []) {
            $source = 'checkpoint_windows';
            $rows = $checkpoint;
        } elseif ($monthly !== []) {
            $source = 'monthly_test_windows';
            $rows = $monthly;
        } else {
            $scores = array_values((array) data_get($result, 'forward_window_scores', []));
            $rows = array_map(
                static fn ($score, $index): array => ['window' => $index + 1, 'score' => $score],
                $scores,
                array_keys($scores),
            );
            $source = $rows === [] ? 'not_available' : 'score_array_without_window_bounds';
        }

        $normalized = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            $identity = $this->windowIdentity($row, $index, $source);
            if (isset($seen[$identity])) continue;
            $seen[$identity] = true;
            [$start, $end] = $this->windowBounds($row, $source);
            $normalized[] = [
                'id' => $identity,
                'start' => $start,
                'end' => $end,
                'score' => is_numeric(data_get($row, 'score')) ? (float) data_get($row, 'score') : null,
                'profit_factor' => $this->numericOrNull($row, ['profit_factor', 'net_pf', 'summary.net_pf', 'results.forward.profit_factor', 'results.forward.net_pf']),
                'net_profit_percent' => $this->numericOrNull($row, ['net_profit_percent', 'summary.net_profit_percent', 'results.forward.net_profit_percent']),
                'trades' => (int) data_get($row, 'trades', data_get($row, 'summary.trades', data_get($row, 'results.forward.total_trades', 0))),
            ];
        }

        $hasBounds = $normalized !== [] && collect($normalized)->every(
            static fn (array $window): bool => filled($window['start']) && filled($window['end']),
        );
        $continuousDiagnostic = collect($rows)->contains(
            static fn (array $window): bool => data_get($window, 'independent_evidence') === false
                || filled(data_get($window, 'state_continuity'))
                || data_get($window, 'state_reset') === false,
        );
        $overlapDetected = false;
        $independent = $normalized;
        if ($hasBounds) {
            usort($independent, static fn (array $left, array $right): int => strcmp((string) $left['start'], (string) $right['start']));
            $accepted = [];
            foreach ($independent as $window) {
                $previous = $accepted === [] ? null : $accepted[array_key_last($accepted)];
                if ($previous !== null && (string) $window['start'] <= (string) $previous['end']) {
                    $overlapDetected = true;
                    continue;
                }
                $accepted[] = $window;
            }
            $independent = $accepted;
        }

        $positive = array_values(array_filter($independent, function (array $window): bool {
            if ($window['trades'] > 0 && $window['trades'] < 10) return false;
            if ($window['profit_factor'] !== null) {
                return $window['profit_factor'] >= 1.0
                    && ($window['net_profit_percent'] === null || $window['net_profit_percent'] > 0);
            }
            return $window['score'] !== null && $window['score'] > 0;
        }));

        // A stateful ledger can be perfectly partitioned by timestamps and
        // still is not an independent forward experiment: every window shares
        // indicator warm-up, cooldown, risk and adaptive state. It remains
        // useful diagnostic evidence, but cannot confirm a reusable skill.
        if ($continuousDiagnostic) {
            $independenceVerified = false;
            $independent = [];
        } else {
            $independenceVerified = $hasBounds && ! $overlapDetected;
        }
        return [
            'protocol' => 'non_overlapping_forward_windows_v1',
            'source' => $source,
            'observed_windows' => count($normalized),
            'independent_windows' => count($independent),
            'positive_windows' => count($positive),
            'confirmed_windows' => $independenceVerified ? min(3, count($positive)) : 0,
            'required_windows' => 3,
            'minimum_positive_windows' => 2,
            'independence_verified' => $independenceVerified,
            'overlap_detected' => $overlapDetected,
            'stateful_diagnostic_only' => $continuousDiagnostic,
            'window_ids' => array_values(array_map(static fn (array $window): string => $window['id'], $independent)),
            'promotion_evidence' => false,
        ];
    }

    private function sameHash(?array $parent, array $child, string $path): bool
    {
        $left = data_get($parent, $path);
        $right = data_get($child, $path);
        return is_string($left) && $left !== '' && is_string($right) && $right !== '' && hash_equals($left, $right);
    }

    private function targetScore(string $target, array $metrics): ?float
    {
        if ($metrics === [] || $target === '') return null;
        return match ($target) {
            'monthly_survival', 'temporal_stability' => $this->monthlyScore($metrics),
            'regime_coverage', 'rolling_regime', 'portfolio_router' => $this->regimeScore($metrics),
            'opportunity_recall', 'trade_frequency' => $this->recallScore($metrics),
            'volatility_session_stability', 'exit_topology', 'stress_cost', 'risk_exit', 'transition_firewall' => $this->stressScore($metrics),
            'architecture', 'robustness' => is_numeric(data_get($metrics, 'forward_score')) ? (float) data_get($metrics, 'forward_score') : null,
            default => null,
        };
    }

    private function monthlyScore(array $metrics): ?float
    {
        $passport = (array) data_get($metrics, 'monthly_passport', []);
        $wins = data_get($passport, 'rolling_forward_wins');
        $worst = data_get($passport, 'worst_month_pf', data_get($passport, 'worst_month.profit_factor'));
        if (! is_numeric($wins) && ! is_numeric($worst)) return null;
        return (is_numeric($wins) ? (float) $wins : 0.0) + (is_numeric($worst) ? (float) $worst / 100 : 0.0);
    }

    private function regimeScore(array $metrics): ?float
    {
        $edge = (array) data_get($metrics, 'statistical_evidence.edge_quality', []);
        $worst = data_get($edge, 'worst_regime_pf');
        if (! is_numeric($worst)) {
            $rows = collect((array) data_get($metrics, 'regime_performance', []))
                ->filter(static fn ($row): bool => is_numeric(data_get($row, 'net_pf', data_get($row, 'profit_factor'))))
                ->map(static fn ($row): float => (float) data_get($row, 'net_pf', data_get($row, 'profit_factor')));
            $worst = $rows->isNotEmpty() ? $rows->min() : null;
        }
        if (! is_numeric($worst)) return null;
        $sampled = collect((array) data_get($metrics, 'regime_performance', []))
            ->filter(static fn ($row): bool => (int) data_get($row, 'trades', 0) > 0)->count();
        return (float) $worst + min(1.0, $sampled / 100);
    }

    private function recallScore(array $metrics): ?float
    {
        $recall = data_get($metrics, 'opportunity_recall.opportunity_recall', data_get($metrics, 'opportunity_metrics.recall'));
        $precision = data_get($metrics, 'opportunity_recall.abstention_precision');
        if (! is_numeric($recall) && ! is_numeric($precision)) return null;
        return (is_numeric($recall) ? (float) $recall : 0.0)
            + (is_numeric($precision) ? (float) $precision / 100 : 0.0);
    }

    private function stressScore(array $metrics): ?float
    {
        $score = data_get($metrics, 'pf_attribution.stress_cost.profit_factor', data_get($metrics, 'stress_test.profit_factor'));
        if (! is_numeric($score)) return null;
        return (float) $score;
    }

    private function windowIdentity(array $row, int $index, string $source): string
    {
        foreach (['window_id', 'id', 'window', 'test_month'] as $key) {
            $value = data_get($row, $key);
            if (is_scalar($value) && trim((string) $value) !== '') return $source.'|'.(string) $value;
        }
        return $source.'|'.($index + 1);
    }

    /** @return array{0:?string,1:?string} */
    private function windowBounds(array $row, string $source): array
    {
        $start = data_get($row, 'start', data_get($row, 'period.start'));
        $end = data_get($row, 'end', data_get($row, 'period.end'));
        if ($source === 'monthly_test_windows' && filled(data_get($row, 'test_month'))) {
            $month = (string) data_get($row, 'test_month');
            $start = $month.'-01';
            $timestamp = strtotime($start);
            $end = $timestamp === false ? null : date('Y-m-t', $timestamp);
        }
        if (is_string($start) && str_contains($start, ' - ') && $end === null) {
            [$start, $end] = array_pad(explode(' - ', $start, 2), 2, null);
        }
        if (($start === null || $end === null) && is_string(data_get($row, 'periods.forward'))) {
            [$periodStart, $periodEnd] = array_pad(explode(' - ', (string) data_get($row, 'periods.forward'), 2), 2, null);
            $start ??= $periodStart;
            $end ??= $periodEnd;
        }
        return [filled($start) ? (string) $start : null, filled($end) ? (string) $end : null];
    }

    /** @return array<string, mixed> */
    private function purgedValidation(array $result): array
    {
        foreach ([
            data_get($result, 'selection_validation'),
            data_get($result, 'portfolio_selection_context.purged_cscv'),
            data_get($result, 'market_adaptive_replay.selection_validation'),
        ] as $candidate) {
            if (! is_array($candidate)) continue;
            $protocol = (string) data_get($candidate, 'protocol', '');
            $applied = (bool) data_get($candidate, 'purge_embargo_applied', false);
            $promotion = (bool) data_get($candidate, 'promotion_evidence', false);
            if ($protocol === 'purged_embargoed_cscv_v1' && $applied && $promotion) {
                return [
                    'protocol' => $protocol,
                    'purge_bars' => (int) data_get($candidate, 'purge_bars', 0),
                    'embargo_bars' => (int) data_get($candidate, 'embargo_bars', 0),
                    'purge_embargo_applied' => true,
                    'promotion_evidence' => true,
                ];
            }
            return [
                'protocol' => $protocol !== '' ? $protocol : 'missing',
                'purge_bars' => (int) data_get($candidate, 'purge_bars', 0),
                'embargo_bars' => (int) data_get($candidate, 'embargo_bars', 0),
                'purge_embargo_applied' => $applied,
                'promotion_evidence' => false,
                'reason' => data_get($candidate, 'reason', 'purged/embargoed promotion evidence is not valid'),
            ];
        }
        return [
            'protocol' => 'missing',
            'purge_embargo_applied' => false,
            'promotion_evidence' => false,
            'reason' => 'No purged/embargoed financial validation artifact was returned.',
        ];
    }

    /** @param array<int, string> $paths */
    private function numericOrNull(array $row, array $paths): ?float
    {
        foreach ($paths as $path) {
            $value = data_get($row, $path);
            if (is_numeric($value)) return (float) $value;
        }
        return null;
    }
}
