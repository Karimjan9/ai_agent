<?php

namespace App\Services;

use App\Jobs\ExecuteScopedResearchArmJob;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentWorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use Throwable;

/** Original method-policy diagnostics, executed only by the existing arbiter/work/replay owners. */
class InstrumentMethodStudyExecutionService
{
    public const PROTOCOL = 'instrument_method_original_execution_v1';
    public const WORK_TYPE = 'instrument_method_original_matrix';
    public const MARKER = 'instrument_method_study_execution';
    public const WORK_LEASE_SECONDS = 2700;
    public const DEADLINE_PROTOCOL = 'instrument_method_original_deadline_v2';
    public const DEADLINE_MARKER = 'instrument_method_original_deadline';
    public const ARMS = ['L1A1', 'L2A1', 'L1A2', 'L2A2'];

    // Reviewed pre-deadline publisher: compileRequest retained raw model
    // parameters and returned bindGenerationRequest directly. This capability
    // is only for spent technical projection, never future execution permission.
    private const UNSIGNED_ORIGINAL_PUBLISHER_SHA256 = 'c804ef64778ceafa7858245d86691a3d4d289d450a3dc63daa58fae388fcf490';
    private const UNSIGNED_ORIGINAL_PUBLISHER_BYTES = 37367;

    public function __construct(private InstrumentMethodStudyService $studies,
        private InstrumentMethodResearchBindingService $bindings, private LabImmutableEvidenceService $evidence,
        private ResearchReleaseSealService $releases, private ResearchExperimentConversionKernelService $conversion) {}

    /** Seal a real local input before any dispatch; this is not independent validation. */
    public function registerWork(string $studyHash, array $source): array
    {
        try {
            return DB::transaction(function () use ($studyHash, $source): array {
                DB::table('instrument_method_studies')->where('study_hash', $studyHash)->lockForUpdate()->first();
                $design = $this->studies->originalDesign($studyHash);
                $this->need(isset($source['dataset_path'], $source['dataset_sha256'])
                    && array_diff(array_keys($source), ['dataset_path', 'dataset_sha256', 'origin']) === [], 'METHOD_STUDY_EXACT_SOURCE_DECLARATION_REQUIRED');
                $source = $this->inspectSource($source, $design);
                $registration = ['protocol' => self::PROTOCOL, 'study_hash' => $studyHash, 'source' => $source,
                    'php_source_hash' => $this->evidence->codeHash(), 'python_source_hash' => $this->releases->pythonHash(),
                    'max_original_attempts_per_arm' => 1, 'max_work_deliveries' => 8, ...$this->ceiling()];
                $registrationHash = $this->hash($registration);
                $row = DB::table('instrument_method_studies')->where('study_hash', $studyHash)->first();
                $this->need($row->assessment === null, 'METHOD_STUDY_ORIGINAL_ASSESSMENT_ALREADY_CLOSED');
                $existing = ResearchExperimentWorkItem::where('work_type', self::WORK_TYPE)
                    ->where('payload->method_registration->study_hash', $studyHash)->limit(2)->get();
                $this->need($existing->count() <= 1, 'METHOD_STUDY_ONE_ORIGINAL_WORK_REQUIRED');
                if ($existing->isNotEmpty()) {
                    $work = $existing->first();
                    $this->need(data_get($work->payload, 'method_registration_hash') === $registrationHash,
                        'METHOD_STUDY_ORIGINAL_REGISTRATION_IMMUTABLE');
                    return ['status' => 'original_work_exists', 'work_id' => (int) $work->id, 'study_hash' => $studyHash, ...$this->ceiling()];
                }
                foreach ($design['arms'] as $arm) {
                    $this->need(! LabEvaluationRun::where('model_version_id', $arm['model_id'])->exists()
                        && ! LabAgent::where('model_version_id', $arm['model_id'])->exists(), 'METHOD_STUDY_UNUSED_ORIGINAL_MODELS_REQUIRED');
                }
                $result = $this->conversion->record([
                    'contract_version' => ResearchExperimentConversionKernelService::CONTRACT_VERSION,
                    'source' => ['type' => self::class, 'id' => (int) $row->id],
                    'scope' => ['symbol' => $design['scope']['symbol'], 'laboratory_timeframe' => 'H1', 'execution_timeframe' => $design['scope']['timeframe']],
                    'claim' => ['target_stage' => 'original_method_diagnostic', 'hypothesis' => $design['study_key']],
                    'identity' => ['baseline_epoch_hash' => $design['arms']['L1A1']['baseline_hash'],
                        'data_and_mtf_hash' => $design['data_hash'], 'runtime_and_contract_hash' => $design['execution_hash'],
                        'intervention_hash' => $studyHash, 'window_plan_hash' => $this->hash($design['scope']), 'evaluator_version' => self::PROTOCOL],
                    'arms' => array_map(fn ($arm) => ['role' => $arm, 'model_id' => $design['arms'][$arm]['model_id']], self::ARMS),
                ], ['registration_hash' => $registrationHash, 'original_registration' => $registration, ...$this->ceiling()], 'INCONCLUSIVE',
                    ['type' => self::WORK_TYPE, 'identity' => $studyHash, 'priority' => 8, 'executable' => false,
                        'method_registration' => $registration, 'method_registration_hash' => $registrationHash,
                        'retry_condition' => ['code' => 'METHOD_STUDY_FRESH_ORIGINAL_READINESS_REQUIRED', 'max_experiments' => 1,
                            'same_evidence_replay_forbidden' => true]], []);
                $this->need(($result['status'] ?? null) === 'recorded', 'METHOD_STUDY_CANONICAL_WORK_REGISTRATION_REQUIRED');
                return ['status' => 'original_work_registered', 'study_hash' => $studyHash, 'work_id' => $result['work_id'], ...$this->ceiling()];
            });
        } catch (Throwable $error) { return $this->blocked($error); }
    }

    /** Fresh original proof at reconcile, claim, construction and every replay publication. */
    public function inspectWork(ResearchExperimentWorkItem $work): array
    {
        try {
            return app(AgentMethodLearningService::class)->withVerificationScope(
                fn (): array => $this->inspectWorkInVerificationScope($work));
        } catch (Throwable $error) { return $this->blocked($error); }
    }

    private function inspectWorkInVerificationScope(ResearchExperimentWorkItem $work): array
    {
        try {
            $registration = (array) data_get($work->payload, 'method_registration', []);
            $this->need($work->work_type === self::WORK_TYPE && data_get($work->payload, 'owner') === ResearchLoopArbiterService::class
                && data_get($work->payload, 'executor') === ResearchExperimentWorkConsumerService::class
                && ($registration['protocol'] ?? null) === self::PROTOCOL
                && $this->hash($registration) === data_get($work->payload, 'method_registration_hash'), 'METHOD_STUDY_ORIGINAL_WORK_OWNER_REQUIRED');
            $receipt = $work->receipt;
            $this->need($receipt !== null && data_get($receipt->payload, 'evidence.registration_hash') === $this->hash($registration)
                && data_get($receipt->payload, 'evidence.original_registration') === $registration, 'METHOD_STUDY_ORIGINAL_RECEIPT_BINDING_REQUIRED');
            foreach ($this->ceiling() as $field => $value) $this->need(($registration[$field] ?? null) === $value, 'METHOD_STUDY_DIAGNOSTIC_AUTHORITY_REQUIRED');
            $this->need($registration['php_source_hash'] === $this->evidence->codeHash()
                && $registration['python_source_hash'] === $this->releases->pythonHash(), 'METHOD_STUDY_REGISTERED_SOURCE_CHANGED');
            $design = $this->studies->originalDesign($registration['study_hash']);
            $this->need($work->symbol === $design['scope']['symbol'] && $work->timeframe === 'H1', 'METHOD_STUDY_STREAM_OWNER_MISMATCH');
            $source = $this->inspectSource($registration['source'], $design);
            $this->need($source === $registration['source'], 'METHOD_STUDY_ORIGINAL_SOURCE_CHANGED');
            $execution = app(ExecutionContractService::class)->for($work->symbol, $design['scope']['timeframe']);
            $this->need($execution['execution_hash'] === $design['execution_hash']
                && $this->hash($execution['parameters']) === $this->hash($design['execution_costs']), 'METHOD_STUDY_CURRENT_COST_POLICY_CHANGED');
            $lab = AiLaboratory::where('symbol', $work->symbol)->where('timeframe', $work->timeframe)->first();
            $this->need($lab !== null, 'METHOD_STUDY_EXISTING_LABORATORY_REQUIRED');
            $owned = $lab->generations()->where('trigger_context->'.self::MARKER.'->work_item_id', $work->id)->limit(2)->get();
            $this->need($owned->count() <= 1, 'METHOD_STUDY_ONE_ORIGINAL_COHORT_REQUIRED');
            $generation = $owned->first();
            $this->need(! $lab->generations()->whereIn('status', LabPopulationService::ACTIVE_GENERATION_STATUSES)
                ->when($generation, fn ($query) => $query->where('id', '!=', $generation->id))->exists(), 'METHOD_STUDY_ANOTHER_GENERATION_OWNS_STREAM');
            foreach ($design['arms'] as $armKey => $arm) {
                $model = ModelVersion::find($arm['model_id']);
                $this->need($model !== null
                    && $this->hash((array) $model->parameters) === $arm['parameter_hash']
                    && $this->hash($this->evidence->modelRuntimeBasis($model)) === $arm['model_runtime_basis_hash'],
                    'METHOD_STUDY_ORIGINAL_MODEL_DRIFT');
                $this->need(data_get($model->metadata, InstrumentMethodResearchBindingService::METADATA.'.policy_hash') === $arm['policy_hash']
                    && data_get($model->metadata, InstrumentMethodResearchBindingService::METADATA.'.agent_key') === 'model:'.$model->id,
                    'METHOD_STUDY_ORIGINAL_POLICY_BINDING_REQUIRED');
                // Candidate baseline already validates the canonical cost
                // envelope without treating it as model authority. A signed
                // meta attachment must inspect the unmodified physical model.
                app(AgentMethodCandidateService::class)->baseline($model);
                $policy = app(AgentMethodLearningService::class)->policy($arm['policy_hash']);
                $this->need(($policy['status'] ?? null) !== 'blocked' && $policy['agent_key'] === 'model:'.$model->id,
                    'METHOD_STUDY_ORIGINAL_POLICY_REQUIRED');
                $this->need($this->evidence->equivalentJsonValue((array) data_get($model->metadata, 'execution_contract.parameters', []), $design['execution_costs']),
                    'METHOD_STUDY_PERSISTED_ORIGINAL_COSTS_REQUIRED');
                $agents = LabAgent::where('model_version_id', $model->id)->get();
                $this->need($agents->count() <= 1 && ($agents->isEmpty() || (int) $agents->first()->lab_generation_id === (int) $generation?->id),
                    'METHOD_STUDY_ORIGINAL_ARM_ALREADY_ENROLLED');
                $runs = LabEvaluationRun::where('model_version_id', $model->id)->get();
                $checkpoint = data_get($work->result, 'original_arms.'.$armKey);
                $this->need($runs->count() <= 1 && ($runs->isEmpty() || (is_array($checkpoint)
                    && $runs->first()->run_id === ($checkpoint['run_id'] ?? null)
                    && $runs->first()->lab_generation_id === $generation?->id)), 'METHOD_STUDY_ORIGINAL_ARM_REPEAT_FORBIDDEN');
            }
            return ['protocol' => self::PROTOCOL, 'status' => 'ready', 'executable' => true,
                'study_hash' => $registration['study_hash'], 'design' => $design, 'source' => $source,
                'generation_id' => $generation?->id, ...$this->ceiling()];
        } catch (Throwable $error) { return $this->blocked($error); }
    }

