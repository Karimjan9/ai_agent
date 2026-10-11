<?php

namespace App\Services;

use App\Jobs\EvaluateLabAgentJob;
use App\Jobs\ExecuteScopedResearchArmJob;
use App\Models\AgentLearningCausalExperiment;
use App\Models\DescendantValueTrial;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabSkillZooEntry;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentWorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use Throwable;

/** One original four-arm question, delivered by the existing arbiter work owner. */
class DescendantScopedExecutionService
{
    public const PROTOCOL = 'scoped_descendant_native_execution_v1';

    public const WORK_TYPE = 'scoped_descendant_four_arm';

    public const PURPOSE = 'independent_scoped_descendant_research';

    public const COMPONENT_WORK_TYPE = 'scoped_component_two_arm';

    public const COMPONENT_PURPOSE = 'independent_scoped_component_research';

    public const WORK_TYPES = [self::WORK_TYPE, self::COMPONENT_WORK_TYPE];

    public const WORK_LEASE_SECONDS = 2700;

    public const MAX_DELIVERIES = 47;

    public function __construct(
        private DescendantScopedProofService $proofs,
        private ScopedResearchCertificateService $certificates,
        private InstrumentResearchWindowService $windows,
        private LabImmutableEvidenceService $evidence,
        private ResearchExperimentConversionKernelService $conversion,
        private ResearchReleaseSealService $releases,
    ) {}

    /** Called before the original trial/certificate seal, never after outcomes. */
    public function validateProspectiveExecution(array $input, array $design, LabSkillZooEntry $cartridge): array
    {
        return $this->validateExecutionInput($input, $design, $cartridge->symbol, $cartridge);
    }

    /** Returns a prospectively augmented component design before registry sealing. */
    public function prepareComponentExecution(array $design, AgentLearningCausalExperiment $source): array
    {
        $design['statistical_guard'] = $this->prospectiveStatisticalGuard((array) ($design['statistical_guard'] ?? []),
            app(ResearchPaperEpochContractService::class)->parameterHash(['source_id' => (int) $source->id,
                'subject' => $design['subject'], 'validation_windows' => $design['validation_windows']]));
        $role = data_get($design, 'subject.candidate_role');
        $candidateId = match ($role) {
            'guided' => $source->guided_agent_id, 'blinded' => $source->blinded_agent_id,
            default => throw new LogicException('SCOPED_COMPONENT_ORIGINAL_CANDIDATE_ROLE_REQUIRED')
        };
        $agents = ['candidate' => LabAgent::with('modelVersion')->findOrFail($candidateId),
            'control' => LabAgent::with('modelVersion')->findOrFail($source->control_agent_id)];
        if (! app(ExactCausalBaselineService::class)->matches($agents['candidate'], $agents['control'])) {
            throw new LogicException('SCOPED_COMPONENT_EXACT_ORIGINAL_BASELINE_REQUIRED');
        }
        $kernel = app(CompositionAuthorityKernelService::class);
        $traitGene = (string) array_key_first((array) $agents['candidate']->parameter_diff);
        if (! $kernel->prospectiveParameterInterventionAllowed($kernel->prospectiveRecipeFromMetadata($agents['control']->modelVersion), $traitGene)) {
            throw new LogicException('SCOPED_COMPONENT_ORIGINAL_TRAIT_OVERRIDDEN_BY_SOURCE_MANAGEMENT');
        }
        $programmes = array_map(fn ($agent): string => app(CompositionAuthorityKernelService::class)
            ->prospectiveProgrammeHash($agent->modelVersion), $agents);
        if (count(array_unique($programmes)) !== 1) {
            throw new LogicException('SCOPED_COMPONENT_EXACT_INTRINSIC_PROGRAMME_REQUIRED');
        }
        $design['subject']['arm_models'] = [];
        $design['subject']['arm_parameters'] = [];
        foreach ($agents as $arm => $agent) {
            $model = $agent->modelVersion;
            $design['subject']['arm_models'][$arm] = ['model_version_id' => (int) $model->id,
                'parameter_hash' => app(ResearchPaperEpochContractService::class)->parameterHash((array) $model->parameters),
                'runtime_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($this->evidence->modelRuntimeBasis($model)),
                'source_agent_id' => (int) $agent->id];
            $design['subject']['arm_parameters'][$arm] = (array) $model->parameters;
        }
        if ($agents['candidate']->model_version_id === $agents['control']->model_version_id) {
            throw new LogicException('SCOPED_COMPONENT_TWO_DISTINCT_ORIGINAL_MODELS_REQUIRED');
        }
        $input = (array) ($design['native_execution'] ?? []);
        $prepared = ($input['protocol'] ?? null) === self::PROTOCOL;
        $raw = $prepared ? Arr::only($input, ['authorization_ids', 'execution_timeframe',
            'initial_capital', 'risk_policy', 'full_replay_runtime_policy']) : $input;
        $native = $this->validateExecutionInput($raw, $design, $source->symbol);
        if ($prepared && app(ResearchPaperEpochContractService::class)->parameterHash($native)
            !== app(ResearchPaperEpochContractService::class)->parameterHash($input)) {
            throw new LogicException('SCOPED_COMPONENT_ORIGINAL_PREPARED_NATIVE_DESIGN_DRIFT');
        }
        $design['native_execution'] = $native;

        return $design;
    }

    public function prospectiveStatisticalGuard(array $guard, string $seedIdentity): array
    {
        if ($guard === []) {
            $guard = ['method' => 'paired_window_bootstrap_percentile', 'replicates' => 1000,
                'seed' => (int) hexdec(substr(hash('sha256', 'prospective-scoped-bootstrap|'.$seedIdentity), 0, 8)),
                'lower_quantile' => 0.05];
        }
        if (($guard['method'] ?? null) !== 'paired_window_bootstrap_percentile'
            || ! is_int($guard['replicates'] ?? null) || $guard['replicates'] < 500 || $guard['replicates'] > 5000
            || ! is_int($guard['seed'] ?? null) || ($guard['lower_quantile'] ?? null) !== 0.05) {
            throw new LogicException('SCOPED_PROSPECTIVE_PAIRED_STATISTICAL_GUARD_REQUIRED');
        }

        return $guard;
    }

