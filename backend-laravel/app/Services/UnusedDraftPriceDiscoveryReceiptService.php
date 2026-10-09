<?php

namespace App\Services;

use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningSettlement;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;

/** Original price/Entry/WAIT observations; no economic or causal interpretation of the old pairs. */
class UnusedDraftPriceDiscoveryReceiptService
{
    public const PROTOCOL = 'unused_draft_price_discovery_original_receipt_v1';
    public const RECEIPTS = 'unused_draft_price_discovery_receipts';
    public const PAIRS = 'unused_draft_price_discovery_neutral_pairs';

    public function __construct(private UnusedDraftPriceDiscoveryPreparationService $preparations,
        private LabImmutableEvidenceService $evidence, private ResearchPaperEpochContractService $hashes,
        private LabGenerationContextService $contexts) {}

    /** After immutable finishRun only; the completed original envelopes are always reopened. */
    public function record(LabEvaluationRun $run): array
    {
        return DB::transaction(function () use ($run): array {
            $generation = LabGeneration::whereKey($run->lab_generation_id)->lockForUpdate()->firstOrFail();
            $prepared = $this->preparations->assertOwner($generation, (array) data_get($generation->trigger_context, 'mtf_bundle_manifest', []));
            if ($run->status !== 'completed' || $run->phase !== 'screening' || ! $run->finished_at || (int) $run->attempt !== 1
                || LabEvaluationRun::where('lab_agent_id', $run->lab_agent_id)->count() !== 1) $this->deny('ONE_ORIGINAL_COMPLETED_SCREEN_REQUIRED');
            $request = $this->artifact($run, 'evaluation_request');
            $response = $this->artifact($run, 'evaluation_response');
            $header = (array) data_get($request, 'policy_context.'.UnusedDraftPriceDiscoveryPreparationService::OWNER, []);
            if (($header['preparation_hash'] ?? null) !== $prepared['preparation_hash']
                || ($header['physical_question_key'] ?? null) !== $prepared['physical_question_key']
                || ($header['original_agent_id'] ?? null) !== (int) $run->lab_agent_id
                || ($header['original_model_id'] ?? null) !== (int) $run->model_version_id
                || ($header['generation_id'] ?? null) !== (int) $generation->id
                || $run->data_hash !== $prepared['bundle_hash'] || $run->code_hash !== $prepared['source_hash']
                || ! $this->evidence->verifiedModelRuntimeIdentity($run)) $this->deny('ORIGINAL_REQUEST_RESPONSE_IDENTITY_INVALID');
            $probe = (array) data_get($request, 'policy_context.prospective_probe_window', []);
            $observedProbe = (array) data_get($response, 'prospective_probe_window_receipt', []);
            if (($probe['loaded_rows'] ?? null) !== 15512 || ($probe['evaluated_rows'] ?? null) !== 15000
                || ($probe['warmup_rows'] ?? null) !== 512 || ! app(ProspectiveRepairProbeWindowService::class)->attests($probe, $observedProbe)) {
                $this->deny('ORIGINAL_ROW_PERIOD_RECEIPT_REQUIRED');
            }
            $release = (array) ($request['research_release'] ?? []);
            if (! app(ResearchReleaseSealService::class)->responseValid($release,
                (array) data_get($response, 'data_quality.research_release_receipt', []))) $this->deny('ORIGINAL_RELEASE_RECEIPT_REQUIRED');
            $manifest = (array) data_get($request, 'mtf_snapshot_manifest', []);
            if ($this->contextHash($manifest) !== $prepared['manifest_hash']) $this->deny('ORIGINAL_MTF_REQUEST_DRIFT');
            $coverage = $this->evidence->decisionTraceCompleteness($response, $run);
            $expected = $prepared['resource_contract']['expected_ordinary_decision_candle_coverage'];
            if (($coverage['complete'] ?? null) !== true || ($coverage['evaluated_candle_count'] ?? null) !== $expected
                || ($coverage['covered_candle_count'] ?? null) !== $expected) $this->deny('ORIGINAL_EVALUATED_DECISION_COVERAGE_REQUIRED');
            $facts = $this->decisionFacts($run, $probe, $expected, $manifest,
                (array) data_get($response, 'decision_trace', data_get($response, 'candle_decision_trace', data_get($response, 'decision_events', []))));
            $receipt = ['protocol' => self::PROTOCOL, 'generation_id' => (int) $generation->id,
                'kind' => 'measured_research_observation', 'measurement_available' => true, 'replay_executed' => true,
                'agent_id' => (int) $run->lab_agent_id, 'model_id' => (int) $run->model_version_id,
                'preparation_hash' => $prepared['preparation_hash'], 'physical_question_key' => $prepared['physical_question_key'],
                'original_run_id' => $run->run_id, 'request_hash' => $run->request_hash, 'response_hash' => $run->response_hash,
                'data_hash' => $run->data_hash, 'source_hash' => $run->code_hash,
                'native_full_dependency_hash' => $prepared['native_full_dependency_hash'],
                'probe_window_receipt' => $observedProbe, 'decision_facts' => $facts,
                'price_basis' => 'attributed_native_bid_and_secondary_composite_mid_modelled_research_prices',
                'observed_native_quotes' => false, 'observed_volume' => false,
                'economics_interpreted' => false, 'causal_effect_inferred' => false,
                'independent_evidence' => false, 'full_validation_eligible' => false, 'paper_eligible' => false,
                'selection_reward' => 0.0, 'causal_credit_allowed' => false, 'economic_credit_allowed' => false, 'promotion_evidence' => false];
            return $this->publish($generation, $receipt);
        });
    }

