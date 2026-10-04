<?php

namespace App\Services;

use App\Models\InstrumentValuePosterior;
use App\Models\CanonicalLearningOutbox;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\PlaybookValuePosterior;

/** Exact-value consumption is distinct from selecting a gene or reading a prior. */
class InstrumentPolicyConsumptionService
{
    public const TRANSFER_PROTOCOL = 'instrument_exact_delta_transfer_hypothesis_v1';

    public function receipt(array $policy, array $parameterDiff, array $parameters): array
    {
        $isolated = [];
        $bundles = [];
        $applied = [];
        $deltas = [];
        $lineage = [];
        foreach ((array) ($policy['preferred_deltas'] ?? []) as $entry) {
            $delta = (array) ($entry['tested_intervention'] ?? []);
            $gene = (string) ($delta['gene'] ?? '');
            $source = (array) ($entry['source'] ?? []);
            if (! $this->deltaMatches($delta, $parameterDiff, $parameters)
                || ! $this->sourceVerified($source, false, 'confirmed')
                || ! $this->contextMatches((array) ($source['context'] ?? []), (array) ($policy['context'] ?? []), (string) ($policy['strategy_family'] ?? ''))) {
                continue;
            }
            $support = array_values(array_filter((array) ($entry['bundle_sources'] ?? []),
                fn (array $bundle): bool => $this->sourceVerified($bundle, true, 'confirmed')
                    && ($bundle['primary_instrument_key'] ?? null) === ($source['instrument_key'] ?? null)
                    && ($bundle['state_key'] ?? null) === ($source['state_key'] ?? null)
                    && ($bundle['validation_epoch_key'] ?? null) === ($source['validation_epoch_key'] ?? null)
                    && data_get($bundle, 'tested_intervention.intervention_hash') === ($delta['intervention_hash'] ?? null)));
            if ($support === []) {
                continue;
            }
            $isolated[] = $source;
            $bundles = [...$bundles, ...$support];
            $applied[] = $gene;
            $deltas[] = $delta;
            $lineage[] = [
                'source_validation_epoch_key' => $source['validation_epoch_key'],
                'source_intervention_hash' => $delta['intervention_hash'],
                'source_receipts' => $source['source_receipts'],
                'baseline_parameter_hash' => $delta['baseline_parameter_hash'],
                'actual_parameter_change' => [$gene => $parameterDiff[$gene]],
                'resulting_parameter_hash' => $this->hash($parameters),
                'comparison_and_ablation_still_required' => true,
            ];
        }
        $valid = $applied !== [];
        $identity = [
            'protocol' => 'instrument_successor_consumption_v2',
            'status' => $valid ? 'policy_aligned_mutation_observed' : 'not_applied',
            'requested_context' => (array) ($policy['context'] ?? []),
            'applied_genes' => array_values(array_unique($applied)), 'tested_interventions' => $deltas,
            'parameter_diff' => $valid ? array_intersect_key($parameterDiff, array_flip($applied)) : [],
            'isolated_sources' => $isolated, 'bundle_sources' => $bundles,
            'resulting_parameter_hash' => $this->hash($parameters),
            'source_to_parameter_lineage' => $lineage,
            'paper_execution_authority' => false, 'promotion_evidence' => false,
        ];

        return [...$identity, 'receipt_hash' => $this->hash($identity)];
    }

