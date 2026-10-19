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
trait LabPopulationCouncilPlanningTrait
{
    /** Validate an explicit prospective council intent before taking any constructor lease. */
    private function sealNativeCouncilIntent(array $intent, string $symbol, string $timeframe, string $trigger,
        bool $force, array $coverageRescue, bool $roleComplete, ?int $populationLimit, ?array $targetedFailureProfile,
        bool $allowControlledRescue, ?array $prospectiveExpectation): array
    {
        $keys = ['protocol', 'purpose', 'symbol', 'storage_timeframe', 'population_size', 'research_question', 'creator_id'];
        $followupKeys = ['followup_work_item_id', 'followup_resolution_hash'];
        if (array_diff(array_keys($intent), [...$keys, ...$followupKeys, 'research_purpose', 'study_context_declaration', 'depth_audit_declaration']) !== [] || array_diff($keys, array_keys($intent)) !== []
            || ($intent['protocol'] ?? null) !== self::NATIVE_COUNCIL_INTENT_PROTOCOL
            || ($intent['purpose'] ?? null) !== 'research'
            || ($intent['symbol'] ?? null) !== 'XAUUSD' || $symbol !== 'XAUUSD'
            || ($intent['storage_timeframe'] ?? null) !== 'H1' || $timeframe !== 'H1'
            || ($intent['population_size'] ?? null) !== 6
            || $trigger !== GenerationAdmissionDecisionService::HISTORICAL_TRIGGER
            || $force || $coverageRescue !== [] || $roleComplete || $targetedFailureProfile !== null
            || $allowControlledRescue || $prospectiveExpectation !== null
            || ($populationLimit !== null && $populationLimit !== 6)) {
            throw new \InvalidArgumentException('NATIVE_COUNCIL_REQUIRES_EXACT_UNFORCED_SIX_SEAT_RESEARCH_INTENT');
        }
        if ((array_key_exists('research_purpose', $intent) && ! in_array($intent['research_purpose'], ['council_comparison', 'spread_context_study', 'native_reachability_depth_audit'], true))
            || (in_array($intent['research_purpose'] ?? '', ['spread_context_study', 'native_reachability_depth_audit'], true)
                && array_intersect($followupKeys, array_keys($intent)) !== [])) {
            throw new \InvalidArgumentException('NATIVE_COUNCIL_ORIGINAL_RESEARCH_PURPOSE_INVALID');
        }
        if (($intent['research_purpose'] ?? '') === 'spread_context_study') {
            self::assertNativeStudyContextDeclaration((array) ($intent['study_context_declaration'] ?? []));
        } elseif (array_key_exists('study_context_declaration', $intent)) {
            throw new \InvalidArgumentException('NATIVE_COUNCIL_STUDY_DECLARATION_REQUIRES_ORIGINAL_STUDY_PURPOSE');
        }
        if (($intent['research_purpose'] ?? '') === 'native_reachability_depth_audit') {
            self::assertNativeDepthAuditDeclaration((array) ($intent['depth_audit_declaration'] ?? []));
        } elseif (array_key_exists('depth_audit_declaration', $intent)) {
            throw new \InvalidArgumentException('NATIVE_COUNCIL_DEPTH_DECLARATION_REQUIRES_ORIGINAL_DEPTH_PURPOSE');
        }
        foreach (['research_question' => 500, 'creator_id' => 120] as $key => $limit) {
            if (! is_string($intent[$key]) || trim($intent[$key]) === ''
                || strlen($intent[$key]) > $limit || trim($intent[$key]) !== $intent[$key]) {
                throw new \InvalidArgumentException('NATIVE_COUNCIL_INTENT_IDENTITY_INVALID:'.$key);
            }
        }
        if (array_intersect($followupKeys, array_keys($intent)) !== []) {
            $this->nativeCouncilFollowupResolution($intent, true);
        }
        $sealed = [
            ...$intent, 'authority' => 'research_only', 'requires_atomic_preparation' => true,
            'independent_evidence_claimed' => false, 'promotion_evidence' => false,
        ];

        return [...$sealed, 'intent_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($sealed)];
    }

    /** The original panel owner admits a pre-paper full-window cohort. */
    private function authorizedCouncilPanelProof(array $intent): array
    {
        $unsigned = array_diff_key($intent, ['intent_hash' => true]);
        $proof = app(SpecialistCouncilIndependentPanelService::class)->assertConstructorIntent($unsigned);
        if (! is_array($proof) || ($proof['protocol'] ?? null) !== self::AUTHORIZED_COUNCIL_PANEL_INTENT_PROTOCOL
            || ($proof['symbol'] ?? null) !== 'XAUUSD' || ($proof['storage_timeframe'] ?? null) !== 'H1'
            || ! is_int($proof['work_item_id'] ?? null) || $proof['work_item_id'] <= 0
            || ! is_string($proof['work_key'] ?? null) || $proof['work_key'] === ''
            || ! is_string($proof['window_key'] ?? null) || $proof['window_key'] === ''
            || ! is_int($proof['window_ordinal'] ?? null) || $proof['window_ordinal'] < 1 || $proof['window_ordinal'] > 3
            || ! is_array($proof['arm_roots'] ?? null) || count($proof['arm_roots']) < 5 || count($proof['arm_roots']) > 12
            || ($proof['authority'] ?? null) !== 'research_only' || ($proof['promotion_evidence'] ?? null) !== false
            || ($proof['independent_evidence_claimed'] ?? null) !== false) {
            throw new \LogicException('AUTHORIZED_COUNCIL_PANEL_ORIGINAL_CONSTRUCTOR_PROOF_INVALID');
        }
        foreach (['reservation_hash', 'current_source_hash', 'current_python_source_hash'] as $field) {
            if (! preg_match('/^[a-f0-9]{64}$/D', (string) ($proof[$field] ?? ''))) {
                throw new \LogicException('AUTHORIZED_COUNCIL_PANEL_SOURCE_SEAL_REQUIRED');
            }
        }
        if ($proof['current_source_hash'] !== app(LabImmutableEvidenceService::class)->codeHash()
            || $proof['current_python_source_hash'] !== app(ResearchReleaseSealService::class)->pythonHash()) {
            throw new \LogicException('AUTHORIZED_COUNCIL_PANEL_SOURCE_CHANGED');
        }
        $window = $proof['authorized_window'] ?? [];
        if (($window['window_key'] ?? null) !== $proof['window_key']
            || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($window['dataset_sha256'] ?? ''))
            || ! is_string($window['start_inclusive'] ?? null) || ! is_string($window['end_exclusive'] ?? null)
            || ! app(ResearchPaperEpochContractService::class)->researchIntervalDisjointFromPaper(
                $window['start_inclusive'], $window['end_exclusive'])) {
            throw new \LogicException('AUTHORIZED_COUNCIL_PANEL_PRE_PAPER_WINDOW_REQUIRED');
        }
        $keys = [];
        if (isset($proof['member_roots']) && (! is_array($proof['member_roots']) || ! array_is_list($proof['member_roots'])
            || count($proof['member_roots']) !== 4 || count($proof['arm_roots']) !== 9 || $proof['window_ordinal'] !== 1
            || count(array_unique(array_column($proof['member_roots'], 'derived_member_role'))) !== 4
            || count(self::authorizedPanelConstructionRoots($proof)) !== 13)) {
            throw new \LogicException('AUTHORIZED_COUNCIL_PANEL_DERIVED_MEMBER_ROOTS_INVALID');
        }
        foreach ($proof['arm_roots'] as $arm) if (($arm['kind'] ?? null) === 'member_source') {
            throw new \LogicException('AUTHORIZED_COUNCIL_PANEL_SOURCE_REFERENCE_CANNOT_BE_REPLAY_ARM');
        }
        foreach (self::authorizedPanelConstructionRoots($proof) as $arm) {
            if (! is_array($arm) || ! is_string($arm['arm_key'] ?? null) || $arm['arm_key'] === ''
                || isset($keys[$arm['arm_key']]) || ! in_array($arm['kind'] ?? null, ['candidate', 'champion', 'solo', 'ablation', 'retention', 'member_source'], true)) {
                throw new \LogicException('AUTHORIZED_COUNCIL_PANEL_EXACT_ARM_ROOTS_REQUIRED');
            }
            $keys[$arm['arm_key']] = true;
            $this->authorizedCouncilPanelSource($arm);
        }
        $proof['intent_hash'] = app(ResearchPaperEpochContractService::class)->parameterHash($proof);
        if (isset($intent['intent_hash']) && $intent['intent_hash'] !== $proof['intent_hash']) {
            throw new \LogicException('AUTHORIZED_COUNCIL_PANEL_CONSTRUCTOR_INTENT_DRIFT');
        }
        return $proof;
    }

    private function authorizedCouncilPanelSource(array $arm): ModelVersion
    {
        $source = ModelVersion::find($arm['source_model_version_id'] ?? 0);
        if (! $source || ($arm['source_model_hash'] ?? null) !== app(SpecialistCouncilContractService::class)->modelHash($source)
            || ($arm['strategy'] ?? null) !== $source->strategy || ($arm['family'] ?? null) !== $this->schemas->family($source->strategy)
            || ! app(LabImmutableEvidenceService::class)->equivalentJsonValue($arm['parameters'] ?? null, $this->authorizedPanelProjectedParameters($arm, $source))) {
            throw new \LogicException('AUTHORIZED_COUNCIL_PANEL_ORIGINAL_ARM_MODEL_DRIFT');
        }
        $validated = $this->schemas->validate($source->strategy, $arm['parameters']);
        if (! app(LabImmutableEvidenceService::class)->equivalentJsonValue($validated, $arm['parameters'])) {
            throw new \LogicException('AUTHORIZED_COUNCIL_PANEL_CLONE_REQUIRES_PARAMETER_RENORMALIZATION');
        }
        return $source;
    }

    public static function authorizedPanelConstructionRoots(array $intent): array
    {
        return array_values([...(array) ($intent['arm_roots'] ?? []), ...(array) ($intent['member_roots'] ?? [])]);
    }

    private function authorizedPanelProjectedParameters(array $arm, ModelVersion $source): array
    {
        $parameters = (array) $source->parameters;
        if (($arm['kind'] ?? '') !== 'member_source') return $parameters;
        if (! in_array($arm['derived_member_role'] ?? null, ['scalp', 'hour', 'day', 'swing'], true)
            || ! is_array($arm['parameter_deltas'] ?? null) || count($arm['parameter_deltas']) > 4) {
            throw new \LogicException('AUTHORIZED_COUNCIL_PANEL_DERIVED_MEMBER_VECTOR_INVALID');
        }
        $seen = [];
        foreach ($arm['parameter_deltas'] as $delta) {
            $gene = $delta['gene'] ?? null;
            if (! is_string($gene) || isset($seen[$gene]) || ! array_key_exists($gene, $parameters)
                || ! app(LabImmutableEvidenceService::class)->equivalentJsonValue($delta['old'] ?? null, $parameters[$gene])
                || ! array_key_exists('new', $delta)) throw new \LogicException('AUTHORIZED_COUNCIL_PANEL_DERIVED_MEMBER_VECTOR_INVALID');
            $seen[$gene] = true; $parameters[$gene] = $delta['new'];
        }
        return $parameters;
    }

    private function authorizedCouncilPanelMarker(array $intent): array
    {
        return [...\Illuminate\Support\Arr::only($intent, ['protocol', 'work_item_id', 'work_key', 'reservation_hash',
            'window_key', 'window_ordinal', 'source_version_id', 'evaluator_id', 'intent_hash']),
            'panel_version_id' => null, 'plan_hash' => null, 'arm_units' => [],
            'authority' => 'research_only', 'promotion_evidence' => false];
    }

    /** Only the original reservation's still-unused sibling drafts may coexist. */
    private function sameReservedCouncilPanel(?LabGeneration $generation, ?array $intent): bool
    {
        return self::reservedAuthorizedPanelOwnerMatches($generation, $intent);
    }

    /** Pure original-constructor proof shared with admission; a label-only draft never owns a panel. */
    public static function reservedAuthorizedPanelOwnerMatches(?LabGeneration $generation, ?array $intent): bool
    {
        if ($generation === null || $intent === null || $generation->status !== 'research_reserved') return false;
        $owner = data_get($generation->trigger_context, 'specialist_council_authorized_panel');
        $stored = data_get($generation->trigger_context, 'authorized_specialist_council_panel_intent');
        if (! is_array($owner) || ! is_array($stored) || ! is_array($stored['arm_roots'] ?? null)
            || count($stored['arm_roots']) < 5 || count($stored['arm_roots']) > 12
            || ($stored['intent_hash'] ?? null) !== app(ResearchPaperEpochContractService::class)->parameterHash(array_diff_key($stored, ['intent_hash' => true]))
            || ($stored['current_source_hash'] ?? null) !== ($intent['current_source_hash'] ?? null)
            || ($stored['current_python_source_hash'] ?? null) !== ($intent['current_python_source_hash'] ?? null)
            || ($owner['intent_hash'] ?? null) !== $stored['intent_hash']
            || (int) $generation->population_size !== count(self::authorizedPanelConstructionRoots($stored))
            || ($owner['work_item_id'] ?? null) !== $intent['work_item_id']
            || ($stored['work_item_id'] ?? null) !== $intent['work_item_id']
            || ($stored['work_key'] ?? null) !== $intent['work_key']
            || ($stored['reservation_hash'] ?? null) !== $intent['reservation_hash']
            || ! (bool) data_get(app(ImmutableGenerationContractService::class)->validate($generation), 'valid', false)
            || data_get($generation->trigger_context, 'immutable_generation_contract.protocol') !== ImmutableGenerationContractService::PROTOCOL) return false;
        $valid = ($owner['work_item_id'] ?? null) === $intent['work_item_id']
            && ($owner['work_key'] ?? null) === $intent['work_key'] && ($owner['reservation_hash'] ?? null) === $intent['reservation_hash']
            && $generation->trigger_type === self::AUTHORIZED_COUNCIL_PANEL_TRIGGER
            && ! $generation->agents()->where('lifecycle_status', '!=', 'draft')->exists()
            && ! \App\Models\LabEvaluationRun::where('lab_generation_id', $generation->id)->exists();
        if (! $valid) return false;
        $agents = $generation->agents()->with('modelVersion')->get();
        if ($agents->count() !== count(self::authorizedPanelConstructionRoots($stored))) return false;
        $slots = []; $evidence = app(LabImmutableEvidenceService::class);
        foreach ($agents as $agent) {
            $seed = data_get($agent->modelVersion?->metadata, 'authorized_specialist_council_panel_seed');
            $slot = is_array($seed) ? ($seed['construction_slot'] ?? null) : null;
            $arm = is_int($slot) ? (self::authorizedPanelConstructionRoots($stored)[$slot - 1] ?? null) : null;
            if (! is_array($arm) || isset($slots[$slot]) || $agent->origin !== 'authorized_council_panel_root'
                || ($seed['lab_generation_id'] ?? null) !== $generation->id || ($seed['intent_hash'] ?? null) !== $stored['intent_hash']
                || ($seed['arm_key'] ?? null) !== $arm['arm_key'] || ($seed['source_model_hash'] ?? null) !== $arm['source_model_hash']) return false;
            $source = ModelVersion::find($arm['source_model_version_id'] ?? 0);
            if (! $source || ($arm['source_model_hash'] ?? null) !== app(SpecialistCouncilContractService::class)->modelHash($source)
                || ! $evidence->equivalentJsonValue($arm['parameters'], $agent->modelVersion->parameters)
                || ! $evidence->equivalentJsonValue($evidence->modelRuntimeBasis($source), $evidence->modelRuntimeBasis($agent->modelVersion))
                || ! $evidence->equivalentJsonValue(data_get($source->metadata, 'instrument_research_assignment'), data_get($agent->modelVersion->metadata, 'instrument_research_assignment'))) return false;
            $slots[$slot] = true;
        }
        return true;
    }

