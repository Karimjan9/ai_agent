<?php

namespace App\Services;

use App\Models\AgentKnowledgeCard;
use App\Models\AgentLearningLesson;
use App\Models\EliteAgentPortfolio;
use App\Models\LabAgent;
use App\Models\MarketDriftSnapshot;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\MutationMemory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Evidence-constrained memory for an evolving agent.
 *
 * The card is a mutable projection for routing and curriculum selection.
 * Lessons are append-only and always retain their source run. Neither object
 * can grant a forward, paper or elite decision; the normal immutable gates
 * remain the only promotion authority.
 */
class AgentKnowledgeService
{
    public const CARD_PROTOCOL = 'agent_knowledge_card_v1';
    public const LESSON_PROTOCOL = 'agent_lesson_ledger_v1';

    /** Directional quarantine is immutable within one population build. */
    private array $blockedDirectionCache = [];
    /** The expensive agent/model projection is shared across regime scopes. */
    private array $blockedDirectionUniverseCache = [];

    public function recordScreening(LabAgent $agent, array $result, ?string $runId = null): AgentKnowledgeCard
    {
        $this->assertLearningEvidence($runId ?: data_get($result, 'evidence_run_id'));

        return $this->record($agent, null, $result, $runId, true);
    }

    /**
     * Give legacy agents an explicit novice card without inventing evidence.
     * Missing replay data means WAIT, never a hidden pass or a silent absence
     * from the knowledge inventory.
     */
    public function recordBaseline(LabAgent $agent): AgentKnowledgeCard
    {
        return $this->record($agent, null, [
            'knowledge_projection' => [
                'status' => 'baseline_no_evidence',
                'promotion_evidence' => false,
            ],
            'epistemic_boundary' => ['unknown_state_action' => 'WAIT'],
        ], null, true);
    }

    public function recordFullReplay(
        LabAgent $agent,
        ModelMarketPerformance $performance,
        array $result,
        ?string $runId = null,
    ): AgentKnowledgeCard {
        $this->assertLearningEvidence($runId ?: data_get($result, 'evidence_run_id'));

        return $this->record($agent, $performance, $result, $runId, false);
    }

    private function assertLearningEvidence(?string $runId): void
    {
        $eligibility = app(LabImmutableEvidenceService::class)->learningEligibility($runId);
        if (! $eligibility['complete']) {
            throw new \RuntimeException(
                'LEARNING_EVIDENCE_INCOMPLETE: '.implode(',', (array) $eligibility['reason_codes'])
            );
        }
    }

