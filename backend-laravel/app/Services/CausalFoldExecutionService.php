<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\CausalFoldReceipt;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** Durable per-fold execution and atomic three-arm causal settlement. */
class CausalFoldExecutionService
{
    public const PROTOCOL = 'durable_causal_fold_jobs_v1';

    public function __construct(
        private LabAgentEvaluationService $evaluations,
        private LabGenerationTerminalBoundaryService $terminalBoundary,
    ) {}

    public function ensureReceipt(AgentLearningCausalExperiment $experiment, int $foldIndex): CausalFoldReceipt
    {
        $foldCount = $this->foldCount();
        if ($foldIndex < 1 || $foldIndex > $foldCount) {
            throw new RuntimeException('CAUSAL_FOLD_INDEX_OUT_OF_RANGE');
        }

        return CausalFoldReceipt::query()->firstOrCreate([
            'agent_learning_causal_experiment_id' => $experiment->id,
            'fold_index' => $foldIndex,
        ], [
            'receipt_key' => hash('sha256', implode('|', [self::PROTOCOL, $experiment->id, $foldIndex, $foldCount])),
            'lab_generation_id' => $experiment->lab_generation_id,
            'fold_count' => $foldCount,
            'status' => 'planned',
            'attempt_count' => 0,
            'observed_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    public function run(int $experimentId, int $foldIndex): array
    {
        $experiment = AgentLearningCausalExperiment::query()->with('generation.agents.modelVersion')->findOrFail($experimentId);
        $selectorReady = app(ScopedSelectorPanelService::class)->executionReadiness($experiment);
        if (($selectorReady['ready'] ?? false) !== true) {
            return ['status' => 'awaiting_original_selector_data',
                'experiment_id' => $experimentId, 'reason_code' => $selectorReady['reason_code'], 'promotion_evidence' => false];
        }
        if (in_array((string) $experiment->status, ['technical_quarantine', 'invalid_counterfactual_contract'], true)) {
            return [
                'status' => 'terminal_without_replay',
                'experiment_id' => $experimentId,
                'fold_index' => $foldIndex,
                'reason_code' => 'CAUSAL_EXPERIMENT_ALREADY_TERMINAL',
                'promotion_evidence' => false,
            ];
        }
        $receipt = $this->ensureReceipt($experiment, $foldIndex);
        if ((string) $receipt->status === 'completed') {
            return [
                'status' => 'already_completed',
                'receipt_id' => $receipt->id,
                'fold_index' => $foldIndex,
                'settlement' => $this->settleIfComplete($experiment),
            ];
        }

        $lease = (string) Str::uuid();
        DB::transaction(function () use ($receipt, $lease): void {
            $locked = CausalFoldReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            if ((string) $locked->status === 'completed') {
                return;
            }
            $locked->update([
                'status' => 'running',
                'attempt_count' => (int) $locked->attempt_count + 1,
                'lease_token' => $lease,
                'started_at' => now(),
                'completed_at' => null,
                'error_code' => null,
                'error_message' => null,
                'observed_at' => now(),
            ]);
        });
        $receipt->refresh();
        if ((string) $receipt->status === 'completed') {
            return ['status' => 'already_completed', 'receipt_id' => $receipt->id, 'fold_index' => $foldIndex];
        }

        $foldResponseObserved = false;
        try {
            $envelope = $this->evaluations->causalFoldEnvelope($experiment, $foldIndex);
            $request = (array) $envelope['request'];
            $guided = LabAgent::with('generation')->findOrFail($experiment->guided_agent_id);
            $request = app(ResearchReleaseSealService::class)->bindGenerationRequest($guided->generation,
                $request, [$experiment->guided_agent_id, $experiment->blinded_agent_id, $experiment->control_agent_id]);
            $benchmark = app(TypedInstrumentFoundryService::class)->registerCausalBenchmark($experiment, $request);
            if (($benchmark['reason'] ?? null) === 'BENCHMARK_PREREGISTERED_CONTRACT_DRIFT') {
                throw new RuntimeException('CAUSAL_BENCHMARK_PREREGISTERED_CONTRACT_DRIFT');
            }
            $prediction = app(ResearchKnowledgePortfolioService::class)->preregisterExperiment($experiment, $request);
            if (($prediction['reason'] ?? null) === 'PREDICTION_PREREGISTERED_CONTRACT_DRIFT') {
                throw new RuntimeException('CAUSAL_PREDICTION_PREREGISTERED_CONTRACT_DRIFT');
            }
            $requestHash = $this->hash($request);
            if ($receipt->request_hash && ! hash_equals((string) $receipt->request_hash, $requestHash)) {
                throw new RuntimeException('CAUSAL_FOLD_REQUEST_IDENTITY_CHANGED');
            }
            $capturePrefix = app(ResearchWindowExposureInventoryService::class)->recordCausalFoldRequest($receipt, $request, $requestHash);
            $timeout = max(960, min(1200, (int) config('services.lab_selection.causal_fold_transport_timeout_seconds', 960)));
            $requestId = "causal-fold-{$experiment->id}-{$foldIndex}-{$lease}";
            $http = Http::connectTimeout(15)->timeout($timeout)->withOptions([
                'connect_timeout' => 15,
                'timeout' => $timeout,
                'curl' => [
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_CONNECTTIMEOUT_MS => 15000,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_TIMEOUT_MS => $timeout * 1000,
                ],
            ])->acceptJson()->withHeaders([
                'X-Internal-Token' => (string) config('services.internal_api.token'),
                'X-Lab-Request-Id' => $requestId,
            ]);
            $response = $this->sendOriginalWire($http,
                rtrim((string) config('services.ai_service.url'), '/').'/api/backtest/run-all', $request,
                $capturePrefix !== [] && $this->isScopedSelectorWire($request));
            if ($response->failed()) {
                throw new RuntimeException('CAUSAL_FOLD_HTTP_'.$response->status().': '.$response->body());
            }
            $payload = (array) $response->json();
            $foldResponseObserved = true;
            $this->assertFoldResponse($experiment, $payload, $foldIndex, (int) $envelope['fold_count']);
            $responseHash = $this->hash($payload);
            app(ResearchWindowExposureInventoryService::class)->assertCausalFoldRequestCaptured($receipt, $request, $requestHash, $capturePrefix);
            DB::transaction(function () use ($receipt, $lease, $request, $requestHash, $payload, $responseHash, $envelope): void {
                $locked = CausalFoldReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
                if ((string) $locked->status === 'completed') {
                    if (! hash_equals((string) $locked->request_hash, $requestHash)
                        || ! hash_equals((string) $locked->response_hash, $responseHash)) {
                        throw new RuntimeException('CAUSAL_FOLD_COMPLETED_RECEIPT_IMMUTABILITY_VIOLATION');
                    }

                    return;
                }
                if (! hash_equals((string) $locked->lease_token, $lease)) {
                    throw new RuntimeException('CAUSAL_FOLD_STALE_LEASE');
                }
                $locked->update([
                    'status' => 'completed',
                    'request_hash' => $requestHash,
                    'response_hash' => $responseHash,
                    'dataset_hash' => (string) $envelope['dataset_hash'],
                    'execution_hash' => (string) $envelope['execution_hash'],
                    'request_payload' => $request,
                    'response_payload' => $payload,
                    'completed_at' => now(),
                    'observed_at' => now(),
                ]);
            });
            $this->recordProgress($experiment->fresh(), null);
            $settlement = $this->settleIfComplete($experiment->fresh());

            return [
                'status' => 'completed',
                'receipt_id' => $receipt->id,
                'fold_index' => $foldIndex,
                'settlement' => $settlement,
            ];
        } catch (\Throwable $exception) {
            if ($foldResponseObserved && str_starts_with($this->reasonCode($exception), 'EXPOSURE_')) {
                // Preserve the actual observed raw product as technical evidence.
                // A lost/changed pre-HTTP capture may not publish a completed scientific fold.
                CausalFoldReceipt::query()->whereKey($receipt->id)->where('status', '!=', 'completed')->update([
                    'request_hash' => $requestHash, 'request_payload' => $request,
                    'response_hash' => $responseHash ?? $this->hash($payload), 'response_payload' => $payload,
                    'dataset_hash' => (string) $envelope['dataset_hash'], 'execution_hash' => (string) $envelope['execution_hash'],
                    'observed_at' => now(), 'updated_at' => now(),
                ]);
                $this->terminalFailure((int) $experiment->id, $foldIndex, $exception);
                throw $exception;
            }
            CausalFoldReceipt::query()->whereKey($receipt->id)->where('status', '!=', 'completed')->update([
                'status' => 'retry_ready',
                'error_code' => $this->reasonCode($exception),
                'error_message' => substr($exception->getMessage(), 0, 2000),
                'lease_token' => null,
                'observed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->recordProgress($experiment->fresh(), $this->reasonCode($exception));
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function settleIfComplete(AgentLearningCausalExperiment $experiment): array
    {
        $foldCount = $this->foldCount();
        $receipts = CausalFoldReceipt::query()
            ->where('agent_learning_causal_experiment_id', $experiment->id)
            ->orderBy('fold_index')->get();
        if ($receipts->count() !== $foldCount || $receipts->where('status', 'completed')->count() !== $foldCount) {
            return [
                'status' => 'awaiting_folds',
                'completed' => $receipts->where('status', 'completed')->count(),
                'required' => $foldCount,
                'promotion_evidence' => false,
            ];
        }

        $settlementLock = Cache::lock('causal-fold-settlement:'.$experiment->id, 300);
        if (! $settlementLock->get()) {
            return ['status' => 'settlement_in_progress', 'experiment_id' => $experiment->id, 'promotion_evidence' => false];
        }
        try {
            $locked = AgentLearningCausalExperiment::query()->findOrFail($experiment->id);
            if (data_get($locked->evidence, 'fold_execution.settlement.status') === 'completed') {
                return ['status' => 'already_settled', 'experiment_id' => $locked->id, 'promotion_evidence' => false];
            }
            $datasetHashes = $receipts->pluck('dataset_hash')->filter()->unique();
            $executionHashes = $receipts->pluck('execution_hash')->filter()->unique();
            if ($datasetHashes->count() !== 1 || $executionHashes->count() !== 1) {
                throw new RuntimeException('CAUSAL_FOLD_RECEIPT_IDENTITY_MISMATCH');
            }
            $foldPayloads = $receipts->map(fn (CausalFoldReceipt $receipt): array => (array) $receipt->response_payload)->all();
            $aggregateRequest = [
                'expected_fold_count' => $foldCount,
                'fold_receipts' => $foldPayloads,
            ];
            $http = Http::connectTimeout(10)->timeout(60)->acceptJson()->withHeaders([
                'X-Internal-Token' => (string) config('services.internal_api.token'),
                'X-Lab-Request-Id' => 'causal-fold-aggregate-'.$locked->id,
            ]);
            $response = $this->sendOriginalWire($http,
                rtrim((string) config('services.ai_service.url'), '/').'/api/backtest/aggregate-causal-folds',
                $aggregateRequest, $this->isScopedSelectorWire((array) $receipts->first()->request_payload));
            if ($response->failed()) {
                throw new RuntimeException('CAUSAL_FOLD_AGGREGATION_FAILED: '.$response->body());
            }
            $aggregate = (array) $response->json();
            if (data_get($aggregate, 'protocol') !== 'causal_fold_aggregate_response_v1'
                || (int) data_get($aggregate, 'received_fold_count', 0) !== $foldCount) {
                throw new RuntimeException('CAUSAL_FOLD_AGGREGATION_CONTRACT_INVALID');
            }
            $items = collect((array) data_get($aggregate, 'leaderboard', []))->keyBy(
                fn (mixed $item): int => (int) data_get($item, 'lab_agent_id', 0),
            );
            $armIds = collect([$locked->guided_agent_id, $locked->blinded_agent_id, $locked->control_agent_id])
                ->map(fn (mixed $id): int => (int) $id)->filter()->unique()->values();
            if ($items->count() !== 3 || $armIds->count() !== 3 || $armIds->diff($items->keys()->map(fn ($id): int => (int) $id))->isNotEmpty()) {
                throw new RuntimeException('CAUSAL_FOLD_AGGREGATE_THREE_ARM_IDENTITY_MISMATCH');
            }
            $baseRequest = (array) $receipts->first()->request_payload;
            data_set($baseRequest, 'policy_context.causal_fold_aggregate', [
                'protocol' => 'causal_fold_aggregate_receipt_v1',
                'experiment_id' => (int) $locked->id,
                'fold_count' => $foldCount,
                'receipt_hashes' => $receipts->pluck('response_hash')->all(),
                'atomic_settlement' => true,
                'promotion_evidence' => false,
            ]);
            $manifest = (array) data_get(
                $baseRequest,
                'policy_context.causal_fold_job.dataset_manifest',
                data_get($baseRequest, 'mtf_snapshot_manifest', []),
            );
            $agents = LabAgent::query()->with('modelVersion', 'generation')->whereIn('id', $armIds)->get()->keyBy('id');
            foreach ($armIds as $agentId) {
                $agent = $agents->get($agentId);
                if (! $agent) {
                    throw new RuntimeException('CAUSAL_FOLD_AGGREGATE_AGENT_MISSING');
                }
                $existing = LabEvaluationRun::query()->where('lab_agent_id', $agentId)
                    ->where('phase', 'full_validation')->where('status', 'completed')->get()
                    ->first(fn (LabEvaluationRun $run): bool => data_get($run->metadata, 'source') === 'causal_fold_aggregate');
                if (! $existing) {
                    $agent->update(['lifecycle_status' => 'training']);
                    $this->evaluations->projectCausalFoldAggregate(
                        $agent->fresh(['modelVersion', 'generation']),
                        (array) $items->get($agentId),
                        $aggregate,
                        $baseRequest,
                        $manifest,
                    );
                }
            }
            $fresh = $locked->fresh();
            $evidence = (array) $fresh->evidence;
            data_set($evidence, 'fold_execution.compute_comparison',
                app(TypedInstrumentFoundryService::class)->settleCausalBenchmark($fresh, $aggregate));
            data_set($evidence, 'fold_execution.settlement', [
                'protocol' => self::PROTOCOL,
                'status' => 'completed',
                'fold_count' => $foldCount,
                'receipt_ids' => $receipts->pluck('id')->all(),
                'receipt_hashes' => $receipts->pluck('response_hash')->all(),
                'aggregate_hash' => $this->hash($aggregate),
                'settled_at' => now()->utc()->toIso8601String(),
                'atomic' => true,
                'promotion_evidence' => false,
            ]);
            $fresh->update(['evidence' => $evidence]);
            app(ResearchKnowledgePortfolioService::class)->settleExperimentPrediction($fresh);
            app(ScopedSelectorPanelService::class)->reconcileForExperiment($fresh);
            $generation = $fresh->generation()->with('agents.modelVersion')->first();
            if ($generation) {
                $this->terminalBoundary->closeIfTerminal($generation);
                app(LabGenerationReportService::class)->record($generation->fresh(['agents']), 'causal_fold_settlement');
            }

            return [
                'status' => 'completed',
                'experiment_id' => (int) $fresh->id,
                'fold_count' => $foldCount,
                'aggregate_hash' => $this->hash($aggregate),
                'promotion_evidence' => false,
            ];
        } finally {
            $settlementLock->release();
        }
    }

    public function terminalFailure(int $experimentId, int $foldIndex, \Throwable $exception): void
    {
        $experiment = AgentLearningCausalExperiment::query()->with('generation.agents')->find($experimentId);
        if (! $experiment) {
            return;
        }
        $reason = $this->reasonCode($exception);
        CausalFoldReceipt::query()
            ->where('agent_learning_causal_experiment_id', $experimentId)
            ->where('fold_index', $foldIndex)
            ->where('status', '!=', 'completed')
            ->update([
                'status' => 'technical_error',
                'error_code' => $reason,
                'error_message' => substr($exception->getMessage(), 0, 2000),
                'completed_at' => now(),
                'observed_at' => now(),
                'updated_at' => now(),
            ]);
        $evidence = (array) $experiment->evidence;
        data_set($evidence, 'fold_execution.terminal_failure', [
            'fold_index' => $foldIndex,
            'reason_code' => $reason,
            'recorded_at' => now()->utc()->toIso8601String(),
            'partial_fold_credit_allowed' => false,
            'promotion_evidence' => false,
        ]);
        $experiment->update(['status' => 'technical_quarantine', 'evidence' => $evidence]);
        $armIds = array_filter([$experiment->guided_agent_id, $experiment->blinded_agent_id, $experiment->control_agent_id]);
        LabAgent::query()->whereIn('id', $armIds)->whereIn('lifecycle_status', ['full_queued', 'training'])
            ->update([
                'lifecycle_status' => 'technical_quarantine',
                'decision_reason' => "Causal fold {$foldIndex} exhausted bounded retries [{$reason}]; partial folds received no learning credit.",
                'updated_at' => now(),
            ]);
        if ($generation = $experiment->generation()->with('agents')->first()) {
            $generation->update(['status' => 'technical_quarantine', 'completed_at' => now()]);
            app(LabGenerationReportService::class)->record($generation->fresh(['agents']), 'causal_fold_technical_quarantine');
        }
    }

    private function recordProgress(AgentLearningCausalExperiment $experiment, ?string $lastError): void
    {
        $receipts = CausalFoldReceipt::query()
            ->where('agent_learning_causal_experiment_id', $experiment->id)->orderBy('fold_index')->get();
        $evidence = (array) $experiment->evidence;
        data_set($evidence, 'fold_execution', [
            ...((array) data_get($evidence, 'fold_execution', [])),
            'protocol' => self::PROTOCOL,
            'required_fold_count' => $this->foldCount(),
            'completed_fold_indexes' => $receipts->where('status', 'completed')->pluck('fold_index')->map(fn ($index): int => (int) $index)->values()->all(),
            'retry_ready_fold_indexes' => $receipts->where('status', 'retry_ready')->pluck('fold_index')->map(fn ($index): int => (int) $index)->values()->all(),
            'last_error_code' => $lastError,
            'partial_fold_credit_allowed' => false,
            'updated_at' => now()->utc()->toIso8601String(),
            'promotion_evidence' => false,
        ]);
        $experiment->update(['evidence' => $evidence]);
    }

    /** @param array<string,mixed> $payload */
    private function assertFoldResponse(
        AgentLearningCausalExperiment $experiment,
        array $payload,
        int $foldIndex,
        int $foldCount,
    ): void {
        $items = collect((array) data_get($payload, 'leaderboard', []));
        $expectedIds = collect([$experiment->guided_agent_id, $experiment->blinded_agent_id, $experiment->control_agent_id])
            ->map(fn (mixed $id): int => (int) $id)->sort()->values();
        $actualIds = $items->pluck('lab_agent_id')->map(fn ($id): int => (int) $id)->sort()->values();
        if ($items->count() !== 3 || $actualIds->all() !== $expectedIds->all()) {
            throw new RuntimeException('CAUSAL_FOLD_RESPONSE_THREE_ARM_IDENTITY_MISMATCH');
        }
        foreach ($items as $item) {
            $seal = (array) data_get($experiment->generation?->trigger_context, 'research_release', []);
            if (! app(ResearchReleaseSealService::class)->responseValid($seal,
                (array) data_get($item, 'result.data_quality.research_release_receipt', []))) {
                throw new RuntimeException('RESEARCH_WORKER_RELEASE_RECEIPT_INVALID');
            }
            if ((string) data_get($item, 'result.learning_confirmation.execution_mode') !== 'durable_single_fold_job'
                || (int) data_get($item, 'result.learning_confirmation.fold_count', 0) !== 1
                || (int) data_get($item, 'result.learning_confirmation.fold_offset', -1) !== $foldIndex - 1
                || (int) data_get($item, 'result.learning_confirmation.fold_universe_count', 0) !== $foldCount
                || (int) data_get($item, 'rolling_windows_count', 0) !== 1) {
                throw new RuntimeException('CAUSAL_FOLD_RESPONSE_SCOPE_MISMATCH');
            }
        }
    }

    private function foldCount(): int
    {
        return max(1, min(12, (int) config('services.learning_lane.causal_fold_count', 9)));
    }

    private function reasonCode(\Throwable $exception): string
    {
        $message = strtoupper($exception->getMessage());
        if (preg_match('/^(?:EXPOSURE_[A-Z0-9_]+|CANDIDATE_INTERSECTS_[A-Z0-9_]+)(?::[A-Za-z0-9_.:-]+)?$/D', $exception->getMessage())) {
            return $exception->getMessage();
        }
        if (str_contains($message, 'RESEARCH_RELEASE') || str_contains($message, 'RESEARCH_WORKER')) {
            return 'RESEARCH_RELEASE_PROVENANCE_INVALID';
        }
        if (str_contains($message, 'TIMEOUT') || str_contains($message, 'TIMED OUT')) {
            return 'CAUSAL_FOLD_TIMEOUT';
        }
        if (str_contains($message, 'IDENTITY') || str_contains($message, 'MISMATCH')) {
            return 'CAUSAL_FOLD_IDENTITY_MISMATCH';
        }

        return 'CAUSAL_FOLD_TECHNICAL_ERROR';
    }

    /** A wire codec choice is not authority; original scope admission precedes this call. */
    private function isScopedSelectorWire(array $request): bool
    {
        return data_get($request, 'policy_context.scoped_research_certificate.protocol') === ScopedResearchCertificateService::AUTHORITY_POLICY
            && data_get($request, 'policy_context.scoped_research_certificate.purpose') === 'independent_scoped_selector_research';
    }

    private function sendOriginalWire(PendingRequest $http, string $url, array $payload, bool $preserveOriginal): Response
    {
        if (! $preserveOriginal) {
            return $http->post($url, $payload);
        }

        return $http->withBody(json_encode($payload,
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), 'application/json')->post($url);
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', (string) json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
