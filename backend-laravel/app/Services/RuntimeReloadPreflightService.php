<?php

namespace App\Services;

use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabCandleDecisionEvent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGateDecisionEvent;
use App\Models\LabGeneration;
use App\Models\LabLifecycleEvent;
use App\Models\LabMutationCreditEvent;
use App\Models\LabTrialLedger;
use App\Models\ModelMarketPerformance;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;

/**
 * Durable boundary for a PM2 rolling reload.
 *
 * AI liveness alone has a blind spot while a queue worker owns a job but is
 * still preparing its frozen snapshot. A reload in that interval kills the
 * PHP owner and leaves the Redis reservation invisible until retry_after.
 */
class RuntimeReloadPreflightService
{
    public const PROTOCOL = 'runtime_reload_preflight_v1';

    public function __construct(private readonly LabQueueJobInspector $queues) {}

    /** @return array<string,mixed> */
    public function inspect(?int $terminalProjectionRecoveryGenerationId = null, ?int $unsealedDraftColdMaintenanceGenerationId = null): array
    {
        $backlog = $this->queues->reservedQueueBacklog([
            'lab-screening', 'lab-frontier', 'lab-full-validation',
            'lab-xauusd', 'lab-eurusd', 'lab-gbpusd', 'lab-learning',
            'market-maintenance', 'scheduler-critical', 'scheduler-ops',
            'scheduler-constructor', 'scheduler-research', 'strategy-lab', 'backtests',
        ]);
        $activeGenerations = LabGeneration::query()->whereIn('status', [
            'draft', 'queued', 'training', 'screening',
            'full_queued', 'full_validation',
        ])->count();
        if ($unsealedDraftColdMaintenanceGenerationId !== null) {
            if ($terminalProjectionRecoveryGenerationId !== null) {
                return ['protocol' => self::PROTOCOL, 'safe' => false, 'reason' => 'COLD_MAINTENANCE_MODE_AMBIGUOUS',
                    'reserved_queue' => $backlog, 'active_generations' => $activeGenerations,
                    'rolling_reload_allowed' => false, 'replay_idle_probe_required' => true,
                    'scientific_evidence' => false, 'promotion_evidence' => false];
            }
            return $this->inspectUnsealedDraftColdMaintenance($unsealedDraftColdMaintenanceGenerationId, $backlog, $activeGenerations);
        }
        if ($terminalProjectionRecoveryGenerationId !== null) {
            return $this->inspectTerminalProjectionRecovery($terminalProjectionRecoveryGenerationId, $backlog, $activeGenerations);
        }
        $queueKnown = ($backlog['total'] ?? null) !== null;
        $safe = $queueKnown
            && (int) ($backlog['total'] ?? 0) === 0
            && $activeGenerations === 0;

        return [
            'protocol' => self::PROTOCOL,
            'safe' => $safe,
            'reason' => ! $queueKnown
                ? 'QUEUE_STATE_UNKNOWN'
                : ((int) ($backlog['total'] ?? 0) > 0
                    ? 'RESERVED_WORKER_JOB_EXISTS'
                    : ($activeGenerations > 0 ? 'ACTIVE_GENERATION_EXISTS' : 'IDLE')),
            'reserved_queue' => $backlog,
            'active_generations' => $activeGenerations,
            'promotion_evidence' => false,
        ];
    }

