<?php

namespace App\Services;

use App\Models\DualTrackExchangePacket;
use App\Models\DualTrackRun;
use Illuminate\Support\Facades\Schema;

/** Typed, one-way capability exchange; identities and promotion states never cross. */
class DualTrackExchangeService
{
    public const PROTOCOL = 'twin_intelligence_controlled_exchange_v1';

    public function __construct(private TwinIntelligenceProfileService $profiles) {}

    /** @return array<string, mixed> */
    public function recordForRun(DualTrackRun $run, array $champion, array $council, array $decision): array
    {
        if (! $this->hasTable('dual_track_exchange_packets')) return ['status' => 'unavailable', 'promotion_evidence' => false];
        $packets = [
            $this->packet($run, 'champion', 'council', 'execution_proposal', [
                'decision' => $champion['decision'] ?? 'WAIT', 'confidence' => $champion['confidence'] ?? null,
                'expected_edge' => $champion['expected_edge'] ?? null, 'risk_assumptions' => $champion['risk_assumptions'] ?? [],
            ]),
            $this->packet($run, 'council', 'champion', 'risk_review', [
                'decision' => $council['decision'] ?? 'WAIT', 'confidence' => $council['confidence'] ?? null,
                'disagreement_code' => $decision['disagreement_code'] ?? null,
                'risk_warning' => $council['risk_warning'] ?? null, 'abstention_recommended' => ($council['decision'] ?? 'WAIT') === 'WAIT',
            ]),
        ];

        return [
            'status' => 'recorded', 'packets' => $packets,
            'lane_contracts' => ['champion' => $this->profiles->contract('champion'), 'council' => $this->profiles->contract('council')],
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function capabilityPacket(string $sourceLane, string $targetLane, string $type, array $lesson): array
    {
        $source = $this->profiles->profile($sourceLane);
        $target = $this->profiles->profile($targetLane);
        $allowed = in_array($type, (array) data_get($source, 'transfer_policy.can_send', []), true)
            && in_array($type, (array) data_get($target, 'transfer_policy.can_receive', []), true);

        return [
            'protocol' => self::PROTOCOL, 'source_lane' => $sourceLane, 'target_lane' => $targetLane,
            'packet_type' => $type, 'accepted' => $allowed,
            'payload' => $allowed ? [
                'statement' => $lesson['statement'] ?? null, 'failure_class' => $lesson['failure_class'] ?? null,
                'cell_key' => $lesson['cell_key'] ?? null, 'evidence_ref' => $lesson['evidence_ref'] ?? null,
            ] : [],
            'status' => $allowed ? 'quarantined' : 'rejected', 'delivery_status' => $allowed ? 'awaiting_independent_replay' : 'blocked',
            'status_transfer' => false, 'requires_revalidation' => true, 'promotion_evidence' => false,
        ];
    }

    /** A capability is delivered only after an independent replay confirms it. */
    public function revalidateCapabilityPacket(array $packet, array $replayEvidence): array
    {
        // The runtime packet intentionally contains only a compact capability
        // reference. Resolve expiry from the immutable DB row as well; without
        // this, a replay of an old packet could be accepted simply because the
        // transport payload omitted expires_at.
        $stored = null;
        if (isset($packet['packet_id']) && $this->hasTable('dual_track_exchange_packets')) {
            $stored = DualTrackExchangePacket::query()->find((int) $packet['packet_id']);
        }
        $expiresAt = $packet['expires_at'] ?? $stored?->expires_at;
        try {
            $notExpired = $expiresAt === null || now()->lessThan(\Illuminate\Support\Carbon::parse($expiresAt));
        } catch (\Throwable) {
            $notExpired = false;
        }
        $passed = ($packet['accepted'] ?? false) === true
            && ($replayEvidence['independent_snapshot'] ?? false) === true
            && ($replayEvidence['holdout_passed'] ?? false) === true
            && ($replayEvidence['risk_gate_passed'] ?? false) === true
            && $notExpired;

        $result = [
            ...$packet,
            'status' => $passed ? 'delivered' : 'quarantined',
            'delivery_status' => $passed ? 'revalidated' : 'awaiting_more_evidence',
            'revalidation' => ['protocol' => self::PROTOCOL, 'evidence' => $replayEvidence, 'passed' => $passed],
            'promotion_evidence' => false,
        ];
        if ($stored) {
            $hash = hash('sha256', json_encode([$stored->integrity_hash, $replayEvidence], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
            $stored->update(['status' => $passed ? 'delivered' : 'quarantined', 'delivery_status' => $passed ? 'revalidated' : 'awaiting_more_evidence', 'revalidation_hash' => $hash, 'revalidated_at' => now(), 'evidence' => [...((array) $stored->evidence), 'revalidation' => $replayEvidence, 'promotion_evidence' => false]]);
            $result['delivery_status'] = $stored->delivery_status;
            $result['revalidation_hash'] = $hash;
        }
        return $result;
    }

    /** @return array<string, mixed> */
    private function packet(DualTrackRun $run, string $source, string $target, string $type, array $payload): array
    {
        $key = hash('sha256', self::PROTOCOL.'|'.$run->run_key.'|'.$source.'|'.$target.'|'.$type);
        $hash = hash('sha256', json_encode([$run->cell_key, $source, $target, $type, $payload], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        // Packet identity is an immutable event. Re-observing the same run
        // must not downgrade a delivered/revalidated packet back to
        // quarantine or rewrite its original payload.
        $packet = DualTrackExchangePacket::query()->firstOrCreate(
            ['packet_key' => $key],
            [
                'dual_track_run_id' => $run->id, 'symbol' => $run->symbol, 'timeframe' => $run->timeframe,
                'cell_key' => $run->cell_key, 'source_lane' => $source, 'target_lane' => $target,
                'packet_type' => $type, 'protocol_version' => self::PROTOCOL, 'payload' => $payload,
                'integrity_hash' => $hash, 'status' => 'observed',
                'delivery_status' => 'quarantined', 'expires_at' => now()->addDays(7),
                'evidence' => ['source_contract' => $this->profiles->contract($source), 'target_contract' => $this->profiles->contract($target), 'promotion_evidence' => false],
                'promotion_evidence' => false,
            ],
        );

        return ['packet_id' => $packet->id, 'source_lane' => $source, 'target_lane' => $target, 'packet_type' => $type, 'integrity_hash' => $hash, 'delivery_status' => 'quarantined', 'promotion_evidence' => false];
    }

    private function hasTable(string $table): bool
    {
        try { return Schema::hasTable($table); } catch (\Throwable) { return false; }
    }
}
