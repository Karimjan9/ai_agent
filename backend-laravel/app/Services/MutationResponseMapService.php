<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabMutationResponseMap;
use App\Models\ModelMarketPerformance;
use Illuminate\Support\Facades\Schema;

/**
 * Stores immutable response-surface observations for declared mutations.
 * The service is deliberately fail-open until its migration is applied: a
 * deployment can therefore ship the compiler and migration together without
 * turning an old worker into a strategy failure during rollout.
 */
class MutationResponseMapService
{
    public const PROTOCOL = 'mutation_response_map_v1';

    /** @return array<string, mixed>|null */
    public function recordScreening(LabAgent $agent, array $result): ?array
    {
        if (! $this->available() || ! filled(data_get($result, 'evidence_run_id'))) return null;

        $agent->loadMissing('modelVersion');
        $metadata = (array) ($agent->modelVersion?->metadata ?? []);
        $anchorId = (int) data_get($metadata, 'repair_anchor.id', 0);
        $anchor = $anchorId > 0 ? app(FailureRepairAnchorService::class)->findForTarget(
            $anchorId,
            (string) $agent->symbol,
            (string) $agent->timeframe,
            (string) $agent->strategy_family,
            (string) data_get($metadata, 'repair_anchor.failure_target', data_get($metadata, 'generation_target', '')),
        ) : null;
        $baseline = $anchor
            ? app(FailureRepairAnchorService::class)->baselineResult($agent)
            : $this->parentBaseline($agent);
        $sibling = (string) data_get($metadata, 'repair_anchor.sibling_kind', data_get($metadata, 'repair_anchor_sibling.kind', ''));
        $control = in_array($sibling, ['frozen_control', 'architecture_escape'], true)
            || (bool) data_get($metadata, 'causal_experiment_lane.control_only', false)
            || (bool) data_get($metadata, 'g98_council_lane.control_only', false);

        return $this->persist($agent, 'screening', $result, $baseline, [
            'status' => $control ? 'control' : 'screen_observed',
            'anchor_id' => $anchor?->id ?: ($anchorId > 0 ? $anchorId : null),
            'sibling_kind' => $sibling !== '' ? $sibling : null,
            'metadata' => [
                'protocol' => self::PROTOCOL,
                'promotion_evidence' => false,
                'screening_decision' => data_get($result, 'screen_decision'),
                'data_manifest_hash' => data_get($result, 'data_manifest.sha256', data_get($result, 'data_manifest.snapshot_sha256')),
                'execution_hash' => data_get($result, 'execution_contract.execution_hash', data_get(
                    $result,
                    'execution_hash',
                    data_get($result, 'observed_metrics.execution_contract.execution_hash'),
                )),
            ],
        ]);
    }

    /** @return array<string, mixed>|null */
    public function recordFullReplay(
        LabAgent $agent,
        array $result,
        ?ModelMarketPerformance $performance = null,
        ?array $verification = null,
        array $options = [],
    ): ?array {
        if (! $this->available() || ! filled(data_get($result, 'evidence_run_id'))) return null;

        $agent->loadMissing('modelVersion');
        $metadata = (array) ($agent->modelVersion?->metadata ?? []);
        $anchorId = (int) data_get($metadata, 'repair_anchor.id', 0);
        $baseline = (array) data_get($options, 'baseline_metrics', []);
        $baseline = $baseline !== [] ? $baseline : ($anchorId > 0
            ? app(FailureRepairAnchorService::class)->baselineResult($agent)
            : $this->parentBaseline($agent));
        $skillStatus = (string) data_get($result, 'verified_mutation_skill.status', '');
        $repairStatus = (string) data_get($verification, 'status', data_get($result, 'repair_anchor_verification.status', ''));
        $sibling = (string) data_get($metadata, 'repair_anchor.sibling_kind', data_get($metadata, 'repair_anchor_sibling.kind', ''));
        $control = in_array($sibling, ['frozen_control', 'architecture_escape'], true)
            || (bool) data_get($metadata, 'causal_experiment_lane.control_only', false)
            || (bool) data_get($metadata, 'g98_council_lane.control_only', false);

        $status = $control
            ? 'control'
            : (($skillStatus === 'confirmed' || $repairStatus === 'confirmed')
                ? 'independently_confirmed'
                : 'full_replay_observed');

        return $this->persist($agent, 'full_replay', $result, $baseline, [
            'status' => (string) ($options['status'] ?? $status),
            'anchor_id' => $anchorId > 0 ? $anchorId : null,
            'sibling_kind' => $sibling !== '' ? $sibling : null,
            'performance' => $performance,
            'forward_confirmation' => $verification ?: data_get($result, 'verified_mutation_skill'),
            'metadata' => [
                'protocol' => self::PROTOCOL,
                'promotion_evidence' => false,
                'performance_id' => $performance?->id,
                ...((array) ($options['metadata'] ?? [])),
            ],
        ]);
    }

