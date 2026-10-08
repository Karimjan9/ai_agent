<?php

namespace App\Services;

use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGateDecisionEvent;
use App\Models\LabGeneration;
use App\Models\LabLifecycleEvent;
use App\Models\LabMutationCreditEvent;
use App\Models\MutationMemory;
use App\Jobs\ProjectLabCandleDecisionEvents;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Durable evidence ledger for the laboratory.
 *
 * Mutable records remain projections used by selectors and dashboards.  This
 * service is deliberately append-only for historical facts: every invocation
 * inserts a lifecycle/gate/credit/artifact row.  An evaluation run itself is
 * opened once and receives one terminal update; every attempt gets a new
 * run_id, so a retry can never overwrite an earlier attempt.
 */
class LabImmutableEvidenceService
{
    public const TERMINAL_RUN_STATUSES = [
        'completed', 'technical_error', 'retry_released', 'skipped', 'legacy_snapshot',
    ];

    public function findRun(?string $runId): ?LabEvaluationRun
    {
        return $runId ? LabEvaluationRun::query()->where('run_id', $runId)->first() : null;
    }

    public function isTerminalRun(?LabEvaluationRun $run): bool
    {
        return $run !== null && in_array((string) $run->status, self::TERMINAL_RUN_STATUSES, true);
    }

    public function finishIfOpen(
        LabEvaluationRun $run,
        string $status,
        ?array $response = null,
        array $metrics = [],
        array $metadata = [],
        ?Throwable $error = null,
    ): void {
        $run->refresh();
        if ($this->isTerminalRun($run)) {
            return;
        }
        $this->finishRun($run, $status, $response, $metrics, $metadata, $error);
    }

    public function beginRun(LabAgent $agent, string $phase, string $mode, array $context = []): LabEvaluationRun
    {
        $agent->loadMissing('generation', 'modelVersion');
        if ($agent->generation) app(ResearchReleaseSealService::class)->assertCurrent($agent->generation);
        $started = now();
        $run = LabEvaluationRun::create([
            'run_id' => (string) Str::uuid(),
            'lab_generation_id' => $agent->lab_generation_id,
            'lab_agent_id' => $agent->id,
            'model_version_id' => $agent->model_version_id,
            'phase' => $phase,
            'mode' => $mode,
            'attempt' => max(1, (int) ($context['attempt'] ?? 1)),
            'queue' => $context['queue'] ?? null,
            'job_uuid' => $context['job_uuid'] ?? null,
            'request_id' => $context['request_id'] ?? null,
            'status' => 'started',
            'started_at' => $started,
            'worker_name' => gethostname() ?: null,
            'worker_pid' => (string) getmypid(),
            'data_hash' => $context['data_hash'] ?? null,
            'code_hash' => $context['code_hash'] ?? $this->codeHash(),
            'parameter_hash' => $context['parameter_hash'] ?? $this->parameterHash($agent),
            'metadata' => [
                'protocol' => 'lab_immutable_evidence_v1',
                'source' => $context['source'] ?? 'EvaluateLabAgentJob',
                'historical' => false,
                'research_release_hash' => data_get($agent->generation?->trigger_context, 'research_release.release_hash'),
                'worker_boot_source_hash' => app()->bound('research.worker_boot_source_hash')
                    ? app('research.worker_boot_source_hash') : null,
            ],
        ]);

        $this->recordLifecycle($agent, 'evaluation_started', [
            'run_id' => $run->run_id, 'phase' => $phase, 'mode' => $mode,
            'attempt' => $run->attempt, 'queue' => $run->queue,
        ], $phase, $run->run_id, $run->attempt, $context['source'] ?? 'evaluation_job');

        return $run;
    }

    /**
     * A sealed release mismatch happens before an evaluator request exists.
     * Record the refusal without bypassing beginRun's release assertion or
     * pretending that a replay was attempted. Re-delivery of the same sealed
     * agent/phase must not create another technical result.
     */
    public function refuseReleaseBeforeRun(
        LabAgent $agent,
        string $phase,
        string $mode,
        array $context,
        Throwable $error,
    ): LabEvaluationRun {
        if (! ResearchReleaseSealService::isTerminalDrift($error)) {
            throw $error;
        }
        $agent->loadMissing('generation', 'modelVersion');
        $sealedHash = (string) data_get($agent->generation?->trigger_context, 'research_release.release_hash', '');
        if ($sealedHash === '') {
            throw $error;
        }
        $refusalKey = hash('sha256', implode('|', [
            'release_refusal_v1', $agent->lab_generation_id, $agent->id, $phase, $sealedHash,
        ]));
        $existing = LabEvaluationRun::query()
            ->where('lab_agent_id', $agent->id)
            ->where('phase', $phase)
            ->where('metadata->release_refusal_key', $refusalKey)
            ->latest('id')->first();
        if ($existing) {
            return $existing;
        }

        $run = LabEvaluationRun::create([
            'run_id' => (string) Str::uuid(),
            'lab_generation_id' => $agent->lab_generation_id,
            'lab_agent_id' => $agent->id,
            'model_version_id' => $agent->model_version_id,
            'phase' => $phase,
            'mode' => $mode,
            'attempt' => max(1, (int) ($context['attempt'] ?? 1)),
            'queue' => $context['queue'] ?? null,
            'job_uuid' => $context['job_uuid'] ?? null,
            'status' => 'started',
            'started_at' => now(),
            'worker_name' => gethostname() ?: null,
            'worker_pid' => (string) getmypid(),
            'code_hash' => $this->codeHash(),
            'parameter_hash' => $this->parameterHash($agent),
            'metadata' => [
                'protocol' => 'lab_immutable_evidence_v1',
                'source' => $context['source'] ?? 'release_preflight',
                'release_refusal_key' => $refusalKey,
                'research_release_hash' => $sealedHash,
                'sealed_source_hash' => data_get($agent->generation?->trigger_context, 'research_release.source_hash'),
                'sealed_python_source_hash' => data_get($agent->generation?->trigger_context, 'research_release.python_source_hash'),
                'replay_started' => false,
                'evaluator_request_sent' => false,
                'promotion_evidence' => false,
            ],
        ]);
        $this->finishRun($run, 'technical_error', null, [], [
            'reason_code' => $error->getMessage(),
            'failure_class' => 'sealed_release_preflight_refusal',
            'strategy_verdict' => 'withheld',
            'replay_started' => false,
            'evaluator_request_sent' => false,
            'promotion_evidence' => false,
        ], $error);
        $this->recordLifecycle($agent, 'release_preflight_refused', [
            'run_id' => $run->run_id,
            'reason_code' => $error->getMessage(),
            'research_release_hash' => $sealedHash,
            'replay_started' => false,
            'promotion_evidence' => false,
        ], $phase, $run->run_id, $run->attempt, $context['source'] ?? 'release_preflight', $error);

        return $run->fresh();
    }

    public function markSkipped(LabEvaluationRun $run, string $reason, array $payload = []): void
    {
        $this->finishRun($run, 'skipped', null, ['skip_reason' => $reason], [
            'reason_code' => $reason, ...$payload,
        ]);
    }

    public function attachRequest(LabEvaluationRun $run, array $request, array $context = []): void
    {
        DB::transaction(function () use ($run, $request, $context): void {
        $persisted = LabEvaluationRun::whereKey($run->id)->lockForUpdate()->first();
        if (! $persisted || in_array($persisted->status, self::TERMINAL_RUN_STATUSES, true)) return;
        $run->setRawAttributes($persisted->getAttributes(), true);
        $requestHash = (string) ($context['request_hash'] ?? $this->hash($request));
        $payloadHash = $this->hash($request);
        $safeRequest = $this->requestManifest($request);
        $resolvedDataHash = (string) ($context['data_hash'] ?? '');
        if (! $this->isSha256($resolvedDataHash)) {
            $resolvedDataHash = (string) ($run->data_hash ?: ($this->dataHashFromRequest($request) ?? ''));
        }
        $run->update([
            'request_id' => $context['request_id'] ?? $run->request_id,
            'request_hash' => $requestHash,
            'data_hash' => $resolvedDataHash !== '' ? $resolvedDataHash : null,
            'request_meta' => [
                'payload_hash' => $payloadHash,
                'request_hash' => $requestHash,
                'payload' => $safeRequest,
                'candle_count' => $this->candleCount($request),
                'dataset_manifest' => $context['dataset_manifest'] ?? null,
                'dataset_hash' => $resolvedDataHash !== '' ? $resolvedDataHash : null,
                'attached_at' => now()->toIso8601String(),
            ],
        ]);
        $requestArtifact = $this->recordArtifact($run, 'evaluation_request', $safeRequest, [
            'raw_payload_hash' => $payloadHash,
            'request_hash' => $requestHash,
            'dataset_hash' => $resolvedDataHash !== '' ? $resolvedDataHash : null,
            'dataset_hash_present' => $this->isSha256($resolvedDataHash),
            'exact_candles_referenced_by_hash' => true,
        ]);
        // Separate original artifact preserves transport request/cache hashes.
        // Its server-derived model seal is never accepted from request flags.
        DB::transaction(function () use ($run, $requestHash, $payloadHash, $requestArtifact, $request): void {
            $originalRun = LabEvaluationRun::whereKey($run->id)->lockForUpdate()->first();
            if (! $originalRun || in_array($originalRun->status, self::TERMINAL_RUN_STATUSES, true)
                || LabEvidenceArtifact::where('run_id', $originalRun->run_id)->where('artifact_type', 'model_runtime_identity')->exists()) return;
            $this->recordArtifact($originalRun, 'model_runtime_identity', [...$this->modelRuntimeIdentity($originalRun),
                'raw_request_hash' => $requestHash, 'raw_payload_hash' => $payloadHash, 'request_artifact_hash' => $requestArtifact->sha256,
                'compiled_runtime_contract_hash' => app(ResearchPaperEpochContractService::class)->parameterHash((array) ($request['composition_runtime_contract'] ?? []))], [
                'protocol' => 'original_model_runtime_identity_v1', 'promotion_evidence' => false,
            ]);
        });
        $this->recordLifecycle($run->agent, 'evaluation_request_attached', [
            'request_hash' => $requestHash, 'payload_hash' => $payloadHash,
            'data_hash' => $run->data_hash,
        ], $run->phase, $run->run_id, $run->attempt, 'LabImmutableEvidenceService');
        });
    }

    public function finishRun(
        LabEvaluationRun $run,
        string $status,
        ?array $response = null,
        array $metrics = [],
        array $metadata = [],
        ?Throwable $error = null,
    ): void {
        // A terminal status is a publication boundary, not the beginning of
        // artifact sealing. Hide it from concurrent learning/reconciliation
        // readers until every immutable artifact row is durable. The lock
        // also prevents two late callbacks from sealing different responses.
        try {
            DB::transaction(function () use ($run, $status, $response, $metrics, $metadata, $error): void {
                $locked = LabEvaluationRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
                $run->setRawAttributes($locked->getAttributes(), true);
                $this->sealTerminalRun($run, $status, $response, $metrics, $metadata, $error);
                // Publish the evaluation delivery atomically with original
                // evidence. Its consumer runs only after this transaction.
                app(SpecialistCouncilLifecycleService::class)->enqueueCompletedRun($run);
            });
        } catch (Throwable $exception) {
            // A rolled-back close must not leave the caller's in-memory run
            // looking completed. Immutable files may be orphaned, but no
            // consumer can see them as a sealed run or overwrite old facts.
            $run->refresh();
            throw $exception;
        }
        // Seal original producer evidence first. Evaluation projection has its
        // own durable, idempotent delivery and must never rewrite a finished
        // replay as a technical failure when a downstream consumer is down.
        app(SpecialistCouncilLifecycleService::class)->notifyCompletedRun($run);
    }

