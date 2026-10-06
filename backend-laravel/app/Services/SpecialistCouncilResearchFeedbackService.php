<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentReceipt;
use App\Models\ResearchExperimentWorkItem;
use App\Models\SpecialistCouncilVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/** Converts original council comparisons to scoped research knowledge, never authority. */
class SpecialistCouncilResearchFeedbackService
{
    public const PROTOCOL = 'specialist_council_research_feedback_v1';
    public const OBSERVED_SOURCE_SNAPSHOT_PROTOCOL = 'specialist_council_observed_execution_snapshot_v1';

    public const FOLLOWUP_PROTOCOL = 'specialist_council_followup_resolution_v1';

    public const SOURCE_AMENDMENT_PROTOCOL = 'specialist_council_unobserved_source_amendment_v1';

    public const FOLLOWUP_TYPES = ['specialist_council_technical_repair', 'specialist_council_data_repair',
        'specialist_council_power_extension', 'specialist_council_independent_validation', 'specialist_council_descendant_transfer'];

    private const DISCOVERY_FOLLOWUPS = ['specialist_council_technical_repair', 'specialist_council_data_repair',
        'specialist_council_power_extension'];

    public function __construct(
        private ResearchExperimentConversionKernelService $conversion,
        private ResearchPaperEpochContractService $epochs,
        private SpecialistCouncilContractService $contracts,
    ) {}

    /** Called inside original evaluation publication. Redelivery reuses the same receipt/work. */
    public function recordAssessment(SpecialistCouncilVersion $version): array
    {
        $version = $version->fresh() ?? $version;
        $assessment = (array) $version->assessment;
        $exam = DB::table('specialist_council_evaluations')->where('specialist_council_version_id', $version->id)->first();
        $planRow = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $version->id)->first();
        $plan = $planRow ? json_decode($planRow->plan, true, 512, JSON_THROW_ON_ERROR) : [];
        if (! in_array($version->state, ['evaluated', 'approved', 'scheduled', 'active', 'retired', 'rolled_back'], true)
            || ! $exam || ! $planRow || ! $this->contracts->manifestValid($version->manifest)
            || $this->epochs->parameterHash($assessment) !== $version->assessment_hash
            || $exam->assessment_hash !== $version->assessment_hash
            || $this->epochs->parameterHash(json_decode($exam->assessment, true, 512, JSON_THROW_ON_ERROR)) !== $exam->assessment_hash
            || $this->epochs->parameterHash($plan) !== $planRow->plan_hash
            || ($assessment['manifest_hash'] ?? '') !== $version->manifest_hash
            || ($assessment['plan_hash'] ?? '') !== $planRow->plan_hash
            || ($assessment['version_id'] ?? null) !== $version->id
            || ($assessment['protocol'] ?? '') !== SpecialistCouncilLifecycleService::ASSESSMENT_PROTOCOL
            || json_decode($exam->original_run_ids, true, 512, JSON_THROW_ON_ERROR) !== ($assessment['original_run_ids'] ?? null)
            || $exam->evaluator_id !== $planRow->evaluator_id || $exam->evaluator_id === $version->creator_id) {
            throw new LogicException('COUNCIL_RESEARCH_FEEDBACK_ORIGINAL_ASSESSMENT_INVALID');
        }
        foreach ((array) ($assessment['original_sources'] ?? []) as $source) {
            $run = LabEvaluationRun::where('run_id', $source['run_id'] ?? '')->first();
            if (! $run || $run->status !== 'completed' || ! $run->finished_at
                || ! in_array($run->run_id, $assessment['original_run_ids'], true)) {
                throw new LogicException('COUNCIL_RESEARCH_FEEDBACK_ORIGINAL_RUN_MISSING');
            }
            foreach (['request_hash', 'response_hash', 'data_hash', 'parameter_hash', 'code_hash'] as $hash) {
                if (! is_string($source[$hash] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $source[$hash])
                    || ! hash_equals((string) $run->{$hash}, $source[$hash])) {
                    throw new LogicException('COUNCIL_RESEARCH_FEEDBACK_ORIGINAL_RUN_HASH_CHANGED');
                }
            }
        }
        foreach ($plan['windows'] as $window) {
            $start = CarbonImmutable::parse($window['start_inclusive'])->utc();
            $end = CarbonImmutable::parse($window['end_exclusive'])->utc();
            if (! $end->greaterThan($start) || $end->greaterThan(now()->utc())
                || ! $this->epochs->researchIntervalDisjointFromPaper($start->toIso8601String(), $end->toIso8601String())) {
                throw new LogicException('COUNCIL_RESEARCH_FEEDBACK_UNAVAILABLE_OR_PAPER_EVENTS');
            }
        }

