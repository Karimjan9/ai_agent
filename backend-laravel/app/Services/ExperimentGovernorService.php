<?php

namespace App\Services;

use App\Models\CapabilityExperimentDecision;
use App\Models\LabAgent;

/** Allocates research attention; it can only produce shadow, one-axis experiments. */
class ExperimentGovernorService
{
    public const PROTOCOL = 'capability_experiment_governor_v1';

    /** @return array<string,mixed> */
    public function decide(array $diagnosis, array $risk = [], ?LabAgent $agent = null): array
    {
        $failure = (string) ($diagnosis['primary_cause'] ?? $diagnosis['failure_mode'] ?? 'unknown');
        $lane = in_array($failure, ['strategy', 'tactic', 'execution', 'risk'], true) ? 'repair' : ((bool) ($diagnosis['confirmed_skill'] ?? false) ? 'exploit' : 'discovery');
        $axis = $lane === 'repair' ? $failure : ($lane === 'exploit' ? 'replicate_confirmed_skill' : (string) ($diagnosis['changed_axis'] ?? 'entry_topology'));
        $drawdown = max(0, (float) ($risk['drawdown_percent'] ?? 0));
        $ror = max(0, (float) ($risk['risk_of_ruin_percent'] ?? 0));
        $budget = $lane === 'exploit' ? .20 : .10;
        $budget *= max(.10, 1 - ($drawdown / 20) - ($ror / 20));
        $priority = min(1, .4 + ((float) ($diagnosis['severity'] ?? .5) * .4) + ($lane === 'repair' ? .2 : 0));
        $key = hash('sha256', implode('|', [$agent?->id, $lane, $axis, $diagnosis['target_key'] ?? $failure]));
        $contract = ['protocol' => self::PROTOCOL, 'lane' => $lane, 'one_changed_axis' => $axis, 'max_changed_axes' => 1, 'requires_paired_control' => true, 'requires_data_hash' => true, 'requires_execution_hash' => true, 'live_execution' => false, 'promotion_evidence' => false];
        $row = CapabilityExperimentDecision::updateOrCreate(['decision_key' => $key], ['lab_agent_id' => $agent?->id, 'lane' => $lane, 'action' => $lane === 'exploit' ? 'replicate_only' : 'shadow_ablation', 'target_key' => $diagnosis['target_key'] ?? null, 'changed_axis' => $axis, 'research_budget_percent' => round($budget, 6), 'priority_score' => round($priority, 6), 'status' => 'shadow_only', 'contract' => $contract, 'decided_at' => now()]);

        return ['status' => 'recorded', 'decision_id' => $row->id, 'contract' => $contract, 'promotion_evidence' => false];
    }
}