    private function validateExecutionInput(array $input, array $design, string $symbol, ?LabSkillZooEntry $cartridge = null): array
    {
        if (array_diff(array_keys($input), ['source_component_certificate_id', 'authorization_ids',
            'execution_timeframe', 'initial_capital', 'risk_policy', 'full_replay_runtime_policy']) !== []
            || ($cartridge && ! is_int($input['source_component_certificate_id'] ?? null))
            || (! $cartridge && array_key_exists('source_component_certificate_id', $input))
            || ! is_array($input['authorization_ids'] ?? null) || ! array_is_list($input['authorization_ids'])
            || ($input['execution_timeframe'] ?? null) !== 'M5'
            || ! is_numeric($input['initial_capital'] ?? null) || ! is_finite((float) $input['initial_capital']) || $input['initial_capital'] <= 0
            || ! is_array($input['risk_policy'] ?? null) || ! is_numeric($input['risk_policy']['risk_per_trade_percent'] ?? null)
            || array_keys($input['risk_policy']) !== ['risk_per_trade_percent'] || ! is_finite((float) $input['risk_policy']['risk_per_trade_percent'])
            || $input['risk_policy']['risk_per_trade_percent'] <= 0 || $input['risk_policy']['risk_per_trade_percent'] > 2) {
            throw new LogicException('DESCENDANT_PROSPECTIVE_NATIVE_EXECUTION_CONTRACT_REQUIRED');
        }
        $planned = $design['validation_windows'] ?? [];
        $minimumWindows = max(6, (int) config('services.learning_lane.causal_minimum_powered_windows', 6));
        if (! is_array($planned) || ! array_is_list($planned) || count($planned) < $minimumWindows || count($planned) > 9
            || count($input['authorization_ids']) !== count($planned)
            || count(array_unique($input['authorization_ids'], SORT_REGULAR)) !== count($planned)
            || count(array_filter($input['authorization_ids'], fn ($id): bool => is_string($id) && $id !== '')) !== count($planned)) {
            throw new LogicException('DESCENDANT_SIX_OR_CONFIGURED_ORIGINAL_WINDOWS_REQUIRED');
        }
        $previousEnd = null;
        foreach ($planned as $window) {
            if (! is_array($window) || array_diff(array_keys($window), ['start_inclusive', 'end_exclusive']) !== []
                || ! is_string($window['start_inclusive'] ?? null) || ! is_string($window['end_exclusive'] ?? null)) {
                throw new LogicException('DESCENDANT_PREREGISTERED_WINDOW_CHRONOLOGY_REQUIRED');
            }
            $start = CarbonImmutable::parse($window['start_inclusive'])->utc();
            $end = CarbonImmutable::parse($window['end_exclusive'])->utc();
            if ($start->lt(CarbonImmutable::parse($design['validation_start']))
                || $end->gt(CarbonImmutable::parse($design['validation_end'])) || ! $end->gt($start)
                || ($previousEnd && $start->lt($previousEnd))) {
                throw new LogicException('DESCENDANT_ORIGINAL_WINDOWS_OVERLAP_OR_SCOPE_DRIFT');
            }
            $previousEnd = $end;
        }
        if (($design['exposure_policy']['protocol'] ?? null) !== 'prospective_scoped_exposure_policy_v1') {
            throw new LogicException('DESCENDANT_PROSPECTIVE_ORIGINAL_EXPOSURE_POLICY_REQUIRED');
        }
        $policy = $input['full_replay_runtime_policy'] ?? [];
        // This is the declared existing full producer budget, not a faster
        // descendant-only evaluation or a hidden tail/row selection.
        if (! is_array($policy) || array_diff(array_keys($policy), ['protocol', 'evaluation_mode', 'selection',
            'maximum_source_rows', 'maximum_runtime_seconds', 'warmup_rows', 'no_walk_forward_selection', 'promotion_evidence']) !== []
            || ($policy['protocol'] ?? null) !== 'scoped_original_full_source_v1'
            || ($policy['warmup_rows'] ?? null) !== 0 || ! is_int($policy['maximum_runtime_seconds'] ?? null)
            || $policy['maximum_runtime_seconds'] < 30 || $policy['maximum_runtime_seconds'] > 1620
            || ! is_int($policy['maximum_source_rows'] ?? null) || $policy['maximum_source_rows'] < 201
            || $policy['maximum_source_rows'] > 200000
            || ($policy['selection'] ?? null) !== 'entire_authorized_source'
            || ($policy['evaluation_mode'] ?? null) !== 'full'
            || ($policy['no_walk_forward_selection'] ?? null) !== true
            || ($policy['promotion_evidence'] ?? null) !== false) {
            throw new LogicException('DESCENDANT_BOUNDED_FULL_ORIGINAL_RUNTIME_POLICY_REQUIRED');
        }
        if ($cartridge) {
            $this->assertSourceComponent($input['source_component_certificate_id'], $design, $cartridge);
        }
        $execution = app(ExecutionContractService::class)->for($symbol, 'M5');
        if (($execution['execution_hash'] ?? null) !== $design['execution_hash']) {
            throw new LogicException('DESCENDANT_PROSPECTIVE_NATIVE_COST_POLICY_MISMATCH');
        }
        $arms = $cartridge ? 4 : 2;

        return [...$input, 'protocol' => self::PROTOCOL, 'purpose' => $cartridge ? self::PURPOSE : self::COMPONENT_PURPOSE,
            'cost_model' => $execution['parameters'], 'max_original_arms' => $arms,
            'max_windows' => count($planned), 'max_deliveries' => count($planned) * ($arms + 1) + 2,
            'max_preparation_windows_per_delivery' => 1,
            'one_original_arm_per_delivery' => true,
            'executor_source_hash' => hash_file('sha256', __FILE__),
            'python_source_hash' => $this->releases->pythonHash(),
            'research_only' => true, 'promotion_evidence' => false];
    }

    /** Register durable dependency work; registration does not execute or grant authority. */
    public function registerWork(int $trialId, int $certificateId): array
    {
        $proof = $this->proofs->inspect($trialId, $certificateId);
        if (($proof['status'] ?? null) !== 'scoped_proof_inspected') {
            return $proof;
        }
        $design = $proof['certificate']['design'];
        if (($design['native_execution']['protocol'] ?? null) !== self::PROTOCOL) {
            return $this->blocked('DESCENDANT_PROSPECTIVE_NATIVE_EXECUTION_NOT_DECLARED');
        }

        return $this->recordNativeWork($certificateId, $design, $proof['certificate']['design_hash'],
            DescendantValueTrial::findOrFail($trialId), self::WORK_TYPE, self::PURPOSE, $trialId);
    }

    public function registerComponentWork(int $certificateId): array
    {
        $registration = $this->certificates->verifiedRegistration($certificateId);
        $design = $registration['design'];
        if ($registration['scope'] !== 'component' || data_get($design, 'native_execution.protocol') !== self::PROTOCOL
            || data_get($design, 'native_execution.purpose') !== self::COMPONENT_PURPOSE
            || array_keys((array) data_get($design, 'subject.arm_models')) !== ['candidate', 'control']) {
            return $this->blocked('SCOPED_COMPONENT_PROSPECTIVE_NATIVE_EXECUTION_REQUIRED');
        }

        return $this->recordNativeWork($certificateId, $design, $registration['design_hash'],
            AgentLearningCausalExperiment::findOrFail($registration['source_id']), self::COMPONENT_WORK_TYPE, self::COMPONENT_PURPOSE);
    }

    private function recordNativeWork(int $certificateId, array $design, string $identity,
        Model $source, string $type, string $purpose, ?int $trialId = null): array
    {
        $contract = ['contract_version' => ResearchExperimentConversionKernelService::CONTRACT_VERSION,
            'source' => ['type' => $source::class, 'id' => (int) $source->id],
            'scope' => ['symbol' => $source->symbol, 'laboratory_timeframe' => $source->timeframe,
                'execution_timeframe' => 'M5'],
            'identity' => ['baseline_epoch_hash' => $identity, 'data_and_mtf_hash' => $identity,
                'runtime_and_contract_hash' => $design['execution_hash'], 'intervention_hash' => $identity,
                'window_plan_hash' => $identity, 'evaluator_version' => $design['evaluator_hash']],
            'arms' => array_map(fn (string $arm): array => ['role' => $arm], array_keys($design['subject']['arm_models']))];

        return $this->conversion->record($contract, ['certificate_id' => $certificateId, 'prospective_design_hash' => $identity],
            'INCONCLUSIVE', ['type' => $type, 'identity' => $identity,
                'trial_id' => $trialId, 'certificate_id' => $certificateId,
                'purpose' => $purpose, 'executable' => false,
                'retry_condition' => ['code' => 'DESCENDANT_ACTUAL_AUTHORIZED_ORIGINAL_WINDOW_REQUIRED',
                    'max_experiments' => 1, 'same_evidence_replay_forbidden' => true],
                'promotion_evidence' => false]);
    }

