<?php

namespace App\Services;

use App\Models\LabGeneration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only provenance inventory; generation ID is never confused with its label. */
class SourceGenerationAuditService
{
    public const PROTOCOL = 'source_generation_provenance_audit_v1';

    /** @return array<string,mixed> */
    public function audit(int $sourceGenerationId): array
    {
        $generation = LabGeneration::query()->with(['laboratory', 'agents.modelVersion'])->find($sourceGenerationId);
        if (! $generation) {
            return ['protocol' => self::PROTOCOL, 'status' => 'missing_generation', 'source_generation_id' => $sourceGenerationId, 'promotion_evidence' => false];
        }
        $edgePassports = Schema::hasTable('edge_genesis_passports') ? DB::table('edge_genesis_passports')->where('lab_generation_id', $generation->id)->get() : collect();
        $edgeTrials = $edgePassports->isNotEmpty() && Schema::hasTable('edge_genesis_trials')
            ? DB::table('edge_genesis_trials')->whereIn('edge_genesis_passport_id', $edgePassports->pluck('id'))->get()
            : collect();
        $edgeTrialsByAgent = $edgeTrials->filter(fn ($trial) => $trial->lab_agent_id !== null)->keyBy('lab_agent_id');
        $arms = $generation->agents->map(function ($agent) use ($edgeTrialsByAgent): array {
            $metadata = (array) $agent->modelVersion?->metadata;
            $academy = (array) data_get($metadata, 'academy_experiment', []);
            $edge = (array) data_get($metadata, 'edge_genesis', []);
            $attestation = (array) data_get($edge, 'intervention_attestation', []);
            $edgeTrial = $edgeTrialsByAgent->get($agent->id);
            $armRole = $academy['arm_role'] ?? $edge['arm_role'] ?? $edge['role'] ?? $edgeTrial?->arm;
            $metrics = $agent->modelVersion?->marketPerformances()->where('symbol', $agent->symbol)->where('timeframe', $agent->timeframe)->latest('id')->value('metrics');
            if (is_string($metrics)) {
                $metrics = json_decode($metrics, true);
            }

            return ['agent_id' => $agent->id, 'model_version_id' => $agent->model_version_id, 'lifecycle_status' => $agent->lifecycle_status,
                'arm_role' => $armRole, 'control_identity' => (bool) ($academy['control_identity'] ?? in_array($armRole, ['frozen_control', 'blinded_control', 'compiled_control'], true)),
                'parameter_diff' => $agent->parameter_diff, 'replay_evidence_present' => is_array($metrics), 'replay_metrics_hash' => is_array($metrics) ? hash('sha256', json_encode($metrics, JSON_UNESCAPED_SLASHES)) : null,
                'terminal' => in_array((string) $agent->lifecycle_status, ['completed', 'rejected', 'failed', 'technical_quarantine', 'quarantined', 'archived', 'paper', 'forward_validated'], true),
                'academy_trial_id' => $academy['academy_trial_id'] ?? null, 'edge_packet_key' => $edge['packet_key'] ?? $edgeTrial?->packet_key,
                'edge_trial_id' => $edgeTrial?->id, 'edge_trial_status' => $edgeTrial?->status, 'edge_trial_settled_at' => $edgeTrial?->settled_at,
                'edge_intervention_attested' => data_get($attestation, 'protocol') === 'edge_genesis_intervention_attestation_v1'
                    && filled(data_get($attestation, 'consumed_parameter_hash')),
                'consumed_parameter_hash' => data_get($attestation, 'consumed_parameter_hash')];
        })->values();
        $watermarks = Schema::hasTable('settlement_watermarks') ? DB::table('settlement_watermarks')->where('lab_generation_id', $generation->id)->get()->map(fn ($row) => (array) $row)->all() : [];
        $edgeReceipts = $edgePassports->isNotEmpty() && Schema::hasTable('research_experiment_receipts')
            ? DB::table('research_experiment_receipts')->where('source_type', 'edge_genesis_passport')
                ->whereIn('source_id', $edgePassports->pluck('id'))->get()->map(fn ($row) => [
                    'receipt_id' => $row->id, 'source_passport_id' => (int) $row->source_id, 'receipt_key' => $row->receipt_key,
                    'classification' => $row->classification, 'contract_hash' => $row->contract_hash, 'evidence_hash' => $row->evidence_hash,
                    'terminal_reason' => json_decode((string) $row->terminal_reason, true), 'created_at' => $row->created_at,
                ])->all()
            : [];
        $edgeReceiptPassportIds = collect($edgeReceipts)->pluck('source_passport_id')->map(fn ($id) => (int) $id)->unique();
        $edgeReceiptComplete = $edgePassports->isNotEmpty()
            && $edgePassports->every(fn ($passport): bool => $edgeReceiptPassportIds->contains((int) $passport->id));
        $academyTrialIds = $arms->pluck('academy_trial_id')->filter()->unique()->values();
        $academy = Schema::hasTable('edge_academy_trials') && $academyTrialIds->isNotEmpty() ? DB::table('edge_academy_trials')->whereIn('id', $academyTrialIds)->get()->map(fn ($row) => [
            'trial_id' => $row->id, 'status' => $row->status, 'settled_at' => $row->settled_at, 'outcome' => json_decode((string) $row->outcome, true),
        ])->all() : [];
        // `rejected` is terminal in the existing lifecycle. It is not,
        // by itself, a causal control or a valid settlement record.
        $terminal = $arms->isNotEmpty() && $arms->every(fn (array $arm): bool => (bool) $arm['terminal']);
        // `edge_progressing` closes one replay stage but explicitly requires
        // another stage; reporting it as terminal can advertise a receipt
        // before the frozen cohort selector has made its final decision.
        $edgeTerminalStatuses = ['invalid_edge_observability', 'invalid_hash_mismatch', 'invalid_window_partition', 'control_settled', 'replication_control_settled', 'replication_observed', 'authority_observed', 'edge_replication_passed', 'edge_not_found', 'edge_not_confirmed', 'non_controlling_axis', 'technical_quarantine', 'quarantined'];
        $edgeTrialsTerminal = $edgeTrials->isEmpty() ? null : $edgeTrials->every(fn ($trial): bool => $trial->settled_at !== null && in_array((string) $trial->status, $edgeTerminalStatuses, true));
        $provenanceBlockers = [];
        if ($arms->contains(fn (array $arm): bool => blank($arm['arm_role']))) {
            $provenanceBlockers[] = 'EXPLICIT_ARM_ROLE_MISSING';
        }
        if ($arms->where('control_identity', true)->isEmpty()) {
            $provenanceBlockers[] = 'CONTROL_IDENTITY_MISSING';
        }
        if ($arms->contains(fn (array $arm): bool => ! $arm['replay_evidence_present'])) {
            $provenanceBlockers[] = 'REPLAY_EVIDENCE_MISSING';
        }
        if ($edgeTrialsTerminal === false) {
            $provenanceBlockers[] = 'EDGE_TRIAL_SETTLEMENT_INCOMPLETE';
        }
        $edgeAttestationMissing = $edgePassports->isNotEmpty() && $arms->contains(fn (array $arm): bool => ! ($arm['edge_intervention_attested'] ?? false));
        if ($edgeAttestationMissing) {
            $provenanceBlockers[] = 'EDGE_INTERVENTION_ATTESTATION_MISSING';
        }
        // Edge Genesis has no AgentLearningEpisode by design, so an episode
        // watermark cannot be manufactured for it. Its immutable terminal
        // conversion receipt is the equivalent provenance close.
        if ($terminal && $edgePassports->isNotEmpty() && ! $edgeReceiptComplete) {
            $provenanceBlockers[] = 'EDGE_TERMINAL_RECEIPT_MISSING';
        }
        if ($terminal && $watermarks === [] && ! $edgeReceiptComplete) {
            $provenanceBlockers[] = 'SETTLEMENT_WATERMARK_MISSING';
        }
        $nextAction = $edgeTrialsTerminal === false ? 'reconcile_or_quarantine_unsettled_edge_trials' : (! $terminal ? 'settle_or_recover_nonterminal_arm' : ($edgePassports->isNotEmpty() && ! $edgeReceiptComplete ? ($edgeAttestationMissing ? 'quarantine_legacy_source_and_plan_versioned_recovery' : 'record_terminal_edge_receipt') : ($provenanceBlockers !== [] ? 'quarantine_legacy_source_and_plan_versioned_recovery' : 'inspect_receipts_and_durable_work')));

        return ['protocol' => self::PROTOCOL, 'status' => 'audited', 'source_generation_id' => $generation->id, 'generation_label' => $generation->generation,
            'laboratory_id' => $generation->ai_laboratory_id, 'symbol' => $generation->laboratory?->symbol, 'timeframe' => $generation->laboratory?->timeframe,
            'generation_status' => $generation->status, 'arms' => $arms->all(), 'arm_count' => $arms->count(), 'control_arm_count' => $arms->where('control_identity', true)->count(),
            'all_arms_terminal' => $terminal, 'settlement_watermarks' => $watermarks, 'edge_conversion_receipts' => $edgeReceipts, 'academy_projection' => $academy,
            'edge_projection' => ['passports' => $edgePassports->map(fn ($passport) => ['passport_id' => $passport->id, 'phase' => $passport->phase, 'status' => $passport->status, 'data_hash' => $passport->data_hash, 'execution_hash' => $passport->execution_hash])->all(),
                'trials' => $edgeTrials->map(fn ($trial) => ['trial_id' => $trial->id, 'packet_key' => $trial->packet_key, 'arm' => $trial->arm, 'stage' => $trial->stage, 'status' => $trial->status, 'settled_at' => $trial->settled_at])->all(),
                'all_trials_terminal' => $edgeTrialsTerminal],
            'provenance_complete' => $terminal && $provenanceBlockers === [], 'provenance_blockers' => $provenanceBlockers,
            'next_action' => $nextAction, 'promotion_evidence' => false];
    }
}