    /** A technical disposition is not a measured zero effect or a rewritten original run. */
    public function terminal(LabEvaluationRun $run): array
    {
        if ($run->status === 'completed') {
            try { return $this->record($run); }
            catch (LogicException $error) {
                return $this->technical($run, $error->getMessage());
            }
        }
        return $this->technical($run, 'ORIGINAL_TERMINAL_TECHNICAL_RESULT');
    }

    private function technical(LabEvaluationRun $run, string $reason): array
    {
        return DB::transaction(function () use ($run, $reason): array {
            $generation = LabGeneration::whereKey($run->lab_generation_id)->lockForUpdate()->firstOrFail();
            $prepared = $this->preparations->assertOwner($generation, (array) data_get($generation->trigger_context, 'mtf_bundle_manifest', []));
            $agent = $run->agent()->with('modelVersion', 'generation')->firstOrFail();
            $this->preparations->assertAttempt($agent, 'screening', $run);
            if (! in_array($run->status, ['technical_error', 'skipped', 'retry_released', 'completed'], true) || ! $run->finished_at
                || $run->code_hash !== $prepared['source_hash'] || $run->parameter_hash !== $this->evidence->parameterHash($agent)) $this->deny('ORIGINAL_TECHNICAL_RUN_IDENTITY_INVALID');
            $response = $this->artifact($run, 'evaluation_response');
            $requestPresent = $run->request_hash !== null;
            if ($requestPresent) {
                $request = $this->artifact($run, 'evaluation_request');
                if (data_get($request, 'policy_context.'.UnusedDraftPriceDiscoveryPreparationService::OWNER.'.preparation_hash') !== $prepared['preparation_hash']
                    || ! $this->evidence->verifiedModelRuntimeIdentity($run)) $this->deny('ORIGINAL_TECHNICAL_REQUEST_OWNER_INVALID');
            } elseif (LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_request')->exists()) {
                $this->deny('ORIGINAL_PRE_REQUEST_EVIDENCE_DRIFT');
            }
            $receipt = ['protocol' => self::PROTOCOL, 'kind' => 'technical_unassessable',
                'measurement_available' => false, 'replay_executed' => $requestPresent ? null : false, 'generation_id' => (int) $generation->id,
                'agent_id' => (int) $run->lab_agent_id, 'model_id' => (int) $run->model_version_id,
                'preparation_hash' => $prepared['preparation_hash'], 'physical_question_key' => $prepared['physical_question_key'],
                'original_run_id' => $run->run_id, 'original_run_status' => $run->status,
                'request_hash' => $run->request_hash, 'response_hash' => $run->response_hash,
                'data_hash' => $run->data_hash, 'source_hash' => $run->code_hash,
                'native_full_dependency_hash' => $prepared['native_full_dependency_hash'],
                'request_available' => $requestPresent, 'original_terminal_envelope' => isset($response['terminal_replay_envelope']),
                'technical_reason' => $reason, 'decision_facts' => null, 'strategy_verdict' => 'withheld',
                'economic_credit_allowed' => false, 'causal_credit_allowed' => false,
                'independent_evidence' => false, 'full_validation_eligible' => false, 'paper_eligible' => false,
                'selection_reward' => 0, 'promotion_evidence' => false];
            $receipt = $this->publish($generation, $receipt);
            $agent->update(['lifecycle_status' => 'technical_quarantine',
                'decision_reason' => 'UNUSED_PRICE_DISCOVERY_ORIGINAL_TECHNICAL_WITHHELD']);
            return $receipt;
        });
    }

