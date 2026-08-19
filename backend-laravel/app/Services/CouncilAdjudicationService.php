<?php

namespace App\Services;

use App\Models\LabCouncilAdjudication;
use App\Models\LabCouncilDisagreement;
use App\Models\LabEvaluationRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Evidence-backed, append-only resolution projection for council disagreement. */
class CouncilAdjudicationService
{
    public const PROTOCOL = 'council_adjudication_v1';
    public const ROLES = ['entry', 'risk', 'regime', 'volume_temporal'];

    /** @return array<string, mixed> */
    public function preview(string $symbol, string $timeframe, int $limit = 20): array
    {
        if (! Schema::hasTable('lab_council_adjudications')) {
            return ['available' => false, 'unresolved' => 0, 'rows' => []];
        }
        $query = LabCouncilDisagreement::query()
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->where('outcome_status', 'unresolved')
            ->latest('id');
        $unresolved = (clone $query)->count();
        $rows = $query->limit(max(1, min(100, $limit)))->get();

        return [
            'available' => true,
            'protocol' => self::PROTOCOL,
            'symbol' => strtoupper($symbol),
            'timeframe' => strtoupper($timeframe),
            'unresolved' => $unresolved,
            'rows' => $rows->map(fn (LabCouncilDisagreement $row): array => [
                'id' => $row->id,
                'event_key' => $row->event_key,
                'family' => $row->family,
                'regime' => $row->regime,
                'risk_decision' => $row->risk_decision,
                'specialist_votes' => $row->specialist_votes,
                'safe_default' => 'WAIT',
                'resolution_requires_evidence' => true,
            ])->values()->all(),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function adjudicate(
        LabCouncilDisagreement $disagreement,
        string $decision,
        string $evidenceRunId,
        string $replayHash,
        array $windowKeys,
        string $approvedBy,
        string $approvalReason,
        array $evidence = [],
    ): array {
        if ($disagreement->outcome_status !== 'unresolved') {
            throw new RuntimeException('COUNCIL_DISAGREEMENT_ALREADY_RESOLVED');
        }
        $decision = strtoupper(trim($decision));
        if (! in_array($decision, ['BUY', 'SELL', 'WAIT'], true)) {
            throw new RuntimeException('COUNCIL_DECISION_INVALID: use BUY, SELL yoki WAIT.');
        }
        $requestedDecision = $decision;
        $evidenceRunId = trim($evidenceRunId);
        $replayHash = trim($replayHash);
        $windowKeys = array_values(array_filter(array_map('strval', $windowKeys)));
        if ($evidenceRunId === '' || $replayHash === '' || $windowKeys === []) {
            throw new RuntimeException('COUNCIL_EVIDENCE_REQUIRED: evidence-run, replay-hash va kamida bitta window kerak.');
        }
        $run = LabEvaluationRun::query()->where('run_id', $evidenceRunId)->first();
        if (! $run) {
            throw new RuntimeException('COUNCIL_EVIDENCE_RUN_NOT_FOUND: immutable LabEvaluationRun topilmadi.');
        }
        if (! in_array((string) $run->status, ['completed', 'passed', 'succeeded'], true)) {
            throw new RuntimeException('COUNCIL_EVIDENCE_RUN_NOT_TERMINAL: replay hali completed emas.');
        }

        $votes = (array) $disagreement->specialist_votes;
        $roleVotes = $this->roleVotes($votes);
        $riskVeto = in_array(strtoupper((string) $disagreement->risk_decision), ['WAIT', 'VETO'], true);
        $quorum = count(array_intersect(array_keys($roleVotes), self::ROLES));
        if ($riskVeto || $quorum < 3 || count(array_unique(array_values($roleVotes))) > 1) {
            // A disputed or risk-vetoed council may only be resolved safely as
            // WAIT. BUY/SELL cannot be manufactured by an operator flag.
            // Operator intent is retained in the append-only evidence, but
            // the persisted outcome is forced to WAIT by the safety contract.
            $decision = 'WAIT';
        }

        $key = hash('sha256', json_encode([
            self::PROTOCOL, $disagreement->event_key, $decision, $evidenceRunId,
            $replayHash, $windowKeys,
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        return DB::transaction(function () use ($disagreement, $decision, $requestedDecision, $evidenceRunId, $replayHash, $windowKeys, $approvedBy, $approvalReason, $evidence, $roleVotes, $key): array {
            $adjudication = LabCouncilAdjudication::query()->firstOrCreate(
                ['adjudication_key' => $key],
                [
                    'disagreement_id' => $disagreement->id,
                    'decision' => $decision,
                    'evidence_run_id' => $evidenceRunId,
                    'replay_hash' => $replayHash,
                    'window_keys' => $windowKeys,
                    'role_votes' => $roleVotes,
                    'evidence' => [
                        ...$evidence,
                        'protocol' => self::PROTOCOL,
                        'requested_decision' => $requestedDecision,
                        'safety_forced_wait' => $requestedDecision !== $decision,
                        'counterfactual_required' => true,
                        'promotion_evidence' => false,
                    ],
                    'approved_by' => $approvedBy,
                    'approval_reason' => $approvalReason,
                    'promotion_evidence' => false,
                ],
            );
            $disagreement->update([
                'outcome_status' => $decision === 'WAIT' ? 'resolved_wait' : 'resolved_decision',
                'council_decision' => $decision,
                'evidence' => [
                    ...((array) $disagreement->evidence),
                    'adjudication_id' => $adjudication->id,
                    'adjudication_key' => $key,
                    'evidence_run_id' => $evidenceRunId,
                    'replay_hash' => $replayHash,
                    'window_keys' => $windowKeys,
                    'promotion_evidence' => false,
                ],
                'promotion_evidence' => false,
            ]);

            return [
                'status' => 'resolved',
                'decision' => $decision,
                'adjudication_id' => (int) $adjudication->id,
                'event_key' => $disagreement->event_key,
                'promotion_evidence' => false,
            ];
        });
    }

    /** @return array<string, string> */
    private function roleVotes(array $votes): array
    {
        $result = [];
        foreach ($votes as $role => $value) {
            $role = strtolower((string) $role);
            if (is_array($value)) $value = $value['decision'] ?? $value['signal'] ?? $value['action'] ?? null;
            $value = strtoupper(trim((string) $value));
            if ($value !== '') $result[$role] = $value;
        }

        return $result;
    }
}
