<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningSettlement;
use App\Models\CausalCapabilityEscrow;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use Illuminate\Support\Facades\Schema;

/** Preserves verified micro-wins without confusing them with viable parents. */
class CausalCapabilityLatticeService
{
    public const PROTOCOL = 'causal_capability_lattice_v1';

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function evaluate(array $facts): array
    {
        $componentChecks = [
            'post_v2_epoch' => data_get($facts, 'post_v2_epoch') === true,
            'exact_frozen_control' => data_get($facts, 'exact_frozen_control') === true,
            'intent_sealed_before_mutation' => data_get($facts, 'intent_sealed_before_mutation') === true,
            'beats_control' => data_get($facts, 'beats_control') === true,
            'beats_blinded' => data_get($facts, 'beats_blinded') === true,
            'declared_target_improved' => data_get($facts, 'declared_target_improved') === true,
            'independent_windows' => (int) data_get($facts, 'independent_windows', 0) >= 3,
            'positive_windows' => (int) data_get($facts, 'positive_windows', 0) >= 2,
            'hard_risk_safe' => data_get($facts, 'hard_risk_safe') === true,
            'non_target_corridor_safe' => data_get($facts, 'non_target_corridor_safe') === true,
            'intent_run_outcome_linked' => data_get($facts, 'intent_run_outcome_linked') === true,
            'context_and_gene_scoped' => data_get($facts, 'context_and_gene_scoped') === true,
            'context_scope_bound' => data_get(
                $facts,
                'context_scope_bound',
                data_get($facts, 'context_and_gene_scoped'),
            ) === true,
            'source_replay_context_match' => data_get(
                $facts,
                'source_replay_context_match',
                data_get($facts, 'context_and_gene_scoped'),
            ) === true,
        ];
        $componentConfirmed = ! in_array(false, $componentChecks, true);
        $proofCarried = data_get($facts, 'proof_carried') === true;
        $compositionEligible = $componentConfirmed && $proofCarried;
        $organismViable = $compositionEligible
            && data_get($facts, 'composition_screening_passed') === true
            && data_get($facts, 'composition_full_replay_passed') === true
            && (float) data_get($facts, 'composition_absolute_settlement', 0) > 0;
        $reproductiveAuthority = $organismViable
            && data_get($facts, 'forward_or_paper_evidence') === true
            && (int) data_get($facts, 'improving_descendants', 0) >= 2
            && data_get($facts, 'context_trust_confirmed') === true;
        $strongerChild = $reproductiveAuthority
            && data_get($facts, 'child_beats_parent') === true
            && data_get($facts, 'child_beats_frozen_control') === true;

        $state = match (true) {
            $strongerChild => 'stronger_child',
            $reproductiveAuthority => 'eligible_economic_parent',
            $organismViable => 'viable_component_composition',
            $compositionEligible => 'contextual_shadow_capability',
            $componentConfirmed => 'causally_confirmed_component',
            default => 'research_inbox',
        };

        return [
            'protocol' => self::PROTOCOL,
            'lattice_state' => $state,
            'component_confirmed' => $componentConfirmed,
            'composition_eligible' => $compositionEligible,
            'organism_viable' => $organismViable,
            'reproductive_authority' => $reproductiveAuthority,
            'stronger_child' => $strongerChild,
            'component_checks' => $componentChecks,
            'failed_component_checks' => array_keys(array_filter($componentChecks, fn (bool $passed): bool => ! $passed)),
            'absolute_viability_required_for_component' => false,
            'authority_ceiling' => $reproductiveAuthority ? 'economic_parent_candidate' : 'research_only',
            'paper_or_live_authority' => false,
            'promotion_evidence' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $componentEffect
     * @param  array<string,mixed>  $selectorEffect
     * @param  array<int,string>  $confirmationReasons
     * @return array<string,mixed>
     */
    public function projectExperiment(
        AgentLearningCausalExperiment $experiment,
        ?LabLearningLanePair $pair,
        ?AgentLearningSettlement $settlement,
        array $componentEffect,
        array $selectorEffect,
        array $confirmationReasons,
        bool $receiptValid,
        bool $nonTargetSafe,
    ): array {
        $generation = $experiment->lab_generation_id
            ? LabGeneration::query()->find($experiment->lab_generation_id)
            : null;
        $epoch = app(LearningProtocolEpochService::class)->epochFor($generation);
        $dataHash = trim((string) ($pair?->candidate_data_hash ?: $pair?->control_data_hash));
        $executionHash = trim((string) ($pair?->candidate_execution_hash ?: $pair?->control_execution_hash));
        $observedContext = (array) data_get(
            $pair?->metadata,
            'context_scope',
            data_get($pair?->failure_signature, 'state', data_get($experiment->evidence, 'context_scope', [])),
        );
        $sourceContext = (array) data_get(
            $experiment->evidence,
            'source_context_scope',
            data_get($experiment->evidence, 'context_scope', $observedContext),
        );
        $contextContract = app(ContextContractV2Service::class);
        $observedAxes = $contextContract->canonicalDeclaredAxes($observedContext);
        $sourceAxes = $contextContract->canonicalDeclaredAxes($sourceContext);
        $sourceReplayContextMatch = $sourceAxes !== [] && $observedAxes !== []
            && collect($sourceAxes)->every(
                fn (string $value, string $axis): bool => array_key_exists($axis, $observedAxes)
                    && hash_equals($value, (string) $observedAxes[$axis]),
            );
        $context = $observedAxes;
        $riskVetoes = collect((array) data_get($settlement?->reward_components, 'vetoes', []))
            ->map(fn ($reason): string => strtoupper(is_array($reason) ? (string) data_get($reason, 'code', '') : (string) $reason));
        $hardRiskSafe = ! $riskVetoes->contains(fn (string $reason): bool => str_contains($reason, 'DRAWDOWN')
            || str_contains($reason, 'TAIL') || str_contains($reason, 'RISK_OF_RUIN') || str_contains($reason, 'CATASTROPH'));
        $linked = $pair !== null
            && (int) $pair->candidate_agent_id === (int) $experiment->guided_agent_id
            && (int) $pair->control_agent_id === (int) $experiment->control_agent_id
            && $dataHash !== '' && $executionHash !== '';
        $facts = [
            'post_v2_epoch' => $epoch !== null,
            'exact_frozen_control' => $pair?->isVerifiedControlPair() === true,
            'intent_sealed_before_mutation' => $receiptValid,
            'beats_control' => data_get($componentEffect, 'passed') === true,
            'beats_blinded' => data_get($selectorEffect, 'passed') === true,
            'declared_target_improved' => data_get($componentEffect, 'target_effect.passed') === true
                && data_get($selectorEffect, 'target_effect.passed') === true,
            'independent_windows' => (int) data_get($componentEffect, 'common_window_count', 0),
            'positive_windows' => (int) data_get($componentEffect, 'positive_delta_windows', 0),
            'hard_risk_safe' => $hardRiskSafe,
            'non_target_corridor_safe' => $nonTargetSafe,
            'intent_run_outcome_linked' => $linked,
            'context_and_gene_scoped' => trim((string) $experiment->gene_key) !== '' && $observedAxes !== [],
            'context_scope_bound' => $sourceAxes !== [] && $observedAxes !== [],
            'source_replay_context_match' => $sourceReplayContextMatch,
            'proof_carried' => $linked && $receiptValid,
            // A single-gene confirmation never self-declares a viable organism.
            'composition_screening_passed' => false,
            'composition_full_replay_passed' => false,
            'composition_absolute_settlement' => 0,
        ];
        $evaluation = $this->evaluate($facts);
        $epochLink = app(LearningProtocolEpochService::class)->bindCausalChain(
            $experiment, $pair, $settlement, $receiptValid,
        );
        $evaluation['protocol_epoch_link'] = $epochLink;
        $evaluation['confirmation_reasons'] = array_values(array_unique($confirmationReasons));

        if ($epoch !== null && Schema::hasTable('causal_capability_escrows')) {
            $contextHash = hash('sha256', json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
            $proof = [
                'facts' => $facts,
                'evaluation' => $evaluation,
                'gene_or_program_hash' => hash('sha256', (string) $experiment->gene_key),
                'context_predicate' => $context,
                'source_context_predicate' => $sourceAxes,
                'source_replay_context_match' => $sourceReplayContextMatch,
                'data_hash' => $dataHash,
                'execution_hash' => $executionHash,
                'contraindications' => $riskVetoes->values()->all(),
                'absolute_settlement_state' => $settlement?->evidence_state,
                'absolute_viability_is_separate' => true,
                'promotion_evidence' => false,
            ];
            $row = CausalCapabilityEscrow::query()->updateOrCreate(
                ['escrow_key' => hash('sha256', implode('|', [self::PROTOCOL, $epoch, (string) $experiment->id]))],
                [
                    'agent_learning_causal_experiment_id' => $experiment->id,
                    'lab_learning_lane_pair_id' => $pair?->id,
                    'agent_learning_settlement_id' => $settlement?->id,
                    'protocol_epoch' => $epoch,
                    'symbol' => strtoupper($experiment->symbol),
                    'timeframe' => strtoupper($experiment->timeframe),
                    'strategy_family' => $experiment->strategy_family,
                    'target' => (string) ($experiment->target ?: 'unspecified_target_quarantine'),
                    'gene_key' => $experiment->gene_key,
                    'context_hash' => $contextHash,
                    'lattice_state' => $evaluation['lattice_state'],
                    'component_confirmed' => $evaluation['component_confirmed'],
                    'composition_eligible' => $evaluation['composition_eligible'],
                    'organism_viable' => false,
                    'reproductive_authority' => false,
                    'evidence' => $proof,
                    'evaluated_at' => now(),
                ],
            );
            $evaluation['escrow_id'] = (int) $row->id;
        }

        return $evaluation;
    }
}