    public function assertLease(ResearchExperimentWorkItem $work, int $minimumSeconds = 0, bool $admission = true): void
    {
        $current = ResearchExperimentWorkItem::find($work->id);
        $this->need($current !== null && $current->status === 'leased' && $current->lease_token === $work->lease_token
            && (int) $current->fence_version === (int) $work->fence_version && $current->lease_expires_at
            && $current->lease_expires_at->gt(now()->addSeconds($minimumSeconds)), 'METHOD_STUDY_WORK_LEASE_NOT_CURRENT');
        if ($admission) $this->need($this->admittedContinuationAllowed($work), 'METHOD_STUDY_AUTONOMOUS_MODE_STOPPED');
    }

    /** STOP drains only the already admitted four-request unit; PAUSE/HALT never admit work. */
    public function admittedContinuationAllowed(?ResearchExperimentWorkItem $work): bool
    {
        if (! $work || $work->work_type !== self::WORK_TYPE) return false;
        $mode = app(AutonomousModeService::class)->status($work->symbol, $work->timeframe);
        if (($mode['enabled'] ?? false) === true) return true;
        if (! in_array($mode['state'] ?? null, ['stopped', 'draining'], true)
            || data_get($work->result, 'original_barrier.all_four_originals_ready') !== true) return false;
        $generation = LabGeneration::find((int) data_get($work->result, 'original_barrier.generation_id'));
        return $generation !== null && in_array($generation->status, ['research_reserved', 'screening'], true)
            && data_get($generation->trigger_context, self::MARKER.'.work_item_id') === (int) $work->id
            && data_get($generation->trigger_context, self::MARKER.'.study_hash') === data_get($work->payload, 'method_registration.study_hash');
    }

    /** All four original requests must be compiled and sealed before publishing any arm. */
    public function execute(ResearchExperimentWorkItem $work): array
    {
        $lock = Cache::lock('instrument-method-study-work:'.$work->id, self::WORK_LEASE_SECONDS + 60);
        if (! $lock->get()) return $this->defer($work, 'METHOD_STUDY_WORK_OWNER_BUSY', true);
        try {
            $this->assertLease($work);
            $proof = $this->inspectWork($work->fresh('receipt'));
            $this->need(($proof['executable'] ?? false) === true, $proof['reason_code'] ?? 'METHOD_STUDY_ORIGINAL_READINESS_REQUIRED');
            $generation = app(LabPopulationService::class)->buildInstrumentMethodStudy($work, $proof);
            $requests = []; $hashes = [];
            foreach (self::ARMS as $arm) {
                $agent = $generation->agents()->with('modelVersion')->where('model_version_id', $proof['design']['arms'][$arm]['model_id'])->sole();
                $requests[$arm] = $this->compileRequest($generation, $agent, $arm, $proof);
                $hashes[$arm] = $this->hash($requests[$arm]);
            }
            $barrier = ['generation_id' => (int) $generation->id, 'study_hash' => $proof['study_hash'], 'request_hashes' => $hashes,
                'all_four_originals_ready' => true, ...$this->ceiling()];
            DB::transaction(function () use ($work, $barrier): void {
                $current = ResearchExperimentWorkItem::whereKey($work->id)->lockForUpdate()->firstOrFail(); $this->assertLease($work);
                $prior = data_get($current->result, 'original_barrier');
                $this->need($prior === null || $prior === $barrier, 'METHOD_STUDY_ORIGINAL_PREPARATION_DRIFT');
                if ($prior === null) $current->update(['result' => [...(array) $current->result, 'original_barrier' => $barrier]]);
            });
            $runs = [];
            foreach (self::ARMS as $arm) {
                $checkpoint = data_get($work->fresh()->result, 'original_arms.'.$arm);
                if ($checkpoint !== null) {
                    $run = LabEvaluationRun::where('run_id', $checkpoint['run_id'] ?? null)->sole();
                    if ($run->status !== 'completed') {
                        if (! $run->finished_at && $run->started_at->gt(now()->subSeconds($proof['design']['resource_caps']['max_runtime_seconds'] + 240))) {
                            return $this->defer($work, 'METHOD_STUDY_ORIGINAL_ARM_IN_FLIGHT', false);
                        }
                        return $this->closeTechnical($work, $generation, 'METHOD_STUDY_ORIGINAL_TECHNICAL_ATTEMPT_TERMINAL');
                    }
                    $runs[] = $run->run_id; continue;
                }
                $this->need((int) $work->attempts <= 8, 'METHOD_STUDY_BOUNDED_DELIVERY_BUDGET_EXHAUSTED');
                return $this->queueArm($work, $arm, $hashes[$arm], $proof['design']['resource_caps']['max_runtime_seconds']);
            }
            $assessment = $this->studies->settle($proof['study_hash'], $runs);
            if (($assessment['status'] ?? null) === 'technical_unassessable') {
                // Four immutable products cannot be repaired by selecting a
                // second attempt, nor may they strand this stream forever.
                $reason = (string) ($assessment['reason'] ?? 'METHOD_STUDY_ORIGINAL_ASSESSMENT_REQUIRED');
                if (! preg_match('/^(STUDY|METHOD_STUDY)_[A-Z0-9_]+$/D', $reason)) $reason = 'METHOD_STUDY_ORIGINAL_ASSESSMENT_REQUIRED';
                return $this->closeTechnical($work, $generation, $reason);
            }
            $this->need(in_array($assessment['status'] ?? null, ['assessed_local_diagnostic', 'underpowered_local_diagnostic'], true),
                $assessment['reason'] ?? 'METHOD_STUDY_ORIGINAL_ASSESSMENT_REQUIRED');
            $this->assertLease($work, 0, false);
            foreach ($runs as $id) {
                $original = LabEvaluationRun::where('run_id', $id)->sole();
                $learned = app(AgentMethodLearningService::class)->learnFromRun($original);
                app(AgentMethodGenerationService::class)->proposeFromOriginalRun($original, $learned);
            }
            $done = DB::transaction(function () use ($work, $generation, $proof, $runs, $assessment): bool {
                $this->assertLease($work, 0, false);
                $generation->update(['status' => 'screened', 'completed_at' => now(), 'trigger_context' => [...(array) $generation->trigger_context,
                    'instrument_method_study_terminal' => ['protocol' => self::PROTOCOL, 'study_hash' => $proof['study_hash'],
                        'original_run_ids' => $runs, 'assessment_hash' => $assessment['assessment_hash'],
                        'scientific_status' => $assessment['status'], ...$this->ceiling()]]]);
                return $this->conversion->complete($work, ['status' => 'original_method_matrix_completed', 'generation_id' => (int) $generation->id,
                    'study_hash' => $proof['study_hash'], 'original_run_ids' => $runs, 'assessment' => $assessment, ...$this->ceiling()]);
            });
            return ['status' => $done ? 'completed' : 'stale_lease', 'work_id' => (int) $work->id, ...$this->ceiling()];
        } catch (Throwable $error) { return $this->defer($work, $this->reason($error), false); }
        finally { $lock->release(); }
    }