    private function sealTerminalRun(
        LabEvaluationRun $run,
        string $status,
        ?array $response,
        array $metrics,
        array $metadata,
        ?Throwable $error,
    ): void {
        // A terminal attempt is immutable. A late worker/failure callback may
        // still arrive, but it must never rewrite the original verdict or
        // response hash. The lifecycle plane can show that a duplicate close
        // was attempted if an operator needs to diagnose it.
        if ($this->isTerminalRun($run)) {
            $this->recordLifecycle($run->agent, 'evaluation_terminal_duplicate', [
                'run_id' => $run->run_id, 'existing_status' => $run->status,
                'ignored_status' => $status, 'response_hash' => $response === null ? null : $this->hash($response),
            ], $run->phase, $run->run_id, $run->attempt, 'LabImmutableEvidenceService', $error);

            return;
        }
        // A terminal replay attempt always gets a response-plane envelope,
        // even when the evaluator returned no payload.  This envelope is an
        // operational error record, not a strategy result: its incomplete
        // trace/ledger markers keep learning and promotion fail-closed.
        $terminalResponse = $response;
        if ($terminalResponse === null && in_array($status, self::TERMINAL_RUN_STATUSES, true)) {
            $terminalResponse = [
                'terminal_replay_envelope' => [
                    'status' => $status,
                    'response_available' => false,
                    'reason_code' => $metadata['reason_code'] ?? null,
                    'error_class' => $error?->getMessage() !== null ? $error::class : null,
                    'error_message' => $error?->getMessage(),
                ],
                'data_quality' => [
                    'decision_trace' => [
                        'requested' => true,
                        'complete' => false,
                        'reason' => 'terminal_replay_did_not_return_evaluator_response',
                    ],
                ],
                'trade_ledger_hash' => null,
                'total_trades' => null,
                'displayed_trade_count' => 0,
            ];
        }
        $responseHash = $terminalResponse === null ? null : $this->hash($terminalResponse);
        if ($terminalResponse !== null) {
            $this->recordArtifact($run, 'evaluation_response', $terminalResponse, [
                'response_hash' => $responseHash,
                'dataset_hash' => $run->data_hash,
                'dataset_hash_present' => $this->isSha256((string) $run->data_hash),
                'trade_ledger_hash' => data_get($terminalResponse, 'trade_ledger_hash'),
                'displayed_trade_count' => data_get($terminalResponse, 'displayed_trade_count'),
                'trade_ledger_complete' => $this->tradeLedgerComplete($terminalResponse),
            ]);
            $tradeLedger = data_get($terminalResponse, 'trade_ledger');
            if (is_array($tradeLedger)) {
                $this->recordArtifact($run, 'trade_ledger', $tradeLedger, [
                    'trade_ledger_hash' => data_get($terminalResponse, 'trade_ledger_hash'),
                    'dataset_hash' => $run->data_hash,
                    'total_trades' => data_get($terminalResponse, 'total_trades'),
                    'complete' => $this->tradeLedgerComplete($terminalResponse),
                ]);
            } else {
                $this->recordArtifact($run, 'trade_ledger_manifest', [
                    'trade_ledger_hash' => data_get($terminalResponse, 'trade_ledger_hash'),
                    'total_trades' => data_get($terminalResponse, 'total_trades'),
                    'displayed_trade_count' => data_get($terminalResponse, 'displayed_trade_count'),
                    'complete' => false,
                ], ['complete' => false, 'reason' => 'full_trade_ledger_not_returned', 'dataset_hash' => $run->data_hash]);
            }
            $this->recordDecisionTrace($run, $terminalResponse);
        }

        // Completion measures publication, including durable artifact writes.
        // Sampling before gzip/storage can leave original files timestamped
        // after finished_at and correctly rejected by the strict consumer.
        // The existing transaction/row lock publishes this boundary once.
        $finished = now();
        $run->update([
            'status' => $status,
            'finished_at' => $finished,
            'duration_ms' => $run->started_at ? max(0, $run->started_at->diffInMilliseconds($finished)) : null,
            'response_hash' => $responseHash,
            'trade_ledger_hash' => data_get($terminalResponse, 'trade_ledger_hash'),
            'response_meta' => $terminalResponse === null ? null : $this->responseManifest($terminalResponse, $run->data_hash, $run),
            // Keep selector projections bounded; originals stay in artifacts.
            'metrics' => $metrics !== []
                ? $this->projectionPayload($metrics)
                : $this->metricsManifest($terminalResponse),
            'metadata' => array_merge((array) $run->metadata, $metadata, [
                'terminal' => true, 'terminal_at' => $finished->toIso8601String(),
            ]),
            'error_class' => $error ? $error::class : null,
            'error_message' => $error ? substr($error->getMessage(), 0, 4000) : null,
        ]);

        $this->recordLifecycle($run->agent, 'evaluation_'.$status, [
            'run_id' => $run->run_id, 'status' => $status, 'response_hash' => $responseHash,
            'error_class' => $error ? $error::class : null,
        ], $run->phase, $run->run_id, $run->attempt, 'LabImmutableEvidenceService', $error);
    }

    /**
     * Check the response before any mutable gate, champion or learning
     * projection is allowed to consume it.  The request artifact is checked
     * here as well because a response without the exact request cannot be
     * tied to a frozen dataset/execution contract.
     *
     * @return array{complete: bool, reason_codes: array<int, string>, request_artifact: bool, dataset_hash: bool, decision_trace: bool, trade_ledger: bool, promotion_evidence: bool}
     */
    public function replayEvidenceCompleteness(LabEvaluationRun $run, array $response): array
    {
        $requestArtifact = filled($run->request_hash)
            && LabEvidenceArtifact::query()
                ->where('run_id', $run->run_id)
                ->where('artifact_type', 'evaluation_request')
                ->exists();
        $datasetHash = $this->isSha256((string) $run->data_hash)
            && $this->requestHasDatasetHash($run);
        $traceProof = $this->decisionTraceCompleteness($response, $run);
        $traceComplete = $traceProof['complete'];
        $ledgerComplete = $this->tradeLedgerComplete($response)
            && filled(data_get($response, 'trade_ledger_hash'));
        $seal = (array) data_get($run->request_meta, 'payload.research_release',
            data_get($run->generation?->trigger_context, 'research_release', []));
        $releaseComplete = app(ResearchReleaseSealService::class)->responseValid($seal,
            (array) data_get($response, 'data_quality.research_release_receipt', []));
        $reasons = [];
        if (! $requestArtifact) $reasons[] = 'MISSING_EVALUATION_REQUEST_ARTIFACT';
        if (! $datasetHash) $reasons[] = 'MISSING_DATASET_HASH';
        if (! $traceComplete) $reasons[] = 'MISSING_COMPLETE_DECISION_TRACE';
        if (! $ledgerComplete) $reasons[] = 'MISSING_COMPLETE_TRADE_LEDGER';
        if (! $releaseComplete) $reasons[] = 'RESEARCH_WORKER_RELEASE_RECEIPT_INVALID';

        return [
            'complete' => $reasons === [],
            'reason_codes' => $reasons,
            'request_artifact' => $requestArtifact,
            'dataset_hash' => $datasetHash,
            'decision_trace' => $traceComplete,
            'decision_trace_reason_codes' => $traceProof['reason_codes'],
            'trade_ledger' => $ledgerComplete,
            'research_release' => $releaseComplete,
            'promotion_evidence' => false,
        ];
    }

    /** Verify the actual received native trace, not just agreeing hash copies in its receipt. */
    public function nativeDecisionTraceHashValid(array $response, array $native): bool
    {
        $trace = $response['decision_trace'] ?? null;
        $producer = (array) data_get($response, 'data_quality.decision_trace', []);
        $identity = (array) ($native['decision_trace_identity'] ?? []);
        $hash = $producer['trace_hash'] ?? null;
        $contractHash = $native['contract_hash'] ?? null;
        return is_array($trace) && array_is_list($trace) && is_string($hash)
            && preg_match('/^[a-f0-9]{64}$/D', $hash) === 1
            && is_string($contractHash) && preg_match('/^[a-f0-9]{64}$/D', $contractHash) === 1
            && ($producer['scope_owner'] ?? null) === 'native_specialist_council_v1'
            && ($identity['protocol'] ?? null) === 'native_council_decision_trace_v1'
            && ($identity['contract_hash'] ?? null) === $contractHash
            && ($identity['trace_hash'] ?? null) === $hash
            && app(ResearchPaperEpochContractService::class)->parameterHash($trace) === $hash;
    }

