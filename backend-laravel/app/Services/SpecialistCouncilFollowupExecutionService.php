<?php

namespace App\Services;

use App\Models\LabGeneration;
use App\Models\ResearchExperimentWorkItem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use LogicException;
use Throwable;

/** Executes one arbiter-leased prospective question through the original constructor and dispatcher. */
class SpecialistCouncilFollowupExecutionService
{
    public const PROTOCOL = 'specialist_council_followup_execution_v1';

    public const DISCOVERY_TYPES = ['specialist_council_technical_repair', 'specialist_council_data_repair',
        'specialist_council_power_extension'];

    // A scheduled constructor child is bounded to 2370s. The initial work
    // lease covers it plus 330s dispatch margin, below the 3000s mutex. It
    // is never renewed by a checkpoint and confers no research authority.
    public const WORK_LEASE_SECONDS = 2700;

    public function __construct(
        private SpecialistCouncilResearchFeedbackService $feedback,
        private ResearchExperimentConversionKernelService $conversion,
        private LabPopulationService $population,
        private SpecialistCouncilPreparationService $preparation,
        private ResearchPaperEpochContractService $epochs,
        private AutonomousModeService $autonomy,
    ) {}

    public function execute(ResearchExperimentWorkItem $item): array
    {
        $lock = Cache::lock('specialist-council-followup-work:'.$item->id, LabPopulationService::CONSTRUCTOR_LOCK_TTL_SECONDS);
        if (! $lock->get()) return $this->defer($item, 'COUNCIL_FOLLOWUP_OWNER_BUSY', true);
        try {
            $this->assertLease($item);
            $proof = $this->feedback->inspectFollowupReadiness($item->fresh());
            if (($proof['executable'] ?? false) !== true) return $this->defer($item, $proof['reason'] ?? 'COUNCIL_PREREQUISITE_PROOF_REQUIRED', false);
            // The constructor's persisted intent is the crash-recovery pointer:
            // it exists in the original transaction before any model is written.
            $owned = LabGeneration::query()->where(fn ($q) => $q
                ->where('trigger_context->native_specialist_council_intent->followup_work_item_id', (int) $item->id)
                ->orWhereHas('agents.modelVersion', fn ($m) => $m->where('metadata->native_specialist_council_seed->followup_work_item_id', (int) $item->id)))->get();
            // Projection loss must never renew the one-cohort budget. A saved
            // pointer is evidence of ownership, not permission to trust drift.
            $checkpointId = (int) data_get($item->result, 'generation_id', 0);
            if ($checkpointId) {
                if (data_get($item->result, 'protocol') !== self::PROTOCOL
                    || data_get($item->result, 'resolution_hash') !== $proof['resolution_hash']) {
                    throw new LogicException('COUNCIL_FOLLOWUP_ORIGINAL_COHORT_PROOF_DRIFT');
                }
                $checkpointGeneration = LabGeneration::find($checkpointId);
                if (! $checkpointGeneration) throw new LogicException('COUNCIL_FOLLOWUP_ORIGINAL_COHORT_PROOF_DRIFT');
                $owned = $owned->push($checkpointGeneration)->unique('id')->values();
            }
            if ($owned->count() > 1) throw new LogicException('COUNCIL_FOLLOWUP_MULTIPLE_COHORTS_FOR_ONE_WORK');
            $generation = $owned->first();
            if ($generation && ($generation->laboratory?->symbol !== $item->symbol || $generation->laboratory?->timeframe !== $item->timeframe)) {
                throw new LogicException('COUNCIL_FOLLOWUP_ORIGINAL_COHORT_SCOPE_DRIFT');
            }
            $active = LabGeneration::query()->whereHas('laboratory', fn ($q) => $q->where('symbol', $item->symbol)->where('timeframe', $item->timeframe))
                ->whereIn('status', LabPopulationService::ACTIVE_GENERATION_STATUSES)->get();
            if ($active->contains(fn ($g) => ! $generation || $g->id !== $generation->id)) return $this->defer($item, 'COUNCIL_FOLLOWUP_ANOTHER_GENERATION_OWNS_STREAM', false);
            if (! $generation) {
                $generation = $this->population->build((string) $item->symbol, GenerationAdmissionDecisionService::HISTORICAL_TRIGGER,
                    false, (string) $item->timeframe, [], false, false, 6, null, false, null, [
                        'protocol' => LabPopulationService::NATIVE_COUNCIL_INTENT_PROTOCOL, 'purpose' => 'research',
                        'symbol' => (string) $item->symbol, 'storage_timeframe' => (string) $item->timeframe,
                        'population_size' => 6, 'research_question' => $proof['research_question'], 'creator_id' => $proof['creator_id'],
                        'followup_work_item_id' => (int) $item->id, 'followup_resolution_hash' => $proof['resolution_hash'],
                    ]);
                if (! $generation) return $this->defer($item, (string) ($this->population->lastBuildOutcome()['reason_code'] ?? 'COUNCIL_CANONICAL_CONSTRUCTION_DEFERRED'), false);
            }
            if ((int) data_get($generation->trigger_context, 'native_specialist_council_intent.followup_work_item_id') !== (int) $item->id
                || data_get($generation->trigger_context, 'native_specialist_council_intent.followup_resolution_hash') !== $proof['resolution_hash']) {
                throw new LogicException('COUNCIL_FOLLOWUP_ORIGINAL_COHORT_PROOF_DRIFT');
            }
            $this->checkpoint($item, $generation, $proof, 'constructed');
            if (LabPopulationService::constructionIncomplete($generation)) {
                $result = $this->population->continueInterruptedConstruction((int) $generation->id, 6);
                $this->assertLease($item);
                $generation->refresh();
                if (LabPopulationService::constructionIncomplete($generation)) {
                    return $this->defer($item, 'COUNCIL_FOLLOWUP_CONSTRUCTION_CHECKPOINT_PENDING', empty($result['failures']));
                }
            }
            if ($generation->status !== 'draft') {
                if ($this->admitted($generation)) return $this->complete($item, $generation, $proof);
                throw new LogicException('COUNCIL_FOLLOWUP_ORIGINAL_COHORT_NOT_ADMISSIBLE');
            }
            $this->assertLease($item);
            $request = $this->preparationRequest($generation, $proof);
            $this->preparation->prepare($generation, $request);
            $generation->refresh();
            $this->checkpoint($item, $generation, $proof, 'prepared');
            $this->assertLease($item);
            // Canonical dispatch owns dataset/release admission and queue creation.
            // Exit zero alone is not success; verify its durable batch witness below.
            Artisan::call('trading:dispatch-lab', ['symbol' => $item->symbol, '--timeframe' => $item->timeframe, '--resume-draft-agents' => true]);
            $this->assertLease($item);
            $generation->refresh();
            if (! $this->admitted($generation)) return $this->defer($item, 'COUNCIL_FOLLOWUP_CANONICAL_DISPATCH_NOT_ADMITTED', false);
            return $this->complete($item, $generation, $proof);
        } catch (Throwable $error) {
            return $this->defer($item, $error instanceof LogicException || $error instanceof \InvalidArgumentException
                ? $error->getMessage() : 'COUNCIL_FOLLOWUP_EXECUTOR_TECHNICAL_FAILURE', false);
        } finally { $lock->release(); }
    }