    /** Readiness comes from original owners, never an executable payload boolean. */
    public function inspectWork(ResearchExperimentWorkItem $work): array
    {
        try {
            $component = $work->work_type === self::COMPONENT_WORK_TYPE;
            $purpose = $component ? self::COMPONENT_PURPOSE : self::PURPOSE;
            if (! in_array($work->work_type, self::WORK_TYPES, true)
                || data_get($work->payload, 'owner') !== ResearchLoopArbiterService::class
                || data_get($work->payload, 'executor') !== ResearchExperimentWorkConsumerService::class
                || data_get($work->payload, 'purpose') !== $purpose) {
                throw new LogicException('DESCENDANT_CANONICAL_WORK_OWNER_REQUIRED');
            }
            if ($component) {
                $registration = $this->certificates->verifiedRegistration((int) data_get($work->payload, 'certificate_id'));
                if ($registration['scope'] !== 'component') {
                    throw new LogicException('SCOPED_COMPONENT_ORIGINAL_CERTIFICATE_REQUIRED');
                }
                $source = AgentLearningCausalExperiment::findOrFail($registration['source_id']);
                $proof = ['status' => 'scoped_proof_inspected', 'trial_id' => null, 'certificate' => $registration];
                $expected = ['candidate' => data_get($registration, 'design.subject.candidate_role') === 'guided'
                    ? $source->guided_agent_id : $source->blinded_agent_id, 'control' => $source->control_agent_id];
                foreach ($expected as $arm => $agentId) {
                    $identity = data_get($registration, 'design.subject.arm_models.'.$arm, []);
                    $agent = LabAgent::with('modelVersion')->findOrFail($agentId);
                    if (($identity['source_agent_id'] ?? null) !== (int) $agentId
                        || ($identity['model_version_id'] ?? null) !== (int) $agent->model_version_id
                        || ($identity['parameter_hash'] ?? null) !== app(ResearchPaperEpochContractService::class)->parameterHash((array) $agent->modelVersion->parameters)
                        || ($identity['runtime_hash'] ?? null) !== app(ResearchPaperEpochContractService::class)->parameterHash($this->evidence->modelRuntimeBasis($agent->modelVersion))) {
                        throw new LogicException('SCOPED_COMPONENT_ORIGINAL_FROZEN_ARM_DRIFT');
                    }
                }
                $trial = $source;
            } else {
                $proof = $this->proofs->inspect((int) data_get($work->payload, 'trial_id'), (int) data_get($work->payload, 'certificate_id'));
            }
            if (($proof['status'] ?? null) !== 'scoped_proof_inspected') {
                throw new LogicException((string) ($proof['reason_code'] ?? 'DESCENDANT_ORIGINAL_SEALED_TRIAL_REQUIRED'));
            }
            if (! $component) {
                $trial = DescendantValueTrial::findOrFail($proof['trial_id']);
            }
            $design = $proof['certificate']['design'];
            $native = $design['native_execution'] ?? [];
            if (($native['protocol'] ?? null) !== self::PROTOCOL
                || ($native['executor_source_hash'] ?? null) !== hash_file('sha256', __FILE__)
                || ($native['python_source_hash'] ?? null) !== $this->releases->pythonHash()
                || $design['evaluator_hash'] !== $this->evidence->codeHash()
                || data_get($work->receipt?->payload, 'evidence.certificate_id') !== $proof['certificate']['certificate_id']
                || data_get($work->receipt?->payload, 'evidence.prospective_design_hash') !== $proof['certificate']['design_hash']) {
                throw new LogicException('DESCENDANT_ORIGINAL_EXECUTOR_OR_WORK_SEAL_DRIFT');
            }
            if (! $component) {
                $cartridge = LabSkillZooEntry::findOrFail($design['subject']['source_cartridge']['id']);
                $this->assertSourceComponent($native['source_component_certificate_id'], $design, $cartridge);
            }
            if (($native['purpose'] ?? null) !== $purpose) {
                throw new LogicException('SCOPED_NATIVE_PURPOSE_SEAL_MISMATCH');
            }
            if ($work->attempts >= $native['max_deliveries'] && $work->status !== 'leased') {
                throw new LogicException('DESCENDANT_ORIGINAL_DELIVERY_CAP_EXHAUSTED');
            }
            foreach ((array) data_get($work->result, 'original_arms', []) as $unitKey => $checkpoint) {
                $run = $this->checkpointRun($work, $checkpoint['arm'] ?? '', $checkpoint);
                if (! $run->finished_at) {
                    throw new LogicException('DESCENDANT_ORIGINAL_ARM_STILL_IN_FLIGHT');
                }
                if ($run->status !== 'completed') {
                    throw new LogicException('DESCENDANT_ORIGINAL_TECHNICAL_ATTEMPT_TERMINAL');
                }
            }
            $inventory = $this->certificates->independentWindowReadiness((int) $proof['certificate']['certificate_id']);
            if (($inventory['ready'] ?? false) !== true) {
                throw new LogicException((string) ($inventory['reason_codes'][0] ?? 'DESCENDANT_ORIGINAL_EXPOSURE_AND_DATA_BINDING_REQUIRED'));
            }
            $windows = [];
            $volumeModels = ModelVersion::whereIn('id', array_column($design['subject']['arm_models'], 'model_version_id'))->get()->all();
            foreach ($native['authorization_ids'] as $ordinal => $authorizationId) {
                $records = array_values(array_filter((array) config('services.instrument_policy.authorized_research_windows', []),
                    fn ($record): bool => is_array($record) && ($record['authorization_id'] ?? null) === $authorizationId));
                if (count($records) !== 1) {
                    throw new LogicException('DESCENDANT_ACTUAL_AUTHORIZED_ORIGINAL_WINDOW_REQUIRED');
                }
                $record = $records[0];
                $manifest = (array) ($record['mtf_bundle_manifest'] ?? []);
                $window = $this->windows->seal($authorizationId, (string) ($record['dataset_sha256'] ?? ''));
                if (! $window) {
                    throw new LogicException('NO_COMPLETED_AUTHORIZED_INDEPENDENT_WINDOW');
                }
                $planned = $design['validation_windows'][$ordinal];
                if (! CarbonImmutable::parse($window['start_inclusive'])->eq(CarbonImmutable::parse($planned['start_inclusive']))
                    || ! CarbonImmutable::parse($window['end_exclusive'])->eq(CarbonImmutable::parse($planned['end_exclusive']))) {
                    throw new LogicException('DESCENDANT_ACTUAL_WINDOW_OUTSIDE_PREREGISTERED_INTERVAL');
                }
                $originalRows = array_values(array_filter((array) ($inventory['windows'] ?? []),
                    fn ($row): bool => data_get($row, 'window.window_key') === $window['window_key']));
                $original = count($originalRows) === 1 ? ($originalRows[0]['original_readiness'] ?? null) : null;
                // The registry's original exposure producer owns the inventory
                // and exact data binding; a configured window cannot replace it.
                if (! is_array($original) || ($original['ready'] ?? false) !== true
                    || ($original['window_key'] ?? null) !== $window['window_key']) {
                    throw new LogicException((string) ($original['reason_code'] ?? 'DESCENDANT_ORIGINAL_EXPOSURE_AND_DATA_BINDING_REQUIRED'));
                }
                $input = $this->windows->verifySealedReplayWindow($window, $manifest);
                app(LabAgentEvaluationService::class)->scopedOriginalVolumeContext($volumeModels, $trial->symbol,
                    ['bundle_hash' => $window['dataset_sha256'], 'manifest' => $manifest]);
                $execution = app(ExecutionContractService::class)->for($trial->symbol, 'M5');
                if ($execution['execution_hash'] !== $design['execution_hash']
                    || ! $this->evidence->equivalentJsonValue($execution['parameters'], $native['cost_model'])) {
                    throw new LogicException('DESCENDANT_FROZEN_COST_POLICY_CHANGED');
                }
                $rows = (int) ($input['files']['M5']['rows'] ?? 0);
                if ($rows < 201 || $rows > $native['full_replay_runtime_policy']['maximum_source_rows']) {
                    throw new LogicException('DESCENDANT_ORIGINAL_FULL_SOURCE_OUTSIDE_BOUND');
                }
                $windows[] = ['window' => $window, 'manifest' => $manifest, 'original_input' => $input];
            }
            if (count(array_unique(array_map(fn ($entry): string => $entry['window']['dataset_sha256'], $windows))) !== count($windows)) {
                throw new LogicException('DESCENDANT_ORIGINAL_WINDOWS_REUSED_DATASET');
            }

            return ['protocol' => self::PROTOCOL, 'status' => 'ready', 'executable' => true,
                'trial_id' => $component ? null : (int) $trial->id, 'source_id' => (int) $trial->id,
                'purpose' => $purpose, 'certificate_id' => $proof['certificate']['certificate_id'],
                'design' => $design, 'design_hash' => $proof['certificate']['design_hash'],
                'windows' => $windows,
                'research_only' => true, 'promotion_evidence' => false];
        } catch (Throwable $error) {
            return $this->blocked($this->reason($error, 'DESCENDANT_ORIGINAL_OWNER_UNAVAILABLE'));
        }
    }

    /** Sole constructor admission and per-publication fence. */
    public function assertLease(ResearchExperimentWorkItem $work, int $minimumSeconds = 0): void
    {
        $current = ResearchExperimentWorkItem::find($work->id);
        $this->assertLeaseFence($work, $current, $minimumSeconds);
        if (! app(AutonomousModeService::class)->enabled($current->symbol, $current->timeframe)) {
            throw new LogicException('AUTONOMOUS_MODE_STOPPED');
        }
    }

    /** Raw facts from an authorized attempt may publish after STOP, never after ownership loss. */
    private function assertLeaseFence(ResearchExperimentWorkItem $work, ?ResearchExperimentWorkItem $current, int $minimumSeconds = 0): void
    {
        if (! $current || $current->status !== 'leased' || $current->lease_token !== $work->lease_token
            || (int) $current->fence_version !== (int) $work->fence_version
            || ! $current->lease_expires_at || $current->lease_expires_at->lte(now()->addSeconds($minimumSeconds))
            || data_get($current->payload, 'owner') !== ResearchLoopArbiterService::class) {
            throw new LogicException('DESCENDANT_WORK_LEASE_NOT_CURRENT');
        }
    }

