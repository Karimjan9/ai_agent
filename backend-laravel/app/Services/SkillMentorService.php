<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\ModelMarketPerformance;
use Illuminate\Support\Facades\Schema;

/**
 * Maintains the middle evolutionary tier. A mentor owns a capability, not a
 * whole executable parent. It can suggest one compatible gene to a child,
 * but parent selection remains controlled by the ordinary passport frontier.
 */
class SkillMentorService
{
    public const PROTOCOL = 'skill_mentor_v1';

    public function markScreenValidatedSeed(LabAgent $agent, bool $passed, array $result = []): void
    {
        $agent->loadMissing('modelVersion');
        if (! $agent->modelVersion || ! $passed) {
            return;
        }
        $metadata = (array) $agent->modelVersion->metadata;
        $control = (bool) data_get($metadata, 'causal_experiment_lane.control_only', false)
            || in_array((string) data_get($metadata, 'repair_anchor.sibling_kind'), ['frozen_control', 'architecture_escape'], true)
            || in_array((string) data_get($metadata, 'repair_anchor_sibling.kind'), ['frozen_control', 'architecture_escape'], true);
        data_set($metadata, 'evolution_stage', [
            'protocol' => self::PROTOCOL,
            'stage' => $control ? 'screen_validated_control' : 'screen_validated_seed',
            'screening_passed' => true,
            'skill_mentor' => false,
            'full_parent' => false,
            'parent_eligible' => false,
            'screening_evidence_run_id' => data_get($result, 'evidence_run_id'),
            'updated_at' => now()->utc()->toIso8601String(),
            'promotion_evidence' => false,
        ]);
        data_set($metadata, 'screening_seed_only', true);
        $agent->modelVersion->update(['metadata' => $metadata]);
        app(ParentFoundryService::class)->recordSeed($agent->fresh(['modelVersion', 'generation']), $result);
    }

