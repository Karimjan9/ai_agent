<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningLesson;
use App\Models\AgentLearningSettlement;
use App\Models\InstrumentValuePosterior;
use App\Models\LabAgent;
use App\Models\LabLearningLanePair;
use App\Models\PlaybookValuePosterior;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Separates diagnostic volume, causal uplift and inheritable learning. */
class LearningIntelligenceAuditService
{
    public const PROTOCOL = 'learning_intelligence_audit_v2';

    /** @return array<string,mixed> */
    public function snapshot(string $symbol, string $timeframe, int $settlementWindow = 90): array
    {
        $tables = [
            'agent_learning_settlements', 'agent_learning_episodes',
            'agent_learning_causal_experiments', 'agent_learning_lessons',
            'lab_learning_lane_pairs',
        ];
        if (! collect($tables)->every(fn (string $table): bool => Schema::hasTable($table))) {
            return [
                'protocol' => self::PROTOCOL,
                'available' => false,
                'reason_code' => 'LEARNING_TABLES_NOT_READY',
                'promotion_evidence' => false,
            ];
        }

        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        $recent = AgentLearningSettlement::query()
            ->whereHas('episode', fn ($query) => $query
                ->where('symbol', $symbol)
                ->where('timeframe', $timeframe))
            ->latest('id')
            ->limit(max(1, min(500, $settlementWindow)))
            ->get();
        $screening = $recent->where('source_type', LabAgent::class)->values();
        $canonicalRecent = $recent->where('source_type', LabLearningLanePair::class)->values();
        $canonical = AgentLearningSettlement::query()
            ->where('source_type', LabLearningLanePair::class)
            ->whereHas('episode', fn ($query) => $query
                ->where('symbol', $symbol)
                ->where('timeframe', $timeframe))
            ->get();
        $experiments = AgentLearningCausalExperiment::query()
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->get();
        $legacyOrGenericWinners = $experiments->filter(fn (AgentLearningCausalExperiment $row): bool => $row->guided_beats_control && $row->guided_beats_blinded
            && data_get($row->evidence, 'component_effect.passed') === true
            && data_get($row->evidence, 'selector_effect.passed') === true
        );
        $targetAlignedWinners = $legacyOrGenericWinners->filter(
            fn (AgentLearningCausalExperiment $row): bool => data_get($row->evidence, 'component_effect.target_effect.passed') === true
                && data_get($row->evidence, 'selector_effect.target_effect.passed') === true
        );
        $ratchetEligible = $experiments->filter(fn (AgentLearningCausalExperiment $row): bool => data_get($row->evidence, 'repair_frontier.research_ratchet.allowed') === true
        );
        $ratchetBlocked = $experiments->map(fn (AgentLearningCausalExperiment $row) => (array) data_get($row->evidence, 'repair_frontier.research_ratchet.reason_codes', [])
        )->flatten()->map('strval')->countBy()->sortDesc();
        $confirmationBlockers = $experiments->map(fn (AgentLearningCausalExperiment $row) => (array) data_get($row->evidence, 'confirmation_blockers', [])
        )->flatten()->map('strval')->countBy()->sortDesc();
        $verifiedPairs = LabLearningLanePair::query()
            ->with('controlResponseMap')
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->get()
            ->filter(fn (LabLearningLanePair $pair): bool => $pair->isVerifiedControlPair())
            ->keyBy('id');
        $strictConfirmedExperiments = $experiments
            ->where('status', 'confirmed')
            ->filter(fn (AgentLearningCausalExperiment $row): bool => data_get($row->evidence, 'confirmation_evidence_protocol') === CausalLearningConfirmationService::EVIDENCE_PROTOCOL
                && data_get($row->evidence, 'component_effect.passed') === true
                && data_get($row->evidence, 'selector_effect.passed') === true
                && data_get($row->evidence, 'component_effect.target_effect.passed') === true
                && data_get($row->evidence, 'selector_effect.target_effect.passed') === true
                && data_get($row->evidence, 'absolute_viability.status') === 'passed'
                && (array) data_get($row->evidence, 'confirmation_blockers', []) === []
            )->keyBy('id');
        $allSkillLessons = AgentLearningLesson::query()
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->where('lesson_type', 'skill_lesson')
            ->get();
        $strictConfirmedSkills = $allSkillLessons
            ->where('status', 'confirmed')
            ->where('outcome', 'beneficial')
            ->filter(function (AgentLearningLesson $lesson) use ($strictConfirmedExperiments, $verifiedPairs): bool {
                $experiment = $strictConfirmedExperiments->get((int) data_get($lesson->evidence, 'causal_experiment_id', 0));
                $pair = $verifiedPairs->get((int) data_get($lesson->evidence, 'pair_id', 0));

                return $experiment !== null
                    && $pair !== null
                    && (int) $experiment->guided_agent_id === (int) $lesson->lab_agent_id
                    && (int) $pair->candidate_agent_id === (int) $lesson->lab_agent_id
                    && data_get($pair->non_target_regression, 'safe') === true
                    && in_array((string) data_get($pair->non_target_regression, 'status'), ['passed', 'confirmed'], true);
            });
        $confirmedSkills = $strictConfirmedSkills->count();
        $canonicalPositive = $canonical->where('evidence_state', 'positive')
            ->where('hard_failure', false)->count();
        $labeledConfirmedExperiments = $experiments->where('status', 'confirmed')->count();
        $confirmedExperiments = $strictConfirmedExperiments->count();
        $falseConfirmedSkills = max(0, $allSkillLessons->where('status', 'confirmed')->count() - $confirmedSkills);
        $falseConfirmedExperiments = max(0, $labeledConfirmedExperiments - $confirmedExperiments);
        $instrumentTimeframe = $symbol === strtoupper((string) config('services.xauusd_organism.symbol', 'XAUUSD'))
            ? 'M15'
            : $timeframe;
        $instrumentHypotheses = 0;
        if (Schema::hasTable('research_instrument_programs')) {
            $instrumentHypotheses += DB::table('research_instrument_programs')
                ->where('symbol', $symbol)->where('timeframe', $instrumentTimeframe)->count();
        }
        if (Schema::hasTable('instrument_value_posteriors')) {
            $instrumentHypotheses += DB::table('instrument_value_posteriors')
                ->where('symbol', $symbol)->where('timeframe', $instrumentTimeframe)
                ->where('decay_state', '!=', 'confirmed')->count();
        }
        $instrumentInvocations = Schema::hasTable('instrument_invocation_ledger')
            ? DB::table('instrument_invocation_ledger')->where('symbol', $symbol)->count()
            : 0;
        $positiveInstrumentSignals = Schema::hasTable('instrument_invocation_ledger')
            ? DB::table('instrument_invocation_ledger')->where('symbol', $symbol)->where('verdict', 'helped')->count()
            : 0;
        $instrumentPosteriorRows = Schema::hasTable('instrument_value_posteriors')
            ? InstrumentValuePosterior::query()->where('symbol', $symbol)->where('timeframe', $instrumentTimeframe)->get()
            : collect();
        $instrumentAuthority = $instrumentPosteriorRows->map(fn (InstrumentValuePosterior $row): array => app(InstrumentPosteriorAuthorityService::class)->assess($row));
        $confirmedInstrumentPosteriors = $instrumentAuthority->where('verified_confirmed', true)->count();
        $quarantinedInstrumentPosteriors = $instrumentAuthority->where('canonical_state', 'status_only_quarantined')->count();
        $bundlePosteriorRows = Schema::hasTable('playbook_value_posteriors')
            ? PlaybookValuePosterior::query()->where('symbol', $symbol)->where('timeframe', $instrumentTimeframe)
                ->whereHas('playbook', fn ($query) => $query->where('promotion_state', 'research_only'))->get()
            : collect();
        $bundleAuthority = $bundlePosteriorRows->map(fn (PlaybookValuePosterior $row): array => app(InstrumentPosteriorAuthorityService::class)->assess($row));
        $confirmedBundlePosteriors = $bundleAuthority->where('verified_confirmed', true)->count();
        $quarantinedBundlePosteriors = $bundleAuthority->where('canonical_state', 'status_only_quarantined')->count();
        $skillZoo = Schema::hasTable('lab_skill_zoo_entries')
            ? DB::table('lab_skill_zoo_entries')->where('symbol', $symbol)->where('timeframe', $timeframe)->get()
            : collect();
        $organismViable = $skillZoo->filter(fn ($row): bool => in_array((string) $row->organism_viability, ['viable', 'passed'], true)
            && in_array((string) $row->component_status, ['component_confirmed', 'causally_confirmed'], true))->count();
        $authorityRows = Schema::hasTable('evolutionary_authority_ledgers')
            ? DB::table('evolutionary_authority_ledgers')->where('symbol', $symbol)->where('timeframe', $timeframe)->get()
            : collect();
        $mentorIncubated = $authorityRows->filter(fn ($row): bool => data_get(json_decode((string) $row->evidence, true), 'incubation_passed') === true)
            ->pluck('model_version_id')->filter()->unique()->count();
        $eligibleParents = $authorityRows->where('authority_stage', 'eligible_parent')->where('status', 'passed')
            ->pluck('model_version_id')->filter()->unique()->count();
        $performanceCredits = Schema::hasTable('lab_evolution_credit_events')
            ? DB::table('lab_evolution_credit_events')->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->where('event_type', 'performance')->where('amount', '>', 0)->count()
            : 0;
        $descendantProven = Schema::hasTable('descendant_value_trials')
            ? DB::table('descendant_value_trials')->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->where('status', 'settled')->get()
                ->filter(fn ($row): bool => data_get(json_decode((string) $row->evidence, true), 'confirmed_component_only') === true
                    && data_get(json_decode((string) $row->evidence, true), 'improved_over_mentor') === true
                    && data_get(json_decode((string) $row->evidence, true), 'trait_incremental_over_ablation') === true
                    && data_get(json_decode((string) $row->evidence, true), 'inherited_failure') === false)
                ->pluck('child_model_version_id')->filter()->unique()->count()
            : 0;
        $confirmationActive = $experiments->filter(fn (AgentLearningCausalExperiment $row): bool => in_array((string) $row->status, ['planned', 'queued', 'running', 'ready_for_replay'], true)
            || ((string) $row->status === 'provisional'
                && filled(data_get($row->evidence, 'repair_frontier.child_experiment_id')))
        )->count();
        $familyPriors = $allSkillLessons->filter(fn (AgentLearningLesson $lesson): bool => (string) data_get($lesson->evidence, 'retrieval_scope') === 'family_prior'
            || (string) data_get($lesson->evidence, 'source_scope') === 'family_prior'
        )->count();
        $revoked = $falseConfirmedSkills + $falseConfirmedExperiments
            + $authorityRows->filter(fn ($row): bool => (string) $row->authority_stage === 'revoked'
                || (string) $row->status === 'revoked')->count();
        $status = $confirmedExperiments > 0 && $confirmedSkills > 0
            ? 'confirmed_compounding_learning_observed'
            : ($targetAlignedWinners->isNotEmpty()
                ? 'causal_relative_progress_not_yet_compounded'
                : ($legacyOrGenericWinners->isNotEmpty()
                    ? 'legacy_or_generic_uplift_not_target_aligned'
                    : 'no_causal_positive_component_observed'));

        return [
            'protocol' => self::PROTOCOL,
            'available' => true,
            'scope' => ['symbol' => $symbol, 'timeframe' => $timeframe],
            'recent_settlement_window' => [
                'size' => $recent->count(),
                'screening_diagnostic' => $screening->count(),
                'canonical_paired' => $canonicalRecent->count(),
                'states' => $this->counts($recent, 'evidence_state'),
                'screening_reward_component_coverage' => collect(array_keys(LearningRewardService::WEIGHTS))
                    ->mapWithKeys(fn (string $component): array => [
                        $component => $screening->filter(fn (AgentLearningSettlement $row): bool => is_numeric(data_get($row->reward_components, $component))
                        )->count(),
                    ])->all(),
                'truth_rule' => 'A screening diagnostic settlement is not a canonical positive or an inheritable skill.',
            ],
            'canonical_learning' => [
                'paired_settlements' => $canonical->count(),
                'positive_absolute_settlements' => $canonicalPositive,
                'negative_or_incomplete_settlements' => $canonical->count() - $canonicalPositive,
                'confirmed_causal_experiments' => $confirmedExperiments,
                'legacy_or_unverified_confirmed_experiment_labels' => $falseConfirmedExperiments,
                'confirmed_skill_lessons' => $confirmedSkills,
                'confirmation_authority' => 'target_aligned_counterfactuals_plus_absolute_viability_plus_explicit_non_target_pass',
            ],
            'knowledge_blocks' => [
                'research_inbox' => [
                    'authority' => 'experiment_proposal_only',
                    'skill_lessons' => max(0, $allSkillLessons->count() - $confirmedSkills),
                    'provisional_lessons' => $allSkillLessons->where('status', 'provisional')->count(),
                    'negative_evidence' => $canonical->where('evidence_state', 'negative')->count(),
                    'uncertain_evidence' => $canonical->where('evidence_state', 'uncertain')->count(),
                    'family_priors' => $familyPriors,
                    'instrument_hypotheses' => $instrumentHypotheses,
                    'failed_mutations' => $canonical->where('evidence_state', 'negative')->count(),
                    'audit_only_false_confirmed' => $falseConfirmedSkills + $falseConfirmedExperiments,
                    'direct_inheritance_allowed' => false,
                ],
                'proven_skill_registry' => [
                    'authority' => 'inheritance_candidate_only_after_foundry',
                    'causally_confirmed_experiments' => $confirmedExperiments,
                    'verified_skill_lessons' => $confirmedSkills,
                    'organism_viable' => $organismViable,
                    'eligible_parents' => $eligibleParents,
                    'requires_verified_frozen_control' => true,
                    'requires_exact_data_and_execution_hash' => true,
                    'family_prior_direct_inheritance_allowed' => false,
                ],
            ],
            'authority_state_machine' => [
                'protocol' => CausalCompoundingKernelService::PROTOCOL,
                'states' => [
                    'observed' => $verifiedPairs->count(),
                    'provisional' => $allSkillLessons->where('status', 'provisional')->count(),
                    'confirmation_active' => $confirmationActive,
                    'causally_confirmed' => $confirmedExperiments,
                    'organism_viable' => $organismViable,
                    'mentor_incubated' => $mentorIncubated,
                    'descendant_proven' => $descendantProven,
                    'eligible_parent' => $eligibleParents,
                    'revoked' => $revoked,
                ],
                'status_labels_are_not_authority' => true,
                'transition_rule' => 'Each state is re-derived from verified evidence; no lesson status alone may advance authority.',
            ],
            'learning_evolution_chain' => $this->learningEvolutionChain([
                'hypothesis' => $instrumentInvocations > 0 || $instrumentHypotheses > 0,
                'controlled_experiment' => $verifiedPairs->isNotEmpty(),
                'positive_economic_signal' => $canonicalPositive > 0 || $positiveInstrumentSignals > 0,
                'confirmed_instrument' => $confirmedInstrumentPosteriors > 0,
                'confirmed_contextual_bundle' => $confirmedBundlePosteriors > 0,
                'strong_parent' => $eligibleParents > 0,
                'rewarded_evolution' => $performanceCredits > 0,
            ], [
                'instrument_invocations' => $instrumentInvocations,
                'verified_control_pairs' => $verifiedPairs->count(),
                'positive_absolute_settlements' => $canonicalPositive,
                'positive_instrument_signals' => $positiveInstrumentSignals,
                'confirmed_instrument_posteriors' => $confirmedInstrumentPosteriors,
                'status_only_quarantined_instrument_posteriors' => $quarantinedInstrumentPosteriors,
                'confirmed_contextual_bundles' => $confirmedBundlePosteriors,
                'status_only_quarantined_contextual_bundles' => $quarantinedBundlePosteriors,
                'eligible_parents' => $eligibleParents,
                'positive_performance_credits' => $performanceCredits,
            ]),
            'causal_progress' => [
                'experiments' => $experiments->count(),
                'statuses' => $this->counts($experiments, 'status'),
                'legacy_or_generic_window_wins' => $legacyOrGenericWinners->count(),
                'guided_beats_both_counterfactuals' => $targetAlignedWinners->count(),
                'target_aligned_counterfactual_wins' => $targetAlignedWinners->count(),
                'research_ratchet_eligible' => $ratchetEligible->count(),
                'research_ratchet_blockers' => $ratchetBlocked->all(),
                'confirmation_blockers' => $confirmationBlockers->all(),
            ],
            'learning_status' => $status,
            'attention_required' => $status !== 'confirmed_compounding_learning_observed',
            'next_required' => $ratchetEligible->isNotEmpty()
                ? 'run_one_gene_repair_from_causal_positive_research_baseline'
                : ($targetAlignedWinners->isNotEmpty()
                    ? 'obtain_explicit_non_target_pass_then_open_research_ratchet'
                    : ($legacyOrGenericWinners->isNotEmpty()
                        ? 'rerun_legacy_uplift_with_declared_target_and_explicit_non_target_invariants'
                        : 'create_a_control_paired_intervention_that_beats_control_and_blinded')),
            'governance' => [
                'relative_uplift_is_not_absolute_viability' => true,
                'research_ratchet_is_not_parent_authority' => true,
                'promotion_gates_remain_fail_closed' => true,
            ],
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,int> */
    private function counts(Collection $rows, string $field): array
    {
        return $rows->groupBy(fn ($row): string => (string) data_get($row, $field, 'unknown'))
            ->map->count()->sortDesc()->all();
    }

    /** @param array<string,bool> $stages @param array<string,int> $counts @return array<string,mixed> */
    private function learningEvolutionChain(array $stages, array $counts): array
    {
        $firstMissing = collect($stages)->search(false, true);

        return [
            'protocol' => 'learning_evolution_chain_truth_v1',
            'stages' => $stages,
            'counts' => $counts,
            'complete' => $firstMissing === false,
            'first_missing_stage' => $firstMissing === false ? null : $firstMissing,
            'rule' => 'Every stage is evidence-derived; a later artifact cannot make an earlier missing stage green.',
            'promotion_evidence' => false,
        ];
    }
}
