<?php

namespace App\Services;

use App\Models\AgentLearningSettlement;
use App\Models\CanonicalLearningOutbox;
use App\Models\LabSkillZooEntry;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\ResearchExperimentReceipt;
use App\Models\ResearchExperimentWorkItem;
use App\Models\SpecialistCouncilVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * The conversion kernel owns only experiment identity, terminal receipts and
 * durable next work. It does not execute a replay or grant trading authority.
 */
class ResearchExperimentConversionKernelService
{
    public const PROTOCOL = 'research_experiment_conversion_kernel_v1';
    public const CONTRACT_VERSION = 'research_experiment_v1';
    public const RULE_VERSION = 'research_conversion_rules_v1';
    public const POLICY_SELECTION_PROTOCOL = 'qualified_native_panel_policy_selection_v1';
    private const MAX_POLICY_SOURCES = 8;
    private const LEASE_SECONDS = 900;
    private const MAX_EXPIRED_LEASE_RECOVERY = 100;
    private const CLASSIFICATIONS = ['POSITIVE_CANDIDATE', 'BEHAVIORAL_ACTIVATION_HYPOTHESIS', 'INCONCLUSIVE', 'UNDERPOWERED', 'UNREACHABLE', 'TECHNICAL_QUARANTINE', 'BUDGET_EXHAUSTED', 'HARMFUL'];

    /** @return array<string,mixed> */
    public function record(array $contract, array $evidence, string $classification, array $nextWork = [], array $terminalReason = []): array
    {
        if (! $this->available()) return $this->blocked('RESEARCH_CONVERSION_TABLES_UNAVAILABLE');
        // A terminal experiment must close in exactly one direction. Allowing
        // neither produces a silent WAIT; allowing both makes the authority
        // state ambiguous and permits a terminal claim to keep reproducing.
        if (($nextWork === []) === ($terminalReason === [])) {
            return $this->blocked('RESEARCH_CLOSURE_EXACTLY_ONE_OUTCOME_REQUIRED');
        }
        $validation = $this->validate($contract, $classification);
        if (! $validation['valid']) return $this->blocked($validation['reason']);
        $contract = $validation['contract'];
        if (in_array((string) ($nextWork['type'] ?? ''), [
            'activation_independent_validation', 'activation_new_opportunity_window',
        ], true)) {
            $plan = (array) ($nextWork['validation_plan'] ?? []);
            if (! app(ActivationValidationPlanService::class)->valid($plan, $plan)
                || ! hash_equals((string) data_get($contract, 'identity.window_plan_hash', ''),
                    (string) ($plan['plan_hash'] ?? ''))) {
                return $this->blocked('ACTIVATION_VALIDATION_PLAN_INVALID');
            }
        }
        $contractHash = $this->hash($contract);
        $evidenceHash = $this->hash($evidence);
        $source = (array) $contract['source'];
        $key = hash('sha256', implode('|', [self::PROTOCOL, $source['type'], $source['id'] ?? 'none', $contractHash, $evidenceHash]));

        return DB::transaction(function () use ($contract, $evidence, $classification, $nextWork, $terminalReason, $contractHash, $evidenceHash, $source, $key): array {
            $receipt = ResearchExperimentReceipt::query()->firstOrCreate(['receipt_key' => $key], [
                'source_type' => (string) $source['type'], 'source_id' => isset($source['id']) ? (int) $source['id'] : null,
                'canonical_learning_outbox_id' => isset($source['canonical_learning_outbox_id']) ? (int) $source['canonical_learning_outbox_id'] : null,
                'symbol' => (string) data_get($contract, 'scope.symbol'),
                'laboratory_timeframe' => (string) data_get($contract, 'scope.laboratory_timeframe'),
                'execution_timeframe' => (string) data_get($contract, 'scope.execution_timeframe'),
                'contract_version' => self::CONTRACT_VERSION, 'rule_version' => self::RULE_VERSION,
                'contract_hash' => $contractHash, 'evidence_hash' => $evidenceHash, 'classification' => $classification,
                'subject_revision' => max(1, (int) data_get($contract, 'revisions.subject', 1)),
                'evidence_revision' => max(1, (int) data_get($contract, 'revisions.evidence', 1)),
                'payload' => ['protocol' => self::PROTOCOL, 'contract' => $contract, 'evidence' => $evidence, 'promotion_evidence' => false],
                'terminal_reason' => $terminalReason === [] ? null : ['protocol' => self::PROTOCOL, ...$terminalReason, 'promotion_evidence' => false],
            ]);
            // One receipt produces one evidence-bounded memory projection.
            // A duplicate delivery leaves both the receipt and memory intact.
            if ($receipt->wasRecentlyCreated) {
                app(ResearchKnowledgePortfolioService::class)->recordReceipt($receipt);
            }
            $work = null;
            if ($nextWork !== []) {
                $nextWork = $this->normalizeNextWork($nextWork);
                $type = (string) ($nextWork['type'] ?? '');
                if ($type === '') throw new \InvalidArgumentException('NEXT_WORK_TYPE_REQUIRED');
                $work = ResearchExperimentWorkItem::query()->firstOrCreate(
                    ['work_key' => hash('sha256', self::PROTOCOL.'|'.$receipt->receipt_key.'|'.$type.'|'.($nextWork['identity'] ?? 'default'))],
                    ['research_experiment_receipt_id' => $receipt->id, 'symbol' => $receipt->symbol,
                        'timeframe' => $receipt->laboratory_timeframe, 'work_type' => $type,
                        'status' => (bool) ($nextWork['executable'] ?? false)
                            && ! isset($nextWork['dependency_key']) ? 'ready' : 'blocked',
                        'priority' => max(1, min(9, (int) ($nextWork['priority'] ?? 5))),
                        'dependency_key' => $nextWork['dependency_key'] ?? null,
                        'payload' => ['protocol' => self::PROTOCOL, ...$nextWork, 'promotion_evidence' => false],
                    ],
                );
                // Idempotent redelivery also repairs pre-owner rows produced
                // before the executable closure contract was introduced.
                $this->normalizePersistedWork($work);
            }
            return ['protocol' => self::PROTOCOL, 'status' => 'recorded', 'receipt_id' => $receipt->id,
                'receipt_key' => $receipt->receipt_key, 'classification' => $receipt->classification,
                'work_id' => $work?->id, 'work_status' => $work?->status, 'promotion_evidence' => false];
        });
    }

