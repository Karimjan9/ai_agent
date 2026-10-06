<?php

namespace App\Services;

use App\Models\LabEvaluationRun;
use App\Models\AgentLearningCausalExperiment;
use App\Models\LabAgent;
use App\Models\LabEvidenceArtifact;
use App\Models\ModelVersion;
use App\Models\SpecialistCouncilVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;

/** One versioned admission owner; candidate research never bypasses native paper authority. */
class SpecialistCouncilLifecycleService
{
    public const PLAN_PROTOCOL = 'specialist_council_evaluation_plan_v1';
    public const ASSESSMENT_PROTOCOL = 'specialist_council_independent_assessment_v1';
    public const BINDING_PROTOCOL = 'specialist_council_binding_v1';
    public const SUPPORT_QUALIFICATION_PROTOCOL = 'specialist_support_role_qualification_v1';

    /** Read-only original question intake before any arm outcome, not caller features or a new dispatcher. */
    public function nativePolicyQuestionSpec(int $versionId, string $windowKey, string $evaluatorId, bool $requireUnobserved = true): array
    {
        return $this->nativePolicySpec($versionId, $windowKey, $evaluatorId, $requireUnobserved);
    }

    /** Read-only research-parent proof; does not call approve or grant paper/economic authority. */
    public function qualifiedOriginalResearchProof(SpecialistCouncilVersion $version): array
    {
        try {
            $version = $this->verified($version->fresh()); $owner = $this->plan($version);
            $this->independentActor($version, $owner['evaluator_id']);
            $exam = DB::table('specialist_council_evaluations')->where('specialist_council_version_id', $version->id)->first();
            if ($owner['plan']['purpose'] !== 'independent' || ! $exam || $exam->evaluator_id !== $owner['evaluator_id']) {
                throw new LogicException('ORIGINAL_INDEPENDENT_RESEARCH_PARENT_REQUIRED');
            }
            $runIds = json_decode($exam->original_run_ids, true, 512, JSON_THROW_ON_ERROR);
            $stored = json_decode($exam->assessment, true, 512, JSON_THROW_ON_ERROR);
            foreach ($runIds as $runId) {
                $run = LabEvaluationRun::where('run_id', $runId)->firstOrFail();
                $this->assertArchivedOriginalRelease($run, $this->requestForRun($run, $this->originalArtifact($run, 'evaluation_request')),
                    $this->originalArtifact($run, 'evaluation_response'), $owner['plan']);
            }
            $assessment = $this->assessOriginalRuns($version, $owner, $runIds);
            if (($assessment['qualified'] ?? false) !== true || $this->epochs->parameterHash($stored) !== $exam->assessment_hash
                || $exam->assessment_hash !== $version->assessment_hash || $this->epochs->parameterHash($assessment) !== $exam->assessment_hash) {
                throw new LogicException('ORIGINAL_RESEARCH_PARENT_PROOF_INVALID_OR_UNQUALIFIED');
            }
            return ['allowed' => true, 'status' => 'original_independent_research_parent', 'reason' => null,
                'source_version_id' => $version->id, 'manifest_hash' => $version->manifest_hash, 'plan_hash' => $owner['hash'],
                'assessment_hash' => $exam->assessment_hash, 'evaluator_id' => $exam->evaluator_id, 'original_run_ids' => $runIds,
                'qualified_roles' => $assessment['qualified_roles'], 'economic_parent' => false, 'paper_authority_granted' => false,
                'promotion_evidence' => false];
        } catch (\Throwable $error) {
            return ['allowed' => false, 'status' => 'blocked', 'reason' => $error instanceof LogicException ? $error->getMessage() : 'ORIGINAL_RESEARCH_PARENT_PRODUCER_REQUIRED',
                'economic_parent' => false, 'paper_authority_granted' => false, 'promotion_evidence' => false];
        }
    }

    public function inspectOriginalQualification(SpecialistCouncilVersion $version): array
    {
        return $this->qualifiedOriginalResearchProof($version);
    }

    /** Original native calls/behavior, not a component label or support-role qualification. */
    public function executedOriginalTraitProof(SpecialistCouncilVersion $version, string $componentId): array
    {
        $version = $this->verified($version); $owner = $this->plan($version);
        $exam = DB::table('specialist_council_evaluations')->where('specialist_council_version_id', $version->id)->sole();
        $runIds = json_decode($exam->original_run_ids, true, 512, JSON_THROW_ON_ERROR); $observed = [];
        foreach ($runIds as $runId) {
            $run = LabEvaluationRun::where('run_id', $runId)->firstOrFail();
            $request = $this->requestForRun($run, $this->originalArtifact($run, 'evaluation_request'));
            $arm = $owner['plan']['arms'][data_get($request, 'specialist_council_evaluation.arm_key', '')] ?? null;
            if (! $arm || $arm['kind'] !== 'candidate') continue;
            $response = $this->originalArtifact($run, 'evaluation_response');
            $this->assertArchivedOriginalRelease($run, $request, $response, $owner['plan']);
            $receipt = $this->attestReplayResult(ModelVersion::findOrFail($arm['model_version_id']), $request, $response);
            $proof = $this->nativeTraitObservation($version, $componentId, (array) $receipt);
            if ($proof['reason_codes'] !== [] || isset($observed[$arm['window_key']])) {
                throw new LogicException('COUNCIL_DESCENDANT_ORIGINAL_EXECUTED_TRAIT_REQUIRED');
            }
            $observed[$arm['window_key']] = ['run_id' => $run->run_id, 'request_hash' => $run->request_hash,
                'response_hash' => $run->response_hash, 'operator_observation' => $proof];
        }
        if (count($observed) !== count($owner['plan']['windows'])) throw new LogicException('COUNCIL_DESCENDANT_ORIGINAL_EXECUTED_TRAIT_REQUIRED');
        return ['protocol' => 'executed_original_council_trait_v1', 'component_id' => $componentId,
            'source_version_id' => (int) $version->id, 'plan_hash' => $owner['hash'], 'original_candidate_windows' => $observed,
            'promotion_evidence' => false, 'paper_authority_granted' => false];
    }

    private function nativeTraitObservation(SpecialistCouncilVersion $version, string $componentId, array $receipt): array
    {
        $producers = [];
        foreach ($version->manifest['members'] as $member) {
            $operator = $member['operator_contract'] ?? null;
            if (! is_array($operator) || ($operator['component_id'] ?? null) !== $componentId) continue;
            $producers[$member['specialist_id']] = \Illuminate\Support\Arr::only($operator, ['contract_hash', 'ast_hash', 'source_task_key', 'target', 'budget']);
        }
        return $this->nativeSupportObservation(['benchmark' => 'native_operator_behavior', 'producer_contracts' => $producers,
            'minimum_evaluations' => 1, 'minimum_behavior_delta_decisions' => 1], ['receipt' => $receipt]);
    }

    private function nativePolicySpec(int $versionId, string $windowKey, string $evaluatorId, bool $unobserved, ?string $source = null): array
    {
        $version = $this->verified(SpecialistCouncilVersion::findOrFail($versionId));
        $owner = $this->plan($version); $plan = $owner['plan']; $this->independentActor($version, $evaluatorId);
        $window = $plan['windows'][$windowKey] ?? null;
        if ($owner['evaluator_id'] !== $evaluatorId || $plan['purpose'] !== 'independent' || ! is_array($window)) {
            throw new LogicException('AUTHORIZED_ORIGINAL_NATIVE_POLICY_QUESTION_REQUIRED');
        }
        $authorized = $this->authorizedPlanWindow($window, $window['dataset_sha256']);
        if (! $unobserved && ! $authorized) {
            $ids = array_column(array_filter($plan['arms'], fn ($arm) => $arm['window_key'] === $windowKey), 'model_version_id');
            $runs = LabEvaluationRun::whereIn('model_version_id', $ids)->where('status', 'completed')->orderBy('id')->limit(129)->get();
            if ($runs->count() > 128) throw new LogicException('ORIGINAL_NATIVE_POLICY_HISTORY_BOUND_EXCEEDED');
            foreach ($runs as $run) {
                $observation = $this->originalIndependentRunObservation($version, $run);
                if (($observation['window_key'] ?? null) === $windowKey) { $authorized = true; break; }
            }
        }
        if (! $authorized) throw new LogicException('AUTHORIZED_ORIGINAL_NATIVE_POLICY_QUESTION_REQUIRED');
        foreach ($version->manifest['members'] as $member) foreach ($member['scope']['symbols'] as $symbol) {
            if (app(SpecialistCouncilDataUseService::class)->intervalExposed($version, $symbol,
                $window['start_inclusive'], $window['end_exclusive'])) throw new LogicException('NATIVE_POLICY_QUESTION_EVENTS_PREVIOUSLY_EXPOSED');
        }
        $arms = array_filter($plan['arms'], fn ($arm) => $arm['window_key'] === $windowKey);
        if (! $this->findArm($arms, 'candidate') || ! $this->findArm($arms, 'solo')
            || ! $this->findArm($arms, 'champion') || ! $this->findArm($arms, 'retention')
            || ! collect($arms)->contains(fn ($arm) => $arm['kind'] === 'ablation')) {
            throw new LogicException('ORIGINAL_NATIVE_POLICY_COMPARATORS_AND_RETENTION_REQUIRED');
        }
        if ($unobserved && LabEvaluationRun::whereIn('model_version_id', array_column($plan['arms'], 'model_version_id'))->exists()) {
            throw new LogicException('OBSERVED_NATIVE_POLICY_QUESTION_CANNOT_BE_PREREGISTERED');
        }
        if ($unobserved) app(SpecialistCouncilIndependentPanelService::class)->assertUnobservedOriginalPhysicalQuestion($version, $plan, $windowKey);
        $source ??= $plan['preparation_source_hash'] ?? $this->evidence->codeHash();
        if (! preg_match('/^[a-f0-9]{64}$/D', (string) $source)
            || (isset($plan['preparation_source_hash']) && $plan['preparation_source_hash'] !== $source)) {
            throw new LogicException('ORIGINAL_NATIVE_POLICY_SOURCE_REQUIRED');
        }
        $physical = ['manifest_hash' => $version->manifest_hash, 'plan_hash' => $owner['hash'],
            'window' => $window, 'objective' => $plan['objective'], 'arms' => $arms];
        // A new version/plan label does not make an unchanged physical question distinct.
        $question = $this->epochs->parameterHash(['members' => array_map(fn ($member) => [
            'parameters' => $member['parameters'], 'role' => $member['role'], 'scope' => $member['scope'],
            'horizon' => $member['horizon']], $version->manifest['members']), 'objective' => $plan['objective'],
            'window' => $window, 'risk_policy' => $plan['risk_policy'], 'cost_model' => $plan['cost_model']]);
        $ceiling = array_sum(array_map(fn ($member) => max(1, (float) ($member['resources']['max_compute_ms']
            ?? $member['resources']['cpu_budget_ms'] ?? 1000)) / 1000, $version->manifest['members']));
        return ['version_id' => $versionId, 'window_key' => $windowKey, 'window' => $window,
            'manifest_hash' => $version->manifest_hash, 'plan_hash' => $owner['hash'], 'physical_hash' => $this->epochs->parameterHash($physical),
            'scope' => array_column($version->manifest['members'], 'scope'),
            'evaluator_id' => $evaluatorId, 'source_hash' => $source, 'question_hash' => $question,
            // These sealed resource/opportunity proxies are not learned causal forecasts.
            // Unknown expected value/progress stay zero rather than being invented.
            'selector_input' => ['question_id' => $question, 'ready' => true, 'safety_preserved' => true,
                'cost_ceiling_seconds' => $ceiling, 'features' => ['expected_value' => 0,
                    'information_gain' => 1 / (count($arms) + 1), 'learning_progress' => 0,
                    'diversity' => count(array_unique(array_column($version->manifest['members'], 'role'))) / 4]]];
    }

    /** A single original observation proves exposure, never whole-council qualification. */
    public function originalIndependentRunObservation(SpecialistCouncilVersion $version, LabEvaluationRun $run): ?array
    {
        if ($run->status !== 'completed' || ! $run->finished_at) return null;
        $version = $this->verified($version); $owner = $this->plan($version); $plan = $owner['plan'];
        if ($plan['purpose'] !== 'independent') return null;
        $request = $this->requestForRun($run, $this->originalArtifact($run, 'evaluation_request'));
        $binding = $request['specialist_council_evaluation'] ?? null;
        if (! is_array($binding) || ($binding['version_id'] ?? null) !== (int) $version->id) return null;
        $armKey = $binding['arm_key'] ?? null; $arm = $plan['arms'][$armKey] ?? null;
        $window = $plan['windows'][$arm['window_key'] ?? ''] ?? null; $model = $run->modelVersion;
        if (! $arm || ! $window || ! $model || (int) $run->model_version_id !== (int) $arm['model_version_id']
            || ($binding['manifest_hash'] ?? null) !== $version->manifest_hash || ($binding['plan_hash'] ?? null) !== $owner['hash']
            || $run->phase !== $arm['evaluation_phase'] || $run->data_hash !== $window['dataset_sha256']
            || $this->contracts->modelHash($model) !== $arm['model_hash'] || ! $run->started_at
            || $run->started_at->lt($owner['sealed_at']) || ! $this->evidence->verifiedModelRuntimeIdentity($run)) {
            throw new LogicException('ORIGINAL_INDEPENDENT_OBSERVATION_OWNER_INVALID');
        }
        $response = $this->originalArtifact($run, 'evaluation_response');
        $archive = $this->assertArchivedOriginalRelease($run, $request, $response, $plan);
        $this->authorizedOriginalPlanWindow($window, $run, $request, $response, $plan, $archive);
        $this->assertOriginalArmScope($arm, $request, $response, $plan['execution_timeframe']);
        return ['version_id' => (int) $version->id, 'manifest_hash' => $version->manifest_hash, 'plan_hash' => $owner['hash'],
            'window_key' => $arm['window_key'], 'arm_key' => $armKey, 'run_id' => $run->run_id,
            'request_hash' => $run->request_hash, 'response_hash' => $run->response_hash, 'source_hash' => $run->code_hash,
            'symbol' => (string) ($request['symbol'] ?? ''),
            'start_inclusive' => $arm['evaluation_scope']['start_inclusive'], 'end_exclusive' => $arm['evaluation_scope']['end_exclusive'],
            'started_at' => $run->started_at->utc()->toIso8601String(), 'observed_at' => $run->finished_at->utc()->toIso8601String(),
            'registered_at' => $owner['sealed_at']->toIso8601String(), 'qualified' => false, 'promotion_evidence' => false];
    }

    /** Only original independent exams and immutable artifacts can supply policy question utility/cost. */
    public function nativePolicyQuestionOutcome(array $spec, string $registeredAt, string $evaluatorId): array
    {
        try {
            $current = $this->nativePolicySpec($spec['version_id'], $spec['window_key'], $evaluatorId, false, $spec['source_hash']);
            if ($this->epochs->parameterHash($current) !== $this->epochs->parameterHash($spec)) throw new LogicException('ORIGINAL_NATIVE_POLICY_QUESTION_DRIFT');
            $version = $this->verified(SpecialistCouncilVersion::findOrFail($spec['version_id'])); $owner = $this->plan($version);
            $exam = DB::table('specialist_council_evaluations')->where('specialist_council_version_id', $version->id)->first();
            if (! $exam || $exam->evaluator_id !== $evaluatorId) throw new LogicException('ORIGINAL_NATIVE_POLICY_EXAM_REQUIRED');
            $runIds = json_decode($exam->original_run_ids, true, 512, JSON_THROW_ON_ERROR);
            $assessment = $this->assessOriginalRuns($version, $owner, $runIds);
            if ($this->epochs->parameterHash($assessment) !== $exam->assessment_hash || $exam->assessment_hash !== $version->assessment_hash
                || ($assessment['research_observation_status'] ?? '') !== 'research_compared') throw new LogicException('ORIGINAL_NATIVE_POLICY_ASSESSMENT_UNASSESSABLE');
            $comparison = collect($assessment['comparisons'])->firstWhere('window_key', $spec['window_key']);
            if (! $comparison) throw new LogicException('ORIGINAL_NATIVE_POLICY_WINDOW_COMPARISON_REQUIRED');
            $keys = array_keys(array_filter($owner['plan']['arms'], fn ($arm) => $arm['window_key'] === $spec['window_key']));
            $sources = []; $wall = 0; $seen = [];
            foreach (LabEvaluationRun::whereIn('run_id', $runIds)->get() as $run) {
                $request = $this->requestForRun($run, $this->originalArtifact($run, 'evaluation_request')); $binding = $request['specialist_council_evaluation'] ?? [];
                if (! in_array($binding['arm_key'] ?? '', $keys, true)) continue;
                if (isset($seen[$binding['arm_key']]) || $run->code_hash !== $spec['source_hash']
                    || ! $run->started_at || $run->started_at->lt($this->time($registeredAt))
                    || ! $run->finished_at || ! is_numeric($run->duration_ms) || $run->duration_ms < 0
                    || abs($run->duration_ms - $run->started_at->diffInMilliseconds($run->finished_at)) > 1) {
                    throw new LogicException('ORIGINAL_NATIVE_POLICY_RESOURCE_OR_PREREGISTRATION_INVALID');
                }
                $seen[$binding['arm_key']] = true; $wall += $run->duration_ms / 1000;
                $sources[] = ['run_id' => $run->run_id, 'request_hash' => $run->request_hash,
                    'response_hash' => $run->response_hash, 'code_hash' => $run->code_hash, 'duration_ms' => $run->duration_ms];
            }
            if (count($seen) !== count($keys) || $wall <= 0) throw new LogicException('ORIGINAL_NATIVE_POLICY_COMPLETE_RESOURCE_WITNESS_REQUIRED');
            return ['status' => 'original_independent_question_observed', 'question_hash' => $spec['question_hash'],
                'assessment_hash' => $exam->assessment_hash, 'original_sources' => $sources,
                'powered' => ($comparison['powered'] ?? false) === true, 'positive' => ($comparison['incremental_value'] ?? false) === true
                    && ($assessment['qualified'] ?? false) === true, 'end_to_end_wall_seconds' => $wall,
                'window' => $spec['window'], 'economic_authority' => false];
        } catch (\Throwable $error) {
            return ['status' => 'dependency', 'reason' => $error instanceof LogicException ? $error->getMessage() : 'ORIGINAL_NATIVE_POLICY_PRODUCER_REQUIRED'];
        }
    }

    public function __construct(
        private SpecialistCouncilContractService $contracts,
        private ResearchPaperEpochContractService $epochs,
        private LabImmutableEvidenceService $evidence,
    ) {}

    public function registerDraft(array $manifest, string $creatorId): SpecialistCouncilVersion
    {
        $this->actor($creatorId);
        $sealed = $this->contracts->sealManifest($manifest);
        $this->assertResearchSupportConsumptions($sealed);
        return DB::transaction(function () use ($sealed, $creatorId): SpecialistCouncilVersion {
            $existing = SpecialistCouncilVersion::where('council_id', $sealed['council_id'])
                ->where('version', $sealed['version'])->lockForUpdate()->first();
            if ($existing) {
                if ($existing->creator_id !== $creatorId || ! hash_equals($existing->manifest_hash, $sealed['manifest_hash'])) {
                    throw new LogicException('Council version identity already belongs to different sealed content.');
                }
                return $existing;
            }
            return SpecialistCouncilVersion::create(['council_id' => $sealed['council_id'], 'version' => $sealed['version'],
                'creator_id' => $creatorId, 'state' => 'draft', 'manifest' => $sealed,
                'manifest_hash' => $sealed['manifest_hash'], 'sealed_at' => now()->utc()]);
        });
    }

    /** A native lab model selects a stored version; attachment does not alter any gate or lifecycle status. */
    public function attachResearchModel(SpecialistCouncilVersion $version, ModelVersion $model): ModelVersion
    {
        $version = $this->verified($version);
        if (! in_array($version->state, ['draft', 'evaluating', 'evaluated', 'approved', 'scheduled', 'active'], true)) {
            throw new LogicException('A retired or rejected version cannot open new research.');
        }
        $model = $model->fresh() ?? $model;
        $current = data_get($model->metadata, 'specialist_council.version_id');
        if ($current !== null && (int) $current !== $version->id) throw new LogicException('A native model cannot be rebound to another council version.');
        if ($current === null && LabEvaluationRun::where('model_version_id', $model->id)->exists()) {
            throw new LogicException('A previously evaluated model cannot acquire a new council runtime identity.');
        }
        if (in_array((int) $model->id, array_map('intval', array_column($version->manifest['members'], 'model_version_id')), true)) {
            throw new LogicException('A council carrier must be distinct from its specialist member models.');
        }
        $metadata = (array) $model->metadata;
        $metadata['specialist_council'] = ['protocol' => self::BINDING_PROTOCOL, 'version_id' => $version->id,
            'council_id' => $version->council_id, 'council_version' => $version->version,
            'manifest_hash' => $version->manifest_hash, 'research_only' => true];
        $model->forceFill(['metadata' => $metadata])->save();
        return $model->fresh();
    }