    /** Reuses the native payload compiler and the original authorized transport. */
    public function compileRequest(LabGeneration $cohort, LabAgent $agent, string $arm, array $proof): array
    {
        $design = $proof['design'];
        $native = $design['native_execution'];
        $model = $agent->modelVersion;
        if (! in_array($arm, $this->armRoles($proof), true)
            || (int) $agent->lab_generation_id !== (int) $cohort->id
            || (int) $model->id !== $design['subject']['arm_models'][$arm]['model_version_id']) {
            throw new LogicException('DESCENDANT_ORIGINAL_ARM_CONSTRUCTOR_MISMATCH');
        }
        $paths = [];
        foreach ($proof['manifest']['streams'] as $frame => $record) {
            $paths[$frame] = $record['path'];
        }
        $bundle = ['bundle_hash' => $proof['window']['dataset_sha256'], 'manifest' => $proof['manifest'],
            'entry_dataset_path' => $paths['M5'], 'dataset_paths' => $paths];
        $binding = null;
        $renderModel = $model;
        if (data_get($model->metadata, 'prospective_scoped_composition_recipe') !== null) {
            $binding = app(CompositionAuthorityKernelService::class)->compileProspectiveRecipeForRequest($model, [
                'dataset_hash' => $proof['window']['dataset_sha256'], 'execution_hash' => $design['execution_hash'],
                'timeframe' => 'M5', 'mtf_manifest' => $proof['manifest'],
                'expected_recipe_hash' => data_get($model->metadata, 'prospective_scoped_composition_recipe_hash')]);
            $renderModel = $binding['model'];
            $metadata = (array) $renderModel->metadata;
            $metadata['instrument_research_assignment'] = app(LabInstrumentResearchService::class)->bindScopedOriginalAssignment(
                $model, $renderModel, $binding['passport'], $binding['recipe']);
            $renderModel->metadata = $metadata;
        }
        $strategy = app(LabAgentEvaluationService::class)->scopedOriginalMemberPayload(
            $renderModel, 'M5', $bundle, $proof['window']['dataset_sha256'], $agent->symbol);
        $strategy['lab_agent_id'] = (int) $agent->id;
        $execution = app(ExecutionContractService::class)->for($agent->symbol, 'M5');
        $volumeModels = [];
        foreach ($design['subject']['arm_models'] as $identity) {
            $volumeModels[] = ModelVersion::findOrFail($identity['model_version_id']);
        }
        $request = ['symbol' => $agent->symbol, 'timeframe' => 'M5', 'strategy' => 'all',
            'evaluation_mode' => 'full', 'strategies' => [$strategy],
            'mtf_pilot' => $strategy['mtf_pilot'] ?? [],
            'volume_context' => app(LabAgentEvaluationService::class)->scopedOriginalVolumeContext($volumeModels, $agent->symbol, $bundle),
            'composition_runtime_contract' => $strategy['composition_runtime_contract'] ?? (object) [],
            'dataset_path' => $paths['M5'], 'replay_dataset_hash' => $proof['window']['dataset_sha256'],
            'mtf_dataset_paths' => $paths, 'mtf_snapshot_manifest' => $proof['manifest'],
            'execution' => $execution['parameters'], 'execution_contract' => $execution,
            'execution_hash' => $execution['execution_hash'], 'initial_balance' => $native['initial_capital'],
            'risk_per_trade' => $native['risk_policy']['risk_per_trade_percent'], 'emit_decision_trace' => true,
            'policy_context' => ['full_replay_runtime_policy' => $native['full_replay_runtime_policy'],
                'scoped_research_certificate' => ['protocol' => self::PROTOCOL, 'purpose' => $native['purpose'],
                    'certificate_id' => $proof['certificate_id'], 'trial_id' => $proof['trial_id'],
                    'design_hash' => $proof['design_hash'], 'arm' => $arm,
                    'window_key' => $proof['window']['window_key'], 'research_only' => true, 'promotion_evidence' => false]]];
        $holding = (int) data_get($design, 'exposure_policy.holding_fence_seconds', 0);
        $end = CarbonImmutable::parse($proof['window']['end_exclusive'])->utc();
        $request['policy_context']['scoped_position_maturity_fence'] = [
            'protocol' => 'scoped_original_maturity_fence_v1', 'entry_end_exclusive' => $end->subSeconds($holding)->toIso8601String(),
            'end_exclusive' => $end->toIso8601String(), 'holding_fence_seconds' => $holding];
        if ($binding !== null) {
            $request['policy_context']['scoped_program_binding'] = ['protocol' => 'scoped_original_program_binding_v1',
                'model_version_id' => (int) $model->id, 'recipe_hash' => $binding['recipe_hash'],
                'compiled_passport_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($binding['passport']),
                'dataset_hash' => $proof['window']['dataset_sha256'], 'execution_hash' => $design['execution_hash'],
                'parameters_unchanged' => true, 'source_model_metadata_unchanged' => true, 'promotion_evidence' => false];
        }
        $request = $this->releases->bindGenerationRequest($cohort, $request, [(int) $agent->id]);
        if (data_get($request, 'policy_context.authorized_research_transport.window.window_key') !== $proof['window']['window_key']) {
            throw new LogicException('DESCENDANT_ORIGINAL_AUTHORIZED_TRANSPORT_REQUIRED');
        }

        return $request;
    }

    /** At most one HTTP evaluation in a genuine work delivery; original arms cannot be retried. */
    public function execute(ResearchExperimentWorkItem $work): array
    {
        $owner = Cache::lock('scoped-descendant-work:'.$work->id, self::WORK_LEASE_SECONDS + 60);
        if (! $owner->get()) {
            return $this->defer($work, 'DESCENDANT_ORIGINAL_OWNER_BUSY', true);
        }
        $stage = 'readiness';
        try {
            $this->assertLease($work);
            $proof = $this->inspectWork($work->fresh('receipt'));
            if (($proof['executable'] ?? false) !== true) {
                return $this->defer($work, $proof['reason_code'], false);
            }
            $units = [];
            $hashes = [];
            $cohorts = [];
            // All original windows and all four requests must be ready before
            // the first original arm is published. No favorable early window
            // can release only its own subset of the preregistered matrix.
            foreach ($proof['windows'] as $entry) {
                $stage = 'construction';
                $windowProof = [...$proof, ...$entry];
                $prepared = data_get($work->fresh()->result, 'matrix_preparation.'.$entry['window']['window_key']);
                if ($prepared !== null) {
                    $cohort = LabGeneration::findOrFail($prepared['generation_id'] ?? 0);
                    $this->assertPreparedCohort($work, $cohort, $windowProof);
                    $cohorts[$entry['window']['window_key']] = $cohort;
                    if (($prepared['generation_id'] ?? null) !== (int) $cohort->id
                        || ($prepared['design_hash'] ?? null) !== $proof['design_hash']
                        || array_keys((array) ($prepared['request_hashes'] ?? [])) !== $this->armRoles($proof)) {
                        throw new LogicException('SCOPED_NATIVE_ORIGINAL_PREPARATION_PREFIX_DRIFT');
                    }
                    foreach ($this->armRoles($proof) as $arm) {
                        $key = $entry['window']['window_key'].'_'.$arm;
                        $hashes[$key] = $prepared['request_hashes'][$arm];
                        $units[$key] = ['cohort' => $cohort, 'arm' => $arm, 'windowProof' => $windowProof];
                    }

                    continue;
                }
                $cohort = app(LabPopulationService::class)->buildScopedDescendant($work, $windowProof);
                $this->assertLease($work);
                $cohorts[$entry['window']['window_key']] = $cohort;
                $agents = $cohort->agents()->with('modelVersion')->get()->keyBy('model_version_id');
                $windowHashes = [];
                foreach ($this->armRoles($proof) as $arm) {
                    $stage = 'request_compilation';
                    $agent = $agents[$proof['design']['subject']['arm_models'][$arm]['model_version_id']] ?? null;
                    if (! $agent) {
                        throw new LogicException('DESCENDANT_ALL_FOUR_READY_BEFORE_DISPATCH_REQUIRED');
                    }
                    $request = $this->compileRequest($cohort, $agent, $arm, $windowProof);
                    $key = $entry['window']['window_key'].'_'.$arm;
                    $units[$key] = compact('cohort', 'agent', 'arm', 'request', 'windowProof');
                    $hashes[$key] = $this->evidence->hash($request);
                    $windowHashes[$arm] = $hashes[$key];
                }
                $this->checkpointPreparation($work, $cohort, $windowProof, $windowHashes);

                return $this->defer($work, 'SCOPED_NATIVE_ORIGINAL_MATRIX_PREPARATION_PREFIX_PENDING', true);
            }
            $this->publishBarrier($work, $proof, $hashes, array_map(fn ($cohort): int => (int) $cohort->id, $cohorts));
            $stage = 'original_arm_delivery';
            foreach ($units as $unitKey => $unit) {
                ['cohort' => $cohort, 'arm' => $arm, 'windowProof' => $windowProof] = $unit;
                $checkpoint = data_get($work->fresh()->result, 'original_arms.'.$unitKey);
                if ($checkpoint !== null) {
                    $run = $this->checkpointRun($work, $arm, $checkpoint);
                    if (! $run->finished_at) {
                        return $this->defer($work, 'DESCENDANT_ORIGINAL_ARM_STILL_IN_FLIGHT', false);
                    }
                    if ($run->status !== 'completed') {
                        return $this->closeTechnical($work, $cohort, 'DESCENDANT_ORIGINAL_TECHNICAL_ATTEMPT_TERMINAL');
                    }
                    if (! app(AutonomousModeService::class)->enabled($work->symbol, $work->timeframe)) {
                        return $this->defer($work, 'AUTONOMOUS_MODE_STOPPED', false);
                    }

                    continue;
                }
                $agent = $cohort->agents()->with('modelVersion')->where('model_version_id', $proof['design']['subject']['arm_models'][$arm]['model_version_id'])->sole();
                $request = $this->compileRequest($cohort, $agent, $arm, $windowProof);
                if ($this->evidence->hash($request) !== $hashes[$unitKey]) {
                    throw new LogicException('SCOPED_NATIVE_ORIGINAL_PREPARATION_REQUEST_DRIFT');
                }

                return $this->queueOriginalArm($work, $unitKey, $request, $windowProof);
            }
            $products = [];
            $stage = 'original_matrix_settlement';
            foreach ($proof['windows'] as $entry) {
                $ids = [];
                foreach ($this->armRoles($proof) as $arm) {
                    $ids[$arm] = $this->checkpointRun(
                        $work, $arm, data_get($work->fresh()->result, 'original_arms.'.$entry['window']['window_key'].'_'.$arm))->id;
                }
                $this->assertOriginalClockParity($ids, [...$proof, ...$entry]);
                $products[] = ['window_key' => $entry['window']['window_key'], 'run_ids' => $ids];
            }

            return $this->settleOriginalMatrix($work, $proof, $products, $cohorts);
        } catch (Throwable $error) {
            $reason = $this->reason($error, 'DESCENDANT_NATIVE_EXECUTOR_DEPENDENCY');
            if ($stage === 'original_matrix_settlement' && isset($cohort)
                && in_array($reason, ['DESCENDANT_ORIGINAL_EXECUTED_CLOCK_REQUIRED',
                    'DESCENDANT_ORIGINAL_EXECUTED_CLOCK_IDENTITY_MISMATCH',
                    'DESCENDANT_ORIGINAL_EXECUTED_CLOCK_PHYSICAL_SCHEDULE_MISMATCH',
                    'DESCENDANT_ORIGINAL_FOUR_ARM_EXECUTED_CLOCK_MISMATCH'], true)) {
                return $this->closeTechnical($work, $cohort, $reason);
            }
            $class = get_class($error);

            return [...$this->defer($work, $reason, false), 'stage' => $stage,
                'exception_class' => preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]{0,159}$/D', $class) ? $class : 'Throwable'];
        } finally {
            $owner->release();
        }
    }