    /** Actual existing queue failed callbacks use the one original attempt, never manufacture a replacement. */
    public function failed(LabAgent $agent, \Throwable $error, ?LabEvaluationRun $run = null): array
    {
        $agent->loadMissing('modelVersion', 'generation');
        if (! $this->preparations->declares($agent->generation)) $this->deny('FAILURE_OWNER_NOT_DECLARED');
        $runs = LabEvaluationRun::where('lab_agent_id', $agent->id)->where('lab_generation_id', $agent->lab_generation_id)->get();
        $run ??= $runs->count() === 1 ? $runs->first() : null;
        if (! $run || $runs->count() !== 1) return ['status' => 'blocked', 'reason' => 'UNUSED_PRICE_DISCOVERY_ORIGINAL_ATTEMPT_MISSING',
            'promotion_evidence' => false];
        if (! $this->evidence->isTerminalRun($run)) {
            $this->evidence->finishRun($run, 'technical_error', null, [],
                ['reason_code' => 'UNUSED_PRICE_DISCOVERY_ORIGINAL_QUEUE_FAILURE', 'quality_verdict' => 'withheld',
                    'promotion_evidence' => false], $error);
        }
        return $this->terminal($run->fresh());
    }

    /** A failed original control genuinely prevents this unchanged candidate's first execution. */
    public function refuseControl(LabAgent $agent, int $controlId): array
    {
        return DB::transaction(function () use ($agent, $controlId): array {
            $generation = LabGeneration::whereKey($agent->lab_generation_id)->lockForUpdate()->firstOrFail();
            $prepared = $this->preparations->assertOwner($generation, (array) data_get($generation->trigger_context, 'mtf_bundle_manifest', []));
            $agent->load('modelVersion');
            if ((int) data_get($agent->modelVersion->metadata, 'control_pair_contract.control_agent_id') !== $controlId
                || $agent->id === $controlId || LabEvaluationRun::where('lab_agent_id', $agent->id)->exists()) $this->deny('EXACT_UNEXECUTED_CONTROL_REFUSAL_REQUIRED');
            $control = LabAgent::whereKey($controlId)->where('lab_generation_id', $generation->id)->firstOrFail();
            if (! app(FrozenControlScreeningAdmissionService::class)->isControl($control->load('modelVersion'))) $this->deny('ORIGINAL_CONTROL_ROLE_INVALID');
            $controlRun = LabEvaluationRun::where('lab_agent_id', $control->id)->sole();
            $original = $this->terminal($controlRun);
            if (($original['measurement_available'] ?? null) !== false) $this->deny('CONTROL_NOT_TECHNICALLY_WITHHELD');
            $receipt = ['protocol' => self::PROTOCOL, 'kind' => 'pre_execution_control_refusal', 'measurement_available' => false,
                'replay_executed' => false, 'generation_id' => (int) $generation->id, 'agent_id' => (int) $agent->id,
                'model_id' => (int) $agent->model_version_id, 'preparation_hash' => $prepared['preparation_hash'],
                'physical_question_key' => $prepared['physical_question_key'], 'original_run_id' => null,
                'request_hash' => null, 'response_hash' => null, 'data_hash' => null, 'source_hash' => $prepared['source_hash'],
                'original_control_agent_id' => $controlId, 'original_control_run_id' => $controlRun->run_id,
                'original_control_receipt_hash' => $original['receipt_hash'],
                'native_full_dependency_hash' => $prepared['native_full_dependency_hash'], 'attempts_executed' => 0,
                'decision_facts' => null, 'strategy_verdict' => 'withheld', 'selection_reward' => 0,
                'economic_credit_allowed' => false, 'causal_credit_allowed' => false,
                'independent_evidence' => false, 'full_validation_eligible' => false, 'paper_eligible' => false, 'promotion_evidence' => false];
            $receipt = $this->publish($generation->fresh(), $receipt);
            $agent->update(['lifecycle_status' => 'technical_quarantine', 'decision_reason' => 'UNUSED_PRICE_DISCOVERY_ORIGINAL_CONTROL_ADMISSION_REFUSED']);
            return $receipt;
        });
    }

