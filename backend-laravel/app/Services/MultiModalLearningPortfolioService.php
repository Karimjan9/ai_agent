<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\ResearchExperimentReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the laboratory's existing learning mechanisms into one bounded
 * compute portfolio. A method assignment is a pre-registered research
 * question, never evidence that the answer is useful and never promotion
 * authority.
 */
class MultiModalLearningPortfolioService
{
    public const PROTOCOL = 'multi_modal_learning_portfolio_v1';

    public const FIDELITY_PROTOCOL = 'question_bounded_fidelity_v1';

    /** Native depth is a measured stage witness; INCONCLUSIVE is not a trade verdict. */
    public function assessNativeReachabilityDepthAudit(string $receiptKey): array
    {
        $receipt = $this->verifiedPlanningReceipt($receiptKey);
        if (! $receipt || $receipt->source_type !== NativeReachabilityDepthAuditService::class) {
            return $this->planningBlocked('ORIGINAL_NATIVE_DEPTH_AUDIT_WITNESS_REQUIRED');
        }
        try { $witness = app(NativeReachabilityDepthAuditService::class)->publishedWitness($receipt); }
        catch (\Throwable $error) { return $this->planningBlocked('ORIGINAL_NATIVE_DEPTH_AUDIT_WITNESS_INVALID'); }
        return ['protocol' => self::FIDELITY_PROTOCOL, 'status' => ($witness['status'] ?? null) === 'measured_native_reachability_depth_audit'
            ? 'measured_native_stage' : 'blocked_dependency', 'native_witness' => $witness,
            'false_rejection_rate' => null, 'population_rate_estimated' => false,
            'cheap_negative_is_final_skill_verdict' => false, 'independence_attested' => false, 'promotion_evidence' => false];
    }

    /** These are proposal ceilings, never overrides of an executor's admission policy. */
    private const FIDELITIES = [
        'semantic' => [0, 30, 'semantic_equivalence_only'],
        'diagnostic' => [1024, 60, 'diagnostic_only'],
        'discovery' => [15000, 300, 'discovery_only'],
        'replication' => [15000, 900, 'matched_replication_only'],
        'independent_validation' => [15000, 900, 'authorized_independent_window_required'],
        'descendant_proof' => [15000, 900, 'target_local_descendant_proof_required'],
    ];

    /**
     * Select resources by the question, not by the wished-for final authority.
     * A plan is not an executed probe. Independent/descendant admission remains
     * exclusively with the existing window and evidence owners.
     *
     * @return array<string,mixed>
     */
    public function planFidelity(array $question): array
    {
        $kind = (string) ($question['kind'] ?? '');
        if (! isset(self::FIDELITIES[$kind])) return $this->planningBlocked('QUESTION_FIDELITY_KIND_REQUIRED');
        [$events, $seconds, $ceiling] = self::FIDELITIES[$kind];
        $requested = (array) ($question['budget'] ?? []);
        $progress = (string) data_get($question, 'learning_progress.recommendation.action', '');
        $budget = [
            'max_evaluated_events' => max(0, min($events, (int) ($requested['max_evaluated_events'] ?? $events))),
            'max_compute_seconds' => max(1, min($seconds, (int) ($requested['max_compute_seconds'] ?? $seconds))),
            'max_experiments' => 1,
        ];
        $dependency = match ($kind) {
            'independent_validation' => 'EXISTING_OWNER_AUTHORIZED_UNUSED_WINDOW_AND_PREREGISTRATION_REQUIRED',
            'descendant_proof' => 'EXISTING_OWNER_TARGET_LOCAL_MATCHED_DESCENDANT_PROOF_REQUIRED',
            default => null,
        };
        if (in_array($progress, ['hold_budget', 'repair_prerequisite'], true)) {
            $dependency = $progress === 'hold_budget' ? 'LEARNING_PROGRESS_HOLD_BUDGET' : 'LEARNING_PROGRESS_PREREQUISITE_REQUIRED';
        }
        $seal = [
            'protocol' => self::FIDELITY_PROTOCOL, 'kind' => $kind,
            'question_key' => $question['question_key'] ?? null,
            'scope' => (array) ($question['scope'] ?? []),
            'source_references' => (array) ($question['source_references'] ?? []),
            'criterion' => (array) ($question['criterion'] ?? []),
            'budget' => $budget, 'authority_ceiling' => $ceiling,
            'dependency' => $dependency,
        ];

        return [...$seal, 'plan_hash' => $this->planningHash($seal),
            'status' => $dependency === null ? 'planned' : 'blocked_dependency',
            'execution_status' => 'not_executed', 'executor_admission_unchanged' => true,
            'cheap_negative_is_final_skill_verdict' => false,
            'negative_requires_random_higher_fidelity_audit' => in_array($kind, ['semantic', 'diagnostic', 'discovery'], true),
            'independence_attested' => false, 'market_edge_attested' => false, 'promotion_evidence' => false];
    }

    /** Freeze the entire rejection pool before any sampled higher-fidelity outcome. */
    public function preregisterRejectionAudit(array $receiptKeys, array $policy): array
    {
        if (count($receiptKeys) > 100 || $receiptKeys === [] || ! filled($policy['seed'] ?? null)
            || ! filled($policy['policy_version'] ?? null)) return $this->planningBlocked('BOUNDED_AUDIT_POOL_SEED_AND_POLICY_REQUIRED');
        $pool = [];
        foreach (array_unique($receiptKeys) as $key) {
            $receipt = $this->verifiedPlanningReceipt((string) $key);
            $witness = (array) data_get($receipt?->payload, 'evidence.fidelity_witness', []);
            if (! $receipt || ($witness['verdict'] ?? null) !== 'rejected'
                || ! in_array($witness['kind'] ?? null, ['semantic', 'diagnostic', 'discovery'], true)
                || ! $this->usableWitnessIdentity($witness)
                || ! $this->verifiedWitnessRun($receipt)) return $this->planningBlocked('AUDIT_REQUIRES_EXACT_SEALED_CHEAP_REJECTIONS');
            $candidate = (string) $witness['candidate_key'];
            if (isset($pool[$candidate])) return $this->planningBlocked('AUDIT_CANDIDATE_DUPLICATED');
            $pool[$candidate] = ['candidate_key' => $candidate, 'receipt_key' => $receipt->receipt_key,
                'evidence_hash' => $receipt->evidence_hash, 'contract_hash' => $receipt->contract_hash,
                'match_identity' => $this->witnessIdentity($receipt, $witness)];
        }
        ksort($pool);
        $policy = ['seed' => (string) $policy['seed'], 'policy_version' => (string) $policy['policy_version'],
            'max_sample' => max(1, min(8, (int) ($policy['max_sample'] ?? 2)))];
        $ranked = array_values($pool);
        usort($ranked, fn (array $a, array $b): int => strcmp(
            $this->planningHash([$policy, $a['candidate_key']]), $this->planningHash([$policy, $b['candidate_key']])));
        $selected = array_slice($ranked, 0, $policy['max_sample']);
        $first = $this->verifiedPlanningReceipt($selected[0]['receipt_key']);
        return $this->sealPlanningEntry('rejection_audit', [
            'policy' => $policy, 'pool' => array_values($pool), 'sample' => $selected,
            'sample_selected_before_higher_fidelity' => true, 'max_experiments' => count($selected),
            'metric' => 'matched_receipt_relative_false_rejection_rate',
            'scope' => ['symbol' => $first->symbol, 'timeframe' => $first->laboratory_timeframe],
        ]);
    }

