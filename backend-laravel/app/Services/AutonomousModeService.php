<?php

namespace App\Services;

use App\Models\CandidateGateDecision;
use App\Models\GenerationAdmissionDecision;
use App\Models\LabGeneration;
use App\Models\LabLifecycleCycle;
use App\Models\ResearchLoopDecision;
use App\Models\SystemEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Persistent operator switch for autonomous XAUUSD research.
 *
 * STOP is drain-first: already admitted work remains immutable and may settle,
 * while every new generation/research admission is denied. Monitoring and
 * market-data maintenance remain available in both states.
 */
class AutonomousModeService
{
    public const PROTOCOL = 'autonomous_mode_control_v1';

    public const CONTROLLER_STRONG = 'strong_supervisor';

    public const CONTROLLER_LIGHTWEIGHT = 'lightweight_monitor';

    private const EVENT_PREFIX = 'autonomy:mode:';

    /** @return array<string, mixed> */
    public function start(
        string $symbol = 'XAUUSD',
        string $timeframe = 'H1',
        string $actor = 'operator',
        string $reason = 'operator_start',
        string $controllerProfile = self::CONTROLLER_LIGHTWEIGHT,
    ): array {
        return $this->setEnabled(
            $symbol,
            $timeframe,
            true,
            $actor,
            $reason,
            $this->normalizeControllerProfile($controllerProfile, true),
        );
    }

    /** @return array<string, mixed> */
    public function stop(string $symbol = 'XAUUSD', string $timeframe = 'H1', string $actor = 'operator', string $reason = 'operator_stop'): array
    {
        return $this->setEnabled($symbol, $timeframe, false, $actor, $reason, null);
    }

    public function enabled(string $symbol = 'XAUUSD', string $timeframe = 'H1'): bool
    {
        return (bool) $this->status($symbol, $timeframe)['enabled'];
    }