    /** @return array<string, mixed>|null */
    public function bestMentor(
        string $symbol,
        string $timeframe,
        string $family,
        ?string $target = null,
        ?string $role = null,
    ): ?array {
        if (! $this->available()) return null;

        $rows = LabMutationResponseMap::query()
            ->with('agent')
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->where('strategy_family', $family)
            ->where('status', 'independently_confirmed')
            ->whereNotNull('parameter_key')
            ->when($target !== null && $target !== '', fn ($query) => $query->where('target', $target))
            ->latest('id')
            ->get();
        $rows = $rows->filter(fn (LabMutationResponseMap $row): bool =>
            $row->parameter_key !== null
            && ($row->agent === null || count((array) $row->agent->parameter_diff) === 1)
            && (data_get($row->metadata, 'causal_credit_eligible', null) === true
                // Legacy confirmed maps predate the explicit causal flag. A
                // declared parameter is retained as a mentor only when no
                // contradictory multi-gene marker exists.
                || (data_get($row->metadata, 'causal_credit_eligible', null) === null
                    && data_get($row->metadata, 'single_gene', null) !== false))
        );
        if ($role !== null && $role !== '') {
            $roleRows = $rows->filter(fn (LabMutationResponseMap $row): bool =>
                (string) data_get($row->metadata, 'specialist_role', '') === $role
            );
            // A role-specific child may consume only the same role's
            // independently confirmed capability. Falling back to another
            // specialist would silently turn the council into a shared
            // champion-parameter pool and erase the point of role-specific
            // hypotheses.
            if ($roleRows->isEmpty()) return null;
            $rows = $roleRows;
        }

        $row = $rows->sortByDesc(function (LabMutationResponseMap $candidate): array {
            $delta = (float) data_get($candidate->target_delta, 'delta', 0);
            $improved = (bool) data_get($candidate->target_delta, 'improved', false);
            $target = strtolower((string) $candidate->target);
            $utility = in_array($target, ['drawdown', 'drawdown_risk', 'max_drawdown', 'risk'], true)
                ? -$delta
                : $delta;
            return [$improved ? 1 : 0, $utility, (int) $candidate->id];
        })->first();
        if (! $row) return null;

        return [
            'response_map_id' => (int) $row->id,
            'lab_agent_id' => $row->lab_agent_id,
            'model_version_id' => $row->model_version_id,
            'target' => $row->target,
            'parameter_key' => $row->parameter_key,
            'direction' => $row->direction,
            'sibling_kind' => $row->sibling_kind,
            'target_delta' => $row->target_delta,
            'status' => $row->status,
            'protocol' => self::PROTOCOL,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function progress(string $symbol, string $timeframe, ?string $family = null): array
    {
        if (! $this->available()) {
            return ['protocol' => self::PROTOCOL, 'status' => 'migration_pending', 'count' => 0, 'promotion_evidence' => false];
        }
        $query = LabMutationResponseMap::query()
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->when($family, fn ($builder) => $builder->where('strategy_family', $family));
        $rows = $query->get();
        $confirmed = $rows->where('status', 'independently_confirmed')->count();
        return [
            'protocol' => self::PROTOCOL,
            'status' => 'available',
            'count' => $rows->count(),
            'screen_observations' => $rows->where('stage', 'screening')->count(),
            'full_replay_observations' => $rows->where('stage', 'full_replay')->count(),
            'independently_confirmed' => $confirmed,
            'control_observations' => $rows->where('status', 'control')->count(),
            'promotion_evidence' => false,
        ];
    }

    private function available(): bool
    {
        try {
            return Schema::hasTable('lab_mutation_response_maps');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed>|null */
    private function persist(LabAgent $agent, string $stage, array $result, array $baseline, array $options): ?array
    {
        $diff = (array) ($agent->parameter_diff ?? []);
        $singleGene = count($diff) === 1;
        $key = $singleGene
            ? (string) ($options['parameter_key'] ?? array_key_first($diff) ?? data_get($agent->modelVersion?->metadata, 'hypothesis_contract.changed_gene'))
            : null;
        $old = $key !== null && array_key_exists($key, $diff) ? data_get($diff, $key.'.old') : null;
        $new = $key !== null && array_key_exists($key, $diff) ? data_get($diff, $key.'.new') : null;
        $target = (string) data_get($agent->modelVersion?->metadata, 'repair_anchor.failure_target', data_get($agent->modelVersion?->metadata, 'generation_target', ''));
        $direction = $this->direction($old, $new);
        $targetDelta = $this->targetDelta($target, $baseline, $result);
        $observability = (array) data_get($result, 'mutation_observability', data_get($agent->modelVersion?->metadata, 'mutation_observability', []));
        $controlRelative = (array) data_get($observability, 'control_relative', []);
        $contextual = app(ContextualMutationBanditService::class)->context($agent, $result, $target, $key, $direction);
        $banditReward = app(ContextualMutationBanditService::class)->reward($observability, $controlRelative);
        $responseKey = hash('sha256', json_encode([
            'protocol' => self::PROTOCOL,
            'agent' => $agent->id,
            'model' => $agent->model_version_id,
            'stage' => $stage,
            'run' => data_get($result, 'evidence_run_id'),
            'result' => hash('sha256', json_encode($result, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)),
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $status = (string) ($options['status'] ?? 'observed');
        if ($stage !== 'control' && ! $singleGene) {
            $status = 'diagnostic_multi_gene';
        }
        $row = LabMutationResponseMap::firstOrCreate(['response_key' => $responseKey], [
            'stage' => $stage,
            'status' => $status,
            'symbol' => strtoupper((string) $agent->symbol),
            'timeframe' => strtoupper((string) $agent->timeframe),
            'strategy_family' => (string) $agent->strategy_family,
            'target' => $target !== '' ? $target : null,
            'parameter_key' => $key !== null && $key !== '' ? $key : null,
            'direction' => $direction,
            'sibling_kind' => $options['sibling_kind'] ?? null,
            'lab_agent_id' => $agent->id,
            'model_version_id' => $agent->model_version_id,
            'repair_anchor_id' => $options['anchor_id'] ?? null,
            'evidence_run_id' => (string) data_get($result, 'evidence_run_id'),
            'temporal_window_key' => (string) data_get($result, 'temporal_window_key', data_get($result, 'forward_window_protocol.window_key', '')) ?: null,
            'old_value' => ['value' => $old],
            'new_value' => ['value' => $new],
            'baseline_metrics' => $this->compactMetrics($baseline),
            'observed_metrics' => $this->compactMetrics($result),
            'target_delta' => $targetDelta,
            'non_target_regression' => data_get($observability, 'non_target_regression', data_get($result, 'no_regression_contract', data_get($result, 'differential_no_regression', []))),
            'regime_result' => data_get($result, 'regime_performance', data_get($result, 'robustness_matrix', [])),
            'forward_confirmation' => $options['forward_confirmation'] ?? data_get($result, 'verified_mutation_skill', []),
            'metadata' => [
                'protocol' => self::PROTOCOL,
                'single_gene' => $singleGene,
                'causal_credit_eligible' => $stage === 'control' || $singleGene,
                'mutation_credit_status' => $stage === 'control' || $singleGene ? 'eligible' : 'diagnostic_only',
                'specialist_role' => data_get($agent->modelVersion?->metadata, 'council_specialist_contract.role', data_get($agent->modelVersion?->metadata, 'repair_anchor_sibling.role', data_get($agent->modelVersion?->metadata, 'portfolio_council_lane.specialist_role'))),
                'repair_anchor_sibling_cohort_id' => data_get($agent->modelVersion?->metadata, 'repair_anchor.sibling_cohort_id'),
                'data_manifest_hash' => data_get($result, 'data_manifest.sha256', data_get($result, 'data_manifest.snapshot_sha256')),
                'execution_hash' => data_get($result, 'execution_contract.execution_hash', data_get($result, 'execution_hash')),
                'anchor_delta' => data_get($observability, 'anchor_delta', data_get($targetDelta, 'delta')),
                'control_delta' => data_get($observability, 'control_delta'),
                'control_relative_improved' => (bool) data_get($observability, 'control_relative_improved', false),
                'observable_effect' => (bool) data_get($observability, 'observable_effect', data_get($observability, 'classification') === 'observable_effect'),
                'holdout_confirmation' => data_get($observability, 'holdout_confirmation', ['confirmed' => false]),
                'progress_ladder' => data_get($observability, 'progress_ladder', []),
                'contextual_bandit' => [...$contextual, ...$banditReward],
                'promotion_evidence' => false,
                ...((array) ($options['metadata'] ?? [])),
            ],
        ]);

        return [
            'id' => (int) $row->id,
            'response_key' => $row->response_key,
            'stage' => $row->stage,
            'status' => $row->status,
            'target' => $row->target,
            'parameter_key' => $row->parameter_key,
            'direction' => $row->direction,
            'target_delta' => $row->target_delta,
            'promotion_evidence' => false,
        ];
    }

    private function parentBaseline(LabAgent $agent): array
    {
        $parentIds = array_values(array_filter([(int) $agent->parent_a_model_version_id, (int) $agent->parent_b_model_version_id]));
        if ($parentIds === []) return [];
        return (array) ModelMarketPerformance::query()
            ->whereIn('model_version_id', $parentIds)
            ->where('symbol', $agent->symbol)
            ->where('timeframe', $agent->timeframe)
            ->latest('id')->first()?->metrics;
    }

    /** @return array<string, mixed> */
    public function targetDelta(string $target, array $baseline, array $observed): array
    {
        $before = $this->targetScore($target, $baseline);
        $after = $this->targetScore($target, $observed);
        $delta = $before !== null && $after !== null ? round($after - $before, 6) : null;
        $improved = $before !== null && $after !== null
            ? (in_array(strtolower($target), ['drawdown', 'drawdown_risk', 'max_drawdown', 'risk'], true) ? $delta < 0 : $delta > 0)
            : false;
        return ['baseline' => $before, 'observed' => $after, 'delta' => $delta, 'improved' => $improved];
    }

    private function targetScore(string $target, array $metrics): ?float
    {
        $value = match ($target) {
            'profit_factor' => data_get($metrics, 'profit_factor'),
            'stress_cost' => data_get($metrics, 'screening_survival.stress_cost_pf', data_get($metrics, 'pf_attribution.stress_cost.profit_factor', data_get($metrics, 'stress_test.profit_factor'))),
            'temporal_stability', 'monthly_survival' => data_get($metrics, 'screening_survival.worst_temporal_chunk_pf', data_get($metrics, 'screening_survival.worst_window_pf', data_get($metrics, 'monthly_passport.worst_month_pf'))),
            'regime_coverage' => data_get($metrics, 'screening_survival.worst_regime_pf', data_get($metrics, 'statistical_evidence.edge_quality.worst_regime_pf')),
            'drawdown_risk' => data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown')),
            'trade_frequency' => data_get($metrics, 'total_trades', data_get($metrics, 'entry_funnel.accepted_entries')),
            'architecture' => data_get($metrics, 'profit_factor', data_get($metrics, 'forward_score')),
            default => null,
        };
        return is_numeric($value) ? (float) $value : null;
    }

    /** @return array<string, mixed> */
    private function compactMetrics(array $metrics): array
    {
        return collect($metrics)->only([
            'profit_factor', 'forward_score', 'total_trades', 'winrate',
            'max_drawdown_percent', 'max_drawdown', 'net_profit_percent',
            'screening_survival', 'window_survival', 'monthly_passport', 'pf_attribution',
            'monte_carlo', 'data_manifest', 'execution_contract',
        ])->all();
    }

    private function direction(mixed $old, mixed $new): ?string
    {
        if (is_numeric($old) && is_numeric($new)) {
            return (float) $new > (float) $old ? 'increase' : ((float) $new < (float) $old ? 'decrease' : 'unchanged');
        }
        if (is_bool($old) || is_bool($new)) return (bool) $new ? 'enable' : 'disable';
        return $old === $new ? 'unchanged' : 'alternate';
    }
}