    public function recordFullReplayOutcome(
        LabAgent $agent,
        ModelMarketPerformance $performance,
        array $result,
        ?object $forwardDecision = null,
    ): array {
        $agent->loadMissing('modelVersion');
        if (! $agent->modelVersion) {
            return ['stage' => 'unknown', 'promotion_evidence' => false];
        }
        $metadata = (array) $agent->modelVersion->metadata;
        $learningLane = data_get($metadata, 'learning_lane.protocol') === LearningLaneService::PROTOCOL;
        $control = (bool) data_get($metadata, 'causal_experiment_lane.control_only', false)
            || in_array((string) data_get($metadata, 'repair_anchor.sibling_kind', data_get($metadata, 'repair_anchor_sibling.kind', '')), ['frozen_control', 'architecture_escape'], true);
        $changedGenes = array_keys((array) $agent->parameter_diff);
        $singleGeneCredit = count($changedGenes) === 1;
        $verification = (array) data_get($result, 'verified_mutation_skill', []);
        $mentorContract = app(CausalSkillCompilerService::class)->mentorContract([
            'independent_windows' => data_get($verification, 'independent_forward_windows.independent_windows', data_get($verification, 'required_windows', 0)),
            'positive_windows' => data_get($verification, 'independent_forward_windows.positive_windows', data_get($verification, 'minimum_positive_windows', 0)),
        ]);
        $skillConfirmed = $singleGeneCredit
            && data_get($verification, 'status') === 'confirmed'
            && data_get($mentorContract, 'status') === 'confirmed_shadow_mentor';
        $researchOnly = in_array((string) data_get($metadata, 'repair_anchor.sibling_kind', data_get($metadata, 'repair_anchor_sibling.kind', '')), ['frozen_control', 'architecture_escape'], true);
        $decisionPassed = $forwardDecision && data_get($forwardDecision, 'decision') === 'passed';
        $economicRequirements = [
            // Reaching this normal full-replay settlement is itself downstream
            // of screening. Learning-lane projections remain explicit because
            // they may be replayed independently of normal candidate admission.
            'screening_passed' => ! $learningLane
                || data_get($metadata, 'evolution_stage.screening_passed') === true
                || data_get($result, 'screening_gate_passed') === true,
            'full_replay_passed' => $performance->evidence_status === 'valid'
                && filled(data_get($result, 'evidence_run_id'))
                && ! (bool) data_get($result, 'is_overfit', true),
            'positive_absolute_settlement' => $this->positiveAbsoluteSettlement($result),
            'forward_or_paper_evidence' => $decisionPassed
                || in_array((string) $performance->status, ['forward_validated', 'paper', 'champion'], true),
            'source' => 'immutable_full_replay_and_forward_gate',
            'promotion_evidence' => false,
        ];
        $learningParentEligible = ! $learningLane || $this->learningParentEligible($metadata, $verification);
        $fullParent = ! $control && $decisionPassed && $learningParentEligible
            && $this->fullParentPassport($agent, $performance, $result);
        $stage = $researchOnly
            ? ((string) data_get($metadata, 'repair_anchor.sibling_kind', data_get($metadata, 'repair_anchor_sibling.kind', '')) === 'architecture_escape'
                ? 'architecture_escape'
                : 'screen_validated_control')
            : ($control ? 'screen_validated_control'
            : ($fullParent ? 'full_parent' : ($skillConfirmed ? 'skill_mentor' : 'full_replay_observed')));
        $gene = array_key_first((array) $agent->parameter_diff);
        $capsuleResolution = $skillConfirmed && is_string($gene)
            ? app(CanonicalSkillCartridgeService::class)->traitCapsuleForMentor(
                $agent->modelVersion,
                $agent,
                $gene,
            )
            : ['status' => 'not_applicable', 'valid' => false, 'promotion_evidence' => false];
        $capsuleReady = $skillConfirmed && (bool) data_get($capsuleResolution, 'valid', false);
        if ($stage === 'skill_mentor' && ! $capsuleReady) {
            $stage = 'skill_confirmed_capsule_pending';
        }
        $mentorStatus = $capsuleReady && in_array($stage, ['skill_mentor', 'full_parent'], true)
            ? 'confirmed'
            : ($skillConfirmed ? 'causal_capsule_pending' : 'not_confirmed');
        $mentor = [
            'protocol' => self::PROTOCOL,
            'status' => $mentorStatus,
            'stage' => $stage,
            'target' => data_get($metadata, 'repair_anchor.failure_target', data_get($metadata, 'generation_target')),
            'parameter_key' => $gene,
            'changed_genes' => array_keys((array) $agent->parameter_diff),
            'role' => data_get($metadata, 'council_specialist_contract.role', data_get($metadata, 'portfolio_council_lane.specialist_role')),
            'evidence_run_id' => data_get($result, 'evidence_run_id'),
            'forward_gate_decision' => $forwardDecision?->decision,
            'parent_eligible' => $fullParent,
            'learning_lane' => $learningLane,
            'mentor_contract' => $mentorContract,
            'authority_tier' => $skillConfirmed
                ? EvolutionaryAuthorityLadderService::RESEARCH_MENTOR
                : 'none',
            'economic_parent_requirements' => $economicRequirements,
            'trait_capsule' => (bool) data_get($capsuleResolution, 'valid', false)
                ? data_get($capsuleResolution, 'capsule')
                : null,
            'trait_capsule_resolution' => [
                'status' => data_get($capsuleResolution, 'status'),
                'valid' => (bool) data_get($capsuleResolution, 'valid', false),
                'reason_codes' => (array) data_get($capsuleResolution, 'reason_codes', []),
                'cartridge_id' => data_get($capsuleResolution, 'cartridge_id'),
                'cartridge_key' => data_get($capsuleResolution, 'cartridge_key'),
                'cartridge_revision' => data_get($capsuleResolution, 'cartridge_revision'),
                'promotion_evidence' => false,
            ],
            'shadow_only' => ! $fullParent,
            'promotion_evidence' => false,
        ];
        data_set($metadata, 'skill_mentor', $mentor);
        data_set($metadata, 'evolution_stage', [
            'protocol' => self::PROTOCOL,
            'stage' => $stage,
            'screening_passed' => ! $learningLane,
            'skill_mentor' => $stage === 'skill_mentor' && $capsuleReady,
            'full_parent' => $fullParent,
            'parent_eligible' => $fullParent,
            'updated_at' => now()->utc()->toIso8601String(),
            'promotion_evidence' => false,
        ]);
        if (in_array($stage, ['skill_mentor', 'skill_confirmed_capsule_pending'], true)) {
            data_set($metadata, 'screening_seed_only', true);
        } elseif ($fullParent) {
            data_set($metadata, 'screening_seed_only', false);
        }
        $agent->modelVersion->update(['metadata' => $metadata]);
        if (in_array($stage, ['skill_mentor', 'skill_confirmed_capsule_pending'], true)
            && in_array((string) $agent->lifecycle_status, ['challenger', 'forward_validated', 'paper', 'champion'], true)) {
            // A mentor may have a good economic replay but is not a global
            // parent yet. Keep operational status truthful while metadata
            // prevents parent selection from treating it as full parent.
            $agent->update(['decision_reason' => 'Verified skill mentor; full-parent passport is still required.']);
        }
        $mentor['parent_foundry'] = app(ParentFoundryService::class)->record(
            $agent->fresh(['modelVersion', 'generation']),
            $performance,
            $mentor,
        );
        // The historical Skill Mentor projection remains descriptive. The
        // Foundry ledger is the stricter prospective authority path and
        // requires incubator plus descendant proof before it can be used by
        // a new parent/paper admission.
        $mentor['evolutionary_authority'] = app(EvolutionaryAuthorityFoundryService::class)->refreshAuthority(
            $agent->modelVersion->fresh(),
            $agent->fresh(),
            [
                'passed' => $fullParent,
                'elite_passport' => data_get($result, 'elite_agent_passport.status'),
                'economic_parent_requirements' => $economicRequirements,
            ],
        );

        return $mentor;
    }

