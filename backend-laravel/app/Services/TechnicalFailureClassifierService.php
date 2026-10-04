<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabLifecycleEvent;
use Illuminate\Support\Facades\Schema;

class TechnicalFailureClassifierService
{
    public const TRANSIENT = 'TRANSIENT_RECOVERABLE';

    public const CAPABILITY = 'DATA_CAPABILITY_MISSING';

    public const TERMINAL = 'TERMINAL_DIAGNOSTIC';

    /** @return array<string, mixed> */
    public function forAgent(LabAgent $agent): array
    {
        $agent->loadMissing(['modelVersion', 'generation']);
        if (data_get($agent->generation?->trigger_context, 'academy_preparation_containment.protocol')
            === AcademyExperimentMaterializerService::PREPARATION_CONTAINMENT_PROTOCOL) {
            $preparation = app(AcademyExperimentMaterializerService::class)->preparationTerminalDispositionForAgent($agent);
            if ($preparation !== null) return [
                ...$preparation, 'class' => self::TERMINAL, 'capability' => null,
                'blocks_global_generation' => false, 'action' => 'TERMINAL_DIAGNOSTIC',
                'reason' => 'Original source/request/checkpoint and zero scientific output attest a drained preparation failure; its bounded repair is a separate arbiter decision.',
            ];
        }
        $draftDisposition = $this->immutableDraftIntegrityDisposition($agent);
        if ($draftDisposition !== null) {
            return $draftDisposition;
        }
        $terminalDisposition = (array) data_get(
            $agent->modelVersion?->metadata,
            'technical_recovery_terminal_disposition',
            [],
        );
        if ((string) $agent->lifecycle_status === 'technical_quarantine'
            && (string) ($terminalDisposition['protocol'] ?? '') === 'frozen_recovery_contract_terminal_v1'
            && (string) ($terminalDisposition['reason_code'] ?? '') === 'FROZEN_RECOVERY_CONTRACT_UNAVAILABLE'
            && (string) ($terminalDisposition['strategy_verdict'] ?? '') === 'withheld') {
            return [
                'class' => self::TERMINAL,
                'reason_code' => 'FROZEN_RECOVERY_CONTRACT_UNAVAILABLE',
                'capability' => null,
                'blocks_global_generation' => false,
                'action' => 'TERMINAL_DIAGNOSTIC',
                'reason' => 'The frozen same-generation recovery contract was sealed unavailable; the old timeout remains technical history.',
            ];
        }
        $run = LabEvaluationRun::query()
            ->where('lab_agent_id', $agent->id)
            ->whereIn('status', ['technical_error', 'failed'])
            ->latest('id')
            ->first();
        $preflightErrors = array_values(array_filter(array_map(
            'strval',
            (array) data_get($agent->modelVersion?->metadata, 'preflight_quarantine.errors', []),
        )));
        $message = $run?->error_message
            ?: trim(implode(' ', [implode(' ', $preflightErrors), (string) $agent->decision_reason]));

        // A candidate batch can be quarantined without opening its own run
        // when its same-generation frozen control terminates first. The
        // candidate has no independent replay debt: its disposition follows
        // the immutable control failure. Without this projection the empty
        // candidate run is misclassified as an unclassified transient and a
        // terminal generation blocks every autonomous successor forever.
        if ($run === null && str_contains(strtolower($message), 'frozen_control_replay_incomplete')) {
            $controlId = (int) data_get($agent->modelVersion?->metadata, 'control_pair_contract.control_agent_id',
                data_get($agent->modelVersion?->metadata, 'learning_receipt.control_agent_id', 0));
            if ($controlId <= 0 && Schema::hasTable('agent_learning_causal_experiments')) {
                $experiment = AgentLearningCausalExperiment::query()
                    ->where('lab_generation_id', $agent->lab_generation_id)
                    ->where(function ($query) use ($agent): void {
                        $query->where('guided_agent_id', $agent->id)
                            ->orWhere('blinded_agent_id', $agent->id);
                    })
                    ->latest('id')
                    ->first();
                $controlId = (int) ($experiment?->control_agent_id ?? 0);
            }
            $control = $controlId > 0 && $controlId !== (int) $agent->id
                ? LabAgent::query()->with(['modelVersion', 'generation'])->find($controlId)
                : null;
            if ($control instanceof LabAgent && (int) $control->lab_generation_id === (int) $agent->lab_generation_id) {
                // The candidate was withheld before it could open an own run.
                // If its frozen control later completed a real screening run,
                // the old candidate quarantine remains failed evidence, but
                // there is no longer an upstream transport failure to repair.
                // In particular, do not classify the screened control's
                // ordinary decision_reason as a new technical error.
                $controlRun = LabEvaluationRun::query()
                    ->where('lab_agent_id', $control->id)
                    ->where('phase', 'screening')
                    ->latest('id')
                    ->first();
                if ((string) $control->lifecycle_status === 'screened'
                    && (string) $controlRun?->status === 'completed') {
                    return [
                        'class' => self::TERMINAL,
                        'reason_code' => 'UPSTREAM_FROZEN_CONTROL_COMPLETED_CANDIDATE_UNREPLAYED',
                        'capability' => null,
                        'blocks_global_generation' => false,
                        'action' => 'TERMINAL_DIAGNOSTIC',
                        'reason' => 'The frozen control later completed; this candidate remains unreplayed diagnostic evidence, not an independent technical-recovery debt.',
                    ];
                }
                $upstream = $this->forAgent($control);
                if (data_get($upstream, 'blocks_global_generation') !== true) {
                    return [
                        'class' => self::TERMINAL,
                        'reason_code' => 'UPSTREAM_FROZEN_CONTROL_TERMINAL',
                        'capability' => data_get($upstream, 'capability'),
                        'blocks_global_generation' => false,
                        'action' => 'TERMINAL_DIAGNOSTIC',
                        'reason' => 'The candidate never started because its immutable frozen control terminated; it has no independent replay recovery debt.',
                    ];
                }

                // This candidate has no evaluator run of its own. It waits
                // for the control's bounded repair, but must never spend a
                // second timeout-recovery seat under the control's error code.
                return [
                    'class' => self::TRANSIENT,
                    'reason_code' => 'FROZEN_CONTROL_UPSTREAM_REPAIR_PENDING',
                    'capability' => data_get($upstream, 'capability'),
                    'blocks_global_generation' => true,
                    'action' => 'WAIT_FOR_FROZEN_CONTROL_REPAIR',
                    'reason' => 'The candidate has no own replay; its frozen control owns the bounded technical repair.',
                ];
            }
        }

        // The prequeue guard may withhold a future agent without an own
        // evaluator run. Its disposition still needs the native original
        // failure and exact current frozen bytes, not an error-code label.
        if (str_contains((string) $message, 'GENERATION_MTF_M5_KNOWN_CANDLE_GAP')) {
            $manifest = (array) data_get($agent->generation?->trigger_context, 'mtf_bundle_manifest', []);
            $path = (string) data_get($manifest, 'streams.M5.path', '');
            $sha = (string) data_get($manifest, 'streams.M5.sha256', '');
            if (preg_match('/^[a-f0-9]{64}$/D', $sha) === 1 && is_file($path)
                && hash_equals($sha, (string) hash_file('sha256', $path))) {
                $readiness = app(GenerationSnapshotAdmissionService::class)->historicalDatasetReadiness($manifest);
                if (! $readiness['allowed']) return ['class' => self::CAPABILITY,
                    'reason_code' => 'GENERATION_MTF_M5_KNOWN_CANDLE_GAP', 'capability' => 'sealed_historical_candle_continuity',
                    'blocks_global_generation' => false, 'action' => 'WAIT_FOR_DATA_REPAIR',
                    'reason' => 'The current frozen source bytes match an original immutable continuity failure.',
                    'strategy_verdict' => 'withheld', 'data_dependency' => $readiness['source_dependency'],
                    'same_evidence_replay_forbidden' => true, 'scientific_question_budget_reset' => false];
            }
        }

        $classification = $this->classify(strtolower((string) $message), $run?->error_class);
        if (($classification['reason_code'] ?? null) === 'IMMUTABLE_HISTORICAL_CANDLE_GAP') {
            $dependency = $run ? $this->historicalGapDependency($run) : null;
            if ($dependency !== null && (int) $run->lab_generation_id === (int) $agent->lab_generation_id
                && (int) $run->model_version_id === (int) $agent->model_version_id) {
                return [...$classification, 'data_dependency' => $dependency];
            }
            return ['class' => self::TRANSIENT, 'reason_code' => 'UNATTESTED_DATA_QUALITY_FAILURE',
                'capability' => null, 'blocks_global_generation' => true, 'action' => 'RECOVER_TECHNICAL',
                'reason' => 'A manual or unsealed gap label is not an immutable dataset disposition.'];
        }

        return $classification;
    }