    /**
     * Adapter for the existing canonical learning source of truth.  It makes
     * no new economic claim and never replays an already settled pair.
     */
    public function recordCanonicalSettlement(
        LabLearningLanePair $pair,
        CanonicalLearningOutbox $outbox,
        AgentLearningSettlement $settlement,
        ?LabMutationResponseMap $map,
        array $result,
        bool $insufficient,
        mixed $cartridge,
    ): array {
        $positive = ! $insufficient && (bool) data_get($outbox->payload, 'causal_credit_eligible')
            && (bool) data_get($outbox->payload, 'delta.improved');
        $classification = $insufficient ? 'UNDERPOWERED' : ($positive ? 'POSITIVE_CANDIDATE' : 'INCONCLUSIVE');
        $baselineEpoch = hash('sha256', implode('|', [(string) $pair->control_data_hash, (string) $pair->control_execution_hash, (string) $pair->control_agent_id]));
        $intervention = hash('sha256', implode('|', [(string) ($map?->response_key ?? ''), json_encode((array) ($map?->tested_value ?? []))]));
        $cartridgeId = (int) data_get($cartridge, 'cartridge_id', data_get($cartridge, 'id', 0));
        $nextWork = $positive && $cartridgeId > 0
            ? ['type' => 'cartridge_confirmation', 'identity' => (string) $cartridgeId, 'priority' => 8,
                'dependency_key' => 'cartridge_confirmation_admission:'.$cartridgeId, 'cartridge_id' => $cartridgeId]
            : [];
        $terminalReason = $nextWork === [] ? ['code' => $insufficient ? 'INSUFFICIENT_POWER' : 'NO_EXECUTABLE_FOLLOW_UP',
            'detail' => $positive ? 'POSITIVE_EVIDENCE_HAS_NO_EXECUTABLE_CARTRIDGE' : 'CANONICAL_SETTLEMENT_CLASSIFIED'] : [];

        return $this->record([
            'contract_version' => self::CONTRACT_VERSION,
            'source' => ['type' => LabLearningLanePair::class, 'id' => $pair->id, 'canonical_learning_outbox_id' => $outbox->id],
            'scope' => ['symbol' => $pair->symbol, 'laboratory_timeframe' => $pair->timeframe, 'execution_timeframe' => 'M5'],
            'claim' => ['target_stage' => 'full_replay', 'hypothesis' => (string) ($pair->target ?? 'paired_mutation'),
                'minimum_meaningful_effect' => (array) ($pair->target_delta ?? [])],
            'identity' => ['baseline_epoch_hash' => $baselineEpoch,
                'data_and_mtf_hash' => (string) ($pair->candidate_data_hash ?: $outbox->data_hash),
                'runtime_and_contract_hash' => (string) ($pair->candidate_execution_hash ?: $outbox->execution_hash),
                'intervention_hash' => $intervention,
                'window_plan_hash' => hash('sha256', (string) ($pair->independent_window_key ?? 'canonical-window')),
                'evaluator_version' => CanonicalLearningOutboxService::PROTOCOL],
            'arms' => [['role' => 'frozen_control', 'agent_id' => $pair->control_agent_id], ['role' => 'candidate', 'agent_id' => $pair->candidate_agent_id]],
            'revisions' => ['subject' => 1, 'evidence' => max(1, (int) $settlement->id)],
        ], ['settlement_id' => $settlement->id, 'canonical_outbox_id' => $outbox->id,
            'evidence_run_id' => $outbox->evidence_run_id, 'result_hash' => $this->hash($result), 'promotion_evidence' => false],
        $classification, $nextWork, $terminalReason);
    }

    /** Claim only ready work; expired tokens are first returned to ready. */
    public function claim(int $limit = 10): array
    {
        if (! $this->available()) return [];
        $this->recoverExpiredLeases();
        return $this->claimMatching($limit);
    }