    /**
     * Return only independently confirmed harmful mutation keys. A single
     * failed screen is a hypothesis, not a permanent ban. If every possible
     * key is blocked, the caller must retain a small retest lane.
     */
    public function blockedMutationKeys(
        string $symbol,
        string $timeframe,
        string $family,
        ?string $scope = null,
    ): array {
        $query = AgentLearningLesson::query()
            ->where(['symbol' => $symbol, 'timeframe' => $timeframe, 'strategy_family' => $family])
            ->where('lesson_type', 'harmful_lesson')
            ->where('status', 'confirmed')
            ->whereNotNull('parameter_key')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });

        $scope = $this->normalizeScope($scope);
        if ($scope !== null) {
            $query->where(function ($query) use ($scope): void {
                // A lesson with no proven regime is global safety evidence;
                // a scoped lesson remains local to its declared regime.
                $query->where('regime', $scope)->orWhereNull('regime');
            });
        }

        return $query->pluck('parameter_key')->filter()->unique()->values()->all();
    }

    /**
     * Return mutation directions that the laboratory must not rediscover.
     *
     * A parameter name alone is too coarse: the safe and unsafe directions
     * of the same gene can be opposite.  Confirmed harmful lessons remain the
     * strongest source.  A role-complete child that was quarantined after its
     * bounded independent replay is also a hard search-space lesson, even if
     * the legacy mutation-memory reconciler labelled the outcome neutral
     * because the causal credit quorum was not met.  This blocks repetition;
     * it never grants a promotion or changes any frozen result.
     *
     * @return array<int, array{signature:string,parameter_key:string,new_value:mixed,source_agent_ids:array<int,int>,reason:string}>
     */
    public function blockedMutationDirections(
        string $symbol,
        string $timeframe,
        string $family,
        ?string $scope = null,
    ): array {
        $scope = $this->normalizeScope($scope);
        $cacheKey = strtoupper($symbol).'|'.strtoupper($timeframe).'|'.$family.'|'.($scope ?? '*');
        if (array_key_exists($cacheKey, $this->blockedDirectionCache)) {
            return $this->blockedDirectionCache[$cacheKey];
        }
        $universeKey = strtoupper($symbol).'|'.strtoupper($timeframe).'|'.$family;
        if (array_key_exists($universeKey, $this->blockedDirectionUniverseCache)) {
            $universe = $this->blockedDirectionUniverseCache[$universeKey];
            $result = $scope === null
                ? $universe
                : collect($universe)->filter(fn (array $direction): bool =>
                    (bool) data_get($direction, 'global_scope', false)
                    || in_array($scope, (array) data_get($direction, 'scopes', []), true)
                )->values()->all();
            $this->blockedDirectionCache[$cacheKey] = $result;
            return $result;
        }
        $directions = [];

        $agents = LabAgent::query()
            ->with('modelVersion')
            ->where([
                'symbol' => strtoupper($symbol),
                'timeframe' => strtoupper($timeframe),
                'strategy_family' => $family,
            ])
            ->whereIn('lifecycle_status', ['quarantined', 'technical_quarantine', 'overfit', 'rejected'])
            ->latest('id')
            ->take(400)
            ->get();
        $agentIds = $agents->pluck('id')->values()->all();
        $memoryKeys = $agentIds === [] ? collect() : MutationMemory::query()
            ->whereIn('lab_agent_id', $agentIds)
            ->where('outcome', 'harmful')
            ->where('independent_confirmation_count', '>=', 2)
            ->get(['lab_agent_id', 'parameter_key'])
            ->groupBy('lab_agent_id')
            ->map(fn ($rows): array => $rows->pluck('parameter_key')->filter()->unique()->values()->all());
        $lessonKeys = $agentIds === [] ? collect() : AgentLearningLesson::query()
            ->whereIn('lab_agent_id', $agentIds)
            ->where('lesson_type', 'harmful_lesson')
            ->where('status', 'confirmed')
            ->whereNotNull('parameter_key')
            ->get(['lab_agent_id', 'parameter_key'])
            ->groupBy('lab_agent_id')
            ->map(fn ($rows): array => $rows->pluck('parameter_key')->filter()->unique()->values()->all());

        foreach ($agents as $agent) {
            $model = $agent->modelVersion;
            $diff = (array) $agent->parameter_diff;
            if (! $model || count($diff) !== 1) continue;

            $metadata = (array) $model->metadata;
            $roleComplete = data_get($metadata, 'role_complete_council.protocol') === 'role_complete_council_v1';
            $agentScope = $this->normalizeScope(
                data_get($metadata, 'council_specialist_contract.owner_regime',
                    data_get($metadata, 'portfolio_council_lane.regime')),
            );

            $key = (string) array_key_first($diff);
            $change = (array) ($diff[$key] ?? []);
            if (! array_key_exists('new', $change)) continue;

            $memoryStrong = in_array($key, (array) $memoryKeys->get($agent->id, []), true);
            $lessonStrong = in_array($key, (array) $lessonKeys->get($agent->id, []), true);
            $decision = strtolower((string) $agent->decision_reason);
            $quarantineStrong = $roleComplete
                && in_array((string) $agent->lifecycle_status, ['quarantined', 'overfit', 'rejected'], true)
                && ($decision === ''
                    || str_contains($decision, 'quarantin')
                    || str_contains($decision, 'failed independent')
                    || str_contains($decision, 'known-failed'));

            if (! $memoryStrong && ! $lessonStrong && ! $quarantineStrong) continue;

            $signature = $this->mutationDirectionSignature($key, $change['new']);
            if (! isset($directions[$signature])) {
                $directions[$signature] = [
                    'signature' => $signature,
                    'parameter_key' => $key,
                    'new_value' => $change['new'],
                    'source_agent_ids' => [],
                    'scopes' => [],
                    'global_scope' => false,
                    'reason' => $lessonStrong || $memoryStrong
                        ? 'independently_confirmed_harmful_mutation'
                        : 'quarantined_role_replay_direction',
                ];
            }
            $directions[$signature]['source_agent_ids'][] = (int) $agent->id;
            if ($agentScope === null) {
                $directions[$signature]['global_scope'] = true;
            } else {
                $directions[$signature]['scopes'][] = $agentScope;
            }
        }

        $universe = collect($directions)->map(function (array $direction): array {
            $direction['source_agent_ids'] = array_values(array_unique($direction['source_agent_ids']));
            $direction['scopes'] = array_values(array_unique($direction['scopes']));
            return $direction;
        })->values()->all();
        $this->blockedDirectionUniverseCache[$universeKey] = $universe;
        $result = $scope === null
            ? $universe
            : collect($universe)->filter(fn (array $direction): bool =>
                (bool) data_get($direction, 'global_scope', false)
                || in_array($scope, (array) data_get($direction, 'scopes', []), true)
            )->values()->all();
        $this->blockedDirectionCache[$cacheKey] = $result;

        return $result;
    }

    private function mutationDirectionSignature(string $parameterKey, mixed $newValue): string
    {
        return $parameterKey.'|'.json_encode(
            $newValue,
            JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * Seed a child with an auditable learning contract. This carries skill
     * requirements and blocked lessons, never a parent promotion status.
     */
    public function childContract(
        string $symbol,
        string $timeframe,
        string $family,
        ?ModelVersion $parent,
        ?array $niche,
        string $target,
        array $contributors = [],
    ): array {
        $parentModels = collect([$parent, ...$contributors])
            ->filter(fn ($model): bool => $model instanceof ModelVersion)
            ->unique('id')
            ->values();
        $cards = $parentModels->isEmpty()
            ? collect()
            : AgentKnowledgeCard::query()
                ->whereIn('model_version_id', $parentModels->pluck('id')->all())
                ->latest('last_observed_at')
                ->get()
                ->unique('model_version_id')
                ->values();
        $card = $parent?->id
            ? $cards->firstWhere('model_version_id', $parent->id)
            : $cards->first();
        $scope = data_get($niche, 'regime');
        $professional = app(AgentProfessionalExamService::class);
        $usableCards = $cards->filter(fn ($candidate): bool => $professional->skillUsable($candidate));
        $skillUsable = $professional->skillUsable($card);
        $preserve = $usableCards
            ->flatMap(fn ($candidate) => collect((array) ($candidate->capability_vector ?? []))
                ->filter(fn ($score): bool => is_numeric($score) && (float) $score >= 60)
                ->keys())
            ->unique()->values()->all();
        $budget = app(AgentProfessionalExamService::class)->mutationBudget($symbol, $timeframe, $family, $card);

        return [
            'protocol' => self::CARD_PROTOCOL,
            'status' => 'research_only',
            'stage' => 'novice',
            'parent_model_version_id' => $parent?->id,
            'parent_model_version_ids' => $parentModels->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'parent_card_ids' => $usableCards->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'parent_card_id' => $card?->id,
            'parent_stage' => $card?->skill_stage,
            'parent_skill_status' => $skillUsable ? 'active' : 'expired',
            'target' => $target,
            'target_state_cluster' => data_get($niche, 'state_cluster'),
            'preserve_skill_keys' => $preserve,
            'blocked_mutations' => $this->blockedMutationKeys($symbol, $timeframe, $family, $scope),
            'blocked_mutation_directions' => $this->blockedMutationDirections($symbol, $timeframe, $family, $scope),
            'required_exams' => [
                'no_change_control', 'independent_state_windows',
                'retention', 'abstention_quality', 'drift_recheck',
                'hidden_state_cluster_challenge', 'router_calibration_abstention',
                'teacher_student_shadow', 'mutation_budget',
            ],
            'mutation_budget' => $budget,
            'curiosity_lane' => [
                'enabled' => true, 'budget' => 2, 'promotion_evidence' => false,
                'target' => 'unknown_state_curiosity',
            ],
            'promotion_evidence' => false,
            'rule' => 'A child may learn from a parent card, but must re-prove every capability on frozen evidence.',
            'multi_parent_rule' => 'Confirmed capability priors may be unioned across selected parents; blocked lessons remain independently re-earned and never become promotion evidence.',
        ];
    }

    /** Mark members as elite only after the portfolio gate itself passed. */
    public function markCouncilElite(EliteAgentPortfolio $portfolio, ?string $runId = null): int
    {
        $portfolio->loadMissing('members.performance.modelVersion');
        $updated = 0;
        foreach ($portfolio->members as $member) {
            $performance = $member->performance;
            $agent = $performance?->model_version_id
                ? LabAgent::query()->where('model_version_id', $performance->model_version_id)->latest('id')->first()
                : null;
            if (! $agent) continue;

            $card = AgentKnowledgeCard::query()->where('lab_agent_id', $agent->id)->first();
            if (! $card) continue;
            if (! app(AgentProfessionalExamService::class)->skillUsable($card)) continue;
            $card->update([
                'skill_stage' => 'elite_council_member',
                'skill_contract' => [
                    ...((array) $card->skill_contract),
                    'council_protocol' => 'agent_council_v1',
                    'portfolio_id' => $portfolio->id,
                    'role' => $member->role,
                    'target_regime' => $member->target_regime,
                    'target_volatility' => $member->target_volatility,
                    'router_replay_passed' => true,
                    'promotion_evidence' => true,
                    'evidence_run_id' => $runId,
                ],
                'last_evidence_run_id' => $runId ?: $card->last_evidence_run_id,
                'last_observed_at' => now(),
            ]);
            $updated++;
        }
        return $updated;
    }

    private function record(
        LabAgent $agent,
        ?ModelMarketPerformance $performance,
        array $result,
        ?string $runId,
        bool $screening,
    ): AgentKnowledgeCard {
        $agent->loadMissing('modelVersion', 'generation');
        $model = $agent->modelVersion;
        $context = $this->stateContext($model);
        $stateEvidence = $this->stateEvidence($result, $context);
        $failureProfiles = $this->failureProfiles($result);
        $lessonRows = $this->lessonRows($agent, $model, $result, $context, $failureProfiles, $runId, $screening);

        foreach ($lessonRows as $lesson) {
            AgentLearningLesson::query()->firstOrCreate(
                ['lesson_hash' => $lesson['lesson_hash']],
                $lesson['attributes'],
            );
        }

        $lessons = AgentLearningLesson::query()->where('lab_agent_id', $agent->id)->get();
        $confirmed = $lessons->where('status', 'confirmed');
        $blocked = $lessons->where('lesson_type', 'harmful_lesson')->where('status', 'confirmed')
            ->pluck('parameter_key')->filter()->unique()->values()->all();
        $tested = $lessons->whereIn('lesson_type', ['skill_lesson', 'harmful_lesson'])
            ->pluck('parameter_key')->filter()->unique()->values()->all();
        $retention = $this->retention($model);
        $abstention = $this->abstention($result);
        $drift = $this->drift($agent->symbol, $agent->timeframe, $result);
        $windowEvidence = $this->independentWindowEvidence($agent, $model, $result);
        $independentWindows = (int) $windowEvidence['confirmed_windows'];
        $skillStage = $this->skillStage(
            $screening,
            count($stateEvidence['strong_state_clusters']),
            $independentWindows,
            $performance,
            $model,
            $retention['status'],
            (string) $windowEvidence['control_status'],
            (string) $windowEvidence['lower_confidence_status'],
            (string) $drift['status'],
        );
        $skillScore = $this->skillScore(
            $screening,
            count($stateEvidence['strong_regimes']),
            $independentWindows,
            $retention,
            $abstention,
            $drift,
        );
        $capability = (array) data_get($model?->metadata, 'capability_vector', []);
        $contract = [
            'protocol' => self::CARD_PROTOCOL,
            'stage' => $skillStage,
            'preserve_old_skills' => true,
            'unknown_state_action' => $this->unknownStateAction($result),
            'required_independent_state_clusters' => 2,
            'learning_exams' => [
                'independent_state_windows' => $windowEvidence,
                'no_change_control' => ['status' => $windowEvidence['control_status']],
                'positive_lower_confidence' => ['status' => $windowEvidence['lower_confidence_status']],
                'retention' => ['status' => $retention['status'], 'lost_skills' => $retention['lost_skills']],
            ],
            'skill_status' => $drift['status'] === 'recheck_required' ? 'expired' : 'active',
            'recertification_required' => $drift['status'] === 'recheck_required',
            'skill_expiry_at' => $drift['recheck_at'],
            'curiosity_lane' => [
                'budget' => 2, 'used' => 0, 'remaining' => 2,
                'allowed_targets' => ['unknown_state_curiosity'],
                'promotion_evidence' => false,
            ],
            'promotion_evidence' => false,
        ];
        $provenance = [
            'protocol' => self::CARD_PROTOCOL,
            'agent_id' => $agent->id,
            'model_version_id' => $model?->id,
            'generation_id' => $agent->lab_generation_id,
            'generation' => $agent->generation?->generation,
            'evidence_run_id' => $runId ?: data_get($result, 'evidence_run_id'),
            'screening' => $screening,
            'promotion_evidence' => false,
                'state_cluster_rule' => 'Calendar month is diagnostic only; skill scope is regime/volatility/transition/liquidity/veto.',
                'learning_exams' => $windowEvidence,
            ];

        $card = AgentKnowledgeCard::query()->updateOrCreate(
            ['lab_agent_id' => $agent->id],
            [
                'model_version_id' => $model?->id,
                'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe,
                'strategy_family' => $agent->strategy_family,
                'skill_stage' => $skillStage, 'skill_score' => $skillScore,
                'strong_regimes' => $stateEvidence['strong_regimes'],
                'strong_state_clusters' => $stateEvidence['strong_state_clusters'],
                'failure_profiles' => $failureProfiles,
                'tested_mutations' => $tested, 'blocked_mutations' => $blocked,
                'independent_window_count' => $independentWindows,
                'confirmed_lesson_count' => $confirmed->count(),
                'retention_status' => $retention['status'],
                'retention_score' => $retention['score'],
                'abstention_status' => $abstention['status'],
                'abstention_precision' => $abstention['precision'],
                'unknown_state_action' => $this->unknownStateAction($result),
                'drift_status' => $drift['status'],
                'drift_recheck_at' => $drift['recheck_at'],
                'capability_vector' => $capability,
                'skill_contract' => $contract,
                'provenance' => [...$provenance, 'context' => $context],
                'last_evidence_run_id' => $runId ?: data_get($result, 'evidence_run_id'),
                'last_observed_at' => now(),
            ],
        );

        // Professional exams are a separate learning ledger. They are
        // deliberately recorded after the card exists so drift expiry and
        // teacher/student retention can reference the current projection.
        $professionalExams = app(AgentProfessionalExamService::class)->assessAndRecord(
            $agent, $model, $performance, $result, $card,
        );
        if ($performance) {
            $performance->update([
                'metrics' => [
                    ...((array) $performance->metrics),
                    'professional_exams' => $professionalExams,
                    'promotion_evidence' => false,
                ],
            ]);
        }
        $professionalSkill = (array) data_get($professionalExams, 'drift_recertification', []);
        $examStage = $skillStage;
        // A/B are only the legacy database projection. Professional stage
        // decisions must see the complete contribution graph, otherwise a
        // multi-parent child could skip the teacher/student retention exam
        // when its first two compatibility columns are empty or incomplete.
        $hasParent = app(ParentContributionGraphService::class)->ids($agent) !== [];
        $hiddenPassed = data_get($professionalExams, 'hidden_state_challenge.status') === 'passed';
        $shadowPassed = data_get($professionalExams, 'teacher_student_shadow.status') === 'passed';
        $routerRequired = data_get($result, 'portfolio_evidence.status') === 'observed'
            || (bool) data_get($model?->metadata, 'portfolio_proxy', false);
        $routerPassed = data_get($professionalExams, 'router_calibration.status') === 'assessed';
        if ($examStage === 'certified' && (! $hiddenPassed || ($hasParent && ! $shadowPassed)
            || ($routerRequired && ! $routerPassed))) {
            $examStage = 'specialist';
        }
        if (data_get($professionalSkill, 'recertification_required') === true) {
            $examStage = 'apprentice';
        }
        $card->update([
            'skill_stage' => $examStage,
            'skill_contract' => [
                ...((array) $card->skill_contract),
                'professional_exams' => $professionalExams,
                'stage_after_professional_exams' => $examStage,
                'skill_status' => data_get($professionalSkill, 'skill_status', data_get($card->skill_contract, 'skill_status', 'active')),
                'recertification_required' => (bool) data_get($professionalSkill, 'recertification_required', false),
                'skill_expiry_at' => data_get($professionalSkill, 'skill_expiry_at', data_get($card->skill_contract, 'skill_expiry_at')),
                'mutation_budget' => data_get($professionalExams, 'mutation_budget', []),
                'promotion_evidence' => false,
            ],
        ]);

        if ($model) {
            $model->update(['metadata' => [
                ...((array) $model->metadata),
                'agent_knowledge' => [
                    'protocol' => self::CARD_PROTOCOL,
                    'card_id' => $card->id,
                    'skill_stage' => $examStage,
                    'skill_score' => $skillScore,
                    'blocked_mutations' => $blocked,
                    'unknown_state_action' => $this->unknownStateAction($result),
                    'drift_status' => $drift['status'],
                    'skill_status' => data_get($professionalSkill, 'skill_status', 'active'),
                    'recertification_required' => (bool) data_get($professionalSkill, 'recertification_required', false),
                    'professional_exams' => $professionalExams,
                    'promotion_evidence' => false,
                ],
            ]]);
        }

        return $card->fresh();
    }

    private function lessonRows(
        LabAgent $agent,
        ?ModelVersion $model,
        array $result,
        array $context,
        array $failureProfiles,
        ?string $runId,
        bool $screening,
    ): array {
        $sourceRunIds = array_values(array_unique(array_filter([
            $runId, data_get($result, 'evidence_run_id'),
        ])));
        $rows = [];
        foreach ($failureProfiles as $profile) {
            $rows[] = $this->lesson(
                $agent, $model, 'failure_diagnosis', 'observed', $profile['class'],
                null, $context, $sourceRunIds, $profile,
            );
        }

        foreach ((array) ($agent->parameter_diff ?? []) as $key => $change) {
            $memory = MutationMemory::query()->where('lab_agent_id', $agent->id)
                ->where('parameter_key', $key)->latest('id')->first();
            if (! $memory || ! in_array($memory->outcome, ['beneficial', 'harmful'], true)) continue;
            $creditStatus = (string) data_get($memory->behavioral_effect, 'causal_credit.status', '');
            $priorConfirmed = AgentLearningLesson::query()
                ->where('lab_agent_id', $agent->id)
                ->where('parameter_key', $key)
                ->where('lesson_type', $memory->outcome === 'harmful' ? 'harmful_lesson' : 'skill_lesson')
                ->where('status', 'confirmed')->count();
            $independent = $creditStatus === 'independently_confirmed'
                || (int) $memory->independent_confirmation_count >= 2;
            // Repeating a lesson for the same child is not independent
            // evidence. Confirmation requires an explicitly independent
            // state/window credit; an earlier confirmed row cannot promote a
            // new replay by itself.
            $status = $independent ? 'confirmed' : 'provisional';
            $type = $memory->outcome === 'harmful' ? 'harmful_lesson' : 'skill_lesson';
            $rows[] = $this->lesson(
                $agent, $model, $type, $status, null, (string) $key, $context, $sourceRunIds,
                [
                    'outcome' => $memory->outcome,
                    'old_value' => data_get($change, 'old'),
                    'new_value' => data_get($change, 'new'),
                    'forward_delta' => $memory->forward_delta,
                    'causal_credit_status' => $creditStatus,
                    'confirmation_count' => $independent ? max(2, $priorConfirmed + 1) : max(0, $priorConfirmed),
                    'screening' => $screening,
                ],
            );
        }

        $transition = (array) data_get($result, 'transition_homework', []);
        $transitionTrades = (int) data_get($transition, 'transition_trades', 0);
        $falseEntryRate = (float) data_get($transition, 'false_entry_rate', 0);
        $policyEvaluations = collect((array) data_get($result, 'veto_policy_lab.evaluations', []));
        $policyLesson = $policyEvaluations->first(fn ($item): bool =>
            data_get($item, 'status') === 'negative_or_uncertain'
            && (int) data_get($item, 'sample_count', 0) >= 30
            && is_numeric(data_get($item, 'lower_confidence_bound'))
            && (float) data_get($item, 'lower_confidence_bound') <= 0
        );
        if ($transitionTrades >= 10 && $falseEntryRate >= .5 || $policyLesson) {
            $rows[] = $this->lesson(
                $agent, $model, 'abstention_lesson', $policyLesson ? 'confirmed' : 'provisional',
                'transition', null, $context, $sourceRunIds,
                [
                    'action' => 'WAIT_OR_REDUCE_RISK',
                    'transition_trades' => $transitionTrades,
                    'false_entry_rate' => $falseEntryRate,
                    'abstention_quality' => data_get($transition, 'abstention_quality'),
                    'policy_evidence' => $policyLesson,
                    'rule' => 'No-trade is a valid lesson only when context evidence supports it; it never increases promotion recall by itself.',
                ],
            );
        }

        $drift = MarketDriftSnapshot::query()->where(['symbol' => $agent->symbol, 'timeframe' => $agent->timeframe])
            ->latest('detected_at')->first();
        $driftConfirmation = app(MarketDriftDetectionService::class)->confirmation($agent->symbol, $agent->timeframe);
        $validatedDrift = $driftConfirmation['status'] === 'confirmed'
            || (app()->environment('testing') && $drift?->status === 'drift');
        if ($validatedDrift) {
            $rows[] = $this->lesson(
                $agent, $model, 'drift_recheck', 'confirmed', 'market_drift', null, $context, $sourceRunIds,
                ['psi_score' => $drift?->psi_score, 'volatility_ratio' => $drift?->volatility_ratio,
                    'confirmation' => $driftConfirmation, 'detected_at' => $drift?->detected_at?->toIso8601String()],
            );
        }

        return $rows;
    }

    private function lesson(
        LabAgent $agent,
        ?ModelVersion $model,
        string $type,
        string $status,
        ?string $failureClass,
        ?string $parameterKey,
        array $context,
        array $sourceRunIds,
        array $evidence,
    ): array {
        $identity = [
            self::LESSON_PROTOCOL, $agent->id, $model?->id, $type, $status,
            $failureClass, $parameterKey, $context['state_cluster_id'] ?? null,
            $sourceRunIds, $evidence,
        ];
        $hash = hash('sha256', json_encode($identity, JSON_PRESERVE_ZERO_FRACTION));
        $confirmationCount = max(0, (int) data_get($evidence, 'confirmation_count', 0));
        $lowerBound = data_get($evidence, 'lower_confidence_bound', data_get($evidence, 'policy_evidence.lower_confidence_bound'));
        return [
            'lesson_hash' => $hash,
            'attributes' => [
                'lesson_id' => (string) Str::uuid(), 'lab_agent_id' => $agent->id,
                'model_version_id' => $model?->id, 'symbol' => $agent->symbol,
                'timeframe' => $agent->timeframe, 'strategy_family' => $agent->strategy_family,
                'lesson_type' => $type, 'status' => $status, 'failure_class' => $failureClass,
                'parameter_key' => $parameterKey, 'state_cluster_id' => $context['state_cluster_id'] ?? null,
                'regime' => $context['regime'] ?? null, 'volatility' => $context['volatility'] ?? null,
                'transition_state' => $context['transition_state'] ?? null,
                'spread_liquidity_state' => $context['spread_liquidity_state'] ?? null,
                'veto_reason' => $context['veto_reason'] ?? null,
                'outcome' => data_get($evidence, 'outcome', data_get($evidence, 'action')),
                'independent_window_count' => (int) data_get($evidence, 'independent_window_count', 0),
                'confirmation_count' => $confirmationCount,
                'lower_confidence_bound' => is_numeric($lowerBound) ? (float) $lowerBound : null,
                'source_run_ids' => $sourceRunIds, 'evidence' => [
                    'protocol' => self::LESSON_PROTOCOL,
                    ...$evidence,
                    'promotion_evidence' => false,
                ],
                'observed_at' => now(),
                'expires_at' => $type === 'drift_recheck' ? now()->addDays(3) : null,
            ],
        ];
    }

    private function stateContext(?ModelVersion $model): array
    {
        $cluster = (array) data_get(
            $model?->metadata,
            'state_cluster_contract.cluster',
            data_get($model?->metadata, 'portfolio_council_lane.state_cluster', []),
        );
        $regime = data_get($cluster, 'regime', data_get($model?->metadata, 'portfolio_council_lane.regime'));
        $volatility = data_get($cluster, 'volatility', data_get($model?->metadata, 'portfolio_council_lane.volatility'));
        $transition = data_get($cluster, 'transition_state', 'unknown');
        $spread = data_get($cluster, 'spread_liquidity_state', 'unknown');
        $veto = data_get($cluster, 'veto_reason', null);
        $clusterId = data_get($cluster, 'cluster_id');
        if (! $clusterId && ($regime || $volatility || $transition !== 'unknown' || $spread !== 'unknown' || $veto)) {
            $clusterId = hash('sha256', implode('|', [
                'state_cluster_v1', $regime, $volatility, $transition, $spread, $veto,
            ]));
        }
        return [
            'state_cluster_id' => $clusterId,
            'regime' => $regime, 'volatility' => $volatility,
            'transition_state' => $transition, 'spread_liquidity_state' => $spread,
            'veto_reason' => $veto,
            'month_labels_are_diagnostic_only' => true,
        ];
    }

    private function stateEvidence(array $result, array $context): array
    {
        $strongRegimes = [];
        $regimes = (array) data_get($result, 'pf_attribution.breakdown.by_regime', []);
        foreach ($regimes as $regime => $row) {
            $trades = (int) data_get($row, 'trades', 0);
            $pf = (float) data_get($row, 'net_pf', data_get($row, 'profit_factor', 0));
            if ($trades >= 10 && $pf >= 1.3) {
                $strongRegimes[] = ['regime' => (string) $regime, 'trades' => $trades, 'net_pf' => $pf, 'status' => 'observed'];
            }
        }

        $strongClusters = [];
        $contexts = (array) data_get($result, 'pf_attribution.breakdown.by_regime_volatility', []);
        foreach ($contexts as $key => $row) {
            [$regime, $volatility] = array_pad(explode('|', (string) $key, 2), 2, null);
            $trades = (int) data_get($row, 'trades', 0);
            $pf = (float) data_get($row, 'net_pf', data_get($row, 'profit_factor', 0));
            if ($trades < 10 || $pf < 1.3) continue;
            $clusterId = ($context['regime'] === $regime && $context['volatility'] === $volatility)
                ? $context['state_cluster_id']
                : hash('sha256', implode('|', ['state_cluster_v1', $regime, $volatility, 'unknown', 'unknown', null]));
            $strongClusters[] = [
                'cluster_id' => $clusterId, 'regime' => $regime, 'volatility' => $volatility,
                'transition_state' => $context['regime'] === $regime && $context['volatility'] === $volatility
                    ? $context['transition_state'] : 'unknown',
                'spread_liquidity_state' => $context['regime'] === $regime && $context['volatility'] === $volatility
                    ? $context['spread_liquidity_state'] : 'unknown',
                'trades' => $trades, 'net_pf' => $pf,
                'month_labels_are_diagnostic_only' => true,
            ];
        }

        return [
            'strong_regimes' => collect($strongRegimes)->unique('regime')->values()->all(),
            'strong_state_clusters' => collect($strongClusters)->unique('cluster_id')->values()->all(),
        ];
    }

    private function failureProfiles(array $result): array
    {
        $profiles = [];
        $rawSignals = (int) data_get($result, 'entry_funnel.raw_strategy_signals', data_get($result, 'diagnostic_telemetry.signal_count', 0));
        $accepted = (int) data_get($result, 'entry_funnel.accepted_entries', data_get($result, 'total_trades', 0));
        if ($rawSignals > 0 && $accepted === 0) {
            $profiles[] = ['class' => 'entry', 'severity' => 'high', 'evidence' => ['raw_signals' => $rawSignals, 'accepted_entries' => $accepted]];
        }
        $recall = data_get($result, 'opportunity_metrics.recall', data_get($result, 'opportunity_metrics.opportunity_recall'));
        if (is_numeric($recall) && (float) $recall < .20) {
            $profiles[] = ['class' => 'missed_opportunity', 'severity' => 'high', 'evidence' => ['recall' => (float) $recall]];
        }
        $transition = (array) data_get($result, 'transition_homework', []);
        if ((int) data_get($transition, 'transition_trades', 0) >= 10 && (float) data_get($transition, 'false_entry_rate', 0) >= .5) {
            $profiles[] = ['class' => 'transition', 'severity' => 'high', 'evidence' => $transition];
        }
        $stress = (float) data_get($result, 'pf_attribution.stress_cost.profit_factor', 0);
        if ($stress > 0 && $stress < 1.05) {
            $profiles[] = ['class' => 'spread', 'severity' => 'high', 'evidence' => ['stress_cost_pf' => $stress]];
        }
        $dd = (float) data_get($result, 'max_drawdown_percent', data_get($result, 'max_drawdown', 0));
        $ruin = (float) data_get($result, 'monte_carlo.risk_of_ruin_percent', 0);
        if ($dd > 15 || $ruin > 10) {
            $profiles[] = ['class' => 'risk_veto', 'severity' => 'high', 'evidence' => ['drawdown' => $dd, 'ruin_risk' => $ruin]];
        }
        $exitDistribution = (array) data_get($result, 'diagnostic_telemetry.exit_distribution', []);
        $stopShare = (float) data_get($exitDistribution, 'stop_loss.share', data_get($exitDistribution, 'intrabar_stop.share', 0));
        if ($stopShare >= .5) {
            $profiles[] = ['class' => 'exit', 'severity' => 'medium', 'evidence' => ['stop_share' => $stopShare]];
        }
        return collect($profiles)->unique('class')->values()->all();
    }

    private function retention(?ModelVersion $model): array
    {
        $exam = (array) data_get($model?->metadata, 'skill_retention_exam', []);
        $status = (string) data_get($exam, 'status', 'baseline_unavailable');
        $score = match ($status) {
            'retained' => 100.0,
            'catastrophic_forgetting' => 0.0,
            default => null,
        };
        return ['status' => $status, 'score' => $score, 'lost_skills' => (array) data_get($exam, 'lost_skills', [])];
    }

    /**
     * Count independent evidence windows from chronological replay outputs.
     * A second regime bucket inside one aggregate replay is not a second
     * confirmation window. Lower-confidence and control evidence stay
     * explicit so the card cannot silently promote a lucky single run.
     */
    private function independentWindowEvidence(
        LabAgent $agent,
        ?ModelVersion $model,
        array $result,
    ): array {
        $checkpointWindows = collect((array) data_get($result, 'market_adaptive_replay.checkpoint_windows', []));
        $monthlyWindows = collect((array) data_get($result, 'market_adaptive_replay.monthly_walk_forward.windows', []));
        // These are two projections of the same replay, not two independent
        // samples. Monthly walk-forward rows also contain expanding train
        // intervals, so merging them with checkpoint chunks double-counts
        // chronology and can manufacture a false quorum. Prefer disjoint
        // checkpoint windows; use monthly test months only when checkpoints
        // are unavailable.
        $windows = ($checkpointWindows->isNotEmpty() ? $checkpointWindows : $monthlyWindows)->values();
        $confirmed = $windows->filter(function ($window): bool {
            $trades = (int) data_get($window, 'trades', data_get($window, 'summary.trades', 0));
            $pf = data_get($window, 'profit_factor', data_get($window, 'net_pf', data_get($window, 'summary.net_pf')));
            $score = data_get($window, 'score');
            if ($trades < 10) return false;
            if (is_numeric($pf)) return (float) $pf >= 1.30 && (float) data_get($window, 'net_profit_percent', 1) > 0;
            return is_numeric($score) && (float) $score > 0;
        })->count();

        $lowerBound = data_get($result, 'statistical_evidence.edge_quality.bootstrap_pf.pf_5_percentile_lower_bound');
        if (! is_numeric($lowerBound)) {
            $lowerBound = data_get($result, 'statistical_evidence.edge_quality.lower_confidence_bound');
        }
        $lowerStatus = is_numeric($lowerBound)
            ? ((float) $lowerBound > 0 ? 'positive' : 'non_positive')
            : 'unassessed';

        $explicitControl = data_get($result, 'no_change_control.status', data_get($result, 'paired_experiment.status'));
        $controlStatus = in_array($explicitControl, ['assessed', 'confirmed', 'passed', 'not_confirmed'], true)
            ? 'assessed'
            : 'unassessed';
        if ($controlStatus === 'unassessed') {
            $controlStatus = MutationMemory::query()
                ->where('lab_agent_id', $agent->id)
                ->get()
                ->contains(fn (MutationMemory $memory): bool => in_array(
                    data_get($memory->behavioral_effect, 'paired_experiment.status'),
                    ['confirmed', 'not_confirmed'],
                    true,
                )) ? 'assessed' : 'unassessed';
        }

        return [
            'protocol' => 'independent_state_window_exam_v1',
            'observed_windows' => $windows->count(),
            'confirmed_windows' => min(3, $confirmed),
            'required_windows' => 2,
            'status' => $confirmed >= 2 ? 'confirmed' : ($windows->isEmpty() ? 'unassessed' : 'insufficient'),
            'control_status' => $controlStatus,
            'lower_confidence_status' => $lowerStatus,
            'lower_confidence_bound' => is_numeric($lowerBound) ? (float) $lowerBound : null,
            'source' => $windows->isNotEmpty() ? 'chronological_checkpoint_or_monthly_windows' : 'not_available',
            'promotion_evidence' => false,
        ];
    }

    private function abstention(array $result): array
    {
        $transition = (array) data_get($result, 'transition_homework', []);
        $evaluations = collect((array) data_get($result, 'veto_policy_lab.evaluations', []));
        $confirmed = $evaluations->first(fn ($item): bool => data_get($item, 'status') === 'negative_or_uncertain'
            && (int) data_get($item, 'sample_count', 0) >= 30
            && is_numeric(data_get($item, 'lower_confidence_bound'))
            && (float) data_get($item, 'lower_confidence_bound') <= 0);
        if ($confirmed) return ['status' => 'confirmed', 'precision' => (float) data_get($transition, 'abstention_quality', 0)];
        if ((int) data_get($transition, 'transition_trades', 0) >= 10) {
            return ['status' => 'provisional', 'precision' => (float) data_get($transition, 'abstention_quality', 0)];
        }
        return ['status' => 'unassessed', 'precision' => null];
    }

    private function drift(string $symbol, string $timeframe, array $result): array
    {
        $snapshot = MarketDriftSnapshot::query()->where(compact('symbol', 'timeframe'))->latest('detected_at')->first();
        $confirmation = app(MarketDriftDetectionService::class)->confirmation($symbol, $timeframe);
        $validated = $confirmation['status'] === 'confirmed'
            || (app()->environment('testing') && $snapshot?->status === 'drift');
        if ($validated) return [
            'status' => 'recheck_required', 'recheck_at' => now(), 'confirmation' => $confirmation,
        ];
        if ($snapshot) return ['status' => 'stable', 'recheck_at' => null];
        return ['status' => 'unknown', 'recheck_at' => null];
    }

    private function skillStage(
        bool $screening,
        int $strongClusterCount,
        int $independentWindows,
        ?ModelMarketPerformance $performance,
        ?ModelVersion $model,
        string $retentionStatus,
        string $controlStatus,
        string $lowerConfidenceStatus,
        string $driftStatus = 'unknown',
    ): string {
        if ($screening) return 'novice';
        if ($driftStatus === 'recheck_required') return 'apprentice';
        $passport = data_get($performance?->metrics, 'elite_agent_passport.status') === 'passed';
        $adversarial = data_get($performance?->metrics, 'secret_adversarial_arena.status') === 'passed';
        $temporal = data_get($performance?->metrics, 'temporal_firewall.status') === 'passed';
        $learningEvidence = $strongClusterCount >= 2
            && $independentWindows >= 2
            && $controlStatus === 'assessed'
            && $lowerConfidenceStatus === 'positive';
        if ($passport && $retentionStatus === 'retained' && $adversarial && $temporal && $learningEvidence) return 'certified';
        if ($learningEvidence) return 'specialist';
        return 'apprentice';
    }

    private function skillScore(
        bool $screening,
        int $regimeCount,
        int $stateCount,
        array $retention,
        array $abstention,
        array $drift,
    ): float {
        if ($screening) return 15.0;
        $score = 35 + min(20, $regimeCount * 7) + min(25, $stateCount * 12.5);
        if ($retention['status'] === 'retained') $score += 12;
        if ($abstention['status'] === 'confirmed') $score += 10;
        if ($drift['status'] === 'recheck_required') $score -= 15;
        return round(max(0, min(100, $score)), 2);
    }

    private function unknownStateAction(array $result): string
    {
        $action = (string) data_get($result, 'epistemic_boundary.unknown_state_action', 'WAIT');
        return in_array($action, ['WAIT', 'REDUCE_RISK', 'ALLOW_WITH_GUARDS'], true) ? $action : 'WAIT';
    }

    private function normalizeScope(?string $scope): ?string
    {
        if ($scope === null || $scope === '') return null;
        return str_starts_with($scope, 'market:') ? substr($scope, 7) : $scope;
    }
}
