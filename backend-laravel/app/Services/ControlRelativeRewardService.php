<?php

namespace App\Services;

use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;

/** Computes competitive learning reward against the same-cohort frozen seat. */
class ControlRelativeRewardService
{
    public const PROTOCOL = 'control_relative_reward_v1';

    public function __construct(private GateMarginService $margins, private FrozenControlParityService $controls) {}

    /** @return array<string, mixed> */
    public function assess(LabAgent $agent, array $candidate, array $observability = []): array
    {
        $agent->loadMissing('modelVersion', 'generation.agents.modelVersion');
        $generationContext = (array) ($agent->generation?->trigger_context ?? []);
        $generationMode = (string) data_get(
            $generationContext,
            'research_allocation_budget.mode',
            data_get($generationContext, 'control_pairing_contract.mode', ''),
        );
        $normalPairingAllowed = $generationMode !== 'normal_research'
            || (bool) data_get($generationContext, 'control_pairing_contract.allowed', false);
        $controls = $agent->generation?->agents?->filter(fn (LabAgent $member): bool =>
            (int) $member->id !== (int) $agent->id
            && $this->controls->isControl($member)
            && $this->sameCohort($agent, $member)
            && $this->usesVolumeResearch($agent) === $this->usesVolumeResearch($member)
        ) ?? collect();
        $control = $controls->first();
        $anchorDelta = data_get($observability, 'gate_margin.normalized_delta');
        $base = [
            'protocol' => self::PROTOCOL,
            'control_agent_id' => $control?->id,
            'anchor_delta' => is_numeric($anchorDelta) ? (float) $anchorDelta : null,
            'control_delta' => null,
            'non_target_regression' => $this->nonTargetRegression($candidate),
            'holdout_confirmation' => $this->holdoutConfirmation($candidate),
            'control_relative_improved' => false,
            'interpretation_allowed' => false,
            'generation_pairing_allowed' => $normalPairingAllowed,
            'status' => ! $normalPairingAllowed
                ? 'generation_pairing_contract_incomplete'
                : ($control ? 'control_evidence_missing' : 'control_missing'),
            'promotion_evidence' => false,
        ];
        if (! $normalPairingAllowed || ! $control) return $base;

        $controlResult = $this->result($control);
        if ($controlResult === []) return $base;
        $target = (string) data_get($observability, 'target', data_get($agent->modelVersion?->metadata, 'generation_target', 'profit_factor'));
        $comparison = $this->margins->compare($candidate, $controlResult, $target);
        $controlDelta = data_get($comparison, 'margin_delta');
        $sameContract = data_get($comparison, 'same_data_hash') === true && data_get($comparison, 'same_execution_hash') === true;
        $nonTargetSafe = $base['non_target_regression']['safe'];
        $improved = $sameContract && data_get($comparison, 'candidate_better') === true && $nonTargetSafe;

        return [
            ...$base,
            'target' => $target,
            'control_delta' => is_numeric($controlDelta) ? (float) $controlDelta : null,
            'candidate_observation' => data_get($comparison, 'candidate_observation'),
            'control_observation' => data_get($comparison, 'control_observation'),
            'comparison' => $comparison,
            'same_contract' => $sameContract,
            'control_relative_improved' => $improved,
            'interpretation_allowed' => $sameContract,
            'status' => $sameContract ? ($improved ? 'competitive_progress' : 'control_comparable') : 'control_contract_mismatch',
            'promotion_evidence' => false,
        ];
    }

    /** @return array{status:string,safe:bool} */
    private function nonTargetRegression(array $candidate): array
    {
        $status = (string) data_get($candidate, 'differential_no_regression.status', data_get($candidate, 'no_regression_contract.status', 'not_recorded'));
        return ['status' => $status, 'safe' => in_array($status, ['', 'not_recorded', 'not_applicable', 'passed', 'confirmed'], true)];
    }

    /** @return array{status:string,confirmed:bool} */
    private function holdoutConfirmation(array $candidate): array
    {
        foreach (['holdout_confirmation', 'independent_holdout', 'forward_confirmation', 'verified_mutation_skill'] as $path) {
            $status = (string) data_get($candidate, $path.'.status', '');
            if (in_array($status, ['passed', 'confirmed', 'valid'], true)) return ['status' => $status, 'confirmed' => true];
        }
        return ['status' => 'not_confirmed', 'confirmed' => false];
    }

    private function sameCohort(LabAgent $left, LabAgent $right): bool
    {
        $leftPair = (string) data_get($left->modelVersion?->metadata, 'control_pair_contract.pair_key', '');
        $rightPair = (string) data_get($right->modelVersion?->metadata, 'control_pair_contract.pair_key', '');
        if ($leftPair !== '' || $rightPair !== '') {
            // A normal-generation candidate may only consume the exact
            // frozen control reserved for its family/lane. A missing pair key
            // is not interchangeable with a generation-wide fallback.
            return $leftPair !== '' && $leftPair === $rightPair;
        }
        $leftStructural = (string) data_get($left->modelVersion?->metadata, 'portfolio_council_lane.structural_cohort_id', '');
        $rightStructural = (string) data_get($right->modelVersion?->metadata, 'portfolio_council_lane.structural_cohort_id', '');
        if ($leftStructural !== '' || $rightStructural !== '') {
            return $leftStructural !== ''
                && $leftStructural === $rightStructural
                && (string) $left->strategy_family === (string) $right->strategy_family;
        }
        $leftCohort = (string) data_get($left->modelVersion?->metadata, 'repair_anchor.sibling_cohort_id', data_get($left->modelVersion?->metadata, 'repair_anchor_sibling.cohort_id', 'generation:'.$left->lab_generation_id));
        $rightCohort = (string) data_get($right->modelVersion?->metadata, 'repair_anchor.sibling_cohort_id', data_get($right->modelVersion?->metadata, 'repair_anchor_sibling.cohort_id', 'generation:'.$right->lab_generation_id));
        return $leftCohort !== '' && $leftCohort === $rightCohort;
    }

    private function result(LabAgent $agent): array
    {
        $result = (array) data_get($agent->modelVersion?->metadata, 'last_screen_result', []);
        if ($result !== []) return $result;
        return (array) LabEvaluationRun::query()->where('lab_agent_id', $agent->id)->where('phase', 'screening')->latest('id')->first()?->metrics;
    }

    private function usesVolumeResearch(LabAgent $agent): bool
    {
        $metadata = (array) ($agent->modelVersion?->metadata ?? []);
        $parameters = (array) ($agent->modelVersion?->parameters ?? []);

        return (bool) data_get($metadata, 'volume_research_contract.enabled', false)
            || data_get($metadata, 'volume_research_contract.protocol') === 'volume_council_v1'
            || (bool) data_get($metadata, 'portfolio_council_lane.volume_shadow', false)
            || (bool) data_get($metadata, 'risk_bounded_evolution.volume_shadow', false)
            || data_get($metadata, 'portfolio_council_lane.role') === 'volume_m15_specialist'
            || data_get($metadata, 'portfolio_council_lane.specialist_role') === 'volume_m15_specialist'
            || (string) data_get($parameters, 'volume_lane', 'none') !== 'none';
    }
}