    /**
     * Compact, read-only snapshot intended for a lightweight controller.
     *
     * @return array<string, mixed>
     */
    public function monitor(string $symbol = 'XAUUSD', string $timeframe = 'H1', ?string $controllerProfile = null): array
    {
        $control = $this->status($symbol, $timeframe);
        $storedProfile = (string) $control['controller_profile'];
        $effectiveProfile = $controllerProfile === null || trim($controllerProfile) === ''
            ? $storedProfile
            : $this->normalizeControllerProfile($controllerProfile, true);
        $latestId = (int) data_get($control, 'latest_generation.id', 0);
        $latest = $latestId > 0 && Schema::hasTable('lab_generations')
            ? LabGeneration::query()->find($latestId)
            : null;
        $agentCounts = $latest && Schema::hasTable('lab_agents')
            ? $latest->agents()
                ->selectRaw('lifecycle_status, count(*) as aggregate')
                ->groupBy('lifecycle_status')
                ->pluck('aggregate', 'lifecycle_status')
                ->map(fn ($count): int => (int) $count)
                ->all()
            : [];
        $actualPopulation = array_sum($agentCounts);
        $plannedPopulation = $latest ? $this->plannedPopulation($latest) : null;
        $terminalGeneration = $latest && in_array((string) $latest->status, [
            'screened', 'completed', 'technical_quarantine', 'abandoned', 'failed',
        ], true);
        $terminalAgentStatuses = [
            'screened', 'completed', 'technical_quarantine', 'quarantined',
            'legacy_quarantine', 'abandoned', 'failed',
        ];
        $terminalAgentCount = collect($agentCounts)
            ->only($terminalAgentStatuses)
            ->sum();
        $technicalRunCount = $latest && Schema::hasTable('lab_evaluation_runs')
            ? DB::table('lab_evaluation_runs')
                ->where('lab_generation_id', $latest->id)
                ->where('status', 'technical_error')
                ->count()
            : 0;
        $technicalLifecycleEventCount = $latest && Schema::hasTable('lab_lifecycle_events')
            ? DB::table('lab_lifecycle_events')
                ->where('lab_generation_id', $latest->id)
                ->where(function ($query): void {
                    $query->where('event_type', 'evaluation_technical_error')
                        ->orWhere('event_type', 'like', '%technical_quarantine%')
                        ->orWhere('event_type', 'like', '%integrity_quarantine%');
                })
                ->count()
            : 0;
        $blockedCycleCount = $latest && Schema::hasTable('lab_lifecycle_cycles')
            ? LabLifecycleCycle::query()
                ->where('symbol', $control['symbol'])
                ->where('timeframe', $control['laboratory_storage_timeframe'])
                ->where('started_at', '>=', $latest->created_at)
                ->where('status', 'blocked')
                ->count()
            : 0;
        $attentionReasons = [];
        try {
            $constructor = app(LabPopulationService::class)->constructorStatus(
                (string) $control['symbol'],
                (string) $control['laboratory_storage_timeframe'],
            );
        } catch (\Throwable) {
            $constructor = [
                'protocol' => 'lab_population_constructor_status_v1',
                'available' => false,
                'active' => null,
                'status' => 'unknown_runtime_cache_unavailable',
                'promotion_evidence' => false,
            ];
            $attentionReasons[] = 'CONSTRUCTOR_STATE_UNKNOWN';
        }
        $checkpoint = Schema::hasTable('lab_lifecycle_cycles')
            ? LabLifecycleCycle::query()
                ->where('symbol', $control['symbol'])
                ->where('timeframe', $control['laboratory_storage_timeframe'])
                ->latest('id')
                ->first()
            : null;
        $heartbeatAvailable = true;
        try {
            $heartbeat = Cache::get('system:scheduler-heartbeat');
        } catch (\Throwable) {
            $heartbeat = null;
            $heartbeatAvailable = false;
        }
        $schedulerHealthy = false;
        if ($heartbeat) {
            try {
                $schedulerHealthy = Carbon::parse((string) $heartbeat)->greaterThanOrEqualTo(
                    now()->subSeconds(max(60, (int) config('services.scheduler.lease_seconds', 900))),
                );
            } catch (\Throwable) {
                $schedulerHealthy = false;
            }
        }
        try {
            $queue = app(LabQueueJobInspector::class)->labQueueBacklog();
        } catch (\Throwable) {
            $queue = ['total' => null, 'queues' => [], 'available' => false];
        }
        if (! $heartbeatAvailable) {
            $attentionReasons[] = 'SCHEDULER_HEARTBEAT_UNKNOWN';
        } elseif (! $schedulerHealthy) {
            $attentionReasons[] = 'SCHEDULER_HEARTBEAT_STALE';
        }
        if (data_get($queue, 'total') === null) {
            $attentionReasons[] = 'QUEUE_STATE_UNKNOWN';
        }
        $activeGeneration = $latest && in_array((string) $latest->status, [
            'draft', 'queued', 'training', 'screening', 'full_queued', 'full_validation',
        ], true);
        $technicalAgents = (int) ($agentCounts['evaluation_error'] ?? 0)
            + (int) ($agentCounts['technical_quarantine'] ?? 0);
        if ($activeGeneration && (int) data_get($queue, 'total', -1) === 0 && $technicalAgents > 0) {
            $attentionReasons[] = 'ACTIVE_GENERATION_HAS_TERMINAL_TECHNICAL_AGENTS_WITHOUT_QUEUED_RECOVERY';
        }
        if ($checkpoint && (string) $checkpoint->status === 'blocked') {
            $attentionReasons[] = 'LIFECYCLE_CYCLE_BLOCKED';
        }
        if (LabPopulationService::constructionIncomplete($latest)) {
            $attentionReasons[] = 'GENERATION_CONSTRUCTION_INCOMPLETE';
        }
        $acceptanceReasons = [];
        if ($plannedPopulation === null || $plannedPopulation !== $actualPopulation) {
            $acceptanceReasons[] = 'POPULATION_NOT_COMPLETE';
        }
        if ($technicalRunCount > 0) {
            $acceptanceReasons[] = 'TECHNICAL_EVALUATION_RUN_RECORDED';
        }
        if ($technicalLifecycleEventCount > 0) {
            $acceptanceReasons[] = 'IMMUTABLE_TECHNICAL_LIFECYCLE_EVENT_RECORDED';
        }
        if ($technicalAgents > 0) {
            $acceptanceReasons[] = 'TECHNICAL_AGENT_RECORDED';
        }
        if ($blockedCycleCount > 0) {
            $acceptanceReasons[] = 'BLOCKED_LIFECYCLE_CYCLE_RECORDED';
        }
        if ($latest && in_array((string) $latest->status, ['technical_quarantine', 'abandoned', 'failed'], true)) {
            $acceptanceReasons[] = 'GENERATION_TERMINATED_TECHNICALLY';
        }
        $technicalFailureObserved = collect($acceptanceReasons)->contains(
            fn (string $reason): bool => $reason !== 'POPULATION_NOT_COMPLETE',
        );
        $acceptanceState = ! $latest
            ? 'not_started'
            : ($technicalFailureObserved
                ? 'failed'
                : ($terminalGeneration
                    && $plannedPopulation !== null
                    && $plannedPopulation === $actualPopulation
                    && $terminalAgentCount === $actualPopulation
                        ? 'passed'
                        : 'running_clean'));
        $manualInterventionRequiredNow = $technicalAgents > 0
            || ($checkpoint && (string) $checkpoint->status === 'blocked');
        $loopDecision = Schema::hasTable('research_loop_decisions')
            ? ResearchLoopDecision::query()->where('symbol', $control['symbol'])
                ->where('timeframe', $control['laboratory_storage_timeframe'])->latest('id')->first()
            : null;
        $researchClosure = Schema::hasTable('research_experiment_receipts')
            ? app(ResearchClosureInvariantService::class)->inspect(
                (string) $control['symbol'],
                (string) $control['laboratory_storage_timeframe'],
                false,
            )
            : ['healthy' => false, 'status' => 'migration_pending'];
        if (($researchClosure['healthy'] ?? false) !== true) {
            $attentionReasons[] = 'RESEARCH_CLOSURE_REPAIR_REQUIRED';
        }

        $monitor = [
            'observed_at' => now()->utc()->toIso8601String(),
            'scheduler_healthy' => $schedulerHealthy,
            'lifecycle_cycle' => $checkpoint ? [
                'cycle_id' => (string) $checkpoint->cycle_id,
                'status' => (string) $checkpoint->status,
                'stage' => (string) $checkpoint->stage,
                'heartbeat_at' => $checkpoint->heartbeat_at?->toIso8601String(),
            ] : null,
            'generation' => $latest ? [
                'id' => (int) $latest->id,
                'generation' => (int) $latest->generation,
                'status' => (string) $latest->status,
                'planned_population' => $plannedPopulation,
                'actual_population' => $actualPopulation,
                'population_complete' => $plannedPopulation !== null && $plannedPopulation === $actualPopulation,
                'agent_statuses' => $agentCounts,
                'construction' => $constructor,
                'technical_process_acceptance' => [
                    'protocol' => 'generation_unattended_acceptance_v1',
                    'state' => $acceptanceState,
                    'terminal' => (bool) $terminalGeneration,
                    'terminal_agents' => (int) $terminalAgentCount,
                    'technical_evaluation_runs' => (int) $technicalRunCount,
                    'technical_lifecycle_events' => (int) $technicalLifecycleEventCount,
                    'technical_agents' => $technicalAgents,
                    'blocked_lifecycle_cycles' => (int) $blockedCycleCount,
                    'unattended_acceptance_eligible' => ! $technicalFailureObserved,
                    'manual_intervention_required' => (bool) $manualInterventionRequiredNow,
                    'reason_codes' => array_values(array_unique($acceptanceReasons)),
                    'pass_rule' => 'complete_population_and_all_agents_terminal_with_zero_technical_runs_events_agents_or_blocked_cycles',
                    'strategy_rejection_is_not_a_technical_failure' => true,
                ],
            ] : null,
            'queue' => $queue,
            'instrument_learning' => app(InstrumentLearningMonitorService::class)->snapshot($control['symbol']),
            'research_loop' => [
                'single_owner' => ResearchLoopArbiterService::class,
                'latest_decision' => $loopDecision ? [
                    'id' => (int) $loopDecision->id,
                    'action' => (string) $loopDecision->action,
                    'status' => (string) $loopDecision->status,
                    'priority' => (int) $loopDecision->priority,
                    'reason_codes' => (array) $loopDecision->reason_codes,
                    'created_at' => $loopDecision->created_at?->toIso8601String(),
                ] : null,
                'closure' => array_intersect_key($researchClosure, array_flip([
                    'protocol', 'status', 'healthy', 'projection_debt', 'work', 'next_repair',
                ])),
            ],
            'runtime_attention_required' => $attentionReasons !== [],
            'runtime_attention_reasons' => array_values(array_unique($attentionReasons)),
        ];
        if ($effectiveProfile === self::CONTROLLER_STRONG) {
            $monitor['supervision'] = $this->strongSupervisionSnapshot(
                (string) $control['symbol'],
                (string) $control['laboratory_storage_timeframe'],
                $latest,
            );
        }

        return [
            ...$control,
            'stored_controller_profile' => $storedProfile,
            'effective_controller_profile' => $effectiveProfile,
            'controller_contract' => $this->controllerContract($effectiveProfile),
            'monitor' => $monitor,
        ];
    }