    /** Observe an unused constructor result; never seal, retire, dispatch, or probe-acquire its locks. */
    private function inspectUnsealedDraftColdMaintenance(int $generationId, array $backlog, int $activeGenerations): array
    {
        $result = ['protocol' => self::PROTOCOL, 'safe' => false,
            'mode' => 'unsealed_draft_cold_maintenance_v1',
            'reason' => 'UNSEALED_DRAFT_COLD_MAINTENANCE_REFUSED', 'generation_id' => $generationId,
            'reserved_queue' => $backlog, 'active_generations' => $activeGenerations,
            'rolling_reload_allowed' => false, 'replay_idle_probe_required' => true,
            'scientific_evidence' => false, 'promotion_evidence' => false];
        foreach (['total', 'pending', 'delayed'] as $key) {
            if (! is_int($backlog[$key] ?? null) || $backlog[$key] < 0) return [...$result, 'reason' => 'QUEUE_STATE_UNKNOWN'];
            if ($backlog[$key] !== 0) return [...$result, 'reason' => 'QUEUE_WORK_REMAINS'];
        }

        try {
            $generation = $generationId > 0 ? LabGeneration::with('laboratory', 'agents.modelVersion')->find($generationId) : null;
            if ($activeGenerations !== 1 || ! $generation || ! $generation->laboratory
                || $generation->status !== 'draft' || $generation->completed_at !== null) {
                return [...$result, 'reason' => 'UNSEALED_DRAFT_TARGET_NOT_SOLE_ACTIVE'];
            }
            $control = app(AutonomousModeService::class)->status($generation->laboratory->symbol, $generation->laboratory->timeframe);
            if (($control['enabled'] ?? null) !== false || ! in_array($control['state'] ?? null, ['draining', 'stopped'], true)) {
                return [...$result, 'reason' => 'DRAIN_FIRST_STOP_REQUIRED'];
            }
            $constructorKey = 'lab-population-constructor:'.strtoupper($generation->laboratory->symbol)
                .':'.strtoupper($generation->laboratory->timeframe).':v1';
            // Cache values and Redis locks can use different connections. isOwnedBy(null)
            // reads the actual lock owner without acquiring, releasing, or exposing it.
            $store = Cache::store()->getStore();
            // Database/file cache get() can delete expired values; refusing those
            // stores preserves the strictly read-only nature of this new mode.
            if (! $store instanceof RedisStore && ! $store instanceof ArrayStore) {
                return [...$result, 'reason' => 'CONSTRUCTOR_STATE_UNKNOWN'];
            }
            if (Cache::get($constructorKey.':owner') !== null || ! Cache::lock($constructorKey)->isOwnedBy(null)) {
                return [...$result, 'reason' => 'CONSTRUCTOR_OWNER_OR_LEASE_EXISTS'];
            }

            $context = (array) $generation->trigger_context;
            $ownerMarkers = ['native_specialist_council_intent', 'authorized_specialist_council_panel_intent',
                'specialist_council_authorized_panel', 'specialist_council_preparation',
                'native_spread_context_study', 'academy_trial_id', 'academy_experiment', 'academy_control_admission'];
            if ($generation->trigger_type === 'academy_experiment' || $this->hasMarker($context, $ownerMarkers)) {
                return [...$result, 'reason' => 'UNSEALED_DRAFT_SEPARATE_EXPERIMENT_OWNER'];
            }
            // Foundation/price snapshots, immutable constructor/control declarations,
            // and failed partition preparation do not attest queue admission.
            $admissionMarkers = ['research_release', 'mtf_bundle_hash', 'mtf_bundle_manifest', 'mtf_runtime_contract', 'queue_batches'];
            if ($this->hasMarker($context, $admissionMarkers) || $this->hasAdmissionProtocol($context)) {
                return [...$result, 'reason' => 'UNSEALED_DRAFT_ADMISSION_OR_EXECUTION_EVIDENCE'];
            }
            $audit = (array) ($context['constructor_audit'] ?? []);
            $planned = $audit['planned_slots'] ?? null;
            $plan = $context['generation_plan'] ?? null;
            $agents = $generation->agents;
            if (($audit['protocol'] ?? null) !== 'agent_constructor_invariant_v1'
                || ! is_int($planned) || $planned < 1 || ! is_array($plan) || ! array_is_list($plan)
                || count($plan) !== $planned || ($audit['created_agents'] ?? null) !== $planned
                || ($audit['skipped_zero_diff_slots'] ?? null) !== []
                || (int) $generation->population_size !== $planned || $agents->count() !== $planned
                || $this->hasMarker($context, ['constructor_contract_abort', 'controlled_rescue_constructor_abort', 'shadow_research_constructor_abort'])) {
                return [...$result, 'reason' => 'UNSEALED_DRAFT_CONSTRUCTION_INCOMPLETE'];
            }
            $modelIds = $agents->pluck('model_version_id')->map(fn ($id): int => (int) $id)->all();
            if (count(array_unique($modelIds)) !== $planned || in_array(0, $modelIds, true)
                || LabAgent::whereIn('model_version_id', $modelIds)->where('lab_generation_id', '!=', $generationId)->exists()) {
                return [...$result, 'reason' => 'UNSEALED_DRAFT_MODEL_OWNERSHIP_INVALID'];
            }
            $slots = [];
            foreach ($agents as $agent) {
                $model = $agent->modelVersion;
                if ($agent->lifecycle_status !== 'draft' || ! $model || $model->status !== 'testing'
                    || $model->promoted_at !== null || $model->invalidated_at !== null) {
                    return [...$result, 'reason' => 'UNSEALED_DRAFT_AGENT_OR_MODEL_NOT_UNUSED'];
                }
                $metadata = (array) $model->metadata;
                foreach (['train_score', 'validation_score', 'forward_score', 'champion_improvement',
                    'profit_factor', 'max_drawdown', 'risk_of_ruin'] as $score) {
                    if ($agent->getAttribute($score) !== null) return [...$result, 'reason' => 'UNSEALED_DRAFT_ADMISSION_OR_EXECUTION_EVIDENCE'];
                }
                if ((int) $agent->sample_count !== 0 || (int) $agent->rolling_wins !== 0) {
                    return [...$result, 'reason' => 'UNSEALED_DRAFT_ADMISSION_OR_EXECUTION_EVIDENCE'];
                }
                if ($this->hasMarker($metadata, ['native_specialist_council_seed', 'authorized_specialist_council_panel_seed',
                    'specialist_council', 'specialist_council_evaluation', 'native_spread_context_study', 'academy_experiment', 'academy_control_admission',
                    'original_source_execution_snapshot', 'policy_context.specialist_council_authorized_arm'])
                    || in_array($agent->origin, ['native_council_root', 'authorized_council_panel'], true)) {
                    return [...$result, 'reason' => 'UNSEALED_DRAFT_SEPARATE_EXPERIMENT_OWNER'];
                }
                if ($this->hasMarker($metadata, [...$admissionMarkers, 'last_result', 'last_screen_result', 'full_validation_batch', 'preflight_quarantine'])
                    || (int) ($metadata['evaluator_recovery_attempts'] ?? 0) !== 0 || $this->hasAdmissionProtocol($metadata)
                    || (data_get($metadata, 'lifecycle_sync.agent_status') !== null && data_get($metadata, 'lifecycle_sync.agent_status') !== 'draft')) {
                    return [...$result, 'reason' => 'UNSEALED_DRAFT_ADMISSION_OR_EXECUTION_EVIDENCE'];
                }
                if (preg_match('/_g'.preg_quote((string) $generation->generation, '/').'_a(\d+)$/', (string) $model->strategy, $match) !== 1) {
                    return [...$result, 'reason' => 'UNSEALED_DRAFT_CONSTRUCTOR_SLOT_INVALID'];
                }
                $slot = (int) $match[1];
                $spec = $plan[$slot - 1] ?? null;
                if ($slot < 1 || $slot > $planned || in_array($slot, $slots, true) || ! is_array($spec)
                    || ($spec['family'] ?? null) !== $agent->strategy_family || ($spec['origin'] ?? null) !== $agent->origin
                    || ($spec['target'] ?? null) !== ($metadata['generation_target'] ?? null)
                    || (int) $model->generation !== (int) $generation->generation
                    || $agent->symbol !== $generation->laboratory->symbol || $agent->timeframe !== $generation->laboratory->timeframe
                    || ($metadata['lab_symbol'] ?? null) !== $agent->symbol || ($metadata['lab_timeframe'] ?? null) !== $agent->timeframe
                    || ($metadata['origin'] ?? null) !== $agent->origin) {
                    return [...$result, 'reason' => 'UNSEALED_DRAFT_CONSTRUCTOR_SLOT_INVALID'];
                }
                $slots[] = $slot;
            }
            $agentIds = $agents->modelKeys();
            if (LabEvaluationRun::where('lab_generation_id', $generationId)->orWhereIn('lab_agent_id', $agentIds)->orWhereIn('model_version_id', $modelIds)->exists()
                || LabTrialLedger::where('lab_generation_id', $generationId)->orWhereIn('lab_agent_id', $agentIds)->orWhereIn('model_version_id', $modelIds)->exists()
                || LabEvidenceArtifact::where('lab_generation_id', $generationId)->orWhereIn('lab_agent_id', $agentIds)->exists()
                || LabGateDecisionEvent::where('lab_generation_id', $generationId)->orWhereIn('lab_agent_id', $agentIds)->exists()
                || LabCandleDecisionEvent::where('lab_generation_id', $generationId)->orWhereIn('lab_agent_id', $agentIds)->exists()
                || LabMutationCreditEvent::where('lab_generation_id', $generationId)->orWhereIn('lab_agent_id', $agentIds)->orWhereIn('model_version_id', $modelIds)->exists()
                || CandidateGateDecision::whereIn('lab_agent_id', $agentIds)->exists()
                || ModelMarketPerformance::whereIn('model_version_id', $modelIds)->exists()
                || LabLifecycleEvent::where(fn ($q) => $q->where('lab_generation_id', $generationId)->orWhereIn('lab_agent_id', $agentIds))
                    ->where(fn ($q) => $q->where('event_type', '!=', 'agent_created')->orWhereNotNull('run_id')->orWhereNotNull('attempt'))
                    ->exists()) {
                return [...$result, 'reason' => 'UNSEALED_DRAFT_ADMISSION_OR_EXECUTION_EVIDENCE'];
            }
            sort($slots);
            return [...$result, 'safe' => true, 'reason' => 'UNSEALED_DRAFT_COLD_MAINTENANCE_READY',
                'unsealed_draft_proof' => ['generation_id' => $generationId, 'planned_slots' => $planned,
                    'draft_agents' => $agents->count(), 'constructor_slots' => $slots,
                    'model_version_ids' => $modelIds, 'generation_status' => 'draft', 'replay_admission_granted' => false]];
        } catch (\Throwable) {
            return [...$result, 'reason' => 'UNSEALED_DRAFT_PROOF_UNAVAILABLE'];
        }
    }