    /** Authority and credit are new actions, unlike already completed raw originals. */
    private function settleOriginalMatrix(ResearchExperimentWorkItem $work, array $proof, array $products, array $cohorts): array
    {
        return DB::transaction(function () use ($work, $proof, $products, $cohorts): array {
            // Existing issuer/bridge transactions nest on this connection.
            // They lock certificates, never this existing work in reverse order.
            $lockedLease = ResearchExperimentWorkItem::whereKey($work->id)->lockForUpdate()->firstOrFail();
            $this->assertLease($work);
            $authority = $this->certificates->issueIndependent($proof['certificate_id'], $products);
            $this->assertLease($work);
            $scope = $work->work_type === self::COMPONENT_WORK_TYPE ? 'component' : 'inheritance';
            if (! in_array(data_get($authority, 'original_authority.'.$scope.'.status'), ['confirmed', 'negative_or_inconclusive'], true)) {
                return $this->defer($work, (string) data_get($authority, 'issuance.reason_code',
                    'SCOPED_NATIVE_INDEPENDENT_ORIGINAL_SETTLEMENT_DEPENDENCY'), false);
            }
            $handoff = data_get($authority, 'original_authority.'.$scope.'.confirmed') === true
                ? app(CausalSkillCreditBridgeService::class)->settleScopedCertificate($proof['certificate_id']) : null;
            $this->assertLease($work);
            $next = $scope === 'component' && ($handoff['status'] ?? null) === 'credited'
                ? app(ScopedDescendantCandidatePreparationService::class)->register($proof['certificate_id']) : null;
            $this->assertLease($work);
            $terminal = $scope === 'inheritance'
                ? $this->proofs->recordIndependentTerminal($proof['trial_id'], $proof['certificate_id']) : null;
            if (($terminal['status'] ?? null) === 'blocked') {
                throw new LogicException($terminal['reason_code']);
            }
            $this->assertLease($work);
            foreach ($cohorts as $cohort) {
                $cohort->update(['status' => 'completed', 'completed_at' => now()]);
            }
            // Last fresh lease/mode check immediately precedes the fenced
            // terminal write and commit. Any loss rolls back all new authority,
            // credit, next-work and cohort writes, not prior original facts.
            $this->assertLease($work);
            $done = $this->conversion->complete($work, ['status' => 'original_four_arm_diagnostic_completed',
                'generation_ids' => array_map(fn ($cohort): int => (int) $cohort->id, $cohorts), 'trial_id' => $proof['trial_id'],
                'certificate_id' => $proof['certificate_id'], 'original_products' => $products,
                'independent_scope' => $authority, 'scoped_credit_handoff' => $handoff,
                'original_trial_terminal' => $terminal,
                'prospective_descendant_next_work' => $next, 'promotion_evidence' => false]);
            if (! $done) {
                throw new LogicException('DESCENDANT_WORK_LEASE_NOT_CURRENT');
            }
            // The terminal CAS consumes this row's token intentionally. Its
            // original locked ownership cannot be reassigned until commit;
            // recheck that original lease's clock and current operator mode.
            $this->assertLeaseFence($work, $lockedLease);
            if (! app(AutonomousModeService::class)->enabledForCommit($work->symbol, $work->timeframe)) {
                throw new LogicException('AUTONOMOUS_MODE_STOPPED');
            }

            return ['protocol' => self::PROTOCOL, 'status' => 'completed',
                'original_products' => $products, 'promotion_evidence' => false];
        });
    }

    private function publishBarrier(ResearchExperimentWorkItem $work, array $proof, array $hashes, array $cohortIds): void
    {
        DB::transaction(function () use ($work, $proof, $hashes, $cohortIds): void {
            $current = ResearchExperimentWorkItem::whereKey($work->id)->lockForUpdate()->firstOrFail();
            $this->assertLease($work);
            $barrier = ['protocol' => self::PROTOCOL, 'generation_ids' => $cohortIds,
                'certificate_id' => $proof['certificate_id'], 'design_hash' => $proof['design_hash'],
                'original_request_hashes' => $hashes,
                'all_original_arms_ready_before_dispatch' => true, 'promotion_evidence' => false];
            $old = data_get($current->result, 'four_arm_barrier');
            if ($old !== null && ! $this->evidence->equivalentJsonValue($barrier, $old)) {
                throw new LogicException('DESCENDANT_PREREGISTERED_ALL_ARM_REQUEST_DRIFT');
            }
            $current->update(['result' => [...(array) $current->result, 'four_arm_barrier' => $barrier]]);
        });
    }

    private function checkpointPreparation(ResearchExperimentWorkItem $work, LabGeneration $cohort, array $proof, array $hashes): void
    {
        DB::transaction(function () use ($work, $cohort, $proof, $hashes): void {
            $current = ResearchExperimentWorkItem::whereKey($work->id)->lockForUpdate()->firstOrFail();
            $this->assertLease($work);
            if (! array_key_exists($proof['window']['window_key'], (array) data_get($current->result, 'matrix_preparation', []))) {
                $result = (array) $current->result;
                $result['matrix_preparation'][$proof['window']['window_key']] = ['protocol' => self::PROTOCOL,
                    'generation_id' => (int) $cohort->id, 'design_hash' => $proof['design_hash'], 'request_hashes' => $hashes,
                    'promotion_evidence' => false];
                $current->update(['result' => $result]);
            } else {
                throw new LogicException('SCOPED_NATIVE_ORIGINAL_PREPARATION_PREFIX_ALREADY_PUBLISHED');
            }
        });
    }

    private function assertPreparedCohort(ResearchExperimentWorkItem $work, LabGeneration $cohort, array $proof): void
    {
        $marker = data_get($cohort->trigger_context, 'scoped_descendant_execution', []);
        $ids = $cohort->agents()->pluck('model_version_id')->map('intval')->all();
        $expected = array_map(fn ($identity): int => (int) $identity['model_version_id'], $proof['design']['subject']['arm_models']);
        sort($ids);
        sort($expected);
        if (($marker['protocol'] ?? null) !== self::PROTOCOL || ($marker['work_item_id'] ?? null) !== (int) $work->id
            || ($marker['work_key'] ?? null) !== $work->work_key || ($marker['certificate_id'] ?? null) !== $proof['certificate_id']
            || ($marker['window_key'] ?? null) !== $proof['window']['window_key']
            || ($marker['design_hash'] ?? null) !== $proof['design_hash'] || $ids !== $expected
            || (int) $cohort->population_size !== count($this->armRoles($proof))
            || ! (app(ImmutableGenerationContractService::class)->validate($cohort)['valid'] ?? false)) {
            throw new LogicException('SCOPED_NATIVE_ORIGINAL_PREPARED_COHORT_DRIFT');
        }
        $this->releases->assertCurrent($cohort);
    }

