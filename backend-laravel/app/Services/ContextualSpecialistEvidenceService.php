<?php

namespace App\Services;

use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\ModelMarketPerformance;

/**
 * Materializes local specialist authority from persisted candidate/control
 * replay evidence. Model labels and hand-written authority booleans are never
 * accepted as substitutes for the exact pair, calendar and gate ledgers.
 */
class ContextualSpecialistEvidenceService
{
    public const PROTOCOL = 'contextual_specialist_evidence_v1';

    /** @return array<string, mixed> */
    public function forCandidate(ModelMarketPerformance $candidate): array
    {
        $candidate->loadMissing('modelVersion');
        $identity = $this->identity((array) ($candidate->modelVersion?->metadata ?? []));
        $agent = LabAgent::query()
            ->with(['modelVersion', 'generation.agents.modelVersion'])
            ->where('model_version_id', $candidate->model_version_id)
            ->where('symbol', $candidate->symbol)
            ->where('timeframe', $candidate->timeframe)
            ->latest('id')
            ->first();
        $control = $this->controlFor($agent);
        $controlPerformance = $control
            ? ModelMarketPerformance::query()
                ->where('model_version_id', $control->model_version_id)
                ->where('symbol', $candidate->symbol)
                ->where('timeframe', $candidate->timeframe)
                ->where('evidence_status', 'valid')
                ->latest('id')
                ->first()
            : null;

        return $this->compile($candidate, $agent, $controlPerformance, $control, $identity);
    }