    /** A new baseline buys a question, never application or inherited authority. */
    public function transferHypotheses(array $policy, array $parameters): array
    {
        $hypotheses = [];
        foreach ((array) ($policy['preferred_deltas'] ?? []) as $entry) {
            $delta = (array) ($entry['tested_intervention'] ?? []);
            $gene = (string) ($delta['gene'] ?? '');
            $source = (array) ($entry['source'] ?? []);
            if (! array_key_exists($gene, $parameters)
                || ! app(InstrumentValidationEvidenceService::class)->deltaValid($delta, (string) ($delta['state_key'] ?? ''))
                || ! $this->contextMatches((array) ($source['context'] ?? []), (array) ($policy['context'] ?? []), (string) ($policy['strategy_family'] ?? ''))
                || ! $this->sourceVerified($source, false, 'confirmed')) {
                continue;
            }
            $support = array_values(array_filter((array) ($entry['bundle_sources'] ?? []),
                fn (array $bundle): bool => $this->sourceVerified($bundle, true, 'confirmed')
                    && ($bundle['primary_instrument_key'] ?? null) === ($source['instrument_key'] ?? null)
                    && ($bundle['state_key'] ?? null) === ($source['state_key'] ?? null)
                    && ($bundle['validation_epoch_key'] ?? null) === ($source['validation_epoch_key'] ?? null)
                    && data_get($bundle, 'tested_intervention.intervention_hash') === ($delta['intervention_hash'] ?? null)));
            $control = [...$parameters, $gene => $delta['old']];
            $candidate = [...$control, $gene => $delta['new']];
            if ($support === [] || $this->hash($control) === ($delta['baseline_parameter_hash'] ?? null)) {
                continue;
            }
            // Both vectors retain every other actual recipient parameter.
            // The old/new trait is unchanged; its effect on this vector is not proven.
            $identity = [
                'protocol' => self::TRANSFER_PROTOCOL, 'status' => 'untested_changed_baseline',
                'strategy_family' => (string) $policy['strategy_family'],
                'requested_context' => (array) $policy['context'],
                'source' => $source, 'bundle_sources' => $support, 'source_intervention' => $delta,
                'recipient_parameter_snapshot' => $parameters,
                'actual_recipient_parameter_hash' => $this->hash($parameters),
                'proposed_control_parameters' => $control,
                'proposed_control_parameter_hash' => $this->hash($control),
                'proposed_candidate_parameters' => $candidate,
                'proposed_candidate_parameter_hash' => $this->hash($candidate),
                'proposed_parameter_diff' => [$gene => ['old' => $delta['old'], 'new' => $delta['new']]],
                'trait_already_present' => $this->hash($candidate) === $this->hash($parameters),
                'required_comparisons' => ['frozen_control', 'candidate', 'matched_trait_ablation', 'memory_blinded'],
                'equal_compute_budget_required' => true,
                'authorized_unused_validation_window_required' => true,
                'canonical_transfer_admission' => [
                    'status' => 'requires_canonical_transfer_admission',
                    'owner' => CanonicalSkillCartridgeService::class,
                    'sequence' => ['planTransplant', 'materializeTransplant', 'settleTransplantOutcome'],
                    'requirements' => [
                        'source_receipts_bound_to_canonical_cartridge_with_same_gene_old_and_tested_value',
                        'persisted_causal_control_matching_proposed_control_parameter_hash',
                        'canonical_price_and_foundation_dataset_snapshots',
                        'authorized_unused_validation_window_and_preregistered_equal_budget_comparison',
                    ],
                    'admitted' => false,
                ],
                'independent_evidence' => false, 'retained_benefit' => false,
                'paper_execution_authority' => false, 'promotion_evidence' => false,
            ];
            $hypotheses[] = [...$identity, 'hypothesis_hash' => $this->hash($identity)];
            // A recipient opens at most one bounded proposal; no new scheduler.
            break;
        }

        return $hypotheses;
    }

