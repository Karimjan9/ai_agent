<?php

namespace App\Services;

use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use Illuminate\Support\Collection;

/**
 * Audits the frozen member of a research cohort before interpreting a
 * mutation. A failed/missing control blocks interpretation, never promotion.
 */
class FrozenControlParityService
{
    public const PROTOCOL = 'frozen_control_parity_v1';
    public const CONTROL_PAIR_PROTOCOL = 'frozen_control_pair_v1';

    public function __construct(private GateMarginService $margins) {}

    /** @return array<string, mixed> */
    public function assess(LabGeneration $generation): array
    {
        $generation->loadMissing('laboratory', 'agents.modelVersion');
        $agents = $generation->agents;
        $controls = $agents->filter(fn (LabAgent $agent): bool => $this->isControl($agent))->values();
        if ($controls->isEmpty()) {
            return [
                'protocol' => self::PROTOCOL,
                'status' => 'not_applicable',
                'control_count' => 0,
                'recompute_required' => false,
                'interpretation' => 'No frozen-control member is attached to this legacy/ordinary generation.',
                'promotion_evidence' => false,
            ];
        }

        $controlRows = $controls->map(fn (LabAgent $control): array => $this->controlRow($control))->values();
        $incomplete = $controlRows->filter(fn (array $row): bool => $row['status'] === 'incomplete')->count();
        $failed = $controlRows->filter(fn (array $row): bool => $row['status'] === 'failed')->count();
        $candidateRows = [];
        foreach ($controls as $control) {
            $cohort = $this->cohortId($control);
            $controlResult = $this->result($control);
            $members = $agents->filter(fn (LabAgent $agent): bool =>
                ! $this->isControl($agent) && $this->cohortId($agent) === $cohort
            );
            foreach ($members as $candidate) {
                $candidateResult = $this->result($candidate);
                $comparison = $controlResult !== [] && $candidateResult !== []
                    ? $this->margins->compare(
                        $candidateResult,
                        $controlResult,
                        (string) data_get($candidate->modelVersion?->metadata, 'repair_anchor.failure_target', data_get($candidate->modelVersion?->metadata, 'generation_target', 'profit_factor')),
                    )
                    : [
                        'protocol' => self::PROTOCOL,
                        'same_data_hash' => false,
                        'same_execution_hash' => false,
                        'candidate_better' => null,
                        'control_gate_status' => 'incomplete',
                        'promotion_evidence' => false,
                    ];
                $candidateRows[] = [
                    'candidate_agent_id' => (int) $candidate->id,
                    'control_agent_id' => (int) $control->id,
                    'cohort_id' => $cohort,
                    'comparison' => $comparison,
                    'interpretation_allowed' => $failed === 0 && $incomplete === 0
                        && data_get($comparison, 'same_data_hash') === true
                        && data_get($comparison, 'same_execution_hash') === true,
                    'promotion_evidence' => false,
                ];
            }
        }

        $status = $incomplete > 0
            ? 'incomplete'
            : ($failed > 0 ? 'control_failed' : 'passed');

        return [
            'protocol' => self::PROTOCOL,
            'status' => $status,
            'control_count' => $controls->count(),
            'control_passed' => $controlRows->filter(fn (array $row): bool => $row['status'] === 'passed')->count(),
            'control_failed' => $failed,
            'control_incomplete' => $incomplete,
            'recompute_required' => $incomplete > 0 || $failed > 0,
            'interpretation' => $status === 'passed'
                ? 'Candidate/control comparisons are interpretable on the observed control contract.'
                : 'Control must be recomputed or audited before mutation results are interpreted.',
            'controls' => $controlRows->all(),
            'candidate_comparisons' => $candidateRows,
            'promotion_evidence' => false,
        ];
    }