    /** Rebind the sealed old-role template only to this exact fresh cohort's original models. */
    public function preparationRequest(LabGeneration $generation, array $proof): array
    {
        $agents = $generation->agents()->with('modelVersion')->get();
        if ($agents->count() !== 6) throw new LogicException('COUNCIL_FOLLOWUP_EXACT_SIX_MODELS_REQUIRED');
        $roles = []; $map = [];
        foreach ($agents as $agent) {
            $slot = (string) data_get($agent->modelVersion?->metadata, 'native_specialist_council_seed.slot_role');
            if (isset($roles[$slot])) throw new LogicException('COUNCIL_FOLLOWUP_DUPLICATE_NATIVE_ROLE');
            $role = str_starts_with($slot, 'source_') ? substr($slot, 7) : 'day';
            $spec = $proof['native_source_models'][$role] ?? null;
            if (! $spec || $agent->strategy_family !== $spec['family']
                || $this->epochs->parameterHash((array) $agent->modelVersion->parameters) !== $spec['parameter_hash']
                || data_get($agent->modelVersion->metadata, 'strategy_architecture') !== $spec['strategy_architecture']) {
                throw new LogicException('COUNCIL_FOLLOWUP_ACTUAL_NATIVE_VECTOR_MISMATCH');
            }
            $roles[$slot] = (int) $agent->model_version_id;
            if (str_starts_with($slot, 'source_')) $map[(int) $spec['model_version_id']] = (int) $agent->model_version_id;
        }
        foreach (['source_scalp', 'source_hour', 'source_day', 'source_swing', 'candidate_carrier', 'ablation_carrier'] as $slot) {
            if (! isset($roles[$slot])) throw new LogicException('COUNCIL_FOLLOWUP_NATIVE_ROLE_MISSING');
        }
        $manifest = $proof['manifest_template'];
        unset($manifest['manifest_hash']);
        $manifest['version'] = 'followup-'.$proof['work_item_id'].'-'.substr($proof['resolution_hash'], 0, 12);
        foreach ($manifest['members'] as &$member) {
            if (! in_array($member['role'], SpecialistCouncilContractService::TRADING_ROLES, true)) continue;
            $member['model_version_id'] = $roles['source_'.$member['role']];
            unset($member['passport_hash'], $member['source_model_hash']);
            $member['qualified_evidence'] = [];
            $member['uncertainty'] = ['status' => 'unqualified'];
        }
        unset($member);
        foreach (['champion_model_version_id', 'solo_model_version_id'] as $key) {
            $old = (int) ($manifest['evaluation_policy'][$key] ?? 0);
            if (! isset($map[$old])) throw new LogicException('COUNCIL_FOLLOWUP_COMPARATOR_SOURCE_UNMAPPED');
            $manifest['evaluation_policy'][$key] = $map[$old];
            unset($manifest['evaluation_policy'][$key.'_hash']);
        }
        $plan = $proof['evaluation_plan'];
        // Derived parent seals are never copied to the new original plan.
        foreach (['plan_hash', 'manifest_hash', 'version_id', 'preparation_source_hash', 'learning_consumption_receipt',
            'research_question_fingerprint', 'prior_feedback_digest'] as $field) unset($plan[$field]);
        foreach ($plan['arms'] as &$arm) {
            $arm['model_version_id'] = match ($arm['kind']) {
                'candidate' => $roles['candidate_carrier'], 'ablation' => $roles['ablation_carrier'],
                'solo' => $map[(int) $arm['model_version_id']] ?? throw new LogicException('COUNCIL_FOLLOWUP_SOLO_SOURCE_UNMAPPED'),
                default => throw new LogicException('COUNCIL_FOLLOWUP_UNSUPPORTED_ARM_KIND'),
            };
            unset($arm['model_hash']);
        }
        unset($arm);
        return ['protocol' => SpecialistCouncilPreparationService::PROTOCOL, 'creator_id' => $proof['creator_id'],
            'evaluator_id' => $proof['evaluator_id'], 'research_question' => $proof['research_question'],
            'carrier_model_version_id' => $roles['candidate_carrier'], 'manifest' => $manifest,
            'evaluation_plan' => $plan, 'discovery_bundle_manifest' => $proof['discovery_bundle_manifest']];
    }