    /** @return array<string, mixed> */
    public function status(string $symbol = 'XAUUSD', string $timeframe = 'H1'): array
    {
        [$symbol, $timeframe] = $this->scope($symbol, $timeframe);
        $event = Schema::hasTable('system_events')
            ? SystemEvent::query()->where('event_key', $this->eventKey($symbol, $timeframe))->first()
            : null;
        $enabled = $event === null
            ? (bool) config('services.autonomous_mode.default_enabled', true)
            : (bool) data_get($event->payload, 'enabled', false);
        $controllerProfile = $this->normalizeControllerProfile(
            (string) data_get(
                $event?->payload,
                'controller_profile',
                config('services.autonomous_mode.default_controller_profile', self::CONTROLLER_LIGHTWEIGHT),
            ),
        );
        $latest = Schema::hasTable('lab_generations') && Schema::hasTable('ai_laboratories')
            ? LabGeneration::query()
                ->whereHas('laboratory', fn ($query) => $query
                    ->where('symbol', $symbol)
                    ->where('timeframe', $timeframe))
                ->latest('generation')
                ->first()
            : null;
        $activeStatuses = ['draft', 'queued', 'screening', 'training', 'full_queued', 'full_validation'];
        $active = $latest !== null && in_array((string) $latest->status, $activeStatuses, true);

        return [
            'protocol' => self::PROTOCOL,
            'symbol' => $symbol,
            // Public control scope is the whole XAUUSD organism. H1 remains
            // a persistence coordinate only and must not be presented as a
            // separate trading/learning organism to a lightweight monitor.
            'timeframe' => $symbol === strtoupper((string) config('services.xauusd_organism.symbol', 'XAUUSD')) ? 'MTF' : $timeframe,
            'laboratory_storage_timeframe' => $timeframe,
            'timeframe_semantics' => $symbol === strtoupper((string) config('services.xauusd_organism.symbol', 'XAUUSD'))
                ? 'single_multi_timeframe_organism; storage anchor only'
                : 'single_timeframe_scope',
            'organism' => [
                'id' => $symbol === strtoupper((string) config('services.xauusd_organism.symbol', 'XAUUSD'))
                    ? $symbol.'_MTF'
                    : $symbol.'_'.$timeframe,
                'symbol' => $symbol,
                'scope' => $symbol === strtoupper((string) config('services.xauusd_organism.symbol', 'XAUUSD'))
                    ? 'single_multi_timeframe_lineage'
                    : 'single_timeframe_lineage',
                'timeframe_roles' => $symbol === strtoupper((string) config('services.xauusd_organism.symbol', 'XAUUSD'))
                    ? (array) config('services.xauusd_organism.timeframe_roles', [])
                    : [$timeframe => 'decision_and_execution'],
            ],
            'enabled' => $enabled,
            'state' => $enabled ? 'running' : ($active ? 'draining' : 'stopped'),
            'controller_profile' => $controllerProfile,
            'controller_semantics' => $controllerProfile === self::CONTROLLER_STRONG
                ? 'strong model receives deep read-only diagnostics; scheduler remains the sole work authority'
                : 'lightweight model receives compact start/stop/status monitoring only',
            'source' => $event ? 'persistent_operator_control' : 'configuration_default',
            'stop_policy' => 'deny_new_work_and_drain_admitted_work',
            'monitoring_continues_when_stopped' => true,
            'latest_generation' => $latest ? [
                'id' => (int) $latest->id,
                'generation' => (int) $latest->generation,
                'status' => (string) $latest->status,
                'active' => $active,
            ] : null,
            'actor' => data_get($event?->payload, 'actor'),
            'reason' => data_get($event?->payload, 'reason'),
            'changed_at' => data_get($event?->payload, 'changed_at'),
            'next_action' => $enabled
                ? 'scheduler_owns_generation_learning_and_recovery'
                : ($active ? 'monitor_until_admitted_work_drains' : 'monitor_only_until_ai_start'),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function setEnabled(
        string $symbol,
        string $timeframe,
        bool $enabled,
        string $actor,
        string $reason,
        ?string $controllerProfile,
    ): array {
        [$symbol, $timeframe] = $this->scope($symbol, $timeframe);
        $actor = trim($actor) !== '' ? trim($actor) : 'operator';
        $reason = trim($reason) !== '' ? trim($reason) : ($enabled ? 'operator_start' : 'operator_stop');

        if (! Schema::hasTable('system_events')) {
            return [
                'protocol' => self::PROTOCOL,
                'symbol' => $symbol,
                'timeframe' => $symbol === strtoupper((string) config('services.xauusd_organism.symbol', 'XAUUSD')) ? 'MTF' : $timeframe,
                'laboratory_storage_timeframe' => $timeframe,
                'enabled' => false,
                'state' => 'unavailable',
                'changed' => false,
                'reason_code' => 'SYSTEM_EVENTS_TABLE_NOT_READY',
                'promotion_evidence' => false,
            ];
        }

        $changed = DB::transaction(function () use ($symbol, $timeframe, $enabled, $actor, $reason, $controllerProfile): bool {
            $key = $this->eventKey($symbol, $timeframe);
            $current = SystemEvent::query()->where('event_key', $key)->lockForUpdate()->first();
            $currentEnabled = $current === null
                ? (bool) config('services.autonomous_mode.default_enabled', true)
                : (bool) data_get($current->payload, 'enabled', false);
            $currentProfile = $this->normalizeControllerProfile((string) data_get(
                $current?->payload,
                'controller_profile',
                config('services.autonomous_mode.default_controller_profile', self::CONTROLLER_LIGHTWEIGHT),
            ));
            $nextProfile = $controllerProfile ?? $currentProfile;
            if ($current !== null && $currentEnabled === $enabled && $currentProfile === $nextProfile) {
                return false;
            }

            $changedAt = now()->utc()->toIso8601String();
            $payload = [
                'protocol' => self::PROTOCOL,
                'enabled' => $enabled,
                'state' => $enabled ? 'running' : 'stopped',
                'controller_profile' => $nextProfile,
                'actor' => $actor,
                'reason' => $reason,
                'changed_at' => $changedAt,
                'stop_policy' => 'deny_new_work_and_drain_admitted_work',
                'promotion_evidence' => false,
            ];
            SystemEvent::query()->updateOrCreate(['event_key' => $key], [
                'event_type' => 'autonomous_mode_control',
                'agent' => $actor,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'severity' => $enabled ? 'info' : 'warning',
                'summary' => $enabled
                    ? 'Autonomous research mode started; scheduler owns bounded work admission.'
                    : 'Autonomous research mode stopped; new work is denied while admitted work drains.',
                'payload' => $payload,
                'occurred_at' => now(),
            ]);
            SystemEvent::query()->create([
                'event_type' => 'autonomous_mode_transition',
                'event_key' => self::EVENT_PREFIX.'transition:'.Str::uuid(),
                'agent' => $actor,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'severity' => $enabled ? 'info' : 'warning',
                'summary' => $enabled ? 'Autonomous mode START accepted.' : 'Autonomous mode STOP accepted.',
                'payload' => $payload,
                'occurred_at' => now(),
            ]);

            return $currentEnabled !== $enabled || $currentProfile !== $nextProfile;
        });

        return [...$this->status($symbol, $timeframe), 'changed' => $changed];
    }

    /** @return array{0: string, 1: string} */
    private function scope(string $symbol, string $timeframe): array
    {
        $symbol = strtoupper(str_replace(['/', '_', '-'], '', trim($symbol)));
        $timeframe = strtoupper(trim($timeframe));
        if ($symbol === strtoupper((string) config('services.xauusd_organism.symbol', 'XAUUSD'))) {
            $timeframe = strtoupper((string) config('services.xauusd_organism.laboratory_storage_timeframe', 'H1'));
        }

        return [$symbol, $timeframe];
    }

    private function eventKey(string $symbol, string $timeframe): string
    {
        return self::EVENT_PREFIX.$symbol.':'.$timeframe;
    }

    /** @return array<string, mixed> */
    private function controllerContract(string $profile): array
    {
        $strong = $profile === self::CONTROLLER_STRONG;

        return [
            'profile' => $profile,
            'role' => $strong ? 'supervised_diagnostic_monitor' : 'start_stop_and_monitor_only',
            'diagnostic_depth' => $strong ? 'deep' : 'compact',
            'allowed_commands' => $strong
                ? ['ai:start --controller=strong --json', 'ai:status --controller=strong --json', 'ai:stop --json']
                : ['ai:start --controller=lightweight --json', 'ai:status --controller=lightweight --json', 'ai:stop --json'],
            'manual_generation_commands_allowed' => false,
            'force_or_gate_bypass_allowed' => false,
            'scheduler_owns_internal_actions' => true,
            'same_governed_engine_for_both_profiles' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function strongSupervisionSnapshot(string $symbol, string $timeframe, ?LabGeneration $latest): array
    {
        if (! Schema::hasTable('lab_generations') || ! Schema::hasTable('lab_agents')) {
            return ['available' => false, 'reason_code' => 'LAB_TABLES_NOT_READY'];
        }

        $recent = LabGeneration::query()
            ->whereHas('laboratory', fn ($query) => $query
                ->where('symbol', $symbol)
                ->where('timeframe', $timeframe))
            ->orderByDesc('generation')
            ->orderByDesc('id')
            ->limit(5)
            ->get();
        $rows = $recent->map(function (LabGeneration $generation): array {
            $agentIds = $generation->agents()->pluck('id');
            $decisions = $agentIds->isEmpty() || ! Schema::hasTable('candidate_gate_decisions')
                ? collect()
                : CandidateGateDecision::query()
                    ->whereIn('lab_agent_id', $agentIds)
                    ->where('stage', 'screening')
                    ->get(['decision']);
            $terminal = in_array((string) $generation->status, [
                'screened', 'completed', 'abandoned', 'failed',
            ], true);

            return [
                'generation' => (int) $generation->generation,
                'generation_id' => (int) $generation->id,
                'trigger_type' => (string) $generation->trigger_type,
                'status' => (string) $generation->status,
                'planned_population' => $this->plannedPopulation($generation),
                'actual_population' => $agentIds->count(),
                'screen_decisions' => $decisions->count(),
                'screen_passes' => $decisions->where('decision', 'passed')->count(),
                'screen_failures' => $decisions->where('decision', 'failed')->count(),
                'scientific_zero_pass' => $terminal
                    && $decisions->isNotEmpty()
                    && $decisions->where('decision', 'passed')->isEmpty(),
            ];
        })->values();
        $latestAdmission = Schema::hasTable('generation_admission_decisions')
            ? GenerationAdmissionDecision::query()
                ->when($latest, fn ($query) => $query->where('latest_generation_id', $latest->id))
                ->latest('id')
                ->first()
            : null;

        return [
            'available' => true,
            'mode' => 'read_only_supervision',
            'recent_generations' => $rows->all(),
            'recent_scientific_zero_pass_observations' => $rows->where('scientific_zero_pass', true)->count(),
            'structural_escape_threshold' => (int) config('services.learning_lane.zero_pass_circuit_breaker_generations', 3),
            'latest_admission' => $latestAdmission ? [
                'decision' => (string) $latestAdmission->decision,
                'allowed' => (bool) $latestAdmission->allowed,
                'reason_codes' => (array) $latestAdmission->reason_codes,
                'decided_at' => $latestAdmission->decided_at?->toIso8601String(),
            ] : null,
            'learning_intelligence' => app(LearningIntelligenceAuditService::class)
                ->snapshot($symbol, $timeframe, 90),
            'governance' => [
                'new_work_authority' => 'scheduler_plus_generation_admission',
                'fresh_data_required_for_zero_pass_accumulation' => true,
                'structural_escape_is_bounded' => true,
                'promotion_gates_remain_fail_closed' => true,
                'manual_generation_bypass' => false,
            ],
            'promotion_evidence' => false,
        ];
    }

    private function plannedPopulation(LabGeneration $generation): int
    {
        $generationPlan = (array) data_get($generation->trigger_context, 'generation_plan', []);
        if ($generationPlan !== []) {
            return count($generationPlan);
        }

        return (int) data_get(
            $generation->trigger_context,
            'population_group_contract.planned_population',
            $generation->population_size,
        );
    }

    private function normalizeControllerProfile(?string $profile, bool $strict = false): string
    {
        $value = strtolower(trim((string) $profile));
        $normalized = match ($value) {
            '', 'lightweight', 'light', 'weak', 'monitor', 'monitor_only', self::CONTROLLER_LIGHTWEIGHT => self::CONTROLLER_LIGHTWEIGHT,
            'strong', 'supervisor', 'supervised', 'deep', self::CONTROLLER_STRONG => self::CONTROLLER_STRONG,
            default => null,
        };
        if ($normalized === null && $strict) {
            throw new \InvalidArgumentException('Unsupported controller profile. Use strong or lightweight.');
        }

        return $normalized ?? self::CONTROLLER_LIGHTWEIGHT;
    }
}
