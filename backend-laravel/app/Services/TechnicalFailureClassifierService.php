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
        $message = strtolower((string) ($run?->error_message
            ?: data_get($agent->modelVersion?->metadata, 'preflight_quarantine.errors.0', $agent->decision_reason)));

        return $this->classify($message, $run?->error_class);
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
                'capability' => null,
                'blocks_global_generation' => false,
                'action' => 'TERMINAL_DIAGNOSTIC',
                'reason' => 'The immutable experiment cannot be repaired by replay.',
            ];
        }

        return [
            'class' => self::TRANSIENT,
            'capability' => null,
            'blocks_global_generation' => true,
            'action' => 'RECOVER_TECHNICAL',
            'reason' => trim((string) $errorClass.' '.$message),
        ];
    }
}
