<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Four explicitly evidenced outcomes; raw profit and receipt volume are not authority. */
class ExperimentQualityProgressService
{
    public const PROTOCOL = 'experiment_quality_progress_v1';

    public function snapshot(LabGeneration $generation): array
    {
        $experiments = AgentLearningCausalExperiment::where('lab_generation_id', $generation->id)->get();
        $confirmed = $experiments->filter(fn ($row): bool => $row->status === 'confirmed' && $row->confirmed_at !== null);
        $ids = $experiments->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $benchmarkRows = Schema::hasTable('research_compounding_benchmarks') ? DB::table('research_compounding_benchmarks')
            ->where('symbol', $generation->laboratory?->symbol)->where('timeframe', $generation->laboratory?->timeframe)
            ->where('status', 'executed_diagnostic')->get()->filter(fn ($row): bool =>
                in_array((int) data_get(json_decode($row->sealed_contract, true), 'experiment_id'), $ids, true)) : collect();
        $memory = $benchmarkRows->map(fn ($row): array => $this->memoryComparison(
            (array) json_decode($row->memory_enabled_result, true), (array) json_decode($row->memory_blinded_result, true),
            (array) json_decode($row->assessment, true)))->values()->all();
        $modelIds = $generation->agents()->pluck('model_version_id')->all();
        $trials = Schema::hasTable('descendant_value_trials') ? DB::table('descendant_value_trials')
            ->whereIn('child_model_version_id', $modelIds)->where('status', 'settled')->get() : collect();
        $retained = $trials->filter(fn ($row): bool => $this->retainedBenefit((array) json_decode($row->evidence, true)));
        $receipts = Schema::hasTable('research_experiment_receipts') ? DB::table('research_experiment_receipts')
            ->where('source_type', AgentLearningCausalExperiment::class)->whereIn('source_id', $ids)->get() : collect();
        $runMilliseconds = (int) LabEvaluationRun::where('lab_generation_id', $generation->id)->sum('duration_ms');
        return ['protocol' => self::PROTOCOL, 'generation_id' => $generation->id,
            'independent_control_improvement' => ['confirmed_count' => $confirmed->count(),
                'experiment_ids' => $confirmed->pluck('id')->all(), 'effects' => $confirmed->map(fn ($row) =>
                    ['experiment_id' => $row->id, 'component_effect' => data_get($row->evidence, 'component_effect')])->values()->all()],
            'memory_vs_blinded_efficiency' => ['status' => $memory === [] ? 'not_measured' : 'executed_diagnostic',
                'comparisons' => $memory, 'memory_superiority_proven' => false],
            'retained_benefit' => ['distinct_traits' => $retained->map(fn ($row) =>
                    data_get(json_decode($row->evidence, true), 'trait_capsule_hash'))->filter()->unique()->count(),
                'trial_ids' => $retained->pluck('id')->all()],
            'knowledge_per_compute_hour' => ['status' => 'worker_elapsed_proxy',
                'unique_closed_questions' => $receipts->unique('contract_hash')->count(),
                'confirmed_improvements' => $confirmed->count(), 'worker_elapsed_hours' => round($runMilliseconds / 3600000, 6),
                'closed_questions_per_worker_hour' => $runMilliseconds > 0
                    ? round($receipts->unique('contract_hash')->count() * 3600000 / $runMilliseconds, 6) : null,
                'reliable_improvements_per_worker_hour' => $runMilliseconds > 0
                    ? round($confirmed->count() * 3600000 / $runMilliseconds, 6) : null,
                'cpu_hour_efficiency_attested' => false,
                'scope' => 'sum_of_evidence_run_durations_includes_retries_and_shared_batch_cost'],
            'discovery_closures' => $experiments->map(fn ($row) => data_get($row->evidence,
                'prospective_discovery_closure'))->filter()->values()->all(), 'promotion_evidence' => false];
    }

    public function retainedBenefit(array $evidence): bool
    {
        return ($evidence['confirmed_component_only'] ?? false) === true
            && ($evidence['trait_incremental_over_ablation'] ?? false) === true
            && ($evidence['improved_over_mentor'] ?? false) === true
            && ($evidence['other_gene_mutated'] ?? false) === true
            && ($evidence['forward_gate_passed'] ?? false) === true
            && ($evidence['inherited_failure'] ?? true) === false
            && (int) ($evidence['constraint_violations'] ?? 1) === 0
            && (int) ($evidence['independent_windows'] ?? 0) >= 3
            && (int) ($evidence['ablated_child_model_version_id'] ?? 0) > 0
            && data_get($evidence, 'contextual_control_comparison.eligible') === true
            && data_get($evidence, 'contextual_trait_ablation_comparison.eligible') === true
            && in_array(data_get($evidence, 'context_trust.status'), ['probation', 'context_confirmed'], true)
            && ! empty($evidence['trait_capsule_hash']) && ! empty($evidence['activation_context_hash'])
            && ! empty($evidence['instrument_bundle_hash']);
    }

    public function memoryComparison(array $guided, array $blinded, array $assessment): array
    {
        $g = data_get($assessment, 'arm_resources.memory_enabled.cpu_seconds');
        $b = data_get($assessment, 'arm_resources.memory_blinded.cpu_seconds');
        $measured = ($assessment['actual_per_arm_cpu_attested'] ?? false) === true
            && is_numeric($g) && is_numeric($b) && is_finite((float) $g) && is_finite((float) $b) && $g > 0 && $b > 0;
        $delta = is_numeric($guided['after_cost_expectancy_r'] ?? null) && is_numeric($blinded['after_cost_expectancy_r'] ?? null)
            ? (float) $guided['after_cost_expectancy_r'] - (float) $blinded['after_cost_expectancy_r'] : null;
        return ['status' => $measured ? 'measured_diagnostic' : 'actual_arm_compute_not_measured',
            'after_cost_expectancy_delta_r' => $delta,
            'guided_cpu_seconds' => $measured ? (float) $g : null,
            'blinded_cpu_seconds' => $measured ? (float) $b : null,
            'guided_to_blinded_cpu_ratio' => $measured ? round($g / $b, 6) : null,
            'independent_selector_superiority_proven' => false, 'promotion_evidence' => false];
    }
}
