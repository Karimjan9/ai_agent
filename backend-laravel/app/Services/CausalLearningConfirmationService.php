<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningLesson;
use App\Models\AgentLearningMutationIntent;
use App\Models\AgentLearningPolicy;
use App\Models\LabAgent;
use App\Models\LabLearningLanePair;
use App\Models\ModelMarketPerformance;
use Illuminate\Support\Facades\Schema;

/** Confirms memory only when it beats both blinded mutation and frozen control. */
class CausalLearningConfirmationService
{
    /** @return array<string, mixed> */
    public function recordOutcome(
        LabAgent $agent,
        LabLearningLanePair $pair,
        array $result,
        array $delta,
        ?AgentLearningLesson $lesson = null,
        ?ModelMarketPerformance $performance = null,
        ?object $forwardDecision = null,
    ): array {
        if (! Schema::hasTable('agent_learning_causal_experiments')) {
            return ['status' => 'unavailable', 'confirmed' => false, 'promotion_evidence' => false];
        }
        $experiment = AgentLearningCausalExperiment::query()
            ->where('guided_agent_id', $agent->id)
            ->orWhere('blinded_agent_id', $agent->id)
            ->orWhere('control_agent_id', $agent->id)
            ->first();
        if (! $experiment) {
            return ['status' => 'not_applicable', 'confirmed' => false, 'promotion_evidence' => false];
        }
        $role = match ((int) $agent->id) {
            (int) $experiment->guided_agent_id => 'memory_guided',
            (int) $experiment->blinded_agent_id => 'blinded',
            default => 'frozen_control',
        };
        $windows = $this->windows($pair, $result);
        $evidence = (array) $experiment->evidence;
        $evidence['outcomes'][$role] = [
            'agent_id' => (int) $agent->id,
            'pair_id' => (int) $pair->id,
            'lesson_id' => $lesson?->id,
            'control_agent_id' => $pair->control_agent_id,
            'verified_control' => $pair->isVerifiedControlPair(),
            'target_delta' => $delta,
            'utility' => $this->utility((string) $pair->target, $delta),
            'independent_window_keys' => $windows['keys'],
            'independent_window_count' => $windows['count'],
            'independence_verified' => $windows['verified'],
            'positive_windows' => $windows['positive'],
            'evidence_run_id' => data_get($result, 'evidence_run_id'),
            'performance_id' => $performance?->id,
            'forward_decision' => data_get($forwardDecision, 'decision'),
            'elite_agent_passport_status' => data_get($result, 'elite_agent_passport.status'),
            'recorded_at' => now()->utc()->toIso8601String(),
            'promotion_evidence' => false,
        ];
        $experiment->update(['evidence' => $evidence, 'status' => 'outcomes_pending']);
        $experiment = $experiment->fresh();
        $guided = (array) data_get($experiment->evidence, 'outcomes.memory_guided', []);
        $blinded = (array) data_get($experiment->evidence, 'outcomes.blinded', []);
        if ($guided === [] || $blinded === []) {
            return [
                'status' => 'awaiting_counterfactual_outcomes',
                'confirmed' => false,
                'experiment_id' => (int) $experiment->id,
                'promotion_evidence' => false,
            ];
        }
        $required = max(2, (int) config('services.learning_lane.independent_confirmations_required', 2));
        $guidedIntent = AgentLearningMutationIntent::query()->where('lab_agent_id', $experiment->guided_agent_id)->first();
        $guidedAgent = LabAgent::query()->with('modelVersion')->find($experiment->guided_agent_id);
        $receiptValid = data_get($guidedAgent?->modelVersion?->metadata, 'learning_receipt.integrity.valid') === true
            && data_get($guidedAgent?->modelVersion?->metadata, 'learning_receipt.status') === 'provisional';
        $guidedUtility = (float) data_get($guided, 'utility', 0);
        $blindedUtility = (float) data_get($blinded, 'utility', 0);
        $guidedBeatsControl = (bool) data_get($guided, 'target_delta.improved', false) && $guidedUtility > 0;
        $guidedBeatsBlinded = $guidedUtility > $blindedUtility;
        $reasons = [];
        if ((string) $experiment->status === 'invalid_counterfactual_contract'
            || data_get($experiment->evidence, 'construction_validation.status') !== 'ready_for_replay') {
            $reasons[] = 'COUNTERFACTUAL_CONSTRUCTION_INVALID';
        }
        if ($guidedIntent?->influence_type !== 'memory_guided'
            || (array) $guidedIntent?->causally_applied_lesson_ids === []) {
            $reasons[] = 'GUIDED_MEMORY_NOT_CAUSALLY_APPLIED';
        }
        if (! $receiptValid) {
            $reasons[] = 'GUIDED_RECEIPT_INVALID';
        }
        if (data_get($guided, 'verified_control') !== true
            || (int) data_get($guided, 'control_agent_id') !== (int) $experiment->control_agent_id) {
            $reasons[] = 'FROZEN_CONTROL_MISMATCH';
        }
        if (data_get($blinded, 'verified_control') !== true
            || (int) data_get($blinded, 'control_agent_id') !== (int) $experiment->control_agent_id) {
            $reasons[] = 'BLINDED_CONTROL_MISMATCH';
        }
        if (! $guidedBeatsControl) {
            $reasons[] = 'GUIDED_DID_NOT_BEAT_CONTROL';
        }
        if (! $guidedBeatsBlinded) {
            $reasons[] = 'GUIDED_DID_NOT_BEAT_BLINDED';
        }
        if (data_get($guided, 'independence_verified') !== true
            || (int) data_get($guided, 'independent_window_count', 0) < $required
            || (int) data_get($guided, 'positive_windows', 0) < $required) {
            $reasons[] = 'INDEPENDENT_WINDOWS_INSUFFICIENT';
        }
        if ($reasons !== []) {
            $experiment->update([
                'status' => 'provisional',
                'independent_window_count' => (int) data_get($guided, 'independent_window_count', 0),
                'guided_beats_blinded' => $guidedBeatsBlinded,
                'guided_beats_control' => $guidedBeatsControl,
                'evidence' => [...((array) $experiment->evidence), 'confirmation_blockers' => $reasons, 'promotion_evidence' => false],
            ]);

            return ['status' => 'provisional', 'confirmed' => false, 'reason_codes' => $reasons, 'promotion_evidence' => false];
        }
        $experiment->update([
            'status' => 'confirmed',
            'independent_window_count' => (int) data_get($guided, 'independent_window_count', 0),
            'guided_beats_blinded' => true,
            'guided_beats_control' => true,
            'confirmed_at' => now(),
            'evidence' => [...((array) $experiment->evidence),
                'confirmation_protocol' => 'memory_guided_vs_blinded_vs_frozen_control_v1',
                'confirmation_blockers' => [],
                'promotion_evidence' => false,
            ],
        ]);
        $guidedPairId = (int) data_get($guided, 'pair_id', 0);
        $guidedLesson = AgentLearningLesson::query()
            ->where('lab_agent_id', $experiment->guided_agent_id)
            ->where('parameter_key', $experiment->gene_key)
            ->where('lesson_type', 'skill_lesson')
            ->where(fn ($query) => $query->where('evidence->pair_id', $guidedPairId)->orWhere('id', data_get($guided, 'lesson_id')))
            ->latest('id')
            ->first();
        $guidedLesson?->update([
            'status' => 'confirmed',
            'independent_window_count' => (int) data_get($guided, 'independent_window_count', 0),
            'confirmation_count' => $required,
            'evidence' => [...((array) $guidedLesson?->evidence),
                'causal_experiment_id' => (int) $experiment->id,
                'confirmation_protocol' => 'memory_guided_vs_blinded_vs_frozen_control_v1',
                'confirmed_at' => now()->utc()->toIso8601String(),
                'promotion_evidence' => false,
            ],
            'expires_at' => null,
        ]);
        if ($guidedPairId > 0) {
            $guidedPair = LabLearningLanePair::query()->find($guidedPairId);
            $guidedPair?->update([
                'status' => 'skill_confirmed',
                'metadata' => [...((array) $guidedPair?->metadata),
                    'skill_state' => 'confirmed',
                    'causal_experiment_id' => (int) $experiment->id,
                    'promotion_evidence' => false,
                ],
            ]);
        }
        $guidedIntent?->update(['status' => 'settled', 'metadata' => [
            ...((array) $guidedIntent?->metadata),
            'causal_experiment_id' => (int) $experiment->id,
            'causal_confirmation' => 'confirmed',
            'promotion_evidence' => false,
        ]]);
        if ($guidedAgent?->modelVersion) {
            $guidedMetadata = (array) $guidedAgent->modelVersion->metadata;
            $guidedMetadata['causal_learning_experiment'] = [
                'protocol' => 'memory_guided_vs_blinded_vs_frozen_control_v1',
                'experiment_id' => (int) $experiment->id,
                'status' => 'confirmed',
                'guided_beats_blinded' => true,
                'guided_beats_control' => true,
                'independent_window_count' => (int) $experiment->independent_window_count,
                'source_lesson_id' => $experiment->source_lesson_id,
                'confirmed_at' => $experiment->confirmed_at?->toIso8601String(),
                'promotion_evidence' => false,
            ];
            $guidedAgent->modelVersion->update(['metadata' => $guidedMetadata]);
        }
        $mentor = $this->projectConfirmedGuidedMentor(
            $guidedAgent,
            $guided,
            $required,
        );
        if ($mentor !== null) {
            $experiment->update(['evidence' => [
                ...((array) $experiment->fresh()->evidence),
                'confirmed_guided_mentor' => $mentor,
                'promotion_evidence' => false,
            ]]);
        }
        $policy = app(LearningPolicyRegistryService::class)->register(
            'causal-memory:'.strtoupper($experiment->symbol).':'.strtoupper($experiment->timeframe).':'.$experiment->strategy_family,
            [
                'protocol' => 'causal_memory_policy_v1',
                'gene' => $experiment->gene_key,
                'value' => data_get($guidedIntent?->new_value, 'value'),
                'source_lesson_id' => $experiment->source_lesson_id,
                'causal_experiment_id' => (int) $experiment->id,
                'selection_rule' => 'Use only in compatible contexts; preserve blinded/control evidence and all ordinary gates.',
                'promotion_evidence' => false,
            ],
            [
                'symbol' => $experiment->symbol,
                'timeframe' => $experiment->timeframe,
                'strategy_family' => $experiment->strategy_family,
            ],
        );
        if ($policy instanceof AgentLearningPolicy) {
            $policy = app(LearningPolicyRegistryService::class)->transition($policy, 'shadow', [
                'causal_experiment_id' => (int) $experiment->id,
                'confirmed_lesson_id' => $guidedLesson?->id,
                'promotion_evidence' => false,
            ]);
        }

        return [
            'status' => 'confirmed',
            'confirmed' => true,
            'experiment_id' => (int) $experiment->id,
            'guided_lesson_id' => $guidedLesson?->id,
            'policy_id' => $policy instanceof AgentLearningPolicy ? (int) $policy->id : null,
            'mentor' => $mentor,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed>|null */
    private function projectConfirmedGuidedMentor(?LabAgent $agent, array $guided, int $required): ?array
    {
        if (! $agent || (int) data_get($guided, 'performance_id', 0) <= 0) {
            return null;
        }
        $performance = ModelMarketPerformance::query()->find((int) data_get($guided, 'performance_id'));
        if (! $performance || (int) $performance->model_version_id !== (int) $agent->model_version_id) {
            return null;
        }
        $windows = (int) data_get($guided, 'independent_window_count', 0);
        $positive = (int) data_get($guided, 'positive_windows', 0);
        $result = [
            'evidence_run_id' => data_get($guided, 'evidence_run_id'),
            'elite_agent_passport' => ['status' => data_get($guided, 'elite_agent_passport_status')],
            'verified_mutation_skill' => [
                'protocol' => 'learning_lane_independent_skill_v1',
                'status' => 'confirmed',
                'independent_observation_count' => $windows,
                'independent_confirmations_required' => $required,
                'required_windows' => $windows,
                'minimum_positive_windows' => $positive,
                'independent_forward_windows' => [
                    'independent_windows' => $windows,
                    'positive_windows' => $positive,
                ],
                'promotion_evidence' => false,
            ],
        ];
        $forward = filled(data_get($guided, 'forward_decision'))
            ? (object) ['decision' => (string) data_get($guided, 'forward_decision')]
            : null;

        return app(SkillMentorService::class)->recordFullReplayOutcome(
            $agent->fresh(['modelVersion', 'generation']),
            $performance->fresh(),
            $result,
            $forward,
        );
    }

    /** @return array{keys: array<int, string>, count: int, verified: bool, positive: int} */
    private function windows(LabLearningLanePair $pair, array $result): array
    {
        $protocol = (array) data_get($result, 'forward_window_protocol', []);
        $keys = collect([$pair->independent_window_key, data_get($protocol, 'window_key')])
            ->merge(collect((array) data_get($protocol, 'windows', []))->map(
                fn ($row) => data_get($row, 'window_key', data_get($row, 'key')),
            ))->filter()->map('strval')->unique()->values();
        $observed = (int) data_get($protocol, 'observed_windows', 0);

        return [
            'keys' => $keys->all(),
            'count' => max($keys->count(), $observed),
            'verified' => data_get($protocol, 'independence_verified') === true
                && data_get($protocol, 'overlap_detected') !== true,
            'positive' => max((int) data_get($protocol, 'positive_windows', 0), (int) data_get($protocol, 'confirmed_windows', 0)),
        ];
    }

    private function utility(string $target, array $delta): float
    {
        $value = (float) data_get($delta, 'delta', 0);

        return in_array(strtolower($target), ['drawdown', 'drawdown_risk', 'max_drawdown', 'risk'], true)
            ? -$value
            : $value;
    }
}