    /** Missing, old, incomplete or unmatched witnesses never enter the denominator. */
    public function assessRejectionAudit(string $sealKey, array $higherReceiptKeys): array
    {
        $sealed = $this->readPlanningEntry($sealKey, 'rejection_audit');
        if ($sealed === null) return $this->planningBlocked('AUDIT_PREREGISTRATION_MISSING_OR_TAMPERED');
        $spec = $sealed['spec']; $sample = (array) $spec['sample'];
        if (count($higherReceiptKeys) > 8) return $this->planningBlocked('AUDIT_WITNESS_BUDGET_EXCEEDED');
        $matched = []; $falseRejected = 0; $dependencies = [];
        foreach ($higherReceiptKeys as $key) {
            $receipt = $this->verifiedPlanningReceipt((string) $key);
            $witness = (array) data_get($receipt?->payload, 'evidence.fidelity_witness', []);
            $candidate = (string) ($witness['candidate_key'] ?? '');
            $member = collect($sample)->firstWhere('candidate_key', $candidate);
            $candidateOutcomeCount = $candidate === '' ? 0 : ResearchExperimentReceipt::query()
                ->where('payload->evidence->fidelity_witness->preregistration_key', $sealKey)
                ->where('payload->evidence->fidelity_witness->candidate_key', $candidate)->count();
            $run = $this->verifiedWitnessRun($receipt);
            $cheap = $member ? $this->verifiedPlanningReceipt($member['receipt_key']) : null;
            if (! $receipt || ! $cheap || ! $run || isset($matched[$candidate]) || $candidateOutcomeCount !== 1
                || $cheap->evidence_hash !== $member['evidence_hash'] || $cheap->contract_hash !== $member['contract_hash']
                || ! in_array($witness['kind'] ?? null, ['replication', 'independent_validation', 'descendant_proof'], true)
                || ($witness['preregistration_key'] ?? null) !== $sealKey
                || ! $this->usableWitnessIdentity($witness)
                || $this->witnessIdentity($receipt, $witness) !== $member['match_identity']
                || ! $run->started_at || $run->started_at->lessThanOrEqualTo($sealed['recorded_at'])
                || ! $receipt->created_at || $receipt->created_at->lessThanOrEqualTo($sealed['recorded_at'])
                || (int) data_get($run->response_meta, 'decision_trace_completeness.covered_candle_count', 0) <= 0
                || ($witness['verdict'] ?? null) !== ($receipt->classification === 'POSITIVE_CANDIDATE' ? 'accepted' : 'rejected')
                || ! in_array($receipt->classification, ['POSITIVE_CANDIDATE', 'HARMFUL', 'UNREACHABLE'], true)) {
                $dependencies[] = 'MATCHED_PROSPECTIVE_COMPLETE_HIGHER_FIDELITY_WITNESS_REQUIRED';
                continue;
            }
            $matched[$candidate] = $receipt->receipt_key;
            if ($receipt->classification === 'POSITIVE_CANDIDATE') $falseRejected++;
        }
        if (count($matched) !== count($sample)) $dependencies[] = 'SAMPLED_CANDIDATE_WITNESSES_OUTSTANDING';

        return ['protocol' => self::FIDELITY_PROTOCOL, 'status' => $dependencies === [] ? 'measured' : 'blocked_dependency',
            'preregistration_key' => $sealKey, 'sample_count' => count($sample), 'matched_count' => count($matched),
            'false_rejections' => $falseRejected,
            'false_rejection_rate' => count($matched) === 0 ? null : $falseRejected / count($matched),
            'metric_scope' => 'matched_receipt_relative_only_not_unsampled_population',
            'matching_scope' => 'same_sealed_target_window_not_equal_evaluated_subset',
            'witness_receipts' => $matched, 'dependencies' => array_values(array_unique($dependencies)),
            'cheap_negative_is_final_skill_verdict' => false, 'independence_attested' => false, 'promotion_evidence' => false];
    }

    /** Seal a value-of-measurement test, not an order to acquire or fabricate data. */
    public function proposeMeasurementAcquisition(array $hypotheses, array $observation): array
    {
        if (count($hypotheses) < 2 || count($hypotheses) > 8 || ! filled($observation['observation_key'] ?? null)
            || ! filled($observation['source'] ?? null) || ! is_array($observation['scope'] ?? null)
            || ! is_array($observation['cost'] ?? null) || ! $this->utcInstant($observation['decision_as_of_utc'] ?? null)
            || ! $this->sha((string) ($observation['event_set_hash'] ?? ''))
            || ! $this->sha((string) ($observation['criterion_hash'] ?? ''))) {
            return $this->planningBlocked('MEASUREMENT_SEALED_HYPOTHESES_SOURCE_COST_ASOF_SCOPE_REQUIRED');
        }
        $sealedHypotheses = [];
        foreach ($hypotheses as $key) {
            $row = Schema::hasTable('research_knowledge_entries')
                ? DB::table('research_knowledge_entries')->where('knowledge_key', $key)->first() : null;
            if (! $row || $row->authority !== 'research_only' || $row->status !== 'sealed') {
                return $this->planningBlocked('MEASUREMENT_ACTUAL_SEALED_HYPOTHESIS_REQUIRED');
            }
            $claim = json_decode($row->claim, true);
            $evidence = json_decode($row->evidence, true);
            $payload = (array) ($evidence['spec'] ?? []);
            $hash = (string) ($evidence['spec_hash'] ?? '');
            if (! $this->sha($hash) || ! hash_equals($hash, $this->planningHash($payload))
                || ($claim['kind'] ?? null) !== 'competing_hypotheses') {
                return $this->planningBlocked('MEASUREMENT_HYPOTHESIS_SEAL_INVALID');
            }
            if ($this->planningCanonical((array) ($payload['context'] ?? []))
                !== $this->planningCanonical((array) $observation['scope'])) {
                return $this->planningBlocked('MEASUREMENT_HYPOTHESIS_SCOPE_MISMATCH');
            }
            $predicted = data_get($payload, 'predictions.'.$observation['observation_key']);
            if ($predicted === null) return $this->planningBlocked('MEASUREMENT_HYPOTHESIS_PREDICTION_MISSING');
            $sealedHypotheses[] = ['knowledge_key' => $key, 'spec_hash' => $hash, 'prediction' => $predicted];
        }
        if (count(array_unique(array_map(fn (array $row): string => $this->planningHash([$row['prediction']]), $sealedHypotheses))) < 2) {
            return $this->planningBlocked('OBSERVATION_DOES_NOT_SEPARATE_SEALED_HYPOTHESES');
        }
        return $this->sealPlanningEntry('measurement_acquisition', [
            'hypotheses' => $sealedHypotheses, 'observation' => $observation, 'scope' => $observation['scope'],
            'test' => 'matched_existing_data_masked_unmasked', 'max_experiments' => 1,
            'max_receipts' => 2, 'prospective_acquisition_requires_operator_source_cost_authorization' => true,
        ]);
    }

    /** A paired existing-data sensitivity result is not a market-causal or acquisition-value guarantee. */
    public function assessMeasurementAcquisition(string $sealKey, array $receiptKeys): array
    {
        $sealed = $this->readPlanningEntry($sealKey, 'measurement_acquisition');
        if ($sealed === null || count($receiptKeys) !== 2) return $this->planningBlocked('SEALED_MATCHED_MEASUREMENT_PAIR_REQUIRED');
        $observation = $sealed['spec']['observation']; $arms = []; $identity = null;
        foreach ($receiptKeys as $key) {
            $receipt = $this->verifiedPlanningReceipt((string) $key);
            $run = $this->verifiedWitnessRun($receipt);
            $witness = (array) data_get($receipt?->payload, 'evidence.measurement_witness', []);
            $arm = (string) ($witness['arm'] ?? '');
            if (! $receipt || ! $run || ! in_array($arm, ['masked', 'unmasked'], true) || isset($arms[$arm])
                || ($witness['preregistration_key'] ?? null) !== $sealKey
                || ($witness['observation_key'] ?? null) !== $observation['observation_key']
                || ($witness['event_set_hash'] ?? null) !== $observation['event_set_hash']
                || ($witness['criterion_hash'] ?? null) !== $observation['criterion_hash']
                || ($witness['source'] ?? null) !== $observation['source']
                || ! $run->started_at || $run->started_at->lessThanOrEqualTo($sealed['recorded_at'])
                || ! $this->sha((string) ($witness['matched_input_hash'] ?? ''))
                || ! $this->sha((string) ($witness['decisions_hash'] ?? ''))
                || ! is_int($witness['observations'] ?? null) || $witness['observations'] <= 0
                || ! $this->utcInstant($witness['latest_available_at_utc'] ?? null)
                || CarbonImmutable::parse($witness['latest_available_at_utc'])->greaterThan(CarbonImmutable::parse($observation['decision_as_of_utc']))
                || ! is_numeric($witness['criterion_loss'] ?? null) || ! is_finite((float) $witness['criterion_loss'])) {
                return $this->planningBlocked('MEASUREMENT_MATCH_ASOF_COMPLETENESS_OR_CHRONOLOGY_INVALID');
            }
            $match = ['input' => $witness['matched_input_hash'], 'observations' => $witness['observations'],
                'availability' => $this->planningHash((array) ($witness['availability_by_event'] ?? [])),
                'program_hash' => $witness['program_hash'], 'source_hash' => $witness['source_hash'],
                'contract_scope' => data_get($receipt->payload, 'contract.scope'),
                'identity' => data_get($receipt->payload, 'contract.identity')];
            if ($identity !== null && $identity !== $match) return $this->planningBlocked('MEASUREMENT_PAIR_NOT_MATCHED');
            $identity = $match;
            $arms[$arm] = ['receipt_key' => $receipt->receipt_key, ...$witness];
        }
        $gain = (float) $arms['masked']['criterion_loss'] - (float) $arms['unmasked']['criterion_loss'];
        $costKnown = is_numeric(data_get($observation, 'cost.amount')) && (float) data_get($observation, 'cost.amount') >= 0
            && filled(data_get($observation, 'cost.unit'));

        return ['protocol' => self::FIDELITY_PROTOCOL, 'status' => 'measured_existing_data_sensitivity',
            'preregistration_key' => $sealKey, 'criterion_loss_reduction' => $gain,
            'decision_changed' => $arms['masked']['decisions_hash'] !== $arms['unmasked']['decisions_hash'],
            'matched_observations' => $arms['masked']['observations'], 'cost' => $observation['cost'],
            'recommendation' => $gain > 0 && $costKnown ? 'bounded_prospective_acquisition_dependency' : 'no_acquisition_justified',
            'dependencies' => ['OPERATOR_SOURCE_COST_AUTHORIZATION_AND_ACTUAL_OBSERVATION_REQUIRED'],
            'paid_api_calls_authorized' => false, 'masked_unmasked_is_market_causal_proof' => false,
            'fabricated_observations_allowed' => false, 'promotion_evidence' => false];
    }

