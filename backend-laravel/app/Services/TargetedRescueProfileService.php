<?php

namespace App\Services;

use App\Models\CandidateGateDecision;
use App\Models\LabGeneration;

/** Builds an auditable failure curriculum without turning failures into priors. */
class TargetedRescueProfileService
{
    /** @return array<string, mixed> */
    public function forGeneration(LabGeneration $generation): array
    {
        $generation->loadMissing('laboratory', 'agents');
        $agentIds = $generation->agents->pluck('id')->values()->all();
        $decisions = CandidateGateDecision::query()
            ->whereIn('lab_agent_id', $agentIds)
            ->where('stage', 'screening')
            ->orderByDesc('id')
            ->get()
            ->groupBy('lab_agent_id')
            ->map(fn ($rows) => $rows->first())
            ->values();

        $reasonCounts = [];
        $targetCounts = [];
        $incompleteAgentIds = [];
        $technicalExcludedAgentIds = [];
        foreach ($decisions as $decision) {
            $reasons = array_values(array_unique(array_map('strtoupper', (array) $decision->reason_codes)));
            $technical = (string) $decision->decision === 'insufficient_evidence'
                || collect($reasons)->contains(fn (string $reason): bool =>
                    str_contains($reason, 'EVIDENCE')
                    || str_contains($reason, 'TECHNICAL')
                    || str_contains($reason, 'SNAPSHOT')
                );
            if ($technical) {
                $incompleteAgentIds[] = (int) $decision->lab_agent_id;
                $technicalExcludedAgentIds[] = (int) $decision->lab_agent_id;
                continue;
            }
            foreach ($reasons as $reason) {
                if ($reason === '') continue;
                $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
                $target = $this->targetForReason($reason);
                if ($target !== null) $targetCounts[$target] = ($targetCounts[$target] ?? 0) + 1;
            }
        }
        arsort($reasonCounts);
        arsort($targetCounts);

        $symbol = strtoupper((string) $generation->laboratory?->symbol);
        $timeframe = strtoupper((string) $generation->laboratory?->timeframe);
        $profile = [
            'protocol' => LabPopulationService::TARGETED_RESCUE_PROFILE_PROTOCOL,
            'rescue_protocol' => LearningProtocolSafetyService::CONTROLLED_RESCUE_PROTOCOL,
            'temporary' => true,
            'population_size' => count(LabPopulationService::POPULATION_GROUPS) * LabPopulationService::POPULATION_GROUP_SEATS,
            'group_plan' => LabPopulationService::TARGETED_RESCUE_GROUP_PLAN,
            'source_generation_id' => (int) $generation->id,
            'source_generation' => (int) $generation->generation,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'reason_counts' => $reasonCounts,
            'target_counts' => $targetCounts,
            'targets' => array_values(array_unique([
                ...array_keys($targetCounts),
                'profit_factor', 'stress_cost', 'temporal_stability', 'regime_coverage',
            ])),
            'incomplete_evidence_agent_ids' => array_values(array_unique($incompleteAgentIds)),
            'technical_excluded_agent_ids' => array_values(array_unique($technicalExcludedAgentIds)),
            'actionable_failure_count' => array_sum($targetCounts),
            'causal_prior_allowed' => false,
            'promotion_evidence' => false,
            'rule' => 'Failure observations route research only; no failed or legacy record becomes a parent, mutation credit or promotion evidence.',
        ];
        $profile['profile_hash'] = hash('sha256', json_encode($profile, JSON_UNESCAPED_SLASHES));

        return $profile;
    }

    private function targetForReason(string $reason): ?string
    {
        return match ($reason) {
            'FAILED_PROFIT_FACTOR' => 'profit_factor',
            'FAILED_STRESS_COST' => 'stress_cost',
            'FAILED_TEMPORAL_CHUNK_SURVIVAL',
            'FAILED_CALENDAR_MONTH_SURVIVAL',
            'FAILED_TRAIN_FORWARD_GAP',
            'FAILED_PARAMETER_STABILITY',
            'FAILED_SIGNAL_TIMING_STABILITY' => 'temporal_stability',
            'FAILED_REGIME_COVERAGE',
            'INSUFFICIENT_REGIME_EVIDENCE',
            'FAILED_TRANSITION' => 'regime_coverage',
            'FAILED_NON_TARGET_REGRESSION' => 'drawdown_risk',
            'FAILED_DRAWDOWN',
            'FAILED_RUIN' => 'drawdown_risk',
            'FAILED_OVERFIT',
            'FAILED_STATISTICAL' => 'architecture',
            default => null,
        };
    }
}
