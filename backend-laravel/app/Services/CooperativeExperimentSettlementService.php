<?php

namespace App\Services;

use App\Models\CandidateGateDecision;
use App\Models\ContextualInstrumentBundleEffect;
use App\Models\ContextualSpecialistCapsule;
use App\Models\CooperativeExperimentSettlement;
use App\Models\CooperativeModuleSpeciesMember;
use App\Models\InstrumentInvocationLedger;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvolutionArchiveEntry;
use Illuminate\Support\Facades\Schema;

/** Closes pair/factorial/transfer/descendant blocks from their actual screening arms. */
class CooperativeExperimentSettlementService
{
    public const PROTOCOL = 'cooperative_experiment_settlement_v1';

    public function __construct(private LabImmutableEvidenceService $evidence) {}

    /** @return array<string,mixed> */
    public function observe(LabAgent $agent): array
    {
        $block = (array) data_get($agent->modelVersion?->metadata, 'cooperative_experiment_block', []);
        $blockKey = (string) data_get($block, 'block_key', '');
        if ($blockKey === '' || ! Schema::hasTable('cooperative_experiment_settlements')) {
            return ['protocol' => self::PROTOCOL, 'status' => $blockKey === '' ? 'not_applicable' : 'migration_pending'];
        }
        $agents = $agent->generation->agents()->with('modelVersion')->get()->filter(fn (LabAgent $row): bool => (string) data_get($row->modelVersion?->metadata, 'cooperative_experiment_block.block_key', '') === $blockKey
        );
        $armResults = [];
        $eligibleArmResults = [];
        $invalidArms = [];
        foreach ($agents as $row) {
            $arm = (string) data_get($row->modelVersion?->metadata, 'cooperative_experiment_block.arm', '');
            $decision = CandidateGateDecision::query()->where('lab_agent_id', $row->id)->where('stage', 'screening')->latest('id')->first();
            if ($arm === '' || ! $decision) {
                continue;
            }
            $metrics = (array) $decision->metrics;
            $armEvidence = $this->armEvidence($row, $decision, $blockKey);
            $armResults[$arm] = [
                'lab_agent_id' => $row->id, 'model_version_id' => $row->model_version_id,
                'decision_id' => $decision->id, 'decision' => $decision->decision,
                'after_cost_value' => $armEvidence['status'] === 'eligible' ? $this->value($metrics) : null,
                'pareto_vector' => data_get($metrics, 'contextual_capsule_archive.pareto_vector'),
                'context_cell_key' => data_get($row->modelVersion?->metadata, 'cooperative_experiment_block.context_cell_key', data_get($row->modelVersion?->metadata, 'cooperative_evolution_capsule.context_cell_hash')),
                'evidence_status' => $armEvidence['status'],
                'evidence_run_id' => $armEvidence['run_id'],
                'data_hash' => $armEvidence['data_hash'],
                'execution_hash' => $armEvidence['execution_hash'],
                'mtf_bundle_hash' => $armEvidence['mtf_bundle_hash'],
                'session_instance_id' => $armEvidence['session_instance_id'],
                'evidence_reason_codes' => $armEvidence['reason_codes'],
                'activation_telemetry' => (string) data_get($block, 'block_type') === 'activation_factorial'
                    ? $this->activationTelemetry($metrics, (string) data_get($row->modelVersion?->metadata,
                        'smart_composition.composition_passport.composition_id', '')) : null,
            ];
            if ($armEvidence['status'] === 'eligible') {
                $eligibleArmResults[$arm] = $armResults[$arm];
            } elseif ($armEvidence['status'] === 'invalid') {
                $invalidArms[$arm] = $armEvidence['reason_codes'];
            }
        }
        $required = array_values((array) data_get($block, 'required_arms', []));
        $complete = $required !== [] && collect($required)->every(fn (string $arm): bool => array_key_exists($arm, $eligibleArmResults));
        $identityReasons = $complete ? $this->identityReasons($required, $eligibleArmResults) : [];
        if ($identityReasons !== []) {
            $complete = false;
            $invalidArms['_block_identity'] = $identityReasons;
        }
        $activationBlock = (string) data_get($block, 'block_type') === 'activation_factorial';
        if ($activationBlock && $complete) {
            $activationReasons = app(ActivationFactorialContractService::class)->blockReasons($agents);
            if ($activationReasons !== []) {
                $complete = false;
                $invalidArms['_activation_identity'] = $activationReasons;
            }
            foreach ($eligibleArmResults as $arm => $result) {
                if (data_get($result, 'activation_telemetry.valid') !== true) {
                    $complete = false;
                    $invalidArms[$arm] = ['ACTIVATION_RUNTIME_RECEIPT_INVALID'];
                }
            }
            $universeHashes = collect($eligibleArmResults)
                ->pluck('activation_telemetry.opportunity_universe_hash')->unique();
            $opportunityCounts = collect($eligibleArmResults)
                ->pluck('activation_telemetry.opportunity_count')->unique();
            if ($universeHashes->count() !== 1 || $opportunityCounts->count() !== 1) {
                $complete = false;
                $invalidArms['_paired_opportunity_universe'] = ['ACTIVATION_PAIRED_OPPORTUNITY_MISMATCH'];
            }
            $mtfHashes = collect($eligibleArmResults)->pluck('mtf_bundle_hash');
            if ($mtfHashes->filter(fn (mixed $hash): bool => strlen((string) $hash) === 64)->count() !== 4
                || $mtfHashes->unique()->count() !== 1) {
                $complete = false;
                $invalidArms['_mtf_bundle'] = ['ACTIVATION_MTF_BUNDLE_MISMATCH'];
            }
        }
        if ($activationBlock) {
            $effects = $complete ? $this->activationEffects($eligibleArmResults) : [];
            $status = $invalidArms !== [] ? 'invalid_arm_evidence'
                : (! $complete ? 'waiting_for_arms' : (string) $effects['behavioral_claim']);
            $settlementKey = hash('sha256', implode('|', [self::PROTOCOL, $agent->lab_generation_id, $blockKey]));
            $row = CooperativeExperimentSettlement::query()->updateOrCreate(['settlement_key' => $settlementKey], [
                'block_key' => $blockKey, 'lab_generation_id' => $agent->lab_generation_id,
                'block_type' => 'activation_factorial',
                'context_cell_key' => (string) data_get($agent->modelVersion?->metadata,
                    'cooperative_evolution_capsule.context_cell_hash', '') ?: null,
                'arm_results' => $armResults, 'component_effects' => $effects,
                'pareto_vectors' => [], 'outcome_status' => $status,
                'evidence_complete' => $complete, 'promotion_evidence' => false,
            ]);
            $conversion = $complete
                ? $this->recordActivationFrontier($row, $agents, $eligibleArmResults, $effects)
                : null;

            return ['protocol' => self::PROTOCOL, 'status' => $status,
                'settlement_id' => (int) $row->id, 'evidence_complete' => $complete,
                'component_effects' => $effects, 'invalid_arms' => $invalidArms,
                'instrument_bundle_graph' => null, 'credit_allowed' => false,
                'independent_validation_required' => true,
                'frontier_conversion' => $conversion, 'promotion_evidence' => false];
        }
        $effects = $this->effects((string) data_get($block, 'block_type', ''), $complete ? $eligibleArmResults : []);
        $positive = $complete && (float) data_get($effects, 'whole_capsule_effect', data_get($effects, 'candidate_delta', 0)) > 0;
        $status = $invalidArms !== []
            ? 'invalid_arm_evidence'
            : (! $complete ? 'waiting_for_arms' : ($positive ? 'settled_positive_signal' : 'settled_negative_or_null'));
        $settlementKey = hash('sha256', implode('|', [self::PROTOCOL, $agent->lab_generation_id, $blockKey]));
        $row = CooperativeExperimentSettlement::query()->updateOrCreate(['settlement_key' => $settlementKey], [
            'block_key' => $blockKey, 'lab_generation_id' => $agent->lab_generation_id,
            'block_type' => (string) data_get($block, 'block_type', 'unknown'),
            'context_cell_key' => (string) data_get($agent->modelVersion?->metadata, 'cooperative_evolution_capsule.context_cell_hash', '') ?: null,
            'arm_results' => $armResults, 'component_effects' => $effects,
            'pareto_vectors' => collect($armResults)->mapWithKeys(fn (array $result, string $arm): array => [$arm => $result['pareto_vector']])->all(),
            'outcome_status' => $status, 'evidence_complete' => $complete, 'promotion_evidence' => false,
        ]);
        if ($complete) {
            $this->settleModuleSpecies($agents, (string) data_get($block, 'block_type', ''), $effects, $row->id);
            $bundleGraph = app(ContextualInstrumentBundleGraphService::class)->record($row, $agents, $effects);
            app(ResearchIdeaInboxService::class)->settle($blockKey, [
                'settlement_id' => $row->id, 'settlement_key' => $settlementKey, 'outcome_status' => $status,
                'component_effects' => $effects, 'instrument_bundle_graph' => $bundleGraph,
            ]);
        } elseif ($invalidArms !== []) {
            $this->retractInvalidSettlement($agents, $row->id, $invalidArms);
            app(ResearchIdeaInboxService::class)->invalidate($blockKey, [
                'settlement_id' => $row->id,
                'settlement_key' => $settlementKey,
                'outcome_status' => $status,
                'invalid_arms' => $invalidArms,
                'invalidated_at' => now()->utc()->toIso8601String(),
            ]);
        }

        return ['protocol' => self::PROTOCOL, 'status' => $status, 'settlement_id' => $row->id,
            'evidence_complete' => $complete, 'component_effects' => $effects,
            'invalid_arms' => $invalidArms,
            'instrument_bundle_graph' => $bundleGraph ?? null, 'promotion_evidence' => false];
    }

