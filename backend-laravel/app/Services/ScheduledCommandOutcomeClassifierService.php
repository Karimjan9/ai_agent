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
            // The generation builder deliberately exits zero for a typed
            // admission refusal. Delivery succeeded, but no successor was
            // created; recording the arbiter writer as completed would make
            // a blocked constructor look like lifecycle progress.
            if ($command === 'trading:lab-generation'
                && str_contains(strtolower($output), 'generation blocked (')) {
                return $this->result('safety_blocked', false, $exitCode, 'generation_admission_withheld');
            }
            // Lifecycle commands are deliberately fail-closed and report a
            // typed JSON pause with exit 0. Process success is not the same
            // as achieving the arbiter's requested state transition. Calling
            // this `completed` permanently deduplicates the unchanged state
            // and can strand an autonomous successor behind a no-op command.
            if ($command === 'trading:run-lifecycle-cycle') {
                $payload = json_decode(trim($output), true);
                $status = is_array($payload) ? strtolower((string) ($payload['status'] ?? '')) : '';
                // A recovery dispatch is reported as `running` with exit 0,
                // but it has not performed the arbiter's requested successor
                // transition. Treat it like a pause so a later tick can
                // reselect after the recovered agent reaches a terminal state.
                if (in_array($status, ['running', 'paused', 'blocked', 'deferred'], true)) {
                    return $this->result(
                        $status === 'blocked' ? 'safety_blocked' : 'deferred',
                        false,
                        $exitCode,
                        'lifecycle_transition_not_achieved',
                    );
                }
            }

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

        // Scheduled external feeds are optional inputs with independent
        // freshness/quarantine gates. A transient DNS/TLS/timeout outage must
        // keep that input unavailable, but it is not a broken queue job and
        // must not poison the generation runtime circuit breaker. Normal
        // cadence retries it; auth, schema and application errors still fail.
        if ($this->isTransientExternalDependencyFailure($command, $normalized)) {
            return $this->result('deferred_external_dependency', false, $exitCode, 'transient_external_dependency');
        }

        return $this->result('technical_failure', true, $exitCode, 'nonzero_exit');
    }

    private function isTransientExternalDependencyFailure(string $command, string $normalizedOutput): bool
    {
        if (! in_array($command, [
            'market-data:update',
            'market-data:sync-volume',
            'market-intelligence:sync-cot',
            'trading:sync-economic-calendar',
            'trading:sync-official-us-calendar',
        ], true)) {
            return false;
        }

        foreach ([
            'curl error 6:',
            'curl error 7:',
            'curl error 28:',
            'curl error 35:',
            'curl error 52:',
            'curl error 56:',
            'could not resolve host',
            'connection timed out',
            'operation timed out',
            'ssl_error_syscall',
            'temporarily unavailable',
            'service unavailable',
            'bad gateway',
            'gateway timeout',
        ] as $signature) {
            if (str_contains($normalizedOutput, $signature)) {
                return true;
            }
        }

        return false;
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
