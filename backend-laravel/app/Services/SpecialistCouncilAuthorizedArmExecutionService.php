<?php

namespace App\Services;

use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningSettlement;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use App\Models\ResearchExperimentWorkItem;
use App\Models\SpecialistCouncilVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use Throwable;

/**
 * One original full arm, never the ordinary training/champion evaluator.
 * The existing arbiter/work lease and shared replay lane remain the owners.
 */
class SpecialistCouncilAuthorizedArmExecutionService
{
    public const PROTOCOL = 'specialist_council_authorized_arm_execution_v1';
    public const OWNER_PROTOCOL = 'specialist_council_authorized_panel_v1';
    public const HTTP_SECONDS = 600;

    public function __construct(
        private LabImmutableEvidenceService $evidence,
        private SpecialistCouncilLifecycleService $lifecycle,
        private SpecialistCouncilContractService $contracts,
        private ResearchPaperEpochContractService $epochs,
        private ResearchReleaseSealService $releases,
        private InstrumentResearchWindowService $windows,
        private LabAgentEvaluationService $compiler,
        private LearningKernelService $learning,
        private LabGenerationTerminalBoundaryService $terminal,
    ) {}

    /**
     * Server-derived request, usable for prospective hashing before dispatch.
     * No caller request/inline data/authority flag participates in compilation.
     */
    public function compileRequest(LabGeneration $cohort, array $unit, ResearchExperimentWorkItem $work): array
    {
        app(SpecialistCouncilPanelReservationService::class)->assertExecutionUnit($work, $cohort, $unit);
        $basis = $this->basis($cohort, $unit, $work);
        $agent = $basis['agent']; $model = $agent->modelVersion;
        $plan = $basis['plan']; $window = $basis['window'];
        $manifest = (array) data_get($cohort->trigger_context, 'mtf_bundle_manifest', []);
        if (($manifest['bundle_hash'] ?? '') !== $window['dataset_sha256']
            || ! is_array($manifest['streams'] ?? null)
            || array_diff(['M5', 'M15', 'H1', 'H4'], array_keys($manifest['streams'])) !== []) {
            throw new LogicException('COUNCIL_ARM_AUTHORIZED_ORIGINAL_MTF_BUNDLE_REQUIRED');
        }
        $paths = [];
        foreach ($manifest['streams'] as $timeframe => $record) {
            if (! is_array($record) || ! is_string($record['path'] ?? null)) {
                throw new LogicException('COUNCIL_ARM_ORIGINAL_STREAM_PATH_REQUIRED');
            }
            $paths[$timeframe] = $record['path'];
        }
        $timeframe = $plan['execution_timeframe'];
        if (! isset($paths[$timeframe])) throw new LogicException('COUNCIL_ARM_ORIGINAL_EXECUTION_STREAM_MISSING');
        $bundle = ['bundle_hash' => $manifest['bundle_hash'], 'manifest' => $manifest,
            'entry_dataset_path' => $paths[$timeframe], 'dataset_paths' => $paths];
        $execution = app(ExecutionContractService::class)->for($agent->symbol, $timeframe);
        if ($execution['execution_hash'] !== $plan['execution_hash']
            || ! $this->evidence->equivalentJsonValue($execution['parameters'], $plan['cost_model'])) {
            throw new LogicException('COUNCIL_ARM_ORIGINAL_COST_POLICY_CHANGED');
        }
        if (data_get($model->metadata, 'specialist_council') === null) {
            $strategy = $this->compiler->specialistCouncilMemberPayload(
                $model, $timeframe, $bundle, $window['dataset_sha256'], $agent->symbol);
            $strategy['lab_agent_id'] = (int) $agent->id;
            if (isset($basis['arm']['standalone_source'])) {
                $strategy['specialist_council_contract'] = $this->lifecycle->runtimeContractForModel(
                    $model, $timeframe, $window['dataset_sha256'], $execution['execution_hash'], $bundle, $agent->symbol);
            }
        } else {
            $assignment = (array) data_get($model->metadata, 'instrument_research_assignment', []);
            $strategy = [
                'lab_agent_id' => (int) $agent->id, 'strategy' => $model->strategy,
                'base_strategy' => app(StrategyParameterSchemaService::class)->runtimeBaseStrategy(
                    $model->strategy, data_get($model->metadata, 'base_strategy'), $agent->strategy_family),
                'version' => $model->version, 'parameters' => (array) $model->parameters,
                'instrument_research_assignment' => $assignment,
                'composition_runtime_contract' => $this->compiler->compositionRuntimeContract(
                    $agent, $assignment, $timeframe, $bundle, $window['dataset_sha256']),
                'specialist_context_contract' => (object) (array) data_get($model->metadata, 'specialist_council_membership.contextual_cell', []),
                'specialist_council_contract' => $this->lifecycle->runtimeContractForModel(
                    $model, $timeframe, $window['dataset_sha256'], $execution['execution_hash'], $bundle, $agent->symbol),
            ];
        }
        $strategy['specialist_council_evaluation'] = $basis['binding'];
        $qualification = $this->lifecycle->standaloneQualificationDeclarationForModel($model, $window['dataset_sha256']);
        if ($qualification !== null) $strategy['native_standalone_qualification'] = $qualification;
        $armPolicy = [
            'protocol' => self::PROTOCOL, 'generation_id' => (int) $cohort->id,
            'work_item_id' => (int) $work->id, 'reservation_hash' => $basis['owner']['reservation_hash'],
            'arm_key' => $unit['arm_key'], 'window_key' => $unit['window_key'],
            'plan_hash' => $unit['plan_hash'], 'model_hash' => $unit['model_hash'],
            'evaluation_scope' => $basis['arm']['evaluation_scope'],
            'research_only' => true, 'promotion_evidence' => false,
        ];
        $armPolicy['contract_hash'] = $this->epochs->parameterHash($armPolicy);
        $request = [
            'symbol' => $agent->symbol, 'timeframe' => $timeframe, 'strategy' => 'all',
            'evaluation_mode' => 'full', 'strategies' => [$strategy],
            'dataset_path' => $paths[$timeframe], 'replay_dataset_hash' => $window['dataset_sha256'],
            'mtf_dataset_paths' => $paths, 'mtf_snapshot_manifest' => $manifest,
            'execution' => $execution['parameters'], 'execution_contract' => $execution,
            'initial_balance' => $plan['initial_capital'], 'risk_per_trade' => $plan['risk_policy']['risk_per_trade_percent'],
            'volume_context' => ['status' => 'not_requested', 'enabled' => false, 'promotion_evidence' => false],
            'emit_decision_trace' => true, 'policy_context' => [
                'full_replay_runtime_policy' => $plan['full_replay_runtime_policy'],
                'specialist_council_authorized_arm' => $armPolicy,
            ],
        ];
        $request = $this->lifecycle->bindEvaluationRequestForModel($model, $request);
        $request = $this->releases->bindGenerationRequest($cohort, $request, [(int) $agent->id]);
        // Binding must issue the REAL original authorization, not silently
        // retain the ordinary unsigned historical route.
        $transport = data_get($request, 'policy_context.authorized_research_transport');
        if (! is_array($transport) || ($transport['protocol'] ?? null) !== InstrumentResearchWindowService::TRANSPORT_PROTOCOL
            || data_get($transport, 'window.window_key') !== $unit['window_key']
            || ($transport['dataset_hash'] ?? null) !== $window['dataset_sha256']
            || ($transport['release_hash'] ?? null) !== data_get($cohort->trigger_context, 'research_release.release_hash')) {
            throw new LogicException('COUNCIL_ARM_ACTUAL_AUTHORIZED_FULL_TRANSPORT_REQUIRED');
        }
        return $request;
    }

