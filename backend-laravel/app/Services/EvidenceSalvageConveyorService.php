<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningLesson;
use App\Models\AgentLearningSettlement;
use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** Routes the closest-to-proof evidence before allowing unrelated exploration. */
class EvidenceSalvageConveyorService
{
    public const PROTOCOL = 'evidence_salvage_conveyor_v1';

    /**
     * A terminal generation cannot keep a lesson locked in an apparently
     * active replay forever. This repairs only the mutable experiment
     * projection; immutable runs, settlements and the generation remain.
     *
     * @return array<string,mixed>
     */
    public function reconcileTerminalTrialOwnership(string $symbol, string $timeframe, bool $apply): array
    {
        if (! Schema::hasTable('agent_learning_causal_experiments')) {
            return ['protocol' => self::PROTOCOL, 'status' => 'migration_pending', 'found' => 0,
                'invalidated' => 0, 'promotion_evidence' => false];
        }
        $rows = AgentLearningCausalExperiment::query()
            ->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))
            ->whereIn('status', ['awaiting_counterfactuals', 'ready_for_replay'])
            ->whereHas('generation', fn ($query) => $query->whereIn('status', [
                'technical_quarantine', 'failed', 'abandoned', 'completed', 'screened',
            ]))->with('generation')->get()
            ->filter(function (AgentLearningCausalExperiment $experiment): bool {
                if ((string) $experiment->generation?->status !== 'screened') {
                    return true;
                }
                $ids = array_values(array_unique(array_filter(array_map('intval', [
                    $experiment->guided_agent_id, $experiment->blinded_agent_id, $experiment->control_agent_id,
                ]))));

                // A complete screened triplet may legally move to the atomic
                // full-replay admission even when screening gates rejected an
                // arm. Only an incomplete triplet is terminalized here.
                return count($ids) !== 3;
            });
        $invalidated = 0;
        if ($apply) {
            foreach ($rows->groupBy('lab_generation_id') as $experiments) {
                $generation = $experiments->first()?->generation;
                if (! $generation) {
                    continue;
                }
                $invalidated += app(CausalLearningCohortService::class)->invalidateGeneration($generation, [
                    'TERMINAL_GENERATION_CANNOT_OWN_CAUSAL_REPLAY',
                    'FRESH_POST_V2_REPRODUCTION_REQUIRED',
                ]);
            }
        }

        return [
            'protocol' => self::PROTOCOL,
            'status' => $rows->isEmpty() ? 'nothing_to_reconcile' : ($apply ? 'terminal_trials_invalidated' : 'would_invalidate_terminal_trials'),
            'found' => $rows->count(),
            'invalidated' => $invalidated,
            'experiment_ids' => $rows->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'immutable_evidence_rewritten' => false,
            'next_action' => $rows->isEmpty() ? null : 'open_fresh_post_v2_triplet_after_terminalization',
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function planForLab(AiLaboratory $lab, int $limit = 20): array
    {
        return $this->plan($lab->symbol, $lab->timeframe, $limit);
    }

    /** @return array<string,mixed> */
    public function plan(string $symbol, string $timeframe, int $limit = 20): array
    {
        if (! Schema::hasTable('agent_learning_lessons') || ! Schema::hasTable('agent_learning_causal_experiments')) {
            return $this->empty('migration_pending');
        }
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        $experiments = AgentLearningCausalExperiment::query()
            ->where('symbol', $symbol)->where('timeframe', $timeframe)->get()->keyBy('id');
        $lessons = AgentLearningLesson::query()
            ->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->where('lesson_type', 'skill_lesson')->whereIn('status', ['provisional', 'confirmed'])
            ->where('outcome', 'beneficial')->whereNotNull('parameter_key')->get();
        $scopePairIds = LabLearningLanePair::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->pluck('id');
        $positivePairIds = $scopePairIds->isEmpty() ? collect() : AgentLearningSettlement::query()
            ->where('source_type', LabLearningLanePair::class)->whereIn('source_id', $scopePairIds)
            ->where('evidence_state', 'positive')->where('hard_failure', false)->pluck('source_id')->unique();
        // Full contract checks are relatively expensive. Always audit every
        // absolute-positive source, then a bounded recent provisional frontier.
        $candidates = $lessons->filter(fn (AgentLearningLesson $lesson): bool => $positivePairIds->contains(
            (int) data_get($lesson->evidence, 'pair_id', 0),
        ))->merge($lessons->sortByDesc(fn (AgentLearningLesson $lesson): int => (int) $lesson->id)->take(20))
            ->unique('id')->values();
        $allRanked = $this->rankLessons($candidates, $experiments);
        $selected = $allRanked->firstWhere('active_trial', true)
            ?: $allRanked->firstWhere('contract_complete', true);
        $ranked = $allRanked->take(max(1, min(100, $limit)))->values();
        if ($selected && ! $ranked->contains(fn (array $row): bool => (int) $row['lesson_id'] === (int) $selected['lesson_id'])) {
            $ranked->push($selected);
        }

        return [
            'protocol' => self::PROTOCOL,
            'status' => data_get($selected, 'active_trial') === true
                ? (data_get($selected, 'active_trial_is_post_v2') === true
                    ? 'existing_v2_trial_requires_settlement'
                    : 'legacy_trial_requires_settlement_before_fresh_v2')
                : ($selected ? 'fresh_v2_reproduction_required' : ($ranked->isNotEmpty()
                    ? 'signals_quarantined_pending_executable_contract' : 'no_salvageable_signal')),
            'selected' => $selected,
            'ranked' => $ranked->all(),
            'selection_order' => [
                'terminal_settlement', 'exact_v2_positive_evidence', 'near_confirmed_component',
                'legacy_positive_reproduction', 'interaction_composition', 'bounded_fresh_exploration',
            ],
            'legacy_authority_allowed' => false,
            'audited_signal_count' => $allRanked->count(),
            'quarantined_signal_count' => $allRanked->where('contract_complete', false)->count(),
            'next_experiment_rule' => 'guided_plus_blinded_plus_exact_frozen_control_under_current_post_v2_epoch',
            'promotion_evidence' => false,
        ];
    }

    /**
     * @param  Collection<int,AgentLearningLesson>  $lessons
     * @param  Collection<int,AgentLearningCausalExperiment>|null  $experiments
     * @return Collection<int,array<string,mixed>>
     */
    public function rankLessons(Collection $lessons, ?Collection $experiments = null): Collection
    {
        $experiments ??= collect();

        return $lessons->map(function (AgentLearningLesson $lesson) use ($experiments): array {
            $experimentId = (int) data_get($lesson->evidence, 'causal_experiment_id', 0);
            /** @var AgentLearningCausalExperiment|null $experiment */
            $experiment = $experiments->get($experimentId);
            $targetAligned = $experiment
                && data_get($experiment->evidence, 'component_effect.target_effect.passed') === true
                && data_get($experiment->evidence, 'selector_effect.target_effect.passed') === true;
            $genericWin = $experiment
                && data_get($experiment->evidence, 'component_effect.passed') === true
                && data_get($experiment->evidence, 'selector_effect.passed') === true;
            $postV2 = $experiment
                && (string) data_get($experiment->evidence, 'protocol_epoch') === LearningProtocolEpochService::CURRENT_EPOCH;
            $windows = max((int) $lesson->independent_window_count, (int) data_get($experiment?->evidence, 'component_effect.common_window_count', 0));
            $lowerBound = max(0.0, (float) ($lesson->lower_confidence_bound ?? 0));
            $repeatCount = $experiment ? max(0, (int) data_get($experiment->evidence, 'repair_lineage.depth', 0)) : 0;
            $gene = (string) $lesson->parameter_key;
            $admission = app(CausalLessonAdmissionService::class)->assess($lesson);
            $pairId = (int) data_get($admission, 'pair_id', 0);
            $value = data_get($admission, 'new_value');
            if ($value === null && $lesson->model_version_id) {
                $value = data_get($lesson->modelVersion?->parameters, $gene);
            }
            $contractChecks = (array) data_get($admission, 'reproduction_checks', []);
            $authorityChecks = (array) data_get($admission, 'authority_checks', []);
            $relatedAttempts = $experiments->filter(fn (AgentLearningCausalExperiment $row): bool => (int) data_get($row->evidence, 'source_pair_id', 0) === $pairId
                && (string) $row->gene_key === $gene
            );
            $activeAttempt = $relatedAttempts->first(fn (AgentLearningCausalExperiment $row): bool => in_array(
                (string) $row->status,
                ['awaiting_counterfactuals', 'ready_for_replay', 'outcomes_pending'],
                true,
            ));
            $activeGeneration = $activeAttempt?->lab_generation_id
                ? LabGeneration::query()->find($activeAttempt->lab_generation_id)
                : null;
            $activePostV2 = $activeAttempt !== null
                && app(LearningProtocolEpochService::class)->epochFor($activeGeneration) !== null;
            $currentAttempts = $relatedAttempts->filter(fn (AgentLearningCausalExperiment $row): bool => data_get($row->evidence, 'confirmation_evidence_protocol') === CausalLearningConfirmationService::EVIDENCE_PROTOCOL
            );
            $alreadyConfirmed = $currentAttempts->contains(fn (AgentLearningCausalExperiment $row): bool => (string) $row->status === 'confirmed');
            $retryAvailable = $currentAttempts->count() < max(1, (int) config('services.learning_lane.confirmation_max_attempts', 3));
            $contractChecks['no_active_duplicate'] = $activeAttempt === null;
            $contractChecks['not_already_confirmed'] = ! $alreadyConfirmed;
            $contractChecks['retry_budget_available'] = $retryAvailable;
            $attemptBlockers = array_keys(array_filter([
                'no_active_duplicate' => $activeAttempt === null,
                'not_already_confirmed' => ! $alreadyConfirmed,
                'retry_budget_available' => $retryAvailable,
            ], fn (bool $passed): bool => ! $passed));
            $reproductionBlockers = array_values(array_unique([
                ...((array) data_get($admission, 'blockers', [])),
                ...$attemptBlockers,
            ]));
            $sourceReady = data_get($admission, 'source_ready') === true;
            $contractComplete = $sourceReady && $activeAttempt === null && ! $alreadyConfirmed && $retryAvailable;
            $informationGain = $targetAligned ? .95 : ($genericWin ? .80 : .60);
            $probabilityOfClosure = min(.98, .35 + (.10 * min(4, $windows)) + ($targetAligned ? .20 : 0) + ($lowerBound > 0 ? .10 : 0));
            $targetGapReduction = $targetAligned ? 1.0 : ($genericWin ? .72 : .45);
            $contextRelevance = filled($lesson->regime) || filled($lesson->state_cluster_id) ? 1.0 : .75;
            $computeCost = 1.0;
            $priority = ($informationGain * $probabilityOfClosure * $targetGapReduction * $contextRelevance)
                / $computeCost / (1 + $repeatCount);

            return [
                'lesson_id' => (int) $lesson->id,
                'source_experiment_id' => $experimentId ?: $activeAttempt?->id,
                'source_pair_id' => $pairId ?: null,
                'strategy_family' => (string) $lesson->strategy_family,
                'target' => (string) ($lesson->failure_class ?: 'causal_learning'),
                'gene_key' => $gene,
                'gene_value' => $value,
                'context_scope' => array_filter([
                    'regime' => $lesson->regime,
                    'volatility' => $lesson->volatility,
                    'transition_state' => $lesson->transition_state,
                    'spread_liquidity_state' => $lesson->spread_liquidity_state,
                ], fn ($value): bool => filled($value)),
                'evidence_class' => $postV2 && $targetAligned
                    ? 'exact_v2_positive_evidence'
                    : ($targetAligned ? 'target_aligned_pre_epoch_reproduction' : ($genericWin ? 'legacy_positive_reproduction' : 'provisional_signal_reproduction')),
                'priority' => round($priority, 8),
                'contract_complete' => $contractComplete,
                'contract_status' => $activeAttempt
                    ? 'existing_trial_owns_signal'
                    : ($contractComplete ? 'ready_for_fresh_v2_reproduction' : 'quarantined_incomplete_contract'),
                'contract_checks' => $contractChecks,
                'authority_checks' => $authorityChecks,
                'contract_blockers' => $reproductionBlockers,
                'reproduction_blockers' => $reproductionBlockers,
                'authority_blockers' => (array) data_get($admission, 'authority_blockers', []),
                'source_authority' => (string) data_get($admission, 'source_authority', 'quarantined'),
                'legacy_hypothesis_grants_credit' => false,
                'active_trial' => $activeAttempt !== null,
                'active_trial_is_post_v2' => $activePostV2,
                'active_trial_experiment_id' => $activeAttempt?->id,
                'active_trial_experiment_status' => $activeAttempt?->status,
                'priority_factors' => [
                    'expected_information_gain' => $informationGain,
                    'probability_of_closing_next_gate' => $probabilityOfClosure,
                    'target_gap_reduction' => $targetGapReduction,
                    'context_relevance' => $contextRelevance,
                    'compute_cost' => $computeCost,
                    'repeated_failure_count' => $repeatCount,
                ],
                'next_experiment' => $activeAttempt
                    ? ($activePostV2 ? 'resume_existing_v2_trial' : 'settle_legacy_trial_then_open_fresh_v2_triplet')
                    : 'fresh_v2_causal_triplet',
                'exact_control_required' => true,
                'authority_allowed' => false,
                'selected_before_mutation' => true,
                'promotion_evidence' => false,
            ];
        })->sortByDesc('priority')->values();
    }

    /** @return array<string,mixed> */
    private function empty(string $status): array
    {
        return ['protocol' => self::PROTOCOL, 'status' => $status, 'selected' => null,
            'ranked' => [], 'legacy_authority_allowed' => false, 'promotion_evidence' => false];
    }
}