    /** Describe the actual dependency already selected by the arbiter, never replace its gate. */
    public function measurementDependencyProposal(array $readiness): array
    {
        $source = (array) ($readiness['source_dependency'] ?? []);
        return ['protocol' => self::FIDELITY_PROTOCOL, 'status' => 'blocked_dependency',
            'observation' => 'provider_native_event_membership_and_quotes_for_exact_missing_utc_events',
            'source_evidence' => $source, 'source_sha256' => $readiness['primary_stream_sha256'] ?? null,
            'hypotheses' => [], 'hypotheses_invented' => false,
            'value_of_measurement' => null, 'cost' => ['status' => 'unknown_not_authorized'],
            'dependencies' => ['SEALED_COMPETING_HYPOTHESES_REQUIRED', 'MATCHED_EXISTING_DATA_MASKED_UNMASKED_WITNESS_REQUIRED',
                'OPERATOR_SOURCE_COST_AUTHORIZATION_REQUIRED'],
            'max_experiments' => 1, 'executor' => MultiTimeframeSnapshotService::class,
            'existing_data_gate_unchanged' => true, 'paid_api_calls_authorized' => false,
            'absence_is_market_closure_proof' => false, 'promotion_evidence' => false];
    }

    /** The Council's final seat owner may resolve a stricter method than an allocator proposal. */
    public function planExistingSeat(string $blockKey, string $type, string $method, array $priority, array $identity, ?array $source): array
    {
        $kind = match ($type) {
            'repair_pair', 'activation_factorial', 'phase_scope_probe', 'factorial', 'coverage_guard', 'adversarial_guard' => 'diagnostic',
            'replication' => 'replication', 'transfer', 'descendant' => 'descendant_proof', default => 'discovery',
        };
        $fidelity = $this->planFidelity(['kind' => $kind, 'question_key' => $blockKey,
            'scope' => $identity, 'source_references' => $source === null ? [] : [$source],
            'criterion' => ['scientific_block_type' => $type, 'resolved_owner_method' => $method]]);
        $ranking = null;
        if ((int) ($identity['laboratory_id'] ?? 0) > 0 && is_int($identity['generation_number'] ?? null) && $identity['generation_number'] > 0) {
            $score = (float) ($priority['score'] ?? 0);
            $ranking = app(ResearchKnowledgePortfolioService::class)->rankResearchQuestions([
                ['question_id' => $blockKey, 'learning_method' => $method, 'ready' => true, 'safety_preserved' => true,
                    'cost_ceiling_seconds' => $fidelity['budget']['max_compute_seconds'],
                    'features' => ['expected_value' => $score / (1 + abs($score))],
                    'feature_basis' => 'existing_council_priority_uncalibrated_not_forecast'],
            ], $this->planningHash([$identity, $blockKey, $method, $priority]));
        }

        return ['fidelity_plan' => $fidelity, 'question_selection' => $ranking,
            'planning_identity' => $identity, 'resolved_method_unchanged' => true,
            'selection_application' => 'council_resolved_sole_compatible_question_frozen_before_mutation_no_method_override',
            'forecast_status' => 'unknown_requires_exact_native_fold_scope',
            'hypothesis_dependency' => 'ACTUAL_COMPETING_HYPOTHESES_AND_PROBE_SPEC_REQUIRED', 'promotion_evidence' => false];
    }

    private const METHODS = [
        'failure_directed_repair' => [
            'learns_from' => 'terminal_failure_or_pre_registered_gate_deficit',
            'mechanism' => 'root_cause_hypothesis_then_exact_repair',
            'authority_ceiling' => 'repair_credit_until_independently_replicated',
            'compatible_blocks' => ['exact_repair'],
        ],
        'positive_skill_replication' => [
            'learns_from' => 'positive_control_relative_causal_delta',
            'mechanism' => 'independent_chronological_replication',
            'authority_ceiling' => 'research_mentor',
            'compatible_blocks' => ['replication'],
        ],
        'bayesian_active_learning' => [
            'learns_from' => 'posterior_uncertainty_and_expected_information_gain',
            'mechanism' => 'select_high_value_uncertainty_not_random_novelty',
            'authority_ceiling' => 'information_credit',
            'compatible_blocks' => ['structural_novelty', 'replication'],
        ],
        'counterfactual_factorial' => [
            'learns_from' => 'component_main_effect_and_interaction_uncertainty',
            'mechanism' => 'paired_a_b_marginal_screen_then_dedicated_five_arm_interaction',
            'authority_ceiling' => 'information_credit_until_dedicated_interaction_settlement',
            'compatible_blocks' => ['factorial_interaction'],
            'minimum_pairs_per_experiment' => 2,
        ],
        'quality_diversity_novelty' => [
            'learns_from' => 'contextual_archive_coverage_gap',
            'mechanism' => 'contextual_map_elites_cell_discovery',
            'authority_ceiling' => 'information_credit',
            'compatible_blocks' => ['structural_novelty'],
        ],
        'context_transfer_validation' => [
            'learns_from' => 'confirmed_local_skill_and_target_context_deficit',
            'mechanism' => 'source_candidate_vs_target_novelty_then_frozen_transfer_matrix',
            'authority_ceiling' => 'information_credit_until_target_local_transfer_proof',
            'compatible_blocks' => ['descendant_challenge'],
            'minimum_pairs_per_experiment' => 2,
        ],
        'adversarial_robustness' => [
            'learns_from' => 'historically_bounded_stress_boundary',
            'mechanism' => 'sealed_red_team_replay',
            'authority_ceiling' => 'robustness_diagnostic',
            'compatible_blocks' => ['continuity_adversarial_guard'],
        ],
        'elite_rehearsal_guard' => [
            'learns_from' => 'confirmed_contextual_elite_archive',
            'mechanism' => 'shadow_replay_and_non_regression_rehearsal',
            'authority_ceiling' => 'continuity_only_no_replacement_authority',
            'compatible_blocks' => ['continuity_adversarial_guard'],
        ],
    ];