    private function publish(LabGeneration $generation, array $receipt): array
    {
        $receipt['receipt_hash'] = $this->contextHash($receipt);
        $prior = data_get($generation->trigger_context, self::RECEIPTS.'.'.$receipt['agent_id']);
        if ($prior !== null && $this->contextHash($prior) !== $this->contextHash($receipt)) $this->deny('ORIGINAL_RECEIPT_REWRITE_FORBIDDEN');
        $this->contexts->update($generation, function ($context) use ($receipt) {
            $context[self::RECEIPTS][(string) $receipt['agent_id']] = $receipt;
            return $context;
        });
        $this->neutralEpisodes($generation->fresh(), $receipt);
        $this->neutralPairs($generation->fresh());
        return $receipt;
    }

    /** A genuine completed control receipt replaces only this discovery's ordinary-learning projection wait. */
    public function controlAdmission(LabAgent $agent, int $controlId): array
    {
        $generation = $agent->generation()->firstOrFail();
        $this->preparations->assertOwner($generation, (array) data_get($generation->trigger_context, 'mtf_bundle_manifest', []));
        $run = LabEvaluationRun::where('lab_agent_id', $controlId)->where('lab_generation_id', $generation->id)->first();
        if (! $run || ! $this->evidence->isTerminalRun($run)) return ['agent_id' => $agent->id, 'status' => 'waiting',
            'reason' => 'UNUSED_PRICE_DISCOVERY_ORIGINAL_CONTROL_RECEIPT_PENDING', 'control_agent_id' => $controlId];
        $receipt = $this->terminal($run);
        if (($receipt['measurement_available'] ?? null) !== true) return ['agent_id' => $agent->id, 'status' => 'blocked',
            'reason' => 'UNUSED_PRICE_DISCOVERY_CONTROL_TECHNICAL_WITHHELD', 'control_agent_id' => $controlId, 'promotion_evidence' => false];
        return ['agent_id' => $agent->id, 'status' => 'ready', 'reason' => 'UNUSED_PRICE_DISCOVERY_ORIGINAL_CONTROL_COMPLETED',
            'control_agent_id' => $controlId, 'promotion_evidence' => false];
    }

