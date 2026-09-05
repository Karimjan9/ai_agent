<?php

namespace App\Services;

use App\Models\MtfPlaybookFrozenControlRun;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;

/**
 * Converts a measured Confirmation & Entry funnel failure into at most one
 * causal, single-gene follow-up before the curriculum advances.
 */
class MtfPlaybookLearningDirectorService
{
    public const PROTOCOL = 'mtf_playbook_learning_director_v2_multifidelity';

    public function __construct(
        private AgentResearchPlaybookToolboxService $toolbox,
        private MtfPlaybookFrozenControlService $runner,
    ) {}

    /** @param array<int,string> $preferredModelIds @return array<string,mixed> */
    public function nextTrial(string $symbol, array $preferredModelIds = [], ?string $relatedSymbol = null): array
    {
        $symbol = strtoupper(str_replace(['/', '_', '-'], '', trim($symbol)));
        $runs = $this->currentRuns($symbol, $preferredModelIds);
        $expansion = $this->nextEvidenceBudgetExpansion($runs, $preferredModelIds);
        if ($expansion !== null) {
            return $expansion;
        }
        $repair = $this->nextBoundedConfirmationRepair($runs, $preferredModelIds);
        if ($repair !== null) {
            return $repair;
        }

        $next = $this->toolbox->nextFrozenPriorTrial([], $symbol, [
            'related_symbol' => filled($relatedSymbol) ? strtoupper((string) $relatedSymbol) : null,
        ], $preferredModelIds);
        $modelId = (string) data_get($next, 'tool.id', '');

        return $modelId !== '' ? [
            'protocol' => self::PROTOCOL,
            'status' => 'ready',
            'trial_type' => 'frozen_default',
            'model_id' => $modelId,
            'candidate_overrides' => [],
            'trial_context' => [],
            'promotion_evidence' => false,
        ] : [
            'protocol' => self::PROTOCOL,
            'status' => (string) data_get($next, 'status') === 'catalogue_waiting_for_dependencies'
                ? 'curriculum_waiting_for_dependencies'
                : 'curriculum_complete',
            'trial_type' => null,
            'model_id' => null,
            'candidate_overrides' => [],
            'trial_context' => [],
            'dependency_blocked_model_ids' => (array) data_get($next, 'dependency_blocked_model_ids', []),
            'promotion_evidence' => false,
        ];
    }

    /** @param array<int,string> $preferredModelIds @return array<string,mixed>|null */
    private function nextBoundedConfirmationRepair(Collection $runs, array $preferredModelIds): ?array
    {
        if ($runs->isEmpty() || $preferredModelIds === []) {
            return null;
        }

        foreach ($preferredModelIds as $modelId) {
            $base = $runs->first(function (MtfPlaybookFrozenControlRun $run) use ($modelId): bool {
                $variant = (string) data_get($run->comparison, 'candidate_variant.id', 'frozen_default');

                return (string) $run->research_model_id === $modelId
                    && (string) $run->status === 'completed'
                    && $variant === 'frozen_default'
                    && (string) data_get($run->comparison, 'learning_directive.status') === 'ready';
            });
            if (! $base) {
                continue;
            }

            $existingRepair = $runs->first(fn (MtfPlaybookFrozenControlRun $run): bool => (string) $run->research_model_id === $modelId
                && (int) data_get($run->comparison, 'candidate_variant.source_run_id', 0) === (int) $base->id
                && (string) data_get($run->comparison, 'candidate_variant.id') === 'min_independent_confirmations_2'
                && in_array((string) $run->status, ['started', 'completed'], true));
            if ($existingRepair) {
                continue;
            }

            return [
                'protocol' => self::PROTOCOL,
                'status' => 'ready',
                'trial_type' => 'bounded_confirmation_activity_repair',
                'model_id' => $modelId,
                'candidate_overrides' => ['minimum_independent_confirmations' => 2],
                'trial_context' => [
                    'source_run_id' => (int) $base->id,
                    'reason' => 'confirmation_stage_is_the_measured_activity_bottleneck',
                    'target_metric' => 'candidate_activity',
                    'expected_direction' => 'increase_confirmation_and_entry_ready_counts_without_changing_other_genes',
                    'stopping_rule' => 'one_repair_only_then_advance_model',
                ],
                'promotion_evidence' => false,
            ];
        }

        return null;
    }