    /** Native request/model/error artifacts bind a data dependency; no historical row is rewritten. */
    public function historicalGapDependency(LabEvaluationRun $run): ?array
    {
        if ($run->status !== 'technical_error' || $run->finished_at === null
            || ($this->classify((string) $run->error_message)['reason_code'] ?? null) !== 'IMMUTABLE_HISTORICAL_CANDLE_GAP'
            || ! filled($run->data_hash) || ! filled($run->response_hash)) return null;
        $evidence = app(LabImmutableEvidenceService::class);
        $original = $evidence->verifiedModelRuntimeIdentity($run);
        if ($original === null || ($original['evidence_parameter_hash'] ?? null) !== $run->parameter_hash) return null;
        $requestArtifact = LabEvidenceArtifact::query()->where('run_id', $run->run_id)->where('artifact_type', 'evaluation_request')
            ->where('sha256', $original['request_artifact_hash'])->oldest('id')->first();
        $responseArtifact = LabEvidenceArtifact::query()->where('run_id', $run->run_id)->where('artifact_type', 'evaluation_response')
            ->where('sha256', $run->response_hash)->oldest('id')->first();
        if (! $requestArtifact || ! $responseArtifact) return null;
        $request = $evidence->readArtifactPayload($requestArtifact);
        $response = $evidence->readArtifactPayload($responseArtifact);
        $primary = (string) data_get($request, 'mtf_snapshot_manifest.streams.M5.sha256', '');
        $bundle = (string) data_get($request, 'mtf_snapshot_manifest.bundle_hash', '');
        if (! is_array($request) || ! is_array($response) || preg_match('/^[a-f0-9]{64}$/D', $primary) !== 1
            || ($request['replay_dataset_hash'] ?? null) !== $run->data_hash || $bundle !== $run->data_hash
            || data_get($response, 'terminal_replay_envelope.status') !== 'technical_error'
            || data_get($response, 'terminal_replay_envelope.response_available') !== false
            || data_get($response, 'terminal_replay_envelope.error_message') !== $run->error_message
            || data_get($response, 'total_trades') !== null || data_get($response, 'trade_ledger_hash') !== null
            || data_get($response, 'data_quality.decision_trace.complete') !== false) return null;

        return ['protocol' => 'immutable_historical_candle_gap_dependency_v1', 'status' => 'blocked_data_dependency',
            'run_id' => $run->run_id, 'lab_generation_id' => (int) $run->lab_generation_id,
            'lab_agent_id' => (int) $run->lab_agent_id, 'model_version_id' => (int) $run->model_version_id,
            'dataset_hash' => $run->data_hash, 'primary_stream_sha256' => $primary,
            'model_runtime_identity_hash' => $original['artifact_hash'], 'request_artifact_hash' => $requestArtifact->sha256,
            'response_artifact_hash' => $responseArtifact->sha256,
            'same_evidence_replay_forbidden' => true, 'scientific_question_budget_reset' => false,
            'strategy_verdict' => 'withheld', 'promotion_evidence' => false];
    }

