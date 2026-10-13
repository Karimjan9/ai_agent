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
                // The canonical execution envelope's promotion_evidence label
                // describes cost-policy usability, not model trading authority.
                // Its exact parameters are independently checked below. Do not
                // copy that envelope into the generic candidate authority test.
                $plain = clone $model; $plainMetadata = (array) $model->metadata;
                unset($plainMetadata['execution_contract']); $plain->metadata = $plainMetadata;
                app(AgentMethodCandidateService::class)->baseline($plain);
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
        $learning = $this->bindings->compilePolicy($design['arms'][$arm]['policy_hash'], ['strategy_family' => $family,
            'parameters' => (array) $model->parameters], $scope, 'model:'.$model->id);
        $request = ['symbol' => $agent->symbol, 'timeframe' => $design['scope']['timeframe'], 'strategy' => $model->strategy,
            'base_strategy' => $family, 'parameters' => (array) $model->parameters, 'evaluation_mode' => 'incremental',
            'dataset_path' => $proof['source']['dataset_path'], 'replay_dataset_hash' => $design['data_hash'],
            'initial_balance' => $design['initial_balance'], 'risk_per_trade' => $design['risk_per_trade'],
            'execution' => $execution['parameters'], 'execution_contract' => $execution, 'execution_hash' => $execution['execution_hash'],
            'emit_decision_trace' => true, 'emit_trade_ledger' => true,
            'policy_context' => ['instrument_method_learning' => $learning, 'hard_limits' => $design['hard_limits'],
                'instrument_method_study' => ['study_hash' => $proof['study_hash'], 'arm' => $arm, 'resource_caps' => $design['resource_caps']],
                'instrument_method_study_resource_cap' => $design['resource_caps'], 'instrument_method_execution' => [
                    'protocol' => self::PROTOCOL, 'original_source' => $proof['source'], 'research_only' => true,
                    'uses_existing_simple_replay' => true, 'independent_validation' => false, 'promotion_evidence' => false]]];
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
            $this->evidence->finishIfOpen($run, 'completed', $response, [], [self::MARKER => ['arm' => $arm,
                'study_hash' => $proof['study_hash'], 'projection_withheld' => true, ...$this->ceiling()]]);
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
            return $this->conversion->complete($work, ['status' => 'original_method_matrix_unassessable', 'reason_code' => $reason,
                'generation_id' => (int) $generation->id, 'original_arms' => data_get($work->fresh()->result, 'original_arms', []), ...$this->ceiling()]);
        });
        return ['status' => $done ? 'completed_technical' : 'stale_lease', 'reason_code' => $reason, ...$this->ceiling()];
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
