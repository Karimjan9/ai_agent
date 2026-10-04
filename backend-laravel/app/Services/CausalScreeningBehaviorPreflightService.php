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

    /** Non-economic admission: missing data and low power are not bad skills. */
    public function learnability(array $observations, int $minimumOpportunities = 20, int $minimumTrades = 8): array
    {
        $status = 'ready_for_independent_validation'; $reasons = [];
        foreach (['guided', 'blinded', 'control'] as $role) {
            $observation = $observations[$role] ?? [];
            if (($observation['complete'] ?? false) !== true
                || ($observation['data_present'] ?? false) !== true) {
                $status = 'data_missing'; $reasons[] = strtoupper($role).'_DATA_PREREQUISITE_MISSING';
                if (($observation['scope_receipt_valid'] ?? true) === false) {
                    $reasons[] = strtoupper($role).'_EXACT_CONTEXT_RECEIPT_MISSING_OR_MISMATCHED';
                }
                if (($observation['context_quote_receipt_valid'] ?? true) === false) {
                    $reasons[] = strtoupper($role).'_EXACT_CONTEXT_OBSERVED_QUOTES_MISSING_OR_INSUFFICIENT';
                }
            }
        }
        if ($status !== 'data_missing') {
            $control = $observations['control'] ?? [];
            foreach ($observations as $role => $observation) {
                if ((int) ($observation['context_opportunities'] ?? 0) < max(1, $minimumOpportunities)) {
                    $status = 'underpowered'; $reasons[] = strtoupper($role).'_CONTEXT_TOO_RARE';
                }
            }
        }
        if ($status === 'ready_for_independent_validation') {
            foreach (['guided', 'blinded'] as $role) {
                if (! $this->compare($observations[$role], $control, $role)['executable_behavior_changed']) {
                    $status = 'no_effect'; $reasons[] = strtoupper($role).'_NO_EXECUTABLE_EFFECT';
                }
            }
            if ($status !== 'no_effect') foreach ($observations as $role => $observation) {
                if ((int) ($observation['accepted_entries'] ?? 0) < max(1, $minimumTrades)) {
                    $status = 'underpowered'; $reasons[] = strtoupper($role).'_INSUFFICIENT_PAIRED_OPPORTUNITIES';
                }
            }
        }
        return ['protocol' => 'causal_experiment_learnability_v1', 'status' => $status,
            'observations' => $observations, 'reason_codes' => array_values(array_unique($reasons)),
            'minimum_context_opportunities' => max(1, $minimumOpportunities),
            'minimum_trades_per_arm' => max(1, $minimumTrades),
            'economic_selection' => false, 'negative_skill_allowed' => false,
            'promotion_evidence' => false];
    }

    public function assessLearnability(AgentLearningCausalExperiment $experiment): array
    {
        $observations = [];
        $windowHashes = [];
        foreach (['guided' => $experiment->guided_agent_id, 'blinded' => $experiment->blinded_agent_id,
            'control' => $experiment->control_agent_id] as $role => $id) {
            $run = \App\Models\LabEvaluationRun::where('lab_agent_id', $id)
                ->where('phase', 'screening')->where('status', 'completed')->latest('id')->first();
            // Mutable dashboard projections are not data-quality proof.
            $result = $run ? app(LabImmutableEvidenceService::class)->latestArtifactPayload($run) : [];
            $quality = (array) ($result['data_quality'] ?? []);
            $spread = (array) ($quality['spread_quality'] ?? []);
            $scope = (array) ($quality['specialist_signal_scope'] ?? []);
            $declaredScope = (array) data_get($experiment->evidence, 'source_context_scope', []);
            $expectedAxes = app(ContextContractV2Service::class)->canonicalDeclaredAxes($declaredScope);
            $observedAxes = app(ContextContractV2Service::class)->canonicalDeclaredAxes([
                'regime' => $scope['target_regime'] ?? null,
                'volatility' => $scope['target_volatility'] ?? null,
                'session' => $scope['target_session'] ?? null,
                'venue_phase' => $scope['target_venue_phase'] ?? null,
                'transition_state' => $scope['target_transition_state'] ?? null,
                'spread_liquidity_state' => $scope['target_spread_liquidity_state'] ?? null,
                'direction' => $scope['target_direction'] ?? null,
            ]);
            $expectedScopeHash = (string) data_get($experiment->evidence, 'source_context_hash', '');
            $scopeReceiptValid = $expectedAxes !== [] && $expectedScopeHash !== ''
                && ($scope['protocol'] ?? null) === 'prospective_repair_exact_context_v1'
                && $observedAxes === $expectedAxes
                && is_numeric($scope['accepted_signal_count'] ?? null)
                && is_numeric($scope['eligible_context_candle_count'] ?? null)
                && hash_equals($expectedScopeHash,
                    (string) ($scope['source_context_hash'] ?? ''));
            $window = (array) data_get($result, 'data_manifest.prospective_probe_window', []);
            $receipt = (array) data_get($result, 'prospective_probe_window_receipt', []);
            $windowAttested = app(ProspectiveRepairProbeWindowService::class)->attests($window, $receipt);
            if ($windowAttested) {
                $windowHashes[$role] = (string) ($window['contract_hash'] ?? '');
            }
            // The predeclared specialist requires observed liquidity. Modeled
            // spread/promotion labels cannot fulfill that input requirement.
            $policy = (array) data_get($experiment->evidence, 'prospective_probe_policy', ProspectiveRepairExperimentService::PROBE_POLICY);
            $contextQuoteValid = $windowAttested && $scopeReceiptValid
                && $this->contextQuotesReady((array) ($scope['context_quote_quality'] ?? []), $window,
                    $expectedScopeHash, (float) $policy['minimum_observed_quote_coverage']);
            $dataPresent = $windowAttested && $scopeReceiptValid
                && data_get($quality, 'mtf_stack.status') === 'ready'
                && ($spread['protocol'] ?? '') === 'historical_quote_spread_quality_v1'
                && ($spread['provider_observed'] ?? false) === true
                && $contextQuoteValid;
            $observations[$role] = [...$this->observation((int) $id, $role === 'control'),
                'data_present' => $dataPresent,
                'context_opportunities' => (int) ($scope['eligible_context_candle_count'] ?? 0),
                'accepted_scope_signals' => (int) ($scope['accepted_signal_count'] ?? 0),
                'scope_receipt_valid' => $scopeReceiptValid,
                'probe_window_attested' => $windowAttested,
                'probe_window_hash' => $windowAttested ? $window['contract_hash'] : null,
                'evaluated_month_counts' => $windowAttested ? $window['evaluated_month_counts'] : [],
                'quote_coverage' => $spread['coverage'] ?? null,
                'context_quote_coverage' => data_get($scope, 'context_quote_quality.coverage'),
                'context_quote_receipt_valid' => $contextQuoteValid,
                'mtf_status' => data_get($quality, 'mtf_stack.status')];
        }
        // Three individually valid screens are not an exact control unless
        // they replayed the same sealed discovery window and execution.
        if (count($windowHashes) !== 3 || count(array_unique($windowHashes)) !== 1) {
            foreach ($observations as &$observation) {
                $observation['data_present'] = false;
                $observation['paired_probe_window_valid'] = false;
            }
            unset($observation);
        } else {
            foreach ($observations as &$observation) {
                $observation['paired_probe_window_valid'] = true;
            }
            unset($observation);
        }
        return $this->learnability($observations, (int) $policy['minimum_context_opportunities'],
            (int) $policy['minimum_trades_per_arm']);
    }

    /** Global coverage cannot substitute for the exact evaluated context. */
    public function contextQuotesReady(array $receipt, array $window, string $contextHash, float $minimum): bool
    {
        if (($receipt['protocol'] ?? null) !== 'exact_context_quote_quality_v1'
            || $contextHash === '' || ($receipt['source_context_hash'] ?? null) !== $contextHash
            || ($receipt['source_provenance_valid'] ?? null) !== true
            || ($receipt['before_liquidity_veto'] ?? null) !== true
            || ($receipt['modeled_spread_is_not_observed'] ?? null) !== true
            || ($receipt['promotion_evidence'] ?? null) !== false) return false;
        foreach (['evaluated_rows', 'evaluated_start', 'evaluated_end'] as $key) {
            if (! array_key_exists($key, $window) || ($receipt[$key] ?? null) !== $window[$key]) return false;
        }
        $rows = $receipt['context_rows'] ?? null;
        $observed = $receipt['observed_rows'] ?? null;
        if (! is_int($rows) || ! is_int($observed) || $rows < 0 || $observed < 0 || $observed > $rows
            || $rows > (int) $window['evaluated_rows']) return false;
        if ($rows === 0) return $observed === 0 && array_key_exists('coverage', $receipt) && $receipt['coverage'] === null;
        $coverage = $receipt['coverage'] ?? null;
        return is_numeric($coverage) && is_finite((float) $coverage)
            && abs((float) $coverage - $observed / $rows) < 0.000000001
            && (float) $coverage >= $minimum;
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