    private function authorizedCouncilPanelSeedPlan(array $intent): array
    {
        return array_map(fn (array $arm, int $index): array => [
            'family' => $arm['family'], 'origin' => 'authorized_council_panel_root', 'target' => 'portfolio_router',
            'niche' => ['protocol' => self::AUTHORIZED_COUNCIL_PANEL_INTENT_PROTOCOL, 'control_only' => true,
                'role' => 'authorized_panel_'.$arm['kind'], 'promotion_evidence' => false,
                'authorized_specialist_council_panel_seed' => [...$this->authorizedCouncilPanelMarker($intent),
                    'construction_slot' => $index + 1, 'arm_key' => $arm['arm_key'], 'kind' => $arm['kind'],
                    'source_model_version_id' => $arm['source_model_version_id'], 'source_model_hash' => $arm['source_model_hash']]],
        ], self::authorizedPanelConstructionRoots($intent), array_keys(self::authorizedPanelConstructionRoots($intent)));
    }

    /** Six unused references for one future atomic council question, not six qualified specialists. */
    public static function nativeCouncilSlotRoles(array $intent): array
    {
        return ['source_scalp', 'source_hour', 'source_day', 'source_swing',
            ...match ($intent['research_purpose'] ?? 'council_comparison') {
                'spread_context_study' => ['study_masked_carrier', 'study_unmasked_carrier'],
                'native_reachability_depth_audit' => ['audit_cheap_carrier', 'audit_deeper_carrier'],
                default => ['candidate_carrier', 'ablation_carrier'],
            }];
    }

    public static function assertNativeDepthAuditDeclaration(array $declaration): void
    {
        $keys = ['protocol', 'criterion', 'minimum_observed_opportunities', 'contexts', 'cheap_evaluated_rows',
            'deeper_evaluated_rows', 'sample_cap', 'seed', 'initial_capital'];
        if (count($declaration) !== count($keys) || array_diff(array_keys($declaration), $keys) !== []
            || ($declaration['protocol'] ?? null) !== 'native_reachability_depth_declaration_v1'
            || ($declaration['criterion'] ?? null) !== 'instrument_gate_reached'
            || ! is_int($declaration['minimum_observed_opportunities'] ?? null)
            || $declaration['minimum_observed_opportunities'] < 1 || $declaration['minimum_observed_opportunities'] > 15000
            || ! is_int($declaration['cheap_evaluated_rows'] ?? null) || $declaration['cheap_evaluated_rows'] < 2 || $declaration['cheap_evaluated_rows'] > 1024
            || ! is_int($declaration['deeper_evaluated_rows'] ?? null) || $declaration['deeper_evaluated_rows'] <= $declaration['cheap_evaluated_rows'] || $declaration['deeper_evaluated_rows'] > 15000
            || ! is_int($declaration['sample_cap'] ?? null) || $declaration['sample_cap'] < 1 || $declaration['sample_cap'] > 2
            || ! is_string($declaration['seed'] ?? null) || strlen($declaration['seed']) < 1 || strlen($declaration['seed']) > 128
            || trim($declaration['seed']) !== $declaration['seed']
            || (! is_int($declaration['initial_capital'] ?? null) && ! is_float($declaration['initial_capital'] ?? null))
            || ! is_finite((float) $declaration['initial_capital'])
            || $declaration['initial_capital'] <= 0 || $declaration['initial_capital'] > 1e12
            || ! is_array($declaration['contexts'] ?? null) || count($declaration['contexts']) !== 4
            || array_diff(array_keys($declaration['contexts']), SpecialistCouncilContractService::TRADING_ROLES) !== []) {
            throw new \InvalidArgumentException('NATIVE_COUNCIL_ORIGINAL_DEPTH_AUDIT_DECLARATION_REQUIRED');
        }
        foreach ($declaration['contexts'] as $context) {
            if (! is_array($context) || count($context) !== 5
                || array_diff(array_keys($context), ['regime', 'volatility', 'session', 'venue_phase', 'direction']) !== []) {
                throw new \InvalidArgumentException('NATIVE_COUNCIL_DEPTH_CONTEXT_INVALID');
            }
            $canonical = app(ContextContractV2Service::class)->canonicalDeclaredAxes($context);
            if (isset($canonical['direction'])) $canonical['direction'] = strtoupper($canonical['direction']);
            if (count($canonical) !== 5 || ! in_array($context['direction'] ?? null, ['BUY', 'SELL'], true)
                || app(ResearchPaperEpochContractService::class)->parameterHash($canonical) !== app(ResearchPaperEpochContractService::class)->parameterHash($context)) {
                throw new \InvalidArgumentException('NATIVE_COUNCIL_DEPTH_CONTEXT_INVALID');
            }
        }
    }

    public static function assertNativeStudyContextDeclaration(array $declaration): void
    {
        $context = (array) ($declaration['exact_context'] ?? []);
        $axes = ['regime', 'volatility', 'session', 'venue_phase', 'direction'];
        $canonical = app(ContextContractV2Service::class)->canonicalDeclaredAxes($context);
        if (isset($canonical['direction'])) $canonical['direction'] = strtoupper($canonical['direction']);
        if (array_diff(array_keys($declaration), ['specialist_id', 'exact_context', 'spread_context_predicate', 'liquidity_atr_binding']) !== []
            || ! in_array($declaration['specialist_id'] ?? null, SpecialistCouncilContractService::TRADING_ROLES, true)
            || ! in_array($declaration['spread_context_predicate'] ?? null, ['normal', 'high'], true)
            || ! in_array($declaration['liquidity_atr_binding'] ?? null, ['closed_strategy_atr_v1', 'closed_structure_atr_v1', 'closed_m5_management_atr_v1'], true)
            || count($context) !== 5 || array_diff(array_keys($context), $axes) !== [] || count($canonical) !== 5
            || ! in_array($context['direction'] ?? null, ['BUY', 'SELL'], true)
            || app(ResearchPaperEpochContractService::class)->parameterHash($canonical) !== app(ResearchPaperEpochContractService::class)->parameterHash($context)) {
            throw new \InvalidArgumentException('NATIVE_COUNCIL_ORIGINAL_SUPPORTED_STUDY_CONTEXT_REQUIRED');
        }
    }

    private function nativeCouncilSeedPlan(AiLaboratory $lab, array $intent): array
    {
        $definitions = array_values($this->councilRoleDefinitions($lab));
        if (count($definitions) !== 4) {
            throw new \RuntimeException('NATIVE_COUNCIL_SOURCE_ROLE_DEFINITIONS_CHANGED');
        }
        $definitions = [...$definitions, $definitions[2], $definitions[2]];
        $roles = self::nativeCouncilSlotRoles($intent);
        $resolution = isset($intent['followup_work_item_id']) ? $this->nativeCouncilFollowupResolution($intent) : null;
        if ($resolution !== null) {
            foreach (['scalp', 'hour', 'day', 'swing', 'day', 'day'] as $index => $role) {
                $definitions[$index]['family'] = $resolution['native_source_models'][$role]['family'];
                $definitions[$index]['followup_source'] = $resolution['native_source_models'][$role];
            }
        }

        return array_map(fn (array $definition, int $index): array => [
            'family' => $definition['family'], 'origin' => 'native_council_root', 'target' => 'portfolio_router',
            'niche' => [
                'protocol' => self::NATIVE_COUNCIL_INTENT_PROTOCOL,
                'role' => 'native_council_'.$roles[$index],
                // These are pristine schema references. Their future council/solo/ablation contrast
                // is assembled atomically by the preparation owner, not the old pair planner.
                'control_only' => true, 'parent_lane' => 'autonomous',
                'native_council_followup_source' => $definition['followup_source'] ?? null,
                'native_specialist_council_seed' => [
                    'protocol' => self::NATIVE_COUNCIL_INTENT_PROTOCOL,
                    'intent_hash' => $intent['intent_hash'], 'slot_role' => $roles[$index],
                    ...(in_array($intent['research_purpose'] ?? '', ['spread_context_study', 'native_reachability_depth_audit'], true)
                        ? ['research_purpose' => $intent['research_purpose'], 'construction_slot' => $index + 1] : []),
                    'prospective_horizon' => $index < 4 ? substr($roles[$index], 7) : null,
                    'qualified_specialist' => false, 'authority' => 'research_only',
                    'followup_work_item_id' => $intent['followup_work_item_id'] ?? null,
                    'followup_resolution_hash' => $intent['followup_resolution_hash'] ?? null,
                ],
                'promotion_evidence' => false,
            ],
        ], $definitions, array_keys($roles));
    }

    /** The continuation is a verified prospective research vector, never genetic parent authority. */
    private function nativeCouncilFollowupResolution(array $intent, bool $requiresLease = false, ?int $generationId = null): array
    {
        $work = \App\Models\ResearchExperimentWorkItem::find($intent['followup_work_item_id'] ?? 0);
        if (! $work || ! is_string($intent['followup_resolution_hash'] ?? null)
            || ($requiresLease && ($work->status !== 'leased' || ! $work->lease_token
                || ! $work->lease_expires_at || ! $work->lease_expires_at->isFuture()))) {
            throw new \LogicException('NATIVE_COUNCIL_FOLLOWUP_CURRENT_OWNER_REQUIRED');
        }
        if ($this->nativeFollowupConstructorInvocation && $this->nativeFollowupInvocationProof !== null) {
            $this->assertNativeFollowupInvocationCurrent($intent, $this->nativeFollowupInvocationProof, $generationId);
            return $this->nativeFollowupInvocationProof['proof'];
        }
        $feedback = $this->nativeFollowupFeedbackOwner ?? app(SpecialistCouncilResearchFeedbackService::class);
        $proof = $feedback->inspectFollowupReadiness($work);
        if (($proof['executable'] ?? false) !== true
            || ! hash_equals($intent['followup_resolution_hash'], (string) ($proof['resolution_hash'] ?? ''))
            || ($intent['creator_id'] ?? null) !== ($proof['creator_id'] ?? null)
            || ($intent['research_question'] ?? null) !== ($proof['research_question'] ?? null)) {
            throw new \LogicException('NATIVE_COUNCIL_FOLLOWUP_SOURCE_PROOF_INVALID');
        }
        // Expensive proof may outlast the original lease. Never persist even
        // the first slot merely because its entry check preceded expiry.
        if ($requiresLease || $this->nativeFollowupConstructorInvocation) {
            $binding = $feedback->inspectFollowupSourceBinding($work->fresh());
            $memo = ['proof' => $proof, 'work_id' => $work->id, 'lease_token' => $work->lease_token,
                'fence_version' => (int) $work->fence_version, 'source_binding' => $binding];
            if ($this->nativeFollowupConstructorInvocation && ! ($proof['owned_generation_id'] ?? null)) {
                $memo['pristine_unbuilt_start'] = $feedback->pristineUnbuiltFollowupSnapshot($work->fresh());
            }
            $this->assertNativeFollowupInvocationCurrent($intent, $memo, $generationId);
            if ($this->nativeFollowupConstructorInvocation) $this->nativeFollowupInvocationProof = $memo;
        }
        return $proof;
    }

    /** Cheap per-slot and persistence fence; no lease extension or cross-invocation cache. */
    private function assertNativeFollowupInvocationCurrent(array $intent, array $memo, ?int $generationId = null): void
    {
        $query = \App\Models\ResearchExperimentWorkItem::whereKey($memo['work_id']);
        if (DB::transactionLevel() > 0) $query->lockForUpdate();
        $current = $query->first();
        if (! $current || $current->status !== 'leased' || ! $current->lease_expires_at?->isFuture()
            || ! is_string($current->lease_token) || $current->lease_token === ''
            || $current->lease_token !== $memo['lease_token'] || (int) $current->fence_version !== $memo['fence_version']
            || $current->id !== ($intent['followup_work_item_id'] ?? null)
            || data_get($current->payload, 'followup_resolution.resolution_hash') !== ($intent['followup_resolution_hash'] ?? null)) {
            throw new \LogicException('NATIVE_COUNCIL_FOLLOWUP_CURRENT_OWNER_REQUIRED');
        }
        $feedback = $this->nativeFollowupFeedbackOwner ?? app(SpecialistCouncilResearchFeedbackService::class);
        $binding = $feedback->inspectFollowupSourceBinding($current);
        if ($binding !== $memo['source_binding']) throw new \LogicException('NATIVE_COUNCIL_FOLLOWUP_SOURCE_CHANGED_DURING_CONSTRUCTION');
        if ($ownedId = $generationId ?? (int) ($memo['proof']['owned_generation_id'] ?? 0)) {
            $feedback->assertUnobservedConstructorBinding($current, $ownedId,
                $memo['pristine_unbuilt_start'] ?? null);
        }
        foreach ((array) ($memo['proof']['native_source_models'] ?? []) as $spec) {
            $source = ModelVersion::find($spec['model_version_id']);
            if (! $source || app(SpecialistCouncilContractService::class)->modelHash($source) !== ($spec['model_hash'] ?? null)) {
                throw new \LogicException('NATIVE_COUNCIL_FOLLOWUP_SOURCE_VECTOR_CHANGED_DURING_CONSTRUCTION');
            }
        }
    }