    /** Existing canonical terminal owner may close only all twenty originals and ten neutral pairs. */
    public function reconcileGeneration(LabGeneration $generation): ?array
    {
        if (! $this->preparations->declares($generation)) return null;
        try {
            $agents = $generation->agents()->orderBy('id')->get();
            $runs = LabEvaluationRun::where('lab_generation_id', $generation->id)->orderBy('lab_agent_id')->get();
            if ($agents->count() !== 20 || $agents->contains(fn ($agent) => ! in_array($agent->lifecycle_status, ['screened', 'technical_quarantine'], true))
                || $runs->count() > 20 || $runs->pluck('lab_agent_id')->unique()->count() !== $runs->count()
                || $runs->contains(fn ($run) => ! in_array($run->status, ['completed', 'technical_error', 'skipped', 'retry_released'], true) || $run->phase !== 'screening')) $this->deny('TWENTY_ORIGINALS_NOT_TERMINAL');
            foreach ($runs as $run) $this->terminal($run);
            $generation = $generation->fresh();
            $receipts = (array) data_get($generation->trigger_context, self::RECEIPTS, []);
            $pairs = (array) data_get($generation->trigger_context, self::PAIRS, []);
            $episodes = AgentLearningEpisode::whereIn('lab_agent_id', $agents->pluck('id'))->with('settlement')->get();
            foreach ($agents as $agent) {
                $receipt = $receipts[$agent->id] ?? null;
                if (! $receipt) $this->deny('ORIGINAL_AGENT_DISPOSITION_MISSING');
                if (($receipt['kind'] ?? null) === 'pre_execution_control_refusal') $this->refuseControl($agent, $receipt['original_control_agent_id']);
            }
            if (count($receipts) !== 20 || count($pairs) !== 10 || $episodes->count() !== 20
                || $episodes->contains(fn ($episode) => $episode->status !== 'settled'
                    || $episode->settlement?->source_type !== self::class || $episode->settlement?->evidence_state !== 'neutral'
                    || $episode->settlement?->selection_reward !== 0.0 || $episode->settlement?->hard_failure !== false)) $this->deny('ORIGINAL_NEUTRAL_PROJECTION_NOT_CLOSED');
            return ['status' => 'settled_zero_authority', 'protocol' => self::PROTOCOL,
                'original_terminal_run_count' => $runs->count(), 'original_completed_runs' => $runs->where('status', 'completed')->count(),
                'original_technical_runs' => $runs->whereIn('status', ['technical_error', 'skipped', 'retry_released'])->count(),
                'withheld_pre_execution_originals' => count(array_filter($receipts, fn ($receipt) => $receipt['kind'] === 'pre_execution_control_refusal')),
                'measured_originals' => count(array_filter($receipts, fn ($receipt) => $receipt['measurement_available'] === true)),
                'neutral_original_pairs' => 10, 'neutral_original_episodes' => 20,
                'native_full_dependency_preserved' => true, 'promotion_evidence' => false];
        } catch (\Throwable $error) {
            return ['status' => 'blocked', 'protocol' => self::PROTOCOL,
                'reason' => $error instanceof LogicException ? $error->getMessage() : 'UNUSED_PRICE_DISCOVERY_ORIGINAL_RECEIPT_UNAVAILABLE',
                'promotion_evidence' => false];
        }
    }