    /** Source-backed full worker budget, separate from the 900-second pump. */
    public static function nativeJobTimeout(int $producerSeconds): int
    {
        $httpSeconds = $producerSeconds + 180;
        $watchdog = $httpSeconds + 180;
        if ($producerSeconds < 30 || $producerSeconds > 1620
            || $httpSeconds > (int) config('services.lab_selection.full_replay_timeout_seconds', 3900)
            || $watchdog > EvaluateLabAgentJob::FULL_JOB_TIMEOUT_SECONDS
            || $watchdog + 60 >= self::WORK_LEASE_SECONDS) {
            throw new LogicException('SCOPED_NATIVE_EXISTING_FULL_WORKER_BUDGET_INSUFFICIENT');
        }

        return $watchdog;
    }

    private function queueOriginalArm(ResearchExperimentWorkItem $work, string $unitKey, array $request, array $proof): array
    {
        $budget = (int) data_get($proof, 'design.native_execution.full_replay_runtime_policy.maximum_runtime_seconds');
        $timeout = self::nativeJobTimeout($budget);
        $this->assertLease($work, $timeout + 60);
        $connection = (string) config('queue.default', 'redis');
        if (config('queue.connections.'.$connection.'.driver') === 'sync') {
            throw new LogicException('SCOPED_NATIVE_ASYNCHRONOUS_REPLAY_QUEUE_REQUIRED');
        }
        $hash = $this->evidence->hash($request);
        $publish = DB::transaction(function () use ($work, $unitKey, $hash, $budget): bool {
            $current = ResearchExperimentWorkItem::whereKey($work->id)->lockForUpdate()->firstOrFail();
            $this->assertLease($work);
            $previous = data_get($current->result, 'queued_arm');
            if (is_array($previous) && ($previous['fence_version'] ?? null) === (int) $work->fence_version) {
                if (($previous['unit_key'] ?? null) !== $unitKey || ($previous['request_hash'] ?? null) !== $hash) {
                    throw new LogicException('SCOPED_NATIVE_QUEUE_PUBLICATION_FENCE_DRIFT');
                }

                return false;
            }
            $current->update(['result' => [...(array) $current->result, 'queued_arm' => [
                'protocol' => self::PROTOCOL, 'unit_key' => $unitKey, 'request_hash' => $hash,
                'fence_version' => (int) $work->fence_version, 'producer_budget_seconds' => $budget,
                'queue' => 'lab-full-validation', 'promotion_evidence' => false]]]);

            return true;
        });
        if ($publish) {
            $this->assertLease($work, $timeout + 60);
            Bus::dispatch(new ExecuteScopedResearchArmJob((int) $work->id, $work->lease_token,
                (int) $work->fence_version, $unitKey, $hash, $budget));
        }

        return ['protocol' => self::PROTOCOL, 'status' => 'queued', 'work_item_id' => (int) $work->id,
            'unit_key' => $unitKey, 'job_timeout_seconds' => $timeout,
            'queue' => 'lab-full-validation', 'promotion_evidence' => false];
    }

    /** Worker-side original publication under the exact lease which queued it. */
    public function executeQueuedArm(int $workId, string $token, int $fence, string $unitKey, string $requestHash, int $budget): array
    {
        $work = ResearchExperimentWorkItem::with('receipt')->find($workId);
        if (! $work || $work->lease_token !== $token || (int) $work->fence_version !== $fence) {
            return [...$this->blocked('DESCENDANT_WORK_LEASE_NOT_CURRENT'), 'status' => 'stale_lease'];
        }
        $owner = Cache::lock('scoped-descendant-work:'.$workId, self::WORK_LEASE_SECONDS + 60);
        if (! $owner->get()) {
            return $this->defer($work, 'DESCENDANT_ORIGINAL_OWNER_BUSY', true);
        }
        try {
            $this->assertLease($work, self::nativeJobTimeout($budget) + 30);
            $queued = data_get($work->result, 'queued_arm');
            if (! is_array($queued) || ($queued['unit_key'] ?? null) !== $unitKey
                || ($queued['fence_version'] ?? null) !== $fence || ($queued['request_hash'] ?? null) !== $requestHash
                || ($queued['producer_budget_seconds'] ?? null) !== $budget
                || data_get($work->result, 'four_arm_barrier.original_request_hashes.'.$unitKey) !== $requestHash) {
                throw new LogicException('SCOPED_NATIVE_ORIGINAL_QUEUE_UNIT_REQUIRED');
            }
            $proof = $this->inspectWork($work);
            if (($proof['executable'] ?? false) !== true) {
                return $this->defer($work, $proof['reason_code'], false);
            }
            if (data_get($proof, 'design.native_execution.full_replay_runtime_policy.maximum_runtime_seconds') !== $budget) {
                throw new LogicException('SCOPED_NATIVE_ORIGINAL_PRODUCER_BUDGET_DRIFT');
            }
            foreach ($proof['windows'] as $entry) {
                foreach ($this->armRoles($proof) as $arm) {
                    if ($entry['window']['window_key'].'_'.$arm !== $unitKey) {
                        continue;
                    }
                    $cohortId = data_get($work->result, 'four_arm_barrier.generation_ids.'.$entry['window']['window_key']);
                    $cohort = LabGeneration::findOrFail($cohortId);
                    $agent = $cohort->agents()->with('modelVersion')->where('model_version_id', $proof['design']['subject']['arm_models'][$arm]['model_version_id'])->sole();
                    $windowProof = [...$proof, ...$entry];
                    $request = $this->compileRequest($cohort, $agent, $arm, $windowProof);
                    if ($this->evidence->hash($request) !== $requestHash) {
                        throw new LogicException('SCOPED_NATIVE_ORIGINAL_QUEUE_REQUEST_DRIFT');
                    }
                    $checkpoint = data_get($work->fresh()->result, 'original_arms.'.$unitKey);
                    if ($checkpoint !== null) {
                        $run = $this->checkpointRun($work, $arm, $checkpoint);

                        return $run->finished_at && $run->status === 'completed'
                            ? $this->defer($work, 'DESCENDANT_NEXT_ORIGINAL_ARM_OR_SETTLEMENT', true)
                            : $this->defer($work, 'DESCENDANT_ORIGINAL_ARM_CANNOT_REPEAT', false);
                    }
                    $run = $this->executeOriginalArm($work, $cohort, $agent, $arm, $request, $windowProof);
                    if (! $run) {
                        return $this->defer($work, 'DESCENDANT_SHARED_REPLAY_LANE_BUSY', true);
                    }
                    if ($run->status !== 'completed') {
                        return $this->closeTechnical($work, $cohort, 'DESCENDANT_ORIGINAL_TECHNICAL_ATTEMPT_TERMINAL');
                    }
                    if (! app(AutonomousModeService::class)->enabled($work->symbol, $work->timeframe)) {
                        return $this->defer($work, 'AUTONOMOUS_MODE_STOPPED', false);
                    }

                    return $this->defer($work, 'DESCENDANT_NEXT_ORIGINAL_ARM_OR_SETTLEMENT', true);
                }
            }
            throw new LogicException('SCOPED_NATIVE_ORIGINAL_QUEUE_UNIT_REQUIRED');
        } catch (Throwable $error) {
            return $this->defer($work, $this->reason($error, 'SCOPED_NATIVE_WORKER_DEPENDENCY'), false);
        } finally {
            $owner->release();
        }
    }