    /** Execute at most ONE arm; this does not complete the containing work. */
    public function execute(LabGeneration $cohort, array $unit, ResearchExperimentWorkItem $work,
        string $worker, string $leaseToken, int $leaseFence): array
    {
        $this->assertLease($work, $worker, $leaseToken, $leaseFence);
        app(SpecialistCouncilPanelReservationService::class)->assertNativeExecutionBarrier($work, $cohort, $unit);
        app(SpecialistCouncilPanelReservationService::class)->assertExecutionUnit($work, $cohort, $unit);
        $basis = $this->basis($cohort, $unit, $work);
        $existing = data_get($work->fresh()->result, 'panel_units.'.$unit['arm_key']);
        if (is_array($existing)) {
            $run = $this->originalCheckpoint($existing, $cohort, $unit, $work);
            return $run->finished_at === null
                ? $this->waiting($run, 'ORIGINAL_COUNCIL_ARM_STILL_IN_FLIGHT')
                : $this->projectTerminal($cohort, $unit, $work, $run, $worker, $leaseToken, $leaseFence);
        }
        $lane = Cache::lock('laravel-queue-overlap:'.(string) config(
            'services.lab_queue.replay_mutex_key', 'neurotrader-ai-heavy-replay'), self::HTTP_SECONDS + 120);
        if (! $lane->get()) return $this->blocked('COUNCIL_ARM_SHARED_REPLAY_LANE_BUSY');
        $run = null; $result = null; $dataDependency = false;
        try {
            if (! app(AutonomousModeService::class)->enabled($work->symbol, $work->timeframe)) {
                throw new LogicException('AUTONOMOUS_MODE_STOPPED');
            }
            $request = $this->compileRequest($cohort, $unit, $work);
            if (($unit['request_hash'] ?? null) !== $this->evidence->hash($request)) {
                throw new LogicException('COUNCIL_ARM_PREREGISTERED_REQUEST_HASH_DRIFT');
            }
            $this->assertLease($work, $worker, $leaseToken, $leaseFence, self::HTTP_SECONDS + 30);
            $health = $this->health($cohort);
            $created = false;
            $run = DB::transaction(function () use ($cohort, $unit, $work, $worker, $leaseToken, $leaseFence, $request, $basis, $health, &$created) {
                $locked = ResearchExperimentWorkItem::whereKey($work->id)->lockForUpdate()->firstOrFail();
                $this->assertLease($locked, $worker, $leaseToken, $leaseFence, self::HTTP_SECONDS + 30);
                $result = (array) $locked->result;
                if (isset($result['panel_units'][$unit['arm_key']])) {
                    return $this->originalCheckpoint($result['panel_units'][$unit['arm_key']], $cohort, $unit, $locked);
                }
                $agent = LabAgent::whereKey($unit['lab_agent_id'])->lockForUpdate()->firstOrFail();
                if (! in_array($agent->lifecycle_status, ['draft', 'full_queued'], true)
                    || LabEvaluationRun::where('lab_agent_id', $agent->id)->exists()) {
                    throw new LogicException('COUNCIL_ARM_ORIGINAL_UNUSED_AGENT_REQUIRED');
                }
                // Checkpoint publication and beginRun are inseparable: a crash
                // can never open a second physical attempt on redelivery.
                $run = $this->evidence->beginRun($agent, 'full_validation', 'full', [
                    'source' => self::class, 'queue' => config('services.lab_queue.learning_queue', 'lab-learning'),
                    'data_hash' => $request['replay_dataset_hash'],
                ]);
                $requestHash = $this->evidence->hash($request);
                $run->update(['metadata' => [...(array) $run->metadata, 'council_panel' => [
                    'protocol' => self::PROTOCOL, 'work_item_id' => (int) $locked->id,
                    'reservation_hash' => $basis['owner']['reservation_hash'], 'arm_key' => $unit['arm_key'],
                    'plan_hash' => $unit['plan_hash'], 'window_key' => $unit['window_key'],
                ]]]);
                $this->evidence->attachRequest($run, $request, ['request_id' => 'council-arm-'.$run->run_id,
                    'request_hash' => $requestHash, 'data_hash' => $request['replay_dataset_hash']]);
                $this->evidence->recordArtifact($run, 'ai_health_preflight', $health, ['promotion_evidence' => false]);
                $result['panel_units'][$unit['arm_key']] = [
                    'protocol' => self::PROTOCOL, 'run_id' => $run->run_id, 'run_row_id' => (int) $run->id,
                    'generation_id' => (int) $cohort->id, 'agent_id' => (int) $agent->id,
                    'model_version_id' => (int) $agent->model_version_id, 'arm_key' => $unit['arm_key'],
                    'plan_hash' => $unit['plan_hash'], 'window_key' => $unit['window_key'],
                    'reservation_hash' => $basis['owner']['reservation_hash'], 'request_hash' => $requestHash,
                    'release_hash' => data_get($cohort->trigger_context, 'research_release.release_hash'),
                    'status' => 'started', 'promotion_evidence' => false,
                ];
                $locked->update(['result' => $result, 'heartbeat_at' => now()]);
                $agent->update(['lifecycle_status' => 'full_validation']);
                $cohort->update(['status' => 'full_validation', 'started_at' => $cohort->started_at ?? now()]);
                $created = true;
                return $run;
            });
            // Another original publisher won inside the same lease.
            if ($run->finished_at !== null) {
                return $this->projectTerminal($cohort, $unit, $work, $run, $worker, $leaseToken, $leaseFence);
            }
            if (! $created) return $this->waiting($run, 'ORIGINAL_COUNCIL_ARM_STILL_IN_FLIGHT');
            $this->assertLease($work, $worker, $leaseToken, $leaseFence, self::HTTP_SECONDS + 15);
            $response = Http::connectTimeout(10)->timeout(self::HTTP_SECONDS)->acceptJson()->withHeaders([
                'X-Internal-Token' => (string) config('services.internal_api.token'),
                'X-Lab-Request-Id' => 'council-arm-'.$run->run_id,
            ])->post(rtrim((string) config('services.ai_service.url'), '/').'/api/backtest/run-all', $request);
            if ($response->failed()) throw new LogicException('COUNCIL_ARM_TRANSPORT_HTTP_'.$response->status());
            $body = $response->json();
            $items = (array) ($body['leaderboard'] ?? []);
            if (count($items) !== 1 || ! is_array($items[0]['result'] ?? null)
                || ($items[0]['strategy'] ?? null) !== $basis['agent']->modelVersion->strategy) {
                throw new LogicException('COUNCIL_ARM_SINGLE_ORIGINAL_RESULT_REQUIRED');
            }
            $result = $items[0]['result'];
            $native = $this->lifecycle->attestReplayResult($basis['agent']->modelVersion, $request, $result);
            if (is_array($native) && ($native['status'] ?? null) === 'dependency') {
                $dataDependency = true;
                throw new LogicException('COUNCIL_ARM_NATIVE_DATA_DEPENDENCY');
            }
            $this->lifecycle->assertOriginalArmScope($basis['arm'], $request, $result, $basis['plan']['execution_timeframe']);
            if (! app(ExecutionContractService::class)->matches((array) ($result['execution_contract'] ?? []),
                $basis['agent']->symbol, $basis['plan']['execution_timeframe'])) {
                throw new LogicException('COUNCIL_ARM_ORIGINAL_EXECUTION_RECEIPT_MISMATCH');
            }
            $complete = $this->evidence->replayEvidenceCompleteness($run, $result);
            if (($complete['complete'] ?? false) !== true) throw new LogicException(
                'COUNCIL_ARM_IMMUTABLE_EVIDENCE_INCOMPLETE:'.implode(',', $complete['reason_codes']));
            app(SpecialistCouncilDataUseService::class)->recordReplayUse(
                $basis['version'], [...$request, 'specialist_council_evaluation' => $basis['binding']], $run->run_id, $native);
            $this->evidence->finishIfOpen($run, 'completed', $result, [], [
                'research_only' => true, 'economic_skill_proven' => false, 'promotion_evidence' => false,
            ]);
        } catch (Throwable $error) {
            // Original evidence publication may outlive the operational lease,
            // but it may finish only this already checkpointed run. No retry,
            // fake result or later favorable attempt substitutes for it.
            if ($run !== null && $run->fresh()->finished_at === null) {
                $this->evidence->finishIfOpen($run, 'technical_error', $result, [], [
                    'reason_code' => $error instanceof LogicException ? $error->getMessage() : 'COUNCIL_ARM_TRANSPORT_TECHNICAL_FAILURE',
                    'scientific_disposition' => $dataDependency ? 'data_missing' : 'technical_unassessable',
                    'research_only' => true, 'promotion_evidence' => false,
                ], $error);
            }
            if ($run === null) return $this->blocked($error instanceof LogicException ? $error->getMessage() : 'COUNCIL_ARM_PREREQUISITE_UNAVAILABLE');
        } finally { $lane->release(); }
        return $this->projectTerminal($cohort, $unit, $work, $run->fresh(), $worker, $leaseToken, $leaseFence);
    }