    /** @return array<string,mixed> */
    private function armEvidence(LabAgent $agent, CandidateGateDecision $decision, string $blockKey): array
    {
        $runId = (string) data_get($decision->metrics, 'evidence_run_id', '');
        $run = $runId !== ''
            ? LabEvaluationRun::query()->where('lab_agent_id', $agent->id)->where('run_id', $runId)->first()
            : null;
        if (! $run) {
            return $this->armEvidenceResult('invalid', $runId ?: null, null, null, null, ['IMMUTABLE_EVIDENCE_RUN_REQUIRED']);
        }
        if ((string) $run->status !== 'completed') {
            $terminal = in_array((string) $run->status, ['technical_error', 'failed', 'skipped'], true);

            return $this->armEvidenceResult(
                $terminal ? 'invalid' : 'pending',
                $runId,
                $run->data_hash,
                null,
                null,
                [$terminal ? 'IMMUTABLE_EVIDENCE_RUN_NOT_COMPLETED' : 'IMMUTABLE_EVIDENCE_RUN_PENDING'],
            );
        }
        $eligibility = $this->evidence->learningEligibility($run);
        if (! $eligibility['complete']) {
            return $this->armEvidenceResult('invalid', $runId, $run->data_hash, null, null, [
                'IMMUTABLE_EVIDENCE_CHAIN_INCOMPLETE',
                ...(array) $eligibility['reason_codes'],
            ]);
        }
        if ((string) data_get($agent->modelVersion?->metadata,
            'cooperative_experiment_block.block_type') === 'activation_factorial') {
            $immutableRequest = $this->evidence->latestArtifactPayload($run, 'evaluation_request');
            if (! is_array($immutableRequest)
                || $immutableRequest !== data_get($run->request_meta, 'payload')) {
                return $this->armEvidenceResult('invalid', $runId, $run->data_hash,
                    null, null, ['ACTIVATION_IMMUTABLE_REQUEST_MISMATCH']);
            }
            $immutableTrace = data_get($this->evidence->latestArtifactPayload($run) ?? [],
                'composition_runtime_trace');
            $decisionTrace = data_get($decision->metrics, 'composition_runtime_trace');
            if (! is_array($immutableTrace) || $immutableTrace !== $decisionTrace) {
                return $this->armEvidenceResult('invalid', $runId, $run->data_hash,
                    null, null, ['ACTIVATION_IMMUTABLE_TRACE_MISMATCH']);
            }
        }

        $strategyPayload = collect((array) data_get($run->request_meta, 'payload.strategies', []))
            ->first(function (mixed $strategy) use ($agent): bool {
                if (! is_array($strategy)) {
                    return false;
                }
                $payloadAgentId = data_get($strategy, 'lab_agent_id');

                return ($payloadAgentId !== null && (int) $payloadAgentId === (int) $agent->id)
                    || (string) data_get($strategy, 'strategy', '') === (string) $agent->modelVersion?->strategy;
            });
        $actualContext = is_array($strategyPayload) && array_key_exists('specialist_context_contract', $strategyPayload)
            ? data_get($strategyPayload, 'specialist_context_contract')
            : null;
        $expectedContext = data_get($agent->modelVersion?->metadata, 'specialist_council_membership.contextual_cell');
        $expectedCell = (string) data_get(
            $agent->modelVersion?->metadata,
            'cooperative_experiment_block.context_cell_key',
            data_get($agent->modelVersion?->metadata, 'cooperative_evolution_capsule.context_cell_hash', ''),
        );
        $contextReasons = [];
        if (! is_array($expectedContext) || $expectedContext === []) {
            $contextReasons[] = 'SEALED_SPECIALIST_CONTEXT_REQUIRED';
        }
        if (! is_array($actualContext) || $actualContext === []) {
            $contextReasons[] = 'REQUEST_SPECIALIST_CONTEXT_REQUIRED';
        } else {
            if ($expectedCell === '' || (string) data_get($actualContext, 'cell_hash', '') !== $expectedCell) {
                $contextReasons[] = 'REQUEST_CONTEXT_CELL_MISMATCH';
            }
            if ((string) data_get($actualContext, 'outside_scope_action', '') !== 'WAIT') {
                $contextReasons[] = 'OUTSIDE_SCOPE_WAIT_CONTRACT_REQUIRED';
            }
        }
        if ((string) data_get($agent->modelVersion?->metadata, 'cooperative_experiment_block.block_key', '') !== $blockKey) {
            $contextReasons[] = 'EXPERIMENT_BLOCK_IDENTITY_MISMATCH';
        }
        $executionHash = (string) data_get($run->request_meta, 'payload.execution_contract.execution_hash', '');
        if ($executionHash === '') {
            $contextReasons[] = 'EXECUTION_HASH_REQUIRED';
        }
        if ($contextReasons !== []) {
            return $this->armEvidenceResult(
                'invalid', $runId, $run->data_hash, $executionHash ?: null,
                is_array($actualContext) ? $this->sessionInstanceId($actualContext) : null,
                $contextReasons,
            );
        }

        return $this->armEvidenceResult(
            'eligible', $runId, $run->data_hash, $executionHash,
            $this->sessionInstanceId($actualContext), [],
            (string) data_get($run->request_meta, 'dataset_manifest.mtf_bundle_hash', ''),
        );
    }