    /**
     * A withheld draft is not an evaluator retry. Do not infer this from prose:
     * the original dispatch event, terminal generation projection and absence
     * of any replay evidence must agree. A replacement is a separate policy.
     *
     * @return array<string, mixed>|null
     */
    private function immutableDraftIntegrityDisposition(LabAgent $agent): ?array
    {
        $generation = $agent->generation;
        if ((string) $agent->lifecycle_status !== 'technical_quarantine'
            || $generation === null || (string) $generation->status !== 'technical_quarantine'
            || ! Schema::hasTable('lab_lifecycle_events') || ! Schema::hasTable('lab_evidence_artifacts')) {
            return null;
        }
        $events = LabLifecycleEvent::query()
            ->where('lab_generation_id', $generation->id)->where('lab_agent_id', $agent->id)
            ->where('event_type', 'draft_integrity_quarantine')->limit(2)->get();
        if ($events->count() !== 1) {
            return null;
        }
        $event = $events->first();
        $payload = (array) $event->payload;
        if ((string) $event->source !== \App\Console\Commands\DispatchLabGeneration::class
            || (string) $event->reason_code !== 'DRAFT_IDENTITY_INTEGRITY_BREACH'
            || (string) $event->from_status !== 'draft' || (string) $event->to_status !== 'technical_quarantine'
            || (string) $event->phase !== 'screening' || $event->run_id !== null
            || ($payload['reason_code'] ?? null) !== 'DRAFT_IDENTITY_INTEGRITY_BREACH'
            || ($payload['quality_verdict'] ?? null) !== 'withheld'
            || ($payload['promotion_evidence'] ?? null) !== false) {
            return null;
        }
        $violations = $payload['violations'] ?? null;
        if (! is_array($violations) || ! array_is_list($violations) || $violations === []
            || count(array_filter($violations, fn ($value) => is_string($value) && trim($value) !== '')) !== count($violations)
            || count(array_unique($violations)) !== count($violations)) {
            return null;
        }
        $attestations = data_get($generation->trigger_context, 'draft_integrity_quarantines');
        if (! is_array($attestations) || ! array_is_list($attestations)) {
            return null;
        }
        $matching = array_values(array_filter($attestations, fn ($row) => is_array($row)
            && (int) ($row['agent_id'] ?? 0) === (int) $agent->id));
        if (count($matching) !== 1 || ($matching[0]['promotion_evidence'] ?? null) !== false
            || ! is_array($matching[0]['violations'] ?? null)) {
            return null;
        }
        $declared = $matching[0]['violations'];
        sort($declared);
        sort($violations);
        if ($declared !== $violations) {
            return null;
        }
        $scope = fn ($query) => $query->where('lab_generation_id', $generation->id)
            ->orWhere('lab_agent_id', $agent->id);
        if (LabEvaluationRun::query()->where($scope)->exists()
            || LabEvidenceArtifact::query()->where($scope)->exists()) {
            return null;
        }

        return [
            'class' => self::TERMINAL,
            'reason_code' => 'IMMUTABLE_DRAFT_INTEGRITY_QUARANTINE',
            'capability' => null,
            'blocks_global_generation' => false,
            'action' => 'TERMINAL_DIAGNOSTIC',
            'reason' => 'The dispatcher withheld this draft before replay; its immutable identity failure is diagnostic history, not evaluator recovery debt.',
            'strategy_verdict' => 'withheld',
            'source_lifecycle_event_id' => $event->event_id,
            'replacement_authorized' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function classify(string $message, ?string $errorClass = null): array
    {
        $normalized = strtolower($message);
        // Only this canonical positive-count gate is an immutable continuity
        // dependency. An unknown hard-gate or a timeout is not silently waived.
        if (preg_match('/historical data hard-gate failed: [1-9][0-9]* unexpected candle gaps\./', $normalized) === 1) {
            return ['class' => self::CAPABILITY, 'reason_code' => 'IMMUTABLE_HISTORICAL_CANDLE_GAP',
                'capability' => 'sealed_historical_candle_continuity', 'blocks_global_generation' => false,
                'action' => 'QUARANTINE_CAPABILITY_LANE', 'strategy_verdict' => 'withheld',
                'same_evidence_replay_forbidden' => true, 'scientific_question_budget_reset' => false,
                'reason' => 'The frozen data failed canonical candle continuity; repair and reseal actual provider data before new admission, not the strategy or a same-input replay.'];
        }
        if (str_contains($normalized, 'research_release_') || str_contains($normalized, 'research_worker_')) {
            return ['class' => self::TERMINAL, 'reason_code' => 'RESEARCH_RELEASE_PROVENANCE_INVALID',
                'capability' => 'sealed_runtime_release', 'blocks_global_generation' => false,
                'action' => 'TERMINAL_DIAGNOSTIC',
                'reason' => 'A sealed experiment cannot change its source, worker, dataset or cost identity mid-flight.'];
        }
        if (str_contains($normalized, 'autonomous_mtf_bundle_missing')
            || str_contains($normalized, 'mtf_bundle_missing_or_invalid')) {
            return [
                'class' => self::TERMINAL,
                'reason_code' => 'IMMUTABLE_MTF_ADMISSION_CONTRACT_MISSING',
                'capability' => 'multi_timeframe_snapshot',
                'blocks_global_generation' => false,
                'action' => 'TERMINAL_DIAGNOSTIC',
                'reason' => 'The generation was admitted without its frozen M5/H4/H1/M15 bundle; replay cannot repair that immutable construction boundary.',
            ];
        }
        if (str_contains($normalized, 'volume quality gate')
            || str_contains($normalized, 'volume_unavailable')
            || str_contains($normalized, 'volume coverage')) {
            return [
                'class' => self::CAPABILITY,
                'reason_code' => 'VOLUME_CAPABILITY_MISSING',
                'capability' => 'volume',
                'blocks_global_generation' => false,
                'action' => 'QUARANTINE_CAPABILITY_LANE',
                'reason' => 'Canonical volume evidence is unavailable; volume-dependent hypotheses remain isolated while price-only research may continue.',
            ];
        }
        if (str_contains($normalized, 'zero_diff')
            || str_contains($normalized, 'one_gene_invariant_failed')
            || str_contains($normalized, 'strict lab preflight failed')
            || str_contains($normalized, 'constructor abort')
            || str_contains($normalized, 'constructor contract')
            || str_contains($normalized, 'composition_')
            || str_contains($normalized, 'not executable')) {
            return [
                'class' => self::TERMINAL,
                'reason_code' => 'IMMUTABLE_EXPERIMENT_TERMINAL',
                'capability' => null,
                'blocks_global_generation' => false,
                'action' => 'TERMINAL_DIAGNOSTIC',
                'reason' => 'The immutable experiment cannot be repaired by replay.',
            ];
        }

        $reasonCode = match (true) {
            str_contains($normalized, 'sqlstate[22001]'),
            str_contains($normalized, 'string data, right truncated'),
            str_contains($normalized, 'data too long for column') => 'DATABASE_SCHEMA_WIDTH_MISMATCH',
            str_contains($normalized, 'maxattemptsexceededexception'),
            str_contains($normalized, 'attempted too many times'),
            str_contains($normalized, 'bounded screening batch exhausted operational retries') => 'REPLAY_RETRY_BUDGET_EXHAUSTED',
            str_contains($normalized, 'bounded ai replay exceeded'),
            str_contains($normalized, 'curl error 28'),
            str_contains($normalized, 'operation timed out'),
            str_contains($normalized, 'timed out after'),
            str_contains($normalized, 'timeouterror: causal confirmation fold')
                && str_contains($normalized, 'exceeded its')
                && str_contains($normalized, 'budget') => 'REPLAY_TRANSPORT_TIMEOUT',
            str_contains($normalized, 'curl error 56'),
            str_contains($normalized, 'connection was reset'),
            str_contains($normalized, 'recv failure'),
            str_contains($normalized, 'failed to connect'),
            str_contains($normalized, 'connection refused') => 'AI_SERVICE_UNAVAILABLE',
            default => 'UNCLASSIFIED_TRANSIENT',
        };

        return [
            'class' => self::TRANSIENT,
            'reason_code' => $reasonCode,
            'capability' => null,
            'blocks_global_generation' => true,
            'action' => 'RECOVER_TECHNICAL',
            'reason' => trim((string) $errorClass.' '.$message),
        ];
    }
}
