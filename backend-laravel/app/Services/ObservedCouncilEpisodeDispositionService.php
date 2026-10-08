<?php

namespace App\Services;

use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningSettlement;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabLifecycleEvent;
use App\Models\ResearchExperimentReceipt;
use App\Models\ResearchExperimentWorkItem;
use App\Models\SpecialistCouncilVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Operational compensation only; never re-evaluates an old research result. */
class ObservedCouncilEpisodeDispositionService
{
    public const PROTOCOL = 'observed_council_zero_authority_terminal_v1';
    private const KIND = 'observed_probe_attestation_completion';
    private const REASON = 'OBSERVED_COUNCIL_TECHNICAL_COMPLETION_WITHHOLDS_DERIVED_LEARNING';

    public function __construct(private SpecialistCouncilResearchFeedbackService $feedback,
        private LabImmutableEvidenceService $evidence, private ResearchPaperEpochContractService $epochs,
        private ResearchReleaseSealService $releases, private LabQueueJobInspector $queues) {}

    /** Pure historical evidence inspection. No current-source equality or recovery call. */
    public function inspectGeneration(LabGeneration $generation): array
    {
        $generation = $generation->fresh(['agents.modelVersion']);
        $workId = data_get($generation?->trigger_context, 'native_specialist_council_intent.followup_work_item_id');
        $work = $workId ? ResearchExperimentWorkItem::find($workId) : null;
        $body = (array) data_get($work?->payload, 'followup_resolution', []);
        if (($body['scientific_question_kind'] ?? null) !== self::KIND) {
            return ['protocol' => self::PROTOCOL, 'status' => 'not_applicable', 'allowed' => false,
                'reason_code' => 'NOT_OBSERVED_COUNCIL_COMPLETION', 'promotion_evidence' => false];
        }
        try {
            if (! $generation || ! $work || $work->status !== 'settled'
                || data_get($work->result, 'status') !== 'canonical_dispatch_admitted'
                || data_get($work->result, 'generation_id') !== $generation->id
                || data_get($work->result, 'resolution_hash') !== ($body['resolution_hash'] ?? null)
                || data_get($generation->trigger_context, 'native_specialist_council_intent.followup_resolution_hash') !== ($body['resolution_hash'] ?? null)) {
                throw new LogicException('OBSERVED_COUNCIL_DISPATCH_OWNER_INVALID');
            }
            $root = data_get($body, 'original_observed_probe_completion_proof.root.root_version_id');
            if (! is_int($root) || $root <= 0
                || data_get($body, 'original_observed_probe_completion_proof.max_completions_per_root') !== 1
                || ResearchExperimentWorkItem::where('payload->followup_resolution->scientific_question_kind', self::KIND)
                    ->where('payload->followup_resolution->original_observed_probe_completion_proof->root->root_version_id', $root)->count() !== 1) {
                throw new LogicException('OBSERVED_COUNCIL_GLOBAL_ROOT_CLAIM_INVALID');
            }
            $agents = $generation->agents->sortBy('id')->values();
            if ((int) $generation->population_size !== 6 || $agents->count() !== 6
                || $agents->contains(fn ($agent) => $agent->lifecycle_status !== 'screened')) {
                throw new LogicException('OBSERVED_COUNCIL_EXACT_TERMINAL_SIX_REQUIRED');
            }
            $backlog = $this->queues->generationQueueBacklog($agents->pluck('id')->all(),
                ['lab-screening', 'lab-frontier', 'lab-full-validation', 'lab-learning']);
            if (($backlog['available'] ?? true) !== true || ($backlog['total'] ?? null) !== 0) {
                throw new LogicException('OBSERVED_COUNCIL_PHYSICAL_WORK_NOT_DRAINED');
            }
            $versionId = data_get($generation->trigger_context, 'specialist_council_preparation.version_id');
            $version = SpecialistCouncilVersion::find($versionId);
            $planRow = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $versionId)->first();
            $exam = DB::table('specialist_council_evaluations')->where('specialist_council_version_id', $versionId)->first();
            $assessment = (array) $version?->assessment;
            $plan = $planRow ? json_decode($planRow->plan, true, flags: JSON_THROW_ON_ERROR) : [];
            if (! $version || $version->state !== 'evaluated' || ! $planRow || ! $exam
                || $version->assessment_hash !== $this->epochs->parameterHash($assessment)
                || $exam->assessment_hash !== $version->assessment_hash
                || $this->epochs->parameterHash($plan) !== $planRow->plan_hash
                || ! app(SpecialistCouncilContractService::class)->manifestValid($version->manifest)
                || data_get($generation->trigger_context, 'specialist_council_preparation.manifest_hash') !== $version->manifest_hash
                || $this->epochs->parameterHash(json_decode($exam->assessment, true, flags: JSON_THROW_ON_ERROR)) !== $version->assessment_hash
                || data_get($generation->trigger_context, 'specialist_council_preparation.plan_hash') !== $planRow->plan_hash
                || ($assessment['protocol'] ?? null) !== SpecialistCouncilLifecycleService::ASSESSMENT_PROTOCOL
                || ($assessment['version_id'] ?? null) !== $version->id || ($assessment['manifest_hash'] ?? null) !== $version->manifest_hash
                || ($assessment['plan_hash'] ?? null) !== $planRow->plan_hash || ($assessment['qualified'] ?? null) !== false) {
                throw new LogicException('OBSERVED_COUNCIL_ORIGINAL_ASSESSMENT_INVALID');
            }
            $receipts = ResearchExperimentReceipt::where('source_type', SpecialistCouncilVersion::class)->where('source_id', $versionId)->get();
            if ($receipts->count() !== 1) throw new LogicException('OBSERVED_COUNCIL_ORIGINAL_FEEDBACK_REQUIRED');
            $receipt = $receipts->sole();
            $contract = (array) data_get($receipt->payload, 'contract', []);
            $facts = (array) data_get($receipt->payload, 'evidence', []);
            if ($receipt->classification !== 'TECHNICAL_QUARANTINE'
                || $receipt->contract_hash !== $this->epochs->parameterHash($contract)
                || $receipt->evidence_hash !== $this->epochs->parameterHash($facts)
                || ($facts['assessment_hash'] ?? null) !== $version->assessment_hash
                || ($facts['plan_hash'] ?? null) !== $planRow->plan_hash
                || ($facts['research_observation_status'] ?? null) !== 'technical_unassessable'
                || ($facts['original_run_ids'] ?? null) !== ($assessment['original_run_ids'] ?? null)
                || data_get($contract, 'source.type') !== SpecialistCouncilVersion::class || data_get($contract, 'source.id') !== $versionId) {
                throw new LogicException('OBSERVED_COUNCIL_IMMUTABLE_FEEDBACK_INVALID');
            }
            $witnesses = []; $roles = []; $archives = [];
            foreach ($agents as $agent) {
                $model = $agent->modelVersion;
                $seed = (array) data_get($model?->metadata, 'native_specialist_council_seed', []);
                $role = $seed['slot_role'] ?? '';
                if (isset($roles[$role])) throw new LogicException('OBSERVED_COUNCIL_DUPLICATE_ROLE');
                $roles[$role] = true;
                $runs = LabEvaluationRun::where('lab_agent_id', $agent->id)->orderBy('id')->get();
                if ($runs->count() !== 1 || $runs[0]->lab_generation_id !== $generation->id
                    || $runs[0]->model_version_id !== $model?->id || $runs[0]->status !== 'completed' || ! $runs[0]->finished_at) {
                    throw new LogicException('OBSERVED_COUNCIL_ORIGINAL_COMPLETED_RUN_REQUIRED');
                }
                $run = $runs[0];
                $disposition = $this->feedback->screeningProjectionDisposition($agent, $run);
                if (($disposition['allow_derived_learning'] ?? null) !== false || ($disposition['reason_code'] ?? null) !== self::REASON
                    || ($disposition['work_item_id'] ?? null) !== $work->id || ($disposition['root_version_id'] ?? null) !== $root
                    || ($disposition['resolution_hash'] ?? null) !== ($body['resolution_hash'] ?? null)) {
                    throw new LogicException('OBSERVED_COUNCIL_SIGNED_PROJECTION_OWNER_INVALID');
                }
                $events = LabLifecycleEvent::where('lab_agent_id', $agent->id)->where('run_id', $run->run_id)
                    ->where('event_type', 'screening_learning_projection_withheld')->get();
                if ($events->count() !== 1 || $events[0]->lab_generation_id !== $generation->id
                    || $events[0]->reason_code !== self::REASON
                    || ! $this->evidence->equivalentJsonValue($events[0]->payload, $disposition)) {
                    throw new LogicException('OBSERVED_COUNCIL_ORIGINAL_WITHHOLDING_INVALID');
                }
                $request = $this->originalArtifact($run, 'evaluation_request');
                $response = $this->originalArtifact($run, 'evaluation_response');
                if (! is_array($request) || ! is_array($response) || $this->evidence->hash($response) !== $run->response_hash
                    || ! $this->evidence->verifiedModelRuntimeIdentity($run)) throw new LogicException('OBSERVED_COUNCIL_IMMUTABLE_RUN_INVALID');
                $seal = (array) ($request['research_release'] ?? []);
                if (($seal['source_hash'] ?? null) !== $run->code_hash || ($seal['dataset_hash'] ?? null) !== $run->data_hash
                    || ($seal['release_hash'] ?? null) !== data_get($generation->trigger_context, 'research_release.release_hash')
                    || ! $this->releases->responseValid($seal, (array) data_get($response, 'data_quality.research_release_receipt', []))) {
                    throw new LogicException('OBSERVED_COUNCIL_ORIGINAL_RELEASE_INVALID');
                }
                $archive = (array) ($seal['source_artifact'] ?? []); $address = $archive['artifact_hash'] ?? '';
                if (! isset($archives[$address])) $archives[$address] = [...$this->releases->verifySourceArtifact($archive, false),
                    'original_reference_hash' => $this->epochs->parameterHash($archive)];
                if ($archives[$address]['original_reference_hash'] !== $this->epochs->parameterHash($archive)) {
                    throw new LogicException('OBSERVED_COUNCIL_ORIGINAL_ARCHIVE_REFERENCE_CHANGED');
                }
                if (data_get($archives[$address], 'manifest.source_identity.source_hash') !== $run->code_hash
                    || data_get($archives[$address], 'manifest.source_identity.python_source_hash') !== ($seal['python_source_hash'] ?? null)) {
                    throw new LogicException('OBSERVED_COUNCIL_ORIGINAL_ARCHIVE_INVALID');
                }
                $episodes = AgentLearningEpisode::where('lab_agent_id', $agent->id)->with('settlement')->get();
                if ($episodes->count() !== 1 || $episodes[0]->model_version_id !== $model->id
                    || $episodes[0]->stage !== 'mutation_selection'
                    || (int) data_get($model->metadata, 'learning_decision.episode_id') !== $episodes[0]->id) {
                    throw new LogicException('OBSERVED_COUNCIL_ORIGINAL_SINGLE_EPISODE_REQUIRED');
                }
                $witnesses[] = ['agent_id' => (int) $agent->id, 'model_id' => (int) $model->id, 'slot_role' => $role,
                    'episode_id' => (int) $episodes[0]->id, 'run_id' => $run->run_id, 'request_hash' => $run->request_hash,
                    'response_hash' => $run->response_hash, 'data_hash' => $run->data_hash, 'source_hash' => $run->code_hash,
                    'withholding_event_id' => (int) $events[0]->id, 'source_artifact_hash' => $address];
            }
            $expected = ['source_scalp', 'source_hour', 'source_day', 'source_swing', 'candidate_carrier', 'ablation_carrier'];
            $actual = array_keys($roles); sort($actual); sort($expected);
            if ($actual !== $expected) throw new LogicException('OBSERVED_COUNCIL_EXACT_ROLE_SET_REQUIRED');
            $proof = ['protocol' => self::PROTOCOL, 'generation_id' => (int) $generation->id, 'work_item_id' => (int) $work->id,
                'resolution_hash' => $body['resolution_hash'], 'root_version_id' => $root, 'version_id' => (int) $version->id,
                'plan_hash' => $planRow->plan_hash, 'assessment_hash' => $version->assessment_hash,
                'feedback_receipt_id' => (int) $receipt->id, 'feedback_evidence_hash' => $receipt->evidence_hash,
                'original_classification' => $receipt->classification, 'originals' => $witnesses,
                'generation_terminal_status' => 'technical_quarantine', 'authority_granted' => false,
                'quality_verdict_changed' => false, 'promotion_evidence' => false];
            $proof['proof_hash'] = $this->epochs->parameterHash($proof);
            foreach ($witnesses as $witness) {
                $episode = AgentLearningEpisode::findOrFail($witness['episode_id']);
                $previous = $episode->settlement;
                if ($previous && (! $this->existingMatches($previous, $proof, $witness) || $episode->status !== 'settled')) {
                    throw new LogicException('OBSERVED_COUNCIL_EPISODE_OTHER_OWNER_COLLISION');
                }
                if (! $previous && ! in_array($episode->status, ['open', 'decision', 'running'], true)) {
                    throw new LogicException('OBSERVED_COUNCIL_EPISODE_DISPOSITION_DRIFT');
                }
            }
            return ['protocol' => self::PROTOCOL, 'status' => 'eligible', 'allowed' => true, 'reason_code' => 'ORIGINAL_ZERO_AUTHORITY_EPISODE_DISPOSITION_READY',
                'proof' => $proof, 'promotion_evidence' => false];
        } catch (\Throwable $error) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'allowed' => false,
                'reason_code' => $error instanceof LogicException ? $error->getMessage() : 'OBSERVED_COUNCIL_ORIGINAL_OWNER_UNAVAILABLE', 'promotion_evidence' => false];
        }
    }

    public function reconcileGeneration(LabGeneration $generation): array
    {
        return DB::transaction(function () use ($generation) {
            $locked = LabGeneration::whereKey($generation->id)->lockForUpdate()->firstOrFail();
            $workId = data_get($locked->trigger_context, 'native_specialist_council_intent.followup_work_item_id');
            if ($workId) {
                $work = ResearchExperimentWorkItem::whereKey($workId)->lockForUpdate()->first();
                $rootId = data_get($work?->payload, 'followup_resolution.original_observed_probe_completion_proof.root.root_version_id');
                if (is_int($rootId)) SpecialistCouncilVersion::whereKey($rootId)->lockForUpdate()->first();
            }
            $result = $this->inspectGeneration($locked);
            if (! $result['allowed']) return $result;
            $proof = $result['proof']; $settlementIds = [];
            foreach ($proof['originals'] as $witness) {
                $episode = AgentLearningEpisode::whereKey($witness['episode_id'])->lockForUpdate()->firstOrFail();
                $existing = AgentLearningSettlement::where('episode_id', $episode->id)->first();
                if ($existing) {
                    if (! $this->existingMatches($existing, $proof, $witness) || $episode->status !== 'settled') {
                        throw new LogicException('OBSERVED_COUNCIL_EPISODE_OTHER_OWNER_COLLISION');
                    }
                    $settlementIds[] = $existing->id; continue;
                }
                if (! in_array($episode->status, ['open', 'decision', 'running'], true)
                    || (int) $episode->lab_agent_id !== $witness['agent_id'] || (int) $episode->model_version_id !== $witness['model_id']
                    || $episode->stage !== 'mutation_selection') {
                    throw new LogicException('OBSERVED_COUNCIL_EPISODE_DISPOSITION_DRIFT');
                }
                $outcome = $this->outcome($proof, $witness);
                $settlement = AgentLearningSettlement::create(['settlement_id' => (string) Str::uuid(), 'episode_id' => $episode->id,
                    'source_key' => $this->sourceKey($proof, $witness), 'source_type' => self::class, 'source_id' => $proof['feedback_receipt_id'],
                    'outcome_status' => 'authority_withheld', 'failure_class' => 'projection_withheld', 'evidence_state' => 'neutral',
                    'selection_reward' => 0.0, 'hard_failure' => false, 'outcome' => $outcome,
                    'reward_components' => ['protocol' => self::PROTOCOL, 'signal_authority' => 'none', 'promotion_evidence' => false],
                    'reflection' => ['protocol' => self::PROTOCOL, 'next_action' => 'existing_feedback_dependency_only',
                        'scientific_lesson_inferred' => false, 'promotion_evidence' => false], 'settled_at' => now()]);
                $episode->update(['status' => 'settled', 'settled_at' => now()]);
                $settlementIds[] = $settlement->id;
            }
            return [...$result, 'status' => 'settled_zero_authority', 'settlement_ids' => $settlementIds];
        });
    }

    private function sourceKey(array $proof, array $witness): string
    {
        return self::PROTOCOL.'|'.$proof['proof_hash'].'|'.$witness['episode_id'];
    }

    private function existingMatches(AgentLearningSettlement $existing, array $proof, array $witness): bool
    {
        return $existing->source_key === $this->sourceKey($proof, $witness)
            && $existing->source_type === self::class && (int) $existing->source_id === $proof['feedback_receipt_id']
            && $existing->outcome_status === 'authority_withheld' && $existing->failure_class === 'projection_withheld'
            && $existing->evidence_state === 'neutral' && $existing->selection_reward === 0.0 && ! $existing->hard_failure
            && $this->evidence->equivalentJsonValue($existing->outcome, $this->outcome($proof, $witness));
    }

    private function originalArtifact(LabEvaluationRun $run, string $type): array
    {
        $artifacts = \App\Models\LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', $type)->limit(2)->get();
        if ($artifacts->count() !== 1 || ! $artifacts[0]->storage_path
            || data_get($artifacts[0]->metadata, 'storage_protocol') !== 'compressed_artifact_v2'
            || ! $artifacts[0]->created_at || $artifacts[0]->created_at->gt($run->finished_at)
            || ($type === 'evaluation_request' && data_get($artifacts[0]->metadata, 'request_hash') !== $run->request_hash)
            || ($type === 'evaluation_response' && $artifacts[0]->sha256 !== $run->response_hash)) {
            throw new LogicException('OBSERVED_COUNCIL_ORIGINAL_SINGLE_ARTIFACT_REQUIRED');
        }
        $payload = $this->evidence->readArtifactPayload($artifacts[0]);
        if (! is_array($payload)) throw new LogicException('OBSERVED_COUNCIL_ORIGINAL_ARTIFACT_BYTES_REQUIRED');
        return $payload;
    }

    private function outcome(array $proof, array $witness): array
    {
        return ['protocol' => self::PROTOCOL, 'proof_hash' => $proof['proof_hash'], 'generation_id' => $proof['generation_id'],
            'work_item_id' => $proof['work_item_id'], 'resolution_hash' => $proof['resolution_hash'], 'root_version_id' => $proof['root_version_id'],
            'feedback_receipt_id' => $proof['feedback_receipt_id'], 'feedback_evidence_hash' => $proof['feedback_evidence_hash'],
            'original_run_id' => $witness['run_id'], 'original_request_hash' => $witness['request_hash'], 'original_response_hash' => $witness['response_hash'],
            'withholding_event_id' => $witness['withholding_event_id'], 'metrics' => [], 'quality_verdict_changed' => false,
            'causal_credit_allowed' => false, 'economic_credit_allowed' => false, 'selection_reward_authorized' => false, 'promotion_evidence' => false];
    }
}