    public function researchVersionForModel(ModelVersion $model): ?SpecialistCouncilVersion
    {
        if (! Schema::hasTable('specialist_council_versions')) return null;
        $binding = (array) data_get($model->metadata, 'specialist_council', []);
        $version = SpecialistCouncilVersion::find($binding['version_id'] ?? 0);
        if (! $version || ! in_array($version->state, ['draft', 'evaluating', 'evaluated', 'approved', 'scheduled', 'active'], true)
            || ($binding['protocol'] ?? null) !== self::BINDING_PROTOCOL
            || ($binding['manifest_hash'] ?? null) !== $version->manifest_hash) return null;
        try { return $this->verified($version); } catch (\Throwable) { return null; }
    }

    public function runtimeContract(SpecialistCouncilVersion $version, string $datasetHash, string $executionHash, string $timeframe, ?array $mtfBundle = null, ?string $symbol = null): array
    {
        $version = $this->verified($version);
        $this->assertResearchSupportConsumptions($version->manifest);
        $native = [];
        if (method_exists(LabAgentEvaluationService::class, 'specialistCouncilMemberPayload')) {
            $compiler = app(LabAgentEvaluationService::class);
            foreach ($version->manifest['members'] as $member) {
                if (! in_array($member['role'], SpecialistCouncilContractService::TRADING_ROLES, true)) continue;
                $model = ModelVersion::findOrFail($member['model_version_id']);
                if ($this->contracts->modelHash($model) !== $member['source_model_hash']) throw new LogicException('COUNCIL_MEMBER_NATIVE_MODEL_DRIFT');
                $native[$member['specialist_id']] = $compiler->specialistCouncilMemberPayload($model, $timeframe, $mtfBundle, $datasetHash, $symbol ?? '');
            }
        }
        $contract = $this->contracts->runtimeContract($version->manifest, $timeframe, $datasetHash, $executionHash, $native, $symbol);
        unset($contract['contract_hash']);
        $contract['version_id'] = $version->id;
        return [...$contract, 'contract_hash' => $this->epochs->parameterHash($contract), 'contract_json' => $this->json($contract)];
    }

    /** Derive a single exact removal from the frozen candidate, preserving every other treatment identity. */
    public function runtimeContractForAblation(SpecialistCouncilVersion $version, string $removedId,
        string $datasetHash, string $executionHash, string $timeframe, ?array $mtfBundle = null, ?string $symbol = null): array
    {
        $version = $this->verified($version);
        if (! in_array($removedId, $version->manifest['evaluation_policy']['required_ablations'], true)) throw new LogicException('UNSEALED_ABLATION_TARGET');
        $body = array_diff_key($this->runtimeContract($version, $datasetHash, $executionHash, $timeframe, $mtfBundle, $symbol), array_flip(['contract_hash', 'contract_json']));
        $body['members'] = array_values(array_filter($body['members'], fn (array $member): bool => $member['specialist_id'] !== $removedId));
        if ($body['members'] === []) throw new LogicException('ABLATION_HAS_NO_TRADING_MEMBER');
        $body['component_identities'] = array_values(array_filter($body['component_identities'], fn (array $component): bool => $component['id'] !== $removedId));
        foreach ($body['members'] as &$member) {
            if (data_get($member, 'operator_contract.component_id') === $removedId) unset($member['operator_contract']);
        }
        unset($member);
        $body['ablation_removed_id'] = $removedId;
        return [...$body, 'contract_hash' => $this->epochs->parameterHash($body), 'contract_json' => $this->json($body)];
    }

    public function runtimeContractForModel(ModelVersion $model, string $timeframe, string $datasetHash, string $executionHash, ?array $mtfBundle = null, ?string $symbol = null): ?array
    {
        if (data_get($model->metadata, 'specialist_council') === null) return null;
        $version = $this->researchVersionForModel($model);
        if (! $version) throw new LogicException('DECLARED_SPECIALIST_COUNCIL_BINDING_INVALID');
        $binding = $this->evaluationBindingForModel($model, $datasetHash);
        if ($binding !== null) {
            $owner = $this->plan(SpecialistCouncilVersion::findOrFail($binding['version_id']));
            $arm = $owner['plan']['arms'][$binding['arm_key']];
            if ((int) $binding['version_id'] !== (int) $version->id) {
                if (($owner['plan']['purpose'] ?? null) !== 'independent' || ! isset($owner['plan']['panel_reservation_hash'])
                    || ! in_array($arm['kind'], ['champion', 'solo'], true)) {
                    throw new LogicException('COUNCIL_ORIGINAL_COMPARATOR_RUNTIME_OWNER_CHANGED');
                }
                app(SpecialistCouncilPanelReservationService::class)->assertWindowComparator($owner['plan'], $arm, $model);
                return $this->runtimeContract($version, $datasetHash, $executionHash, $timeframe, $mtfBundle, $symbol);
            }
            if ($arm['kind'] === 'ablation') return $this->runtimeContractForAblation($version, $arm['removed_id'], $datasetHash, $executionHash, $timeframe, $mtfBundle, $symbol);
        }
        return $this->runtimeContract($version, $datasetHash, $executionHash, $timeframe, $mtfBundle, $symbol);
    }

    /** Verify actual member dispatch and the reconciled shared account against the original transported seal. */
    public function attestReplayResult(ModelVersion $model, array $originalRequest, array $result): ?array
    {
        $declared = data_get($model->metadata, 'specialist_council');
        $receipt = $result['specialist_council_receipt'] ?? data_get($result, 'data_quality.specialist_council_receipt');
        $qualityReceipt = data_get($result, 'data_quality.specialist_council_receipt');
        if ($declared === null && ($receipt === null || $receipt === [])
            && ($qualityReceipt === null || $qualityReceipt === [])
            && ! $this->requestDeclaresNativeCouncilForModel($model, $originalRequest)) return null;
        if ($declared === null || ! is_array($receipt)) throw new LogicException('SPECIALIST_COUNCIL_RECEIPT_OR_BINDING_MISSING');
        $version = $this->researchVersionForModel($model);
        if (! $version) throw new LogicException('DECLARED_SPECIALIST_COUNCIL_BINDING_INVALID');
        $runtime = $originalRequest['specialist_council_contract'] ?? null;
        if (! is_array($runtime)) {
            $agentIds = \App\Models\LabAgent::where('model_version_id', $model->id)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $matches = array_values(array_filter((array) ($originalRequest['strategies'] ?? []), function ($strategy) use ($model, $agentIds): bool {
                return is_array($strategy) && (isset($strategy['lab_agent_id'])
                    ? in_array((int) $strategy['lab_agent_id'], $agentIds, true)
                    : (($strategy['strategy'] ?? null) === $model->strategy && ($strategy['version'] ?? null) === $model->version
                        && $this->epochs->parameterHash((array) ($strategy['parameters'] ?? [])) === $this->epochs->parameterHash((array) $model->parameters)));
            }));
            if (count($matches) !== 1) throw new LogicException('ORIGINAL_COUNCIL_BATCH_MEMBER_AMBIGUOUS');
            $runtime = $matches[0]['specialist_council_contract'] ?? null;
        }
        if (! is_array($runtime)) throw new LogicException('ORIGINAL_SPECIALIST_COUNCIL_CONTRACT_MISSING');
        $body = array_diff_key($runtime, array_flip(['contract_hash', 'contract_json']));
        if (isset($runtime['contract_json'])) {
            $decoded = json_decode((string) $runtime['contract_json'], true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || ! $this->evidence->equivalentJsonValue($decoded, $body)) throw new LogicException('COUNCIL_TRANSPORT_SEMANTIC_COPY_MISMATCH');
            $body = $decoded;
        }
        if (($runtime['contract_hash'] ?? '') !== $this->epochs->parameterHash($body)) throw new LogicException('ORIGINAL_COUNCIL_CONTRACT_HASH_MISMATCH');
        $mtfBundle = empty($originalRequest['mtf_snapshot_manifest']) ? null : [
            'bundle_hash' => $originalRequest['replay_dataset_hash'] ?? '', 'manifest' => $originalRequest['mtf_snapshot_manifest']];
        $expected = $this->runtimeContract($version, (string) ($body['replay_dataset_hash'] ?? ''),
            (string) ($body['execution_hash'] ?? ''), (string) ($body['execution_timeframe'] ?? ''), $mtfBundle,
            (string) ($originalRequest['symbol'] ?? ''));
        if (isset($body['ablation_removed_id'])) {
            $binding = $this->evaluationBindingForModel($model, (string) $body['replay_dataset_hash']);
            $owner = $this->plan($version); $arm = $owner['plan']['arms'][$binding['arm_key'] ?? ''] ?? null;
            if (! $arm || $arm['kind'] !== 'ablation' || ($arm['removed_id'] ?? '') !== $body['ablation_removed_id']) {
                throw new LogicException('ORIGINAL_ABLATION_WAS_NOT_PREREGISTERED');
            }
            $expected = $this->runtimeContractForAblation($version, $arm['removed_id'], (string) $body['replay_dataset_hash'],
                (string) $body['execution_hash'], (string) $body['execution_timeframe'], $mtfBundle, (string) ($originalRequest['symbol'] ?? ''));
        }
        if ($this->epochs->parameterHash(array_diff_key($expected, array_flip(['contract_hash', 'contract_json']))) !== $this->epochs->parameterHash($body)) {
            throw new LogicException('ORIGINAL_COUNCIL_CONTRACT_PERSISTED_MANIFEST_MISMATCH');
        }
        $hash = $receipt['receipt_hash'] ?? '';
        $this->assertReceiptSeal($receipt);
        foreach (['protocol' => 'specialist_council_receipt_v1', 'contract_hash' => $runtime['contract_hash'],
            'council_id' => $body['council_id'], 'council_version' => $body['council_version'],
            'final_council_version' => $body['council_version'], 'dataset_hash' => $body['replay_dataset_hash'],
            'execution_hash' => $body['execution_hash'], 'execution_timeframe' => $body['execution_timeframe'],
            'asof_policy' => 'previous_closed_candle_next_open', 'research_only' => true,
            'scientific_evidence' => false, 'promotion_evidence' => false] as $key => $value) {
            if (($receipt[$key] ?? null) !== $value) throw new LogicException('SPECIALIST_COUNCIL_RECEIPT_IDENTITY_MISMATCH:'.$key);
        }
        $duplicate = data_get($result, 'data_quality.specialist_council_receipt');
        if ($duplicate !== null && ! $this->evidence->equivalentJsonValue($receipt, $duplicate)) throw new LogicException('COUNCIL_RESPONSE_RECEIPT_COPY_MISMATCH');
        if (! is_int($receipt['source_rows'] ?? null) || $receipt['source_rows'] < 2
            || ! $this->time($receipt['replay_end'] ?? null)->greaterThan($this->time($receipt['replay_start'] ?? null))
            || ! in_array($receipt['status'] ?? null, ['computed', 'dependency'], true)) throw new LogicException('COUNCIL_REPLAY_CHRONOLOGY_OR_STATUS_INVALID');
        $members = [];
        foreach ($body['members'] as $member) {
            $memberHash = $this->epochs->parameterHash(['council_version' => $body['council_version'], 'member' => $member]);
            $members[$member['specialist_id']] = [...$member, 'member_version_hash' => $memberHash];
        }
        $memberReceipts = (array) ($receipt['members'] ?? []);
        if (count($memberReceipts) !== count($members)) throw new LogicException('COUNCIL_MEMBER_DISPATCH_COVERAGE_MISMATCH');
        $seen = [];
        foreach ($memberReceipts as $memberReceipt) {
            $id = $memberReceipt['specialist_id'] ?? '';
            $member = $members[$id] ?? null;
            if (! $member || isset($seen[$id]) || ($memberReceipt['member_version_hash'] ?? null) !== $member['member_version_hash']
                || ($memberReceipt['council_version'] ?? null) !== $body['council_version']) throw new LogicException('COUNCIL_MEMBER_DISPATCH_IDENTITY_MISMATCH');
            foreach (['role', 'strategy_version', 'tactic_version', 'management_version'] as $key) {
                if (($memberReceipt[$key] ?? null) !== $member[$key]) throw new LogicException('COUNCIL_MEMBER_VERSION_MISMATCH');
            }
            if (! $this->evidence->equivalentJsonValue((array) ($memberReceipt['horizon'] ?? []), $member['horizon'])
                || ($receipt['status'] === 'computed' && (int) data_get($memberReceipt, 'stages.decision:observed', 0) < 1)) {
                throw new LogicException('COUNCIL_MEMBER_HORIZON_OR_ACTUAL_DISPATCH_MISSING');
            }
            $seen[$id] = true;
        }
        $account = (array) ($receipt['account'] ?? []);
        $initial = $this->number($account['initial_balance'] ?? null);
        $final = $this->number($account['final_balance'] ?? null);
        if ($initial <= 0 || ($originalRequest['initial_balance'] ?? null) === null
            || abs($initial - (float) $originalRequest['initial_balance']) > 1e-8) throw new LogicException('COUNCIL_COMMON_INITIAL_CAPITAL_MISMATCH');
        $net = 0.0; $fees = 0.0; $carry = 0.0; $embedded = 0.0; $positionIds = [];
        foreach ((array) ($receipt['position_ledger'] ?? []) as $position) {
            $member = $members[$position['specialist_id'] ?? ''] ?? null;
            $positionId = $position['position_id'] ?? '';
            if (! $member || $positionId === '' || isset($positionIds[$positionId])
                || ($position['member_version_hash'] ?? null) !== $member['member_version_hash']
                || ($position['management_owner'] ?? null) !== $member['specialist_id']
                || ($position['management_version'] ?? null) !== $member['management_version']
                || ($position['council_version'] ?? null) !== $body['council_version']
                || $this->time($position['exit_time'] ?? null)->lessThan($this->time($position['entry_time'] ?? null))) {
                throw new LogicException('COUNCIL_PINNED_POSITION_OWNERSHIP_MISMATCH');
            }
            $pnl = $this->number($position['net_pnl'] ?? null); $fee = $this->number($position['fees'] ?? null);
            $cost = $this->number($position['carry'] ?? null); $spread = $this->number($position['spread_slippage'] ?? null);
            if (min($fee, $cost, $spread) < 0 || abs($pnl - ($this->number($position['gross_pnl_after_embedded_cost'] ?? null) - $fee - $cost)) > 1e-7 * max(1, $initial)) {
                throw new LogicException('COUNCIL_POSITION_COST_ACCOUNTING_MISMATCH');
            }
            $net += $pnl; $fees += $fee; $carry += $cost; $embedded += $spread; $positionIds[$positionId] = true;
        }
        foreach (['net_pnl' => $net, 'fees' => $fees, 'carry' => $carry, 'spread_slippage' => $embedded,
            'reconciliation_error' => $final - $initial - $net, 'reserved_capital' => 0, 'open_positions' => 0] as $key => $value) {
            if (abs($this->number($account[$key] ?? null) - $value) > 1e-7 * max(1, $initial)) throw new LogicException('COUNCIL_SHARED_ACCOUNT_CONSERVATION_FAILED:'.$key);
        }
        if (abs($final - $initial - $net) > 1e-7 * max(1, $initial)) throw new LogicException('COUNCIL_SHARED_CAPITAL_RECONCILIATION_FAILED');
        foreach ((array) ($receipt['account_ledger'] ?? []) as $point) {
            $this->number($point['cash'] ?? null);
            $equity = $this->number($point['equity'] ?? null); $reserved = $this->number($point['reserved_capital'] ?? null);
            if ($reserved < 0 || abs($equity - $reserved - $this->number($point['free_capital'] ?? null)) > 1e-7 * max(1, $initial)
                || array_diff((array) ($point['owners'] ?? []), array_keys($members)) !== []) throw new LogicException('COUNCIL_CAPITAL_RESERVATION_LEDGER_INVALID');
        }
        if ($receipt['status'] === 'computed' && empty($receipt['account_ledger'])) throw new LogicException('COUNCIL_ACTUAL_ACCOUNT_OBSERVATIONS_MISSING');
        return $receipt;
    }

    /** Empty response defaults cannot erase a native declaration in the original owner request. */
    private function requestDeclaresNativeCouncilForModel(ModelVersion $model, array $request): bool
    {
        if (array_key_exists('specialist_council_contract', $request)) return true;
        $agentIds = \App\Models\LabAgent::where('model_version_id', $model->id)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        foreach ((array) ($request['strategies'] ?? []) as $strategy) {
            if (! is_array($strategy) || ! array_key_exists('specialist_council_contract', $strategy)) continue;
            $matches = isset($strategy['lab_agent_id']) ? in_array((int) $strategy['lab_agent_id'], $agentIds, true)
                : (($strategy['strategy'] ?? null) === $model->strategy && ($strategy['version'] ?? null) === $model->version
                    && $this->epochs->parameterHash((array) ($strategy['parameters'] ?? [])) === $this->epochs->parameterHash((array) $model->parameters));
            if ($matches) return true;
        }
        return false;
    }