    public function compileRequest(LabGeneration $generation, LabAgent $agent, string $arm, array $proof): array
    {
        $design = $proof['design']; $model = $agent->modelVersion;
        $this->need(in_array($arm, self::ARMS, true) && $model->id === $design['arms'][$arm]['model_id']
            && $agent->lab_generation_id === $generation->id, 'METHOD_STUDY_ORIGINAL_ARM_OWNER_REQUIRED');
        $this->releases->assertCurrent($generation);
        $execution = app(ExecutionContractService::class)->for($agent->symbol, $design['scope']['timeframe']);
        $scope = [...$design['scope'], 'data_hash' => $design['data_hash'], 'execution_hash' => $design['execution_hash']];
        $family = app(StrategyParameterSchemaService::class)->family(data_get($model->metadata, 'base_strategy', $model->strategy));
        $request = ['symbol' => $agent->symbol, 'timeframe' => $design['scope']['timeframe'], 'strategy' => $model->strategy,
            'base_strategy' => $family, 'parameters' => (array) $model->parameters, 'evaluation_mode' => 'incremental',
            'dataset_path' => $proof['source']['dataset_path'], 'replay_dataset_hash' => $design['data_hash'],
            'initial_balance' => $design['initial_balance'], 'risk_per_trade' => $design['risk_per_trade'],
            'execution' => $execution['parameters'], 'execution_contract' => $execution, 'execution_hash' => $execution['execution_hash'],
            'emit_decision_trace' => true, 'emit_trade_ledger' => true,
            'policy_context' => ['hard_limits' => $design['hard_limits'],
                'instrument_method_study' => ['study_hash' => $proof['study_hash'], 'arm' => $arm, 'resource_caps' => $design['resource_caps']],
                'instrument_method_study_resource_cap' => $design['resource_caps'], 'instrument_method_execution' => [
                    'protocol' => self::PROTOCOL, 'original_source' => $proof['source'], 'research_only' => true,
                    'uses_existing_simple_replay' => true, 'independent_validation' => false, 'promotion_evidence' => false]]];
        // Meta assets verify the seven physical facts of the actual request;
        // compiling the policy first would silently omit that consumer boundary.
        $request['policy_context']['instrument_method_learning'] = $this->bindings->compilePolicy(
            $design['arms'][$arm]['policy_hash'], ['strategy_family' => $family,
                'parameters' => (array) $model->parameters], $scope, 'model:'.$model->id, $request);
        $request = $this->releases->bindGenerationRequest($generation, $request, [(int) $agent->id]);
        if ($design['resource_caps']['max_runtime_seconds'] > 300) {
            $request = $this->bindOriginalDeadline($request, $proof['study_hash'], $arm, $proof['source']);
        }
        return $request;
    }

    private function queueArm(ResearchExperimentWorkItem $work, string $arm, string $requestHash, int $budget): array
    {
        $timeout = DescendantScopedExecutionService::nativeJobTimeout($budget);
        $this->assertLease($work, $timeout + 60);
        $this->need(config('queue.connections.'.config('queue.default').'.driver') !== 'sync', 'METHOD_STUDY_ASYNCHRONOUS_REPLAY_QUEUE_REQUIRED');
        $publish = DB::transaction(function () use ($work, $arm, $requestHash, $budget): bool {
            $current = ResearchExperimentWorkItem::whereKey($work->id)->lockForUpdate()->firstOrFail(); $this->assertLease($work);
            $queue = ['arm' => $arm, 'request_hash' => $requestHash, 'budget' => $budget, 'fence_version' => (int) $work->fence_version];
            $prior = data_get($current->result, 'queued_arm');
            if (is_array($prior) && ($prior['fence_version'] ?? null) === (int) $work->fence_version) {
                $this->need($prior === $queue, 'METHOD_STUDY_QUEUE_FENCE_DRIFT'); return false;
            }
            $current->update(['result' => [...(array) $current->result, 'queued_arm' => $queue]]); return true;
        });
        if ($publish) Bus::dispatch(new ExecuteScopedResearchArmJob((int) $work->id, $work->lease_token,
            (int) $work->fence_version, $arm, $requestHash, $budget));
        return ['status' => 'queued', 'work_id' => (int) $work->id, 'arm' => $arm, 'queue' => 'lab-full-validation', ...$this->ceiling()];
    }

    /** Original worker publication uses the queued fence; there is no second replay attempt. */
    public function executeQueuedArm(int $workId, string $token, int $fence, string $arm, string $requestHash, int $budget): array
    {
        $work = ResearchExperimentWorkItem::with('receipt')->find($workId);
        if (! $work || $work->lease_token !== $token || (int) $work->fence_version !== $fence) return ['status' => 'stale_lease', ...$this->ceiling()];
        $lock = Cache::lock('instrument-method-study-work:'.$workId, self::WORK_LEASE_SECONDS + 60);
        if (! $lock->get()) return $this->defer($work, 'METHOD_STUDY_WORK_OWNER_BUSY', true);
        $run = null; $response = null; $lane = null;
        try {
            $this->assertLease($work, DescendantScopedExecutionService::nativeJobTimeout($budget) + 30);
            $proof = $this->inspectWork($work);
            $this->need(($proof['executable'] ?? false) === true, $proof['reason_code'] ?? 'METHOD_STUDY_ORIGINAL_READINESS_REQUIRED');
            $this->need($budget === $proof['design']['resource_caps']['max_runtime_seconds']
                && data_get($work->result, 'queued_arm') === ['arm' => $arm, 'request_hash' => $requestHash, 'budget' => $budget, 'fence_version' => $fence]
                && data_get($work->result, 'original_barrier.request_hashes.'.$arm) === $requestHash, 'METHOD_STUDY_ORIGINAL_QUEUE_UNIT_REQUIRED');
            $generation = LabGeneration::findOrFail(data_get($work->result, 'original_barrier.generation_id'));
            $agent = $generation->agents()->with('modelVersion')->where('model_version_id', $proof['design']['arms'][$arm]['model_id'])->sole();
            $request = $this->compileRequest($generation, $agent, $arm, $proof);
            $this->need($this->hash($request) === $requestHash, 'METHOD_STUDY_ORIGINAL_REQUEST_DRIFT');
            if (data_get($work->result, 'original_arms.'.$arm) !== null) return $this->defer($work, 'METHOD_STUDY_ORIGINAL_ARM_CANNOT_REPEAT', false);
            $lane = Cache::lock('laravel-queue-overlap:'.config('services.lab_queue.replay_mutex_key', 'neurotrader-ai-heavy-replay'), $budget + 300);
            if (! $lane->get()) return $this->defer($work, 'METHOD_STUDY_SHARED_REPLAY_LANE_BUSY', true);
            $run = DB::transaction(function () use ($work, $agent, $arm, $request, $requestHash): LabEvaluationRun {
                $current = ResearchExperimentWorkItem::whereKey($work->id)->lockForUpdate()->firstOrFail(); $this->assertLease($work);
                $this->need(! LabEvaluationRun::where('model_version_id', $agent->model_version_id)->exists(), 'METHOD_STUDY_ORIGINAL_ARM_REPEAT_FORBIDDEN');
                $run = $this->evidence->beginRun($agent, 'screening', 'incremental', ['source' => self::class, 'data_hash' => $request['replay_dataset_hash']]);
                $run->update(['metadata' => [...(array) $run->metadata, self::MARKER => ['work_id' => (int) $work->id,
                    'study_hash' => data_get($work->payload, 'method_registration.study_hash'), 'arm' => $arm,
                    'projection_withheld' => true, ...$this->ceiling()]]]);
                $this->evidence->attachRequest($run, $request, ['request_id' => 'method-study-'.$run->run_id, 'data_hash' => $request['replay_dataset_hash']]);
                $result = (array) $current->result; $result['original_arms'][$arm] = ['run_id' => $run->run_id,
                    'model_id' => (int) $agent->model_version_id, 'request_hash' => $requestHash];
                $current->update(['result' => $result, 'heartbeat_at' => now()]); $agent->update(['lifecycle_status' => 'screening']);
                return $run;
            });
            $reply = Http::connectTimeout(10)->timeout($budget + 180)->acceptJson()->withHeaders([
                'X-Internal-Token' => (string) config('services.internal_api.token'), 'X-Lab-Request-Id' => 'method-study-'.$run->run_id])
                ->withBody(json_encode($request, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), 'application/json')
                ->post(rtrim((string) config('services.ai_service.url'), '/').'/api/backtest/run');
            if (! $reply->successful()) {
                $failure = $this->transportFailureEnvelope($reply->status(), $reply->body(), $budget);
                $this->evidence->recordArtifact($run, 'method_study_transport_failure', $failure,
                    ['scientific_result' => false, 'reason_code' => 'METHOD_STUDY_ORIGINAL_TRANSPORT_FAILED', ...$this->ceiling()]);
            }
            $this->need($reply->successful() && is_array($reply->json()), 'METHOD_STUDY_ORIGINAL_TRANSPORT_FAILED'); $response = $reply->json();
            $this->need(($this->evidence->replayEvidenceCompleteness($run, $response)['complete'] ?? false) === true
                && app(ExecutionContractService::class)->matches((array) ($response['execution_contract'] ?? []), $agent->symbol, $request['timeframe']),
                'METHOD_STUDY_COMPLETE_ORIGINAL_EVIDENCE_REQUIRED');
            $this->assertLease($work, 0, false);
            // The evidence owner refreshes/locks this row. Do not replace its
            // exact begin marker (including work_id) with a partial close marker.
            $this->evidence->finishIfOpen($run, 'completed', $response);
            $agent->update(['lifecycle_status' => 'screened']);
            return $this->defer($work, 'METHOD_STUDY_NEXT_ORIGINAL_OR_SETTLEMENT', true);
        } catch (Throwable $error) {
            if ($run) {
                try { $this->assertLease($work, 0, false); }
                catch (Throwable) {
                    if ($response !== null) $this->evidence->recordArtifact($run, 'method_study_fence_lost_original_response', $response,
                        ['completed_run_claim' => false, 'reason_code' => 'METHOD_STUDY_WORK_LEASE_NOT_CURRENT', ...$this->ceiling()]);
                    return ['status' => 'stale_lease', ...$this->ceiling()];
                }
                $this->evidence->finishIfOpen($run, 'technical_error', $response, [], ['reason_code' => $this->reason($error),
                    'original_response_retained' => $response !== null, 'projection_withheld' => true, ...$this->ceiling()]);
                return $this->closeTechnical($work, $generation, $this->reason($error));
            }
            return $this->defer($work, $this->reason($error), false);
        } finally { if ($lane) $lane->release(); $lock->release(); }
    }