    /**
     * @param array<string, mixed> $identity
     * @return array<string, mixed>
     */
    public function compile(
        ModelMarketPerformance $candidate,
        ?LabAgent $agent,
        ?ModelMarketPerformance $controlPerformance,
        ?LabAgent $control,
        array $identity,
    ): array {
        $candidateMetrics = (array) ($candidate->metrics ?? []);
        $controlMetrics = (array) ($controlPerformance?->metrics ?? []);
        $candidateCoverage = (array) data_get($candidateMetrics, 'market_session_calendar_coverage', []);
        $controlCoverage = (array) data_get($controlMetrics, 'market_session_calendar_coverage', []);
        $candidateInstances = $this->instances($candidateCoverage);
        $controlInstances = $this->instances($controlCoverage);
        $coverageHash = (string) data_get($candidateCoverage, 'opportunity_calendar_hash', '');
        $controlCoverageHash = (string) data_get($controlCoverage, 'opportunity_calendar_hash', '');
        $sameOpportunityCalendar = $coverageHash !== ''
            && $controlCoverageHash !== ''
            && hash_equals($coverageHash, $controlCoverageHash)
            && (string) data_get($candidateCoverage, 'calendar_version', '') !== ''
            && (string) data_get($candidateCoverage, 'calendar_version')
                === (string) data_get($controlCoverage, 'calendar_version')
            && (float) data_get($candidateCoverage, 'classification_coverage', 0) === 1.0
            && (int) data_get($candidateCoverage, 'unknown_count', 1) === 0;
        $exactControl = $agent !== null
            && $control !== null
            && $controlPerformance !== null
            && app(ExactCausalBaselineService::class)->matches($agent, $control)
            && $sameOpportunityCalendar
            && $candidateInstances !== []
            && $candidateInstances === $controlInstances;
        $windows = $this->chronologicalWindows($candidateMetrics, $controlMetrics);
        $qualifiedWindows = collect($windows)->where('candidate_better_than_control', true)
            ->where('absolute_settlement', '>', 0)->count();
        $pairedConfirmed = data_get($candidateMetrics, 'paired_replay.status') === 'confirmed'
            || data_get($candidateMetrics, 'repair_anchor_verification.status') === 'confirmed';
        $candidateNet = (float) data_get($candidateMetrics, 'net_profit_percent', 0);
        $controlNet = (float) data_get($controlMetrics, 'net_profit_percent', 0);
        $superior = $exactControl
            && $pairedConfirmed
            && $qualifiedWindows >= 2
            && $candidateNet > $controlNet;
        $screening = $agent ? CandidateGateDecision::query()
            ->where('lab_agent_id', $agent->id)
            ->where('stage', 'screening')
            ->latest('evaluated_at')
            ->latest('id')
            ->first() : null;
        $forward = CandidateGateDecision::query()
            ->where('model_market_performance_id', $candidate->id)
            ->where('stage', 'statistical_forward_gate')
            ->latest('evaluated_at')
            ->latest('id')
            ->first();
        $stressPf = data_get(
            $candidateMetrics,
            'pf_attribution.stress_cost.profit_factor',
            data_get($candidateMetrics, 'screening_survival.stress_cost_pf'),
        );
        $phase = strtolower((string) data_get($identity, 'venue_phase', ''));
        $phaseEvidence = (array) data_get($candidateMetrics, 'pf_attribution.by_venue_phase.'.$phase, []);
        $bootstrapLcb = data_get($candidateMetrics, 'statistical_evidence.edge_quality.bootstrap_pf.pf_5_percentile_lower_bound');
        $localPosteriorPassed = (int) data_get($phaseEvidence, 'trades', 0) >= 10
            && (float) data_get($phaseEvidence, 'net_pf', 0) > 1.0
            && (float) data_get($phaseEvidence, 'net_profit_percent', 0) > 0
            && is_numeric($bootstrapLcb)
            && (float) $bootstrapLcb >= 1.0;
        $selection = (array) data_get($candidateMetrics, 'selection_validation', []);
        $deflatedSharpe = (array) data_get($candidateMetrics, 'statistical_evidence.deflated_sharpe', []);
        $multipleTestingPassed = data_get($selection, 'status') === 'assessed'
            && data_get($selection, 'protocol') === 'purged_embargoed_cscv_v1'
            && data_get($selection, 'purge_embargo_applied') === true
            && (float) data_get($selection, 'probability_of_backtest_overfitting', 1) <= .50
            && data_get($deflatedSharpe, 'status') === 'assessed'
            && (float) data_get($deflatedSharpe, 'deflated_sharpe_probability', 0) >= .95;

        $evidence = [
            'protocol' => self::PROTOCOL,
            'identity' => $identity,
            'candidate_performance_id' => $candidate->id,
            'candidate_agent_id' => $agent?->id,
            'control_performance_id' => $controlPerformance?->id,
            'control_agent_id' => $control?->id,
            'screening_status' => $screening?->decision === 'passed' ? 'passed' : 'not_passed',
            'full_replay_status' => $forward?->decision === 'passed' ? 'passed' : 'not_passed',
            'exact_frozen_control' => $exactControl,
            'same_opportunity_calendar' => $sameOpportunityCalendar,
            'candidate_opportunity_calendar_hash' => $coverageHash ?: null,
            'control_opportunity_calendar_hash' => $controlCoverageHash ?: null,
            'candidate_session_instance_ids' => $candidateInstances,
            'control_session_instance_ids' => $controlInstances,
            'frozen_control_superiority' => $superior,
            'absolute_settlement' => $candidateNet,
            'control_absolute_settlement' => $controlNet,
            'chronological_windows' => $windows,
            'qualified_dst_offset_states' => $this->qualifiedOffsets($candidateMetrics, $identity),
            'spread_cost_stress_status' => is_numeric($stressPf) && (float) $stressPf >= 1.05
                ? 'passed' : 'not_passed',
            'local_positive_posterior_status' => $localPosteriorPassed ? 'passed' : 'not_passed',
            'local_phase_evidence' => $phaseEvidence,
            'local_bootstrap_profit_factor_lcb' => is_numeric($bootstrapLcb) ? (float) $bootstrapLcb : null,
            'multiple_testing_validation_status' => $multipleTestingPassed ? 'passed' : 'not_passed',
            'selection_validation' => $selection,
            'deflated_sharpe' => $deflatedSharpe,
            'other_session_regression_status' => data_get($candidateMetrics, 'no_regression_contract.status') === 'passed'
                ? 'passed' : 'not_passed',
            'outside_scope_activation_count' => (int) data_get(
                $candidateCoverage,
                'outside_scope_activation_count',
                -1,
            ),
            'pair_contract' => data_get($agent?->modelVersion?->metadata, 'control_pair_contract'),
            'local_evidence_grants_global_inheritance' => false,
            'materialized_from_persisted_ledgers' => true,
            'promotion_evidence' => false,
        ];
        $evidence['assessment'] = app(ContextualSpecialistAuthorityService::class)->assess($evidence);

        return $evidence;
    }

    /** @param array<string, mixed> $metadata @return array<string, mixed> */
    private function identity(array $metadata): array
    {
        $envelope = (array) data_get($metadata, 'contextual_specialist_identity', []);
        $identity = (array) data_get($envelope, 'identity', $envelope);
        $hash = (string) data_get($envelope, 'identity_hash', data_get($identity, 'identity_hash', ''));
        if ($hash !== '') {
            $identity['identity_hash'] = $hash;
        }

        return $identity;
    }

