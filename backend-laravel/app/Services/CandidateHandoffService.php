<?php

namespace App\Services;

use App\Models\AgentFailureCase;
use App\Models\CandidateGateDecision;
use App\Models\CandidateHandoffEvent;
use App\Models\LabAgent;
use App\Models\LabGeneration;

/** Immutable operational handoff: screening is never silently lost before full replay. */
class CandidateHandoffService
{
    public function record(LabGeneration $generation, ?LabAgent $agent, string $stage, string $status, ?string $reason = null, array $payload = []): CandidateHandoffEvent
    {
        $event = CandidateHandoffEvent::firstOrCreate([
            'lab_generation_id' => $generation->id, 'lab_agent_id' => $agent?->id, 'stage' => $stage,
        ], ['status' => $status, 'terminal_reason' => $reason, 'payload' => $payload, 'recorded_at' => now()]);

        // The projection is idempotent, but a later selector retry can turn a
        // previously unselected candidate into a real full-replay handoff
        // (for example after a technical snapshot quarantine is repaired).
        // Refresh routing projections when their decision/profile changes;
        // stable waiting polls are short-circuited by the callers below so
        // the immutable evidence plane records revisions, not scheduler noise.
        if (! $event->wasRecentlyCreated
            && in_array($stage, ['selection_passed', 'waiting_for_targeted_generation'], true)
            && ($event->status !== $status || $event->terminal_reason !== $reason || $event->payload !== $payload)) {
            $event->update([
                'status' => $status,
                'terminal_reason' => $reason,
                'payload' => $payload,
                'recorded_at' => now(),
            ]);
            $event = $event->fresh();
        }

        // The handoff row is intentionally idempotent for routing, but the
        // evidence plane records every invocation, including repeated retries.
        app(LabImmutableEvidenceService::class)->recordHandoff($generation, $agent, $stage, $status, $reason, [
            'projection_id' => $event->id,
            'projection_write' => 'firstOrCreate',
            ...$payload,
        ]);

        return $event;
    }

    public function noEligibleCandidate(LabGeneration $generation, string $reason = 'NO_ELIGIBLE_CANDIDATE'): CandidateHandoffEvent
    {
        $profile = $this->screeningFailureProfile($generation);
        $profile['repair_anchors'] = app(FailureRepairAnchorService::class)->recordFromHandoff($generation, 'screening');
        $profile['repair_anchor_protocol'] = FailureRepairAnchorService::PROTOCOL;
        $payload = [
            'market' => $generation->laboratory?->symbol, 'timeframe' => $generation->laboratory?->timeframe,
            'next_action' => 'targeted_generation_when_scheduler_capacity_allows',
            'rule' => 'No full replay is forced; create a market-specific near-miss curriculum instead.',
            'screening_failure_profile' => $profile,
            'handoff_profile_hash' => $this->handoffProfileHash('screening', $reason, $profile),
        ];
        if ($existing = $this->stableWaitingHandoff($generation, $reason, $payload['handoff_profile_hash'])) {
            return $existing;
        }
        $event = $this->record($generation, null, 'waiting_for_targeted_generation', 'waiting', $reason, $payload);
        $this->persistFailureCases($generation, $reason, $profile);
        return $event;
    }

    /**
     * A full replay can be technically perfect while every candidate still
     * fails the statistical forward passport. Keep that failure on the same
     * bounded evolution rail as a screening dead-end; otherwise the report
     * says "targeted rescue" but the scheduler has no waiting handoff to
     * consume.
     */
    public function noForwardCandidate(LabGeneration $generation, string $reason = 'NO_FORWARD_VALIDATED_CANDIDATE'): CandidateHandoffEvent
    {
        $profile = $this->forwardFailureProfile($generation);
        $profile['repair_anchors'] = app(FailureRepairAnchorService::class)->recordFromHandoff($generation, 'forward');
        $profile['repair_anchor_protocol'] = FailureRepairAnchorService::PROTOCOL;
        $payload = [
            'market' => $generation->laboratory?->symbol, 'timeframe' => $generation->laboratory?->timeframe,
            'next_action' => 'targeted_generation_when_scheduler_capacity_allows',
            'rule' => 'Full replay evidence is retained, but no failed forward candidate may enter paper; create a bounded failure curriculum instead.',
            'forward_failure_profile' => $profile,
            'promotion_evidence' => false,
            'handoff_profile_hash' => $this->handoffProfileHash('forward', $reason, $profile),
        ];
        if ($existing = $this->stableWaitingHandoff($generation, $reason, $payload['handoff_profile_hash'])) {
            return $existing;
        }
        $event = $this->record($generation, null, 'waiting_for_targeted_generation', 'waiting', $reason, $payload);
        $this->persistFailureCases($generation, $reason, $profile);
        return $event;
    }

