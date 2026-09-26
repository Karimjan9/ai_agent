<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use Illuminate\Support\Facades\Schema;

class TechnicalFailureClassifierService
{
    public const TRANSIENT = 'TRANSIENT_RECOVERABLE';

    public const CAPABILITY = 'DATA_CAPABILITY_MISSING';

    public const TERMINAL = 'TERMINAL_DIAGNOSTIC';

    /** @return array<string, mixed> */
    public function forAgent(LabAgent $agent): array
    {
        $agent->loadMissing(['modelVersion', 'generation']);
        $terminalDisposition = (array) data_get(
            $agent->modelVersion?->metadata,
            'technical_recovery_terminal_disposition',
            [],
        );
        if ((string) $agent->lifecycle_status === 'technical_quarantine'
            && (string) ($terminalDisposition['protocol'] ?? '') === 'frozen_recovery_contract_terminal_v1'
            && (string) ($terminalDisposition['reason_code'] ?? '') === 'FROZEN_RECOVERY_CONTRACT_UNAVAILABLE'
            && (string) ($terminalDisposition['strategy_verdict'] ?? '') === 'withheld') {
            return [
                'class' => self::TERMINAL,
                'reason_code' => 'FROZEN_RECOVERY_CONTRACT_UNAVAILABLE',
                'capability' => null,
                'blocks_global_generation' => false,
                'action' => 'TERMINAL_DIAGNOSTIC',
                'reason' => 'The frozen same-generation recovery contract was sealed unavailable; the old timeout remains technical history.',
            ];
        }
        $run = LabEvaluationRun::query()
            ->where('lab_agent_id', $agent->id)
            ->whereIn('status', ['technical_error', 'failed'])
            ->latest('id')
            ->first();
        $preflightErrors = array_values(array_filter(array_map(
            'strval',
            (array) data_get($agent->modelVersion?->metadata, 'preflight_quarantine.errors', []),
        )));
        $message = $run?->error_message
            ?: trim(implode(' ', [implode(' ', $preflightErrors), (string) $agent->decision_reason]));

        // A candidate batch can be quarantined without opening its own run
        // when its same-generation frozen control terminates first. The
        // candidate has no independent replay debt: its disposition follows
        // the immutable control failure. Without this projection the empty
        // candidate run is misclassified as an unclassified transient and a
        // terminal generation blocks every autonomous successor forever.
        if ($run === null && str_contains(strtolower($message), 'frozen_control_replay_incomplete')) {
            $controlId = (int) data_get($agent->modelVersion?->metadata, 'control_pair_contract.control_agent_id', 0);
            if ($controlId <= 0 && Schema::hasTable('agent_learning_causal_experiments')) {
                $experiment = AgentLearningCausalExperiment::query()
                    ->where('lab_generation_id', $agent->lab_generation_id)
                    ->where(function ($query) use ($agent): void {
                        $query->where('guided_agent_id', $agent->id)
                            ->orWhere('blinded_agent_id', $agent->id);
                    })
                    ->latest('id')
                    ->first();
                $controlId = (int) ($experiment?->control_agent_id ?? 0);
            }
            $control = $controlId > 0 && $controlId !== (int) $agent->id
                ? LabAgent::query()->with(['modelVersion', 'generation'])->find($controlId)
                : null;
            if ($control instanceof LabAgent) {
                // The candidate was withheld before it could open an own run.
                // If its frozen control later completed a real screening run,
                // the old candidate quarantine remains failed evidence, but
                // there is no longer an upstream transport failure to repair.
                // In particular, do not classify the screened control's
                // ordinary decision_reason as a new technical error.
                $controlRun = LabEvaluationRun::query()
                    ->where('lab_agent_id', $control->id)
                    ->where('phase', 'screening')
                    ->latest('id')
                    ->first();
                if ((string) $control->lifecycle_status === 'screened'
                    && (string) $controlRun?->status === 'completed') {
                    return [
                        'class' => self::TERMINAL,
                        'reason_code' => 'UPSTREAM_FROZEN_CONTROL_COMPLETED_CANDIDATE_UNREPLAYED',
                        'capability' => null,
                        'blocks_global_generation' => false,
                        'action' => 'TERMINAL_DIAGNOSTIC',
                        'reason' => 'The frozen control later completed; this candidate remains unreplayed diagnostic evidence, not an independent technical-recovery debt.',
                    ];
                }
                $upstream = $this->forAgent($control);
                if (data_get($upstream, 'blocks_global_generation') !== true) {
                    return [
                        'class' => self::TERMINAL,
                        'reason_code' => 'UPSTREAM_FROZEN_CONTROL_TERMINAL',
                        'capability' => data_get($upstream, 'capability'),
                        'blocks_global_generation' => false,
                        'action' => 'TERMINAL_DIAGNOSTIC',
                        'reason' => 'The candidate never started because its immutable frozen control terminated; it has no independent replay recovery debt.',
                    ];
                }

                // This candidate has no evaluator run of its own. It waits
                // for the control's bounded repair, but must never spend a
                // second timeout-recovery seat under the control's error code.
                return [
                    'class' => self::TRANSIENT,
                    'reason_code' => 'FROZEN_CONTROL_UPSTREAM_REPAIR_PENDING',
                    'capability' => data_get($upstream, 'capability'),
                    'blocks_global_generation' => true,
                    'action' => 'WAIT_FOR_FROZEN_CONTROL_REPAIR',
                    'reason' => 'The candidate has no own replay; its frozen control owns the bounded technical repair.',
                ];
            }
        }

        return $this->classify(strtolower((string) $message), $run?->error_class);
    }

