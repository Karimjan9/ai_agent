<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\ModelMarketPerformance;
use App\Models\MutationMemory;

/** Reconciles isolated mutation credit only after a same-generation control exists. */
class CausalMutationCreditService
{
    private const REQUIRED_WINDOWS = 3;
    private const MINIMUM_POSITIVE_WINDOWS = 2;

    public function reconcileGeneration(int $generationId): int
    {
        $agents = LabAgent::query()->with(['modelVersion', 'mutationMemories', 'generation'])
            ->where('lab_generation_id', $generationId)->get();
        $updated = 0;

        foreach ($agents as $agent) {
            $current = ModelMarketPerformance::query()->where('model_version_id', $agent->model_version_id)->first();
            if (! $current) continue;
            foreach ($agent->mutationMemories as $memory) {
                // New protocol records the complete verification contract at
                // the first full replay.  Do not reconstruct its verdict from
                // row counts or a family-wide champion: the contract already
                // proves exact lineage, hashes, target progress and distinct
                // non-overlapping windows.
                $verification = (array) data_get($memory->behavioral_effect, 'verified_mutation_skill', []);
                // Failure-anchor verification is a separate protocol: its
                // failed source is an immutable repair baseline, not a
                // genetic parent/control pair. MarketChampionService records
                // its credit only after the anchor contract is confirmed;
                // ordinary parent-based reconciliation must not overwrite
                // that result with a zero-window verdict.
                if (data_get($verification, 'protocol') === FailureRepairAnchorService::PROTOCOL) {
                    continue;
                }
                if ($verification !== []) {
                    $confirmed = data_get($verification, 'status') === 'confirmed'
                        && (int) data_get($verification, 'independent_forward_windows.independent_windows', 0) >= self::REQUIRED_WINDOWS
                        && (int) data_get($verification, 'independent_forward_windows.positive_windows', 0) >= self::MINIMUM_POSITIVE_WINDOWS
                        && data_get($verification, 'requirements.three_independent_forward_windows', true) === true
                        && data_get($verification, 'requirements.minimum_positive_forward_windows', true) === true;
                    $windows = min(self::REQUIRED_WINDOWS, (int) data_get($verification, 'independent_forward_windows.independent_windows', data_get($verification, 'independent_forward_windows.confirmed_windows', 0)));
                    $effect = (array) $memory->behavioral_effect;
                    $credit = (array) data_get($effect, 'causal_credit', []);
                    $credit['status'] = $confirmed ? 'independently_confirmed' : 'not_confirmed';
                    $credit['verified_skill_contract'] = $verification;
                    $credit['reconciled_at'] = now()->toIso8601String();
                    $effect['causal_credit'] = $credit;
                    $memory->update([
                        'outcome' => $confirmed && data_get($verification, 'target_gate.improved') === true ? 'beneficial' : ($memory->outcome === 'harmful' ? 'harmful' : 'neutral'),
                        'decision' => $confirmed
                            ? 'Verified skill contract passed; exact semantic descendants may reuse this gene.'
                            : 'Verification contract failed; mutation remains exploratory and cannot become a prior.',
                        'independent_confirmation_count' => $confirmed ? $windows : 0,
                        'non_target_regression_status' => (string) data_get($verification, 'non_target.status', 'failed'),
                        'evidence_scope_status' => $confirmed && $windows >= self::REQUIRED_WINDOWS
                            && (int) data_get($verification, 'independent_forward_windows.positive_windows', 0) >= self::MINIMUM_POSITIVE_WINDOWS
                            ? 'eligible_prior' : 'historical_failure_memory',
                        'behavioral_effect' => $effect,
                    ]);
                    app(LabImmutableEvidenceService::class)->recordMutationCredit($memory->fresh(), [
                        'source' => 'verified_mutation_skill_reconciliation',
                        'model_market_performance_id' => $current->id,
                        'parent_model_version_id' => data_get($verification, 'exact_parent.model_version_id'),
                        'evidence_run_ids' => data_get($verification, 'evidence_run_ids', [data_get($current->metrics, 'evidence_run_id')]),
                        'primary_evidence_run_id' => data_get($current->metrics, 'evidence_run_id'),
                        'verified_skill_contract' => $verification,
                    ]);
                    $updated++;
                    continue;
                }

                if (data_get($memory->behavioral_effect, 'causal_credit.status') !== 'awaiting_paired_confirmation') continue;
                $parentId = data_get($memory->behavioral_effect, 'causal_credit.parent_model_version_id');
                $parent = $parentId ? ModelMarketPerformance::query()->with('modelVersion')->where('model_version_id', $parentId)->first() : null;
                $alternative = ModelMarketPerformance::query()
                    ->where('symbol', $agent->symbol)->where('timeframe', $agent->timeframe)
                    ->where('strategy_family', $agent->strategy_family)
                    ->where('model_version_id', '!=', $agent->model_version_id)
                    ->whereHas('modelVersion', fn ($query) => $query->where('generation', $agent->generation?->generation))
                    ->with('modelVersion')
                    ->orderByDesc('forward_score')->first();
                if (! $parent || ! $alternative) continue;

                $verification = app(MutationSkillVerificationService::class)->verify(
                    $agent,
                    $parent->modelVersion,
                    (array) $parent->metrics,
                    (array) $current->metrics,
                    (array) $agent->parameter_diff,
                    (array) data_get($current->metrics, 'no_regression_contract', []),
                );

                // One replay can still be a lucky chronology. Individual
                // mutation credit requires three independent chronological
                // windows, with at least two positive, on the child, parent
                // and same-family control before it can influence a prior.
                $independentTemporalLanes = (int) data_get($verification, 'independent_forward_windows.independent_windows', 0) >= self::REQUIRED_WINDOWS
                    && (int) data_get($verification, 'independent_forward_windows.positive_windows', 0) >= self::MINIMUM_POSITIVE_WINDOWS
                    && (int) $parent->rolling_windows_count >= self::REQUIRED_WINDOWS
                    && (int) $alternative->rolling_windows_count >= self::REQUIRED_WINDOWS;
                $g98Lane = (array) data_get($agent->modelVersion?->metadata, 'g98_council_lane', []);
                $counterfactualProven = $g98Lane === [] || $this->fiveReplayProof(
                    (array) data_get($current->metrics, 'counterfactual_blame_graph', [])
                );
                $confirmed = $independentTemporalLanes
                    && (bool) data_get($verification, 'requirements.exact_semantic_parent', false)
                    && (bool) data_get($verification, 'requirements.same_data_manifest', false)
                    && (bool) data_get($verification, 'requirements.same_execution_contract', false)
                    && (bool) data_get($verification, 'requirements.single_gene', false)
                    && (bool) data_get($verification, 'requirements.target_gate_improved', false)
                    && (bool) data_get($verification, 'requirements.non_target_gates_preserved', false)
                    && (float) $current->forward_score > (float) $parent->forward_score
                    && (float) $current->forward_score > (float) $alternative->forward_score
                    && data_get($memory->behavioral_effect, 'differential_no_regression.status', 'passed') === 'passed'
                    && $counterfactualProven;
                $paired = [
                    'status' => $confirmed ? 'confirmed' : 'not_confirmed',
                    'evidence_run_ids' => array_values(array_unique(array_filter([
                        data_get($parent->metrics, 'evidence_run_id'),
                        data_get($current->metrics, 'evidence_run_id'),
                        data_get($alternative->metrics, 'evidence_run_id'),
                    ]))),
                    'parent_score' => (float) $parent->forward_score,
                    'targeted_score' => (float) $current->forward_score,
                    'alternative_score' => (float) $alternative->forward_score,
                    'alternative_model_version_id' => $alternative->model_version_id,
                    'independent_temporal_lanes' => $independentTemporalLanes ? self::REQUIRED_WINDOWS : 0,
                    'positive_temporal_windows' => $independentTemporalLanes
                        ? max(self::MINIMUM_POSITIVE_WINDOWS, min(self::REQUIRED_WINDOWS, (int) data_get($verification, 'independent_forward_windows.positive_windows', 0)))
                        : 0,
                    'stress_cost_degraded' => (float) data_get($current->metrics, 'stress_test.profit_factor', 0) < (float) data_get($parent->metrics, 'stress_test.profit_factor', 0),
                    'five_replay_counterfactual_proven' => $counterfactualProven,
                    'reconciled_at' => now()->toIso8601String(),
                    'rule' => 'Targeted mutation is retained only when it beats parent and same-protocol alternative across three independent chronological lanes with at least two positive windows.',
                ];
                $effect = (array) $memory->behavioral_effect;
                $effect['paired_experiment'] = $paired;
                $effect['verified_mutation_skill'] = $verification;
                $effect['causal_credit'] = [
                    ...(array) data_get($effect, 'causal_credit', []),
                    'status' => $confirmed ? 'independently_confirmed' : 'not_confirmed',
                    'alternative_model_version_id' => $alternative->model_version_id,
                    'verified_skill_contract' => $verification,
                    'reconciled_at' => now()->toIso8601String(),
                    'rule' => 'Individual credit requires a single changed gene, a parent, a same-generation control and three independent chronological replay lanes with at least two positive windows.',
                ];
                $memory->update([
                    'outcome' => $confirmed ? ((float) $memory->forward_delta > 0 ? 'beneficial' : 'harmful') : 'neutral',
                    'decision' => $confirmed ? 'Causal paired replay confirmed; gene may enter evidence frontier.' : 'Causal paired replay did not beat the parent and control; gene remains exploratory.',
                    'behavioral_effect' => $effect,
                    'independent_confirmation_count' => $confirmed
                        ? self::REQUIRED_WINDOWS
                        : 0,
                    'non_target_regression_status' => data_get($effect, 'differential_no_regression.status', 'passed'),
                    'evidence_scope_status' => $confirmed
                        && (int) data_get($verification, 'independent_forward_windows.independent_windows', 0) >= self::REQUIRED_WINDOWS
                        && (int) data_get($verification, 'independent_forward_windows.positive_windows', 0) >= self::MINIMUM_POSITIVE_WINDOWS
                        ? 'eligible_prior' : 'historical_failure_memory',
                ]);
                app(LabImmutableEvidenceService::class)->recordMutationCredit($memory->fresh(), [
                    'source' => 'causal_reconciliation',
                    'model_market_performance_id' => $current->id,
                    'parent_model_version_id' => $parent->model_version_id,
                    'control_model_version_id' => $alternative->model_version_id,
                    'evidence_run_ids' => data_get($paired, 'evidence_run_ids', []),
                    'primary_evidence_run_id' => data_get($current->metrics, 'evidence_run_id'),
                    'paired_experiment' => $paired,
                ]);
                $updated++;
            }
        }

        return $updated;
    }

    /** A G98 gene cannot become a prior from a provisional loss story. */
    private function fiveReplayProof(array $graph): bool
    {
        $cases = (array) data_get($graph, 'cases', []);
        if ($cases === []) return false;
        $required = ['no_trade', 'delayed_entry', 'alternative_exit', 'alternative_specialist', 'half_risk'];
        foreach ($cases as $case) {
            foreach ($required as $branch) {
                $status = (string) data_get($case, "{$branch}.status", 'not_assessed');
                if (! str_starts_with($status, 'assessed')) return false;
            }
        }
        return true;
    }
}