    /** Project only after the canonical settlement of the actual recipient committed. */
    public function recordTransferNextWork(LabAgent $agent, CanonicalLearningOutbox $outbox): array
    {
        $agent = $agent->fresh(['modelVersion', 'generation']);
        $outbox = $outbox->fresh();
        $pair = $outbox?->pair;
        $run = $outbox ? LabEvaluationRun::query()->where('run_id', $outbox->evidence_run_id)->first() : null;
        if (! $agent?->modelVersion || ! $outbox || $outbox->status !== 'completed'
            || (int) $pair?->candidate_agent_id !== (int) $agent->id
            || ! $run || (int) $run->lab_agent_id !== (int) $agent->id
            || (int) $run->model_version_id !== (int) $agent->model_version_id
            || $run->parameter_hash !== $this->hash((array) $agent->modelVersion->parameters)
            || $outbox->data_hash !== $pair?->candidate_data_hash
            || $outbox->execution_hash !== $pair?->candidate_execution_hash
            || ! app(LearningEvidenceGate::class)->allow($pair, $run, 'canonical_settled')['allowed']) {
            return ['status' => 'blocked', 'reason' => 'COMMITTED_ACTUAL_RECIPIENT_SETTLEMENT_REQUIRED', 'promotion_evidence' => false];
        }
        $policy = (array) data_get($agent->modelVersion->metadata, 'instrument_learning_policy', []);
        $recipientContext = app(ContextContractV2Service::class)->canonicalAxes((array) data_get($pair->failure_signature, 'state', []));
        $recipientContext['strategy_family'] = $agent->strategy_family;
        if (($policy['strategy_family'] ?? null) !== $agent->strategy_family
            || ! $this->contextMatches($recipientContext, (array) ($policy['context'] ?? []), (string) $agent->strategy_family)) {
            return ['status' => 'not_proposed', 'promotion_evidence' => false];
        }
        $hypotheses = $this->transferHypotheses($policy, (array) $agent->modelVersion->parameters);
        if ($hypotheses === []) return ['status' => 'not_proposed', 'promotion_evidence' => false];
        $executionTimeframe = (string) data_get($run->request_meta, 'payload.timeframe', data_get($outbox->payload, 'result.timeframe', ''));
        if ($executionTimeframe === '') {
            return ['status' => 'blocked', 'reason' => 'RECIPIENT_EXECUTION_TIMEFRAME_REQUIRED', 'promotion_evidence' => false];
        }
        $hypothesis = $hypotheses[0];
        $identity = [
            'baseline_epoch_hash' => $hypothesis['proposed_control_parameter_hash'],
            'data_and_mtf_hash' => (string) $outbox->data_hash,
            'runtime_and_contract_hash' => (string) $outbox->execution_hash,
            'intervention_hash' => $this->hash($hypothesis['proposed_parameter_diff']),
            'window_plan_hash' => $this->hash(['authorized_unused_validation_window_required', $hypothesis['hypothesis_hash']]),
            'evaluator_version' => (string) $run->code_hash,
        ];
        $contract = [
            'contract_version' => ResearchExperimentConversionKernelService::CONTRACT_VERSION,
            'source' => ['type' => self::TRANSFER_PROTOCOL, 'id' => $agent->id, 'canonical_learning_outbox_id' => $outbox->id],
            'scope' => ['symbol' => $agent->symbol, 'laboratory_timeframe' => $agent->timeframe,
                'execution_timeframe' => $executionTimeframe],
            'identity' => $identity,
            'arms' => array_map(fn (string $role): array => ['role' => $role], $hypothesis['required_comparisons']),
            'claim' => ['hypothesis' => 'Exact source trait may retain value on the recipient baseline; untested.', 'research_only' => true],
        ];

        return app(ResearchExperimentConversionKernelService::class)->record($contract, [
            'hypothesis_only' => true, 'answered_comparison' => false,
            'recipient_agent_id' => $agent->id, 'recipient_model_version_id' => $agent->model_version_id,
            'recipient_evidence_run_id' => $run->run_id, 'recipient_parameter_hash' => $run->parameter_hash,
            'hypothesis' => $hypothesis, 'retained_benefit' => false,
        ], 'INCONCLUSIVE', [
            'type' => 'instrument_exact_delta_transfer', 'identity' => $hypothesis['hypothesis_hash'],
            'dependency_key' => 'instrument-transfer:'.$hypothesis['hypothesis_hash'],
            'executable' => false, 'hypothesis' => $hypothesis,
            'canonical_transfer_admission' => $hypothesis['canonical_transfer_admission'],
            'recipient_agent_id' => $agent->id, 'recipient_model_version_id' => $agent->model_version_id,
            'retry_condition' => ['code' => 'CANONICAL_TRANSFER_ADMISSION_AND_AUTHORIZED_UNUSED_WINDOW_REQUIRED',
                'max_experiments' => 1, 'same_evidence_replay_forbidden' => true],
            'paper_execution_authority' => false,
        ]);
    }

    /** Respect the existing selected gene; never replace an isolated experiment. */
    public function applyPreferredDelta(array $policy, array $baseline, array $candidate, array $allowedGenes): array
    {
        $changed = [];
        foreach (array_unique([...array_keys($baseline), ...array_keys($candidate)]) as $gene) {
            if (! array_key_exists($gene, $baseline) || ! array_key_exists($gene, $candidate)
                || $this->hash(['value' => $baseline[$gene]]) !== $this->hash(['value' => $candidate[$gene]])) {
                $changed[] = $gene;
            }
        }
        if (count($changed) !== 1) {
            return $candidate;
        }
        $matches = [];
        foreach ((array) ($policy['preferred_deltas'] ?? []) as $entry) {
            $delta = (array) ($entry['tested_intervention'] ?? []);
            $gene = (string) ($delta['gene'] ?? '');
            if ($gene !== $changed[0] || ! in_array($gene, $allowedGenes, true)
                || ($delta['baseline_parameter_hash'] ?? null) !== $this->hash($baseline)
                || ! array_key_exists('old', $delta) || ! array_key_exists('new', $delta)
                || $this->hash(['value' => $baseline[$gene]]) !== $this->hash(['value' => $delta['old']])) {
                continue;
            }
            $next = [...$candidate, $gene => $delta['new']];
            if ($this->receipt($policy, [$gene => ['old' => $baseline[$gene], 'new' => $delta['new']]], $next)['status'] === 'policy_aligned_mutation_observed') {
                $matches[$this->hash(['value' => $delta['new']])] = $next;
            }
        }

        // Contradictory confirmed proposals need a fresh controlled comparison.
        return count($matches) === 1 ? array_values($matches)[0] : $candidate;
    }

