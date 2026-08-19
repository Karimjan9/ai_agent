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
        if (! $agent->modelVersion || ! $passed) return;
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
    }

    public function recordFullReplayOutcome(
        LabAgent $agent,
        ModelMarketPerformance $performance,
        array $result,
        ?object $forwardDecision = null,
    ): array {
        $agent->loadMissing('modelVersion');
        if (! $agent->modelVersion) return ['stage' => 'unknown', 'promotion_evidence' => false];
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
        $fullParent = ! $control && $decisionPassed && $this->fullParentPassport($agent, $performance, $result);
        $stage = $researchOnly
            ? ((string) data_get($metadata, 'repair_anchor.sibling_kind', data_get($metadata, 'repair_anchor_sibling.kind', '')) === 'architecture_escape'
                ? 'architecture_escape'
                : 'screen_validated_control')
            : ($control ? 'screen_validated_control'
            : ($fullParent ? 'full_parent' : ($skillConfirmed ? 'skill_mentor' : 'full_replay_observed')));
        $gene = array_key_first((array) $agent->parameter_diff);
        $mentor = [
            'protocol' => self::PROTOCOL,
            'status' => $stage === 'skill_mentor' || $stage === 'full_parent' ? 'confirmed' : 'not_confirmed',
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
            'shadow_only' => ! $fullParent,
            'promotion_evidence' => false,
        ];
        data_set($metadata, 'skill_mentor', $mentor);
        data_set($metadata, 'evolution_stage', [
            'protocol' => self::PROTOCOL,
            'stage' => $stage,
            'screening_passed' => ! $learningLane,
            'skill_mentor' => $stage === 'skill_mentor',
            'full_parent' => $fullParent,
            'parent_eligible' => $fullParent,
            'updated_at' => now()->utc()->toIso8601String(),
            'promotion_evidence' => false,
        ]);
        if ($stage === 'skill_mentor') {
            data_set($metadata, 'screening_seed_only', true);
        } elseif ($fullParent) {
            data_set($metadata, 'screening_seed_only', false);
        }
        $agent->modelVersion->update(['metadata' => $metadata]);
        if ($stage === 'skill_mentor' && in_array((string) $agent->lifecycle_status, ['challenger', 'forward_validated', 'paper', 'champion'], true)) {
            // A mentor may have a good economic replay but is not a global
            // parent yet. Keep operational status truthful while metadata
            // prevents parent selection from treating it as full parent.
            $agent->update(['decision_reason' => 'Verified skill mentor; full-parent passport is still required.']);
        }
        return $mentor;
    }

    /** @return array<string, mixed>|null */
    public function bestFor(string $symbol, string $timeframe, string $family, string $target, ?string $role = null): ?array
    {
        try {
            if (! Schema::hasTable('lab_mutation_response_maps')) return null;
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
        if ($repair !== [] && data_get($repair, 'parent_eligible_after_confirmation') !== true) return false;
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
}