    private function neutralEpisodes(LabGeneration $generation, array $receipt): void
    {
        $intent = (array) data_get($generation->trigger_context, UnusedDraftPriceDiscoveryPreparationService::INTENT, []);
        $original = collect(data_get($intent, 'original_snapshot.agents', []))->firstWhere('agent_id', $receipt['agent_id']);
        foreach ((array) ($original['episodes'] ?? []) as $witness) {
            $episode = AgentLearningEpisode::whereKey($witness['id'])->lockForUpdate()->firstOrFail();
            if ((int) $episode->lab_agent_id !== $receipt['agent_id'] || (int) $episode->model_version_id !== $receipt['model_id']
                || $this->hashes->parameterHash((array) $episode->decision_context) !== $witness['decision_context_hash']) $this->deny('ORIGINAL_EPISODE_DRIFT');
            $key = self::PROTOCOL.'|'.$receipt['receipt_hash'].'|'.$episode->id;
            $existing = AgentLearningSettlement::where('episode_id', $episode->id)->first();
            if ($existing) {
                if ($existing->source_key !== $key || $existing->source_type !== self::class
                    || $existing->outcome_status !== 'authority_withheld' || $existing->failure_class !== 'research_scope_only'
                    || $existing->evidence_state !== 'neutral' || $existing->selection_reward !== 0.0 || $existing->hard_failure
                    || data_get($existing->outcome, 'original_receipt_hash') !== $receipt['receipt_hash']
                    || data_get($existing->outcome, 'original_episode_context_hash') !== $witness['decision_context_hash']
                    || data_get($existing->outcome, 'economic_credit_allowed') !== false
                    || data_get($existing->outcome, 'causal_credit_allowed') !== false) $this->deny('EPISODE_OTHER_OWNER_COLLISION');
                continue;
            }
            if (! in_array($episode->status, ['open', 'decision', 'running'], true)) $this->deny('EPISODE_ALREADY_DISPOSED');
            AgentLearningSettlement::create(['settlement_id' => (string) Str::uuid(), 'episode_id' => $episode->id,
                'source_key' => $key, 'source_type' => self::class, 'source_id' => $receipt['generation_id'],
                'outcome_status' => 'authority_withheld', 'failure_class' => 'research_scope_only', 'evidence_state' => 'neutral',
                'selection_reward' => 0.0, 'hard_failure' => false,
                'outcome' => ['protocol' => self::PROTOCOL, 'original_receipt_hash' => $receipt['receipt_hash'],
                    'original_run_id' => $receipt['original_run_id'], 'original_episode_context_hash' => $witness['decision_context_hash'],
                    'economic_credit_allowed' => false, 'causal_credit_allowed' => false, 'promotion_evidence' => false],
                'reward_components' => ['signal_authority' => 'none', 'promotion_evidence' => false],
                'reflection' => ['next_action' => 'native_full_and_independent_data_dependencies_unchanged',
                    'scientific_lesson_inferred' => false, 'promotion_evidence' => false], 'settled_at' => now()]);
            $episode->update(['status' => 'settled', 'settled_at' => now()]);
        }
    }

    private function neutralPairs(LabGeneration $generation): void
    {
        $receipts = (array) data_get($generation->trigger_context, self::RECEIPTS, []);
        $agents = $generation->agents()->with('modelVersion')->orderBy('id')->get();
        $pairs = [];
        foreach ((array) data_get($generation->trigger_context, 'control_pairing_contract.materialized_controls', []) as $original) {
            $control = $agents->get((int) ($original['control_slot'] ?? 0) - 1);
            $candidate = $agents->get((int) ($original['candidate_slot'] ?? 0) - 1);
            if (! $control || ! $candidate || ! isset($receipts[$control->id], $receipts[$candidate->id])) continue;
            $pair = ['protocol' => self::PROTOCOL, 'original_pair_hash' => $this->contextHash($original),
                'original_block_key' => $original['block_key'], 'original_pair_key' => $original['pair_key'],
                'control_agent_id' => (int) $control->id, 'candidate_agent_id' => (int) $candidate->id,
                'original_receipt_hashes' => [$receipts[$control->id]['receipt_hash'], $receipts[$candidate->id]['receipt_hash']],
                'outcome' => $receipts[$control->id]['measurement_available'] && $receipts[$candidate->id]['measurement_available']
                    ? 'neutral_research_observation_only' : 'neutral_technical_or_pre_execution_disposition', 'component_effects' => [],
                'measurement_available' => $receipts[$control->id]['measurement_available'] && $receipts[$candidate->id]['measurement_available'],
                'negative_skill_inferred' => false, 'confirmed_skill_inferred' => false,
                'economic_credit_allowed' => false, 'causal_credit_allowed' => false, 'promotion_evidence' => false];
            $pair['disposition_hash'] = $this->contextHash($pair);
            $pairs[$original['pair_key']] = $pair;
        }
        $this->contexts->update($generation, function ($context) use ($pairs) {
            foreach ($pairs as $key => $pair) {
                $prior = $context[self::PAIRS][$key] ?? null;
                if ($prior !== null && $this->contextHash($prior) !== $this->contextHash($pair)) $this->deny('ORIGINAL_PAIR_DISPOSITION_REWRITE');
                $context[self::PAIRS][$key] = $pair;
            }
            return $context;
        });
    }