    /** Actual dependency events, not minute keys, authorize retry of an operational refusal. */
    public function retryPrerequisiteHash(ResearchExperimentWorkItem $item): string
    {
        $latest = LabGeneration::query()->whereHas('laboratory', fn ($q) => $q->where('symbol', $item->symbol)->where('timeframe', $item->timeframe))
            ->orderByDesc('generation')->orderByDesc('id')->first();
        $queue = app(LabQueueJobInspector::class)->queueSnapshot();
        $velocity = app(LearningVelocityGateService::class)->inspect($item->symbol, $item->timeframe);
        $scope = ['resolution_hash' => data_get($item->payload, 'followup_resolution.resolution_hash'),
            'source_hash' => app(LabImmutableEvidenceService::class)->codeHash(),
            'mode' => data_get($this->autonomy->status($item->symbol, $item->timeframe), 'state'),
            'latest_generation_id' => $latest?->id, 'latest_status' => $latest?->status,
            'agent_states' => $latest ? $latest->agents()->selectRaw('lifecycle_status, count(*) as count')->groupBy('lifecycle_status')
                ->orderBy('lifecycle_status')->get()->map(fn ($a) => [$a->lifecycle_status, (int) $a->count])->all() : [],
            'constructor_failures' => data_get($latest?->trigger_context, 'constructor_audit.failures', []),
            'queue_available' => $queue['available'] ?? false, 'queue_total' => $queue['total'] ?? null,
            'velocity_status' => $velocity['status'] ?? null, 'velocity_allowed' => $velocity['allowed'] ?? null,
            'velocity_observations' => $velocity['observations'] ?? []];
        return $this->epochs->parameterHash($scope);
    }

