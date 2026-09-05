<?php

namespace App\Services;

/**
 * Separates an expected fail-closed scheduler decision from an execution
 * failure.  This never changes the command's gate decision or exit code; it
 * only prevents NOOP/DEFERRED/SAFETY_BLOCKED outcomes from poisoning the
 * queue-health circuit breaker as failed jobs.
 */
class ScheduledCommandOutcomeClassifierService
{
    public const PROTOCOL = 'scheduled_command_outcome_v1';

    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    public function classify(string $command, array $arguments, int $exitCode, string $output): array
    {
        if ($exitCode === 0) {
            return $this->result('completed', false, $exitCode);
        }

        $normalized = strtolower(trim($output));

        if ($command === 'trading:run-lifecycle-cycle'
            && (str_contains($normalized, 'status=blocked') || str_contains($normalized, '"status":"blocked"'))) {
            return $this->result('safety_blocked', false, $exitCode, 'lifecycle_gate_withheld');
        }

        if ($command === 'trading:audit-agent-lifecycle'
            && (str_contains($normalized, 'audit: blocked') || str_contains($normalized, '"status":"blocked"'))) {
            return $this->result('safety_blocked', false, $exitCode, 'diagnostic_audit_blocked');
        }

        // The scheduled rescue tick is proposal-only.  Admission refusal is
        // a healthy idempotent NOOP; state-changing --apply failures remain
        // technical so an operator-triggered rescue can never be hidden.
        if ($command === 'trading:dispatch-controlled-targeted-rescue'
            && ! (bool) ($arguments['--apply'] ?? false)
            && $normalized !== '') {
            return $this->result('deferred', false, $exitCode, 'proposal_not_admitted');
        }

        return $this->result('technical_failure', true, $exitCode, 'nonzero_exit');
    }

    /** @return array<string,mixed> */
    private function result(string $status, bool $throw, int $exitCode, ?string $reason = null): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'status' => $status,
            'throw' => $throw,
            'exit_code' => $exitCode,
            'reason' => $reason,
            'promotion_evidence' => false,
        ];
    }
}