    public function forbiddenDelta(array $policy, array $parameterDiff, array $parameters): bool
    {
        foreach ((array) ($policy['blocked_deltas'] ?? []) as $entry) {
            $delta = (array) ($entry['tested_intervention'] ?? []);
            $source = (array) ($entry['source'] ?? []);
            if (! $this->deltaMatches($delta, $parameterDiff, $parameters)
                || ! $this->contextMatches((array) ($source['context'] ?? []), (array) ($policy['context'] ?? []), (string) ($policy['strategy_family'] ?? ''))) {
                continue;
            }
            if ($this->sourceVerified($source, false, 'forbidden')) {
                return true;
            }
            if ($this->sourceVerified($source, false, 'confirmed')) {
                foreach ((array) ($entry['bundle_sources'] ?? []) as $bundle) {
                    if ($this->sourceVerified((array) $bundle, true, 'forbidden')
                        && ($bundle['validation_epoch_key'] ?? null) === ($source['validation_epoch_key'] ?? null)
                        && ($bundle['state_key'] ?? null) === ($source['state_key'] ?? null)
                        && ($bundle['primary_instrument_key'] ?? null) === ($source['instrument_key'] ?? null)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function deltaMatches(array $delta, array $diff, array $parameters): bool
    {
        $gene = (string) ($delta['gene'] ?? '');
        $baseline = $parameters;
        foreach ($diff as $key => $change) {
            if (! is_array($change) || ! array_key_exists('old', $change) || ! array_key_exists('new', $change)
                || ! array_key_exists($key, $parameters)
                || $this->hash(['value' => $parameters[$key]]) !== $this->hash(['value' => $change['new']])) {
                return false;
            }
            $baseline[$key] = $change['old'];
        }

        return app(InstrumentValidationEvidenceService::class)->deltaValid($delta, (string) ($delta['state_key'] ?? ''))
            && ($delta['baseline_parameter_hash'] ?? null) === $this->hash($baseline)
            && array_key_exists($gene, $parameters) && isset($diff[$gene])
            && array_key_exists('old', $diff[$gene]) && array_key_exists('new', $diff[$gene])
            && $this->hash(['value' => $delta['old']]) === $this->hash(['value' => $diff[$gene]['old']])
            && $this->hash(['value' => $delta['new']]) === $this->hash(['value' => $diff[$gene]['new']])
            && $this->hash(['value' => $delta['new']]) === $this->hash(['value' => $parameters[$gene]]);
    }

    private function sourceVerified(array $source, bool $bundle, string $state): bool
    {
        $posterior = $bundle ? PlaybookValuePosterior::query()->with('playbook')->find($source['posterior_id'] ?? 0)
            : InstrumentValuePosterior::query()->with('instrument')->find($source['posterior_id'] ?? 0);
        if ($posterior === null || ($source['state'] ?? null) !== $state || ($source['state_key'] ?? null) !== $posterior->state_key
            || $posterior->decay_state === 'decaying') {
            return false;
        }
        if ($bundle) {
            if (($source['playbook_key'] ?? null) !== $posterior->playbook?->playbook_key
                || data_get($posterior->playbook?->metadata, 'protocol') !== 'exact_instrument_research_bundle_v1'
                || ($source['primary_instrument_key'] ?? null) !== data_get($posterior->playbook?->metadata, 'primary_instrument_key')) {
                return false;
            }
        } elseif (($source['instrument_key'] ?? null) !== $posterior->instrument?->instrument_key) {
            return false;
        }
        foreach (app(InstrumentPosteriorAuthorityService::class)->validationEpochs($posterior) as $epoch) {
            $axes = array_filter(app(ContextContractV2Service::class)->canonicalAxes((array) data_get($epoch, 'tested_intervention.context', [])),
                static fn ($value): bool => $value !== null && $value !== '');
            $axes['strategy_family'] = (string) data_get($epoch, 'tested_intervention.strategy_family', '');
            if ($epoch['canonical_state'] === $state && ($source['validation_epoch_key'] ?? null) === $epoch['epoch_key']
                && $this->hash((array) ($source['context'] ?? [])) === $this->hash($axes)
                && $this->hash((array) ($source['tested_intervention'] ?? [])) === $this->hash($epoch['tested_intervention'])
                && ($source['window_evidence_digest'] ?? null) === $this->hash($epoch['window_evidence'])
                && $this->hash((array) ($source['source_receipts'] ?? [])) === $this->hash(array_column($epoch['window_evidence'], 'source_receipt'))) {
                return true;
            }
        }

        return false;
    }

    private function contextMatches(array $observed, array $requested, string $family): bool
    {
        $axes = ['regime', 'session', 'venue_phase', 'volatility', 'spread_liquidity_state', 'transition_state', 'direction'];
        if ($family === '' || ($observed['strategy_family'] ?? null) !== $family) {
            return false;
        }
        foreach ($axes as $axis) {
            if (! isset($requested[$axis], $observed[$axis]) || (string) $requested[$axis] !== (string) $observed[$axis]) {
                return false;
            }
        }

        return true;
    }

    private function hash(array $value): string
    {
        return app(ResearchPaperEpochContractService::class)->parameterHash($value);
    }
}