    /** More immutable history is cheaper and safer than loosening a gate. */
    private function nextEvidenceBudgetExpansion(Collection $runs, array $preferredModelIds): ?array
    {
        foreach ($preferredModelIds as $modelId) {
            $sources = $runs
                ->filter(fn (MtfPlaybookFrozenControlRun $run): bool => (string) $run->research_model_id === $modelId
                    && (string) $run->status === 'completed'
                    && in_array((string) data_get($run->comparison, 'candidate_variant.variant_class', 'scout'), ['scout', 'evidence_budget_expansion'], true)
                    && $this->promisingUnderpowered((array) $run->comparison)
                    && $this->nextBudget((int) data_get($run->dataset_manifest, 'bounded_entry_rows', MultiTimeframeSnapshotService::RESEARCH_MAX_M5_ROWS)) !== null)
                ->sortByDesc(fn (MtfPlaybookFrozenControlRun $run): int => (int) data_get(
                    $run->dataset_manifest,
                    'bounded_entry_rows',
                    MultiTimeframeSnapshotService::RESEARCH_MAX_M5_ROWS,
                ));

            foreach ($sources as $source) {
                $sourceBudget = (int) data_get($source->dataset_manifest, 'bounded_entry_rows', MultiTimeframeSnapshotService::RESEARCH_MAX_M5_ROWS);
                $targetBudget = $this->nextBudget($sourceBudget);
                if ($targetBudget === null) {
                    continue;
                }
                $existing = $runs->first(fn (MtfPlaybookFrozenControlRun $run): bool => (string) $run->research_model_id === $modelId
                    && (int) data_get($run->comparison, 'candidate_variant.source_run_id', 0) === (int) $source->id
                    && (string) data_get($run->comparison, 'candidate_variant.id') === 'evidence_budget_'.$targetBudget
                    && in_array((string) $run->status, ['started', 'completed'], true));
                if ($existing) {
                    continue;
                }

                return [
                    'protocol' => self::PROTOCOL,
                    'status' => 'ready',
                    'trial_type' => 'promising_underpowered_evidence_expansion',
                    'model_id' => $modelId,
                    'candidate_overrides' => [],
                    'trial_context' => [
                        'source_run_id' => (int) $source->id,
                        'evidence_budget_rows' => $targetBudget,
                        'reason' => 'promising_underpowered_prior_needs_more_events_without_gate_relaxation',
                        'target_metric' => 'candidate_activity_power',
                        'expected_direction' => 'increase_event_count_under_identical_strategy_and_execution_contract',
                        'stopping_rule' => 'stop_at_power_or_40k_then_require_independent_agent_owned_windows',
                    ],
                    'promotion_evidence' => false,
                ];
            }
        }

        return null;
    }

    /** @param array<int,string> $preferredModelIds */
    private function currentRuns(string $symbol, array $preferredModelIds): Collection
    {
        if (! Schema::hasTable('mtf_playbook_frozen_control_runs') || $preferredModelIds === []) {
            return collect();
        }
        $identity = $this->runner->currentIdentity();

        return MtfPlaybookFrozenControlRun::query()
            ->where('symbol', $symbol)
            ->whereIn('research_model_id', $preferredModelIds)
            ->orderByDesc('id')
            ->get()
            ->filter(fn (MtfPlaybookFrozenControlRun $run): bool => (string) data_get($run->comparison, 'python_runtime_hash') === $identity['python_runtime_hash']
                && (string) data_get($run->comparison, 'runner_contract_hash') === $identity['runner_contract_hash']);
    }

    private function nextBudget(int $current): ?int
    {
        $index = array_search($current, MultiTimeframeSnapshotService::RESEARCH_EVIDENCE_BUDGETS, true);

        return $index === false
            ? null
            : (MultiTimeframeSnapshotService::RESEARCH_EVIDENCE_BUDGETS[$index + 1] ?? null);
    }

    private function promisingUnderpowered(array $comparison): bool
    {
        $control = (array) data_get($comparison, 'control', []);
        $candidate = (array) data_get($comparison, 'candidate', []);
        $minimum = max(1, (int) data_get($comparison, 'power.minimum_trades_per_arm', 8));
        $trades = (int) ($candidate['total_trades'] ?? 0);

        return $trades > 0
            && $trades < $minimum
            && (float) ($candidate['profit_factor'] ?? 0) > (float) ($control['profit_factor'] ?? 0)
            && (float) ($candidate['net_profit_percent'] ?? 0) > (float) ($control['net_profit_percent'] ?? 0)
            && (float) ($candidate['max_drawdown_percent'] ?? INF) <= (float) ($control['max_drawdown_percent'] ?? -INF);
    }
}