    /** Claim only work explicitly owned by the single research arbiter. */
    public function claimForOwner(string $owner, int $limit = 1): array
    {
        if (! $this->available()) return [];
        $this->reconcileOwnershipAndDependencies();

        return $this->claimMatching($limit, $owner);
    }

    /** Resume only the work which already owns this unfinished canonical cohort. */
    public function claimCouncilContinuationForGeneration(\App\Models\LabGeneration $generation): ?ResearchExperimentWorkItem
    {
        $id = (int) data_get($generation->trigger_context, 'native_specialist_council_intent.followup_work_item_id',
            data_get($generation->trigger_context, 'specialist_council_authorized_panel.work_item_id',
                data_get($generation->trigger_context, 'scoped_descendant_execution.work_item_id', 0)));
        if ($id <= 0 || ! $this->available()) return null;
        $this->reconcileOwnershipAndDependencies();
        return $this->claimMatching(1, ResearchLoopArbiterService::class, $id)[0] ?? null;
    }

    /**
     * Repair legacy metadata and release only dependencies that are now
     * executable. Unsupported follow-ups remain visible and explicitly
     * blocked; they can never masquerade as a runnable queue.
     *
     * @return array<string,int|string|bool>
     */
    public function reconcileOwnershipAndDependencies(): array
    {
        if (! $this->available()) {
            return ['protocol' => self::PROTOCOL, 'normalized' => 0, 'released' => 0, 'blocked' => 0, 'expired_leases_recovered' => 0, 'promotion_evidence' => false];
        }
        // Closure inspection runs before the arbiter can claim a continuation.
        // Recover only expired operational ownership here, otherwise that
        // boundary permanently rejects the very claim which could recover it.
        $expiredRecovered = $this->recoverExpiredLeases();
        $normalized = 0;
        $released = 0;
        $blocked = 0;
        ResearchExperimentWorkItem::query()
            ->whereIn('status', ['ready', 'blocked'])
            ->orderBy('id')
            ->chunkById(100, function ($items) use (&$normalized, &$released, &$blocked): void {
                foreach ($items as $snapshot) {
                    // The chunk is only a bounded list of identities. A
                    // registrar or worker may have sealed/leased this row
                    // since it was read; never write that stale payload back.
                    DB::transaction(function () use ($snapshot, &$normalized, &$released, &$blocked): void {
                        $item = ResearchExperimentWorkItem::whereKey($snapshot->id)->lockForUpdate()->first();
                        if (! $item || ! in_array($item->status, ['ready', 'blocked'], true)) return;
                        $before = (array) $item->payload;
                        $this->normalizePersistedWork($item);
                        $item->refresh();
                        if ($before !== (array) $item->payload) $normalized++;
                        $payload = (array) $item->payload;
                        $councilProof = null;
                        if ($item->work_type === ScopedDescendantCandidatePreparationService::WORK_TYPE) {
                            $proof = app(ScopedDescendantCandidatePreparationService::class)->inspectWork($item);
                            $payload['executable'] = ($proof['executable'] ?? false) === true;
                            $payload['retry_condition']['code'] = (string) ($proof['reason_code'] ?? 'SCOPED_DESCENDANT_FUTURE_SERVER_ROSTER_REQUIRED');
                            if ((array) $item->payload !== $payload) $item->update(['payload' => $payload]);
                        }
                        if (in_array($item->work_type, DescendantScopedExecutionService::WORK_TYPES, true)) {
                            $proof = app(DescendantScopedExecutionService::class)->inspectWork($item);
                            $payload['executable'] = ($proof['executable'] ?? false) === true;
                            $payload['retry_condition']['code'] = (string) ($proof['reason_code'] ?? 'DESCENDANT_ORIGINAL_OWNER_REQUIRED');
                            if ((array) $item->payload !== $payload) $item->update(['payload' => $payload]);
                        }
                        if (str_starts_with((string) $item->work_type, 'specialist_council_')) {
                            $proof = app(SpecialistCouncilResearchFeedbackService::class)->inspectFollowupReadiness($item);
                            $councilProof = $proof;
                            $payload['executable'] = ($proof['executable'] ?? false) === true;
                            $payload['retry_condition']['code'] = (string) ($proof['reason'] ?? 'COUNCIL_PREREQUISITE_PROOF_REQUIRED');
                            $hold = (array) data_get($item->result, 'dependency_hold', []);
                            if (($hold['dependency_check_failed'] ?? false) === true) {
                                $payload['executable'] = false;
                                $payload['retry_condition']['code'] = 'COUNCIL_OPERATIONAL_DEPENDENCY_CHECK_UNAVAILABLE';
                            }
                            if ($payload['executable'] && isset($hold['prerequisite_hash'])) {
                                try {
                                    $unchanged = hash_equals((string) $hold['prerequisite_hash'],
                                        app(SpecialistCouncilFollowupExecutionService::class)->retryPrerequisiteHash($item));
                                } catch (\Throwable) { $unchanged = true; }
                                if ($unchanged) {
                                    $payload['executable'] = false;
                                    $payload['retry_condition']['code'] = (string) ($hold['reason'] ?? 'COUNCIL_OPERATIONAL_DEPENDENCY_UNCHANGED');
                                }
                            }
                            if ((array) $item->payload !== $payload) $item->update(['payload' => $payload]);
                        }
                        $executable = (bool) ($payload['executable'] ?? false);
                        $dependencyReady = $this->dependencyReady($item, $payload, $councilProof);
                        $desired = $executable && $dependencyReady ? 'ready' : 'blocked';
                        if ((string) $item->status !== $desired) {
                            $item->update([
                                'status' => $desired,
                                'last_error' => $desired === 'blocked'
                                    ? (string) data_get($payload, 'retry_condition.code', 'DEPENDENCY_NOT_READY')
                                    : null,
                            ]);
                            $desired === 'ready' ? $released++ : $blocked++;
                        }
                    });
                }
            });

        return ['protocol' => self::PROTOCOL, 'normalized' => $normalized, 'released' => $released,
            'blocked' => $blocked, 'expired_leases_recovered' => $expiredRecovered, 'promotion_evidence' => false];
    }