    /**
     * Exact producer/consumer trace contract. Counts of trades or compact
     * projection rows are never candle coverage. A zero-trade WAIT history
     * is complete; a sparse signal-only trace is not. An empty trace needs
     * an explicit zero-evaluated producer declaration, never a default zero.
     *
     * @return array<string,mixed>
     */
    public function decisionTraceCompleteness(array $response, ?LabEvaluationRun $run = null): array
    {
        $trace = data_get($response, 'decision_trace', data_get($response, 'candle_decision_trace', data_get($response, 'decision_events')));
        $producer = (array) data_get($response, 'data_quality.decision_trace', []);
        $events = is_array($trace) && array_is_list($trace) ? count($trace) : null;
        $evaluated = $producer['evaluated_candle_count'] ?? null;
        $input = $producer['input_candle_count'] ?? null;
        // Inline requests expose exact primary rows. Regime/context rows are
        // not execution candles; audit slices have their own producer-bound
        // input count and may not inherit the full economic dataset length.
        if (($producer['audit_slice'] ?? false) !== true && $run !== null) {
            $requestRows = data_get($run->request_meta, 'payload.candles.row_count');
            if (is_int($requestRows)) $input = $requestRows;
        }
        $expected = is_int($input) && $input >= 0 ? max(0, $input - 200) : null;
        $reasons = [];
        $firstIndex = 200;
        $scope = $this->ownedDecisionTraceScope($response, $run);
        if ($scope !== null) {
            $reasons = [...$reasons, ...$scope['reason_codes']];
            $firstIndex = $scope['first_index'];
            $expected = $scope['decision_rows'];
            if (($producer['input_candle_count'] ?? null) !== $scope['source_rows']
                || ($producer['first_candle_index'] ?? null) !== $firstIndex
                || ($producer['warmup_rows'] ?? null) !== $scope['warmup_rows']
                || ($producer['scope_owner'] ?? null) !== $scope['owner']) {
                $reasons[] = 'DECISION_TRACE_OWNED_CLOCK_MISMATCH';
            }
            $hashValid = $scope['owner'] === 'native_specialist_council_v1'
                ? $this->nativeDecisionTraceHashValid($response, (array) ($scope['native'] ?? []))
                : (is_string($producer['trace_hash'] ?? null) && $events !== null
                    && $producer['trace_hash'] === app(ResearchPaperEpochContractService::class)->parameterHash($trace));
            if (! $hashValid) {
                $reasons[] = 'DECISION_TRACE_HASH_MISMATCH';
            }
            if (! $this->decisionTraceScopesAgree((array) ($producer['evaluated_scope'] ?? []), $scope['scope'])) {
                $reasons[] = 'DECISION_TRACE_SCOPE_COPY_MISMATCH';
            }
        }
        if ($events === null) $reasons[] = 'DECISION_TRACE_NOT_A_LIST';
        if (($producer['protocol'] ?? null) !== 'candle_decision_trace_v1') $reasons[] = 'DECISION_TRACE_PROTOCOL_MISSING_OR_UNSUPPORTED';
        if (($producer['requested'] ?? null) !== true || ($producer['complete'] ?? null) !== true) $reasons[] = 'DECISION_TRACE_PRODUCER_INCOMPLETE';
        if (! is_int($producer['event_count'] ?? null) || $producer['event_count'] < 0 || $producer['event_count'] !== $events) {
            $reasons[] = 'DECISION_TRACE_EVENT_COUNT_MISMATCH';
        }
        if (! is_int($evaluated) || $evaluated < 0) $reasons[] = 'DECISION_TRACE_EVALUATED_COUNT_MISSING';
        if ($expected !== null && $evaluated !== $expected) $reasons[] = 'DECISION_TRACE_EXPECTED_CANDLE_COUNT_MISMATCH';
        $covered = [];
        if ($events !== null) {
            foreach ($trace as $event) {
                if (! is_array($event)) {
                    $reasons[] = 'DECISION_TRACE_EVENT_INVALID';
                    continue;
                }
                if (! in_array($event['event_type'] ?? null, ['signal_evaluation', 'position_management'], true)) continue;
                if (! is_int($event['candle_index'] ?? null) || $event['candle_index'] < $firstIndex
                    || ! is_string($event['candle_time'] ?? null) || $event['candle_time'] === '') {
                    $reasons[] = 'DECISION_TRACE_CANDLE_IDENTITY_MISSING';
                    continue;
                }
                if ($scope !== null && ! $this->ownedTraceEventCurrent($event, $scope)) {
                    $reasons[] = 'DECISION_TRACE_OWNED_EVENT_IDENTITY_MISMATCH';
                }
                $covered[$event['candle_index']] = true;
            }
        }
        $coverage = count($covered);
        if (is_int($evaluated) && $evaluated >= 0) {
            if ($coverage !== $evaluated || ($coverage > 0 && (min(array_keys($covered)) !== $firstIndex
                || max(array_keys($covered)) !== $firstIndex - 1 + $evaluated))) {
                $reasons[] = 'DECISION_TRACE_CANDLE_COVERAGE_MISMATCH';
            }
            if ($evaluated === 0 && $events !== 0) $reasons[] = 'DECISION_TRACE_ZERO_COVERAGE_HAS_EVENTS';
        }
        $reasons = array_values(array_unique($reasons));

        return ['protocol' => 'decision_trace_producer_verified_v1', 'complete' => $reasons === [],
            'reason_codes' => $reasons, 'event_count' => $events, 'evaluated_candle_count' => $evaluated,
            'covered_candle_count' => $coverage, 'producer_protocol' => $producer['protocol'] ?? null,
            'expected_evaluated_candle_count' => $expected,
            'first_candle_index' => $firstIndex, 'scope_owner' => $scope['owner'] ?? 'legacy_200_candle_clock',
            'requested' => $producer['requested'] ?? null, 'producer_complete' => $producer['complete'] ?? null,
            'audit_slice' => ($producer['audit_slice'] ?? false) === true, 'promotion_evidence' => false];
    }

    /** Scope comes from the original immutable request and native receipt. */
    private function ownedDecisionTraceScope(array $response, ?LabEvaluationRun $run): ?array
    {
        $producer = (array) data_get($response, 'data_quality.decision_trace', []);
        $native = data_get($response, 'specialist_council_receipt');
        $qualityNative = data_get($response, 'data_quality.specialist_council_receipt');
        $marker = data_get($run?->request_meta, 'payload.policy_context.specialist_council_authorized_arm');
        // Standalone ordinary diagnostics keep the legacy clock. A persisted
        // run must inspect its one immutable request before selecting legacy;
        // removing response hints or mutable projections cannot erase an
        // original native/armed declaration or an unavailable modern owner.
        if ($run === null && $native === null && $qualityNative === null && $marker === null
            && ! isset($producer['scope_owner'])) return null;
        $result = ['reason_codes' => [], 'owner' => is_array($native) ? 'native_specialist_council_v1' : 'authorized_original_council_arm_v1',
            'first_index' => 1, 'decision_rows' => null, 'source_rows' => null, 'warmup_rows' => null,
            'scope' => [], 'native' => null, 'timeframe' => ''];
        try {
            if ($run === null) throw new RuntimeException('ORIGINAL_REQUEST_REQUIRED');
            $artifacts = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_request')->limit(2)->get();
            if ($artifacts->count() !== 1 || data_get($artifacts[0]->metadata, 'request_hash') !== $run->request_hash) {
                throw new RuntimeException('ORIGINAL_REQUEST_ARTIFACT_REQUIRED');
            }
            $request = $this->readArtifactPayload($artifacts[0]);
            if (! is_array($request)) throw new RuntimeException('ORIGINAL_REQUEST_BYTES_REQUIRED');
            $contracts = [];
            $nativeRequested = array_key_exists('specialist_council_contract', $request);
            if (is_array($request['specialist_council_contract'] ?? null)) $contracts[] = $request['specialist_council_contract'];
            foreach ((array) ($request['strategies'] ?? []) as $candidate) {
                if (! is_array($candidate)
                    || (isset($candidate['lab_agent_id']) && (int) $candidate['lab_agent_id'] !== (int) $run->lab_agent_id)) continue;
                if (array_key_exists('specialist_council_contract', $candidate)) $nativeRequested = true;
                if (is_array($candidate['specialist_council_contract'] ?? null)) $contracts[] = $candidate['specialist_council_contract'];
            }
            // Optional response dictionaries serialize as {} and decode as [].
            // Only the original request can establish that this is an absent
            // native claim; an explicit malformed request declaration still
            // requires a real native producer and never becomes solo evidence.
            if (! $nativeRequested && ($native === null || $native === [])
                && ($qualityNative === null || $qualityNative === [])) {
                $native = null;
                $result['owner'] = 'authorized_original_council_arm_v1';
            } elseif (! $nativeRequested || ! is_array($native) || $native === []) {
                throw new RuntimeException('NATIVE_REQUEST_RECEIPT_OWNER_MISMATCH');
            }
            $epochs = app(ResearchPaperEpochContractService::class);
            $declared = data_get($request, 'policy_context.specialist_council_authorized_arm');
            $signed = data_get($request, 'policy_context.authorized_research_transport.original_council_arm');
            if ($native === null && $declared === null && $signed === null && ! isset($producer['scope_owner'])) return null;
            $expectedScope = null;
            $sourceRows = null;
            if ($declared !== null || $signed !== null) {
                if (! is_array($declared) || ! is_array($signed) || ($request['evaluation_mode'] ?? null) !== 'full'
                    || ($signed['protocol'] ?? null) !== 'authorized_original_council_arm_v1'
                    || ($signed['independent_evidence'] ?? null) !== false || ($signed['promotion_evidence'] ?? null) !== false) {
                    throw new RuntimeException('ORIGINAL_ARM_OWNER_INVALID');
                }
                $expectedScope = json_decode($signed['evaluation_scope_json'] ?? '', true, flags: JSON_THROW_ON_ERROR);
                $policy = (array) data_get($request, 'policy_context.full_replay_runtime_policy', []);
                foreach (['plan_hash', 'arm_key', 'window_key', 'model_hash'] as $key) {
                    if (($declared[$key] ?? null) !== ($signed[$key] ?? null)) throw new RuntimeException('ORIGINAL_ARM_IDENTITY_DRIFT');
                }
                if (! is_array($expectedScope) || ! $this->decisionTraceScopesAgree((array) ($declared['evaluation_scope'] ?? []), $expectedScope)
                    || ($expectedScope['policy_hash'] ?? null) !== $epochs->parameterHash($policy)
                    || ($expectedScope['warmup_rows'] ?? null) !== 0) throw new RuntimeException('ORIGINAL_ARM_SCOPE_DRIFT');
                $sourceRows = data_get($request, 'policy_context.authorized_research_transport.files.'.($request['timeframe'] ?? '').'.rows');
            }
            if (is_array($native)) {
                app(SpecialistCouncilLifecycleService::class)->assertReceiptSeal($native);
                $owned = array_filter($contracts, fn (array $contract): bool =>
                    ($contract['protocol'] ?? null) === 'specialist_council_runtime_v1'
                    && ($contract['contract_hash'] ?? null) === ($native['contract_hash'] ?? null));
                if (count($owned) !== 1 || ($native['protocol'] ?? null) !== 'specialist_council_receipt_v1'
                    || ($native['dataset_hash'] ?? null) !== ($request['replay_dataset_hash'] ?? null)) {
                    throw new RuntimeException('NATIVE_REQUEST_RECEIPT_OWNER_MISMATCH');
                }
                $nativeScope = (array) ($native['evaluated_scope'] ?? []);
                if ($expectedScope !== null && ! $this->decisionTraceScopesAgree($nativeScope, $expectedScope)) {
                    throw new RuntimeException('NATIVE_ORIGINAL_SCOPE_DRIFT');
                }
                $expectedScope ??= $nativeScope;
                $sourceRows ??= $native['source_rows'] ?? null;
                if ($sourceRows !== ($native['source_rows'] ?? null)) throw new RuntimeException('NATIVE_SOURCE_ROW_DRIFT');
                $probe = data_get($request, 'policy_context.prospective_probe_window');
                $policy = is_array($probe) ? $probe : data_get($request, 'policy_context.full_replay_runtime_policy');
                if (is_array($probe) && (($probe['loaded_rows'] ?? null) !== $sourceRows
                    || ($probe['warmup_rows'] ?? null) !== ($expectedScope['warmup_rows'] ?? null)
                    || ($probe['evaluated_rows'] ?? null) !== ($expectedScope['rows'] ?? null))) {
                    throw new RuntimeException('NATIVE_PROBE_CLOCK_DRIFT');
                }
                if (is_array($probe)) {
                    $seconds = ['M1' => 60, 'M5' => 300, 'M15' => 900, 'M30' => 1800,
                        'H1' => 3600, 'H4' => 14400, 'D1' => 86400][$request['timeframe'] ?? ''] ?? null;
                    if ($seconds === null || ! CarbonImmutable::parse($expectedScope['start_inclusive'])->equalTo(CarbonImmutable::parse($probe['evaluated_start']))
                        || ! CarbonImmutable::parse($expectedScope['end_exclusive'])->equalTo(CarbonImmutable::parse($probe['evaluated_end'])->addSeconds($seconds))) {
                        throw new RuntimeException('NATIVE_PROBE_BOUNDS_DRIFT');
                    }
                } elseif (($request['evaluation_mode'] ?? 'full') !== 'incremental'
                    && (($expectedScope['warmup_rows'] ?? null) !== 0 || ($expectedScope['rows'] ?? null) !== $sourceRows)) {
                    throw new RuntimeException('NATIVE_FULL_CLOCK_DRIFT');
                }
                if (! is_array($policy) && ($request['evaluation_mode'] ?? null) === 'incremental') {
                    $policy = $nativeScope['selector_policy'] ?? null;
                    $limit = $sourceRows >= 5000 ? 5000 : 2000;
                    if (($expectedScope['rows'] ?? null) !== min($sourceRows, $limit)
                        || ($expectedScope['warmup_rows'] ?? null) !== max(0, $sourceRows - $limit)) {
                        throw new RuntimeException('NATIVE_TAIL_CLOCK_DRIFT');
                    }
                }
                if (($expectedScope['policy_hash'] ?? null) !== (is_array($policy) ? $epochs->parameterHash($policy) : null)) {
                    throw new RuntimeException('NATIVE_SCOPE_POLICY_DRIFT');
                }
                $identity = (array) ($native['decision_trace_identity'] ?? []);
                if (($identity['protocol'] ?? null) !== 'native_council_decision_trace_v1'
                    || ($identity['contract_hash'] ?? null) !== $native['contract_hash']
                    || ($identity['trace_hash'] ?? null) !== ($producer['trace_hash'] ?? null)
                    || ($identity['decision_rows'] ?? null) !== ($expectedScope['decision_rows'] ?? null)
                    || ($identity['warmup_rows'] ?? null) !== ($expectedScope['warmup_rows'] ?? null)
                    || ($identity['source_rows'] ?? null) !== $sourceRows
                    || ($identity['first_candle_index'] ?? null) !== ($expectedScope['warmup_rows'] ?? 0) + 1
                    || ($identity['last_candle_index'] ?? null) !== $sourceRows - 1
                    || ($identity['scope_policy_hash'] ?? null) !== ($expectedScope['policy_hash'] ?? null)) {
                    throw new RuntimeException('NATIVE_TRACE_RECEIPT_DRIFT');
                }
                $result['native'] = $native;
            }
            if (! is_array($expectedScope) || ! is_int($sourceRows) || $sourceRows < 2
                || ! is_int($expectedScope['rows'] ?? null) || $expectedScope['rows'] < 2
                || ! is_int($expectedScope['warmup_rows'] ?? null) || $expectedScope['warmup_rows'] < 0
                || $expectedScope['rows'] + $expectedScope['warmup_rows'] !== $sourceRows
                || ($expectedScope['decision_rows'] ?? null) !== $expectedScope['rows'] - 1) {
                throw new RuntimeException('OWNED_SCOPE_ROW_BUDGET_INVALID');
            }
            if (! $this->decisionTraceScopesAgree((array) data_get($response, 'data_quality.replay_evaluation_scope', []), $expectedScope)) {
                throw new RuntimeException('OWNED_RESPONSE_SCOPE_DRIFT');
            }
            $result = [...$result, 'scope' => $expectedScope, 'source_rows' => $sourceRows,
                'decision_rows' => $expectedScope['decision_rows'], 'warmup_rows' => $expectedScope['warmup_rows'],
                'first_index' => $expectedScope['warmup_rows'] + 1, 'timeframe' => $request['timeframe'] ?? ''];
        } catch (Throwable $error) {
            $result['reason_codes'][] = 'DECISION_TRACE_OWNED_SCOPE_INVALID:'.$error->getMessage();
        }
        return $result;
    }