    private function hasMarker(array $payload, array $paths): bool
    {
        foreach ($paths as $path) if (data_get($payload, $path) !== null) return true;
        return false;
    }

    private function hasAdmissionProtocol(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if ($key === 'protocol' && in_array($value, [ResearchReleaseSealService::PROTOCOL,
                GenerationSnapshotAdmissionService::PROTOCOL, 'autonomous_generation_closed_mtf_v1'], true)) return true;
            if (is_array($value) && $this->hasAdmissionProtocol($value)) return true;
        }
        return false;
    }

    /** Explicit cold maintenance only; the default rolling-reload contract is unchanged. */
    private function inspectTerminalProjectionRecovery(int $generationId, array $backlog, int $activeGenerations): array
    {
        $result = ['protocol' => self::PROTOCOL, 'safe' => false,
            'mode' => 'observed_council_terminal_projection_cold_maintenance_v1',
            'reason' => 'TERMINAL_PROJECTION_RECOVERY_REFUSED', 'generation_id' => $generationId,
            'reserved_queue' => $backlog, 'active_generations' => $activeGenerations,
            'rolling_reload_allowed' => false, 'replay_idle_probe_required' => true,
            'scientific_evidence' => false, 'promotion_evidence' => false];
        foreach (['total', 'pending', 'delayed'] as $key) {
            if (! is_int($backlog[$key] ?? null) || $backlog[$key] < 0) {
                return [...$result, 'reason' => 'QUEUE_STATE_UNKNOWN'];
            }
            if ($backlog[$key] !== 0) return [...$result, 'reason' => 'QUEUE_WORK_REMAINS'];
        }
        $generation = $generationId > 0 ? LabGeneration::with('laboratory')->find($generationId) : null;
        if ($activeGenerations !== 1 || ! $generation
            || ! in_array($generation->status, ['screening', 'full_validation'], true)) {
            return [...$result, 'reason' => 'TERMINAL_PROJECTION_RECOVERY_TARGET_NOT_SOLE_ACTIVE'];
        }
        $control = app(AutonomousModeService::class)->status($generation->laboratory->symbol, $generation->laboratory->timeframe);
        if (($control['enabled'] ?? null) !== false || ! in_array($control['state'] ?? null, ['draining', 'stopped'], true)) {
            return [...$result, 'reason' => 'DRAIN_FIRST_STOP_REQUIRED'];
        }
        try {
            $proof = app(ObservedCouncilEpisodeDispositionService::class)->inspectGeneration($generation);
        } catch (\Throwable) {
            return [...$result, 'reason' => 'TERMINAL_PROJECTION_RECOVERY_PROOF_UNAVAILABLE'];
        }
        if (($proof['allowed'] ?? null) !== true || ($proof['promotion_evidence'] ?? null) !== false
            || ! is_array($proof['proof'] ?? null) || $proof['proof'] === []) {
            return [...$result, 'reason' => 'TERMINAL_PROJECTION_RECOVERY_PROOF_REFUSED'];
        }
        return [...$result, 'safe' => true, 'reason' => 'TERMINAL_PROJECTION_COLD_MAINTENANCE_READY',
            'terminal_projection_proof' => $proof['proof']];
    }
}