    /** @return array<int,string> */
    private function identityReasons(array $required, array $arms): array
    {
        $requiredArms = collect($required)->map(fn (string $arm): array => (array) ($arms[$arm] ?? []));
        $reasons = [];
        if ($requiredArms->pluck('data_hash')->filter()->unique()->count() !== 1) {
            $reasons[] = 'CANDIDATE_CONTROL_DATA_HASH_MISMATCH';
        }
        if ($requiredArms->pluck('execution_hash')->filter()->unique()->count() !== 1) {
            $reasons[] = 'CANDIDATE_CONTROL_EXECUTION_HASH_MISMATCH';
        }
        if ($requiredArms->pluck('context_cell_key')->filter()->unique()->count() !== 1) {
            $reasons[] = 'CANDIDATE_CONTROL_CONTEXT_CELL_MISMATCH';
        }
        $instances = $requiredArms->pluck('session_instance_id')->filter();
        if ($instances->count() !== count($required) || $instances->unique()->count() !== 1) {
            $reasons[] = 'CANDIDATE_CONTROL_SESSION_INSTANCE_MISMATCH';
        }

        return $reasons;
    }

    private function sessionInstanceId(array $context): ?string
    {
        $value = (string) data_get($context, 'session_instance_id', data_get($context, 'session_ownership.session_instance_id', ''));

        return $value !== '' ? $value : null;
    }