    private function decisionFacts(LabEvaluationRun $run, array $probe, int $expected, array $manifest, array $originalTrace): array
    {
        $artifact = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'decision_trace')->sole();
        $trace = $this->storedArtifact($artifact, $run);
        $rows = is_array($trace) ? ($trace['events'] ?? $trace['rows'] ?? $trace) : [];
        if (! is_array($rows) || ! array_is_list($rows) || $this->contextHash($rows) !== $this->contextHash($originalTrace)) $this->deny('ORIGINAL_DECISION_TRACE_REQUIRED');
        $path = (string) data_get($manifest, 'streams.M5.path', '');
        $sha = (string) data_get($manifest, 'streams.M5.sha256', '');
        if (! is_file($path) || ! preg_match('/^[a-f0-9]{64}$/D', $sha) || hash_file('sha256', $path) !== $sha) $this->deny('ORIGINAL_PHYSICAL_M5_BYTES_REQUIRED');
        $physical = app(LabDatasetExportService::class)->rowsFromSnapshot($path, 15512);
        // SQL and ISO timestamp spellings refer to the same UTC instant. This
        // comparison projection never rewrites original CSV/artifact bytes.
        foreach ($physical as &$candle) {
            try { $candle['time'] = CarbonImmutable::parse((string) $candle['time'], 'UTC')->utc()->format('Y-m-d\TH:i:s\Z'); }
            catch (\Throwable) { $this->deny('ORIGINAL_PHYSICAL_M5_UTC_INVALID'); }
        }
        unset($candle);
        if (count($physical) !== 15512 || (int) data_get($manifest, 'streams.M5.row_count') !== 15512
            || $physical[0]['time'] !== $probe['loaded_start'] || $physical[15511]['time'] !== $probe['loaded_end']
            || $physical[512]['time'] !== $probe['evaluated_start'] || $physical[15511]['time'] !== $probe['evaluated_end']) $this->deny('ORIGINAL_PHYSICAL_M5_PERIOD_REQUIRED');
        $counts = ['ENTRY' => 0, 'WAIT' => 0, 'EXIT' => 0, 'OTHER' => 0]; $evaluated = []; $attemptedEntries = 0; $events = 0;
        foreach ($rows as $row) {
            if (! in_array($row['event_type'] ?? null, ['signal_evaluation', 'position_management'], true)) continue;
            $time = (string) ($row['candle_time'] ?? '');
            if ($time === '') $this->deny('ORIGINAL_DECISION_TIME_REQUIRED');
            try { $time = CarbonImmutable::parse($time, 'UTC')->utc()->format('Y-m-d\TH:i:s\Z'); }
            catch (\Throwable) { $this->deny('ORIGINAL_DECISION_TIME_REQUIRED'); }
            $index = $row['candle_index'] ?? null;
            if (! is_int($index) || $index < 200 || $index >= 15000
                || $time !== $physical[512 + $index]['time']) $this->deny('ORIGINAL_DECISION_PHYSICAL_UTC_MISMATCH');
            $decision = strtoupper((string) ($row['decision'] ?? $row['action'] ?? ''));
            if (in_array($decision, ['BUY', 'SELL', 'ENTRY'], true)) $attemptedEntries++;
            $bucket = in_array($decision, ['BUY', 'SELL', 'ENTRY'], true) ? (($row['accepted'] ?? null) === true ? 'ENTRY' : 'WAIT')
                : (in_array($decision, ['WAIT', 'HOLD', 'ABSTAIN'], true) ? 'WAIT' : ($decision === 'EXIT' ? 'EXIT' : 'OTHER'));
            $counts[$bucket]++; $events++; $evaluated[$index] = true;
        }
        if (count($evaluated) !== $expected || min(array_keys($evaluated)) !== 200
            || max(array_keys($evaluated)) !== 14999) $this->deny('ORIGINAL_TRACE_PHYSICAL_COVERAGE_REQUIRED');
        return ['protocol' => 'observed_price_discovery_decision_facts_v1', 'trace_hash' => $artifact->sha256,
            'observed_trace_rows' => count($rows), 'observed_evaluated_candle_coverage' => count($evaluated),
            'decision_event_counts' => $counts, 'observed_evaluated_event_count' => $events,
            'event_count_is_not_unique_candle_coverage' => true,
            'attempted_entry_events' => $attemptedEntries, 'evaluated_rows' => $probe['evaluated_rows'],
            'feature_warmup_rows' => 512, 'unchanged_ordinary_execution_warmup_rows' => 200,
            'actual_decision_coverage_is_not_selected_row_count' => true,
            'warmup_excluded' => true, 'absent_native_quote_inputs' => 'unknown', 'absent_volume_inputs' => 'unknown',
            'economic_verdict' => 'withheld', 'promotion_evidence' => false];
    }

    private function artifact(LabEvaluationRun $run, string $type): array
    {
        $rows = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', $type)->limit(2)->get();
        if ($rows->count() !== 1 || ! $rows[0]->created_at || $rows[0]->created_at->gt($run->finished_at)) $this->deny('SINGLE_ORIGINAL_ARTIFACT_REQUIRED');
        if (($type === 'evaluation_request' && (data_get($rows[0]->metadata, 'request_hash') !== $run->request_hash
                || $rows[0]->sha256 !== $run->request_hash))
            || ($type === 'evaluation_response' && $rows[0]->sha256 !== $run->response_hash)) $this->deny('ORIGINAL_RAW_ARTIFACT_HASH_INVALID');
        $payload = $this->storedArtifact($rows[0], $run);
        if (! is_array($payload)) $this->deny('ORIGINAL_ARTIFACT_BYTES_REQUIRED');
        return $payload;
    }

    private function storedArtifact(LabEvidenceArtifact $artifact, LabEvaluationRun $run): array
    {
        $path = (string) $artifact->storage_path;
        $disk = (string) data_get($artifact->metadata, 'storage_disk');
        if (data_get($artifact->metadata, 'storage_protocol') !== 'compressed_artifact_v2'
            || $artifact->content_encoding !== 'json+gzip' || ! str_starts_with($path, 'lab-evidence/')
            || ! in_array($disk, ['lab_evidence', 'local'], true) || ! $artifact->created_at
            || $artifact->created_at->gt($run->finished_at)) $this->deny('ACTUAL_COMPRESSED_ORIGINAL_ARTIFACT_REQUIRED');
        $absolute = $disk === 'lab_evidence' ? storage_path('app/'.$path) : Storage::disk('local')->path($path);
        $root = $disk === 'lab_evidence' ? storage_path('app/lab-evidence') : Storage::disk('local')->path('lab-evidence');
        $resolved = realpath($absolute); $resolvedRoot = realpath($root);
        if ($resolved === false || $resolvedRoot === false || ! str_starts_with(str_replace('\\', '/', $resolved),
            rtrim(str_replace('\\', '/', $resolvedRoot), '/').'/')) $this->deny('ACTUAL_ORIGINAL_ARTIFACT_BYTES_MISSING');
        if (filesize($resolved) !== (int) $artifact->byte_size || filesize($resolved) > 67108864) $this->deny('ORIGINAL_ARTIFACT_BYTE_BUDGET_OR_SIZE_INVALID');
        $compressed = file_get_contents($resolved);
        $raw = $compressed === false ? false : gzdecode($compressed);
        if ($raw === false || hash('sha256', $raw) !== $artifact->sha256) $this->deny('ACTUAL_ORIGINAL_ARTIFACT_BYTE_HASH_INVALID');
        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) $this->deny('ACTUAL_ORIGINAL_ARTIFACT_JSON_INVALID');
        return $payload;
    }

    /** Numeric JSON projection equality only; original file/request/response hashes stay byte-exact. */
    private function contextHash(array $value): string
    {
        return app(ExecutionContractService::class)->hashParameters($value);
    }

    private function deny(string $reason): never { throw new LogicException('UNUSED_PRICE_DISCOVERY_'.$reason); }
}