    /** Return a bounded expired lease set to dependency revalidation, not execution. */
    private function recoverExpiredLeases(): int
    {
        $cutoff = now();
        $ids = ResearchExperimentWorkItem::query()->where('status', 'leased')
            ->whereNull('completed_at')->where('lease_expires_at', '<=', $cutoff)
            ->orderBy('id')->limit(self::MAX_EXPIRED_LEASE_RECOVERY)->pluck('id');
        $recovered = 0;
        foreach ($ids as $id) {
            $recovered += DB::transaction(function () use ($id, $cutoff): int {
                $item = ResearchExperimentWorkItem::whereKey($id)->lockForUpdate()->first();
                if (! $item || $item->status !== 'leased' || $item->completed_at !== null
                    || ! $item->lease_expires_at || $item->lease_expires_at->gt($cutoff)) return 0;

                // Keep the compare-and-set even under the lock: a stale read
                // must not release a renewed lease or overwrite a newer fence.
                $query = ResearchExperimentWorkItem::whereKey($id)->where('status', 'leased')
                    ->whereNull('completed_at')->where('fence_version', (int) $item->fence_version)
                    ->where('lease_expires_at', '<=', $cutoff);
                $item->lease_token === null ? $query->whereNull('lease_token')
                    : $query->where('lease_token', $item->lease_token);

                return $query->update(['status' => 'ready', 'lease_token' => null,
                    'lease_expires_at' => null, 'heartbeat_at' => null,
                    'last_error' => 'LEASE_EXPIRED', 'updated_at' => $cutoff]);
            });
        }

        return $recovered;
    }

    /** Fenced defer: preserves the work and makes its retry state explicit. */
    public function defer(ResearchExperimentWorkItem $item, string $reason, bool $retryable = true): bool
    {
        return ResearchExperimentWorkItem::query()->whereKey($item->id)->where('status', 'leased')
            ->where('lease_token', $item->lease_token)->where('fence_version', (int) $item->fence_version)
            ->where('lease_expires_at', '>', now())
            ->update(['status' => $retryable ? 'ready' : 'blocked', 'last_error' => $reason,
                'lease_token' => null, 'lease_expires_at' => null, 'heartbeat_at' => null]) === 1;
    }