    private function decisionTraceScopesAgree(array $left, array $right): bool
    {
        foreach (['rows', 'decision_rows', 'warmup_rows', 'policy_hash'] as $key) {
            if (! array_key_exists($key, $left) || ! array_key_exists($key, $right) || $left[$key] !== $right[$key]) return false;
        }
        try {
            return CarbonImmutable::parse($left['start_inclusive'])->equalTo(CarbonImmutable::parse($right['start_inclusive']))
                && CarbonImmutable::parse($left['end_exclusive'])->equalTo(CarbonImmutable::parse($right['end_exclusive']));
        } catch (Throwable) { return false; }
    }

    private function ownedTraceEventCurrent(array $event, array $owned): bool
    {
        try {
            $scope = $owned['scope'];
            $time = CarbonImmutable::parse($event['candle_time']);
            $seconds = ['M1' => 60, 'M5' => 300, 'M15' => 900, 'M30' => 1800,
                'H1' => 3600, 'H4' => 14400, 'D1' => 86400][$owned['timeframe']] ?? null;
            if ($seconds === null || $time->lessThanOrEqualTo(CarbonImmutable::parse($scope['start_inclusive']))
                || ! $time->lessThan(CarbonImmutable::parse($scope['end_exclusive']))) return false;
            if ($event['candle_index'] === $owned['first_index'] + $owned['decision_rows'] - 1
                && ! $time->equalTo(CarbonImmutable::parse($scope['end_exclusive'])->subSeconds($seconds))) return false;
            if ($owned['native'] === null) return true;
            $native = $owned['native']; $clock = (array) ($event['source_clock'] ?? []);
            $epochs = app(ResearchPaperEpochContractService::class);
            if (! $this->isSha256((string) ($event['closed_source_inputs_hash'] ?? ''))
                || ! is_int($event['closed_source_input_columns'] ?? null)
                || $event['closed_source_input_columns'] < count((array) ($event['features'] ?? []))
                || ($event['decision_id'] ?? null) !== $epochs->parameterHash($clock)
                || ($clock['contract_hash'] ?? null) !== $native['contract_hash']
                || ($clock['dataset_hash'] ?? null) !== $native['dataset_hash']
                || ($clock['source_sha256'] ?? null) !== data_get($native, 'source_attestation.actual_source_sha256', '')
                || ($clock['candle_index'] ?? null) !== $event['candle_index']
                || ($clock['execution_time'] ?? null) !== $event['candle_time']
                || ($event['execution_time'] ?? null) !== $event['candle_time']
                || ($clock['signal_time'] ?? null) !== ($event['signal_time'] ?? null)
                || ($clock['decision_at'] ?? null) !== ($event['decision_at'] ?? null)) return false;
            $signal = CarbonImmutable::parse($event['signal_time']); $decision = CarbonImmutable::parse($event['decision_at']);
            if (! $signal->addSeconds($seconds)->equalTo($decision) || $decision->greaterThan($time)) return false;
            if ($event['candle_index'] === $owned['first_index']
                && ! $signal->equalTo(CarbonImmutable::parse($scope['start_inclusive']))) return false;
            $members = array_column((array) ($native['members'] ?? []), 'specialist_id', 'member_version_hash');
            $observed = $event['member_decisions'] ?? null;
            if (! is_array($observed) || ! array_is_list($observed) || $observed === []) return false;
            foreach ($observed as $member) {
                $hash = $member['member_version_hash'] ?? '';
                if (! $this->isSha256((string) ($member['closed_inputs_hash'] ?? ''))
                    || ! is_int($member['closed_input_columns'] ?? null)
                    || $member['closed_input_columns'] < count((array) ($member['closed_inputs'] ?? []))
                    || ($members[$hash] ?? null) !== ($member['specialist_id'] ?? null)
                    || ($member['decision_id'] ?? null) !== $epochs->parameterHash([
                        'account_decision_id' => $event['decision_id'], 'member_version_hash' => $hash])
                    || data_get($member, 'closed_inputs.time') !== $event['signal_time']) return false;
            }
            return true;
        } catch (Throwable) { return false; }
    }

    /**
     * Read the persisted, terminal evidence chain.  This is intentionally
     * stricter than replayEvidenceCompleteness(): learning may start only
     * after the response, trace manifest and ledger artifact are durable.
     *
     * @return array{complete: bool, reason_codes: array<int, string>, run_id: ?string, promotion_evidence: bool}
     */
    public function learningEligibility(LabEvaluationRun|string|null $run): array
    {
        if (is_string($run)) $run = $this->findRun($run);
        if (! $run) {
            return [
                'complete' => false,
                'reason_codes' => ['MISSING_EVIDENCE_RUN'],
                'run_id' => null,
                'promotion_evidence' => false,
            ];
        }

        $artifacts = LabEvidenceArtifact::query()->where('run_id', $run->run_id)->get();
        $hasArtifact = fn (string $type): bool => $artifacts->contains(fn (LabEvidenceArtifact $artifact): bool => $artifact->artifact_type === $type);
        $traceArtifact = $artifacts->first(fn (LabEvidenceArtifact $artifact): bool => $artifact->artifact_type === 'decision_trace');
        $traceManifest = $artifacts->first(fn (LabEvidenceArtifact $artifact): bool => $artifact->artifact_type === 'decision_trace_manifest');
        $responseArtifact = $artifacts->first(fn (LabEvidenceArtifact $artifact): bool => $artifact->artifact_type === 'evaluation_response');
        $ledgerArtifact = $artifacts->first(fn (LabEvidenceArtifact $artifact): bool => in_array($artifact->artifact_type, ['trade_ledger', 'trade_ledger_manifest'], true));
        $responseMeta = (array) $run->response_meta;
        $reasons = [];
        if ($run->status !== 'completed') $reasons[] = 'EVIDENCE_RUN_NOT_COMPLETED';
        if (! $hasArtifact('evaluation_request') || ! filled($run->request_hash)) $reasons[] = 'MISSING_EVALUATION_REQUEST_ARTIFACT';
        if (! $this->isSha256((string) $run->data_hash) || ! $this->requestHasDatasetHash($run)) $reasons[] = 'MISSING_DATASET_HASH';
        if (! $hasArtifact('evaluation_response') || ! filled($run->response_hash)) $reasons[] = 'MISSING_EVALUATION_RESPONSE_ARTIFACT';
        $traceComplete = $traceArtifact
            && data_get($traceArtifact->metadata, 'complete') === true
            && data_get($traceManifest?->metadata, 'complete') === true;
        $traceProof = (array) data_get($responseMeta, 'decision_trace_completeness', []);
        if ($traceProof !== []) {
            // New receipts bind the producer's coverage, response bytes and
            // exact trace artifact. Zero trades do not imply zero decisions.
            $traceComplete = $traceComplete && data_get($traceProof, 'complete') === true
                && ($traceProof['protocol'] ?? null) === 'decision_trace_producer_verified_v1'
                && ($traceProof['requested'] ?? null) === true
                && ($traceProof['producer_complete'] ?? null) === true
                && is_int($traceProof['event_count'] ?? null)
                && $traceProof['event_count'] >= 0
                && is_int($traceProof['evaluated_candle_count'] ?? null)
                && $traceProof['evaluated_candle_count'] >= 0
                && ($traceProof['covered_candle_count'] ?? null) === $traceProof['evaluated_candle_count']
                && $traceProof['event_count'] >= $traceProof['covered_candle_count']
                && data_get($traceArtifact?->metadata, 'producer_proof') === $traceProof
                && data_get($traceManifest?->metadata, 'producer_proof') === $traceProof
                && $responseArtifact?->sha256 === $run->response_hash
                && data_get($responseMeta, 'decision_trace_count') === $traceProof['event_count']
                && data_get($traceArtifact?->metadata, 'event_count') === $traceProof['event_count']
                && data_get($traceManifest?->metadata, 'event_count') === $traceProof['event_count']
                && data_get($traceManifest?->metadata, 'result_hash') === $run->response_hash
                && data_get($traceManifest?->metadata, 'artifact_sha256') === $traceArtifact?->sha256
                && data_get($responseMeta, 'decision_trace_hash') === $traceArtifact?->sha256;
        } else {
            // Historical immutable receipts are not rewritten/backfilled.
            // Retain their existing nonempty-trace contract; an old empty
            // list has no attested coverage and must remain incomplete.
            $traceComplete = $traceComplete && (int) data_get($traceArtifact?->metadata, 'event_count', 0) > 0;
        }
        if (! $traceComplete) {
            $reasons[] = 'MISSING_COMPLETE_DECISION_TRACE';
        }
        if (! $ledgerArtifact || data_get($ledgerArtifact->metadata, 'complete') !== true) $reasons[] = 'MISSING_COMPLETE_TRADE_LEDGER';
        if (data_get($responseMeta, 'decision_trace_present') !== true || data_get($responseMeta, 'trade_ledger_complete') !== true) {
            $reasons[] = 'RESPONSE_MANIFEST_INCOMPLETE';
        }
        $seal = (array) data_get($run->request_meta, 'payload.research_release',
            data_get($run->generation?->trigger_context, 'research_release', []));
        if (! app(ResearchReleaseSealService::class)->responseValid($seal,
            (array) data_get($responseMeta, 'research_release_receipt', []))) {
            $reasons[] = 'RESEARCH_WORKER_RELEASE_RECEIPT_INVALID';
        }

        return [
            'complete' => $reasons === [],
            'reason_codes' => array_values(array_unique($reasons)),
            'run_id' => $run->run_id,
            'decision_trace_proof' => $traceProof === [] ? 'legacy_persisted_nonempty_trace' : ($traceProof['protocol'] ?? 'unknown'),
            'promotion_evidence' => false,
        ];
    }