    /** @return array<string,mixed> */
    private function armEvidenceResult(
        string $status,
        ?string $runId,
        ?string $dataHash,
        ?string $executionHash,
        ?string $sessionInstanceId,
        array $reasonCodes,
        ?string $mtfBundleHash = null,
    ): array {
        return [
            'status' => $status,
            'run_id' => $runId,
            'data_hash' => $dataHash,
            'execution_hash' => $executionHash,
            'session_instance_id' => $sessionInstanceId,
            'mtf_bundle_hash' => $mtfBundleHash,
            'reason_codes' => array_values(array_unique($reasonCodes)),
        ];
    }

    /** @return array<string,float|int|null> */
    private function effects(string $type, array $arms): array
    {
        $v = fn (string $arm): ?float => isset($arms[$arm]) ? (float) $arms[$arm]['after_cost_value'] : null;
        if ($type === 'factorial') {
            $control = $v('control');
            $a = $v('a_only');
            $b = $v('b_only');
            $ab = $v('a_plus_b');

            return ['component_a_marginal_effect' => $this->delta($a, $control),
                'component_b_marginal_effect' => $this->delta($b, $control),
                'interaction_effect' => in_array(null, [$control, $a, $b, $ab], true) ? null : round($ab - $a - $b + $control, 6),
                'whole_capsule_effect' => $this->delta($ab, $control)];
        }
        if ($type === 'transfer') {
            return ['source_context_effect' => $this->delta($v('source_candidate'), $v('source_control')),
                'target_context_effect' => $this->delta($v('target_candidate'), $v('target_control')),
                'whole_capsule_effect' => $this->delta($v('target_candidate'), $v('target_control')),
                'cross_context_authority_granted' => 0];
        }
        if ($type === 'descendant') {
            return ['parent_effect' => $this->delta($v('parent_reference'), $v('parent_control')),
                'child_effect' => $this->delta($v('child_challenge'), $v('descendant_control')),
                'inheritance_effect' => $this->delta($v('child_challenge'), $v('parent_reference')),
                'whole_capsule_effect' => $this->delta($v('child_challenge'), $v('descendant_control'))];
        }
        $control = $v('exact_frozen_control');
        $candidate = $v('candidate') ?? $v('guard_challenge');

        return ['candidate_delta' => $this->delta($candidate, $control), 'whole_capsule_effect' => $this->delta($candidate, $control)];
    }