    /** Exact-byte, entire-source simple replay; never a tail, market-calendar repair or independent window. */
    private function inspectSource(array $source, array $design): array
    {
        $path = $source['dataset_path'] ?? null; $sha = $source['dataset_sha256'] ?? null;
        $this->need(is_string($path) && is_string($sha) && preg_match('/^[a-f0-9]{64}$/D', $sha) === 1
            && ! is_link($path) && ($actual = realpath($path)) !== false && is_file($actual) && filesize($actual) <= 16 * 1024 * 1024,
            'METHOD_STUDY_ORIGINAL_LOCAL_SOURCE_REQUIRED');
        $normalized = str_replace('\\', '/', $actual); $allowed = false;
        foreach ([storage_path('app/lab-datasets'), storage_path('app/private/lab-datasets')] as $root) {
            $resolved = realpath($root);
            if ($resolved !== false && str_starts_with(strtolower($normalized), strtolower(str_replace('\\', '/', $resolved)).'/')) $allowed = true;
        }
        $this->need($allowed && hash_file('sha256', $actual) === $sha && $design['data_hash'] === $sha, 'METHOD_STUDY_ORIGINAL_SOURCE_ROOT_OR_HASH_INVALID');
        $handle = fopen($actual, 'rb'); $this->need($handle !== false, 'METHOD_STUDY_SOURCE_UNREADABLE'); $times = []; $records = []; $previous = null;
        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            $this->need(is_array($header) && count($header) === count(array_unique($header))
                && array_diff(['time', 'open', 'high', 'low', 'close'], $header) === [], 'METHOD_STUDY_SOURCE_COLUMNS_REQUIRED');
            while (($record = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $this->need(count($record) === count($header) && count($times) < min(4096, $design['resource_caps']['max_rows'] + 200), 'METHOD_STUDY_SOURCE_ROW_LIMIT_OR_FORMAT_INVALID');
                $row = array_combine($header, $record);
                $this->need(preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(Z|\+00:00)?$/D', $row['time']) === 1, 'METHOD_STUDY_SOURCE_UTC_CLOCK_REQUIRED');
                $time = CarbonImmutable::parse($row['time'], 'UTC')->utc();
                $this->need($time->lt(app(ResearchPaperEpochContractService::class)->cutoff()) && ($previous === null || $time->gt($previous)), 'METHOD_STUDY_SOURCE_PRE2026_CHRONOLOGY_REQUIRED');
                foreach (['open', 'high', 'low', 'close'] as $key) $this->need(is_numeric($row[$key]) && is_finite((float) $row[$key]) && (float) $row[$key] > 0, 'METHOD_STUDY_SOURCE_REAL_FINITE_PRICES_REQUIRED');
                $this->need($row['high'] >= max($row['open'], $row['close'], $row['low']) && $row['low'] <= min($row['open'], $row['close']), 'METHOD_STUDY_SOURCE_OHLC_INCONSISTENT');
                $times[] = $time->format('Y-m-d\TH:i:s\Z'); $records[] = $record; $previous = $time;
            }
        } finally { fclose($handle); }
        $this->need(count($times) >= 202 && count($times) - 200 === $design['evaluated_rows']
            && CarbonImmutable::parse($design['scope']['evaluated_start'])->equalTo(CarbonImmutable::parse($times[200]))
            && CarbonImmutable::parse($design['scope']['evaluated_end'])->equalTo(CarbonImmutable::parse($times[array_key_last($times)])),
            'METHOD_STUDY_ORIGINAL_EVALUATED_CLOCK_MISMATCH');
        $origin = isset($source['origin']) ? $this->inspectOrigin($source['origin'], $header, $records) : null;
        $this->need(hash_file('sha256', $actual) === $sha, 'METHOD_STUDY_ORIGINAL_SOURCE_CHANGED');
        return ['dataset_path' => $normalized, 'dataset_sha256' => $sha, 'loaded_rows' => count($times), 'warmup_rows' => 200,
            'evaluated_rows' => count($times) - 200, 'loaded_start' => $times[0], 'loaded_end' => $times[array_key_last($times)],
            'selection' => 'entire_exact_source', 'independent_validation' => false,
            ...($origin === null ? [] : ['origin' => $origin])];
    }

    /** A bounded view preserves each original cell, including provider/price/spread metadata. */
    private function inspectOrigin(array $origin, array $header, array $selected): array
    {
        $this->need(count($origin) === 3 && array_diff(array_keys($origin), ['dataset_path', 'dataset_sha256', 'start_row']) === []
            && is_int($origin['start_row'] ?? null) && $origin['start_row'] >= 0 && $origin['start_row'] <= 200000
            && is_string($origin['dataset_path'] ?? null) && is_string($origin['dataset_sha256'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', $origin['dataset_sha256']) === 1,
            'METHOD_STUDY_EXACT_ORIGINAL_VIEW_CUSTODY_REQUIRED');
        $path = $origin['dataset_path']; $actual = realpath($path);
        $this->need(! is_link($path) && $actual !== false && is_file($actual) && filesize($actual) <= 256 * 1024 * 1024,
            'METHOD_STUDY_ORIGINAL_VIEW_SOURCE_REQUIRED');
        $normalized = str_replace('\\', '/', $actual); $allowed = false;
        foreach ([storage_path('app/lab-datasets'), storage_path('app/private/lab-datasets')] as $root) {
            $resolved = realpath($root);
            if ($resolved !== false && str_starts_with(strtolower($normalized), strtolower(str_replace('\\', '/', $resolved)).'/')) $allowed = true;
        }
        $this->need($allowed && hash_file('sha256', $actual) === $origin['dataset_sha256'], 'METHOD_STUDY_ORIGINAL_VIEW_ROOT_OR_HASH_INVALID');
        $handle = fopen($actual, 'rb'); $this->need($handle !== false, 'METHOD_STUDY_ORIGINAL_VIEW_SOURCE_UNREADABLE');
        try {
            $this->need(fgetcsv($handle, 0, ',', '"', '') === $header, 'METHOD_STUDY_ORIGINAL_VIEW_COLUMNS_CHANGED');
            for ($index = 0; $index < $origin['start_row']; $index++) {
                $this->need(fgetcsv($handle, 0, ',', '"', '') !== false, 'METHOD_STUDY_ORIGINAL_VIEW_START_OUTSIDE_SOURCE');
            }
            foreach ($selected as $record) $this->need(fgetcsv($handle, 0, ',', '"', '') === $record, 'METHOD_STUDY_ORIGINAL_VIEW_ROWS_CHANGED');
        } finally { fclose($handle); }
        $this->need(hash_file('sha256', $actual) === $origin['dataset_sha256'], 'METHOD_STUDY_ORIGINAL_VIEW_SOURCE_CHANGED');
        return ['dataset_path' => $normalized, 'dataset_sha256' => $origin['dataset_sha256'], 'start_row' => $origin['start_row']];
    }

    private function closeTechnical(ResearchExperimentWorkItem $work, LabGeneration $generation, string $reason): array
    {
        $done = DB::transaction(function () use ($work, $generation, $reason): bool {
            $this->assertLease($work, 0, false);
            $generation->update(['status' => 'technical_quarantine', 'completed_at' => now(), 'trigger_context' => [...(array) $generation->trigger_context,
                'instrument_method_study_terminal' => ['protocol' => self::PROTOCOL, 'reason_code' => $reason,
                    'original_results_unchanged' => true, ...$this->ceiling()]]]);
            $done = $this->conversion->complete($work, ['status' => 'original_method_matrix_unassessable', 'reason_code' => $reason,
                'generation_id' => (int) $generation->id, 'original_arms' => data_get($work->fresh()->result, 'original_arms', []), ...$this->ceiling()]);
            if ($done) $this->projectTechnicalAgents($generation, $work, $reason);
            return $done;
        });
        return ['status' => $done ? 'completed_technical' : 'stale_lease', 'reason_code' => $reason, ...$this->ceiling()];
    }

    /** A routing hint only. The reconciliation below owns every substantive proof. */
    public function hasTerminalProjection(LabGeneration $generation): bool
    {
        return $generation->status === 'technical_quarantine'
            && data_get($generation->trigger_context, self::MARKER.'.protocol') === self::PROTOCOL;
    }

    /**
     * Repair only an already-spent original matrix's mutable agent projection.
     * Historical requests are reopened, never recompiled under today's source.
     * No work, run, assessment, barrier, scientific budget or credit is rewritten.
     */
    public function reconcileGeneration(LabGeneration $generation): ?array
    {
        if (data_get($generation->trigger_context, self::MARKER) === null) return null;
        $workId = data_get($generation->trigger_context, self::MARKER.'.work_item_id');
        $lock = Cache::lock('instrument-method-study-work:'.$workId, 60);
        $lane = Cache::lock('laravel-queue-overlap:'.config('services.lab_queue.replay_mutex_key', 'neurotrader-ai-heavy-replay'), 60);
        $ownsWork = false; $ownsLane = false;
        try {
            $this->need(is_int($workId) && $workId > 0, 'METHOD_STUDY_TERMINAL_WORK_ID_REQUIRED');
            $this->need($ownsWork = $lock->get(), 'METHOD_STUDY_TERMINAL_WORK_OWNER_BUSY');
            $this->need($ownsLane = $lane->get(), 'METHOD_STUDY_TERMINAL_REPLAY_OWNER_BUSY');
            return DB::transaction(function () use ($generation, $workId): array {
                $work = ResearchExperimentWorkItem::whereKey($workId)->lockForUpdate()->first();
                $current = LabGeneration::whereKey($generation->id)->lockForUpdate()->firstOrFail();
                $this->need($this->hasTerminalProjection($current) && $current->completed_at !== null,
                    'METHOD_STUDY_TERMINAL_GENERATION_REQUIRED');
                $marker = (array) data_get($current->trigger_context, self::MARKER);
                $registration = (array) data_get($work?->payload, 'method_registration', []);
                $this->need($work !== null && $work->work_type === self::WORK_TYPE && $work->status === 'settled'
                    && $work->completed_at !== null && $work->lease_token === null && $work->lease_expires_at === null
                    && data_get($work->payload, 'owner') === ResearchLoopArbiterService::class
                    && data_get($work->payload, 'executor') === ResearchExperimentWorkConsumerService::class
                    && ($registration['protocol'] ?? null) === self::PROTOCOL
                    && $this->hash($registration) === data_get($work->payload, 'method_registration_hash')
                    && ($marker['work_key'] ?? null) === $work->work_key
                    && ($marker['source_receipt_id'] ?? null) === (int) $work->research_experiment_receipt_id
                    && ($marker['study_hash'] ?? null) === ($registration['study_hash'] ?? null)
                    && data_get($work->result, 'status') === 'original_method_matrix_unassessable'
                    && data_get($work->result, 'generation_id') === (int) $current->id
                    && data_get($current->trigger_context, 'instrument_method_study_terminal.protocol') === self::PROTOCOL
                    && preg_match('/^(METHOD_STUDY|STUDY)_[A-Z0-9_]+$/D', (string) data_get($work->result, 'reason_code')) === 1
                    && data_get($work->result, 'reason_code') === data_get($current->trigger_context, 'instrument_method_study_terminal.reason_code'),
                    'METHOD_STUDY_TERMINAL_WORK_BINDING_REQUIRED');
                $receipt = $work->receipt;
                $this->need($receipt !== null && $this->hash((array) data_get($receipt->payload, 'contract')) === $receipt->contract_hash
                    && $this->hash((array) data_get($receipt->payload, 'evidence')) === $receipt->evidence_hash
                    && data_get($receipt->payload, 'evidence.registration_hash') === $this->hash($registration)
                    && data_get($receipt->payload, 'evidence.original_registration') === $registration,
                    'METHOD_STUDY_TERMINAL_RECEIPT_BINDING_REQUIRED');
                foreach ($this->ceiling() as $field => $value) {
                    $this->need(($registration[$field] ?? null) === $value && data_get($work->result, $field) === $value,
                        'METHOD_STUDY_DIAGNOSTIC_AUTHORITY_REQUIRED');
                }
                $design = $this->studies->originalDesign($registration['study_hash']);
                $this->need($work->symbol === $design['scope']['symbol'] && $work->timeframe === 'H1'
                    && (int) $current->ai_laboratory_id === (int) AiLaboratory::where('symbol', $work->symbol)->where('timeframe', $work->timeframe)->value('id')
                    && (int) $current->population_size === 4
                    && (app(ImmutableGenerationContractService::class)->validate($current)['valid'] ?? false),
                    'METHOD_STUDY_TERMINAL_COHORT_REQUIRED');
                $this->assertLaterGenerationsTerminal($current);
                $seal = (array) data_get($current->trigger_context, 'research_release', []); $identity = $seal;
                unset($identity['release_hash'], $identity['sealed_at'], $identity['promotion_evidence']);
                $this->need(($seal['protocol'] ?? null) === ResearchReleaseSealService::PROTOCOL
                    && app(ExecutionContractService::class)->hashParameters($identity) === ($seal['release_hash'] ?? null)
                    && ($seal['source_hash'] ?? null) === ($registration['php_source_hash'] ?? null)
                    && ($seal['python_source_hash'] ?? null) === ($registration['python_source_hash'] ?? null)
                    && ($seal['dataset_hash'] ?? null) === $design['data_hash'], 'METHOD_STUDY_TERMINAL_RELEASE_REQUIRED');
                $unsignedPublisher = null;
                if (isset($seal['source_artifact'])) {
                    $archiveProof = $this->releases->verifySourceArtifact($seal['source_artifact']);
                    $this->need(($archiveProof['status'] ?? null) === 'verified',
                        'METHOD_STUDY_TERMINAL_SOURCE_ARCHIVE_REQUIRED');
                    $unsignedPublisher = $this->historicalUnsignedPublisher($archiveProof, $seal, $registration);
                }
                $agents = $current->agents()->orderBy('id')->lockForUpdate()->get();
                $expected = array_column($design['arms'], 'model_id'); sort($expected);
                $actual = $agents->pluck('model_version_id')->map('intval')->sort()->values()->all();
                $checkpoints = (array) data_get($work->result, 'original_arms', []);
                $actualArms = array_keys($checkpoints); $expectedArms = self::ARMS; sort($actualArms); sort($expectedArms);
                $this->need($actual === $expected && $actualArms !== [] && array_diff($actualArms, $expectedArms) === [],
                    'METHOD_STUDY_TERMINAL_FOUR_ORIGINALS_REQUIRED');
                $partial = count($checkpoints) < 4; $facts = []; $technicalOriginal = false;
                $key = 'instrument_method_study_projection_terminal'; $prior = data_get($current->trigger_context, $key);
                $this->need(! $partial || data_get($work->result, 'queued_arm') === null,
                    'METHOD_STUDY_TERMINAL_UNATTEMPTED_ARM_REQUIRED');
                foreach (self::ARMS as $arm) {
                    $agent = $agents->firstWhere('model_version_id', $design['arms'][$arm]['model_id']);
                    $model = $agent->modelVersion;
                    $this->need($model !== null && $this->hash((array) $model->parameters) === $design['arms'][$arm]['parameter_hash']
                        && $this->hash($this->evidence->modelRuntimeBasis($model)) === $design['arms'][$arm]['model_runtime_basis_hash'],
                        'METHOD_STUDY_ORIGINAL_MODEL_DRIFT');
                    $runs = LabEvaluationRun::where(fn ($query) => $query->where('model_version_id', $agent->model_version_id)
                        ->orWhere('lab_agent_id', $agent->id))->limit(2)->get();
                    if (! array_key_exists($arm, $checkpoints)) {
                        $priorAbsent = is_array($prior) && ($prior['protocol'] ?? null) === self::PROTOCOL
                            && ($prior['work_id'] ?? null) === $workId && ($prior['study_hash'] ?? null) === $registration['study_hash']
                            && in_array($arm, (array) ($prior['unattempted_arms'] ?? []), true);
                        $this->need($runs->isEmpty()
                            && ! \App\Models\LabEvidenceArtifact::where('lab_agent_id', $agent->id)->exists()
                            && ! LabAgent::where('model_version_id', $agent->model_version_id)->where('id', '!=', $agent->id)->exists()
                            && ($agent->lifecycle_status === 'draft' || ($agent->lifecycle_status === 'technical_quarantine'
                                && ($priorAbsent || $this->originalAbsentProjectionEvent($agent, $current, $work)))),
                            'METHOD_STUDY_TERMINAL_UNATTEMPTED_ARM_REQUIRED');
                        $facts[$arm] = ['model_id' => (int) $agent->model_version_id, 'agent_id' => (int) $agent->id, 'original_absent' => true];
                        continue;
                    }
                    $checkpoint = $checkpoints[$arm];
                    $this->need(is_array($checkpoint) && count($checkpoint) === 3
                        && array_diff(array_keys($checkpoint), ['run_id', 'model_id', 'request_hash']) === []
                        && is_string($checkpoint['run_id'] ?? null) && is_int($checkpoint['model_id'] ?? null)
                        && preg_match('/^[a-f0-9]{64}$/D', (string) ($checkpoint['request_hash'] ?? null)) === 1,
                        'METHOD_STUDY_TERMINAL_ORIGINAL_CHECKPOINT_REQUIRED');
                    $this->need($runs->count() === 1, 'METHOD_STUDY_TERMINAL_ONE_ORIGINAL_REQUIRED'); $run = $runs[0];
                    $legacyCompletedOwner = $this->legacyCompletedOwnerShape($run);
                    $this->need($run->run_id === ($checkpoint['run_id'] ?? null) && ($checkpoint['model_id'] ?? null) === (int) $agent->model_version_id
                        && (int) $run->model_version_id === (int) $agent->model_version_id
                        && (int) $run->lab_generation_id === (int) $current->id && (int) $run->lab_agent_id === (int) $agent->id
                        && $run->phase === 'screening' && $run->finished_at !== null && in_array($run->status, ['completed', 'technical_error'], true)
                        && data_get($run->metadata, 'source') === self::class && $run->code_hash === $registration['php_source_hash']
                        && $run->data_hash === $design['data_hash']
                        && (data_get($run->metadata, self::MARKER.'.work_id') === $workId || $legacyCompletedOwner)
                        && data_get($run->metadata, self::MARKER.'.study_hash') === $registration['study_hash']
                        && data_get($run->metadata, self::MARKER.'.arm') === $arm, 'METHOD_STUDY_TERMINAL_ORIGINAL_OWNER_REQUIRED');
                    $requests = \App\Models\LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_request')->limit(2)->get();
                    $responses = \App\Models\LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_response')->limit(2)->get();
                    $this->need($requests->count() === 1 && $responses->count() === 1, 'METHOD_STUDY_TERMINAL_ORIGINAL_ARTIFACTS_REQUIRED');
                    foreach ([$requests[0], $responses[0]] as $artifact) $this->need((int) $artifact->lab_generation_id === (int) $current->id
                        && (int) $artifact->lab_agent_id === (int) $agent->id, 'METHOD_STUDY_TERMINAL_ORIGINAL_OWNER_REQUIRED');
                    $request = $this->evidence->readArtifactPayload($requests[0]); $response = $this->evidence->readArtifactPayload($responses[0]);
                    $this->need(is_array($request) && is_array($response) && $this->hash($request) === ($checkpoint['request_hash'] ?? null)
                        && $this->evidence->hash($request) === $run->request_hash && $this->evidence->hash($response) === $run->response_hash
                        && data_get($request, 'policy_context.instrument_method_study.study_hash') === $registration['study_hash']
                        && data_get($request, 'policy_context.instrument_method_study.arm') === $arm
                        && data_get($request, 'research_release.release_hash') === $seal['release_hash']
                        && data_get($request, 'execution_hash') === $design['execution_hash'], 'METHOD_STUDY_TERMINAL_ORIGINAL_BYTES_REQUIRED');
                    if ($legacyCompletedOwner || $partial) {
                        // Historical settlement discarded original_barrier as
                        // well as completed marker.work_id. Reopen every
                        // checkpoint-bound original request; never manufacture
                        // a retained barrier or recompile under current source.
                        $this->need(data_get($run->metadata, 'research_release_hash') === $seal['release_hash']
                            && ($request['evaluation_mode'] ?? null) === 'incremental'
                            && ($request['symbol'] ?? null) === $design['scope']['symbol']
                            && ($request['timeframe'] ?? null) === $design['scope']['timeframe']
                            && ($request['strategy'] ?? null) === $model->strategy
                            && $this->historicalRequestParametersMatch($request, $model, $registration, $design, $arm, $unsignedPublisher)
                            && ($request['replay_dataset_hash'] ?? null) === $design['data_hash']
                            && $this->evidence->equivalentJsonValue($request['initial_balance'] ?? null, $design['initial_balance'])
                            && $this->evidence->equivalentJsonValue($request['risk_per_trade'] ?? null, $design['risk_per_trade'])
                            && $this->evidence->equivalentJsonValue(data_get($request, 'policy_context.hard_limits'), $design['hard_limits'])
                            && data_get($request, 'execution_contract.execution_hash') === $design['execution_hash']
                            && $this->evidence->equivalentJsonValue($request['execution'] ?? null, $design['execution_costs'])
                            && data_get($request, 'policy_context.instrument_method_learning.policy.policy_hash') === $design['arms'][$arm]['policy_hash']
                            && data_get($request, 'policy_context.instrument_method_execution.protocol') === self::PROTOCOL
                            && data_get($request, 'policy_context.instrument_method_execution.original_source') === $registration['source'],
                            'METHOD_STUDY_TERMINAL_LEGACY_COMPLETED_BINDING_REQUIRED');
                    }
                    $facts[$arm] = ['run_id' => $run->run_id, 'status' => $run->status, 'request_hash' => $run->request_hash,
                        'response_hash' => $run->response_hash, 'request_artifact_hash' => $requests[0]->sha256, 'response_artifact_hash' => $responses[0]->sha256];
                    $technicalOriginal = $technicalOriginal || $run->status === 'technical_error';
                }
                $this->need(! $partial || $technicalOriginal, 'METHOD_STUDY_TERMINAL_PARTIAL_TECHNICAL_ORIGINAL_REQUIRED');
                $this->assertTerminalProjectionIdle($work);
                $projection = ['protocol' => self::PROTOCOL, 'work_id' => $workId, 'study_hash' => $registration['study_hash'],
                    'original_proof_hash' => $this->hash($facts), 'original_results_unchanged' => true, ...$this->ceiling()];
                if ($partial) {
                    $projection['attempted_arms'] = array_values(array_intersect(self::ARMS, array_keys($checkpoints)));
                    $projection['unattempted_arms'] = array_values(array_diff(self::ARMS, array_keys($checkpoints)));
                }
                $this->need($prior === null || $prior === $projection, 'METHOD_STUDY_TERMINAL_PROJECTION_DRIFT');
                $changed = $this->projectTechnicalAgents($current, $work, (string) data_get($work->result, 'reason_code'));
                if ($prior === null) $current->update(['trigger_context' => [...(array) $current->trigger_context, $key => $projection]]);
                return ['status' => 'recorded', 'closed' => $changed > 0 || $prior === null, 'changed_agents' => $changed,
                    'generation_id' => (int) $current->id, 'reason_code' => 'METHOD_STUDY_TERMINAL_PROJECTION_RECONCILED', ...$this->ceiling()];
            });
        } catch (Throwable $error) { return $this->blocked($error) + ['closed' => false]; }
        finally { if ($ownsLane) $lane->release(); if ($ownsWork) $lock->release(); }
    }

    /** A future early technical close has already neutralized unused drafts. */
    private function originalAbsentProjectionEvent(LabAgent $agent, LabGeneration $generation, ResearchExperimentWorkItem $work): bool
    {
        $events = \App\Models\LabLifecycleEvent::where('lab_agent_id', $agent->id)
            ->where('event_type', 'instrument_method_study_terminal_projection')->limit(2)->get();
        if ($events->count() !== 1) return false;
        $event = $events[0];
        $payload = ['reason_code' => data_get($work->result, 'reason_code'), 'work_id' => (int) $work->id,
            'original_results_unchanged' => true, ...$this->ceiling()];
        $actual = $event->payload;
        if (! is_array($actual) || count($actual) !== count($payload)) return false;
        foreach ($payload as $field => $value) {
            // JSON object key order is not evidence. Primitive types and the
            // entire exact key set still belong to the original code owner.
            if (! array_key_exists($field, $actual) || $actual[$field] !== $value) return false;
        }
        return (int) $event->lab_generation_id === (int) $generation->id
            && $event->source === self::class && $event->phase === 'screening' && $event->run_id === null
            && $event->attempt === null && $event->from_status === 'draft' && $event->to_status === 'technical_quarantine'
            && $event->reason_code === $payload['reason_code']
            && $event->error_class === null && $event->error_message === null
            && $event->occurred_at !== null && $event->created_at !== null
            && $generation->completed_at !== null && $work->completed_at !== null
            && $event->occurred_at->greaterThanOrEqualTo($generation->completed_at)
            && $event->occurred_at->greaterThanOrEqualTo($work->completed_at)
            && $event->occurred_at->lessThanOrEqualTo(now())
            && $event->created_at->greaterThanOrEqualTo($work->completed_at)
            && $event->created_at->lessThanOrEqualTo(now());
    }

    /** An older spent projection cannot cross later live or unknown ownership. */
    private function assertLaterGenerationsTerminal(LabGeneration $generation): void
    {
        $later = LabGeneration::where('ai_laboratory_id', $generation->ai_laboratory_id)->where('id', '>', $generation->id)
            ->lockForUpdate()->get(['id', 'status', 'completed_at']);
        $this->need($later->every(fn ($item) => $item->completed_at !== null
            && in_array($item->status, LabPopulationService::TERMINAL_GENERATION_STATUSES, true)),
            'METHOD_STUDY_TERMINAL_LATER_GENERATION_ACTIVE');
        $ids = $later->pluck('id')->all();
        $terminalAgents = ['screened', 'technical_quarantine', 'quarantined', 'legacy_quarantine', 'abandoned', 'failed'];
        $this->need(! LabAgent::whereIn('lab_generation_id', $ids)->where(fn ($query) => $query->whereNull('lifecycle_status')
            ->orWhereNotIn('lifecycle_status', $terminalAgents))->exists(), 'METHOD_STUDY_TERMINAL_LATER_AGENT_ACTIVE');
        $laterAgents = LabAgent::whereIn('lab_generation_id', $ids)->get(['id', 'model_version_id']);
        $this->need(! LabEvaluationRun::where(fn ($query) => $query->whereIn('lab_generation_id', $ids)
            ->orWhereIn('lab_agent_id', $laterAgents->pluck('id')->all())
            ->orWhereIn('model_version_id', $laterAgents->pluck('model_version_id')->all()))->where(fn ($query) => $query->whereNull('finished_at')
            ->orWhereNotIn('status', LabImmutableEvidenceService::TERMINAL_RUN_STATUSES))->exists(),
            'METHOD_STUDY_TERMINAL_LATER_ORIGINAL_ACTIVE');
    }

    /** Only the exact old completed publication shape may omit its owner id. */
    private function legacyCompletedOwnerShape(LabEvaluationRun $run): bool
    {
        $marker = data_get($run->metadata, self::MARKER);
        if (! is_array($marker) || array_key_exists('work_id', $marker)
            || $run->status !== 'completed' || $run->mode !== 'incremental'
            || data_get($run->metadata, 'protocol') !== 'lab_immutable_evidence_v1'
            || data_get($run->metadata, 'source') !== self::class
            || data_get($run->metadata, 'historical') !== false
            || data_get($run->metadata, 'terminal') !== true
            || ($marker['projection_withheld'] ?? null) !== true
            || array_diff(array_keys($marker), ['arm', 'study_hash', 'projection_withheld', ...array_keys($this->ceiling())]) !== []) return false;
        foreach ($this->ceiling() as $field => $value) if (($marker[$field] ?? null) !== $value) return false;
        return true;
    }

    /** Exact verified historical source capability, not a caller or database status flag. */
    private function historicalUnsignedPublisher(array $archiveProof, array $seal, array $registration): ?array
    {
        $reference = (array) ($seal['source_artifact'] ?? []);
        $manifest = (array) ($archiveProof['manifest'] ?? []);
        // A filename contains periods, so use the original manifest key rather
        // than data_get's dotted-path interpretation for the owner descriptor.
        $file = $manifest['files']['backend-laravel/app/Services/InstrumentMethodStudyExecutionService.php'] ?? [];
        if (($archiveProof['status'] ?? null) !== 'verified'
            || ($reference['protocol'] ?? null) !== 'research_source_artifact_reference_v1'
            || ($archiveProof['artifact_hash'] ?? null) !== ($reference['artifact_hash'] ?? null)
            || ($manifest['protocol'] ?? null) !== ResearchReleaseSealService::SOURCE_ARTIFACT_PROTOCOL
            || ($file['sha256'] ?? null) !== self::UNSIGNED_ORIGINAL_PUBLISHER_SHA256
            || ($file['bytes'] ?? null) !== self::UNSIGNED_ORIGINAL_PUBLISHER_BYTES) return null;
        foreach (['source_hash' => 'php_source_hash', 'python_source_hash' => 'python_source_hash'] as $field => $registered) {
            $value = $seal[$field] ?? null;
            if (! is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1
                || ($reference[$field] ?? null) !== $value || data_get($manifest, 'source_identity.'.$field) !== $value
                || ($registration[$registered] ?? null) !== $value) return null;
        }
        return $seal;
    }

    /** Pure comparison with original wire; no request/artifact or signature is published. */
    private function historicalRequestParametersMatch(array $request, ModelVersion $model, array $registration, array $design, string $arm,
        ?array $unsignedPublisher = null): bool
    {
        try {
            $policy = (array) ($request['policy_context'] ?? []);
            if ($design['resource_caps']['max_runtime_seconds'] <= 300) {
                return ! array_key_exists(self::DEADLINE_MARKER, $policy)
                    && $this->hash((array) ($request['parameters'] ?? [])) === $design['arms'][$arm]['parameter_hash'];
            }
            if (! array_key_exists(self::DEADLINE_MARKER, $policy)) {
                return $unsignedPublisher !== null
                    && $this->hash((array) ($request['research_release'] ?? [])) === $this->hash($unsignedPublisher)
                    && ($unsignedPublisher['source_hash'] ?? null) === ($registration['php_source_hash'] ?? null)
                    && ($unsignedPublisher['python_source_hash'] ?? null) === ($registration['python_source_hash'] ?? null)
                    && ($policy['instrument_method_study_resource_cap'] ?? null) === $design['resource_caps']
                    && data_get($policy, 'instrument_method_study.resource_caps') === $design['resource_caps']
                    && $this->hash((array) ($request['parameters'] ?? [])) === $design['arms'][$arm]['parameter_hash'];
            }
            if (! is_array($policy[self::DEADLINE_MARKER] ?? null)
                || ($policy['instrument_method_study_resource_cap'] ?? null) !== $design['resource_caps']) return false;
            // Only the original owner's schema-numeric int->float spelling is
            // admissible. Integer/bool fields, values, old policy/release/source
            // and the issuer signature must all match this pure rederivation.
            $expected = $request; $expected['parameters'] = (array) $model->parameters;
            unset($expected['policy_context'][self::DEADLINE_MARKER]);
            $expected = $this->bindOriginalDeadline($expected, $registration['study_hash'], $arm, $registration['source']);
            return $this->hash((array) ($request['parameters'] ?? [])) === $this->hash($expected['parameters'])
                && $this->hash($policy[self::DEADLINE_MARKER]) === $this->hash($expected['policy_context'][self::DEADLINE_MARKER]);
        } catch (Throwable) { return false; }
    }

    private function assertTerminalProjectionIdle(ResearchExperimentWorkItem $work): void
    {
        $control = app(AutonomousModeService::class)->status($work->symbol, $work->timeframe);
        $this->need(($control['enabled'] ?? true) === false && in_array($control['state'] ?? null, ['stopped', 'draining'], true),
            'METHOD_STUDY_TERMINAL_OPERATOR_STOP_REQUIRED');
        $this->need(! ResearchExperimentWorkItem::where('symbol', $work->symbol)->where('timeframe', $work->timeframe)
            ->where('status', 'leased')->where('lease_expires_at', '>', now())->exists(), 'METHOD_STUDY_TERMINAL_ACTIVE_WORK_LEASE');
        $queues = array_values(array_unique(['scheduler-constructor', 'lab-full-validation',
            (string) config('services.lab_queue.full_validation_queue', 'lab-full-validation'),
            (string) config('services.lab_queue.learning_queue', 'lab-learning'),
            (string) config('services.lab_queue.screening_queue', 'lab-screening'),
            (string) config('services.lab_queue.frontier_queue', 'lab-frontier'),
            ...((array) config('services.lab_queue.legacy_screening_queues', []))]));
        $snapshot = app(LabQueueJobInspector::class)->queueSnapshot($queues);
        $this->need(($snapshot['available'] ?? true) === true && ($snapshot['total'] ?? null) === 0,
            'METHOD_STUDY_TERMINAL_QUEUE_IDLE_REQUIRED');
        $probe = app(ReplayLivenessProbeService::class)->probe();
        $this->need(($probe['source'] ?? null) === 'replay-status' && ($probe['status'] ?? null) === 'ok'
            && ($probe['active_requests'] ?? null) === 0 && ($probe['screening_active'] ?? 0) === 0 && ($probe['full_active'] ?? 0) === 0,
            'METHOD_STUDY_TERMINAL_AUTHENTICATED_REPLAY_IDLE_REQUIRED');
    }

    private function projectTechnicalAgents(LabGeneration $generation, ResearchExperimentWorkItem $work, string $reason): int
    {
        $design = $this->studies->originalDesign((string) data_get($work->payload, 'method_registration.study_hash'));
        $agents = $generation->agents()->orderBy('id')->lockForUpdate()->get();
        $actual = $agents->pluck('model_version_id')->map('intval')->sort()->values()->all();
        $expected = array_column($design['arms'], 'model_id'); sort($expected);
        $this->need($actual === $expected, 'METHOD_STUDY_TERMINAL_COHORT_REQUIRED');
        $changed = 0;
        foreach ($agents as $agent) {
            if (! in_array($agent->lifecycle_status, ['draft', 'queued', 'screening', 'evaluation_error'], true)) continue;
            $runs = LabEvaluationRun::where('model_version_id', $agent->model_version_id)->limit(2)->get();
            $this->need($runs->count() <= 1, 'METHOD_STUDY_TERMINAL_ONE_ORIGINAL_REQUIRED'); $run = $runs->first();
            $this->need($run === null || ((int) $run->lab_generation_id === (int) $generation->id
                && (int) $run->lab_agent_id === (int) $agent->id && $run->finished_at !== null
                && in_array($run->status, ['completed', 'technical_error'], true)), 'METHOD_STUDY_TERMINAL_ORIGINAL_OWNER_REQUIRED');
            $target = $run?->status === 'completed' ? 'screened' : 'technical_quarantine'; $from = $agent->lifecycle_status;
            $agent->update(['lifecycle_status' => $target, 'decision_reason' => 'Original method study terminal projection; no quality verdict, replay or authority.']);
            $this->evidence->recordLifecycle($agent, 'instrument_method_study_terminal_projection', [
                'reason_code' => $reason, 'work_id' => (int) $work->id, 'original_results_unchanged' => true, ...$this->ceiling()],
                'screening', $run?->run_id, null, self::class, null, $from, $target);
            $changed++;
        }
        return $changed;
    }
    private function defer(ResearchExperimentWorkItem $work, string $reason, bool $retryable): array
    {
        $done = $this->conversion->defer($work, $reason, $retryable);
        return ['status' => $done ? ($retryable ? 'deferred' : 'blocked') : 'stale_lease', 'reason_code' => $reason, ...$this->ceiling()];
    }
    /** Future original simple requests only; this never changes an old barrier. */
    private function bindOriginalDeadline(array $request, string $studyHash, string $arm, array $source): array
    {
        // The simple API casts only schema-numeric physical fields to floats
        // before evaluation. Publish that exact future wire spelling before
        // signing; original model/method seals and all values stay unchanged.
        $schema = app(StrategyParameterSchemaService::class)->schema($request['base_strategy']);
        foreach ($request['parameters'] as $field => $value) {
            if (($schema[$field][0] ?? null) === 'numeric') {
                $this->need((is_int($value) || is_float($value)) && is_finite((float) $value),
                    'METHOD_STUDY_SIGNED_DEADLINE_PHYSICAL_NUMBER_REQUIRED');
                $request['parameters'][$field] = (float) $value;
            }
        }
        $caps = data_get($request, 'policy_context.instrument_method_study_resource_cap');
        $this->need(is_array($caps) && is_int($caps['max_runtime_seconds'] ?? null)
            && $caps['max_runtime_seconds'] > 300 && $caps['max_runtime_seconds'] <= 600
            && is_int($caps['max_rows'] ?? null) && $caps['max_rows'] >= 201 && $caps['max_rows'] <= 4096
            && ($source['loaded_rows'] ?? 4097) <= $caps['max_rows'], 'METHOD_STUDY_SIGNED_DEADLINE_CAPS_REQUIRED');
        $key = (string) config('services.internal_api.token');
        $this->need(strlen($key) >= 32, 'METHOD_STUDY_SIGNED_DEADLINE_PRIVATE_KEY_REQUIRED');
        $learning = data_get($request, 'policy_context.instrument_method_learning');
        $this->need(is_array($learning) && is_string($learning['contract_hash'] ?? null)
            && is_array($learning['scope'] ?? null), 'METHOD_STUDY_SIGNED_DEADLINE_METHOD_REQUIRED');
        $body = ['protocol' => self::DEADLINE_PROTOCOL, 'operation' => 'simple', 'study_hash' => $studyHash, 'arm' => $arm,
            'resource_caps' => $caps, 'symbol' => $request['symbol'], 'timeframe' => $request['timeframe'],
            'strategy' => $request['strategy'], 'base_strategy' => $request['base_strategy'],
            'parameters_hash' => $this->hash((array) $request['parameters']), 'data_hash' => $request['replay_dataset_hash'],
            'execution_hash' => data_get($request, 'execution_contract.execution_hash'), 'method_contract_hash' => $learning['contract_hash'],
            'initial_balance' => (float) $request['initial_balance'], 'risk_per_trade' => (float) $request['risk_per_trade'],
            'scope' => $learning['scope'], 'source_identity' => $source,
            'policy_context_hash' => $this->hash((array) $request['policy_context']),
            'research_release_hash' => $this->hash((array) ($request['research_release'] ?? [])),
            'runtime_controls_hash' => $this->hash(['random_seed' => $request['random_seed'] ?? 42,
                'signal_delay_candles' => $request['signal_delay_candles'] ?? 0,
                'emit_decision_trace' => $request['emit_decision_trace'] ?? false, 'emit_trade_ledger' => $request['emit_trade_ledger'] ?? false]),
            'research_only' => true, 'independent_validation' => false, 'economic_authority' => false,
            'promotion_evidence' => false, 'paper_authority' => false, 'live_authority' => false];
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) return $value;
            if (! array_is_list($value)) ksort($value);
            foreach ($value as $name => $item) $value[$name] = $canonical($item);
            return $value;
        };
        $encoded = json_encode($canonical($body), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $encoded);
        $request['policy_context'][self::DEADLINE_MARKER] = [...$body, 'contract_json' => $encoded,
            'contract_hash' => $hash, 'issuer_signature' => hash_hmac('sha256', self::DEADLINE_PROTOCOL."\n".$hash, $key)];
        return $request;
    }

    /** No raw response, token, server trace or arbitrary detail is persisted. */
    public function transportFailureEnvelope(int $status, string $body, int $declaredBudget): array
    {
        $seconds = null;
        if ($status === 504 && strlen($body) <= 8192) {
            try { $decoded = json_decode($body, true, 8, JSON_THROW_ON_ERROR); }
            catch (Throwable) { $decoded = null; }
            if (is_array($decoded) && is_string($decoded['detail'] ?? null)
                && preg_match('/^Bounded AI replay exceeded ([1-9][0-9]{0,3})s; strategy verdict withheld\.$/D', $decoded['detail'], $match) === 1
                && (int) $match[1] <= 1680) $seconds = (int) $match[1];
        }
        return ['protocol' => 'instrument_method_transport_failure_v1', 'http_status' => $status,
            'body_sha256' => hash('sha256', $body), 'body_bytes' => strlen($body), 'operation' => 'simple',
            'detail_code' => $seconds === null ? 'unclassified_non_success_response' : 'bounded_replay_timeout',
            'observed_timeout_seconds' => $seconds, 'declared_budget_seconds' => $declaredBudget,
            'deadline_mismatch_observed' => $seconds !== null && $seconds !== $declaredBudget,
            'raw_body_retained' => false, 'strategy_verdict_withheld' => true, ...$this->ceiling()];
    }

    private function blocked(Throwable $error): array { return ['status' => 'blocked', 'executable' => false, 'reason_code' => $this->reason($error), ...$this->ceiling()]; }
    private function reason(Throwable $error): string { return preg_match('/^(METHOD_STUDY|STUDY|RESEARCH_RELEASE)_[A-Z0-9_]+$/D', $error->getMessage()) ? $error->getMessage() : 'METHOD_STUDY_ORIGINAL_OWNER_UNAVAILABLE'; }
    private function need(bool $condition, string $reason): void { if (! $condition) throw new LogicException($reason); }
    private function hash(array $value): string { return app(ResearchPaperEpochContractService::class)->parameterHash($value); }
    private function ceiling(): array { return ['research_only' => true, 'independent_validation' => false, 'economic_authority' => false,
        'confirmation_proven' => false, 'mastery_proven' => false, 'skill_credit' => false, 'promotion_evidence' => false, 'paper_authority' => false]; }
}