    private function controlFor(?LabAgent $candidate): ?LabAgent
    {
        if (! $candidate) {
            return null;
        }
        $pair = (array) data_get($candidate->modelVersion?->metadata, 'control_pair_contract', []);
        if (data_get($pair, 'role') !== 'candidate' || ! filled(data_get($pair, 'pair_key'))) {
            return null;
        }
        $controlId = (int) data_get($pair, 'control_agent_id', 0);
        $members = $candidate->generation?->agents ?? collect();

        return $members->first(fn (LabAgent $member): bool =>
            ($controlId <= 0 || (int) $member->id === $controlId)
            && data_get($member->modelVersion?->metadata, 'control_pair_contract.role') === 'control'
            && hash_equals(
                (string) data_get($pair, 'pair_key'),
                (string) data_get($member->modelVersion?->metadata, 'control_pair_contract.pair_key', ''),
            )
        );
    }

    /** @param array<string, mixed> $coverage @return array<int, string> */
    private function instances(array $coverage): array
    {
        $instances = array_values(array_unique(array_filter(array_map(
            'strval',
            (array) data_get($coverage, 'target_session_instance_ids', []),
        ))));
        sort($instances);

        return $instances;
    }

    /** @return array<int, array<string, mixed>> */
    private function chronologicalWindows(array $candidate, array $control): array
    {
        $candidateProtocol = (array) data_get($candidate, 'forward_window_protocol', []);
        $controlProtocol = (array) data_get($control, 'forward_window_protocol', []);
        $independent = data_get($candidateProtocol, 'independence_verified') === true
            && data_get($candidateProtocol, 'overlap_detected') !== true
            && data_get($controlProtocol, 'independence_verified') === true
            && data_get($controlProtocol, 'overlap_detected') !== true;
        $candidateRows = collect((array) data_get($candidateProtocol, 'windows', []))
            ->filter(fn ($row): bool => is_array($row))->mapWithKeys(fn (array $row, int|string $index): array => [
                $this->windowKey($row, $index) => $row,
            ]);
        $controlRows = collect((array) data_get($controlProtocol, 'windows', []))
            ->filter(fn ($row): bool => is_array($row))->mapWithKeys(fn (array $row, int|string $index): array => [
                $this->windowKey($row, $index) => $row,
            ]);

        return $candidateRows->keys()->intersect($controlRows->keys())->map(function (string $key) use (
            $candidateRows,
            $controlRows,
            $independent,
        ): array {
            $candidateScore = $this->windowScore((array) $candidateRows->get($key));
            $controlScore = $this->windowScore((array) $controlRows->get($key));
            $comparable = $independent && $candidateScore !== null && $controlScore !== null;

            return [
                'window_key' => $key,
                'candidate_absolute_settlement' => $candidateScore,
                'control_absolute_settlement' => $controlScore,
                'absolute_settlement' => $candidateScore ?? 0.0,
                'candidate_better_than_control' => $comparable && $candidateScore > $controlScore,
                'independence_verified' => $independent,
            ];
        })->values()->all();
    }

    private function windowKey(array $row, int|string $index): string
    {
        $key = (string) data_get($row, 'id', data_get($row, 'window_key', data_get($row, 'key', '')));
        if ($key !== '') {
            return $key;
        }

        return filled(data_get($row, 'start')) || filled(data_get($row, 'end'))
            ? (string) data_get($row, 'start', '?').'|'.(string) data_get($row, 'end', '?')
            : 'window:'.$index;
    }

    private function windowScore(array $row): ?float
    {
        foreach (['net_profit_percent', 'absolute_settlement', 'score'] as $key) {
            if (is_numeric(data_get($row, $key))) {
                return (float) data_get($row, $key);
            }
        }
        if (is_numeric(data_get($row, 'profit_factor'))) {
            return (float) data_get($row, 'profit_factor') - 1.0;
        }

        return null;
    }

    /** @param array<string, mixed> $identity @return array<int, string> */
    private function qualifiedOffsets(array $metrics, array $identity): array
    {
        $phase = strtolower((string) data_get($identity, 'venue_phase', ''));
        $regime = strtolower((string) data_get($identity, 'regime', ''));
        $volatility = strtolower((string) data_get($identity, 'volatility', ''));
        $direction = strtoupper((string) data_get($identity, 'direction', ''));
        $states = [];
        foreach ((array) data_get($metrics, 'robustness_matrix.venue_phase_envelopes', []) as $key => $row) {
            $parts = explode('|', (string) $key, 5);
            if (count($parts) !== 5
                || strtolower($parts[0]) !== $regime
                || strtolower($parts[1]) !== $volatility
                || strtolower($parts[2]) !== $phase
                || strtoupper($parts[3]) !== $direction
                || (int) data_get($row, 'trades', 0) < 3
                || (float) data_get($row, 'net_pf', 0) < 1.0
                || (float) data_get($row, 'net_profit_percent', 0) <= 0) {
                continue;
            }
            $states[] = $parts[4];
        }

        return array_values(array_unique(array_filter($states)));
    }
}