    /** @return array<string,mixed> */
    private function activationTelemetry(array $metrics, string $expectedCompositionId): array
    {
        $trace = (array) data_get($metrics, 'composition_runtime_trace', []);
        $report = (array) data_get($trace, 'decision_receipts', []);
        $receipts = (array) data_get($report, 'receipts', []);
        $passportId = (string) data_get($trace, 'composition_id', '');
        $tacticContexts = collect($receipts)->filter(fn (mixed $receipt): bool =>
            is_array($receipt) && in_array((string) data_get($receipt, 'tactic_signal'), ['BUY', 'SELL'], true)
        )->pluck('paired_context_id')->values()->all();
        $entryContexts = collect($receipts)->filter(fn (mixed $receipt): bool =>
            is_array($receipt) && data_get($receipt, 'preentry_accepted') === true
        )->pluck('paired_context_id')->values()->all();
        $signalCount = max(0, (int) data_get($trace, 'observations.strategy_signals_before_tactic', 0));
        $tacticCount = max(0, (int) data_get($trace, 'component_execution.tactic.accepted_count', 0));

        return [
            'valid' => (string) data_get($trace, 'protocol') === 'xauusd_composition_runtime_trace_v3'
                && data_get($trace, 'execution_receipt_valid') === true
                && data_get($trace, 'component_bindings_valid') === true
                && data_get($trace, 'authority_bindings_valid') === true
                && data_get($trace, 'decision_receipts_valid') === true
                && $passportId !== '' && $passportId === $expectedCompositionId
                && (int) data_get($report, 'decision_count', -1) === $signalCount
                && count($receipts) === $signalCount
                && count($tacticContexts) === $tacticCount
                && (int) data_get($report, 'opportunity_count', -1) >= $signalCount
                && strlen((string) data_get($report, 'opportunity_universe_hash', '')) === 64
                && strlen((string) data_get($report, 'decision_digest', '')) === 64
                && collect($receipts)->every(fn (mixed $receipt): bool =>
                    is_array($receipt) && strlen((string) data_get($receipt, 'paired_context_id', '')) === 64),
            'composition_id' => $passportId,
            'strategy_signals' => $signalCount,
            'tactic_accepted' => $tacticCount,
            'accepted_entries' => max(0, (int) data_get($trace, 'observations.accepted_entries', 0)),
            'managed_trades' => max(0, (int) data_get($trace, 'component_execution.management.completed_trade_count', 0)),
            'evaluated_rows' => max(0, (int) data_get($trace, 'observations.rows', 0)),
            'opportunity_count' => (int) data_get($report, 'opportunity_count', 0),
            'opportunity_universe_hash' => (string) data_get($report, 'opportunity_universe_hash', ''),
            'no_signal_count' => (int) data_get($report, 'no_signal_count', 0),
            'tactic_accepted_context_ids' => $tacticContexts,
            'preentry_accepted_context_ids' => $entryContexts,
            'decision_digest' => (string) data_get($report, 'decision_digest', ''),
            'reason_counts' => (array) data_get($report, 'rejection_counts', []),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function activationEffects(array $arms): array
    {
        $values = collect($arms)->mapWithKeys(fn (array $result, string $arm): array => [
            $arm => (array) data_get($result, 'activation_telemetry', []),
        ])->all();
        $opportunities = (int) data_get($values, 'control.opportunity_count', 0);
        $signalOpportunities = (int) collect($values)->max(
            fn (array $row): int => (int) ($row['strategy_signals'] ?? 0)
        );
        $accepted = fn (string $arm): int => (int) data_get($values, $arm.'.tactic_accepted', 0);
        $entries = fn (string $arm): int => (int) data_get($values, $arm.'.accepted_entries', 0);
        $otherContexts = collect(['control', 'a_only', 'b_only'])->flatMap(fn (string $arm): array =>
            (array) data_get($values, $arm.'.tactic_accepted_context_ids', [])
        )->unique()->all();
        $jointOnlyContexts = array_values(array_diff(
            (array) data_get($values, 'a_plus_b.tactic_accepted_context_ids', []), $otherContexts
        ));
        $jointEntryContexts = array_values(array_intersect($jointOnlyContexts,
            (array) data_get($values, 'a_plus_b.preentry_accepted_context_ids', [])));
        $claim = $opportunities < ProofFrontierService::MIN_PAIRED_OPPORTUNITIES
            || $signalOpportunities < ProofFrontierService::MIN_SIGNAL_OPPORTUNITIES
            ? 'underpowered_activation'
            : ($jointOnlyContexts !== [] ? ($jointEntryContexts !== [] && $entries('a_plus_b') > 0
                ? 'joint_entry_activation_hypothesis' : 'joint_tactic_activation_hypothesis')
                : (collect($values)->contains(fn (array $row): bool => (int) ($row['tactic_accepted'] ?? 0) > 0)
                    ? 'activation_observed_nonexclusive' : 'local_activation_not_observed'));

        return [
            'protocol' => ProofFrontierService::PROTOCOL,
            'behavioral_claim' => $claim,
            'paired_opportunities' => $opportunities,
            'maximum_arm_signal_opportunities' => $signalOpportunities,
            'joint_only_context_count' => count($jointOnlyContexts),
            'joint_only_entry_context_count' => count($jointEntryContexts),
            'minimum_opportunities_for_negative_or_joint_claim' => ProofFrontierService::MIN_PAIRED_OPPORTUNITIES,
            'minimum_signal_opportunities_for_claim' => ProofFrontierService::MIN_SIGNAL_OPPORTUNITIES,
            'arm_telemetry' => $values,
            'economic_claim' => 'not_evaluated',
            'validation_window_status' => 'not_reserved',
            'component_credit_allowed' => false,
            'economic_credit_allowed' => false,
            'promotion_evidence' => false,
        ];
    }

    /** Persist a science-only conclusion and a fenced follow-up; never mint credit here. */
    private function recordActivationFrontier(
        CooperativeExperimentSettlement $settlement,
        $agents,
        array $arms,
        array $effects,
    ): array {
        $first = $agents->first();
        $activation = (array) data_get($first?->modelVersion?->metadata, 'activation_factorial', []);
        $block = (array) data_get($first?->modelVersion?->metadata, 'cooperative_experiment_block', []);
        $claim = (string) data_get($effects, 'behavioral_claim', 'underpowered_activation');
        $hasActivation = in_array($claim, [
            'joint_tactic_activation_hypothesis', 'joint_entry_activation_hypothesis',
            'activation_observed_nonexclusive',
        ], true);
        $classification = $hasActivation ? 'BEHAVIORAL_ACTIVATION_HYPOTHESIS'
            : ($claim === 'underpowered_activation' ? 'UNDERPOWERED' : 'INCONCLUSIVE');
        $nextWork = $hasActivation || $classification === 'UNDERPOWERED'
            ? ['type' => $hasActivation ? 'activation_independent_validation' : 'activation_new_opportunity_window',
                'identity' => (string) data_get($activation, 'hypothesis_key'),
                'priority' => $hasActivation ? 7 : 3,
                'executable' => false,
                'retry_condition' => ['code' => 'NEW_PREREGISTERED_INDEPENDENT_WINDOW_REQUIRED',
                    'max_experiments' => 1, 'same_evidence_replay_forbidden' => true],
                'activation_settlement_id' => $settlement->id,
                'validation_window_status' => 'not_reserved',
                'credit_allowed' => false]
            : [];
        $terminal = $nextWork === []
            ? ['code' => 'LOCAL_ACTIVATION_NOT_OBSERVED', 'hypothesis_key' => data_get($activation, 'hypothesis_key')]
            : [];
        $control = (array) data_get($arms, 'control', []);
        $dataMtfHash = hash('sha256', implode('|', [
            (string) data_get($control, 'data_hash'), (string) data_get($control, 'mtf_bundle_hash'),
        ]));
        $contract = [
            'contract_version' => ResearchExperimentConversionKernelService::CONTRACT_VERSION,
            'source' => ['type' => CooperativeExperimentSettlement::class, 'id' => $settlement->id],
            'scope' => ['symbol' => (string) $first?->symbol,
                'laboratory_timeframe' => (string) $first?->timeframe,
                'execution_timeframe' => 'M5'],
            'claim' => ['target_stage' => 'strategy_to_tactic_activation',
                'hypothesis' => 'Do two bounded interventions activate on the same paired candle?',
                'minimum_meaningful_effect' => [
                    'minimum_paired_opportunities' => ProofFrontierService::MIN_PAIRED_OPPORTUNITIES,
                    'independent_economic_validation_required' => true,
                ]],
            'identity' => [
                'baseline_epoch_hash' => hash('sha256', implode('|', [
                    (string) data_get($activation, 'source_agent_id'),
                    (string) data_get($activation, 'source_data_hash'),
                ])),
                'data_and_mtf_hash' => $dataMtfHash,
                'runtime_and_contract_hash' => hash('sha256', implode('|', [
                    (string) data_get($control, 'execution_hash'),
                    (string) data_get($block, 'activation_manifest_hash'),
                ])),
                'intervention_hash' => (string) data_get($activation, 'hypothesis_key'),
                'window_plan_hash' => hash('sha256', implode('|', [
                    (string) data_get($control, 'data_hash'),
                    (string) data_get($activation, 'max_discovery_trials'),
                    'validation_window_not_reserved',
                ])),
                'evaluator_version' => ProofFrontierService::PROTOCOL,
            ],
            'arms' => collect($arms)->map(fn (array $arm, string $role): array => [
                'role' => $role, 'agent_id' => (int) $arm['lab_agent_id'],
                'model_version_id' => (int) $arm['model_version_id'],
            ])->values()->all(),
            'revisions' => ['subject' => 1, 'evidence' => 1],
        ];

        return app(ResearchExperimentConversionKernelService::class)->record($contract, [
            'settlement_key' => $settlement->settlement_key,
            'behavioral_claim' => $claim,
            'paired_opportunities' => (int) data_get($effects, 'paired_opportunities', 0),
            'joint_only_context_count' => (int) data_get($effects, 'joint_only_context_count', 0),
            'arm_run_ids' => collect($arms)->mapWithKeys(fn (array $arm, string $role): array => [
                $role => (string) $arm['evidence_run_id'],
            ])->all(),
            'economic_claim' => 'not_evaluated', 'promotion_evidence' => false,
        ], $classification, $nextWork, $terminal);
    }

    private function value(array $metrics): float
    {
        return round((float) data_get($metrics, 'after_cost_expectancy_r', data_get($metrics, 'expectancy_r', data_get($metrics, 'net_profit_percent', 0))), 6);
    }

    private function delta(?float $candidate, ?float $control): ?float
    {
        return $candidate === null || $control === null ? null : round($candidate - $control, 6);
    }

    private function settleModuleSpecies($agents, string $type, array $effects, int $settlementId): void
    {
        if (! Schema::hasTable('cooperative_module_species_members')) {
            return;
        }
        foreach ($agents as $agent) {
            $arm = (string) data_get($agent->modelVersion?->metadata, 'cooperative_experiment_block.arm', '');
            $delta = match (true) {
                $type === 'factorial' && $arm === 'a_only' => data_get($effects, 'component_a_marginal_effect'),
                $type === 'factorial' && $arm === 'b_only' => data_get($effects, 'component_b_marginal_effect'),
                $type === 'transfer' && str_starts_with($arm, 'source_candidate') => data_get($effects, 'source_context_effect'),
                $type === 'transfer' && str_starts_with($arm, 'target_candidate') => data_get($effects, 'target_context_effect'),
                $type === 'descendant' && $arm === 'parent_reference' => data_get($effects, 'parent_effect'),
                $type === 'descendant' && $arm === 'child_challenge' => data_get($effects, 'child_effect'),
                in_array($arm, ['candidate', 'guard_challenge'], true) => data_get($effects, 'candidate_delta'),
                default => null,
            };
            if ($delta === null) {
                continue;
            }
            $changedSpecies = (string) data_get(
                $agent->modelVersion?->metadata,
                'cooperative_experiment_block.changed_species',
                '',
            );
            if ($changedSpecies === '' || ! in_array($changedSpecies, CooperativeModuleSpeciesService::SPECIES, true)) {
                continue;
            }
            // Toolbox assignment is a research hypothesis, not evidence that
            // the instrument participated in the decision.  A toolbox member
            // may receive local credit only when the immutable runtime ledger
            // attests a consumed causal-candidate activation for this arm.
            if ($changedSpecies === 'toolbox_instrument' && ! $this->hasActivatedCausalInstrument($agent)) {
                continue;
            }
            $authority = (float) $delta > 0 ? 'repair_credit' : 'information_credit';
            $status = (float) $delta > 0 ? 'beneficial_local_observation' : ((float) $delta < 0 ? 'harmful_local_observation' : 'neutral_local_observation');
            CooperativeModuleSpeciesMember::query()
                ->where('lab_agent_id', $agent->id)
                ->where('species', $changedSpecies)
                ->get()
                ->each(function (CooperativeModuleSpeciesMember $member) use ($settlementId, $delta, $authority, $status, $changedSpecies): void {
                    $evidence = (array) $member->evidence;
                    unset($evidence['invalidated_settlement']);
                    $member->update(['evidence' => [...$evidence,
                        'latest_block_settlement_id' => $settlementId, 'local_control_relative_delta' => $delta,
                        'credited_species' => $changedSpecies,
                        'supporting_species_credit_suppressed' => true,
                        'global_inheritance_allowed' => false, 'promotion_evidence' => false],
                        'authority_level' => $authority, 'status' => $status]);
                });
            if ((float) $delta < 0 && Schema::hasTable('contextual_specialist_capsules')) {
                ContextualSpecialistCapsule::query()->where('lab_agent_id', $agent->id)
                    ->where('status', '!=', 'elite')->update(['status' => 'anti_skill']);
            }
        }
    }

    private function hasActivatedCausalInstrument(LabAgent $agent): bool
    {
        return InstrumentInvocationLedger::query()
            ->where('lab_agent_id', $agent->id)
            ->whereNull('paper_signal_id')
            ->where('used_in_decision', true)
            ->get()
            ->contains(fn (InstrumentInvocationLedger $row): bool => data_get($row->metadata, 'declaration.causal_candidate') === true
                && data_get($row->metadata, 'runtime_trace.decision_path_activated') === true
                && (string) data_get($row->metadata, 'runtime_trace.status') === 'consumed'
            );
    }

    private function retractInvalidSettlement($agents, int $settlementId, array $invalidArms): void
    {
        if (Schema::hasTable('cooperative_module_species_members')) {
            foreach ($agents as $agent) {
                CooperativeModuleSpeciesMember::query()->where('lab_agent_id', $agent->id)->get()
                    ->each(function (CooperativeModuleSpeciesMember $member) use ($settlementId, $invalidArms): void {
                        if ((int) data_get($member->evidence, 'latest_block_settlement_id', 0) !== $settlementId) {
                            return;
                        }
                        $evidence = (array) $member->evidence;
                        $evidence['local_control_relative_delta'] = null;
                        $evidence['global_inheritance_allowed'] = false;
                        $evidence['promotion_evidence'] = false;
                        $evidence['invalidated_settlement'] = [
                            'settlement_id' => $settlementId,
                            'reason_codes' => $invalidArms,
                            'invalidated_at' => now()->utc()->toIso8601String(),
                        ];
                        $member->update([
                            'evidence' => $evidence,
                            'authority_level' => 'hypothesis',
                            'status' => 'research',
                        ]);
                    });
            }
        }
        if (Schema::hasTable('contextual_specialist_capsules')) {
            $invalidArmNames = array_values(array_filter(
                array_keys($invalidArms),
                static fn (string $arm): bool => $arm !== '_block_identity',
            ));
            foreach ($agents->filter(fn (LabAgent $agent): bool => in_array(
                (string) data_get($agent->modelVersion?->metadata, 'cooperative_experiment_block.arm', ''),
                $invalidArmNames,
                true,
            )) as $agent) {
                ContextualSpecialistCapsule::query()->where('lab_agent_id', $agent->id)->get()
                    ->each(function (ContextualSpecialistCapsule $capsule) use ($settlementId, $invalidArms): void {
                        $wasElite = (string) $capsule->status === 'elite';
                        $receipt = [
                            'settlement_id' => $settlementId,
                            'reason_codes' => $invalidArms,
                            'invalidated_at' => now()->utc()->toIso8601String(),
                        ];
                        $capsule->update([
                            'authority_level' => 'invalid_evidence',
                            'status' => 'invalid_evidence',
                            'evidence' => [
                                ...(array) $capsule->evidence,
                                'invalidated_settlement' => $receipt,
                                'promotion_evidence' => false,
                            ],
                        ]);
                        if ($wasElite && $capsule->replaces_capsule_id
                            && ! ContextualSpecialistCapsule::query()
                                ->where('symbol', $capsule->symbol)
                                ->where('timeframe', $capsule->timeframe)
                                ->where('context_cell_key', $capsule->context_cell_key)
                                ->where('status', 'elite')
                                ->exists()) {
                            ContextualSpecialistCapsule::query()
                                ->whereKey($capsule->replaces_capsule_id)
                                ->where('status', 'retained_history')
                                ->where('authority_level', 'contextually_confirmed')
                                ->update(['status' => 'elite']);
                        }
                    });
            }
        }
        if (Schema::hasTable('lab_evolution_archive_entries')) {
            $invalidArmNames ??= array_values(array_filter(
                array_keys($invalidArms),
                static fn (string $arm): bool => $arm !== '_block_identity',
            ));
            foreach ($agents->filter(fn (LabAgent $agent): bool => in_array(
                (string) data_get($agent->modelVersion?->metadata, 'cooperative_experiment_block.arm', ''),
                $invalidArmNames,
                true,
            )) as $agent) {
                LabEvolutionArchiveEntry::query()
                    ->where('lab_agent_id', $agent->id)
                    ->where('archive_type', 'contextual_capsule')
                    ->get()
                    ->each(function (LabEvolutionArchiveEntry $entry) use ($settlementId, $invalidArms): void {
                        $entry->update([
                            'rank' => 0,
                            'novelty_score' => 0,
                            'status' => 'invalid_evidence',
                            'metadata' => [
                                ...(array) $entry->metadata,
                                'invalidated_settlement' => [
                                    'settlement_id' => $settlementId,
                                    'reason_codes' => $invalidArms,
                                    'invalidated_at' => now()->utc()->toIso8601String(),
                                ],
                                'promotion_evidence' => false,
                            ],
                        ]);
                    });
            }
        }
        if (Schema::hasTable('contextual_instrument_bundle_effects')) {
            ContextualInstrumentBundleEffect::query()
                ->where('cooperative_experiment_settlement_id', $settlementId)
                ->get()
                ->each(function (ContextualInstrumentBundleEffect $effect) use ($settlementId, $invalidArms): void {
                    $effect->update([
                        'authority_level' => 'invalid_evidence',
                        'contraindicated' => false,
                        'evidence' => [...(array) $effect->evidence,
                            'invalidated_settlement' => [
                                'settlement_id' => $settlementId,
                                'reason_codes' => $invalidArms,
                                'invalidated_at' => now()->utc()->toIso8601String(),
                            ],
                            'global_inheritance_allowed' => false,
                            'promotion_evidence' => false,
                        ],
                    ]);
                });
        }
    }
}