    private function executeOriginalArm(ResearchExperimentWorkItem $work, LabGeneration $cohort, LabAgent $agent,
        string $arm, array $request, array $proof): ?LabEvaluationRun
    {
        $budget = (int) $proof['design']['native_execution']['full_replay_runtime_policy']['maximum_runtime_seconds'];
        $httpSeconds = $budget + 180;
        $this->assertLease($work, $httpSeconds + 60);
        $lane = Cache::lock('laravel-queue-overlap:'.(string) config('services.lab_queue.replay_mutex_key',
            'neurotrader-ai-heavy-replay'), $httpSeconds + 120);
        if (! $lane->get()) {
            return null;
        }
        $run = null;
        $response = null;
        try {
            $this->assertLease($work, $httpSeconds + 60);
            $run = DB::transaction(function () use ($work, $cohort, $agent, $arm, $request, $proof): LabEvaluationRun {
                $current = ResearchExperimentWorkItem::whereKey($work->id)->lockForUpdate()->firstOrFail();
                $this->assertLease($work);
                $unitKey = $proof['window']['window_key'].'_'.$arm;
                if (data_get($current->result, 'original_arms.'.$unitKey) !== null
                    || LabEvaluationRun::where('lab_agent_id', $agent->id)->exists()) {
                    throw new LogicException('DESCENDANT_ORIGINAL_ARM_CANNOT_REPEAT');
                }
                $run = $this->evidence->beginRun($agent, 'full_validation', 'full', [
                    'source' => self::class, 'data_hash' => $request['replay_dataset_hash']]);
                $run->update(['metadata' => [...(array) $run->metadata,
                    'scoped_certificate_id' => $proof['certificate_id'],
                    'scoped_native_purpose' => $proof['design']['native_execution']['purpose'], 'scoped_research' => [
                        'protocol' => self::PROTOCOL, 'purpose' => $proof['design']['native_execution']['purpose'], 'certificate_id' => $proof['certificate_id'],
                        'trial_id' => $proof['trial_id'], 'work_item_id' => (int) $work->id, 'arm' => $arm,
                        'window_key' => $proof['window']['window_key'], 'projection_withheld' => true]]]);
                $requestId = 'scoped-descendant-'.$run->run_id;
                $this->evidence->attachRequest($run, $request, ['request_id' => $requestId, 'data_hash' => $request['replay_dataset_hash']]);
                $checkpoint = ['run_id' => (int) $run->id, 'run_key' => $run->run_id, 'request_id' => $requestId,
                    'request_hash' => $this->evidence->hash($request), 'certificate_id' => $proof['certificate_id'],
                    'generation_id' => (int) $cohort->id, 'agent_id' => (int) $agent->id,
                    'model_version_id' => (int) $agent->model_version_id, 'arm' => $arm,
                    'window_key' => $proof['window']['window_key']];
                $result = (array) $current->result;
                $result['original_arms'][$unitKey] = $checkpoint;
                $current->update(['result' => $result, 'heartbeat_at' => now()]);
                $agent->update(['lifecycle_status' => 'full_validation']);

                return $run;
            });
            $this->assertLease($work, $httpSeconds + 30);
            $reply = Http::connectTimeout(10)->timeout($httpSeconds)->acceptJson()->withHeaders([
                'X-Internal-Token' => (string) config('services.internal_api.token'),
                'X-Lab-Request-Id' => 'scoped-descendant-'.$run->run_id,
            ])->withBody(json_encode($request, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
                'application/json')->post(rtrim((string) config('services.ai_service.url'), '/').'/api/backtest/run-all');
            if ($reply->failed()) {
                throw new LogicException('DESCENDANT_ORIGINAL_TRANSPORT_HTTP_FAILURE');
            }
            $items = (array) data_get($reply->json(), 'leaderboard', []);
            if (count($items) !== 1 || ($items[0]['strategy'] ?? null) !== $agent->modelVersion->strategy
                || ! is_array($items[0]['result'] ?? null)) {
                throw new LogicException('DESCENDANT_SINGLE_ORIGINAL_RESULT_REQUIRED');
            }
            $response = $items[0]['result'];
            if (! app(ExecutionContractService::class)->matches((array) ($response['execution_contract'] ?? []), $agent->symbol, 'M5')
                || ! $this->releases->responseValid((array) data_get($cohort->trigger_context, 'research_release', []),
                    (array) data_get($response, 'data_quality.research_release_receipt', []))
                || ! ($this->evidence->replayEvidenceCompleteness($run, $response)['complete'] ?? false)) {
                throw new LogicException('DESCENDANT_COMPLETE_ORIGINAL_NATIVE_EVIDENCE_REQUIRED');
            }
            $this->verifyOriginalExecutedClock($response, $request, $proof['original_input']);
            DB::transaction(function () use ($work, $run, $response, $proof, $agent): void {
                $current = ResearchExperimentWorkItem::whereKey($work->id)->lockForUpdate()->first();
                $this->assertLeaseFence($work, $current);
                $this->evidence->finishIfOpen($run, 'completed', $response, [],
                    ['purpose' => $proof['design']['native_execution']['purpose'], 'research_only' => true,
                        'projection_withheld' => true, 'promotion_evidence' => false]);
                $agent->update(['lifecycle_status' => 'screened']);
            });
        } catch (Throwable $error) {
            if ($run) {
                $this->evidence->finishIfOpen($run, 'technical_error', $response, [], [
                    'reason_code' => $this->reason($error, 'DESCENDANT_ORIGINAL_TRANSPORT_TECHNICAL_FAILURE'),
                    'original_response_retained' => $response !== null,
                    'unassessable_original_observation' => $response !== null,
                    'research_only' => true, 'projection_withheld' => true, 'promotion_evidence' => false]);
            } else {
                throw $error;
            }
        } finally {
            $lane->release();
        }

        return $run->fresh();
    }

    private function checkpointRun(ResearchExperimentWorkItem $work, string $arm, array $checkpoint): LabEvaluationRun
    {
        $run = LabEvaluationRun::find($checkpoint['run_id'] ?? 0);
        $roles = $work->work_type === self::COMPONENT_WORK_TYPE ? ['candidate', 'control'] : DescendantScopedProofService::ARMS;
        $purpose = $work->work_type === self::COMPONENT_WORK_TYPE ? self::COMPONENT_PURPOSE : self::PURPOSE;
        if (! $run || ! in_array($arm, $roles, true)
            || $run->run_id !== ($checkpoint['run_key'] ?? null) || $run->request_id !== ($checkpoint['request_id'] ?? null)
            || $run->request_hash !== ($checkpoint['request_hash'] ?? null)
            || (int) $run->lab_generation_id !== ($checkpoint['generation_id'] ?? null)
            || (int) $run->model_version_id !== ($checkpoint['model_version_id'] ?? null)
            || (int) $run->lab_agent_id !== ($checkpoint['agent_id'] ?? null)
            || data_get($run->metadata, 'scoped_research.work_item_id') !== (int) $work->id
            || data_get($run->metadata, 'scoped_research.certificate_id') !== (int) data_get($work->payload, 'certificate_id')
            || data_get($run->metadata, 'scoped_research.arm') !== $arm
            || data_get($run->metadata, 'scoped_research.purpose') !== $purpose) {
            throw new LogicException('DESCENDANT_ORIGINAL_ARM_CHECKPOINT_DRIFT');
        }

        return $run;
    }

    /** Actual producer clocks must match; common input bytes alone are insufficient. */
    public function assertOriginalClockParity(array $runIds, array $proof): void
    {
        $physical = ['protocol', 'semantics', 'index_basis', 'input_rows', 'evaluation_offset_rows',
            'execution_timeframe', 'duration_seconds', 'decision_rows', 'first_evaluation_index', 'last_evaluation_index',
            'signal_start', 'signal_end', 'execution_start', 'execution_end', 'index_set_hash', 'schedule_hash'];
        $digests = [];
        foreach ($this->armRoles($proof) as $arm) {
            $run = LabEvaluationRun::find($runIds[$arm] ?? 0);
            $response = $run ? $this->evidence->latestArtifactPayload($run) : null;
            $request = $run ? $this->evidence->latestArtifactPayload($run, 'evaluation_request') : null;
            if (! is_array($response) || ! is_array($request)) {
                throw new LogicException('DESCENDANT_ORIGINAL_EXECUTED_CLOCK_REQUIRED');
            }
            $this->verifyOriginalExecutedClock($response, $request, $proof['original_input']);
            $clock = data_get($response, 'data_quality.replay_executed_clock');
            $unsigned = is_array($clock) ? array_diff_key($clock, ['receipt_hash' => true, 'receipt_json' => true]) : [];
            if (! is_array($clock) || ($clock['protocol'] ?? null) !== 'replay_executed_clock_v1'
                || ($clock['complete'] ?? null) !== true
                || ($clock['receipt_hash'] ?? null) !== app(ResearchPaperEpochContractService::class)->parameterHash($unsigned)
                || ! is_string($clock['receipt_json'] ?? null)
                || ! $this->evidence->equivalentJsonValue(json_decode($clock['receipt_json'], true), $unsigned)
                || ($clock['dataset_hash'] ?? null) !== $proof['window']['dataset_sha256']
                || ($clock['execution_hash'] ?? null) !== $proof['design']['execution_hash']
                || ($clock['policy_hash'] ?? null) !== app(ResearchPaperEpochContractService::class)->parameterHash($proof['design']['native_execution']['full_replay_runtime_policy'])
                || count(array_intersect_key($clock, array_flip($physical))) !== count($physical)) {
                throw new LogicException('DESCENDANT_ORIGINAL_EXECUTED_CLOCK_REQUIRED');
            }
            $digests[] = $this->evidence->hash(array_intersect_key($clock, array_flip($physical)));
        }
        if (count(array_unique($digests)) !== 1) {
            throw new LogicException('DESCENDANT_ORIGINAL_FOUR_ARM_EXECUTED_CLOCK_MISMATCH');
        }
    }

    public function armRoles(array $proof): array
    {
        $purpose = data_get($proof, 'design.native_execution.purpose');
        $roles = match ($purpose) {
            self::COMPONENT_PURPOSE => ['candidate', 'control'],
            self::PURPOSE => DescendantScopedProofService::ARMS,
            default => throw new LogicException('SCOPED_NATIVE_PURPOSE_SEAL_REQUIRED')
        };
        if (array_keys((array) data_get($proof, 'design.subject.arm_models')) !== $roles) {
            throw new LogicException('SCOPED_NATIVE_EXACT_ORIGINAL_ARM_ROSTER_REQUIRED');
        }

        return $roles;
    }

    /** Re-derive every actual next-open schedule entry from the original M5 bytes. */
    public function verifyOriginalExecutedClock(array $response, array $request, array $input): void
    {
        $clock = data_get($response, 'data_quality.replay_executed_clock');
        if (! is_array($clock)) {
            throw new LogicException('DESCENDANT_ORIGINAL_EXECUTED_CLOCK_REQUIRED');
        }
        $unsigned = array_diff_key($clock, ['receipt_hash' => true, 'receipt_json' => true]);
        $policy = data_get($request, 'policy_context.full_replay_runtime_policy');
        if (($clock['protocol'] ?? null) !== 'replay_executed_clock_v1' || ($clock['owner'] ?? null) !== 'ordinary_single_position_v1'
            || ($clock['complete'] ?? null) !== true || ($clock['promotion_evidence'] ?? null) !== false
            || ($clock['semantics'] ?? null) !== 'previous_closed_candle_next_open_v1'
            || ($clock['index_basis'] ?? null) !== 'evaluated_frame_zero_based_v1'
            || ($clock['execution_timeframe'] ?? null) !== 'M5' || ($clock['duration_seconds'] ?? null) !== 300
            || ($clock['evaluation_offset_rows'] ?? null) !== 0 || ($clock['first_evaluation_index'] ?? null) !== 200
            || ($clock['dataset_hash'] ?? null) !== ($request['replay_dataset_hash'] ?? null)
            || ($clock['execution_hash'] ?? null) !== ($request['execution_hash'] ?? null)
            || ($clock['policy_hash'] ?? null) !== app(ResearchPaperEpochContractService::class)->parameterHash($policy)
            || ($clock['probe_contract_hash'] ?? null) !== null
            || ($clock['receipt_hash'] ?? null) !== app(ResearchPaperEpochContractService::class)->parameterHash($unsigned)
            || ! is_string($clock['receipt_json'] ?? null) || strlen($clock['receipt_json']) > 8192
            || ! $this->evidence->equivalentJsonValue(json_decode($clock['receipt_json'], true), $unsigned)) {
            throw new LogicException('DESCENDANT_ORIGINAL_EXECUTED_CLOCK_IDENTITY_MISMATCH');
        }
        $source = $input['files']['M5'] ?? [];
        $handle = is_string($source['path'] ?? null) ? @fopen($source['path'], 'rb') : false;
        if ($handle === false) {
            throw new LogicException('DESCENDANT_ORIGINAL_CLOCK_SOURCE_REQUIRED');
        }
        try {
            $digest = hash_init('sha256');
            hash_update_stream($digest, $handle);
            if (hash_final($digest) !== ($source['sha256'] ?? null)) {
                throw new LogicException('DESCENDANT_ORIGINAL_CLOCK_SOURCE_CHANGED');
            }
            rewind($handle);
            $columns = array_map(fn ($field): string => strtolower(trim((string) $field)), fgetcsv($handle, escape: '') ?: []);
            $timeColumn = array_search('time', $columns, true);
            if ($timeColumn === false) {
                $timeColumn = array_search('timestamp', $columns, true);
            }
            if ($timeColumn === false) {
                throw new LogicException('DESCENDANT_ORIGINAL_CLOCK_SOURCE_TIME_REQUIRED');
            }
            $indices = hash_init('sha256');
            hash_update($indices, "replay-executed-clock-v1:indices\n");
            $schedule = hash_init('sha256');
            hash_update($schedule, "replay-executed-clock-v1:schedule\n");
            $rows = 0;
            $previous = null;
            $firstSignal = null;
            $firstExecution = null;
            $lastSignal = null;
            $lastExecution = null;
            while (($row = fgetcsv($handle, escape: '')) !== false) {
                $time = CarbonImmutable::parse((string) ($row[$timeColumn] ?? ''), 'UTC')->utc();
                if ($time->format('u') !== '000000' || ($previous && $time->lt($previous->addSeconds(300)))) {
                    throw new LogicException('DESCENDANT_ORIGINAL_CLOCK_SOURCE_CHRONOLOGY_INVALID');
                }
                if ($rows >= 200) {
                    $signal = $previous->toIso8601String();
                    $execution = $time->toIso8601String();
                    hash_update($indices, $rows."\n");
                    hash_update($schedule, json_encode([$rows, $signal, $execution], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
                    $firstSignal ??= $signal;
                    $firstExecution ??= $execution;
                    $lastSignal = $signal;
                    $lastExecution = $execution;
                }
                $previous = $time;
                if (++$rows > 200000) {
                    throw new LogicException('DESCENDANT_ORIGINAL_CLOCK_ROW_BOUND_EXCEEDED');
                }
            }
            $expected = ['input_rows' => $rows, 'decision_rows' => $rows - 200, 'last_evaluation_index' => $rows - 1,
                'signal_start' => $firstSignal, 'execution_start' => $firstExecution,
                'signal_end' => $lastSignal, 'execution_end' => $lastExecution,
                'index_set_hash' => hash_final($indices), 'schedule_hash' => hash_final($schedule)];
            if ($rows !== ($source['rows'] ?? null) || $rows <= 200
                || ! $this->evidence->equivalentJsonValue(array_intersect_key($clock, $expected), $expected)) {
                throw new LogicException('DESCENDANT_ORIGINAL_EXECUTED_CLOCK_PHYSICAL_SCHEDULE_MISMATCH');
            }
            rewind($handle);
            $digest = hash_init('sha256');
            hash_update_stream($digest, $handle);
            if (hash_final($digest) !== ($source['sha256'] ?? null)) {
                throw new LogicException('DESCENDANT_ORIGINAL_CLOCK_SOURCE_CHANGED');
            }
        } finally {
            fclose($handle);
        }
    }

    private function assertSourceComponent(int $certificateId, array $design, LabSkillZooEntry $cartridge): void
    {
        $proof = $this->certificates->inspect($certificateId);
        $authority = $proof['original_authority']['component'] ?? $proof;
        if (($proof['valid'] ?? false) !== true || ($proof['scope'] ?? null) !== 'component'
            || ($authority['confirmed'] ?? false) !== true
            || ($authority['authority_type'] ?? null) !== 'context_bound_research_component'
            || (int) ($authority['candidate_model_version_id'] ?? 0) !== (int) $cartridge->model_version_id
            || data_get($proof, 'design.context_hash') !== $design['context_hash']
            || ($authority['trait_delta']['gene'] ?? null) !== $cartridge->gene_key
            || ! $this->evidence->equivalentJsonValue($authority['trait_delta']['old'] ?? null, $design['subject']['trait_delta']['old'])
            || ! $this->evidence->equivalentJsonValue($authority['trait_delta']['new'] ?? null, $design['subject']['trait_delta']['new'])) {
            throw new LogicException('DESCENDANT_INDEPENDENT_SOURCE_COMPONENT_CERTIFICATE_REQUIRED');
        }
    }

    private function closeTechnical(ResearchExperimentWorkItem $work, LabGeneration $cohort, string $reason): array
    {
        $this->assertLease($work);
        LabGeneration::where('trigger_context->scoped_descendant_execution->work_item_id', $work->id)
            ->update(['status' => 'technical_quarantine', 'completed_at' => now()]);
        $this->conversion->complete($work, ['status' => 'original_four_arm_unassessable', 'reason_code' => $reason,
            'generation_id' => (int) $cohort->id, 'scientific_effect_inferred' => false, 'promotion_evidence' => false]);

        return $this->blocked($reason);
    }

    private function defer(ResearchExperimentWorkItem $work, string $reason, bool $retryable): array
    {
        $changed = $this->conversion->defer($work, $reason, $retryable);

        return [...$this->blocked($reason), 'work_item_id' => (int) $work->id,
            'status' => $changed ? ($retryable ? 'checkpointed' : 'blocked') : 'stale_lease'];
    }

    private function reason(Throwable $error, string $fallback): string
    {
        return ($error instanceof LogicException || $error instanceof \RuntimeException)
            && preg_match('/^[A-Z][A-Z0-9_]{0,159}$/D', $error->getMessage())
            ? $error->getMessage() : $fallback;
    }

    private function blocked(string $reason): array
    {
        return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason_code' => $reason,
            'executable' => false, 'research_only' => true, 'inheritance_credit' => false, 'promotion_evidence' => false];
    }
}
