<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningSettlement;
use App\Models\CausalFoldReceipt;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\ModelVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Evidence-backed progress; raw profit, generation and lesson volume are not authority. */
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
            (array) json_decode($row->assessment, true),
            (array) json_decode($row->sealed_contract, true)))->values()->all();
        $modelIds = $generation->agents()->pluck('model_version_id')->all();
        $trials = Schema::hasTable('descendant_value_trials') ? DB::table('descendant_value_trials')
            ->whereIn('child_model_version_id', $modelIds)->where('status', 'settled')->get() : collect();
        $retained = $trials->filter(fn ($row): bool => $this->retainedTrialVerified($row));
        $receipts = Schema::hasTable('research_experiment_receipts') ? DB::table('research_experiment_receipts')
            ->where('source_type', AgentLearningCausalExperiment::class)->whereIn('source_id', $ids)->get() : collect();
        $validReceipts = $receipts->filter(fn ($row): bool => $this->receiptValid($row));
        $progress = $experiments->map(function ($experiment) use ($validReceipts): array {
            $receipt = $validReceipts->where('source_id', $experiment->id)->sortByDesc('id')->first();
            $complete = $this->technicallyComplete($experiment, $receipt);
            return ['experiment_id' => $experiment->id, 'technically_complete' => $complete,
                'comparison_outcome' => $complete ? $this->comparisonOutcome($receipt, $experiment) : 'technical_incomplete',
                'question_key' => $receipt?->contract_hash ?: (string) data_get($experiment->evidence,
                    'memory_search_receipt.question_key', $experiment->experiment_key),
                'receipt_id' => $receipt?->id, 'promotion_evidence' => false];
        });
        $completed = $progress->where('technically_complete', true);
        $answered = $progress->whereIn('comparison_outcome', ['no_effect', 'harmful', 'positive_candidate']);
        $observedConfirmedLabels = $confirmed->count();
        $confirmed = $confirmed->whereIn('id', $progress->where('comparison_outcome', 'positive_candidate')->pluck('experiment_id')->all())
            ->filter(fn ($experiment): bool => $this->verifiedConfirmedImprovement($experiment));
        $answeredQuestionCount = $answered->unique('question_key')->count();
        $runMilliseconds = (int) LabEvaluationRun::where('lab_generation_id', $generation->id)->sum('duration_ms');
        return ['protocol' => self::PROTOCOL, 'generation_id' => $generation->id,
            'progress_outcomes' => [
                'comparison_scope' => 'canonical_causal_experiments_only',
                'retention_scope' => 'original_replay_verified_descendant_trials',
                'academy_trials_included' => false,
                'academy_progress_owner' => AcademyExperimentSettlementReconcilerService::class,
                'technically_completed_experiments' => ['count' => $completed->count(),
                    'experiment_ids' => $completed->pluck('experiment_id')->values()->all()],
                'question_answering_comparisons' => ['count' => $answeredQuestionCount,
                    'experiment_ids' => $answered->pluck('experiment_id')->values()->all(),
                    'outcome_counts' => $progress->countBy('comparison_outcome')->all(),
                    'underpowered_is_not_no_effect_or_harmful' => true,
                    'comparisons' => $progress->values()->all()],
                'retained_beneficial_changes' => ['distinct_traits' => $retained->map(fn ($row) =>
                        data_get(json_decode($row->evidence, true), 'trait_capsule_hash'))->filter()->unique()->count(),
                    'trial_ids' => $retained->pluck('id')->all(), 'actual_matched_ablation_required' => true],
            ],
            'independent_control_improvement' => ['confirmed_count' => $confirmed->count(),
                'observed_confirmed_labels' => $observedConfirmedLabels,
                'experiment_ids' => $confirmed->pluck('id')->all(), 'effects' => $confirmed->map(fn ($row) =>
                    ['experiment_id' => $row->id, 'component_effect' => data_get($row->evidence, 'component_effect')])->values()->all()],
            'memory_vs_blinded_efficiency' => ['status' => $memory === [] ? 'not_measured' : 'executed_diagnostic',
                'comparisons' => $memory,
                'measured_selector_question_count' => collect($memory)->pluck('search_efficiency.question_key')->filter()->unique()->count(),
                'minimum_distinct_selector_questions' => 5, 'memory_superiority_proven' => false],
            'retained_benefit' => ['distinct_traits' => $retained->map(fn ($row) =>
                    data_get(json_decode($row->evidence, true), 'trait_capsule_hash'))->filter()->unique()->count(),
                'trial_ids' => $retained->pluck('id')->all()],
            'knowledge_per_compute_hour' => ['status' => 'worker_elapsed_proxy',
                'unique_closed_questions' => $validReceipts->unique('contract_hash')->count(),
                'question_answering_comparisons' => $answeredQuestionCount,
                'confirmed_improvements' => $confirmed->count(), 'worker_elapsed_hours' => round($runMilliseconds / 3600000, 6),
                'closed_questions_per_worker_hour' => $runMilliseconds > 0
                    ? round($validReceipts->unique('contract_hash')->count() * 3600000 / $runMilliseconds, 6) : null,
                'answered_questions_per_worker_hour' => $runMilliseconds > 0
                    ? round($answeredQuestionCount * 3600000 / $runMilliseconds, 6) : null,
                'reliable_improvements_per_worker_hour' => $runMilliseconds > 0
                    ? round($confirmed->count() * 3600000 / $runMilliseconds, 6) : null,
                'cpu_hour_efficiency_attested' => false,
                'scope' => 'sum_of_evidence_run_durations_includes_retries_and_shared_batch_cost'],
            'discovery_closures' => $experiments->map(fn ($row) => data_get($row->evidence,
                'prospective_discovery_closure'))->filter()->values()->all(),
            'specialist_council' => app(SpecialistCouncilLifecycleService::class)->progressForModels($modelIds),
            'confirmation_route_readiness' => $this->confirmationRouteReadiness(), 'promotion_evidence' => false];
    }

    private function receiptValid(object $receipt): bool
    {
        $payload = (array) json_decode($receipt->payload, true);
        $contract = (array) ($payload['contract'] ?? []);
        $evidence = (array) ($payload['evidence'] ?? []);
        $workCount = Schema::hasTable('research_experiment_work_items') ? DB::table('research_experiment_work_items')
            ->where('research_experiment_receipt_id', $receipt->id)->count() : 0;
        return ($payload['protocol'] ?? null) === ResearchExperimentConversionKernelService::PROTOCOL
            && data_get($contract, 'source.type') === AgentLearningCausalExperiment::class
            && (int) data_get($contract, 'source.id') === (int) $receipt->source_id
            && hash_equals((string) $receipt->contract_hash, $this->hash($contract))
            && hash_equals((string) $receipt->evidence_hash, $this->hash($evidence))
            && (($workCount === 1 && $receipt->terminal_reason === null)
                || ($workCount === 0 && filled($receipt->terminal_reason)));
    }

    private function verifiedConfirmedImprovement(AgentLearningCausalExperiment $experiment): bool
    {
        $pair = LabLearningLanePair::where('lab_generation_id', $experiment->lab_generation_id)
            ->where('candidate_agent_id', $experiment->guided_agent_id)->where('control_agent_id', $experiment->control_agent_id)
            ->where('pair_integrity_status', 'verified')->where('same_generation', true)->first();
        if (! $pair || ! filled($pair->candidate_data_hash) || ! filled($pair->candidate_execution_hash)
            || $pair->candidate_data_hash !== $pair->control_data_hash || $pair->candidate_execution_hash !== $pair->control_execution_hash
            || ! AgentLearningSettlement::where('source_type', LabLearningLanePair::class)->where('source_id', $pair->id)
                ->where('outcome_status', 'settled')->whereNotNull('settled_at')->exists()) return false;
        $results = [];
        foreach ([$experiment->guided_agent_id, $experiment->blinded_agent_id, $experiment->control_agent_id] as $id) {
            $run = LabEvaluationRun::where('lab_generation_id', $experiment->lab_generation_id)->where('lab_agent_id', $id)
                ->where('phase', 'full_validation')->where('status', 'completed')->latest('id')->first();
            $result = $run ? $this->verifiedReplay($run) : null;
            if ($result === null || (string) $run->data_hash !== (string) $pair->candidate_data_hash) return false;
            $results[$id] = $result;
        }
        return data_get(app(GateMarginService::class)->compare($results[$experiment->guided_agent_id],
            $results[$experiment->control_agent_id], $experiment->target), 'candidate_better') === true;
    }

    public function technicallyComplete(AgentLearningCausalExperiment $experiment, ?object $receipt = null): bool
    {
        $armIds = array_map('intval', [$experiment->guided_agent_id, $experiment->blinded_agent_id, $experiment->control_agent_id]);
        if (min($armIds) < 1 || count(array_unique($armIds)) !== 3) return false;
        $settlement = (array) data_get($experiment->evidence, 'fold_execution.settlement', []);
        if (($settlement['status'] ?? null) === 'completed' && ($settlement['atomic'] ?? false) === true) {
            $folds = CausalFoldReceipt::where('agent_learning_causal_experiment_id', $experiment->id)->orderBy('fold_index')->get();
            $count = (int) ($settlement['fold_count'] ?? 0);
            if ($count < 1 || $folds->count() !== $count || $folds->where('status', 'completed')->count() !== $count
                || $folds->pluck('fold_index')->map(fn ($value): int => (int) $value)->all() !== range(1, $count)
                || $folds->pluck('id')->all() !== ($settlement['receipt_ids'] ?? [])
                || $folds->pluck('response_hash')->all() !== ($settlement['receipt_hashes'] ?? [])
                || ! filled($settlement['aggregate_hash'] ?? null)
                || $folds->pluck('dataset_hash')->filter()->unique()->count() !== 1
                || $folds->pluck('execution_hash')->filter()->unique()->count() !== 1) return false;
            sort($armIds);
            return $folds->every(function ($fold) use ($armIds, $experiment, $count): bool {
                $observedIds = collect((array) data_get($fold->response_payload, 'leaderboard', []))
                    ->pluck('lab_agent_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
                return (int) $fold->lab_generation_id === (int) $experiment->lab_generation_id
                    && (int) $fold->fold_count === $count && $fold->completed_at !== null
                    && filled($fold->dataset_hash) && filled($fold->execution_hash)
                    && (string) data_get($fold->request_payload, 'replay_dataset_hash') === (string) $fold->dataset_hash
                    && (string) data_get($fold->request_payload, 'execution_contract.execution_hash') === (string) $fold->execution_hash
                    && (int) data_get($fold->request_payload, 'policy_context.causal_fold_job.fold_count', $count) === $count
                    && hash_equals((string) $fold->request_hash, $this->hash((array) $fold->request_payload))
                    && hash_equals((string) $fold->response_hash, $this->hash((array) $fold->response_payload))
                    && $observedIds === $armIds;
            });
        }
        if (! $receipt || ! $this->receiptValid($receipt)) return false;
        $closureId = (int) data_get($experiment->evidence, 'prospective_discovery_closure.conversion_receipt.receipt_id');
        if ($closureId !== (int) $receipt->id) return false;
        // The kernel records closure atomically; a receipt alone does not establish that replay completed.
        return collect($armIds)->every(function ($id) use ($experiment): bool {
            $run = LabEvaluationRun::where('lab_generation_id', $experiment->lab_generation_id)->where('lab_agent_id', $id)
                ->where('phase', 'screening')->where('status', 'completed')->latest('id')->first();
            return $run && data_get(app(LabImmutableEvidenceService::class)->learningEligibility($run), 'complete') === true;
        });
    }

    private function comparisonOutcome(?object $receipt, AgentLearningCausalExperiment $experiment): string
    {
        $effect = (array) data_get($experiment->evidence, 'component_effect', []);
        if (data_get($experiment->evidence, 'causal_power.status') === 'unpowered_control') return 'underpowered';
        // These are the canonical confirmation owner's measured, paired window deltas, not a confirmed label.
        if (($effect['protocol'] ?? null) === 'paired_disjoint_window_delta_v1'
            && ($effect['protocol_verified'] ?? false) === true && ($effect['power_contract_applied'] ?? false) === true
            && (int) ($effect['common_window_count'] ?? 0) >= max(3, (int) ($effect['required_common_windows'] ?? 0))
            && count((array) ($effect['window_deltas'] ?? [])) === (int) $effect['common_window_count']
            && is_numeric($effect['mean_delta'] ?? null) && is_finite((float) $effect['mean_delta'])
            && data_get($experiment->evidence, 'causal_arm_parity.passed') === true
            && in_array(data_get($effect, 'target_effect.status'), ['improved', 'not_improved'], true)) {
            $deltas = collect($effect['window_deltas']);
            if ($deltas->pluck('window_id')->filter()->unique()->count() !== $deltas->count()) return 'unassessable';
            $finite = $deltas->every(fn ($row): bool => is_numeric($row['delta'] ?? null) && is_finite((float) $row['delta']));
            if (! $finite) return 'unassessable';
            if ((float) $effect['mean_delta'] < -.00000001
                && $deltas->every(fn ($row): bool => (float) $row['delta'] < -.00000001)) return 'harmful';
            if (abs((float) $effect['mean_delta']) <= .00000001
                && $deltas->every(fn ($row): bool => abs((float) $row['delta']) <= .00000001)) return 'no_effect';
            if (($effect['passed'] ?? false) === true) return 'positive_candidate';
        }
        if (! $receipt) return 'unassessable';
        $quality = (array) data_get(json_decode($receipt->payload, true), 'evidence', []);
        if (($quality['status'] ?? null) === 'data_missing') return 'unassessable';
        if (($quality['status'] ?? null) === 'underpowered' || $receipt->classification === 'UNDERPOWERED') return 'underpowered';
        $observations = (array) ($quality['observations'] ?? []);
        $exact = collect(['guided', 'blinded', 'control'])->every(fn ($role): bool =>
            data_get($observations, $role.'.complete') === true && data_get($observations, $role.'.data_present') === true
            && data_get($observations, $role.'.paired_probe_window_valid') === true);
        if ($exact && ($quality['status'] ?? null) === 'no_effect') return 'no_effect';
        // A behavioral change answers a stage question, not the economic-benefit question.
        if ($exact && ($quality['status'] ?? null) === 'ready_for_independent_validation') return 'positive_candidate';
        return 'unassessable';
    }

    public function confirmationRouteReadiness(): array
    {
        $epochs = app(ResearchPaperEpochContractService::class);
        $windows = app(InstrumentResearchWindowService::class)->readiness();
        return ['historical_causal_research' => ['policy' => 'existing_frozen_causal_contract',
                'allowed_uses' => data_get($epochs->contract(), 'research_epoch.allowed_uses'),
                'independent_window_authority' => false],
            'post_paper_independent_validation' => ['status' => $windows['status'],
                'reason_code' => $windows['reason_code'], 'completed_authorized_window_count' => count($windows['eligible_windows']),
                'future_authorized_window_count' => count($windows['future_windows']),
                'minimum_instrument_windows' => max(3, (int) config('services.instrument_policy.minimum_independent_windows', 3)),
                'unused_selection_provenance_still_required' => true, 'future_plans_are_data' => false,
                'independence_or_execution_proven_by_registry_count' => false], 'promotion_evidence' => false];
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

    /** Recheck the original sibling organisms and replay, rather than counting trial booleans. */
    public function retainedTrialVerified(object $trial): bool
    {
        $evidence = (array) json_decode($trial->evidence, true);
        if (! $this->retainedBenefit($evidence) || $trial->settled_at === null) return false;
        $mentor = ModelVersion::find($trial->mentor_model_version_id);
        $child = ModelVersion::find($trial->child_model_version_id);
        $ablated = ModelVersion::find($evidence['ablated_child_model_version_id']);
        if (! $mentor || ! $child || ! $ablated || $child->id === $ablated->id) return false;
        $contract = (array) data_get($child->metadata, 'authority_descendant', []);
        $ablation = (array) data_get($ablated->metadata, 'authority_descendant', []);
        $gene = (string) ($contract['confirmed_gene'] ?? '');
        $other = (string) ($contract['mutated_gene'] ?? '');
        $baseline = ModelVersion::find((int) ($contract['causal_baseline_model_version_id'] ?? 0));
        $capsule = (array) ($contract['trait_capsule'] ?? []);
        if (! $baseline || $gene === '' || $other === '' || $gene === $other
            || ($contract['protocol'] ?? null) !== EvolutionaryAuthorityFoundryService::PROTOCOL
            || ($ablation['protocol'] ?? null) !== EvolutionaryAuthorityFoundryService::PROTOCOL
            || ($ablation['trait_ablated'] ?? false) !== true
            || (int) ($contract['mentor_model_version_id'] ?? 0) !== (int) $mentor->id
            || (int) ($ablation['mentor_model_version_id'] ?? 0) !== (int) $mentor->id
            || ($contract['paired_ablation_arm'] ?? null) !== ($ablation['arm'] ?? null)
            || data_get(app(ContextualCausalTraitCapsuleService::class)->assess($capsule, $gene), 'valid') !== true) return false;
        foreach (['trait_capsule_hash' => 'capsule_hash', 'activation_context_hash' => 'activation_context.context_hash',
            'instrument_bundle_hash' => 'instrument_bundle.bundle_hash'] as $key => $path) {
            if (! hash_equals((string) $evidence[$key], (string) data_get($capsule, $path, ''))) return false;
        }
        foreach (['trait_capsule_hash', 'data_hash', 'execution_hash', 'confirmed_gene', 'mutated_gene',
            'causal_baseline_model_version_id'] as $key) {
            if (! filled($contract[$key] ?? null) || ($contract[$key] ?? null) !== ($ablation[$key] ?? null)) return false;
        }
        $mentorParameters = (array) $mentor->parameters;
        $childParameters = (array) $child->parameters;
        $ablatedParameters = (array) $ablated->parameters;
        $expectedChild = $mentorParameters;
        $expectedChild[$other] = $childParameters[$other] ?? null;
        $expectedAblated = $childParameters;
        $expectedAblated[$gene] = data_get($baseline->parameters, $gene);
        if (! array_key_exists($gene, $mentorParameters) || ! array_key_exists($other, $mentorParameters)
            || ($childParameters[$other] ?? null) === $mentorParameters[$other]
            || ($childParameters[$gene] ?? null) !== $mentorParameters[$gene]
            || data_get($baseline->parameters, $gene) === $mentorParameters[$gene]
            || $this->hash($expectedChild) !== $this->hash($childParameters)
            || $this->hash($expectedAblated) !== $this->hash($ablatedParameters)) return false;
        $childRun = LabEvaluationRun::where('model_version_id', $child->id)->where('phase', 'full_validation')
            ->where('status', 'completed')->latest('id')->first();
        $ablationRun = LabEvaluationRun::where('model_version_id', $ablated->id)->where('phase', 'full_validation')
            ->where('status', 'completed')->latest('id')->first();
        if (! $childRun || ! $ablationRun || $childRun->lab_generation_id !== $ablationRun->lab_generation_id) return false;
        $childResult = $this->verifiedReplay($childRun);
        $ablationResult = $this->verifiedReplay($ablationRun);
        if ($childResult === null || $ablationResult === null) return false;
        $controlAgent = \App\Models\LabAgent::where('lab_generation_id', $childRun->lab_generation_id)->with('modelVersion')->get()
            ->first(fn ($agent): bool => data_get($agent->modelVersion?->metadata, 'authority_descendant.arm') === 'mentor_control'
                && (int) data_get($agent->modelVersion?->metadata, 'authority_descendant.mentor_model_version_id') === (int) $mentor->id);
        if (! $controlAgent || $this->hash((array) $controlAgent->modelVersion->parameters) !== $this->hash($mentorParameters)) return false;
        $controlRun = LabEvaluationRun::where('lab_agent_id', $controlAgent->id)->where('phase', 'full_validation')
            ->where('status', 'completed')->latest('id')->first();
        $controlResult = $controlRun ? $this->verifiedReplay($controlRun) : null;
        if ($controlResult === null) return false;
        foreach ([$childResult, $ablationResult, $controlResult] as $result) {
            if ((string) data_get($result, 'data_manifest.sha256', data_get($result, 'data_hash')) !== (string) $contract['data_hash']
                || (string) data_get($result, 'execution_contract.execution_hash', data_get($result, 'execution_hash')) !== (string) $contract['execution_hash']) return false;
            if (data_get($result, 'instrument_research_trace.protocol') !== LabInstrumentResearchService::RUNTIME_TRACE_PROTOCOL
                || data_get($result, 'instrument_research_trace.status') !== 'consumed') return false;
            foreach (['assignment_hash_valid', 'parameter_hash_valid', 'runtime_bindings_valid', 'activation_contracts_valid',
                'runtime_observations_valid', 'bundle_fully_activated'] as $flag) {
                if (data_get($result, 'instrument_research_trace.'.$flag) !== true) return false;
            }
        }
        $windows = (array) data_get($childResult, 'forward_window_protocol', []);
        if (($windows['independence_verified'] ?? false) !== true || ($windows['overlap_detected'] ?? true) !== false
            || (int) ($windows['observed_windows'] ?? 0) < 3 || (int) ($windows['positive_windows'] ?? 0) < 3) return false;
        $target = (string) ($contract['target'] ?? '');
        return data_get(app(GateMarginService::class)->compare($childResult, $ablationResult, $target), 'candidate_better') === true
            && data_get(app(GateMarginService::class)->compare($childResult, $controlResult, $target), 'candidate_better') === true
            && $this->poweredContextImproved($childResult, $ablationResult, $capsule, $target)
            && $this->poweredContextImproved($childResult, $controlResult, $capsule, $target);
    }

    private function verifiedReplay(LabEvaluationRun $run): ?array
    {
        $owner = app(LabImmutableEvidenceService::class);
        try {
            $identity = $owner->verifiedModelRuntimeIdentity($run);
            if ($identity === null || ! $run->agent
                || ! hash_equals((string) $run->parameter_hash, $owner->parameterHash($run->agent))) return null;
            if (data_get($owner->learningEligibility($run), 'complete') !== true) return null;
            $response = $owner->latestArtifactPayload($run);
            return is_array($response) && hash_equals((string) $run->response_hash, $owner->hash($response)) ? $response : null;
        } catch (\RuntimeException) {
            return null;
        }
    }

    private function poweredContextImproved(array $candidate, array $control, array $capsule, string $target): bool
    {
        $slices = [];
        foreach ([$candidate, $control] as $result) {
            $slice = collect((array) data_get($result, 'instrument_research_trace.context_slices', []))->first(fn ($slice): bool =>
                is_array($slice) && ($slice['powered'] ?? false) === true
                && data_get(app(ContextualCausalTraitCapsuleService::class)->contextCompatibility($capsule,
                    (array) ($slice['context'] ?? [])), 'status') === 'exact_activation_match');
            if (! $slice) return false;
            $slices[] = (array) ($slice['metrics'] ?? []);
        }
        $key = match ($target) { 'drawdown_risk' => 'max_drawdown_percent',
            'opportunity_recall', 'trade_frequency' => 'trades', default => 'net_pf' };
        $candidateValue = $slices[0][$key] ?? null; $controlValue = $slices[1][$key] ?? null;
        if (! is_numeric($candidateValue) || ! is_numeric($controlValue)
            || ! is_finite((float) $candidateValue) || ! is_finite((float) $controlValue)) return false;
        return $key === 'max_drawdown_percent' ? $candidateValue + .000001 < $controlValue : $candidateValue > $controlValue + .000001;
    }

    public function memoryComparison(array $guided, array $blinded, array $assessment, array $contract = []): array
    {
        $g = data_get($assessment, 'arm_resources.memory_enabled.cpu_seconds');
        $b = data_get($assessment, 'arm_resources.memory_blinded.cpu_seconds');
        $measured = ($assessment['actual_per_arm_cpu_attested'] ?? false) === true
            && ($assessment['resource_scope'] ?? null) === 'economic_replay_only_excludes_shared_features_and_audit'
            && is_numeric($g) && is_numeric($b) && is_finite((float) $g) && is_finite((float) $b) && $g > 0 && $b > 0;
        $caps = ($contract['protocol'] ?? null) === 'causal_equal_budget_observation_v1'
            && ($assessment['equal_preregistered_caps'] ?? false) === true
            && (int) ($contract['fold_count'] ?? 0) > 0 && (int) ($contract['per_arm_fold_seconds_limit'] ?? 0) > 0
            && (int) ($contract['max_rows_per_fold'] ?? 0) > 0
            && count(array_unique((array) ($contract['arm_ids'] ?? []))) === 3;
        $cpuEqual = $measured && $caps && (float) $g === (float) $b;
        $delta = is_numeric($guided['after_cost_expectancy_r'] ?? null) && is_numeric($blinded['after_cost_expectancy_r'] ?? null)
            ? (float) $guided['after_cost_expectancy_r'] - (float) $blinded['after_cost_expectancy_r'] : null;
        $search = (array) ($assessment['selector_search_comparison'] ?? []);
        $searchMeasured = ($search['status'] ?? null) === 'measured_selector_and_replay_diagnostic'
            && data_get($contract, 'selector_observation.status') === 'measured_constructor_observation'
            && filled($search['selector_receipt_hash'] ?? null)
            && hash_equals((string) $search['selector_receipt_hash'], (string) data_get($contract, 'selector_observation.receipt.receipt_hash', ''));
        return ['status' => $measured ? 'measured_diagnostic' : 'actual_arm_compute_not_measured',
            'after_cost_expectancy_delta_r' => $delta,
            'guided_cpu_seconds' => $measured ? (float) $g : null,
            'blinded_cpu_seconds' => $measured ? (float) $b : null,
            'guided_to_blinded_cpu_ratio' => $measured ? round($g / $b, 6) : null,
            'equal_preregistered_replay_caps' => $caps, 'equal_observed_replay_cpu' => $cpuEqual,
            'compute_scope' => $measured ? $assessment['resource_scope'] : null,
            'observed_cpu_relative_difference' => $measured ? round(abs($g - $b) / max($g, $b), 6) : null,
            'fairness_status' => ! $caps ? 'preregistered_equal_caps_not_verified'
                : (! $measured ? 'equal_caps_actual_compute_unknown' : ($cpuEqual ? 'equal_caps_and_observed_replay_cpu' : 'equal_caps_observed_replay_cpu_differs')),
            'selector_search_computation_measured' => false, 'selector_wall_time_measured' => $searchMeasured,
            'search_efficiency' => $searchMeasured ? $search : ['status' => 'not_measured', 'repeated_error_reduction' => null,
                'compute_to_first_useful_hypothesis' => null, 'retention_on_unused_independent_window' => null],
            'superiority_blockers' => ['SELECTOR_SEARCH_COMPUTE_NOT_MEASURED',
                'PREREGISTERED_MULTISCOPE_SEARCH_COMPARISON_REQUIRED', 'ORIGINAL_UNUSED_WINDOW_PROVENANCE_REQUIRED'],
            'independent_selector_superiority_proven' => false, 'promotion_evidence' => false];
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) if (is_array($item)) $value[$key] = $this->canonicalize($item);
        return $value;
    }
}