    /** @return array<string, mixed> */
    public function classify(string $message, ?string $errorClass = null): array
    {
        $normalized = strtolower($message);
        if (str_contains($normalized, 'autonomous_mtf_bundle_missing')
            || str_contains($normalized, 'mtf_bundle_missing_or_invalid')) {
            return [
                'class' => self::TERMINAL,
                'reason_code' => 'IMMUTABLE_MTF_ADMISSION_CONTRACT_MISSING',
                'capability' => 'multi_timeframe_snapshot',
                'blocks_global_generation' => false,
                'action' => 'TERMINAL_DIAGNOSTIC',
                'reason' => 'The generation was admitted without its frozen M5/H4/H1/M15 bundle; replay cannot repair that immutable construction boundary.',
            ];
        }
        if (str_contains($normalized, 'volume quality gate')
            || str_contains($normalized, 'volume_unavailable')
            || str_contains($normalized, 'volume coverage')) {
            return [
                'class' => self::CAPABILITY,
                'reason_code' => 'VOLUME_CAPABILITY_MISSING',
                'capability' => 'volume',
                'blocks_global_generation' => false,
                'action' => 'QUARANTINE_CAPABILITY_LANE',
                'reason' => 'Canonical volume evidence is unavailable; volume-dependent hypotheses remain isolated while price-only research may continue.',
            ];
        }
        if (str_contains($normalized, 'zero_diff')
            || str_contains($normalized, 'one_gene_invariant_failed')
            || str_contains($normalized, 'strict lab preflight failed')
            || str_contains($normalized, 'constructor abort')
            || str_contains($normalized, 'constructor contract')
            || str_contains($normalized, 'composition_')
            || str_contains($normalized, 'not executable')) {
            return [
                'class' => self::TERMINAL,
                'reason_code' => 'IMMUTABLE_EXPERIMENT_TERMINAL',
                'capability' => null,
                'blocks_global_generation' => false,
                'action' => 'TERMINAL_DIAGNOSTIC',
                'reason' => 'The immutable experiment cannot be repaired by replay.',
            ];
        }

        $reasonCode = match (true) {
            str_contains($normalized, 'sqlstate[22001]'),
            str_contains($normalized, 'string data, right truncated'),
            str_contains($normalized, 'data too long for column') => 'DATABASE_SCHEMA_WIDTH_MISMATCH',
            str_contains($normalized, 'maxattemptsexceededexception'),
            str_contains($normalized, 'attempted too many times'),
            str_contains($normalized, 'bounded screening batch exhausted operational retries') => 'REPLAY_RETRY_BUDGET_EXHAUSTED',
            str_contains($normalized, 'bounded ai replay exceeded'),
            str_contains($normalized, 'curl error 28'),
            str_contains($normalized, 'operation timed out'),
            str_contains($normalized, 'timed out after'),
            str_contains($normalized, 'timeouterror: causal confirmation fold')
                && str_contains($normalized, 'exceeded its')
                && str_contains($normalized, 'budget') => 'REPLAY_TRANSPORT_TIMEOUT',
            str_contains($normalized, 'curl error 56'),
            str_contains($normalized, 'connection was reset'),
            str_contains($normalized, 'recv failure'),
            str_contains($normalized, 'failed to connect'),
            str_contains($normalized, 'connection refused') => 'AI_SERVICE_UNAVAILABLE',
            default => 'UNCLASSIFIED_TRANSIENT',
        };

        return [
            'class' => self::TRANSIENT,
            'reason_code' => $reasonCode,
            'capability' => null,
            'blocks_global_generation' => true,
            'action' => 'RECOVER_TECHNICAL',
            'reason' => trim((string) $errorClass.' '.$message),
        ];
    }
}
