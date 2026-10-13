<?php

namespace App\Services;

use App\Models\AgentDiagnosis;
use App\Models\AgentFailureCase;
use App\Models\AgentKnowledgeCard;
use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningMutationIntent;
use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\Candle;
use App\Models\EliteAgentPortfolio;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\MutationMemory;
use App\Models\Symbol;
use App\Services\MarketData\HistoricalDataQualityService;
use App\Services\MarketData\MarketDataContinuityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Internal method group composed exclusively into LabPopulationService. */
trait LabPopulationAgentFactoryTrait
{
    /** An original, fully-attested native arm clone within the canonical per-seat transaction. */
    private function createAuthorizedCouncilPanelAgent(LabGeneration $generation, string $family, string $origin,
        int $slot, string $target, array $niche): bool
    {
        $intent = $this->authorizedCouncilPanelProof((array) data_get($generation->trigger_context, 'authorized_specialist_council_panel_intent', []));
        $arm = self::authorizedPanelConstructionRoots($intent)[$slot - 1] ?? null;
        $seed = (array) data_get($niche, 'authorized_specialist_council_panel_seed', []);
        if ($origin !== 'authorized_council_panel_root' || $target !== 'portfolio_router' || ! is_array($arm)
            || $family !== $arm['family'] || ($seed['construction_slot'] ?? null) !== $slot
            || ($seed['arm_key'] ?? null) !== $arm['arm_key'] || ($seed['source_model_hash'] ?? null) !== $arm['source_model_hash']
            || ($seed['intent_hash'] ?? null) !== $intent['intent_hash'] || ($seed['reservation_hash'] ?? null) !== $intent['reservation_hash']) {
            throw new \LogicException('AUTHORIZED_COUNCIL_PANEL_SLOT_PREREGISTRATION_INVALID');
        }
        $source = $this->authorizedCouncilPanelSource($arm);
        $this->publishConstructorStage($generation, $slot, 'seat_started');
        // Preserve every physical runtime/assignment owner, including the old
        // council runtime binding. The global unobserved panel preparation owns
        // its controlled rebinding; stripping it here would change the program.
        // Old outcome/evaluation/credit/parent identities are not inherited.
        $metadata = \Illuminate\Support\Arr::only((array) $source->metadata, [
            'architecture', 'strategy_architecture', 'base_strategy', 'tactic', 'tactic_contract',
            'composition_passport', 'composition_runtime_contract', 'confirmation_entry', 'risk_governor',
            'trade_management', 'execution_contract', 'runtime_ensemble', 'agent_constitution',
            'specialist_context_contract', 'contextual_specialist_cell', 'contextual_specialist_contract',
            'session_specialist_contract', 'regime_specialist_contract', 'specialist_council', 'smart_composition',
            'specialist_council_membership', 'causal_learning_cohort', 'instrument_research_assignment',
            'instrument_learning_policy',
        ]);
        $metadata['lab_symbol'] = $generation->laboratory->symbol;
        $metadata['lab_timeframe'] = $generation->laboratory->timeframe;
        $metadata['authorized_specialist_council_panel_seed'] = [...$seed, 'lab_generation_id' => (int) $generation->id,
            'physical_program_hash' => app(ResearchPaperEpochContractService::class)->parameterHash(
                app(LabImmutableEvidenceService::class)->modelRuntimeBasis($source)),
            'independent_evidence_claimed' => false, 'promotion_evidence' => false];
        $metadata['parent_mentor_broker'] = ['parameter_baseline_source' => 'original_preregistered_native_arm',
            'parent_tier' => 'no_parent', 'authority' => 'research_only', 'promotion_evidence' => false];
        $metadata['mutation_constructor_invariant'] = ['protocol' => 'agent_constructor_invariant_v1',
            'kind' => 'exact_preregistered_panel_arm', 'source_model_version_id' => $source->id,
            'source_model_hash' => $arm['source_model_hash'], 'zero_diff_expected' => ($arm['parameter_deltas'] ?? []) === [],
            'causal_baseline_is_genetic_parent' => false, 'promotion_evidence' => false];
        $this->publishConstructorStage($generation, $slot, 'model_persistence');
        $model = ModelVersion::create(['name' => 'authorized_panel_g'.$generation->generation.'_a'.str_pad((string) $slot, 2, '0', STR_PAD_LEFT),
            'strategy' => $source->strategy, 'version' => 'panel_g'.$generation->generation.'_a'.$slot,
            'generation' => $generation->generation, 'status' => 'testing', 'parameters' => $arm['parameters'],
            'description' => 'Prospective original native arm; no inherited outcome or authority.', 'metadata' => $metadata]);
        $this->sealParameterIntegrity($model, $family);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'parent_a_model_version_id' => null, 'parent_b_model_version_id' => null,
            'symbol' => $generation->laboratory->symbol, 'timeframe' => $generation->laboratory->timeframe,
            'strategy_family' => $family, 'origin' => $origin, 'lifecycle_status' => 'draft', 'parameter_diff' => $arm['parameter_deltas'] ?? []]);
        $model->refresh();
        $this->assertAuthorizedCouncilPanelPhysicalClone($model, $source, $arm['parameters']);
        app(LearningKernelService::class)->openEpisode($agent, ['decision_key' => 'generation:'.$generation->id.':agent:'.$agent->id,
            'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe, 'strategy_family' => $family,
            'stage' => 'mutation_selection', 'decision' => 'CONTROL',
            'context' => ['original_panel_work_item_id' => $intent['work_item_id'], 'window_key' => $intent['window_key'],
                'arm_key' => $arm['arm_key'], 'research_only' => true],
            'parameter_hash' => $this->parameterFingerprint($family, (array) $model->parameters)]);
        $this->publishConstructorStage($generation, $slot, 'post_persistence_contracts');
        return true;
    }

    private function assertAuthorizedCouncilPanelPhysicalClone(ModelVersion $model, ModelVersion $source, ?array $parameters = null): void
    {
        $evidence = app(LabImmutableEvidenceService::class);
        if (! $evidence->equivalentJsonValue($model->parameters, $parameters ?? $source->parameters)
            || ! $evidence->equivalentJsonValue($evidence->modelRuntimeBasis($model), $evidence->modelRuntimeBasis($source))
            || ! $evidence->equivalentJsonValue(data_get($model->metadata, 'instrument_research_assignment'), data_get($source->metadata, 'instrument_research_assignment'))
            || ! $evidence->equivalentJsonValue(data_get($model->metadata, 'specialist_council_membership.contextual_cell'), data_get($source->metadata, 'specialist_council_membership.contextual_cell'))
            || ! $evidence->equivalentJsonValue(data_get($model->metadata, 'causal_learning_cohort'), data_get($source->metadata, 'causal_learning_cohort'))
            || ! $evidence->equivalentJsonValue(data_get($model->metadata, 'smart_composition.composition_passport'), data_get($source->metadata, 'smart_composition.composition_passport'))) {
            throw new \LogicException('AUTHORIZED_COUNCIL_PANEL_PERSISTED_PHYSICAL_PROGRAM_DRIFT');
        }
    }

    private function createAgent(
        LabGeneration $generation,
        string $family,
        string $origin,
        int $slot,
        string $target,
        ?array $niche = null,
        ?array $history = null,
        ?string $researchGroup = null,
        int $groupSeat = 0,
        ?string &$failureReason = null,
    ): bool {
        $failureReason = null;
        $lab = $generation->laboratory;
        $niche ??= [];
        if (data_get($generation->trigger_context, 'authorized_specialist_council_panel_intent') !== null
            || data_get($niche, 'authorized_specialist_council_panel_seed') !== null || $origin === 'authorized_council_panel_root') {
            return $this->createAuthorizedCouncilPanelAgent($generation, $family, $origin, $slot, $target, $niche);
        }
        $nativeSeed = (array) data_get($niche, 'native_specialist_council_seed', []);
        $nativeIntent = (array) data_get($generation->trigger_context, 'native_specialist_council_intent', []);
        $nativeCouncilRoot = $nativeSeed !== [] || $nativeIntent !== [];
        $nativeFollowupSource = null;
        if ($nativeCouncilRoot) {
            $nativeSeal = $nativeIntent;
            unset($nativeSeal['intent_hash']);
            $expectedRole = self::nativeCouncilSlotRoles($nativeIntent)[$slot - 1] ?? null;
            if ($origin !== 'native_council_root' || $expectedRole === null
                || ($nativeIntent['protocol'] ?? null) !== self::NATIVE_COUNCIL_INTENT_PROTOCOL
                || ($nativeIntent['purpose'] ?? null) !== 'research' || ($nativeIntent['population_size'] ?? null) !== 6
                || ($nativeIntent['requires_atomic_preparation'] ?? null) !== true
                || ($nativeIntent['authority'] ?? null) !== 'research_only'
                || ($nativeIntent['independent_evidence_claimed'] ?? null) !== false
                || ($nativeIntent['promotion_evidence'] ?? null) !== false
                || ($nativeSeed['protocol'] ?? null) !== self::NATIVE_COUNCIL_INTENT_PROTOCOL
                || ($nativeSeed['slot_role'] ?? null) !== $expectedRole
                || (in_array($nativeIntent['research_purpose'] ?? '', ['spread_context_study', 'native_reachability_depth_audit'], true)
                    && (($nativeSeed['research_purpose'] ?? null) !== $nativeIntent['research_purpose']
                        || ($nativeSeed['construction_slot'] ?? null) !== $slot || isset($nativeIntent['followup_work_item_id'])))
                || ($nativeSeed['intent_hash'] ?? null) !== ($nativeIntent['intent_hash'] ?? null)
                || ($nativeIntent['intent_hash'] ?? null) !== app(ResearchPaperEpochContractService::class)->parameterHash($nativeSeal)) {
                $failureReason = 'NATIVE_COUNCIL_CONSTRUCTOR_SEED_SEAL_INVALID';

                return false;
            }
            if (isset($nativeIntent['followup_work_item_id'])) {
                $resolution = $this->nativeCouncilFollowupResolution($nativeIntent, true, $generation->id);
                $role = ['scalp', 'hour', 'day', 'swing', 'day', 'day'][$slot - 1];
                $nativeFollowupSource = $resolution['native_source_models'][$role] ?? null;
                if (! is_array($nativeFollowupSource) || ($nativeFollowupSource['family'] ?? null) !== $family
                    || app(ResearchPaperEpochContractService::class)->parameterHash($nativeFollowupSource)
                        !== app(ResearchPaperEpochContractService::class)->parameterHash((array) data_get($niche, 'native_council_followup_source'))
                    || ($nativeSeed['followup_work_item_id'] ?? null) !== $nativeIntent['followup_work_item_id']
                    || ($nativeSeed['followup_resolution_hash'] ?? null) !== $nativeIntent['followup_resolution_hash']) {
                    $failureReason = 'NATIVE_COUNCIL_FOLLOWUP_SLOT_PROOF_INVALID';
                    return false;
                }
            } elseif (! empty(data_get($niche, 'native_council_followup_source'))) {
                $failureReason = 'NATIVE_COUNCIL_FOLLOWUP_SLOT_PROOF_INVALID';
                return false;
            }
        }
        $compositionPassport = (array) data_get($niche, 'composition_passport', []);
        if ($compositionPassport !== []) {
            $strategyId = (string) data_get($compositionPassport, 'components.strategy_id', '');
            $passportRuntime = $strategyId !== ''
                ? app(StrategyLibraryCompilerService::class)->runtime($strategyId)
                : null;
            $expectedTactic = (string) data_get($compositionPassport, 'components.tactic_id', '');
            if ((string) data_get($compositionPassport, 'protocol') !== CompositionAuthorityKernelService::PROTOCOL
                || ! is_array($passportRuntime)
                || (string) data_get($passportRuntime, 'family') !== $family
                || (string) data_get($passportRuntime, 'architecture') !== (string) data_get($niche, 'composition_architecture', '')
                || $expectedTactic === ''
                || $expectedTactic !== (string) data_get($niche, 'tactic_library_key', '')) {
                $failureReason = 'COMPOSITION_PASSPORT_RUNTIME_IDENTITY_MISMATCH';

                return false;
            }
        }
        $this->publishConstructorStage($generation, $slot, 'seat_started');
        $cooperativeBlock = (array) data_get($niche, 'cooperative_experiment_block', []);
        $cooperativeSeat = data_get($cooperativeBlock, 'protocol') === CooperativeContextualEvolutionCouncilService::PROTOCOL;
        $researchGroup = $cooperativeSeat
            ? ((string) ($researchGroup ?: 'experiment_block:'.data_get($cooperativeBlock, 'block_type', 'unknown')))
            : ($researchGroup && array_key_exists($researchGroup, self::POPULATION_GROUPS)
                ? $researchGroup
                : $this->researchGroupForTarget($target, $slot));
        $groupSeat = $groupSeat > 0 ? $groupSeat : ($cooperativeSeat
            ? max(1, (int) data_get($cooperativeBlock, 'arm_ordinal', 1))
            : (($slot - 1) % self::POPULATION_GROUP_SEATS) + 1);
        $populationGroup = $cooperativeSeat ? [
            'protocol' => self::POPULATION_GROUP_PROTOCOL,
            'key' => $researchGroup,
            'label' => (string) data_get($cooperativeBlock, 'block_type', 'cooperative_experiment'),
            'axis' => 'cooperative_causal_block',
            'seat' => $groupSeat,
            'cohort_size' => (int) data_get($cooperativeBlock, 'seat_count', 2),
            'search_mode' => 'dynamic_priority',
            'search_role' => (string) data_get($cooperativeBlock, 'arm', 'unknown_arm'),
            'block_key' => data_get($cooperativeBlock, 'block_key'),
            'promotion_evidence' => false,
        ] : $this->populationGroupSeatContract($researchGroup, $groupSeat);
        if ($origin === 'targeted_failure_profile') {
            $populationGroup['rescue_objective'] = data_get($niche, 'rescue_lane');
            $populationGroup['rescue_protocol'] = data_get($niche, 'rescue_protocol');
            $populationGroup['protected_semantic_cell'] = (bool) data_get($niche, 'protected_semantic_cell', true);
            $populationGroup['non_target_parent_freeze'] = (bool) data_get($niche, 'non_target_parent_freeze', true);
        }
        $priorGroupCheckpoint = (array) data_get(
            $generation->trigger_context,
            'group_checkpoint_inputs.'.$researchGroup,
            [],
        );
        $history ??= ($this->historicalLearning->latestForFamily($lab->symbol, $lab->timeframe, $family)?->toArray());
        // Retrieval happens before any compiler path selects a mutation.  The
        // packet is later attached to the newborn agent and settled against
        // its screening outcome; it is a bounded research prior only.
        $causalCohortRole = (string) data_get($niche, 'causal_learning_cohort.role', '');
        // The blinded sibling receives the same baseline, data and compute
        // budget, but no memory packet. Its ordinary cold-start selector may
        // choose a different single gene; the treatment is selector policy,
        // not a duplicated mutation.
        $decisionPacket = $nativeCouncilRoot ? [
            'packet_id' => (string) Str::uuid(), 'status' => 'prospective_native_council_schema_root',
            'positive_lessons' => [], 'harmful_lessons' => [], 'uncertainty_lessons' => [],
            'blocked_mutations' => [], 'recommended_genes' => [], 'retrieval_count' => 0,
            'promotion_evidence' => false,
        ] : match ($causalCohortRole) {
            'blinded', 'repair_guided' => [
                'packet_id' => (string) Str::uuid(),
                'status' => $causalCohortRole === 'repair_guided'
                    ? 'causal_repair_frontier'
                    : 'blinded_counterfactual',
                'positive_lessons' => [],
                'harmful_lessons' => [],
                'uncertainty_lessons' => [],
                'blocked_mutations' => [],
                'recommended_genes' => [],
                'retrieval_count' => 0,
                'promotion_evidence' => false,
            ],
            'hypothesis_guided' => [
                'packet_id' => (string) Str::uuid(),
                'status' => 'pre_registered_legacy_hypothesis',
                'positive_lessons' => [[
                    'lesson_id' => (int) data_get($niche, 'causal_learning_cohort.source_lesson_id', 0),
                    'parameter_key' => (string) data_get($niche, 'causal_learning_cohort.gene', ''),
                    'provenance' => 'legacy_hypothesis_only',
                    'match_level' => 'fresh_post_v2_reproduction_required',
                    'score' => 0,
                    'promotion_evidence' => false,
                ]],
                'harmful_lessons' => [],
                'uncertainty_lessons' => [],
                'blocked_mutations' => [],
                'recommended_genes' => [],
                'retrieval_count' => 0,
                'legacy_hypothesis_grants_credit' => false,
                'promotion_evidence' => false,
            ],
            'memory_guided' => app(LearningKernelService::class)->retrieveCanonicalLesson(
                (int) data_get($niche, 'causal_learning_cohort.source_lesson_id', 0),
                $lab->symbol,
                $lab->timeframe,
                $family,
                [
                    'regime' => data_get($niche, 'regime'),
                    'volatility' => data_get($niche, 'volatility'),
                    'transition_state' => data_get($niche, 'transition_state'),
                    'session' => data_get($niche, 'session', data_get($niche, 'owner_context.session')),
                    'spread_liquidity_state' => data_get($niche, 'spread_liquidity_state', data_get($niche, 'state_cluster.spread_liquidity_state')),
                    'volume_state' => data_get($niche, 'volume_state', data_get($niche, 'state_cluster.volume_state')),
                    'direction' => data_get($niche, 'direction'),
                    'state_cluster_id' => data_get($niche, 'state_cluster.cluster_id', data_get($niche, 'state_cluster')),
                ],
            ),
            default => $this->knowledge->decisionPacket($lab->symbol, $lab->timeframe, $family, $niche),
        };
        if ($causalCohortRole === 'memory_guided' && data_get($decisionPacket, 'status') !== 'ok') {
            $failureReason = 'CAUSAL_LEARNING_SOURCE_RETRIEVAL_FAILED:'.(string) data_get($decisionPacket, 'reason_code', 'UNKNOWN');

            return false;
        }
        // Block-2 instrument value may influence only a later legal mutation:
        // confirmed genes become bounded preferences and forbidden genes join
        // the existing harmful-mutation firewall. Blinded/causal cohort arms
        // remain uncontaminated by global learned policy.
        $instrumentMutationPolicy = $causalCohortRole === '' && ! $nativeCouncilRoot
            ? app(LabInstrumentResearchService::class)->mutationPolicy($lab->symbol, $family, (array) $niche)
            : [
                'protocol' => 'instrument_posterior_mutation_policy_v2',
                'preferred_genes' => [],
                'blocked_genes' => [],
                'sources' => [],
                'reason' => 'causal_or_blinded_arm_isolation',
                'promotion_evidence' => false,
            ];
        $historyKeys = array_values(array_unique([
            ...(array) data_get($history, 'recommended_keys', data_get($history, 'recommended_mutations.keys', [])),
            ...(array) data_get($instrumentMutationPolicy, 'preferred_genes', []),
        ]));
        $historyInsightId = data_get($history, 'insight_id');
        $g98Target = in_array($target, ['monthly_survival', 'regime_coverage', 'volatility_session_stability', 'exit_topology', 'transition_firewall', 'portfolio_router', 'opportunity_recall', 'unknown_state_curiosity'], true);
        $targetedFailureLane = $origin === 'targeted_failure_profile';
        $declaredGene = (string) data_get($niche, 'declared_gene', '');
        $shadowMutationGene = (string) data_get($niche, 'shadow_mutation_gene', '');
        $shadowMutationIndex = max(0, (int) data_get($niche, 'shadow_mutation_index', $slot - 1));
        $repairAnchorId = (int) data_get($niche, 'repair_anchor_id', 0);
        $repairSiblingKind = (string) data_get($niche, 'sibling_kind', data_get($niche, 'repair_anchor_sibling_kind', ''));
        $repairDirection = (string) data_get($niche, 'repair_direction', '');
        $evolutionMode = (string) data_get($niche, 'evolution_mode', '');
        $riskControlOnly = (bool) data_get($niche, 'control_only', false);
        $compositionLane = (string) data_get($niche, 'composition_lane', '');
        $riskMutationGene = (string) data_get($niche, 'risk_mutation_gene', '');
        // A causal triplet displaces the discovery mutation originally
        // assigned to those seats. Its guided/blinded/frozen contract is the
        // sole variable under test and cannot also inherit a structural gene.
        $structuralResearch = $causalCohortRole === ''
            && (bool) data_get($niche, 'structural_research', false);
        // The root portfolio is a pre-registered ablation, not a generic
        // historical-novelty search.  Its declared value must survive the
        // compiler exactly; otherwise a confidence 1.0/0.75/0.5 comparison
        // silently becomes three unrelated mutations.
        $rootPortfolioSeat = (bool) data_get($niche, 'root_experiment_portfolio', false);
        $rootPortfolioIntervention = $rootPortfolioSeat && ! $riskControlOnly;
        $hybridLane = (string) data_get($niche, 'hybrid_evolution_lane', '');
        $hybridMultiGene = $structuralResearch
            && in_array($hybridLane, ['bold_structural', 'adversarial_escape'], true)
            && count((array) data_get($niche, 'declared_genes', [])) >= 2;
        $hybridContract = (array) data_get(
            $niche,
            'hybrid_evolution_contract',
            app(HybridEvolutionContractService::class)->seatContract(
                ['family' => $family, 'target' => $target, 'research_group' => $researchGroup, 'niche' => $niche],
                $hybridLane !== '' ? $hybridLane : 'directed_repair',
                (string) data_get($niche, 'structural_hypothesis_id', hash('sha256', json_encode([$generation->id, $slot, $family, $target], JSON_UNESCAPED_SLASHES))),
                (array) data_get($niche, 'declared_values', []),
                $riskControlOnly,
            ),
        );
        $riskStepMultiplier = max(.25, min(3.0, (float) data_get($niche, 'mutation_step_multiplier', 1.0)));
        $exactPairContract = (array) data_get($niche, 'control_pair_contract', []);
        $exactPairProtocol = (string) data_get($exactPairContract, 'protocol', '');
        $exactPairRole = (string) data_get($exactPairContract, 'role', '');
        $exactPairCandidate = $exactPairProtocol === 'exact_frozen_control_pair_v2'
            && $exactPairRole === 'candidate'
            && (bool) data_get($exactPairContract, 'required_for_candidate', false);
        $exactPairControl = $exactPairProtocol === 'exact_frozen_control_pair_v2'
            && in_array($exactPairRole, ['control', 'uncertainty_abstain'], true);
        $exactPairBaselineModel = null;
        $exactPairControlAgent = null;
        $activationFactorialBaselineModel = null;
        $phaseScopeBaselineModel = null;
        $repairAnchor = null;
        if ($repairAnchorId > 0) {
            $repairAnchor = app(FailureRepairAnchorService::class)->findForTarget(
                $repairAnchorId,
                $lab->symbol,
                $lab->timeframe,
                $family,
                $target,
            );
            // A profile that explicitly names an anchor must not silently
            // degrade into a cold-start mutation if the anchor is stale,
            // cross-market or already consumed.
            if ($repairAnchor === null) {
                $failureReason = 'REPAIR_ANCHOR_NOT_FOUND';

                return false;
            }
        }
        $repairResearchOnly = $repairAnchor !== null
            && in_array($repairSiblingKind, ['frozen_control', 'architecture_escape'], true);
        $repairControlOnly = $repairAnchor !== null
            && $repairSiblingKind === 'frozen_control'
            && (bool) data_get($niche, 'control_only', false);
        // A confirmed mentor contributes one compatible gene only; it is not
        // attached as a genetic parent and cannot bypass the normal gates.
        $skillMentorInput = null;
        $skillMentorApplied = false;
        if ($declaredGene === '' && $causalCohortRole === '' && ! $repairResearchOnly && ! $nativeCouncilRoot) {
            // One mentor probe per research group is enough to test a
            // confirmed capability without collapsing the whole cohort onto
            // one gene. Group seats 2-4 remain independent challengers.
            $skillMentorInput = $groupSeat === 1
                ? app(SkillMentorService::class)->bestFor(
                    $lab->symbol,
                    $lab->timeframe,
                    $family,
                    $target,
                    (string) data_get($niche, 'specialist_role', data_get($niche, 'role', '')),
                )
                : null;
            // A provisional skill is a bounded research prior, not a mentor
            // and never a parent. It is allowed to create one compatible
            // probe when the confirmed mentor frontier is still empty.
            if ($skillMentorInput === null && $groupSeat === 1) {
                $skillMentorInput = app(LearningLaneService::class)->bestProvisionalFor(
                    $lab->symbol,
                    $lab->timeframe,
                    $family,
                    $target,
                    (string) data_get($niche, 'specialist_role', data_get($niche, 'role', '')),
                );
            }
            $mentorGene = (string) data_get($skillMentorInput, 'parameter_key', '');
            if ($mentorGene !== '' && array_key_exists($mentorGene, $this->schemas->schema($family))) {
                $declaredGene = $mentorGene;
                if ($repairDirection === '') {
                    $repairDirection = (string) data_get($skillMentorInput, 'direction', '');
                }
            }
        }
        $architectureEscape = $repairSiblingKind === 'architecture_escape'
            || (bool) data_get($niche, 'architecture_escape', false);
        $stateMachineEscape = data_get($niche, 'state_machine_variant') !== null
            && (string) data_get($niche, 'state_machine_variant') !== ''
            && (string) data_get($niche, 'state_machine_variant') !== 'none';
        $entryTopologyVariant = (string) data_get($niche, 'entry_topology_variant', 'frozen');
        $entryTopologyEscape = $entryTopologyVariant !== '' && $entryTopologyVariant !== 'frozen';
        $regimeClassifierVariant = (string) data_get($niche, 'regime_classifier_variant', 'frozen');
        $regimeClassifierEscape = $regimeClassifierVariant !== '' && $regimeClassifierVariant !== 'frozen';
        $architectureInteractionVariant = (string) data_get($niche, 'architecture_interaction_variant', 'frozen');
        $architectureInteractionEscape = $architectureInteractionVariant !== ''
            && $architectureInteractionVariant !== 'frozen';
        $architectureExperiment = (bool) data_get($niche, 'architecture_experiment', false)
            && ($target === 'architecture' || $architectureEscape || data_get($niche, 'state_machine_variant') !== null
                || $entryTopologyEscape || $regimeClassifierEscape || $architectureInteractionEscape)
            && ($targetedFailureLane || (bool) data_get($niche, 'shadow_only', false));
        $architectureControlOnly = $architectureExperiment
            && (bool) data_get($niche, 'architecture_control_only', false);
        if ($targetedFailureLane && $declaredGene !== '') {
            // A targeted seat is an experiment declaration, not a suggestion.
            // Historical learning may rank a different key, but it may not
            // silently replace the seat's causal gene.
            $historyKeys = [$declaredGene];
        }
        // The screen gate names the observable failure lane, while the
        // differential/range compiler needs the causal gene family that can
        // actually repair it. Previously volatility/exit seats fell through
        // to regime-strength mutations, so FAILED_STRESS_COST was never
        // tested by the lane that claimed to repair it.
        $mutationTarget = match ($target) {
            'volatility_session_stability', 'exit_topology' => 'stress_cost',
            default => $target,
        };
        $curiosityLane = $target === 'unknown_state_curiosity' || $origin === 'curiosity_probe'
            || (bool) data_get($niche, 'curiosity_lane', false);
        // Forward score alone is insufficient evidence for a reusable parent.
        // Weak-PF or high-ruin candidates remain explorers, not gene sources.
        // `qualityParents()` still exposes legacy/loose matches to the
        // diagnostic curriculum, but only the strict projection below may
        // enter the genetic parent list.
        $parentTier = 'semantic_group_root';
        $parentSelection = 'exact_group_root_default';
        $this->publishConstructorStage($generation, $slot, 'parent_frontier');
        $diagnosticParents = $this->qualityParents($lab->symbol, $lab->timeframe, $family, $target, $niche);
        // Archive revival is still diagnostic until it passes the exact
        // semantic boundary below. Failure entries are deliberately never
        // returned by this service.
        $semanticArchiveKey = implode('|', [
            strtoupper((string) $lab->symbol),
            strtoupper((string) $lab->timeframe),
            $family,
            (string) data_get($this->semanticGroups->descriptor($lab->symbol, $lab->timeframe, $family, $niche), 'key'),
            in_array($origin, ['architecture', 'curiosity_probe', 'robust_crossover', 'crossover'], true)
                || $target === 'unknown_state_curiosity' ? 'young' : 'stable',
            hash('sha256', (string) json_encode($diagnosticParents->pluck('id')->values()->all())),
        ]);
        $diagnosticParents = $this->archiveFrontierSnapshots[$semanticArchiveKey]
            ??= $this->evolutionArchive->augmentFrontier(
                $diagnosticParents,
                $lab->symbol,
                $lab->timeframe,
                $family,
                $origin,
                $target,
                $niche,
            );
        $this->publishConstructorStage($generation, $slot, 'parent_frontier_ready');
        $parents = $this->strictSemanticParents(
            $diagnosticParents,
            $lab->symbol,
            $lab->timeframe,
            $family,
            $niche,
        );
        if ($parents->isNotEmpty()) {
            $parentTier = 'validated_frontier';
            $parentSelection = 'exact_semantic_group_parent';
        } else {
            // A valid near-miss may seed a bounded screening experiment only
            // when it already belongs to this exact semantic group. It is
            // never a migration shortcut and never inherits from a legacy
            // unscoped record.
            $diagnosticSeeds = $this->screeningSeedParents($lab->symbol, $lab->timeframe, $family, $target, $niche);
            $parents = $this->strictSemanticParents(
                $diagnosticSeeds,
                $lab->symbol,
                $lab->timeframe,
                $family,
                $niche,
            );
            if ($parents->isNotEmpty()) {
                $parentTier = 'screening_seed';
                $parentSelection = 'exact_semantic_group_screening_seed';
            }
        }
        // A differential router is its own semantic group. It may consume
        // evidence about a hybrid failure, but it must never inherit hybrid
        // parameters as genetic parent material. If no differential frontier
        // exists, keep the child in a same-family seed/default lane.
        // A portfolio council lane carries the exact sealed member that
        // failed in that context.  Keep that performance as the diagnostic
        // source, but do not automatically make it the genetic parent.  A
        // rejected/overfit source can explain a failure while being a poor
        // repair anchor; cloning it simply reproduces the same blind spot.
        $sourcePerformance = data_get($niche, 'source_performance_id')
            ? ModelMarketPerformance::with('modelVersion')->find((int) data_get($niche, 'source_performance_id'))
            : null;
        $sourceParent = $sourcePerformance?->modelVersion;
        $sourceCanBeExactParent = $sourceParent
            && in_array($family, ['hybrid', 'differential_router'], true)
            && (string) $sourcePerformance->strategy_family === $family
            && $sourcePerformance->evidence_status === 'valid'
            && $sourceParent->evidence_status === 'valid'
            && in_array((string) $sourcePerformance->status, ['champion', 'challenger', 'forward_validated', 'paper'], true)
            && $this->parentEligible($sourcePerformance)
            && $this->semanticGroups->exactParentCompatible(
                $sourceParent,
                $lab->symbol,
                $lab->timeframe,
                $family,
                $niche,
            );
        if ($sourceCanBeExactParent) {
            $parents = collect([$sourceParent]);
            $parentTier = 'portfolio_failure_context_parent';
            $parentSelection = 'exact_eligible_failure_context_parent';
        } elseif ($sourcePerformance && $parents->isNotEmpty()) {
            // Keep the operator-facing explanation from the failure-context
            // rescue: the rejected source selected this lane, while the actual
            // genetic parent was chosen from the exact declared group.  The
            // label must never imply that the rejected/legacy source became a
            // parent.
            $parentSelection = 'validated_frontier_fallback_from_failure_context';
        } elseif ($sourcePerformance && $parents->isEmpty()) {
            // Never cross-seed a family with a rejected context model just to
            // avoid an empty parent list.  The defaults remain a safer
            // research baseline than an invalid architecture; the child is
            // still labelled with its failure source below.
            $parentSelection = 'diagnostic_failure_source_only; exact_group_root_default';
        }
        $controlRootSeedAgent = null;
        // A coverage-rescue child has no freedom to substitute a stronger but
        // unrelated frontier parent. Its sole research parent is sealed by
        // the audit, making the non-target replay comparison meaningful. The
        // sealed parent must also belong to this child's semantic family;
        // otherwise the rescue remains diagnostic and starts without a
        // genetic parent rather than importing a foreign strategy.
        $frozenParent = ModelVersion::find((int) data_get($niche, 'frozen_parent_model_version_id', 0));
        if ($frozenParent && data_get($niche, 'protocol') === CoverageRescueAuditService::PROTOCOL) {
            $frozenParentFamily = ModelMarketPerformance::query()
                ->where('model_version_id', $frozenParent->id)
                ->where('symbol', $lab->symbol)
                ->where('timeframe', $lab->timeframe)
                ->latest('id')
                ->value('strategy_family')
                ?: LabAgent::query()->where('model_version_id', $frozenParent->id)
                    ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
                    ->latest('id')->value('strategy_family');
            if ((string) $frozenParentFamily === $family
                && $this->semanticGroups->exactParentCompatible(
                    $frozenParent,
                    $lab->symbol,
                    $lab->timeframe,
                    $family,
                    $niche,
                )) {
                $parents = collect([$frozenParent]);
                $parentTier = 'coverage_rescue_frozen_parent';
                $parentSelection = 'sealed_coverage_rescue_parent';
            } else {
                $parents = collect();
                $parentTier = 'no_parent';
                $parentSelection = 'coverage_rescue_parent_group_mismatch';
            }
        }
        // If no validated exact parent exists, continue from the prior
        // generation's declared control root for this exact specialist cell.
        // This is a seed handoff, not a quality shortcut: the root's
        // catalogue identity, semantic key and parameter hash are checked by
        // ControlRootInheritanceService before it is allowed into $parents.
        // Coverage rescue remains sealed to its audited parent and may not
        // silently substitute a root.
        if ($parents->isEmpty() && ! $frozenParent && ! $nativeCouncilRoot) {
            $controlRootSeedAgent = $this->controlRootInheritance->findSeed($generation, $family, $niche);
            if ($controlRootSeedAgent) {
                $parents = collect([$controlRootSeedAgent->modelVersion]);
                $parentTier = 'control_root_seed';
                $parentSelection = 'control_root_seed_inheritance';
            }
        }
        // The governor now chooses the actual contributor set. Causal lanes
        // receive one anchor; robust/architecture lanes may receive a
        // dynamic multi-parent frontier selected for quality plus novelty.
        $adaptiveParentSelection = $this->adaptiveParentFrontier->select(
            $parents,
            $lab->symbol,
            $lab->timeframe,
            $family,
            $origin,
            $target,
            $niche,
            $slot,
            $generation,
        );
        if ((bool) config('services.lab_selection.adaptive_parent_shadow', false)) {
            $shadowParents = $parents->values();
            $adaptiveParentSelection['parents'] = $shadowParents;
            $adaptiveParentSelection['selected_parent_ids'] = $shadowParents->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $adaptiveParentSelection['contract']['status'] = 'shadow_only';
            $adaptiveParentSelection['contract']['shadow_selected_parent_model_version_ids'] = $adaptiveParentSelection['selected_parent_ids'];
        }
        $parents = $adaptiveParentSelection['parents'];
        if ($parents->isEmpty()) {
            // An archive can contain only research seeds or stale projections.
            // Do not label that empty result as a validated frontier and do
            // not let it block a legal canonical control-root handoff.
            $parentTier = 'no_parent';
            if ($controlRootSeedAgent === null && ! $frozenParent && ! $nativeCouncilRoot) {
                $controlRootSeedAgent = $this->controlRootInheritance->findSeed($generation, $family, $niche);
                if ($controlRootSeedAgent) {
                    $parents = collect([$controlRootSeedAgent->modelVersion]);
                    $parentTier = 'control_root_seed';
                    $parentSelection = 'control_root_seed_inheritance';
                    $adaptiveParentSelection = $this->adaptiveParentFrontier->select(
                        $parents,
                        $lab->symbol,
                        $lab->timeframe,
                        $family,
                        $origin,
                        $target,
                        $niche,
                        $slot,
                        $generation,
                    );
                    $parents = $adaptiveParentSelection['parents'];
                }
            }
            // A genuinely parentless specialist is an explicit no-parent
            // starting state. Keep the failure-context explanation in
            // portfolio_council_parent_selection and diagnostic metadata;
            // it must not be mistaken for an attached genetic parent.
            if ($parents->isEmpty() && $controlRootSeedAgent === null && ! $frozenParent) {
                $parentSelection = 'no_parent_available';
            }
        } elseif ((bool) data_get($adaptiveParentSelection, 'contract.research_seed_only', false)) {
            $parentTier = 'screening_seed';
            $parentSelection = 'archive_revival_research_seed';
        }
        $this->publishConstructorStage($generation, $slot, 'migration_plan');
        $adaptiveParentSelection['contract']['island_migration'] = $this->archiveMigrationSnapshots[$semanticArchiveKey]
            ??= $this->evolutionArchive->migrationPlan(
                $lab->symbol,
                $lab->timeframe,
                $family,
                $niche,
                (int) config('services.lab_selection.archive_migration_limit', 0),
            );
        // Do not let a repair-only child inherit the ordinary frontier in the
        // archive projection either. The failed vector is a baseline, while
        // the new child must be represented as parentless until confirmation.
        // Keep diagnostic candidates visible, but mark no selected parent.
        if ($nativeCouncilRoot) {
            // These models are new prospective references, not descendants or saved
            // control vectors. Ordinary parent/frontier observations grant them no material.
            $parents = collect();
            $controlRootSeedAgent = null;
            $frozenParent = null;
            $parentTier = 'no_parent';
            $parentSelection = 'prospective_native_council_schema_root';
            $adaptiveParentSelection = [
                'parents' => collect(), 'selected_parent_ids' => [], 'capability_genome' => [],
                'contract' => ['status' => 'prospective_native_council_schema_root',
                    'selected_parent_model_version_ids' => [], 'genetic_parent_forbidden' => true,
                    'promotion_evidence' => false],
            ];
        }
        $archiveSelectedParents = $repairAnchor !== null ? collect() : $parents;
        $archiveSelection = $repairAnchor !== null
            ? [
                ...$adaptiveParentSelection,
                'selected_parent_ids' => [],
                'parents' => collect(),
                'contract' => [
                    ...((array) ($adaptiveParentSelection['contract'] ?? [])),
                    'status' => 'repair_anchor_only',
                    'selected_parent_model_version_ids' => [],
                    'genetic_parent_forbidden' => true,
                    'parent_selection_reason' => 'rejected_pending_paired_replay',
                    'parent_selection_reasons' => [
                        'rejected_pending_paired_replay',
                        'rejected_no_independent_forward',
                    ],
                    'reason_ledger_protocol' => 'parent_selection_reason_ledger_v1',
                    'repair_anchor_id' => $repairAnchor->id,
                    'promotion_evidence' => false,
                ],
            ]
            : $adaptiveParentSelection;
        $this->publishConstructorStage($generation, $slot, 'mutation_compile');
        $parentCount = $parents->count();
        $parentA = $parents->first();
        $parentB = $parentCount > 1 ? $parents->get(1) : null;
        $cohortExperimentKey = (string) data_get($niche, 'causal_learning_cohort.experiment_key', '');
        $causalBaselineModelId = (int) data_get($niche, 'causal_learning_cohort.baseline_model_version_id', 0);
        if ($cohortExperimentKey !== '' && $causalBaselineModelId > 0) {
            $sourceControl = LabAgent::query()
                ->whereKey((int) data_get($niche, 'causal_learning_cohort.source_control_agent_id', 0))
                ->where('model_version_id', $causalBaselineModelId)
                ->where('symbol', $lab->symbol)
                ->where('timeframe', $lab->timeframe)
                ->where('strategy_family', $family)
                ->first();
            $forcedParent = $sourceControl ? ModelVersion::query()->find($causalBaselineModelId) : null;
            if (! $forcedParent) {
                $failureReason = 'CAUSAL_LEARNING_SOURCE_BASELINE_MISSING';

                return false;
            }
            $parents = collect([$forcedParent]);
            $parentA = $forcedParent;
            $parentB = null;
            $parentCount = 1;
            $parentTier = 'causal_source_research_baseline';
            $parentSelection = 'same_canonical_source_baseline_for_all_counterfactual_arms';
            $adaptiveParentSelection['parents'] = $parents;
            $adaptiveParentSelection['selected_parent_ids'] = [(int) $forcedParent->id];
            $adaptiveParentSelection['contract']['causal_counterfactual_parent_lock'] = [
                'source_control_agent_id' => (int) $sourceControl->id,
                'source_pair_id' => (int) data_get($niche, 'causal_learning_cohort.source_pair_id', 0),
                'parent_model_version_id' => (int) $forcedParent->id,
                'role' => $causalCohortRole,
                'research_baseline_only_until_confirmation' => true,
                'promotion_evidence' => false,
            ];
        }
        if ($exactPairCandidate) {
            $pairKey = (string) data_get($exactPairContract, 'pair_key', '');
            $exactPairControlAgent = $generation->agents()
                ->with(['modelVersion', 'parentA', 'parentB'])
                ->get()
                ->first(fn (LabAgent $candidate): bool => $candidate->modelVersion !== null
                    && (string) data_get($candidate->modelVersion->metadata, 'control_pair_contract.protocol', '') === 'exact_frozen_control_pair_v2'
                    && (string) data_get($candidate->modelVersion->metadata, 'control_pair_contract.role', '') === 'control'
                    && hash_equals(
                        $pairKey,
                        (string) data_get($candidate->modelVersion->metadata, 'control_pair_contract.pair_key', ''),
                    )
                );
            if ($pairKey === '' || ! $exactPairControlAgent?->modelVersion) {
                $failureReason = 'EXACT_PAIR_CONTROL_MUST_BE_PERSISTED_FIRST';

                return false;
            }
            $exactPairBaselineModel = $exactPairControlAgent->modelVersion;
            $pairedParentIds = array_values(array_filter([
                (int) $exactPairControlAgent->parent_a_model_version_id,
                (int) $exactPairControlAgent->parent_b_model_version_id,
            ]));
            $pairedParentModels = ModelVersion::query()->whereIn('id', $pairedParentIds)->get()->keyBy('id');
            $parents = collect($pairedParentIds)
                ->map(fn (int $id): ?ModelVersion => $pairedParentModels->get($id))
                ->filter()
                ->values();
            $parentA = $parents->first();
            $parentB = $parents->count() > 1 ? $parents->get(1) : null;
            $parentCount = $parents->count();
            $parentTier = $parentA ? 'paired_control_genetic_source' : 'no_parent';
            $parentSelection = $parentA
                ? 'same_genetic_source_as_exact_paired_control'
                : 'no_genetic_parent; same_generation_exact_control_baseline';
            $controlRootSeedAgent = null;
            $adaptiveParentSelection['parents'] = $parents;
            $adaptiveParentSelection['selected_parent_ids'] = $pairedParentIds;
            $adaptiveParentSelection['contract'] = [
                ...((array) data_get($adaptiveParentSelection, 'contract', [])),
                'status' => 'exact_pair_source_locked',
                'selected_parent_model_version_ids' => $pairedParentIds,
                'causal_baseline_model_version_id' => (int) $exactPairBaselineModel->id,
                'causal_baseline_is_genetic_parent' => false,
                'control_agent_id' => (int) $exactPairControlAgent->id,
                'pair_key' => $pairKey,
                'promotion_evidence' => false,
            ];
            $niche['control_pair_contract'] = [
                ...$exactPairContract,
                'control_agent_id' => (int) $exactPairControlAgent->id,
                'causal_baseline_model_version_id' => (int) $exactPairBaselineModel->id,
                'causal_baseline_is_genetic_parent' => false,
            ];
            $exactPairContract = (array) $niche['control_pair_contract'];
        }
        if ((string) data_get($cooperativeBlock, 'block_type') === 'activation_factorial'
            && (string) data_get($cooperativeBlock, 'arm') === 'control') {
            $activationSource = LabAgent::query()->with(['modelVersion', 'generation'])
                ->find((int) data_get($niche, 'activation_factorial.source_agent_id', 0));
            $sourceParameters = (array) $activationSource?->modelVersion?->parameters;
            $sourceParameterHash = hash('sha256', json_encode(
                $this->schemas->canonicalizeForIdentity($family, $sourceParameters),
                JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            ));
            if (! $activationSource?->modelVersion
                || (int) $activationSource->model_version_id !== (int) data_get($niche,
                    'activation_factorial.source_model_version_id', 0)
                || (int) $activationSource->generation?->ai_laboratory_id !== (int) $lab->id
                || (string) $activationSource->strategy_family !== $family
                || (array) data_get($activationSource->modelVersion->metadata,
                    'smart_composition.composition_passport.components', [])
                    !== (array) data_get($compositionPassport, 'components', [])
                || ! hash_equals((string) data_get($niche,
                    'activation_factorial.source_parameter_hash', ''), $sourceParameterHash)) {
                $failureReason = 'ACTIVATION_FACTORIAL_SOURCE_BASELINE_MISSING';

                return false;
            }
            $activationFactorialBaselineModel = $activationSource->modelVersion;
        }
        if ((string) data_get($cooperativeBlock, 'block_type') === 'phase_scope_probe'
            && (string) data_get($cooperativeBlock, 'arm') === 'phase_control') {
            $phaseSource = LabAgent::query()->with(['modelVersion', 'generation'])
                ->find((int) data_get($niche, 'phase_scope_probe.source_agent_id', 0));
            $sourceParameters = (array) $phaseSource?->modelVersion?->parameters;
            $sourceHash = hash('sha256', json_encode(
                $this->schemas->canonicalizeForIdentity($family, $sourceParameters),
                JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            ));
            if (! $phaseSource?->modelVersion
                || (int) $phaseSource->model_version_id !== (int) data_get($niche,
                    'phase_scope_probe.source_model_version_id', 0)
                || (int) $phaseSource->generation?->ai_laboratory_id !== (int) $lab->id
                || (string) $phaseSource->strategy_family !== $family
                || (array) data_get($phaseSource->modelVersion->metadata,
                    'smart_composition.composition_passport.components', [])
                    !== (array) data_get($compositionPassport, 'components', [])
                || ! hash_equals((string) data_get($niche,
                    'phase_scope_probe.source_parameter_hash', ''), $sourceHash)) {
                $failureReason = 'PHASE_SCOPE_SOURCE_BASELINE_MISSING';

                return false;
            }
            $phaseScopeBaselineModel = $phaseSource->modelVersion;
        }
        if ((string) data_get($cooperativeBlock, 'block_type') === 'activation_factorial'
            && (string) data_get($cooperativeBlock, 'arm') === 'b_only') {
            $activationControl = $generation->agents()->with('modelVersion')->get()->first(
                fn (LabAgent $candidate): bool => $candidate->modelVersion !== null
                    && (string) data_get($candidate->modelVersion->metadata, 'cooperative_experiment_block.block_key')
                        === (string) data_get($cooperativeBlock, 'block_key')
                    && (string) data_get($candidate->modelVersion->metadata, 'cooperative_experiment_block.arm')
                        === 'control',
            );
            if (! $activationControl?->modelVersion
                || (string) $activationControl->strategy_family !== $family
                || (array) data_get($activationControl->modelVersion->metadata,
                    'smart_composition.composition_passport.components', [])
                    !== (array) data_get($compositionPassport, 'components', [])) {
                $failureReason = 'ACTIVATION_FACTORIAL_FROZEN_BASELINE_MISSING';

                return false;
            }
            $activationFactorialBaselineModel = $activationControl->modelVersion;
        }
        // A same-cell root can be used as a frozen parameter baseline when
        // no promotable parent exists.  It is deliberately *not* attached to
        // parent_a: a 1.22 PF research seed must not masquerade as a 1.30 PF
        // genetic parent or contribute promotion evidence.
        $frozenResearchSeedAgent = null;
        if ($parentA === null && $repairAnchor === null && ! $frozenParent && $exactPairBaselineModel === null && ! $nativeCouncilRoot) {
            $frozenResearchSeedAgent = $this->frozenResearchSeed($generation, $family, $niche);
            if ($frozenResearchSeedAgent) {
                $parentSelection = 'no_genetic_parent; same_cell_frozen_research_seed';
            }
        }
        $parentLane = (string) data_get(
            $niche,
            'parent_lane',
            $parentA ? 'autonomous' : 'autonomous',
        );
        $parentMentorContract = in_array($parentLane, ['mentor_assisted', 'cross_skill_composition'], true)
            ? $this->parentMentorBroker->propose(
                $parents,
                $lab->symbol,
                $lab->timeframe,
                $family,
                $target,
                $niche,
                $parentLane,
                array_keys($this->schemas->schema($family)),
            )
            : $this->parentMentorBroker->autonomousContract(
                $lab->symbol,
                $lab->timeframe,
                $family,
                $target,
                $niche,
            );
        $parentMentorApplied = false;
        $suggestedGene = (string) data_get($parentMentorContract, 'parent_suggestion.changed_gene', '');
        if ($suggestedGene !== ''
            && $declaredGene === ''
            && ! $targetedFailureLane
            && ! $repairResearchOnly
            && array_key_exists($suggestedGene, $this->schemas->schema($family))) {
            // The broker supplies one hypothesis only. The normal compiler
            // still creates the child's mutation; no parent parameter vector
            // is copied as a mentor result.
            $declaredGene = $suggestedGene;
            if ($repairDirection === '') {
                $repairDirection = (string) data_get($parentMentorContract, 'parent_suggestion.direction', '');
            }
            $parentMentorApplied = true;
        }
        $parentMentorContract['child_adoption'] = [
            'accepted' => $parentMentorApplied,
            'changed_gene' => $parentMentorApplied ? $declaredGene : null,
            'child_specific_mutation_required' => true,
            'promotion_evidence' => false,
        ];

        if ($repairAnchor !== null) {
            // This is the critical distinction: a failed model contributes
            // an immutable repair baseline, never genetic parent material.
            $parents = collect();
            $parentA = null;
            $parentB = null;
            $controlRootSeedAgent = null;
            $parentTier = 'repair_anchor';
            $parentSelection = 'repair_anchor_only';
            $adaptiveParentSelection['selected_parent_ids'] = [];
            $adaptiveParentSelection['parents'] = collect();
            $adaptiveParentSelection['contract'] = [
                ...((array) ($adaptiveParentSelection['contract'] ?? [])),
                'status' => 'repair_anchor_only',
                'selected_parent_model_version_ids' => [],
                'genetic_parent_forbidden' => true,
                'parent_selection_reason' => 'rejected_pending_paired_replay',
                'parent_selection_reasons' => [
                    'rejected_pending_paired_replay',
                    'rejected_no_independent_forward',
                ],
                'reason_ledger_protocol' => 'parent_selection_reason_ledger_v1',
                'repair_anchor_id' => $repairAnchor->id,
                'promotion_evidence' => false,
            ];
            $parentLane = 'autonomous';
            $parentMentorApplied = false;
            $parentMentorContract = $this->parentMentorBroker->autonomousContract(
                $lab->symbol,
                $lab->timeframe,
                $family,
                $target,
                $niche,
            );
            $parentMentorContract['status'] = 'repair_anchor_parent_forbidden';
            $parentMentorContract['inheritance_firewall'] = [
                'protocol' => 'parent_inheritance_firewall_v1',
                'direct_parameter_vector_copy' => false,
                'genetic_parent_forbidden' => true,
                'repair_anchor_is_baseline_only' => true,
                'promotion_evidence' => false,
            ];
        }
        // Architecture discovery and robust crossover are multi-gene,
        // capability-level hypotheses. Treating architecture as a one-gene
        // repair here made EliteAgentPassportService demand a causal paired
        // replay that the child was never designed to provide. Causal lanes
        // keep the isolated repair lineage; discovery lanes keep their full
        // independent replay and promotion gates.
        $repairOrigins = ['gate_targeted', 'risk_exit', 'causal_isolation', 'g98_council', 'targeted_failure_profile', 'coverage_rescue'];
        $parentRepair = (array) data_get($parentA?->metadata, 'repair_lineage', []);
        $repairLineage = in_array($origin, $repairOrigins, true) ? [
            'protocol' => 'bounded_repair_lineage_v1',
            'root_model_version_id' => data_get($parentRepair, 'root_model_version_id', $parentA?->id),
            'parent_model_version_id' => $parentA?->id,
            'attempt' => max(1, (int) data_get($parentRepair, 'attempt', 0) + 1),
            'independent_forward_replays_required' => 2,
            'status' => 'active',
            'rule' => 'After two failed independent repair replays, quarantine the repair lineage instead of tuning indefinitely.',
        ] : null;
        if ($repairAnchor !== null) {
            $repairLineage = [
                'protocol' => FailureRepairAnchorService::PROTOCOL,
                'status' => 'active',
                'repair_anchor_id' => (int) $repairAnchor->id,
                'source_lab_agent_id' => (int) $repairAnchor->source_lab_agent_id,
                'source_model_version_id' => (int) $repairAnchor->source_model_version_id,
                'source_generation_id' => (int) $repairAnchor->source_lab_generation_id,
                'failure_reason' => $repairAnchor->failure_reason,
                'failure_target' => $repairAnchor->failure_target,
                'immutable_parameter_fingerprint' => $repairAnchor->parameter_fingerprint,
                'attempt' => $architectureEscape ? 0 : 1,
                'mode' => $architectureEscape ? 'architecture_escape_hypothesis' : 'bounded_gene_repair',
                'parent_eligible' => false,
                'mutation_credit_status' => $architectureEscape ? 'architecture_hypothesis_no_gene_credit' : 'pending_paired_full_replay_forward',
                'independent_forward_replays_required' => 2,
                'rule' => 'Failed parameters are a repair baseline only; mutation credit and parent eligibility are earned after paired screening, full replay and independent forward confirmation.',
                'promotion_evidence' => false,
            ];
        }
        // Merge defaults with the parent instead of replacing the defaults
        // with a legacy parameter map. New specialist genes must be present
        // before the next bounded mutation is selected.
        // Mentor-assisted children use the parent as a suggestion source only
        // and start execution construction from the canonical schema defaults.
        // Autonomous and cross-skill lanes retain the exact semantic parent
        // baseline with full provenance.
        $mentorOnlyLane = $parentLane === 'mentor_assisted';
        $parameterBaselineParent = $exactPairBaselineModel
            ?: $activationFactorialBaselineModel
            ?: $phaseScopeBaselineModel
            ?: ($mentorOnlyLane ? null : ($parentA ?: $frozenResearchSeedAgent?->modelVersion));
        $base = [...$this->schemas->defaults($family), ...($parameterBaselineParent?->parameters ?? [])];
        // A sealed coverage parent is already validated as belonging to this
        // child's family. Intersecting with the child schema remains a final
        // guard against stale legacy parameters crossing the family boundary.
        $base = array_intersect_key($base, $this->schemas->schema($family));
        if ($nativeFollowupSource !== null) {
            // Exact, server-validated prospective vectors. No qualified parent,
            // inherited authority or new schema default is introduced here.
            $base = $nativeFollowupSource['parameters'];
        }
        if ($causalCohortRole !== ''
            || in_array((string) data_get($cooperativeBlock, 'block_type'),
                ['phase_scope_probe', 'activation_factorial'], true)) {
            // Counterfactual arms are measured against the historical source
            // model, not against today's schema defaults. Adding a newly
            // introduced default to only the child/control silently changes
            // the execution hash and makes a "frozen" control non-frozen.
            $base = array_intersect_key(
                (array) $parameterBaselineParent?->parameters,
                $this->schemas->schema($family),
            );
            if ($base === []) {
                $failureReason = 'CAUSAL_LEARNING_EXACT_BASELINE_EMPTY';

                return false;
            }
        }
        if ($rootPortfolioSeat) {
            // Controls and candidates must share the same schema-valid frozen
            // vector. Historical parent maps can contain superseded values
            // outside today's bounded schema; retain valid fields only and
            // fall back per field, rather than letting a root ablation fail
            // before its declared gene is even applied.
            $base = $this->schemas->defaults($family);
        }
        // A transition/risk router must observe the high-volatility envelope
        // it is responsible for protecting.  `high_volatility_wait=true` is
        // valid for ordinary signal specialists, but it made the G115 router
        // lanes structurally blind and produced zero-trade passports.  This
        // is a declared role baseline, not a promotion-gate relaxation or a
        // hidden mutation; the unchanged transition firewall and all final
        // gates still decide whether the router is useful.
        $semanticRole = (string) data_get(
            $niche,
            'paired_semantic_role',
            data_get($niche, 'specialist_role', data_get($niche, 'role', '')),
        );
        // A causal triplet must retain the source model's exact semantic cell
        // for lineage/preflight, but that label is not a request to recompile
        // the source baseline through today's council policy. In particular,
        // the historical `frozen_control` semantic role has no council policy
        // spec and previously reset the blinded cold-start mutation to zero.
        // Cooperative pairing may reuse a shadow frozen-control template for
        // both arms. The block owns candidate/control identity; the inherited
        // control label has no mutation policy and would reset its candidate
        // to the baseline. Keep the semantic label for provenance only.
        $councilRole = $causalCohortRole !== '' || ($cooperativeSeat && $semanticRole === 'frozen_control')
            ? '' : $semanticRole;
        $roleCompleteCouncil = (bool) data_get($niche, 'role_complete_council', false);
        if ($councilRole === 'transition_risk_router' && array_key_exists('high_volatility_wait', $base)) {
            $base['high_volatility_wait'] = false;
        }
        // A role owns a bounded operating envelope, not an unrestricted
        // boolean ablation.  These are explicit role baselines and therefore
        // are excluded from the causal diff; the child may still change one
        // declared research gene below.  In particular, a transition/risk
        // owner can never learn by disabling its own transition firewall.
        foreach ($this->councilRoleBaseline($councilRole, $family) as $key => $value) {
            if (array_key_exists($key, $base)) {
                $base[$key] = $value;
            }
        }
        if ($repairAnchor !== null) {
            $anchorParameters = array_intersect_key(
                (array) $repairAnchor->parameter_snapshot,
                $this->schemas->schema($family),
            );
            if ($anchorParameters === []) {
                $failureReason = 'REPAIR_ANCHOR_PARAMETER_SNAPSHOT_EMPTY';

                return false;
            }
            // The failed vector is the exact repair baseline. It is never
            // edited in storage; only the child copy below may mutate.
            // The frozen control is a byte-for-byte executable copy of the
            // immutable anchor snapshot. Repair siblings may receive current
            // schema defaults for newly introduced genes, but the control
            // must not: otherwise its parameter order/value vector is no
            // longer the exact paired baseline being measured.
            $base = $repairControlOnly
                ? $anchorParameters
                : [...$this->schemas->defaults($family), ...$anchorParameters];
        }
        $mutationScope = ($g98Target || in_array($origin, ['gate_targeted', 'risk_exit', 'causal_isolation', 'architecture', 'g98_council', 'targeted_failure_profile', 'curiosity_probe'], true))
            ? $this->mutationScope($lab->symbol, $lab->timeframe, $family, $slot)
            : null;
        $councilRegime = data_get($niche, 'regime');
        // Knowledge cards are a search prior for every compiler lane, not
        // only for the generic mutate() path. A council niche can be more
        // specific than mutationScope(), so union both scopes.
        $mutationBudget = app(AgentProfessionalExamService::class)->mutationBudget($lab->symbol, $lab->timeframe, $family);
        $budgetBlockedKeys = array_values(array_diff(
            array_keys($this->schemas->schema($family)),
            app(AgentProfessionalExamService::class)->allowedMutationKeys(
                array_keys($this->schemas->schema($family)), $mutationBudget,
            ),
        ));
        $failureCircuit = app(FailureCurriculumService::class)->mutationCircuit(
            $lab->symbol,
            $lab->timeframe,
            $family,
            $mutationScope,
        );
        $knowledgeBlockedKeys = array_values(array_unique(array_merge(
            $this->knowledge->blockedMutationKeys($lab->symbol, $lab->timeframe, $family, $mutationScope),
            $this->knowledge->blockedMutationKeys($lab->symbol, $lab->timeframe, $family, $councilRegime),
            (array) data_get($decisionPacket, 'blocked_mutations', []),
            (array) data_get($failureCircuit, 'blocked_keys', []),
            (array) data_get($instrumentMutationPolicy, 'blocked_genes', []),
            $budgetBlockedKeys,
        )));
        if ($exactPairCandidate) {
            // Exact discovery pairs must remain executable. Downstream
            // risk/management genes stay visible in the curriculum, but when
            // this frozen baseline has not earned their dependency phase they
            // cannot be selected by memory, bandit, role policy or the generic
            // mutator. The pair spends its seat on a legal Edge prerequisite.
            $dependencyDeniedKeys = collect(array_keys($this->schemas->schema($family)))
                ->filter(fn (string $gene): bool => $this->dependencyOrderedGene($gene))
                ->reject(fn (string $gene): bool => (bool) data_get(
                    app(DependencyAwareEdgeGenesisFoundryService::class)
                        ->mutationAdmission($parameterBaselineParent ?: $parentA, $gene),
                    'allowed',
                    false,
                ))
                ->values()
                ->all();
            $knowledgeBlockedKeys = array_values(array_unique([
                ...$knowledgeBlockedKeys,
                ...$dependencyDeniedKeys,
            ]));
        }
        $blockedMutationDirections = collect([
            ...array_values(array_filter((array) data_get($decisionPacket, 'blocked_mutation_directions', []),
                fn (array $constraint): bool => array_key_exists((string) ($constraint['parameter_key'] ?? ''), $base)
                    && ($base[$constraint['parameter_key']] == ($constraint['old_value'] ?? null)))),
            ...$this->knowledge->blockedMutationDirections($lab->symbol, $lab->timeframe, $family, $mutationScope),
            ...$this->knowledge->blockedMutationDirections($lab->symbol, $lab->timeframe, $family, $councilRegime),
            ...((array) data_get($failureCircuit, 'blocked_directions', [])),
        ])->unique('signature')->values()->all();
        if ($causalCohortRole === 'blinded') {
            // The blind arm is pre-registered from schema + target only. No
            // learned blacklist, direction memory or mentor may influence it.
            $knowledgeBlockedKeys = [];
            $blockedMutationDirections = [];
        }
        // The differential target regime is an execution-contract coordinate,
        // not the causal gene under test.  When a council child is seeded from
        // a legacy/unscoped parent, the router schema defaults to trend_down; comparing
        // that default with a trend_up/range council lane falsely creates a
        // multi-gene child and invalidates the real one-gene experiment.  Pin
        // the contract coordinate before compiling the mutation so only the
        // declared lane gene appears in parameter_diff.
        if ($family === 'differential_router'
            && ($g98Target || $targetedFailureLane)
            && is_string($councilRegime)
            && in_array($councilRegime, ['trend_up', 'trend_down', 'range'], true)
            && array_key_exists('differential_target_regime', $base)) {
            $base['differential_target_regime'] = $councilRegime;
        }
        // Slots are interleaved by family (1, 5, 9...).  Use a family-local
        // experiment index so every architecture receives representation;
        // using the raw slot would give a family the same modulo forever.
        $architectureSeed = intdiv($slot - 1, max(1, count($lab->strategy_families))) + 1;
        $architectureParent = $mentorOnlyLane ? null : $parentA;
        $architecture = $g98Target && $architectureParent
            ? (string) data_get($architectureParent->metadata, 'strategy_architecture', $this->architectureBaseStrategy($family))
            : $this->selectArchitecture($lab->symbol, $lab->timeframe, $family, $origin, $architectureSeed, $mutationScope, $architectureParent);
        if ($architectureExperiment) {
            $frozenArchitecture = (string) data_get(
                $parentA?->metadata,
                'strategy_architecture',
                (self::ARCHITECTURES[$family][0] ?? $this->architectureBaseStrategy($family)),
            );
            $declaredArchitecture = data_get($niche, 'architecture_variant');
            $architecture = $architectureControlOnly
                ? $frozenArchitecture
                : (is_string($declaredArchitecture) && $declaredArchitecture !== ''
                    ? $declaredArchitecture
                    : $frozenArchitecture);
            // The temporal state-machine escape is an executable topology
            // hypothesis carried by its declared state_machine_variant gene.
            // Keep the base strategy architecture fixed so the child has one
            // causal change rather than silently combining a router swap with
            // a state-machine mutation.
            if (! $architectureControlOnly && ! $stateMachineEscape && $architecture === $frozenArchitecture) {
                $alternativeArchitectures = array_values(array_diff(
                    self::ARCHITECTURES[$family] ?? [],
                    [$frozenArchitecture],
                ));
                if ($alternativeArchitectures !== []) {
                    $architecture = $alternativeArchitectures[0];
                }
            }
            // The requested architecture can equal the frozen baseline. In
            // that case the compiler selects a real alternate topology above;
            // persist that executable choice back into the sealed lane rather
            // than leaving downstream attribution pointed at the no-op request.
            if (! $architectureControlOnly && ! $stateMachineEscape) {
                $niche['architecture_variant'] = $architecture;
            }
        }
        // Composition lanes choose an implemented runtime topology, not just
        // metadata. This happens after the ordinary architecture selection so
        // the library contract is the final executable identity for its seat.
        $compositionArchitecture = (string) data_get($niche, 'composition_architecture', '');
        if (in_array($compositionArchitecture, self::ARCHITECTURES[$family] ?? [], true)
            && ($compositionPassport !== []
                || (in_array($compositionLane, ['strategy_composition', 'tactic_mutation'], true)
                    && ! $structuralResearch
                    && ! $architectureExperiment))) {
            $architecture = $compositionArchitecture;
        }
        if ($nativeFollowupSource !== null) $architecture = $nativeFollowupSource['strategy_architecture'];
        $tacticArchitecture = (string) data_get($niche, 'tactic_library_key', $architecture);
        $tacticContract = $this->tactics->for($family, $tacticArchitecture, $target);
        $strategyLibraryContract = (array) data_get($niche, 'strategy_library_contract', []);
        if ($strategyLibraryContract === [] && filled(data_get($niche, 'strategy_library_id'))) {
            $strategyLibraryContract = app(StrategyLibraryCompilerService::class)->compile((string) data_get($niche, 'strategy_library_id'));
        }
        $riskLibraryContract = (array) data_get($niche, 'risk_library_contract', []);
        $semanticGroup = $this->semanticGroups->descriptor(
            $lab->symbol,
            $lab->timeframe,
            $family,
            $niche,
            $architecture,
        );

        $skillCrossoverSources = [];
        $capabilityGeneProvenance = (array) data_get($adaptiveParentSelection, 'capability_genome.parameter_sources', []);
        $noLegalOwnerMutationControl = false;
        if ($causalCohortRole !== '') {
            // The exact guided/blinded intervention is applied at the final
            // causal boundary below. Keeping the compiler frozen here avoids
            // hidden bandit/memory selection and whole-genome novelty fallbacks.
            $parameters = $base;
        } elseif ($riskControlOnly) {
            // Risk governor controls are byte-for-byte frozen references for
            // the paired cohort. They are not allowed to become accidental
            // mutations merely because a compiler found a preferred gene.
            $parameters = $base;
        } elseif ($repairControlOnly) {
            // The fourth sibling is a true frozen control: it uses the
            // immutable anchor vector byte-for-byte and never participates in
            // novelty, role nudging or historical mutation selection.
            $parameters = $base;
        } elseif ($architectureExperiment) {
            // Architecture seats compare topology metadata while keeping the
            // entire executable parameter vector frozen. This gives the
            // cohort a real control/variant contrast without a hidden second
            // parameter mutation.
            $parameters = $base;
        } elseif ($structuralResearch) {
            // Structural seats start from the frozen executable vector and
            // apply exactly one declared topology/classifier gene below. No
            // scalar compiler, historical nudge or role prior may be mixed
            // into this hypothesis.
            $parameters = $base;
        } elseif ($niche && $family === 'hybrid' && (
            data_get($niche, 'regime') === 'range'
            || data_get($niche, 'specialist_role', data_get($niche, 'role')) === 'transition_risk_router'
        )) {
            $parameters = $this->rangeCouncilSingleGene(
                $base,
                $slot,
                $mutationTarget,
                data_get($niche, 'recall_variant'),
                $niche,
                $knowledgeBlockedKeys,
                $blockedMutationDirections,
            );
        } elseif ($family === 'differential_router') {
            // General generations may use the differential architecture, but
            // exactly one target-lane gene is allowed to move.  Parent
            // parameters remain frozen outside that declared lane.
            $parameters = $this->differentialSingleGene(
                $base,
                $slot,
                $councilRegime ?: $mutationScope,
                $mutationTarget,
                data_get($niche, 'recall_variant'),
                $niche,
                $knowledgeBlockedKeys,
                $blockedMutationDirections,
            );
        } elseif (in_array($origin, ['robust_crossover', 'architecture', 'crossover'], true) && ! $g98Target) {
            [$parameters, $skillCrossoverSources, $capabilityGeneProvenance] = $this->skillCrossover(
                $family,
                $parents,
                $base,
                $slot,
                (array) ($adaptiveParentSelection['capability_genome'] ?? []),
            );
        } else {
            $parameters = $g98Target
                ? $this->mutate($lab->symbol, $lab->timeframe, $family, $base, $slot, $mutationScope, $mutationTarget, true, $historyKeys, $decisionPacket)
                : match ($origin) {
                    'gate_targeted', 'risk_exit', 'architecture' => $this->mutate($lab->symbol, $lab->timeframe, $family, $base, $slot, $mutationScope, $mutationTarget, false, $historyKeys, $decisionPacket),
                    'causal_isolation', 'g98_council', 'targeted_failure_profile', 'curiosity_probe' => $this->mutate($lab->symbol, $lab->timeframe, $family, $base, $slot, $mutationScope, $mutationTarget, true, $historyKeys, $decisionPacket),
                    // Unknown/new origins must still be evolutionary. A parent
                    // is a frozen capability baseline; only a first-ever lab is
                    // allowed to start from a random schema draw.
                    default => ($parentA || $exactPairBaselineModel)
                        ? $this->mutate($lab->symbol, $lab->timeframe, $family, $base, $slot, $mutationScope, $mutationTarget, false, $historyKeys, $decisionPacket)
                        : $this->randomParameters($family, $slot),
                };
        }
        $canonicalParentB = in_array($origin, ['crossover', 'robust_crossover'], true)
            ? $parentB?->id
            : null;
        // Specialized council compilers may return a direct one-gene result,
        // so enforce the same harmful-lesson firewall after compilation. A
        // blocked field is restored to the frozen parent/default; the later
        // one-gene nudge chooses a fresh allowed direction when one exists.
        $blockedChangedKeys = array_values(array_intersect(
            array_keys($this->diff($base, $parameters)),
            $knowledgeBlockedKeys,
        ));
        foreach ($blockedChangedKeys as $blockedKey) {
            if (array_key_exists($blockedKey, $base)) {
                $parameters[$blockedKey] = $base[$blockedKey];
            }
        }
        if ($councilRole !== '' && ! $structuralResearch && ! $architectureExperiment && ! $repairControlOnly && ! $riskControlOnly) {
            $parameters = $this->enforceCouncilRolePolicy(
                $councilRole,
                $family,
                $base,
                $parameters,
                $slot,
                $blockedMutationDirections,
                $knowledgeBlockedKeys,
            );
        }
        $knowledgeContract = $this->knowledge->childContract(
            $lab->symbol,
            $lab->timeframe,
            $family,
            $parentA,
            $niche,
            $target,
            $parents->all(),
            $decisionPacket,
        );
        $knowledgeContract['curiosity_lane'] = [
            ...((array) data_get($knowledgeContract, 'curiosity_lane', [])),
            'enabled' => $curiosityLane,
            'target' => $curiosityLane ? 'unknown_state_curiosity' : null,
            'promotion_evidence' => false,
        ];
        $stateMachineVariant = (string) data_get($niche, 'state_machine_variant', data_get($niche, 'state_machine_hypothesis', 'none'));
        if ($stateMachineVariant !== '' && $stateMachineVariant !== 'none') {
            // The state-machine escape is executable only in the explicitly
            // shadow-labelled architecture lane. Normal/control agents keep
            // the default parameter and therefore retain exact parity.
            if (($structuralResearch || (bool) data_get($niche, 'shadow_only', false))
                && in_array($stateMachineVariant, ['neutral_transition_cooldown_reentry_v1'], true)
                && array_key_exists('state_machine_variant', $this->schemas->schema($family))) {
                $parameters['state_machine_variant'] = $stateMachineVariant;
            }
        }
        if ($entryTopologyEscape
            && ($structuralResearch || (bool) data_get($niche, 'shadow_only', false))
            && in_array($entryTopologyVariant, [
                'regime_consensus_v1', 'transition_hazard_v1', 'breakout_retest_v1',
                'trend_regime_confirmation_v1', 'range_reentry_confirmation_v1',
                'volatility_persistence_v1',
            ], true)
            && array_key_exists('entry_topology_variant', $this->schemas->schema($family))) {
            // Shared with the Python router: this is an executable structural
            // mutation, not an architecture label or telemetry-only flag.
            $parameters['entry_topology_variant'] = $entryTopologyVariant;
        }
        if ($regimeClassifierEscape
            && ($structuralResearch || (bool) data_get($niche, 'shadow_only', false))
            && in_array($regimeClassifierVariant, [
                'adx_hysteresis_v1', 'ema_slope_consensus_v1', 'volatility_adaptive_v1',
            ], true)
            && array_key_exists('regime_classifier_variant', $this->schemas->schema($family))) {
            $parameters['regime_classifier_variant'] = $regimeClassifierVariant;
        }
        $parameters = $this->schemas->normalizeForGeneration($family, $parameters);
        // Root seats are reset to their frozen default vector below. Do not
        // validate a transient generic compiler proposal first: it is not an
        // executable root candidate and may contain a legacy parent scalar.
        if (! $rootPortfolioSeat) {
            $parameters = $this->schemas->validate($family, $parameters);
        }
        // A frozen parent/default may already hold the proposed value (for
        // example router v2 on a fresh lab). A G98 seat must still be a real
        // one-gene experiment, never a zero-diff clone labelled as causal.
        if ($g98Target
            && ! $structuralResearch
            && ! $architectureExperiment
            && ! $repairControlOnly
            && ! $riskControlOnly
            && $this->diff($base, $parameters) === []) {
            $parameters = $councilRole !== ''
                ? $this->councilRoleMutationCandidate($councilRole, $family, $base, $knowledgeBlockedKeys, $blockedMutationDirections)
                : $this->forceSingleGeneNudge($family, $parameters, $slot, $target, $knowledgeBlockedKeys);
            if ($parameters !== null) {
                $parameters = $this->schemas->normalizeForGeneration($family, $parameters);
                $parameters = $this->schemas->validate($family, $parameters);
            }
            // No legal owner gene exists under the learned safety firewall.
            // Keep one explicit no-change control so the five-by-four council
            // can measure the exhausted lane without fabricating a mutation.
            // It is marked control-only below and can never earn promotion.
            if ($parameters === null) {
                $explicitControlSeat = $architectureControlOnly
                    || (bool) data_get($niche, 'control_only', false)
                    || $roleCompleteCouncil;
                if ($g98Target && $explicitControlSeat) {
                    $parameters = $base;
                    $noLegalOwnerMutationControl = true;
                } else {
                    $failureReason = 'NO_LEGAL_NONZERO_MUTATION';

                    return false;
                }
            }
        }
        // A generation is an experiment set, not a collection of parameter
        // clones.  Reject exact fingerprints and near neighbours before they
        // consume an expensive full validation slot.
        $isolatedKey = ($g98Target || in_array($origin, ['causal_isolation', 'g98_council', 'targeted_failure_profile'], true)) ? array_key_first($this->diff($base, $parameters)) : null;
        // Keep the directed child across the generation-local novelty pass.
        // A duplicate boolean nudge can otherwise return to the parent value
        // and erase the declared experiment before historical novelty runs.
        $directedParameters = $parameters;
        if ($causalCohortRole === '' && ! $rootPortfolioIntervention && ! $structuralResearch && ! $architectureExperiment && ! $repairControlOnly && ! $riskControlOnly) {
            $parameters = $this->ensureNovelParameters($generation, $family, $parameters, $slot, $g98Target || in_array($origin, ['gate_targeted', 'causal_isolation', 'g98_council', 'targeted_failure_profile'], true), $isolatedKey);
        }
        if ($g98Target && ! $structuralResearch && ! $repairControlOnly && $isolatedKey !== null && $this->diff($base, $parameters) === []) {
            $parameters = $directedParameters;
        }
        // The generation-local check above cannot see the same failed
        // topology from G83/G84/G85/G86.  Avoiding only current-generation
        // duplicates allowed the council to rediscover an already falsified
        // member every cycle, after which portfolio admission correctly
        // rejected it as a known-failed fingerprint.  Historical novelty is
        // applied only to the declared lane gene, preserving differential and
        // non-target invariants.
        // Preserve the directed candidate so a historical novelty nudge
        // cannot silently toggle a boolean back to the frozen parent value.
        // The previous behaviour produced zero-diff G98 children (notably
        // transition-firewall lanes), which are not interpretable experiments
        // and must never consume a screening slot.
        $directedParameters = $parameters;
        $genericHistoricalNoveltyExhausted = false;
        if ($causalCohortRole === '' && ! $rootPortfolioIntervention && ! $structuralResearch && ! $architectureExperiment && ! $repairControlOnly && ! $riskControlOnly) {
            $parameters = $this->ensureHistoricalNovelParameters(
                $lab->symbol,
                $lab->timeframe,
                $family,
                $parameters,
                $slot,
                $target,
                $niche,
                $isolatedKey,
                (int) $generation->id,
                $genericHistoricalNoveltyExhausted,
                $isolatedKey !== null ? ($base[$isolatedKey] ?? null) : null,
            );
        }
        // An architecture/topology escape is a separate structural
        // hypothesis, not another value on the exhausted scalar gene lane.
        // The old branch re-entered the role-gene fallback even after the
        // replacement compiler had explicitly selected an architecture
        // escape, so valid council seats were rejected as exhausted and the
        // population constructor ended in technical quarantine. Let the
        // structural escape reach its own invariant below; it remains
        // shadow-only and all unchanged screening/promotion gates still
        // apply.
        if (! $rootPortfolioIntervention
            && $genericHistoricalNoveltyExhausted
            && $g98Target
            && ! $structuralResearch
            && ! $architectureExperiment
            && ! $repairControlOnly
            && ! $riskControlOnly) {
            $explicitControlSeat = $architectureControlOnly
                || (bool) data_get($niche, 'control_only', false)
                || $roleCompleteCouncil;
            if ($explicitControlSeat) {
                $parameters = $base;
                $noLegalOwnerMutationControl = true;
            } elseif ((bool) data_get($niche, 'shadow_only', false)
                && $councilRole !== ''
                && $isolatedKey !== null) {
                // Shadow research may exhaust one finite gene surface (for
                // example the volume-lane enum) while the role still has
                // other schema-bounded mechanisms to explore. Rotate to an
                // unseen owner gene instead of shrinking the 20-seat cohort;
                // the fallback is rechecked against historical fingerprints
                // and remains shadow-only/no-credit.
                $fallbackBlockedKeys = array_values(array_unique([
                    ...$knowledgeBlockedKeys,
                    $isolatedKey,
                ]));
                $fallback = $this->councilRoleMutationCandidate(
                    $councilRole,
                    $family,
                    $base,
                    $fallbackBlockedKeys,
                    $blockedMutationDirections,
                    [$isolatedKey],
                );
                $fallbackDiff = $this->diff($base, $fallback);
                if (count($fallbackDiff) === 1) {
                    $fallbackKey = (string) array_key_first($fallbackDiff);
                    $fallbackExhausted = false;
                    $fallback = $this->ensureHistoricalNovelParameters(
                        $lab->symbol,
                        $lab->timeframe,
                        $family,
                        $fallback,
                        $slot + 1,
                        $target,
                        $niche,
                        $fallbackKey,
                        (int) $generation->id,
                        $fallbackExhausted,
                        $base[$fallbackKey] ?? null,
                    );
                    $fallback = $this->schemas->normalizeForGeneration($family, $fallback);
                    $fallback = $this->schemas->validate($family, $fallback);
                    if (! $fallbackExhausted && count($this->diff($base, $fallback)) === 1) {
                        $parameters = $fallback;
                        $isolatedKey = $fallbackKey;
                        $genericHistoricalNoveltyExhausted = false;
                    } else {
                        $failureReason = 'HISTORICAL_GENE_EXHAUSTED';

                        return false;
                    }
                } else {
                    $failureReason = 'HISTORICAL_GENE_EXHAUSTED';

                    return false;
                }
            } else {
                $failureReason = 'HISTORICAL_GENE_EXHAUSTED';

                return false;
            }
        }
        if ($g98Target && ! $structuralResearch && $isolatedKey !== null && $this->diff($base, $parameters) === [] && ! $noLegalOwnerMutationControl) {
            $parameters = $directedParameters;
        }
        if ($rootPortfolioIntervention) {
            $rootGene = (string) data_get($niche, 'declared_gene', '');
            $rootValueDeclared = array_key_exists('declared_value', (array) $niche);
            if ($rootGene === '' || ! $rootValueDeclared || ! array_key_exists($rootGene, $this->schemas->schema($family))) {
                $failureReason = 'ROOT_PORTFOLIO_DECLARATION_INVALID';

                return false;
            }
            // Reset from the frozen baseline so the root ablation contains
            // exactly one executable causal change.
            $parameters = $this->schemas->defaults($family);
            $parameters[$rootGene] = data_get($niche, 'declared_value');
        }
        $parameters = $this->schemas->normalizeForGeneration($family, $parameters);
        $parameters = $this->schemas->validate($family, $parameters);
        if ($rootPortfolioIntervention) {
            $rootDiff = $this->diff($base, $parameters);
            $rootGene = (string) data_get($niche, 'declared_gene', '');
            if (count($rootDiff) !== 1
                || (string) array_key_first($rootDiff) !== $rootGene
                || json_encode($parameters[$rootGene] ?? null, JSON_PRESERVE_ZERO_FRACTION)
                    !== json_encode(data_get($niche, 'declared_value'), JSON_PRESERVE_ZERO_FRACTION)) {
                $failureReason = 'ROOT_PORTFOLIO_MUTATION_CONTRACT_FAILED';

                return false;
            }
        }
        // Historical novelty is allowed to choose an unseen nearby value, but
        // it must not undo the role contract or resurrect a quarantined
        // direction. Apply the policy once more after that search step; this
        // is still draft-time parameter construction and creates no evidence.
        if ($councilRole !== '' && ! $structuralResearch && ! $architectureExperiment && ! $repairControlOnly && ! $riskControlOnly) {
            $parameters = $this->enforceCouncilRolePolicy(
                $councilRole,
                $family,
                $base,
                $parameters,
                $slot,
                $blockedMutationDirections,
                $knowledgeBlockedKeys,
            );
            $parameters = $this->schemas->normalizeForGeneration($family, $parameters);
            $parameters = $this->schemas->validate($family, $parameters);
        }

        // The constructor invariant is checked after every compiler, novelty
        // pass and role policy.  Checking only before persistence was too
        // early: historical novelty could toggle the proposed value back to
        // the parent and create a zero-diff agent.  Repair lanes also require
        // exactly one changed gene; a multi-gene child is not attributable.
        $strictSingleGene = ! $repairControlOnly && ! $hybridMultiGene && ($exactPairCandidate || $causalCohortRole !== '' || $structuralResearch || $g98Target
            || in_array($origin, ['gate_targeted', 'risk_exit', 'causal_isolation', 'g98_council', 'targeted_failure_profile', 'coverage_rescue'], true)
            || $family === 'differential_router');
        if ($causalCohortRole === '' && ! $architectureExperiment && ! $repairControlOnly && ! $riskControlOnly && ! $rootPortfolioIntervention) {
            $parameters = $this->enforceConstructorMutationInvariant(
                $family,
                $base,
                $parameters,
                $slot,
                $target,
                $knowledgeBlockedKeys,
                $strictSingleGene,
            );
            if ($parameters === null) {
                $explicitControlSeat = $architectureControlOnly
                    || (bool) data_get($niche, 'control_only', false)
                    || $roleCompleteCouncil;
                if ($g98Target && $explicitControlSeat) {
                    $parameters = $base;
                    $noLegalOwnerMutationControl = true;
                } else {
                    return false;
                }
            }
        }
        $parameters = $this->schemas->normalizeForGeneration($family, $parameters);
        $parameters = $this->schemas->validate($family, $parameters);
        // The generic one-gene nudge above is intentionally family-wide and
        // can select a protected firewall when a role compiler returned a
        // zero-diff control. Re-apply the role contract after that nudge so a
        // router can never be persisted with its transition firewall off.
        if ($councilRole !== '' && ! $structuralResearch && ! $architectureExperiment && ! $repairControlOnly && ! $riskControlOnly) {
            $parameters = $this->enforceCouncilRolePolicy(
                $councilRole,
                $family,
                $base,
                $parameters,
                $slot,
                $blockedMutationDirections,
                $knowledgeBlockedKeys,
            );
            $parameters = $this->schemas->normalizeForGeneration($family, $parameters);
            $parameters = $this->schemas->validate($family, $parameters);
        }
        if ($councilRole !== '' && ! $structuralResearch && ! $architectureExperiment && ! $repairControlOnly && ! $riskControlOnly) {
            // The final role-policy pass can legitimately restore a child to
            // the role's first preferred gene after constructor nudging. If
            // that fingerprint already failed in an earlier generation,
            // rotate the same declared gene to the next unseen bounded value
            // instead of silently replaying the old experiment. This remains
            // one-gene, schema-valid and subject to every unchanged gate.
            $finalDiff = $this->diff($base, $parameters);
            $finalKey = count($finalDiff) === 1 ? (string) array_key_first($finalDiff) : null;
            $roleAllowlist = (array) data_get($this->councilRolePolicySpec($councilRole), 'mutation_allowlist', []);
            if ($finalKey !== null && in_array($finalKey, $roleAllowlist, true)) {
                $finalNoveltyExhausted = false;
                $novelParameters = $this->ensureHistoricalNovelParameters(
                    $lab->symbol,
                    $lab->timeframe,
                    $family,
                    $parameters,
                    $slot,
                    $target,
                    $niche,
                    $finalKey,
                    (int) $generation->id,
                    $finalNoveltyExhausted,
                    $base[$finalKey] ?? null,
                );
                $novelParameters = $this->schemas->normalizeForGeneration($family, $novelParameters);
                $novelParameters = $this->schemas->validate($family, $novelParameters);
                if (! $finalNoveltyExhausted && count($this->diff($base, $novelParameters)) === 1) {
                    $parameters = $novelParameters;
                }
            }
        }
        if ($skillMentorInput !== null
            && $declaredGene !== ''
            && ! $targetedFailureLane
            && ! $structuralResearch
            && ! $architectureExperiment
            && ! $repairControlOnly
            && ! $riskControlOnly) {
            // A mentor is a one-gene capability donor, not a genetic parent.
            // Compile a bounded probe directly against the frozen base so the
            // metadata claim is reflected in parameter_diff. If the learned
            // direction is blocked or at a schema boundary, retain the
            // ordinary candidate and mark the probe as unused.
            $mentorParameters = $this->declaredSingleGeneMutation(
                $base,
                $this->schemas->schema($family),
                $declaredGene,
                $slot,
                $knowledgeBlockedKeys,
                $blockedMutationDirections,
                $repairDirection !== '' ? $repairDirection : null,
            );
            $mentorParameters = $this->schemas->normalizeForGeneration($family, $mentorParameters);
            $mentorParameters = $this->schemas->validate($family, $mentorParameters);
            if ($councilRole !== '' && ! $riskControlOnly) {
                $mentorParameters = $this->enforceCouncilRolePolicy(
                    $councilRole,
                    $family,
                    $base,
                    $mentorParameters,
                    $slot,
                    $blockedMutationDirections,
                    $knowledgeBlockedKeys,
                );
                $mentorParameters = $this->schemas->normalizeForGeneration($family, $mentorParameters);
                $mentorParameters = $this->schemas->validate($family, $mentorParameters);
            }
            $mentorDiff = $this->diff($base, $mentorParameters);
            if (count($mentorDiff) === 1 && array_key_first($mentorDiff) === $declaredGene) {
                $parameters = $mentorParameters;
                $skillMentorApplied = true;
            }
        }
        if ($targetedFailureLane
            && $declaredGene !== ''
            && ! $hybridMultiGene
            && ! $structuralResearch
            && ! $architectureExperiment
            && ! $repairControlOnly
            && ! $riskControlOnly) {
            $declaredDiff = $this->diff($base, $parameters);
            if (count($declaredDiff) !== 1 || (string) array_key_first($declaredDiff) !== $declaredGene || $repairDirection !== '') {
                $parameters = $this->declaredSingleGeneMutation(
                    $base,
                    $this->schemas->schema($family),
                    $declaredGene,
                    $slot,
                    $knowledgeBlockedKeys,
                    $blockedMutationDirections,
                    $repairDirection !== '' ? $repairDirection : null,
                );
                $parameters = $this->schemas->normalizeForGeneration($family, $parameters);
                $parameters = $this->schemas->validate($family, $parameters);
            }
        }
        // A risk-library seat is a genuine independent specialist lane. Its
        // profile-selected guard becomes the sole executable difference from
        // the frozen baseline; paired controls remain byte-for-byte frozen.
        $edgeRiskAdmission = app(DependencyAwareEdgeGenesisFoundryService::class)->mutationAdmission(
            $parameterBaselineParent ?: $parentA,
            $riskMutationGene,
            (array) (($parameterBaselineParent ?: $parentA)?->marketPerformances()->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)->latest('id')->value('metrics') ?? []),
        );
        if ($compositionLane === 'risk_management_mutation'
            && $riskMutationGene !== ''
            && (bool) data_get($edgeRiskAdmission, 'allowed', false)
            && ! $structuralResearch
            && ! $architectureExperiment
            && ! $repairControlOnly
            && ! $riskControlOnly
            && array_key_exists($riskMutationGene, $this->schemas->schema($family))) {
            $parameters = $this->declaredSingleGeneMutation(
                $base,
                $this->schemas->schema($family),
                $riskMutationGene,
                $slot,
                $knowledgeBlockedKeys,
                $blockedMutationDirections,
            );
            $parameters = $this->schemas->normalizeForGeneration($family, $parameters);
            $parameters = $this->schemas->validate($family, $parameters);
        }
        if ($compositionLane === 'risk_management_mutation' && ! (bool) data_get($edgeRiskAdmission, 'allowed', false)) {
            if (! $exactPairCandidate) {
                $parameters = $base;
            } else {
                $activeDiff = $this->diff($base, $parameters);
                data_set($niche, 'deferred_dependency_work', [
                    'protocol' => 'dependency_ordered_pair_reallocation_v1',
                    'status' => 'deferred_until_edge_and_risk_authority',
                    'original_target' => $target,
                    'original_gene' => $riskMutationGene !== '' ? $riskMutationGene : null,
                    'active_gene' => count($activeDiff) === 1 ? array_key_first($activeDiff) : null,
                    'reason' => (string) data_get($edgeRiskAdmission, 'reason', 'DEPENDENCY_AUTHORITY_MISSING'),
                    'promotion_evidence' => false,
                ]);
            }
            data_set($niche, 'edge_genesis_mutation_admission', $edgeRiskAdmission);
            data_set($niche, 'risk_mutation_locked', true);
        }
        // Confirmed memory is an active experiment instruction, not a rank
        // annotation. Reproduce its exact one-gene value against a compatible
        // frozen baseline. If this child's actual baseline has drifted, fail
        // the directive back to exploration instead of pretending the stored
        // causal effect was inherited in a different counterfactual.
        $learningDirective = (array) data_get($niche, 'learning_evolution', []);
        $learningRole = (string) data_get($learningDirective, 'experiment_role', 'explore');
        if (in_array($learningRole, ['exploit', 'repair'], true)) {
            $learningGene = (string) data_get($learningDirective, 'required_gene', '');
            $learningFrom = data_get($learningDirective, 'mutation_from');
            $learningTo = data_get($learningDirective, 'mutation_to');
            $baselineValue = $base[$learningGene] ?? null;
            $baselineMatches = is_numeric($baselineValue) && is_numeric($learningFrom)
                ? abs((float) $baselineValue - (float) $learningFrom) < 0.000000001
                : json_encode($baselineValue, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
                    === json_encode($learningFrom, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            $learningCandidate = $base;
            if ($learningGene !== ''
                && array_key_exists($learningGene, $this->schemas->schema($family))
                && $baselineMatches) {
                $learningCandidate[$learningGene] = $learningTo;
                $learningCandidate = $this->schemas->normalizeForGeneration($family, $learningCandidate);
                $learningCandidate = $this->schemas->validate($family, $learningCandidate);
            }
            $learningDiff = $this->diff($base, $learningCandidate);
            if (count($learningDiff) === 1 && (string) array_key_first($learningDiff) === $learningGene) {
                $parameters = $learningCandidate;
                data_set($niche, 'learning_evolution.execution_status', 'exact_receipt_hypothesis_applied');
            } else {
                $learningRole = 'explore';
                data_set($niche, 'learning_evolution.experiment_role', 'explore');
                data_set($niche, 'learning_evolution.consumed_receipt_ids', []);
                data_set($niche, 'learning_evolution.inherited_components', []);
                data_set($niche, 'learning_evolution.required_component', null);
                data_set($niche, 'learning_evolution.required_gene', null);
                data_set($niche, 'learning_evolution.fallback_reason', 'ACTUAL_BASELINE_INCOMPATIBLE_WITH_CONFIRMED_RECEIPT');
                data_set($niche, 'learning_evolution.execution_status', 'degraded_to_exploration');
                if ((array) data_get($niche, 'learning_receipt_injection', []) !== []) {
                    unset($niche['declared_gene'], $niche['declared_value'], $niche['learning_receipt_injection']);
                    $declaredGene = '';
                }
            }
            $passport = (array) data_get($niche, 'composition_passport', []);
            if ($passport !== []) {
                data_set($niche, 'composition_passport', app(CompositionAuthorityKernelService::class)
                    ->bindLearningDirective($passport, (array) data_get($niche, 'learning_evolution', [])));
            }
        }
        // Structural cohort seats may declare an exact enum/boolean
        // hypothesis (for example a volume topology). Keep this assignment
        // inside the same one-gene boundary so the value is executable and
        // auditable rather than metadata-only.
        if ($targetedFailureLane
            && $declaredGene !== ''
            && ! $hybridMultiGene
            && array_key_exists('declared_value', (array) $niche)
            && array_key_exists($declaredGene, $this->schemas->schema($family))
            && ! $architectureExperiment
            && ! $repairControlOnly
            && ! $riskControlOnly) {
            $declaredValue = data_get($niche, 'declared_value');
            $declaredCandidate = $base;
            $declaredCandidate[$declaredGene] = $declaredValue;
            $declaredCandidate = $this->schemas->normalizeForGeneration($family, $declaredCandidate);
            $declaredCandidate = $this->schemas->validate($family, $declaredCandidate);
            if (count($this->diff($base, $declaredCandidate)) === 1
                && array_key_first($this->diff($base, $declaredCandidate)) === $declaredGene) {
                $parameters = $declaredCandidate;
            }
        }
        // The declared targeted compiler above is authoritative for the
        // causal key, but it can restore the original curriculum step after
        // historical novelty has selected a fresh value. Re-run the
        // historical blacklist at the final targeted-construction boundary;
        // no later targeted compiler may overwrite this value before the
        // immutable model row is persisted.
        $historicalNoveltyAudit = null;
        $historicalNoveltyExhausted = false;
        if ($targetedFailureLane
            && $declaredGene !== ''
            && ! $hybridMultiGene
            && ! $structuralResearch
            && ! $architectureExperiment
            && ! $repairControlOnly
            && ! $riskControlOnly) {
            $beforeHistoricalParameters = $parameters;
            $parameters = $this->ensureHistoricalNovelParameters(
                $lab->symbol,
                $lab->timeframe,
                $family,
                $parameters,
                $slot,
                $target,
                $niche,
                $declaredGene,
                (int) $generation->id,
                $historicalNoveltyExhausted,
                $base[$declaredGene] ?? null,
                $repairDirection !== '' ? $repairDirection : null,
            );
            $parameters = $this->schemas->normalizeForGeneration($family, $parameters);
            $parameters = $this->schemas->validate($family, $parameters);
            if ($historicalNoveltyExhausted) {
                // A fully explored one-gene lane has no truthful new child.
                // Persist an explicit no-change control so the exhausted
                // dimension remains visible without spending a replay on a
                // known historical topology.
                $parameters = $base;
                // Repair siblings are not controls. If their declared gene is
                // exhausted, construction must fail closed so the bounded
                // cohort cannot claim four siblings while persisting a
                // zero-diff child. Only an explicitly declared control seat
                // may use the no-change representation.
                $noLegalOwnerMutationControl = $architectureControlOnly
                    || (bool) data_get($niche, 'control_only', false);
            }
            $historicalNoveltyAudit = [
                'protocol' => 'historical_novelty_constructor_v2',
                'scope' => 'targeted_final_boundary',
                'generation_id' => (int) $generation->id,
                'declared_gene' => $declaredGene,
                'before_value' => $beforeHistoricalParameters[$declaredGene] ?? null,
                'after_value' => $parameters[$declaredGene] ?? null,
                'before_fingerprint' => $this->parameterFingerprint($family, $beforeHistoricalParameters),
                'after_fingerprint' => $this->parameterFingerprint($family, $parameters),
                'changed_by_novelty_pass' => $this->parameterFingerprint($family, $beforeHistoricalParameters)
                    !== $this->parameterFingerprint($family, $parameters),
                'exhausted' => $historicalNoveltyExhausted,
                'fallback' => $historicalNoveltyExhausted ? 'explicit_no_change_control' : null,
                'promotion_evidence' => false,
            ];
        }
        // Success can earn a larger next probe, while uncertainty receives a
        // smaller one. Apply this only to a single numeric causal diff and
        // never to an anchor repair, architecture topology, or control. The
        // exact changed gene remains the declared experiment.
        if (! $structuralResearch && ! $riskControlOnly && ! $repairControlOnly && ! $targetedFailureLane && ! $architectureExperiment
            && ! in_array($learningRole, ['exploit', 'repair'], true)) {
            $parameters = $this->applyRiskBoundedMutationStep(
                $family,
                $base,
                $parameters,
                $evolutionMode,
                $riskStepMultiplier,
            );
            $parameters = $this->schemas->normalizeForGeneration($family, $parameters);
            $parameters = $this->schemas->validate($family, $parameters);
        }
        if ((bool) data_get($niche, 'shadow_only', false)
            && $shadowMutationGene !== ''
            && ! in_array($learningRole, ['exploit', 'repair'], true)
            && ! $architectureExperiment
            && ! $repairControlOnly
            && ! $riskControlOnly) {
            // Role seats receive an explicit owner gene after generic
            // crossover/novelty passes. This closes the old path where five
            // robustness or volume seats converged on the same first scalar.
            $parameters = $this->shadowSingleGeneProbe(
                $family,
                $base,
                $parameters,
                $shadowMutationGene,
                $shadowMutationIndex,
            );
            $parameters = $this->schemas->normalizeForGeneration($family, $parameters);
            $parameters = $this->schemas->validate($family, $parameters);
        }
        // Bold/adversarial structural seats are the only lane allowed to
        // change more than one executable gene. The declared values are
        // applied as one coherent mechanism and remain research-only until
        // the complete independent evidence contract passes.
        if ($hybridMultiGene) {
            $multiValues = (array) data_get($niche, 'declared_values', []);
            if (count($multiValues) < 2 || count($multiValues) > 3) {
                $failureReason = 'HYBRID_MULTI_GENE_CONTRACT_FAILED';

                return false;
            }
            $multiCandidate = $base;
            foreach ($multiValues as $gene => $value) {
                if (! array_key_exists((string) $gene, $this->schemas->schema($family))) {
                    $failureReason = 'HYBRID_MULTI_GENE_NOT_IN_SCHEMA';

                    return false;
                }
                $multiCandidate[(string) $gene] = $value;
            }
            $multiCandidate = $this->schemas->normalizeForGeneration($family, $multiCandidate);
            $multiCandidate = $this->schemas->validate($family, $multiCandidate);
            $parameters = $multiCandidate;
        }
        if ($structuralResearch && ! $hybridMultiGene && ! $riskControlOnly) {
            $structuralGene = (string) data_get($niche, 'declared_gene', '');
            if ($structuralGene !== '' && array_key_exists($structuralGene, $this->schemas->schema($family))
                && array_key_exists('declared_value', (array) $niche)) {
                // Structural research was already correctly frozen above,
                // but previously never applied the declared topology value.
                // That made the structural contract test a zero-diff child.
                $parameters = $base;
                $declaredStructuralValue = data_get($niche, 'declared_value');
                // A planner value can still equal the frozen parent after
                // control pairing/normalization. Select the first declared
                // schema value that is genuinely different so the structural
                // seat remains a legal one-gene experiment.
                if (json_encode($base[$structuralGene] ?? null, JSON_PRESERVE_ZERO_FRACTION)
                    === json_encode($declaredStructuralValue, JSON_PRESERVE_ZERO_FRACTION)) {
                    $definition = (array) $this->schemas->schema($family)[$structuralGene];
                    foreach ((array) ($definition[1] ?? []) as $alternateStructuralValue) {
                        if (json_encode($base[$structuralGene] ?? null, JSON_PRESERVE_ZERO_FRACTION)
                            !== json_encode($alternateStructuralValue, JSON_PRESERVE_ZERO_FRACTION)) {
                            $declaredStructuralValue = $alternateStructuralValue;
                            $niche['declared_value'] = $alternateStructuralValue;
                            break;
                        }
                    }
                }
                $parameters[$structuralGene] = $declaredStructuralValue;
                $parameters = $this->schemas->normalizeForGeneration($family, $parameters);
                $parameters = $this->schemas->validate($family, $parameters);
            }
        }
        // The guided branch must execute the exact pre-registered lesson.
        // The blinded branch deliberately remains a cold-start single-gene
        // selector: the treatment is the selection policy, not a forced copy
        // of the guided mutation. Generic compilers remain free elsewhere.
        if (in_array($causalCohortRole, ['memory_guided', 'hypothesis_guided', 'repair_guided'], true)) {
            $causalGene = (string) data_get($niche, 'causal_learning_cohort.gene', '');
            $causalValueDeclared = array_key_exists('value', (array) data_get($niche, 'causal_learning_cohort', []));
            $causalValue = data_get($niche, 'causal_learning_cohort.value');
            if ($causalGene === '' || ! $causalValueDeclared || ! array_key_exists($causalGene, $this->schemas->schema($family))) {
                $failureReason = 'CAUSAL_LEARNING_MUTATION_CONTRACT_INVALID';

                return false;
            }
            $parameters = $base;
            $parameters[$causalGene] = $causalValue;
            $parameters = $this->schemas->normalizeForGeneration($family, $parameters);
            $parameters = $this->schemas->validate($family, $parameters);
        } elseif ($causalCohortRole === 'blinded') {
            $blindedSelector = (array) data_get($niche, 'causal_learning_cohort.blinded_selector', []);
            $blindedGene = (string) data_get($blindedSelector, 'gene', '');
            $blindedValueDeclared = array_key_exists('value', $blindedSelector);
            if (data_get($blindedSelector, 'protocol') !== CausalBlindedMutationSelectorService::PROTOCOL
                || (int) data_get($blindedSelector, 'memory_inputs', -1) !== 0
                || $blindedGene === ''
                || ! $blindedValueDeclared
                || ! array_key_exists($blindedGene, $base)
                || ! array_key_exists($blindedGene, $this->schemas->schema($family))) {
                $failureReason = 'CAUSAL_BLINDED_SELECTOR_CONTRACT_INVALID';

                return false;
            }
            $parameters = $base;
            $parameters[$blindedGene] = data_get($blindedSelector, 'value');
            $parameters = $this->schemas->normalizeForGeneration($family, $parameters);
            $parameters = $this->schemas->validate($family, $parameters);
        } elseif ($causalCohortRole === 'frozen_control') {
            // This assignment intentionally happens after every generic
            // compiler/normalizer: the control is the source vector exactly.
            $parameters = $base;
        }
        if ($riskControlOnly) {
            // The control was frozen before the specialist compilers, but a
            // stored learning directive can be applied later in the pipeline.
            // Reassert exact parity at the final mutation boundary so a
            // dependency fallback can never become a hidden risk/management
            // mutation and then fail the Edge Genesis firewall again.
            $parameters = $base;
        }
        if ($causalCohortRole === '' && ! $exactPairCandidate && ! $exactPairControl
            && ! $structuralResearch && ! $riskControlOnly && ! $rootPortfolioSeat) {
            $parameters = app(InstrumentPolicyConsumptionService::class)->applyPreferredDelta(
                (array) $instrumentMutationPolicy, (array) $base, (array) $parameters,
                array_keys($this->schemas->schema($family)),
            );
            $parameters = $this->schemas->validate($family, $parameters);
        }
        $parameterDiff = $this->diff($base, $parameters);
        if ($nativeFollowupSource !== null
            && app(ResearchPaperEpochContractService::class)->parameterHash($parameters)
                !== $nativeFollowupSource['parameter_hash']) {
            $failureReason = 'NATIVE_COUNCIL_FOLLOWUP_EXECUTABLE_VECTOR_DRIFT';
            return false;
        }
        if ($causalCohortRole === '' && app(InstrumentPolicyConsumptionService::class)->forbiddenDelta(
            (array) $instrumentMutationPolicy, (array) $parameterDiff, (array) $parameters,
        )) {
            $failureReason = 'INSTRUMENT_EXACT_DELTA_FORBIDDEN';

            return false;
        }
        $this->publishConstructorStage($generation, $slot, 'mutation_ready');
        // Dependency-aware Edge Genesis is the global mutation firewall:
        // neither cosmetic risk repair nor exit/management tuning may spend
        // replay budget before a context-bound edge has been proven and
        // attributed. A blocked candidate is not silently rewritten.
        foreach (array_keys($parameterDiff) as $changedGene) {
            $edgeAdmission = app(DependencyAwareEdgeGenesisFoundryService::class)->mutationAdmission($parameterBaselineParent ?: $parentA, (string) $changedGene);
            if (! (bool) data_get($edgeAdmission, 'allowed', false)) {
                $failureReason = (string) data_get($edgeAdmission, 'reason', 'EDGE_GENESIS_MUTATION_ADMISSION_DENIED');

                return false;
            }
        }
        if (in_array($causalCohortRole, ['memory_guided', 'hypothesis_guided', 'repair_guided'], true)) {
            $causalGene = (string) data_get($niche, 'causal_learning_cohort.gene', '');
            if (count($parameterDiff) !== 1 || (string) array_key_first($parameterDiff) !== $causalGene) {
                $failureReason = 'CAUSAL_LEARNING_MUTATION_NOT_EXECUTABLE';

                return false;
            }
        } elseif ($causalCohortRole === 'blinded') {
            $blindedSelector = (array) data_get($niche, 'causal_learning_cohort.blinded_selector', []);
            $blindedGene = (string) data_get($blindedSelector, 'gene', '');
            if (count($parameterDiff) !== 1
                || (string) array_key_first($parameterDiff) !== $blindedGene
                || $this->valuesDiffer(data_get($parameterDiff, $blindedGene.'.new'), data_get($blindedSelector, 'value'))) {
                $failureReason = 'CAUSAL_BLINDED_SELECTOR_NOT_EXACT_SINGLE_GENE';

                return false;
            }
        } elseif ($causalCohortRole === 'frozen_control' && $parameterDiff !== []) {
            $failureReason = 'CAUSAL_FROZEN_CONTROL_NOT_EXACT_BASELINE';

            return false;
        }
        if ($structuralResearch && ! $hybridMultiGene && ! $riskControlOnly) {
            $structuralGene = (string) data_get($niche, 'declared_gene', '');
            $structuralValueDeclared = array_key_exists('declared_value', (array) $niche);
            $structuralValue = data_get($niche, 'declared_value');
            if ($structuralGene === ''
                || ! $structuralValueDeclared
                || count($parameterDiff) !== 1
                || (string) array_key_first($parameterDiff) !== $structuralGene
                || ! array_key_exists($structuralGene, $parameters)
                || json_encode($parameters[$structuralGene], JSON_PRESERVE_ZERO_FRACTION)
                    !== json_encode($structuralValue, JSON_PRESERVE_ZERO_FRACTION)) {
                $failureReason = 'STRUCTURAL_MUTATION_CONTRACT_FAILED';

                return false;
            }
        }
        if ($hybridMultiGene) {
            $declaredGenes = array_values(array_map('strval', array_keys((array) data_get($niche, 'declared_values', []))));
            $changedGenes = array_values(array_map('strval', array_keys($parameterDiff)));
            sort($declaredGenes);
            sort($changedGenes);
            if (count($parameterDiff) < 2 || count($parameterDiff) > 3 || $changedGenes !== $declaredGenes) {
                $failureReason = 'HYBRID_MULTI_GENE_DIFF_MISMATCH';

                return false;
            }
        }
        if ((bool) data_get($niche, 'shadow_only', false)
            && ! $repairControlOnly
            && ! $riskControlOnly
            && ! $hybridMultiGene
            && ! (bool) data_get($niche, 'control_only', false)) {
            $expectedShadowGene = $entryTopologyEscape
                ? 'entry_topology_variant'
                : ($shadowMutationGene !== '' ? $shadowMutationGene : null);
            if ($expectedShadowGene !== null
                && (count($parameterDiff) !== 1 || (string) array_key_first($parameterDiff) !== $expectedShadowGene)) {
                $failureReason = 'SHADOW_MUTATION_CONTRACT_FAILED';

                return false;
            }
        }
        // A role-complete lane may truthfully persist a no-change control when
        // every legal owner mutation is blocked by learned harmful lessons.
        // It remains research-only and can never satisfy a passport, but it
        // preserves role coverage and records an explicit abstention/control
        // result instead of resurrecting a protected firewall gene.
        // A state-machine escape keeps the frozen base strategy architecture
        // and changes one executable state gene.  It must not be serialized
        // as the synthetic __architecture gene, otherwise draft admission
        // rejects the truthful one-key parameter diff before screening.
        $architectureChanged = $architectureExperiment
            && ! $stateMachineEscape
            && ! $architectureInteractionEscape
            && $architecture !== (string) data_get(
                $parentA?->metadata,
                'strategy_architecture',
                (self::ARCHITECTURES[$family][0] ?? $this->architectureBaseStrategy($family)),
            );
        $architectureExperimentValid = $architectureExperiment
            // The shadow rescue lane may keep the runtime topology frozen and
            // test one explicitly declared executable state-machine variant.
            // That is a valid architecture hypothesis even when the family
            // has no alternate `strategy_architecture` metadata value. It is
            // still gated by `$architectureExperiment`, which requires the
            // shadow-only/targeted lane, so this does not grant promotion or
            // parent credit to the exploratory child.
            && ($architectureControlOnly || $architectureChanged || $stateMachineEscape
                || $entryTopologyEscape || $regimeClassifierEscape || $architectureInteractionEscape);
        if ($architectureExperiment && ! $architectureExperimentValid) {
            $failureReason = 'ARCHITECTURE_ESCAPE_INVALID';

            return false;
        }
        // A no-change seat is valid only when the plan explicitly declared a
        // control. A role name alone is not permission to persist a cold
        // clone after the mutation compiler was exhausted.
        $roleControlEligible = $noLegalOwnerMutationControl
            || $repairControlOnly
            || $architectureControlOnly
            || $riskControlOnly;
        // A planner's `architecture_escape` recommendation is not itself an
        // executable mutation. Only a declared control or a topology that
        // actually changed may carry a zero parameter diff. The broader flag
        // previously admitted zero-diff causal seats that strict preflight
        // then had to quarantine as ONE_GENE_INVARIANT_FAILED.
        $zeroParameterDiffAllowed = $roleControlEligible || $architectureChanged;
        if (($parameterDiff === [] && ! $zeroParameterDiffAllowed)
            || ($strictSingleGene && count($parameterDiff) !== 1 && ! $zeroParameterDiffAllowed)) {
            $failureReason = 'CONSTRUCTOR_MUTATION_INVARIANT_FAILED';

            return false;
        }

        $roleControl = $noLegalOwnerMutationControl
            || $repairControlOnly
            || $architectureControlOnly
            || ($roleControlEligible && $parameterDiff === [] && ! $architectureChanged);
        $rolePolicy = $councilRole !== ''
            ? $this->councilRolePolicyContract($councilRole, $family, $base, $parameters, $blockedMutationDirections)
            : null;
        if ($roleControl && is_array($rolePolicy)) {
            $rolePolicy['role_control'] = [
                'type' => 'no_change_control',
                'status' => 'no_legal_owner_mutation_available',
                'promotion_evidence' => false,
            ];
        }
        $structuralChangedGene = $entryTopologyEscape
            ? 'entry_topology_variant'
            : ($stateMachineEscape
                ? 'state_machine_variant'
                : ($regimeClassifierEscape
                    ? 'regime_classifier_variant'
                    : ($architectureInteractionEscape
                        ? 'architecture_interaction_variant'
                        : (($architectureExperiment && ! $architectureControlOnly && $architectureChanged)
                            ? '__architecture'
                            : null))));
        $tacticAlignment = $this->tactics->alignment(
            $tacticContract,
            $target,
            $structuralChangedGene ?? array_key_first($this->diff($base, $parameters)),
            $roleControl,
        );
        $controlRoot = app(ControlRootCatalogueService::class)->for($family, $architecture);
        $controlRootSeedDeclaration = $parentA === null && ! $nativeCouncilRoot
            ? $this->controlRootInheritance->seedDeclaration(
                $lab->symbol,
                $lab->timeframe,
                $family,
                $niche,
                $semanticGroup,
                $controlRoot,
                $architecture,
                $parameters,
            )
            : null;
        $pendingControlRootInheritance = $controlRootSeedAgent
            ? $this->controlRootInheritance->pendingChildDeclaration($controlRootSeedAgent, $semanticGroup, $target)
            : null;
        $parentPerformance = $parentA?->marketPerformances()
            ->where('symbol', $lab->symbol)
            ->where('timeframe', $lab->timeframe)
            ->latest('id')
            ->first();
        $parentMetrics = $parentPerformance?->metrics ?? [];
        $geneticParentIds = $mentorOnlyLane
            ? []
            : array_values(array_unique(array_filter([
                $parentA?->id,
                $canonicalParentB,
                ...array_values((array) ($adaptiveParentSelection['selected_parent_ids'] ?? [])),
                ...array_values($skillCrossoverSources),
            ], static fn ($id): bool => is_numeric($id) && (int) $id > 0)));
        // Every child receives an auditable inheritance contract. Parameters
        // are still the executable source of truth, while this snapshot
        // proves which frontier parent, target progress and confirmed traits
        // were carried into the next generation.
        $progressiveInheritance = $this->progressiveInheritanceContract(
            $parentA,
            $parentPerformance,
            $family,
            $target,
            $niche,
            $base,
            $parameters,
            $parentTier,
            $parentSelection,
            $semanticGroup,
            $parents->all(),
        );
        $constitution = $this->constitutions->draft($lab->symbol, $lab->timeframe, $family, $architecture, $parameters);
        $universalGenome = $this->universalCapabilities->genome(
            $lab->symbol,
            $lab->timeframe,
            $family,
            $architecture,
            $parameters,
            $parentA,
            $parents->all(),
        );
        $reservoirRecall = $this->regimeReservoir->recall($lab->symbol, $lab->timeframe, $mutationScope);
        // Model versions are globally unique. H1 keeps its established name;
        // additional execution timeframes receive an explicit namespace so an
        // EURUSD M15 G1 specialist cannot collide with EURUSD H1 G1.
        $timeframePrefix = strtoupper($lab->timeframe) === 'H1' ? '' : '_'.strtolower($lab->timeframe);
        $strategy = strtolower($lab->symbol).$timeframePrefix.'_'.$family.'_g'.$generation->generation.'_a'.str_pad((string) $slot, 2, '0', STR_PAD_LEFT);
        $organismLane = data_get($niche, 'protocol') === 'portfolio_council_v1'
            || $g98Target || $targetedFailureLane
            || in_array($origin, ['g98_council', 'council_role_complete', 'causal_isolation'], true)
            ? 'council' : 'champion';
        $failureRepairContract = null;
        if ($repairAnchor !== null) {
            $failureRepairContract = app(FailureSignatureCompilerService::class)->fromAnchor($repairAnchor);
            $hypothesisRevision = data_get($niche, 'structural_hypothesis_id')
                ?: data_get($niche, 'temporal_mutation_hypothesis')
                ?: implode('|', array_filter([
                    $repairSiblingKind,
                    $declaredGene,
                    $repairDirection,
                    data_get($niche, 'state_machine_variant'),
                    data_get($niche, 'architecture_variant'),
                ], fn ($value): bool => filled($value)));
            $failureRepairContract['hypothesis_revision'] = hash('sha256', is_scalar($hypothesisRevision)
                ? (string) $hypothesisRevision
                : json_encode($hypothesisRevision, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
            $failureRepairContract['repair_anchor_id'] = (int) $repairAnchor->id;
            $failureRepairContract['action'] = $repairControlOnly ? 'exact_frozen_control' : 'repair';
        }
        $learningPairRole = (string) data_get($niche, 'learning_method_contract.pair_role', '');
        $intentRole = $causalCohortRole !== ''
            ? $causalCohortRole
            : ($learningPairRole === 'exact_frozen_control'
                ? 'frozen_control'
                : (data_get($niche, 'evolutionary_experiment_block.block_type') === 'exact_repair'
                    ? 'repair_directed'
                    : null));
        $causalIntentPlan = app(CausalLearningMutationIntentService::class)->plan(
            $generation,
            $decisionPacket,
            $family,
            $target,
            $base,
            $parameters,
            $parameterDiff,
            $intentRole,
            (array) data_get($niche, 'causal_learning_cohort.skill_cartridge', []) ?: null,
            $failureRepairContract,
            (array) data_get($niche, 'learning_method_contract', []) ?: null,
        );
        if (in_array($causalCohortRole, ['memory_guided', 'hypothesis_guided'], true)
            && data_get($causalIntentPlan, 'skill_cartridge.status') !== 'compatible_cartridge_found') {
            $failureReason = 'CAUSAL_LEARNING_EXECUTABLE_CARTRIDGE_MISSING';

            return false;
        }
        // Parent/archive maintenance belongs only to an executable candidate.
        // Running it before mutation validation repeated the same expensive
        // writes for every zero-diff replacement that was never persisted.
        $this->publishConstructorStage($generation, $slot, 'archive_sync');
        $this->evolutionArchive->sync(
            $generation,
            $diagnosticParents,
            $archiveSelectedParents,
            $lab->symbol,
            $lab->timeframe,
            $family,
            $origin,
            $target,
            $niche,
            $archiveSelection,
        );
        $this->publishConstructorStage($generation, $slot, 'model_persistence');
        $activationExecutableHash = (string) data_get($cooperativeBlock, 'block_type') === 'activation_factorial'
            ? hash('sha256', json_encode([
                ProofFrontierService::PROTOCOL,
                $family,
                $this->schemas->canonicalizeForIdentity($family, $parameters),
                data_get($compositionPassport, 'components'),
                data_get($niche, 'contextual_specialist_cell.cell_hash'),
            ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) : null;
        $phaseProbeExecutableHash = (string) data_get($cooperativeBlock, 'block_type') === 'phase_scope_probe'
            ? hash('sha256', json_encode([
                (string) data_get($niche, 'phase_scope_probe.protocol',
                    ProofFrontierService::PHASE_PROBE_PROTOCOL),
                $family,
                $this->schemas->canonicalizeForIdentity($family, $parameters),
                data_get($compositionPassport, 'components'),
                data_get($niche, 'contextual_specialist_cell.cell_hash'),
                ...((string) data_get($niche, 'phase_scope_probe.protocol')
                    === ProofFrontierService::PHASE_PROBE_REFREEZE_PROTOCOL
                    ? [(string) data_get($niche, 'phase_scope_probe.prospective_passport_hash', '')] : []),
            ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) : null;
        if ($nativeFollowupSource !== null) $this->nativeCouncilFollowupResolution($nativeIntent, true, $generation->id);
        $model = ModelVersion::create([
            'name' => $strategy, 'strategy' => $strategy, 'version' => 'v'.$generation->generation,
            'generation' => $generation->generation, 'status' => 'testing', 'parameters' => $parameters,
            'description' => "{$lab->name} generation {$generation->generation} {$origin} agent",
            'metadata' => array_filter([
                'base_strategy' => $this->schemas->runtimeBaseStrategy($strategy, $this->architectureBaseStrategy($architecture), $family), 'strategy_architecture' => $architecture,
                'tactic_contract' => $tacticContract,
                'tactic_alignment' => $tacticAlignment,
                'instrument_learning_policy' => $instrumentMutationPolicy,
                'instrument_learning_consumption' => app(InstrumentPolicyConsumptionService::class)->receipt(
                    (array) $instrumentMutationPolicy,
                    (array) $parameterDiff,
                    (array) $parameters,
                ),
                'smart_composition' => $compositionLane !== '' ? [
                    'protocol' => StrategyTacticRiskCompositionPlannerService::PROTOCOL,
                    'lane' => $compositionLane,
                    'strategy_library_id' => data_get($niche, 'strategy_library_id'),
                    'strategy_library_contract' => $strategyLibraryContract !== [] ? $strategyLibraryContract : null,
                    'tactic_library_key' => data_get($niche, 'tactic_library_key'),
                    'risk_library_id' => data_get($niche, 'risk_library_id'),
                    'risk_library_contract' => $riskLibraryContract !== [] ? $riskLibraryContract : null,
                    'risk_mutation_gene' => $riskMutationGene !== '' ? $riskMutationGene : null,
                    // A composition is authoritative only when the planner
                    // sealed this exact passport before agent construction.
                    // It remains immutable for the replay/trade lifetime.
                    'composition_passport' => data_get($niche, 'composition_passport'),
                    'causal_packet' => data_get($niche, 'causal_packet'),
                    'paired_control_required' => true,
                    'promotion_evidence' => false,
                ] : null,
                'lab_symbol' => $lab->symbol, 'origin' => $origin,
                'lab_timeframe' => $lab->timeframe,
                'native_specialist_council_seed' => $nativeCouncilRoot
                    ? [...$nativeSeed, 'lab_generation_id' => (int) $generation->id] : null,
                'causal_baseline_model_version_id' => $exactPairBaselineModel?->id,
                'activation_factorial_baseline_model_version_id' => $activationFactorialBaselineModel?->id,
                'phase_scope_baseline_model_version_id' => $phaseScopeBaselineModel?->id,
                'activation_factorial' => $activationExecutableHash !== null
                    ? [...(array) data_get($niche, 'activation_factorial', []),
                        'executable_hash' => $activationExecutableHash] : null,
                'phase_scope_probe' => $phaseProbeExecutableHash !== null
                    ? [...(array) data_get($niche, 'phase_scope_probe', []),
                        'executable_hash' => $phaseProbeExecutableHash] : null,
                'causal_baseline_is_genetic_parent' => false,
                'twin_intelligence' => app(TwinIntelligenceProfileService::class)->contract($organismLane),
                'population_group' => [
                    ...$populationGroup,
                    'prior_checkpoint' => $priorGroupCheckpoint !== [] ? $priorGroupCheckpoint : null,
                    'checkpoint_inheritance_rule' => 'Use the prior group checkpoint as bounded research context; exact semantic parent and unchanged gates remain authoritative.',
                    'promotion_evidence' => false,
                ],
                'risk_bounded_evolution' => [
                    'protocol' => 'risk_bounded_exploration_governor_v1',
                    'mode' => $evolutionMode !== '' ? $evolutionMode : 'standard_research',
                    'outcome_mode' => data_get($niche, 'outcome_mode', 'uncertainty'),
                    'outcome_policy' => data_get($niche, 'outcome_policy'),
                    'mutation_step_multiplier' => $riskStepMultiplier,
                    'control_only' => $roleControl,
                    'exploration_domain' => data_get($niche, 'exploration_domain'),
                    'volume_shadow' => (bool) data_get($niche, 'volume_shadow', false),
                    'adversarial_red_team' => (bool) data_get($niche, 'adversarial_red_team', false),
                    'research_only_until_independent_replay' => (bool) data_get($niche, 'research_only_until_independent_replay', false),
                    'promotion_evidence' => false,
                ],
                'hybrid_evolution' => [
                    ...$hybridContract,
                    'lane' => $hybridLane !== '' ? $hybridLane : data_get($hybridContract, 'lane', 'directed_repair'),
                    'multi_gene' => $hybridMultiGene,
                    'changed_genes' => data_get($hybridContract, 'changed_genes', []),
                    'research_only_until_independent_confirmation' => true,
                    'promotion_evidence' => false,
                ],
                // Keep the shadow admission contract on the model itself,
                // not only in generation_plan/portfolio_council_lane.  Gate
                // and learning services must be able to enforce it from the
                // immutable candidate metadata.
                'shadow_research_lane' => data_get($niche, 'shadow_research_lane') !== null ? [
                    ...(array) data_get($niche, 'shadow_research_lane', []),
                    'shadow_only' => true,
                    'promotion_evidence' => false,
                ] : null,
                'shadow_mutation_gene' => $shadowMutationGene !== '' ? $shadowMutationGene : null,
                'shadow_mutation_contract' => data_get($niche, 'shadow_mutation_contract'),
                // Root portfolio candidates are genuine causal interventions,
                // not the old unpaired data-edge seeds.  Keep this marker on
                // the immutable model because observability runs after the
                // transient generation plan is no longer in scope.
                'root_experiment_portfolio' => (bool) data_get($niche, 'root_experiment_portfolio', false),
                'root_experiment_lane' => data_get($niche, 'experiment_lane'),
                'root_experiment_hypothesis' => data_get($niche, 'hypothesis'),
                'root_experiment_hypothesis_family' => data_get($niche, 'hypothesis_family'),
                // Preserve the exact declared causal mechanism on the model
                // itself. Observability runs from immutable model metadata,
                // not the discarded planner array, so this prevents a root
                // confidence/exit/topology result from being recorded as an
                // anonymous parameter change.
                'declared_gene' => $declaredGene !== '' ? $declaredGene : null,
                'root_experiment_contract' => $rootPortfolioSeat ? [
                    'protocol' => self::ROOT_EXPERIMENT_PORTFOLIO_PROTOCOL,
                    'declared_gene' => $declaredGene !== '' ? $declaredGene : null,
                    'declared_value' => data_get($niche, 'declared_value'),
                    'hypothesis_family' => data_get($niche, 'hypothesis_family'),
                    'behavioral_falsification' => data_get($niche, 'behavioral_falsification'),
                    'behavioral_change_required' => ! $riskControlOnly,
                    'trade_ledger_delta_required' => ! $riskControlOnly,
                    'control_pair_required' => ! $riskControlOnly,
                    'promotion_evidence' => false,
                ] : null,
                'entry_topology_variant' => data_get($niche, 'entry_topology_variant'),
                'state_machine_variant' => data_get($niche, 'state_machine_variant'),
                'regime_classifier_variant' => data_get($niche, 'regime_classifier_variant'),
                'architecture_interaction_variant' => data_get($niche, 'architecture_interaction_variant'),
                'structural_research_contract' => $structuralResearch ? [
                    'protocol' => 'normal_structural_hypothesis_v1',
                    'hypothesis_id' => data_get($niche, 'structural_hypothesis_id'),
                    'operation' => data_get($niche, 'structural_operation'),
                    'declared_gene' => $declaredGene !== '' ? $declaredGene : null,
                    'declared_genes' => $hybridMultiGene
                        ? array_values(array_map('strval', array_keys((array) data_get($niche, 'declared_values', []))))
                        : array_values(array_filter([$declaredGene !== '' ? $declaredGene : null])),
                    'hybrid_evolution_lane' => $hybridLane !== '' ? $hybridLane : null,
                    'max_changed_genes' => $hybridMultiGene ? (int) data_get($hybridContract, 'max_changed_genes', 3) : 1,
                    'declared_value' => data_get($niche, 'declared_value'),
                    'executable_parameter_diff_required' => true,
                    'signal_or_regime_behavior_required' => true,
                    'control_pair_required' => true,
                    'promotion_evidence' => false,
                ] : null,
                'control_pair_contract' => data_get($niche, 'control_pair_contract'),
                'parent_mentor_broker' => [
                    ...$parentMentorContract,
                    'parent_lane' => $parentLane,
                    'parameter_baseline_source' => $mentorOnlyLane
                        ? 'schema_defaults'
                        : ($exactPairBaselineModel
                            ? 'same_generation_exact_control'
                            : ($parentA ? 'exact_semantic_parent' : ($frozenResearchSeedAgent ? 'same_cell_frozen_research_seed' : 'schema_defaults'))),
                    'parent_used_as_parameter_source' => $parentA !== null && ! $mentorOnlyLane,
                    'parent_suggestion_applied' => $parentMentorApplied,
                    'promotion_evidence' => false,
                ],
                'contextual_specialist_identity' => (array) data_get($niche, 'contextual_specialist_cell', []) !== [] ? app(ContextualSpecialistIdentityService::class)->build(
                    (array) data_get($niche, 'contextual_specialist_cell', []),
                    $niche,
                    (string) (data_get($niche, 'cooperative_evolution_capsule.components.strategy') ?: $family),
                    (array) $tacticContract,
                    (array) $instrumentMutationPolicy,
                    [
                        'strategy_library_id' => data_get($niche, 'strategy_library_id'),
                        'risk_library_id' => data_get($niche, 'risk_library_id'),
                        'management_id' => data_get($niche, 'management_id'),
                    ],
                ) : null,
                'cooperative_evolution_capsule' => data_get($niche, 'cooperative_evolution_capsule'),
                'cooperative_experiment_block' => data_get($niche, 'cooperative_experiment_block'),
                'uncertainty_abstain_contract' => data_get($niche, 'uncertainty_abstain_contract'),
                'specialist_council_membership' => [
                    'protocol' => self::SPECIALIST_COUNCIL_PROTOCOL,
                    'group_key' => $researchGroup,
                    'member_role' => data_get($populationGroup, 'search_role'),
                    'contextual_cell' => data_get($niche, 'contextual_specialist_cell'),
                    'session_ownership' => data_get($niche, 'contextual_specialist_cell.session_ownership'),
                    'parameter_specialties' => array_keys($parameterDiff),
                    'global_champion' => false,
                    'council_frontier_only_until_individual_passport' => true,
                    'promotion_evidence' => false,
                ],
                'semantic_group' => $semanticGroup,
                'semantic_map_elites' => app(SemanticEliteArchiveService::class)->contract(
                    // The group descriptor is the immutable cell identity;
                    // this model is the child being placed into the archive.
                    // A temporary model is unnecessary because the cell key
                    // is already persisted in semantic_group below.
                    ModelVersion::make(['metadata' => ['semantic_group' => $semanticGroup]]),
                    $family,
                ),
                'semantic_module_crossover' => app(SkillZooService::class)->semanticCrossoverPlan(
                    $lab->symbol,
                    $lab->timeframe,
                    $family,
                ),
                'adaptive_parent_ecosystem' => $adaptiveParentSelection['contract'] ?? null,
                'capability_genome' => $adaptiveParentSelection['capability_genome'] ?? null,
                'capability_gene_provenance' => $capabilityGeneProvenance ?: null,
                'runtime_ensemble_policy' => $adaptiveParentSelection['runtime_ensemble_policy'] ?? null,
                'parent_inheritance_protocol' => [
                    'protocol' => 'exact_semantic_parent_or_group_root_v1',
                    'parent_selection' => $parentSelection,
                    'parent_status' => $parentA ? 'attached' : 'not_available',
                    'parent_lane' => $parentLane,
                    'mentor_only_parameter_baseline' => $mentorOnlyLane,
                    'mentor_parent_vector_copy_forbidden' => $mentorOnlyLane,
                    'parent_graph_protocol' => 'lab_agent_parent_graph_v1',
                    'control_root_protocol' => $controlRootSeedAgent ? ControlRootInheritanceService::PROTOCOL : null,
                    'control_root_seed_model_version_id' => $controlRootSeedAgent?->model_version_id,
                    'frozen_research_seed_model_version_id' => $frozenResearchSeedAgent?->model_version_id,
                    'frozen_research_seed_agent_id' => $frozenResearchSeedAgent?->id,
                    'frozen_research_seed_is_genetic_parent' => false,
                    'frozen_research_seed_promotion_evidence' => false,
                    'cross_cell_parent_forbidden' => true,
                    'legacy_parent_genetic_material' => false,
                    'repair_anchor_id' => $repairAnchor?->id,
                    'repair_anchor_is_genetic_parent' => false,
                    'promotion_evidence' => false,
                ],
                'control_root' => $controlRoot,
                'control_root_seed' => $controlRootSeedDeclaration,
                'control_root_specialist_inheritance' => $pendingControlRootInheritance,
                'ai_policy_boundary' => [
                    'protocol' => 'bounded_ai_policy_authority_v1',
                    'signal_generator' => false,
                    'gate_threshold_mutation' => false,
                    'allowed_layers' => ['paper_only_position_sizing', 'paper_only_execution'],
                    'status' => 'enforced',
                    'promotion_evidence' => false,
                ],
                'generation_target' => $target,
                'historical_learning' => $history ? [
                    'protocol' => LabHistoricalLearningService::PROTOCOL,
                    'insight_id' => $historyInsightId,
                    'evidence_quality' => data_get($history, 'evidence_quality'),
                    'causal_prior_allowed' => (bool) data_get($history, 'causal_prior_allowed', false),
                    'confidence' => (float) data_get($history, 'confidence', 0),
                    'primary_target' => data_get($history, 'primary_target', data_get($history, 'recommended_mutations.primary_target')),
                    'recommended_keys' => $historyKeys,
                    'blocked_mutations' => (array) data_get($history, 'blocked_mutations', []),
                    'failure_signature' => (array) data_get($history, 'failure_signature', []),
                    'direction_rule' => 'Only independently confirmed exact replay credits can influence mutation direction.',
                ] : null,
                'g98_council_lane' => ($g98Target || $targetedFailureLane) ? [
                    'protocol' => self::GENERATION_PROTOCOL,
                    'lane' => $target,
                    'mutation_target' => $mutationTarget,
                    'mutation_layers' => 1,
                    'research_variant' => data_get($niche, 'recall_variant'),
                    'targeted_failure_profile' => $targetedFailureLane,
                    'parent_lane_freeze' => true,
                    'control_only' => $roleControl,
                    'control_reason' => $roleControl
                        ? ($noLegalOwnerMutationControl ? 'all_legal_owner_mutations_blocked' : 'role_policy_no_change')
                        : null,
                    'acceptance_rule' => 'causal_blame_proven_and_unchanged_gates_pass',
                ] : null,
                'coverage_rescue_contract' => data_get($niche, 'protocol') === CoverageRescueAuditService::PROTOCOL ? [
                    'protocol' => CoverageRescueAuditService::PROTOCOL,
                    'parent_model_version_id' => $parentA?->id,
                    'target_envelope' => [
                        'regime' => data_get($niche, 'regime'), 'volatility' => data_get($niche, 'volatility'),
                        'session_utc_hour' => data_get($niche, 'session_utc_hour'), 'direction' => data_get($niche, 'direction'),
                    ],
                    'entry_logic_frozen' => true, 'exit_logic_frozen' => true,
                    'non_target_replay_required' => ['signal_identity', 'confidence_identity', 'trade_ledger_identity'],
                    'breach_action' => 'technical_quarantine',
                ] : null,
                'portfolio_council_lane' => $niche,
                'repair_anchor' => $repairAnchor !== null ? [
                    'protocol' => FailureRepairAnchorService::PROTOCOL,
                    'id' => (int) $repairAnchor->id,
                    'source_lab_agent_id' => (int) $repairAnchor->source_lab_agent_id,
                    'source_model_version_id' => (int) $repairAnchor->source_model_version_id,
                    'source_generation_id' => (int) $repairAnchor->source_lab_generation_id,
                    'failure_reason' => $repairAnchor->failure_reason,
                    'failure_target' => $repairAnchor->failure_target,
                    'parameter_fingerprint' => $repairAnchor->parameter_fingerprint,
                    'snapshot_is_immutable' => true,
                    'genetic_parent_forbidden' => true,
                    'paired_screening_required' => true,
                    'full_replay_required' => true,
                    'independent_forward_required' => true,
                    'mutation_credit_after' => ['paired_screening', 'full_replay', 'independent_forward'],
                    'sibling_cohort_id' => data_get($niche, 'repair_anchor_sibling_cohort_id'),
                    'sibling_index' => data_get($niche, 'repair_anchor_sibling_index'),
                    'sibling_kind' => $repairSiblingKind !== '' ? $repairSiblingKind : null,
                    'cohort_contract' => data_get($niche, 'cohort_contract', 'legacy_three_siblings_plus_control_v1'),
                    'repair_direction' => $repairDirection !== '' ? $repairDirection : null,
                    'control_only' => $repairControlOnly,
                    'anchor_policy' => (array) data_get($niche, 'anchor_policy', []),
                    'promotion_evidence' => false,
                ] : null,
                'repair_anchor_sibling' => $repairAnchor !== null ? [
                    'protocol' => self::ANCHOR_SIBLING_PROTOCOL,
                    'cohort_id' => data_get($niche, 'repair_anchor_sibling_cohort_id'),
                    'index' => data_get($niche, 'repair_anchor_sibling_index'),
                    'kind' => $repairSiblingKind !== '' ? $repairSiblingKind : null,
                    'cohort_contract' => data_get($niche, 'cohort_contract', 'legacy_three_siblings_plus_control_v1'),
                    'direction' => $repairDirection !== '' ? $repairDirection : null,
                    'declared_gene' => $declaredGene !== '' ? $declaredGene : null,
                    'role' => $councilRole !== '' ? $councilRole : null,
                    'control_only' => $repairControlOnly,
                    'paired_screening_required' => ! $repairControlOnly,
                    'promotion_evidence' => false,
                ] : null,
                'skill_mentor_input' => $skillMentorInput !== null ? [
                    'protocol' => data_get($skillMentorInput, 'status') === 'provisional'
                        ? LearningLaneService::PROTOCOL
                        : SkillMentorService::PROTOCOL,
                    'lesson_id' => data_get($skillMentorInput, 'lesson_id'),
                    'pair_id' => data_get($skillMentorInput, 'pair_id'),
                    'status' => data_get($skillMentorInput, 'status', 'confirmed'),
                    'research_only' => (bool) data_get($skillMentorInput, 'research_only', false),
                    'response_map_id' => data_get($skillMentorInput, 'response_map_id'),
                    'model_version_id' => data_get($skillMentorInput, 'model_version_id'),
                    'parameter_key' => data_get($skillMentorInput, 'parameter_key'),
                    'target' => data_get($skillMentorInput, 'target'),
                    'direction' => data_get($skillMentorInput, 'direction'),
                    'applied' => $skillMentorApplied,
                    'compatible_gene_only' => true,
                    'parent_inheritance' => false,
                    'promotion_evidence' => false,
                ] : null,
                'professional_learning_lane' => [
                    'protocol' => 'professional_learning_lane_v1',
                    'curiosity_lane' => $curiosityLane,
                    'selection_lane' => $curiosityLane ? 'curiosity_research' : 'standard_research',
                    'promotion_evidence' => false,
                ],
                'mutation_budget' => data_get($knowledgeContract, 'mutation_budget', []),
                'portfolio_council_source_performance_id' => data_get($niche, 'source_performance_id'),
                'portfolio_council_parent_selection' => [
                    'protocol' => 'failure_context_frontier_parent_v1',
                    'requested_failure_source_performance_id' => $sourcePerformance?->id,
                    'selected_parent_model_version_id' => $parentA?->id,
                    'selection' => $parentSelection,
                    'source_was_eligible_exact_parent' => (bool) $sourceCanBeExactParent,
                    'failure_context_remains_diagnostic_only' => (bool) $sourcePerformance && ! $sourceCanBeExactParent,
                    'promotion_evidence' => false,
                ],
                'state_cluster_contract' => $g98Target && data_get($niche, 'state_cluster') ? [
                    'protocol' => 'state_cluster_v1',
                    'cluster' => data_get($niche, 'state_cluster'),
                    'month_labels_are_diagnostic_only' => true,
                    'mutation_feature_allowlist' => [
                        'regime', 'volatility', 'transition_state',
                        'spread_liquidity_state', 'veto_reason',
                    ],
                    'promotion_evidence' => false,
                ] : null,
                'council_specialist_contract' => data_get($niche, 'protocol') === 'portfolio_council_v1' ? [
                    'protocol' => 'agent_council_v1',
                    'role' => data_get($niche, 'specialist_role', data_get($niche, 'role')),
                    'owner_regime' => data_get($niche, 'regime'),
                    'owner_volatility' => data_get($niche, 'volatility'),
                    'owner_direction' => data_get($niche, 'direction'),
                    'owner_session' => data_get($niche, 'contextual_specialist_cell.session'),
                    'owner_venue_phase' => data_get($niche, 'contextual_specialist_cell.venue_phase'),
                    'owner_context' => data_get($niche, 'owner_context'),
                    'lane' => $target,
                    'mutation_target' => $mutationTarget,
                    'standalone_forward_passport_required' => true,
                    'combined_replay_after_individual_passports' => true,
                    'promotion_evidence' => false,
                ] : null,
                'role_complete_council' => (bool) data_get($niche, 'role_complete_council', false) ? [
                    'protocol' => self::ROLE_COMPLETE_COUNCIL_PROTOCOL,
                    'role' => data_get($niche, 'specialist_role', data_get($niche, 'role')),
                    'full_replay_required' => true,
                    'standalone_forward_passport_required' => true,
                    'router_before_combined_replay' => true,
                    'policy' => $rolePolicy,
                    'role_control' => $roleControl ? [
                        'type' => 'no_change_control',
                        'status' => 'no_legal_owner_mutation_available',
                        'promotion_evidence' => false,
                    ] : null,
                    'promotion_evidence' => false,
                ] : null,
                // Council members enter a research-only lane first. Keep the
                // routing declaration and the admission contract under one
                // canonical metadata name so a strong niche child can be
                // selected for combined replay after full validation.
                'portfolio_research_contract' => data_get($niche, 'protocol') === 'portfolio_council_v1' ? [
                    'protocol' => 'portfolio_member_research_v1',
                    'status' => 'screening_seed',
                    'target_regime' => data_get($niche, 'regime'),
                    'target_volatility' => data_get($niche, 'volatility'),
                    'target_direction' => data_get($niche, 'direction'),
                    'target_session' => data_get(
                        $niche,
                        'contextual_specialist_cell.session',
                        data_get($niche, 'session'),
                    ),
                    'target_venue_phase' => data_get($niche, 'contextual_specialist_cell.venue_phase'),
                    'contextual_specialist_cell' => data_get($niche, 'contextual_specialist_cell'),
                    'screening_agent_id' => null,
                    'promotion_rule' => 'standalone_forward_passport_required; member_never_promotes_as_champion; combined_portfolio_after_passports',
                    'standalone_forward_required' => true,
                    'combined_replay_only_after_individual_passports' => true,
                ] : null,
                'parent_provenance' => $parentTier,
                'screening_seed_only' => $parentTier === 'screening_seed'
                    || ($parentTier === 'no_parent' && $parentSelection === 'archive_revival_research_seed'),
                'evolution_stage' => [
                    'protocol' => SkillMentorService::PROTOCOL,
                    'stage' => $repairControlOnly ? 'repair_anchor_control' : ($repairAnchor !== null ? 'repair_anchor' : 'candidate'),
                    'screening_passed' => false,
                    'skill_mentor' => false,
                    'full_parent' => false,
                    'parent_eligible' => false,
                    'promotion_evidence' => false,
                ],
                'archive_revival' => [
                    'status' => $parentSelection === 'archive_revival_research_seed' ? 'research_seed' : 'not_used',
                    'parent_tier' => $parentTier,
                    'independent_replay_required' => true,
                    'old_score_not_inherited' => true,
                    'promotion_evidence' => false,
                ],
                'semantic_lineage' => [
                    'protocol' => 'strict_semantic_lineage_v2',
                    'parent_status' => $mentorOnlyLane ? 'mentor_source_only' : ($parentA ? 'attached' : 'not_available'),
                    'child_group_key' => data_get($semanticGroup, 'key'),
                    'genetic_parent_model_version_id' => $geneticParentIds[0] ?? null,
                    'genetic_parent_model_version_ids' => $geneticParentIds,
                    'genetic_parent_group_key' => $parentA
                        ? data_get($this->semanticGroups->fromModel($parentA, $family), 'key')
                        : null,
                    'mode' => $mentorOnlyLane
                        ? 'mentor_broker_source_only'
                        : ($controlRootSeedAgent
                        ? 'control_root_seed_inheritance'
                        : ($parentA ? 'exact_semantic_parent' : 'no_parent_available')),
                    'legacy_parent_ids_diagnostic_only' => $diagnosticParents
                        ->filter(fn (ModelVersion $candidate): bool => (bool) data_get($this->semanticGroups->fromModel($candidate, $family), 'legacy_unscoped', false))
                        ->pluck('id')->values()->all(),
                    'diagnostic_candidate_count' => $diagnosticParents->count(),
                    'adaptive_selected_parent_count' => count((array) ($adaptiveParentSelection['selected_parent_ids'] ?? [])),
                    'promotion_evidence' => false,
                    'rule' => 'Exact semantic key only for genetic inheritance; all other candidates remain diagnostic controls.',
                ],
                'progressive_inheritance' => $progressiveInheritance,
                'causal_experiment_lane' => ($g98Target || $targetedFailureLane || $origin === 'causal_isolation' || $origin === 'g98_council' || $origin === 'curiosity_probe' || $family === 'differential_router') ? [
                    'status' => $roleControl ? 'no_change_control' : 'isolated_single_gene',
                    'rule' => 'One changed parameter only; requires parent and same-generation alternative before causal credit.',
                    'control_only' => $roleControl,
                ] : null,
                'strategic_research_director' => [
                    'protocol' => StrategicResearchDirectorService::PROTOCOL,
                    'action_space' => app(StrategicResearchDirectorService::class)->actionSpace(),
                    'belief_state_protocol' => StrategicResearchDirectorService::BELIEF_PROTOCOL,
                    'causal_decision_graph_protocol' => StrategicResearchDirectorService::CAUSAL_GRAPH_PROTOCOL,
                    'prediction_market_protocol' => 'prediction_market_for_research_v1',
                    'experiment_chain_protocol' => StrategicResearchDirectorService::EXPERIMENT_CHAIN_PROTOCOL,
                    'hypothesis_retirement_protocol' => StrategicResearchDirectorService::RETIREMENT_PROTOCOL,
                    'router_safety_protocol' => StrategicResearchDirectorService::ROUTER_PROTOCOL,
                    'selected_action' => data_get($niche, 'strategic_research_action'),
                    'required_fields' => [
                        'failure_cause', 'decision_layer', 'falsifiable_hypothesis',
                        'expected_behavioral_delta', 'exact_control',
                        'disconfirming_result', 'next_experiment',
                    ],
                    'allowed_actions' => [
                        'TRADE', 'ABSTAIN', 'REQUEST_NEW_DATA', 'RUN_COUNTERFACTUAL',
                        'REQUEST_CONTROL', 'SWITCH_SPECIALIST',
                        'WAIT_FOR_REGIME_CONFIRMATION', 'RETIRE_HYPOTHESIS',
                    ],
                    'pf_is_not_sufficient' => true,
                    'research_only' => true,
                    'mutation_credit' => false,
                    'parent_eligible' => false,
                    'promotion_evidence' => false,
                ],
                'hypothesis_contract' => ($g98Target || $targetedFailureLane || $origin === 'coverage_rescue' || $family === 'differential_router') ? [
                    'protocol' => 'hypothesis_laboratory_v1',
                    'hypothesis' => 'Improve only the declared operating-envelope failure without changing any protected lane.',
                    'target_lane' => $target,
                    'target_context' => $niche ? [
                        'regime' => data_get($niche, 'regime'), 'volatility' => data_get($niche, 'volatility'),
                        'session_utc_hour' => data_get($niche, 'session_utc_hour'), 'direction' => data_get($niche, 'direction'),
                        'state_cluster' => data_get($niche, 'state_cluster'),
                    ] : null,
                    // The persisted diff is the sole mutation truth. Planner
                    // declarations remain useful diagnostics, but can never
                    // make a receipt name a gene that the child did not run.
                    'changed_gene' => count($parameterDiff) === 1
                        ? (string) array_key_first($parameterDiff)
                        : null,
                    'changed_genes' => array_values(array_map('strval', array_keys($parameterDiff))),
                    'planner_declared_gene' => $structuralChangedGene
                        ?? ($shadowMutationGene !== '' ? $shadowMutationGene : ($declaredGene !== '' ? $declaredGene : $isolatedKey)),
                    'architecture_changed' => $architectureChanged,
                    'architecture_variant' => $architectureExperiment && ! $stateMachineEscape ? $architecture : null,
                    'entry_topology_variant' => $entryTopologyEscape ? $entryTopologyVariant : null,
                    'falsifiable_statement' => data_get($niche, 'hypothesis_contract.falsifiable_statement'),
                    'expected_behavioral_delta' => data_get($niche, 'hypothesis_contract.expected_behavioral_delta'),
                    'non_target_invariants' => data_get($niche, 'hypothesis_contract.non_target_invariants', []),
                    'expected_window' => data_get($niche, 'hypothesis_contract.expected_window'),
                    'pre_registered_prediction_protocol' => 'pre_registered_prediction_v1',
                    'unchanged_lane_invariant' => $family === 'differential_router'
                        ? 'Non-target signal, confidence and trade-ledger identities must equal the frozen parent.'
                        : 'Only the declared single gene may differ from the frozen parent/default.',
                    'independent_reconfirmations_required' => 3,
                    'retire_family_after_failed_independent_replays' => 2,
                    'parent_rule' => 'Only independently confirmed beneficial credits may become reusable mutation priors.',
                    'tactic_alignment' => $tacticAlignment,
                    'hybrid_evolution' => $hybridContract,
                    'causal_mechanism' => data_get($hybridContract, 'causal_mechanism'),
                    'falsification_condition' => data_get($hybridContract, 'falsification_condition', []),
                    'rollback_condition' => data_get($hybridContract, 'rollback_condition'),
                    'kill_condition' => data_get($hybridContract, 'kill_condition'),
                    'research_only_until_independent_confirmation' => true,
                ] : null,
                'mutation_bundle' => $this->evolutionQuality->curriculum($parentMetrics)['bounded_bundle'] ?? null,
                'mutation_scope' => $mutationScope,
                'skill_crossover_sources' => $skillCrossoverSources ?: null,
                'parent_contribution_graph' => [
                    'protocol' => 'lab_agent_parent_graph_v1',
                    'all_parent_model_version_ids' => $geneticParentIds,
                    'primary_parent_a_model_version_id' => $mentorOnlyLane ? null : $parentA?->id,
                    'primary_parent_b_model_version_id' => $mentorOnlyLane ? null : $canonicalParentB,
                    'control_root_seed_model_version_id' => $controlRootSeedAgent?->model_version_id,
                    'frozen_research_seed_model_version_id' => $frozenResearchSeedAgent?->model_version_id,
                    'skill_source_model_version_ids' => $skillCrossoverSources,
                    'capability_gene_provenance' => $capabilityGeneProvenance,
                    'adaptive_selected_parent_model_version_ids' => $mentorOnlyLane ? [] : (array) ($adaptiveParentSelection['selected_parent_ids'] ?? []),
                    'adaptive_selection_contract' => $adaptiveParentSelection['contract'] ?? null,
                    'promotion_evidence' => false,
                ],
                'agent_knowledge_contract' => $knowledgeContract,
                // The evolution director can only influence a child through
                // this sealed directive and the manifest emitted below. Raw
                // accounting observations are deliberately absent here.
                'learning_evolution_directive' => data_get($niche, 'learning_evolution'),
                // A frozen near-forward parent is never edited in place.
                // This child is the only allowed research fork from it.
                'elite_candidate_fork' => data_get($parentA?->metadata, 'elite_agent_passport.freeze.status') === 'frozen'
                    ? ['parent_model_version_id' => $parentA->id, 'parent_parameter_hash' => data_get($parentA->metadata, 'elite_agent_passport.freeze.parameter_hash')]
                    : null,
                'parameter_fingerprint' => $this->parameterFingerprint($family, $parameters),
                'historical_novelty_constructor' => $historicalNoveltyAudit,
                'mutation_constructor_invariant' => [
                    'protocol' => 'agent_constructor_invariant_v1',
                    'status' => 'passed',
                    'control_only' => $roleControl,
                    'architecture_changed' => $architectureChanged,
                    'architecture_variant' => $architectureExperiment && ! $stateMachineEscape ? $architecture : null,
                    'entry_topology_variant' => $entryTopologyEscape ? $entryTopologyVariant : null,
                    'single_gene_required' => $strictSingleGene,
                    'hybrid_multi_gene' => $hybridMultiGene,
                    'hybrid_lane' => $hybridLane !== '' ? $hybridLane : null,
                    'hybrid_max_changed_genes' => $hybridMultiGene
                        ? (int) data_get($hybridContract, 'max_changed_genes', 3)
                        : 1,
                    'changed_parameter_keys' => array_keys($parameterDiff),
                    'parameter_diff_count' => count($parameterDiff),
                    'parent_model_version_id' => $parentA?->id,
                    'causal_baseline_model_version_id' => $exactPairBaselineModel?->id,
                    'causal_baseline_is_genetic_parent' => false,
                    'parent_rule' => $parentA
                        ? 'Child inherits only from its exact declared semantic parent.'
                        : 'No exact parent is available; child starts from the semantic group root/default seed.',
                    'promotion_evidence' => false,
                ],
                // New generations must produce actual CSCV/PBO, DSR and
                // bootstrap evidence before paper promotion. Legacy records
                // remain auditable but cannot silently define this protocol.
                'statistical_gate_version' => 3,
                'robustness_gate_version' => 1,
                'repair_lineage' => $repairLineage,
                'agent_constitution' => $constitution,
                'universal_genome' => $universalGenome,
                'regime_reservoir_recall' => $reservoirRecall,
                // Store the exact symbol-aware contract used by every replay
                // lane. The old differential protocol string was not an
                // execution hash and made an otherwise valid FX cohort fail
                // the later full-validation preflight.
                'execution_contract' => $this->nativeFollowupExecutionContract($nativeFollowupSource,
                    $lab->symbol,
                    app(MultiTimeframePilotService::class)->replayTimeframe($lab->symbol, $lab->timeframe),
                ),
                'original_source_execution_snapshot' => data_get($nativeFollowupSource, 'original_descriptor_materialization.protocol')
                    === SpecialistCouncilResearchFeedbackService::OBSERVED_SOURCE_SNAPSHOT_PROTOCOL
                    ? data_get($nativeFollowupSource, 'original_descriptor_materialization') : null,
                'differential_router_contract' => $family === 'differential_router' && $parentA ? [
                    'parent_model_version_id' => $parentA->id,
                    'parent_frozen_hash' => hash('sha256', json_encode(data_get($parentA->metadata, 'last_screen_result', []), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)),
                    'target_regime' => $parameters['differential_target_regime'],
                    'target_parameter' => array_key_first($this->diff($base, $parameters)),
                    'execution_contract' => LearningProtocolSafetyService::EXECUTION_CONTRACT,
                    'non_target_parent_freeze' => true,
                ] : null,
            ]),
        ]);
        if ($exactPairControl) {
            $exactControlMetadata = (array) $model->metadata;
            $exactControlMetadata['causal_baseline_model_version_id'] = (int) $model->id;
            $exactControlMetadata['causal_baseline_is_genetic_parent'] = false;
            $exactControlMetadata['control_pair_contract'] = [
                ...((array) data_get($exactControlMetadata, 'control_pair_contract', [])),
                'causal_baseline_model_version_id' => (int) $model->id,
                'causal_baseline_is_genetic_parent' => false,
            ];
            data_set(
                $exactControlMetadata,
                'mutation_constructor_invariant.causal_baseline_model_version_id',
                (int) $model->id,
            );
            $model->update(['metadata' => $exactControlMetadata]);
        }
        // This opt-in constructor hook cannot intervene in protected/exact comparative seats.
        // New policy ownership is attached before parameter, intent and passport sealing.
        $methodDisposition = app(AgentMethodGenerationService::class)->prepareConstructorChild(
            $model->fresh(), $parentA, $generation, $slot, $niche,
        );
        if (($methodDisposition['status'] ?? null) !== 'not_requested') {
            $model->refresh();
            $model->update(['metadata' => [...(array) $model->metadata,
                'instrument_method_generation_disposition' => $methodDisposition]]);
        }
        // Reconcile the persisted model identity before it can enter any
        // queue.  Parameters are the source of truth; a stale fingerprint or
        // universal-genome hash makes a child impossible to audit even when
        // its diff has one gene.  This is a draft-time metadata repair only;
        // it never changes a parameter or creates promotion evidence.
        $this->sealParameterIntegrity($model, $family);
        $sealedCausalIntent = app(CausalLearningMutationIntentService::class)->seal(
            $causalIntentPlan,
            $generation,
            $model->fresh(),
        );
        if (($causalCohortRole !== '' || $repairAnchor !== null || $learningPairRole !== '')
            && ! $sealedCausalIntent instanceof AgentLearningMutationIntent) {
            throw new \RuntimeException('CAUSAL_LEARNING_INTENT_SEAL_FAILED '.json_encode($sealedCausalIntent, JSON_UNESCAPED_SLASHES));
        }
        $agent = $generation->agents()->create([
            'model_version_id' => $model->id, 'parent_a_model_version_id' => $parentA?->id,
            'parent_b_model_version_id' => $canonicalParentB,
            'symbol' => $lab->symbol, 'timeframe' => $lab->timeframe, 'strategy_family' => $family,
            'origin' => $origin, 'lifecycle_status' => 'draft',
            'parameter_diff' => $parameterDiff,
            'decision_reason' => $exactPairCandidate
                ? 'One pre-registered intervention against the preceding exact same-generation frozen control; the control is a causal baseline, not a genetic parent.'
                : ($parentA
                    ? null
                    : 'Parent currently unavailable; agent starts without a parent and may use an exact parent in a later generation.'),
        ]);
        $this->publishConstructorStage($generation, $slot, 'post_persistence_contracts');
        $agent->setRelation('modelVersion', $model);
        $inheritanceDirective = (array) data_get($niche, 'learning_evolution', []);
        $causalInheritance = (array) data_get($niche, 'causal_learning_cohort', []);
        if ($causalInheritance !== []) {
            $causalRole = (string) data_get($causalInheritance, 'role', '');
            $actualCausalGene = count($parameterDiff) === 1 ? (string) array_key_first($parameterDiff) : null;
            $inheritanceDirective = [
                ...$inheritanceDirective,
                'experiment_role' => in_array($causalRole, ['memory_guided', 'hypothesis_guided', 'repair_guided', 'blinded'], true) ? 'falsification' : 'explore',
                'required_component' => 'learning_policy',
                'required_gene' => $causalRole === 'frozen_control' ? null : $actualCausalGene,
                'mutation_from' => $causalRole === 'frozen_control' ? null : data_get($parameterDiff, $actualCausalGene.'.old'),
                'mutation_to' => $causalRole === 'frozen_control' ? null : data_get($parameterDiff, $actualCausalGene.'.new'),
                'mutation_reason' => $causalRole === 'repair_guided'
                    ? 'Pre-registered bounded repair for falsified causal experiment '.data_get($causalInheritance, 'source_causal_experiment_id')
                    : 'Pre-registered '.$causalRole.' arm for '.($causalRole === 'hypothesis_guided'
                        ? 'legacy hypothesis reproduction '
                        : 'canonical lesson ').data_get($causalInheritance, 'source_lesson_id'),
                'source_lesson_ids' => in_array($causalRole, ['memory_guided', 'hypothesis_guided'], true)
                    ? array_values(array_filter([(int) data_get($causalInheritance, 'source_lesson_id', 0)]))
                    : [],
                'control_pair_required' => $causalRole !== 'frozen_control',
                'full_replay_required' => true,
                'settlement_required' => true,
            ];
        }
        $inheritanceManifest = app(InheritanceEnforcerService::class)->seal(
            $agent,
            $inheritanceDirective,
            $parentA?->id,
            $parameterDiff,
            (array) data_get($model->metadata, 'smart_composition.composition_passport', []),
        );
        if (($inheritanceManifest['status'] ?? null) === 'rejected') {
            throw new \RuntimeException('INHERITANCE_MANIFEST_REJECTED '.json_encode($inheritanceManifest['validation'] ?? [], JSON_UNESCAPED_SLASHES));
        }
        $manifestMetadata = (array) $model->fresh()->metadata;
        $manifestMetadata['inheritance_manifest'] = $inheritanceManifest['manifest'] ?? null;
        $manifestMetadata['inheritance_manifest_validation'] = $inheritanceManifest['validation'] ?? null;
        $model->update(['metadata' => $manifestMetadata]);
        $selectedGene = array_key_first($parameterDiff);
        $decisionEpisode = app(LearningKernelService::class)->openEpisode($agent, [
            'decision_key' => 'generation:'.$generation->id.':agent:'.$agent->id,
            'symbol' => $lab->symbol,
            'timeframe' => $lab->timeframe,
            'strategy_family' => $family,
            'stage' => 'mutation_selection',
            'decision' => $roleControl ? 'CONTROL' : 'MUTATE',
            'context' => [
                'regime' => data_get($niche, 'regime'),
                'volatility' => data_get($niche, 'volatility'),
                'transition_state' => data_get($niche, 'transition_state'),
                'session' => data_get($niche, 'session', data_get($niche, 'owner_context.session')),
                'spread_liquidity_state' => data_get($niche, 'spread_liquidity_state', data_get($niche, 'state_cluster.spread_liquidity_state')),
                'volume_state' => data_get($niche, 'volume_state', data_get($niche, 'state_cluster.volume_state')),
                'direction' => data_get($niche, 'direction'),
                'state_cluster_id' => data_get($niche, 'state_cluster.cluster_id', data_get($niche, 'state_cluster')),
                'generation_target' => $target,
            ],
            'parameter_hash' => $this->parameterFingerprint($family, $parameters),
        ]);
        $intentBinding = app(CausalLearningMutationIntentService::class)->bind(
            $sealedCausalIntent,
            $agent,
            $decisionEpisode instanceof AgentLearningEpisode ? $decisionEpisode->id : null,
        );
        if (($intentBinding['status'] ?? null) === 'invalid') {
            throw new \RuntimeException('CAUSAL_LEARNING_INTENT_BINDING_FAILED '.json_encode($intentBinding, JSON_UNESCAPED_SLASHES));
        }
        if ($repairAnchor !== null && $sealedCausalIntent instanceof AgentLearningMutationIntent) {
            app(FailureRepairAnchorService::class)->recordConsumptionReceipt(
                $repairAnchor,
                $agent,
                $sealedCausalIntent,
                $failureRepairContract ?? [],
            );
        }
        $consumption = app(LearningKernelService::class)->recordConsumption(
            $decisionPacket,
            [
                'parameter_key' => $selectedGene,
                'causally_applied_retrieval_ids' => (array) data_get($causalIntentPlan, 'causally_applied_retrieval_ids', []),
            ],
            $agent,
            $decisionEpisode instanceof AgentLearningEpisode ? $decisionEpisode : null,
        );
        if ($decisionEpisode instanceof AgentLearningEpisode) {
            $freshMetadata = (array) $model->fresh()->metadata;
            $freshMetadata['learning_decision'] = [
                'protocol' => 'pre_mutation_retrieval_consumption_v2',
                'episode_id' => $decisionEpisode->id,
                'packet_id' => data_get($decisionPacket, 'packet_id'),
                'retrieval_count' => (int) data_get($decisionPacket, 'retrieval_count', 0),
                'selected_gene' => $selectedGene,
                'causal_intent_id' => data_get($intentBinding, 'intent_id'),
                'causal_influence' => data_get($causalIntentPlan, 'influence_type'),
                'retrieved_lesson_ids' => data_get($causalIntentPlan, 'retrieved_lesson_ids', []),
                'selected_lesson_ids' => data_get($causalIntentPlan, 'selected_lesson_ids', []),
                'causally_applied_lesson_ids' => data_get($causalIntentPlan, 'causally_applied_lesson_ids', []),
                'rejected_lesson_ids' => data_get($causalIntentPlan, 'rejected_lesson_ids', []),
                'mutation_was_sealed_before_agent_persistence' => true,
                'consumption' => $consumption,
                'outcome_status' => 'pending_screening',
                'promotion_evidence' => false,
            ];
            $model->update(['metadata' => $freshMetadata]);
        }
        app(CausalLearningCohortService::class)->enroll(
            $agent->fresh(['modelVersion', 'generation']),
            $sealedCausalIntent,
            $niche,
        );
        if ($roleControl) {
            // Constructor-time declaration makes the control identity
            // explicit before a worker touches it. MutationResponseMapService
            // replaces the provisional hashes with the sealed replay hashes.
            $controlMetadata = (array) $model->fresh()->metadata;
            $execution = (array) data_get($controlMetadata, 'execution_contract', []);
            $controlMetadata['control_contract'] = [
                'protocol' => 'frozen_control_v2', 'control_only' => true,
                'role' => 'control', 'generation_id' => (int) $generation->id,
                'pair_key' => data_get($controlMetadata, 'control_pair_contract.pair_key'),
                'causal_baseline_model_version_id' => data_get($controlMetadata, 'causal_baseline_model_version_id'),
                'parameter_hash' => $this->parameterFingerprint($family, (array) $model->parameters),
                'data_hash' => (string) ($generation->data_fingerprint ?: data_get($generation->trigger_context, 'dataset_manifest.snapshot_sha256', '')),
                'execution_hash' => hash('sha256', json_encode($execution, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
                'status' => 'control_sealed_pending_replay', 'promotion_evidence' => false,
            ];
            $model->update(['metadata' => $controlMetadata]);
        }
        if ($controlRootSeedDeclaration !== null) {
            $this->controlRootInheritance->finalizeSeed($model, $agent);
        }
        if ($controlRootSeedAgent !== null) {
            $this->controlRootInheritance->finalizeSpecialist(
                $agent,
                $controlRootSeedAgent,
                $family,
                $niche,
                $semanticGroup,
                $base,
                $parameters,
                $parameterDiff,
                $knowledgeContract,
                $progressiveInheritance,
                $history,
                $target,
            );
        }
        $this->persistParentContributionGraph(
            $agent,
            $parentA,
            $canonicalParentB,
            $skillCrossoverSources,
            $mentorOnlyLane
                ? 'mentor_broker_source'
                : ($controlRootSeedAgent !== null ? 'control_root_seed' : null),
            (array) ($adaptiveParentSelection['selected_parent_ids'] ?? []),
            (array) ($adaptiveParentSelection['contract'] ?? []),
            $capabilityGeneProvenance,
            $parentMentorContract,
        );
        try {
            $counterfactualContract = $this->parentAwareCredit->registerCandidate($agent->fresh(['modelVersion']));
            $metadata = (array) $model->fresh()->metadata;
            $metadata['parent_counterfactual'] = $counterfactualContract;
            $metadata['parent_counterfactual']['promotion_evidence'] = false;
            $model->update(['metadata' => $metadata]);
        } catch (\Throwable $exception) {
            // Parent-aware evidence is downstream of the immutable candidate
            // identity. A broker projection fault must not make construction
            // silently inherit a different strategy or open promotion.
            report($exception);
        }
        $this->evolutionArchive->recordParentSelectionDecision(
            $generation,
            $agent,
            $lab->symbol,
            $lab->timeframe,
            $family,
            $origin,
            $target,
            $adaptiveParentSelection,
        );
        // A generation is not itself learning progress.  Each child carries
        // this immutable experiment intent so later screening/full-replay
        // evidence can settle a named causal claim rather than only a cohort.
        app(LearningReceiptService::class)->issue($agent->fresh(['modelVersion', 'generation']), $decisionPacket);
        if ($nativeFollowupSource !== null) $this->nativeCouncilFollowupResolution($nativeIntent, true, $generation->id);
        $this->publishConstructorStage($generation, $slot, 'seat_complete');

        return true;
    }

    /** The resolution is revalidated before this copy; the old source is never saved. */
    private function nativeFollowupExecutionContract(?array $source, string $symbol, string $timeframe): array
    {
        $snapshot = data_get($source, 'original_descriptor_materialization');
        if (! is_array($snapshot) || ($snapshot['protocol'] ?? null) !== SpecialistCouncilResearchFeedbackService::OBSERVED_SOURCE_SNAPSHOT_PROTOCOL) {
            return $this->executionContracts->for($symbol, $timeframe);
        }
        $value = $snapshot['original_value'] ?? null;
        if (($snapshot['descriptor_field'] ?? null) !== 'execution_contract' || ! is_array($value) || $value === []
            || ($snapshot['model_version_id'] ?? null) !== ($source['model_version_id'] ?? null)
            || ($snapshot['current_model_hash'] ?? null) !== ($source['model_hash'] ?? null)
            || app(ResearchPaperEpochContractService::class)->parameterHash($value) !== ($snapshot['original_declared_execution_hash'] ?? null)
            || ($snapshot['scientific_outcomes_observed'] ?? null) !== true || ($snapshot['observed_auxiliary_only'] ?? null) !== true
            || ($snapshot['original_preparation_remains_invalid'] ?? null) !== true || ($snapshot['promotion_evidence'] ?? null) !== false) {
            throw new \LogicException('NATIVE_COUNCIL_OBSERVED_SOURCE_COPY_PROOF_INVALID');
        }
        return $value;
    }

    /**
     * Persist every actual crossover contributor. The two parent columns are
     * retained as the primary compatibility projection, while skill-level
     * links make a robust crossover auditable when different genes came from
     * different parents.
     */
    private function persistParentContributionGraph(
        LabAgent $agent,
        ?ModelVersion $parentA,
        ?int $parentBId,
        array $skillCrossoverSources,
        ?string $parentARelationType = null,
        array $adaptiveParentIds = [],
        array $selectionContract = [],
        array $geneProvenance = [],
        array $parentMentorContract = [],
    ): void {
        $links = [];
        $linkedIds = [];
        if ($parentA?->id) {
            $links[] = [
                'parent_model_version_id' => $parentA->id,
                'relation_type' => $parentARelationType ?: 'parent_a',
                'contribution_key' => 'parent_a',
                'metadata' => [
                    'source' => $parentARelationType ?: 'primary_parent_column',
                    'genetic_parameter_source' => $parentARelationType !== 'mentor_broker_source',
                    'mentor_only' => $parentARelationType === 'mentor_broker_source',
                    'promotion_evidence' => false,
                ],
            ];
            $linkedIds[] = (int) $parentA->id;
        }
        if ($parentBId) {
            $links[] = [
                'parent_model_version_id' => $parentBId,
                'relation_type' => 'parent_b',
                'contribution_key' => 'parent_b',
                'metadata' => ['source' => 'primary_parent_column'],
            ];
            $linkedIds[] = (int) $parentBId;
        }
        foreach ($skillCrossoverSources as $skill => $parentId) {
            if (! is_numeric($parentId) || (int) $parentId <= 0) {
                continue;
            }
            $skillGenes = collect($geneProvenance)
                ->filter(fn (array $provenance): bool => (string) data_get($provenance, 'source_module', '') !== ''
                    && (int) data_get($provenance, 'source_parent_id', 0) === (int) $parentId)
                ->keys()->values()->all();
            $links[] = [
                'parent_model_version_id' => (int) $parentId,
                'relation_type' => 'skill_crossover',
                'contribution_key' => (string) $skill,
                'metadata' => [
                    'source' => 'skill_crossover',
                    'skill' => (string) $skill,
                    'gene_keys' => $skillGenes,
                    'gene_provenance' => collect($geneProvenance)->only($skillGenes)->all(),
                    'promotion_evidence' => false,
                ],
            ];
            $linkedIds[] = (int) $parentId;
        }
        foreach (array_values(array_unique(array_filter($adaptiveParentIds))) as $index => $parentId) {
            if (! is_numeric($parentId) || (int) $parentId <= 0 || in_array((int) $parentId, $linkedIds, true)) {
                continue;
            }
            $links[] = [
                'parent_model_version_id' => (int) $parentId,
                'relation_type' => 'adaptive_contributor',
                'contribution_key' => 'adaptive_parent_'.($index + 1),
                'metadata' => [
                    'source' => 'adaptive_parent_frontier',
                    'selection_protocol' => data_get($selectionContract, 'protocol'),
                    'selection_mode' => data_get($selectionContract, 'mode'),
                    'island_key' => data_get($selectionContract, 'island_key'),
                    'promotion_evidence' => false,
                ],
            ];
            $linkedIds[] = (int) $parentId;
        }
        $mentorParentId = (int) data_get($parentMentorContract, 'parent_suggestion.parent_model_version_id', 0);
        $mentorSkill = (string) data_get($parentMentorContract, 'parent_suggestion.skill_key', '');
        if ($mentorParentId > 0 && $mentorSkill !== '') {
            $links[] = [
                'parent_model_version_id' => $mentorParentId,
                'relation_type' => 'mentor_suggestion',
                'contribution_key' => 'mentor:'.$mentorSkill,
                'metadata' => [
                    'source' => self::class,
                    'skill_key' => $mentorSkill,
                    'context_key' => data_get($parentMentorContract, 'context.context_key'),
                    'trust_score' => data_get($parentMentorContract, 'parent_suggestion.context_trust.trust_score'),
                    'direct_parameter_copy' => false,
                    'promotion_evidence' => false,
                ],
            ];
        }
        if ($links !== []) {
            $agent->parentLinks()->createMany($links);
        }
    }
}
