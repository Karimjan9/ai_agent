<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\LabMutationResponseMap;

/**
 * Proves that both interventions reached executable behaviour before the
 * expensive nine-fold lane is opened.
 *
 * A different parameter or signal digest alone is not enough. The screening
 * replay must show a changed trade/event path (or a changed accepted-entry
 * count) against the exact frozen control. Economic direction is deliberately
 * ignored here: a harmful but real intervention is still valid causal data.
 */
class CausalScreeningBehaviorPreflightService
{
    public const PROTOCOL = 'causal_screening_behavior_preflight_v1';

    /** @return array<string, mixed> */
    public function assess(AgentLearningCausalExperiment $experiment): array
    {
        $control = $this->observation((int) $experiment->control_agent_id, true);
        $roles = [
            'guided' => $this->observation((int) $experiment->guided_agent_id),
            'blinded' => $this->observation((int) $experiment->blinded_agent_id),
        ];
        if (! $control['complete'] || collect($roles)->contains(fn (array $role): bool => ! $role['complete'])) {
            return [
                'protocol' => self::PROTOCOL,
                'status' => 'incomplete',
                'reason_codes' => ['CAUSAL_COHORT_SCREENING_BEHAVIOR_EVIDENCE_INCOMPLETE'],
                'control' => $control,
                'roles' => $roles,
                'economic_selection' => false,
                'promotion_evidence' => false,
            ];
        }

        $comparisons = collect($roles)->map(
            fn (array $candidate, string $role): array => $this->compare($candidate, $control, $role),
        )->all();
        $failed = collect($comparisons)->filter(fn (array $comparison): bool => ! $comparison['executable_behavior_changed']);

        return [
            'protocol' => self::PROTOCOL,
            'status' => $failed->isEmpty() ? 'passed' : 'failed',
            'reason_codes' => $failed->keys()->map(
                fn (string $role): string => strtoupper($role).'_SCREENING_EXECUTABLE_BEHAVIOR_UNCHANGED',
            )->values()->all(),
            'control' => $control,
            'roles' => $comparisons,
            'economic_selection' => false,
            'rule' => 'Parameter/signal identity is diagnostic; full replay requires a changed executable trade or event path.',
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function compare(array $candidate, array $control, string $role = 'candidate'): array
    {
        $tradeChanged = ! hash_equals((string) $control['trade_ledger_hash'], (string) $candidate['trade_ledger_hash']);
        $eventChanged = ! hash_equals((string) $control['event_ledger_hash'], (string) $candidate['event_ledger_hash']);
        $signalChanged = ! hash_equals((string) $control['signal_decision_hash'], (string) $candidate['signal_decision_hash']);
        $entriesChanged = (int) $control['accepted_entries'] !== (int) $candidate['accepted_entries'];
        $executable = $tradeChanged || $eventChanged || $entriesChanged;

        return [
            ...$candidate,
            'role' => $role,
            'trade_ledger_changed' => $tradeChanged,
            'event_ledger_changed' => $eventChanged,
            'signal_decision_changed' => $signalChanged,
            'accepted_entries_changed' => $entriesChanged,
            'executable_behavior_changed' => $executable,
            'signal_only_change_is_insufficient' => $signalChanged && ! $executable,
            'economic_selection' => false,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function observation(int $agentId, bool $control = false): array
    {
        $query = LabMutationResponseMap::query()
            ->where('lab_agent_id', $agentId)
            ->where('stage', 'screening');
        if ($control) {
            $query->where('status', 'control');
        }
        $map = $query->latest('id')->first();
        $observation = (array) data_get($map?->observed_metrics, 'causal_observation', []);
        $trade = trim((string) data_get($observation, 'trade_ledger_hash', ''));
        $event = trim((string) data_get($observation, 'event_ledger_hash', ''));
        $signal = trim((string) data_get($observation, 'signal_decision_hash', ''));
        $entries = data_get($observation, 'entry_funnel.accepted_entries');

        return [
            'agent_id' => $agentId,
            'response_map_id' => $map?->id,
            'complete' => $map !== null && $trade !== '' && $event !== '' && $signal !== '' && is_numeric($entries),
            'trade_ledger_hash' => $trade,
            'event_ledger_hash' => $event,
            'signal_decision_hash' => $signal,
            'accepted_entries' => is_numeric($entries) ? (int) $entries : null,
        ];
    }
}
