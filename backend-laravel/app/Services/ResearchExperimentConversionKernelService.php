<?php

namespace App\Services;

use App\Models\AgentLearningSettlement;
use App\Models\CanonicalLearningOutbox;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\ResearchExperimentReceipt;
use App\Models\ResearchExperimentWorkItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The conversion kernel owns only experiment identity, terminal receipts and
 * durable next work. It does not execute a replay or grant trading authority.
 */
class ResearchExperimentConversionKernelService
{
    public const PROTOCOL = 'research_experiment_conversion_kernel_v1';
    public const CONTRACT_VERSION = 'research_experiment_v1';
    public const RULE_VERSION = 'research_conversion_rules_v1';
    private const LEASE_SECONDS = 900;
    private const CLASSIFICATIONS = ['POSITIVE_CANDIDATE', 'INCONCLUSIVE', 'UNDERPOWERED', 'UNREACHABLE', 'TECHNICAL_QUARANTINE', 'BUDGET_EXHAUSTED', 'HARMFUL'];

    /** @return array<string,mixed> */
    public function record(array $contract, array $evidence, string $classification, array $nextWork = [], array $terminalReason = []): array
    {
        if (! $this->available()) return $this->blocked('RESEARCH_CONVERSION_TABLES_UNAVAILABLE');
        $validation = $this->validate($contract, $classification);
        if (! $validation['valid']) return $this->blocked($validation['reason']);
        $contract = $validation['contract'];
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
            $work = null;
            if ($nextWork !== []) {
                $type = (string) ($nextWork['type'] ?? '');
                if ($type === '') throw new \InvalidArgumentException('NEXT_WORK_TYPE_REQUIRED');
                $work = ResearchExperimentWorkItem::query()->firstOrCreate(
                    ['work_key' => hash('sha256', self::PROTOCOL.'|'.$receipt->receipt_key.'|'.$type.'|'.($nextWork['identity'] ?? 'default'))],
                    ['research_experiment_receipt_id' => $receipt->id, 'symbol' => $receipt->symbol,
                        'timeframe' => $receipt->laboratory_timeframe, 'work_type' => $type,
                        'status' => isset($nextWork['dependency_key']) ? 'blocked' : 'ready',
                        'priority' => max(1, min(9, (int) ($nextWork['priority'] ?? 5))),
                        'dependency_key' => $nextWork['dependency_key'] ?? null,
                        'payload' => ['protocol' => self::PROTOCOL, ...$nextWork, 'promotion_evidence' => false],
                    ],
                );
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
        return DB::transaction(function () use ($limit): array {
            ResearchExperimentWorkItem::query()->where('status', 'leased')->where('lease_expires_at', '<=', now())
                ->update(['status' => 'ready', 'lease_token' => null, 'lease_expires_at' => null, 'heartbeat_at' => null,
                    'last_error' => 'LEASE_EXPIRED', 'updated_at' => now()]);
            $items = ResearchExperimentWorkItem::query()->where('status', 'ready')->orderByDesc('priority')->orderBy('id')
                ->lockForUpdate()->limit(max(1, min(50, $limit)))->get();
            foreach ($items as $item) {
                $now = now();
                $lease = ['status' => 'leased', 'attempts' => (int) $item->attempts + 1, 'lease_token' => (string) Str::uuid(),
                    'fence_version' => (int) $item->fence_version + 1, 'lease_expires_at' => $now->copy()->addSeconds(self::LEASE_SECONDS), 'heartbeat_at' => $now];
                $item->update($lease); $item->forceFill($lease);
            }
            return $items->all();
        });
    }

    /** Fenced completion: a superseded worker is a no-op. */
    public function complete(ResearchExperimentWorkItem $item, array $result): bool
    {
        return ResearchExperimentWorkItem::query()->whereKey($item->id)->where('status', 'leased')
            ->where('lease_token', $item->lease_token)->where('fence_version', (int) $item->fence_version)
            ->update(['status' => 'settled', 'result' => ['protocol' => self::PROTOCOL, ...$result, 'promotion_evidence' => false],
                'completed_at' => now(), 'lease_token' => null, 'lease_expires_at' => null, 'heartbeat_at' => null]) === 1;
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