    public function isControl(LabAgent $agent): bool
    {
        $metadata = (array) ($agent->modelVersion?->metadata ?? []);
        $siblingKind = (string) data_get(
            $metadata,
            'repair_anchor.sibling_kind',
            data_get($metadata, 'repair_anchor_sibling.kind', ''),
        );
        $repairSibling = $siblingKind !== '';
        $portfolioLane = (array) data_get($metadata, 'portfolio_council_lane', []);

        return (bool) data_get($metadata, 'repair_anchor.control_only', false)
            || in_array($siblingKind, ['frozen_control', 'control'], true)
            // A no-change constructor/control seat in a repair cohort is an
            // exhausted mutation lane, not the cohort's frozen control. Keep
            // it out of parity comparisons; the explicit frozen_control seat
            // is the only reference member for that cohort.
            || (! $repairSibling && (bool) data_get($metadata, 'mutation_constructor_invariant.control_only', false))
            || (! $repairSibling && (bool) data_get($metadata, 'g98_council_lane.control_only', false))
            || data_get($metadata, 'control_pair_contract.protocol') === self::CONTROL_PAIR_PROTOCOL
                && data_get($metadata, 'control_pair_contract.required_for_candidate') === false
            // Structural cohort niches are stored as an immutable
            // portfolio-council contract. Keep their frozen seats out of the
            // ordinary candidate set and make them visible to parity.
            || (bool) data_get($portfolioLane, 'control_only', false)
            || data_get($portfolioLane, 'structural_family') === 'frozen_control';
    }

    /** @return array<string, mixed> */
    private function controlRow(LabAgent $control): array
    {
        $run = LabEvaluationRun::query()
            ->where('lab_agent_id', $control->id)
            ->where('phase', 'screening')
            ->latest('id')
            ->first();
        $decision = CandidateGateDecision::query()
            ->where('lab_agent_id', $control->id)
            ->where('stage', 'screening')
            ->latest('id')
            ->first();
        $result = $this->result($control);
        $status = ! $run || $run->status !== 'completed' || $result === [] || ! $decision
            ? 'incomplete'
            : ($decision->decision === 'passed' ? 'passed' : 'failed');

        return [
            'agent_id' => (int) $control->id,
            'cohort_id' => $this->cohortId($control),
            'run_id' => $run?->run_id,
            'decision' => $decision?->decision,
            'status' => $status,
            'data_hash' => data_get($result, 'data_manifest.snapshot_sha256', data_get($result, 'data_manifest.sha256')),
            'execution_hash' => data_get($result, 'execution_contract.execution_hash', data_get($result, 'execution_hash')),
            'gate_margin' => $result !== [] ? $this->margins->screening($result, (array) ($decision?->reason_codes ?? [])) : null,
            'promotion_evidence' => false,
        ];
    }

    private function cohortId(LabAgent $agent): string
    {
        $metadata = (array) ($agent->modelVersion?->metadata ?? []);
        $pairKey = (string) data_get($metadata, 'control_pair_contract.pair_key', '');
        if ($pairKey !== '') return 'pair:'.$pairKey;
        $structural = (string) data_get($metadata, 'portfolio_council_lane.structural_cohort_id', data_get($metadata, 'structural_cohort_id', ''));
        if ($structural !== '') {
            // A structural cohort may contain hybrid and differential-router
            // seats. Pair only within the same executable family; otherwise a
            // control from one runtime could accidentally explain another.
            return $structural.'|family:'.(string) $agent->strategy_family;
        }

        return (string) data_get(
            $agent->modelVersion?->metadata,
            'repair_anchor.sibling_cohort_id',
            data_get($agent->modelVersion?->metadata, 'repair_anchor_sibling.cohort_id', 'generation:'.$agent->lab_generation_id),
        );
    }

    /** @return array<string, mixed> */
    private function result(LabAgent $agent): array
    {
        $result = (array) data_get($agent->modelVersion?->metadata, 'last_screen_result', []);
        if ($result !== []) return $result;

        $run = LabEvaluationRun::query()
            ->where('lab_agent_id', $agent->id)
            ->where('phase', 'screening')
            ->latest('id')
            ->first();

        return (array) ($run?->metrics ?? []);
    }
}