    private function assertLease(ResearchExperimentWorkItem $item): void
    {
        $current = $item->fresh();
        if (! $current || $current->status !== 'leased' || $current->lease_token !== $item->lease_token
            || (int) $current->fence_version !== (int) $item->fence_version || ! $current->lease_expires_at || ! $current->lease_expires_at->isFuture()
            || data_get($current->payload, 'owner') !== ResearchLoopArbiterService::class) throw new LogicException('COUNCIL_FOLLOWUP_LEASE_NOT_CURRENT');
        if (! $this->autonomy->enabled($item->symbol, $item->timeframe)) throw new LogicException('AUTONOMOUS_MODE_STOPPED');
    }

    private function checkpoint(ResearchExperimentWorkItem $item, LabGeneration $generation, array $proof, string $stage): void
    {
        $this->assertLease($item);
        // Keep the original dependency refusal visible after a legitimate
        // retry. A constructive pointer is added, not a replacement history.
        $priorResult = (array) $item->fresh()->result;
        if (ResearchExperimentWorkItem::whereKey($item->id)->where('status', 'leased')->where('lease_token', $item->lease_token)
            ->where('fence_version', $item->fence_version)->where('lease_expires_at', '>', now())->update(['result' => [...$priorResult, 'protocol' => self::PROTOCOL, 'stage' => $stage,
                'generation_id' => (int) $generation->id, 'resolution_hash' => $proof['resolution_hash'], 'promotion_evidence' => false], 'heartbeat_at' => now()]) !== 1) {
            throw new LogicException('COUNCIL_FOLLOWUP_LEASE_NOT_CURRENT');
        }
    }

    private function admitted(LabGeneration $generation): bool
    {
        return in_array((string) $generation->status, ['screening', 'screened', 'completed'], true)
            && ! empty(data_get($generation->trigger_context, 'queue_batches.screening'))
            && $this->preparation->isResearchGeneration($generation);
    }

    private function complete(ResearchExperimentWorkItem $item, LabGeneration $generation, array $proof): array
    {
        $this->assertLease($item);
        $result = [...(array) $item->fresh()->result, 'protocol' => self::PROTOCOL, 'status' => 'canonical_dispatch_admitted', 'generation_id' => (int) $generation->id,
            'work_item_id' => (int) $item->id, 'source_receipt_id' => (int) $item->research_experiment_receipt_id,
            'resolution_hash' => $proof['resolution_hash'], 'preparation_receipt_hash' => data_get($generation->trigger_context, 'specialist_council_preparation.receipt_hash'),
            'next_owner' => 'canonical_lab_lifecycle', 'causal_claim_still_requires_settlement' => true, 'promotion_evidence' => false];
        $this->assertLease($item);
        return [...$result, 'status' => $this->conversion->complete($item, $result) ? 'completed' : 'stale_lease'];
    }

    private function defer(ResearchExperimentWorkItem $item, string $reason, bool $retryable): array
    {
        // A stopped owner and a stale fence never write a new hold. Every
        // other refusal remains visible until an actual prerequisite changes.
        if (! $retryable && ! in_array($reason, ['AUTONOMOUS_MODE_STOPPED', 'COUNCIL_FOLLOWUP_LEASE_NOT_CURRENT'], true)) {
            $hold = ['reason' => $reason, 'dependency_check_failed' => true, 'promotion_evidence' => false];
            try {
                $hold = ['reason' => $reason, 'prerequisite_hash' => $this->retryPrerequisiteHash($item), 'promotion_evidence' => false];
            } catch (Throwable) { /* An unavailable proof has no retry authority. */ }
            $current = $item->fresh();
            $result = [...(array) $current?->result, 'dependency_hold' => $hold];
            ResearchExperimentWorkItem::whereKey($item->id)->where('status', 'leased')->where('lease_token', $item->lease_token)
                ->where('fence_version', $item->fence_version)->where('lease_expires_at', '>', now())->update(['result' => $result]);
        }
        $this->conversion->defer($item, $reason, $retryable);
        return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => $reason, 'work_item_id' => (int) $item->id,
            'promotion_evidence' => false];
    }
}