        $status = (string) ($assessment['research_observation_status'] ?? 'technical_unassessable');
        [$classification, $next, $terminal] = $this->closure($version, $assessment, $status);
        $symbols = array_values(array_unique(array_merge(...array_map(fn (array $member): array =>
            (array) ($member['scope']['symbols'] ?? []), $version->manifest['members']))));
        if (count($symbols) !== 1) throw new LogicException('COUNCIL_RESEARCH_FEEDBACK_REQUIRES_ONE_CANONICAL_MARKET');
        $carrier = LabAgent::whereIn('model_version_id', array_column($plan['arms'], 'model_version_id'))->orderBy('id')->first();
        $contract = [
            'contract_version' => ResearchExperimentConversionKernelService::CONTRACT_VERSION,
            'source' => ['type' => SpecialistCouncilVersion::class, 'id' => $version->id],
            'scope' => ['symbol' => $symbols[0], 'laboratory_timeframe' => $carrier?->timeframe ?? 'H1',
                'execution_timeframe' => $plan['execution_timeframe'], 'council_id' => $version->council_id,
                'council_version' => $version->version, 'manifest_hash' => $version->manifest_hash,
                'contexts' => array_map(fn (array $member): array => ['specialist_id' => $member['specialist_id'],
                    'role' => $member['role'], 'scope' => $member['scope']], $version->manifest['members'])],
            'claim' => ['target_stage' => 'shared_account_research_comparison',
                'hypothesis' => $plan['objective'], 'minimum_meaningful_effect' => [
                    'minimum_paired_trades' => $version->manifest['evaluation_policy']['minimum_paired_trades'],
                    'external_risk_limits_unchanged' => true], 'scope_local_only' => true,
                'individual_component_causal_effect_proven' => false, 'global_harmful_ban' => false],
            'identity' => ['baseline_epoch_hash' => $version->manifest_hash,
                'data_and_mtf_hash' => $this->epochs->parameterHash($plan['windows']),
                'runtime_and_contract_hash' => $plan['execution_hash'], 'intervention_hash' => $version->manifest_hash,
                'window_plan_hash' => $planRow->plan_hash, 'evaluator_version' => self::PROTOCOL,
                'research_question_fingerprint' => $this->questionFingerprint($version->manifest, $plan)],
            'arms' => array_values(array_map(fn (array $arm): array => ['role' => $arm['kind'],
                'model_version_id' => $arm['model_version_id'], 'model_hash' => $arm['model_hash'],
                'window_key' => $arm['window_key'], 'removed_id' => $arm['removed_id'] ?? null], $plan['arms'])),
            'revisions' => ['subject' => 1, 'evidence' => (int) $exam->id],
        ];
        $evidence = ['protocol' => self::PROTOCOL, 'assessment_id' => (int) $exam->id,
            'assessment_hash' => $version->assessment_hash, 'plan_hash' => $planRow->plan_hash,
            'original_run_ids' => $assessment['original_run_ids'], 'original_sources' => $assessment['original_sources'],
            'research_observation_status' => $status, 'comparisons' => $assessment['comparisons'],
            'reason_codes' => $assessment['reason_codes'], 'economic_direction' => $this->economicDirection($assessment),
            'original_independent_assessment_qualified' => $plan['purpose'] === 'independent' && ($assessment['qualified'] ?? false),
            'qualified' => false, 'confirmed_skill_credit' => false, 'promotion_evidence' => false];
        $receipt = $this->conversion->record($contract, $evidence, $classification, $next, $terminal);
        if (($receipt['status'] ?? '') !== 'recorded') throw new LogicException('COUNCIL_RESEARCH_FEEDBACK_NOT_PUBLISHED:'.($receipt['reason'] ?? 'UNKNOWN'));
        return ['protocol' => self::PROTOCOL, ...$receipt, 'economic_direction' => $evidence['economic_direction'],
            'knowledge_authority' => 'research_only', 'global_harmful_ban' => false, 'promotion_evidence' => false];
    }

    /**
     * Seal one prospective question, never a caller-supplied runnable flag. Fresh
     * model IDs are deliberately absent: the canonical constructor must create
     * and attest those exact projected vectors before actual preparation.
     */
    public function registerFollowupProof(int $workItemId, array $proposed, ?string $actor = null): array
    {
        return DB::transaction(function () use ($workItemId, $proposed, $actor): array {
            $work = ResearchExperimentWorkItem::whereKey($workItemId)->lockForUpdate()->firstOrFail();
            if (! in_array($work->status, ['blocked', 'ready'], true) || $work->completed_at !== null
                || ! empty($work->result)) throw new LogicException('COUNCIL_FOLLOWUP_ALREADY_LEASED_OR_OBSERVED');
            $inputHash = $this->epochs->parameterHash($proposed);
            $prior = data_get($work->payload, 'followup_resolution');
            if ($prior !== null) {
                if (! is_array($prior) || ($prior['input_hash'] ?? null) !== $inputHash) {
                    throw new LogicException('COUNCIL_FOLLOWUP_PREREGISTRATION_ALREADY_SEALED');
                }
                return $this->inspectFollowupReadiness($work);
            }
            [$receipt, $version, $original] = $this->followupOriginal($work);
            if (! in_array($work->work_type, self::DISCOVERY_FOLLOWUPS, true)) {
                return app(SpecialistCouncilIndependentPanelService::class)->register($work, $proposed, $actor, $version, $original);
            }
            if (($proposed['protocol'] ?? null) !== self::FOLLOWUP_PROTOCOL
                || array_diff(array_keys($proposed), ['protocol', 'research_question', 'creator_id', 'evaluator_id',
                    'native_source_model_ids', 'parameter_deltas', 'discovery_bundle_manifest', 'evaluation_plan', 'continuation_kind']) !== []
                || ! is_string($proposed['research_question'] ?? null) || trim($proposed['research_question']) === ''
                || strlen($proposed['research_question']) > 500 || trim($proposed['research_question']) !== $proposed['research_question']
                || ! is_string($proposed['creator_id'] ?? null) || trim($proposed['creator_id']) === ''
                || ! preg_match('/^[A-Za-z0-9_.:-]{1,120}$/D', $proposed['creator_id'])
                || ! is_string($proposed['evaluator_id'] ?? null) || trim($proposed['evaluator_id']) === ''
                || ! preg_match('/^[A-Za-z0-9_.:-]{1,120}$/D', $proposed['evaluator_id'])
                || $proposed['creator_id'] === $proposed['evaluator_id']) {
                throw new LogicException('COUNCIL_FOLLOWUP_PROSPECTIVE_CONTRACT_INVALID');
            }
            $kind = $proposed['continuation_kind'] ?? ($work->work_type === 'specialist_council_technical_repair'
                ? 'same_question_source_repair' : 'new_discovery');
            if (! in_array($kind, ['same_question_source_repair', 'new_discovery'], true)
                || ($kind === 'same_question_source_repair' && $work->work_type !== 'specialist_council_technical_repair')) {
                throw new LogicException('COUNCIL_FOLLOWUP_SCIENTIFIC_PURPOSE_INVALID');
            }
            // An unobserved repair and an observed auxiliary-source copy are
            // distinct proofs. Neither repairs the old model or preparation.
            $unobservedProof = $kind === 'same_question_source_repair'
                ? $this->unobservedTechnicalProof($receipt, $version, $original) : null;
            $observedProof = $kind === 'new_discovery' && $work->work_type === 'specialist_council_technical_repair'
                ? $this->observedAuxiliarySourceProof($receipt, $version, $original) : null;
            if ($observedProof !== null && (array) ($proposed['parameter_deltas'] ?? []) !== []) {
                throw new LogicException('COUNCIL_OBSERVED_SOURCE_COPY_FORBIDS_RETUNING');
            }
            $specs = $this->projectNativeSources($version, (array) ($proposed['native_source_model_ids'] ?? []),
                (array) ($proposed['parameter_deltas'] ?? []), (array) ($unobservedProof['descriptor_materializations']
                    ?? $observedProof['descriptor_materializations'] ?? []));
            $plan = (array) ($proposed['evaluation_plan'] ?? []);
            $bundle = (array) ($proposed['discovery_bundle_manifest'] ?? []);
            $this->assertFollowupDesign($original, $plan, $version);
            app(SpecialistCouncilPreparationService::class)->assertProspectiveDiscoveryPlan($plan, $bundle);
            $currentSource = app(LabImmutableEvidenceService::class)->codeHash();
            $currentPython = app(ResearchReleaseSealService::class)->pythonHash();
            if (! preg_match('/^[a-f0-9]{64}$/D', $currentSource) || ! preg_match('/^[a-f0-9]{64}$/D', $currentPython)) {
                throw new LogicException('COUNCIL_FOLLOWUP_CURRENT_SOURCE_UNVERIFIABLE');
            }
            if ($observedProof !== null && $currentSource === ($original['preparation_source_hash'] ?? null)) {
                throw new LogicException('COUNCIL_OBSERVED_SOURCE_COMPLETION_REQUIRES_CHANGED_SOURCE');
            }
            $template = array_diff_key($version->manifest, array_flip(['manifest_hash', 'epoch_contract', 'promotion_evidence']));
            foreach ($template['members'] as &$member) {
                $role = $member['role'];
                $member = array_diff_key($member, array_flip(['passport_hash', 'source_model_hash', 'qualified', 'qualified_evidence']));
                $member['parameters'] = $specs[$role]['parameters'];
                $member['qualified_evidence'] = [];
            }
            unset($member);
            if ($kind === 'same_question_source_repair') {
                $changed = array_filter($specs, fn (array $spec): bool => $spec['parameter_deltas'] !== []);
                $originalSource = $original['preparation_source_hash'] ?? data_get($receipt->payload, 'evidence.original_sources.0.code_hash');
                if ($changed !== [] || ! is_string($originalSource) || $originalSource === $currentSource
                    || data_get($receipt->payload, 'evidence.original_sources', []) !== []
                    || $this->questionFingerprint($template, $plan) !== $this->questionFingerprint($version->manifest, $original)) {
                    throw new LogicException('COUNCIL_TECHNICAL_REPAIR_REQUIRES_SAME_QUESTION_AND_CHANGED_SOURCE');
                }
            }
            if ($work->work_type === 'specialist_council_power_extension') {
                $before = array_values($original['windows']); $after = array_values($plan['windows']);
                if (array_map(fn (array $window): array => [$window['start_inclusive'], $window['end_exclusive']], $before)
                    === array_map(fn (array $window): array => [$window['start_inclusive'], $window['end_exclusive']], $after)) {
                    throw new LogicException('COUNCIL_POWER_EXTENSION_REQUIRES_NEW_PROSPECTIVE_EVENT_SCOPE');
                }
            }
            $samePhysicalQuestion = $this->questionFingerprint($template, $plan) === $this->questionFingerprint($version->manifest, $original);
            if ($kind === 'new_discovery' && $work->work_type === 'specialist_council_technical_repair' && $observedProof === null
                && $samePhysicalQuestion) {
                throw new LogicException('COUNCIL_FOLLOWUP_NEW_DISCOVERY_REQUIRES_NEW_PHYSICAL_QUESTION');
            }
            if ($work->work_type === 'specialist_council_data_repair'
                && array_column(array_values($original['windows']), 'dataset_sha256')
                    === array_column(array_values($plan['windows']), 'dataset_sha256')) {
                throw new LogicException('COUNCIL_DATA_REPAIR_REQUIRES_CHANGED_VERIFIED_DATA');
            }
            $body = ['protocol' => self::FOLLOWUP_PROTOCOL, 'work_item_id' => $work->id, 'work_key' => $work->work_key,
                'source_receipt_id' => $receipt->id, 'source_receipt_key' => $receipt->receipt_key,
                'source_contract_hash' => $receipt->contract_hash, 'source_evidence_hash' => $receipt->evidence_hash,
                'source_version_id' => $version->id, 'source_manifest_hash' => $version->manifest_hash,
                'source_assessment_hash' => $version->assessment_hash, 'source_plan_hash' => data_get($receipt->payload, 'evidence.plan_hash'),
                'work_type' => $work->work_type, 'input_hash' => $inputHash, 'native_source_models' => $specs,
                'manifest_template' => $template, 'evaluation_plan' => $plan, 'discovery_bundle_manifest' => $bundle,
                'discovery_manifest_hash' => $this->epochs->parameterHash($bundle),
                'current_source_hash' => $currentSource, 'current_python_source_hash' => $currentPython,
                'creator_id' => $proposed['creator_id'], 'evaluator_id' => $proposed['evaluator_id'],
                'research_question' => $proposed['research_question'], 'registered_at' => now()->utc()->toIso8601String(),
                'registered_by' => $actor === null ? $proposed['creator_id'] : trim($actor),
                'prospective_question_fingerprint' => $this->questionFingerprint($template, $plan),
                'scientific_question_kind' => $kind === 'same_question_source_repair'
                    ? 'unobserved_same_question_source_repair' : ($observedProof !== null
                        ? 'source_repair_completion_after_observed_auxiliary_source' : 'new_prospective_discovery_informed_by_original_observation'),
                'original_unobserved_technical_proof' => $unobservedProof,
                'original_observed_source_proof' => $observedProof,
                'same_physical_question_acknowledged' => $observedProof !== null && $samePhysicalQuestion,
                'scientific_novelty_claimed' => false,
                'authority' => 'research_only', 'max_experiments' => 1, 'independent_evidence_claimed' => false,
                'fresh_model_attestation_required' => true, 'promotion_evidence' => false];
            if ($body['registered_by'] === '' || strlen($body['registered_by']) > 255) throw new LogicException('COUNCIL_FOLLOWUP_REGISTRAR_REQUIRED');
            $body['resolution_hash'] = $this->epochs->parameterHash($body);
            $body['server_seal'] = $this->followupServerSeal($body);
            $work->update(['payload' => [...(array) $work->payload, 'executable' => false, 'followup_resolution' => $body]]);
            return $this->inspectFollowupReadiness($work->fresh());
        });
    }

    /** Pure readiness, rechecked at claim, construction and execution; no stored flag can grant it. */
    public function inspectFollowupReadiness(ResearchExperimentWorkItem $work): array
    {
        try {
            [$receipt, $version, $original] = $this->followupOriginal($work);
            if (! in_array($work->work_type, self::DISCOVERY_FOLLOWUPS, true)) {
                return app(SpecialistCouncilIndependentPanelService::class)->inspect($work, $version);
            }
            $body = data_get($work->payload, 'followup_resolution');
            if (! is_array($body)) return $this->followupBlocked('COUNCIL_FOLLOWUP_PREREGISTRATION_REQUIRED');
            if (($body['protocol'] ?? null) !== self::FOLLOWUP_PROTOCOL
                || ($body['resolution_hash'] ?? null) !== $this->epochs->parameterHash(array_diff_key($body, ['resolution_hash' => true, 'server_seal' => true]))
                || ! is_string($body['server_seal'] ?? null)
                || ! hash_equals($body['server_seal'], $this->followupServerSeal(array_diff_key($body, ['server_seal' => true])))
                || ($body['work_item_id'] ?? null) !== $work->id || ($body['work_key'] ?? null) !== $work->work_key
                || ($body['source_receipt_id'] ?? null) !== $receipt->id || ($body['source_receipt_key'] ?? null) !== $receipt->receipt_key
                || ($body['source_contract_hash'] ?? null) !== $receipt->contract_hash || ($body['source_evidence_hash'] ?? null) !== $receipt->evidence_hash
                || ($body['source_version_id'] ?? null) !== $version->id || ($body['source_manifest_hash'] ?? null) !== $version->manifest_hash
                || ($body['source_assessment_hash'] ?? null) !== $version->assessment_hash
                || ($body['source_plan_hash'] ?? null) !== data_get($receipt->payload, 'evidence.plan_hash')
                || ($body['authority'] ?? null) !== 'research_only' || ($body['max_experiments'] ?? null) !== 1
                || ($body['independent_evidence_claimed'] ?? null) !== false || ($body['promotion_evidence'] ?? null) !== false
                || ($body['fresh_model_attestation_required'] ?? null) !== true) {
                throw new LogicException('COUNCIL_FOLLOWUP_ORIGINAL_RESOLUTION_DRIFT');
            }
            // Operational source repairs append a separate original proof;
            // the scientific resolution and its original hash never change.
            $sourceBinding = $this->inspectFollowupSourceBinding($work);
            $unobservedProof = ($body['scientific_question_kind'] ?? null) === 'unobserved_same_question_source_repair'
                ? $this->unobservedTechnicalProof($receipt, $version, $original) : null;
            $observedProof = ($body['scientific_question_kind'] ?? null) === 'source_repair_completion_after_observed_auxiliary_source'
                ? $this->observedAuxiliarySourceProof($receipt, $version, $original) : null;
            if ($unobservedProof !== null && $this->epochs->parameterHash($unobservedProof)
                !== $this->epochs->parameterHash($body['original_unobserved_technical_proof'] ?? null)) {
                throw new LogicException('COUNCIL_FOLLOWUP_ORIGINAL_PREEXECUTION_PROOF_CHANGED');
            }
            if (($body['scientific_question_kind'] ?? null) === 'source_repair_completion_after_observed_auxiliary_source'
                && ($observedProof === null || $this->epochs->parameterHash($observedProof)
                    !== $this->epochs->parameterHash($body['original_observed_source_proof'] ?? null)
                    || ($body['same_physical_question_acknowledged'] ?? null) !== ($this->questionFingerprint($body['manifest_template'], $body['evaluation_plan']) === $this->questionFingerprint($version->manifest, $original))
                    || ($body['scientific_novelty_claimed'] ?? null) !== false
                    || ($body['current_source_hash'] ?? null) === ($original['preparation_source_hash'] ?? null))) {
                throw new LogicException('COUNCIL_FOLLOWUP_ORIGINAL_OBSERVED_SOURCE_PROOF_CHANGED');
            }
            foreach ($body['native_source_models'] as $role => $spec) {
                $model = ModelVersion::find($spec['model_version_id']);
                if (! $model || $this->contracts->modelHash($model) !== $spec['model_hash']) {
                    throw new LogicException('COUNCIL_FOLLOWUP_ORIGINAL_NATIVE_MODEL_DRIFT');
                }
            }
            $sourceIds = array_map(fn (array $spec): int => $spec['model_version_id'], $body['native_source_models']);
            $deltas = [];
            foreach ($body['native_source_models'] as $role => $spec) {
                if ($spec['parameter_deltas'] !== []) $deltas[$role] = array_column($spec['parameter_deltas'], 'new', 'gene');
            }
            if ($this->epochs->parameterHash($this->projectNativeSources($version, $sourceIds, $deltas,
                (array) ($unobservedProof['descriptor_materializations'] ?? $observedProof['descriptor_materializations'] ?? [])))
                !== $this->epochs->parameterHash($body['native_source_models'])) {
                throw new LogicException('COUNCIL_FOLLOWUP_PROJECTED_PARAMETER_DELTA_DRIFT');
            }
            $this->assertFollowupDesign($original, $body['evaluation_plan'], $version);
            if ($this->epochs->parameterHash($body['discovery_bundle_manifest']) !== $body['discovery_manifest_hash']) {
                throw new LogicException('COUNCIL_FOLLOWUP_DISCOVERY_BUNDLE_DRIFT');
            }
            app(SpecialistCouncilPreparationService::class)->assertProspectiveDiscoveryPlan($body['evaluation_plan'], $body['discovery_bundle_manifest']);
            if ($work->status === 'settled' || $work->completed_at !== null) return $this->followupBlocked('COUNCIL_FOLLOWUP_ALREADY_COMPLETED');
            $owned = LabGeneration::where('trigger_context->native_specialist_council_intent->followup_work_item_id', $work->id)
                ->orderBy('id')->limit(2)->get();
            if ($owned->count() > 1) return $this->followupBlocked('COUNCIL_FOLLOWUP_MULTIPLE_COHORT_OWNERS');
            $generation = $owned->first();
            if ($generation && (data_get($generation->trigger_context, 'native_specialist_council_intent.followup_resolution_hash') !== $body['resolution_hash']
                || in_array($generation->status, ['failed', 'abandoned'], true)
                || ($generation->status === 'technical_quarantine' && ! LabPopulationService::constructionIncomplete($generation)))) {
                return $this->followupBlocked('COUNCIL_FOLLOWUP_NEEDS_NEW_PREREGISTERED_ATTEMPT');
            }
            if ($generation && $observedProof !== null) {
                $roles = ['source_scalp' => 'scalp', 'source_hour' => 'hour', 'source_day' => 'day', 'source_swing' => 'swing',
                    'candidate_carrier' => 'day', 'ablation_carrier' => 'day'];
                $agents = $generation->agents()->with('modelVersion')->limit(7)->get();
                if ($agents->count() > 6) throw new LogicException('COUNCIL_OBSERVED_SOURCE_CONSTRUCTED_ROSTER_DRIFT');
                foreach ($agents as $agent) {
                    $model = $agent->modelVersion; $role = $roles[data_get($model?->metadata, 'native_specialist_council_seed.slot_role')] ?? null;
                    if (! $model || $role === null) throw new LogicException('COUNCIL_OBSERVED_SOURCE_CONSTRUCTED_ROSTER_DRIFT');
                    $snapshot = data_get($body, 'native_source_models.'.$role.'.original_descriptor_materialization');
                    if (is_array($snapshot) && ($snapshot['protocol'] ?? null) === self::OBSERVED_SOURCE_SNAPSHOT_PROTOCOL
                        && (! $model || $this->epochs->parameterHash(data_get($model->metadata, 'execution_contract')) !== $snapshot['original_declared_execution_hash']
                            || $this->epochs->parameterHash(data_get($model->metadata, 'original_source_execution_snapshot')) !== $this->epochs->parameterHash($snapshot))) {
                        throw new LogicException('COUNCIL_OBSERVED_SOURCE_CONSTRUCTED_DECLARATION_DRIFT');
                    }
                }
            }
            $currentEighthLease = (int) $work->attempts === 8 && $work->status === 'leased'
                && is_string($work->lease_token) && $work->lease_token !== '' && $work->lease_expires_at?->isFuture();
            if ((int) $work->attempts >= 8 && ! $currentEighthLease
                && (! $generation || ! in_array($generation->status, ['queued', 'screening', 'screened', 'completed'], true))) {
                return $this->followupBlocked('COUNCIL_FOLLOWUP_OPERATIONAL_LEASE_BUDGET_EXHAUSTED');
            }
            return ['protocol' => self::FOLLOWUP_PROTOCOL, 'status' => 'ready', 'executable' => true,
                ...$body, 'owned_generation_id' => $generation?->id,
                'effective_source_hash' => $sourceBinding['source_hash'],
                'effective_python_source_hash' => $sourceBinding['python_source_hash'],
                'operational_source_amendment_hash' => $sourceBinding['amendment_hash'],
                'native_intent' => ['protocol' => LabPopulationService::NATIVE_COUNCIL_INTENT_PROTOCOL,
                    'purpose' => 'research', 'symbol' => 'XAUUSD', 'storage_timeframe' => 'H1', 'population_size' => 6,
                    'creator_id' => $body['creator_id'], 'research_question' => $body['research_question'],
                    'followup_work_item_id' => $work->id, 'followup_resolution_hash' => $body['resolution_hash']],
                'promotion_evidence' => false];
        } catch (\Throwable $error) {
            return $this->followupBlocked($error instanceof LogicException ? $error->getMessage() : 'COUNCIL_FOLLOWUP_OWNER_PROOF_UNAVAILABLE');
        }
    }

    /**
     * Public, append-only repair of an unobserved constructor attempt. This
     * does not retune a question, extend a lease, create a generation, alter
     * original evidence or authorize independently confirmed/paper skill.
     */
    public function amendUnobservedFollowupSource(int $workItemId, int $generationId, string $actor, string $reason): array
    {
        if (! preg_match('/^[A-Za-z0-9_.:-]{1,120}$/D', $actor)
            || trim($reason) === '' || strlen($reason) > 500 || trim($reason) !== $reason) {
            throw new LogicException('COUNCIL_SOURCE_AMENDMENT_ATTRIBUTION_REQUIRED');
        }
        return DB::transaction(function () use ($workItemId, $generationId, $actor, $reason): array {
            $work = ResearchExperimentWorkItem::whereKey($workItemId)->lockForUpdate()->firstOrFail();
            [$receipt] = $this->followupOriginal($work);
            $body = $this->assertSignedFollowupResolution($work);
            if (! in_array($work->work_type, self::DISCOVERY_FOLLOWUPS, true)
                || ! in_array($work->status, ['blocked', 'ready'], true) || $work->lease_token !== null
                || $work->completed_at !== null || (int) $work->attempts >= 8
                || ! in_array($work->last_error, ['COUNCIL_FOLLOWUP_LEASE_NOT_CURRENT',
                    'COUNCIL_FOLLOWUP_PREREGISTERED_SOURCE_CHANGED', 'COUNCIL_FOLLOWUP_NEEDS_CONSTRUCTION_CONTINUATION'], true)) {
                throw new LogicException('COUNCIL_SOURCE_AMENDMENT_REQUIRES_UNLEASED_OPERATIONAL_ATTEMPT');
            }
            $generation = LabGeneration::whereKey($generationId)->lockForUpdate()->firstOrFail();
            $snapshot = $this->unobservedNativeConstructorSnapshot($work, $body, $generation);
            $source = app(LabImmutableEvidenceService::class)->codeHash();
            $python = app(ResearchReleaseSealService::class)->pythonHash();
            $amendments = (array) data_get($work->payload, 'followup_source_amendments', []);
            $prior = $amendments === [] ? null : end($amendments);
            if ($prior !== null) {
                $this->assertSourceAmendmentChain($work, $body, $amendments);
                if (($prior['source_hash'] ?? null) === $source && ($prior['python_source_hash'] ?? null) === $python) {
                    return ['protocol' => self::SOURCE_AMENDMENT_PROTOCOL, 'status' => 'already_registered',
                        'work_item_id' => $work->id, 'generation_id' => $generation->id,
                        'resolution_hash' => $body['resolution_hash'], 'amendment_hash' => $prior['amendment_hash'],
                        'promotion_evidence' => false];
                }
            }
            if (count($amendments) >= 3 || ! preg_match('/^[a-f0-9]{64}$/D', $source)
                || ! preg_match('/^[a-f0-9]{64}$/D', $python)
                || ($source === $body['current_source_hash'] && $python === $body['current_python_source_hash'])) {
                throw new LogicException('COUNCIL_SOURCE_AMENDMENT_REQUIRES_BOUNDED_CHANGED_VERIFIED_SOURCE');
            }
            $releases = app(ResearchReleaseSealService::class);
            $sourceArtifact = $releases->currentSourceArtifact() ?? $releases->buildSourceArtifact();
            $sourceVerified = $releases->verifySourceArtifact($sourceArtifact, true);
            if (($sourceVerified['status'] ?? null) !== 'verified'
                || ($sourceArtifact['source_hash'] ?? null) !== $source
                || ($sourceArtifact['python_source_hash'] ?? null) !== $python) {
                throw new LogicException('COUNCIL_SOURCE_AMENDMENT_CURRENT_ARCHIVE_REQUIRED');
            }
            $amendment = ['protocol' => self::SOURCE_AMENDMENT_PROTOCOL, 'work_item_id' => $work->id,
                'work_key' => $work->work_key, 'source_receipt_id' => $receipt->id,
                'generation_id' => $generation->id, 'original_resolution_hash' => $body['resolution_hash'],
                'original_resolution_body_hash' => $this->epochs->parameterHash($body),
                'previous_amendment_hash' => $prior['amendment_hash'] ?? null,
                'original_source_hash' => $body['current_source_hash'],
                'original_python_source_hash' => $body['current_python_source_hash'],
                'source_hash' => $source, 'python_source_hash' => $python,
                'source_artifact' => $sourceArtifact,
                'native_constructor_snapshot' => $snapshot, 'registered_at' => now()->utc()->toIso8601String(),
                'registered_by' => $actor, 'reason' => $reason,
                'operation' => 'bounded_unobserved_native_constructor_repair',
                'authority' => 'research_only', 'new_scientific_attempt' => false,
                'independent_evidence_claimed' => false, 'promotion_evidence' => false];
            $amendment['amendment_hash'] = $this->epochs->parameterHash($amendment);
            $amendment['server_seal'] = $this->sourceAmendmentSeal($amendment);
            $artifact = app(LabImmutableEvidenceService::class)->recordArtifact(null, self::SOURCE_AMENDMENT_PROTOCOL,
                $amendment, ['generation_id' => $generation->id, 'work_item_id' => $work->id,
                    'promotion_evidence' => false], $generation->agents()->orderBy('id')->first());
            $amendments[] = [...$amendment, 'artifact_id' => $artifact->artifact_id, 'artifact_sha256' => $artifact->sha256];
            $work->update(['payload' => [...(array) $work->payload, 'followup_source_amendments' => $amendments]]);
            return ['protocol' => self::SOURCE_AMENDMENT_PROTOCOL, 'status' => 'registered',
                'work_item_id' => $work->id, 'generation_id' => $generation->id,
                'resolution_hash' => $body['resolution_hash'], 'amendment_hash' => $amendment['amendment_hash'],
                'artifact_id' => $artifact->artifact_id, 'promotion_evidence' => false];
        });
    }

    /** Cheap original source fence; never substitutes for full readiness/admission. */
    public function inspectFollowupSourceBinding(ResearchExperimentWorkItem $work): array
    {
        $body = $this->assertSignedFollowupResolution($work);
        $amendments = (array) data_get($work->payload, 'followup_source_amendments', []);
        $last = $amendments === [] ? null : $this->assertSourceAmendmentChain($work, $body, $amendments);
        $source = $last['source_hash'] ?? $body['current_source_hash'];
        $python = $last['python_source_hash'] ?? $body['current_python_source_hash'];
        if (! hash_equals($source, app(LabImmutableEvidenceService::class)->codeHash())
            || ! hash_equals($python, app(ResearchReleaseSealService::class)->pythonHash())) {
            throw new LogicException('COUNCIL_FOLLOWUP_PREREGISTERED_SOURCE_CHANGED');
        }
        return ['source_hash' => $source, 'python_source_hash' => $python,
            'amendment_hash' => $last['amendment_hash'] ?? null,
            'resolution_body_hash' => $this->epochs->parameterHash($body)];
    }

    /** Constructor-only fence before preparation; checks cheap original rows, never data replay readiness. */
    public function assertUnobservedConstructorBinding(ResearchExperimentWorkItem $work, int $generationId): void
    {
        $body = $this->assertSignedFollowupResolution($work);
        $generation = LabGeneration::findOrFail($generationId);
        $current = $this->unobservedNativeConstructorSnapshot($work, $body, $generation);
        foreach ((array) data_get($work->payload, 'followup_source_amendments', []) as $amendment) {
            $frozen = $amendment['native_constructor_snapshot'] ?? [];
            if (($amendment['generation_id'] ?? null) !== $generationId
                || ($frozen['intent_hash'] ?? null) !== $current['intent_hash']
                || ($frozen['plan_hash'] ?? null) !== $current['plan_hash']
                || ($frozen['original_native_vectors_hash'] ?? null) !== $current['original_native_vectors_hash']) {
                throw new LogicException('COUNCIL_SOURCE_AMENDMENT_ORIGINAL_CONSTRUCTOR_DRIFT');
            }
            $roster = array_column($current['existing_roster'], null, 'agent_id');
            foreach ((array) ($frozen['existing_roster'] ?? []) as $row) {
                if (($roster[$row['agent_id']] ?? null) !== $row) {
                    throw new LogicException('COUNCIL_SOURCE_AMENDMENT_ORIGINAL_CONSTRUCTOR_DRIFT');
                }
            }
        }
    }

    private function assertSignedFollowupResolution(ResearchExperimentWorkItem $work): array
    {
        $body = data_get($work->payload, 'followup_resolution');
        if (! is_array($body) || ($body['protocol'] ?? null) !== self::FOLLOWUP_PROTOCOL
            || ($body['resolution_hash'] ?? null) !== $this->epochs->parameterHash(array_diff_key($body, ['resolution_hash' => true, 'server_seal' => true]))
            || ! is_string($body['server_seal'] ?? null)
            || ! hash_equals($body['server_seal'], $this->followupServerSeal(array_diff_key($body, ['server_seal' => true])))
            || ($body['work_item_id'] ?? null) !== $work->id || ($body['work_key'] ?? null) !== $work->work_key
            || ($body['source_receipt_id'] ?? null) !== $work->research_experiment_receipt_id
            || ($body['authority'] ?? null) !== 'research_only' || ($body['max_experiments'] ?? null) !== 1
            || ($body['promotion_evidence'] ?? null) !== false || ($body['independent_evidence_claimed'] ?? null) !== false) {
            throw new LogicException('COUNCIL_FOLLOWUP_ORIGINAL_RESOLUTION_DRIFT');
        }
        return $body;
    }

    private function assertSourceAmendmentChain(ResearchExperimentWorkItem $work, array $body, array $amendments): array
    {
        if (! array_is_list($amendments) || count($amendments) > 3) throw new LogicException('COUNCIL_SOURCE_AMENDMENT_CHAIN_INVALID');
        $previous = null;
        foreach ($amendments as $entry) {
            if (! is_array($entry)) throw new LogicException('COUNCIL_SOURCE_AMENDMENT_CHAIN_INVALID');
            $original = array_diff_key($entry, ['artifact_id' => true, 'artifact_sha256' => true]);
            $unsigned = array_diff_key($original, ['amendment_hash' => true, 'server_seal' => true]);
            $artifact = LabEvidenceArtifact::where('artifact_id', $entry['artifact_id'] ?? '')->first();
            if (($entry['protocol'] ?? null) !== self::SOURCE_AMENDMENT_PROTOCOL
                || ($entry['work_item_id'] ?? null) !== $work->id || ($entry['work_key'] ?? null) !== $work->work_key
                || ($entry['source_receipt_id'] ?? null) !== $work->research_experiment_receipt_id
                || ($entry['original_resolution_hash'] ?? null) !== $body['resolution_hash']
                || ($entry['original_resolution_body_hash'] ?? null) !== $this->epochs->parameterHash($body)
                || ($entry['previous_amendment_hash'] ?? null) !== $previous
                || ($entry['original_source_hash'] ?? null) !== $body['current_source_hash']
                || ($entry['original_python_source_hash'] ?? null) !== $body['current_python_source_hash']
                || ($entry['operation'] ?? null) !== 'bounded_unobserved_native_constructor_repair'
                || ($entry['authority'] ?? null) !== 'research_only' || ($entry['new_scientific_attempt'] ?? null) !== false
                || ($entry['independent_evidence_claimed'] ?? null) !== false || ($entry['promotion_evidence'] ?? null) !== false
                || ($entry['amendment_hash'] ?? null) !== $this->epochs->parameterHash($unsigned)
                || ! is_string($entry['server_seal'] ?? null)
                || ! hash_equals($entry['server_seal'], $this->sourceAmendmentSeal(array_diff_key($original, ['server_seal' => true])))
                || ! $artifact || $artifact->artifact_type !== self::SOURCE_AMENDMENT_PROTOCOL
                || $artifact->sha256 !== ($entry['artifact_sha256'] ?? null)
                || $this->epochs->parameterHash(app(LabImmutableEvidenceService::class)->readArtifactPayload($artifact))
                    !== $this->epochs->parameterHash($original)
                || LabGeneration::where('trigger_context->native_specialist_council_intent->followup_work_item_id', $work->id)
                    ->whereKey($entry['generation_id'] ?? 0)->doesntExist()) {
                throw new LogicException('COUNCIL_SOURCE_AMENDMENT_ORIGINAL_PROOF_INVALID');
            }
            $archive = (array) ($entry['source_artifact'] ?? []);
            if (($archive['source_hash'] ?? null) !== $entry['source_hash']
                || ($archive['python_source_hash'] ?? null) !== $entry['python_source_hash']
                || (app(ResearchReleaseSealService::class)->verifySourceArtifact($archive)['status'] ?? null) !== 'verified') {
                throw new LogicException('COUNCIL_SOURCE_AMENDMENT_ORIGINAL_ARCHIVE_INVALID');
            }
            $previous = $entry['amendment_hash'];
        }
        return $entry;
    }

    private function sourceAmendmentSeal(array $body): string
    {
        $key = (string) config('services.internal_api.token');
        if (strlen($key) < 32) throw new LogicException('COUNCIL_FOLLOWUP_SERVER_SEAL_KEY_UNAVAILABLE');
        return hash_hmac('sha256', self::SOURCE_AMENDMENT_PROTOCOL."\n".$this->epochs->parameterHash($body), $key);
    }

    private function unobservedNativeConstructorSnapshot(ResearchExperimentWorkItem $work, array $body, LabGeneration $generation): array
    {
        $owned = LabGeneration::where('trigger_context->native_specialist_council_intent->followup_work_item_id', $work->id)->pluck('id')->all();
        $intent = (array) data_get($generation->trigger_context, 'native_specialist_council_intent', []);
        $plan = (array) data_get($generation->trigger_context, 'generation_plan', []);
        $roles = ['source_scalp', 'source_hour', 'source_day', 'source_swing', 'candidate_carrier', 'ablation_carrier'];
        if ($owned !== [$generation->id] || ! in_array($generation->status, ['draft', 'technical_quarantine'], true)
            || ($intent['followup_resolution_hash'] ?? null) !== $body['resolution_hash']
            || ($intent['creator_id'] ?? null) !== $body['creator_id'] || ($intent['research_question'] ?? null) !== $body['research_question']
            || ($intent['protocol'] ?? null) !== LabPopulationService::NATIVE_COUNCIL_INTENT_PROTOCOL
            || ($intent['population_size'] ?? null) !== 6 || ($intent['authority'] ?? null) !== 'research_only'
            || ($intent['promotion_evidence'] ?? null) !== false || ($intent['independent_evidence_claimed'] ?? null) !== false
            || ($intent['intent_hash'] ?? null) !== $this->epochs->parameterHash(array_diff_key($intent, ['intent_hash' => true]))
            || count($plan) !== 6 || ! array_is_list($plan)
            || data_get($generation->trigger_context, 'specialist_council_preparation') !== null
            || data_get($generation->trigger_context, 'research_release') !== null
            || ($work->result && (data_get($work->result, 'stage') !== 'constructed'
                || data_get($work->result, 'generation_id') !== $generation->id
                || data_get($work->result, 'resolution_hash') !== $body['resolution_hash']))) {
            throw new LogicException('COUNCIL_SOURCE_AMENDMENT_REQUIRES_EXACT_UNOBSERVED_SIX_SEAT_INTENT');
        }
        foreach ($plan as $slot => $definition) {
            $seed = (array) data_get($definition, 'niche.native_specialist_council_seed', []);
            $role = ['scalp', 'hour', 'day', 'swing', 'day', 'day'][$slot];
            if (($definition['origin'] ?? null) !== 'native_council_root'
                || ($seed['slot_role'] ?? null) !== $roles[$slot] || ($seed['intent_hash'] ?? null) !== $intent['intent_hash']
                || ($seed['followup_work_item_id'] ?? null) !== $work->id
                || ($seed['followup_resolution_hash'] ?? null) !== $body['resolution_hash']
                || $this->epochs->parameterHash(data_get($definition, 'niche.native_council_followup_source'))
                    !== $this->epochs->parameterHash($body['native_source_models'][$role])) {
                throw new LogicException('COUNCIL_SOURCE_AMENDMENT_FROZEN_PLAN_DRIFT');
            }
        }
        foreach ($body['native_source_models'] as $spec) {
            $source = ModelVersion::find($spec['model_version_id']);
            if (! $source || $this->contracts->modelHash($source) !== $spec['model_hash']) {
                throw new LogicException('COUNCIL_SOURCE_AMENDMENT_ORIGINAL_NATIVE_SOURCE_DRIFT');
            }
        }
        $agents = $generation->agents()->with('modelVersion')->orderBy('id')->get();
        $ids = $agents->pluck('model_version_id')->all();
        if (LabEvaluationRun::where('lab_generation_id', $generation->id)->orWhereIn('model_version_id', $ids)->exists()
            || LabAgent::whereIn('model_version_id', $ids)->where('lab_generation_id', '!=', $generation->id)->exists()) {
            throw new LogicException('COUNCIL_SOURCE_AMENDMENT_COHORT_ALREADY_OBSERVED');
        }
        $roster = []; $seen = [];
        foreach ($agents as $agent) {
            $model = $agent->modelVersion; $seed = (array) data_get($model?->metadata, 'native_specialist_council_seed', []);
            $slot = array_search($seed['slot_role'] ?? null, $roles, true);
            $role = $slot === false ? null : ['scalp', 'hour', 'day', 'swing', 'day', 'day'][$slot];
            if (! $model || $slot === false || isset($seen[$slot]) || $agent->origin !== 'native_council_root'
                || $agent->parent_a_model_version_id !== null || $agent->parent_b_model_version_id !== null
                || ($seed['lab_generation_id'] ?? null) !== $generation->id
                || ($seed['intent_hash'] ?? null) !== $intent['intent_hash']
                || ($seed['followup_work_item_id'] ?? null) !== $work->id
                || ($seed['followup_resolution_hash'] ?? null) !== $body['resolution_hash']
                || ! in_array($agent->lifecycle_status, ['draft', 'technical_quarantine'], true)
                || ! app(LabImmutableEvidenceService::class)->equivalentJsonValue($model->parameters, $body['native_source_models'][$role]['parameters'])
                || data_get($model->metadata, 'strategy_architecture') !== $body['native_source_models'][$role]['strategy_architecture']
                || data_get($model->metadata, 'base_strategy') !== $body['native_source_models'][$role]['base_strategy']
                || $agent->strategy_family !== $body['native_source_models'][$role]['family']) {
                throw new LogicException('COUNCIL_SOURCE_AMENDMENT_CONSTRUCTED_NATIVE_VECTOR_DRIFT');
            }
            $seen[$slot] = true;
            $roster[] = ['agent_id' => $agent->id, 'model_version_id' => $model->id, 'slot_role' => $roles[$slot],
                'model_hash' => $this->contracts->modelHash($model)];
        }
        return ['generation_id' => $generation->id, 'intent_hash' => $intent['intent_hash'],
            'plan_hash' => $this->epochs->parameterHash($plan), 'planned_slots' => 6,
            'existing_roster' => $roster, 'original_native_vectors_hash' => $this->epochs->parameterHash($body['native_source_models']),
            'run_count' => 0, 'prepared' => false, 'scientific_outcomes_observed' => false];
    }

    private function followupOriginal(ResearchExperimentWorkItem $work): array
    {
        $receipt = ResearchExperimentReceipt::find($work->research_experiment_receipt_id);
        if (! in_array($work->work_type, self::FOLLOWUP_TYPES, true) || ! $receipt
            || $receipt->source_type !== SpecialistCouncilVersion::class || ! $this->priorReceiptValid($receipt)
            || data_get($work->payload, 'owner') !== ResearchLoopArbiterService::class
            || data_get($work->payload, 'executor') !== ResearchExperimentWorkConsumerService::class
            || data_get($work->payload, 'version_id') !== $receipt->source_id
            || data_get($work->payload, 'assessment_hash') !== data_get($receipt->payload, 'evidence.assessment_hash')
            || data_get($work->payload, 'same_evidence_replay_forbidden') !== true
            || data_get($work->payload, 'retry_condition.max_experiments') !== 1) {
            throw new LogicException('COUNCIL_FOLLOWUP_ORIGINAL_WORK_OR_EVIDENCE_INVALID');
        }
        $version = SpecialistCouncilVersion::findOrFail($receipt->source_id);
        $stored = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $version->id)->sole();
        return [$receipt, $version, json_decode($stored->plan, true, 512, JSON_THROW_ON_ERROR)];
    }

    /** Descendants derive IDs from the original manifest; no caller can supply hash overrides. */
    public function projectOriginalNativeSources(SpecialistCouncilVersion $version, array $deltas): array
    {
        foreach ($deltas as $changes) if (! is_array($changes) || $changes === [] || array_is_list($changes)) {
            throw new LogicException('COUNCIL_DESCENDANT_TYPED_TRAIT_AND_INTERVENTION_REQUIRED');
        }
        $specs = $this->projectNativeSources($version, array_column($version->manifest['members'], 'model_version_id', 'role'), $deltas);
        foreach ($specs as $spec) foreach ($spec['parameter_deltas'] as $delta) {
            if (app(LabImmutableEvidenceService::class)->equivalentJsonValue($delta['old'], $delta['new'])) {
                throw new LogicException('COUNCIL_FOLLOWUP_DECLARED_DELTA_IS_NO_EFFECT');
            }
        }
        return $specs;
    }

    private function projectNativeSources(SpecialistCouncilVersion $version, array $ids, array $deltas,
        array $materializations = []): array
    {
        $roles = ['scalp', 'hour', 'day', 'swing']; $keys = array_keys($ids); sort($keys); $expected = $roles; sort($expected);
        if ($keys !== $expected || count(array_unique(array_values($ids))) !== 4
            || array_diff(array_keys($deltas), $roles) !== [] || count($deltas) > 2) {
            throw new LogicException('COUNCIL_FOLLOWUP_REQUIRES_EXACT_FOUR_NATIVE_SOURCE_ROLES');
        }
        $members = [];
        foreach ($version->manifest['members'] as $member) {
            if (! in_array($member['role'], $roles, true) || isset($members[$member['role']])) {
                throw new LogicException('COUNCIL_FOLLOWUP_NATIVE_SOURCE_ROLE_AMBIGUOUS');
            }
            $members[$member['role']] = $member;
        }
        if (count($members) !== 4) throw new LogicException('COUNCIL_FOLLOWUP_NATIVE_SOURCE_ROLE_INCOMPLETE');
        $schemas = app(StrategyParameterSchemaService::class); $specs = []; $count = 0;
        foreach ($roles as $role) {
            $model = ModelVersion::find($ids[$role]); $member = $members[$role];
            $materialization = $materializations[$role] ?? null;
            if (! $model || $model->id !== $member['model_version_id']
                || ($this->contracts->modelHash($model) !== $member['source_model_hash']
                    && (! is_array($materialization) || ($materialization['model_version_id'] ?? null) !== $model->id
                        || ($materialization['original_member_hash'] ?? null) !== $member['source_model_hash']
                        || ($materialization['current_model_hash'] ?? null) !== $this->contracts->modelHash($model)))) {
                throw new LogicException('COUNCIL_FOLLOWUP_SOURCE_MUST_BE_ORIGINAL_NATIVE_MEMBER');
            }
            $base = (array) $model->parameters; $changes = (array) ($deltas[$role] ?? []); $count += count($changes);
            if ($count > 4 || array_diff(array_keys($changes), array_keys($base)) !== []) {
                throw new LogicException('COUNCIL_FOLLOWUP_DELTA_MUST_BE_BOUNDED_EXISTING_NATIVE_GENES');
            }
            $parameters = $schemas->validate($model->strategy, [...$base, ...$changes]);
            if ($this->epochs->parameterHash($parameters) !== $this->epochs->parameterHash($schemas->normalizeForGeneration($model->strategy, $parameters))) {
                throw new LogicException('COUNCIL_FOLLOWUP_DELTA_REQUIRES_UNDECLARED_NORMALIZATION');
            }
            $vector = [];
            foreach ($changes as $gene => $value) {
                if ($this->epochs->parameterHash([$base[$gene]]) === $this->epochs->parameterHash([$value])) {
                    throw new LogicException('COUNCIL_FOLLOWUP_DECLARED_DELTA_IS_NO_EFFECT');
                }
                $admission = app(DependencyAwareEdgeGenesisFoundryService::class)->mutationAdmission($model, (string) $gene);
                if (($admission['allowed'] ?? false) !== true) {
                    throw new LogicException((string) ($admission['reason'] ?? 'EDGE_GENESIS_MUTATION_ADMISSION_DENIED'));
                }
                $vector[] = ['gene' => $gene, 'old' => $base[$gene], 'new' => $value];
            }
            // Only original exact-context, verified instrument policy can veto;
            // negative council observations never become a gene-wide ban.
            if (app(InstrumentPolicyConsumptionService::class)->forbiddenDelta(
                (array) data_get($model->metadata, 'instrument_learning_policy', []),
                array_map(
                    fn (array $delta): array => ['old' => $delta['old'], 'new' => $delta['new']], array_column($vector, null, 'gene')),
                $parameters,
            )) throw new LogicException('INSTRUMENT_EXACT_DELTA_FORBIDDEN');
            $specs[$role] = ['model_version_id' => $model->id, 'model_hash' => $this->contracts->modelHash($model),
                'family' => $schemas->family($model->strategy), 'strategy' => $model->strategy,
                'strategy_architecture' => data_get($model->metadata, 'strategy_architecture'),
                'base_strategy' => data_get($model->metadata, 'base_strategy', $schemas->runtimeBaseStrategy($model->strategy)),
                'original_parameters_hash' => $this->epochs->parameterHash($base), 'parameters' => $parameters,
                'parameter_hash' => $this->epochs->parameterHash($parameters), 'parameter_deltas' => $vector];
            if ($materialization !== null) $specs[$role]['original_descriptor_materialization'] = $materialization;
        }
        return $specs;
    }

    private function assertFollowupDesign(array $original, array $plan, SpecialistCouncilVersion $version): void
    {
        if (($plan['purpose'] ?? null) !== 'research' || ($plan['execution_timeframe'] ?? null) !== 'M5'
            || count((array) ($plan['windows'] ?? [])) !== 1 || count((array) ($plan['arms'] ?? [])) !== 3) {
            throw new LogicException('COUNCIL_FOLLOWUP_REQUIRES_ONE_BOUNDED_RESEARCH_WINDOW_AND_THREE_ARMS');
        }
        foreach (['execution_hash', 'execution_timeframe', 'initial_capital', 'cost_model', 'risk_policy'] as $key) {
            if ($this->epochs->parameterHash([$plan[$key] ?? null]) !== $this->epochs->parameterHash([$original[$key] ?? null])) {
                throw new LogicException('COUNCIL_FOLLOWUP_EXTERNAL_COST_RISK_OR_CAPITAL_CHANGED');
            }
        }
        $types = array_count_values(array_column($plan['arms'], 'kind'));
        if ($types !== ['candidate' => 1, 'solo' => 1, 'ablation' => 1]) {
            ksort($types);
            if ($types !== ['ablation' => 1, 'candidate' => 1, 'solo' => 1]) throw new LogicException('COUNCIL_FOLLOWUP_THREE_ARM_KIND_MISMATCH');
        }
        $originalIds = array_column($original['arms'], 'model_version_id', 'kind');
        $window = array_values($plan['windows'])[0];
        foreach ($plan['arms'] as $arm) {
            if (($arm['window_key'] ?? null) !== ($window['window_key'] ?? null)
                || ($arm['model_version_id'] ?? null) !== ($originalIds[$arm['kind']] ?? null)
                || ($arm['kind'] === 'ablation' && ! in_array($arm['removed_id'] ?? null, array_column($version->manifest['members'], 'specialist_id'), true))) {
                throw new LogicException('COUNCIL_FOLLOWUP_ORIGINAL_COMPARATOR_OR_ABLATION_CHANGED');
            }
        }
    }

    /**
     * A specific original request-schema refusal is not a market observation.
     * No HTTP status is fabricated: the trusted terminal publisher preserves
     * the exact Python null-dictionary schema error and its original request.
     * Any timeout, scientific payload, live/duplicate arm or missing artifact
     * remains an explicit dependency, never an unobserved replacement license.
     */
    /** An observed auxiliary run is never an unobserved repair or a comparative outcome. */
    private function observedAuxiliarySourceProof(ResearchExperimentReceipt $receipt, SpecialistCouncilVersion $version, array $plan): ?array
    {
        $drifted = [];
        foreach ($version->manifest['members'] as $member) {
            $model = ModelVersion::find($member['model_version_id']);
            if (! $model || $this->contracts->modelHash($model) !== $member['source_model_hash']) $drifted[] = $member;
        }
        // Preserve the ordinary exact-source refusal for unrelated drift.
        if (count($drifted) !== 1) return null;
        $member = $drifted[0]; $comparativeIds = array_column($plan['arms'], 'model_version_id');
        if (count($plan['arms']) !== 3) return null;
        if (in_array($member['model_version_id'], $comparativeIds, true)) return null;
        $owner = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $version->id)->sole();
        $completed = LabEvaluationRun::whereIn('model_version_id', array_column($version->manifest['members'], 'model_version_id'))
            ->whereNotIn('model_version_id', $comparativeIds)->where('started_at', '>=', $owner->sealed_at)
            ->where('status', 'completed')->orderBy('id')->limit(2)->get();
        if ($completed->count() !== 1 || (int) $completed[0]->model_version_id !== (int) $member['model_version_id']) return null;
        if (data_get($receipt->payload, 'evidence.original_sources', []) !== []
            || data_get($receipt->payload, 'evidence.comparisons', []) !== []
            || data_get($receipt->payload, 'evidence.original_run_ids', []) !== []
            || LabEvaluationRun::whereIn('model_version_id', $comparativeIds)->where('started_at', '>=', $owner->sealed_at)->exists()) {
            throw new LogicException('COUNCIL_OBSERVED_SOURCE_REQUIRES_UNEXECUTED_COMPARATIVE_ARMS');
        }
        $run = $completed[0]; $model = $run->modelVersion; $agent = $run->agent;
        $seed = data_get($model?->metadata, 'native_specialist_council_seed');
        if (! $model || ! $agent || ! $run->started_at || ! $run->finished_at || $run->finished_at->lt($run->started_at)
            || $run->phase !== 'screening'
            || $run->mode !== 'incremental' || (int) $agent->model_version_id !== (int) $model->id
            || (int) $agent->lab_generation_id !== (int) $run->lab_generation_id
            || $agent->origin !== 'native_council_root' || ! is_array($seed)
            || ($seed['protocol'] ?? null) !== LabPopulationService::NATIVE_COUNCIL_INTENT_PROTOCOL
            || ($seed['slot_role'] ?? null) !== 'source_'.$member['role']
            || ($seed['lab_generation_id'] ?? null) !== (int) $run->lab_generation_id
            || ! is_string($seed['intent_hash'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $seed['intent_hash'])
            || data_get($agent->generation->trigger_context, 'native_specialist_council_intent.intent_hash') !== $seed['intent_hash']
            || $run->code_hash !== ($plan['preparation_source_hash'] ?? null)) {
            throw new LogicException('COUNCIL_OBSERVED_SOURCE_ORIGINAL_OWNER_INVALID');
        }
        $evidence = app(LabImmutableEvidenceService::class); $artifacts = [];
        foreach (['evaluation_request', 'evaluation_response', 'model_runtime_identity'] as $type) {
            $rows = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', $type)->get();
            $artifact = $rows->count() === 1 ? $rows[0] : null;
            if (! $artifact || ! $artifact->storage_path
                || data_get($artifact->metadata, 'storage_protocol') !== 'compressed_artifact_v2'
                || ! $artifact->created_at || $artifact->created_at->greaterThan($run->finished_at)) {
                throw new LogicException('COUNCIL_OBSERVED_SOURCE_IMMUTABLE_ARTIFACT_REQUIRED');
            }
            $artifacts[$type] = $artifact;
        }
        $requestArtifact = $artifacts['evaluation_request']; $responseArtifact = $artifacts['evaluation_response'];
        $identityArtifact = $artifacts['model_runtime_identity'];
        $request = $evidence->readArtifactPayload($requestArtifact); $response = $evidence->readArtifactPayload($responseArtifact);
        $identity = $evidence->readArtifactPayload($identityArtifact);
        if (! is_array($request) || ! is_array($response) || ! is_array($identity)
            || data_get($requestArtifact->metadata, 'request_hash') !== $run->request_hash
            || $responseArtifact->sha256 !== $run->response_hash
            || ($identity['protocol'] ?? null) !== 'original_model_runtime_identity_v1'
            || ($identity['run_id'] ?? null) !== $run->run_id
            || ($identity['model_version_id'] ?? null) !== (int) $model->id
            || ($identity['lab_agent_id'] ?? null) !== (int) $agent->id
            || ($identity['raw_request_hash'] ?? null) !== $run->request_hash
            || ($identity['request_artifact_hash'] ?? null) !== $requestArtifact->sha256
            || ! is_string($identity['raw_payload_hash'] ?? null)
            || $identity['raw_payload_hash'] !== data_get($requestArtifact->metadata, 'raw_payload_hash')
            || ($identity['compiled_runtime_contract_hash'] ?? null) !== $this->epochs->parameterHash((array) ($request['composition_runtime_contract'] ?? []))
            || ($identity['evidence_parameter_hash'] ?? null) !== $run->parameter_hash
            || ($identity['parameter_hash'] ?? null) !== $this->epochs->parameterHash((array) $model->parameters)) {
            throw new LogicException('COUNCIL_OBSERVED_SOURCE_IMMUTABLE_OWNER_MISMATCH');
        }
        $strategies = array_values(array_filter((array) ($request['strategies'] ?? []), fn ($strategy): bool => is_array($strategy)
            && ($strategy['lab_agent_id'] ?? null) === (int) $run->lab_agent_id));
        if (count($strategies) !== 1) throw new LogicException('COUNCIL_OBSERVED_SOURCE_ORIGINAL_REQUEST_AMBIGUOUS');
        $strategy = $strategies[0]; $effective = [...$request, ...$strategy];
        if (($request['replay_dataset_hash'] ?? null) !== $run->data_hash
            || ! in_array($run->data_hash, array_column($plan['windows'], 'dataset_sha256'), true)) {
            throw new LogicException('COUNCIL_OBSERVED_SOURCE_ORIGINAL_DATA_OWNER_INVALID');
        }
        $declared = data_get($identity, 'runtime_basis.components.execution_contract');
        $current = data_get($model->metadata, 'execution_contract'); $observed = $response['execution_contract'] ?? null;
        $original = clone $model;
        $original->metadata = [...(array) $model->metadata, 'execution_contract' => $declared];
        if (! is_array($declared) || $declared === [] || ! is_array($current) || $current === []
            || ! is_array($observed) || $observed === []
            || ! $evidence->equivalentJsonValue($declared, $effective['execution_contract'] ?? null)
            || ! $evidence->equivalentJsonValue($current, $observed)
            || $evidence->equivalentJsonValue($declared, $current)
            || ($strategy['strategy'] ?? null) !== $model->strategy || ($strategy['version'] ?? null) !== $model->version
            || ! $evidence->equivalentJsonValue($strategy['parameters'] ?? null, (array) $model->parameters)
            || ! $evidence->equivalentJsonValue($identity['runtime_basis'] ?? null, $evidence->modelRuntimeBasis($original))
            || $this->contracts->modelHash($original) !== $member['source_model_hash']) {
            throw new LogicException('COUNCIL_OBSERVED_SOURCE_EXECUTION_ONLY_SNAPSHOT_NOT_PROVEN');
        }
        $archive = app(SpecialistCouncilLifecycleService::class)->assertArchivedOriginalRelease($run, $request, $response, $plan);
        // Match the model/resolution JSON persistence domain without losing
        // the separately pinned original archived declaration/hash.
        $copy = json_decode(json_encode($declared, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true, 512, JSON_THROW_ON_ERROR);
        if (! $evidence->equivalentJsonValue($copy, $declared)) throw new LogicException('COUNCIL_OBSERVED_SOURCE_DECLARATION_COPY_CHANGED');
        $materialization = ['protocol' => self::OBSERVED_SOURCE_SNAPSHOT_PROTOCOL, 'descriptor_field' => 'execution_contract',
            'original_value' => $copy, 'original_declared_execution_hash' => $this->epochs->parameterHash($copy),
            'original_archived_declaration_hash' => $this->epochs->parameterHash($declared),
            'observed_execution_hash' => $this->epochs->parameterHash($current), 'model_version_id' => (int) $model->id,
            'specialist_id' => $member['specialist_id'], 'original_member_hash' => $member['source_model_hash'],
            'current_model_hash' => $this->contracts->modelHash($model), 'original_run_id' => $run->run_id,
            'original_generation_id' => (int) $run->lab_generation_id, 'original_agent_id' => (int) $run->lab_agent_id,
            'original_request_hash' => $run->request_hash, 'request_artifact_hash' => $requestArtifact->sha256,
            'response_artifact_hash' => $responseArtifact->sha256, 'model_runtime_identity_artifact_hash' => $identityArtifact->sha256,
            'original_archive_hash' => data_get($request, 'research_release.source_artifact.artifact_hash'),
            'original_archive_sha256' => data_get($request, 'research_release.source_artifact.archive_sha256'),
            'original_source_hash' => data_get($archive, 'manifest.source_identity.source_hash'),
            'original_parameter_hash' => $run->parameter_hash, 'runtime_parameter_hash' => $identity['parameter_hash'],
            'runtime_basis_hash' => $this->epochs->parameterHash($identity['runtime_basis']), 'equivalence' => 'verified_numerical_json_value',
            'original_preparation_remains_invalid' => true, 'scientific_outcomes_observed' => true,
            'observed_auxiliary_only' => true, 'promotion_evidence' => false];
        return ['protocol' => 'specialist_council_original_observed_auxiliary_source_v1', 'original_plan_hash' => $owner->plan_hash,
            'original_version_id' => (int) $version->id, 'original_auxiliary_run_id' => $run->run_id,
            'descriptor_materializations' => [$member['role'] => $materialization], 'comparative_run_ids' => [],
            'scientific_outcomes_observed' => true, 'observed_auxiliary_only' => true,
            'independent_evidence_claimed' => false, 'promotion_evidence' => false];
    }

    private function unobservedTechnicalProof(ResearchExperimentReceipt $receipt, SpecialistCouncilVersion $version, array $plan): array
    {
        if (data_get($receipt->payload, 'evidence.original_sources', []) !== []
            || data_get($receipt->payload, 'evidence.comparisons', []) !== []) {
            throw new LogicException('COUNCIL_TECHNICAL_REPAIR_ORIGINAL_OUTCOMES_ALREADY_OBSERVED');
        }
        $owner = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $version->id)->sole();
        $arms = $plan['arms']; $comparisonModelIds = array_column($arms, 'model_version_id');
        $members = $version->manifest['members'];
        $modelIds = array_values(array_unique([...$comparisonModelIds, ...array_column($members, 'model_version_id')]));
        $runs = LabEvaluationRun::whereIn('model_version_id', $modelIds)->where('started_at', '>=', $owner->sealed_at)
            ->orderBy('id')->limit(count($modelIds) + 1)->get();
        $declared = (array) data_get($receipt->payload, 'evidence.original_run_ids', []);
        $actualIds = $runs->filter(fn (LabEvaluationRun $run): bool => in_array((int) $run->model_version_id, $comparisonModelIds, true))->pluck('run_id')->all();
        $declaredSorted = $declared; $actualSorted = $actualIds;
        sort($declaredSorted); sort($actualSorted);
        if ($runs->count() > count($modelIds) || $runs->pluck('model_version_id')->unique()->count() !== $runs->count()
            || count(array_unique($declared)) !== count($declared)
            || $declaredSorted !== $actualSorted) {
            throw new LogicException('COUNCIL_TECHNICAL_REPAIR_ORIGINAL_RUN_SET_CHANGED');
        }
        $evidence = app(LabImmutableEvidenceService::class); $rejected = []; $covered = []; $materializations = [];
        foreach ($runs as $run) {
            $requestArtifact = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_request')->oldest('id')->first();
            $responseArtifact = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_response')->oldest('id')->first();
            if ($run->status !== 'technical_error' || ! $run->finished_at
                || data_get($run->metadata, 'source') !== 'bounded_screening_batch'
                || data_get($run->metadata, 'reason_code') !== 'BATCH_REPLAY_TRANSPORT_FAILURE'
                || data_get($run->metadata, 'terminal') !== true || $run->trade_ledger_hash !== null
                || ! $requestArtifact || ! $responseArtifact || ! $requestArtifact->storage_path || ! $responseArtifact->storage_path
                || data_get($requestArtifact->metadata, 'storage_protocol') !== 'compressed_artifact_v2'
                || data_get($responseArtifact->metadata, 'storage_protocol') !== 'compressed_artifact_v2'
                || $requestArtifact->created_at === null || $requestArtifact->created_at->greaterThan($run->finished_at)
                || $responseArtifact->created_at === null || $responseArtifact->created_at->greaterThan($run->finished_at)
                || data_get($requestArtifact->metadata, 'request_hash') !== $run->request_hash
                || $responseArtifact->sha256 !== $run->response_hash
                || ! $evidence->verifiedModelRuntimeIdentity($run)) {
                throw new LogicException('COUNCIL_TECHNICAL_REPAIR_ORIGINAL_PREEXECUTION_PROOF_REQUIRED');
            }
            $request = $evidence->readArtifactPayload($requestArtifact);
            $response = $evidence->readArtifactPayload($responseArtifact);
            $strategies = (array) ($request['strategies'] ?? []); $strategy = $strategies[0] ?? null;
            $terminal = (array) ($response['terminal_replay_envelope'] ?? []);
            $failure = json_decode((string) ($terminal['error_message'] ?? ''), true);
            $expectedError = ['type' => 'dict_type', 'loc' => ['body', 'strategies', 0, 'specialist_council_contract'],
                'msg' => 'Input should be a valid dictionary', 'input' => null];
            $binding = (array) ($strategy['specialist_council_evaluation'] ?? []);
            $effective = [...(array) $request, ...(array) $strategy];
            $armKey = $binding['arm_key'] ?? null; $arm = $arms[$armKey] ?? null;
            $member = collect($members)->firstWhere('model_version_id', $run->model_version_id);
            $window = $arm === null ? array_values($plan['windows'])[0] : ($plan['windows'][$arm['window_key']] ?? null);
            $comparison = in_array((int) $run->model_version_id, $comparisonModelIds, true);
            if (! is_array($request) || ! is_array($response) || count($strategies) !== 1 || ! is_array($strategy)
                || ! array_key_exists('specialist_council_contract', $strategy) || $strategy['specialist_council_contract'] !== null
                || (int) ($strategy['lab_agent_id'] ?? 0) !== (int) $run->lab_agent_id
                || ! $evidence->equivalentJsonValue($failure, ['detail' => [$expectedError]])
                || ($terminal['status'] ?? null) !== 'technical_error' || ($terminal['response_available'] ?? null) !== false
                || ($terminal['reason_code'] ?? null) !== 'BATCH_REPLAY_TRANSPORT_FAILURE'
                || ($terminal['error_class'] ?? null) !== \RuntimeException::class
                || $run->error_class !== \RuntimeException::class || $run->error_message !== $terminal['error_message']
                || array_diff(array_keys($response), ['terminal_replay_envelope', 'data_quality', 'trade_ledger_hash', 'total_trades', 'displayed_trade_count']) !== []
                || ($response['total_trades'] ?? null) !== null || ($response['trade_ledger_hash'] ?? null) !== null
                || ($response['displayed_trade_count'] ?? null) !== 0
                || ! $evidence->equivalentJsonValue($response['data_quality'] ?? null, ['decision_trace' => [
                    'requested' => true, 'complete' => false, 'reason' => 'terminal_replay_did_not_return_evaluator_response']])
                || ! $window || ($comparison && (! $arm || isset($covered[$armKey])
                    || (int) $arm['model_version_id'] !== (int) $run->model_version_id || $run->phase !== $arm['evaluation_phase']
                    || ($binding['version_id'] ?? null) !== $version->id || ($binding['manifest_hash'] ?? null) !== $version->manifest_hash
                || ($binding['plan_hash'] ?? null) !== $owner->plan_hash))
                || ($comparison && ((float) ($effective['initial_balance'] ?? 0) !== (float) $plan['initial_capital']
                    || ! $evidence->equivalentJsonValue($effective['cost_model'] ?? null, $plan['cost_model'])
                    || ! $evidence->equivalentJsonValue($effective['risk_policy'] ?? null, $plan['risk_policy'])))
                || (! $comparison && (! $member || $binding !== [] || $run->phase !== 'screening'))
                || $run->code_hash !== ($plan['preparation_source_hash'] ?? null)
                || $run->data_hash !== $window['dataset_sha256']
                || ($request['replay_dataset_hash'] ?? null) !== $run->data_hash
                || ($effective['execution_hash'] ?? data_get($effective, 'execution_contract.execution_hash')) !== $plan['execution_hash']) {
                throw new LogicException('COUNCIL_TECHNICAL_REPAIR_ORIGINAL_SCHEMA_REJECTION_NOT_PROVEN');
            }
            $originalModelHash = $comparison ? $arm['model_hash'] : $member['source_model_hash'];
            if ($this->contracts->modelHash($run->modelVersion) !== $originalModelHash) {
                if (! $member || $originalModelHash !== $member['source_model_hash'] || $materializations !== []) {
                    throw new LogicException('COUNCIL_TECHNICAL_REPAIR_ORIGINAL_NATIVE_MODEL_DRIFT');
                }
                $materializations[$member['role']] = $this->originalDescriptorMaterialization(
                    $run, $member, $strategy, $requestArtifact, $responseArtifact, $evidence);
            }
            if ($comparison) $covered[$armKey] = true;
            $rejected[] = ['run_id' => $run->run_id, 'arm_key' => $armKey, 'model_version_id' => $run->model_version_id,
                'specialist_id' => $member['specialist_id'] ?? null,
                'request_hash' => $run->request_hash, 'request_artifact_hash' => $requestArtifact->sha256,
                'response_hash' => $responseArtifact->sha256, 'source_hash' => $run->code_hash,
                'data_hash' => $run->data_hash, 'parameter_hash' => $run->parameter_hash,
                'basis' => 'original_python_null_contract_schema_refusal_before_execution'];
        }
        // Undispatched original peers have no inferred outcomes. They must
        // nevertheless retain their full original seals, including carriers.
        $expectedModels = [];
        foreach ([...$members, ...array_values($arms)] as $source) {
            $id = $source['model_version_id']; $hash = $source['source_model_hash'] ?? $source['model_hash'];
            if (isset($expectedModels[$id]) && $expectedModels[$id] !== $hash) {
                throw new LogicException('COUNCIL_TECHNICAL_REPAIR_ORIGINAL_MODEL_OWNERS_AMBIGUOUS');
            }
            $expectedModels[$id] = $hash;
        }
        foreach ($expectedModels as $id => $hash) {
            $model = ModelVersion::find($id);
            $materialization = collect($materializations)->firstWhere('model_version_id', (int) $id);
            if (! $model || $this->contracts->modelHash($model) !== ($materialization['current_model_hash'] ?? $hash)) {
                throw new LogicException('COUNCIL_TECHNICAL_REPAIR_ORIGINAL_NATIVE_MODEL_DRIFT');
            }
        }
        return ['protocol' => 'specialist_council_original_unobserved_repair_v1', 'original_plan_hash' => $owner->plan_hash,
            'original_run_ids' => $actualIds, 'original_native_run_ids' => $runs->pluck('run_id')->all(), 'schema_rejected_arms' => $rejected,
            'undispatched_arm_keys' => array_values(array_diff(array_keys($arms), array_keys($covered))),
            'descriptor_materializations' => $materializations,
            'scientific_outcomes_observed' => false, 'http_status_inferred' => false, 'promotion_evidence' => false];
    }

    /**
     * Prove one late assignment against the original schema-rejected request.
     * This reconstructs the old seal in memory, never excludes a field from
     * current hashes or repairs the old generation's invalid preparation.
     */
    private function originalDescriptorMaterialization(LabEvaluationRun $run, array $member, array $strategy,
        LabEvidenceArtifact $requestArtifact, LabEvidenceArtifact $responseArtifact, LabImmutableEvidenceService $evidence): array
    {
        $model = $run->modelVersion; $agent = $run->agent;
        $assignment = data_get($model->metadata, 'instrument_research_assignment');
        $originalAssignment = $strategy['instrument_research_assignment'] ?? null;
        $runtime = $evidence->verifiedModelRuntimeIdentity($run);
        $original = clone $model;
        $original->metadata = [...(array) $model->metadata, 'instrument_research_assignment' => null];
        if (! is_array($assignment) || $assignment === [] || ! is_array($originalAssignment) || $originalAssignment === []
            || ! $evidence->equivalentJsonValue($assignment, $originalAssignment)
            || ($originalAssignment['protocol'] ?? null) !== LabInstrumentResearchService::PROTOCOL
            || ($originalAssignment['hash_protocol'] ?? null) !== LabInstrumentResearchService::HASH_PROTOCOL
            || ($originalAssignment['lab_agent_id'] ?? null) !== $run->lab_agent_id
            || ($originalAssignment['lab_generation_id'] ?? null) !== $run->lab_generation_id
            || ($originalAssignment['model_version_id'] ?? null) !== $model->id
            || ($originalAssignment['strategy'] ?? null) !== $model->strategy
            || ! is_string($originalAssignment['assignment_hash'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/D', $originalAssignment['assignment_hash'])
            || ! $runtime || ! $agent || $run->parameter_hash !== $evidence->parameterHash($agent)
            || $runtime['parameter_hash'] !== $this->epochs->parameterHash((array) $model->parameters)
            || ! $evidence->equivalentJsonValue($strategy['parameters'] ?? null, (array) $model->parameters)
            || ($strategy['strategy'] ?? null) !== $model->strategy
            || $this->contracts->modelHash($original) !== $member['source_model_hash']) {
            throw new LogicException('COUNCIL_TECHNICAL_REPAIR_ORIGINAL_DESCRIPTOR_MATERIALIZATION_NOT_PROVEN');
        }
        return ['protocol' => 'specialist_council_original_descriptor_materialization_v1',
            'model_version_id' => $model->id, 'specialist_id' => $member['specialist_id'],
            'descriptor_field' => 'instrument_research_assignment', 'original_value' => null,
            'original_member_hash' => $member['source_model_hash'], 'current_model_hash' => $this->contracts->modelHash($model),
            'original_request_assignment_hash' => $this->epochs->parameterHash($originalAssignment),
            'current_assignment_hash' => $this->epochs->parameterHash($assignment),
            'assignment_hash' => $originalAssignment['assignment_hash'], 'equivalence' => 'verified_numerical_json_value',
            'original_run_id' => $run->run_id, 'original_request_hash' => $run->request_hash,
            'request_artifact_hash' => $requestArtifact->sha256, 'response_artifact_hash' => $responseArtifact->sha256,
            'model_runtime_identity_artifact_hash' => $runtime['artifact_hash'],
            'original_parameter_hash' => $run->parameter_hash, 'runtime_parameter_hash' => $runtime['parameter_hash'],
            'runtime_basis_hash' => $this->epochs->parameterHash($runtime['runtime_basis']),
            'original_preparation_remains_invalid' => true, 'scientific_outcomes_observed' => false, 'promotion_evidence' => false];
    }

    private function followupBlocked(string $reason): array
    {
        return ['protocol' => self::FOLLOWUP_PROTOCOL, 'status' => 'blocked', 'reason' => $reason,
            'executable' => false, 'authority' => 'research_only', 'promotion_evidence' => false];
    }

    /** A recomputed public hash or executable flag is not server preregistration. */
    private function followupServerSeal(array $body): string
    {
        $key = (string) config('services.internal_api.token', '');
        if (strlen($key) < 32) throw new LogicException('COUNCIL_FOLLOWUP_SERVER_SEAL_KEY_UNAVAILABLE');
        return hash_hmac('sha256', self::FOLLOWUP_PROTOCOL."\n".$this->epochs->parameterHash($body), $key);
    }

    /** Bounded prior scoped observations may guide a new question, not a champion or risk gate. */
    public function priorObservations(string $councilId, int $limit = 8): array
    {
        if (! Schema::hasTable('research_experiment_receipts')) return [];
        return ResearchExperimentReceipt::where('source_type', SpecialistCouncilVersion::class)
            ->where('payload->contract->scope->council_id', $councilId)->orderByDesc('id')->limit(max(1, min(32, $limit)))
            ->get()->filter(fn (ResearchExperimentReceipt $receipt): bool => $this->priorReceiptValid($receipt))
            ->map(fn (ResearchExperimentReceipt $receipt): array => $this->observationFromReceipt($receipt))->values()->all();
    }

    /** Revalidate a sealed snapshot by exact IDs, not today's possibly changed top-eight ranking. */
    public function assertPriorObservations(string $councilId, array $snapshot): void
    {
        if (! array_is_list($snapshot) || count($snapshot) > 8
            || count(array_unique(array_column($snapshot, 'receipt_id'))) !== count($snapshot)) {
            throw new LogicException('COUNCIL_PRIOR_RESEARCH_SNAPSHOT_UNBOUNDED_OR_DUPLICATED');
        }
        foreach ($snapshot as $observation) {
            $receipt = is_array($observation) ? ResearchExperimentReceipt::find($observation['receipt_id'] ?? 0) : null;
            if (! $receipt || $receipt->source_type !== SpecialistCouncilVersion::class
                || data_get($receipt->payload, 'contract.scope.council_id') !== $councilId
                || ! $this->priorReceiptValid($receipt)
                || $this->epochs->parameterHash($this->observationFromReceipt($receipt)) !== $this->epochs->parameterHash($observation)) {
                throw new LogicException('COUNCIL_PRIOR_RESEARCH_SNAPSHOT_ORIGINAL_RECEIPT_INVALID');
            }
        }
    }

    /** A new council label cannot respend a completed same-release physical question. */
    public function completedQuestionForSource(string $fingerprint, string $currentCodeHash): ?array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $fingerprint) || ! preg_match('/^[a-f0-9]{64}$/', $currentCodeHash)) {
            throw new LogicException('COUNCIL_COMPLETED_QUESTION_SOURCE_IDENTITY_INVALID');
        }
        if (! Schema::hasTable('research_experiment_receipts')) return null;
        $receipts = ResearchExperimentReceipt::where('source_type', SpecialistCouncilVersion::class)
            ->where('payload->contract->identity->research_question_fingerprint', $fingerprint)
            ->orderByDesc('id')->limit(32)->get();
        foreach ($receipts as $receipt) {
            if (! $this->priorReceiptValid($receipt)
                || data_get($receipt->payload, 'evidence.research_observation_status') !== 'research_compared') continue;
            $planRow = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $receipt->source_id)->first();
            $plan = $planRow ? json_decode($planRow->plan, true, 512, JSON_THROW_ON_ERROR) : [];
            $sources = (array) data_get($receipt->payload, 'evidence.original_sources', []);
            $runIds = (array) data_get($receipt->payload, 'evidence.original_run_ids', []);
            if (count($sources) !== count($plan['arms'] ?? []) || count($runIds) !== count($sources)
                || count((array) data_get($receipt->payload, 'evidence.comparisons', [])) !== count($plan['windows'] ?? [])
                || ! collect($sources)->every(fn (array $source): bool => ($source['code_hash'] ?? '') === $currentCodeHash)) continue;
            $covered = true;
            foreach ($plan['windows'] as $key => $window) {
                $windowArms = array_values(array_filter($plan['arms'], fn (array $arm): bool => $arm['window_key'] === $key));
                $kinds = array_column($windowArms, 'kind');
                if (count(array_filter($kinds, fn (string $kind): bool => $kind === 'candidate')) !== 1
                    || count(array_filter($kinds, fn (string $kind): bool => $kind === 'solo')) !== 1
                    || ! in_array('ablation', $kinds, true)) $covered = false;
            }
            if ($covered) return [...$this->observationFromReceipt($receipt), 'completed_original_arm_count' => count($sources),
                'current_code_hash' => $currentCodeHash, 'same_release_completed_question' => true];
        }
        return null;
    }

    private function observationFromReceipt(ResearchExperimentReceipt $receipt): array
    {
        return ['receipt_id' => $receipt->id,
                'receipt_key' => $receipt->receipt_key, 'classification' => $receipt->classification,
                'contract_hash' => $receipt->contract_hash, 'evidence_hash' => $receipt->evidence_hash,
                'research_question_fingerprint' => data_get($receipt->payload, 'contract.identity.research_question_fingerprint'),
                'original_run_ids' => data_get($receipt->payload, 'evidence.original_run_ids'),
                'plan_hash' => data_get($receipt->payload, 'evidence.plan_hash'),
                'assessment_hash' => data_get($receipt->payload, 'evidence.assessment_hash'),
                'economic_direction' => data_get($receipt->payload, 'evidence.economic_direction'),
                'scope' => data_get($receipt->payload, 'contract.scope'), 'selection_authority' => 'research_only',
                'promotion_evidence' => false];
    }

    /** Fresh row/version labels do not renew the same physical research question. */
    public function questionFingerprint(array $manifest, array $plan): string
    {
        $members = array_map(fn (array $member): array => array_intersect_key($member, array_flip([
            'role', 'strategy', 'parameters', 'scope', 'horizon', 'capital_weight', 'risk_per_trade_percent',
            'sensor_timeframes', 'allowed_actions', 'data_requirements', 'operator_contract',
        ])), $manifest['members']);
        usort($members, fn (array $left, array $right): int => strcmp($this->epochs->parameterHash($left), $this->epochs->parameterHash($right)));
        $windows = array_map(fn (array $window): array => [
            'start_inclusive' => $window['start_inclusive'], 'end_exclusive' => $window['end_exclusive'],
            'evaluation_scope' => array_intersect_key((array) ($window['evaluation_scope'] ?? []), array_flip([
                'start_inclusive', 'end_exclusive', 'rows', 'decision_rows', 'warmup_rows',
            ])),
        ], array_values($plan['windows']));
        usort($windows, fn (array $left, array $right): int => strcmp($left['start_inclusive'], $right['start_inclusive']));
        return $this->epochs->parameterHash(['members' => $members, 'windows' => $windows,
            'components' => array_map(fn (array $component): array => array_diff_key($component, ['id' => true, 'version' => true]), (array) ($manifest['components'] ?? [])),
            'execution' => array_diff_key($manifest['execution'], ['id' => true, 'version' => true]),
            'risk_policy' => $plan['risk_policy'], 'cost_model' => $plan['cost_model'],
            'initial_capital' => $plan['initial_capital'], 'execution_timeframe' => $plan['execution_timeframe'],
            'objective' => $plan['objective'] ?? $manifest['evaluation_policy']['objective']]);
    }

    private function priorReceiptValid(ResearchExperimentReceipt $receipt): bool
    {
        try {
            if (data_get($receipt->payload, 'evidence.protocol') !== self::PROTOCOL
                || $this->epochs->parameterHash((array) data_get($receipt->payload, 'contract', [])) !== $receipt->contract_hash
                || $this->epochs->parameterHash((array) data_get($receipt->payload, 'evidence', [])) !== $receipt->evidence_hash) return false;
            $version = SpecialistCouncilVersion::find($receipt->source_id);
            $exam = DB::table('specialist_council_evaluations')->where('specialist_council_version_id', $receipt->source_id)->first();
            $plan = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $receipt->source_id)->first();
            if (! $version || ! $exam || ! $plan || ! $this->contracts->manifestValid($version->manifest)
                || $version->manifest_hash !== data_get($receipt->payload, 'contract.scope.manifest_hash')
                || $version->assessment_hash !== data_get($receipt->payload, 'evidence.assessment_hash')
                || $this->epochs->parameterHash((array) $version->assessment) !== $version->assessment_hash
                || $exam->assessment_hash !== $version->assessment_hash
                || $this->epochs->parameterHash(json_decode($exam->assessment, true, 512, JSON_THROW_ON_ERROR)) !== $exam->assessment_hash
                || $plan->plan_hash !== data_get($receipt->payload, 'evidence.plan_hash')
                || $this->epochs->parameterHash(json_decode($plan->plan, true, 512, JSON_THROW_ON_ERROR)) !== $plan->plan_hash
                || json_decode($exam->original_run_ids, true, 512, JSON_THROW_ON_ERROR) !== data_get($receipt->payload, 'evidence.original_run_ids')) return false;
            foreach ((array) data_get($receipt->payload, 'evidence.original_sources', []) as $source) {
                $run = LabEvaluationRun::where('run_id', $source['run_id'] ?? '')->first();
                if (! $run || $run->status !== 'completed' || ! $run->finished_at) return false;
                foreach (['request_hash', 'response_hash', 'data_hash', 'parameter_hash', 'code_hash'] as $hash) {
                    if (! is_string($source[$hash] ?? null) || ! hash_equals((string) $run->{$hash}, $source[$hash])) return false;
                }
            }
            return true;
        } catch (\Throwable) { return false; }
    }

    private function closure(SpecialistCouncilVersion $version, array $assessment, string $status): array
    {
        $base = ['identity' => $version->assessment_hash, 'priority' => 5,
            'owner' => ResearchLoopArbiterService::class, 'executor' => ResearchExperimentWorkConsumerService::class,
            'executable' => false, 'version_id' => $version->id, 'manifest_hash' => $version->manifest_hash,
            'assessment_hash' => $version->assessment_hash, 'same_evidence_replay_forbidden' => true];
        if ($status === 'technical_unassessable') return ['TECHNICAL_QUARANTINE', [...$base,
            'type' => 'specialist_council_technical_repair', 'dependency_key' => 'council_original_evidence_repair:'.$version->id,
            'retry_condition' => ['code' => 'NEW_SEALED_ORIGINAL_COUNCIL_EVIDENCE_REQUIRED', 'max_experiments' => 1,
                'same_evidence_replay_forbidden' => true]], []];
        if ($status === 'data_missing') return ['INCONCLUSIVE', [...$base,
            'type' => 'specialist_council_data_repair', 'dependency_key' => 'council_execution_data_owner_receipt:'.$version->id,
            'retry_condition' => ['code' => 'VERIFIED_EXECUTION_DATA_PREREQUISITES_REQUIRED', 'max_experiments' => 1,
                'same_evidence_replay_forbidden' => true]], []];
        if ($status === 'underpowered') return ['UNDERPOWERED', [...$base,
            'type' => 'specialist_council_power_extension', 'dependency_key' => 'prospective_council_powered_scope:'.$version->id,
            'retry_condition' => ['code' => 'NEW_PREREGISTERED_POWERED_SCOPE_REQUIRED', 'max_experiments' => 1,
                'same_evidence_replay_forbidden' => true]], []];
        if ($status !== 'research_compared') throw new LogicException('UNKNOWN_COUNCIL_RESEARCH_OBSERVATION_STATUS');
        if (($assessment['qualified'] ?? false) === true) return ['POSITIVE_CANDIDATE', [...$base,
            'type' => 'specialist_council_descendant_transfer',
            'dependency_key' => 'prospective_council_descendant_and_ablation:'.$version->id,
            'retry_condition' => ['code' => 'NEW_CONTROLLED_DESCENDANT_TRAIT_ABLATION_AND_AUTHORIZED_WINDOW_REQUIRED',
                'max_experiments' => 1, 'same_evidence_replay_forbidden' => true]], []];
        $direction = $this->economicDirection($assessment);
        if ($direction === 'locally_promising') return ['BEHAVIORAL_ACTIVATION_HYPOTHESIS', [...$base,
            'type' => 'specialist_council_independent_validation',
            'dependency_key' => 'authorized_unused_council_validation:'.$version->id,
            'data_policy' => ['paper_2026_is_research' => false, 'minimum_research_year' => 2027,
                'unused_authorized_window_required' => true, 'original_plan_does_not_authorize_validation' => true],
            'retry_condition' => ['code' => 'AUTHORIZED_UNUSED_INDEPENDENT_COUNCIL_WINDOW_REQUIRED',
                'max_experiments' => 1, 'same_evidence_replay_forbidden' => true]], []];
        return ['INCONCLUSIVE', [], ['code' => $direction === 'local_negative'
            ? 'SCOPED_COUNCIL_COMPARISON_NOT_BETTER_THAN_SOLO' : 'SCOPED_COUNCIL_COMPARISON_NO_INCREMENTAL_BENEFIT',
            'hypothesis_closed_for_original_manifest_and_window' => true,
            'new_question_requires_new_preregistered_contract' => true, 'global_harmful_ban' => false]];
    }

    private function economicDirection(array $assessment): string
    {
        $comparisons = (array) ($assessment['comparisons'] ?? []);
        if ($comparisons === []) return 'unassessable';
        if (($assessment['research_observation_status'] ?? '') !== 'research_compared') return 'not_powered_or_assessable';
        $localRiskFailure = in_array('COUNCIL_EXTERNAL_RISK_LIMIT_EXCEEDED', (array) ($assessment['reason_codes'] ?? []), true);
        if (! $localRiskFailure && collect($comparisons)->contains(fn (array $comparison): bool =>
            ($comparison['incremental_value'] ?? false) && ($comparison['powered'] ?? false)
            && ! empty($comparison['ablations']) && collect($comparison['ablations'])->every(fn (array $ablation): bool =>
                ($ablation['incremental_value_observed'] ?? false)))) return 'locally_promising';
        return collect($comparisons)->every(fn (array $comparison): bool => ($comparison['net_profit_delta_vs_solo'] ?? 0) < 0)
            ? 'local_negative' : 'local_null';
    }
}
