<?php

namespace App\Services;

use App\Models\SystemEvent;
use RuntimeException;

/** Explicit, auditable human approval for state-changing lab commands. */
class OperatorApprovalService
{
    /** @return array{event_id: int, approved_by: string, reason: string} */
    public function requireForApply(string $operation, ?string $approvedBy, ?string $reason, array $scope = []): array
    {
        $approvedBy = trim((string) $approvedBy);
        $reason = trim((string) $reason);
        if ($approvedBy === '' || $reason === '') {
            throw new RuntimeException('OPERATOR_APPROVAL_REQUIRED: --approved-by va --approval-reason majburiy.');
        }

        $payload = [
            'protocol' => 'operator_apply_approval_v1',
            'operation' => $operation,
            'approved_by' => $approvedBy,
            'reason' => $reason,
            'scope' => $scope,
            'promotion_evidence' => false,
            'approved_at' => now()->utc()->toIso8601String(),
        ];
        $fingerprint = hash('sha256', json_encode([
            ...$payload,
            'nonce' => microtime(true),
        ], JSON_UNESCAPED_SLASHES));
        $event = SystemEvent::create([
            'event_type' => 'learning_protocol_operator_approval',
            'event_key' => 'learning_protocol:operator_approval:'.$fingerprint,
            'agent' => $approvedBy,
            'severity' => 'warning',
            'summary' => "Operator approved state-changing lab operation: {$operation}.",
            'payload' => $payload,
            'occurred_at' => now(),
        ]);

        return ['event_id' => (int) $event->id, 'approved_by' => $approvedBy, 'reason' => $reason];
    }
}