    private function stableWaitingHandoff(LabGeneration $generation, string $reason, string $profileHash): ?CandidateHandoffEvent
    {
        $event = CandidateHandoffEvent::query()
            ->where('lab_generation_id', $generation->id)
            ->whereNull('lab_agent_id')
            ->where('stage', 'waiting_for_targeted_generation')
            ->first();
        if (! $event || $event->status !== 'waiting' || $event->terminal_reason !== $reason) {
            return null;
        }

        return data_get((array) $event->payload, 'handoff_profile_hash') === $profileHash
            ? $event
            : null;
    }

    private function handoffProfileHash(string $profileType, string $reason, array $profile): string
    {
        return hash('sha256', json_encode($this->canonicalizeForHash([
            'protocol' => 'waiting_handoff_dedupe_v1',
            'profile_type' => $profileType,
            'reason' => $reason,
            'profile' => $profile,
        ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalizeForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalizeForHash($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalizeForHash($item);
        }

        return $value;
    }

    /**
     * Turn a rejected population into a stable, actionable curriculum profile.
     * A generation id is evidence provenance, not a new failure type: the same
     * market deficit must accumulate evidence across generations instead of
     * creating hundreds of duplicate open cases.
     */
    private function screeningFailureProfile(LabGeneration $generation): array
    {
        $generation->loadMissing('agents');
        $agents = $generation->agents->keyBy('id');
        $decisions = CandidateGateDecision::query()
            ->where('stage', 'screening')
            ->whereIn('lab_agent_id', $agents->keys()->all())
            ->get();

        $reasonCounts = [];
        $familyCounts = [];
        $familyReasons = [];
        $nearMisses = [];
        foreach ($decisions as $decision) {
            $agent = $agents->get($decision->lab_agent_id);
            $family = (string) ($agent?->strategy_family ?? 'unknown');
            $familyCounts[$family] = ($familyCounts[$family] ?? 0) + 1;
            foreach (array_values(array_unique((array) $decision->reason_codes)) as $code) {
                $reasonCounts[$code] = ($reasonCounts[$code] ?? 0) + 1;
                $familyReasons[$family][$code] = ($familyReasons[$family][$code] ?? 0) + 1;
            }
            $margin = (array) data_get($decision->metrics, 'gate_margin', []);
            if ($margin === []) {
                $margin = app(GateMarginService::class)->screening((array) $decision->metrics, (array) $decision->reason_codes);
            }
            $nearMisses[] = [
                'agent_id' => (int) $decision->lab_agent_id,
                'family' => $family,
                'score' => (float) data_get($margin, 'near_miss_score', 0),
                'dominant_target' => data_get($margin, 'dominant_target'),
                'target_margin' => data_get($margin, 'target_margin'),
                'total_normalized_deficit' => data_get($margin, 'total_normalized_deficit'),
                'promotion_evidence' => false,
            ];
        }

        arsort($reasonCounts);
        arsort($familyCounts);
        foreach ($familyReasons as &$reasons) arsort($reasons);
        unset($reasons);

        $targets = collect($reasonCounts)->mapWithKeys(function (int $count, string $reason): array {
            return [$reason => ['count' => $count, 'target' => $this->targetForScreeningReason($reason)]];
        })->all();

        return [
            'protocol' => 'screening_failure_profile_v1',
            'generation_id' => $generation->id,
            'generation' => $generation->generation,
            'decision_count' => $decisions->count(),
            'reason_counts' => $reasonCounts,
            'targets' => $targets,
            'family_counts' => $familyCounts,
            'family_reasons' => $familyReasons,
            'near_miss_agents' => collect($nearMisses)->sortByDesc('score')->take(12)->values()->all(),
            'near_miss_protocol' => GateMarginService::PROTOCOL,
            'dominant_target' => collect($nearMisses)->pluck('dominant_target')->filter()->countBy()->sortDesc()->keys()->first(),
            'dominant_reason' => array_key_first($reasonCounts),
            'promotion_evidence' => false,
            'rule' => 'Gate failures are diagnostic evidence only; no near-miss is promoted to full validation or paper status.',
        ];
    }

    private function forwardFailureProfile(LabGeneration $generation): array
    {
        $generation->loadMissing('agents');
        $agents = $generation->agents->keyBy('id');
        $decisions = CandidateGateDecision::query()
            ->where('stage', 'statistical_forward_gate')
            ->whereIn('lab_agent_id', $agents->keys()->all())
            ->get();

        $reasonCounts = [];
        $familyCounts = [];
        $familyReasons = [];
        foreach ($decisions as $decision) {
            $agent = $agents->get($decision->lab_agent_id);
            $family = (string) ($agent?->strategy_family ?? 'unknown');
            $familyCounts[$family] = ($familyCounts[$family] ?? 0) + 1;
            foreach (array_values(array_unique((array) $decision->reason_codes)) as $code) {
                $reasonCounts[$code] = ($reasonCounts[$code] ?? 0) + 1;
                $familyReasons[$family][$code] = ($familyReasons[$family][$code] ?? 0) + 1;
            }
        }

        arsort($reasonCounts);
        arsort($familyCounts);
        foreach ($familyReasons as &$reasons) arsort($reasons);
        unset($reasons);

        $targets = collect($reasonCounts)->mapWithKeys(function (int $count, string $reason): array {
            return [$reason => ['count' => $count, 'target' => $this->targetForScreeningReason($reason)]];
        })->all();

        return [
            'protocol' => 'forward_failure_profile_v1',
            'generation_id' => $generation->id,
            'generation' => $generation->generation,
            'decision_count' => $decisions->count(),
            'reason_counts' => $reasonCounts,
            'targets' => $targets,
            'family_counts' => $familyCounts,
            'family_reasons' => $familyReasons,
            'dominant_reason' => array_key_first($reasonCounts),
            'promotion_evidence' => false,
            'rule' => 'Forward-gate failures are learning evidence only; no near-miss is promoted to paper.',
        ];
    }

    private function persistFailureCases(LabGeneration $generation, string $reason, array $profile): void
    {
        $symbol = (string) $generation->laboratory?->symbol;
        $timeframe = (string) ($generation->laboratory?->timeframe ?? 'H1');
        $reasons = collect((array) ($profile['reason_counts'] ?? []));
        if ($reasons->isEmpty()) {
            $reasons = collect([$symbol === 'GBPUSD' ? 'FAILED_TRADE_COUNT' : 'FAILED_PROFIT_FACTOR' => 1]);
        }

        foreach ($reasons->take(4) as $screeningReason => $count) {
            [$failure, $target] = $this->failureCaseForScreeningReason((string) $screeningReason, $symbol);
            $key = hash('sha256', "handoff|{$symbol}|{$timeframe}|{$failure}");
            $case = AgentFailureCase::firstOrNew(['failure_case_key' => $key]);
            $case->fill([
                'market_slice_hash' => hash('sha256', "{$symbol}|{$timeframe}|screening|{$failure}"),
                'symbol' => $symbol, 'timeframe' => $timeframe, 'regime' => null,
                'failure_type' => $failure, 'severity' => 'P1_QUALITY', 'expected_safe_behavior' => 'DO_NOT_FORCE_FULL_REPLAY',
                'expected_action' => $target, 'discovered_by' => 'CandidateHandoffProtocol', 'regression_status' => 'open',
                'discovered_at' => $case->discovered_at ?? now(),
                'evidence' => [
                    'latest_generation_id' => $generation->id,
                    'latest_generation' => $generation->generation,
                    'latest_screening_reason' => $screeningReason,
                    'latest_gate_reason' => $screeningReason,
                    'latest_reason_count' => $count,
                    'reason' => $reason,
                    'failure_profile' => $profile,
                    'screening_failure_profile' => $profile,
                    'promotion_evidence' => false,
                ],
            ]);
            $case->save();
        }
    }

    private function targetForScreeningReason(string $reason): string
    {
        return $this->failureCaseForScreeningReason($reason, '')[1];
    }

    private function failureCaseForScreeningReason(string $reason, string $symbol): array
    {
        return match ($reason) {
            'FAILED_TRADE_COUNT' => ['trade_viability_signal_frequency', 'trade_frequency'],
            'FAILED_PROFIT_FACTOR' => ['edge_pf_signal_quality', 'profit_factor'],
            'FAILED_DRAWDOWN', 'FAILED_RUIN' => ['cost_fragility', 'drawdown_risk'],
            'FAILED_STRESS_COST' => ['cost_fragility', 'stress_cost'],
            'FAILED_TEMPORAL_CHUNK_SURVIVAL' => ['temporal_chunk_survival', 'temporal_stability'],
            'FAILED_CALENDAR_MONTH_SURVIVAL', 'FAILED_MONTHLY_SURVIVAL' => ['temporal_monthly_survival', 'monthly_survival'],
            'FAILED_TRAIN_FORWARD_GAP', 'FAILED_PARAMETER_STABILITY', 'FAILED_SIGNAL_TIMING_STABILITY' => ['temporal_stability', 'temporal_stability'],
            'FAILED_REGIME_COVERAGE', 'FAILED_TRANSITION' => ['regime_coverage_quality', 'regime_coverage'],
            'FAILED_OVERFIT', 'FAILED_STATISTICAL' => ['overfit_structure', 'architecture'],
            'FAILED_CALENDAR_ALIGNMENT' => ['calendar_alignment', 'rolling_regime'],
            default => [$symbol === 'GBPUSD' ? 'trade_viability_signal_frequency' : 'edge_pf_signal_quality', $symbol === 'GBPUSD' ? 'trade_frequency' : 'profit_factor'],
        };
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