    private function basis(LabGeneration $cohort, array $unit, ResearchExperimentWorkItem $work): array
    {
        $cohort->refresh(); $owner = (array) data_get($cohort->trigger_context, 'specialist_council_authorized_panel', []);
        if (($owner['protocol'] ?? '') !== self::OWNER_PROTOCOL || ($owner['work_item_id'] ?? null) !== (int) $work->id
            || ($owner['work_key'] ?? '') !== $work->work_key || empty($owner['reservation_hash'])
            || ($owner['window_key'] ?? '') !== ($unit['window_key'] ?? null)
            || ($owner['plan_hash'] ?? '') !== ($unit['plan_hash'] ?? null)
            || (int) ($owner['panel_version_id'] ?? 0) !== (int) ($unit['panel_version_id'] ?? 0)
            || ! in_array($unit, (array) ($owner['arm_units'] ?? []), true)) {
            throw new LogicException('COUNCIL_ARM_CANONICAL_OWNER_OR_UNIT_DRIFT');
        }
        $version = SpecialistCouncilVersion::findOrFail($unit['panel_version_id']);
        $row = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $version->id)->first();
        $plan = $row ? json_decode($row->plan, true, 512, JSON_THROW_ON_ERROR) : null;
        $arm = $plan['arms'][$unit['arm_key']] ?? null;
        $window = $plan['windows'][$unit['window_key']] ?? null;
        $agent = LabAgent::with('modelVersion')->findOrFail($unit['lab_agent_id']);
        if (! is_array($plan) || $plan['purpose'] !== 'independent'
            || ! $this->contracts->manifestValid($version->manifest) || $version->manifest_hash !== ($plan['manifest_hash'] ?? '')
            || $row->plan_hash !== $unit['plan_hash'] || $this->epochs->parameterHash($plan) !== $row->plan_hash
            || $row->evaluator_id !== ($owner['evaluator_id'] ?? '') || $row->evaluator_id === $version->creator_id
            || ! $arm || ! $window || $arm['kind'] !== $unit['kind'] || $arm['window_key'] !== $unit['window_key']
            || $arm['evaluation_phase'] !== 'full_validation' || (int) $arm['model_version_id'] !== (int) $unit['model_version_id']
            || $arm['model_hash'] !== $unit['model_hash'] || $this->contracts->modelHash($agent->modelVersion) !== $arm['model_hash']
            || (int) $agent->lab_generation_id !== (int) $cohort->id || (int) $agent->model_version_id !== (int) $arm['model_version_id']
            || ! is_array($plan['full_replay_runtime_policy'] ?? null)) {
            throw new LogicException('COUNCIL_ARM_ORIGINAL_PREREGISTERED_BASIS_DRIFT');
        }
        $authorized = $this->windows->seal($window['authorization_id'], $window['dataset_sha256']);
        if (! $authorized || $authorized['window_key'] !== $unit['window_key']) {
            throw new LogicException('NO_COMPLETED_AUTHORIZED_INDEPENDENT_WINDOW');
        }
        foreach (['control_pair_contract', 'cooperative_experiment_block', 'causal_learning_cohort', 'prospective_repair', 'academy_trial'] as $foreign) {
            if (data_get($agent->modelVersion->metadata, $foreign) !== null) throw new LogicException('COUNCIL_ARM_FOREIGN_EXPERIMENT_OWNER');
        }
        if (app(SpecialistCouncilDataUseService::class)->intervalExposed($version, $agent->symbol,
            $window['start_inclusive'], $window['end_exclusive'])) throw new LogicException('EVALUATION_EVENTS_ALREADY_USED_FOR_TRAINING_OR_SELECTION');
        $binding = $this->lifecycle->evaluationBindingForModel($agent->modelVersion, $window['dataset_sha256']);
        if (($binding['version_id'] ?? null) !== (int) $version->id || ($binding['arm_key'] ?? null) !== $unit['arm_key']
            || ($binding['plan_hash'] ?? null) !== $unit['plan_hash']) throw new LogicException('COUNCIL_ARM_ORIGINAL_NATIVE_BINDING_DRIFT');
        return compact('owner', 'version', 'plan', 'arm', 'window', 'agent', 'binding');
    }

    private function assertLease(ResearchExperimentWorkItem $work, string $worker, string $token, int $fence, int $headroom = 0): void
    {
        $current = $work->fresh();
        if ($worker === '' || ! $current || $current->status !== 'leased' || $current->lease_token !== $token
            || (int) $current->fence_version !== $fence || ! $current->lease_expires_at
            || ! $current->lease_expires_at->greaterThan(now()->addSeconds($headroom))
            || data_get($current->payload, 'owner') !== ResearchLoopArbiterService::class) {
            throw new LogicException('COUNCIL_ARM_LEASE_NOT_CURRENT_OR_INSUFFICIENT');
        }
        if (! app(AutonomousModeService::class)->enabled($work->symbol, $work->timeframe)
            && data_get($current->result, 'panel_units') === null) throw new LogicException('AUTONOMOUS_MODE_STOPPED');
    }

    private function health(LabGeneration $cohort): array
    {
        $response = Http::connectTimeout(3)->timeout(5)->acceptJson()->withHeaders([
            'X-Internal-Token' => (string) config('services.internal_api.token'),
        ])->get(rtrim((string) config('services.ai_service.url'), '/').'/api/replay-status');
        $status = $response->json();
        $expected = data_get($cohort->trigger_context, 'research_release.python_source_hash');
        if ($response->failed() || ! is_array($status) || ($status['protocol'] ?? '') !== 'replay_liveness_v2_bounded_worker'
            || ! array_key_exists('active_requests', $status) || $status['active_requests'] !== 0
            || data_get($status, 'research_source.loaded_code_current') !== true
            || data_get($status, 'research_source.source_hash') !== $expected
            || data_get($status, 'research_source.boot_source_hash') !== $expected) {
            throw new LogicException('COUNCIL_ARM_REPLAY_NOT_IDLE_OR_LOADED_RELEASE_DRIFT');
        }
        return $status;
    }

    private function originalCheckpoint(array $ref, LabGeneration $cohort, array $unit, ResearchExperimentWorkItem $work): LabEvaluationRun
    {
        $run = LabEvaluationRun::where('run_id', $ref['run_id'] ?? '')->firstOrFail();
        if (($ref['protocol'] ?? '') !== self::PROTOCOL || ($ref['generation_id'] ?? null) !== (int) $cohort->id
            || ($ref['arm_key'] ?? '') !== $unit['arm_key'] || ($ref['plan_hash'] ?? '') !== $unit['plan_hash']
            || ($ref['window_key'] ?? '') !== $unit['window_key'] || ($ref['agent_id'] ?? null) !== (int) $unit['lab_agent_id']
            || ($ref['model_version_id'] ?? null) !== (int) $unit['model_version_id']
            || (int) $run->lab_agent_id !== (int) $unit['lab_agent_id'] || (int) $run->lab_generation_id !== (int) $cohort->id
            || (int) $run->model_version_id !== (int) $unit['model_version_id'] || $run->phase !== 'full_validation'
            || $run->mode !== 'full' || $run->request_hash !== ($ref['request_hash'] ?? null)
            || $run->request_hash !== ($unit['request_hash'] ?? null)
            || data_get($run->metadata, 'council_panel.protocol') !== self::PROTOCOL
            || data_get($run->metadata, 'council_panel.work_item_id') !== (int) $work->id
            || data_get($run->metadata, 'council_panel.arm_key') !== $unit['arm_key']
            || data_get($run->metadata, 'council_panel.plan_hash') !== $unit['plan_hash']
            || data_get($run->metadata, 'research_release_hash') !== ($ref['release_hash'] ?? null)
            || data_get($run->metadata, 'council_panel.reservation_hash') !== ($ref['reservation_hash'] ?? null)) {
            throw new LogicException('COUNCIL_ARM_ORIGINAL_CHECKPOINT_DRIFT');
        }
        $this->artifact($run, 'evaluation_request');
        return $run;
    }

    private function projectTerminal(LabGeneration $cohort, array $unit, ResearchExperimentWorkItem $work,
        LabEvaluationRun $run, string $worker, string $token, int $fence): array
    {
        $this->assertLease($work, $worker, $token, $fence);
        if (! $this->evidence->isTerminalRun($run) || $run->finished_at === null) return $this->waiting($run, 'ORIGINAL_COUNCIL_ARM_STILL_IN_FLIGHT');
        $response = $this->artifact($run, 'evaluation_response');
        $dataMissing = data_get($run->metadata, 'scientific_disposition') === 'data_missing';
        if ($dataMissing) {
            $basis = $this->basis($cohort, $unit, $work);
            $native = $this->lifecycle->attestReplayResult($basis['agent']->modelVersion,
                $this->artifact($run, 'evaluation_request'), $response);
            if (! is_array($native) || ($native['status'] ?? null) !== 'dependency') {
                throw new LogicException('COUNCIL_ARM_ORIGINAL_DATA_DEPENDENCY_PROOF_DRIFT');
            }
        }
        if ($run->status === 'completed') {
            $basis = $this->basis($cohort, $unit, $work);
            $request = $this->artifact($run, 'evaluation_request');
            $this->lifecycle->attestReplayResult($basis['agent']->modelVersion, $request, $response);
            $this->lifecycle->assertOriginalArmScope($basis['arm'], $request, $response, $basis['plan']['execution_timeframe']);
            if (($this->evidence->replayEvidenceCompleteness($run, $response)['complete'] ?? false) !== true) {
                throw new LogicException('COUNCIL_ARM_TERMINAL_ORIGINAL_EVIDENCE_INCOMPLETE');
            }
        }
        return DB::transaction(function () use ($cohort, $unit, $work, $run, $worker, $token, $fence, $dataMissing) {
            $locked = ResearchExperimentWorkItem::whereKey($work->id)->lockForUpdate()->firstOrFail();
            $this->assertLease($locked, $worker, $token, $fence);
            $original = $this->originalCheckpoint((array) data_get($locked->result, 'panel_units.'.$unit['arm_key']), $cohort, $unit, $locked);
            $agent = LabAgent::whereKey($unit['lab_agent_id'])->lockForUpdate()->firstOrFail();
            $technical = $original->status !== 'completed';
            $episode = AgentLearningEpisode::where('lab_agent_id', $agent->id)->lockForUpdate()->orderBy('id')->first();
            if (! $episode || AgentLearningEpisode::where('lab_agent_id', $agent->id)->count() !== 1) {
                throw new LogicException('COUNCIL_ARM_ORIGINAL_SINGLE_LEARNING_EPISODE_REQUIRED');
            }
            $outcome = [
                'source_key' => self::PROTOCOL.'|'.$original->run_id,
                'source_type' => self::class, 'source_id' => (int) $original->id,
                'outcome_status' => $dataMissing ? 'data_missing' : ($technical ? 'technical_unassessable' : 'original_arm_observed'),
                'evidence_state' => 'insufficient_evidence', 'metrics' => [],
                'failure_class' => $dataMissing ? 'data_missing' : ($technical ? 'execution_failure' : 'evidence_completion'),
                'original_run_id' => $original->run_id, 'original_request_hash' => $original->request_hash,
                'original_response_hash' => $original->response_hash, 'arm_key' => $unit['arm_key'],
                'plan_hash' => $unit['plan_hash'], 'window_key' => $unit['window_key'],
                'research_only' => true, 'selection_reward_authorized' => false,
                'causal_skill_credit' => false, 'promotion_evidence' => false,
            ];
            $previous = AgentLearningSettlement::where('episode_id', $episode->id)->first();
            if ($previous && $previous->source_key !== $outcome['source_key']) {
                throw new LogicException('COUNCIL_ARM_EPISODE_ALREADY_SETTLED_BY_OTHER_OWNER');
            }
            $priorReceipt = data_get($locked->result, 'panel_units.'.$unit['arm_key'].'.terminal_receipt');
            if (is_array($priorReceipt)) {
                if (($priorReceipt['receipt_hash'] ?? '') !== $this->epochs->parameterHash(
                    array_diff_key($priorReceipt, ['receipt_hash' => true]))
                    || ($priorReceipt['run_id'] ?? '') !== $original->run_id
                    || ($priorReceipt['request_hash'] ?? '') !== $original->request_hash
                    || ($priorReceipt['response_hash'] ?? null) !== $original->response_hash
                    || ! $previous || $previous->source_key !== $outcome['source_key']
                    || $agent->lifecycle_status !== ($technical ? 'technical_quarantine' : 'completed')) {
                    throw new LogicException('COUNCIL_ARM_ORIGINAL_TERMINAL_PROJECTION_DRIFT');
                }
                return [...$priorReceipt, 'already_terminal' => true];
            }
            $this->learning->settleOutcome($episode, $outcome);
            $agent->update(['lifecycle_status' => $technical ? 'technical_quarantine' : 'completed',
                'decision_reason' => 'Original authorized research arm '.($technical ? 'technical refusal' : 'observed; no trading qualification').'.']);
            $receipt = [
                'protocol' => self::PROTOCOL, 'status' => $outcome['outcome_status'],
                'work_item_id' => (int) $work->id, 'generation_id' => (int) $cohort->id,
                'arm_key' => $unit['arm_key'], 'run_id' => $original->run_id,
                'request_hash' => $original->request_hash, 'response_hash' => $original->response_hash,
                'data_hash' => $original->data_hash, 'plan_hash' => $unit['plan_hash'],
                'model_hash' => $unit['model_hash'], 'window_key' => $unit['window_key'],
                'episode_id' => (int) $episode->id, 'promotion_evidence' => false,
                'economic_skill_proven' => false, 'trading_authority_granted' => false,
            ];
            $receipt['receipt_hash'] = $this->epochs->parameterHash($receipt);
            $result = (array) $locked->result;
            $result['panel_units'][$unit['arm_key']] = [...(array) $result['panel_units'][$unit['arm_key']],
                'status' => 'terminal', 'terminal_receipt' => $receipt];
            $locked->update(['result' => $result, 'heartbeat_at' => now()]);
            // Existing boundary verifies ALL agents, runs, owned queues and
            // real episode settlements. A local arm cannot forge that result.
            $closure = $this->terminal->closeIfTerminal($cohort);
            return [...$receipt, 'already_terminal' => false, 'generation_terminal' => $closure];
        });
    }

    /** Only the immutable publisher's single original bytes can settle a unit. */
    private function artifact(LabEvaluationRun $run, string $type): array
    {
        $rows = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', $type)->limit(2)->get();
        if ($rows->count() !== 1) throw new LogicException('COUNCIL_ARM_ORIGINAL_ARTIFACT_MISSING_OR_DUPLICATED');
        $artifact = $rows->first(); $payload = $this->evidence->readArtifactPayload($artifact);
        $hash = $type === 'evaluation_request' ? $run->request_hash : $run->response_hash;
        if (! is_array($payload) || data_get($artifact->metadata, $type === 'evaluation_request' ? 'request_hash' : 'response_hash') !== $hash
            || ($run->finished_at && $artifact->created_at->greaterThan($run->finished_at))
            || (int) $artifact->lab_agent_id !== (int) $run->lab_agent_id
            || (int) $artifact->lab_generation_id !== (int) $run->lab_generation_id) {
            throw new LogicException('COUNCIL_ARM_ORIGINAL_ARTIFACT_IDENTITY_DRIFT');
        }
        return $payload;
    }

    private function waiting(LabEvaluationRun $run, string $reason): array
    {
        return ['protocol' => self::PROTOCOL, 'status' => 'waiting', 'reason' => $reason,
            'run_id' => $run->run_id, 'promotion_evidence' => false];
    }

    private function blocked(string $reason): array
    {
        return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => $reason,
            'promotion_evidence' => false];
    }
}