    /** Existing lineage recovery remains a separate bounded semantic-root plan. */
    private function boundedRootRecoveryPlan(AiLaboratory $lab, int $limit): array
    {
        // A root is not a generic hybrid control. It is the first member of
        // the exact semantic cell that the next specialist owns. Reusing the
        // same definitions as mandatoryCouncilRolePlan() makes the root a
        // legal parent in the following generation.
        $niches = array_values($this->councilRoleDefinitions($lab));

        return collect(array_slice($niches, 0, $limit))->map(function (array $niche): array {
            return [
                'family' => $niche['family'],
                'origin' => 'lineage_root_rebuild',
                'target' => $niche['target'],
                'niche' => [
                    'protocol' => 'bounded_root_recovery_v1',
                    'role' => $niche['role'],
                    'specialist_role' => $niche['role'],
                    'regime' => $niche['regime'],
                    'volatility' => $niche['volatility'],
                    'direction' => null,
                    'research_reason' => 'legacy_lineage_quarantine_clean_restart',
                    'promotion_rule' => 'all_forward_and_elite_gates_unchanged',
                ],
            ];
        })->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function mandatoryCouncilRolePlan(AiLaboratory $lab): array
    {
        $curriculum = collect((array) data_get($this->roleCouncilCurriculumSnapshot($lab), 'niches', []));
        $defaults = $this->councilRoleDefinitions($lab);

        return collect($defaults)->map(function (array $default, string $role) use ($curriculum): array {
            $source = $curriculum->first(fn (array $niche): bool => (string) data_get($niche, 'role', data_get($niche, 'specialist_role', '')) === $role
            ) ?? [];
            // The semantic cell is a stable curriculum identity, not a
            // mutable copy of the latest failure sample. Fresh evidence is
            // retained below as diagnostic context, while roots and
            // specialists always share this canonical envelope.
            $regime = (string) $default['regime'];
            $volatility = (string) $default['volatility'];
            $target = $role === 'trend_down_specialist' && data_get($source, 'recall_variant') !== null
                ? 'opportunity_recall' : $default['target'];
            $cluster = (array) data_get($source, 'state_cluster', []);
            if ($cluster !== []
                && ((string) data_get($cluster, 'regime') !== $regime
                    || (string) data_get($cluster, 'volatility') !== $volatility)) {
                $cluster = [];
            }
            if ($cluster === []) {
                $labels = [
                    'regime' => $regime, 'volatility' => $volatility,
                    'transition_state' => $role === 'transition_risk_router' ? 'transition_observed' : 'transition_wait',
                    'spread_liquidity_state' => 'low_spread',
                    'veto_reason' => $role === 'range_specialist' ? 'spread_to_atr' : 'regime_transition_wait',
                ];
                $cluster = [
                    'protocol' => 'state_cluster_v1',
                    'cluster_id' => hash('sha256', json_encode($labels, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)),
                    ...$labels, 'month_labels_are_diagnostic_only' => true,
                    'promotion_evidence' => false,
                ];
            }

            return [
                // lab_agents.origin is varchar(24); the role contract itself
                // lives in model metadata, so keep this audit label compact.
                'origin' => 'council_role_complete',
                'family' => $default['family'],
                'target' => $target,
                'niche' => [
                    ...$source,
                    'protocol' => 'portfolio_council_v1',
                    'role' => $role,
                    'specialist_role' => $role,
                    'regime' => $regime,
                    'volatility' => $volatility,
                    'direction' => null,
                    'objective' => $target,
                    'source_performance_id' => data_get($source, 'source_performance_id'),
                    'state_cluster' => $cluster,
                    'role_complete_council' => true,
                    'full_replay_required' => true,
                    'standalone_forward_passport_required' => true,
                    'combined_replay_after_individual_passports' => true,
                    'month_labels_are_diagnostic_only' => true,
                    'role_policy' => $this->councilRolePolicySpec($role),
                    'promotion_evidence' => false,
                ],
            ];
        })->values()->all();
    }

    /**
     * Resolve the same semantic envelope for roots and their next specialist.
     * Fresh curriculum evidence is retained as context, but it cannot mutate
     * the canonical cell identity and strand a root from its next specialist.
     *
     * @return array<string, array<string, mixed>>
     */
    private function councilRoleDefinitions(AiLaboratory $lab): array
    {
        $curriculum = collect((array) data_get($this->roleCouncilCurriculumSnapshot($lab), 'niches', []));

        return collect($this->semanticGroups->canonicalSpecialistGroups())
            ->mapWithKeys(function (array $default, string $role) use ($curriculum): array {
                $source = $curriculum->first(fn (array $niche): bool => (string) data_get($niche, 'role', data_get($niche, 'specialist_role', '')) === $role
                ) ?? [];

                return [$role => [
                    ...$default,
                    'direction' => null,
                    'curriculum_source' => $source,
                ]];
            })->all();
    }

    /** @return array<string, mixed> */
    private function roleCouncilCurriculumSnapshot(AiLaboratory $lab): array
    {
        $niches = [];
        try {
            // Extract only the nested curriculum from the database. Loading
            // the full trigger_context would also hydrate historical reports,
            // cell metrics and replay diagnostics that are irrelevant to this
            // constructor step.
            $rawNiches = $lab->generations()
                ->whereRaw("JSON_LENGTH(JSON_EXTRACT(trigger_context, '$.portfolio_council_curriculum.niches')) > 0")
                ->orderByDesc('generation')
                ->value(DB::raw("JSON_EXTRACT(trigger_context, '$.portfolio_council_curriculum.niches')"));
            $decodedNiches = is_string($rawNiches) ? json_decode($rawNiches, true) : $rawNiches;
            if (is_array($decodedNiches)) {
                $niches = $decodedNiches;
            }
        } catch (\Throwable) {
            // Older SQLite/test schemas may not expose JSON path functions.
            // The mandatory role defaults remain a valid bounded curriculum;
            // never fall back to memory-heavy historical recomputation here.
        }
        if ($niches === [] && app()->environment('testing')) {
            // Small in-memory fixtures may not have a persisted curriculum
            // snapshot yet. Preserve their diagnostic source assertions
            // without reintroducing this fallback in production.
            $niches = (array) data_get($this->portfolioCouncilCurriculum($lab), 'niches', []);
        }

        return [
            'protocol' => 'portfolio_council_curriculum_v1',
            'source' => $niches === [] ? 'role_default_fallback' : 'stored_nested_snapshot',
            'niches' => $niches,
            'promotion_evidence' => false,
        ];
    }

    /**
     * Route recall research to the actual owner-envelope bottleneck.  A
     * round-robin variant can spend the only recall seat testing a cooldown
     * toggle when the parent is actually losing opportunities to a negative-EV
     * or spread veto.  The mapping is diagnostic only: each returned variant
     * still changes one gene and remains subject to every unchanged passport
     * gate.
     */
    private function recallVariantForNiche(?array $niche, int $index): string
    {
        $declaredVariant = (string) data_get($niche, 'recall_variant', '');
        if ($declaredVariant !== '') {
            return $declaredVariant;
        }

        $sourceId = (int) data_get($niche, 'source_performance_id', 0);
        if ($sourceId > 0) {
            $funnel = (array) data_get(ModelMarketPerformance::find($sourceId)?->metrics, 'entry_funnel', []);
            $rejected = array_filter((array) data_get($funnel, 'rejected', []), fn ($count): bool => is_numeric($count));
            if ($rejected !== []) {
                arsort($rejected);

                return match ((string) array_key_first($rejected)) {
                    'loss_cooldown' => 'loss_cooldown_shortening',
                    'negative_ev_lower_bound' => 'negative_ev_lower_bound_ablation',
                    'spread_to_atr' => 'spread_atr_recall_probe',
                    'regime_transition_wait' => 'transition_wait_shortening',
                    default => 'target_confidence',
                };
            }
        }

        return match ($index % 3) {
            1 => 'state_conditioned_cooldown',
            2 => 'negative_ev_lower_bound_ablation',
            default => 'target_confidence',
        };
    }

    /** Only uncertified operating-envelope cells receive a G102 child slot. */
    private function coverageRescuePlan(array $audit, int $populationSize = 20): array
    {
        $cells = array_values((array) data_get($audit, 'uncertified_cells', []));
        $parents = array_values((array) data_get($audit, 'parent_model_version_ids', []));
        if ($cells === [] || $parents === []) {
            return [];
        }

        return collect(range(0, max(0, $populationSize - 1)))->map(function (int $index) use ($cells, $parents, $audit): array {
            $cell = $cells[$index % count($cells)];
            $parentModelVersionId = (int) data_get($cell, 'parent_model_version_id', $parents[$index % count($parents)]);
            $parentFamily = ModelMarketPerformance::query()
                ->where('model_version_id', $parentModelVersionId)
                ->where('symbol', data_get($audit, 'symbol', data_get($cell, 'symbol', 'XAUUSD')))
                ->where('timeframe', data_get($audit, 'timeframe', data_get($cell, 'timeframe', 'H1')))
                ->latest('id')
                ->value('strategy_family')
                ?: LabAgent::query()->where('model_version_id', $parentModelVersionId)
                    ->latest('id')->value('strategy_family');
            $family = $parentFamily ?: 'differential_router';
            // Edge-first search forbids risk shaping until a context-bound
            // edge exists. Coverage rescue must therefore spend every seat
            // on the regime/entry owner surface instead of emitting risk
            // mutations that the dependency firewall will reject and
            // leaving a partial generation behind.
            $safeGenes = array_values(array_intersect([
                'trend_strength_min', 'minimum_signal_confidence', 'lookback',
                'minimum_confidence', 'trend_up_strength_min',
                'trend_down_strength_min', 'range_deviation',
                'trend_up_roc_period', 'trend_down_roc_period',
            ], array_keys($this->schemas->schema($family))));
            $declaredGene = $safeGenes === [] ? null : $safeGenes[$index % count($safeGenes)];

            return [
                // The rescue follows the sealed parent's own strategy group.
                // An unknown legacy family remains an unparented diagnostic
                // seat; it is never silently converted into a router child.
                'origin' => 'coverage_rescue', 'family' => $family, 'target' => 'regime_coverage',
                'niche' => [
                    'protocol' => CoverageRescueAuditService::PROTOCOL,
                    // Keep the cell label diagnostic, but give the compiler a
                    // real owner role. An unknown diagnostic label has an
                    // empty mutation allowlist and previously collapsed the
                    // whole rescue population into zero-diff clones.
                    'role' => 'uncertified_cell_only',
                    'specialist_role' => 'regime_coverage_specialist',
                    ...$cell,
                    'frozen_parent_model_version_id' => $parentModelVersionId,
                    'sealed_parent_strategy_family' => $parentFamily,
                    'declared_gene' => $declaredGene,
                    'edge_first_mutation_scope' => 'regime_or_entry_owner_only',
                    'entry_logic_frozen' => true, 'exit_logic_frozen' => true, 'non_target_parent_freeze' => true,
                    'differential_invariant' => 'non_target signal, confidence and trade-ledger identities must match parent; breach quarantines child.',
                ],
            ];
        })->all();
    }

    /** Recent full-replay failures become G98 context contracts, not month genes. */
    private function robustnessMatrixFrontier(AiLaboratory $lab): array
    {
        $rowsQuery = ModelMarketPerformance::query()
            ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
            ->where('evidence_status', 'valid')->latest('updated_at');
        $sourceLimit = (int) config('services.lab_selection.robustness_matrix_source_limit', 0);
        if ($sourceLimit > 0) {
            $rowsQuery->take($sourceLimit);
        }
        $rows = $rowsQuery->get();
        $frontier = [];
        foreach ($rows as $performance) {
            foreach ((array) data_get($performance->metrics, 'robustness_matrix.weakest_envelopes', []) as $cell) {
                $regime = (string) data_get($cell, 'regime');
                $volatility = (string) data_get($cell, 'volatility');
                $direction = strtoupper((string) data_get($cell, 'direction'));
                if (! in_array($regime, ['trend_up', 'trend_down', 'range'], true)
                    || ! in_array($volatility, ['low_volatility', 'normal_volatility', 'high_volatility'], true)
                    || ! in_array($direction, ['BUY', 'SELL'], true)) {
                    continue;
                }
                $frontier[] = [
                    'protocol' => 'robustness_matrix_v1', 'role' => 'failure_context',
                    'regime' => $regime, 'volatility' => $volatility, 'direction' => $direction,
                    'session_utc_hour' => (string) data_get($cell, 'session', 'unknown'),
                    'calendar_role' => 'diagnostic_recurrence_only',
                    'source_performance_id' => $performance->id,
                    'trades' => (int) data_get($cell, 'trades', 0),
                    'profit_factor' => (float) data_get($cell, 'net_pf', 0),
                    'reason' => 'robustness_matrix_weak_envelope',
                ];
            }
        }
        $frontierQuery = collect($frontier)->filter(fn (array $cell): bool => $cell['trades'] >= 3)
            ->sortBy(fn (array $cell): array => [$cell['profit_factor'], -$cell['trades']])
            ->unique(fn (array $cell): string => implode('|', [$cell['regime'], $cell['volatility'], $cell['session_utc_hour'], $cell['direction']]));
        $frontierLimit = (int) config('services.lab_selection.robustness_matrix_frontier_limit', 0);
        if ($frontierLimit > 0) {
            $frontierQuery = $frontierQuery->take($frontierLimit);
        }

        return $frontierQuery->values()->all();
    }

    /** A zero-activity family may explore, but it cannot consume rescue or
     * validation budget until it demonstrates an executable opportunity. */
    private function explorationOnlyFamilies(AiLaboratory $lab): array
    {
        if ($lab->symbol !== 'XAUUSD' || $lab->timeframe !== 'H1') {
            return [];
        }
        $latestPopulation = $lab->generations()->latest('generation')->first();
        if (! $latestPopulation) {
            return [];
        }
        $volatility = $latestPopulation->agents()->where('strategy_family', 'volatility')->get(['sample_count']);

        return $volatility->count() >= 3 && $volatility->every(fn ($agent) => (int) $agent->sample_count === 0)
            ? ['volatility']
            : [];
    }

    private function prioritizedFamilies(AiLaboratory $lab): array
    {
        $evidence = collect($lab->strategy_families)->reject(fn (string $family) => $this->familyPaused($lab, $family))->map(function (string $family) use ($lab): array {
            $diagnosis = AgentDiagnosis::query()->whereHas('modelMarketPerformance', fn ($query) => $query
                ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)->where('strategy_family', $family))
                ->latest()->first();
            $rescue = CandidateGateDecision::query()->where('stage', 'diagnostic_rescue_replay')
                ->whereHas('labAgent', fn ($agent) => $agent->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)->where('strategy_family', $family))
                ->latest('evaluated_at')->first();
            $vetoTarget = $this->shadowVetoTarget($lab->symbol, $lab->timeframe, $family);
            $deficits = (array) data_get($diagnosis?->evidence, 'deficits', []);
            $latest = ModelMarketPerformance::query()->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
                ->where('strategy_family', $family)->latest()->first();
            $curriculum = $latest ? $this->evolutionQuality->curriculum((array) $latest->metrics) : null;
            $history = $this->historicalLearning->latestForFamily($lab->symbol, $lab->timeframe, $family);
            $historyTarget = (string) data_get($history?->recommended_mutations, 'primary_target', '');
            $historyConfidence = (float) ($history?->confidence ?? 0);
            $doctorBundle = data_get($diagnosis?->evidence, 'gate_doctor.recommended_bundle');
            $target = $vetoTarget ?: ($doctorBundle ? $this->targetFromBundle($doctorBundle) : ($diagnosis ? $this->dominantTarget($diagnosis->primary_failure, $deficits) : ($curriculum['primary_target'] ?? $this->rescueTarget($rescue?->reason_codes ?? []))));
            $severity = (float) ($deficits['trade_deficit'] ?? 0) / 30
                + (float) ($deficits['pf_deficit'] ?? 0) / 1.3
                + (float) ($deficits['rolling_deficit'] ?? 0) / 3
                + (float) ($deficits['drawdown_excess'] ?? 0) / 15
                + (float) ($deficits['ruin_excess'] ?? 0) / 10
                + ($rescue ? 1.0 : 0.0);
            $failureCases = AgentFailureCase::query()
                ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
                ->where('regression_status', 'open')->latest()->take(3)->get();
            // A fresh screening profile outranks stale red-team/cost cases for
            // the next population. Otherwise an old stress finding can make a
            // new generation optimize drawdown while every current candidate
            // is actually failing PF or activity.
            $screeningCase = AgentFailureCase::query()
                ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
                ->where('discovered_by', 'CandidateHandoffProtocol')->where('regression_status', 'open')
                ->latest('updated_at')->first();
            $screeningReasons = (array) data_get($screeningCase?->evidence, "screening_failure_profile.family_reasons.{$family}", []);
            // A short screen may fail several gates at once. Preserve the
            // causal diagnosis, but retain the ranked alternatives so the
            // fixed experiment budget can test more than one bottleneck.
            $screeningTargets = $this->screeningTargetsForReasons($screeningReasons);
            $screeningTarget = $screeningTargets[0] ?? null;
            // A failed sealed portfolio is an ensemble-level failure signal.
            // Let its feature-envelope curriculum outrank stale member
            // diagnoses, while keeping month labels diagnostic only.
            $portfolioTargets = (array) data_get(
                $this->portfolioFailureCurriculum($lab),
                "targets.{$family}",
                []
            );
            if ($portfolioTargets !== []) {
                $screeningTargets = array_values(array_unique([
                    ...$portfolioTargets,
                    ...$screeningTargets,
                ]));
                $screeningTarget = $portfolioTargets[0];
            }
            // Immutable history outranks stale diagnosis when no current
            // screening profile exists. It supplies a failure target only;
            // it never grants a promotion or causal mutation direction.
            if ($historyTarget !== '') {
                $screeningTargets = array_values(array_unique([$historyTarget, ...$screeningTargets]));
                if ($screeningTarget === null && $historyConfidence >= 35) {
                    $screeningTarget = $historyTarget;
                }
            }
            $failureTarget = $screeningTarget ?: $screeningCase?->expected_action;
            if (! $failureTarget) {
                $failureTarget = match (true) {
                    $failureCases->contains('failure_type', 'cost_fragility') => 'drawdown_risk',
                    $failureCases->contains('failure_type', 'regime_coverage_quality') || $failureCases->contains('failure_type', 'transition_failure') => 'rolling_regime',
                    $failureCases->contains('failure_type', 'overfit_structure') => 'architecture',
                    $failureCases->contains('failure_type', 'edge_pf_signal_quality') => 'profit_factor',
                    $failureCases->contains('failure_type', 'trade_viability_signal_frequency') => 'trade_frequency',
                    default => null,
                };
            }
            $primaryTarget = $failureTarget ?: $target;
            if ($screeningTargets === [] && $primaryTarget) {
                $screeningTargets = [$primaryTarget];
            } elseif ($primaryTarget && ! in_array($primaryTarget, $screeningTargets, true)) {
                array_unshift($screeningTargets, $primaryTarget);
            }

            return ['family' => $family, 'target' => $primaryTarget,
                'screening_targets' => array_values(array_unique($screeningTargets)),
                'portfolio_curriculum' => $portfolioTargets !== [],
                'severity' => $severity + ($failureCases->count() * .35)
                    + ($historyTarget !== '' ? ($historyConfidence / 100) * ($history?->evidence_quality === 'exact' ? 1.5 : .35) : 0),
                'curriculum' => $curriculum,
                'failure_curriculum_case_ids' => $failureCases->pluck('id')->all(),
                'history' => $history ? [
                    'insight_id' => $history->insight_id,
                    'evidence_quality' => $history->evidence_quality,
                    'causal_prior_allowed' => (bool) $history->causal_prior_allowed,
                    'confidence' => (float) $history->confidence,
                    'primary_target' => $historyTarget ?: null,
                    'recommended_keys' => (array) data_get($history->recommended_mutations, 'keys', []),
                    'blocked_mutations' => (array) $history->blocked_mutations,
                    'failure_signature' => (array) $history->failure_signature,
                ] : null];
        })->sortByDesc('severity')->values();

