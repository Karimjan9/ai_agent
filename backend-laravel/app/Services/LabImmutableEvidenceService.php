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
            ],
        ]);

        $this->recordLifecycle($agent, 'evaluation_started', [
            'run_id' => $run->run_id, 'phase' => $phase, 'mode' => $mode,
            'attempt' => $run->attempt, 'queue' => $run->queue,
        ], $phase, $run->run_id, $run->attempt, $context['source'] ?? 'evaluation_job');

        return $run;
    }

    public function markSkipped(LabEvaluationRun $run, string $reason, array $payload = []): void
    {
        $this->finishRun($run, 'skipped', null, ['skip_reason' => $reason], [
            'reason_code' => $reason, ...$payload,
        ]);
    }

    public function attachRequest(LabEvaluationRun $run, array $request, array $context = []): void
    {
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
        $this->recordArtifact($run, 'evaluation_request', $safeRequest, [
            'raw_payload_hash' => $payloadHash,
            'request_hash' => $requestHash,
            'dataset_hash' => $resolvedDataHash !== '' ? $resolvedDataHash : null,
            'dataset_hash_present' => $this->isSha256($resolvedDataHash),
            'exact_candles_referenced_by_hash' => true,
        ]);
        $this->recordLifecycle($run->agent, 'evaluation_request_attached', [
            'request_hash' => $requestHash, 'payload_hash' => $payloadHash,
            'data_hash' => $run->data_hash,
        ], $run->phase, $run->run_id, $run->attempt, 'LabImmutableEvidenceService');
    }

    public function finishRun(
        LabEvaluationRun $run,
        string $status,
        ?array $response = null,
        array $metrics = [],
        array $metadata = [],
        ?Throwable $error = null,
    ): void {
        $run->refresh();
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
        $finished = now();
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
        $run->update([
            'status' => $status,
            'finished_at' => $finished,
            'duration_ms' => $run->started_at ? max(0, $run->started_at->diffInMilliseconds($finished)) : null,
            'response_hash' => $responseHash,
            'trade_ledger_hash' => data_get($terminalResponse, 'trade_ledger_hash'),
            'response_meta' => $terminalResponse === null ? null : $this->responseManifest($terminalResponse, $run->data_hash),
            // The complete response remains immutable in the compressed
            // artifact plane. Run metrics are a mutable selector projection;
            // keep them bounded so retries do not rewrite trade/event arrays.
            'metrics' => $metrics !== []
                ? $this->projectionPayload($metrics)
                : $this->metricsManifest($terminalResponse),
            'metadata' => array_merge((array) $run->metadata, $metadata, [
                'terminal' => true, 'terminal_at' => $finished->toIso8601String(),
            ]),
            'error_class' => $error ? $error::class : null,
            'error_message' => $error ? substr($error->getMessage(), 0, 4000) : null,
        ]);

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
        $trace = data_get($response, 'decision_trace', data_get($response, 'candle_decision_trace', data_get($response, 'decision_events')));
        $traceContract = (array) data_get($response, 'data_quality.decision_trace', []);
        $traceComplete = is_array($trace)
            && array_is_list($trace)
            && data_get($traceContract, 'complete', true) === true
            && data_get($traceContract, 'requested', true) === true
            && ($trace !== [] || (int) data_get($traceContract, 'evaluated_candle_count', 0) === 0);
        $ledgerComplete = $this->tradeLedgerComplete($response)
            && filled(data_get($response, 'trade_ledger_hash'));
        $reasons = [];
        if (! $requestArtifact) $reasons[] = 'MISSING_EVALUATION_REQUEST_ARTIFACT';
        if (! $datasetHash) $reasons[] = 'MISSING_DATASET_HASH';
        if (! $traceComplete) $reasons[] = 'MISSING_COMPLETE_DECISION_TRACE';
        if (! $ledgerComplete) $reasons[] = 'MISSING_COMPLETE_TRADE_LEDGER';

        return [
            'complete' => $reasons === [],
            'reason_codes' => $reasons,
            'request_artifact' => $requestArtifact,
            'dataset_hash' => $datasetHash,
            'decision_trace' => $traceComplete,
            'trade_ledger' => $ledgerComplete,
            'promotion_evidence' => false,
        ];
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
        $ledgerArtifact = $artifacts->first(fn (LabEvidenceArtifact $artifact): bool => in_array($artifact->artifact_type, ['trade_ledger', 'trade_ledger_manifest'], true));
        $responseMeta = (array) $run->response_meta;
        $reasons = [];
        if ($run->status !== 'completed') $reasons[] = 'EVIDENCE_RUN_NOT_COMPLETED';
        if (! $hasArtifact('evaluation_request') || ! filled($run->request_hash)) $reasons[] = 'MISSING_EVALUATION_REQUEST_ARTIFACT';
        if (! $this->isSha256((string) $run->data_hash) || ! $this->requestHasDatasetHash($run)) $reasons[] = 'MISSING_DATASET_HASH';
        if (! $hasArtifact('evaluation_response') || ! filled($run->response_hash)) $reasons[] = 'MISSING_EVALUATION_RESPONSE_ARTIFACT';
        if (! $traceArtifact
            || data_get($traceArtifact->metadata, 'complete') !== true
            || (int) data_get($traceArtifact->metadata, 'event_count', 0) < 1
            || data_get($traceManifest?->metadata, 'complete') !== true) {
            $reasons[] = 'MISSING_COMPLETE_DECISION_TRACE';
        }
        if (! $ledgerArtifact || data_get($ledgerArtifact->metadata, 'complete') !== true) $reasons[] = 'MISSING_COMPLETE_TRADE_LEDGER';
        if (data_get($responseMeta, 'decision_trace_present') !== true || data_get($responseMeta, 'trade_ledger_complete') !== true) {
            $reasons[] = 'RESPONSE_MANIFEST_INCOMPLETE';
        }

        return [
            'complete' => $reasons === [],
            'reason_codes' => array_values(array_unique($reasons)),
            'run_id' => $run->run_id,
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
            $decoded = json_decode($contents, true);
            if (! is_array($decoded)) {
                throw new RuntimeException("Evidence artifact JSON yaroqsiz: {$artifact->artifact_id}");
            }
            $decodedHash = hash('sha256', $this->encode($decoded));
            $roundtripHash = (string) data_get($artifact->metadata, 'payload_roundtrip_sha256', '');
            if (! hash_equals((string) $artifact->sha256, $decodedHash)
                && ! ($roundtripHash !== '' && hash_equals($roundtripHash, $decodedHash))) {
                throw new RuntimeException("Evidence artifact hash mismatch: {$artifact->artifact_id}");
            }

            return $decoded;
        }

        return $artifact->payload;
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
        if (! is_array($trace) || ! array_is_list($trace)) {
            $manifest = [
                'protocol' => 'candle_decision_trace_v1', 'complete' => false,
                'reason' => 'evaluator_response_did_not_supply_decision_trace',
                'result_hash' => $this->hash($response), 'promotion_evidence' => false,
            ];
            $this->recordArtifact($run, 'decision_trace_manifest', $manifest, ['complete' => false]);

            return $manifest;
        }

        $traceArtifact = $this->recordArtifact($run, 'decision_trace', $trace, [
            'complete' => true,
            'event_count' => count($trace),
            'promotion_evidence' => false,
        ]);
        $manifest = [
            'protocol' => 'candle_decision_trace_v1', 'complete' => true,
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
        $this->recordArtifact($run, 'decision_trace_manifest', $manifest, ['complete' => true, 'event_count' => count($trace), 'projection_status' => 'queued']);
        ProjectLabCandleDecisionEvents::dispatch($run->run_id);

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

    public function codeHash(): string
    {
        $backendRoot = base_path();
        $pythonRoot = dirname($backendRoot).'/ai-service-python';
        $roots = [$backendRoot.'/app', $pythonRoot.'/app'];
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
                $parts[str_replace('\\', '/', str_replace($backendRoot, '', $path))] = hash_file('sha256', $path);
            }
        }
        foreach ($manifestFiles as $file) {
            if (is_file($file)) {
                $parts[str_replace('\\', '/', str_replace($backendRoot, '', $file))] = hash_file('sha256', $file);
            }
        }
        ksort($parts);

        return $this->hash([
            'protocol' => 'full_runtime_dependency_fingerprint_v2',
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

    private function responseManifest(array $response, ?string $dataHash = null): array
    {
        $trace = data_get($response, 'decision_trace', data_get($response, 'candle_decision_trace', data_get($response, 'decision_events')));
        $ledger = data_get($response, 'trade_ledger');

        return [
            'payload_hash' => $this->hash($response),
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
