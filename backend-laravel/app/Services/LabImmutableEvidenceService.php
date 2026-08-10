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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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
        $run->update([
            'request_id' => $context['request_id'] ?? $run->request_id,
            'request_hash' => $requestHash,
            'data_hash' => $context['data_hash'] ?? $run->data_hash ?? $this->dataHashFromRequest($request),
            'request_meta' => [
                'payload_hash' => $payloadHash,
                'request_hash' => $requestHash,
                'payload' => $safeRequest,
                'candle_count' => $this->candleCount($request),
                'dataset_manifest' => $context['dataset_manifest'] ?? null,
                'attached_at' => now()->toIso8601String(),
            ],
        ]);
        $this->recordArtifact($run, 'evaluation_request', $safeRequest, [
            'raw_payload_hash' => $payloadHash,
            'request_hash' => $requestHash,
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
        $responseHash = $response === null ? null : $this->hash($response);
        $run->update([
            'status' => $status,
            'finished_at' => $finished,
            'duration_ms' => $run->started_at ? max(0, $run->started_at->diffInMilliseconds($finished)) : null,
            'response_hash' => $responseHash,
            'trade_ledger_hash' => data_get($response, 'trade_ledger_hash'),
            'response_meta' => $response === null ? null : $this->responseManifest($response),
            'metrics' => $metrics ?: $this->metricsManifest($response),
            'metadata' => array_merge((array) $run->metadata, $metadata, [
                'terminal' => true, 'terminal_at' => $finished->toIso8601String(),
            ]),
            'error_class' => $error ? $error::class : null,
            'error_message' => $error ? substr($error->getMessage(), 0, 4000) : null,
        ]);

        if ($response !== null) {
            $this->recordArtifact($run, 'evaluation_response', $response, [
                'response_hash' => $responseHash,
                'trade_ledger_hash' => data_get($response, 'trade_ledger_hash'),
                'displayed_trade_count' => data_get($response, 'displayed_trade_count'),
                'trade_ledger_complete' => $this->tradeLedgerComplete($response),
            ]);
            $tradeLedger = data_get($response, 'trade_ledger');
            if (is_array($tradeLedger)) {
                $this->recordArtifact($run, 'trade_ledger', $tradeLedger, [
                    'trade_ledger_hash' => data_get($response, 'trade_ledger_hash'),
                    'total_trades' => data_get($response, 'total_trades'),
                    'complete' => $this->tradeLedgerComplete($response),
                ]);
            } else {
                $this->recordArtifact($run, 'trade_ledger_manifest', [
                    'trade_ledger_hash' => data_get($response, 'trade_ledger_hash'),
                    'total_trades' => data_get($response, 'total_trades'),
                    'displayed_trade_count' => data_get($response, 'displayed_trade_count'),
                    'complete' => false,
                ], ['complete' => false, 'reason' => 'full_trade_ledger_not_returned']);
            }
            $this->recordDecisionTrace($run, $response);
        }

        $this->recordLifecycle($run->agent, 'evaluation_'.$status, [
            'run_id' => $run->run_id, 'status' => $status, 'response_hash' => $responseHash,
            'error_class' => $error ? $error::class : null,
        ], $run->phase, $run->run_id, $run->attempt, 'LabImmutableEvidenceService', $error);
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

        return LabMutationCreditEvent::create([
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
            'evidence_run_ids' => array_values(array_filter([
                $runId, ...((array) ($payload['evidence_run_ids'] ?? data_get($credit, 'evidence_run_ids', []))),
            ])),
            'payload' => [
                'gate_transition' => $memory->gate_transition,
                'behavioral_effect' => $memory->behavioral_effect,
                ...$payload,
            ],
            'recorded_at' => now(),
        ]);
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
        $projected = $payload;
        foreach (['decision_trace', 'candle_decision_trace', 'decision_events', 'trade_ledger'] as $key) {
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
        $rows = [];
        $recorded = 0;
        foreach ($trace as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $features = (array) ($item['features'] ?? $item['feature_snapshot'] ?? []);
            $state = (array) ($item['state'] ?? $item['state_snapshot'] ?? []);
            $payload = $item;
            $rows[] = [
                'decision_id' => (string) Str::uuid(), 'run_id' => $run->run_id,
                'lab_generation_id' => $run->lab_generation_id, 'lab_agent_id' => $run->lab_agent_id,
                'candle_time' => (string) ($item['candle_time'] ?? $item['time'] ?? $item['signal_time'] ?? ''),
                'candle_index' => isset($item['candle_index']) ? (int) $item['candle_index'] : (isset($item['index']) ? (int) $item['index'] : $index),
                'event_type' => (string) ($item['event_type'] ?? 'signal_evaluation'),
                'action' => (string) ($item['action'] ?? $item['signal'] ?? $item['decision'] ?? 'WAIT'),
                'accepted' => array_key_exists('accepted', $item) ? (bool) $item['accepted'] : null,
                'rejection_code' => $item['rejection_code'] ?? $item['reason'] ?? null,
                'market_regime' => $item['market_regime'] ?? $item['regime'] ?? null,
                'volatility_regime' => $item['volatility_regime'] ?? $item['volatility'] ?? null,
                'confidence' => isset($item['confidence']) ? (float) $item['confidence'] : (isset($item['signal_confidence']) ? (float) $item['signal_confidence'] : null),
                'price' => isset($item['price']) ? (float) $item['price'] : null,
                // The complete feature/state/payload graph is in the
                // compressed decision_trace artifact. Keep only scalar
                // dimensions in MySQL so the learning aggregates stay fast
                // without turning every candle into a multi-KB JSON row.
                'features' => null, 'state' => null,
                'payload_hash' => $this->hash($payload), 'payload' => null,
                'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ];
            if (count($rows) >= 500) {
                DB::table('lab_candle_decision_events')->insert($rows);
                $recorded += count($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('lab_candle_decision_events')->insert($rows);
            $recorded += count($rows);
        }
        $manifest = [
            'protocol' => 'candle_decision_trace_v1', 'complete' => true,
            'event_count' => $recorded, 'result_hash' => $this->hash($response),
            'artifact_id' => $traceArtifact->artifact_id,
            'artifact_path' => $traceArtifact->storage_path,
            'artifact_sha256' => $traceArtifact->sha256,
            'promotion_evidence' => false,
        ];
        $this->recordArtifact($run, 'decision_trace_manifest', $manifest, ['complete' => true, 'event_count' => $recorded]);

        return $manifest;
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

    private function responseManifest(array $response): array
    {
        $trace = data_get($response, 'decision_trace', data_get($response, 'candle_decision_trace', data_get($response, 'decision_events')));
        $ledger = data_get($response, 'trade_ledger');

        return [
            'payload_hash' => $this->hash($response),
            'leaderboard_count' => is_array($response['leaderboard'] ?? null) ? count($response['leaderboard']) : null,
            'trade_ledger_hash' => data_get($response, 'trade_ledger_hash'),
            'trade_ledger_count' => is_array($ledger) ? count($ledger) : null,
            'displayed_trade_count' => data_get($response, 'displayed_trade_count'),
            'trade_ledger_complete' => $this->tradeLedgerComplete($response),
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

        return data_get($manifest, 'sha256') ?: ($path ? $this->hash(['dataset_path' => $path, 'candles' => $this->candleCount($request)]) : null);
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