        // No prior evidence is an exploration case, not an implicit claim of
        // quality. Keep the configured family order deterministic on G1.
        return $evidence->isEmpty() ? collect($lab->strategy_families)->map(fn ($family) => ['family' => $family, 'target' => 'trade_frequency', 'severity' => 0])->all() : $evidence->all();
    }

    /**
     * Convert a failed sealed portfolio into a bounded next-generation
     * curriculum. Month names remain observability evidence; the mutation
     * target is always a generalizable execution/regime feature envelope.
     */
    private function portfolioFailureCurriculum(AiLaboratory $lab): array
    {
        $portfolio = EliteAgentPortfolio::query()
            ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
            ->where('gate_status', 'failed')->latest('updated_at')->first();
        if (! $portfolio) {
            return [];
        }

        $reasons = array_values(array_unique((array) $portfolio->gate_reasons));
        $targetMap = [
            'FAILED_PORTFOLIO_CALENDAR_SURVIVAL' => 'monthly_survival',
            'FAILED_PORTFOLIO_TEMPORAL_SURVIVAL' => 'temporal_stability',
            'FAILED_PORTFOLIO_REGIME_COVERAGE' => 'regime_coverage',
            'FAILED_PORTFOLIO_STRESS_COST' => 'stress_cost',
            'FAILED_PORTFOLIO_PROFIT_FACTOR' => 'profit_factor',
            'FAILED_PORTFOLIO_TRADE_COUNT' => 'trade_frequency',
        ];
        $targets = collect($reasons)->map(fn (string $reason) => $targetMap[$reason] ?? null)
            ->filter()->unique()->values()->all();
        if ($targets === []) {
            return [];
        }

        $result = (array) data_get($portfolio->evidence, 'result', []);
        $failedWindows = collect((array) data_get($result, 'window_survival.windows', []))
            ->filter(fn (array $window): bool => data_get($window, 'status') === 'edge_failure' || (bool) data_get($window, 'catastrophic', false))
            ->map(fn (array $window): array => [
                'window' => data_get($window, 'month'),
                'regime_context' => array_keys((array) data_get($window, 'regime_performance', [])),
                'trades' => (int) data_get($window, 'trades', 0),
                'profit_factor' => (float) data_get($window, 'profit_factor', 0),
            ])->values()->all();
        $breakdown = (array) data_get($result, 'portfolio_evidence.member_breakdown', []);
        $memberFailures = collect($breakdown)->map(function (array $member, string $memberKey): ?array {
            $failedMonths = collect((array) data_get($member, 'monthly', []))
                ->filter(fn (array $month): bool => (float) data_get($month, 'profit_factor', 0) < 1.0)
                ->keys()->values()->all();

            return $failedMonths === [] ? null : ['member_key' => $memberKey, 'failed_windows' => $failedMonths];
        })->filter()->values()->all();

        return [
            'protocol' => 'portfolio_feature_envelope_curriculum_v1',
            'source_portfolio_id' => $portfolio->id,
            'targets' => array_fill_keys($lab->strategy_families, $targets),
            'failed_windows' => $failedWindows,
            'member_failure_map' => $memberFailures,
            'month_labels_are_diagnostic_only' => true,
            'repair_rule' => 'freeze unaffected lanes; replace only the failing sealed niche; require control-window non-regression and all unchanged gates',
        ];
    }

    /**
     * Turn observed sealed context failures into a small specialist council.
     * The target is a regime x volatility envelope, optionally split by a
     * direction that has independent evidence; never a calendar month.
     * This method deliberately returns only research instructions; admission
     * and promotion remain the responsibility of the normal gate services.
     */
    private function portfolioCouncilCurriculum(AiLaboratory $lab): array
    {
        $portfolio = EliteAgentPortfolio::query()
            ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
            ->latest('updated_at')->first();
        // A market with no individually forward-valid member normally has a
        // portfolio in `waiting`, not `failed`: the combined replay cannot
        // start until its members exist.  Requiring `failed` here created a
        // deadlock where the council curriculum could only be created after
        // the council had already replayed.  Seed the council from the
        // immutable failed-forward evidence in that state; this is research
        // routing only and never changes any promotion gate.
        if (! $portfolio || $portfolio->gate_status !== 'failed') {
            return $this->forwardFailureCouncilCurriculum($lab);
        }

        $candidateQuery = ModelMarketPerformance::query()
            ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
            ->where('evidence_status', 'valid')
            ->whereHas('modelVersion', fn ($query) => $query->whereJsonContains('metadata->portfolio_research_contract->protocol', 'portfolio_member_research_v1'))
            ->latest('updated_at');
        $sourceLimit = (int) config('services.lab_selection.portfolio_council_source_limit', 0);
        if ($sourceLimit > 0) {
            $candidateQuery->take($sourceLimit);
        }
        $candidates = $candidateQuery->get();

        $observed = [];
        foreach ($candidates as $candidate) {
            foreach ((array) data_get($candidate->metrics, 'pf_attribution.breakdown.by_regime_volatility', []) as $key => $row) {
                [$regime, $volatility] = array_pad(explode('|', (string) $key, 2), 2, null);
                $trades = (int) data_get($row, 'trades', 0);
                if (! in_array($regime, ['trend_up', 'trend_down', 'range'], true)
                    || ! in_array($volatility, ['low_volatility', 'normal_volatility', 'high_volatility'], true)
                    || $trades < 5) {
                    continue;
                }
                $pf = (float) data_get($row, 'net_pf', data_get($row, 'profit_factor', 0));
                $nicheKey = "{$regime}|{$volatility}";
                if (! isset($observed[$nicheKey]) || $pf < $observed[$nicheKey]['profit_factor']) {
                    $observed[$nicheKey] = [
                        'regime' => $regime, 'volatility' => $volatility,
                        'profit_factor' => $pf, 'trades' => $trades,
                        'source_performance_id' => $candidate->id,
                    ];
                }
            }
        }

        // Derive the next council from member x context evidence.  The old
        // hard-coded pair (trend_up|normal and range|low) could miss the
        // actual failing lanes of the sealed portfolio.  Calendar labels are
        // used only to prove that a context failed in a frozen window; they
        // never enter the niche contract or parameter mutation.
        $portfolioBreakdown = (array) data_get(
            $portfolio->evidence,
            'result.portfolio_evidence.member_breakdown',
            []
        );
        $niches = [];
        foreach ($portfolioBreakdown as $memberKey => $member) {
            $sourcePerformanceId = str_starts_with((string) $memberKey, 'performance:')
                ? (int) substr((string) $memberKey, strlen('performance:'))
                : null;
            foreach ((array) data_get($member, 'context_breakdown', []) as $contextKey => $context) {
                [$regime, $volatility] = array_pad(explode('|', (string) $contextKey, 2), 2, null);
                if (! in_array($regime, ['trend_up', 'trend_down', 'range'], true)
                    || ! in_array($volatility, ['low_volatility', 'normal_volatility', 'high_volatility'], true)) {
                    continue;
                }
                $trades = (int) data_get($context, 'trades', 0);
                $profitFactor = (float) data_get($context, 'profit_factor', 0);
                $failedFrozenWindow = collect((array) data_get($context, 'monthly', []))
                    ->contains(fn (array $month): bool => (int) data_get($month, 'trades', 0) >= 2
                        && (float) data_get($month, 'profit_factor', 0) < 1.0);
                $directionalNicheAdded = false;
                // The canonical portfolio ledger stores direction evidence at
                // member level, keyed by regime|volatility. Older/alternate
                // ledgers may nest it under the context row. Read both shapes
                // so a sealed BUY/SELL rescue is never silently skipped.
                $memberDirectionBreakdown = (array) data_get($member, 'direction_breakdown', []);
                $directionBreakdown = (array) data_get($context, 'direction_breakdown', []);
                if ($directionBreakdown === [] && array_key_exists((string) $contextKey, $memberDirectionBreakdown)) {
                    $directionBreakdown = (array) $memberDirectionBreakdown[(string) $contextKey];
                }
                $healthyDirectionComplements = [];
                foreach ($directionBreakdown as $direction => $directionEvidence) {
                    $direction = strtoupper((string) $direction);
                    if (! in_array($direction, ['BUY', 'SELL'], true)) {
                        continue;
                    }
                    $directionTrades = (int) data_get($directionEvidence, 'trades', 0);
                    $directionPf = (float) data_get($directionEvidence, 'profit_factor', 0);
                    $directionWindowFailure = collect((array) data_get($directionEvidence, 'monthly', []))
                        ->contains(fn (array $month): bool => (int) data_get($month, 'trades', 0) >= 2
                            && (float) data_get($month, 'profit_factor', 0) < 1.0);
                    // A side-specific rescue needs enough independent side
                    // observations. It is a research instruction only; the
                    // combined portfolio still faces every unchanged gate.
                    if (! $directionWindowFailure && $directionTrades >= 8 && $directionPf >= 1.3) {
                        // Preserve a healthy opposite side as a separate
                        // research hypothesis when the context itself has a
                        // frozen-window failure.  This is not a claim that
                        // the side will pass; it gives the council a chance
                        // to replace a losing directional lane without
                        // learning a calendar label.
                        $healthyDirectionComplements[] = [
                            'regime' => $regime,
                            'volatility' => $volatility,
                            'direction' => $direction,
                            'profit_factor' => $directionPf,
                            'trades' => $directionTrades,
                            'source_performance_id' => $sourcePerformanceId,
                            'reason' => 'healthy_directional_complement',
                        ];

                        continue;
                    }
                    if ($directionTrades < 8) {
                        continue;
                    }
                    $niches[] = [
                        'regime' => $regime,
                        'volatility' => $volatility,
                        'direction' => $direction,
                        'profit_factor' => $directionPf,
                        'trades' => $directionTrades,
                        'source_performance_id' => $sourcePerformanceId,
                        'reason' => $directionWindowFailure
                            ? 'sealed_direction_frozen_window_failure'
                            : 'sealed_direction_below_research_floor',
                    ];
                    $directionalNicheAdded = true;
                }
                if ($directionalNicheAdded) {
                    foreach ($healthyDirectionComplements as $complement) {
                        $alreadyAdded = collect($niches)->contains(
                            fn (array $existing): bool => data_get($existing, 'regime') === $complement['regime']
                                && data_get($existing, 'volatility') === $complement['volatility']
                                && data_get($existing, 'direction') === $complement['direction']
                                && data_get($existing, 'source_performance_id') === $complement['source_performance_id'],
                        );
                        if (! $alreadyAdded) {
                            $niches[] = $complement;
                        }
                    }
                }
                if ($directionalNicheAdded) {
                    continue;
                }
                // Eight total observations plus either a weak aggregate or a
                // repeated frozen-window failure is enough to fund research.
                // It is not a promotion threshold and never bypasses replay.
                if ($trades < 8 || (! $failedFrozenWindow && $profitFactor >= 1.3)) {
                    continue;
                }
                $niches[] = [
                    'regime' => $regime,
                    'volatility' => $volatility,
                    'profit_factor' => $profitFactor,
                    'trades' => $trades,
                    'source_performance_id' => $sourcePerformanceId,
                    'reason' => $failedFrozenWindow
                        ? 'sealed_context_frozen_window_failure'
                        : 'sealed_context_below_research_floor',
                ];
            }
        }

        // Legacy evidence may not contain the richer context breakdown. Keep
        // a conservative fallback so a portfolio can still create research
        // work after an old replay, but remove it automatically once a fresh
        // replay writes context_breakdown.
        if ($niches === []) {
            foreach ([
                ['regime' => 'trend_up', 'volatility' => 'normal_volatility'],
                ['regime' => 'range', 'volatility' => 'low_volatility'],
            ] as $requiredNiche) {
                $key = $requiredNiche['regime'].'|'.$requiredNiche['volatility'];
                $row = $observed[$key] ?? null;
                if ($row === null || (float) $row['profit_factor'] < 1.3 || (int) $row['trades'] < 8) {
                    $niches[] = [
                        ...$requiredNiche,
                        'profit_factor' => (float) ($row['profit_factor'] ?? 0),
                        'trades' => (int) ($row['trades'] ?? 0),
                        'source_performance_id' => $row['source_performance_id'] ?? null,
                        'reason' => 'legacy_context_evidence_missing',
                    ];
                }
            }
        }

        // The sealed portfolio ledger is intentionally conservative, but it
        // can lag behind a newer full replay.  Recover asymmetric directional
        // evidence from the current valid performance frontier as a research
        // hypothesis.  Example: a context can have a healthy SELL lane while
        // BUY is weak; that is a real complementary specialist opportunity,
        // whereas copying the aggregate context PF would hide the asymmetry.
        // This never promotes the source and never relaxes a combined gate.
        foreach ($this->evidenceDirectionalComplements($lab, $niches) as $complement) {
            $alreadyAdded = collect($niches)->contains(
                fn (array $existing): bool => data_get($existing, 'regime') === $complement['regime']
                    && data_get($existing, 'volatility') === $complement['volatility']
                    && strtoupper((string) data_get($existing, 'direction', '')) === strtoupper((string) $complement['direction'])
                    && (int) data_get($existing, 'source_performance_id', 0) === (int) $complement['source_performance_id'],
            );
            if (! $alreadyAdded) {
                $niches[] = $complement;
            }
        }

        // The portfolio ledger can lag behind a newer standalone forward
        // replay. If that replay's dominant rejection is transition_wait,
        // preserve it as the recall owner for the matching regime envelope.
        // This is a research-source override only; the portfolio and all
        // forward/elite gates remain unchanged.
        $forwardCouncil = $this->forwardFailureCouncilCurriculum($lab);
        $transitionRecall = collect((array) data_get($forwardCouncil, 'niches', []))
            ->first(fn (array $niche): bool => data_get($niche, 'recall_variant') === 'transition_wait_shortening');
        if (is_array($transitionRecall)) {
            foreach ($niches as $nicheIndex => $niche) {
                if ((string) data_get($niche, 'regime') !== (string) data_get($transitionRecall, 'regime')
                    || (string) data_get($niche, 'volatility') !== (string) data_get($transitionRecall, 'volatility')) {
                    continue;
                }
                $niches[$nicheIndex]['recall_source_performance_id'] = data_get($transitionRecall, 'source_performance_id');
                $niches[$nicheIndex]['recall_variant'] = 'transition_wait_shortening';
                $niches[$nicheIndex]['transition_wait_rejections'] = (int) data_get($transitionRecall, 'transition_wait_rejections', 0);
                $niches[$nicheIndex]['dominant_entry_rejection'] = 'regime_transition_wait';
                $niches[$nicheIndex]['research_reason'] = 'forward_transition_wait_recurrence';
                break;
            }
        }

        // Older portfolio ledgers do not carry a state cluster. Reconstruct it
        // from the frozen member performance rather than falling back to a
        // calendar label or an unscoped global mutation.
        $niches = collect($niches)->map(function (array $niche): array {
            if (data_get($niche, 'state_cluster') !== null) {
                return $niche;
            }
            $source = ModelMarketPerformance::find((int) data_get($niche, 'source_performance_id', 0));
            $niche['state_cluster'] = $this->stateClusterForPerformance(
                $source,
                data_get($niche, 'regime'),
                data_get($niche, 'volatility'),
            );

            return $niche;
        })->all();

        $niches = collect($niches)
            ->unique(fn (array $niche): string => (string) $niche['regime'].'|'.(string) $niche['volatility'].'|'.(string) ($niche['direction'] ?? 'any').'|'.(string) ($niche['source_performance_id'] ?? ''))
            // Give an evidence-derived asymmetric lane a seat before legacy
            // weak niches.  The later member selector still requires its own
            // niche sample, stress edge and unchanged full/portfolio gates.
            ->sortBy(fn (array $niche): array => [
                in_array(data_get($niche, 'reason'), [
                    'evidence_directional_complement',
                    'evidence_directional_temporal_rescue',
                ], true) ? 0 : 1,
                (int) data_get($niche, 'trades', 0) >= 10 ? 0 : 1,
                (float) data_get($niche, 'profit_factor', 0),
            ])
            ->when((int) config('services.lab_selection.portfolio_council_max_niches', 0) > 0,
                fn ($collection) => $collection->take((int) config('services.lab_selection.portfolio_council_max_niches', 0)))
            ->values()->all();

        $niches = $this->withTransitionRiskRouter($niches);

        return [
            'protocol' => 'portfolio_council_curriculum_v1',
            'source_portfolio_id' => $portfolio->id,
            'niches' => $niches,
            'rule' => 'repair only weak regime x volatility x direction envelopes; preserve all unaffected lanes and require combined replay gates',
        ];
    }

    /**
     * Add one explicit transition/liquidity-risk owner to either a failed
     * portfolio curriculum or the forward-failure council. The owner keeps a
     * measurable regime x volatility anchor for its standalone replay, while
     * the role and transition/risk lanes remain distinct from trend/range
     * specialists. The fixed generation budget decides how many of its lanes
     * fit; it never creates extra promotion capacity.
     */
    private function withTransitionRiskRouter(array $niches): array
    {
        if ($niches === [] || collect($niches)->contains(fn (array $niche): bool => data_get($niche, 'role') === 'transition_risk_router'
        )) {
            return $niches;
        }

        $transitionAnchor = collect($niches)->first(fn (array $niche): bool => data_get($niche, 'regime') === 'trend_up'
            && data_get($niche, 'volatility') === 'high_volatility'
        ) ?? ($niches[0] ?? null);
        if (! is_array($transitionAnchor)) {
            return $niches;
        }

        $niches[] = [
            'protocol' => 'portfolio_council_v1',
            'role' => 'transition_risk_router',
            'specialist_role' => 'transition_risk_router',
            'regime' => data_get($transitionAnchor, 'regime', 'trend_up'),
            'volatility' => data_get($transitionAnchor, 'volatility', 'normal_volatility'),
            'direction' => null,
            'objective' => 'transition_firewall',
            'source_performance_id' => data_get($transitionAnchor, 'source_performance_id'),
            'observed_trades' => data_get($transitionAnchor, 'observed_trades', data_get($transitionAnchor, 'trades', 0)),
            'observed_profit_factor' => data_get($transitionAnchor, 'observed_profit_factor', data_get($transitionAnchor, 'profit_factor', 0)),
            'failed_gate_reasons' => data_get($transitionAnchor, 'failed_gate_reasons', []),
            'state_cluster' => data_get($transitionAnchor, 'state_cluster'),
            'research_reason' => 'shared_transition_and_liquidity_risk_router',
            'owner_context' => 'transition_risk',
            'non_target_parent_freeze' => true,
            'promotion_rule' => 'standalone_forward_passport_required; combined_portfolio_after_individual_passports',
        ];

        return $niches;
    }

    /**
     * Build a month-independent failure state from immutable replay evidence.
     * Calendar months remain diagnostic windows only; they are deliberately
     * excluded from the cluster identity and from child mutation inputs.
     */
    private function stateClusterForPerformance(
        ?ModelMarketPerformance $performance,
        ?string $regime = null,
        ?string $volatility = null,
    ): ?array {
        if (! $performance) {
            return null;
        }

        $metrics = (array) $performance->metrics;
        $funnel = (array) data_get($metrics, 'entry_funnel', []);
        $veto = (array) data_get($metrics, 'veto_regret', []);
        $contexts = (array) data_get($veto, 'by_regime_context', []);
        $dominantVeto = (string) data_get($funnel, 'dominant_rejection', '');
        if ($dominantVeto === '') {
            $dominantVeto = (string) data_get($veto, 'highest_regret_veto', '');
        }

        $selected = null;
        $matching = [];
        foreach ($contexts as $key => $row) {
            $parts = array_pad(explode('|', (string) $key, 4), 4, null);
            if ($regime !== null && $parts[1] !== $regime) {
                continue;
            }
            if ($volatility !== null && $parts[2] !== $volatility) {
                continue;
            }
            $matching[] = ['key' => (string) $key, 'row' => (array) $row, 'parts' => $parts];
        }
        if ($matching !== []) {
            $preferred = collect($matching)->filter(fn (array $item): bool => $dominantVeto !== '' && $item['parts'][0] === $dominantVeto
            );
            $selected = ($preferred->isNotEmpty() ? $preferred : collect($matching))
                ->sortByDesc(fn (array $item): int => (int) data_get($item, 'row.shadow_trades', 0))
                ->first();
            $parts = (array) data_get($selected, 'parts', []);
            $dominantVeto = $dominantVeto !== '' ? $dominantVeto : (string) ($parts[0] ?? '');
            $regime ??= $parts[1] ?? null;
            $volatility ??= $parts[2] ?? null;
        }

        $transition = (array) data_get($metrics, 'transition_homework', []);
        $transitionState = $dominantVeto === 'regime_transition_wait'
            ? 'transition_wait'
            : ((int) data_get($transition, 'transition_events', 0) > 0 ? 'transition_observed' : 'unknown');
        $spreadLiquidity = (string) (data_get($selected, 'parts.3') ?: 'unknown');
        if ($spreadLiquidity === 'unknown' && $dominantVeto === 'spread_to_atr') {
            $spreadLiquidity = 'spread_filter_veto';
        }
        $labels = [
            'regime' => $regime ?: 'unknown',
            'volatility' => $volatility ?: 'unknown',
            'transition_state' => $transitionState,
            'spread_liquidity_state' => $spreadLiquidity,
            'veto_reason' => $dominantVeto !== '' ? $dominantVeto : 'unknown',
        ];

        return [
            'protocol' => 'state_cluster_v1',
            'status' => ($selected !== null || $transition !== []) ? 'assessed' : 'insufficient_evidence',
            'cluster_id' => hash('sha256', json_encode($labels, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)),
            ...$labels,
            'veto_samples' => (int) data_get($selected, 'row.shadow_trades', 0),
            'transition_events' => (int) data_get($transition, 'transition_events', 0),
            'transition_false_entry_rate' => (float) data_get($transition, 'false_entry_rate', 0),
            'source_context_key' => data_get($selected, 'key'),
            'month_labels_are_diagnostic_only' => true,
            'promotion_evidence' => false,
            'rule' => 'State cluster guides bounded research only; calendar month is never a mutation feature.',
        ];
    }

    /**
     * Activate the council before a combined portfolio exists.
     *
     * Individual forward failures are already immutable, context-level
     * evidence.  Use them to create one bounded owner for each regime so the
     * next generation actually tests trend-up, trend-down and range lanes.
     * The resulting children remain screening/research candidates and are
     * still subject to full replay, standalone gates and the combined gate.
     */
    private function forwardFailureCouncilCurriculum(AiLaboratory $lab): array
    {
        $performanceQuery = ModelMarketPerformance::query()
            ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
            ->where('evidence_status', 'valid')
            // Overfit members are still valid failure evidence for research
            // routing. They can expose which regime owner failed, but they
            // can never become a council member or promotion evidence.
            ->whereIn('status', ['challenger', 'stagnated', 'rejected', 'overfit'])
            ->latest('updated_at');
        $sourceLimit = (int) config('services.lab_selection.forward_failure_source_limit', 0);
        if ($sourceLimit > 0) {
            $performanceQuery->take($sourceLimit);
        }
        $performances = $performanceQuery->get();
        if ($performances->isEmpty()) {
            return [];
        }

        $decisions = CandidateGateDecision::query()
            ->where('stage', 'statistical_forward_gate')
            ->where('decision', 'failed')
            ->whereIn('model_market_performance_id', $performances->pluck('id')->all())
            ->latest('evaluated_at')->get()->groupBy('model_market_performance_id');
        $failed = $performances->filter(fn (ModelMarketPerformance $performance): bool => $decisions->has($performance->id));
        if ($failed->isEmpty()) {
            return [];
        }

        return [
            'protocol' => 'portfolio_council_curriculum_v1',
            'source_portfolio_id' => null,
            'activation' => 'forward_failure_deadlock_breaker_v1',
            'niches' => $this->withTransitionRiskRouter($this->forwardFailureCouncilNiches($failed, $decisions)),
            'rule' => 'Individual forward failures seed explicit regime owners; no member or combined portfolio receives promotion evidence from this curriculum.',
        ];
    }

    /**
     * Keep each regime's original candidate/tie order, without decoding every
     * full replay document once per regime and once per candidate field.
     * There is no process/persistent cache: fresh original rows are selected
     * above on every call. Only the three winning contexts need state clusters.
     */
    private function forwardFailureCouncilNiches(\Illuminate\Support\Collection $failed, \Illuminate\Support\Collection $decisions): array
    {
        $defaults = [
            'trend_up' => 'normal_volatility',
            'trend_down' => 'normal_volatility',
            'range' => 'low_volatility',
        ];
        $bestByRegime = [];
        foreach ($failed as $performance) {
            $metrics = (array) $performance->metrics;
            $contexts = (array) data_get($metrics, 'pf_attribution.breakdown.by_regime_volatility', []);
            $dominantRejection = (string) data_get($metrics, 'entry_funnel.dominant_rejection', '');
            $transitionWaitRejections = (int) data_get($metrics, 'entry_funnel.rejected.regime_transition_wait', 0);
            foreach ($contexts as $key => $row) {
                [$contextRegime, $volatility] = array_pad(explode('|', (string) $key, 2), 2, null);
                if (! array_key_exists((string) $contextRegime, $defaults) || ! in_array($volatility, ['low_volatility', 'normal_volatility', 'high_volatility'], true)) {
                    continue;
                }
                $regime = $contextRegime;
                $best = $bestByRegime[$regime] ?? null;
                $trades = (int) data_get($row, 'trades', 0);
                if ($trades < 3) {
                    continue;
                }
                $pf = (float) data_get($row, 'net_pf', data_get($row, 'profit_factor', 0));
                $candidate = [
                    'regime' => $regime, 'volatility' => $volatility,
                    'profit_factor' => $pf, 'trades' => $trades,
                    'source_performance_id' => $performance->id,
                    'failed_gate_reasons' => array_values(array_unique((array) data_get($decisions->get($performance->id)->first(), 'reason_codes', []))),
                ];
                // A transition-dominant child is a more informative
                // research parent for the transition/recall lane than a
                // higher-trade child whose bottleneck is unrelated. This
                // is only source selection for bounded experiments; it
                // does not grant the source or its child any gate pass.
                $candidate['_transition_priority'] = $dominantRejection === 'regime_transition_wait';
                $candidate['_transition_wait_rejections'] = $transitionWaitRejections;
                $candidate['dominant_entry_rejection'] = $dominantRejection ?: null;
                $isPreferredFamily = in_array($performance->strategy_family, ['hybrid', 'differential_router'], true);
                $bestPreferred = $best && in_array($best['_family'] ?? null, ['hybrid', 'differential_router'], true);
                $bestTransitionPriority = (bool) ($best['_transition_priority'] ?? false);
                $transitionWins = $candidate['_transition_priority'] && ! $bestTransitionPriority;
                $sameTransitionPriorityMoreEvidence = $candidate['_transition_priority'] === $bestTransitionPriority
                    && $transitionWaitRejections > (int) ($best['_transition_wait_rejections'] ?? 0);
                if ($best === null
                    || ($isPreferredFamily && ! $bestPreferred)
                    || (($isPreferredFamily === $bestPreferred)
                        && ($transitionWins
                            || $sameTransitionPriorityMoreEvidence
                            || (! $candidate['_transition_priority']
                                && ! $bestTransitionPriority
                                && $trades > (int) ($best['trades'] ?? 0))))) {
                    $candidate['_family'] = $performance->strategy_family;
                    $candidate['_performance'] = $performance;
                    $bestByRegime[$regime] = $candidate;
                }
            }
        }

        $niches = [];
        foreach ($defaults as $regime => $defaultVolatility) {
            $best = $bestByRegime[$regime] ?? null;
            $cluster = $best === null ? null : $this->stateClusterForPerformance(
                $best['_performance'], $regime, $best['volatility'],
            );
            $niches[] = [
                'protocol' => 'portfolio_council_v1',
                'role' => $regime.'_specialist',
                'regime' => $regime,
                'volatility' => (string) ($best['volatility'] ?? $defaultVolatility),
                'direction' => null,
                'objective' => 'regime_coverage',
                'source_performance_id' => $best['source_performance_id'] ?? null,
                'observed_trades' => (int) ($best['trades'] ?? 0),
                'observed_profit_factor' => (float) ($best['profit_factor'] ?? 0),
                'failed_gate_reasons' => $best['failed_gate_reasons'] ?? [],
                'dominant_entry_rejection' => $best['dominant_entry_rejection'] ?? null,
                'transition_wait_rejections' => (int) ($best['_transition_wait_rejections'] ?? 0),
                'state_cluster' => $cluster,
                'recall_variant' => (bool) ($best['_transition_priority'] ?? false)
                    ? 'transition_wait_shortening' : null,
                'recall_source_performance_id' => (bool) ($best['_transition_priority'] ?? false)
                    ? ($best['source_performance_id'] ?? null) : null,
                'research_reason' => 'forward_failure_without_combined_portfolio',
                'non_target_parent_freeze' => true,
                'promotion_rule' => 'combined_portfolio_only',
            ];
        }

        return $niches;
    }

    /**
     * Find a bounded BUY/SELL complement from already-valid full evidence.
     * The source must have current statistical metadata, positive stress-cost
     * edge, at least ten observations on the declared side, and a weak or
     * absent opposite side.  A temporal failure is allowed only as an
     * explicitly labelled rescue hypothesis; it can never become a member
     * without passing the unchanged temporal gate. Calendar labels are
     * deliberately ignored.
     */
    private function evidenceDirectionalComplements(AiLaboratory $lab, array $existingNiches = []): array
    {
        $coveredContexts = collect($existingNiches)
            ->map(fn (array $niche): string => (string) data_get($niche, 'regime').'|'.(string) data_get($niche, 'volatility'))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $performanceQuery = ModelMarketPerformance::with('modelVersion')
            ->where('symbol', $lab->symbol)
            ->where('timeframe', $lab->timeframe)
            ->where('evidence_status', 'valid')
            ->latest('updated_at');
        $sourceLimit = (int) config('services.lab_selection.evidence_complement_source_limit', 0);
        if ($sourceLimit > 0) {
            $performanceQuery->take($sourceLimit);
        }
        $performances = $performanceQuery->get();

        $complements = [];
        foreach ($performances as $performance) {
            if ($performance->status === 'overfit') {
                continue;
            }
            if ((int) data_get($performance->modelVersion?->metadata, 'statistical_gate_version', 0) < 3) {
                continue;
            }

            $metrics = (array) $performance->metrics;
            $stressPf = (float) data_get(
                $metrics,
                'pf_attribution.stress_cost.profit_factor',
                data_get($metrics, 'screening_survival.stress_cost_pf', 0),
            );
            if ($stressPf < 1.05) {
                continue;
            }

            $temporalFailure = collect((array) data_get($metrics, 'pf_attribution.breakdown.by_temporal_chunk', []))
                ->contains(fn (array $chunk): bool => (int) data_get($chunk, 'trades', 0) >= 5
                    && (float) data_get($chunk, 'net_pf', data_get($chunk, 'profit_factor', 0)) < 1.0);

            foreach ((array) data_get($metrics, 'pf_attribution.breakdown.by_regime_volatility_direction', []) as $contextKey => $directions) {
                [$regime, $volatility] = array_pad(explode('|', (string) $contextKey, 2), 2, null);
                if (! in_array($regime, ['trend_up', 'trend_down', 'range'], true)
                    || ! in_array($volatility, ['low_volatility', 'normal_volatility', 'high_volatility'], true)) {
                    continue;
                }
                // The sealed council already owns this context.  Only add a
                // new evidence-derived lane when it expands envelope
                // coverage; otherwise the next generation would spend its
                // whole budget cloning the same regime.
                if (in_array("{$regime}|{$volatility}", $coveredContexts, true)) {
                    continue;
                }

                foreach ((array) $directions as $direction => $row) {
                    $direction = strtoupper((string) $direction);
                    if (! in_array($direction, ['BUY', 'SELL'], true) || ! is_array($row)) {
                        continue;
                    }
                    $trades = (int) data_get($row, 'trades', 0);
                    $pf = (float) data_get($row, 'net_pf', data_get($row, 'profit_factor', 0));
                    if ($trades < 10 || $pf < 1.3) {
                        continue;
                    }

                    $opposite = $direction === 'BUY' ? 'SELL' : 'BUY';
                    $oppositePf = (float) data_get(
                        $directions,
                        "{$opposite}.net_pf",
                        data_get($directions, "{$opposite}.profit_factor", 0),
                    );
                    if ($oppositePf >= 1.1) {
                        continue;
                    }

                    $complements[] = [
                        'regime' => $regime,
                        'volatility' => $volatility,
                        'direction' => $direction,
                        'profit_factor' => $pf,
                        'trades' => $trades,
                        'source_performance_id' => $performance->id,
                        // A locally strong side with a weak temporal chunk is
                        // a repair experiment, not a healthy member.  Keep it
                        // available so evolution can attack the exact
                        // temporal weakness, but let the normal member
                        // selector reject the child unless every unchanged
                        // survival gate is later satisfied.
                        'reason' => $temporalFailure
                            ? 'evidence_directional_temporal_rescue'
                            : 'evidence_directional_complement',
                        'opposite_profit_factor' => $oppositePf,
                    ];
                }
            }
        }

        return collect($complements)
            ->sortByDesc(fn (array $row): array => [
                (float) data_get($row, 'profit_factor', 0),
                (int) data_get($row, 'trades', 0),
            ])
            ->unique(fn (array $row): string => (string) $row['regime'].'|'.(string) $row['volatility'].'|'.(string) $row['direction'])
            ->take(2)
            ->values()
            ->all();
    }

    private function familyPaused(AiLaboratory $lab, string $family): bool
    {
        $records = MutationMemory::with('labAgent.generation')
            ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
            ->where('strategy_family', $family)->whereNotNull('gate_transition')->get();

        return $this->noGateProgressAcrossThreeGenerations($records);
    }

    private function pausedArchitectures(string $symbol, string $timeframe, string $family): array
    {
        return MutationMemory::with('labAgent.generation')->where(compact('symbol', 'timeframe'))
            ->where('strategy_family', $family)->where('parameter_key', '__architecture')->whereNotNull('gate_transition')->get()
            ->groupBy(fn (MutationMemory $memory) => (string) data_get($memory->new_value, 'value'))
            ->filter(fn ($records) => $this->noGateProgressAcrossThreeGenerations($records))
            ->keys()->filter()->values()->all();
    }

    private function noGateProgressAcrossThreeGenerations($records): bool
    {
        $generations = collect($records)->filter(fn (MutationMemory $memory) => $memory->labAgent?->generation?->status === 'completed')
            ->groupBy(fn (MutationMemory $memory) => $memory->labAgent?->lab_generation_id)
            ->sortByDesc(fn ($items) => (int) $items->first()?->labAgent?->generation?->generation)
            ->take(3);
        if ($generations->count() < 3) {
            return false;
        }

        return $generations->every(fn ($items) => $items->every(fn (MutationMemory $memory) => empty(data_get($memory->gate_transition, 'improved', []))));
    }

    private function dominantTarget(?string $failure, array $deficits): string
    {
        return match ($failure) {
            'signal_starvation', 'over_filtering', 'insufficient_trades', 'no_trade' => 'trade_frequency',
            'negative_edge', 'weak_profit_factor', 'stop_too_tight' => 'profit_factor',
            'excessive_drawdown', 'ruin_risk' => 'drawdown_risk',
            'overfit' => 'architecture',
            default => ((int) ($deficits['rolling_deficit'] ?? 0) > 0 ? 'rolling_regime' : 'profit_factor'),
        };
    }

    private function rescueTarget(array $reasons): string
    {
        if (in_array('FAILED_TRADE_COUNT', $reasons, true)) {
            return 'trade_frequency';
        }
        if (in_array('FAILED_DRAWDOWN', $reasons, true) || in_array('FAILED_RUIN_RISK', $reasons, true)) {
            return 'drawdown_risk';
        }
        if (in_array('FAILED_PROFIT_FACTOR', $reasons, true) || in_array('FAILED_STRESS_COST', $reasons, true)) {
            return 'profit_factor';
        }

        return 'rolling_regime';
    }

    /** A shadow result may relax exactly one veto; it never weakens promotion gates. */
    private function shadowVetoTarget(string $symbol, string $timeframe, string $family): ?string
    {
        if ($target = $this->vetoPolicies->recommendedTarget($symbol, $timeframe, $family)) {
            return $target;
        }
        $decisionQuery = CandidateGateDecision::query()->where('stage', 'screening')
            ->whereHas('labAgent', fn ($agent) => $agent->where('symbol', $symbol)->where('timeframe', $timeframe)->where('strategy_family', $family))
            ->latest('evaluated_at');
        $decisionLimit = (int) config('services.lab_selection.shadow_veto_decision_limit', 0);
        if ($decisionLimit > 0) {
            $decisionQuery->take($decisionLimit);
        }
        $decisions = $decisionQuery->get();
        foreach ($decisions as $decision) {
            foreach ((array) data_get($decision->metrics, 'veto_regret.by_veto_reason', []) as $reason => $metrics) {
                // Legacy aggregates remain diagnostic only. New policy-lab
                // records require 30 context samples, three months and a
                // positive lower bound before a bounded experiment.
                if ((int) data_get($metrics, 'shadow_trades', 0) < 30 || data_get($metrics, 'recommended_action') !== 'relax_bounded_veto') {
                    continue;
                }

                return match ($reason) {
                    'loss_cooldown' => 'shadow_veto_loss_cooldown',
                    'minimum_confidence' => 'shadow_veto_confidence',
                    'high_volatility_veto' => 'shadow_veto_volatility',
                    default => null,
                };
            }
        }

        return null;
    }

    private function screeningTargetsForReasons(array $reasons): array
    {
        $mapping = [
            'FAILED_TRAIN_FORWARD_GAP' => 'temporal_stability',
            'FAILED_TEMPORAL_SCORE_DRIFT' => 'temporal_stability',
            'FAILED_PARAMETER_STABILITY' => 'temporal_stability',
            'FAILED_SIGNAL_TIMING_STABILITY' => 'temporal_stability',
            'FAILED_TEMPORAL_CHUNK_SURVIVAL' => 'temporal_stability',
            'FAILED_CALENDAR_MONTH_SURVIVAL' => 'monthly_survival',
            // Compatibility for historical G34/G35 ledger rows.
            'FAILED_MONTHLY_SURVIVAL' => 'monthly_survival',
            'FAILED_REGIME_COVERAGE' => 'regime_coverage',
            'FAILED_TRANSITION' => 'regime_coverage',
            'FAILED_STRESS_COST' => 'stress_cost',
            'FAILED_TRADE_COUNT' => 'trade_frequency',
            'FAILED_PROFIT_FACTOR' => 'profit_factor',
            'FAILED_DRAWDOWN' => 'drawdown_risk',
            'FAILED_RUIN' => 'drawdown_risk',
            'FAILED_OVERFIT' => 'architecture',
            'FAILED_STATISTICAL' => 'architecture',
            // Calendar alignment is a data-contract defect, not a strategy
            // parameter defect. It must be fixed in the event pipeline.
            'FAILED_CALENDAR_ALIGNMENT' => null,
        ];
        $weighted = [];
        foreach ($reasons as $key => $value) {
            $reason = is_string($key) ? $key : (string) $value;
            $count = is_string($key) && is_numeric($value) ? (int) $value : 1;
            if (! array_key_exists($reason, $mapping) || $mapping[$reason] === null) {
                continue;
            }
            $target = $mapping[$reason];
            $weighted[$target] = ($weighted[$target] ?? 0) + max(1, $count);
        }
        arsort($weighted);

        return array_keys($weighted);
    }

    private function screeningTargetForReasons(array $reasons): ?string
    {
        return $this->screeningTargetsForReasons($reasons)[0] ?? null;
    }

    private function targetFromBundle(string $bundle): string
    {
        return match ($bundle) {
            'trade_frequency_bundle' => 'trade_frequency', 'profit_factor_bundle' => 'profit_factor',
            'drawdown_bundle' => 'drawdown_risk', 'architecture_bundle' => 'architecture',
            default => 'rolling_regime',
        };
    }

    /**
     * Role baselines are safety invariants, not promotion shortcuts.  They
     * make the council curriculum express ownership explicitly: a specialist
     * may research its envelope, but it cannot remove the safety mechanism
     * that defines that envelope.
     */
    private function councilRoleBaseline(string $role, string $family): array
    {
        $baseline = match ($role) {
            'trend_up_specialist', 'trend_down_specialist' => [
                'transition_firewall_enabled' => true,
            ],
            'range_specialist' => [
                'transition_firewall_enabled' => true,
                'range_low_volatility_only' => true,
                'range_reentry_required' => true,
            ],
            'transition_risk_router' => [
                'transition_firewall_enabled' => true,
                'high_volatility_wait' => false,
            ],
            'temporal_survival_drift_abstention_specialist' => [
                'temporal_survival_enabled' => true,
                'adaptive_signal_expiry_enabled' => true,
                'drift_abstention_enabled' => true,
            ],
            default => [],
        };

        return array_intersect_key($baseline, $this->schemas->schema($family));
    }

    /** @return array<string, mixed> */
    private function councilRolePolicySpec(string $role): array
    {
        return match ($role) {
            'trend_up_specialist' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'trend_up|high_volatility',
                'mutation_allowlist' => ['transition_wait_candles', 'high_volatility_risk_multiplier'],
                'protected_invariants' => ['transition_firewall_enabled' => true],
                'unknown_state_action' => 'WAIT',
                'transition_action' => 'WAIT_OR_REDUCE_RISK',
            ],
            'trend_down_specialist' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'trend_down|normal_volatility',
                'mutation_allowlist' => [
                    'transition_wait_candles', 'loss_cooldown_candles',
                    'confidence_ev_lower_bound_enabled', 'differential_target_min_signal_confidence',
                ],
                'protected_invariants' => ['transition_firewall_enabled' => true],
                'unknown_state_action' => 'WAIT',
                'recall_rule' => 'Expand recall only through a state-conditioned bounded probe; preserve abstention and cost gates.',
            ],
            'range_specialist' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'range|low_volatility',
                'mutation_allowlist' => ['range_signal_mode', 'range_reentry_required', 'range_deviation', 'range_adx_max'],
                'protected_invariants' => [
                    'transition_firewall_enabled' => true,
                    'range_low_volatility_only' => true,
                    'range_reentry_required' => true,
                ],
                'unknown_state_action' => 'WAIT',
                'edge_absent_action' => 'ABSTAIN_AND_SHADOW',
            ],
            'transition_risk_router' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'transition|risk',
                'routing_only' => true,
                'mutation_allowlist' => ['transition_wait_candles', 'high_volatility_risk_multiplier'],
                'protected_invariants' => ['transition_firewall_enabled' => true],
                'disagreement_action' => 'WAIT',
                'unknown_state_action' => 'WAIT',
            ],
            'temporal_survival_drift_abstention_specialist' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'temporal_survival|drift_abstention',
                'mutation_allowlist' => [
                    'signal_max_age_candles', 'signal_decay_half_life_candles',
                    'temporal_followthrough_window', 'temporal_followthrough_min_rate',
                    'temporal_followthrough_atr_fraction',
                    'temporal_volatility_ratio_max', 'temporal_spread_atr_ratio_max',
                    'temporal_drift_zscore_max', 'temporal_loss_streak_limit',
                ],
                'protected_invariants' => [
                    'temporal_survival_enabled' => true,
                    'adaptive_signal_expiry_enabled' => true,
                    'drift_abstention_enabled' => true,
                ],
                'unknown_state_action' => 'WAIT',
                'research_rule' => 'Test signal expiry and state drift abstention on independent evidence; no calendar selector, rescue reset or promotion credit is allowed.',
            ],
            'edge_quality_specialist' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'profit_factor|entry_quality',
                'mutation_allowlist' => [
                    'minimum_signal_confidence', 'minimum_confidence',
                    'trend_strength_min', 'trend_up_strength_min',
                    'trend_down_strength_min', 'differential_target_min_signal_confidence',
                    'lookback', 'trend_ema_period', 'trend_roc_period',
                ],
                'protected_invariants' => [],
                'unknown_state_action' => 'WAIT',
                'research_rule' => 'Improve entry quality with one bounded confidence or signal-quality gene; preserve execution cost and risk gates.',
            ],
            'cost_stability_specialist' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'stress_cost|exit_topology',
                'mutation_allowlist' => [
                    'atr_stop_multiplier', 'atr_target_multiplier',
                    'trailing_atr_multiplier', 'time_stop_candles',
                    'partial_take_profit_fraction', 'partial_target_atr_multiplier',
                    'max_spread_atr_ratio', 'high_volatility_risk_multiplier',
                    'avoid_high_volatility',
                ],
                'protected_invariants' => [],
                'unknown_state_action' => 'WAIT',
                'research_rule' => 'Improve net-of-cost behavior with one bounded exit or cost gene; entry logic remains frozen.',
            ],
            'temporal_stability_specialist' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'temporal_stability|monthly_survival',
                'mutation_allowlist' => [
                    'session_filter_enabled', 'session_start', 'session_end',
                    'lookback', 'trend_ema_period', 'trend_roc_period',
                    'transition_firewall_enabled', 'transition_wait_candles',
                    'max_loss_streak_before_wait', 'loss_cooldown_candles',
                    'loss_streak_wait_candles', 'weak_regime_wait_candles',
                    'time_stop_candles',
                ],
                'protected_invariants' => [],
                'unknown_state_action' => 'WAIT',
                'research_rule' => 'Improve temporal stability with one bounded timing or persistence gene; regime and cost gates remain unchanged.',
            ],
            // Targeted failure-profile cohorts use observable gate failures
            // as role names. Keep those roles inside the same one-gene
            // constructor contract as the specialist council; an unknown
            // role used to restore every proposed change and silently shrink
            // a planned 20-seat rescue to only the few control-only seats
            // that happened to match a generic G98 target.
            'pf_stress_specialist' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'profit_factor|stress_cost',
                'mutation_allowlist' => [
                    'atr_stop_multiplier', 'atr_target_multiplier',
                    'trailing_atr_multiplier', 'time_stop_candles',
                    'partial_take_profit_fraction', 'max_spread_atr_ratio',
                    'high_volatility_risk_multiplier', 'avoid_high_volatility',
                ],
                'protected_invariants' => [],
                'unknown_state_action' => 'WAIT',
                'research_rule' => 'Repair PF/stress cost through one bounded cost or exit gene; keep all non-target lanes frozen.',
            ],
            'temporal_calendar_specialist' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'temporal_stability|calendar_survival',
                'mutation_allowlist' => [
                    'minimum_signal_confidence', 'transition_firewall_enabled',
                    'transition_wait_candles', 'dynamic_cooldown_enabled',
                    'max_loss_streak_before_wait', 'loss_cooldown_candles',
                    'loss_streak_wait_candles',
                    'weak_regime_wait_candles', 'time_stop_candles',
                ],
                'protected_invariants' => [],
                'unknown_state_action' => 'WAIT',
                'research_rule' => 'Repair temporal/calendar stability through one bounded timing or persistence gene; calendar labels remain diagnostic only.',
            ],
            'temporal_survival_drift_abstention_specialist' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'temporal_stability|drift_abstention',
                'mutation_allowlist' => [
                    'signal_max_age_candles', 'signal_decay_half_life_candles',
                    'temporal_drift_zscore_max', 'temporal_survival_enabled',
                    'adaptive_signal_expiry_enabled', 'drift_abstention_enabled',
                ],
                'protected_invariants' => [],
                'unknown_state_action' => 'WAIT',
                'research_rule' => 'Probe signal survival and drift abstention with one executable temporal gene; independent windows remain mandatory.',
            ],
            'robustness_split_specialist' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'train_forward_robustness|calibration',
                'mutation_allowlist' => [
                    'confidence_calibration_min_samples', 'weak_regime_min_samples',
                    'meta_label_min_history', 'cooldown_shadow_min_samples',
                    'confidence_calibration_enabled', 'meta_label_enabled',
                    'weak_regime_wait_candles', 'loss_streak_wait_candles',
                ],
                'protected_invariants' => [
                    'train_forward_split' => 'immutable',
                    'evaluation_contract' => 'immutable',
                ],
                'unknown_state_action' => 'WAIT',
                'research_rule' => 'Repair train/forward robustness through one bounded calibration or abstention gene; use the identical immutable split and execution contract.',
            ],
            'regime_coverage_specialist' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'regime_coverage',
                'mutation_allowlist' => [
                    'trend_strength_min', 'lookback',
                    'high_volatility_risk_multiplier', 'minimum_signal_confidence',
                    'minimum_confidence', 'trend_up_strength_min',
                    'trend_down_strength_min', 'range_deviation',
                    'trend_up_roc_period', 'trend_down_roc_period',
                ],
                'protected_invariants' => [],
                'unknown_state_action' => 'WAIT',
                'research_rule' => 'Repair regime coverage through one bounded regime sensitivity gene; no regime gate is relaxed.',
            ],
            'volume_m15_specialist' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'regime_coverage|volume_shadow',
                'mutation_allowlist' => [
                    'volume_lane', 'max_spread_atr_ratio',
                    'high_volatility_risk_multiplier', 'session_filter_enabled',
                    'session_start', 'session_end',
                ],
                'protected_invariants' => [],
                'unknown_state_action' => 'WAIT',
                'research_rule' => 'Test one bounded volume/M15 routing mode on the same snapshot; no coverage, trade-count or promotion gate is relaxed.',
            ],
            'bounded_random_adversarial' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'bounded_random_adversarial|schema_surface',
                // Randomness is bounded by the fixed seed and schema range;
                // the role still needs an executable owner allowlist so the
                // final council policy does not erase its one-gene probe.
                'mutation_allowlist' => [
                    'volume_lane', 'trend_weight', 'breakout_weight',
                    'mean_reversion_weight', 'minimum_confidence',
                    'differential_target_min_signal_confidence',
                    'trend_up_strength_min', 'trend_down_strength_min',
                    'range_lookback', 'range_deviation',
                    'high_volatility_risk_multiplier', 'max_spread_atr_ratio',
                ],
                'protected_invariants' => [],
                'unknown_state_action' => 'WAIT',
                'research_rule' => 'Probe one fixed-seed schema-bounded coordinate; no promotion, parent or gate bypass is allowed.',
            ],
            'non_target_regression_specialist' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'drawdown_risk|non_target_regression',
                'mutation_allowlist' => [
                    'high_volatility_risk_multiplier', 'max_loss_streak_before_wait',
                    'loss_cooldown_candles', 'atr_stop_multiplier',
                    'time_stop_candles', 'partial_target_atr_multiplier',
                    'partial_take_profit_fraction', 'avoid_high_volatility',
                ],
                'protected_invariants' => [],
                'unknown_state_action' => 'WAIT',
                'research_rule' => 'Protect non-target behavior through one bounded risk/exit gene; the target gate remains unchanged.',
            ],
            'architecture_control_specialist' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'architecture_control',
                'mutation_allowlist' => [
                    'lookback', 'session_start', 'session_end',
                    'trend_strength_min', 'minimum_signal_confidence',
                    'range_signal_mode', 'range_deviation', 'range_adx_max',
                    'entry_topology_variant',
                ],
                'protected_invariants' => [],
                'unknown_state_action' => 'WAIT',
                'research_rule' => 'Test architecture behavior through one bounded topology-control gene; family and execution contract remain frozen.',
            ],
            default => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'mutation_allowlist' => [],
                'protected_invariants' => [],
                'unknown_state_action' => 'WAIT',
            ],
        };
    }

    /**
     * Keep a role child from resurrecting a quarantined mutation direction.
     * If the first proposal is blocked, the role receives a different,
     * still one-gene bounded probe.  If its lane is exhausted, the unchanged
     * gates see a no-change/retest candidate rather than a disguised repeat.
     */
    private function enforceCouncilRolePolicy(
        string $role,
        string $family,
        array $base,
        array $parameters,
        int $slot,
        array $blockedDirections,
        array $blockedKeys = [],
    ): array {
        $spec = $this->councilRolePolicySpec($role);
        $allowed = array_values((array) data_get($spec, 'mutation_allowlist', []));
        if ($role === 'temporal_survival_drift_abstention_specialist') {
            // The rescue-blocked shadow lane has three seats. Give them
            // deterministic, distinct probes so they do not collapse into
            // the first numeric gene in the allowlist. This is still one
            // bounded gene per child; the unchanged gates judge all results.
            $preferred = match ($slot % 3) {
                2 => 'signal_max_age_candles',
                0 => 'signal_decay_half_life_candles',
                default => 'temporal_drift_zscore_max',
            };
            $allowed = array_values(array_unique([$preferred, ...$allowed]));
        }
        foreach ($this->councilRoleBaseline($role, $family) as $key => $value) {
            if (array_key_exists($key, $base)) {
                $parameters[$key] = $value;
            }
        }

        // Historical novelty and generic curriculum fallbacks are allowed to
        // propose a gene outside the role envelope. Restore those proposals
        // before looking for a replacement; otherwise a range child can spend
        // a trend gene simply because every known range direction is blocked.
        foreach (array_keys($this->diff($base, $parameters)) as $changedKey) {
            if (! in_array((string) $changedKey, $allowed, true)
                || in_array((string) $changedKey, $blockedKeys, true)) {
                if (array_key_exists($changedKey, $base)) {
                    $parameters[$changedKey] = $base[$changedKey];
                } else {
                    unset($parameters[$changedKey]);
                }
            }
        }

        $diff = $this->diff($base, $parameters);
        if (count($diff) === 1) {
            $changedKey = (string) array_key_first($diff);
            $changedValue = data_get($diff, $changedKey.'.new');
            if (! $this->mutationDirectionBlocked($changedKey, $changedValue, $blockedDirections)) {
                return $parameters;
            }
            if (array_key_exists($changedKey, $base)) {
                $parameters[$changedKey] = $base[$changedKey];
            }
        }

        $fallback = $this->councilRoleMutationCandidate($role, $family, $base, $blockedKeys, $blockedDirections);
        $fallbackDiff = $this->diff($base, $fallback);
        if (count($fallbackDiff) === 1) {
            return $fallback;
        }

        // No legal owner mutation remains. Keep a truthful no-change control;
        // draft validation may admit it as evidence, while the passport hard
        // gate still prevents it from becoming a specialist or paper member.
        return $base;
    }

    /**
     * Find a fresh one-gene mutation inside the role allowlist. This is the
     * last bounded search before a no-change control, never a global fallback.
     * It prevents harmful-direction memory from leaking into another role.
     */
    private function councilRoleMutationCandidate(
        string $role,
        string $family,
        array $base,
        array $blockedKeys = [],
        array $blockedDirections = [],
        array $excludedKeys = [],
    ): array {
        $spec = $this->councilRolePolicySpec($role);
        $allowed = array_values((array) data_get($spec, 'mutation_allowlist', []));
        $protected = (array) data_get($spec, 'protected_invariants', []);
        $schema = $this->schemas->schema($family);

        foreach ($allowed as $key) {
            if (in_array($key, $blockedKeys, true)
                || in_array($key, $excludedKeys, true)
                || ! array_key_exists($key, $base)
                || ! isset($schema[$key])) {
                continue;
            }

            [$type, $min, $max] = array_pad($schema[$key], 3, null);
            $current = $base[$key];
            $candidates = [];

            if ($type === 'string') {
                foreach ((array) $min as $value) {
                    if ($value !== $current) {
                        $candidates[] = $value;
                    }
                }
            } elseif ($type === 'boolean') {
                $candidate = ! (bool) $current;
                if (! array_key_exists($key, $protected) || $candidate === $protected[$key]) {
                    $candidates[] = $candidate;
                }
            } elseif (in_array($type, ['integer', 'numeric'], true) && is_numeric($min) && is_numeric($max)) {
                $span = (float) $max - (float) $min;
                $step = $type === 'integer' ? 1.0 : max(.0001, $span * .05);
                for ($multiplier = 1; $multiplier <= 8; $multiplier++) {
                    foreach ([1, -1] as $direction) {
                        $value = (float) $current + ($direction * $step * $multiplier);
                        $value = max((float) $min, min((float) $max, $value));
                        $candidates[] = $type === 'integer' ? (int) round($value) : round($value, 4);
                    }
                }
            }

            foreach (array_values(array_unique($candidates, SORT_REGULAR)) as $candidate) {
                if (array_key_exists($key, $protected) && $candidate !== $protected[$key]) {
                    continue;
                }
                if (! $this->valuesDiffer($current, $candidate)
                    || $this->mutationDirectionBlocked($key, $candidate, $blockedDirections)) {
                    continue;
                }
                $child = $base;
                $child[$key] = $candidate;
                $diff = $this->diff($base, $child);
                if (count($diff) === 1) {
                    return $child;
                }
            }
        }

        return $base;
    }

    /** @param array<int, array<string, mixed>> $blockedDirections */
    private function transitionCouncilMutation(array $parameters, array $schema, array $blockedDirections): array
    {
        if (array_key_exists('transition_wait_candles', $parameters)
            && isset($schema['transition_wait_candles'])) {
            [, $min, $max] = array_pad($schema['transition_wait_candles'], 3, null);
            $current = (int) $parameters['transition_wait_candles'];
            for ($step = 1; $step <= 6; $step++) {
                foreach ([$current + $step, $current - $step] as $candidate) {
                    $candidate = max((int) $min, min((int) $max, $candidate));
                    if ($candidate === $current || $this->mutationDirectionBlocked('transition_wait_candles', $candidate, $blockedDirections)) {
                        continue;
                    }
                    $parameters['transition_wait_candles'] = $candidate;

                    return $parameters;
                }
            }
        }

        if (array_key_exists('high_volatility_risk_multiplier', $parameters)
            && isset($schema['high_volatility_risk_multiplier'])) {
            [, $min, $max] = array_pad($schema['high_volatility_risk_multiplier'], 3, null);
            $current = (float) $parameters['high_volatility_risk_multiplier'];
            $step = max(.0001, ((float) $max - (float) $min) * .05);
            for ($multiplier = 1; $multiplier <= 8; $multiplier++) {
                foreach ([-1, 1] as $direction) {
                    $candidate = round(max((float) $min, min((float) $max, $current + ($direction * $step * $multiplier))), 4);
                    if ($candidate === $current || $this->mutationDirectionBlocked('high_volatility_risk_multiplier', $candidate, $blockedDirections)) {
                        continue;
                    }
                    $parameters['high_volatility_risk_multiplier'] = $candidate;

                    return $parameters;
                }
            }
        }

        return $parameters;
    }

    /** @param array<int, array<string, mixed>> $blockedDirections */
    private function trendDownCouncilRecallMutation(array $parameters, array $schema, array $blockedDirections): array
    {
        if (array_key_exists('transition_wait_candles', $parameters) && isset($schema['transition_wait_candles'])) {
            $current = (int) $parameters['transition_wait_candles'];
            $candidate = max(1, $current - 1);
            if ($candidate !== $current && ! $this->mutationDirectionBlocked('transition_wait_candles', $candidate, $blockedDirections)) {
                $parameters['transition_wait_candles'] = $candidate;

                return $parameters;
            }
        }
        if (array_key_exists('loss_cooldown_candles', $parameters) && isset($schema['loss_cooldown_candles'])) {
            $current = (int) $parameters['loss_cooldown_candles'];
            $candidate = max(1, $current - 1);
            if ($candidate !== $current && ! $this->mutationDirectionBlocked('loss_cooldown_candles', $candidate, $blockedDirections)) {
                $parameters['loss_cooldown_candles'] = $candidate;

                return $parameters;
            }
        }
        if (array_key_exists('confidence_ev_lower_bound_enabled', $parameters)
            && isset($schema['confidence_ev_lower_bound_enabled'])) {
            $candidate = ! (bool) $parameters['confidence_ev_lower_bound_enabled'];
            if (! $this->mutationDirectionBlocked('confidence_ev_lower_bound_enabled', $candidate, $blockedDirections)) {
                $parameters['confidence_ev_lower_bound_enabled'] = $candidate;

                return $parameters;
            }
        }
        if (array_key_exists('differential_target_min_signal_confidence', $parameters)
            && isset($schema['differential_target_min_signal_confidence'])) {
            $current = (float) $parameters['differential_target_min_signal_confidence'];
            $candidate = round(max(0.0, $current - .05), 4);
            if ($candidate !== $current && ! $this->mutationDirectionBlocked('differential_target_min_signal_confidence', $candidate, $blockedDirections)) {
                $parameters['differential_target_min_signal_confidence'] = $candidate;

                return $parameters;
            }
        }

        return $parameters;
    }

    /** @param array<int, array<string, mixed>> $blockedDirections */
    private function rangeCouncilRoleMutation(array $parameters, array $blockedDirections): array
    {
        $mode = (string) ($parameters['range_signal_mode'] ?? 'reentry');
        foreach (['mean_reversion', 'mid_cross', 'inverse_extreme', 'reentry'] as $candidate) {
            if ($candidate === $mode || $this->mutationDirectionBlocked('range_signal_mode', $candidate, $blockedDirections)) {
                continue;
            }
            $parameters['range_signal_mode'] = $candidate;

            return $parameters;
        }

        if (array_key_exists('range_deviation', $parameters)) {
            $current = (float) $parameters['range_deviation'];
            foreach ([
                round($current + .2, 4), round($current - .2, 4),
                round($current + .4, 4), round($current - .4, 4),
                round($current + .6, 4), round($current - .6, 4),
            ] as $candidate) {
                if ($candidate === $current || $this->mutationDirectionBlocked('range_deviation', $candidate, $blockedDirections)) {
                    continue;
                }
                $parameters['range_deviation'] = max(.5, min(4.0, $candidate));

                return $parameters;
            }
        }

        if (array_key_exists('range_adx_max', $parameters)) {
            $current = (float) $parameters['range_adx_max'];
            foreach ([
                round($current + 2, 4), round($current - 2, 4),
                round($current + 4, 4), round($current - 4, 4),
            ] as $candidate) {
                if ($candidate === $current || $this->mutationDirectionBlocked('range_adx_max', $candidate, $blockedDirections)) {
                    continue;
                }
                $parameters['range_adx_max'] = max(5, min(35, $candidate));

                return $parameters;
            }
        }

        return $parameters;
    }

    /** @param array<int, array<string, mixed>> $blockedDirections */
    private function mutationDirectionBlocked(string $key, mixed $value, array $blockedDirections): bool
    {
        $encoded = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES);
        foreach ($blockedDirections as $direction) {
            if ((string) data_get($direction, 'parameter_key') !== $key) {
                continue;
            }
            if (json_encode(data_get($direction, 'new_value'), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES) === $encoded) {
                return true;
            }
        }

        return false;
    }

    private function councilRolePolicyContract(
        string $role,
        string $family,
        array $base,
        array $parameters,
        array $blockedDirections,
    ): array {
        $spec = $this->councilRolePolicySpec($role);
        $diff = $this->diff($base, $parameters);
        $changedKey = array_key_first($diff);
        $spec['family'] = $family;
        $spec['changed_gene'] = $changedKey;
        $spec['changed_value'] = $changedKey !== null ? data_get($diff, $changedKey.'.new') : null;
        $spec['blocked_direction_firewall'] = [
            'protocol' => 'mutation_direction_firewall_v1',
            'blocked_direction_count' => count($blockedDirections),
            'applied' => true,
            'promotion_evidence' => false,
        ];
        $spec['promotion_evidence'] = false;

        return $spec;
    }
}
