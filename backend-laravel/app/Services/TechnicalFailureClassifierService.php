<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabEvaluationRun;

class TechnicalFailureClassifierService
{
    public const TRANSIENT = 'TRANSIENT_RECOVERABLE';

    public const CAPABILITY = 'DATA_CAPABILITY_MISSING';

    public const TERMINAL = 'TERMINAL_DIAGNOSTIC';

    /** @return array<string, mixed> */
    public function forAgent(LabAgent $agent): array
    {
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

        return $this->classify(strtolower((string) $message), $run?->error_class);
    }

    /** @return array<string, mixed> */
    public function classify(string $message, ?string $errorClass = null): array
    {
        $normalized = strtolower($message);
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
            str_contains($normalized, 'maxattemptsexceededexception'),
            str_contains($normalized, 'attempted too many times'),
            str_contains($normalized, 'bounded screening batch exhausted operational retries') => 'REPLAY_RETRY_BUDGET_EXHAUSTED',
            str_contains($normalized, 'bounded ai replay exceeded'),
            str_contains($normalized, 'curl error 28'),
            str_contains($normalized, 'operation timed out'),
            str_contains($normalized, 'timed out after') => 'REPLAY_TRANSPORT_TIMEOUT',
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
