<?php

namespace App\Services;

use App\Models\AgentFailureCase;
use App\Models\CandidateHandoffEvent;
use App\Models\LabAgent;
use App\Models\LabGeneration;

/** Immutable operational handoff: screening is never silently lost before full replay. */
class CandidateHandoffService
{
    public function record(LabGeneration $generation, ?LabAgent $agent, string $stage, string $status, ?string $reason = null, array $payload = []): CandidateHandoffEvent
    {
        return CandidateHandoffEvent::firstOrCreate([
            'lab_generation_id' => $generation->id, 'lab_agent_id' => $agent?->id, 'stage' => $stage,
        ], ['status' => $status, 'terminal_reason' => $reason, 'payload' => $payload, 'recorded_at' => now()]);
    }

    public function noEligibleCandidate(LabGeneration $generation, string $reason = 'NO_ELIGIBLE_CANDIDATE'): CandidateHandoffEvent
    {
        $event = $this->record($generation, null, 'waiting_for_targeted_generation', 'waiting', $reason, [
            'market' => $generation->laboratory?->symbol, 'timeframe' => $generation->laboratory?->timeframe,
            'next_action' => 'targeted_generation_when_scheduler_capacity_allows',
            'rule' => 'No full replay is forced; create a market-specific near-miss curriculum instead.',
        ]);
        $symbol = (string) $generation->laboratory?->symbol;
        $failure = $symbol === 'GBPUSD' ? 'trade_viability_signal_frequency' : 'edge_pf_signal_quality';
        $target = $symbol === 'GBPUSD' ? 'trade_frequency' : 'profit_factor';
        AgentFailureCase::firstOrCreate(['failure_case_key' => hash('sha256', "handoff|{$generation->id}|{$failure}")], [
            'market_slice_hash' => hash('sha256', "{$symbol}|{$generation->laboratory?->timeframe}|{$generation->id}|handoff"),
            'symbol' => $symbol, 'timeframe' => $generation->laboratory?->timeframe ?? 'H1', 'regime' => null,
            'failure_type' => $failure, 'severity' => 'P1_QUALITY', 'expected_safe_behavior' => 'DO_NOT_FORCE_FULL_REPLAY',
            'expected_action' => $target, 'discovered_by' => 'CandidateHandoffProtocol', 'regression_status' => 'open',
            'discovered_at' => now(), 'evidence' => ['generation_id' => $generation->id, 'reason' => $reason, 'promotion_evidence' => false],
        ]);
        return $event;
    }

    public function backfill(LabGeneration $generation): void
    {
        $generation->loadMissing('agents');
        foreach ($generation->agents as $agent) {
            if (in_array($agent->lifecycle_status, ['screened', 'full_queued', 'training', 'challenger', 'forward_validated', 'paper'], true)) {
                $this->record($generation, $agent, 'screened', 'completed', null, ['backfilled' => true]);
            }
            if (in_array($agent->lifecycle_status, ['full_queued', 'training', 'challenger', 'forward_validated', 'paper'], true)) {
                $this->record($generation, $agent, 'full_validation_queued', 'completed', null, ['backfilled' => true]);
            }
            if (in_array($agent->lifecycle_status, ['challenger', 'forward_validated', 'paper', 'rejected', 'overfit', 'stagnated'], true)) {
                $this->record($generation, $agent, 'full_validation_completed', 'completed', null, ['backfilled' => true]);
            }
        }
    }
}