    /** Retain the producer's exact object/list shape and float spelling through associative PHP decoding. */
    public function assertReceiptSeal(array $receipt): void
    {
        $body = array_diff_key($receipt, array_flip(['receipt_hash', 'receipt_json']));
        $hash = $receipt['receipt_hash'] ?? '';
        if (isset($receipt['receipt_json'])) {
            $json = $receipt['receipt_json'];
            if (! is_string($json) || strlen($json) > 33554432 || ! is_string($hash)
                || ! hash_equals($hash, hash('sha256', $json))) throw new LogicException('SPECIALIST_COUNCIL_PRODUCER_RECEIPT_HASH_INVALID');
            $original = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($original) || ! $this->evidence->equivalentJsonValue($body, $original)) throw new LogicException('SPECIALIST_COUNCIL_PRODUCER_RECEIPT_COPY_MISMATCH');
        } elseif (! is_string($hash) || $hash !== $this->epochs->parameterHash($body)) {
            throw new LogicException('SPECIALIST_COUNCIL_PRODUCER_RECEIPT_HASH_INVALID');
        }
    }

    /** Progress is evidence separated; drafted agents and replay activity never become master qualifications. */
    public function progressForModels(array $modelIds): array
    {
        $empty = ['protocol' => 'specialist_council_progress_v1', 'drafted_versions' => 0,
            'research_compared_versions' => 0, 'independently_qualified_versions' => 0, 'active_versions' => 0,
            'roles' => [], 'unresolved_prerequisites' => [], 'promotion_evidence' => false];
        if ($modelIds === [] || ! Schema::hasTable('specialist_council_versions')) return $empty;
        if (count($modelIds) > 1000) throw new InvalidArgumentException('Council progress query exceeds its bounded model set.');
        $models = ModelVersion::whereIn('id', $modelIds)->get(); $ids = [];
        foreach ($models as $model) {
            $id = data_get($model->metadata, 'specialist_council.version_id');
            if ($id !== null) $ids[] = (int) $id;
        }
        $progress = $empty; $gaps = [];
        foreach (SpecialistCouncilVersion::whereIn('id', array_unique($ids))->get() as $version) {
            try { $version = $this->verified($version); } catch (\Throwable) { $gaps[] = 'COUNCIL_MANIFEST_OR_BINDING_DRIFT'; continue; }
            $progress['drafted_versions']++;
            $qualified = false;
            if (is_array($version->assessment) && $this->epochs->parameterHash($version->assessment) === $version->assessment_hash) {
                if (! empty($version->assessment['comparisons']) && ! empty($version->assessment['original_sources'])) $progress['research_compared_versions']++;
                try {
                    $fresh = $this->assessOriginalRuns($version, $this->plan($version), (array) ($version->assessment['original_run_ids'] ?? []));
                    $qualified = ($fresh['qualified'] ?? false) && $this->epochs->parameterHash($fresh) === $version->assessment_hash;
                    $gaps = [...$gaps, ...$fresh['reason_codes']];
                } catch (\Throwable $e) { $gaps[] = $e->getMessage(); }
            } else $gaps[] = 'ORIGINAL_INDEPENDENT_ASSESSMENT_NOT_SETTLED';
            if ($qualified) $progress['independently_qualified_versions']++;
            if ($version->state === 'active' && $qualified && $this->paperAuthority($version, true)['allowed']) $progress['active_versions']++;
            foreach ($version->manifest['members'] as $member) {
                $role = $member['role'];
                $progress['roles'][$role] ??= ['candidates' => 0, 'independently_qualified' => 0, 'known_limits' => [], 'data_requirements' => []];
                $progress['roles'][$role]['candidates']++;
                if ($qualified && in_array($role, (array) ($version->assessment['qualified_roles'] ?? []), true)) $progress['roles'][$role]['independently_qualified']++;
                $progress['roles'][$role]['known_limits'] = array_values(array_unique([...$progress['roles'][$role]['known_limits'], ...$member['known_limits']]));
                $progress['roles'][$role]['data_requirements'] = array_values(array_unique([...$progress['roles'][$role]['data_requirements'], ...(array) ($member['data_requirements'] ?? [])]));
            }
        }
        $progress['unresolved_prerequisites'] = array_values(array_unique($gaps));
        return $progress;
    }

    /** Seal the objective, arms, costs, capital and actual windows before workers can create evidence. */
    public function sealEvaluationPlan(SpecialistCouncilVersion $version, string $evaluatorId, array $plan): array
    {
        $version = $this->verified($version);
        $this->independentActor($version, $evaluatorId);
        if ($version->state !== 'draft') throw new LogicException('An evaluation plan must precede evaluation.');
        if (! in_array($plan['purpose'] ?? null, ['research', 'independent'], true)
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($plan['execution_hash'] ?? ''))
            || ! is_numeric($plan['initial_capital'] ?? null) || $plan['initial_capital'] <= 0
            || empty($plan['cost_model']) || empty($plan['risk_policy'])) {
            throw new InvalidArgumentException('Evaluation requires a purpose, fixed execution, common capital, costs and risk.');
        }
        $this->contracts->timeframeSeconds((string) ($plan['execution_timeframe'] ?? ''));
        $windows = []; $symbols = [];
        foreach ($version->manifest['members'] as $member) $symbols = [...$symbols, ...$member['scope']['symbols']];
        foreach ((array) ($plan['windows'] ?? []) as $window) {
            $key = (string) ($window['window_key'] ?? '');
            if ($key === '' || isset($windows[$key])) throw new InvalidArgumentException('Evaluation windows must have distinct original identities.');
            if ($plan['purpose'] === 'independent') {
                $declaredScope = $window['evaluation_scope'] ?? null;
                $receipt = app(InstrumentResearchWindowService::class)->seal((string) ($window['authorization_id'] ?? ''),
                    (string) ($window['dataset_sha256'] ?? ''));
                if (! $receipt) throw new LogicException('NO_COMPLETED_AUTHORIZED_INDEPENDENT_WINDOW');
                if ($key !== $receipt['window_key']) throw new LogicException('INDEPENDENT_WINDOW_KEY_DIFFERS_FROM_SERVER_IDENTITY');
                $window = $receipt;
                if ($declaredScope !== null) $window['evaluation_scope'] = $declaredScope;
            } else {
                $start = $this->time($window['start_inclusive'] ?? null);
                $end = $this->time($window['end_exclusive'] ?? null);
                if (! $end->greaterThan($start) || $end->greaterThan($this->epochs->cutoff())) {
                    throw new LogicException('2026_PAPER_EVENTS_ARE_NOT_RESEARCH_DATA');
                }
                if (! preg_match('/^[a-f0-9]{64}$/', (string) ($window['dataset_sha256'] ?? ''))) {
                    throw new InvalidArgumentException('Evaluation window requires a sealed original dataset.');
                }
            }
            $window['window_key'] = $key;
            if (isset($window['evaluation_scope'])) {
                $window['evaluation_scope'] = $this->normalizeEvaluationScope((array) $window['evaluation_scope']);
                if ($this->time($window['evaluation_scope']['start_inclusive'])->lessThan($this->time($window['start_inclusive']))
                    || $this->time($window['evaluation_scope']['end_exclusive'])->greaterThan($this->time($window['end_exclusive']))) {
                    throw new LogicException('EVALUATED_SCOPE_OUTSIDE_ORIGINAL_AUTHORIZED_WINDOW');
                }
            }
            foreach (array_unique($symbols) as $symbol) {
                if ($plan['purpose'] === 'independent' && app(SpecialistCouncilDataUseService::class)->intervalExposed($version, (string) $symbol,
                    $window['start_inclusive'], $window['end_exclusive'])) {
                    throw new LogicException('EVALUATION_EVENTS_ALREADY_USED_FOR_TRAINING_OR_SELECTION');
                }
            }
            $windows[$key] = $window;
        }
        if ($windows === [] || count($windows) > 12) throw new InvalidArgumentException('Evaluation requires one to twelve bounded windows.');
        $ordered = array_values($windows); usort($ordered, fn (array $a, array $b): int => strcmp($a['start_inclusive'], $b['start_inclusive']));
        for ($index = 1; $index < count($ordered); $index++) {
            if ($ordered[$index - 1]['end_exclusive'] > $ordered[$index]['start_inclusive']) throw new InvalidArgumentException('Evaluation windows overlap original events.');
        }
        $arms = [];
        foreach ((array) ($plan['arms'] ?? []) as $arm) {
            $key = (string) ($arm['arm_key'] ?? '');
            if ($key === '' || isset($arms[$key]) || ! isset($windows[$arm['window_key'] ?? ''])
                || ! in_array($arm['kind'] ?? null, ['candidate', 'champion', 'solo', 'ablation', 'retention', 'memory_guided', 'memory_blinded'], true)) {
                throw new InvalidArgumentException('Unknown, duplicate or unscoped evaluation arm.');
            }
            $model = ModelVersion::find($arm['model_version_id'] ?? 0);
            if (! $model) throw new InvalidArgumentException('Evaluation arm native model is missing.');
            $expectedModel = match ($arm['kind']) {
                'champion' => $version->manifest['evaluation_policy']['champion_model_version_id'],
                'solo' => $version->manifest['evaluation_policy']['solo_model_version_id'],
                default => $model->id,
            };
            if ((int) $expectedModel !== (int) $model->id) {
                if (($plan['purpose'] ?? null) !== 'independent' || ! isset($plan['panel_reservation_hash'])) {
                    throw new InvalidArgumentException('Comparator differs from the preregistered champion/solo.');
                }
                app(SpecialistCouncilPanelReservationService::class)->assertWindowComparator($plan, $arm, $model);
            }
            if ($arm['kind'] === 'ablation' && ! in_array($arm['removed_id'] ?? null, $version->manifest['evaluation_policy']['required_ablations'], true)) {
                throw new InvalidArgumentException('Ablation must remove a sealed member or component.');
            }
            $arms[$key] = [...$arm, 'model_hash' => $this->contracts->modelHash($model)];
            $phase = $arm['evaluation_phase'] ?? $plan['evaluation_phase'] ?? ($plan['purpose'] === 'independent' ? 'full_validation' : 'screening');
            if (! in_array($phase, ['screening', 'full_validation'], true)) throw new InvalidArgumentException('Evaluation arm phase is unsupported.');
            $arms[$key]['evaluation_phase'] = $phase;
            $arms[$key]['expected_start_inclusive'] = $arm['expected_start_inclusive'] ?? $windows[$arm['window_key']]['start_inclusive'];
            $arms[$key]['expected_end_exclusive'] = $arm['expected_end_exclusive'] ?? $windows[$arm['window_key']]['end_exclusive'];
            if (isset($windows[$arm['window_key']]['evaluation_scope'])) {
                $scope = $windows[$arm['window_key']]['evaluation_scope'];
                if (isset($arm['evaluation_scope']) && ! $this->sameEvaluationScope((array) $arm['evaluation_scope'], $scope)) {
                    throw new LogicException('PAIRED_ARMS_CANNOT_CHANGE_EVALUATED_CALENDAR_OR_ROW_BUDGET');
                }
                $arms[$key]['evaluation_scope'] = $scope;
            }
            if (in_array($arm['kind'], ['memory_guided', 'memory_blinded'], true)) {
                $experiment = AgentLearningCausalExperiment::find($arm['selector_experiment_id'] ?? 0);
                $observation = $experiment ? app(TypedInstrumentFoundryService::class)->selectorObservationForExperiment($experiment) : [];
                $agentId = $arm['kind'] === 'memory_blinded' ? $experiment?->blinded_agent_id : $experiment?->guided_agent_id;
                if (($observation['status'] ?? '') !== 'measured_constructor_observation'
                    || (int) LabAgent::find($agentId)?->model_version_id !== (int) $model->id) {
                    throw new LogicException('MEMORY_BLINDED_ARM_REQUIRES_ORIGINAL_VERIFIED_SELECTOR_EXPERIMENT');
                }
                $arms[$key]['selection_protocol'] = $observation;
            }
        }
        if ($arms === [] || count($arms) > 256) throw new InvalidArgumentException('Missing or unbounded evaluation arms.');
        $supportTrials = $this->contracts->sealSupportRoleTrials($version->manifest, (array) ($plan['support_role_trials'] ?? []));
        foreach ($supportTrials as $trial) {
            if (isset($trial['original_policy_benchmark']) && \App\Models\CausalFoldReceipt::whereIn(
                'agent_learning_causal_experiment_id', $trial['original_policy_benchmark']['original_experiment_ids'])
                ->where(fn ($query) => $query->whereNotNull('request_hash')->orWhereNotNull('response_hash'))->exists()) {
                throw new LogicException('SUPPORT_ROLE_POLICY_BENCHMARK_MUST_PRECEDE_ORIGINAL_OUTCOMES');
            }
        }
        foreach ($supportTrials as $trial) {
            foreach (array_keys($windows) as $windowKey) {
                $windowArms = array_filter($arms, fn (array $arm): bool => $arm['window_key'] === $windowKey);
                if (! $this->findArm($windowArms, 'candidate') || ! $this->findArm($windowArms, 'retention')
                    || ! $this->findArm($windowArms, 'ablation', $trial['component_id'])) {
                    throw new LogicException('SUPPORT_ROLE_ORIGINAL_CANDIDATE_ABLATION_RETENTION_REQUIRED');
                }
            }
        }
        $sealed = [...$plan, 'protocol' => self::PLAN_PROTOCOL, 'version_id' => $version->id,
            'manifest_hash' => $version->manifest_hash, 'objective' => $version->manifest['evaluation_policy']['objective'],
            'windows' => $windows, 'arms' => $arms, 'promotion_evidence' => false];
        if ($supportTrials !== []) $sealed['support_role_trials'] = $supportTrials;
        $hash = $this->epochs->parameterHash($sealed);
        DB::transaction(function () use ($version, $evaluatorId, $sealed, $hash): void {
            $current = SpecialistCouncilVersion::lockForUpdate()->findOrFail($version->id);
            if ($current->state !== 'draft' || DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $version->id)->exists()) {
                throw new LogicException('The original evaluation plan is already sealed.');
            }
            DB::table('specialist_council_evaluation_plans')->insert(['specialist_council_version_id' => $version->id,
                'evaluator_id' => $evaluatorId, 'plan' => $this->json($sealed), 'plan_hash' => $hash,
                'sealed_at' => now()->utc(), 'created_at' => now(), 'updated_at' => now()]);
            $current->forceFill(['state' => 'evaluating', 'evaluator_id' => $evaluatorId])->save();
        });
        return [...$sealed, 'plan_hash' => $hash];
    }

    public function bindEvaluationRequest(SpecialistCouncilVersion $version, string $armKey, array $request): array
    {
        return $this->bindEvaluationRequestOwned($version, $armKey, $request);
    }

    private function bindEvaluationRequestOwned(SpecialistCouncilVersion $version, string $armKey, array $request,
        ?LabEvaluationRun $originalRun = null): array
    {
        $version = $this->verified($version);
        $owner = $this->plan($version);
        $preparationSource = $owner['plan']['preparation_source_hash'] ?? null;
        if ($preparationSource !== null && (! is_string($preparationSource)
            || ($originalRun === null && ! hash_equals($preparationSource, $this->evidence->codeHash()))
            || ($originalRun !== null && ! hash_equals($preparationSource, (string) $originalRun->code_hash)))) {
            throw new LogicException('COUNCIL_PREPARATION_SOURCE_CHANGED_BEFORE_ORIGINAL_REPLAY');
        }
        $arm = $owner['plan']['arms'][$armKey] ?? null;
        if (! $arm || ($request['replay_dataset_hash'] ?? '') !== $owner['plan']['windows'][$arm['window_key']]['dataset_sha256']
            || ($request['execution_hash'] ?? data_get($request, 'execution_contract.execution_hash')) !== $owner['plan']['execution_hash']) {
            throw new LogicException('Original evaluation request does not match the preregistered arm.');
        }
        $model = ModelVersion::find($arm['model_version_id']);
        if (! $model || $this->contracts->modelHash($model) !== $arm['model_hash']) throw new LogicException('Evaluation model changed after preregistration.');
        $plan = $owner['plan'];
        $execution = (array) ($request['execution'] ?? []);
        $executionContract = (array) ($request['execution_contract'] ?? []);
        if (strtoupper((string) ($request['timeframe'] ?? '')) !== $plan['execution_timeframe']
            || app(ExecutionContractService::class)->hashParameters($execution) !== $plan['execution_hash']
            || ($executionContract['execution_hash'] ?? null) !== $plan['execution_hash']
            || ! $this->evidence->equivalentJsonValue($execution, (array) ($executionContract['parameters'] ?? []))
            || ! $this->evidence->equivalentJsonValue($execution, (array) $plan['cost_model'])) {
            throw new LogicException('COUNCIL_PLAN_COSTS_MUST_MATCH_ACTUAL_CANONICAL_EXECUTION');
        }
        $risk = (array) $plan['risk_policy'];
        $legacyRisk = $risk['risk_per_trade_percent'] ?? null;
        if (! is_numeric($legacyRisk) || ! is_finite((float) $legacyRisk) || $legacyRisk <= 0 || $legacyRisk > 2) {
            throw new LogicException('COUNCIL_PLAN_REQUIRES_ACTUAL_BOUNDED_LEGACY_RISK_PER_TRADE');
        }
        foreach ($this->accountPolicyKeys() as $key) {
            if (! array_key_exists($key, $risk) || ! $this->evidence->equivalentJsonValue($risk[$key], $version->manifest['execution'][$key])) {
                throw new LogicException('COUNCIL_PLAN_RISK_DIFFERS_FROM_SEALED_SHARED_ACCOUNT:'.$key);
            }
            $runtimePolicies = [data_get($request, 'specialist_council_contract.policy')];
            foreach ((array) ($request['strategies'] ?? []) as $strategy) {
                if (($strategy['specialist_council_evaluation']['arm_key'] ?? null) === $armKey
                    && ($strategy['specialist_council_evaluation']['plan_hash'] ?? null) === $owner['hash']) {
                    $runtimePolicies[] = data_get($strategy, 'specialist_council_contract.policy');
                }
            }
            foreach ($runtimePolicies as $runtimePolicy) {
                if (is_array($runtimePolicy) && ! $this->evidence->equivalentJsonValue($risk[$key], $runtimePolicy[$key] ?? null)) {
                    throw new LogicException('COUNCIL_PLAN_RISK_NOT_APPLIED_TO_NATIVE_ACCOUNT:'.$key);
                }
            }
        }
        $scope = $arm['evaluation_scope'] ?? null;
        if (! is_array($scope)) throw new LogicException('PLAN_EVALUATED_SCOPE_NOT_PREREGISTERED');
        $window = $plan['windows'][$arm['window_key']];
        $probe = $window['prospective_probe_window'] ?? null;
        if (is_array($probe)) {
            if (! app(ProspectiveRepairProbeWindowService::class)->attests($probe, [...$probe, 'complete' => true])
                || ($probe['dataset_hash'] ?? null) !== $window['dataset_sha256']
                || ($probe['execution_hash'] ?? null) !== $plan['execution_hash']
                || ! $this->sameEvaluationScope($scope, $this->scopeFromProbe($probe, $plan['execution_timeframe']))) {
                throw new LogicException('COUNCIL_PLAN_PROBE_NOT_SEALED_TO_ACTUAL_CALENDAR_AND_EXECUTION');
            }
            $policy = $probe;
        } else {
            $policy = $plan['full_replay_runtime_policy'] ?? null;
            if (! is_array($policy) || ! in_array($request['evaluation_mode'] ?? '', ['full', 'replay'], true)
                || $scope['policy_hash'] !== $this->epochs->parameterHash($policy)) {
                throw new LogicException('COUNCIL_EVALUATION_WINDOW_POLICY_UNSUPPORTED');
            }
        }
        $shared = ['initial_balance' => (float) $plan['initial_capital'], 'risk_per_trade' => (float) $legacyRisk,
            'cost_model' => $plan['cost_model'], 'risk_policy' => $risk, 'evaluation_scope' => $scope,
            'window_policy' => $policy];
        $prior = $request['specialist_council_evaluation_policy'] ?? null;
        if ($prior !== null && ! $this->evidence->equivalentJsonValue($prior, $shared)) {
            throw new LogicException('BATCH_COUNCIL_EVALUATION_POLICIES_CONFLICT');
        }
        $request['initial_balance'] = $shared['initial_balance'];
        $request['execution_hash'] = $plan['execution_hash'];
        $request['risk_per_trade'] = $shared['risk_per_trade'];
        $request['cost_model'] = $shared['cost_model'];
        $request['risk_policy'] = $risk;
        $request['specialist_council_evaluation_policy'] = $shared;
        $request['policy_context'][is_array($probe) ? 'prospective_probe_window' : 'full_replay_runtime_policy'] = $policy;
        $request['specialist_council_evaluation'] = ['protocol' => self::PLAN_PROTOCOL, 'version_id' => $version->id,
            'manifest_hash' => $version->manifest_hash, 'plan_hash' => $owner['hash'], 'arm_key' => $armKey];
        return $request;
    }

    /** Call at the native outer-request sealing seam; conflicting batch arms fail before any run starts. */
    public function bindEvaluationRequestForModel(ModelVersion $model, array $request): array
    {
        if (data_get($model->metadata, 'specialist_council_evaluation') === null) return $request;
        $binding = $this->evaluationBindingForModel($model, (string) ($request['replay_dataset_hash'] ?? ''));
        $bound = $this->bindEvaluationRequest(SpecialistCouncilVersion::findOrFail($binding['version_id']), $binding['arm_key'], $request);
        if (isset($request['strategies'])) {
            $matched = 0;
            foreach ($bound['strategies'] as &$strategy) {
                if (($strategy['specialist_council_evaluation']['arm_key'] ?? null) === $binding['arm_key']
                    && ($strategy['specialist_council_evaluation']['plan_hash'] ?? null) === $binding['plan_hash']) {
                    $strategy['specialist_council_evaluation'] = $binding; $matched++;
                }
            }
            unset($strategy, $bound['specialist_council_evaluation']);
            if ($matched !== 1) throw new LogicException('BATCH_COUNCIL_EVALUATION_ARM_IDENTITY_MISSING_OR_DUPLICATED');
        }
        return $bound;
    }

    /** Native dispatcher metadata binds one original model to one preregistered arm. */
    public function attachEvaluationArm(SpecialistCouncilVersion $version, string $armKey, ModelVersion $model): ModelVersion
    {
        $version = $this->verified($version); $owner = $this->plan($version);
        $arm = $owner['plan']['arms'][$armKey] ?? null;
        if (! $arm || (int) $arm['model_version_id'] !== (int) $model->id || $this->contracts->modelHash($model) !== $arm['model_hash']) {
            throw new LogicException('EVALUATION_ARM_NATIVE_MODEL_MISMATCH');
        }
        $metadata = (array) $model->metadata;
        $binding = ['protocol' => self::PLAN_PROTOCOL, 'version_id' => $version->id,
            'manifest_hash' => $version->manifest_hash, 'plan_hash' => $owner['hash'], 'arm_key' => $armKey];
        $existing = $metadata['specialist_council_evaluation'] ?? null;
        if ($existing !== null && $this->epochs->parameterHash((array) $existing) !== $this->epochs->parameterHash($binding)) {
            $bindings = $existing['bindings'] ?? [$existing];
            foreach ($bindings as $prior) {
                if (($prior['plan_hash'] ?? '') !== $owner['hash'] || ($prior['version_id'] ?? null) !== $version->id) {
                    throw new LogicException('NATIVE_MODEL_ALREADY_BOUND_TO_ANOTHER_EVALUATION_PLAN');
                }
                if (($prior['arm_key'] ?? '') === $armKey) return $model->fresh();
                $previousArm = $owner['plan']['arms'][$prior['arm_key']] ?? [];
                if (($previousArm['window_key'] ?? '') === $arm['window_key']) throw new LogicException('SAME_NATIVE_MODEL_CANNOT_BE_TWO_ARMS_IN_ONE_WINDOW');
            }
            $binding = ['protocol' => self::PLAN_PROTOCOL, 'bindings' => [...$bindings, $binding]];
        }
        $metadata['specialist_council_evaluation'] = $binding;
        $model->forceFill(['metadata' => $metadata])->save();
        return $model->fresh();
    }

    public function evaluationBindingForModel(ModelVersion $model, ?string $datasetHash = null): ?array
    {
        $binding = data_get($model->metadata, 'specialist_council_evaluation');
        if ($binding === null) return null;
        if (! is_array($binding) || ! Schema::hasTable('specialist_council_versions')) throw new LogicException('DECLARED_COUNCIL_EVALUATION_BINDING_INVALID');
        if (isset($binding['bindings'])) {
            $matches = [];
            foreach ($binding['bindings'] as $candidate) {
                $version = SpecialistCouncilVersion::findOrFail($candidate['version_id'] ?? 0);
                $owner = $this->plan($version); $arm = $owner['plan']['arms'][$candidate['arm_key'] ?? ''] ?? null;
                if ($arm && $datasetHash !== null && $owner['plan']['windows'][$arm['window_key']]['dataset_sha256'] === $datasetHash) $matches[] = $candidate;
            }
            if (count($matches) !== 1) throw new LogicException('COUNCIL_EVALUATION_WINDOW_BINDING_AMBIGUOUS');
            $binding = $matches[0];
        }
        $version = $this->verified(SpecialistCouncilVersion::findOrFail($binding['version_id'] ?? 0));
        $owner = $this->plan($version); $arm = $owner['plan']['arms'][$binding['arm_key'] ?? ''] ?? null;
        if (! $arm || ($binding['protocol'] ?? null) !== self::PLAN_PROTOCOL || ($binding['plan_hash'] ?? null) !== $owner['hash']
            || ($binding['manifest_hash'] ?? null) !== $version->manifest_hash
            || (int) $arm['model_version_id'] !== (int) $model->id || $this->contracts->modelHash($model) !== $arm['model_hash']) {
            throw new LogicException('DECLARED_COUNCIL_EVALUATION_BINDING_INVALID');
        }
        if ($datasetHash !== null && $owner['plan']['windows'][$arm['window_key']]['dataset_sha256'] !== $datasetHash) {
            throw new LogicException('COUNCIL_EVALUATION_REQUEST_DATASET_NOT_PREREGISTERED');
        }
        return $binding;
    }

    /** Server-owned purpose, not a caller's research-only flag. */
    public function evaluationPurposeForModel(ModelVersion $model): ?string
    {
        $declared = data_get($model->metadata, 'specialist_council_evaluation');
        if ($declared === null) return null;
        if (! is_array($declared)) throw new LogicException('DECLARED_COUNCIL_EVALUATION_BINDING_INVALID');
        $bindings = isset($declared['bindings']) ? $declared['bindings'] : [$declared];
        if (! is_array($bindings) || $bindings === []) throw new LogicException('DECLARED_COUNCIL_EVALUATION_BINDING_INVALID');
        $purposes = [];
        foreach ($bindings as $binding) {
            $version = $this->verified(SpecialistCouncilVersion::findOrFail($binding['version_id'] ?? 0));
            $owner = $this->plan($version);
            $arm = $owner['plan']['arms'][$binding['arm_key'] ?? ''] ?? null;
            if (! $arm || ($binding['protocol'] ?? null) !== self::PLAN_PROTOCOL
                || ($binding['plan_hash'] ?? null) !== $owner['hash']
                || ($binding['manifest_hash'] ?? null) !== $version->manifest_hash
                || (int) $arm['model_version_id'] !== (int) $model->id
                || $this->contracts->modelHash($model) !== $arm['model_hash']) {
                throw new LogicException('DECLARED_COUNCIL_EVALUATION_BINDING_INVALID');
            }
            $purposes[] = $owner['plan']['purpose'];
        }
        $purposes = array_values(array_unique($purposes));
        if (count($purposes) !== 1) throw new LogicException('COUNCIL_EVALUATION_PURPOSE_AMBIGUOUS');
        return $purposes[0];
    }

    /** Native immutable completion calls this; first original arms settle once, without a second scheduler. */
    public function settleEvaluationForRun(LabEvaluationRun $run): ?array
    {
        $request = (array) data_get($run->request_meta, 'payload', []);
        $request = $this->requestForRun($run, $request);
        $binding = $request['specialist_council_evaluation'] ?? null;
        if ($binding === null) return null;
        $version = $this->verified(SpecialistCouncilVersion::findOrFail($binding['version_id'] ?? 0));
        $owner = $this->plan($version);
        if (($binding['plan_hash'] ?? '') !== $owner['hash']) throw new LogicException('ORIGINAL_RUN_EVALUATION_PLAN_BINDING_MISMATCH');
        if ($version->state === 'evaluated') return $version->assessment;
        $runs = LabEvaluationRun::whereIn('model_version_id', array_column($owner['plan']['arms'], 'model_version_id'))
            ->where('started_at', '>=', $owner['sealed_at'])->orderBy('id')->limit(512)->get();
        $selected = [];
        foreach ($runs as $candidate) {
            $candidateRequest = $this->requestForRun($candidate, (array) data_get($candidate->request_meta, 'payload', []));
            $candidateBinding = (array) ($candidateRequest['specialist_council_evaluation'] ?? []);
            $armKey = $candidateBinding['arm_key'] ?? '';
            if (($candidateBinding['plan_hash'] ?? '') !== $owner['hash'] || ! isset($owner['plan']['arms'][$armKey]) || isset($selected[$armKey])) continue;
            if ($candidate->phase !== $owner['plan']['arms'][$armKey]['evaluation_phase']) continue;
            $selected[$armKey] = $candidate; // Earliest original run; a later better result cannot replace it.
        }
        if (count($selected) !== count($owner['plan']['arms'])
            || collect($selected)->contains(fn (LabEvaluationRun $candidate): bool => $candidate->finished_at === null)) {
            return ['status' => 'waiting_for_original_arms', 'settled_arms' => count($selected),
                'required_arms' => count($owner['plan']['arms']), 'promotion_evidence' => false];
        }
        return $this->evaluateOriginalRuns($version, $owner['evaluator_id'], array_map(fn (LabEvaluationRun $candidate): string => $candidate->run_id, array_values($selected)));
    }

    /** Insert alongside the original terminal seal transaction; this does not invoke an evaluator. */
    public function enqueueCompletedRun(LabEvaluationRun $run): ?object
    {
        $payload = (array) data_get($run->request_meta, 'payload', []);
        if (! isset($payload['specialist_council_evaluation']) && ! collect((array) ($payload['strategies'] ?? []))
            ->contains(fn ($strategy): bool => is_array($strategy) && isset($strategy['specialist_council_evaluation']))) return null;
        $request = $this->requestForRun($run, $payload);
        $binding = $request['specialist_council_evaluation'] ?? null;
        if ($binding === null) return null;
        if (! is_array($binding) || ! isset($binding['version_id'])) throw new LogicException('COMPLETED_RUN_EVALUATION_BINDING_INVALID');
        DB::table('specialist_council_evaluation_deliveries')->insertOrIgnore([
            'run_id' => $run->run_id, 'specialist_council_version_id' => $binding['version_id'],
            'status' => 'pending', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $delivery = DB::table('specialist_council_evaluation_deliveries')->where('run_id', $run->run_id)->first();
        return $delivery;
    }

    /** Consume only after immutable publication commits; errors remain durable delivery receipts. */
    public function notifyCompletedRun(LabEvaluationRun $run): ?array
    {
        $delivery = $this->enqueueCompletedRun($run);
        return $delivery === null ? null : $this->deliverEvaluation($delivery);
    }

    /** The existing research arbiter or learning recovery owner calls this bounded drain. */
    public function reconcilePendingEvaluations(int $limit = 8): array
    {
        if (! Schema::hasTable('specialist_council_evaluation_deliveries')) return ['status' => 'unavailable', 'deliveries' => [], 'promotion_evidence' => false];
        $deliveries = DB::table('specialist_council_evaluation_deliveries')->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()->utc()))
            ->orderBy('id')->limit(max(1, min(32, $limit)))->get();
        $receipts = [];
        foreach ($deliveries as $delivery) $receipts[] = $this->deliverEvaluation($delivery);
        return ['status' => 'reconciled', 'deliveries' => $receipts, 'promotion_evidence' => false];
    }

    private function deliverEvaluation(object $delivery): array
    {
        if ($delivery->status === 'completed') return (array) json_decode((string) $delivery->receipt, true);
        if ($delivery->status === 'blocked' || ($delivery->next_attempt_at !== null
            && CarbonImmutable::parse($delivery->next_attempt_at, 'UTC')->greaterThan(now()))) {
            return (array) json_decode((string) $delivery->receipt, true);
        }
        try {
            $run = LabEvaluationRun::where('run_id', $delivery->run_id)->firstOrFail();
            $receipt = $this->settleEvaluationForRun($run) ?? ['status' => 'no_original_binding', 'promotion_evidence' => false];
            $settled = ($receipt['protocol'] ?? null) === self::ASSESSMENT_PROTOCOL;
            if ($settled) {
                DB::table('specialist_council_evaluation_deliveries')->where('specialist_council_version_id', $delivery->specialist_council_version_id)
                    ->where('status', 'pending')->update(['status' => 'completed', 'receipt' => $this->json($receipt), 'last_error' => null, 'updated_at' => now()]);
            } else {
                DB::table('specialist_council_evaluation_deliveries')->where('id', $delivery->id)
                    ->update(['receipt' => $this->json($receipt), 'last_error' => null, 'updated_at' => now()]);
            }
            return $receipt;
        } catch (\Throwable $e) {
            $blocked = (int) $delivery->attempts + 1 >= 3;
            $receipt = ['status' => $blocked ? 'evaluation_delivery_blocked' : 'evaluation_delivery_pending', 'run_id' => $delivery->run_id,
                'reason_code' => 'ORIGINAL_COUNCIL_EVALUATION_SETTLEMENT_DEPENDENCY',
                'detail' => $e->getMessage(), 'promotion_evidence' => false];
            DB::table('specialist_council_evaluation_deliveries')->where('id', $delivery->id)
                ->update(['attempts' => DB::raw('attempts + 1'), 'last_error' => $e->getMessage(),
                    'status' => $blocked ? 'blocked' : 'pending', 'next_attempt_at' => $blocked ? null : now()->utc()->addMinutes(5),
                    'receipt' => $this->json($receipt), 'updated_at' => now()]);
            return $receipt;
        }
    }

    /** All metrics come from original producer bytes, never assessment flags or mutable projections. */
    public function evaluateOriginalRuns(SpecialistCouncilVersion $version, string $evaluatorId, array $runIds): array
    {
        $version = $this->verified($version);
        $this->independentActor($version, $evaluatorId);
        $owner = $this->plan($version);
        if ($owner['evaluator_id'] !== $evaluatorId) throw new LogicException('Only the preregistered independent evaluator owns this exam.');
        if (! in_array($version->state, ['evaluating', 'evaluated'], true)) throw new LogicException('Version has no open independent evaluation.');
        $assessment = $this->assessOriginalRuns($version, $owner, $runIds);
        return DB::transaction(function () use ($version, $evaluatorId, $runIds, $assessment): array {
            $current = SpecialistCouncilVersion::lockForUpdate()->findOrFail($version->id);
            if (! in_array($current->state, ['evaluating', 'evaluated'], true)) throw new LogicException('Evaluation boundary changed concurrently.');
            if ($current->state === 'evaluated') {
                if ($current->assessment_hash === $this->epochs->parameterHash($assessment)
                    && (array) ($current->assessment['original_run_ids'] ?? []) === array_values($runIds)) {
                    app(SpecialistCouncilResearchFeedbackService::class)->recordAssessment($current);
                    return $current->assessment;
                }
                throw new LogicException('ORIGINAL_COUNCIL_ASSESSMENT_ALREADY_SEALED');
            }
            DB::table('specialist_council_evaluations')->insert(['specialist_council_version_id' => $version->id,
                'evaluator_id' => $evaluatorId, 'original_run_ids' => $this->json(array_values($runIds)),
                'assessment' => $this->json($assessment), 'assessment_hash' => $this->epochs->parameterHash($assessment),
                'created_at' => now(), 'updated_at' => now()]);
            $current->forceFill(['state' => 'evaluated', 'assessment' => $assessment,
                'assessment_hash' => $this->epochs->parameterHash($assessment)])->save();
            // The original assessment and its scoped knowledge closure publish together.
            // This records research information, never a skill or trading entitlement.
            app(SpecialistCouncilResearchFeedbackService::class)->recordAssessment($current);
            return $assessment;
        });
    }

    public function approve(SpecialistCouncilVersion $version, string $approverId): array
    {
        $this->independentActor($version, $approverId);
        return DB::transaction(function () use ($version): array {
            $current = $this->verified(SpecialistCouncilVersion::lockForUpdate()->findOrFail($version->id));
            if ($current->state !== 'evaluated') return $this->blocked('COUNCIL_NOT_INDEPENDENTLY_EVALUATED');
            $owner = $this->plan($current);
            $original = (array) ($current->assessment['original_run_ids'] ?? []);
            $assessment = $this->assessOriginalRuns($current, $owner, $original);
            if (($assessment['qualified'] ?? false) !== true || $this->epochs->parameterHash($assessment) !== $current->assessment_hash) {
                return $this->blocked('ORIGINAL_INDEPENDENT_COUNCIL_EVIDENCE_INCOMPLETE');
            }
            $paper = $this->paperAuthority($current, true);
            if (! $paper['allowed']) return $paper;
            $current->forceFill(['state' => 'approved', 'approved_at' => now()->utc(), 'paper_authority' => $paper])->save();
            return ['allowed' => true, 'state' => 'approved', 'version_id' => $current->id,
                'paper_authority' => $paper, 'promotion_evidence' => false];
        });
    }

    /** Entry adoption happens at an explicit future boundary; existing positions retain their pins. */
    public function schedule(SpecialistCouncilVersion $version, string $effectiveAt): array
    {
        $time = $this->time($effectiveAt);
        if ($time->lessThan(now()->utc())) throw new InvalidArgumentException('Adoption boundary cannot precede scheduling.');
        return DB::transaction(function () use ($version, $time): array {
            $current = $this->verified(SpecialistCouncilVersion::lockForUpdate()->findOrFail($version->id));
            if ($current->state !== 'approved') return $this->blocked('COUNCIL_NOT_APPROVED');
            if (SpecialistCouncilVersion::where('council_id', $current->council_id)->where('state', 'scheduled')->exists()) {
                return $this->blocked('COUNCIL_ADOPTION_ALREADY_SCHEDULED');
            }
            $current->forceFill(['state' => 'scheduled', 'effective_at' => $time])->save();
            return ['allowed' => true, 'state' => 'scheduled', 'effective_at' => $time->toIso8601String(), 'promotion_evidence' => false];
        });
    }

    /** Called by the native paper cycle. No independent scheduler or live authorization. */
    public function activateDue(string $councilId): array
    {
        return DB::transaction(function () use ($councilId): array {
            $versions = SpecialistCouncilVersion::where('council_id', $councilId)->orderBy('id')->lockForUpdate()->get();
            $next = $versions->first(fn (SpecialistCouncilVersion $row): bool => $row->state === 'scheduled' && $row->effective_at?->lessThanOrEqualTo(now()));
            if (! $next) return $this->blocked('NO_DUE_APPROVED_COUNCIL_VERSION');
            $next = $this->verified($next);
            $owner = $this->plan($next);
            $assessment = $this->assessOriginalRuns($next, $owner, (array) ($next->assessment['original_run_ids'] ?? []));
            if (! ($assessment['qualified'] ?? false) || $this->epochs->parameterHash($assessment) !== $next->assessment_hash) {
                return $this->blocked('COUNCIL_ORIGINAL_EVIDENCE_DRIFT');
            }
            $paper = $this->paperAuthority($next, true);
            if (! $paper['allowed']) return $paper;
            $previous = $versions->firstWhere('state', 'active');
            if ($previous) $previous->forceFill(['state' => 'retired', 'retired_at' => now()->utc()])->save();
            $next->forceFill(['state' => 'active', 'previous_version_id' => $previous?->id, 'activated_at' => now()->utc()])->save();
            $this->publishEntryBindings($next);
            return ['allowed' => true, 'state' => 'active', 'version_id' => $next->id,
                'previous_version_id' => $previous?->id, 'existing_position_versions_preserved' => true, 'promotion_evidence' => false];
        });
    }

    public function rollback(SpecialistCouncilVersion $active, string $reason): array
    {
        if (trim($reason) === '') throw new InvalidArgumentException('Rollback requires an attributable reason.');
        return DB::transaction(function () use ($active, $reason): array {
            $current = $this->verified(SpecialistCouncilVersion::lockForUpdate()->findOrFail($active->id));
            $previous = SpecialistCouncilVersion::lockForUpdate()->find($current->previous_version_id);
            if ($current->state !== 'active' || ! $previous || $previous->state !== 'retired' || ! $previous->approved_at) {
                return $this->blocked('NO_PREVIOUS_APPROVED_ROLLBACK_VERSION');
            }
            $previous = $this->verified($previous);
            $paper = $this->paperAuthority($previous, true);
            if (! $paper['allowed']) return $paper;
            $current->forceFill(['state' => 'rolled_back', 'retired_at' => now()->utc()])->save();
            $previous->forceFill(['state' => 'active', 'retired_at' => null, 'activated_at' => now()->utc()])->save();
            // Models may be shared by two council versions. Restore only the
            // future-entry pointer; existing orders retain their original pin.
            $this->publishEntryBindings($previous);
            return ['allowed' => true, 'version_id' => $previous->id, 'rolled_back_version_id' => $current->id,
                'reason' => $reason, 'existing_position_versions_preserved' => true, 'promotion_evidence' => false];
        });
    }

    private function publishEntryBindings(SpecialistCouncilVersion $version): void
    {
        $members = collect($version->manifest['members'])
            ->filter(fn (array $member): bool => in_array($member['role'], SpecialistCouncilContractService::TRADING_ROLES, true))
            ->sortBy('model_version_id');
        foreach ($members as $member) {
            $model = ModelVersion::lockForUpdate()->findOrFail($member['model_version_id']);
            if ($this->contracts->modelHash($model) !== $member['source_model_hash']) {
                throw new LogicException('COUNCIL_MEMBER_NATIVE_MODEL_DRIFT');
            }
            $metadata = (array) $model->metadata;
            $metadata['specialist_council_binding'] = ['protocol' => self::BINDING_PROTOCOL,
                'version_id' => $version->id, 'council_id' => $version->council_id, 'council_version' => $version->version,
                'specialist_id' => $member['specialist_id'], 'management_version' => $member['management_version']];
            $model->forceFill(['metadata' => $metadata])->save();
        }
    }

    /** A pinned retired version may manage existing positions; only the active version may propose new entries. */
    public function paperBinding(ModelVersion $model, string $symbol, string $timeframe, ?array $binding = null): array
    {
        if (! Schema::hasTable('specialist_council_versions')) return $this->blocked('SPECIALIST_COUNCIL_STORAGE_UNAVAILABLE');
        $binding ??= (array) data_get($model->metadata, 'specialist_council_binding', []);
        $query = SpecialistCouncilVersion::query();
        if (isset($binding['version_id'])) $query->where('id', $binding['version_id']);
        else $query->where('council_id', $binding['council_id'] ?? '')->where('version', $binding['council_version'] ?? '');
        $version = $query->first();
        if (! $version || ($binding['protocol'] ?? null) !== self::BINDING_PROTOCOL) return $this->blocked('SPECIALIST_COUNCIL_BINDING_MISSING');
        $management = ($binding['management_only'] ?? false) === true;
        if ((! $management && $version->state !== 'active') || ($management && ! in_array($version->state, ['active', 'retired', 'rolled_back'], true))) {
            return $this->blocked('SPECIALIST_COUNCIL_VERSION_NOT_ACTIVE');
        }
        try { $version = $this->verified($version); } catch (\Throwable) { return $this->blocked('SPECIALIST_COUNCIL_MANIFEST_DRIFT'); }
        $member = collect($version->manifest['members'])->firstWhere('specialist_id', $binding['specialist_id'] ?? '');
        if (! $member || ! in_array($member['role'], SpecialistCouncilContractService::TRADING_ROLES, true)
            || (int) ($member['model_version_id'] ?? 0) !== (int) $model->id
            || ($member['management_version'] ?? null) !== ($binding['management_version'] ?? null)
            || ! in_array(strtoupper($symbol), array_map('strtoupper', $member['scope']['symbols']), true)
            || (! $management && $member['source_model_hash'] !== $this->contracts->modelHash($model))) return $this->blocked('SPECIALIST_MEMBER_OR_MANAGEMENT_IDENTITY_MISMATCH');
        if (! $management) {
            $paper = app(PaperAuthorityAdmissionService::class)->verifyFrozenCandidate($model, $symbol, $timeframe);
            if (($paper['allowed'] ?? false) !== true) return $this->blocked($paper['reason_code'] ?? 'SPECIALIST_NATIVE_PAPER_AUTHORITY_MISSING');
            $readiness = app(PaperAuthorityAdmissionService::class)->observationReadiness($model, $symbol, $timeframe);
            if (($readiness['allowed'] ?? false) !== true) return $this->blocked($readiness['reason_code'] ?? 'SPECIALIST_PAPER_EPOCH_NOT_OPEN');
        }
        return ['protocol' => self::BINDING_PROTOCOL, 'allowed' => true, 'reason_code' => null,
            'owner_id' => $member['specialist_id'], 'specialist_id' => $member['specialist_id'],
            'council_id' => $version->council_id, 'council_version' => $version->version,
            'version_id' => $version->id, 'management_version' => $member['management_version'],
            'member' => $member, 'management_only' => $management, 'promotion_evidence' => false];
    }

    public function activeTradingMembers(string $symbol): array
    {
        if (! Schema::hasTable('specialist_council_versions')) return [];
        $members = [];
        foreach (SpecialistCouncilVersion::where('state', 'active')->get() as $version) {
            $version = $this->verified($version);
            foreach ($version->manifest['members'] as $member) {
                if (! in_array($member['role'], SpecialistCouncilContractService::TRADING_ROLES, true)
                    || ! in_array(strtoupper($symbol), array_map('strtoupper', $member['scope']['symbols']), true)) continue;
                $members[] = [...$member, 'binding' => ['protocol' => self::BINDING_PROTOCOL,
                    'version_id' => $version->id, 'council_id' => $version->council_id,
                    'council_version' => $version->version, 'specialist_id' => $member['specialist_id'],
                    'management_version' => $member['management_version']]];
            }
        }
        return $members;
    }

    private function assessOriginalRuns(SpecialistCouncilVersion $version, array $owner, array $runIds): array
    {
        $plan = $owner['plan']; $errors = []; $arms = []; $sources = []; $roleCoverage = []; $dataDependencies = []; $traitCoverage = [];
        if ($runIds === [] || count($runIds) > 256 || count(array_unique($runIds)) !== count($runIds)) $errors[] = 'ORIGINAL_RUN_SET_MISSING_OR_DUPLICATED';
        foreach ($runIds as $runId) {
            $run = LabEvaluationRun::where('run_id', $runId)->first();
            try {
                if (! $run || $run->status !== 'completed' || ! $run->started_at || ! $run->finished_at
                    || $run->started_at->lessThan($owner['sealed_at'])
                    || ! $this->evidence->learningEligibility($run)['complete']
                    || ! $this->evidence->verifiedModelRuntimeIdentity($run)) throw new LogicException('ORIGINAL_RUN_NOT_COMPLETE_OR_PREREGISTERED');
                $request = $this->requestForRun($run, $this->originalArtifact($run, 'evaluation_request'));
                $response = $this->originalArtifact($run, 'evaluation_response');
                $archive = isset($plan['preparation_source_hash']) || is_array(data_get($request, 'research_release.source_artifact'))
                    ? $this->assertArchivedOriginalRelease($run, $request, $response, $plan) : null;
                $binding = (array) ($request['specialist_council_evaluation'] ?? []);
                $armKey = (string) ($binding['arm_key'] ?? ''); $arm = $plan['arms'][$armKey] ?? null;
                $window = $plan['windows'][$arm['window_key'] ?? ''] ?? null;
                $model = ModelVersion::find($run->model_version_id);
                if (! $arm || ! $window || isset($arms[$armKey]) || ! $model || $model->id !== (int) $arm['model_version_id']
                    || $run->phase !== $arm['evaluation_phase']
                    || $this->contracts->modelHash($model) !== $arm['model_hash'] || ($binding['plan_hash'] ?? '') !== $owner['hash']
                    || ($binding['manifest_hash'] ?? '') !== $version->manifest_hash || ($binding['version_id'] ?? null) !== $version->id
                    || $run->data_hash !== $window['dataset_sha256'] || ($request['replay_dataset_hash'] ?? '') !== $run->data_hash
                    || ($request['execution_hash'] ?? '') !== $plan['execution_hash']
                    || (float) ($request['initial_balance'] ?? 0) !== (float) $plan['initial_capital']
                    || $this->epochs->parameterHash((array) ($request['cost_model'] ?? [])) !== $this->epochs->parameterHash((array) $plan['cost_model'])
                    || $this->epochs->parameterHash((array) ($request['risk_policy'] ?? [])) !== $this->epochs->parameterHash((array) $plan['risk_policy'])) {
                    throw new LogicException('ORIGINAL_ARM_IDENTITY_OR_EQUAL_CAPITAL_COST_RISK_MISMATCH');
                }
                if ($plan['purpose'] === 'independent'
                    && ! ($archive ? $this->authorizedOriginalPlanWindow($window, $run, $request, $response, $plan, $archive)
                        : $this->authorizedPlanWindow($window, $run->data_hash))) throw new LogicException('ORIGINAL_WINDOW_AUTHORIZATION_MISSING');
                if (! is_array($request['specialist_council_evaluation_policy'] ?? null)
                    || (float) ($request['risk_per_trade'] ?? 0) !== (float) ($plan['risk_policy']['risk_per_trade_percent'] ?? 0)) {
                    throw new LogicException('ORIGINAL_ARM_PLAN_ECONOMICS_NOT_NATIVE_BOUND');
                }
                $this->bindEvaluationRequestOwned($version, $armKey, $request, $run);
                if (isset($plan['panel_reservation_hash']) && in_array($arm['kind'], ['champion', 'solo'], true)) {
                    app(SpecialistCouncilPanelReservationService::class)->assertHistoricalWindowComparator($plan, $arm, $model, $run);
                }
                foreach ($version->manifest['members'] as $member) {
                    foreach ($member['scope']['symbols'] as $symbol) {
                        if ($plan['purpose'] === 'independent' && app(SpecialistCouncilDataUseService::class)->intervalExposed($version, $symbol,
                            $window['start_inclusive'], $window['end_exclusive'])) throw new LogicException('INDEPENDENT_EVENT_EXPOSURE_DETECTED');
                    }
                }
                $this->assertOriginalArmScope($arm, $request, $response, $plan['execution_timeframe']);
                $producer = null;
                if (isset($plan['descendant_programs']) && in_array($arm['kind'], ['champion', 'solo'], true)) {
                    $controlReceipt = $this->attestReplayResult($model, $request, $response);
                    if ($arm['kind'] === 'champion') $traitCoverage[$arm['window_key']]['champion'] = $this->nativeTraitObservation(
                        $this->researchVersionForModel($model), $plan['descendant_programs']['trait']['component_id'], (array) $controlReceipt);
                }
                if (in_array($arm['kind'], ['candidate', 'ablation', 'retention'], true)) {
                    $runtime = (array) ($request['specialist_council_contract'] ?? []);
                    $receipt = (array) ($response['specialist_council_receipt'] ?? []);
                    if (($runtime['manifest_hash'] ?? null) !== $version->manifest_hash
                        || ($receipt['contract_hash'] ?? null) !== ($runtime['contract_hash'] ?? null)
                        || ($receipt['protocol'] ?? '') !== 'specialist_council_receipt_v1') throw new LogicException('COUNCIL_RUNTIME_PRODUCER_RECEIPT_MISSING');
                    if (($receipt['status'] ?? '') === 'dependency') {
                        $dataDependencies = [...$dataDependencies, ...(array) ($receipt['dependency_reasons'] ?? [])];
                        $errors[] = 'COUNCIL_EXECUTION_DATA_PREREQUISITES_MISSING';
                    }
                    if ($arm['kind'] === 'candidate') {
                        $this->attestReplayResult($model, $request, $response);
                        if (isset($plan['descendant_programs'])) $traitCoverage[$arm['window_key']]['candidate'] = $this->nativeTraitObservation(
                            $version, $plan['descendant_programs']['trait']['component_id'], $receipt);
                        foreach ($version->manifest['members'] as $member) {
                            if (! in_array($member['role'], SpecialistCouncilContractService::TRADING_ROLES, true)) continue;
                            $mature = count(array_filter((array) ($receipt['position_ledger'] ?? []), fn (array $position): bool =>
                                ($position['specialist_id'] ?? null) === $member['specialist_id'] && ($position['outcome_matured'] ?? null) === true));
                            $roleCoverage[$member['specialist_id']][$arm['window_key']] = $mature;
                            if ($mature < $version->manifest['evaluation_policy']['minimum_paired_trades']) $errors[] = 'SPECIALIST_MATURE_HORIZON_EVIDENCE_UNDERPOWERED:'.$member['specialist_id'];
                            $producerMember = collect((array) ($receipt['members'] ?? []))->firstWhere('specialist_id', $member['specialist_id']);
                            if (! is_array($producerMember) || (int) data_get($producerMember, 'stages.decision:observed', 0) < 1) {
                                $errors[] = 'SPECIALIST_ACTUAL_ROLE_DISPATCH_NOT_OBSERVED:'.$member['specialist_id'];
                            }
                        }
                    }
                    $bundle = empty($request['mtf_snapshot_manifest']) ? null : [
                        'bundle_hash' => $request['replay_dataset_hash'], 'manifest' => $request['mtf_snapshot_manifest']];
                    $expected = $this->runtimeContract($version, $run->data_hash, $plan['execution_hash'], $plan['execution_timeframe'], $bundle, $request['symbol'] ?? null);
                    if ($arm['kind'] !== 'ablation' && $this->epochs->parameterHash($runtime) !== $this->epochs->parameterHash($expected)) {
                        throw new LogicException('COUNCIL_CANDIDATE_EXECUTED_DIFFERENT_MANIFEST');
                    }
                    if ($arm['kind'] === 'ablation') {
                        $expected = $this->runtimeContractForAblation($version, $arm['removed_id'], $run->data_hash,
                            $plan['execution_hash'], $plan['execution_timeframe'], $bundle, $request['symbol'] ?? null);
                        if ($this->epochs->parameterHash($runtime) !== $this->epochs->parameterHash($expected)) throw new LogicException('ABLATION_CHANGED_MORE_THAN_ITS_SEALED_TARGET');
                        if (in_array($arm['removed_id'], array_column((array) ($runtime['members'] ?? []), 'specialist_id'), true)
                            || in_array($arm['removed_id'], array_column((array) ($runtime['component_identities'] ?? []), 'id'), true)) {
                            throw new LogicException('ABLATION_DID_NOT_REMOVE_REQUESTED_COMPONENT');
                        }
                    }
                    if (! empty($plan['support_role_trials'])) {
                        $producer = ['receipt' => $this->attestReplayResult($model, $request, $response),
                            'runtime' => $runtime, 'code_hash' => $run->code_hash, 'run_id' => $run->run_id, 'version_id' => $version->id];
                    }
                }
                $metrics = $this->metrics($response);
                $arms[$armKey] = [...$arm, 'metrics' => $metrics, 'original_run_id' => $run->run_id];
                if ($producer !== null) $arms[$armKey]['support_producer'] = $producer;
                $sources[] = ['run_id' => $run->run_id, 'request_hash' => $run->request_hash,
                    'response_hash' => $run->response_hash, 'data_hash' => $run->data_hash,
                    'parameter_hash' => $run->parameter_hash, 'code_hash' => $run->code_hash];
            } catch (\Throwable $e) { $errors[] = $e->getMessage(); }
        }
        foreach ($plan['arms'] as $key => $arm) if (! isset($arms[$key])) $errors[] = 'PREREGISTERED_ARM_NOT_SETTLED:'.$key;
        $originalErrors = $errors;
        $comparisons = []; $memoryComparisons = []; $positive = 0; $underpowered = false;
        foreach ($plan['windows'] as $windowKey => $window) {
            $windowArms = array_filter($arms, fn (array $arm): bool => $arm['window_key'] === $windowKey);
            $candidate = $this->findArm($windowArms, 'candidate');
            $guided = $this->findArm($windowArms, 'memory_guided');
            $blinded = $this->findArm($windowArms, 'memory_blinded');
            if ($guided || $blinded) {
                $parity = $guided && $blinded && (int) ($guided['selector_experiment_id'] ?? 0) === (int) ($blinded['selector_experiment_id'] ?? 0)
                    && $this->evidence->equivalentJsonValue($guided['selection_protocol'], $blinded['selection_protocol']);
                $memoryComparisons[] = ['window_key' => $windowKey, 'status' => $parity ? 'paired_research_diagnostic' : 'dependency',
                    'selector_experiment_id' => $blinded['selector_experiment_id'] ?? $guided['selector_experiment_id'] ?? null,
                    'selection_protocol' => $parity ? $blinded['selection_protocol'] : null,
                    'guided_metrics' => $guided['metrics'] ?? null, 'blinded_metrics' => $blinded['metrics'] ?? null,
                    'equal_compute_cap_and_legal_space_verified' => $parity, 'memory_superiority_proven' => false,
                    'reason_code' => $parity ? 'RESEARCH_PAIR_DOES_NOT_PROVE_INDEPENDENT_SMART_SELECTOR' : 'ORIGINAL_GUIDED_BLINDED_PAIR_MISSING',
                    'promotion_evidence' => false];
            }
            $champion = $this->findArm($windowArms, 'champion'); $solo = $this->findArm($windowArms, 'solo');
            $independent = $plan['purpose'] === 'independent';
            if (! $candidate || ! $solo || ($independent && ! $champion)) {
                $errors[] = $independent ? 'CANDIDATE_CHAMPION_SOLO_COMPARISON_MISSING' : 'CANDIDATE_SOLO_RESEARCH_COMPARISON_MISSING';
                continue;
            }
            $windowSources = array_values(array_filter($sources, fn (array $source): bool =>
                in_array($source['run_id'], array_column($windowArms, 'original_run_id'), true)));
            if (count(array_unique(array_column($windowSources, 'code_hash'))) > 1) $errors[] = 'PAIRED_COUNCIL_EVALUATOR_RELEASE_MISMATCH';
            $metrics = $candidate['metrics'];
            $minTrades = $version->manifest['evaluation_policy']['minimum_paired_trades'];
            $comparatorMetrics = [$solo['metrics']];
            if ($champion) $comparatorMetrics[] = $champion['metrics'];
            $powered = min([$metrics['matured_trades'], ...array_column($comparatorMetrics, 'matured_trades')]) >= $minTrades;
            if (! $powered) { $underpowered = true; $errors[] = 'COMPARISON_MATURE_OUTCOME_POWER_INSUFFICIENT'; }
            $policy = $version->manifest['execution'];
            foreach (['max_drawdown_percent', 'max_daily_loss_percent', 'max_gross_exposure_percent', 'max_total_risk_percent'] as $key) {
                if ($metrics[$key] > $policy[$key]) $errors[] = 'COUNCIL_EXTERNAL_RISK_LIMIT_EXCEEDED';
            }
            $gain = $plan['objective'] === 'net_return_at_equal_risk'
                ? $metrics['net_profit'] > max(array_column($comparatorMetrics, 'net_profit'))
                    && $metrics['max_drawdown_percent'] <= min(array_column($comparatorMetrics, 'max_drawdown_percent'))
                : $metrics['net_profit'] >= max(array_column($comparatorMetrics, 'net_profit'))
                    && $metrics['max_drawdown_percent'] < min(array_column($comparatorMetrics, 'max_drawdown_percent'));
            $declaredAblations = array_values(array_filter($plan['arms'], fn (array $arm): bool =>
                $arm['window_key'] === $windowKey && $arm['kind'] === 'ablation'));
            $targets = $independent ? $version->manifest['evaluation_policy']['required_ablations']
                : array_values(array_unique(array_column($declaredAblations, 'removed_id')));
            if (! $independent && $targets === []) $errors[] = 'RESEARCH_INCREMENTAL_ABLATION_NOT_PREREGISTERED';
            $ablations = [];
            foreach ($targets as $removed) {
                $ablation = $this->findArm($windowArms, 'ablation', $removed);
                if (! $ablation) $errors[] = 'ABLATION_MISSING:'.$removed;
                else {
                    if ($ablation['metrics']['matured_trades'] < $minTrades) { $underpowered = true; $errors[] = 'ABLATION_MATURE_OUTCOME_POWER_INSUFFICIENT:'.$removed; }
                    $incremental = $metrics['net_profit'] > $ablation['metrics']['net_profit'];
                    $ablations[] = ['removed_id' => $removed, 'metrics' => $ablation['metrics'],
                        'net_profit_delta' => $metrics['net_profit'] - $ablation['metrics']['net_profit'],
                        'incremental_value_observed' => $incremental, 'component_authority_granted' => false];
                    if (! $incremental) $errors[] = 'COMPONENT_INCREMENTAL_VALUE_NOT_SHOWN:'.$removed;
                }
            }
            $retention = $this->findArm($windowArms, 'retention');
            if ($independent && (! $retention || $retention['metrics']['net_profit'] < $champion['metrics']['net_profit']
                || $retention['metrics']['max_drawdown_percent'] > $champion['metrics']['max_drawdown_percent'])) $errors[] = 'IMPORTANT_CAPABILITY_RETENTION_NOT_SHOWN';
            $transfer = null;
            if (isset($plan['descendant_programs'])) {
                $traitId = $plan['descendant_programs']['trait']['component_id'];
                $pu = $this->findArm($windowArms, 'ablation', $traitId);
                $fourPowered = $powered && $pu && $retention
                    && min($pu['metrics']['matured_trades'], $retention['metrics']['matured_trades']) >= $minTrades;
                $retainedTrait = $pu && $champion['metrics']['net_profit'] > $solo['metrics']['net_profit']
                    && $metrics['net_profit'] > $pu['metrics']['net_profit'];
                $activated = isset($traitCoverage[$windowKey]['champion'], $traitCoverage[$windowKey]['candidate'])
                    && $traitCoverage[$windowKey]['champion']['reason_codes'] === [] && $traitCoverage[$windowKey]['candidate']['reason_codes'] === [];
                if (! $fourPowered) { $underpowered = true; $errors[] = 'DESCENDANT_ORIGINAL_FOUR_PROGRAM_POWER_INSUFFICIENT'; }
                if (! $retainedTrait) $errors[] = 'DESCENDANT_TRAIT_TRANSFER_NOT_RETAINED';
                if (! $activated) $errors[] = 'DESCENDANT_ORIGINAL_TRAIT_DISPATCH_NOT_OBSERVED';
                $transfer = ['p' => $solo['metrics'], 'p_plus_t' => $champion['metrics'], 'p_plus_t_plus_u' => $metrics,
                    'p_plus_u' => $pu['metrics'] ?? null, 'trait_component_id' => $traitId,
                    'powered' => (bool) $fourPowered, 'trait_retained' => (bool) $retainedTrait,
                    'actual_trait_dispatch' => (bool) $activated, 'promotion_evidence' => false];
            }
            if ($gain) $positive++;
            $comparisons[] = ['window_key' => $windowKey, 'candidate' => $metrics,
                'champion' => $champion['metrics'] ?? null, 'solo' => $solo['metrics'], 'incremental_value' => $gain,
                'net_profit_delta_vs_solo' => $metrics['net_profit'] - $solo['metrics']['net_profit'],
                'powered' => $powered, 'ablations' => $ablations, 'independently_confirmed' => false,
                ...($transfer ? ['descendant_transfer' => $transfer] : [])];
        }
        if ($plan['purpose'] !== 'independent') $errors[] = 'RESEARCH_COMPARISON_HAS_NO_INDEPENDENT_PROMOTION_AUTHORITY';
        if (count($plan['windows']) < $version->manifest['evaluation_policy']['minimum_independent_windows']) $errors[] = 'INSUFFICIENT_INDEPENDENT_WINDOWS';
        if ($positive < 2) $errors[] = 'INDEPENDENT_BENEFIT_NOT_REPLICATED';
        $errors = array_values(array_unique($errors));
        $originalErrors = array_values(array_filter($originalErrors, fn (string $reason): bool =>
            ! str_starts_with($reason, 'SPECIALIST_MATURE_HORIZON_EVIDENCE_UNDERPOWERED:')
            && ! ($dataDependencies !== [] && str_starts_with($reason, 'SPECIALIST_ACTUAL_ROLE_DISPATCH_NOT_OBSERVED:'))
            && $reason !== 'COUNCIL_EXECUTION_DATA_PREREQUISITES_MISSING'));
        $underpowered = $underpowered || collect($errors)->contains(fn (string $reason): bool =>
            str_starts_with($reason, 'SPECIALIST_MATURE_HORIZON_EVIDENCE_UNDERPOWERED:'));
        $researchStatus = $originalErrors !== [] || $comparisons === []
            || in_array('PAIRED_COUNCIL_EVALUATOR_RELEASE_MISMATCH', $errors, true) ? 'technical_unassessable'
            : (in_array('COUNCIL_EXECUTION_DATA_PREREQUISITES_MISSING', $errors, true)
                ? 'data_missing' : ($underpowered ? 'underpowered' : 'research_compared'));
        $qualifiedRoles = [];
        if ($errors === []) {
            foreach ($version->manifest['members'] as $member) {
                if (in_array($member['role'], SpecialistCouncilContractService::TRADING_ROLES, true)
                    && count($roleCoverage[$member['specialist_id']] ?? []) >= $version->manifest['evaluation_policy']['minimum_independent_windows']) $qualifiedRoles[] = $member['role'];
            }
        }
        $support = $this->assessSupportRoles($version, $plan, $arms, $originalErrors);
        $assessment = ['protocol' => self::ASSESSMENT_PROTOCOL, 'version_id' => $version->id,
            'manifest_hash' => $version->manifest_hash, 'plan_hash' => $owner['hash'],
            'original_run_ids' => array_values($runIds), 'original_sources' => $sources,
            'comparisons' => $comparisons, 'positive_independent_windows' => $plan['purpose'] === 'independent' ? $positive : 0,
            'observed_positive_windows' => $positive, 'research_observation_status' => $researchStatus,
            'data_dependency_reasons' => array_values(array_unique($dataDependencies)),
            'memory_selector_comparisons' => $memoryComparisons, 'memory_superiority_proven' => false,
            'role_mature_outcome_coverage' => $roleCoverage, 'qualified_roles' => array_values(array_unique($qualifiedRoles)),
            'qualified' => $errors === [], 'reason_codes' => $errors, 'promotion_evidence' => false];
        if (! empty($plan['support_role_trials'])) $assessment['support_role_qualifications'] = $support;
        return $assessment;
    }

    /** Role evidence is derived only from the original native producers above, never the plan's asserted outcomes. */
    private function assessSupportRoles(SpecialistCouncilVersion $version, array $plan, array $arms, array $originalErrors): array
    {
        $qualifications = [];
        foreach ((array) ($plan['support_role_trials'] ?? []) as $trial) {
            $id = $trial['component_id']; $reasons = []; $comparisons = []; $positive = 0;
            $validated = $this->contracts->sealSupportRoleTrials($version->manifest, [$trial]);
            if ($this->epochs->parameterHash($validated[0]) !== $this->epochs->parameterHash($trial)) $reasons[] = 'SUPPORT_ROLE_SEALED_TRIAL_CHANGED';
            if ($plan['purpose'] !== 'independent') $reasons[] = 'SUPPORT_ROLE_AUTHORIZED_INDEPENDENT_WINDOWS_REQUIRED';
            if (count($plan['windows']) < $version->manifest['evaluation_policy']['minimum_independent_windows']) $reasons[] = 'SUPPORT_ROLE_INDEPENDENT_WINDOW_POWER_INSUFFICIENT';
            // Trader sample size is not a toolbox/risk/execution requirement. Original
            // producer, source, calendar, authorization and cost integrity still are.
            $integrityErrors = array_values(array_filter($originalErrors, fn (string $reason): bool =>
                ! str_starts_with($reason, 'SPECIALIST_MATURE_HORIZON_EVIDENCE_UNDERPOWERED:')));
            if ($integrityErrors !== []) $reasons[] = 'SUPPORT_ROLE_ORIGINAL_EVIDENCE_INVALID';
            foreach ($plan['windows'] as $windowKey => $window) {
                $windowArms = array_filter($arms, fn (array $arm): bool => $arm['window_key'] === $windowKey);
                $candidate = $this->findArm($windowArms, 'candidate');
                $ablation = $this->findArm($windowArms, 'ablation', $id);
                $retention = $this->findArm($windowArms, 'retention');
                $windowReasons = []; $proofs = [];
                foreach (['candidate' => $candidate, 'ablation' => $ablation, 'retention' => $retention] as $kind => $arm) {
                    $producer = $arm['support_producer'] ?? null;
                    if (! is_array($producer) || ($producer['receipt']['status'] ?? '') !== 'computed'
                        || ($producer['receipt']['asof_policy'] ?? '') !== 'previous_closed_candle_next_open') {
                        $windowReasons[] = 'SUPPORT_ROLE_ORIGINAL_PRODUCER_MISSING:'.$kind;
                        continue;
                    }
                    $proofs[$kind] = $producer;
                }
                if (count($proofs) === 3 && count(array_unique(array_column($proofs, 'code_hash'))) !== 1) $windowReasons[] = 'SUPPORT_ROLE_PAIRED_RELEASE_MISMATCH';
                $positiveHere = false;
                $observations = [];
                if ($windowReasons === []) {
                    foreach (['candidate', 'retention'] as $kind) {
                        $observations[$kind] = $this->nativeSupportObservation($trial, $proofs[$kind]);
                        $windowReasons = [...$windowReasons, ...$observations[$kind]['reason_codes']];
                    }
                    if (isset($trial['original_native_policy_benchmark'])) {
                        $native = $this->nativePolicyBenchmarkObservation($trial, $window);
                        $windowReasons = [...$windowReasons, ...$native['reason_codes']];
                        $observations['original_policy_panel'] = $native;
                    }
                    $ablationMembers = (array) ($proofs['ablation']['runtime']['members'] ?? []);
                    foreach ($ablationMembers as $member) {
                        if (data_get($member, 'operator_contract.component_id') === $id) $windowReasons[] = 'SUPPORT_ROLE_ABLATION_OPERATOR_REMAINED';
                    }
                    if (($proofs['ablation']['runtime']['ablation_removed_id'] ?? '') !== $id) $windowReasons[] = 'SUPPORT_ROLE_EXACT_ABLATION_NOT_OBSERVED';
                    if ($windowReasons === []) {
                        // Correctness certificates for infrastructure roles do not
                        // claim causal P&L contribution from removing hard safety.
                        $positiveHere = true;
                        if (isset($trial['original_native_policy_benchmark'])) $positiveHere = ($native['positive_role_capability'] ?? false) === true;
                        if ($trial['role'] === 'risk') {
                            $baseline = $ablation['metrics']['max_total_risk_percent'];
                            $candidateRisk = $candidate['metrics']['max_total_risk_percent'];
                            $retainedRisk = $retention['metrics']['max_total_risk_percent'];
                            $baselineEntries = $this->executedIntents($proofs['ablation']['receipt']);
                            $minimumEntries = (int) ceil($baselineEntries * $trial['minimum_opportunity_fraction']);
                            $positiveHere = $baseline > 0 && $candidateRisk < $baseline && $retainedRisk < $baseline
                                && $this->executedIntents($proofs['candidate']['receipt']) >= $minimumEntries
                                && $this->executedIntents($proofs['retention']['receipt']) >= $minimumEntries;
                            if (! $positiveHere) $windowReasons[] = 'SUPPORT_ROLE_RISK_REDUCTION_OR_OPPORTUNITY_RETENTION_NOT_SHOWN';
                        }
                    }
                }
                if ($positiveHere) $positive++;
                $reasons = [...$reasons, ...$windowReasons];
                $comparisons[] = ['window_key' => $windowKey,
                    'original_run_ids' => array_values(array_column($proofs, 'run_id')),
                    'producer_receipt_hashes' => array_map(fn (array $proof): string => $proof['receipt']['receipt_hash'], $proofs),
                    'observations' => $observations, 'positive_role_capability' => $positiveHere,
                    'reason_codes' => array_values(array_unique($windowReasons))];
            }
            if ($positive < $trial['minimum_positive_windows']) $reasons[] = 'SUPPORT_ROLE_CAPABILITY_NOT_INDEPENDENTLY_REPLICATED';
            $reasons = array_values(array_unique($reasons));
            $body = ['protocol' => self::SUPPORT_QUALIFICATION_PROTOCOL, 'version_id' => $version->id,
                'component_id' => $id, 'role' => $trial['role'], 'component_contract_hash' => $trial['component_contract_hash'],
                'trial_hash' => $trial['trial_hash'], 'benchmark' => $trial['benchmark'],
                'status' => $reasons === [] ? 'research_role_qualified' : 'dependency',
                'positive_independent_windows' => $plan['purpose'] === 'independent' ? $positive : 0,
                'observed_positive_windows' => $positive, 'comparisons' => $comparisons, 'reason_codes' => $reasons,
                'authority' => 'scoped_research_component_only', 'economic_skill_proven' => false,
                'causal_component_value_proven' => false,
                'paper_authority_granted' => false, 'live_authority_granted' => false, 'promotion_evidence' => false];
            $qualifications[$id] = [...$body, 'qualification_hash' => $this->epochs->parameterHash($body)];
        }
        return $qualifications;
    }

    private function executedIntents(array $receipt): int
    {
        return count(array_filter((array) ($receipt['intent_execution_ledger'] ?? []), fn (array $event): bool =>
            ($event['stage'] ?? '') === 'execution' && ($event['reason'] ?? '') === 'filled'));
    }

    /** Callable original producer benchmark; unsupported role adapters stay typed dependencies. */
    private function nativeSupportObservation(array $trial, array $producer): array
    {
        $receipt = $producer['receipt']; $reasons = []; $calls = 0; $changed = 0;
        if (in_array($trial['benchmark'], ['native_account_capital', 'native_account_execution', 'native_asof_observation'], true)) {
            return $this->nativeInfrastructureSupportObservation($trial, $producer);
        }
        if (! in_array($trial['benchmark'], ['native_operator_behavior', 'native_operator_risk'], true)) {
            if (isset($trial['original_native_policy_benchmark'])) return $this->nativePolicyBenchmarkObservation($trial);
            if (isset($trial['original_policy_benchmark'])) return $this->originalPolicyBenchmarkObservation($trial);
            return ['evaluations' => 0, 'behavior_delta_decisions' => 0,
                'reason_codes' => ['SUPPORT_ROLE_ORIGINAL_BENCHMARK_ADAPTER_REQUIRED:'.$trial['benchmark']]];
        }
        if ($trial['producer_contracts'] === []) $reasons[] = 'SUPPORT_ROLE_ACTUAL_OPERATOR_BINDING_REQUIRED';
        foreach ($trial['producer_contracts'] as $memberId => $contract) {
            $member = collect((array) ($receipt['members'] ?? []))->firstWhere('specialist_id', $memberId);
            $operator = (array) ($member['operator_receipt'] ?? []);
            foreach (['contract_hash', 'ast_hash', 'source_task_key', 'target'] as $field) {
                if (($operator[$field] ?? null) !== $contract[$field]) $reasons[] = 'SUPPORT_ROLE_ORIGINAL_OPERATOR_IDENTITY_MISMATCH';
            }
            if (($operator['protocol'] ?? '') !== 'typed_operator_decision_v1' || ($operator['research_only'] ?? null) !== true
                || ($operator['cpu_budget_compliant'] ?? null) !== true
                || ! is_int($operator['calls'] ?? null) || $operator['calls'] < 0 || $operator['calls'] > $contract['budget']['max_calls']
                || ! is_int($operator['node_evaluations'] ?? null) || $operator['node_evaluations'] < 0 || $operator['node_evaluations'] > $contract['budget']['max_node_evaluations']
                || ! is_int($operator['behavior_delta_decisions'] ?? null) || $operator['behavior_delta_decisions'] < 0
                || $operator['behavior_delta_decisions'] > $operator['calls']
                || ! preg_match('/^[a-f0-9]{64}$/', (string) ($operator['observation_hash'] ?? ''))
                || (float) ($operator['cpu_seconds_budget'] ?? 0) !== (float) $contract['budget']['cpu_seconds']) {
                $reasons[] = 'SUPPORT_ROLE_ORIGINAL_COMPUTE_OR_BEHAVIOR_RECEIPT_INVALID';
            }
            $calls += (int) ($operator['calls'] ?? 0); $changed += (int) ($operator['behavior_delta_decisions'] ?? 0);
        }
        if ($calls < $trial['minimum_evaluations']) $reasons[] = 'SUPPORT_ROLE_ORIGINAL_EVALUATION_POWER_INSUFFICIENT';
        if ($changed < $trial['minimum_behavior_delta_decisions']) $reasons[] = 'SUPPORT_ROLE_ACTUAL_BEHAVIOR_CONTRIBUTION_NOT_OBSERVED';
        return ['evaluations' => $calls, 'behavior_delta_decisions' => $changed, 'reason_codes' => array_values(array_unique($reasons))];
    }

    /** Callable prospective benchmark intake through the existing original equal-budget producer. */
    public function preregisterSupportPolicyBenchmark(SpecialistCouncilVersion $version, string $evaluatorId,
        string $componentId, array $policyKeys, array $experimentIds, string $seed): array
    {
        $version = $this->verified($version); $this->independentActor($version, $evaluatorId);
        if ($version->state !== 'draft') throw new LogicException('SUPPORT_ROLE_POLICY_BENCHMARK_REQUIRES_DRAFT');
        $component = collect($version->manifest['components'])->firstWhere('id', $componentId);
        if (! $component || ! in_array($component['role'], ['learning', 'evolution'], true)
            || ! in_array($componentId, $policyKeys, true)) throw new LogicException('SUPPORT_ROLE_POLICY_COMPONENT_IDENTITY_MISMATCH');
        $challenge = app(ResearchKnowledgePortfolioService::class)->preregisterPolicyChallenge($policyKeys, $experimentIds, $seed);
        if (($challenge['status'] ?? '') === 'blocked') return $challenge;
        return ['status' => 'original_benchmark_preregistered', 'component_id' => $componentId,
            'original_policy_benchmark' => $this->contracts->supportPolicyBenchmarkReference($component,
                $challenge['knowledge_key'], $componentId), 'research_only' => true, 'promotion_evidence' => false];
    }

    /** Settlement executes the original producer, not caller-supplied benchmark scores. */
    public function settleSupportPolicyBenchmark(SpecialistCouncilVersion $version, string $evaluatorId, string $componentId): array
    {
        $version = $this->verified($version); $owner = $this->plan($version);
        $this->independentActor($version, $evaluatorId);
        if ($owner['evaluator_id'] !== $evaluatorId) throw new LogicException('SUPPORT_ROLE_ORIGINAL_EVALUATOR_REQUIRED');
        $trial = collect($owner['plan']['support_role_trials'] ?? [])->firstWhere('component_id', $componentId);
        if (! isset($trial['original_policy_benchmark'])) throw new LogicException('SUPPORT_ROLE_ORIGINAL_POLICY_BENCHMARK_NOT_PREREGISTERED');
        $result = app(ResearchKnowledgePortfolioService::class)->settlePolicyChallenge($trial['original_policy_benchmark']['challenge_key']);
        return ['status' => ($result['status'] ?? '') === 'fixed_real_question_comparison' ? 'provisional_original_benchmark' : 'dependency',
            'benchmark' => $result, 'observation' => $this->originalPolicyBenchmarkObservation($trial),
            'role_qualified' => false, 'policy_activated' => false, 'promotion_evidence' => false];
    }

    /** Bounded prospective independent producer route; no policy scores/authorization flags are accepted. */
    public function preregisterSupportNativePolicyBenchmark(SpecialistCouncilVersion $version, string $evaluatorId,
        string $componentId, string $ablationPolicyKey, string $retentionPolicyKey, string $axis,
        array $panels, string $seed, float $wallBudget): array
    {
        $version = $this->verified($version); $this->independentActor($version, $evaluatorId);
        if ($version->state !== 'draft') throw new LogicException('SUPPORT_ROLE_NATIVE_POLICY_BENCHMARK_REQUIRES_DRAFT');
        $component = collect($version->manifest['components'])->firstWhere('id', $componentId);
        if (! $component || ! in_array($component['role'], ['learning', 'evolution'], true)) {
            throw new LogicException('SUPPORT_ROLE_NATIVE_POLICY_COMPONENT_REQUIRED');
        }
        $challenge = app(ResearchKnowledgePortfolioService::class)->preregisterNativePolicyChallenge($componentId,
            $ablationPolicyKey, $retentionPolicyKey, $axis, $panels, $seed, $wallBudget, $evaluatorId);
        if (($challenge['status'] ?? '') === 'blocked') return $challenge;
        foreach ($challenge['panels'] as $panel) foreach (['target_cases', 'retention_cases'] as $kind) foreach ($panel[$kind]['cases'] as $spec) {
            foreach ($spec['scope'] as $scope) if (! collect($version->manifest['members'])->contains(fn ($member) =>
                $this->evidence->equivalentJsonValue($member['scope'], $scope))) throw new LogicException('SUPPORT_ROLE_NATIVE_POLICY_PANEL_SCOPE_WIDENED');
        }
        return ['status' => 'original_native_policy_benchmark_preregistered', 'component_id' => $componentId,
            'original_native_policy_benchmark' => $this->contracts->supportNativePolicyBenchmarkReference($component, $challenge['knowledge_key']),
            'role_qualified' => false, 'policy_activated' => false, 'promotion_evidence' => false];
    }

    public function settleSupportNativePolicyBenchmark(SpecialistCouncilVersion $version, string $evaluatorId, string $componentId): array
    {
        $version = $this->verified($version); $owner = $this->plan($version); $this->independentActor($version, $evaluatorId);
        if ($owner['evaluator_id'] !== $evaluatorId) throw new LogicException('SUPPORT_ROLE_ORIGINAL_EVALUATOR_REQUIRED');
        $trial = collect($owner['plan']['support_role_trials'] ?? [])->firstWhere('component_id', $componentId);
        if (! isset($trial['original_native_policy_benchmark'])) throw new LogicException('SUPPORT_ROLE_NATIVE_POLICY_BENCHMARK_NOT_PREREGISTERED');
        $result = app(ResearchKnowledgePortfolioService::class)->settleNativePolicyChallenge($trial['original_native_policy_benchmark']['challenge_key']);
        return ['status' => $result['status'], 'benchmark' => $result, 'observation' => $this->nativePolicyBenchmarkObservation($trial),
            'role_qualified' => false, 'policy_activated' => false, 'promotion_evidence' => false];
    }

    private function nativePolicyBenchmarkObservation(array $trial, ?array $window = null): array
    {
        $reference = $trial['original_native_policy_benchmark'];
        $component = ['id' => $trial['component_id'], 'role' => $trial['role'], 'version' => '1'];
        $fresh = $this->contracts->supportNativePolicyBenchmarkReference($component, $reference['challenge_key']);
        $reasons = [];
        if ($this->epochs->parameterHash($fresh) !== $this->epochs->parameterHash($reference)) $reasons[] = 'SUPPORT_ROLE_ORIGINAL_NATIVE_POLICY_REFERENCE_DRIFT';
        // Re-execute the pure original producer verification, not a stored qualified/result flag.
        $result = app(ResearchKnowledgePortfolioService::class)->inspectNativePolicyChallenge($reference['challenge_key']);
        $resultKey = hash('sha256', ResearchKnowledgePortfolioService::META_PROTOCOL.'|native_policy_challenge_result|'.$reference['challenge_key']);
        $stored = $this->contracts->originalPolicyJournal($resultKey, 'native_policy_challenge_result');
        if ($stored === null || $this->epochs->parameterHash($stored) !== $this->epochs->parameterHash($result)) {
            $reasons[] = 'SUPPORT_ROLE_ORIGINAL_NATIVE_POLICY_RESULT_NOT_SETTLED_OR_DRIFTED';
        }
        if (($result['status'] ?? '') !== 'original_independent_native_question_comparison') $reasons[] = $result['reason'] ?? 'SUPPORT_ROLE_ORIGINAL_NATIVE_POLICY_PANELS_REQUIRED';
        $panels = (array) ($result['panels'] ?? []);
        if ($window !== null) {
            $panels = array_filter($panels, fn ($panel) => $this->evidence->equivalentJsonValue($panel['window'], $window));
            if (count($panels) !== 1) $reasons[] = 'SUPPORT_ROLE_NATIVE_POLICY_EXACT_AUTHORIZED_WINDOW_REQUIRED';
        }
        $evaluations = 0; $changed = 0;
        foreach ($panels as $panel) {
            $evaluations += count($panel['original_question_outcomes']['target_cases']) + count($panel['original_question_outcomes']['retention_cases']);
            $changed += (int) $panel['actual_prefix_selection_changed'];
            if (! $panel['retained_original_role_utility']) $reasons[] = 'SUPPORT_ROLE_NATIVE_POLICY_RETENTION_REGRESSED';
        }
        $positive = count(array_filter($panels, fn ($panel) => $panel['positive_role_capability']));
        if ($window === null && $positive < $trial['minimum_positive_windows']) $reasons[] = 'SUPPORT_ROLE_NATIVE_POLICY_PREFIX_UTILITY_NOT_INDEPENDENTLY_REPLICATED';
        if ($evaluations < $trial['minimum_evaluations']) $reasons[] = 'SUPPORT_ROLE_ORIGINAL_EVALUATION_POWER_INSUFFICIENT';
        if ($changed < $trial['minimum_behavior_delta_decisions']) $reasons[] = 'SUPPORT_ROLE_ACTUAL_BEHAVIOR_CONTRIBUTION_NOT_OBSERVED';
        return ['evaluations' => $evaluations, 'behavior_delta_decisions' => $changed, 'original_panels' => $panels,
            'positive_role_capability' => $window !== null && $positive === 1,
            'original_result_hash' => $this->epochs->parameterHash(array_diff_key($result, ['knowledge_key' => true])),
            'compute_advantage_proven' => false, 'selector_cpu_seconds' => null,
            'resource_scope' => 'original_end_to_end_wall_seconds_not_cpu', 'reason_codes' => array_values(array_unique($reasons))];
    }

    /** Explicit scoped research consumption executes the original declarative ranker; no scheduler/paper policy changes. */
    public function rankQualifiedResearchQuestions(SpecialistCouncilVersion $source, string $componentId, array $caseRefs, string $seed): array
    {
        $binding = $this->researchSupportBinding($source, $componentId);
        if (! in_array($binding['role'], ['learning', 'evolution'], true) || count($caseRefs) < 2 || count($caseRefs) > 16) {
            throw new LogicException('QUALIFIED_BOUNDED_RESEARCH_POLICY_REQUIRED');
        }
        $owner = $this->plan($source); $inputs = []; $questions = []; $cases = [];
        foreach ($caseRefs as $case) {
            if (! is_array($case) || array_diff(array_keys($case), ['version_id', 'window_key']) !== []) throw new LogicException('QUALIFIED_POLICY_CALLER_FEATURES_FORBIDDEN');
            $caseOwner = $this->plan($this->verified(SpecialistCouncilVersion::findOrFail($case['version_id'])));
            $spec = $this->nativePolicyQuestionSpec((int) $case['version_id'], (string) $case['window_key'], $caseOwner['evaluator_id']);
            foreach ($spec['scope'] as $scope) if (! collect($binding['scope'])->contains(fn ($original) =>
                $this->evidence->equivalentJsonValue($scope, $original))) throw new LogicException('QUALIFIED_POLICY_CONSUMPTION_SCOPE_WIDENED');
            if (isset($questions[$spec['question_hash']])) throw new LogicException('QUALIFIED_POLICY_QUESTION_DUPLICATED');
            $questions[$spec['question_hash']] = true; $inputs[] = $spec['selector_input'];
            $cases[] = \Illuminate\Support\Arr::only($spec, ['version_id', 'window_key', 'question_hash', 'manifest_hash',
                'plan_hash', 'physical_hash', 'source_hash', 'scope', 'evaluator_id']);
        }
        $rank = app(ResearchKnowledgePortfolioService::class)->rankResearchQuestions($inputs, $seed, $componentId, false);
        if (($rank['status'] ?? '') !== 'research_ranking' || count((array) ($rank['ranking'] ?? [])) !== count($inputs)) {
            throw new LogicException('QUALIFIED_POLICY_MATCHED_OPPORTUNITY_OR_CAP_REQUIRED');
        }
        $trial = collect($owner['plan']['support_role_trials'] ?? [])->firstWhere('component_id', $componentId);
        if (! is_array($trial['original_native_policy_benchmark'] ?? null)) throw new LogicException('QUALIFIED_ORIGINAL_NATIVE_POLICY_BENCHMARK_REQUIRED');
        return [...$rank, 'original_qualification_binding' => $binding, 'original_question_cases' => $cases,
            'source_plan_hash' => $owner['hash'], 'native_benchmark_reference' => $trial['original_native_policy_benchmark'],
            'actual_policy_consumed' => true,
            'research_only' => true, 'paper_authority_granted' => false, 'promotion_evidence' => false];
    }

    private function originalPolicyBenchmarkObservation(array $trial): array
    {
        $reference = $trial['original_policy_benchmark'];
        $key = hash('sha256', ResearchKnowledgePortfolioService::META_PROTOCOL.'|policy_challenge_result|'.$reference['challenge_key']);
        $result = $this->contracts->originalPolicyJournal($key, 'policy_challenge_result');
        $reasons = ['SUPPORT_ROLE_ORIGINAL_BENCHMARK_ADAPTER_REQUIRED:'.$trial['benchmark']];
        $score = $result['scores'][$reference['policy_key']] ?? null;
        if (! is_array($score) || ($result['status'] ?? '') !== 'fixed_real_question_comparison'
            || ($result['challenge_key'] ?? '') !== $reference['challenge_key']
            || ($result['policy_activated'] ?? null) !== false || ($result['economic_authority'] ?? null) !== false) {
            $reasons[] = 'SUPPORT_ROLE_ORIGINAL_FIXED_QUESTION_OUTCOMES_REQUIRED';
            return ['evaluations' => 0, 'behavior_delta_decisions' => null, 'reason_codes' => $reasons];
        }
        return ['evaluations' => $score['assessable_local_questions'], 'behavior_delta_decisions' => null,
            'status' => 'provisional_original_equal_budget_benchmark',
            'original_challenge_key' => $reference['challenge_key'], 'original_result_key' => $key,
            'original_result_hash' => $this->epochs->parameterHash($result), 'policy_key' => $reference['policy_key'],
            'original_fold_receipt_ids' => array_merge(...array_column($result['outcomes'], 'receipt_ids')),
            'same_budget_policy_scores' => $result['scores'], 'selector_cpu_seconds' => $score['selector_cpu_seconds'],
            'compute_advantage_proven' => false, 'role_qualified' => false, 'policy_activated' => false,
            'reason_codes' => $reasons];
    }

    private function nativeInfrastructureSupportObservation(array $trial, array $producer): array
    {
        $receipt = $producer['receipt']; $runtime = $producer['runtime']; $reasons = [];
        $binding = $trial['policy_binding'] ?? null; $observations = 0;
        if (! is_array($binding)) $reasons[] = 'SUPPORT_ROLE_NATIVE_POLICY_BINDING_REQUIRED:'.$trial['role'];
        if ($trial['role'] === 'data') {
            if (($receipt['source_attestation']['status'] ?? '') !== 'verified'
                || ($receipt['asof_policy'] ?? '') !== 'previous_closed_candle_next_open') $reasons[] = 'SUPPORT_ROLE_ORIGINAL_ASOF_SOURCE_ATTESTATION_REQUIRED';
            $uses = DB::table('specialist_council_data_uses as uses')
                ->join('specialist_council_data_events as events', 'events.id', '=', 'uses.event_id')
                ->where('uses.run_id', $producer['run_id'])->where('uses.use', 'evaluation')
                ->where('uses.specialist_council_version_id', $producer['version_id'])
                ->select('uses.as_of', 'events.available_at', 'events.matured_at', 'events.event_end')->get();
            foreach ($uses as $use) {
                if (! $use->matured_at || CarbonImmutable::parse($use->available_at, 'UTC')->greaterThan(CarbonImmutable::parse($use->as_of, 'UTC'))
                    || CarbonImmutable::parse($use->matured_at, 'UTC')->greaterThan(CarbonImmutable::parse($use->as_of, 'UTC'))
                    || CarbonImmutable::parse($use->available_at, 'UTC')->lessThan(CarbonImmutable::parse($use->event_end, 'UTC'))) {
                    $reasons[] = 'SUPPORT_ROLE_ORIGINAL_EVENT_USE_CHRONOLOGY_INVALID';
                }
            }
            $observations = $uses->count();
            if ($observations === 0) $reasons[] = 'SUPPORT_ROLE_ORIGINAL_DATA_USE_PRODUCER_MISSING';
        } else {
            $identity = $runtime[$trial['role'] === 'capital' ? 'allocation_identity' : 'execution_identity'] ?? [];
            if (! is_array($binding) || ($identity['id'] ?? '') !== ($binding['id'] ?? '')
                || (string) ($identity['version'] ?? '') !== ($binding['version'] ?? '')) $reasons[] = 'SUPPORT_ROLE_ORIGINAL_POLICY_IDENTITY_MISMATCH';
            if ($trial['role'] === 'capital') {
                if ($this->epochs->parameterHash($identity) !== ($binding['policy_hash'] ?? '')
                    || ! $this->evidence->equivalentJsonValue(array_column($runtime['members'], 'capital_weight', 'specialist_id'), $binding['member_weights'] ?? [])) {
                    $reasons[] = 'SUPPORT_ROLE_ORIGINAL_ALLOCATION_VECTOR_CHANGED';
                }
                foreach ((array) ($receipt['account_ledger'] ?? []) as $point) {
                    $equity = $this->number($point['equity'] ?? null); $reserved = $this->number($point['reserved_capital'] ?? null);
                    if ($reserved < 0 || $reserved > max(0, $equity) + 1e-7
                        || abs($equity - $reserved - $this->number($point['free_capital'] ?? null)) > 1e-7 * max(1, abs($equity))) {
                        $reasons[] = 'SUPPORT_ROLE_CAPITAL_CONSERVATION_OR_RESERVATION_FAILED';
                    }
                }
                $observations = count((array) ($receipt['account_ledger'] ?? []));
            } else {
                $expectedPolicy = [...($runtime['policy'] ?? []), 'id' => $identity['id'] ?? '', 'version' => (string) ($identity['version'] ?? '')];
                if ($this->epochs->parameterHash($expectedPolicy) !== ($binding['policy_hash'] ?? '')) $reasons[] = 'SUPPORT_ROLE_ORIGINAL_EXECUTION_POLICY_CHANGED';
                $observations = $this->executedIntents($receipt);
                if (abs($this->number($receipt['account']['reconciliation_error'] ?? null)) > 1e-7) $reasons[] = 'SUPPORT_ROLE_NATIVE_EXECUTION_RECONCILIATION_FAILED';
                // This producer is candle replay, not broker latency/partial fill competence.
                if (($receipt['execution_capabilities']['candle_execution'] ?? null) !== true) $reasons[] = 'SUPPORT_ROLE_ACTUAL_EXECUTION_CAPABILITY_MISSING';
            }
        }
        if ($observations < $trial['minimum_evaluations']) $reasons[] = 'SUPPORT_ROLE_ORIGINAL_EVALUATION_POWER_INSUFFICIENT';
        return ['evaluations' => $observations, 'behavior_delta_decisions' => null,
            'qualification_scope' => 'native_candle_correctness_only_not_learned_economic_value',
            'reason_codes' => array_values(array_unique($reasons))];
    }

    /** Reverify the immutable exam and producer on every use; this is never paper/trading approval. */
    public function researchSupportBinding(SpecialistCouncilVersion $version, string $componentId): array
    {
        $version = $this->verified($version); $owner = $this->plan($version);
        $this->independentActor($version, $owner['evaluator_id']);
        $exam = DB::table('specialist_council_evaluations')->where('specialist_council_version_id', $version->id)->first();
        if (! $exam || $exam->evaluator_id !== $owner['evaluator_id']
            || ! in_array($version->state, ['evaluated', 'approved', 'scheduled', 'active', 'retired', 'rolled_back'], true)
            || $this->epochs->parameterHash(json_decode($exam->assessment, true, 512, JSON_THROW_ON_ERROR)) !== $exam->assessment_hash
            || $exam->assessment_hash !== $version->assessment_hash) throw new LogicException('SUPPORT_ROLE_ORIGINAL_ASSESSMENT_MISSING_OR_CHANGED');
        $runIds = json_decode($exam->original_run_ids, true, 512, JSON_THROW_ON_ERROR);
        $assessment = $this->assessOriginalRuns($version, $owner, $runIds);
        if ($this->epochs->parameterHash($assessment) !== $exam->assessment_hash) throw new LogicException('SUPPORT_ROLE_ORIGINAL_PROOF_NO_LONGER_VALID');
        $proof = $assessment['support_role_qualifications'][$componentId] ?? null;
        $component = collect($version->manifest['components'])->firstWhere('id', $componentId);
        if (! is_array($proof) || ! $component || ($proof['status'] ?? '') !== 'research_role_qualified'
            || $proof['component_contract_hash'] !== $component['contract_hash']) {
            throw new LogicException('SUPPORT_ROLE_NOT_INDEPENDENTLY_QUALIFIED');
        }
        return ['protocol' => 'specialist_support_research_binding_v1', 'source_version_id' => $version->id,
            'source_manifest_hash' => $version->manifest_hash, 'assessment_hash' => $exam->assessment_hash,
            'component_id' => $componentId, 'component_contract_hash' => $component['contract_hash'],
            'qualification_hash' => $proof['qualification_hash'], 'role' => $proof['role'],
            'scope' => array_values(array_map(fn (array $member): array => $member['scope'], $version->manifest['members'])),
            'authority' => 'scoped_research_component_only', 'paper_authority_granted' => false, 'promotion_evidence' => false];
    }

    /** Native draft registration and each replay recheck research component adoption, not asserted flags. */
    private function assertResearchSupportConsumptions(array $manifest): void
    {
        $uses = (array) ($manifest['support_role_consumptions'] ?? []);
        if (count($uses) > 16) throw new LogicException('SUPPORT_ROLE_CONSUMPTION_BUDGET_EXCEEDED');
        foreach ($uses as $use) {
            if (! is_array($use) || array_diff(array_keys($use), ['source_version_id', 'component_id', 'qualification_hash']) !== []) {
                throw new LogicException('SUPPORT_ROLE_CONSUMPTION_ASSERTED_AUTHORITY_FORBIDDEN');
            }
            $source = SpecialistCouncilVersion::find($use['source_version_id'] ?? 0);
            if (! $source) throw new LogicException('SUPPORT_ROLE_CONSUMPTION_SOURCE_MISSING');
            $proof = $this->researchSupportBinding($source, (string) ($use['component_id'] ?? ''));
            $component = collect($manifest['components'])->firstWhere('id', $proof['component_id']);
            if (! $component || $component['contract_hash'] !== $proof['component_contract_hash']
                || ($use['qualification_hash'] ?? '') !== $proof['qualification_hash']) {
                throw new LogicException('SUPPORT_ROLE_CONSUMPTION_COMPONENT_OR_PROOF_CHANGED');
            }
            $originalTrial = collect($this->plan($source)['plan']['support_role_trials'] ?? [])->firstWhere('component_id', $proof['component_id']);
            $newTrial = $this->contracts->sealSupportRoleTrials($manifest, [$originalTrial])[0];
            if ($newTrial['producer_contracts'] === [] && empty($newTrial['policy_binding'])) throw new LogicException('SUPPORT_ROLE_CONSUMPTION_EXECUTABLE_BINDING_MISSING');
            if (! $this->evidence->equivalentJsonValue($newTrial['policy_binding'] ?? null, $originalTrial['policy_binding'] ?? null)) {
                throw new LogicException('SUPPORT_ROLE_CONSUMPTION_NATIVE_POLICY_CHANGED');
            }
            foreach ($newTrial['producer_contracts'] as $contract) {
                $compatible = collect($originalTrial['producer_contracts'])->contains(fn (array $original): bool =>
                    $this->evidence->equivalentJsonValue(array_diff_key($contract, ['passport_hash' => true]),
                        array_diff_key($original, ['passport_hash' => true])));
                if (! $compatible) throw new LogicException('SUPPORT_ROLE_CONSUMPTION_EXECUTABLE_OPERATOR_CHANGED');
            }
            foreach ($manifest['members'] as $member) {
                if (data_get($member, 'operator_contract.component_id') !== $proof['component_id']
                    && (empty($newTrial['policy_binding']) || ! in_array($member['role'], $component['consumer_roles'], true))) continue;
                if (! collect($proof['scope'])->contains(fn (array $scope): bool => $this->evidence->equivalentJsonValue($scope, $member['scope']))) {
                    throw new LogicException('SUPPORT_ROLE_CONSUMPTION_SCOPE_WIDENED');
                }
            }
        }
    }

    private function metrics(array $response): array
    {
        $source = (array) data_get($response, 'specialist_council_receipt.metrics', $response['metrics'] ?? $response);
        $values = ['net_profit' => $source['net_profit'] ?? null, 'total_costs' => $source['total_costs'] ?? null,
            'trades' => $source['total_trades'] ?? $source['trades'] ?? $response['total_trades'] ?? null,
            'matured_trades' => $source['matured_trades'] ?? null, 'censored_trades' => $source['censored_trades'] ?? null];
        foreach (['max_drawdown_percent', 'max_daily_loss_percent', 'max_gross_exposure_percent', 'max_total_risk_percent'] as $key) $values[$key] = $source[$key] ?? null;
        foreach ($values as $key => $value) {
            if (! is_numeric($value) || ! is_finite((float) $value) || ($key !== 'net_profit' && $value < 0)) throw new LogicException('ORIGINAL_COMPARISON_METRICS_INCOMPLETE');
            $values[$key] = (float) $value;
        }
        return $values;
    }

    private function findArm(array $arms, string $kind, ?string $removedId = null): ?array
    {
        $matches = array_values(array_filter($arms, fn (array $arm): bool => $arm['kind'] === $kind && ($removedId === null || ($arm['removed_id'] ?? null) === $removedId)));
        return count($matches) === 1 ? $matches[0] : null;
    }

    private function originalArtifact(LabEvaluationRun $run, string $type): array
    {
        if (LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', $type)->count() !== 1) {
            throw new LogicException('ORIGINAL_FILE_BACKED_EVIDENCE_ARTIFACT_AMBIGUOUS');
        }
        $artifact = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', $type)->oldest('id')->first();
        if (! $artifact || ! $artifact->storage_path || data_get($artifact->metadata, 'storage_protocol') !== 'compressed_artifact_v2'
            || ! $artifact->created_at || $artifact->created_at->greaterThan($run->finished_at)) throw new LogicException('ORIGINAL_FILE_BACKED_EVIDENCE_ARTIFACT_MISSING');
        $payload = $this->evidence->readArtifactPayload($artifact);
        if (! is_array($payload) || ($type === 'evaluation_response' && $artifact->sha256 !== $run->response_hash)
            || ($type === 'evaluation_request' && data_get($artifact->metadata, 'request_hash') !== $run->request_hash)) {
            throw new LogicException('ORIGINAL_EVIDENCE_HASH_INVALID');
        }
        return $payload;
    }

    /** Historical evidence belongs to its archived source, not today's source or a live lease. */
    public function assertArchivedOriginalRelease(LabEvaluationRun $run, array $request, array $response, array $plan): array
    {
        $seal = $request['research_release'] ?? null;
        $identity = is_array($seal) ? array_diff_key($seal, array_flip(['release_hash', 'sealed_at', 'promotion_evidence'])) : [];
        if ($run->status !== 'completed' || ! is_array($seal) || ! is_array($seal['source_artifact'] ?? null)
            || ($seal['protocol'] ?? null) !== ResearchReleaseSealService::PROTOCOL
            || ($seal['release_hash'] ?? null) !== app(ExecutionContractService::class)->hashParameters($identity)
            || ($seal['source_hash'] ?? null) !== $run->code_hash || ($seal['dataset_hash'] ?? null) !== $run->data_hash
            || (isset($plan['preparation_source_hash']) && $plan['preparation_source_hash'] !== $run->code_hash)
            || ! app(ResearchReleaseSealService::class)->responseValid($seal, (array) data_get($response, 'data_quality.research_release_receipt', []))) {
            throw new LogicException('COUNCIL_ORIGINAL_ARCHIVED_WORKER_RELEASE_REQUIRED');
        }
        $proof = app(ResearchReleaseSealService::class)->verifySourceArtifact($seal['source_artifact'], false);
        if (data_get($proof, 'manifest.source_identity.source_hash') !== $run->code_hash
            || data_get($proof, 'manifest.source_identity.python_source_hash') !== ($seal['python_source_hash'] ?? null)) {
            throw new LogicException('COUNCIL_ORIGINAL_ARCHIVED_SOURCE_IDENTITY_MISMATCH');
        }
        return $proof;
    }

    /** Original issuer decision + archived registry witness + actual consumed bytes, never today's registry. */
    private function authorizedOriginalPlanWindow(array $window, LabEvaluationRun $run, array $request, array $response,
        array $plan, array $archive): bool
    {
        $signed = data_get($request, 'policy_context.authorized_research_transport');
        $key = (string) config('services.internal_api.token');
        $settings = (array) data_get($archive, 'manifest.effective_non_secret_settings', []);
        $registryDigest = $settings['services.instrument_policy.authorized_research_windows_sha256'] ?? null;
        if (! is_array($signed) || strlen($key) < 32 || ! is_string($registryDigest)
            || ! preg_match('/^[a-f0-9]{64}$/D', $registryDigest) || $registryDigest === hash('sha256', '[]')) {
            throw new LogicException('COUNCIL_ORIGINAL_SIGNED_WINDOW_AND_REGISTRY_WITNESS_REQUIRED');
        }
        $identity = array_diff_key($signed, array_flip(['contract_hash', 'hmac_sha256']));
        $ordered = function (mixed $value) use (&$ordered): mixed {
            if (! is_array($value)) return $value;
            if (! array_is_list($value)) ksort($value, SORT_STRING);
            return array_map($ordered, $value);
        };
        $json = json_encode($ordered($identity), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (! is_string($signed['contract_hash'] ?? null) || ! is_string($signed['hmac_sha256'] ?? null)
            || ! hash_equals(hash('sha256', $json), $signed['contract_hash'])
            || ! hash_equals(hash_hmac('sha256', InstrumentResearchWindowService::TRANSPORT_PROTOCOL."\n".$json, $key), $signed['hmac_sha256'])) {
            throw new LogicException('COUNCIL_ORIGINAL_WINDOW_ISSUER_SIGNATURE_INVALID');
        }
        $originalWindow = array_diff_key($window, ['evaluation_scope' => true]);
        $fields = ['protocol', 'authorization_id', 'research_epoch_id', 'start_inclusive', 'end_exclusive', 'dataset_sha256'];
        $windowIdentity = [];
        foreach ($fields as $field) $windowIdentity[$field] = $originalWindow[$field] ?? null;
        if (($identity['protocol'] ?? null) !== InstrumentResearchWindowService::TRANSPORT_PROTOCOL
            || ($identity['purpose'] ?? null) !== 'server_authorized_research_execution'
            || ($identity['independent_evidence'] ?? null) !== false || ($identity['promotion_evidence'] ?? null) !== false
            || ($identity['generation_id'] ?? null) !== (int) $run->lab_generation_id
            || ($identity['release_hash'] ?? null) !== data_get($request, 'research_release.release_hash')
            || ($identity['dataset_hash'] ?? null) !== $run->data_hash || ($identity['symbol'] ?? null) !== ($request['symbol'] ?? null)
            || ($identity['timeframe'] ?? null) !== $plan['execution_timeframe'] || ($identity['evaluation_mode'] ?? null) !== 'full'
            || ($request['evaluation_mode'] ?? null) !== 'full' || count($originalWindow) !== 7
            || ($originalWindow['protocol'] ?? null) !== InstrumentResearchWindowService::PROTOCOL
            || ($originalWindow['dataset_sha256'] ?? null) !== $run->data_hash
            || ($originalWindow['window_key'] ?? null) !== hash('sha256', json_encode($windowIdentity, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            || ! $this->evidence->equivalentJsonValue($originalWindow, $identity['window'] ?? null)) {
            throw new LogicException('COUNCIL_ORIGINAL_WINDOW_RELEASE_IDENTITY_MISMATCH');
        }
        $start = $this->time($originalWindow['start_inclusive']); $end = $this->time($originalWindow['end_exclusive']);
        if ($start->lessThan('2027-01-01T00:00:00Z') || ! $end->greaterThan($start) || ! $run->started_at || $end->greaterThan($run->started_at)) {
            throw new LogicException('COUNCIL_ORIGINAL_WINDOW_EPOCH_INVALID');
        }
        $papers = $identity['paper_exclusions'] ?? null; $legacy = false;
        if (! is_array($papers) || ! array_is_list($papers) || count($papers) < 1 || count($papers) > 64) throw new LogicException('COUNCIL_ORIGINAL_PAPER_EXCLUSIONS_REQUIRED');
        foreach ($papers as $paper) {
            if (! is_array($paper) || count($paper) !== 2) throw new LogicException('COUNCIL_ORIGINAL_PAPER_EXCLUSIONS_REQUIRED');
            $from = $this->time($paper['start_inclusive'] ?? null); $until = $this->time($paper['end_exclusive'] ?? null);
            if (! $until->greaterThan($from) || ($start->lessThan($until) && $end->greaterThan($from))) throw new LogicException('COUNCIL_ORIGINAL_PAPER_OVERLAP');
            $legacy = $legacy || ($from->equalTo('2026-01-01T00:00:00Z') && $until->equalTo('2027-01-01T00:00:00Z'));
        }
        if (! $legacy) throw new LogicException('COUNCIL_ORIGINAL_IMMUTABLE_2026_EXCLUSION_REQUIRED');
        $binding = $request['specialist_council_evaluation'] ?? [];
        $arm = $plan['arms'][$binding['arm_key'] ?? ''] ?? [];
        $owner = $identity['original_council_arm'] ?? [];
        foreach (['protocol' => 'authorized_original_council_arm_v1', 'purpose' => 'independent',
            'generation_id' => (int) $run->lab_generation_id, 'work_item_id' => $plan['panel_work_item_id'] ?? null,
            'reservation_hash' => $plan['panel_reservation_hash'] ?? null, 'version_id' => $plan['version_id'],
            'manifest_hash' => $plan['manifest_hash'], 'plan_hash' => $binding['plan_hash'] ?? null,
            'arm_key' => $binding['arm_key'] ?? null, 'kind' => $arm['kind'] ?? null, 'window_key' => $originalWindow['window_key'],
            'model_version_id' => (int) $run->model_version_id, 'model_hash' => $arm['model_hash'] ?? null,
            'independent_evidence' => false, 'promotion_evidence' => false] as $field => $value) {
            if ($value === null || ($owner[$field] ?? null) !== $value) throw new LogicException('COUNCIL_ORIGINAL_SIGNED_PLAN_BINDING_INVALID');
        }
        $scope = json_decode($owner['evaluation_scope_json'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        if (! $this->evidence->equivalentJsonValue($scope, $arm['evaluation_scope'] ?? null)) throw new LogicException('COUNCIL_ORIGINAL_SIGNED_SCOPE_INVALID');
        $strategies = array_values((array) ($request['strategies'] ?? []));
        $program = json_decode($owner['strategy_payload_json'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        $runtimePolicy = json_decode($owner['runtime_policy_json'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        $shared = json_decode($owner['shared_runtime_json'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        if (count($strategies) !== 1 || ($strategies[0]['lab_agent_id'] ?? null) !== (int) $run->lab_agent_id
            || ! $this->evidence->equivalentJsonValue($program, $strategies[0])
            || ! $this->evidence->equivalentJsonValue($runtimePolicy, $plan['full_replay_runtime_policy'] ?? null)
            || ! $this->evidence->equivalentJsonValue($shared, [
                'initial_balance' => $request['initial_balance'] ?? null, 'risk_per_trade' => $request['risk_per_trade'] ?? null,
                'execution' => $request['execution'] ?? null, 'execution_contract' => $request['execution_contract'] ?? null,
                'volume_context' => (array) ($request['volume_context'] ?? []), 'emit_decision_trace' => $request['emit_decision_trace'] ?? null])) {
            throw new LogicException('COUNCIL_ORIGINAL_SIGNED_RUNTIME_INVALID');
        }
        $files = $identity['files'] ?? []; $records = data_get($request, 'mtf_snapshot_manifest.streams', []);
        $names = array_keys($files); $expectedNames = array_keys($records); sort($names); sort($expectedNames);
        if ($names !== $expectedNames || $names !== ['H1', 'H4', 'M15', 'M5']) throw new LogicException('COUNCIL_ORIGINAL_SIGNED_FILES_INVALID');
        $contexts = (array) data_get($response, 'data_quality.context_source_attestations', []);
        $nativeReceipt = $response['specialist_council_receipt'] ?? data_get($response, 'data_quality.specialist_council_receipt');
        if (array_key_exists('specialist_council_contract', $request) || (is_array($nativeReceipt) && $nativeReceipt !== [])) {
            $model = ModelVersion::find($run->model_version_id);
            if (! $model) throw new LogicException('COUNCIL_ORIGINAL_NATIVE_MODEL_REQUIRED');
            // Native contexts belong to actual compiler dispatches, not the
            // ordinary top-level quality projection. Authenticate the original
            // contract, every member, account and producer seal before reading.
            $nativeReceipt = $this->attestReplayResult($model, $request, $response);
            if (! is_array($nativeReceipt) || ($nativeReceipt['status'] ?? null) !== 'computed') {
                throw new LogicException('COUNCIL_ORIGINAL_NATIVE_COMPUTED_RECEIPT_REQUIRED');
            }
            $contexts = [];
            foreach ($files as $stream => $file) {
                if ($stream !== $plan['execution_timeframe']) $contexts[$stream] = $this->originalNativeContextWitness($nativeReceipt, $stream, $file);
            }
        }
        foreach ($files as $stream => $file) {
            $record = $records[$stream];
            $proof = $stream === $plan['execution_timeframe'] ? (array) data_get($response, 'data_quality.dataset_attestation', []) : ($contexts[$stream] ?? []);
            if (($file['sha256'] ?? null) !== ($record['sha256'] ?? null)
                || str_replace('\\', '/', (string) ($file['path'] ?? '')) !== str_replace('\\', '/', (string) ($record['path'] ?? ''))
                || ! is_int($file['rows'] ?? null) || $file['rows'] < 2 || ($file['rows'] ?? null) !== ($record['rows'] ?? null)
                || ! $this->time($file['start_inclusive'] ?? null)->equalTo($this->time($record['first_candle_at'] ?? null))
                || ! $this->time($file['last_candle_at'] ?? null)->equalTo($this->time($record['last_candle_at'] ?? null))
                || ($proof['status'] ?? null) !== 'verified' || ($proof['actual_source_sha256'] ?? null) !== $file['sha256']
                || ($proof['consumed_rows'] ?? null) !== $file['rows'] || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($proof['consumed_data_hash'] ?? ''))) {
                throw new LogicException('COUNCIL_ORIGINAL_ATTESTED_FILE_CONSUMPTION_MISMATCH:'.$stream);
            }
        }
        return true;
    }

    /** Called only after authenticating the complete original native receipt. */
    private function originalNativeContextWitness(array $receipt, string $stream, array $file): array
    {
        $members = $receipt['members'] ?? null; $first = null;
        if (! is_array($members) || $members === []) throw new LogicException('COUNCIL_ORIGINAL_ATTESTED_FILE_CONSUMPTION_MISMATCH:'.$stream);
        foreach ($members as $member) {
            $proof = data_get($member, 'context_source_attestations.'.$stream);
            if (! is_array($proof) || ($proof['stream'] ?? null) !== $stream || ($proof['status'] ?? null) !== 'verified'
                || ($proof['actual_source_sha256'] ?? null) !== ($file['sha256'] ?? null)
                || ! is_int($proof['consumed_rows'] ?? null) || $proof['consumed_rows'] !== ($file['rows'] ?? null)
                || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($proof['consumed_data_hash'] ?? ''))
                || ! $this->time($proof['first_candle_utc'] ?? null)->equalTo($this->time($file['start_inclusive'] ?? null))
                || ! $this->time($proof['last_candle_utc'] ?? null)->equalTo($this->time($file['last_candle_at'] ?? null))
                || ($first !== null && $proof['consumed_data_hash'] !== $first['consumed_data_hash'])) {
                throw new LogicException('COUNCIL_ORIGINAL_ATTESTED_FILE_CONSUMPTION_MISMATCH:'.$stream);
            }
            $first ??= $proof;
        }
        return $first;
    }

    private function paperAuthority(SpecialistCouncilVersion $version, bool $champion): array
    {
        $sources = [];
        foreach ($version->manifest['members'] as $member) {
            if (! in_array($member['role'], SpecialistCouncilContractService::TRADING_ROLES, true)) continue;
            $model = ModelVersion::find($member['model_version_id']);
            if (! $model || $this->contracts->modelHash($model) !== $member['source_model_hash']) return $this->blocked('COUNCIL_MEMBER_NATIVE_MODEL_DRIFT');
            foreach ($member['scope']['symbols'] as $symbol) {
                $timeframe = $member['paper_timeframe'] ?? 'H1';
                $native = app(PaperAuthorityAdmissionService::class)->verifyFrozenCandidate($model, $symbol, $timeframe);
                if (! ($native['allowed'] ?? false) || ($champion && ! app(PaperAuthorityAdmissionService::class)->championEligible($model, $symbol, $timeframe))) {
                    return $this->blocked('COUNCIL_NATIVE_E3_E4_PAPER_AUTHORITY_MISSING');
                }
                $sources[] = ['specialist_id' => $member['specialist_id'], 'model_version_id' => $model->id,
                    'symbol' => $symbol, 'timeframe' => $timeframe, 'identity_hash' => $native['identity_hash'] ?? null];
            }
        }
        if ($sources === []) return $this->blocked('NO_PAPER_QUALIFIED_TRADING_SPECIALIST');
        return ['allowed' => true, 'sources' => $sources, 'native_paper_authority_required' => true, 'promotion_evidence' => false];
    }

    private function verified(SpecialistCouncilVersion $version): SpecialistCouncilVersion
    {
        $current = $version->fresh() ?? $version;
        if (! $this->contracts->manifestValid((array) $current->manifest)
            || ($current->manifest['manifest_hash'] ?? '') !== $current->manifest_hash
            || ($current->manifest['council_id'] ?? '') !== $current->council_id
            || (string) ($current->manifest['version'] ?? '') !== $current->version) throw new LogicException('COUNCIL_SEALED_MANIFEST_DRIFT');
        return $current;
    }

    private function plan(SpecialistCouncilVersion $version): array
    {
        $row = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $version->id)->first();
        $plan = $row ? json_decode($row->plan, true) : null;
        if (! is_array($plan) || ! hash_equals($row->plan_hash, $this->epochs->parameterHash($plan))
            || ($plan['manifest_hash'] ?? '') !== $version->manifest_hash) throw new LogicException('ORIGINAL_PREREGISTERED_EVALUATION_PLAN_MISSING');
        return ['plan' => $plan, 'hash' => $row->plan_hash, 'evaluator_id' => $row->evaluator_id,
            'sealed_at' => CarbonImmutable::parse($row->sealed_at, 'UTC')];
    }

    /** Evaluation scope is an evaluator suffix, never a replacement/rename of the original seven-field authority receipt. */
    private function authorizedPlanWindow(array $window, string $hash): bool
    {
        return app(InstrumentResearchWindowService::class)->authorized(array_diff_key($window, ['evaluation_scope' => true]), $hash);
    }

    private function accountPolicyKeys(): array
    {
        return ['broker_position_mode', 'opposite_position_policy', 'max_open_positions', 'max_reserved_capital_percent',
            'max_gross_exposure_percent', 'max_total_risk_percent', 'max_drawdown_percent', 'max_daily_loss_percent', 'max_expected_cost_percent'];
    }

    private function normalizeEvaluationScope(array $scope): array
    {
        $start = $this->time($scope['start_inclusive'] ?? null); $end = $this->time($scope['end_exclusive'] ?? null);
        foreach (['rows', 'decision_rows', 'warmup_rows'] as $key) {
            if (! is_int($scope[$key] ?? null) || $scope[$key] < 0 || $scope[$key] > 2000000) {
                throw new LogicException('EVALUATED_SCOPE_REQUIRES_EXACT_BOUNDED_PRODUCER_ROW_COUNTS');
            }
        }
        if (! $end->greaterThan($start) || $scope['rows'] < 2 || $scope['decision_rows'] !== $scope['rows'] - 1
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($scope['policy_hash'] ?? ''))) {
            throw new LogicException('EVALUATED_SCOPE_CHRONOLOGY_OR_NEXT_OPEN_CLOCK_INVALID');
        }
        return ['start_inclusive' => $start->toIso8601String(), 'end_exclusive' => $end->toIso8601String(),
            'rows' => $scope['rows'], 'decision_rows' => $scope['decision_rows'], 'warmup_rows' => $scope['warmup_rows'],
            'policy_hash' => $scope['policy_hash']];
    }

    private function sameEvaluationScope(array $left, array $right): bool
    {
        return $this->normalizeEvaluationScope($left) === $this->normalizeEvaluationScope($right);
    }

    private function scopeFromProbe(array $probe, string $timeframe): array
    {
        return $this->normalizeEvaluationScope(['start_inclusive' => $probe['evaluated_start'] ?? null,
            'end_exclusive' => $this->time($probe['evaluated_end'] ?? null)->addSeconds($this->contracts->timeframeSeconds($timeframe))->toIso8601String(),
            'rows' => $probe['evaluated_rows'] ?? null, 'decision_rows' => isset($probe['evaluated_rows']) ? $probe['evaluated_rows'] - 1 : null,
            'warmup_rows' => $probe['warmup_rows'] ?? null, 'policy_hash' => $this->epochs->parameterHash(array_diff_key($probe, ['complete' => true]))]);
    }

    /** Bounds come only from the original producer, never inferred from a shared file hash. */
    public function assertOriginalArmScope(array $arm, array $request, array $response, string $timeframe): void
    {
        if (! is_array($arm['evaluation_scope'] ?? null)) throw new LogicException('PLAN_EVALUATED_SCOPE_NOT_PREREGISTERED');
        $native = (array) ($response['specialist_council_receipt'] ?? []);
        if ($native !== []) {
            $this->assertReceiptSeal($native);
            if (($native['status'] ?? null) !== 'computed') throw new LogicException('ORIGINAL_ARM_NATIVE_EXECUTION_DEPENDENCY');
            $actual = $native['evaluated_scope'] ?? null;
        } else {
            $actual = data_get($response, 'data_quality.replay_evaluation_scope');
            $probe = data_get($request, 'policy_context.prospective_probe_window');
            if ($actual === null && is_array($probe)) {
                $receipt = $response['prospective_probe_window_receipt'] ?? data_get($response, 'data_quality.prospective_probe_window_receipt');
                if (! is_array($receipt) || ! app(ProspectiveRepairProbeWindowService::class)->attests($probe, $receipt)) {
                    throw new LogicException('LEGACY_COMPARATOR_ORIGINAL_PROBE_RECEIPT_MISSING');
                }
                $actual = $this->scopeFromProbe($probe, $timeframe);
            }
        }
        if (! is_array($actual)) throw new LogicException('ORIGINAL_COMPARATOR_EVALUATED_SCOPE_RECEIPT_MISSING');
        if (! $this->sameEvaluationScope($arm['evaluation_scope'], $actual)) {
            throw new LogicException('ORIGINAL_PAIRED_ARM_CALENDAR_OR_ROW_BUDGET_MISMATCH');
        }
    }

    private function requestForRun(LabEvaluationRun $run, array $request): array
    {
        if (isset($request['specialist_council_evaluation'])) return $request;
        $strategies = array_values(array_filter((array) ($request['strategies'] ?? []), fn ($strategy): bool => is_array($strategy)
            && (int) ($strategy['lab_agent_id'] ?? 0) === (int) $run->lab_agent_id));
        if (count($strategies) === 1) return [...$request, ...$strategies[0]];
        return $request;
    }

    private function independentActor(SpecialistCouncilVersion $version, string $actor): void
    {
        $this->actor($actor);
        if ($actor === $version->creator_id || in_array($actor, array_column($version->manifest['members'], 'specialist_id'), true)) {
            throw new LogicException('CREATOR_OR_MEMBER_CANNOT_SELF_CERTIFY');
        }
    }

    private function actor(string $actor): void
    {
        if (! preg_match('/^[A-Za-z0-9_.:-]{1,150}$/', $actor)) throw new InvalidArgumentException('Invalid attributable actor identity.');
    }

    private function time(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value)) throw new InvalidArgumentException('Explicit UTC-offset timestamp required.');
        return CarbonImmutable::parse($value)->utc();
    }

    private function blocked(string $reason): array
    {
        return ['allowed' => false, 'reason_code' => $reason, 'promotion_evidence' => false];
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function number(mixed $value): float
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) throw new LogicException('COUNCIL_ACCOUNT_VALUE_MISSING_OR_NONFINITE');
        return (float) $value;
    }
}