    /** @return array<int,ResearchExperimentWorkItem> */
    private function claimMatching(int $limit, ?string $owner = null, ?int $workItemId = null): array
    {
        return DB::transaction(function () use ($limit, $owner, $workItemId): array {
            $query = ResearchExperimentWorkItem::query()->where('status', 'ready');
            if ($workItemId !== null) $query->whereKey($workItemId);
            if ($owner !== null) {
                // Filter ownership in SQL before applying the bounded claim
                // limit. Otherwise twenty unrelated high-priority rows can
                // indefinitely hide valid arbiter work just beyond the scan.
                $query->where('payload->owner', $owner);
            }
            $items = $query->orderByDesc('priority')->orderBy('id')
                ->lockForUpdate()->limit(max(1, min(50, $limit)))->get();
            $claimed = [];
            foreach ($items as $item) {
                $leaseSeconds = self::LEASE_SECONDS;
                if ($item->work_type === ScopedDescendantCandidatePreparationService::WORK_TYPE) {
                    $proof = app(ScopedDescendantCandidatePreparationService::class)->inspectWork($item);
                    if (($proof['executable'] ?? false) !== true) {
                        $item->update(['status' => 'blocked', 'last_error' => $proof['reason_code'] ?? 'SCOPED_DESCENDANT_FUTURE_SERVER_ROSTER_REQUIRED']);
                        continue;
                    }
                }
                if (in_array($item->work_type, DescendantScopedExecutionService::WORK_TYPES, true)) {
                    $proof = app(DescendantScopedExecutionService::class)->inspectWork($item);
                    if (($proof['executable'] ?? false) !== true) {
                        $item->update(['status' => 'blocked', 'last_error' => $proof['reason_code'] ?? 'DESCENDANT_ORIGINAL_OWNER_REQUIRED']);
                        continue;
                    }
                    $leaseSeconds = DescendantScopedExecutionService::WORK_LEASE_SECONDS;
                }
                if (str_starts_with((string) $item->work_type, 'specialist_council_')) {
                    // Ready/executable payload flags are only projections. A
                    // caller cannot extend a lease or claim a stale original
                    // council proof by setting them. Re-attest under this row
                    // lock before starting the bounded initial lease clock.
                    $proof = app(SpecialistCouncilResearchFeedbackService::class)->inspectFollowupReadiness($item);
                    if (($proof['executable'] ?? false) !== true) {
                        $item->update(['status' => 'blocked', 'last_error' => (string) ($proof['reason'] ?? 'COUNCIL_PREREQUISITE_PROOF_REQUIRED')]);
                        continue;
                    }
                    if (in_array((string) $item->work_type, SpecialistCouncilFollowupExecutionService::DISCOVERY_TYPES, true)
                        && data_get($item->payload, 'owner') === ResearchLoopArbiterService::class
                        && data_get($item->payload, 'executor') === ResearchExperimentWorkConsumerService::class
                        && ($proof['protocol'] ?? null) === SpecialistCouncilResearchFeedbackService::FOLLOWUP_PROTOCOL
                        && ($proof['authority'] ?? null) === 'research_only'
                        && ($proof['work_item_id'] ?? null) === $item->id
                        && ($proof['work_key'] ?? null) === $item->work_key
                        && ($proof['source_receipt_id'] ?? null) === $item->research_experiment_receipt_id
                        && is_string($proof['resolution_hash'] ?? null)
                        && preg_match('/^[a-f0-9]{64}$/D', $proof['resolution_hash'])
                        && hash_equals($proof['resolution_hash'], (string) data_get($item->payload, 'followup_resolution.resolution_hash', ''))
                        && ($proof['max_experiments'] ?? null) === 1
                        && ($proof['promotion_evidence'] ?? null) === false) {
                        $leaseSeconds = SpecialistCouncilFollowupExecutionService::WORK_LEASE_SECONDS;
                    }
                }
                $this->selectPreparedResearchPolicy($item);
                $now = now();
                $lease = ['status' => 'leased', 'attempts' => (int) $item->attempts + 1, 'lease_token' => (string) Str::uuid(),
                    'fence_version' => (int) $item->fence_version + 1, 'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds), 'heartbeat_at' => $now];
                $item->update($lease); $item->forceFill($lease);
                $claimed[] = $item;
            }
            return $claimed;
        });
    }

    /** Original prepared questions only; this never changes ready-work priority or admission. */
    private function selectPreparedResearchPolicy(ResearchExperimentWorkItem $item): void
    {
        if (data_get($item->payload, 'owner') !== ResearchLoopArbiterService::class
            || data_get($item->payload, 'executor') !== ResearchExperimentWorkConsumerService::class
            || ! in_array($item->work_type, SpecialistCouncilIndependentPanelService::TYPES, true)
            || ! is_array(data_get($item->result, 'panel_preparation'))
            || data_get($item->result, 'research_policy_selection') !== null) return;
        try {
            $projection = app(SpecialistCouncilPanelReservationService::class)->pendingNativePanelQuestionCases($item);
            $cases = $projection['cases'] ?? null;
            if (! is_array($cases) || ! array_is_list($cases) || count($cases) < 2 || count($cases) > 16) return;
            $key = (string) config('services.internal_api.token');
            if (strlen($key) < 32) return;
            $refs = array_map(fn (array $case): array => ['version_id' => $case['version_id'], 'window_key' => $case['window_key']], $cases);
            $specs = array_map(fn (array $case): array => array_diff_key($case, ['arm_keys' => true]), $cases);
            $epochs = app(ResearchPaperEpochContractService::class);
            $owner = app(SpecialistCouncilLifecycleService::class);
            $sources = SpecialistCouncilVersion::query()->whereIn('state', ['evaluated', 'approved', 'scheduled', 'active', 'retired', 'rolled_back'])
                ->whereNotNull('assessment->support_role_qualifications')
                ->orderByDesc('id')->limit(self::MAX_POLICY_SOURCES)->get();
            foreach ($sources as $source) {
                foreach ((array) ($source->manifest['components'] ?? []) as $component) {
                    $id = $component['id'] ?? null;
                    $proof = is_string($id) ? (($source->assessment['support_role_qualifications'] ?? [])[$id] ?? []) : [];
                    // The stored projection only narrows the bounded lookup.
                    // The rank API independently re-attests the original exam,
                    // native benchmark, component, scope and fresh questions.
                    if (! is_string($id) || ! in_array($component['role'] ?? null, ['learning', 'evolution'], true)
                        || ($proof['status'] ?? null) !== 'research_role_qualified') continue;
                    try {
                        $seed = $epochs->parameterHash([$item->work_key, $projection['plan_hash'], $source->id, $id]);
                        $rank = $owner->rankQualifiedResearchQuestions($source, $id, $refs, $seed);
                        $binding = $rank['original_qualification_binding'] ?? [];
                        $benchmark = $rank['native_benchmark_reference'] ?? [];
                        if (($rank['status'] ?? null) !== 'research_ranking' || ($rank['actual_policy_consumed'] ?? null) !== true
                            || ($rank['research_only'] ?? null) !== true || ($rank['promotion_evidence'] ?? null) !== false
                            || ($rank['paper_authority_granted'] ?? null) !== false
                            || ($binding['source_version_id'] ?? null) !== (int) $source->id || ($binding['component_id'] ?? null) !== $id
                            || ! in_array($binding['role'] ?? null, ['learning', 'evolution'], true)
                            || ($binding['authority'] ?? null) !== 'scoped_research_component_only'
                            || ! $this->policySha($rank['source_plan_hash'] ?? null)
                            || ! $this->policySha($benchmark['challenge_hash'] ?? null) || ! $this->policySha($benchmark['policy_hash'] ?? null)
                            || ($benchmark['policy_key'] ?? null) !== $id
                            || $epochs->parameterHash($rank['original_question_cases'] ?? []) !== $epochs->parameterHash($specs)) continue;
                        $ranking = $rank['ranking'] ?? [];
                        $questions = array_column($cases, 'question_hash');
                        $ranked = is_array($ranking) ? array_column($ranking, 'question_id') : [];
                        $expected = $questions; $actual = $ranked; sort($expected); sort($actual);
                        if (count($ranked) !== count($cases) || count(array_unique($ranked)) !== count($ranked) || $expected !== $actual) continue;
                        $body = ['protocol' => self::POLICY_SELECTION_PROTOCOL, 'work_item_id' => (int) $item->id,
                            'work_key' => $item->work_key, 'reservation_hash' => $projection['reservation_hash'],
                            'panel_version_id' => $projection['panel_version_id'], 'plan_hash' => $projection['plan_hash'],
                            'current_source_hash' => $projection['current_source_hash'], 'policy_source_version_id' => (int) $source->id,
                            'component_id' => $id, 'original_qualification_binding' => $binding,
                            'source_plan_hash' => $rank['source_plan_hash'], 'native_benchmark_reference' => $benchmark,
                            'case_snapshot' => $cases, 'rank_receipt' => $rank, 'ranking' => $ranking,
                            'selected_question_hash' => $ranked[0], 'selected_at' => now()->utc()->toIso8601String(),
                            'authority' => 'scoped_research_component_only', 'paper_authority_granted' => false, 'promotion_evidence' => false];
                        // Seal the JSON cast's exact persisted representation.
                        $body = json_decode(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true, flags: JSON_THROW_ON_ERROR);
                        $body['rank_receipt_hash'] = $epochs->parameterHash($body['rank_receipt']);
                        $body['selection_hash'] = $epochs->parameterHash($body);
                        $body['server_seal'] = hash_hmac('sha256', self::POLICY_SELECTION_PROTOCOL."\n".$body['selection_hash'], $key);
                        $item->update(['result' => [...(array) $item->result, 'research_policy_selection' => $body]]);
                        return;
                    } catch (Throwable) { /* Invalid support never blocks ordinary ready work. */ }
                }
            }
        } catch (Throwable) { /* Missing prepared cases retain the normal owner selection. */ }
    }

    /** Retry/consumer check of one frozen selection; it never calls the ranker again. */
    public function verifiedNativePolicySelection(ResearchExperimentWorkItem $item, array $projection): ?array
    {
        $body = data_get($item->result, 'research_policy_selection');
        if ($body === null) return null;
        $epochs = app(ResearchPaperEpochContractService::class);
        $key = (string) config('services.internal_api.token');
        if (! is_array($body) || strlen($key) < 32 || ($body['protocol'] ?? null) !== self::POLICY_SELECTION_PROTOCOL
            || ($body['work_item_id'] ?? null) !== (int) $item->id || ($body['work_key'] ?? null) !== $item->work_key
            || ($body['authority'] ?? null) !== 'scoped_research_component_only'
            || ($body['paper_authority_granted'] ?? null) !== false || ($body['promotion_evidence'] ?? null) !== false
            || ! $this->policySha($body['selection_hash'] ?? null) || ! is_string($body['server_seal'] ?? null)
            || $epochs->parameterHash(array_diff_key($body, ['selection_hash' => true, 'server_seal' => true])) !== $body['selection_hash']
            || ! hash_equals(hash_hmac('sha256', self::POLICY_SELECTION_PROTOCOL."\n".$body['selection_hash'], $key), $body['server_seal'])
            || ($body['rank_receipt_hash'] ?? null) !== $epochs->parameterHash($body['rank_receipt'] ?? [])) {
            throw new LogicException('ORIGINAL_NATIVE_POLICY_SELECTION_SEAL_INVALID');
        }
        foreach (['reservation_hash', 'panel_version_id', 'plan_hash', 'current_source_hash'] as $field) {
            if (($body[$field] ?? null) !== ($projection[$field] ?? null)) throw new LogicException('ORIGINAL_NATIVE_POLICY_SELECTION_OWNER_DRIFT');
        }
        // Numeric spelling may differ before and after a model JSON cast.
        $cases = json_decode(json_encode($projection['cases'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true, flags: JSON_THROW_ON_ERROR);
        if ($epochs->parameterHash($body['case_snapshot'] ?? []) !== $epochs->parameterHash($cases)
            || ($body['ranking'] ?? null) !== ($body['rank_receipt']['ranking'] ?? null)
            || ($body['selected_question_hash'] ?? null) !== data_get($body, 'ranking.0.question_id')) {
            throw new LogicException('ORIGINAL_NATIVE_POLICY_SELECTION_CASE_DRIFT');
        }
        $source = SpecialistCouncilVersion::find($body['policy_source_version_id'] ?? 0);
        if (! $source || ! is_string($body['component_id'] ?? null)) {
            throw new LogicException('ORIGINAL_NATIVE_POLICY_QUALIFICATION_REQUIRED');
        }
        $binding = app(SpecialistCouncilLifecycleService::class)->researchSupportBinding($source, $body['component_id']);
        $plan = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $source->id)->first();
        $component = collect($source->manifest['components'] ?? [])->firstWhere('id', $body['component_id']);
        $benchmark = $component ? app(SpecialistCouncilContractService::class)->supportNativePolicyBenchmarkReference(
            $component, (string) data_get($body, 'native_benchmark_reference.challenge_key', '')) : null;
        if ($epochs->parameterHash($binding) !== $epochs->parameterHash($body['original_qualification_binding'] ?? [])
            || ($plan->plan_hash ?? null) !== ($body['source_plan_hash'] ?? null)
            || $epochs->parameterHash($benchmark) !== $epochs->parameterHash($body['native_benchmark_reference'] ?? [])) {
            throw new LogicException('ORIGINAL_NATIVE_POLICY_QUALIFICATION_DRIFT');
        }
        return $body;
    }

    private function policySha(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    /** Fenced completion: a superseded worker is a no-op. */
    public function complete(ResearchExperimentWorkItem $item, array $result): bool
    {
        return ResearchExperimentWorkItem::query()->whereKey($item->id)->where('status', 'leased')
            ->where('lease_token', $item->lease_token)->where('fence_version', (int) $item->fence_version)
            ->where('lease_expires_at', '>', now())
            ->update(['status' => 'settled', 'result' => ['protocol' => self::PROTOCOL, ...$result, 'promotion_evidence' => false],
                'completed_at' => now(), 'lease_token' => null, 'lease_expires_at' => null, 'heartbeat_at' => null]) === 1;
    }

    /** @return array<string,mixed> */
    private function normalizeNextWork(array $nextWork): array
    {
        $type = (string) ($nextWork['type'] ?? '');
        $profiles = [
            'cartridge_confirmation' => [true, 'CANONICAL_CARTRIDGE_AND_BASELINE_READY', 3],
            DescendantScopedExecutionService::WORK_TYPE => [false, 'DESCENDANT_ACTUAL_AUTHORIZED_ORIGINAL_WINDOW_REQUIRED', 1],
            DescendantScopedExecutionService::COMPONENT_WORK_TYPE => [false, 'SCOPED_COMPONENT_ACTUAL_AUTHORIZED_ORIGINAL_WINDOW_REQUIRED', 1],
            ScopedDescendantCandidatePreparationService::WORK_TYPE => [false, 'SCOPED_DESCENDANT_FUTURE_SERVER_ROSTER_REQUIRED', 1],
            // Replaying the same deterministic archive is not independent
            // replication. This becomes executable only after a distinct,
            // preregistered window contract is attached by a later compiler.
            'academy_independent_replication' => [false, 'NEW_INDEPENDENT_WINDOW_CONTRACT_REQUIRED', 2],
            'academy_harmful_intervention_repair' => [false, 'VERSIONED_REPAIR_COMPILER_REQUIRED', 1],
            'academy_upstream_repair' => [false, 'UPSTREAM_CURRICULUM_EVIDENCE_REQUIRED', 1],
            'academy_power_extension' => [false, 'NEW_INDEPENDENT_POWERED_WINDOW_REQUIRED', 1],
            'academy_technical_quarantine' => [false, 'TECHNICAL_ROOT_CAUSE_REPAIR_REQUIRED', 1],
            'academy_adversarial_ablation' => [false, 'VERSIONED_ABLATION_CONTRACT_REQUIRED', 1],
            'activation_independent_validation' => [false, 'AUTHORIZED_RESEARCH_WINDOW_REQUIRED', 1],
            'activation_new_opportunity_window' => [false, 'AUTHORIZED_RESEARCH_WINDOW_REQUIRED', 1],
            'instrument_exact_delta_transfer' => [false, 'CANONICAL_TRANSFER_ADMISSION_AND_AUTHORIZED_UNUSED_WINDOW_REQUIRED', 1],
        ];
        [$executable, $retryCode, $maxExperiments] = $profiles[$type] ?? [true, 'OWNER_RETRY_ADMISSION', 1];
        $activationRequiresWindow = in_array($type, [
            'activation_independent_validation', 'activation_new_opportunity_window',
            'instrument_exact_delta_transfer',
        ], true);
        $retryCondition = (array) ($nextWork['retry_condition'] ?? [
            'code' => $retryCode, 'max_experiments' => $maxExperiments, 'same_evidence_replay_forbidden' => true,
        ]);
        if ($type === 'instrument_exact_delta_transfer') {
            // A proposal is not canonical admission, even when a caller supplies
            // a runnable flag or a larger retry budget.
            $retryCondition = ['code' => $retryCode, 'max_experiments' => 1, 'same_evidence_replay_forbidden' => true];
        }

        return [
            ...$nextWork,
            'owner' => (string) ($nextWork['owner'] ?? ResearchLoopArbiterService::class),
            'executor' => (string) ($nextWork['executor'] ?? ResearchExperimentWorkConsumerService::class),
            'executable' => $activationRequiresWindow || str_starts_with($type, 'specialist_council_')
                ? false : (bool) ($nextWork['executable'] ?? $executable),
            'retry_condition' => $retryCondition,
        ];
    }

    private function normalizePersistedWork(ResearchExperimentWorkItem $item): void
    {
        DB::transaction(function () use ($item): void {
            $current = ResearchExperimentWorkItem::whereKey($item->id)->lockForUpdate()->first();
            if (! $current) return;
            if (in_array($current->status, ['ready', 'blocked'], true)) {
                $payload = $this->normalizeNextWork([
                    ...((array) $current->payload),
                    'type' => (string) ($current->work_type ?: data_get($current->payload, 'type', '')),
                ]);
                if (str_starts_with((string) $current->work_type, 'specialist_council_')) {
                    // Derive readiness from the registrar's original current
                    // proof below, never from the caller or a stale snapshot.
                    $payload['executable'] = (bool) data_get($current->payload, 'executable', false);
                }
                if ((array) $current->payload !== $payload) $current->update(['payload' => $payload]);
            }
            // Record's caller must also see the current leased/settled row.
            $item->setRawAttributes($current->getAttributes(), true);
        });
    }

    private function dependencyReady(ResearchExperimentWorkItem $item, array $payload, ?array $lockedCouncilProof = null): bool
    {
        if ($item->work_type === ScopedDescendantCandidatePreparationService::WORK_TYPE) {
            return (app(ScopedDescendantCandidatePreparationService::class)->inspectWork($item)['executable'] ?? false) === true;
        }
        if (in_array($item->work_type, DescendantScopedExecutionService::WORK_TYPES, true)) {
            return (app(DescendantScopedExecutionService::class)->inspectWork($item)['executable'] ?? false) === true;
        }
        if (str_starts_with((string) $item->work_type, 'specialist_council_')) {
            // Reconciliation just proved this same locked original work. Only
            // operational executable/retry projections changed afterwards.
            // This local value cannot survive the transaction or replace the
            // next claim, executor, constructor or admission proof.
            $proof = $lockedCouncilProof ?? app(SpecialistCouncilResearchFeedbackService::class)->inspectFollowupReadiness($item);
            return ($proof['executable'] ?? false) === true;
        }
        if (! (bool) ($payload['executable'] ?? false)) return false;
        if ((string) $item->work_type !== 'cartridge_confirmation') return true;
        $cartridgeId = (int) ($payload['cartridge_id'] ?? 0);
        if ($cartridgeId <= 0) return false;
        $cartridge = LabSkillZooEntry::query()->find($cartridgeId);

        return $cartridge !== null
            && in_array((string) $cartridge->status, ['provisional', 'confirmed'], true)
            && (int) $cartridge->causal_baseline_agent_id > 0;
    }

    private function validate(array $contract, string $classification): array
    {
        if (($contract['contract_version'] ?? null) !== self::CONTRACT_VERSION) return ['valid' => false, 'reason' => 'RESEARCH_CONTRACT_VERSION_REQUIRED'];
        if (! in_array($classification, self::CLASSIFICATIONS, true)) return ['valid' => false, 'reason' => 'UNKNOWN_EXPERIMENT_CLASSIFICATION'];
        $scope = (array) ($contract['scope'] ?? []); $identity = (array) ($contract['identity'] ?? []);
        foreach (['symbol', 'laboratory_timeframe', 'execution_timeframe'] as $field) if (! filled($scope[$field] ?? null)) return ['valid' => false, 'reason' => 'RESEARCH_SCOPE_'.$field.'_REQUIRED'];
        foreach (['baseline_epoch_hash', 'data_and_mtf_hash', 'runtime_and_contract_hash', 'intervention_hash', 'window_plan_hash', 'evaluator_version'] as $field) if (! filled($identity[$field] ?? null)) return ['valid' => false, 'reason' => 'RESEARCH_IDENTITY_'.$field.'_REQUIRED'];
        if (! filled(data_get($contract, 'source.type'))) return ['valid' => false, 'reason' => 'RESEARCH_SOURCE_REQUIRED'];
        $arms = (array) ($contract['arms'] ?? []);
        if ($arms === [] || collect($arms)->contains(fn ($arm): bool => ! is_array($arm) || ! filled($arm['role'] ?? null))) return ['valid' => false, 'reason' => 'EXPLICIT_EXPERIMENT_ARM_ROLES_REQUIRED'];
        return ['valid' => true, 'contract' => $this->canonicalize($contract)];
    }

    private function available(): bool { return Schema::hasTable('research_experiment_receipts') && Schema::hasTable('research_experiment_work_items'); }
    private function blocked(string $reason): array { return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => $reason, 'promotion_evidence' => false]; }
    private function hash(array $value): string { return hash('sha256', json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)); }
    private function canonicalize(array $value): array { if (! array_is_list($value)) ksort($value); foreach ($value as $key => $item) if (is_array($item)) $value[$key] = $this->canonicalize($item); return $value; }
}