    /** @return array<string,mixed> */
    public function planForLab(AiLaboratory $lab, array $authorityBlocks, array $contextualEvidence = []): array
    {
        $behavior = app(TypedInstrumentFoundryService::class)->behaviorProposalEvidence((string) $lab->symbol, (string) $lab->timeframe);
        $plan = $this->allocate($authorityBlocks, [...$this->signals($lab, $contextualEvidence),
            'observed_behavior_priority' => $behavior['priority_signal']],
            (array) ($contextualEvidence['__planning_identity'] ?? []));
        $references = $this->sourceReferences($lab);
        $plan['behavior_archive_consumption'] = $behavior;
        $plan['native_depth_audit_witnesses'] = $this->nativeDepthAuditWitnesses($lab);
        if ($behavior['matched_pairs'] !== []) {
            $references['previous_archive_reference'] = $references['archive'] ?? null;
            $references['observed_behavior_archive'] = ['source_type' => 'original_observed_behavior_archive',
                'receipt_hash' => $behavior['receipt_hash'], 'sources' => $behavior['sources'],
                'matched_pairs' => $behavior['matched_pairs'], 'authority_ceiling' => $behavior['authority_ceiling'],
                'confirmed_value' => null, 'promotion_evidence' => false];
            // The final native seat owner consumes this existing key as well
            // as the legacy allocator's blocks; no new selector is introduced.
            $references['archive'] = $references['observed_behavior_archive'];
        }
        $signalSnapshot = (array) $plan['signals'];
        $plan['source_references'] = $references;
        $plan['blocks'] = collect((array) $plan['blocks'])->map(function (array $block) use ($references, $signalSnapshot): array {
            $method = (string) $block['learning_method'];
            $source = match ($method) {
                'failure_directed_repair' => $references['failure'] ?? null,
                'positive_skill_replication', 'counterfactual_factorial', 'context_transfer_validation' => $references['causal_skill'] ?? null,
                'bayesian_active_learning' => $references['information'] ?? null,
                'quality_diversity_novelty' => $references['observed_behavior_archive'] ?? $references['archive'] ?? null,
                'adversarial_robustness' => $references['adversarial'] ?? null,
                'elite_rehearsal_guard' => $references['economic_parent'] ?? null,
                default => null,
            };
            $historicalContextRequired = in_array($method, [
                'positive_skill_replication', 'counterfactual_factorial',
                'context_transfer_validation', 'elite_rehearsal_guard',
            ], true);
            $interventionRequired = in_array($method, [
                'positive_skill_replication', 'counterfactual_factorial', 'context_transfer_validation',
            ], true);
            if ($historicalContextRequired && ($source === null
                || ! $this->usableContextScope((array) data_get($source, 'context_scope', []))
                || ($interventionRequired && (array) data_get($source, 'intervention', []) === []))) {
                $deferredMethod = $method;
                $method = $deferredMethod === 'elite_rehearsal_guard'
                    ? 'adversarial_robustness'
                    : 'bayesian_active_learning';
                $block['deferred_learning_method'] = $deferredMethod;
                $block['deferred_reason'] = match (true) {
                    $source === null => 'EVIDENCE_SOURCE_MISSING',
                    ! $this->usableContextScope((array) data_get($source, 'context_scope', [])) => 'EVIDENCE_SOURCE_CONTEXT_NOT_RECONSTRUCTABLE',
                    default => 'EVIDENCE_SOURCE_INTERVENTION_NOT_EXECUTABLE',
                };
                $block['learning_method'] = $method;
                $block['learns_from'] = self::METHODS[$method]['learns_from'];
                $block['mechanism'] = 'recover_missing_source_scope_before_'.self::METHODS[$deferredMethod]['mechanism'];
                $block['authority_ceiling'] = 'information_credit';
                unset($block['experiment_topology']);
                $source = null;
            }
            $sourceRequired = in_array($method, [
                'positive_skill_replication', 'counterfactual_factorial',
                'context_transfer_validation', 'elite_rehearsal_guard',
            ], true);
            $sourceContextRequired = in_array($method, [
                'positive_skill_replication', 'counterfactual_factorial',
                'context_transfer_validation', 'elite_rehearsal_guard',
            ], true);
            $block['source_reference'] = $source;
            $block['source_reference_required'] = $sourceRequired;
            $block['source_context_required'] = $sourceContextRequired;
            $block['source_reference_status'] = $source !== null
                ? 'bound_before_mutation'
                : ($sourceRequired ? 'missing_fail_closed' : 'pre_registered_without_historical_source');
            $block['fidelity_plan'] = $this->planFidelity([
                ...((array) $block['fidelity_plan']),
                'source_references' => $source === null ? [] : [$source],
            ]);
            $receipt = (array) $block['selection_receipt'];
            $receipt['receipt_hash'] = hash('sha256', json_encode([
                'protocol' => self::PROTOCOL,
                'block_index' => $block['block_index'],
                'authority_block_type' => $block['authority_block_type'],
                'learning_method' => $method,
                'source_reference' => $source,
                'behavior_archive_consumption_hash' => $references['observed_behavior_archive']['receipt_hash'] ?? null,
                'experiment_topology' => data_get($block, 'experiment_topology'),
                'fidelity_plan_hash' => $block['fidelity_plan']['plan_hash'] ?? null,
                'question_selection' => $block['question_selection'] ?? null,
                'discriminating_probe_plan_hash' => data_get($block, 'discriminating_probe_plan.plan_hash'),
                'signals' => $signalSnapshot,
            ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
            $block['selection_receipt'] = $receipt;
            $block['selection_receipt']['behavior_archive_consumption_hash'] = $references['observed_behavior_archive']['receipt_hash'] ?? null;

            return $block;
        })->all();
        $plan['pair_allocations'] = collect(array_keys(self::METHODS))->mapWithKeys(fn (string $method): array => [
            $method => collect($plan['blocks'])->where('learning_method', $method)->count(),
        ])->all();
        $plan['active_methods'] = array_keys(array_filter($plan['pair_allocations'], fn (int $count): bool => $count > 0));
        $plan['method_diversity'] = count($plan['active_methods']);
        if (isset($contextualEvidence['rejected_receipt_keys'], $contextualEvidence['rejection_audit_policy'])) {
            $plan['rejection_audit'] = $this->preregisterRejectionAudit(
                (array) $contextualEvidence['rejected_receipt_keys'], (array) $contextualEvidence['rejection_audit_policy'],
            );
        }
        if (isset($contextualEvidence['sealed_hypothesis_keys'], $contextualEvidence['measurement_observation'])) {
            $plan['measurement_acquisition_proposal'] = $this->proposeMeasurementAcquisition(
                (array) $contextualEvidence['sealed_hypothesis_keys'], (array) $contextualEvidence['measurement_observation'],
            );
        }

        return $plan;
    }

    /**
     * Pure deterministic allocator used by generation construction and tests.
     *
     * @param  array<int,array<string,mixed>>  $authorityBlocks
     * @param  array<string,int|float>  $signals
     * @return array<string,mixed>
     */
    public function allocate(array $authorityBlocks, array $signals = [], array $planningIdentity = []): array
    {
        $signals = $this->normalizeSignals($signals);
        $scores = $this->scores($signals);
        $allocations = array_fill_keys(array_keys(self::METHODS), 0);
        $blocks = [];

        foreach (array_values($authorityBlocks) as $index => $authorityBlock) {
            $blockType = (string) data_get($authorityBlock, 'block_type', 'structural_novelty');
            $questionSelection = null; $discriminatingProbe = null; $selectedQuestion = [];
            $eligible = collect(self::METHODS)
                ->filter(fn (array $definition, string $method): bool =>
                    in_array($blockType, (array) $definition['compatible_blocks'], true)
                    && $this->available($method, $signals)
                );
            if ($eligible->isEmpty()) {
                // Every known authority block has a safe method. This fallback
                // is intentionally diagnostic if a future block is introduced
                // without first extending this constitution.
                $method = 'bayesian_active_learning';
                $unsupportedBlock = true;
            } else {
                $method = (string) $eligible->keys()->sortByDesc(function (string $candidate) use ($scores, $allocations): float {
                    $coverageBoost = $allocations[$candidate] === 0 ? .35 : 0.0;

                    return (($scores[$candidate] ?? 0.0) + $coverageBoost) / (1 + (.55 * $allocations[$candidate]));
                })->first();
                $unsupportedBlock = false;
            }
            // Rank only already-compatible/available questions inside this
            // authority seat. Never reorder arbiter readiness tiers or invent
            // another dispatcher. Legacy blocks retain their existing choice.
            $questions = (array) ($authorityBlock['question_candidates'] ?? []);
            $seed = (string) ($authorityBlock['question_selection_seed'] ?? '');
            $nativeIdentity = (int) ($planningIdentity['laboratory_id'] ?? 0) > 0
                && is_int($planningIdentity['generation_number'] ?? null) && $planningIdentity['generation_number'] > 0
                && filled($planningIdentity['symbol'] ?? null) && filled($planningIdentity['timeframe'] ?? null);
            if ($questions === [] && $nativeIdentity && ! $eligible->isEmpty()) {
                $kind = match ($blockType) {
                    'exact_repair', 'factorial_interaction', 'continuity_adversarial_guard' => 'diagnostic',
                    'replication' => 'replication', 'descendant_challenge' => 'descendant_proof', default => 'discovery',
                };
                $seed = $this->planningHash([self::FIDELITY_PROTOCOL, $planningIdentity, $index, $blockType, $signals]);
                foreach ($eligible->keys() as $candidateMethod) {
                    $coverageBoost = $allocations[$candidateMethod] === 0 ? .35 : 0.0;
                    $score = (($scores[$candidateMethod] ?? 0.0) + $coverageBoost) / (1 + .55 * $allocations[$candidateMethod]);
                    $questions[] = ['question_id' => $this->planningHash([$seed, $candidateMethod]),
                        'learning_method' => $candidateMethod, 'kind' => $kind, 'ready' => true, 'safety_preserved' => true,
                        'cost_ceiling_seconds' => self::FIDELITIES[$kind][1],
                        'features' => ['expected_value' => $score / (1 + abs($score))],
                        'feature_basis' => 'existing_allocation_heuristic_uncalibrated_not_forecast',
                        'readiness_basis' => 'existing_method_availability_with_final_source_reattestation_unchanged'];
                }
            }
            if ($questions !== [] && $seed !== '' && ! $eligible->isEmpty()) {
                $questions = array_values(array_filter($questions, fn ($question): bool => is_array($question)
                    && $eligible->has((string) ($question['learning_method'] ?? ''))
                    && isset(self::FIDELITIES[(string) ($question['kind'] ?? '')])
                    && is_numeric($question['cost_ceiling_seconds'] ?? null)
                    && is_finite((float) $question['cost_ceiling_seconds'])
                    && $question['cost_ceiling_seconds'] > 0
                    && $question['cost_ceiling_seconds'] <= self::FIDELITIES[$question['kind']][1]));
                $questionSelection = app(ResearchKnowledgePortfolioService::class)->rankResearchQuestions(
                    $questions, $seed, $authorityBlock['research_policy_key'] ?? null,
                );
                $questionSelection['planning_identity'] = $planningIdentity;
                $questionSelection['candidate_specs'] = $questions;
                $questionSelection['heuristic_is_forecast_evidence'] = false;
                $selectedId = data_get($questionSelection, 'ranking.0.question_id');
                $selectedQuestion = $selectedId === null ? [] : (array) collect($questions)->firstWhere('question_id', $selectedId);
                if ($selectedQuestion !== []) $method = $selectedQuestion['learning_method'];
                $forecastSpec = (array) ($selectedQuestion['forecast_spec'] ?? []);
                $forecastScope = (array) ($forecastSpec['scope'] ?? $forecastSpec);
                if (array_diff(['symbol', 'timeframe', 'family', 'target', 'gene', 'baseline_hash', 'data_hash',
                    'execution_hash', 'runtime_hash'], array_keys($forecastScope)) === []) {
                    $questionSelection['prospective_forecast'] = app(ResearchKnowledgePortfolioService::class)->predictExperiment($forecastSpec);
                    $questionSelection['applicability_map'] = app(ResearchKnowledgePortfolioService::class)->applicabilityMap(
                        $forecastSpec, (array) ($selectedQuestion['candidate_contexts'] ?? []),
                    );
                }
            }
            $hypotheses = (array) ($authorityBlock['competing_hypotheses'] ?? []);
            $probes = (array) ($authorityBlock['discriminating_probes'] ?? []);
            $probeContext = (array) ($authorityBlock['question_scope'] ?? []);
            if (count($hypotheses) >= 2 && $probes !== [] && filled($probeContext['question_key'] ?? null)
                && filled($probeContext['baseline_hash'] ?? null) && filled($probeContext['data_hash'] ?? null)) {
                $discriminatingProbe = app(ResearchKnowledgePortfolioService::class)->chooseDiscriminatingProbe($hypotheses, $probes, $probeContext);
            }
            $allocations[$method]++;
            $definition = self::METHODS[$method];
            $fidelity = $this->planFidelity([
                'kind' => (string) ($selectedQuestion['kind'] ?? $authorityBlock['question_kind'] ?? match ($blockType) {
                    'exact_repair', 'factorial_interaction', 'continuity_adversarial_guard' => 'diagnostic',
                    'replication' => 'replication',
                    'descendant_challenge' => 'descendant_proof',
                    default => 'discovery',
                }),
                'question_key' => $selectedQuestion['question_id'] ?? $authorityBlock['question_key'] ?? self::PROTOCOL.'|'.$index.'|'.$blockType,
                'scope' => (array) ($authorityBlock['question_scope'] ?? []),
                'criterion' => (array) ($authorityBlock['question_criterion'] ?? []),
                'budget' => [...((array) ($authorityBlock['question_budget'] ?? [])),
                    ...($selectedQuestion === [] ? [] : ['max_compute_seconds' => $selectedQuestion['cost_ceiling_seconds']])],
            ]);
            $receiptPayload = [
                'protocol' => self::PROTOCOL,
                'block_index' => (int) data_get($authorityBlock, 'block_index', $index + 1),
                'authority_block_type' => $blockType,
                'learning_method' => $method,
                'score' => round((float) ($scores[$method] ?? 0.0), 6),
                'signals' => $signals,
                'fidelity_plan_hash' => $fidelity['plan_hash'] ?? null,
                'question_selection' => $questionSelection,
                'discriminating_probe_plan_hash' => $discriminatingProbe['plan_hash'] ?? null,
            ];
            $blocks[] = [
                'protocol' => self::PROTOCOL,
                'block_index' => $receiptPayload['block_index'],
                'authority_block_type' => $blockType,
                'learning_method' => $method,
                'learns_from' => $definition['learns_from'],
                'mechanism' => $definition['mechanism'],
                'authority_ceiling' => $definition['authority_ceiling'],
                'pair_roles' => ['exact_frozen_control', 'candidate'],
                'requires_exact_frozen_control' => true,
                'selection_must_precede_mutation' => true,
                'settlement_must_link_to_receipt' => true,
                'confirmed_elite_replacement_allowed' => false,
                'cross_context_authority_transfer_allowed' => false,
                'research_nursery_only' => true,
                'unsupported_authority_block' => $unsupportedBlock,
                'fidelity_plan' => $fidelity,
                'question_selection' => $questionSelection,
                'discriminating_probe_plan' => $discriminatingProbe,
                'planning_dependency' => $questionSelection === null ? 'EXACT_GENERATION_PLANNING_IDENTITY_OR_SEALED_QUESTION_SPECS_REQUIRED' : null,
                'hypothesis_dependency' => $discriminatingProbe === null ? 'ACTUAL_COMPETING_HYPOTHESES_AND_PROBE_SPEC_REQUIRED' : null,
                'selection_receipt' => [
                    'receipt_hash' => hash('sha256', json_encode($receiptPayload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
                    'status' => 'pre_registered',
                    'selected_before_mutation' => true,
                    'result_link_pending' => true,
                    'promotion_evidence' => false,
                ],
                'promotion_evidence' => false,
            ];
        }

        $blocks = $this->bindMultiPairTopologies($blocks);
        $activeMethods = array_keys(array_filter($allocations, fn (int $count): bool => $count > 0));

        return [
            'protocol' => self::PROTOCOL,
            'status' => $blocks === [] ? 'not_applicable' : 'allocated',
            'planning_identity' => $planningIdentity,
            'signals' => $signals,
            'planning_identity' => $planningIdentity,
            'method_definitions' => self::METHODS,
            'scores' => $scores,
            'pair_allocations' => $allocations,
            'active_methods' => $activeMethods,
            'method_diversity' => count($activeMethods),
            'blocks' => $blocks,
            'constitution' => [
                'failure_learning_is_one_method_not_the_only_method' => true,
                'positive_delta_creates_replication_pressure' => true,
                'uncertainty_buys_information_before_blind_search' => true,
                'interaction_claim_requires_factorial_counterfactual' => true,
                'local_skill_never_becomes_global_authority_by_transfer' => true,
                'best_known_specialist_is_monotonic' => true,
                'challengers_run_in_research_nursery' => true,
                'deployment_uses_confirmed_elites_only' => true,
                'all_methods_require_frozen_control' => true,
                'question_appropriate_fidelity_never_weakens_admission' => true,
                'cheap_negative_requires_matched_random_audit_not_final_skill_rejection' => true,
            ],
            'promotion_evidence' => false,
        ];
    }

    private function planningBlocked(string $reason): array
    {
        return ['protocol' => self::FIDELITY_PROTOCOL, 'status' => 'blocked_dependency',
            'reason' => $reason, 'execution_status' => 'not_executed', 'promotion_evidence' => false];
    }

    /** Bounded published diagnostic observations; never ordinary success/credit labels. */
    private function nativeDepthAuditWitnesses(AiLaboratory $lab): array
    {
        if (! Schema::hasTable('research_experiment_receipts')) return [];
        $keys = ResearchExperimentReceipt::where('source_type', NativeReachabilityDepthAuditService::class)
            ->where('symbol', $lab->symbol)->where('laboratory_timeframe', $lab->timeframe)
            ->latest('id')->limit(4)->pluck('receipt_key');
        $observations = [];
        foreach ($keys as $key) {
            $assessment = $this->assessNativeReachabilityDepthAudit($key);
            if (isset($assessment['native_witness'])) $observations[] = $assessment;
        }
        return $observations;
    }

    /** Immutable local journal; duplicate delivery never moves the preregistration timestamp. */
    private function sealPlanningEntry(string $kind, array $spec): array
    {
        if (! Schema::hasTable('research_knowledge_entries')) return $this->planningBlocked('RESEARCH_JOURNAL_UNAVAILABLE');
        $hash = $this->planningHash($spec);
        $key = $this->planningHash([self::FIDELITY_PROTOCOL, $kind, $hash]);
        $registeredAt = CarbonImmutable::now('UTC')->startOfSecond();
        $registeredUtc = $registeredAt->toIso8601ZuluString();
        DB::table('research_knowledge_entries')->insertOrIgnore([
            'knowledge_key' => $key, 'knowledge_type' => 'PROCEDURAL', 'subject_type' => self::class,
            'subject_key' => $hash, 'symbol' => data_get($spec, 'scope.symbol'), 'timeframe' => data_get($spec, 'scope.timeframe'),
            'authority' => 'research_only', 'freshness' => 'active', 'status' => 'sealed',
            'scope' => json_encode((array) ($spec['scope'] ?? []), JSON_PRESERVE_ZERO_FRACTION),
            'claim' => json_encode(['protocol' => self::FIDELITY_PROTOCOL, 'kind' => $kind, 'promotion_evidence' => false]),
            'evidence' => json_encode(['spec' => $spec, 'spec_hash' => $hash, 'registered_at_utc' => $registeredUtc,
                'seal_hash' => $this->planningHash([$hash, $registeredUtc, $kind])], JSON_PRESERVE_ZERO_FRACTION),
            'dependencies' => json_encode(['no_executor_or_acquisition_authorization' => true]),
            'recorded_at' => $registeredAt, 'created_at' => $registeredAt, 'updated_at' => $registeredAt,
        ]);
        $sealed = $this->readPlanningEntry($key, $kind);
        if ($sealed === null) return $this->planningBlocked('PREREGISTRATION_TAMPERED');

        return ['protocol' => self::FIDELITY_PROTOCOL, 'status' => 'preregistered',
            'preregistration_key' => $key, 'spec_hash' => $hash,
            'recorded_at_utc' => $sealed['recorded_at']->toIso8601String(), 'spec' => $spec,
            'execution_status' => 'not_executed', 'promotion_evidence' => false];
    }

    private function readPlanningEntry(string $key, string $kind): ?array
    {
        if (! Schema::hasTable('research_knowledge_entries')) return null;
        $row = DB::table('research_knowledge_entries')->where('knowledge_key', $key)->first();
        if (! $row || $row->subject_type !== self::class || $row->knowledge_type !== 'PROCEDURAL'
            || $row->status !== 'sealed' || $row->authority !== 'research_only') return null;
        $claim = json_decode($row->claim, true); $evidence = json_decode($row->evidence, true);
        if (($claim['protocol'] ?? null) !== self::FIDELITY_PROTOCOL || ($claim['kind'] ?? null) !== $kind
            || ! is_array($evidence['spec'] ?? null)) return null;
        $hash = $this->planningHash($evidence['spec']);
        $registeredUtc = CarbonImmutable::parse($row->recorded_at, 'UTC')->toIso8601ZuluString();
        if (($evidence['spec_hash'] ?? null) !== $hash || $row->subject_key !== $hash
            || ($evidence['registered_at_utc'] ?? null) !== $registeredUtc
            || ($evidence['seal_hash'] ?? null) !== $this->planningHash([$hash, $registeredUtc, $kind])
            || CarbonImmutable::parse($row->created_at, 'UTC')->toIso8601ZuluString() !== $registeredUtc
            || $key !== $this->planningHash([self::FIDELITY_PROTOCOL, $kind, $hash])) return null;

        return ['spec' => $evidence['spec'], 'recorded_at' => CarbonImmutable::parse($row->recorded_at, 'UTC')];
    }

    private function verifiedPlanningReceipt(string $key): ?ResearchExperimentReceipt
    {
        if (! Schema::hasTable('research_experiment_receipts')) return null;
        $receipt = ResearchExperimentReceipt::where('receipt_key', $key)->first();
        if (! $receipt) return null;
        $contract = (array) data_get($receipt->payload, 'contract', []);
        $evidence = (array) data_get($receipt->payload, 'evidence', []);
        $contractHash = $this->planningHash($contract); $evidenceHash = $this->planningHash($evidence);
        $expected = hash('sha256', implode('|', [ResearchExperimentConversionKernelService::PROTOCOL,
            data_get($contract, 'source.type'), data_get($contract, 'source.id', 'none'), $contractHash, $evidenceHash]));

        return $receipt->contract_hash === $contractHash && $receipt->evidence_hash === $evidenceHash
            && $receipt->receipt_key === $expected ? $receipt : null;
    }

    /** At most two small measurement responses/eight audit responses, never a full-archive normal gate. */
    private function verifiedWitnessRun(?ResearchExperimentReceipt $receipt): ?LabEvaluationRun
    {
        if (! $receipt) return null;
        $evidence = (array) data_get($receipt->payload, 'evidence', []);
        $run = LabEvaluationRun::where('run_id', $evidence['evidence_run_id'] ?? '')->first();
        if (! $run || $run->response_hash !== ($evidence['response_hash'] ?? null)
            || $run->data_hash !== data_get($receipt->payload, 'contract.identity.data_and_mtf_hash')) return null;
        $owner = app(LabImmutableEvidenceService::class);
        $requestArtifact = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_request')->first();
        if (! $requestArtifact || (int) data_get($requestArtifact->metadata, 'uncompressed_byte_size', PHP_INT_MAX) > 2_000_000
            || ! $owner->learningEligibility($run)['complete'] || $owner->verifiedModelRuntimeIdentity($run) === null) return null;
        $artifact = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_response')
            ->where('sha256', $run->response_hash)->first();
        if (! $artifact || (int) data_get($artifact->metadata, 'uncompressed_byte_size', PHP_INT_MAX) > 2_000_000) return null;
        try { $response = $owner->readArtifactPayload($artifact); } catch (\Throwable) { return null; }
        foreach (['fidelity_witness', 'measurement_witness'] as $field) {
            if (isset($evidence[$field]) && ($response[$field] ?? null) !== $evidence[$field]) return null;
        }
        if (! isset($evidence['fidelity_witness']) && ! isset($evidence['measurement_witness'])) return null;
        $fidelity = (array) ($evidence['fidelity_witness'] ?? []);
        $eventRows = [];
        foreach ((array) ($response['decision_trace'] ?? []) as $event) {
            if (in_array($event['event_type'] ?? null, ['signal_evaluation', 'position_management'], true)) {
                $eventRows[(int) $event['candle_index']] = ['candle_index' => (int) $event['candle_index'], 'candle_time' => $event['candle_time']];
            }
        }
        ksort($eventRows);
        $eventHash = $this->planningHash(array_values($eventRows));
        $range = (array) data_get($receipt->payload, 'contract.scope.physical_event_range_utc', []);
        if (! $this->utcInstant($range['start'] ?? null) || ! $this->utcInstant($range['end'] ?? null)
            || ! $this->sha((string) data_get($receipt->payload, 'contract.identity.window_plan_hash'))
            || CarbonImmutable::parse($range['start'])->greaterThanOrEqualTo(CarbonImmutable::parse($range['end']))) return null;
        foreach ($eventRows as $event) {
            if (! $this->utcInstant($event['candle_time'])
                || CarbonImmutable::parse($event['candle_time'])->lessThan(CarbonImmutable::parse($range['start']))
                || CarbonImmutable::parse($event['candle_time'])->greaterThanOrEqualTo(CarbonImmutable::parse($range['end']))) return null;
        }
        if ($fidelity !== [] && (($fidelity['program_hash'] ?? null) !== $run->parameter_hash
            || ($fidelity['source_hash'] ?? null) !== $run->code_hash
            || ($fidelity['physical_event_set_hash'] ?? null) !== $eventHash)) return null;
        $measurement = (array) ($evidence['measurement_witness'] ?? []);
        if ($measurement !== []) {
            try { $request = $owner->readArtifactPayload($requestArtifact); } catch (\Throwable) { return null; }
            $probe = (array) ($request['measurement_probe'] ?? []);
            unset($request['measurement_probe']);
            $decisions = array_map(fn (array $row): array => array_intersect_key($row,
                array_flip(['candle_index', 'candle_time', 'event_type', 'action', 'accepted'])), (array) ($response['decision_trace'] ?? []));
            if (($measurement['program_hash'] ?? null) !== $run->parameter_hash
                || ($measurement['source_hash'] ?? null) !== $run->code_hash
                || ($measurement['matched_input_hash'] ?? null) !== $this->planningHash($request)
                || ($probe['preregistration_key'] ?? null) !== ($measurement['preregistration_key'] ?? null)
                || ($probe['arm'] ?? null) !== ($measurement['arm'] ?? null)
                || ($probe['observation_key'] ?? null) !== ($measurement['observation_key'] ?? null)
                || ($probe['source'] ?? null) !== ($measurement['source'] ?? null)
                || ($probe['availability_by_event'] ?? null) !== ($measurement['availability_by_event'] ?? null)
                || ($measurement['event_set_hash'] ?? null) !== $eventHash
                || ($measurement['observations'] ?? null) !== count($eventRows)
                || ($measurement['decisions_hash'] ?? null) !== $this->planningHash($decisions)) return null;
            $availability = (array) ($measurement['availability_by_event'] ?? []);
            if (count($availability) !== count($eventRows)) return null;
            $seen = []; $latestAvailable = null;
            foreach ($availability as $row) {
                $index = $row['candle_index'] ?? null;
                if (! is_int($index) || isset($seen[$index]) || ! isset($eventRows[$index])
                    || ($row['candle_time'] ?? null) !== $eventRows[$index]['candle_time']
                    || ! $this->utcInstant($row['available_at_utc'] ?? null)
                    || CarbonImmutable::parse($row['available_at_utc'])->greaterThan(CarbonImmutable::parse($row['candle_time']))) return null;
                $seen[$index] = true;
                if ($latestAvailable === null || CarbonImmutable::parse($row['available_at_utc'])->greaterThan(CarbonImmutable::parse($latestAvailable))) {
                    $latestAvailable = $row['available_at_utc'];
                }
            }
            if ($latestAvailable !== ($measurement['latest_available_at_utc'] ?? null)) return null;
        }

        return $run;
    }

    private function usableWitnessIdentity(array $witness): bool
    {
        foreach (['candidate_key', 'program_hash', 'source_hash', 'criterion_hash', 'physical_event_set_hash'] as $field) {
            if (! $this->sha((string) ($witness[$field] ?? ''))) return false;
        }

        return true;
    }

    private function witnessIdentity(ResearchExperimentReceipt $receipt, array $witness): array
    {
        return $this->planningCanonical(['scope' => data_get($receipt->payload, 'contract.scope'),
            'identity' => data_get($receipt->payload, 'contract.identity'),
            ...array_intersect_key($witness, array_flip(['candidate_key', 'program_hash', 'source_hash', 'criterion_hash']))]);
    }

    private function sha(string $value): bool { return preg_match('/^[a-f0-9]{64}$/D', $value) === 1; }

    private function utcInstant(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|\+00:00)$/D', $value) !== 1) return false;
        try { CarbonImmutable::parse($value); } catch (\Throwable) { return false; }

        return true;
    }

    private function planningHash(array $value): string
    {
        return hash('sha256', json_encode($this->planningCanonical($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function planningCanonical(array $value): array
    {
        if (! array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) if (is_array($item)) $value[$key] = $this->planningCanonical($item);

        return $value;
    }

    /** @return array<string,int|float> */
    private function signals(AiLaboratory $lab, array $contextualEvidence): array
    {
        $symbol = strtoupper((string) $lab->symbol);
        $timeframe = strtoupper((string) $lab->timeframe);
        $credits = array_fill_keys([
            'information_credit', 'repair_credit', 'causal_skill_credit',
            'performance_credit', 'inheritance_credit',
        ], 0);
        if (Schema::hasTable('lab_evolution_credit_events')) {
            $rows = DB::table('lab_evolution_credit_events')
                ->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->where('amount', '>', 0)->selectRaw('event_type, COUNT(*) as aggregate')->groupBy('event_type')->get();
            foreach ($rows as $row) {
                $type = in_array((string) $row->event_type, ['learning', 'discovery'], true)
                    ? 'information_credit' : (string) $row->event_type;
                if (array_key_exists($type, $credits)) {
                    $credits[$type] += (int) $row->aggregate;
                }
            }
        }

        $authority = ['research_mentor_count' => 0, 'economic_parent_count' => 0];
        if (Schema::hasTable('evolutionary_authority_ledgers')) {
            $authority['research_mentor_count'] = DB::table('evolutionary_authority_ledgers')
                ->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->whereIn('status', ['research_mentor_granted', 'passed'])->count();
            $authority['economic_parent_count'] = DB::table('evolutionary_authority_ledgers')
                ->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->where('authority_stage', 'eligible_parent')->where('status', 'passed')->count();
        }

        $coverageDeficit = collect((array) data_get($contextualEvidence, '__sessions.__global', []))
            ->filter(fn ($row): bool => (int) data_get($row, 'observations', 0) === 0
                && (int) data_get($row, 'posterior_observations', 0) === 0)
            ->count();
        $posteriorCells = collect((array) data_get($contextualEvidence, '__sessions.__global', []));
        $posteriorEntropyMean = $posteriorCells->isEmpty() ? 0.0 : (float) $posteriorCells
            ->map(function ($row): float {
                $successes = max(0, (int) data_get($row, 'successes', 0));
                $failures = max(0, (int) data_get($row, 'failures', 0));
                // Beta(1,1) posterior predictive entropy. It is a bounded
                // acquisition signal, not replay evidence or promotion.
                $probability = ($successes + 1) / ($successes + $failures + 2);

                return $this->binaryEntropy($probability);
            })->avg();
        $informationGainProxy = $posteriorCells->sum(function ($row): float {
            $observations = max(0, (int) data_get($row, 'observations', 0));
            $posteriorObservations = max(0, (int) data_get($row, 'posterior_observations', 0));
            $successes = max(0, (int) data_get($row, 'successes', 0));
            $failures = max(0, (int) data_get($row, 'failures', 0));
            $probability = ($successes + 1) / ($successes + $failures + 2);

            return $this->binaryEntropy($probability) / sqrt($observations + $posteriorObservations + 1);
        });

        return [
            ...$credits,
            ...$authority,
            'open_failure_count' => $this->scopedCount('lab_failure_repair_anchors', $symbol, $timeframe, ['status' => 'open']),
            'confirmed_skill_count' => $this->scopedCount('lab_skill_zoo_entries', $symbol, $timeframe, ['status' => 'confirmed']),
            'archive_cell_count' => $this->scopedCount('lab_evolution_archive_entries', $symbol, $timeframe, ['status' => 'active']),
            'pending_adversarial_count' => $this->scopedCount('lab_adversarial_scenarios', $symbol, $timeframe, ['status' => 'planned']),
            // The current transfer table is keyed by model and markets. Its
            // waiting rows are global research debt, never local authority.
            'pending_transfer_count' => Schema::hasTable('transfer_matrix_entries')
                ? DB::table('transfer_matrix_entries')->where('status', 'waiting_for_frozen_replay')->count()
                : 0,
            'context_coverage_deficit' => $coverageDeficit,
            'posterior_entropy_mean' => round($posteriorEntropyMean, 6),
            'expected_information_gain_proxy' => round($informationGainProxy, 6),
        ];
    }

    /** @return array<string,array<string,mixed>|null> */
    private function sourceReferences(AiLaboratory $lab): array
    {
        $symbol = strtoupper((string) $lab->symbol);
        $timeframe = strtoupper((string) $lab->timeframe);
        $credit = function (array $types) use ($symbol, $timeframe): ?array {
            if (! Schema::hasTable('lab_evolution_credit_events')) {
                return null;
            }
            $row = DB::table('lab_evolution_credit_events')
                ->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->whereIn('event_type', $types)->where('amount', '>', 0)->latest('id')->first();

            $payload = $row ? (array) json_decode((string) $row->payload, true) : [];
            $context = (array) data_get($payload, 'context', []);
            if ($context === [] && $row) {
                $context = $this->modelContext((int) $row->model_version_id);
            }

            return $row ? [
                'source_type' => 'evolution_credit', 'id' => (int) $row->id,
                'event_type' => (string) $row->event_type,
                'strategy_family' => (string) $row->strategy_family,
                'model_version_id' => $row->model_version_id,
                'lab_agent_id' => $row->lab_agent_id,
                'context_key' => (string) $row->context_key,
                'context_scope' => $context,
                'intervention' => $this->agentIntervention((int) $row->lab_agent_id),
                'evidence_fingerprint' => (string) $row->evidence_fingerprint,
            ] : null;
        };
        $failure = null;
        if (Schema::hasTable('lab_failure_repair_anchors')) {
            $row = DB::table('lab_failure_repair_anchors')->where('symbol', $symbol)
                ->where('timeframe', $timeframe)->where('status', 'open')->latest('id')->first();
            $failure = $row ? [
                'source_type' => 'failure_repair_anchor', 'id' => (int) $row->id,
                'anchor_key' => (string) $row->anchor_key,
                'failure_target' => (string) $row->failure_target,
            ] : null;
        }
        $causalSkill = $credit(['causal_skill_credit']);
        if ($causalSkill === null && Schema::hasTable('lab_skill_zoo_entries')) {
            $row = DB::table('lab_skill_zoo_entries')->where('symbol', $symbol)
                ->where('timeframe', $timeframe)->where('status', 'confirmed')->latest('id')->first();
            $causalSkill = $row ? [
                'source_type' => 'confirmed_skill_archive', 'id' => (int) $row->id,
                'skill_key' => (string) $row->skill_key,
                'strategy_family' => (string) $row->strategy_family,
                'gene_key' => $row->gene_key,
                'context_scope' => (array) data_get(json_decode((string) $row->evidence, true), 'context', [])
                    ?: $this->modelContext((int) $row->model_version_id),
                'intervention' => $this->skillIntervention((array) json_decode((string) $row->evidence, true), (string) $row->gene_key)
                    ?: $this->agentIntervention((int) $row->lab_agent_id),
            ] : null;
        }
        if ($causalSkill === null && Schema::hasTable('evolutionary_authority_ledgers')) {
            $row = DB::table('evolutionary_authority_ledgers')->where('symbol', $symbol)
                ->where('timeframe', $timeframe)->whereIn('status', ['research_mentor_granted', 'passed'])
                ->latest('id')->first();
            $causalSkill = $row ? [
                'source_type' => 'research_mentor_authority', 'id' => (int) $row->id,
                'authority_key' => (string) $row->authority_key,
                'strategy_family' => (string) $row->strategy_family,
                'model_version_id' => $row->model_version_id,
                'lab_agent_id' => $row->lab_agent_id,
                'context_scope' => (array) data_get(
                    json_decode((string) $row->evidence, true),
                    'research_mentor_authority.scope.context',
                    data_get(json_decode((string) $row->evidence, true), 'research_mentor_authority.scope', []),
                ) ?: $this->modelContext((int) $row->model_version_id),
                'intervention' => $this->agentIntervention((int) $row->lab_agent_id),
            ] : null;
        }
        $archive = null;
        if (Schema::hasTable('lab_evolution_archive_entries')) {
            $row = DB::table('lab_evolution_archive_entries')->where('symbol', $symbol)
                ->where('timeframe', $timeframe)->where('status', 'active')->latest('id')->first();
            $archive = $row ? [
                'source_type' => 'quality_diversity_archive', 'id' => (int) $row->id,
                'archive_type' => (string) $row->archive_type,
                'island_key' => (string) $row->island_key,
            ] : null;
        }
        $adversarial = null;
        if (Schema::hasTable('lab_adversarial_scenarios')) {
            $row = DB::table('lab_adversarial_scenarios')->where('symbol', $symbol)
                ->where('timeframe', $timeframe)->where('status', 'planned')->latest('id')->first();
            $adversarial = $row ? [
                'source_type' => 'adversarial_scenario', 'id' => (int) $row->id,
                'scenario_key' => (string) $row->scenario_key,
                'scenario_type' => (string) $row->scenario_type,
            ] : null;
        }
        $economicParent = null;
        if (Schema::hasTable('evolutionary_authority_ledgers')) {
            $row = DB::table('evolutionary_authority_ledgers')->where('symbol', $symbol)
                ->where('timeframe', $timeframe)->where('authority_stage', 'eligible_parent')
                ->where('status', 'passed')->latest('id')->first();
            $economicParent = $row ? [
                'source_type' => 'economic_parent_authority', 'id' => (int) $row->id,
                'authority_key' => (string) $row->authority_key,
                'model_version_id' => $row->model_version_id,
                'strategy_family' => (string) $row->strategy_family,
                'context_scope' => (array) data_get(
                    json_decode((string) $row->evidence, true),
                    'economic_parent_authority.scope.context',
                    data_get(json_decode((string) $row->evidence, true), 'economic_parent_authority.scope', []),
                ) ?: $this->modelContext((int) $row->model_version_id),
            ] : null;
        }

        return [
            'failure' => $failure,
            'information' => $credit(['information_credit', 'learning', 'discovery']),
            'causal_skill' => $causalSkill,
            'archive' => $archive,
            'adversarial' => $adversarial,
            'economic_parent' => $economicParent,
        ];
    }

    /** @param array<string,string|int|float|bool> $where */
    private function scopedCount(string $table, string $symbol, string $timeframe, array $where): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }
        $query = DB::table($table);
        if (Schema::hasColumn($table, 'symbol')) {
            $query->where('symbol', $symbol);
        }
        if (Schema::hasColumn($table, 'timeframe')) {
            $query->where('timeframe', $timeframe);
        }
        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }

        return $query->count();
    }

    /** @return array<string,mixed> */
    private function modelContext(int $modelVersionId): array
    {
        if ($modelVersionId <= 0 || ! Schema::hasTable('model_versions')) {
            return [];
        }
        $raw = DB::table('model_versions')->where('id', $modelVersionId)->value('metadata');
        $metadata = is_string($raw) ? (array) json_decode($raw, true) : (array) $raw;
        foreach ([
            'contextual_trait_capsule.activation_context',
            'skill_mentor.activation_context',
            'portfolio_council_lane',
            'specialist_council_membership.contextual_cell',
            'semantic_group.context',
        ] as $path) {
            $context = (array) data_get($metadata, $path, []);
            if ($this->usableContextScope($context)) {
                return $context;
            }
        }

        return [];
    }

    private function usableContextScope(array $context): bool
    {
        return filled(data_get($context, 'regime'))
            || filled(data_get($context, 'session'))
            || filled(data_get($context, 'session_utc_hour'))
            || filled(data_get($context, 'volatility'))
            || filled(data_get($context, 'volatility_state'));
    }

    /** @return array<string,mixed> */
    private function agentIntervention(int $agentId): array
    {
        if ($agentId <= 0 || ! Schema::hasTable('lab_agents')) {
            return [];
        }
        $raw = DB::table('lab_agents')->where('id', $agentId)->value('parameter_diff');
        $diff = is_string($raw) ? (array) json_decode($raw, true) : (array) $raw;
        if (count($diff) !== 1) {
            return [];
        }
        $gene = (string) array_key_first($diff);

        return [
            'gene' => $gene,
            'old_value' => data_get($diff, $gene.'.old.value', data_get($diff, $gene.'.old')),
            'new_value' => data_get($diff, $gene.'.new.value', data_get($diff, $gene.'.new')),
        ];
    }

    /** @return array<string,mixed> */
    private function skillIntervention(array $evidence, string $fallbackGene): array
    {
        $intervention = (array) data_get($evidence, 'intervention', []);
        $gene = (string) data_get($intervention, 'gene', $fallbackGene);
        $old = data_get($intervention, 'old_value');
        $new = data_get($intervention, 'tested_value', data_get($intervention, 'new_value'));
        if ($gene === '' || $new === null) {
            return [];
        }

        return ['gene' => $gene, 'old_value' => $old, 'new_value' => $new];
    }

    /** @return array<string,int|float> */
    private function normalizeSignals(array $signals): array
    {
        $defaults = [
            'open_failure_count' => 0, 'information_credit' => 0, 'repair_credit' => 0,
            'causal_skill_credit' => 0, 'performance_credit' => 0, 'inheritance_credit' => 0,
            'research_mentor_count' => 0, 'economic_parent_count' => 0,
            'confirmed_skill_count' => 0, 'archive_cell_count' => 0,
            'pending_transfer_count' => 0, 'pending_adversarial_count' => 0,
            'context_coverage_deficit' => 0, 'posterior_entropy_mean' => 0,
            'expected_information_gain_proxy' => 0,
            'observed_behavior_priority' => 0,
        ];

        return collect([...$defaults, ...$signals])->map(
            fn ($value): int|float => max(0, is_numeric($value) ? $value + 0 : 0),
        )->all();
    }

    /** @return array<string,float> */
    private function scores(array $signals): array
    {
        $log = fn (string $key): float => log(1 + (float) $signals[$key]);

        return [
            'failure_directed_repair' => 2.4 + $log('open_failure_count') + (.45 * $log('repair_credit')),
            'positive_skill_replication' => 2.0 + $log('causal_skill_credit') + (.4 * $log('confirmed_skill_count')),
            'bayesian_active_learning' => 2.1
                + (.5 * $log('information_credit'))
                + (.3 * (float) $signals['context_coverage_deficit'])
                + (.55 * (float) $signals['posterior_entropy_mean'])
                + (.35 * log(1 + (float) $signals['expected_information_gain_proxy'])),
            'counterfactual_factorial' => 2.0 + $log('causal_skill_credit') + (.45 * $log('repair_credit')),
            'quality_diversity_novelty' => 2.0 + (.45 * (float) $signals['context_coverage_deficit']) + (1 / sqrt(1 + (float) $signals['archive_cell_count']))
                + min(.25, (float) $signals['observed_behavior_priority']),
            'context_transfer_validation' => 1.8 + $log('causal_skill_credit') + (.25 * $log('pending_transfer_count')),
            'adversarial_robustness' => 2.0 + (.3 * $log('pending_adversarial_count')),
            'elite_rehearsal_guard' => 2.2 + $log('economic_parent_count') + (.35 * $log('confirmed_skill_count')),
        ];
    }

    private function available(string $method, array $signals): bool
    {
        return match ($method) {
            'positive_skill_replication', 'counterfactual_factorial', 'context_transfer_validation' =>
                (int) $signals['causal_skill_credit'] > 0
                || (int) $signals['research_mentor_count'] > 0
                || (int) $signals['confirmed_skill_count'] > 0,
            'elite_rehearsal_guard' => (int) $signals['economic_parent_count'] > 0,
            default => true,
        };
    }

    private function binaryEntropy(float $probability): float
    {
        $probability = min(1 - 1.0e-12, max(1.0e-12, $probability));

        return -($probability * log($probability, 2))
            - ((1 - $probability) * log(1 - $probability, 2));
    }

    /** @param array<int,array<string,mixed>> $blocks @return array<int,array<string,mixed>> */
    private function bindMultiPairTopologies(array $blocks): array
    {
        foreach ([
            'counterfactual_factorial' => [
                ['exact_control', 'component_a'],
                ['exact_control', 'component_b'],
            ],
            'context_transfer_validation' => [
                ['exact_target_control', 'transferred_skill_candidate'],
                ['exact_target_control', 'from_scratch_novelty_candidate'],
            ],
        ] as $method => $pairArms) {
            $indexes = array_keys(array_filter($blocks, fn (array $block): bool => $block['learning_method'] === $method));
            foreach (array_chunk($indexes, 2) as $groupOrdinal => $groupIndexes) {
                $complete = count($groupIndexes) === 2;
                $groupKey = hash('sha256', implode('|', [
                    self::PROTOCOL, $method, $groupOrdinal + 1,
                    ...array_map(fn (int $index): int => (int) $blocks[$index]['block_index'], $groupIndexes),
                ]));
                foreach ($groupIndexes as $pairOrdinal => $blockIndex) {
                    $blocks[$blockIndex]['experiment_topology'] = [
                        'topology' => $method === 'counterfactual_factorial'
                            ? 'paired_marginal_screen_before_dedicated_control_a_b_a_plus_b'
                            : 'paired_transfer_screen_before_frozen_transfer_matrix',
                        'group_key' => $groupKey,
                        'required_pairs' => 2,
                        'required_seats' => 4,
                        'pair_ordinal' => $pairOrdinal + 1,
                        'control_arm' => $pairArms[$pairOrdinal][0],
                        'candidate_arm' => $pairArms[$pairOrdinal][1],
                        'same_context_cell_required' => true,
                        'same_data_and_execution_hash_required' => true,
                        'interaction_claim_allowed' => $method !== 'counterfactual_factorial',
                        'dedicated_interaction_cohort_required' => $method === 'counterfactual_factorial',
                        'dedicated_interaction_protocol' => $method === 'counterfactual_factorial'
                            ? CanonicalSkillCartridgeService::PROTOCOL
                            : null,
                        'transfer_claim_allowed' => $method !== 'context_transfer_validation',
                        'frozen_transfer_matrix_required' => $method === 'context_transfer_validation',
                        'frozen_transfer_matrix_protocol' => $method === 'context_transfer_validation'
                            ? 'transfer_matrix_v1'
                            : null,
                        'status' => $complete ? 'complete' : 'incomplete_fail_closed',
                        'promotion_evidence' => false,
                    ];
                }
            }
        }

        return $blocks;
    }
}