    /** @return array<string, mixed>|null */
    public function bestFor(string $symbol, string $timeframe, string $family, string $target, ?string $role = null): ?array
    {
        try {
            if (! Schema::hasTable('lab_mutation_response_maps')) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return app(MutationResponseMapService::class)->bestMentor($symbol, $timeframe, $family, $target, $role);
    }

    /** @return array<string, mixed> */
    public function frontier(string $symbol, string $timeframe, ?string $family = null): array
    {
        return app(MutationResponseMapService::class)->progress($symbol, $timeframe, $family);
    }

    private function fullParentPassport(LabAgent $agent, ModelMarketPerformance $performance, array $result): bool
    {
        $metrics = (array) $performance->metrics;
        $repair = (array) data_get($agent->modelVersion?->metadata, 'repair_anchor', []);
        if ($repair !== [] && data_get($repair, 'parent_eligible_after_confirmation') !== true) {
            return false;
        }

        return $performance->evidence_status === 'valid'
            && $agent->modelVersion?->evidence_status === 'valid'
            && in_array((string) $performance->status, ['forward_validated', 'paper', 'champion'], true)
            && (float) data_get($metrics, 'profit_factor', 0) >= 1.3
            && (float) data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown', 100)) <= 15
            && (float) data_get($metrics, 'monte_carlo.risk_of_ruin_percent', 100) <= 10
            && ! (bool) data_get($metrics, 'is_overfit', true)
            && (int) $performance->sample_count >= 30
            && (int) $performance->rolling_windows_count >= 3
            && (int) $performance->rolling_forward_wins >= 3
            && data_get($result, 'elite_agent_passport.status') === 'passed';
    }

    private function learningParentEligible(array $metadata, array $verification): bool
    {
        if (data_get($verification, 'status') !== 'confirmed') {
            return false;
        }
        $role = (string) data_get($metadata, 'causal_learning_cohort.role', '');
        if (in_array($role, ['blinded', 'frozen_control'], true)) {
            return false;
        }
        if (in_array($role, ['memory_guided', 'hypothesis_guided', 'repair_guided'], true)) {
            return data_get($metadata, 'causal_learning_experiment.status') === 'confirmed';
        }

        return true;
    }

    private function positiveAbsoluteSettlement(array $result): bool
    {
        foreach ([
            'net_profit_percent', 'net_profit', 'total_return_percent', 'total_return',
            'settlement.net_profit_percent', 'settlement.net_profit',
            'economic_settlement.net_value',
        ] as $path) {
            $value = data_get($result, $path);
            if (is_numeric($value)) {
                return (float) $value > 0;
            }
        }

        return false;
    }
}