    public function recordLifecycle(
        ?LabAgent $agent,
        string $eventType,
        array $payload = [],
        ?string $phase = null,
        ?string $runId = null,
        ?int $attempt = null,
        ?string $source = null,
        ?Throwable $error = null,
        ?string $fromStatus = null,
        ?string $toStatus = null,
    ): LabLifecycleEvent {
        $agent?->loadMissing('generation');

        return LabLifecycleEvent::create([
            'event_id' => (string) Str::uuid(),
            'lab_generation_id' => $agent?->lab_generation_id ?? ($payload['generation_id'] ?? null),
            'lab_agent_id' => $agent?->id,
            'run_id' => $runId,
            'phase' => $phase,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'attempt' => $attempt,
            'source' => $source,
            'reason_code' => $payload['reason_code'] ?? $payload['reason'] ?? null,
            'error_class' => $error ? $error::class : ($payload['error_class'] ?? null),
            'error_message' => $error ? substr($error->getMessage(), 0, 4000) : ($payload['error_message'] ?? null),
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }

    public function recordAgentCreated(LabAgent $agent): void
    {
        $this->recordLifecycle($agent, 'agent_created', [
            'origin' => $agent->origin, 'strategy_family' => $agent->strategy_family,
            'parameter_diff_hash' => $this->hash($agent->parameter_diff ?? []),
        ], 'creation', null, null, 'LabAgentObserver', null, null, $agent->lifecycle_status);
    }

    public function recordAgentStatusChanged(LabAgent $agent, ?string $from, ?string $to, string $source = 'LabAgentObserver'): void
    {
        if ($from === $to) {
            return;
        }
        $this->recordLifecycle($agent, 'status_changed', [
            'source_projection' => 'lab_agents.lifecycle_status',
        ], $this->phaseForStatus($to), null, null, $source, null, $from, $to);
    }

    /**
     * Creates a clearly labelled, non-fictional bridge for pre-ledger rows.
     * It preserves the old snapshot and tells the audit that exact retry
     * history is unavailable for that period.
     */
    public function backfillLegacySnapshot(LabAgent $agent): LabEvaluationRun
    {
        $agent->loadMissing('generation', 'modelVersion');
        $snapshotHash = $this->legacySnapshotHash($agent);
        $run = LabEvaluationRun::create([
            'run_id' => (string) Str::uuid(),
            'lab_generation_id' => $agent->lab_generation_id,
            'lab_agent_id' => $agent->id,
            'model_version_id' => $agent->model_version_id,
            'phase' => 'legacy_backfill', 'mode' => 'snapshot', 'attempt' => 1,
            'status' => 'legacy_snapshot',
            'started_at' => $agent->created_at ?? now(),
            'finished_at' => $agent->updated_at ?? now(),
            'code_hash' => null, 'parameter_hash' => $this->parameterHash($agent),
            'metadata' => [
                'historical' => true, 'completeness' => 'snapshot_only',
                'snapshot_hash' => $snapshotHash,
                'rule' => 'Backfill never claims that missing retries or runtime events existed.',
            ],
        ]);
        $this->recordLifecycle($agent, 'legacy_agent_snapshot', [
            'run_id' => $run->run_id, 'lifecycle_status' => $agent->lifecycle_status,
            'completeness' => 'snapshot_only', 'historical' => true,
        ], 'legacy_backfill', $run->run_id, 1, 'lab-backfill-immutable-evidence', null, null, $agent->lifecycle_status);
        foreach ([
            'last_screen_result' => data_get($agent->modelVersion?->metadata, 'last_screen_result'),
            'last_result' => data_get($agent->modelVersion?->metadata, 'last_result'),
        ] as $type => $snapshot) {
            if (is_array($snapshot) && $snapshot !== []) {
                $this->recordArtifact($run, 'legacy_'.$type, $snapshot, [
                    'historical' => true, 'completeness' => 'snapshot_only',
                ]);
            }
        }

        return $run;
    }

    public function legacySnapshotHash(LabAgent $agent): string
    {
        $agent->loadMissing('modelVersion');

        return $this->hash([
            'lifecycle_status' => $agent->lifecycle_status,
            'decision_reason' => $agent->decision_reason,
            'sample_count' => $agent->sample_count,
            'profit_factor' => $agent->profit_factor,
            'last_screen_result' => data_get($agent->modelVersion?->metadata, 'last_screen_result'),
            'last_result' => data_get($agent->modelVersion?->metadata, 'last_result'),
        ]);
    }

    public function recordHandoff(LabGeneration $generation, ?LabAgent $agent, string $stage, string $status, ?string $reason, array $payload = []): void
    {
        $agent ??= null;
        $this->recordLifecycle($agent, 'handoff_'.$stage, [
            'generation_id' => $generation->id, 'stage' => $stage, 'status' => $status,
            'terminal_reason' => $reason, 'handoff_payload' => $payload,
        ], $stage, $payload['evidence_run_id'] ?? null, null, 'CandidateHandoffService');
    }

    public function recordGateDecision(CandidateGateDecision $decision, array $payload = [], ?string $runId = null): LabGateDecisionEvent
    {
        $decision->loadMissing('labAgent', 'performance');
        $agent = $decision->labAgent;
        if (! $agent && $decision->performance) {
            $agent = LabAgent::query()->where('model_version_id', $decision->performance->model_version_id)
                ->where('symbol', $decision->performance->symbol)->where('timeframe', $decision->performance->timeframe)
                ->latest('id')->first();
        }
        $generationId = $agent?->lab_generation_id;
        $revisionQuery = LabGateDecisionEvent::query()->where('stage', $decision->stage)
            ->where('model_market_performance_id', $decision->model_market_performance_id)
            ->where('lab_agent_id', $decision->lab_agent_id);
        $revision = $revisionQuery->count() + 1;

        return LabGateDecisionEvent::create([
            'current_decision_id' => $decision->id,
            'model_market_performance_id' => $decision->model_market_performance_id,
            'lab_generation_id' => $generationId,
            'lab_agent_id' => $decision->lab_agent_id ?: $agent?->id,
            'run_id' => $runId,
            'stage' => $decision->stage,
            'decision' => $decision->decision,
            'revision' => $revision,
            'attribution_status' => $decision->attribution_status,
            'reason_codes' => $decision->reason_codes,
            'metrics' => $decision->metrics,
            'payload' => $payload,
            'recorded_at' => $decision->evaluated_at ?? now(),
        ]);
    }

    public function recordMutationCredit(MutationMemory $memory, array $payload = [], ?string $runId = null): LabMutationCreditEvent
    {
        $memory->loadMissing('labAgent.generation', 'labAgent.modelVersion');
        $agent = $memory->labAgent;
        $effect = (array) $memory->behavioral_effect;
        $credit = (array) data_get($effect, 'causal_credit', []);
        $bundle = $payload['mutation_bundle_id'] ?? data_get($agent?->modelVersion?->metadata, 'mutation_bundle');
        if (is_array($bundle)) {
            $bundle = $this->hash($bundle);
        }
        $evidenceRunIds = array_values(array_unique(array_filter([
            $runId,
            ...((array) ($payload['evidence_run_ids']
                ?? data_get($payload, 'verified_skill_contract.evidence_run_ids', [])
                ?? data_get($payload, 'paired_experiment.evidence_run_ids', [])
                ?? data_get($credit, 'evidence_run_ids', []))),
        ])));
        sort($evidenceRunIds);
        $primaryEvidenceRunId = $runId
            ?: ($payload['primary_evidence_run_id'] ?? data_get($credit, 'primary_evidence_run_id'))
            ?: ($evidenceRunIds[0] ?? null);
        $parentIds = $agent
            ? app(ParentContributionGraphService::class)->ids($agent)
            : array_values(array_filter(array_map('intval', (array) ($payload['parent_model_version_ids'] ?? []))));
        $eventPayload = [
            ...$payload,
            'gate_transition' => $memory->gate_transition,
            'behavioral_effect' => $memory->behavioral_effect,
            'old_value' => $memory->old_value,
            'new_value' => $memory->new_value,
            'market_regime' => $memory->market_regime,
            'direction' => $memory->direction,
            'volatility_regime' => $memory->volatility_regime,
            'parent_model_version_ids' => $parentIds,
            'primary_evidence_run_id' => $primaryEvidenceRunId,
        ];
        $temporalWindowKey = $this->temporalWindowKey($eventPayload);
        $eventPayload['temporal_window_key'] = $temporalWindowKey;
        $reconciliationKey = $this->hash([
            'protocol' => 'mutation_credit_reconciliation_v1',
            'generation_id' => $agent?->lab_generation_id,
            'agent_id' => $memory->lab_agent_id,
            'mutation_memory_id' => $memory->id,
            'parameter_key' => $memory->parameter_key,
            'outcome' => (string) $memory->outcome,
            'primary_evidence_run_id' => $primaryEvidenceRunId,
            'temporal_window_key' => $temporalWindowKey,
        ]);
        $fingerprint = $this->hash([
            'protocol' => 'lab_mutation_credit_event_v2',
            'mutation_memory_id' => $memory->id,
            'lab_agent_id' => $memory->lab_agent_id,
            'model_market_performance_id' => $payload['model_market_performance_id'] ?? null,
            'parameter_key' => $memory->parameter_key,
            'mutation_bundle_id' => $bundle,
            'outcome' => (string) $memory->outcome,
            'parent_model_version_id' => $payload['parent_model_version_id'] ?? $agent?->parent_a_model_version_id ?? data_get($credit, 'parent_model_version_id'),
            'control_model_version_id' => $payload['control_model_version_id'] ?? data_get($credit, 'alternative_model_version_id'),
            'evidence_run_ids' => $evidenceRunIds,
            'source' => $payload['source'] ?? null,
            'causal_credit_status' => data_get($credit, 'status'),
            'stable_payload' => $this->stableEvidenceValue($eventPayload),
        ]);

        return LabMutationCreditEvent::query()->firstOrCreate(['reconciliation_key' => $reconciliationKey], [
            'mutation_memory_id' => $memory->id,
            'lab_generation_id' => $agent?->lab_generation_id,
            'lab_agent_id' => $memory->lab_agent_id,
            'model_version_id' => $agent?->model_version_id,
            'model_market_performance_id' => $payload['model_market_performance_id'] ?? null,
            'parameter_key' => $memory->parameter_key,
            'mutation_bundle_id' => $bundle,
            'outcome' => (string) $memory->outcome,
            'forward_delta' => $memory->forward_delta,
            'parent_model_version_id' => $payload['parent_model_version_id'] ?? $agent?->parent_a_model_version_id ?? data_get($credit, 'parent_model_version_id'),
            'control_model_version_id' => $payload['control_model_version_id'] ?? data_get($credit, 'alternative_model_version_id'),
            'evidence_run_ids' => $evidenceRunIds,
            'temporal_window_key' => $temporalWindowKey,
            'reconciliation_key' => $reconciliationKey,
            'evidence_fingerprint' => $fingerprint,
            'payload' => $eventPayload,
            'recorded_at' => now(),
        ]);
    }

    private function stableEvidenceValue(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        $stable = [];
        foreach ($value as $key => $item) {
            if (in_array((string) $key, ['reconciled_at', 'recorded_at', 'updated_at'], true)) continue;
            $stable[$key] = $this->stableEvidenceValue($item);
        }
        if (! array_is_list($stable)) ksort($stable);

        return $stable;
    }

    private function temporalWindowKey(array $payload): string
    {
        $ids = collect([
            ...((array) data_get($payload, 'temporal_window_ids', [])),
            ...((array) data_get($payload, 'verified_mutation_skill.independent_forward_windows.window_ids', [])),
            ...((array) data_get($payload, 'verified_skill_contract.independent_forward_windows.window_ids', [])),
            ...((array) data_get($payload, 'paired_experiment.independent_forward_windows.window_ids', [])),
            ...((array) data_get($payload, 'behavioral_effect.verified_mutation_skill.independent_forward_windows.window_ids', [])),
            ...((array) data_get($payload, 'behavioral_effect.causal_credit.temporal_window_ids', [])),
        ])->filter(fn ($id): bool => filled($id))->map(fn ($id): string => (string) $id)->unique()->sort()->values()->all();
        $explicit = data_get($payload, 'temporal_window_key');
        if (is_string($explicit) && trim($explicit) !== '') return trim($explicit);
        if ($ids !== []) return $this->hash(['protocol' => 'temporal_window_set_v1', 'window_ids' => $ids]);

        $bounds = [
            'start' => data_get($payload, 'temporal_window.start', data_get($payload, 'window_start')),
            'end' => data_get($payload, 'temporal_window.end', data_get($payload, 'window_end')),
        ];
        if (filled($bounds['start']) || filled($bounds['end'])) {
            return $this->hash(['protocol' => 'temporal_window_bounds_v1', 'bounds' => $bounds]);
        }

        return 'missing';
    }

    public function recordArtifact(?LabEvaluationRun $run, string $type, array $payload, array $metadata = [], ?LabAgent $agent = null, ?string $runId = null): LabEvidenceArtifact
    {
        $agent ??= $run?->agent;
        $agent?->loadMissing('generation');
        $encoded = $this->encode($payload);
        $artifactId = (string) Str::uuid();
        $compressed = gzencode($encoded, 6);
        if ($compressed === false) {
            throw new RuntimeException('Evidence payloadini gzip qilish muvaffaqiyatsiz tugadi.');
        }

        $relativePath = sprintf(
            'lab-evidence/%s/%s/%s.json.gz',
            now()->format('Y/m'),
            preg_replace('/[^a-z0-9_-]+/i', '-', $type) ?: 'artifact',
            $artifactId,
        );
        $this->writeArtifact($relativePath, $compressed);

        try {
            return LabEvidenceArtifact::create([
                'artifact_id' => $artifactId,
                'run_id' => $run?->run_id ?? $runId,
                'lab_generation_id' => $run?->lab_generation_id ?? $agent?->lab_generation_id,
                'lab_agent_id' => $run?->lab_agent_id ?? $agent?->id,
                'artifact_type' => $type,
                'sha256' => hash('sha256', $encoded),
                'byte_size' => strlen($compressed),
                'content_encoding' => 'json+gzip',
                'storage_path' => $relativePath,
                'payload' => null,
                'metadata' => [
                    'protocol' => 'lab_immutable_evidence_v1',
                    'storage_protocol' => 'compressed_artifact_v2',
                    'storage_disk' => $this->artifactDisk(),
                    'uncompressed_byte_size' => strlen($encoded),
                    ...$metadata,
                ],
                'recorded_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $this->deleteArtifact($relativePath);
            throw $exception;
        }
    }

    /** Read both new compressed artifacts and legacy inline payloads. */
    public function readArtifactPayload(LabEvidenceArtifact $artifact): ?array
    {
        if ($artifact->storage_path) {
            $contents = $this->readArtifact((string) $artifact->storage_path);
            if (str_contains((string) $artifact->content_encoding, 'gzip')) {
                $contents = gzdecode($contents) ?: '';
            }
            // The writer seals the original JSON bytes before compression.
            // Re-encoding a decoded JSON object can change key shape/order
            // without changing those immutable bytes (notably numeric keys).
            $rawHash = hash('sha256', $contents);
            $decoded = json_decode($contents, true);
            if (! is_array($decoded)) {
                throw new RuntimeException("Evidence artifact JSON yaroqsiz: {$artifact->artifact_id}");
            }
            $decodedHash = hash('sha256', $this->encode($decoded));
            $roundtripHash = (string) data_get($artifact->metadata, 'payload_roundtrip_sha256', '');
            $modernArtifact = (string) data_get($artifact->metadata, 'storage_protocol', '')
                === 'compressed_artifact_v2';
            if (! hash_equals((string) $artifact->sha256, $rawHash)
                && ($modernArtifact || (! hash_equals((string) $artifact->sha256, $decodedHash)
                    && ! ($roundtripHash !== '' && hash_equals($roundtripHash, $decodedHash))))) {
                throw new RuntimeException("Evidence artifact hash mismatch: {$artifact->artifact_id}");
            }

            return $decoded;
        }

        return $artifact->payload;
    }

    /** Compare JSON projections without treating 1 and 1.0 as different facts. */
    public function equivalentJsonValue(mixed $left, mixed $right): bool
    {
        if (is_array($left) || is_array($right)) {
            if (! is_array($left) || ! is_array($right) || count($left) !== count($right)) {
                return false;
            }
            foreach ($left as $key => $value) {
                if (! array_key_exists($key, $right)
                    || ! $this->equivalentJsonValue($value, $right[$key])) {
                    return false;
                }
            }

            return true;
        }
        if (is_int($left) && is_int($right)) {
            return $left === $right;
        }
        if ((is_int($left) && is_float($right)) || (is_float($left) && is_int($right))) {
            // JSON storage may normalize 1.0 to 1, but integers above the
            // IEEE-754 exact range must never alias a different identity.
            $integer = is_int($left) ? $left : $right;
            $floating = is_float($left) ? $left : $right;

            return abs($integer) <= 9007199254740991
                && is_finite($floating)
                && (float) $integer === $floating;
        }

        return $left === $right;
    }

    /**
     * Read the latest immutable artifact for a run.  Consumers such as the
     * manual-backtest result page must read the response plane rather than a
     * mutable BacktestRun/Trade/Mistake projection.
     */
    public function latestArtifactPayload(LabEvaluationRun $run, string $type = 'evaluation_response'): ?array
    {
        $artifact = LabEvidenceArtifact::query()
            ->where('run_id', $run->run_id)
            ->where('artifact_type', $type)
            ->latest('id')
            ->first();

        return $artifact ? $this->readArtifactPayload($artifact) : null;
    }

    /**
     * Move one legacy inline artifact to the compressed evidence store while
     * retaining the original hash and artifact id. Safe to call repeatedly.
     */
    public function externalizeLegacyArtifact(LabEvidenceArtifact $artifact): bool
    {
        if ($artifact->storage_path || $artifact->payload === null) {
            return false;
        }

        $encoded = $this->encode((array) $artifact->payload);
        $compressed = gzencode($encoded, 6);
        if ($compressed === false) {
            throw new RuntimeException("Legacy evidence gzip qilinmadi: {$artifact->artifact_id}");
        }
        $expectedHash = (string) $artifact->sha256;
        $roundtripHash = hash('sha256', $encoded);
        $hashMatches = $expectedHash === '' || hash_equals($expectedHash, $roundtripHash);

        $relativePath = sprintf(
            'lab-evidence/%s/%s/%s.json.gz',
            optional($artifact->recorded_at)->format('Y/m') ?: now()->format('Y/m'),
            preg_replace('/[^a-z0-9_-]+/i', '-', $artifact->artifact_type) ?: 'artifact',
            $artifact->artifact_id,
        );
        $this->writeArtifact($relativePath, $compressed);

        try {
            $artifact->update([
                'byte_size' => strlen($compressed),
                'content_encoding' => 'json+gzip',
                'storage_path' => $relativePath,
                'payload' => null,
                'metadata' => [
                    ...(array) $artifact->metadata,
                    'storage_protocol' => 'compressed_artifact_v2',
                    'storage_disk' => $this->artifactDisk(),
                    'uncompressed_byte_size' => strlen($encoded),
                    'legacy_hash_matches_roundtrip' => $hashMatches,
                    'payload_roundtrip_sha256' => $roundtripHash,
                    'externalized_at' => now()->utc()->toIso8601String(),
                ],
            ]);
        } catch (Throwable $exception) {
            $this->deleteArtifact($relativePath);
            throw $exception;
        }

        return true;
    }

    /**
     * Build the bounded mutable projection used by selectors and dashboards.
     * Full trace/ledger data stays in the immutable artifact plane; retaining
     * it in last_screen_result/last_result would make every retry rewrite a
     * huge snapshot and tempt selectors to consume non-versioned evidence.
     */
    public function projectionPayload(array $payload): array
    {
        $trace = data_get($payload, 'decision_trace', data_get($payload, 'candle_decision_trace', data_get($payload, 'decision_events')));
        $ledger = data_get($payload, 'trade_ledger');
        $trades = data_get($payload, 'trades');
        $projected = $payload;
        foreach (['decision_trace', 'candle_decision_trace', 'decision_events', 'trade_ledger', 'trades'] as $key) {
            unset($projected[$key]);
        }
        $projected['observability_manifest'] = [
            'protocol' => 'lab_immutable_evidence_v1',
            'immutable_source_required' => true,
            'decision_trace_present' => is_array($trace),
            'decision_trace_count' => is_array($trace) ? count($trace) : null,
            'decision_trace_hash' => is_array($trace) ? $this->hash($trace) : null,
            'trade_ledger_present' => is_array($ledger),
            'trade_ledger_count' => is_array($ledger) ? count($ledger) : null,
            'trade_ledger_hash' => data_get($payload, 'trade_ledger_hash'),
            'event_ledger_hash' => data_get($payload, 'event_ledger_hash', data_get($payload, 'event_digest.hash')),
            'event_ledger_count' => data_get($payload, 'event_ledger_count', data_get($payload, 'event_digest.count')),
            'event_ledger_categories' => data_get($payload, 'event_ledger_categories', data_get($payload, 'event_digest.categories', [])),
            'trades_present' => is_array($trades),
            'trades_count' => is_array($trades) ? count($trades) : null,
            'trades_hash' => is_array($trades) ? $this->hash($trades) : null,
            'compact_projection' => true,
        ];
        if (is_array($projected['result'] ?? null)) {
            $projected['result'] = $this->projectionPayload($projected['result']);
        }
        if (is_array($projected['leaderboard'] ?? null)) {
            $projected['leaderboard'] = array_map(fn ($row) => is_array($row) ? $this->projectionPayload($row) : $row, $projected['leaderboard']);
        }

        return $projected;
    }

    /**
     * Persist every returned decision row when the Python contract supplies
     * it.  Legacy responses receive an explicit incomplete manifest instead
     * of silently being treated as a complete candle history.
     */
    public function recordDecisionTrace(LabEvaluationRun $run, array $response): array
    {
        $trace = data_get($response, 'decision_trace', data_get($response, 'candle_decision_trace', data_get($response, 'decision_events')));
        $proof = $this->decisionTraceCompleteness($response, $run);
        if (! is_array($trace) || ! array_is_list($trace)) {
            $manifest = [
                'protocol' => 'candle_decision_trace_v1', 'complete' => false,
                'reason' => 'evaluator_response_did_not_supply_decision_trace',
                'producer_proof' => $proof,
                'result_hash' => $this->hash($response), 'promotion_evidence' => false,
            ];
            $this->recordArtifact($run, 'decision_trace_manifest', $manifest, ['complete' => false]);

            return $manifest;
        }

        $traceArtifact = $this->recordArtifact($run, 'decision_trace', $trace, [
            'complete' => $proof['complete'],
            'event_count' => count($trace),
            'producer_proof' => $proof,
            'promotion_evidence' => false,
        ]);
        $manifest = [
            'protocol' => 'candle_decision_trace_v1', 'complete' => $proof['complete'],
            'producer_proof' => $proof,
            'event_count' => count($trace), 'result_hash' => $this->hash($response),
            'artifact_id' => $traceArtifact->artifact_id,
            'artifact_path' => $traceArtifact->storage_path,
            'artifact_sha256' => $traceArtifact->sha256,
            'projection_protocol' => 'candle_decision_projection_v1',
            'projection_mode' => (bool) config('services.lab_evidence.compact_decision_projection', true)
                ? 'compact_rollup_v1' : 'full_rows_v1',
            'projection_status' => 'queued',
            'promotion_evidence' => false,
        ];
        $this->recordArtifact($run, 'decision_trace_manifest', $manifest, [
            'complete' => $proof['complete'], 'event_count' => count($trace), 'producer_proof' => $proof,
            'result_hash' => $manifest['result_hash'], 'artifact_sha256' => $traceArtifact->sha256,
            'projection_status' => 'queued',
        ]);
        $runId = $run->run_id;
        DB::afterCommit(function () use ($runId): void {
            try {
                ProjectLabCandleDecisionEvents::dispatch($runId);
            } catch (Throwable $exception) {
                // A queue outage cannot reopen a durably sealed replay.
                // Existing projection reconciliation can redeliver by run ID.
                report($exception);
            }
        });

        return $manifest;
    }

    /**
     * Project the immutable trace into scalar candle rows. This method is
     * intentionally separate from recordDecisionTrace so the replay request
     * never waits on a million-row secondary insert.
     *
     * @return array<string, mixed>
     */
    public function projectDecisionTrace(LabEvaluationRun $run): array
    {
        $trace = $this->latestArtifactPayload($run, 'decision_trace');
        if (! is_array($trace) || ! array_is_list($trace)) {
            return ['protocol' => 'candle_decision_projection_v1', 'complete' => false, 'event_count' => 0];
        }

        $rows = [];
        $rollups = [];
        $recordable = 0;
        $storedRows = 0;
        $projectedAt = now()->utc();
        $batchSize = 1000;
        $compactProjection = (bool) config('services.lab_evidence.compact_decision_projection', true)
            && Schema::hasTable('lab_candle_decision_rollups');
        foreach ($trace as $index => $item) {
            if (! is_array($item)) continue;
            $eventType = (string) ($item['event_type'] ?? 'signal_evaluation');
            $candleIndex = isset($item['candle_index']) ? (int) $item['candle_index'] : (isset($item['index']) ? (int) $item['index'] : $index);
            $decisionId = $this->deterministicDecisionId($run->run_id, $candleIndex, $eventType, $index);
            $payload = $item;
            $action = (string) ($item['action'] ?? $item['signal'] ?? $item['decision'] ?? 'WAIT');
            $accepted = array_key_exists('accepted', $item) ? (bool) $item['accepted'] : null;
            $rejectionCode = $item['rejection_code'] ?? $item['reason'] ?? null;
            $marketRegime = $item['market_regime'] ?? $item['regime'] ?? null;
            $volatilityRegime = $item['volatility_regime'] ?? $item['volatility'] ?? null;
            $candleTime = (string) ($item['candle_time'] ?? $item['time'] ?? $item['signal_time'] ?? '');
            $keepRow = ! $compactProjection || $this->isHighValueDecisionEvent(
                $eventType,
                $action,
                $accepted,
                $rejectionCode,
            );
            if ($compactProjection) {
                $rollupIdentity = [
                    'run_id' => $run->run_id,
                    'lab_generation_id' => $run->lab_generation_id,
                    'lab_agent_id' => $run->lab_agent_id,
                    'bucket_date' => $this->decisionBucketDate($candleTime),
                    'event_type' => $eventType,
                    'action' => $action,
                    'accepted' => $accepted,
                    'rejection_code' => $rejectionCode,
                    'market_regime' => $marketRegime,
                    'volatility_regime' => $volatilityRegime,
                ];
                $rollupKey = $this->hash($rollupIdentity);
                if (! isset($rollups[$rollupKey])) {
                    $rollups[$rollupKey] = [
                        'rollup_key' => $rollupKey,
                        'run_id' => $run->run_id,
                        'lab_generation_id' => $run->lab_generation_id,
                        'lab_agent_id' => $run->lab_agent_id,
                        'bucket_date' => $rollupIdentity['bucket_date'],
                        'event_type' => $eventType,
                        'action' => $action,
                        'accepted' => $accepted,
                        'rejection_code' => $rejectionCode,
                        'market_regime' => $marketRegime,
                        'volatility_regime' => $volatilityRegime,
                        'event_count' => 0,
                        'accepted_count' => 0,
                        'first_candle_time' => $candleTime !== '' ? $candleTime : null,
                        'last_candle_time' => $candleTime !== '' ? $candleTime : null,
                        'recorded_at' => $projectedAt,
                        'created_at' => $projectedAt,
                        'updated_at' => $projectedAt,
                    ];
                }
                $rollups[$rollupKey]['event_count']++;
                if ($accepted === true) $rollups[$rollupKey]['accepted_count']++;
                if ($candleTime !== '') {
                    $first = $rollups[$rollupKey]['first_candle_time'];
                    $last = $rollups[$rollupKey]['last_candle_time'];
                    if ($first === null || $candleTime < $first) $rollups[$rollupKey]['first_candle_time'] = $candleTime;
                    if ($last === null || $candleTime > $last) $rollups[$rollupKey]['last_candle_time'] = $candleTime;
                }
            }
            if (! $keepRow) {
                $recordable++;
                continue;
            }
            $rows[] = [
                'decision_id' => $decisionId, 'run_id' => $run->run_id,
                'lab_generation_id' => $run->lab_generation_id, 'lab_agent_id' => $run->lab_agent_id,
                'candle_time' => $candleTime,
                'candle_index' => $candleIndex, 'event_type' => $eventType,
                'action' => $action,
                'accepted' => $accepted,
                'rejection_code' => $rejectionCode,
                'market_regime' => $marketRegime,
                'volatility_regime' => $volatilityRegime,
                'confidence' => isset($item['confidence']) ? (float) $item['confidence'] : (isset($item['signal_confidence']) ? (float) $item['signal_confidence'] : null),
                'price' => isset($item['price']) ? (float) $item['price'] : null,
                'features' => null, 'state' => null,
                'payload_hash' => $this->hash($payload), 'payload' => null,
                // Projection time is operational metadata only. One shared
                // timestamp avoids constructing three Carbon objects for
                // every candle while the immutable trace remains canonical.
                'recorded_at' => $projectedAt, 'created_at' => $projectedAt, 'updated_at' => $projectedAt,
            ];
            $recordable++;
            $storedRows++;
            if (count($rows) >= $batchSize) {
                DB::table('lab_candle_decision_events')->insertOrIgnore($rows);
                $rows = [];
            }
        }
        if ($rows !== []) DB::table('lab_candle_decision_events')->insertOrIgnore($rows);
        if ($compactProjection && $rollups !== []) {
            foreach (array_chunk(array_values($rollups), $batchSize) as $rollupBatch) {
                DB::table('lab_candle_decision_rollups')->insertOrIgnore($rollupBatch);
            }
        }

        return [
            'protocol' => 'candle_decision_projection_v1', 'complete' => true,
            'event_count' => $recordable, 'run_id' => $run->run_id,
            'idempotent' => true, 'batch_size' => $batchSize,
            'stored_event_count' => $storedRows,
            'compacted_event_count' => max(0, $recordable - $storedRows),
            'rollup_count' => $compactProjection ? count($rollups) : 0,
            'projection_mode' => $compactProjection ? 'compact_rollup_v1' : 'full_rows_v1',
            'projection_optimization' => $compactProjection
                ? 'compact_rollup_single_timestamp_batched_insert_v3'
                : 'single_timestamp_batched_insert_v2',
            'promotion_evidence' => false,
        ];
    }

    private function isHighValueDecisionEvent(
        string $eventType,
        string $action,
        ?bool $accepted,
        mixed $rejectionCode,
    ): bool {
        if ($accepted === true) return true;
        if (in_array(strtolower($eventType), [
            'trade_entry', 'trade_exit', 'execution', 'technical_failure',
            'veto', 'regime_transition', 'volume_transition',
        ], true)) return true;
        if ($rejectionCode === null || $rejectionCode === '') return true;

        return ! in_array(strtolower((string) $rejectionCode), ['no_signal', 'position_open'], true)
            || strtoupper($action) !== 'WAIT';
    }

    private function decisionBucketDate(string $candleTime): ?string
    {
        if ($candleTime === '') return null;
        try {
            return CarbonImmutable::parse($candleTime, 'UTC')->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function deterministicDecisionId(string $runId, int $candleIndex, string $eventType, int $ordinal): string
    {
        $hex = hash('sha256', $runId.'|'.$candleIndex.'|'.$eventType.'|'.$ordinal);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }

    public function hash(mixed $value): string
    {
        return hash('sha256', $this->encode($value));
    }

    public function parameterHash(LabAgent $agent): string
    {
        $agent->loadMissing('modelVersion');

        return $this->hash([
            'model_version_id' => $agent->model_version_id,
            'strategy' => $agent->modelVersion?->strategy,
            'parameters' => $agent->modelVersion?->parameters,
            'parameter_diff' => $agent->parameter_diff,
        ]);
    }

    public function modelRuntimeIdentity(LabEvaluationRun $run): array
    {
        $model = $run->modelVersion()->first(); $agent = $run->agent()->first();
        return ['protocol' => 'original_model_runtime_identity_v1', 'run_id' => $run->run_id,
            'model_version_id' => $model?->id, 'lab_agent_id' => $agent?->id,
            'parameter_hash' => app(ResearchPaperEpochContractService::class)->parameterHash((array) $model?->parameters),
            'evidence_parameter_hash' => $agent ? $this->parameterHash($agent) : null,
            'runtime_basis' => $model ? $this->modelRuntimeBasis($model) : null,
            'promotion_evidence' => false];
    }

    /** Treatment identity; dataset/assignment-specific passport hashes belong to the original request plane. */
    public function modelRuntimeBasis(\App\Models\ModelVersion $model): array
    {
        $components = collect(['architecture', 'strategy_architecture', 'base_strategy', 'tactic', 'tactic_contract',
            'composition_passport', 'composition_runtime_contract', 'confirmation_entry', 'risk_governor',
            'trade_management', 'execution_contract', 'runtime_ensemble', 'agent_constitution',
            'specialist_context_contract', 'contextual_specialist_cell', 'contextual_specialist_contract',
            'session_specialist_contract', 'regime_specialist_contract', 'specialist_council'])
            ->mapWithKeys(fn (string $key): array => [$key => data_get($model?->metadata, $key)])->all();
        $components['smart_composition_treatment'] = \Illuminate\Support\Arr::only(
            (array) data_get($model->metadata, 'smart_composition.composition_passport', []),
            ['protocol', 'symbol', 'laboratory_storage_timeframe', 'execution_timeframe',
                'temporal_sensor_scope', 'decision_tools', 'market_state', 'components',
                'strategy_contract', 'strategy_signal_scope', 'risk_governor', 'risk_contract',
                'management_contract', 'temporal_policy', 'horizon_mode', 'horizon_contract',
                'location_thesis', 'information_families', 'temporal_owners', 'setup_expires_at',
                'trigger_expires_at', 'invalidation_model', 'target_model', 'invalidation_target_contract',
                'management_state_machine', 'session_handoff_state', 'news_state', 'session_news_contract',
                'typed_program', 'filter_funnel_version', 'volume_provenance', 'risk_hysteresis', 'validation']);
        return ['strategy' => $model->strategy, 'components' => $components];
    }

    /** Read-only original owner seal; historical sources are never backfilled. */
    public function verifiedModelRuntimeIdentity(LabEvaluationRun $run): ?array
    {
        $artifact = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'model_runtime_identity')->oldest('id')->first();
        if (! $artifact || data_get($artifact->metadata, 'storage_protocol') !== 'compressed_artifact_v2' || ! $artifact->storage_path
            || ($run->finished_at !== null && ($artifact->created_at === null || $artifact->created_at->greaterThan($run->finished_at)))) return null;
        $payload = $this->readArtifactPayload($artifact);
        if (! is_array($payload) || ($payload['protocol'] ?? null) !== 'original_model_runtime_identity_v1'
            || ($payload['raw_request_hash'] ?? null) !== $run->request_hash
            || $this->hash(array_diff_key($payload, array_flip(['raw_request_hash', 'raw_payload_hash', 'request_artifact_hash', 'compiled_runtime_contract_hash']))) !== $this->hash($this->modelRuntimeIdentity($run))) return null;
        $requestArtifact = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_request')
            ->where('sha256', $payload['request_artifact_hash'] ?? '')->oldest('id')->first();
        if (! $requestArtifact || data_get($requestArtifact->metadata, 'storage_protocol') !== 'compressed_artifact_v2'
            || data_get($requestArtifact->metadata, 'request_hash') !== $run->request_hash
            || data_get($requestArtifact->metadata, 'raw_payload_hash') !== ($payload['raw_payload_hash'] ?? null)) return null;
        $request = $this->readArtifactPayload($requestArtifact);
        if (! is_array($request) || ($payload['compiled_runtime_contract_hash'] ?? null)
            !== app(ResearchPaperEpochContractService::class)->parameterHash((array) ($request['composition_runtime_contract'] ?? []))) return null;
        return [...$payload, 'artifact_hash' => $artifact->sha256];
    }

    public function codeHash(): string
    {
        $backendRoot = base_path();
        $pythonRoot = dirname($backendRoot).'/ai-service-python';
        $roots = [$backendRoot.'/app', $backendRoot.'/config', $pythonRoot.'/app'];
        $manifestFiles = [
            $backendRoot.'/composer.lock', $backendRoot.'/package-lock.json',
            $pythonRoot.'/requirements.txt', $pythonRoot.'/pyproject.toml',
        ];
        $parts = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            foreach (File::allFiles($root) as $file) {
                $extension = strtolower($file->getExtension());
                if (! in_array($extension, ['php', 'py'], true)) {
                    continue;
                }
                $path = $file->getPathname();
                $parts[str_replace('\\', '/', substr($path, strlen(dirname($backendRoot)) + 1))] = hash_file('sha256', $path);
            }
        }
        foreach ($manifestFiles as $file) {
            if (is_file($file)) {
                $parts[str_replace('\\', '/', substr($file, strlen(dirname($backendRoot)) + 1))] = hash_file('sha256', $file);
            }
        }
        ksort($parts);

        return $this->hash([
            'protocol' => 'full_runtime_dependency_fingerprint_v3',
            'files' => $parts,
            'php' => PHP_VERSION,
            'commit' => env('APP_COMMIT_SHA'),
        ]);
    }

    private function requestManifest(array $request): array
    {
        $manifest = $request;
        foreach (['candles', 'regime_candles'] as $key) {
            if (! array_key_exists($key, $manifest)) {
                continue;
            }
            $rows = is_array($manifest[$key]) ? $manifest[$key] : [];
            $manifest[$key] = [
                '__canonical_dataset_reference' => true, 'row_count' => count($rows),
                'sha256' => $this->hash($rows), 'first_row' => $rows[0] ?? null,
                'last_row' => $rows === [] ? null : $rows[array_key_last($rows)],
            ];
        }

        return $manifest;
    }

    private function responseManifest(array $response, ?string $dataHash = null, ?LabEvaluationRun $run = null): array
    {
        $trace = data_get($response, 'decision_trace', data_get($response, 'candle_decision_trace', data_get($response, 'decision_events')));
        $ledger = data_get($response, 'trade_ledger');

        return [
            'payload_hash' => $this->hash($response),
            'research_release_receipt' => data_get($response, 'data_quality.research_release_receipt'),
            'leaderboard_count' => is_array($response['leaderboard'] ?? null) ? count($response['leaderboard']) : null,
            'trade_ledger_hash' => data_get($response, 'trade_ledger_hash'),
            'event_ledger_hash' => data_get($response, 'event_ledger_hash', data_get($response, 'event_digest.hash')),
            'event_ledger_count' => data_get($response, 'event_ledger_count', data_get($response, 'event_digest.count')),
            'trade_ledger_count' => is_array($ledger) ? count($ledger) : null,
            'displayed_trade_count' => data_get($response, 'displayed_trade_count'),
            'trade_ledger_complete' => $this->tradeLedgerComplete($response),
            'dataset_hash' => $dataHash,
            'dataset_hash_present' => $this->isSha256((string) $dataHash),
            'decision_trace_present' => is_array($trace),
            'decision_trace_count' => is_array($trace) ? count($trace) : null,
            'decision_trace_hash' => is_array($trace) ? $this->hash($trace) : null,
            'decision_trace_completeness' => $this->decisionTraceCompleteness($response, $run),
        ];
    }

    private function metricsManifest(?array $response): array
    {
        if ($response === null) {
            return [];
        }

        return [
            'total_trades' => data_get($response, 'total_trades'),
            'profit_factor' => data_get($response, 'profit_factor'),
            'max_drawdown_percent' => data_get($response, 'max_drawdown_percent'),
            'screening_survival' => data_get($response, 'screening_survival'),
            'monthly_passport' => data_get($response, 'monthly_passport'),
            'gate_failure_context' => data_get($response, 'gate_failure_context'),
            'event_ledger_hash' => data_get($response, 'event_ledger_hash', data_get($response, 'event_digest.hash')),
        ];
    }

    private function tradeLedgerComplete(array $response): bool
    {
        $ledger = data_get($response, 'trade_ledger');
        $trades = data_get($response, 'trades');
        $displayed = data_get($response, 'displayed_trade_count');
        $total = data_get($response, 'total_trades');
        if (is_array($ledger) && $total !== null && count($ledger) >= (int) $total) {
            return true;
        }

        return is_array($trades) && $displayed !== null && $total !== null && (int) $displayed >= (int) $total;
    }

    private function candleCount(array $request): int
    {
        return count((array) ($request['candles'] ?? [])) + count((array) ($request['regime_candles'] ?? []));
    }

    private function dataHashFromRequest(array $request): ?string
    {
        $path = $request['dataset_path'] ?? null;
        $manifest = is_string($path) && is_file($path.'.manifest.json') ? json_decode((string) file_get_contents($path.'.manifest.json'), true) : null;
        $foundationPath = $request['foundation_dataset_path'] ?? null;
        $foundationManifest = is_string($foundationPath) && is_file($foundationPath.'.manifest.json')
            ? json_decode((string) file_get_contents($foundationPath.'.manifest.json'), true)
            : null;

        if (data_get($manifest, 'sha256') && data_get($foundationManifest, 'sha256')) {
            return $this->hash([
                'canonical_dataset_sha256' => data_get($manifest, 'sha256'),
                'foundation_dataset_sha256' => data_get($foundationManifest, 'sha256'),
                'foundation_promotion_evidence' => data_get($foundationManifest, 'promotion_evidence', false),
            ]);
        }

        if (data_get($manifest, 'sha256')) return (string) data_get($manifest, 'sha256');
        if ($path && is_file($path)) return (string) hash_file('sha256', $path);
        $candles = $request['candles'] ?? null;

        return is_array($candles) && $candles !== [] ? $this->hash($candles) : null;
    }

    private function requestHasDatasetHash(LabEvaluationRun $run): bool
    {
        $manifest = (array) data_get($run->request_meta, 'dataset_manifest', []);
        $hashes = [
            data_get($run->request_meta, 'dataset_hash'),
            data_get($manifest, 'data_hash'),
            data_get($manifest, 'sha256'),
            data_get($manifest, 'snapshot_sha256'),
            data_get($manifest, 'foundation.sha256'),
            data_get($manifest, 'foundation.snapshot_sha256'),
            data_get($manifest, 'regime.sha256'),
            data_get($manifest, 'regime_snapshot_sha256'),
        ];

        return collect($hashes)->contains(fn ($hash): bool => $this->isSha256((string) $hash));
    }

    private function isSha256(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/i', trim($value)) === 1;
    }

    private function phaseForStatus(?string $status): string
    {
        return match ($status) {
            'screening', 'screened', 'queued' => 'screening',
            'full_queued', 'training', 'challenger', 'forward_validated' => 'full_validation',
            'paper' => 'paper',
            default => 'lifecycle',
        };
    }

    private function encode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return json_encode(['serialization_error' => true, 'type' => get_debug_type($value)], JSON_UNESCAPED_SLASHES) ?: '{}';
        }
    }

    private function writeArtifactFile(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname($path));
        $temporary = $path.'.'.Str::random(12).'.tmp';
        if (File::put($temporary, $contents) === false) {
            throw new RuntimeException("Evidence artifact yozilmadi: {$path}");
        }
        if (! rename($temporary, $path)) {
            File::delete($temporary);
            throw new RuntimeException("Evidence artifact publish qilinmadi: {$path}");
        }
    }

    private function artifactDisk(): string
    {
        return (string) config('services.lab_evidence.disk', 'lab_evidence');
    }

    private function writeArtifact(string $relativePath, string $contents): void
    {
        if ($this->artifactDisk() === 'lab_evidence') {
            $this->writeArtifactFile(storage_path('app/'.$relativePath), $contents);

            return;
        }

        if (! Storage::disk($this->artifactDisk())->put($relativePath, $contents)) {
            throw new RuntimeException("Evidence artifact publish qilinmadi: {$relativePath}");
        }
    }

    private function readArtifact(string $relativePath): string
    {
        $disk = $this->artifactDisk();
        if ($disk !== 'lab_evidence' && Storage::disk($disk)->exists($relativePath)) {
            return (string) Storage::disk($disk)->get($relativePath);
        }

        // Keep existing local artifacts readable during an object-storage
        // migration. New writes use the configured disk; this fallback is
        // only for manifests created before the disk switch.
        $localPath = storage_path('app/'.ltrim($relativePath, '/\\'));
        if (is_file($localPath)) {
            return (string) file_get_contents($localPath);
        }

        throw new RuntimeException("Evidence artifact topilmadi: {$relativePath}");
    }

    private function deleteArtifact(string $relativePath): void
    {
        if ($this->artifactDisk() === 'lab_evidence') {
            File::delete(storage_path('app/'.$relativePath));

            return;
        }

        Storage::disk($this->artifactDisk())->delete($relativePath);
    }
}
